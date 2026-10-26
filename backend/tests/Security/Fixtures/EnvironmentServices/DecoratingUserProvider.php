<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\EnvironmentServices;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Fixture de SecurityServicesEnvironmentParityTest (issue #698) : un
 * décorateur du fournisseur du palier de base, déclaré pour `prod` seul. La
 * section `security` reste identique d'un environnement à l'autre, le
 * fournisseur réellement appelé non. Jamais chargé hors de ce test.
 *
 * @implements UserProviderInterface<UserInterface>
 */
final readonly class DecoratingUserProvider implements UserProviderInterface
{
    /**
     * @param UserProviderInterface<UserInterface> $inner
     */
    public function __construct(private UserProviderInterface $inner)
    {
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->inner->refreshUser($user);
    }

    public function supportsClass(string $class): bool
    {
        return $this->inner->supportsClass($class);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->inner->loadUserByIdentifier($identifier);
    }
}
