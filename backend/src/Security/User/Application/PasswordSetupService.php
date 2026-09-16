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
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
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

        if (null === $token) {
            throw InvalidPasswordSetupTokenException::unknownToken();
        }

        if (!$token->isUsable($this->clock->now())) {
            throw PasswordSetupTokenExpiredException::expiredOrAlreadyUsed();
        }

        return $token;
    }
}
