<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

/**
 * Issue #593 : les rôles PostgreSQL (`cpg_app`, `cpg_migrator`, `cpg_reader`,
 * issue #575) se créent et se rejouent par un `kubectl exec` dans le pod
 * `postgres`, sur le socket local, sous le superutilisateur bootstrap.
 *
 * Jusqu'à #593, un Job (`postgres-roles-job.yaml`) le faisait par TCP. Le
 * `pg_hba.conf` du dépôt ({@see PostgresHbaTest}) ferme désormais le réseau à
 * ce rôle, et `pg_hba` ne distingue pas un pod d'un autre : le seul chemin
 * restant est l'intérieur du pod. C'est l'unique exception écrite à #520
 * (« un effet de bord passe par un Job »), bornée par
 * {@see OperatorExecBoundaryTest} à une ligne du runbook. Elle a deux
 * contreparties, toutes deux favorables : les mots de passe ne traversent plus
 * le réseau du cluster, et aucun Secret ni ConfigMap temporaire n'est créé. Le
 * prix : la sortie de verify.sql est sur le terminal de l'opérateur, pas dans
 * `kubectl logs`.
 *
 * Ce test pince la commande du runbook et ce qui, dans le SQL, garde les mots
 * de passe hors de toute sortie.
 */
final class PostgresRolesProcedureTest extends TestCase
{
    private const string SECTION = 'Pour créer ou rejouer les rôles';

    private const string SQL_DIRECTORY = 'postgres-roles';

    private const array SQL_FILES = ['roles.sql', 'privileges.sql', 'verify.sql'];

    /**
     * Le namespace est obligatoire, jamais une valeur par défaut, et les
     * fichiers des mots de passe naissent dans un répertoire privé.
     */
    public function testTheProcedureTargetsTheNamespaceGivenByTheOperatorFromAPrivateDirectory(): void
    {
        $block = $this->block();

        self::assertMatchesRegularExpression('/^NS= /m', $block);
        self::assertStringContainsString(': "${NS:?', $block);
        self::assertMatchesRegularExpression('/^umask 077$/m', $block);
        self::assertStringContainsString('TMP=$(mktemp -d)', $block);
        self::assertDoesNotMatchRegularExpression('/-n (preprod|prod)\b/', $block);
    }

    /**
     * Une seule commande, sur le socket local du pod (pas de `-h` : le
     * `trust` de `local all all`), le SQL lu sur l'entrée standard, avec les
     * noms de rôle du dépôt et l'attente de verrou bornée comme partout.
     */
    public function testOneExecRunsTheSqlOverTheLocalSocket(): void
    {
        $exec = $this->exec();

        self::assertMatchesRegularExpression('/kubectl -n "\$\{NS:\?[^}]+\}" exec -i deploy\/postgres -c postgres --/', $exec);
        self::assertStringContainsString('PGOPTIONS="-c lock_timeout=5s" psql -X ', $exec);
        self::assertStringContainsString('-v ON_ERROR_STOP=1', $exec);
        self::assertStringContainsString('-U "$POSTGRES_USER" -d "$POSTGRES_DB"', $exec);
        self::assertDoesNotMatchRegularExpression('/\s-h\s|--host/', $exec, 'Le socket local : par TCP, le pg_hba.conf refuse ce rôle.');
        self::assertStringContainsString('-v app_role=cpg_app -v migrator_role=cpg_migrator -v reader_role=cpg_reader', $exec);
        self::assertMatchesRegularExpression("/ -f -'\\s*\$/", $exec, 'Le SQL arrive sur l\'entrée standard.');
    }

    /**
     * La transaction est ouverte et validée **dans le flux**, jamais par
     * `--single-transaction`. Avec cette option, psql envoie COMMIT dès la fin
     * de son entrée si rien n'a échoué : un flux coupé en route (réseau,
     * terminal fermé) validait ce qui était arrivé — mesuré : les trois rôles
     * créés sans transfert de propriété ni verify.sql, psql sorti en 0. Avec
     * `BEGIN;` en tête et `COMMIT;` en dernière ligne, un flux tronqué perd son
     * COMMIT et la session close annule tout (mesuré : aucun rôle).
     */
    public function testTheStreamOpensAndCommitsItsOwnTransaction(): void
    {
        $block = $this->block();
        $exec = $this->exec();
        self::assertDoesNotMatchRegularExpression('/--single-transaction|\s-1\b/', $exec);

        self::assertSame(1, preg_match('/^\{\n(.*?)\n\}\s*\|/ms', $block, $group), 'Le flux est un groupe de commandes `{ … } |`.');
        $lines = array_map(trim(...), explode("\n", $group[1]));
        self::assertSame("printf 'BEGIN;\\n'", $lines[0], 'Première ligne du flux : BEGIN.');
        self::assertSame("printf 'COMMIT;\\n'", array_last($lines), 'Dernière ligne du flux : COMMIT, après verify.sql.');

        // Les fichiers n'ont pas de contrôle de transaction à eux : un COMMIT
        // en leur milieu validerait avant verify.sql. Le BEGIN … END d'un bloc
        // `DO $$` n'en est pas un (ni `;` après BEGIN, ni END seul suivi de `;`).
        foreach (self::SQL_FILES as $file) {
            $statements = preg_replace('/^\s*--.*$/m', '', $this->sql($file)) ?? '';
            self::assertDoesNotMatchRegularExpression('/^\s*(COMMIT|ROLLBACK|ABORT|START\s+TRANSACTION|PREPARE\s+TRANSACTION|BEGIN\s*(WORK|TRANSACTION)?\s*;|END\s*(WORK|TRANSACTION)?\s*;)/im', $statements, $file);
        }
    }

    /**
     * Le SQL est celui du checkout, dans l'ordre ; les mots de passe sont lus
     * dans leurs fichiers par `cat` — leur valeur ne figure jamais dans un
     * argument, ni de l'exec, ni d'un processus local. La rotation est
     * explicite.
     */
    public function testTheSqlAndThePasswordsReachPsqlOnStandardInputOnly(): void
    {
        $block = $this->block();
        $exec = $this->exec();

        $input = substr($block, 0, (int) strpos($block, $exec));
        self::assertMatchesRegularExpression('/\}\s*\|\s*$/', $input, 'Le groupe de commandes est envoyé par un tube.');
        self::assertStringContainsString("printf '\\\\set app_pw '; cat \"\$TMP/app-password\"", $input);
        self::assertStringContainsString("printf '\\\\set migrator_pw '; cat \"\$TMP/migrator-password\"", $input);
        self::assertStringContainsString("printf '\\\\set rotate %s\\n' \"\$ROTATE\"", $input);
        self::assertMatchesRegularExpression('/^ROTATE=0\b/m', $block, 'Pas de rotation par défaut : réécrire le mot de passe de cpg_app couperait l\'application.');

        $paths = array_map(static fn (string $file): string => 'k8s/base/'.self::SQL_DIRECTORY.'/'.$file, self::SQL_FILES);
        self::assertStringContainsString('cat '.implode(' ', $paths), $input);

        self::assertStringNotContainsString('$TMP', $exec);
        self::assertDoesNotMatchRegularExpression('/password|\\\\set|\b(SELECT|ALTER|CREATE)\b/i', $exec, 'Ni mot de passe ni SQL dans l\'argv de l\'exec.');
    }

    /**
     * Audit de #575 : un seul écho mettrait un mot de passe à l'écran. Ni sur
     * la ligne de commande (`-a`, `-e`, `--echo-*`, `-v ECHO…`), ni dans les
     * fichiers (`\set ECHO`, `\echo :variable`, `VERBOSITY`).
     */
    public function testNeitherTheCommandNorTheFilesEchoAnything(): void
    {
        $exec = $this->exec();
        self::assertDoesNotMatchRegularExpression('/\s-[a-zA-Z]*[ae]\b/', $exec);
        self::assertDoesNotMatchRegularExpression('/--echo|\bECHO\b|VERBOSITY/', $exec);

        foreach (self::SQL_FILES as $file) {
            $source = $this->sql($file);
            self::assertDoesNotMatchRegularExpression('/\\\\set\s+(ECHO|ECHO_HIDDEN|VERBOSITY)\b/i', $source, $file);
            self::assertDoesNotMatchRegularExpression('/\\\\echo\s[^\n]*:[\'"]?\w+/', $source, $file.' : un \\echo ne cite jamais une variable.');
        }
    }

    /**
     * Rien ne s'exécute dans le shell du pod : un `\set x \`…\`` (le Job lisait
     * ainsi son Secret monté) lancerait une commande sous l'uid de PostgreSQL.
     */
    public function testTheFilesRunNoShellCommandInThePod(): void
    {
        foreach (self::SQL_FILES as $file) {
            self::assertStringNotContainsString('`', preg_replace('/^\s*--.*$/m', '', $this->sql($file)) ?? '', $file);
            self::assertDoesNotMatchRegularExpression('/^\s*\\\\(!|o\s*\||g\s*\|)/m', $this->sql($file), $file);
        }
    }

    /**
     * roles.sql refuse avant toute écriture un mot de passe absent ou court et
     * une rotation qui ne vaut ni 0 ni 1, ne réécrit un mot de passe qu'à la
     * création ou sur rotation (relecture de #575), et coupe la journalisation
     * d'un ALTER ROLE refusé, qui porterait son texte.
     */
    public function testRolesRefusesMissingInputAndRewritesAPasswordOnlyWhenAsked(): void
    {
        $roles = $this->sql('roles.sql');

        self::assertStringContainsString(':{?app_pw}', $roles);
        self::assertStringContainsString(':{?migrator_pw}', $roles);
        self::assertStringContainsString(':{?rotate}', $roles);
        self::assertMatchesRegularExpression("/:'rotate' IN \\('0', '1'\\)/", $roles);
        self::assertMatchesRegularExpression("/ALTER ROLE %I PASSWORD %L[^\\n]*WHERE :'rotate' = '1' OR NOT :'app_existed'::boolean/", $roles);
        self::assertMatchesRegularExpression("/ALTER ROLE %I PASSWORD %L[^\\n]*WHERE :'rotate' = '1' OR NOT :'migrator_existed'::boolean/", $roles);
        self::assertStringContainsString('SET log_min_error_statement = panic', $roles);
    }

    /** Le contrôle imprime des compteurs : jamais un vérificateur de mot de passe. */
    public function testTheVerificationPrintsNoSecret(): void
    {
        $verify = $this->sql('verify.sql');

        self::assertDoesNotMatchRegularExpression('/\brolpassword\b(?!\s+IS\s+(NOT\s+)?NULL)/', $verify);
        self::assertStringNotContainsString('\\set', $verify);
    }

    private function sql(string $file): string
    {
        return KubernetesManifests::base()->source(self::SQL_DIRECTORY.'/'.$file);
    }

    /**
     * La seule commande `kubectl … exec` du bloc, continuations rejointes,
     * depuis `kubectl`.
     */
    private function exec(): string
    {
        $execs = array_values(array_filter(
            explode("\n", $this->block()),
            static fn (string $line): bool => 1 === preg_match('/\bkubectl\b.*\bexec\b/', $line),
        ));
        self::assertCount(1, $execs, 'La procédure des rôles tient en un seul exec.');

        // L'argv de l'exec seul, sans le groupe de commandes qui l'alimente par un tube.
        return substr($execs[0], (int) strpos($execs[0], 'kubectl '));
    }

    /**
     * Le bloc `bash` qui suit la phrase d'introduction, continuations (`\` en
     * fin de ligne) rejointes.
     */
    private function block(): string
    {
        $readme = (string) file_get_contents(\dirname(KubernetesManifests::base()->directory()).'/README.md');
        $start = strpos($readme, self::SECTION);
        self::assertIsInt($start, \sprintf('La section « %s » a disparu du runbook.', self::SECTION));
        self::assertSame(1, preg_match('/```bash\n(.*?)```/s', substr($readme, $start), $block));

        $joined = preg_replace('/\\\\\n\s*/', ' ', $block[1]);
        self::assertIsString($joined);

        return $joined;
    }
}
