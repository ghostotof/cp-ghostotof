<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Issue #521 : les commandes Messenger à effet de bord passent par un Job
 * déclaré, lancé par tools/run-job.sh (règle de #520,
 * `.claude/rules/deploiement.md`) :
 *
 *  - `messenger:stop-workers` réécrit le signal de redémarrage en cache, sous
 *    la clé du secret courant (étape 4 de la rotation d'`APP_SECRET`) ;
 *  - `messenger:failed:retry <id>` rejoue un message, donc envoie un e-mail ;
 *  - `messenger:failed:remove <id>` le supprime de la table.
 *
 * La lecture de `failed` (`messenger:failed:show`) reste un `kubectl exec`
 * humain : en `-vv`, elle affiche le message lui-même (nom, adresse et texte
 * d'un visiteur), qu'un Job écrirait dans les journaux de son pod.
 *
 * Ce test pince ce qui rend ces Jobs sûrs : aucune verbosité (sans `-vv`,
 * Messenger n'affiche que la classe, les dates et l'erreur, mesuré pour
 * #521), un Id numérique ou rien — `--all` passé pour un Id viderait toute la
 * table —, pas de reprise, et le durcissement du Job de migration.
 */
final class MessengerMaintenanceJobsManifestTest extends TestCase
{
    private const string STOP_WORKERS = 'messenger-stop-workers-job.yaml';
    private const string FAILED_RETRY = 'messenger-failed-retry-job.yaml';
    private const string FAILED_REMOVE = 'messenger-failed-remove-job.yaml';

    /** Code de sortie d'un Id refusé (EX_USAGE), distinct d'un échec de la commande. */
    private const int REFUSED_ID = 64;

    private ?string $sandbox = null;

    protected function tearDown(): void
    {
        if (null !== $this->sandbox) {
            new Filesystem()->remove($this->sandbox);
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function namedJobs(): iterable
    {
        yield 'stop-workers' => [self::STOP_WORKERS, 'backend-messenger-stop-workers', ['BACKEND_IMAGE']];
        yield 'failed:retry' => [self::FAILED_RETRY, 'backend-messenger-failed-retry', ['BACKEND_IMAGE', 'FAILED_MESSAGE_ID']];
        yield 'failed:remove' => [self::FAILED_REMOVE, 'backend-messenger-failed-remove', ['BACKEND_IMAGE', 'FAILED_MESSAGE_ID']];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function jobs(): iterable
    {
        foreach (self::namedJobs() as $label => [$manifest]) {
            yield $label => [$manifest];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failedCommands(): iterable
    {
        yield 'failed:retry' => [self::FAILED_RETRY, 'messenger:failed:retry'];
        yield 'failed:remove' => [self::FAILED_REMOVE, 'messenger:failed:remove'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failedJobs(): iterable
    {
        foreach (self::failedCommands() as $label => [$manifest]) {
            yield $label => [$manifest];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedIds(): iterable
    {
        foreach (self::failedCommands() as $label => [$manifest]) {
            yield $label.', vide' => [$manifest, ''];
            // Lu comme une option : `--all` supprimerait toute la table.
            yield $label.', option' => [$manifest, '--all'];
            yield $label.', négatif' => [$manifest, '-1'];
            yield $label.', suffixe' => [$manifest, '12a'];
            yield $label.', deux Ids' => [$manifest, '12 13'];
        }
    }

    /**
     * @param list<string> $placeholders
     */
    #[DataProvider('namedJobs')]
    public function testTheJobIsNamedAfterItsManifest(string $manifest, string $name, array $placeholders): void
    {
        self::assertSame($name, $this->job($manifest)['metadata']['name'] ?? null);
        self::assertSame(['app.kubernetes.io/name' => $name], $this->job($manifest)['metadata']['labels'] ?? null);
    }

    /**
     * Seuls ces placeholders sont substitués par tools/run-job.sh, qui refuse
     * tout `${…}` resté sans valeur.
     *
     * @param list<string> $placeholders
     */
    #[DataProvider('namedJobs')]
    public function testOnlyTheDeclaredPlaceholdersAreSubstituted(string $manifest, string $name, array $placeholders): void
    {
        preg_match_all('/\$\{([A-Z_]+)\}/', $this->source($manifest), $matches);

        self::assertSame($placeholders, array_values(array_unique($matches[1])));
    }

    /** Un humain relit l'échec ; une reprise rejouerait un e-mail ou une suppression. */
    #[DataProvider('jobs')]
    public function testTheJobIsBoundedAndNeverRetried(string $manifest): void
    {
        $spec = $this->job($manifest)['spec'] ?? null;
        self::assertIsArray($spec);

        self::assertSame(0, $spec['backoffLimit'] ?? null);
        // Deux tirages `Always` compris, sous les 300 s qu'attend la procédure.
        self::assertSame(240, $spec['activeDeadlineSeconds'] ?? null);
        self::assertSame(3600, $spec['ttlSecondsAfterFinished'] ?? null);
    }

    /** Les Secrets de l'API : même base, même clé de MAC des enveloppes et du cache. */
    #[DataProvider('jobs')]
    public function testTheJobRunsWithTheBackendSecrets(string $manifest): void
    {
        self::assertSame(
            [['configMapRef' => ['name' => 'backend-config']], ['secretRef' => ['name' => 'backend-secrets']]],
            $this->container($manifest)['envFrom'] ?? null,
        );
    }

    #[DataProvider('jobs')]
    public function testThePodIsHardenedLikeTheMigrationJob(string $manifest): void
    {
        $spec = $this->podSpec($manifest);
        self::assertFalse($spec['automountServiceAccountToken'] ?? null);
        self::assertSame('Never', $spec['restartPolicy'] ?? null);
        self::assertSame([
            'runAsNonRoot' => true,
            'runAsUser' => 10001,
            'readOnlyRootFilesystem' => true,
            'allowPrivilegeEscalation' => false,
            'capabilities' => ['drop' => ['ALL']],
        ], $this->container($manifest)['securityContext'] ?? null);
    }

    /**
     * En `-vv`, Messenger affiche le message (nom, adresse et texte d'un
     * visiteur) et la trace de l'exception : dans un Job, ils partiraient dans
     * les journaux du pod, lisibles par le jeton CI (`pods/log`).
     */
    #[DataProvider('jobs')]
    public function testTheCommandNeverRaisesItsVerbosity(string $manifest): void
    {
        $command = implode(' ', $this->command($manifest));

        self::assertDoesNotMatchRegularExpression('/(^|\s)(-v+|--verbose)(\s|=|$)/', $command);
    }

    /** Hors de `kustomization.yaml` : un `apply -k` ne touche jamais à `failed`. */
    #[DataProvider('jobs')]
    public function testTheJobIsNotPartOfTheKustomization(string $manifest): void
    {
        self::assertStringNotContainsString($manifest, (string) file_get_contents($this->k8sBase().'/kustomization.yaml'));
    }

    public function testStopWorkersRewritesTheRestartSignal(): void
    {
        self::assertSame(
            ['php', 'bin/console', 'messenger:stop-workers', '--no-interaction'],
            $this->command(self::STOP_WORKERS),
        );
    }

    /**
     * L'Id arrive par une variable du conteneur, sous un autre nom que le
     * placeholder : envsubst remplace aussi `$FAILED_MESSAGE_ID`.
     */
    #[DataProvider('failedJobs')]
    public function testTheIdReachesTheContainerThroughItsEnvironment(string $manifest): void
    {
        self::assertSame([['name' => 'MESSAGE_ID', 'value' => '${FAILED_MESSAGE_ID}']], $this->container($manifest)['env'] ?? null);
    }

    #[DataProvider('failedCommands')]
    public function testANumericIdReachesTheCommandWithoutConfirmation(string $manifest, string $consoleCommand): void
    {
        [$exitCode, $argv] = $this->runScript($manifest, '42');

        self::assertSame(0, $exitCode);
        self::assertSame(['bin/console', $consoleCommand, '42', '--force', '--no-interaction'], $argv);
    }

    #[DataProvider('refusedIds')]
    public function testAnIdOtherThanDigitsIsRefusedBeforeTheConsole(string $manifest, string $id): void
    {
        [$exitCode, $argv] = $this->runScript($manifest, $id);

        self::assertSame(self::REFUSED_ID, $exitCode);
        self::assertNull($argv, 'La console ne doit pas être appelée.');
    }

    /**
     * Joue le script du conteneur avec un faux `php` qui consigne son argv.
     *
     * @return array{int, list<string>|null}
     */
    private function runScript(string $manifest, string $id): array
    {
        $command = $this->command($manifest);
        self::assertSame(['sh', '-c'], \array_slice($command, 0, 2));
        self::assertCount(3, $command);

        $this->sandbox = sys_get_temp_dir().'/messenger-job-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->sandbox.'/bin');
        $filesystem->dumpFile($this->sandbox.'/bin/php', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$ARGV_FILE\"\n");
        $filesystem->chmod($this->sandbox.'/bin/php', 0o755);

        $process = new Process($command, $this->sandbox, [
            'MESSAGE_ID' => $id,
            'ARGV_FILE' => $this->sandbox.'/argv',
            'PATH' => $this->sandbox.'/bin:/usr/bin:/bin',
        ]);
        $process->run();

        $argv = is_file($this->sandbox.'/argv')
            ? explode("\n", rtrim((string) file_get_contents($this->sandbox.'/argv'), "\n"))
            : null;

        return [(int) $process->getExitCode(), $argv];
    }

    /**
     * @return list<string>
     */
    private function command(string $manifest): array
    {
        $command = $this->container($manifest)['command'] ?? null;
        self::assertIsArray($command);
        $typed = [];
        foreach ($command as $part) {
            self::assertIsString($part);
            $typed[] = $part;
        }

        return $typed;
    }

    /**
     * @return array<mixed>
     */
    private function container(string $manifest): array
    {
        $containers = $this->podSpec($manifest)['containers'] ?? null;
        self::assertIsArray($containers);
        self::assertCount(1, $containers);
        $container = $containers[0];
        self::assertIsArray($container);

        return $container;
    }

    /**
     * @return array<mixed>
     */
    private function podSpec(string $manifest): array
    {
        $spec = $this->job($manifest)['spec']['template']['spec'] ?? null;
        self::assertIsArray($spec);

        return $spec;
    }

    /**
     * @return array<mixed>
     */
    private function job(string $manifest): array
    {
        $job = Yaml::parse($this->source($manifest));
        self::assertIsArray($job);
        self::assertSame('Job', $job['kind'] ?? null);

        return $job;
    }

    private function source(string $manifest): string
    {
        $path = $this->k8sBase().'/'.$manifest;
        self::assertFileIsReadable($path);

        return (string) file_get_contents($path);
    }

    private function k8sBase(): string
    {
        return \dirname(__DIR__, 5).'/k8s/base';
    }
}
