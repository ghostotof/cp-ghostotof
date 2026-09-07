<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Presentation\Command;

use App\Portfolio\Watch\Application\WatchRefreshReport;
use App\Portfolio\Watch\Application\WatchRefresherInterface;
use App\Portfolio\Watch\Presentation\Command\RefreshWatchCommand;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test unitaire, et non KernelTestCase comme les autres commandes du projet :
 * cette commande-ci est la seule qui déclenche un appel sortant. La faire
 * passer par le conteneur réel injecterait le vrai client HTTP et enverrait la
 * suite de tests sur Internet — précisément ce que la spécification interdit.
 * Le rafraîchisseur est donc substitué ; ce qui est vérifié ici, c'est ce que
 * la commande en fait : son résumé et son code de sortie.
 */
final class RefreshWatchCommandTest extends TestCase
{
    private WatchRefresherInterface&Stub $refresher;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->refresher = self::createStub(WatchRefresherInterface::class);
        $this->tester = new CommandTester(new RefreshWatchCommand($this->refresher));
    }

    private function givenReport(WatchRefreshReport $report): void
    {
        $this->refresher->method('refresh')->willReturn($report);
    }

    public function testASuccessfulRefreshReportsTheCountAndSucceeds(): void
    {
        $this->givenReport(new WatchRefreshReport(7, [], [], true));

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('7', $this->tester->getDisplay());
    }

    /**
     * Un slug inconnu du catalogue n'est pas une panne : la commande le signale
     * pour qu'on corrige la saisie, mais réussit — sinon le Job planifié
     * échouerait tous les jours à cause d'une faute de frappe.
     */
    public function testUnknownSlugsAreReportedWithoutFailingTheCommand(): void
    {
        $this->givenReport(new WatchRefreshReport(7, ['phpp'], [], true));

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('phpp', $this->tester->getDisplay());
    }

    /**
     * À l'inverse, une source injoignable est une panne, généralement
     * transitoire : le snapshot partiel est bien écrit — la page reste à jour —
     * mais le code de sortie non nul permet au Job de réessayer et à la
     * supervision de le voir.
     */
    public function testAPartialRefreshWritesButStillFails(): void
    {
        $this->givenReport(new WatchRefreshReport(6, [], ['nginx'], true));

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('nginx', $this->tester->getDisplay());
    }

    public function testATotalOutageFails(): void
    {
        $this->givenReport(new WatchRefreshReport(0, [], ['php', 'nginx'], false));

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    /**
     * Base neuve : il n'y a rien à rafraîchir, ce n'est pas une erreur. Sortir
     * en échec ici ferait passer une installation normale pour un incident.
     */
    public function testAnEmptyCatalogSucceedsQuietly(): void
    {
        $this->givenReport(new WatchRefreshReport(0, [], [], false));

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testTheDryRunOptionIsForwardedAndAnnounced(): void
    {
        $seenDryRun = null;
        $this->refresher->method('refresh')->willReturnCallback(
            function (\DateTimeImmutable $now, bool $dryRun) use (&$seenDryRun): WatchRefreshReport {
                $seenDryRun = $dryRun;

                return new WatchRefreshReport(7, [], [], false);
            },
        );

        $exitCode = $this->tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($seenDryRun);
        self::assertStringContainsString('imulation', $this->tester->getDisplay());
    }
}
