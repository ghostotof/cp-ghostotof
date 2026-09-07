<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Presentation\Command;

use App\Portfolio\Watch\Application\WatchRefreshReport;
use App\Portfolio\Watch\Application\WatchRefresherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rafraîchit le snapshot des cycles de vie, destiné à être exécuté par un
 * CronJob quotidien (décision D5).
 *
 * C'est le seul point d'entrée de l'application qui provoque un appel sortant :
 * aucune requête de visiteur n'atteint jamais endoflife.date.
 *
 * Les codes de sortie sont pensés pour un Job planifié plutôt que pour un
 * humain :
 *
 *  - un slug inconnu du catalogue **ne fait pas échouer** la commande. C'est une
 *    faute de saisie à corriger au backoffice ; la faire échouer condamnerait le
 *    Job à alerter tous les jours pour une coquille.
 *  - une source injoignable **fait échouer** la commande, même quand le snapshot
 *    partiel a été écrit. La panne est généralement transitoire : le code non nul
 *    déclenche la reprise du Job et rend l'incident visible.
 *  - un catalogue vide réussit en silence : sur une base neuve, il n'y a rien à
 *    faire, et ce n'est pas un incident.
 */
#[AsCommand(
    name: 'app:watch:refresh',
    description: 'Interroge les sources de veille et met à jour le snapshot servi par /api/watch.',
)]
final class RefreshWatchCommand extends Command
{
    public function __construct(private readonly WatchRefresherInterface $refresher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Interroge les sources et affiche le résultat sans rien écrire en base.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Simulation : aucune écriture ne sera effectuée.');
        }

        $report = $this->refresher->refresh(new \DateTimeImmutable(), $dryRun);

        $this->describe($io, $report);

        return [] === $report->failedSlugs ? Command::SUCCESS : Command::FAILURE;
    }

    private function describe(SymfonyStyle $io, WatchRefreshReport $report): void
    {
        if ([] !== $report->unknownSlugs) {
            $io->warning(sprintf(
                'Produits inconnus de la source, à corriger au backoffice : %s',
                implode(', ', $report->unknownSlugs),
            ));
        }

        if ([] !== $report->failedSlugs) {
            $io->error(sprintf(
                'Sources injoignables pour : %s. Le snapshot précédent reste servi pour ces entrées.',
                implode(', ', $report->failedSlugs),
            ));
        }

        if (0 === $report->refreshedCount) {
            if ([] === $report->failedSlugs) {
                $io->success('Aucun produit surveillé : rien à rafraîchir.');
            }

            return;
        }

        $message = sprintf('%d produit(s) rafraîchi(s).', $report->refreshedCount);

        if ($report->persisted) {
            $io->success($message);

            return;
        }

        $io->info($message.' Rien n\'a été écrit.');
    }
}
