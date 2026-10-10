<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures;

use SensitiveParameter;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Fixture de {@see \App\Tests\Security\SecretParametersTest} (issue #414) : ce
 * que la garde doit voir, caché ou non des traces d'exception, et ce qu'elle
 * doit ignorer. Hors de src/, elle n'est câblée nulle part.
 */
final class SecretParametersFixture
{
    /**
     * Cinq secrets en clair, un seul caché des traces ; puis un condensat, un
     * service, un compte et un titre, ignorés.
     */
    public function issue(
        string $jwt,
        #[SensitiveParameter] string $clearToken,
        ?string $refreshToken,
        mixed $clientSecret,
        string $apiKey,
        string $tokenHash,
        TokenStorageInterface $tokenStorage,
        int $maxTokens,
        string $title,
    ): bool {
        return null !== $tokenStorage->getToken()
            && '' !== $jwt.$clearToken.$refreshToken.$tokenHash.$apiKey.$title
            && null !== $clientSecret
            && 0 < $maxTokens;
    }
}
