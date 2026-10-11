<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Command;

use App\Shared\Application\MessengerRoundTripProbeInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Smoke préprod du trajet API → worker (issue #470), lancé par le Job
 * k8s/base/messenger-probe-job.yaml après chaque déploiement : son code de
 * sortie fait échouer `smoke-test-preprod`, donc déclenche `rollback-preprod`.
 *
 * Un écart de clé de MAC entre `backend` et `worker` (un `KEY_CONTEXT` mal
 * déployé, un worker resté sur l'ancienne release) laissait la préprod verte
 * avec chaque message dans `failed` : `rollout status` ne regardait que les
 * pods.
 */
#[AsCommand(
    name: 'app:messenger:probe',
    description: 'Met un message témoin en file et attend que le worker l\'ait traité (smoke préprod).',
)]
final class MessengerProbeCommand extends Command
{
    private const int DEFAULT_TIMEOUT_SECONDS = 60;

    private const int MAX_TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly MessengerRoundTripProbeInterface $probe,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'timeout',
            null,
            InputOption::VALUE_REQUIRED,
            \sprintf('Délai d\'attente en secondes, de 1 à %d.', self::MAX_TIMEOUT_SECONDS),
            (string) self::DEFAULT_TIMEOUT_SECONDS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $timeout = $input->getOption('timeout');

        if (!\is_string($timeout) || 1 !== preg_match('/\A[1-9]\d{0,2}\z/', $timeout) || (int) $timeout > self::MAX_TIMEOUT_SECONDS) {
            $io->error(\sprintf('--timeout attend un nombre entier de secondes, de 1 à %d.', self::MAX_TIMEOUT_SECONDS));

            return Command::INVALID;
        }

        if ($this->probe->probe((int) $timeout)) {
            $io->success('Le worker a consommé le message témoin.');

            return Command::SUCCESS;
        }

        $io->error(\sprintf(
            'Le worker n\'a pas consommé le message témoin en %d s. Vérifier `failed` (messenger:failed:show, une enveloppe refusée y part sans réessai), '
            .'les journaux du worker, que `backend` et `worker` tournent la même release, et que la base est joignable depuis ce pod.',
            (int) $timeout,
        ));

        return Command::FAILURE;
    }
}
