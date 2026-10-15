<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Postgres;

use App\Tests\Support\OpensProbeConnection;
use App\Tests\Support\RolesVerification;
use App\Tests\Support\Runbook;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issue #608 : propriétaire de la base (#575), le rôle de migration peut la
 * rendre inutilisable pour l'application sans être superutilisateur. Le bloc
 * de refus `#608` de `k8s/base/postgres-roles/verify.sql` est du SQL pur : ce
 * test rejoue **ce bloc** sur la base de test, migrée comme une base de
 * préprod (fuseau posé par `Version20261011120000`), d'abord tel quel — il
 * passe —, puis après chacun des gestes mesurés sur l'image épinglée — il
 * refuse, en nommant l'écart.
 *
 * Issue #611 : propriétaire de la base et de chaque objet de `public`, il peut
 * aussi vider le DML de l'application **sans erreur** — sécurité au niveau des
 * lignes sans politique, schéma au nom du rôle applicatif devant `public`,
 * règle `DO INSTEAD NOTHING`, trigger qui annule ou efface la ligne, opérateur
 * plus exact que celui du catalogue. Les prémisses ci-dessous rejouent ces
 * mesures. Le bloc `#611` n'énumère pas les gestes : il compare l'empreinte du
 * schéma à celle que les migrations laissent, et ce test vérifie d'abord que
 * la base de test migrée a la sienne dans verify.sql, puis que chaque geste la
 * change.
 *
 * Issue #610 : ce que le bloc `#608` refuse, roles.sql le répare — son bloc
 * de réparation, du SQL pur lui aussi, est rejoué après chacun de ces gestes,
 * puis le bloc `#608` doit passer.
 *
 * Tout tient dans une transaction annulée au `tearDown` : les rôles jetables
 * (`NOLOGIN`, aucun mot de passe), les `ALTER DATABASE`, les `REVOKE` et le DDL
 * sont transactionnels et ne se voient que d'elle. Le reste de verify.sql n'est
 * pas rejoué ici (il lit le pg_hba.conf de l'image de préprod) ; la procédure
 * entière a été jouée sur l'image épinglée (.claude/rules/deploiement-postgres.md).
 */
final class DatabaseOwnerSettingsVerificationTest extends KernelTestCase
{
    use OpensProbeConnection;

    /** La fonction du garde des groupes de traduction, seule fonction de `public`. */
    private const string GUARD_FUNCTION = 'public.portfolio_translation_group_single_position()';

    /** Une fonction de trigger qui annule la ligne (`BEFORE`) ou l'ignore (`AFTER`). */
    private const string NOTHING_FUNCTION = "CREATE FUNCTION public.{f}() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NULL; END'";

    /** Une fonction de trigger qui efface la ligne qu'on vient d'écrire. */
    private const string ERASING_FUNCTION = "CREATE FUNCTION public.{f}() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN DELETE FROM public.{t1} WHERE id = NEW.id; RETURN NULL; END'";

    /** Le refus du bloc `#611` quand le schéma n'a pas l'empreinte attendue. */
    private const string SCHEMA_REFUSAL = "#611 : le schéma de la base n'est pas celui des migrations";

    /** La phrase qui ouvre la lecture de preuve du runbook, avant la réparation (#610). */
    private const string EVIDENCE_RUNBOOK = "La réparation efface ce qu'elle répare";

    private Connection $probe;

    private string $app;

    private string $migrator;

    private string $reader;

    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->probe = self::openProbeBeside(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        self::assertSame('on', $this->probe->fetchOne("SELECT current_setting('is_superuser')"), 'Prémisse : le rôle de test est le superutilisateur de l\'image, qui crée les rôles jetables.');

        $this->suffix = bin2hex(random_bytes(4));
        $this->app = 'cpg_app_probe_'.$this->suffix;
        $this->migrator = 'cpg_migrator_probe_'.$this->suffix;
        $this->reader = 'cpg_reader_probe_'.$this->suffix;

        $this->probe->beginTransaction();
        foreach ([$this->app, $this->migrator, $this->reader] as $role) {
            $this->probe->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE', $this->probe->quoteSingleIdentifier($role)));
        }
        // Ce que verify.sql reçoit de psql, par set_config, avant ses blocs.
        $this->probe->executeQuery(
            "SELECT set_config('cpg.app_role', ?, true), set_config('cpg.migrator_role', ?, true), set_config('cpg.reader_role', ?, true)",
            [$this->app, $this->migrator, $this->reader],
        );
        // Le rôle de migration lit la table des migrations, comme son
        // propriétaire le fait dans le cluster (roles.sql, étape 3).
        $this->probe->executeStatement('GRANT SELECT ON public.doctrine_migration_versions TO '.$this->probe->quoteSingleIdentifier($this->migrator));
    }

    protected function tearDown(): void
    {
        if (isset($this->probe)) {
            if ($this->probe->isTransactionActive()) {
                $this->probe->rollBack();
            }
            $this->probe->close();
        }
        parent::tearDown();
    }

    /** L'état de la base de test, migrée : le bloc passe. */
    public function testAMigratedDatabaseWithItsTimeZoneOnlyPasses(): void
    {
        $this->play(RolesVerification::blockOf('#608'));

        self::assertSame(
            'TimeZone',
            $this->probe->fetchOne("SELECT array_to_string(array_agg(split_part(c, '=', 1)), ',') FROM pg_db_role_setting, unnest(setconfig) AS c WHERE setdatabase = (SELECT oid FROM pg_database WHERE datname = current_database()) AND setrole = 0"),
            'Prémisse : la base de test porte le seul fuseau de la migration.',
        );
    }

    /**
     * Chaque geste que le propriétaire de la base peut faire et qui couperait
     * l'application est refusé, avec sa cause.
     */
    #[DataProvider('ownerAlterations')]
    public function testEveryAlterationTheOwnerCanMakeIsRefused(string $alteration, string $refusal): void
    {
        $this->arm([$alteration]);

        $this->assertBlockRefuses('#608', $refusal);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ownerAlterations(): iterable
    {
        yield 'lecture seule' => ['ALTER DATABASE {db} SET default_transaction_read_only = on', '#608 : la base porte les réglages {TimeZone,default_transaction_read_only}, {TimeZone} attendu'];
        yield 'délai des instructions' => ["ALTER DATABASE {db} SET statement_timeout = '1ms'", 'les réglages {TimeZone,statement_timeout}'];
        yield 'chemin de recherche' => ['ALTER DATABASE {db} SET search_path = nowhere', 'les réglages {TimeZone,search_path}'];
        yield 'rôle de session' => ['ALTER DATABASE {db} SET role = {migrator}', 'les réglages {TimeZone,role}'];
        yield 'fuseau retiré' => ['ALTER DATABASE {db} RESET timezone', '#608 : la base porte les réglages <NULL>, {TimeZone} attendu'];
        yield 'fuseau changé' => ["ALTER DATABASE {db} SET timezone TO 'UTC'", "#608 : le fuseau de la base n'est pas Europe/Paris"];
        yield 'limite de connexions' => ['ALTER DATABASE {db} CONNECTION LIMIT 0', '#608 : la base limite ses connexions'];
        yield 'modèle' => ['ALTER DATABASE {db} WITH IS_TEMPLATE true', '#608 : la base est marquée modèle'];
        yield 'CONNECT retiré' => ['REVOKE CONNECT ON DATABASE {db} FROM PUBLIC', '#608 : 3 rôle(s) sur 3 sans CONNECT'];
        yield 'réglage de rôle dans la base' => ['ALTER ROLE {app} IN DATABASE {db} SET default_transaction_read_only = on', '#608 : 1 réglage(s) de rôle propre(s) à la base'];
    }

    /**
     * Issue #610 : chaque écart que le bloc `#608` refuse, roles.sql le
     * répare — la procédure, qui échouait tant qu'il existait, repasse.
     *
     * @param list<string> $alterations
     */
    #[DataProvider('repairableAlterations')]
    public function testRolesSqlRepairsEveryAlterationTheVerificationRefuses(array $alterations): void
    {
        $this->arm($alterations);
        // Témoin : l'écart est refusé. Le refus interrompt la transaction ;
        // le savepoint la rend à la réparation, l'écart toujours en place.
        $this->probe->createSavepoint('refus');
        $this->assertBlockRefuses('#608', '#608 :');
        $this->probe->rollbackSavepoint('refus');

        $this->play(RolesVerification::repairBlock());

        $this->play(RolesVerification::blockOf('#608'));
        self::assertSame('{TimeZone=Europe/Paris}', $this->databaseSettings(), 'Le fuseau de la migration, seul.');
    }

    /**
     * Les gestes du bloc `#608`, plus ceux qu'il refuse sans les nommer un à un.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function repairableAlterations(): iterable
    {
        foreach (self::ownerAlterations() as $case => [$alteration]) {
            yield $case => [[$alteration]];
        }
        yield 'réglage de base et fuseau changé ensemble' => [['ALTER DATABASE {db} SET search_path = nowhere', "ALTER DATABASE {db} SET timezone TO 'UTC'"]];
        yield 'réglage d\'extension' => [["ALTER DATABASE {db} SET cpg.probe = 'x'"]];
        yield 'réglage de rôle dans la base, rôle de migration' => [['ALTER ROLE {migrator} IN DATABASE {db} SET statement_timeout = 1']];
        yield 'réglage de rôle dans la base, superutilisateur' => [["ALTER ROLE CURRENT_USER IN DATABASE {db} SET work_mem = '64kB'"]];
        yield 'CONNECT retiré, propriétaire compris' => [['REVOKE CONNECT ON DATABASE {db} FROM PUBLIC', 'REVOKE CONNECT ON DATABASE {db} FROM CURRENT_USER']];
    }

    /**
     * Une base que le bloc `#608` admet n'est pas réécrite : ni ses réglages,
     * ni ses privilèges (un GRANT inconditionnel rendrait explicite une ACL par
     * défaut, et rouvrirait à PUBLIC une base qui donne CONNECT aux trois rôles
     * seulement).
     *
     * @param list<string> $setUp
     */
    #[DataProvider('healthyDatabases')]
    public function testRolesSqlLeavesAHealthyDatabaseUntouched(array $setUp): void
    {
        $this->arm($setUp);
        $this->play(RolesVerification::blockOf('#608'));
        $before = $this->databaseState();

        $this->play(RolesVerification::repairBlock());

        self::assertSame($before, $this->databaseState());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function healthyDatabases(): iterable
    {
        yield 'ACL par défaut' => [[]];
        yield 'CONNECT aux trois rôles seulement' => [[
            'REVOKE CONNECT ON DATABASE {db} FROM PUBLIC',
            'GRANT CONNECT ON DATABASE {db} TO {app}, {migrator}, {reader}',
        ]];
    }

    /**
     * Avant `Version20261011120000` (environnement neuf, base restaurée d'avant
     * elle), le fuseau n'appartient qu'à la migration : roles.sql ne le pose
     * pas, et retire celui qu'il trouverait — le bloc `#608` le refuse. Table
     * des migrations absente (base neuve) comprise.
     *
     * @param list<string> $setUp
     */
    #[DataProvider('timeZonesBeforeTheMigration')]
    public function testRolesSqlSetsNoTimeZoneBeforeItsMigration(array $setUp): void
    {
        $this->arm($setUp);

        $this->play(RolesVerification::repairBlock());

        $this->play(RolesVerification::blockOf('#608'));
        self::assertNull($this->databaseSettings());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function timeZonesBeforeTheMigration(): iterable
    {
        $unmigrated = "DELETE FROM public.doctrine_migration_versions WHERE version = 'DoctrineMigrations\\Version20261011120000'";
        yield 'sans fuseau' => [[$unmigrated, 'ALTER DATABASE {db} RESET timezone']];
        yield 'avec le fuseau' => [[$unmigrated, "ALTER DATABASE {db} SET timezone TO 'Europe/Paris'"]];
        yield 'table des migrations absente' => [['ALTER TABLE public.doctrine_migration_versions RENAME TO {t1}', "ALTER DATABASE {db} SET timezone TO 'Europe/Paris'"]];
    }

    /**
     * Revue de #610, mesuré sur l'image épinglée : la table des migrations
     * appartient au rôle de migration, qui peut la remplacer par une vue dont
     * une fonction s'exécutait sous le superutilisateur de la procédure, et
     * `SET LOCAL ROLE` ne l'en empêchait pas (le code lu fait `RESET ROLE`).
     * roles.sql la lit donc dans une fonction `SECURITY DEFINER` du rôle de
     * migration : le code posé tourne sous lui, et ne peut ni changer de rôle
     * ni de session.
     */
    #[DataProvider('plantedStatements')]
    public function testAFunctionPlantedInTheMigrationTableRunsUnderTheMigratorWithoutEscape(string $statement, string $refusal): void
    {
        $this->plantInTheMigrationTable($statement);

        // Le nom tel que current_user l'imprime, sans guillemets.
        $expected = str_replace('{migrator}', $this->migrator, $refusal);

        try {
            $this->probe->executeStatement(RolesVerification::migrationsReading());
            self::fail('La lecture des migrations a accepté la vue.');
        } catch (DriverException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plantedStatements(): iterable
    {
        yield 'sous quel rôle' => ["RAISE EXCEPTION ''sous %'', current_user;", 'sous {migrator}'];
        yield 'RESET ROLE' => ['RESET ROLE;', 'cannot set parameter "role" within security-definer function'];
        yield 'SET ROLE vers le superutilisateur' => ["EXECUTE format(''SET ROLE %I'', session_user);", 'cannot set parameter "role" within security-definer function'];
        yield 'SET SESSION AUTHORIZATION' => ['SET SESSION AUTHORIZATION DEFAULT;', 'cannot set parameter "session_authorization" within security-definer function'];
    }

    /**
     * Issue #633, mesuré sur l'image épinglée : un `SET` ordinaire, dans une
     * fonction qui porte une clause `SET`, l'emporte sur elle et survit à sa
     * sortie (documentation de CREATE FUNCTION). Le code posé par le rôle de
     * migration plaçait ainsi `public` devant le catalogue pour la suite de la
     * session du superutilisateur : une fonction de `public` homonyme d'une
     * fonction du catalogue s'exécutait sous lui dès l'étape 5. Rien de ce
     * que ce code règle ne doit survivre à la lecture.
     */
    #[DataProvider('plantedSessionSettings')]
    public function testThePlantedCodeLeavesNoSettingToTheSuperusersSession(string $statement): void
    {
        $this->asTheProcedureSession();
        $this->plantInTheMigrationTable($statement);
        $before = $this->sessionSettings();

        $this->probe->executeStatement(RolesVerification::migrationsReading());

        self::assertSame($before, $this->sessionSettings());
    }

    /**
     * Des réglages qu'un rôle sans privilège pose, et qui changent le sens de
     * ce qui suit : la résolution des noms, les avis `#610` de la réparation,
     * la lecture des littéraux, l'empreinte `#611`.
     *
     * @return iterable<string, array{string}>
     */
    public static function plantedSessionSettings(): iterable
    {
        yield 'SET search_path' => ['SET search_path = public, pg_catalog;'];
        yield 'set_config de session' => ["PERFORM set_config(''search_path'', ''public, pg_catalog'', false);"];
        yield 'avis masqués' => ['SET client_min_messages = error;'];
        yield 'littéraux' => ['SET standard_conforming_strings = off;'];
        yield 'empreinte' => ['SET quote_all_identifiers = on;'];
    }

    /**
     * Le geste complet de #633 : `public` placé devant le catalogue par le code
     * posé, puis une fonction homonyme d'une fonction du catalogue, que la
     * réparation et chaque bloc de verify.sql appellent. Elle ne doit jamais
     * s'exécuter : chaque bloc passe, ou refuse pour sa propre cause — la vue
     * piégée ne rend qu'une migration (`#611`), les rôles jetables n'ont ni
     * LOGIN ni mot de passe (`#575`).
     */
    #[DataProvider('blocksAfterTheReading')]
    public function testNoFunctionOfPublicRunsUnderTheSuperuserAfterTheReading(string $block, ?string $ownRefusal): void
    {
        $this->asTheProcedureSession();
        $this->plantInTheMigrationTable('SET search_path = public, pg_catalog;');
        $this->plantACatalogHomonymInPublic();

        $this->probe->executeStatement(RolesVerification::migrationsReading());
        self::assertSame('pg_catalog, pg_temp', $this->probe->fetchOne("SELECT pg_catalog.current_setting('search_path')"), 'Le chemin de la procédure, rendu après la lecture.');

        $sql = '#610' === $block ? RolesVerification::repairBlock() : RolesVerification::blockOf($block);
        if (null === $ownRefusal) {
            $this->probe->executeStatement($sql);
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertStatementRefuses($sql, $ownRefusal);
    }

    /**
     * La réparation, puis les blocs de verify.sql, et le refus propre attendu
     * de chacun sur cette base piégée (`null` : il passe).
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function blocksAfterTheReading(): iterable
    {
        yield 'réparation #610' => ['#610', null];
        yield '#575' => ['#575', '#575 : '];
        yield '#608' => ['#608', null];
        yield '#611' => ['#611', '#611 : '];
    }

    /**
     * La lecture se fait dans une sous-transaction qu'elle annule elle-même,
     * par une erreur sentinelle, une fois les valeurs lues. Le code posé qui
     * lève cette sentinelle avant la fin ne fait pas passer une lecture
     * inachevée pour une lecture faite.
     */
    public function testThePlantedCodeCannotFakeTheEndOfTheReading(): void
    {
        $this->plantInTheMigrationTable("RAISE EXCEPTION USING ERRCODE = ''CP633'';");

        $this->assertStatementRefuses(RolesVerification::migrationsReading(), "#633 : la lecture des migrations n'a pas abouti");
    }

    /**
     * Le garde du chemin de recherche refuse une session où un autre schéma
     * que le catalogue et le schéma temporaire se trouve dans le chemin : une
     * fonction, un opérateur ou une relation de ce schéma pourrait y prendre
     * la place de ceux du catalogue (le schéma de la CVE-2018-1058).
     *
     * @param list<string> $setUp
     */
    #[DataProvider('foreignSearchPaths')]
    public function testTheSearchPathGuardRefusesAPathOtherThanTheProcedures(array $setUp): void
    {
        $this->arm($setUp);

        $this->assertStatementRefuses(RolesVerification::searchPathGuardOfVerify(), "#633 : le chemin de recherche de la session n'est plus celui de la procédure");
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function foreignSearchPaths(): iterable
    {
        yield 'public devant le catalogue' => [['SET LOCAL search_path = public, pg_catalog']];
        yield 'public après le catalogue' => [['SET LOCAL search_path = pg_catalog, public']];
        yield 'chemin par défaut' => [['SET LOCAL search_path = "$user", public']];
        yield 'schéma temporaire devant le catalogue' => [['CREATE TEMP TABLE {t1} ()', 'SET LOCAL search_path = pg_temp, pg_catalog']];
        // Relecture de #633 : le schéma temporaire d'une autre session, nommé, se parcourt comme un autre.
        yield 'schéma temporaire nommé' => [['SET LOCAL search_path = pg_catalog, pg_temp_7']];
        // Un schéma absent ne figure pas dans current_schemas, mais il entre
        // dans le chemin dès qu'un rôle qui peut le créer le crée.
        yield 'schéma encore absent' => [['SET LOCAL search_path = pg_catalog, pg_temp, "$user"']];
    }

    /**
     * Le chemin de la procédure passe : tel que PGOPTIONS l'écrit (sans
     * espace), tel qu'un `SET` le réécrit, avant ou après la création du
     * schéma temporaire de la session (l'étape 4 y crée sa fonction).
     *
     * @param list<string> $setUp
     */
    #[DataProvider('proceduresSearchPaths')]
    public function testTheSearchPathGuardAdmitsTheProceduresPath(array $setUp): void
    {
        $this->arm($setUp);

        $this->probe->executeStatement(RolesVerification::searchPathGuardOfVerify());
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function proceduresSearchPaths(): iterable
    {
        yield 'options de la procédure' => [["SELECT set_config('search_path', 'pg_catalog,pg_temp', true)"]];
        yield 'SET' => [['SET LOCAL search_path = pg_catalog, pg_temp']];
        yield 'schéma temporaire créé' => [['CREATE TEMP TABLE {t1} ()', "SELECT set_config('search_path', 'pg_catalog,pg_temp', true)"]];
    }

    /**
     * Témoin de #633 : la même vue, la même fonction de `public`, sans le `SET`
     * posé — elle ne s'exécute pas, le search_path de la procédure ne nomme
     * pas `public`. La vue reste : le témoin ne diffère du geste complet que
     * par ce `SET`.
     */
    public function testAFunctionOfPublicAloneDoesNotRunUnderTheProceduresSearchPath(): void
    {
        $this->asTheProcedureSession();
        $this->plantInTheMigrationTable('');
        $this->plantACatalogHomonymInPublic();

        $this->play(RolesVerification::repairBlock());

        $this->play(RolesVerification::blockOf('#608'));
        $this->addToAssertionCount(1);
    }

    /**
     * La fonction de lecture est appelée une fois et disparaît avec la
     * sous-transaction qui l'a créée (#633), sans `DROP` : le code posé, qui
     * tourne sous son propriétaire, ne peut la redéfinir pour qu'un appel
     * suivant du superutilisateur l'exécute. Les blocs relisent ce qu'elle a
     * rendu dans des réglages de transaction.
     */
    public function testTheMigrationsAreReadOnceAndTheReadingFunctionDisappearsWithItsSubTransaction(): void
    {
        $this->probe->executeStatement(RolesVerification::migrationsReading());

        self::assertNull($this->probe->fetchOne("SELECT to_regprocedure('pg_temp.cpg_migrations()')"));
        self::assertSame(
            $this->probe->fetchNumeric('SELECT count(*)::text, max(version), \'true\' FROM public.doctrine_migration_versions'),
            $this->probe->fetchNumeric("SELECT current_setting('cpg.migrations'), current_setting('cpg.derniere_migration'), current_setting('cpg.fuseau_migre')"),
            'Ce que la table porte, lu par le superutilisateur hors de toute vue piégée.',
        );
    }

    /** Sans la lecture de roles.sql, les blocs de verify.sql refusent : rien n'est lu en superutilisateur. */
    #[DataProvider('blocksReadingTheMigrations')]
    public function testABlockRefusesWithoutTheMigratorsReading(string $issue): void
    {
        $this->assertStatementRefuses(RolesVerification::blockOf($issue), 'unrecognized configuration parameter "cpg.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blocksReadingTheMigrations(): iterable
    {
        yield '#608' => ['#608'];
        yield '#611' => ['#611'];
    }

    /**
     * L'empreinte du schéma de la base de test, migrée jusqu'à la dernière
     * migration du dépôt, figure dans verify.sql pour ces migrations (la
     * dernière et leur nombre). Une migration nouvelle rougit ce test : son
     * message donne la ligne à ajouter.
     */
    public function testTheMigratedSchemaHasItsFingerprintInVerifySql(): void
    {
        $latest = $this->probe->fetchOne('SELECT max(version) FROM public.doctrine_migration_versions');
        $applied = $this->probe->fetchOne('SELECT count(*) FROM public.doctrine_migration_versions');
        self::assertIsString($latest);
        self::assertIsInt($applied);
        $fingerprint = RolesVerification::schemaFingerprint($this->probe);

        self::assertContains(
            $fingerprint,
            RolesVerification::schemaFingerprints()[$latest.'|'.$applied] ?? [],
            \sprintf("verify.sql, bloc #611 : ajouter ('%s', %d, '%s') à ses empreintes, sans retirer les précédentes.", $latest, $applied, $fingerprint),
        );
    }

    /** La base de test, migrée, a l'empreinte de ses migrations : le bloc passe. */
    public function testAMigratedDatabasePassesTheSchemaFingerprint(): void
    {
        $this->play(RolesVerification::blockOf('#611'));

        self::assertSame(8, $this->probe->fetchOne("SELECT count(*) FROM pg_trigger WHERE tgname = 'trg_translation_group_single_position'"), 'Prémisse : le garde sur ses huit tables, dans l\'empreinte.');
    }

    /**
     * Deux empreintes admises pour les mêmes migrations — la transition d'une
     * montée de PostgreSQL qui change l'impression d'une définition — : le
     * bloc compare à chacune, sans lever sur la seconde.
     */
    public function testASecondFingerprintForTheSameMigrationsIsAdmittedToo(): void
    {
        $block = RolesVerification::blockOf('#611');
        self::assertSame(1, preg_match("/\\('(DoctrineMigrations\\\\Version\\d{14})', (\\d+), '[0-9a-f]{32}'\\)\\n\\s*\\) AS empreintes/", $block, $last), 'Prémisse : la dernière ligne de la table des empreintes.');

        $this->play(str_replace($last[0], \sprintf("('%s', %s, '%s'),\n%s", $last[1], $last[2], md5('autre impression'), $last[0]), $block));
        $this->addToAssertionCount(1);
    }

    /**
     * Des migrations que verify.sql ne connaît pas — un checkout en retard sur
     * l'environnement, une base antérieure aux empreintes — sont refusées
     * comme telles, pas comme un schéma altéré.
     */
    public function testUnknownMigrationsAreRefusedAsSuch(): void
    {
        $this->arm(["INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\\Version29991231000000', now(), 0)"]);

        $this->assertBlockRefuses('#611', '#611 : aucune empreinte de schéma connue pour ces migrations');
    }

    /** Une table des migrations vide — un premier `migrate` interrompu — est refusée comme telle. */
    public function testAnEmptyMigrationTableIsRefusedAsSuch(): void
    {
        $this->arm(['DELETE FROM public.doctrine_migration_versions']);

        $this->assertBlockRefuses('#611', '#611 : la table des migrations est vide');
    }

    /**
     * Prémisse mesurée sur l'image épinglée : sans politique, une table en
     * sécurité au niveau des lignes ne montre plus rien au rôle applicatif, qui
     * la lisait ; son `INSERT` est refusé, ses `UPDATE` et `DELETE` ne touchent
     * rien, sans erreur.
     */
    public function testRowSecurityWithoutPolicyEmptiesTheApplicationsDml(): void
    {
        $table = $this->ownedTable();
        self::assertSame(1, $this->asRole($this->app, fn (): mixed => $this->probe->fetchOne('SELECT count(*) FROM '.$table)), 'Témoin : le rôle applicatif lit la ligne.');

        $this->probe->executeStatement(\sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table));

        $this->asRole($this->app, function () use ($table): void {
            self::assertSame(0, $this->probe->fetchOne('SELECT count(*) FROM '.$table));
            self::assertSame(0, $this->probe->executeStatement('UPDATE '.$table.' SET id = 2'));
            self::assertSame(0, $this->probe->executeStatement('DELETE FROM '.$table));
            $this->probe->createSavepoint('insertion');
            try {
                $this->probe->executeStatement('INSERT INTO '.$table.' VALUES (3)');
                self::fail('L\'INSERT a été admis.');
            } catch (DriverException $exception) {
                self::assertStringContainsString('violates row-level security policy', $exception->getMessage());
            }
            $this->probe->rollbackSavepoint('insertion');
        });
    }

    /** Prémisse mesurée : `FORCE` retire au propriétaire son contournement ; seul, sans `ENABLE`, il est inerte. */
    public function testForcedRowSecurityHidesTheRowsFromTheOwnerToo(): void
    {
        $table = $this->ownedTable();
        $count = fn (): mixed => $this->probe->fetchOne('SELECT count(*) FROM '.$table);

        $this->probe->executeStatement(\sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $table));
        self::assertSame(1, $this->asRole($this->app, $count), 'FORCE seul est inerte.');

        $this->probe->executeStatement(\sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table));
        self::assertSame(0, $this->asRole($this->migrator, $count), 'FORCE + ENABLE : le propriétaire non plus.');
    }

    /**
     * Prémisse mesurée : un schéma au nom du rôle applicatif, ouvert à lui,
     * passe devant `public` dans le `search_path` par défaut — ses requêtes non
     * qualifiées lisent la copie.
     */
    public function testASchemaNamedAfterTheApplicationRoleShadowsPublic(): void
    {
        self::assertSame('"$user", public', $this->probe->fetchOne('SHOW search_path'), 'Prémisse : le search_path par défaut.');
        $this->ownedTable();
        $this->arm([
            'CREATE SCHEMA {app}',
            'CREATE TABLE {app}.{t1} (id integer)',
            'GRANT USAGE ON SCHEMA {app} TO {app}',
            'GRANT SELECT ON {app}.{t1} TO {app}',
        ]);

        self::assertSame(0, $this->asRole($this->app, fn (): mixed => $this->probe->fetchOne($this->expand('SELECT count(*) FROM {t1}'))));
    }

    /**
     * Prémisse mesurée (le schéma de la CVE-2018-1058) : un opérateur de
     * `public` plus exact que celui du catalogue est choisi pour une
     * comparaison à un littéral ou à un paramètre de type inconnu — la ligne
     * n'est plus trouvée, sans erreur.
     */
    public function testAnOperatorInPublicShadowsTheCatalogsOne(): void
    {
        $this->arm([
            'CREATE TABLE public.{t1} (username varchar(180))',
            "INSERT INTO public.{t1} VALUES ('alice')",
            'GRANT SELECT ON public.{t1} TO {app}',
        ]);
        $find = fn (): mixed => $this->probe->fetchOne($this->expand("SELECT count(*) FROM public.{t1} WHERE username = 'alice'"));
        self::assertSame(1, $this->asRole($this->app, $find), 'Témoin : l\'opérateur du catalogue trouve la ligne.');

        $this->arm([
            "CREATE FUNCTION public.{f}(varchar, varchar) RETURNS boolean LANGUAGE sql IMMUTABLE AS 'SELECT false'",
            'CREATE OPERATOR public.= (LEFTARG = varchar, RIGHTARG = varchar, FUNCTION = public.{f})',
        ]);

        self::assertSame(0, $this->asRole($this->app, $find));
    }

    /**
     * Prémisse mesurée : une règle ou un trigger posé par le propriétaire fait
     * disparaître l'`INSERT` de l'application, sans erreur.
     *
     * @param list<string> $gesture
     */
    #[DataProvider('silentInsertDrops')]
    public function testEachGestureSilentlyDropsTheApplicationsInsert(array $gesture): void
    {
        $this->arm(['CREATE TABLE public.{t1} (id integer)', 'GRANT SELECT, INSERT, UPDATE, DELETE ON public.{t1} TO {app}', ...$gesture]);

        $this->asRole($this->app, fn (): int|string => $this->probe->executeStatement($this->expand('INSERT INTO public.{t1} VALUES (1)')));
        self::assertSame(0, $this->probe->fetchOne($this->expand('SELECT count(*) FROM public.{t1}')));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function silentInsertDrops(): iterable
    {
        yield 'règle DO INSTEAD NOTHING' => [['CREATE RULE {r} AS ON INSERT TO public.{t1} DO INSTEAD NOTHING']];
        yield 'trigger BEFORE qui renvoie NULL' => [[self::NOTHING_FUNCTION, 'CREATE TRIGGER {r} BEFORE INSERT ON public.{t1} FOR EACH ROW EXECUTE FUNCTION public.{f}()']];
        yield 'trigger AFTER qui efface la ligne' => [[self::ERASING_FUNCTION, 'CREATE TRIGGER {r} AFTER INSERT ON public.{t1} FOR EACH ROW EXECUTE FUNCTION public.{f}()']];
    }

    /**
     * Chaque geste du propriétaire sur le schéma change son empreinte : il est
     * refusé. Chaque cas ne touche qu'une famille de lignes (un objet existant,
     * ou un seul objet ajouté), ce qui prouve que l'empreinte la couvre.
     */
    #[DataProvider('schemaGestures')]
    public function testEveryGestureOnTheSchemaIsRefused(string $gesture): void
    {
        $this->arm([$gesture]);

        $this->assertBlockRefuses('#611', self::SCHEMA_REFUSAL);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function schemaGestures(): iterable
    {
        yield 'sécurité de ligne activée' => ['ALTER TABLE public.contribution ENABLE ROW LEVEL SECURITY'];
        yield 'sécurité de ligne forcée seule' => ['ALTER TABLE public.contribution FORCE ROW LEVEL SECURITY'];
        yield 'politique' => ['CREATE POLICY {r} ON public.contribution USING (true)'];
        yield 'schéma au nom du rôle applicatif' => ['CREATE SCHEMA {app}'];
        yield 'règle DO INSTEAD NOTHING' => ['CREATE RULE {r} AS ON INSERT TO public.contribution DO INSTEAD NOTHING'];
        yield 'vue' => ['CREATE VIEW public.{t1} AS SELECT 1 AS id'];
        yield 'table' => ['CREATE TABLE public.{t1} (id integer)'];
        yield 'trigger BEFORE sur la fonction du garde' => ['CREATE TRIGGER {r} BEFORE INSERT ON public.contribution FOR EACH ROW EXECUTE FUNCTION '.self::GUARD_FUNCTION];
        yield 'garde désactivé' => ['ALTER TABLE public.contribution DISABLE TRIGGER trg_translation_group_single_position'];
        yield 'garde retiré d\'une table' => ['DROP TRIGGER trg_translation_group_single_position ON public.contribution'];
        yield 'fonction du garde réécrite' => ['CREATE OR REPLACE FUNCTION '.self::GUARD_FUNCTION." RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NULL; END'"];
        yield 'fonction du garde renommée' => ['ALTER FUNCTION '.self::GUARD_FUNCTION.' RENAME TO {f}'];
        yield 'fonction ajoutée' => ["CREATE FUNCTION public.{f}() RETURNS integer LANGUAGE sql AS 'SELECT 1'"];
        yield 'opérateur' => ['CREATE OPERATOR public.=== (LEFTARG = text, RIGHTARG = text, FUNCTION = pg_catalog.texteq)'];
        yield 'type' => ["CREATE TYPE public.{t1} AS ENUM ('a')"];
        yield 'index' => ['CREATE INDEX {r} ON public.contribution (position)'];
        yield 'contrainte' => ['ALTER TABLE public.contribution ADD CONSTRAINT {r} CHECK (true)'];
        yield 'défaut de colonne' => ['ALTER TABLE public.contribution ALTER COLUMN position SET DEFAULT 12345'];
        yield 'identité de réplication' => ['ALTER TABLE public.contribution REPLICA IDENTITY NOTHING'];
        yield 'publication' => ['CREATE PUBLICATION {r} FOR TABLE public.contribution'];
        yield 'transtypage' => ['CREATE CAST (text AS public.contribution) WITH INOUT'];
    }

    /**
     * Un geste sur un objet qu'une migration poserait — une règle désactivée,
     * une politique rendue à un autre rôle — change ses lignes. Le bloc ne
     * peut le montrer seul (poser l'objet change déjà l'empreinte) : on
     * compare l'empreinte avant et après le seul geste.
     *
     * @param list<string> $setUp
     */
    #[DataProvider('gesturesOnMigratedObjects')]
    public function testAGestureOnAnObjectAMigrationWouldCreateChangesTheFingerprint(array $setUp, string $gesture): void
    {
        $this->arm($setUp);
        $before = RolesVerification::schemaFingerprint($this->probe);

        $this->arm([$gesture]);

        self::assertNotSame($before, RolesVerification::schemaFingerprint($this->probe));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function gesturesOnMigratedObjects(): iterable
    {
        yield 'règle désactivée' => [['CREATE RULE {r} AS ON INSERT TO public.contribution DO ALSO NOTHING'], 'ALTER TABLE public.contribution DISABLE RULE {r}'];
        yield 'politique rendue à un autre rôle' => [['CREATE POLICY {r} ON public.contribution USING (true)'], 'ALTER POLICY {r} ON public.contribution TO {app}'];
    }

    /**
     * Le garde recréé à l'identique, sauf une condition `WHEN (false)` qui
     * l'empêche de jamais s'exécuter : refusé — sa définition est dans
     * l'empreinte, pas seulement son nom et sa fonction.
     */
    public function testTheGuardRecreatedUnderAFalseConditionIsRefused(): void
    {
        $definition = $this->probe->fetchOne("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgname = 'trg_translation_group_single_position' AND tgrelid = 'public.contribution'::regclass");
        self::assertIsString($definition);
        self::assertStringContainsString('FOR EACH ROW EXECUTE', $definition, 'Prémisse : la définition, sans condition.');

        $this->arm([
            'DROP TRIGGER trg_translation_group_single_position ON public.contribution',
            str_replace('FOR EACH ROW EXECUTE', 'FOR EACH ROW WHEN (false) EXECUTE', $definition),
        ]);

        $this->assertBlockRefuses('#611', self::SCHEMA_REFUSAL);
    }

    /**
     * Issue #634 : la lecture de preuve du runbook garde, avant l'étape 2, ce
     * que la réparation efface. Elle ne lisait que les réglages propres à la
     * base, pas ceux d'un rôle pour toutes les bases (`ALTER ROLE … SET`,
     * permis à tout rôle sur lui-même) ni ceux de tous les rôles (`ALTER ROLE
     * ALL SET`, superutilisateur) : la remise de #618 les neutralise pour la
     * session de la procédure, mais la preuve ne les gardait pas. Rejouée ici
     * telle que le runbook l'écrit, dans la transaction annulée du test.
     */
    public function testTheEvidenceReadingShowsTheRoleSettingsOutsideTheDatabaseToo(): void
    {
        $this->arm([
            "ALTER ROLE {app} SET work_mem = '1MB'",
            "ALTER ROLE {migrator} IN DATABASE {db} SET work_mem = '2MB'",
            "ALTER ROLE ALL SET work_mem = '3MB'",
        ]);

        $rows = $this->evidenceSettings();

        self::assertContains($this->app.'|(toutes les bases)|{work_mem=1MB}', $rows);
        self::assertContains($this->migrator.'|(cette base)|{work_mem=2MB}', $rows);
        self::assertContains('(tous les rôles)|(toutes les bases)|{work_mem=3MB}', $rows);
        self::assertContains('(base)|(cette base)|{TimeZone=Europe/Paris}', $rows, 'Prémisse : le fuseau de la migration, sur la base.');
    }

    /**
     * Les lignes de la première requête de la lecture de preuve du runbook,
     * jouée après ses `SET`, colonnes jointes par `|`.
     *
     * @return list<string>
     */
    private function evidenceSettings(): array
    {
        $sql = Runbook::sqlHeredoc(Runbook::repository()->bashBlockAfter(self::EVIDENCE_RUNBOOK));
        $statements = array_map(trim(...), explode(";\n", rtrim($sql, ";\n")));
        foreach ($statements as $statement) {
            if (str_starts_with($statement, 'SET ')) {
                $this->probe->executeStatement($statement);
                continue;
            }
            self::assertStringContainsString('pg_db_role_setting', $statement, 'La première requête lit les réglages.');

            // Une colonne NULL s'écrit NULL : elle ne disparaît pas en décalant les autres.
            return array_map(
                static fn (array $row): string => implode('|', array_map(static fn (mixed $column): string => \is_scalar($column) ? (string) $column : 'NULL', $row)),
                $this->probe->fetchAllNumeric($statement),
            );
        }
        self::fail('La lecture de preuve ne lit rien.');
    }

    /**
     * Issue #634 : `pg_get_expr` imprime une constante selon les réglages de
     * la session — un horodatage selon `TimeZone` et `DateStyle`, un
     * intervalle selon `IntervalStyle`, un flottant selon
     * `extra_float_digits`, des octets selon `bytea_output`, un antislash
     * selon `standard_conforming_strings`, tout identifiant selon
     * `quote_all_identifiers` (relecture de #634 : la copie du runbook, lue
     * par le lecteur sans la remise de #618, en héritait). La session de la
     * procédure tourne en `GMT` depuis la remise de #618, celle qui calcule
     * les empreintes admises en `Europe/Paris` : une migration qui poserait une telle constante dans un
     * `DEFAULT`, un `CHECK` ou un prédicat d'index ferait refuser son
     * empreinte à chaque passage. Le bloc fixe donc ces réglages lui-même :
     * joué sous deux sessions qui les ont tous différents, il calcule la même
     * empreinte (lue dans son refus, la table plantée n'étant d'aucune
     * migration).
     */
    public function testTheFingerprintDoesNotDependOnTheSessionPrintSettings(): void
    {
        $this->arm(["CREATE TABLE public.{t1} (instant timestamptz DEFAULT '2026-03-29 01:30:00+00', jour date DEFAULT '2026-10-25', duree interval DEFAULT '1 day 02:00:00', mesure double precision DEFAULT 0.30000000000000004, octets bytea DEFAULT '\\x0102', texte text DEFAULT 'a\\b')"]);

        $paris = $this->refusedFingerprintUnder(['TimeZone' => 'Europe/Paris', 'DateStyle' => 'ISO, MDY', 'IntervalStyle' => 'postgres', 'extra_float_digits' => '1', 'bytea_output' => 'hex', 'standard_conforming_strings' => 'on', 'quote_all_identifiers' => 'off']);
        $elsewhere = $this->refusedFingerprintUnder(['TimeZone' => 'Asia/Tokyo', 'DateStyle' => 'SQL, DMY', 'IntervalStyle' => 'sql_standard', 'extra_float_digits' => '0', 'bytea_output' => 'escape', 'standard_conforming_strings' => 'off', 'quote_all_identifiers' => 'on']);

        self::assertSame($paris, $elsewhere);
        self::assertSame($paris, RolesVerification::schemaFingerprint($this->probe), 'Le test calcule l\'empreinte sous les réglages du bloc, pas sous ceux de sa session : la ligne qu\'il propose pour une migration nouvelle est celle que le bloc admettra.');
    }

    /**
     * L'empreinte que le bloc `#611` cite dans son refus, joué sous les
     * réglages de session `$settings`, posés et retirés dans un point de
     * sauvegarde.
     *
     * @param array<string, string> $settings
     */
    private function refusedFingerprintUnder(array $settings): string
    {
        $this->probe->beginTransaction();
        try {
            RolesVerification::setForTheTransaction($this->probe, $settings);
            self::assertSame(1, preg_match('/empreinte ([0-9a-f]{32})/', $this->assertBlockRefuses('#611', self::SCHEMA_REFUSAL), $fingerprint));

            return $fingerprint[1];
        } finally {
            $this->probe->rollBack();
        }
    }

    /** Joue le bloc de l'issue après la lecture des migrations ; renvoie son refus, qui doit contenir `$refusal`. */
    private function assertBlockRefuses(string $issue, string $refusal): string
    {
        $this->probe->executeStatement(RolesVerification::migrationsReading());

        return $this->assertStatementRefuses(RolesVerification::blockOf($issue), $refusal);
    }

    /** Joue l'instruction ; renvoie son refus, qui doit contenir `$refusal`. */
    private function assertStatementRefuses(string $statement, string $refusal): string
    {
        try {
            $this->probe->executeStatement($statement);
        } catch (DriverException $exception) {
            self::assertStringContainsString($refusal, $exception->getMessage());

            return $exception->getMessage();
        }
        self::fail('Le bloc a accepté.');
    }

    /**
     * Joue un bloc comme le flux de la procédure : la lecture des migrations
     * de roles.sql d'abord, sur l'état présent de la base, puis le bloc.
     */
    private function play(string $block): void
    {
        $this->probe->executeStatement(RolesVerification::migrationsReading());
        $this->probe->executeStatement($block);
    }

    /**
     * Une table de `public` au rôle de migration jetable, une ligne, le DML
     * ouvert au rôle applicatif jetable — la forme que privileges.sql donne.
     */
    private function ownedTable(): string
    {
        $this->arm([
            'CREATE TABLE public.{t1} (id integer)',
            'INSERT INTO public.{t1} VALUES (1)',
            'GRANT SELECT, INSERT, UPDATE, DELETE ON public.{t1} TO {app}',
            'ALTER TABLE public.{t1} OWNER TO {migrator}',
        ]);

        return $this->expand('public.{t1}');
    }

    /**
     * Remplace la table des migrations par une vue du rôle de migration, dont
     * la lecture exécute `$statement` (PL/pgSQL, apostrophes doublées) — ce que
     * son propriétaire peut poser dans le cluster (revue de #610).
     */
    private function plantInTheMigrationTable(string $statement): void
    {
        $this->arm([
            'DROP TABLE public.doctrine_migration_versions',
            "CREATE FUNCTION public.{f}() RETURNS boolean LANGUAGE plpgsql AS 'BEGIN ".$statement." RETURN true; END'",
            "CREATE VIEW public.doctrine_migration_versions AS SELECT v AS version FROM (VALUES ('DoctrineMigrations\\Version20261011120000'::varchar)) AS t(v) WHERE public.{f}()",
            'ALTER VIEW public.doctrine_migration_versions OWNER TO {migrator}',
        ]);
    }

    /**
     * Une fonction de `public` homonyme exacte de `pg_catalog.current_setting(text)`,
     * que la réparation et verify.sql appellent sans la qualifier : exécutée,
     * elle lève une erreur qui nomme le rôle sous lequel elle a tourné.
     */
    private function plantACatalogHomonymInPublic(): void
    {
        $this->arm(["CREATE FUNCTION public.current_setting(text) RETURNS text LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''#633 : sous %'', current_user; END'"]);
    }

    /**
     * Le search_path que les options de connexion de la procédure donnent à sa
     * session (PGOPTIONS, issue #608), pour la durée de la transaction du test.
     */
    private function asTheProcedureSession(): void
    {
        self::assertStringContainsString('-c search_path=pg_catalog,pg_temp ', RolesVerification::sessionOptions().' ', 'Prémisse : le search_path des options de la procédure.');
        $this->probe->executeStatement('SET LOCAL search_path = pg_catalog, pg_temp');
        // Comme roles.sql en tête (#618) : les réglages de plpgsql visibles
        // avant la lecture, pas révélés par elle.
        $this->probe->executeStatement("LOAD 'plpgsql'");
    }

    /**
     * Les réglages de la session, hors ceux que la lecture des migrations range
     * elle-même (`cpg.*`).
     *
     * @return list<array<string, mixed>>
     */
    private function sessionSettings(): array
    {
        return $this->probe->fetchAllAssociative("SELECT name, setting FROM pg_catalog.pg_settings WHERE name NOT LIKE 'cpg.%' ORDER BY name");
    }

    /** @param list<string> $statements joués sous le superutilisateur, dans la transaction annulée au `tearDown` */
    private function arm(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->probe->executeStatement($this->expand($statement));
        }
    }

    /** Les noms jetables de ce test, quotés : `{t1}`, `{f}`, `{r}`, `{app}`, `{migrator}`, `{reader}`, et la base `{db}`. */
    private function expand(string $statement): string
    {
        return strtr($statement, [
            '{t1}' => $this->probe->quoteSingleIdentifier('cpg_probe_t1_'.$this->suffix),
            '{f}' => $this->probe->quoteSingleIdentifier('cpg_probe_f_'.$this->suffix),
            '{r}' => $this->probe->quoteSingleIdentifier('cpg_probe_r_'.$this->suffix),
            '{app}' => $this->probe->quoteSingleIdentifier($this->app),
            '{migrator}' => $this->probe->quoteSingleIdentifier($this->migrator),
            '{reader}' => $this->probe->quoteSingleIdentifier($this->reader),
            '{db}' => $this->probe->quoteSingleIdentifier($this->database()),
        ]);
    }

    /**
     * Joue `$operation` sous `$role` (`SET LOCAL ROLE`), rend la main au
     * superutilisateur, et renvoie ce qu'elle a renvoyé. Si elle échoue, la
     * transaction est peut-être interrompue : son erreur remonte telle quelle,
     * sans `RESET ROLE` qui la masquerait — le `tearDown` annule tout.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function asRole(string $role, callable $operation): mixed
    {
        $this->probe->executeStatement('SET LOCAL ROLE '.$this->probe->quoteSingleIdentifier($role));
        $result = $operation();
        $this->probe->executeStatement('RESET ROLE');

        return $result;
    }

    /** Les réglages propres à la base (`setrole = 0`), tels que le catalogue les garde ; `null` sans aucun. */
    private function databaseSettings(): ?string
    {
        $settings = $this->probe->fetchOne('SELECT setconfig::text FROM pg_db_role_setting WHERE setdatabase = (SELECT oid FROM pg_database WHERE datname = current_database()) AND setrole = 0');
        self::assertTrue(false === $settings || \is_string($settings));

        return false === $settings ? null : $settings;
    }

    /**
     * Ce que la réparation peut écrire : les réglages de la base et de ses
     * rôles, son ACL, sa limite de connexions et sa marque de modèle.
     *
     * @return array<string, mixed>
     */
    private function databaseState(): array
    {
        $state = $this->probe->fetchAssociative(
            "SELECT d.datacl::text AS acl, d.datconnlimit AS limite, d.datistemplate AS modele,
                    (SELECT string_agg(s.setrole::text || ':' || s.setconfig::text, ';' ORDER BY s.setrole) FROM pg_db_role_setting s WHERE s.setdatabase = d.oid) AS reglages
             FROM pg_database d WHERE d.datname = current_database()",
        );
        self::assertIsArray($state);

        return $state;
    }

    private function database(): string
    {
        $database = $this->probe->fetchOne('SELECT current_database()');
        self::assertIsString($database);

        return $database;
    }
}
