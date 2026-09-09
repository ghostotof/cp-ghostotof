<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\Service\SupportStatusCalculator;
use App\Portfolio\Watch\Domain\ValueObject\ReleaseCycle;
use App\Portfolio\Watch\Domain\ValueObject\SupportStatus;
use PHPUnit\Framework\TestCase;

final class SupportStatusCalculatorTest extends TestCase
{
    private const string TODAY = '2026-09-07';

    private SupportStatusCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new SupportStatusCalculator();
    }

    private function cycle(
        ?string $endOfActiveSupportFrom,
        ?string $eolFrom,
        bool $isMaintained = true,
        bool $isEndOfActiveSupport = false,
        bool $isEol = false,
    ): ReleaseCycle {
        return new ReleaseCycle(
            '8.5',
            $isMaintained,
            $isEndOfActiveSupport,
            null !== $endOfActiveSupportFrom ? new \DateTimeImmutable($endOfActiveSupportFrom) : null,
            $isEol,
            null !== $eolFrom ? new \DateTimeImmutable($eolFrom) : null,
            '8.5.10',
        );
    }

    private function statusOf(?ReleaseCycle $cycle): SupportStatus
    {
        return $this->calculator->statusFor($cycle, new \DateTimeImmutable(self::TODAY));
    }

    /**
     * Une version qu'on ne retrouve dans aucun cycle publié n'est pas « en fin
     * de vie » : elle est inconnue. Confondre les deux afficherait une alerte
     * rouge pour une simple faute de saisie.
     */
    public function testAnAbsentCycleIsUnknownRatherThanEndOfLife(): void
    {
        self::assertSame(SupportStatus::UNKNOWN, $this->statusOf(null));
    }

    public function testAPastEndOfLifeDateMakesTheCycleEndOfLife(): void
    {
        self::assertSame(SupportStatus::EOL, $this->statusOf($this->cycle(null, '2025-12-31')));
    }

    /**
     * Cas limite : la date de fin de vie est *inclusive*. Le jour dit, le
     * support est terminé — pas le lendemain.
     */
    public function testTheEndOfLifeDateIsInclusive(): void
    {
        self::assertSame(SupportStatus::EOL, $this->statusOf($this->cycle(null, self::TODAY)));
    }

    public function testAPastEndOfActiveSupportMeansSecurityFixesOnly(): void
    {
        self::assertSame(
            SupportStatus::SECURITY_ONLY,
            $this->statusOf($this->cycle('2026-01-01', '2029-12-31')),
        );
    }

    public function testAFullySupportedCycleIsSupported(): void
    {
        self::assertSame(
            SupportStatus::SUPPORTED,
            $this->statusOf($this->cycle('2027-12-31', '2029-12-31')),
        );
    }

    /**
     * Le test qui justifie le calcul par dates plutôt que par drapeaux.
     *
     * Les booléens `isEol`/`isEoas` publiés par la source sont figés à l'instant
     * où elle a généré sa réponse. Notre snapshot, lui, est relu pendant 24 à
     * 36 h. Une échéance franchie entre-temps doit se voir immédiatement, sans
     * attendre le prochain rafraîchissement : c'est l'unique raison d'être de
     * cette page.
     */
    public function testDatesPrevailOverStaleFlagsFromTheSource(): void
    {
        $staleCycle = $this->cycle(null, '2026-09-06', isMaintained: true, isEol: false);

        self::assertSame(SupportStatus::EOL, $this->statusOf($staleCycle));
    }

    public function testFlagsAreUsedAsAFallbackWhenNoDateIsPublished(): void
    {
        self::assertSame(
            SupportStatus::EOL,
            $this->statusOf($this->cycle(null, null, isMaintained: false, isEol: true)),
        );

        self::assertSame(
            SupportStatus::SECURITY_ONLY,
            $this->statusOf($this->cycle(null, null, isMaintained: true, isEndOfActiveSupport: true)),
        );

        self::assertSame(
            SupportStatus::SUPPORTED,
            $this->statusOf($this->cycle(null, null, isMaintained: true)),
        );
    }

    /**
     * Ni date ni drapeau exploitable : on l'admet plutôt que de trancher au
     * hasard. Une veille qui invente une réponse ne vaut pas mieux qu'une
     * absence de veille.
     */
    public function testACycleWithoutAnyUsableSignalIsUnknown(): void
    {
        self::assertSame(
            SupportStatus::UNKNOWN,
            $this->statusOf($this->cycle(null, null, isMaintained: false)),
        );
    }
}
