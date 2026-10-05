<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\InvalidPasswordSetupTokenException;
use App\Security\User\Domain\Exception\PasswordSetupTokenExpiredException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class PasswordSetupService implements PasswordSetupServiceInterface
{
    public function __construct(
        private PasswordSetupTokenRepositoryInterface $passwordSetupTokenRepository,
        private CpgUserRepositoryInterface $cpgUserRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
        private SecurityAuditLoggerInterface $auditLogger,
    ) {
    }

    public function validate(string $clearToken): void
    {
        $this->usableTokenOrFail($clearToken);
    }

    public function complete(string $clearToken, string $plainPassword): void
    {
        $token = $this->usableTokenOrFail($clearToken);
        $user = $token->getUser();
        $now = $this->clock->now();

        // Le hash d'abord : s'il échouait, le jeton ne serait pas consommé.
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);

        // Puis la réclamation atomique (issue #356) : `isUsable()` n'a lu que
        // l'état chargé, qu'une requête concurrente sur le même lien a pu
        // consommer depuis. La perdante est un rejeu — c'est le scénario du
        // lien fuité soumis en même temps que la personne invitée —, et rien
        // de ce qu'elle a envoyé ne touche le compte.
        if (!$this->passwordSetupTokenRepository->claim($token, $now)) {
            $this->auditLogger->passwordSetupTokenReplayed($user);

            throw PasswordSetupTokenExpiredException::expiredOrAlreadyUsed();
        }

        $user->setPassword($hashedPassword);
        $user->markActivated($now);
        $token->markUsed($now);

        $this->cpgUserRepository->save($user);
        $this->passwordSetupTokenRepository->save($token);
        // Journal de sécurité (D5) : le compte activé, jamais le jeton ni le
        // mot de passe. Parcours anonyme : l'auteur sera « anonymous ».
        $this->auditLogger->accountActivated($user);
    }

    private function usableTokenOrFail(string $clearToken): PasswordSetupToken
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
            if (null !== $token->getUsedAt()) {
                $this->auditLogger->passwordSetupTokenReplayed($token->getUser());
            }

            throw PasswordSetupTokenExpiredException::expiredOrAlreadyUsed();
        }

        return $token;
    }
}
