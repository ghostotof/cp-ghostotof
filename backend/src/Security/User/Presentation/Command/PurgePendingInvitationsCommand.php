<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\Command;

use App\Security\User\Application\PendingInvitationPurgerInterface;
use App\Security\User\Domain\Exception\InvalidPurgeRetentionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge les comptes invités depuis le backoffice jamais activés au-delà d'une
 * durée de rétention (issue #238).
 *
 * Contexte RGPD (registre §3.2, minimisation art. 5-1-c) : un compte invité
 * (App\Security\User\Application\CpgUserInviter) porte un e-mail — une donnée
 * personnelle — tant qu'il n'a pas défini son mot de passe. Une invitation
 * jamais suivie d'effet n'a plus de raison de conserver cette adresse
 * indéfiniment. Le seuil (30 jours par défaut) court depuis la *dernière*
 * invitation (`CpgUser::markInvited`, remis à jour par
 * `CpgUserInviter::reinvite`), pas depuis la création du compte. La règle
 * métier — jetons supprimés avec le compte (FK ON DELETE CASCADE), garde
 * anti-lockout ROLE_SUPER, aucune notification à la personne (l'adresse
 * purgée est précisément ce qu'on supprime) — vit dans
 * App\Security\User\Application\PendingInvitationPurger ; cette commande n'en
 * est que l'habillage CLI, planifié quotidiennement par
 * k8s/base/messenger-purge-cronjob.yaml. Round de correction (revue) : le
 * purgeur refuse tout seuil qui ne serait pas strictement dans le passé
 * (--older-than négatif ou nul), exit Command::INVALID ici.
 */
#[AsCommand(
    name: 'app:user:purge-pending-invitations',
    description: 'Supprime les comptes invités jamais activés au-delà d\'une durée depuis leur dernière invitation (RGPD, minimisation).',
)]
final class PurgePendingInvitationsCommand extends Command
{
    private const string DEFAULT_MAX_AGE = '30 days';

    public function __construct(private readonly PendingInvitationPurgerInterface $purger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Âge minimal de la dernière invitation, exprimé en intervalle relatif PHP, durée strictement positive (ex. "30 days", "12 hours").',
                self::DEFAULT_MAX_AGE,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'N\'écrit rien : liste les comptes qui seraient purgés sans les supprimer ni journaliser d\'événement.',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawOlderThan = $input->getOption('older-than');
        \assert(\is_string($rawOlderThan));
        $olderThan = trim($rawOlderThan);
        $dryRun = (bool) $input->getOption('dry-run');

        $maxAge = $this->parseMaxAge($olderThan, $io);

        if (null === $maxAge) {
            return Command::INVALID;
        }

        try {
            $result = $this->purger->purge($maxAge, $dryRun);
        } catch (InvalidPurgeRetentionException $e) {
            // Garde métier du purgeur (protège tout appelant, pas seulement
            // cette commande) : un "--older-than" négatif ou nul avance le
            // seuil au lieu de le reculer.
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->title(sprintf(
            '%sInvitations en attente antérieures au %s',
            $dryRun ? 'Simulation — ' : '',
            $result->threshold->format('Y-m-d H:i:s'),
        ));

        if ([] === $result->purged) {
            $io->text('Rien à purger.');
        } else {
            $io->section($dryRun ? 'Comptes qui seraient purgés' : 'Comptes purgés');
            $io->listing($result->purged);
        }

        if ([] !== $result->skipped) {
            $io->section('Comptes ignorés (ROLE_SUPER en attente d\'activation)');
            $io->listing($result->skipped);
            $io->note('Un compte ROLE_SUPER en attente n\'est jamais purgé automatiquement : décision manuelle (comme le dernier super-administrateur à la suppression).');
        }

        // Succès même à zéro purge : c'est le cas normal de presque chaque
        // exécution du CronJob, jamais une erreur.
        $io->success($dryRun ? 'Simulation terminée.' : 'Purge terminée.');

        return Command::SUCCESS;
    }

    private function parseMaxAge(string $olderThan, SymfonyStyle $io): ?\DateInterval
    {
        try {
            // Même garde que app:contact:purge-failed-messages : un intervalle
            // relatif que \DateTimeImmutable elle-même refuse est rejeté ici,
            // avant de demander la même chaîne à DateInterval ci-dessous.
            // \Exception seul suffit à couvrir les deux cas réels : depuis
            // PHP 8.3, une chaîne illisible fait lever
            // DateMalformedStringException ici puis
            // DateMalformedIntervalStringException plus bas — les deux
            // étendent directement \Exception, jamais l'une l'autre. Cette
            // garde ne juge que la *syntaxe* de l'intervalle ; qu'il soit
            // positif, négatif ou nul est vérifié plus loin par
            // PendingInvitationPurger::purge() (InvalidPurgeRetentionException),
            // seul endroit qui connaît "maintenant".
            new \DateTimeImmutable('-'.$olderThan);

            return \DateInterval::createFromDateString($olderThan);
        } catch (\Exception) {
            $io->error(sprintf('Intervalle invalide : "%s". Exemples valides : "30 days", "12 hours".', $olderThan));

            return null;
        }
    }
}
