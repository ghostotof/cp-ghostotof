<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

/**
 * Issue #575 : `k8s/base/postgres-roles-job.yaml` crée les rôles PostgreSQL
 * (`cpg_app`, `cpg_migrator`, `cpg_reader`) et transfère la propriété du
 * schéma, à la main, en préprod puis en prod — un Job plutôt qu'un
 * `kubectl exec` (#520 : un effet de bord passe par un Job, et chaque `psql`
 * du runbook reste une lecture, {@see OperatorExecBoundaryTest}).
 *
 * Ce test pince ce qui fait de ce Job une voie sûre : l'image de la base
 * elle-même (jamais l'image backend, qui le ferait entrer dans les
 * recensements des Jobs applicatifs), le SQL du dépôt par une ConfigMap
 * temporaire, les mots de passe par un Secret temporaire monté en lecture
 * seule et lus par psql — jamais en argument ni en variable —, une seule
 * transaction, pas de reprise, le même durcissement que les autres Jobs.
 */
final class PostgresRolesJobManifestTest extends TestCase
{
    private const string MANIFEST = 'postgres-roles-job.yaml';
    private const string CONTAINER = 'psql';
    private const string SQL_DIRECTORY = 'postgres-roles';
    private const string SQL_MOUNT = '/sql';
    private const string SQL_CONFIGMAP = 'postgres-roles-sql';
    private const string PASSWORDS_SECRET = 'postgres-roles-passwords';
    private const string PASSWORDS_MOUNT = '/run/secrets/postgres-roles';

    /** L'image de `postgres.yaml`, lue, jamais recopiée : une montée de version les déplace ensemble. */
    public function testTheJobRunsTheImageOfTheDatabaseItself(): void
    {
        $image = $this->container()['image'] ?? null;

        self::assertSame(KubernetesManifests::base()->container('postgres.yaml', 'Deployment', 'postgres')['image'] ?? null, $image);
        self::assertFalse(KubernetesManifests::isBackendImage($image), 'Un Job de l\'image backend entrerait dans les recensements des Jobs applicatifs (cache système, PGOPTIONS).');
    }

    /**
     * Les trois fichiers, dans l'ordre, en une transaction (`--single-transaction` :
     * un refus annule tout), sous `ON_ERROR_STOP`, sans `~/.psqlrc`, avec les
     * noms de rôle du dépôt, en tant que rôle bootstrap de l'image.
     */
    public function testTheJobRunsTheThreeFilesInOneTransaction(): void
    {
        $command = $this->commandLine();

        self::assertMatchesRegularExpression('/\bpsql -X\b/', $command);
        self::assertStringContainsString('-v ON_ERROR_STOP=1', $command);
        self::assertStringContainsString('--single-transaction', $command);
        self::assertStringContainsString('-h database -U "$POSTGRES_USER" -d "$POSTGRES_DB"', $command);
        self::assertStringContainsString('-v app_role=cpg_app -v migrator_role=cpg_migrator -v reader_role=cpg_reader', $command);
        self::assertStringContainsString(\sprintf('-f %1$s/roles.sql -f %1$s/privileges.sql -f %1$s/verify.sql', self::SQL_MOUNT), $command);
        self::assertDoesNotMatchRegularExpression('/\s-[a-zA-Z]*[ae]\b/', $command, 'Ni -a ni -e : psql n\'écho ni les fichiers ni les instructions (un ALTER ROLE porte un mot de passe).');
    }

    /**
     * Les mots de passe des nouveaux rôles : un Secret temporaire monté en
     * lecture seule, lu par psql depuis roles.sql — jamais une variable, jamais
     * l'argv. Le mot de passe du rôle bootstrap, lui, arrive par `PGPASSWORD`
     * depuis `postgres-credentials`, par référence.
     */
    public function testPasswordsReachPsqlThroughTheMountedSecretOnly(): void
    {
        $spec = $this->podSpec();
        $volume = KubernetesManifests::firstWith($spec, 'volumes', 'name', self::PASSWORDS_SECRET);
        self::assertNotNull($volume);
        self::assertSame(self::PASSWORDS_SECRET, $volume['secret']['secretName'] ?? null);
        self::assertSame(0o440, $volume['secret']['defaultMode'] ?? null);

        $mount = KubernetesManifests::firstWith($this->container(), 'volumeMounts', 'name', self::PASSWORDS_SECRET);
        self::assertNotNull($mount);
        self::assertSame(self::PASSWORDS_MOUNT, $mount['mountPath'] ?? null);
        self::assertTrue($mount['readOnly'] ?? false);

        $roles = KubernetesManifests::base()->source(self::SQL_DIRECTORY.'/roles.sql');
        self::assertStringContainsString(self::PASSWORDS_MOUNT.'/app-password', $roles);
        self::assertStringContainsString(self::PASSWORDS_MOUNT.'/migrator-password', $roles);
        self::assertStringContainsString('SET log_min_error_statement = panic', $roles, 'Un ALTER ROLE refusé ne doit pas être journalisé avec son texte.');
        // Relecture de #575 : un Job rejoué (un objet créé à la main à rattraper) ne doit pas
        // changer le mot de passe de l'application, qui tournerait alors sans URL valable.
        self::assertStringContainsString(self::PASSWORDS_MOUNT.'/rotate', $roles, 'La rotation se demande par une clé du Secret, jamais par défaut.');
        self::assertMatchesRegularExpression("/ALTER ROLE %I PASSWORD %L[^\\n]*WHERE :'rotate' = '1' OR NOT :'app_existed'::boolean/", $roles);

        self::assertStringNotContainsString('password', strtolower($this->commandLine()));
        self::assertArrayNotHasKey('envFrom', $this->container(), 'Aucun Secret recopié en bloc dans l\'environnement : chaque valeur par référence.');
        foreach (KubernetesManifests::entries($this->container(), 'env') as $variable) {
            $name = $variable['name'] ?? '';
            self::assertIsString($name);
            if (str_contains(strtolower($name), 'password')) {
                self::assertSame('PGPASSWORD', $name, 'Le seul mot de passe de l\'environnement est celui du rôle bootstrap, pour psql.');
                self::assertSame(['name' => 'postgres-credentials', 'key' => 'POSTGRES_PASSWORD'], $variable['valueFrom']['secretKeyRef'] ?? null, $name);
            }
        }
    }

    /**
     * Audit de #575 : un seul écho suffirait à mettre un mot de passe dans des
     * journaux lisibles par le jeton CI. Ni sur la ligne de commande
     * (`-a`, `-e`, `--echo-*`, `-v ECHO…`), ni dans les fichiers (`\set ECHO`,
     * `\echo :variable`, `VERBOSITY`).
     */
    public function testNeitherTheCommandNorTheFilesEchoAnything(): void
    {
        $command = $this->commandLine();
        self::assertDoesNotMatchRegularExpression('/--echo|\bECHO\b|VERBOSITY/', $command);

        foreach (['roles.sql', 'privileges.sql', 'verify.sql'] as $file) {
            $source = KubernetesManifests::base()->source(self::SQL_DIRECTORY.'/'.$file);
            self::assertDoesNotMatchRegularExpression('/\\\\set\s+(ECHO|ECHO_HIDDEN|VERBOSITY)\b/i', $source, $file);
            self::assertDoesNotMatchRegularExpression('/\\\\echo\s[^\n]*:[\'"]?\w+/', $source, $file.' : un \\echo ne cite jamais une variable.');
        }
    }

    /** Le SQL est celui du dépôt, monté par une ConfigMap créée depuis `k8s/base/postgres-roles/`. */
    public function testTheSqlComesFromTheRepositoryThroughATemporaryConfigMap(): void
    {
        $volume = KubernetesManifests::firstWith($this->podSpec(), 'volumes', 'name', self::SQL_CONFIGMAP);
        self::assertNotNull($volume);
        self::assertSame(self::SQL_CONFIGMAP, $volume['configMap']['name'] ?? null);

        $mount = KubernetesManifests::firstWith($this->container(), 'volumeMounts', 'name', self::SQL_CONFIGMAP);
        self::assertNotNull($mount);
        self::assertSame(self::SQL_MOUNT, $mount['mountPath'] ?? null);
        self::assertTrue($mount['readOnly'] ?? false);

        foreach (['roles.sql', 'privileges.sql', 'verify.sql'] as $file) {
            self::assertFileIsReadable(KubernetesManifests::base()->directory().'/'.self::SQL_DIRECTORY.'/'.$file);
        }
    }

    /** Le contrôle imprime des compteurs : jamais un vérificateur de mot de passe. */
    public function testTheVerificationPrintsNoSecret(): void
    {
        $verify = KubernetesManifests::base()->source(self::SQL_DIRECTORY.'/verify.sql');

        self::assertDoesNotMatchRegularExpression('/\brolpassword\b(?!\s+IS\s+(NOT\s+)?NULL)/', $verify);
        self::assertStringNotContainsString('\\set', $verify);
    }

    /**
     * Attendu 120 s par le runbook (`tools/run-job.sh … 120`, k8s/README.md),
     * délai au-dessus, convention de tous les Jobs (#535) que
     * `tools/tests/run-job.test.sh` vérifie. Pas de reprise : un refus demande
     * une lecture, et la transaction a tout annulé.
     */
    public function testTheJobIsBoundedAndNeverRetried(): void
    {
        $job = $this->job();

        self::assertSame(0, $job['spec']['backoffLimit'] ?? null);
        self::assertSame(240, $job['spec']['activeDeadlineSeconds'] ?? null);
        self::assertSame(3600, $job['spec']['ttlSecondsAfterFinished'] ?? null);
    }

    /** L'utilisateur `postgres` de l'image (uid 70, comme `postgres.yaml`), tout le reste fermé. */
    public function testThePodIsHardenedLikeTheOtherJobs(): void
    {
        $spec = $this->podSpec();
        self::assertFalse($spec['automountServiceAccountToken'] ?? null);
        self::assertSame('Never', $spec['restartPolicy'] ?? null);
        self::assertSame(['fsGroup' => 70, 'seccompProfile' => ['type' => 'RuntimeDefault']], $spec['securityContext'] ?? null);
        self::assertSame([
            'runAsNonRoot' => true,
            'runAsUser' => 70,
            'readOnlyRootFilesystem' => true,
            'allowPrivilegeEscalation' => false,
            'capabilities' => ['drop' => ['ALL']],
        ], $this->container()['securityContext'] ?? null);
    }

    /** Le lock_timeout des autres Jobs : un ALTER … OWNER TO derrière une transaction longue échoue au lieu d'attendre. */
    public function testLockWaitsAreBounded(): void
    {
        $variable = KubernetesManifests::firstWith($this->container(), 'env', 'name', 'PGOPTIONS');
        self::assertNotNull($variable);
        self::assertSame('-c lock_timeout=5s', $variable['value'] ?? null);
    }

    /** Rien à substituer : l'image est celle du manifeste, aucun nom n'arrive à l'apply. */
    public function testNothingIsSubstitutedAtApplyTime(): void
    {
        self::assertDoesNotMatchRegularExpression('/\$\{[A-Z_]+\}/', $this->source());
    }

    /** Hors de `kustomization.yaml` : un `apply -k` ne touche jamais aux rôles. */
    public function testTheJobIsNotPartOfTheKustomization(): void
    {
        self::assertStringNotContainsString(self::MANIFEST, KubernetesManifests::base()->source('kustomization.yaml'));
    }

    private function commandLine(): string
    {
        $command = $this->container()['command'] ?? null;
        self::assertIsArray($command);

        return KubernetesManifests::text($command);
    }

    /**
     * @return array<mixed>
     */
    private function container(): array
    {
        return KubernetesManifests::base()->container(self::MANIFEST, 'Job', self::CONTAINER);
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
    private function job(): array
    {
        return KubernetesManifests::base()->resource(self::MANIFEST, 'Job');
    }

    private function source(): string
    {
        return KubernetesManifests::base()->source(self::MANIFEST);
    }
}
