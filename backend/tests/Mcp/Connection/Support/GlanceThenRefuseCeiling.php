<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Support;

use DateTimeImmutable;
use LogicException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;

/**
 * Un limiteur (plafond commun ou compteur d'un client connu) qui se remplit
 * entre le coup d'œil et la consommation (#651, D-F) : il répond « il reste un jeton » à `consume(0)` puis refuse
 * `consume(1)`, avec un `retryAfter` d'une heure. C'est la course que le quota
 * accepte (un autre appelant prend la dernière unité entre les deux) : aucun
 * limiteur réel ne la produit à la demande, d'où cette doublure nommée.
 */
final class GlanceThenRefuseCeiling implements RateLimiterFactoryInterface, LimiterInterface
{
    public function create(?string $key = null): LimiterInterface
    {
        return $this;
    }

    public function consume(int $tokens = 1): RateLimit
    {
        $retryAfter = new DateTimeImmutable('+1 hour');

        // Le coup d'œil : « accepté » est toujours vrai pour zéro jeton, la file n'est pas pleine.
        if (0 === $tokens) {
            return new RateLimit(1, $retryAfter, true, 100);
        }

        return new RateLimit(0, $retryAfter, false, 100);
    }

    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        throw new LogicException('Le quota ne réserve jamais.');
    }

    public function reset(): void
    {
    }
}
