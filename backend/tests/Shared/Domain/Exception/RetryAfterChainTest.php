<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain\Exception;

use App\Shared\Domain\Exception\RetryAfterAware;
use App\Shared\Domain\Exception\RetryAfterChain;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * La recherche unique de la première échéance d'une chaîne d'exceptions (#651) :
 * partagée par l'écouteur `Retry-After` et par le point d'autorisation OAuth.
 */
final class RetryAfterChainTest extends TestCase
{
    public function testTheThrownExceptionIsItsOwnFirstQuota(): void
    {
        $quota = $this->quota('2026-10-02 12:00:10');

        self::assertSame($quota, RetryAfterChain::firstIn($quota));
    }

    public function testAQuotaCauseIsFoundWalkingThePreviousChain(): void
    {
        $quota = $this->quota('2026-10-02 12:00:10');

        self::assertSame($quota, RetryAfterChain::firstIn(new DomainException('haut', 0, new DomainException('milieu', 0, $quota))));
    }

    public function testAChainWithoutQuotaGivesNull(): void
    {
        self::assertNull(RetryAfterChain::firstIn(new DomainException('x', 0, new DomainException('y'))));
    }

    /**
     * Deux quotas dans la chaîne : le plus proche de l'exception levée gagne.
     */
    public function testTheQuotaNearestTheThrownExceptionWinsOverAnInnerOne(): void
    {
        $inner = $this->quota('2026-10-02 12:01:39');
        $outer = $this->quotaWrapping('2026-10-02 12:00:10', $inner);

        self::assertSame($outer, RetryAfterChain::firstIn(new DomainException('haut', 0, $outer)));
    }

    private function quota(string $deadline): Throwable
    {
        return $this->quotaWrapping($deadline, null);
    }

    /**
     * Un quota extérieur (RetryAfterAware) qui enveloppe $inner comme `previous`.
     */
    private function quotaWrapping(string $deadline, ?Throwable $inner): Throwable
    {
        return new class(new DateTimeImmutable($deadline), $inner) extends DomainException implements RetryAfterAware {
            public function __construct(public readonly DateTimeImmutable $retryAfter, ?Throwable $previous)
            {
                parent::__construct('quota', 0, $previous);
            }
        };
    }
}
