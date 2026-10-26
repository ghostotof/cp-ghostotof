<?php

declare(strict_types=1);

namespace App\Contact\Presentation\Command;

use App\Shared\Domain\Exception\InvalidRetentionPeriodException;
use App\Shared\Domain\ValueObject\RetentionPeriod;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Psr\Clock\ClockInterface;
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
 * La purge porte sur toute la file `failed` : messages de contact, mais
 * aussi invitations (SendAccountInvitationMessage, qui ne porte que des
 * identifiants) et enveloppes en échec de décodage (issue #444). Elle se fait
 * en SQL, sans rien désérialiser.
 *
 * Le seuil est comparé en heure murale **UTC** (issue #446) : c'est ainsi que
 * le transport Doctrine de Messenger écrit `created_at`
 * (`new DateTimeImmutable('UTC')`, colonne sans fuseau). Formaté dans le
 * fuseau de PHP (Europe/Paris), il purgeait une à deux heures trop tôt.
 *
 * La conversion vient **après** le calcul : « 30 days » se compte en jours du
 * calendrier de Paris (règle de #445), si bien qu'une fenêtre qui contient un
 * changement d'heure dure 721 ou 719 heures. Ce n'est donc pas « maintenant UTC
 * moins 30 jours » (issue #709). Une durée en heures, minutes ou secondes
 * (« 12 hours ») se compte en revanche en temps écoulé (issue #710).
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

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            null,
            InputOption::VALUE_REQUIRED,
            'Âge minimal des messages à purger, exprimé en intervalle relatif PHP, strictement positif (ex. "30 days", "12 hours").',
            self::DEFAULT_MAX_AGE,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawOlderThan = $input->getOption('older-than');
        \assert(\is_string($rawOlderThan));

        try {
            $retentionPeriod = RetentionPeriod::fromString($rawOlderThan, $this->clock->now());
        } catch (InvalidRetentionPeriodException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        // Dans l'horloge du transport, qui écrit `created_at` en UTC.
        $threshold = $retentionPeriod->threshold()->setTimezone(new DateTimeZone('UTC'));

        try {
            $deleted = $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE queue_name = :queue AND created_at < :threshold', self::MESSENGER_TABLE),
                ['queue' => self::FAILED_QUEUE, 'threshold' => $threshold->format('Y-m-d H:i:s')],
            );
        } catch (TableNotFoundException) {
            // La table n'est créée qu'au premier passage d'un message par le
            // transport Doctrine : aucune, donc rien à purger.
            $io->success('Aucune table de messages en échec : rien à purger.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d message(s) en échec antérieur(s) à %s UTC purgé(s).', $deleted, $threshold->format('Y-m-d H:i:s')));

        return Command::SUCCESS;
    }
}
