<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Command;

use App\Mcp\Connection\Application\McpConnectionPurgeResult;
use App\Mcp\Connection\Application\McpConnectionPurgerInterface;
use App\Mcp\Connection\Domain\Exception\McpPurgeRetentionTooShortException;
use App\Shared\Domain\Exception\InvalidRetentionPeriodException;
use App\Shared\Domain\ValueObject\RetentionPeriod;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * La purge quotidienne des connexions MCP et de leurs jetons (#649) : marque
 * expirées les connexions dont l'échéance est passée (cause `expired`, audit
 * acteur `system`), supprime les codes et jetons morts, puis les connexions
 * révoquées ou expirées depuis plus que la rétention. La règle vit dans
 * {@see McpConnectionPurgerInterface} ; cette commande n'en est que
 * l'habillage CLI, planifiée chaque nuit par
 * `k8s/base/messenger-purge-cronjob.yaml`.
 *
 * La rétention par défaut est en configuration
 * (`app.mcp.revoked_connection_retention`, six mois), lue comme toute durée de
 * purge par {@see RetentionPeriod} ; une durée illisible, une date, ou moins
 * d'un jour sont refusées en `Command::INVALID`. Pas de `--dry-run` (décision
 * 6 de #649) : rien de ce qu'elle supprime n'est regrettable.
 */
#[AsCommand(
    name: 'app:mcp:purge',
    description: 'Marque les connexions MCP expirées, supprime les codes et jetons morts et les connexions révoquées au-delà de la rétention.',
)]
final class PurgeMcpConnectionsCommand extends Command
{
    public function __construct(
        private readonly McpConnectionPurgerInterface $purger,
        private readonly ClockInterface $clock,
        #[Autowire('%app.mcp.revoked_connection_retention%')]
        private readonly string $defaultRetention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            null,
            InputOption::VALUE_REQUIRED,
            'Rétention des connexions révoquées ou expirées, intervalle relatif PHP d\'au moins un jour (ex. "6 months", "90 days").',
            $this->defaultRetention,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $olderThan = $input->getOption('older-than');
        \assert(\is_string($olderThan));

        try {
            $retention = RetentionPeriod::fromString($olderThan, $this->clock->now());
            $result = $this->purger->purge($retention);
        } catch (InvalidRetentionPeriodException|McpPurgeRetentionTooShortException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $io->title(\sprintf(
            'Connexions MCP — rétention « %s », seuil %s',
            $retention->expression(),
            $result->threshold->format('Y-m-d H:i:s P'),
        ));
        $this->report($io, $result);

        // Succès même à zéro : c'est le cas de presque chaque nuit.
        $io->success('Purge terminée.');

        return Command::SUCCESS;
    }

    /**
     * Par UUID seulement (#363) : cette sortie est le journal du pod du
     * CronJob — ni compte, ni identifiant de client, ni empreinte de jeton.
     */
    private function report(SymfonyStyle $io, McpConnectionPurgeResult $result): void
    {
        if ([] === $result->expired) {
            $io->text('Aucune connexion expirée à marquer.');
        } else {
            $io->section('Connexions marquées expirées (UUID)');
            $io->listing($result->expired);
        }

        $io->listing([
            \sprintf('Codes d\'autorisation supprimés : %d', $result->deletedAuthorizationCodes),
            \sprintf('Jetons d\'accès supprimés : %d', $result->deletedAccessTokens),
            \sprintf('Jetons de rafraîchissement supprimés : %d', $result->deletedRefreshTokens),
            \sprintf('Connexions révoquées avant le seuil supprimées : %d', $result->deletedConnections),
        ]);
    }
}
