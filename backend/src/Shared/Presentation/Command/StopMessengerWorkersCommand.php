<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Command;

use App\Shared\Application\WorkerRestartSignalInterface;
use App\Shared\Application\WorkerRestartSignalNotSavedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `messenger:stop-workers`, dont le succès est vérifié (issue #528), lancé par
 * le Job k8s/base/messenger-stop-workers-job.yaml — surtout à l'étape 4 de la
 * rotation d'`APP_SECRET` (k8s/README.md), où la ligne du signal signée sous
 * l'ancienne clé est refusée à chaque tour du worker.
 *
 * Celle de Symfony reste disponible, mais ignore le retour de `save()` : sur
 * le cache en base, elle répondait `[OK]` et sortait en 0 même quand
 * PostgreSQL refusait l'écriture, et le Job était vert pour rien. Celle-ci
 * sort en 1 si le signal ne se relit pas.
 */
#[AsCommand(
    name: 'app:messenger:stop-workers',
    description: 'Demande aux workers de s\'arrêter après leur message en cours, et vérifie que le signal est enregistré.',
)]
final class StopMessengerWorkersCommand extends Command
{
    public function __construct(
        private readonly WorkerRestartSignalInterface $restartSignal,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->restartSignal->send();
        } catch (WorkerRestartSignalNotSavedException $failure) {
            $io->error([
                $failure->getMessage(),
                'Aucun worker ne s\'arrêtera. Vérifier que la base est joignable en écriture depuis ce pod (journaux du canal `cache`), puis relancer le Job.',
            ]);

            return Command::FAILURE;
        }

        $io->success('Signal d\'arrêt écrit et relu : chaque worker s\'arrêtera après son message en cours, et Kubernetes le relancera.');

        return Command::SUCCESS;
    }
}
