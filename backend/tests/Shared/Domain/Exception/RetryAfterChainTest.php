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
 * La recherche unique de l'échéance la plus tardive d'une chaîne d'exceptions (#651) :
 * partagée par l'écouteur `Retry-After` et par le point d'autorisation OAuth.
 */
final class RetryAfterChainTest extends TestCase
{
    public function testTheThrownExceptionIsItsOwnLatestQuota(): void
    {
        $quota = $this->quota('2026-10-02 12:00:10');

        self::assertSame($quota, RetryAfterChain::latestIn($quota));
    }

    public function testAQuotaCauseIsFoundWalkingThePreviousChain(): void
    {
        $quota = $this->quota('2026-10-02 12:00:10');

        self::assertSame($quota, RetryAfterChain::latestIn(new DomainException('haut', 0, new DomainException('milieu', 0, $quota))));
    }

    public function testAChainWithoutQuotaGivesNull(): void
    {
        self::assertNull(RetryAfterChain::latestIn(new DomainException('x', 0, new DomainException('y'))));
    }

    /**
     * Deux quotas dans la chaîne : l'échéance la plus tardive gagne, jamais la plus proche de
     * l'exception levée (#651, « le maximum, jamais raccourci »).
     */
    public function testTheLatestDeadlineWinsOverANearerButEarlierOne(): void
    {
        $inner = $this->quota('2026-10-02 12:01:39');
        $outer = $this->quotaWrapping('2026-10-02 12:00:10', $inner);

        self::assertSame($inner, RetryAfterChain::latestIn(new DomainException('haut', 0, $outer)));
    }

    public function testTheOuterQuotaWinsWhenItsDeadlineIsTheLatest(): void
    {
        $inner = $this->quota('2026-10-02 12:00:10');
        $outer = $this->quotaWrapping('2026-10-02 12:01:39', $inner);

        self::assertSame($outer, RetryAfterChain::latestIn(new DomainException('haut', 0, $outer)));
    }

    /**
     * À égalité d'échéance, le premier trouvé (le plus proche de l'exception levée) l'emporte.
     */
    public function testTheFirstFoundWinsOnEqualDeadlines(): void
    {
        $inner = $this->quota('2026-10-02 12:00:10');
        $outer = $this->quotaWrapping('2026-10-02 12:00:10', $inner);

        self::assertSame($outer, RetryAfterChain::latestIn(new DomainException('haut', 0, $outer)));
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
