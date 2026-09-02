<?php

declare(strict_types=1);

namespace App\Contact\Presentation\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge les messages Messenger en échec plus vieux qu'un certain âge.
 *
 * Contexte RGPD (point d'audit B6) : un message de contact dont l'envoi SMTP
 * échoue est routé vers le transport `failed` (doctrine://default?queue_name=failed,
 * cf. config/packages/messenger.yaml), soit la table `messenger_messages`. Il
 * y stagne indéfiniment avec les données personnelles du visiteur (nom, email,
 * corps libre). Cette commande, lancée périodiquement (CronJob Kubernetes,
 * cf. k8s/base/messenger-purge-cronjob.yaml), applique une durée de rétention.
 *
 * La file `failed` n'accueille aujourd'hui que des SendContactMessageMessage
 * (unique message asynchrone du projet) : filtrer sur queue_name = 'failed'
 * revient donc à ne purger que ces messages-là.
 */
#[AsCommand(
    name: 'app:contact:purge-failed-messages',
    description: 'Supprime les messages de contact en échec (transport Messenger "failed") au-delà d\'une durée de rétention.',
)]
final class PurgeFailedContactMessagesCommand extends Command
{
    private const string DEFAULT_MAX_AGE = '30 days';
    private const string FAILED_QUEUE = 'failed';

    /** Table par défaut du transport Doctrine Messenger (aucun table_name= dans le DSN). */
    private const string MESSENGER_TABLE = 'messenger_messages';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            null,
            InputOption::VALUE_REQUIRED,
            'Âge minimal des messages à purger, exprimé en intervalle relatif PHP (ex. "30 days", "12 hours").',
            self::DEFAULT_MAX_AGE,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawOlderThan = $input->getOption('older-than');
        \assert(\is_string($rawOlderThan));
        $olderThan = trim($rawOlderThan);

        try {
            $threshold = new \DateTimeImmutable('-'.$olderThan);
        } catch (\DateMalformedStringException|\Exception) {
            $io->error(sprintf('Intervalle invalide : "%s". Exemples valides : "30 days", "12 hours".', $olderThan));

            return Command::INVALID;
        }

        try {
            $deleted = $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE queue_name = :queue AND created_at < :threshold', self::MESSENGER_TABLE),
                ['queue' => self::FAILED_QUEUE, 'threshold' => $threshold->format('Y-m-d H:i:s')],
            );
        } catch (TableNotFoundException) {
            // La table n'est créée qu'au premier passage d'un message par le
            // transport Doctrine : aucune, donc rien à purger.
            $io->success('Aucune table de messages en échec : rien à purger.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d message(s) en échec antérieur(s) à %s purgé(s).', $deleted, $threshold->format('Y-m-d H:i:s')));

        return Command::SUCCESS;
    }
}
