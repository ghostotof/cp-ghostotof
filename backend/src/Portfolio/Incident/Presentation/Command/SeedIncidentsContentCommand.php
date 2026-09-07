<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Presentation\Command;

use App\Portfolio\Incident\Application\IncidentAdministratorInterface;
use App\Portfolio\Incident\Domain\Repository\IncidentRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Peuple/réinitialise les incidents affichés sur /incidents (fr/en), servis
 * depuis la base via GET /api/incidents/{locale}.
 *
 * Attention : purge par locale avant de recréer. Rejouer cette commande écrase
 * les incidents ajoutés depuis le backoffice — elle sert à poser le contenu de
 * référence sur un environnement neuf, pas à synchroniser une base éditée.
 *
 * Les quatre entrées ci-dessous sont des pannes réelles de ce projet, datées
 * d'après les tags Git correspondants. Le retrait du verbe RBAC `pods/exec`
 * n'y figure pas : c'était une décision d'audit, pas un incident, et le ranger
 * ici serait une erreur de catégorie.
 */
#[AsCommand(
    name: 'app:incidents:seed',
    description: 'Peuple/réinitialise les incidents de production (fr/en) avec leur contenu de référence.',
)]
final class SeedIncidentsContentCommand extends Command
{
    public function __construct(
        private readonly IncidentRepositoryInterface $incidentRepository,
        private readonly IncidentAdministratorInterface $incidentAdministrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->content() as $localeValue => $incidents) {
            $locale = Locale::from($localeValue);

            foreach ($this->incidentRepository->findByLocale($locale) as $existing) {
                $this->incidentRepository->remove($existing);
            }

            foreach ($incidents as $position => $incident) {
                $this->incidentAdministrator->create(
                    $locale,
                    $incident['title'],
                    $incident['version'],
                    new \DateTimeImmutable($incident['occurredAt']),
                    $incident['impact'],
                    $incident['rootCause'],
                    $incident['resolution'],
                    $incident['invariant'],
                    $position,
                );
            }

            $io->success(sprintf('[%s] %d incident(s).', $localeValue, \count($incidents)));
        }

        return Command::SUCCESS;
    }

    /**
     * Classés du plus récent au plus ancien.
     *
     * @return array<string, list<array{title: string, version: string, occurredAt: string, impact: string, rootCause: string, resolution: string, invariant: string}>>
     */
    private function content(): array
    {
        return [
            'fr' => [
                [
                    'title' => 'Un correctif déployé, vérifié, et pourtant sans effet',
                    'version' => 'v0.7.0',
                    'occurredAt' => '2026-09-06',
                    'impact' => <<<'TXT'
                        Deux déploiements de préprod consécutifs ont échoué sur exactement la même erreur, alors que le correctif était poussé, l'image reconstruite et le tag correct. Aucun impact en production : la porte de promotion a tenu.
                        TXT,
                    'rootCause' => <<<'TXT'
                        Le tag Git avait été recréé sous le même nom après un premier échec. Sans `imagePullPolicy` explicite, un conteneur retombe sur `IfNotPresent` : le nœud a resservi l'image qu'il détenait déjà en cache sous ce nom. Le Job affichait donc le bon tag tout en exécutant un build antérieur. Seule la comparaison du digest publié dans le registre avec celui rapporté par le pod l'a mise en évidence.
                        TXT,
                    'resolution' => <<<'TXT'
                        `imagePullPolicy: Always` sur le Job de migration, puis sur les trois déploiements. Le conteneur nginx du backend conserve le défaut : son tag amont est immuable.
                        TXT,
                    'invariant' => <<<'TXT'
                        Quand un correctif vérifié paraît sans effet, cesser de douter du correctif et aller vérifier ce qui tourne réellement. Un tag Git est mutable : le nom déployé ne prouve pas le contenu déployé, seul le digest le prouve.
                        TXT,
                ],
                [
                    'title' => "Le backoffice ne pouvait plus rien créer, sans que rien ne le signale",
                    'version' => 'v0.7.0',
                    'occurredAt' => '2026-09-06',
                    'impact' => <<<'TXT'
                        Toute création de contenu depuis l'administration — une technologie, une carte « À propos », un principe qualité — échouait en production sur une violation de clé primaire. Le défaut était présent depuis le peuplement initial des données et n'avait jamais été déclenché, faute d'avoir créé une ligne depuis.
                        TXT,
                    'rootCause' => <<<'TXT'
                        Les colonnes `id` sont déclarées `GENERATED BY DEFAULT AS IDENTITY`. Sous cette forme, une ligne insérée avec un identifiant explicite n'avance pas la séquence associée. Les tables de contenu ayant été peuplées ainsi, leurs séquences étaient restées à 1 pour des identifiants maximum atteignant 44 : le prochain identifiant réclamé était donc déjà pris.
                        TXT,
                    'resolution' => <<<'TXT'
                        Une migration resynchronise génériquement toutes les colonnes d'identité du schéma, avant la première insertion. Le balayage est générique plutôt que nominatif, pour couvrir aussi les tables ajoutées ensuite.
                        TXT,
                    'invariant' => <<<'TXT'
                        Un défaut sans symptôme n'est pas un défaut absent : il attend l'exécution qui le déclenchera. Celui-ci dormait depuis des semaines et n'a été révélé que par une migration insérant une ligne — puis arrêté par la préprod, avant la production.
                        TXT,
                ],
                [
                    'title' => 'RabbitMQ en CrashLoopBackOff après un durcissement de sécurité',
                    'version' => 'v0.5.0',
                    'occurredAt' => '2026-09-03',
                    'impact' => <<<'TXT'
                        Formulaire de contact en erreur 500 pendant une quinzaine de minutes en production : les messages ne pouvaient plus être publiés dans la file.
                        TXT,
                    'rootCause' => <<<'TXT'
                        L'ajout de `runAsNonRoot` et `fsGroup` au manifeste RabbitMQ a rendu le fichier `.erlang.cookie` accessible au groupe. Erlang refuse de démarrer dans ces conditions. Le fichier vivant sur un volume persistant, il a survécu au retour arrière du manifeste : la panne s'est poursuivie après l'annulation du changement qui l'avait causée.
                        TXT,
                    'resolution' => <<<'TXT'
                        Correction du mode du fichier sur le volume, livrée en correctif v0.5.1. Des tests de fumée couvrant Postgres, RabbitMQ et le worker ont été ajoutés au pipeline dans la foulée.
                        TXT,
                    'invariant' => <<<'TXT'
                        Un changement de `securityContext` sur un service à état ne se valide pas par un `apply --dry-run` : celui-ci ne dit rien du comportement au démarrage sur un volume déjà initialisé. Il faut un déploiement réel en préprod, avec `rollout status` et lecture des journaux, avant toute promotion. Et un retour arrière de manifeste ne répare pas ce qui a été écrit sur un volume persistant.
                        TXT,
                ],
                [
                    'title' => 'La préprod coupée de sa base de données par une règle réseau',
                    'version' => 'v0.4.0',
                    'occurredAt' => '2026-09-02',
                    'impact' => <<<'TXT'
                        Déploiement de préprod en échec, migrations Doctrine en délai d'attente dépassé sur la connexion à PostgreSQL. La préprod est restée inaccessible même après le retour arrière automatique, celui-ci ne supprimant pas les règles réseau.
                        TXT,
                    'rootCause' => <<<'TXT'
                        La règle autorisant l'accès aux bases filtrait sur le label `app.kubernetes.io/part-of`. Le transformateur `labels:` de kustomize pose ce label sur les métadonnées des ressources, mais pas sur `spec.template.metadata.labels` — donc pas sur les pods eux-mêmes. Combinée à un `default-deny-ingress`, la règle n'autorisait plus personne.
                        TXT,
                    'resolution' => <<<'TXT'
                        Filtrage sur un label effectivement porté par les pods, livré en v0.4.1.
                        TXT,
                    'invariant' => <<<'TXT'
                        Une règle réseau se vérifie sur les pods, jamais sur les manifestes : un label posé par kustomize n'atteint pas forcément le gabarit de pod, et aucun `--dry-run` ne le montre. Corollaire : la procédure de retour arrière doit couvrir les objets réseau, pas seulement les déploiements.
                        TXT,
                ],
            ],
            'en' => [
                [
                    'title' => 'A fix deployed, verified, and yet with no effect',
                    'version' => 'v0.7.0',
                    'occurredAt' => '2026-09-06',
                    'impact' => <<<'TXT'
                        Two consecutive preprod deployments failed on exactly the same error, while the fix was pushed, the image rebuilt and the tag correct. No production impact: the promotion gate held.
                        TXT,
                    'rootCause' => <<<'TXT'
                        The Git tag had been re-cut under the same name after a first failure. Without an explicit `imagePullPolicy`, a container falls back to `IfNotPresent`: the node served the image it already held in cache under that name. The Job therefore displayed the right tag while running an earlier build. Only comparing the digest published in the registry with the one reported by the pod made it visible.
                        TXT,
                    'resolution' => <<<'TXT'
                        `imagePullPolicy: Always` on the migration Job, then on the three deployments. The backend's nginx container keeps the default: its upstream tag is immutable.
                        TXT,
                    'invariant' => <<<'TXT'
                        When a verified fix appears to have no effect, stop doubting the fix and go check what is actually running. A Git tag is mutable: the deployed name does not prove the deployed content — only the digest does.
                        TXT,
                ],
                [
                    'title' => 'The backoffice could no longer create anything, and nothing said so',
                    'version' => 'v0.7.0',
                    'occurredAt' => '2026-09-06',
                    'impact' => <<<'TXT'
                        Every content creation from the admin area — a technology, an "About" card, a quality principle — failed in production on a primary key violation. The defect had been there since the initial data load and had never been triggered, no row having been created since.
                        TXT,
                    'rootCause' => <<<'TXT'
                        The `id` columns are declared `GENERATED BY DEFAULT AS IDENTITY`. In that form, a row inserted with an explicit identifier does not advance the associated sequence. The content tables had been populated that way, so their sequences had stayed at 1 for maximum identifiers reaching 44: the next identifier requested was already taken.
                        TXT,
                    'resolution' => <<<'TXT'
                        A migration generically resynchronises every identity column in the schema, before the first insert. The sweep is generic rather than table-by-table, so it also covers tables added later.
                        TXT,
                    'invariant' => <<<'TXT'
                        A defect without a symptom is not an absent defect: it is waiting for the run that will trigger it. This one had been dormant for weeks and surfaced only through a migration inserting a row — then was stopped by preprod, before production.
                        TXT,
                ],
                [
                    'title' => 'RabbitMQ in CrashLoopBackOff after a security hardening',
                    'version' => 'v0.5.0',
                    'occurredAt' => '2026-09-03',
                    'impact' => <<<'TXT'
                        Contact form returning 500 for about fifteen minutes in production: messages could no longer be published to the queue.
                        TXT,
                    'rootCause' => <<<'TXT'
                        Adding `runAsNonRoot` and `fsGroup` to the RabbitMQ manifest made the `.erlang.cookie` file group-readable. Erlang refuses to start under those conditions. The file living on a persistent volume, it survived the manifest rollback: the outage continued after the change that caused it had been reverted.
                        TXT,
                    'resolution' => <<<'TXT'
                        File mode fixed on the volume, shipped as hotfix v0.5.1. Smoke tests covering Postgres, RabbitMQ and the worker were added to the pipeline right after.
                        TXT,
                    'invariant' => <<<'TXT'
                        A `securityContext` change on a stateful service cannot be validated by `apply --dry-run`: that says nothing about startup behaviour on an already-initialised volume. It needs a real preprod rollout, with `rollout status` and log reading, before any promotion. And a manifest rollback does not repair what was written to a persistent volume.
                        TXT,
                ],
                [
                    'title' => 'Preprod cut off from its database by a network policy',
                    'version' => 'v0.4.0',
                    'occurredAt' => '2026-09-02',
                    'impact' => <<<'TXT'
                        Preprod deployment failed, Doctrine migrations timing out on the PostgreSQL connection. Preprod stayed unreachable even after the automatic rollback, which does not delete network policies.
                        TXT,
                    'rootCause' => <<<'TXT'
                        The rule allowing database access filtered on the `app.kubernetes.io/part-of` label. Kustomize's `labels:` transformer sets that label on resource metadata, but not on `spec.template.metadata.labels` — so not on the pods themselves. Combined with a `default-deny-ingress`, the rule allowed no one.
                        TXT,
                    'resolution' => <<<'TXT'
                        Filtering on a label the pods actually carry, shipped in v0.4.1.
                        TXT,
                    'invariant' => <<<'TXT'
                        A network policy is verified against pods, never against manifests: a label set by kustomize does not necessarily reach the pod template, and no `--dry-run` will show it. Corollary: the rollback procedure must cover network objects, not just deployments.
                        TXT,
                ],
            ],
        ];
    }
}
