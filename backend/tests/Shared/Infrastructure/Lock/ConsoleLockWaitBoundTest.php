<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Lock;

use App\Shared\Infrastructure\Lock\PostgresLockedTransaction;
use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Aucune commande console des pods n'attend un verrou PostgreSQL indéfiniment
 * (issue #525), comme aucune requête web (FpmLockWaitBoundTest, #272).
 *
 * Sous FPM, `env[PGOPTIONS] = "-c lock_timeout=5s"` (www.prod.conf) borne toute
 * attente de verrou de la session. La console ne lit pas ce fichier : sans
 * rien d'autre, sa session vaut `lock_timeout = 0` (mesuré), et seuls les trois
 * verrous applicatifs posaient une borne, pour leur seule transaction (#512).
 * Un `wrapInTransaction()` de réordonnancement, un verrou de ligne de l'ORM dans
 * un handler Messenger ou un DDL de migration attendaient sans fin, jusqu'au
 * délai du Job s'il y en avait un.
 *
 * Chaque conteneur de l'image backend qui exécute la console porte donc
 * `PGOPTIONS` dans son `env:`, **à la valeur exacte de www.prod.conf** : libpq
 * la lit pour toute connexion du processus — l'ORM, mais aussi la connexion que
 * le composant Lock ouvre lui-même par `DriverManager` et qu'un middleware DBAL
 * du bundle n'aurait pas vue. Un `env:` explicite plutôt qu'une ligne de
 * `backend-config` : ce ConfigMap est écrit deux fois (overlays preprod et prod),
 * et la borne se lit dans le manifeste qui la reçoit.
 *
 * Le Job de migration la reçoit aussi (décision du 2026-10-12) : une migration
 * qui attend un verrou plus de 5 s échoue, le déploiement s'arrête avant
 * `apply -k` et l'ancienne release continue de servir — au lieu de laisser sa
 * demande de verrou exclusif en file devant chaque requête de l'API. Une
 * migration qui doit attendre plus longtemps l'annonce elle-même par
 * `SET LOCAL lock_timeout`.
 *
 * Les manifestes viennent du recensement par l'image de KubernetesManifests,
 * pas de la liste de SystemCachePodVolumeTest : un nouveau manifeste qui
 * exécute l'image sans la borne fait rougir ce test de lui-même.
 */
final class ConsoleLockWaitBoundTest extends TestCase
{
    /**
     * Les conteneurs de l'image qui n'ont pas besoin de la borne dans leur
     * `env:`, par manifeste, chacun avec sa raison.
     */
    private const array EXEMPT = [
        'backend-deployment.yaml' => [
            // Le processus de ce conteneur est php-fpm : ses workers reçoivent la
            // borne du pool (`env[PGOPTIONS]`, www.prod.conf, FpmLockWaitBoundTest),
            // et aucune console n'y tourne hors des `exec` de lecture du runbook,
            // qui posent leur propre PGOPTIONS (OperatorExecBoundaryTest).
            'php-fpm' => 'borne posée par le pool FPM',
        ],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function manifests(): iterable
    {
        foreach (KubernetesManifests::base()->manifestsRunningTheBackendImage() as $manifest) {
            yield $manifest => [$manifest];
        }
    }

    /**
     * Le recensement par l'image trouve bien les manifestes de la console :
     * une expression devenue fausse ne doit pas rendre ce test vide.
     */
    public function testTheCensusFindsTheConsoleManifests(): void
    {
        $found = KubernetesManifests::base()->manifestsRunningTheBackendImage();

        foreach (['migrate-job.yaml', 'seed-job.yaml', 'messenger-worker-deployment.yaml', 'watch-refresh-cronjob.yaml'] as $expected) {
            self::assertContains($expected, $found);
        }
    }

    public function testTheBoundIsALockTimeout(): void
    {
        self::assertMatchesRegularExpression('/^-c lock_timeout=\d+s\z/', $this->fpmPgOptions());
    }

    /**
     * Le filet du squelette (#512), gardé pour les sessions sans borne (dev,
     * CI), donne la même valeur que les pods : une attente se borne de même
     * partout.
     */
    public function testTheSkeletonFallbackMatchesThePodBound(): void
    {
        self::assertSame('-c lock_timeout='.PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT, $this->fpmPgOptions());
    }

    #[DataProvider('manifests')]
    public function testEveryConsoleContainerBoundsItsLockWaitsLikeFpm(string $manifest): void
    {
        $containers = $this->backendContainers($manifest, 'containers');
        self::assertNotSame([], $containers, \sprintf('%s : aucun conteneur de l\'image backend.', $manifest));

        foreach ($containers as $container) {
            $name = $this->text($container['name'] ?? null);
            if (isset(self::EXEMPT[$manifest][$name])) {
                continue;
            }

            self::assertSame(
                $this->fpmPgOptions(),
                $this->pgOptions($container),
                \sprintf(
                    '%s, conteneur %s : l\'env doit porter PGOPTIONS = « %s », la valeur de www.prod.conf (issue #525). '
                    .'Sans elle, la console attend un verrou PostgreSQL sans fin.',
                    $manifest,
                    $name,
                    $this->fpmPgOptions(),
                ),
            );
        }
    }

    /**
     * Les initContainers de l'image ne font que copier des fichiers (le cache
     * système, les fichiers publics) : s'ils lançaient la console, ils
     * relèveraient de la même borne, que ce test ne vérifie que sur `containers`.
     */
    #[DataProvider('manifests')]
    public function testNoInitContainerOfTheImageRunsTheConsole(string $manifest): void
    {
        foreach ($this->backendContainers($manifest, 'initContainers') as $container) {
            self::assertStringNotContainsString(
                'bin/console',
                $this->text($container['command'] ?? null).' '.$this->text($container['args'] ?? null),
                \sprintf('%s, initContainer %s : il lance la console sans être couvert par ce test.', $manifest, $this->text($container['name'] ?? null)),
            );
        }
    }

    /**
     * Une exemption qui ne sert plus (conteneur renommé, borne ajoutée) est
     * retirée, pour que la liste reste celle des vraies exceptions.
     */
    public function testEveryExemptionIsStillNeeded(): void
    {
        foreach (self::EXEMPT as $manifest => $containers) {
            foreach (array_keys($containers) as $name) {
                $container = null;
                foreach ($this->backendContainers($manifest, 'containers') as $candidate) {
                    if ($name === ($candidate['name'] ?? null)) {
                        $container = $candidate;
                    }
                }

                self::assertNotNull($container, \sprintf('%s : le conteneur exempté %s n\'existe plus.', $manifest, $name));
                self::assertNull($this->pgOptions($container), \sprintf('%s, conteneur %s : il porte PGOPTIONS, l\'exemption est inutile.', $manifest, $name));
            }
        }
    }

    /**
     * La valeur que le pool FPM donne à PGOPTIONS, lue dans www.prod.conf.
     */
    private function fpmPgOptions(): string
    {
        $path = \dirname(__DIR__, 5).'/docker/php/www.prod.conf';
        self::assertFileIsReadable($path);
        self::assertSame(1, preg_match('/^env\[PGOPTIONS\] = "([^"]*)"$/m', (string) file_get_contents($path), $matches), 'www.prod.conf : env[PGOPTIONS] introuvable.');

        return $matches[1];
    }

    /**
     * La valeur littérale de PGOPTIONS dans l'`env:` du conteneur, ou null.
     * Un `valueFrom` n'en est pas une : la borne doit se lire dans le manifeste.
     *
     * @param array<mixed> $container
     */
    private function pgOptions(array $container): ?string
    {
        foreach ($this->items($container['env'] ?? null) as $variable) {
            if ('PGOPTIONS' === ($variable['name'] ?? null)) {
                $value = $variable['value'] ?? null;

                return \is_string($value) ? $value : '(valeur non littérale)';
            }
        }

        return null;
    }

    /**
     * Les conteneurs de l'image backend d'une liste du pod spec
     * (`containers` ou `initContainers`), tous documents du fichier confondus.
     *
     * @param 'containers'|'initContainers' $list
     *
     * @return list<array<mixed>>
     */
    private function backendContainers(string $manifest, string $list): array
    {
        $containers = [];
        foreach (KubernetesManifests::base()->podSpecs($manifest) as $podSpec) {
            foreach (KubernetesManifests::backendContainers($podSpec, $list) as $container) {
                $containers[] = $container;
            }
        }

        return $containers;
    }

    /**
     * Les éléments d'une liste YAML qui sont eux-mêmes des tables.
     *
     * @return list<array<mixed>>
     */
    private function items(mixed $list): array
    {
        return \is_array($list) ? array_values(array_filter($list, \is_array(...))) : [];
    }

    /**
     * Une valeur YAML rendue lisible : chaîne telle quelle, liste jointe par
     * des espaces (une commande `['sh', '-c', '…']`), rien sinon.
     */
    private function text(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        return \is_array($value) ? implode(' ', array_filter($value, \is_string(...))) : '';
    }
}
