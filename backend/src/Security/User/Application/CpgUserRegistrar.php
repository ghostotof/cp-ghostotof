<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CpgUserRegistrar implements CpgUserRegistrarInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private SecurityAuditLoggerInterface $auditLogger,
        private UsernameAllocationLockInterface $usernameAllocationLock,
    ) {
    }

    public function register(string $username, #[SensitiveParameter] string $plainPassword, array $roles = []): CpgUser
    {
        // Refus rapide, avant le hachage (lent) : l'identifiant est déjà pris.
        $this->refuseIfTaken($username);

        $user = new CpgUser($username, CpgUser::NO_PASSWORD);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setRoles($roles);

        // Issue #364 : vérification et enregistrement sous le verrou que
        // prennent les invitations. Sans lui, une invitation qui dérive le même
        // identifiant pendant cette création échouait sur la contrainte
        // unique ; sous lui, elle attend, voit l'identifiant pris et en prend
        // un autre. Le hachage reste dehors : il tiendrait le verrou, donc
        // toutes les invitations, pendant des centaines de millisecondes. La
        // vérification est donc refaite sous le verrou, la seule qui fasse foi.
        $this->usernameAllocationLock->whileAllocating(function () use ($user, $username): void {
            $this->refuseIfTaken($username);
            $this->cpgUserRepository->add($user);
        });

        // Après l'enregistrement, comme les autres cas d'usage : une création
        // refusée n'écrit rien (issue #386).
        $this->auditLogger->userCreated($user);

        return $user;
    }

    /**
     * @throws UsernameAlreadyUsedException
     */
    private function refuseIfTaken(string $username): void
    {
        if (null !== $this->cpgUserRepository->findOneByUsername($username)) {
            throw UsernameAlreadyUsedException::alreadyTaken();
        }
    }
}
