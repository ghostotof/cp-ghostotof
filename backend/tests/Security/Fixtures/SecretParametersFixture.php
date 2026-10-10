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
final class SecretParametersFixture implements SecretParametersFixtureInterface
{
    /**
     * Des secrets en clair sous toutes les graphies et tous les types qui
     * portent une chaîne, un seul caché des traces ; puis un service, un
     * compte et un titre, ignorés.
     *
     * @return list<mixed>
     */
    public function issue(
        string $jwt,
        #[SensitiveParameter] string $clearToken,
        ?string $refreshToken,
        mixed $clientSecret,
        string $apiKey,
        string $API_KEY,
        string $bearer,
        string|int $csrf,
        TokenStorageInterface $tokenStorage,
        int $maxTokens,
        string $title,
    ): array {
        return [$jwt, $clearToken, $refreshToken, $clientSecret, $apiKey, $API_KEY, $bearer, $csrf, $tokenStorage, $maxTokens, $title];
    }

    /**
     * Le vocabulaire des secrets au-delà de « jeton » (revue de #414).
     *
     * @return list<string>
     */
    public function sign(
        string $xsrf,
        string $credentials,
        string $authorization,
        string $dsn,
        string $privateKey,
        string $signingKey,
        string $hmacKey,
        string $cookie,
        string $signature,
        string $nonce,
    ): array {
        return [$xsrf, $credentials, $authorization, $dsn, $privateKey, $signingKey, $hmacKey, $cookie, $signature, $nonce];
    }

    /**
     * Un condensat n'est pas un secret en clair ; un nom qui contient
     * seulement « hash » peut l'être.
     *
     * @return list<string>
     */
    public function digest(
        string $tokenHash,
        string $hashedSecret,
        string $unhashedToken,
        string $tokenToHash,
        string $hashSecret,
    ): array {
        return [$tokenHash, $hashedSecret, $unhashedToken, $tokenToHash, $hashSecret];
    }

    /** Le jeton du prototype, reçu sous un nom que le recensement par nom ne verrait pas. */
    public function verify(string $value): bool
    {
        return '' !== $value;
    }
}
