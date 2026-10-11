<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Exception\InvalidPasswordSetupTokenException;
use App\Security\User\Domain\Exception\PasswordSetupTokenExpiredException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
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
            $this->accountLock->whileLocked($user, function (CpgUser $locked) use ($token, $hashedPassword): void {
                $this->activate($token, $locked, $hashedPassword);
            });
        } catch (CpgUserNotFoundException) {
            // Supprimé entre-temps : la cascade a emporté le jeton, réponse
            // d'un lien inconnu.
            throw InvalidPasswordSetupTokenException::unknownToken();
        }

        // Journal de sécurité (D5) : le compte activé, jamais le jeton ni le
        // mot de passe. Parcours anonyme : l'auteur sera « anonymous ».
        $this->auditLogger->accountActivated($user);
    }

    /**
     * Sous le verrou du compte, compte relu.
     */
    private function activate(PasswordSetupToken $token, CpgUser $user, string $hashedPassword): void
    {
        // L'heure d'après le hachage, qui prend du temps : un lien expiré
        // entre-temps ne doit pas être consommé (issue #447).
        $now = $this->clock->now();

        // Déjà activé, par un autre lien du compte : celui-ci ne doit plus
        // rien pouvoir faire. Un lien présenté pour un compte actif est un
        // rejeu au sens du journal.
        if (!$user->isPendingActivation()) {
            $this->auditLogger->passwordSetupTokenReplayed($user);

            throw PasswordSetupTokenExpiredException::expiredOrAlreadyUsed();
        }

        // Puis la réclamation atomique (issue #356) : `isUsable()` n'a lu que
        // l'état chargé, qu'une requête concurrente sur le même lien a pu
        // consommer depuis. La perdante est un rejeu — c'est le scénario du
        // lien fuité soumis en même temps que la personne invitée —, et rien
        // de ce qu'elle a envoyé ne touche le compte. Un lien expiré pendant
        // le hachage perd aussi, mais n'est pas un rejeu : un lien simplement
        // expiré n'est pas un événement de sécurité.
        if (!$this->passwordSetupTokenRepository->claim($token, $now)) {
            if ($now < $token->getExpiresAt()) {
                $this->auditLogger->passwordSetupTokenReplayed($user);
            }

            throw PasswordSetupTokenExpiredException::expiredOrAlreadyUsed();
        }

        $user->setPassword($hashedPassword);
        $user->markActivated($now);
        $token->markUsed($now);

        $this->cpgUserRepository->save($user);
        $this->passwordSetupTokenRepository->save($token);
        // Un compte activé ne garde aucun jeton utilisable (issue #458).
        $this->passwordSetupTokenRepository->deleteUnusedForUser($user);
    }

    private function usableTokenOrFail(#[SensitiveParameter] string $clearToken): PasswordSetupToken
    {
        $token = $this->passwordSetupTokenRepository->findOneByTokenHash(hash('sha256', $clearToken));

        // Journal de sécurité (issue #356) : ces deux refus n'avaient pour
        // trace que la ligne générique du noyau, sans IP ni chemin. Jamais le
        // jeton, ni en clair ni haché.
        if (null === $token) {
            $this->auditLogger->passwordSetupTokenRejected();

            throw InvalidPasswordSetupTokenException::unknownToken();
        }

        if (!$token->isUsable($this->clock->now())) {
            // Un lien déjà consommé qui revient se distingue ici, côté journal
            // seulement : la réponse reste le 410 fusionné avec « expiré ». Un
            // lien simplement expiré n'est pas un événement de sécurité.
            if ($token->wasUsed()) {
                $this->auditLogger->passwordSetupTokenReplayed($token->getUser());
            }

            throw PasswordSetupTokenExpiredException::expiredOrAlreadyUsed();
        }

        return $token;
    }
}
