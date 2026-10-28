<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Exception\UnusablePasswordSetupLinkException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Domain\ValueObject\TokenClaim;
use App\Shared\Application\Security\AccountAccessWithdrawal;
use App\Shared\Application\Security\AccountMcpConnectionsRevokerInterface;
use App\Shared\Application\Security\RevokedAccountMcpConnections;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class PasswordSetupService implements PasswordSetupServiceInterface
{
    public function __construct(
        private PasswordSetupTokenRepositoryInterface $passwordSetupTokenRepository,
        private CpgUserRepositoryInterface $cpgUserRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
        private SecurityAuditLoggerInterface $auditLogger,
        private AccountLockInterface $accountLock,
        private AccountMcpConnectionsRevokerInterface $mcpConnections,
    ) {
    }

    public function validate(#[SensitiveParameter] string $clearToken): void
    {
        $this->usableTokenOrFail($clearToken);
    }

    public function complete(#[SensitiveParameter] string $clearToken, #[SensitiveParameter] string $plainPassword): void
    {
        $token = $this->usableTokenOrFail($clearToken);
        $user = $token->getUser();

        // Le hash d'abord, hors verrou (il est lent) : s'il échouait, le jeton
        // ne serait pas consommé.
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);

        // Sous le verrou du compte (issue #458), celui du handler d'invitation
        // et dans le même ordre — compte puis jetons : sans lui, un handler
        // passé entre la réclamation et l'activation voyait le compte encore
        // en attente et émettait un jeton, valable sur un compte actif.
        try {
            $revoked = $this->accountLock->whileLocked($user, fn (CpgUser $locked): ?RevokedAccountMcpConnections => $this->activate($token, $locked, $hashedPassword));
        } catch (CpgUserNotFoundException) {
            // Supprimé entre-temps : la cascade a emporté le jeton, réponse
            // et journal d'un lien inconnu.
            $this->auditLogger->passwordSetupTokenRejected();

            throw UnusablePasswordSetupLinkException::unknown();
        }

        // Journal de sécurité (D5) : le compte activé, jamais le jeton ni le
        // mot de passe. Parcours anonyme : l'auteur sera « anonymous ».
        $this->auditLogger->accountActivated($user);

        // Puis, si le lien a remplacé un mot de passe, une ligne par connexion
        // MCP coupée (#650) : après le COMMIT et après l'activation, comme le
        // `PUT` du mot de passe écrit `password-changed` puis ses connexions.
        if (null !== $revoked) {
            $this->mcpConnections->auditRevoked($revoked);
        }
    }

    /**
     * Sous le verrou du compte, compte relu.
     *
     * Rend ce que la révocation des connexions MCP a coupé, ou `null` quand le
     * lien a *posé* le premier mot de passe du compte (#650, tâche 5b).
     */
    private function activate(PasswordSetupToken $token, CpgUser $user, string $hashedPassword): ?RevokedAccountMcpConnections
    {
        // L'heure d'après le hachage, qui prend du temps : un lien expiré
        // entre-temps ne doit pas être consommé (issue #447).
        $now = $this->clock->now();

        // Déjà activé, par un autre lien du compte : celui-ci ne doit plus
        // rien pouvoir faire. Un lien présenté pour un compte actif est un
        // rejeu au sens du journal.
        if (!$user->isPendingActivation()) {
            $this->auditLogger->passwordSetupTokenReplayed($user);

            throw UnusablePasswordSetupLinkException::expiredOrAlreadyUsed();
        }

        // Puis la réclamation atomique (issue #356) : `isUsable()` n'a lu que
        // l'état chargé, qu'une requête concurrente sur le même lien a pu
        // consommer depuis. Seule une perdante face à un jeton déjà consommé
        // est un rejeu — le lien fuité soumis en même temps que la personne
        // invitée —, et rien de ce qu'elle a envoyé ne touche le compte. Un
        // lien expiré pendant le hachage n'est pas un événement de sécurité,
        // et un jeton disparu (une relance l'a remplacé pendant le hachage,
        // issue #458) est un lien inconnu, pas un rejeu.
        $claim = $this->passwordSetupTokenRepository->claim($token, $now);
        if (TokenClaim::Gone === $claim) {
            $this->auditLogger->passwordSetupTokenRejected();

            throw UnusablePasswordSetupLinkException::unknown();
        }
        if (TokenClaim::AlreadyUsed === $claim) {
            $this->auditLogger->passwordSetupTokenReplayed($user);
        }
        if (TokenClaim::Claimed !== $claim) {
            throw UnusablePasswordSetupLinkException::expiredOrAlreadyUsed();
        }

        // Un mot de passe déjà là, posé par un `ROLE_SUPER` sur l'invitation
        // (#460, le compte reste « en attente » mais se connecte), est
        // **remplacé** par ce lien : les connexions MCP qu'il a pu accorder
        // meurent avec lui (#650, tâche 5b — la décision 6 du plan croyait ce
        // cas impossible). Lu avant `setPassword()`, sur le compte relu sous le
        // verrou. Un compte sans mot de passe n'a jamais pu se connecter donc
        // accorder une connexion : le mot de passe est *posé*, aucun appel.
        $replacesAPassword = $user->hasPassword();

        // Le mot de passe, **sa date et la version des identifiants**, qu'il soit
        // posé ou remplacé (F2 de la relecture de #650, S1 de la seconde) : un
        // cookie de session ouvert avant ce lien porte l'ancienne version et n'est
        // plus servi (`CpgUser::claimsAStaleCredentialsVersion()`). Sans condition :
        // un seul chemin, aucun cas à oublier — la version d'un premier mot de
        // passe ne refuse aucune session qui existe déjà (un jeton sans
        // revendication vaut 0, et la version passe à 1 : il est refusé, or aucun
        // jeton ne peut exister pour un compte sans mot de passe).
        $user->replacePassword($hashedPassword, $now);
        $user->markActivated($now);
        $token->markUsed($now);

        $this->cpgUserRepository->save($user);
        $this->passwordSetupTokenRepository->save($token);
        // Un compte activé ne garde aucun jeton utilisable (issue #458).
        $this->passwordSetupTokenRepository->deleteUnusedForUser($user);

        // **Dans** la fermeture du verrou, comme `CpgUserAdministrator::changePassword()` :
        // le nouveau mot de passe, la consommation du lien et la coupure des
        // connexions valident ensemble ou pas du tout — une révocation refusée
        // annule l'activation, le lien reste réessayable. Ordre des verrous :
        // le compte (tenu), son jeton (réclamé plus haut), puis ses connexions
        // par identifiant — le sens de tous les autres chemins.
        return $replacesAPassword
            ? $this->mcpConnections->revokeAllOf($user, AccountAccessWithdrawal::PasswordChanged)
            : null;
    }

    private function usableTokenOrFail(#[SensitiveParameter] string $clearToken): PasswordSetupToken
    {
        $token = $this->passwordSetupTokenRepository->findOneByTokenHash(hash('sha256', $clearToken));

        // Journal de sécurité (issue #356) : ces deux refus n'avaient pour
        // trace que la ligne générique du noyau, sans IP ni chemin. Jamais le
        // jeton, ni en clair ni haché.
        if (null === $token) {
            $this->auditLogger->passwordSetupTokenRejected();

            throw UnusablePasswordSetupLinkException::unknown();
        }

        if (!$token->isUsable($this->clock->now())) {
            // Un lien déjà consommé qui revient se distingue ici, côté journal
            // seulement : la réponse est celle de tout lien inutilisable, un
            // lien inconnu compris (issue #362). Un lien simplement expiré
            // n'est pas un événement de sécurité.
            if ($token->wasUsed()) {
                $this->auditLogger->passwordSetupTokenReplayed($token->getUser());
            }

            throw UnusablePasswordSetupLinkException::expiredOrAlreadyUsed();
        }

        // Un lien encore utilisable d'un compte déjà actif (un jeton resté
        // d'avant #458) : même réponse que celle de complete(), qui le refuse
        // sous le verrou.
        if (!$token->getUser()->isPendingActivation()) {
            $this->auditLogger->passwordSetupTokenReplayed($token->getUser());

            throw UnusablePasswordSetupLinkException::expiredOrAlreadyUsed();
        }

        return $token;
    }
}
