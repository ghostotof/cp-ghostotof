<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use App\Tests\Support\PhpImageBuild;
use App\Tests\Support\RolesVerification;
use App\Tests\Support\Runbook;
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
    private const string SQL_DIRECTORY = 'postgres-roles';

    /** Les fichiers qui écrivent ou contrôlent, sans contrôle de transaction à eux. */
    private const array SQL_FILES = ['roles.sql', 'privileges.sql', 'verify.sql'];

    /** Le dernier fichier du flux : la validation, conditionnée à verify.sql. */
    private const string COMMIT_FILE = 'commit.sql';

    /** La migration qui pose le fuseau de la base (#434), seul réglage de base admis (#608). */
    private const string TIMEZONE_MIGRATION = 'Version20261011120000';

    /**
     * L'unique `exec` qui écrit du runbook, mot pour mot (blancs normalisés) :
     * comparé en entier, une commande glissée dans le `sh -c` le fait rougir.
     */
    private const string EXEC = <<<'SH'
        kubectl -n "${NS:?preprod ou prod}" exec -i deploy/postgres -c postgres -- sh -c 'PGOPTIONS="-c lock_timeout=5s -c idle_in_transaction_session_timeout=30s -c statement_timeout=0 -c transaction_timeout=0 -c idle_session_timeout=0 -c default_transaction_read_only=off -c role=none -c search_path=pg_catalog,pg_temp -c password_encryption=scram-sha-256" psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v app_role=cpg_app -v migrator_role=cpg_migrator -v reader_role=cpg_reader -f -'
        SH;

    /**
     * Les options de connexion de la procédure, chacune avec sa raison. Une
     * option de connexion l'emporte sur un réglage de la base ou d'un rôle dans
     * la base, dès l'ouverture de la session, `BEGIN` compris (issue #608).
     */
    private const array SESSION_OPTIONS = [
        // L'attente de verrou bornée comme partout (#525).
        'lock_timeout' => '5s',
        // Un flux bloqué en route ne garde pas les verrous des transferts de
        // propriété devant l'API : la session est fermée, tout est annulé.
        'idle_in_transaction_session_timeout' => '30s',
        // #608 : propriétaire de la base, le rôle de migration peut poser
        // chacun des réglages suivants sur elle (mesuré sur l'image épinglée).
        // Sans ces options, la procédure échouait avant que verify.sql ne
        // nomme l'écart — ou, pour `role`, tournait sous le rôle de migration.
        'statement_timeout' => '0',
        'transaction_timeout' => '0',
        'idle_session_timeout' => '0',
        'default_transaction_read_only' => 'off',
        'role' => 'none',
        'search_path' => 'pg_catalog,pg_temp',
        // Un mot de passe réécrit (rotation) l'est en SCRAM, la seule méthode
        // que le pg_hba.conf du dépôt accepte.
        'password_encryption' => 'scram-sha-256',
    ];

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
        // Aucune commande kubectl du bloc ne lit NS sans sa garde : vide, elle
        // viserait le namespace du contexte courant.
        self::assertSame(0, preg_match_all('/kubectl -n "\$NS"/', $block), 'Un kubectl du bloc lit "$NS" sans "${NS:?…}".');
    }

    /**
     * Une seule commande, sur le socket local du pod (pas de `-h` : le
     * `trust` de `local all all`), le SQL lu sur l'entrée standard, avec les
     * noms de rôle du dépôt et les options de session de
     * {@see self::SESSION_OPTIONS} ; ni `--single-transaction` ni écho.
     */
    public function testOneExecRunsTheSqlOverTheLocalSocket(): void
    {
        self::assertSame($this->normalized(self::EXEC), $this->normalized($this->exec()));
    }

    /**
     * Issue #608 : un réglage de la base s'applique à chaque nouvelle session,
     * celle de la procédure comprise. En lecture seule, sous un délai court ou
     * sous `role`, la procédure échouait dans roles.sql avant que verify.sql ne
     * nomme l'écart (mesuré sur l'image épinglée). Les `SET` d'un fichier
     * arriveraient après la connexion et après `BEGIN` : les options de
     * connexion, elles, s'appliquent dès l'ouverture. Ni plus ni moins que
     * {@see self::SESSION_OPTIONS}, chacune une fois.
     */
    public function testTheSessionOptionsOverrideTheDatabaseSettings(): void
    {
        self::assertSame(1, preg_match('/PGOPTIONS="([^"]*)" psql /', $this->exec(), $match), 'PGOPTIONS entre guillemets doubles, juste avant psql.');
        self::assertSame(1, preg_match('/\A(?:-c [a-z_]+=\S+ ?)+\z/', $match[1]), 'PGOPTIONS : des « -c nom=valeur » seulement, sans blanc dans une valeur.');
        preg_match_all('/-c ([a-z_]+)=(\S+)/', $match[1], $pairs, \PREG_SET_ORDER);

        $options = [];
        foreach ($pairs as [, $name, $value]) {
            self::assertArrayNotHasKey($name, $options, $name.' : une seule fois.');
            $options[$name] = $value;
        }
        self::assertSame(self::SESSION_OPTIONS, $options);
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
        $last = array_last($verify);
        self::assertIsString($last, 'verify.sql est vide.');
        self::assertSame('\\set verify_passed 1', trim($last));
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
     * garde de longueur), refuse avant toute écriture un mot de passe absent ou court et une rotation qui
     * ne vaut ni 0 ni 1, et ne réécrit un mot de passe qu'à la création ou sur
     * rotation (relecture de #575).
     */
    public function testRolesSilencesTheLogsFirstAndRewritesAPasswordOnlyWhenAsked(): void
    {
        $roles = $this->statements('roles.sql');
        $firstPassword = strpos($roles, "length(:'app_pw')");
        self::assertIsInt($firstPassword);
        foreach (['SET log_min_error_statement = panic;', 'SET log_statement = none;', 'SET log_min_duration_statement = -1;'] as $setting) {
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
        // Le `trust` du lecteur ne vaut que sur le loopback, comme PostgresHbaTest
        // l'exige : `host all cpg_reader all trust` doit faire échouer verify.sql.
        self::assertStringContainsString("auth_method = 'trust' AND type <> 'local' AND NOT (user_name = ARRAY[reader] AND address IN ('127.0.0.1', '::1'))", $verify);
        self::assertStringContainsString("auth_method = 'trust' AND type <> 'local' AND NOT (user_name = ARRAY[:'reader_role'] AND address IN ('127.0.0.1', '::1'))", $verify);
        self::assertStringContainsString("(SELECT auth_method FROM pg_hba_file_rules ORDER BY rule_number DESC LIMIT 1) <> 'reject' THEN RAISE", $verify);
    }

    /**
     * Issue #597, dans le cluster : un `rollout undo` de `postgres` ramènerait
     * une ConfigMap de l'époque #593, sans le refus des bases de l'image, et
     * un superutilisateur peut créer une base que ce refus, qui les nomme,
     * ne couvrirait pas. verify.sql échoue donc sur le refus absent ou placé
     * après une règle TCP qui accepte, sur une base hors de la base de
     * l'application et des trois de l'image, sur une base de l'application
     * qui porterait le nom de l'une d'elles (le refus la couperait), et sur un
     * CONNECT ou TEMPORARY resté à PUBLIC sur `postgres` ou `template1`.
     * Ce test lit la forme ; la procédure jouée sur l'image épinglée, puis en
     * préprod, en est la mesure (.claude/rules/deploiement.md).
     */
    public function testTheVerificationChecksTheImageDatabasesStayClosed(): void
    {
        $verify = $this->normalized($this->statements('verify.sql'));

        self::assertStringContainsString("database @> ARRAY['postgres', 'template0', 'template1'] AND user_name = ARRAY['all'] AND address = 'all' AND auth_method = 'reject'", $verify);
        self::assertStringContainsString("type <> 'local' AND auth_method <> 'reject'", $verify, 'Le refus se compare à la première règle TCP qui accepte, loopback compris.');
        self::assertStringContainsString("current_database() IN ('postgres', 'template0', 'template1')", $verify);
        self::assertStringContainsString("datname NOT IN (current_database(), 'postgres', 'template0', 'template1')", $verify);
        self::assertStringContainsString("has_database_privilege('public', d, 'CONNECT') OR has_database_privilege('public', d, 'TEMPORARY')", $verify);
        self::assertSame(4, preg_match_all("/RAISE EXCEPTION '#597 :/", $verify), 'Quatre refus #597 dans le bloc de vérification.');
    }

    /**
     * Issue #608 : propriétaire de la base (#575), le rôle de migration peut,
     * sans être superutilisateur, la rendre inutilisable pour l'application.
     * Mesuré sur l'image épinglée : un réglage de base (`search_path`,
     * `idle_session_timeout`, `default_transaction_read_only`…), `CONNECTION
     * LIMIT 0`, `IS_TEMPLATE`, `REVOKE CONNECT … FROM PUBLIC` ; et tout rôle
     * pose sur lui-même un réglage propre à la base (`ALTER ROLE … IN DATABASE
     * … SET`), que ni le `RESET ALL` de roles.sql ni `rolconfig` ne voient.
     * verify.sql n'admet donc qu'un réglage de base, le fuseau, et seulement
     * une fois `Version20261011120000` passée — la ligne de la table des
     * migrations, pas son existence : une base restaurée d'avant elle, ou un
     * premier `migrate` interrompu, porte la table sans le fuseau. La valeur
     * admise est celle que la migration, figée, écrit, et celle de
     * `date.timezone` des images : le test lie les trois. Les refus sont lus
     * dans leur bloc seul ({@see RolesVerification::blockOf()}), et rejoués sur
     * la base de test par {@see \App\Tests\Shared\Infrastructure\Postgres\DatabaseOwnerSettingsVerificationTest}.
     */
    public function testTheVerificationRefusesWhatTheDatabaseOwnerCanAlter(): void
    {
        $migration = (string) file_get_contents(\dirname(__DIR__, 4).'/migrations/'.self::TIMEZONE_MIGRATION.'.php');
        self::assertSame(1, preg_match("/SET timezone TO %L', current_database\\(\\), '([^']+)'/", $migration, $written), self::TIMEZONE_MIGRATION.' : le fuseau écrit par la migration.');
        self::assertSame($written[1], PhpImageBuild::productionIniDirective('date.timezone'), 'Le fuseau de la base est celui des images.');

        $block = $this->normalized(RolesVerification::blockOf('#608'));

        self::assertStringContainsString(\sprintf("WHERE version = $1)' INTO migree USING 'DoctrineMigrations\\%s'", self::TIMEZONE_MIGRATION), $block, 'La migration du fuseau est passée : sa ligne, pas la seule existence de la table.');
        self::assertStringContainsString("CASE WHEN migree THEN ARRAY['TimeZone'] END", $block, 'Le seul réglage de base admis, une fois migrée.');
        self::assertStringContainsString(\sprintf("IF migree AND reglages IS DISTINCT FROM ARRAY['TimeZone=%s'] THEN RAISE", $written[1]), $block);
        self::assertStringContainsString('setdatabase = base AND setrole <> 0', $block, 'Aucun réglage de rôle propre à la base (ALTER ROLE … IN DATABASE).');
        self::assertStringContainsString('datconnlimit <> -1', $block);
        self::assertStringContainsString('datistemplate', $block);
        self::assertStringContainsString("NOT has_database_privilege(r, current_database(), 'CONNECT')", $block);
        self::assertSame(6, preg_match_all("/RAISE EXCEPTION '#608 :/", $block), 'Six refus #608 dans leur bloc.');
    }

    /**
     * Les valeurs des réglages de la base sont choisies par qui tient le rôle de
     * migration : verify.sql n'en imprime que les noms (des identifiants que le
     * serveur valide), jamais les valeurs, ni à l'affichage ni dans un refus.
     */
    public function testTheVerificationPrintsTheSettingNamesOnly(): void
    {
        $verify = $this->normalized($this->statements('verify.sql'));

        self::assertStringContainsString("array_agg(split_part(c, '=', 1) ORDER BY c COLLATE \"C\") FROM pg_db_role_setting, unnest(setconfig) AS c", $verify);
        self::assertStringNotContainsString('setconfig AS', $verify);
        self::assertDoesNotMatchRegularExpression("/RAISE EXCEPTION '[^']*'[^;]*\\breglages\\b/", RolesVerification::blockOf('#608'), 'Un refus ne cite pas la valeur des réglages.');
    }

    private function normalized(string $text): string
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
        $execs = Runbook::execLines($this->block());
        self::assertCount(1, $execs, 'La procédure des rôles tient en un seul exec.');

        // L'argv de l'exec seul, sans le groupe de commandes qui l'alimente par un tube.
        return substr($execs[0], (int) strpos($execs[0], 'kubectl '));
    }

    /**
     * Le bloc `bash` de la procédure, continuations rejointes.
     */
    private function block(): string
    {
        return Runbook::joined(Runbook::repository()->bashBlockAfter(Runbook::ROLES_PROCEDURE));
    }
}
