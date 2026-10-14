<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

/**
 * Le Job du smoke préprod qui mesure le pg_hba.conf vu du réseau (issue #596),
 * lancé par tools/run-job.sh dans `smoke-test-preprod`.
 *
 * Il tente, depuis un pod du namespace, une connexion TCP sous le
 * superutilisateur bootstrap, sans mot de passe, et n'aboutit que sur le refus
 * de la règle `reject` du fichier du dépôt (#593). Ce refus tombe avant toute
 * authentification : le Job n'a besoin que du NOM du superutilisateur, jamais
 * de son mot de passe — c'est ce que ce test garde. La sonde elle-même est
 * mesurée dans les deux sens contre l'image épinglée par
 * tools/check-postgres-hba.sh.
 */
final class PostgresHbaProbeJobManifestTest extends TestCase
{
    private const string MANIFEST = 'postgres-hba-probe-job.yaml';

    private const string PROBE = 'postgres-hba-probe.sh';

    /** Les seules clés de `postgres-credentials` que le pod reçoit. */
    private const array CREDENTIAL_KEYS = ['PGUSER' => 'POSTGRES_USER', 'PGDATABASE' => 'POSTGRES_DB'];

    /**
     * Le `command` est la sonde du dépôt, à l'octet près : c'est elle que
     * check-postgres-hba.sh mesure. Une modification de l'une sans l'autre
     * ferait tourner en préprod un script que personne n'a mesuré.
     *
     * Au saut de ligne final près : le parseur YAML de Symfony le perd sur un
     * bloc `|` en fin d'élément de liste, là où celui de Kubernetes (comme
     * PyYAML, vérifié) le garde. `sh -c` n'y voit aucune différence.
     */
    public function testTheJobRunsTheRepositoryProbeVerbatim(): void
    {
        $command = $this->container()['command'] ?? null;
        self::assertIsArray($command);
        self::assertSame(['sh', '-c'], \array_slice($command, 0, 2));
        self::assertCount(3, $command);
        self::assertIsString($command[2]);

        self::assertSame(rtrim(KubernetesManifests::base()->source(self::PROBE), "\n"), rtrim($command[2], "\n"));
    }

    /** L'image du serveur : son `psql`, et l'uid sous lequel check-postgres-hba.sh joue la sonde. */
    public function testTheJobRunsThePostgresImage(): void
    {
        self::assertSame($this->postgresContainer()['image'] ?? null, $this->container()['image'] ?? null);
    }

    /**
     * Le Service `database`, comme l'application : la connexion sort par le
     * réseau du cluster et tombe sur les règles `host`, jamais sur le loopback.
     */
    public function testTheProbeTargetsTheDatabaseService(): void
    {
        $service = KubernetesManifests::base()->resource('postgres.yaml', 'Service');

        self::assertSame($service['metadata']['name'] ?? null, $this->env()['PGHOST']['value'] ?? null);
    }

    /**
     * Le nom du superutilisateur et de la base, lus clé par clé dans
     * `postgres-credentials` ; ni `envFrom` (il amènerait POSTGRES_PASSWORD), ni
     * volume, ni autre clé de Secret.
     */
    public function testNoPasswordReachesThePod(): void
    {
        $container = $this->container();
        self::assertArrayNotHasKey('envFrom', $container);
        self::assertArrayNotHasKey('volumes', $this->podSpec());
        self::assertArrayNotHasKey('volumeMounts', $container);

        $env = $this->env();
        foreach (self::CREDENTIAL_KEYS as $variable => $key) {
            self::assertSame(
                ['secretKeyRef' => ['name' => 'postgres-credentials', 'key' => $key]],
                $env[$variable]['valueFrom'] ?? null,
                $variable,
            );
        }
        self::assertEqualsCanonicalizing(['PGHOST', 'PGUSER', 'PGDATABASE', 'PGCONNECT_TIMEOUT'], array_keys($env));
        self::assertSame('10', $env['PGCONNECT_TIMEOUT']['value'] ?? null);
    }

    /**
     * Le pipeline attend le Job 120 s ; son délai est au-dessus (issue #535,
     * relation vérifiée par tools/tests/run-job.test.sh). Une sonde qui échoue
     * fait échouer le smoke, elle ne réessaie pas.
     */
    public function testTheJobIsBoundedAndNeverRetried(): void
    {
        $spec = KubernetesManifests::base()->resource(self::MANIFEST, 'Job')['spec'] ?? null;
        self::assertIsArray($spec);

        self::assertSame(0, $spec['backoffLimit'] ?? null);
        self::assertSame(180, $spec['activeDeadlineSeconds'] ?? null);
    }

    /** Durci comme le conteneur du serveur, plus un système de fichiers en lecture seule : psql n'écrit rien. */
    public function testThePodIsHardened(): void
    {
        $spec = $this->podSpec();
        self::assertFalse($spec['automountServiceAccountToken'] ?? null);
        self::assertSame('Never', $spec['restartPolicy'] ?? null);
        self::assertSame(['seccompProfile' => ['type' => 'RuntimeDefault']], $spec['securityContext'] ?? null);
        self::assertSame([
            'runAsNonRoot' => true,
            'runAsUser' => $this->postgresContainer()['securityContext']['runAsUser'] ?? null,
            'readOnlyRootFilesystem' => true,
            'allowPrivilegeEscalation' => false,
            'capabilities' => ['drop' => ['ALL']],
        ], $this->container()['securityContext'] ?? null);
    }

    /**
     * Rien à substituer : tools/run-job.sh refuse toute variable entre
     * accolades restée après envsubst, commentaires compris — y compris dans la
     * sonde recopiée.
     */
    public function testNothingIsSubstituted(): void
    {
        self::assertDoesNotMatchRegularExpression('/\$\{/', KubernetesManifests::base()->source(self::MANIFEST));
    }

    /** Hors de `kustomization.yaml` : un `apply -k` ne lance aucun smoke. */
    public function testTheJobIsNotPartOfTheKustomization(): void
    {
        self::assertStringNotContainsString(self::MANIFEST, KubernetesManifests::base()->source('kustomization.yaml'));
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function env(): array
    {
        $env = [];
        foreach (KubernetesManifests::entries($this->container(), 'env') as $entry) {
            $env[KubernetesManifests::text($entry['name'] ?? null)] = $entry;
        }

        return $env;
    }

    /**
     * @return array<mixed>
     */
    private function container(): array
    {
        $containers = KubernetesManifests::containers($this->podSpec());
        self::assertCount(1, $containers);

        return $containers[0];
    }

    /**
     * @return array<mixed>
     */
    private function podSpec(): array
    {
        return KubernetesManifests::base()->podSpec(self::MANIFEST, 'Job');
    }

    /**
     * @return array<mixed>
     */
    private function postgresContainer(): array
    {
        return KubernetesManifests::base()->container('postgres.yaml', 'Deployment', 'postgres');
    }
}
