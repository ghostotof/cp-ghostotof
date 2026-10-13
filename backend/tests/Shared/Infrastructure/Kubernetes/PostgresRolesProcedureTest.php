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
 * Ce test pince la commande du runbook, le flux qu'elle lit, et ce qui, dans
 * le SQL, garde les mots de passe hors de toute sortie et la validation
 * derrière verify.sql.
 */
final class PostgresRolesProcedureTest extends TestCase
{
    private const string SECTION = 'Pour créer ou rejouer les rôles';

    private const string SQL_DIRECTORY = 'postgres-roles';

    /** Les fichiers qui écrivent ou contrôlent, sans contrôle de transaction à eux. */
    private const array SQL_FILES = ['roles.sql', 'privileges.sql', 'verify.sql'];

    /** Le dernier fichier du flux : la validation, conditionnée à verify.sql. */
    private const string COMMIT_FILE = 'commit.sql';

    /**
     * L'unique `exec` qui écrit du runbook, mot pour mot (blancs normalisés) :
     * comparé en entier, une commande glissée dans le `sh -c` le fait rougir.
     */
    private const string EXEC = <<<'SH'
        kubectl -n "${NS:?preprod ou prod}" exec -i deploy/postgres -c postgres -- sh -c 'PGOPTIONS="-c lock_timeout=5s" psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v app_role=cpg_app -v migrator_role=cpg_migrator -v reader_role=cpg_reader -f -'
        SH;

    /**
     * Le namespace est obligatoire, jamais une valeur par défaut, et les
     * fichiers des mots de passe naissent dans un répertoire privé.
     */
    public function testTheProcedureTargetsTheNamespaceGivenByTheOperatorFromAPrivateDirectory(): void
    {
        $block = $this->block();

        self::assertMatchesRegularExpression('/^NS=$/m', $block, 'NS seul sur sa ligne : un commentaire à sa suite devient une commande sous zsh sans interactivecomments.');
        self::assertMatchesRegularExpression('/^ROTATE=0$/m', $block, 'Pas de rotation par défaut : réécrire le mot de passe de cpg_app couperait l\'application.');
        self::assertStringContainsString(': "${NS:?', $block);
        self::assertMatchesRegularExpression('/^umask 077$/m', $block);
        self::assertStringContainsString('TMP=$(mktemp -d)', $block);
        self::assertDoesNotMatchRegularExpression('/-n (preprod|prod)\b/', $block);
        // Toute commande kubectl du bloc vise le namespace donné, garde comprise.
        self::assertSame(0, preg_match_all('/kubectl -n "\$NS"/', $block), 'Chaque kubectl du bloc écrit "${NS:?…}".');
    }

    /**
     * Une seule commande, sur le socket local du pod (pas de `-h` : le
     * `trust` de `local all all`), le SQL lu sur l'entrée standard, avec les
     * noms de rôle du dépôt et l'attente de verrou bornée comme partout ; ni
     * `--single-transaction` ni écho.
     */
    public function testOneExecRunsTheSqlOverTheLocalSocket(): void
    {
        self::assertSame(self::normalized(self::EXEC), self::normalized($this->exec()));
    }

    /**
     * La transaction est ouverte et validée **dans le flux**, jamais par
     * `--single-transaction`, qui envoie COMMIT à la fin de l'entrée si rien
     * n'a échoué : un flux coupé en route validait ce qui était arrivé
     * (mesuré : trois rôles, ni transfert de propriété ni verify.sql, sortie
     * 0). Le flux ouvre par `BEGIN;` et finit par commit.sql, qui ne valide
     * que si verify.sql est allé jusqu'au bout : un fichier manquant (un `cat`
     * lancé hors de la racine du checkout, un fichier renommé) ou un flux
     * tronqué n'écrivent rien.
     */
    public function testTheStreamOpensATransactionThatOnlyAFullVerificationCommits(): void
    {
        self::assertSame(1, preg_match('/^\{\n(.*?)\n\}\s*\|/ms', $this->block(), $group), 'Le flux est un groupe de commandes `{ … } |`.');
        $lines = array_map(trim(...), explode("\n", $group[1]));
        self::assertSame("printf 'BEGIN;\\n'", $lines[0], 'Première ligne du flux : BEGIN.');

        $paths = array_map(
            static fn (string $file): string => 'k8s/base/'.self::SQL_DIRECTORY.'/'.$file,
            [...self::SQL_FILES, self::COMMIT_FILE],
        );
        self::assertSame('cat '.implode(' ', $paths), array_last($lines), 'Dernière ligne du flux : les fichiers, commit.sql le dernier.');
        self::assertCount(1, array_filter($lines, static fn (string $line): bool => 1 === preg_match('/BEGIN|COMMIT/', $line)), 'Le flux ne porte que son BEGIN ; la validation est dans commit.sql.');

        $commit = $this->statements(self::COMMIT_FILE);
        self::assertSame(1, preg_match('/\A\s*\\\\if :\{\?verify_passed\}\s+COMMIT;\s+\\\\else\s+DO \$\$ BEGIN RAISE EXCEPTION \'[^\n]*\'; END \$\$;\s+\\\\endif\s*\z/', $commit), 'commit.sql : COMMIT si verify.sql est allé au bout, une erreur sinon.');

        // La marque n'est posée qu'une fois, par la dernière instruction de
        // verify.sql, et effacée en tête de roles.sql.
        $verify = array_values(array_filter(explode("\n", $this->statements('verify.sql')), static fn (string $line): bool => '' !== trim($line)));
        self::assertSame('\\set verify_passed 1', trim(array_last($verify)));
        self::assertStringContainsString('\\unset verify_passed', $this->statements('roles.sql'));
        foreach (self::SQL_FILES as $file) {
            self::assertLessThanOrEqual('verify.sql' === $file ? 1 : 0, preg_match_all('/\\\\set\s+verify_passed\b/', $this->statements($file)), $file);
        }
    }

    /**
     * Les fichiers n'ont pas de contrôle de transaction à eux : un COMMIT en
     * leur milieu validerait avant verify.sql, une reconnexion (`\c`) fermerait
     * la transaction et passerait la suite en autocommit. Le BEGIN … END d'un
     * bloc `DO $$` n'en est pas un.
     */
    public function testTheFilesNeitherControlTheTransactionNorReconnect(): void
    {
        foreach (self::SQL_FILES as $file) {
            $statements = $this->statements($file);
            self::assertDoesNotMatchRegularExpression('/(^|;)\s*(COMMIT|ROLLBACK|ABORT|START\s+TRANSACTION|PREPARE\s+TRANSACTION|BEGIN\s*(WORK|TRANSACTION)?\s*;|END\s*(WORK|TRANSACTION)?\s*;)/im', $statements, $file);
            self::assertDoesNotMatchRegularExpression("/'(COMMIT|ROLLBACK|BEGIN)'/i", $statements, $file.' : une instruction de transaction construite pour \\gexec.');
            self::assertDoesNotMatchRegularExpression('/^\s*\\\\(c|connect)\b/m', $statements, $file);
        }
    }

    /**
     * Le SQL est celui du checkout ; les mots de passe sont lus dans leurs
     * fichiers par `cat` — leur valeur ne figure jamais dans un argument, ni
     * de l'exec, ni d'un processus local. La rotation est explicite.
     */
    public function testThePasswordsReachPsqlOnStandardInputOnly(): void
    {
        $block = $this->block();
        $input = substr($block, 0, (int) strpos($block, 'kubectl -n "${NS:?preprod ou prod}" exec'));

        self::assertMatchesRegularExpression('/\}\s*\|\s*$/', $input, 'Le groupe de commandes est envoyé par un tube.');
        self::assertStringContainsString("printf '\\\\set app_pw '; cat \"\$TMP/app-password\"", $input);
        self::assertStringContainsString("printf '\\\\set migrator_pw '; cat \"\$TMP/migrator-password\"", $input);
        self::assertStringContainsString("printf '\\\\set rotate %s\\n' \"\$ROTATE\"", $input);
    }

    /**
     * Audit de #575 et relecture de #593 : un seul écho mettrait un mot de
     * passe à l'écran. Ni sur la ligne de commande (`-a`, `-e`, `--echo-*`),
     * ni dans les fichiers (`\set ECHO`, `\echo`, `\qecho` ou `\warn` d'une
     * variable, `VERBOSITY`), et un mot de passe n'est cité qu'aux trois
     * endroits prévus : son défaut, la garde de longueur, son ALTER ROLE.
     */
    public function testNoPasswordIsEchoedOrCitedOutsideItsThreePlaces(): void
    {
        self::assertDoesNotMatchRegularExpression('/\s-[a-zA-Z]*[ae]\b|--echo|\bECHO\b|VERBOSITY/', $this->exec());

        foreach (self::SQL_FILES as $file) {
            $source = $this->sql($file);
            self::assertDoesNotMatchRegularExpression('/\\\\set\s+(ECHO|ECHO_HIDDEN|VERBOSITY)\b/i', $source, $file);
            self::assertDoesNotMatchRegularExpression('/\\\\(echo|qecho|warn)\s[^\n]*:[\'"{]?\w+/', $source, $file.' : un écho ne cite jamais une variable.');
        }

        $allowed = [
            '/^\\\\if :\{\?(app|migrator)_pw\}$/',
            "/^\\\\set (app|migrator)_pw ''$/",
            "/length\\(:'app_pw'\\) >= 32 AND length\\(:'migrator_pw'\\) >= 32 AS passwords_ok,$/",
            "/^SELECT format\\('ALTER ROLE %I PASSWORD %L', :'(app|migrator)_role', :'(app|migrator)_pw'\\) WHERE /",
        ];
        foreach ([...self::SQL_FILES, self::COMMIT_FILE] as $file) {
            foreach (explode("\n", $this->statements($file)) as $line) {
                if (1 !== preg_match('/\b(app|migrator)_pw\b/', $line)) {
                    continue;
                }
                $matches = array_filter($allowed, static fn (string $pattern): bool => 1 === preg_match($pattern, trim($line)));
                self::assertNotEmpty($matches, \sprintf('%s : mot de passe cité hors des endroits prévus : « %s ».', $file, trim($line)));
            }
        }
    }

    /**
     * Rien ne s'exécute dans le shell du pod ni ne sort vers un fichier ou un
     * programme : un `\set x \`…\`` (le Job lisait ainsi son Secret monté),
     * `\!`, `\o |`, `\g |`, `\w`, `\copy`, `\setenv`, ou `COPY … PROGRAM` côté
     * serveur, sous le superutilisateur.
     */
    public function testTheFilesRunNoShellCommandInThePod(): void
    {
        foreach ([...self::SQL_FILES, self::COMMIT_FILE] as $file) {
            $statements = $this->statements($file);
            self::assertStringNotContainsString('`', $statements, $file);
            self::assertDoesNotMatchRegularExpression('/\\\\(!|o\b|g\s*\||gx\s*\||w\b|copy\b|setenv\b)/', $statements, $file);
            self::assertDoesNotMatchRegularExpression('/\bPROGRAM\b/i', $statements, $file);
        }
    }

    /**
     * roles.sql efface la marque de verify.sql, coupe la journalisation d'une
     * instruction refusée **avant** la première qui porte un mot de passe (la
     * garde de longueur), borne une transaction laissée en attente, refuse
     * avant toute écriture un mot de passe absent ou court et une rotation qui
     * ne vaut ni 0 ni 1, et ne réécrit un mot de passe qu'à la création ou sur
     * rotation (relecture de #575).
     */
    public function testRolesSilencesTheLogsFirstAndRewritesAPasswordOnlyWhenAsked(): void
    {
        $roles = $this->statements('roles.sql');
        $firstPassword = strpos($roles, "length(:'app_pw')");
        self::assertIsInt($firstPassword);
        foreach (['SET log_min_error_statement = panic;', 'SET log_statement = none;', 'SET log_min_duration_statement = -1;', "SET idle_in_transaction_session_timeout = '30s';"] as $setting) {
            $at = strpos($roles, $setting);
            self::assertIsInt($at, $setting);
            self::assertLessThan($firstPassword, $at, $setting.' : avant la première requête qui porte un mot de passe.');
        }

        self::assertStringContainsString(':{?rotate}', $roles);
        self::assertMatchesRegularExpression("/:'rotate' IN \\('0', '1'\\)/", $roles);
        self::assertMatchesRegularExpression("/ALTER ROLE %I PASSWORD %L[^\\n]*WHERE :'rotate' = '1' OR NOT :'app_existed'::boolean/", $roles);
        self::assertMatchesRegularExpression("/ALTER ROLE %I PASSWORD %L[^\\n]*WHERE :'rotate' = '1' OR NOT :'migrator_existed'::boolean/", $roles);
    }

    /**
     * Relecture de #593 : rejouée sans rotation, la procédure ignore les mots
     * de passe générés — les publier couperait l'application au redémarrage
     * suivant. roles.sql dit, pour chacun, s'il l'a écrit, jamais sa valeur.
     */
    public function testRolesTellsWhichPasswordsItWrote(): void
    {
        $roles = $this->statements('roles.sql');

        self::assertMatchesRegularExpression("/:'rotate' = '1' OR NOT :'app_existed'::boolean AS mot_de_passe_cpg_app_ecrit\\b/", $roles);
        self::assertMatchesRegularExpression("/:'rotate' = '1' OR NOT :'migrator_existed'::boolean AS mot_de_passe_cpg_migrator_ecrit\\b/", $roles);
    }

    /** Le contrôle imprime des compteurs : jamais un vérificateur de mot de passe. */
    public function testTheVerificationPrintsNoSecret(): void
    {
        self::assertDoesNotMatchRegularExpression('/\brolpassword\b(?!\s+IS\s+(NOT\s+)?NULL)/', $this->sql('verify.sql'));
    }

    /**
     * La seule preuve, dans le cluster, que le pg_hba.conf du dépôt est celui
     * que le serveur lit : verify.sql, sous le superutilisateur, compare
     * `hba_file` au chemin des args de postgres.yaml, refuse une règle en
     * erreur, une confiance hors du socket et du lecteur, et une dernière
     * règle qui ne refuse pas.
     */
    public function testTheVerificationChecksTheActiveHbaFile(): void
    {
        $args = KubernetesManifests::base()->container('postgres.yaml', 'Deployment', 'postgres')['args'] ?? null;
        self::assertIsArray($args);
        $path = null;
        foreach ($args as $arg) {
            if (\is_string($arg) && str_starts_with($arg, 'hba_file=')) {
                $path = substr($arg, \strlen('hba_file='));
            }
        }
        self::assertIsString($path);

        $verify = $this->statements('verify.sql');
        self::assertStringContainsString(\sprintf("current_setting('hba_file') <> '%s'", $path), $verify);
        self::assertStringContainsString('pg_hba_file_rules', $verify);
        self::assertStringContainsString('error IS NOT NULL', $verify);
        self::assertStringContainsString("auth_method = 'trust'", $verify);
        self::assertStringContainsString("(SELECT auth_method FROM pg_hba_file_rules ORDER BY rule_number DESC LIMIT 1) <> 'reject' THEN RAISE", $verify);
    }

    private static function normalized(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function sql(string $file): string
    {
        return KubernetesManifests::base()->source(self::SQL_DIRECTORY.'/'.$file);
    }

    /** Le fichier sans ses commentaires `--` de ligne entière. */
    private function statements(string $file): string
    {
        return (string) preg_replace('/^\s*--.*$/m', '', $this->sql($file));
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
        self::assertSame(1, substr_count($readme, self::SECTION), \sprintf('La phrase « %s » ouvre la section, une seule fois.', self::SECTION));
        $start = (int) strpos($readme, self::SECTION);
        self::assertSame(1, preg_match('/```bash\n(.*?)```/s', substr($readme, $start), $block));

        $joined = preg_replace('/\\\\\n\s*/', ' ', $block[1]);
        self::assertIsString($joined);

        return $joined;
    }
}
