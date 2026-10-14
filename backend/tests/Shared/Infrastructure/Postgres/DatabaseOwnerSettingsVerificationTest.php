<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Postgres;

use App\Tests\Support\OpensProbeConnection;
use App\Tests\Support\RolesVerification;
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
 * Issue #611 : propriétaire de la base et de chaque table de `public`, il peut
 * aussi vider le DML de l'application **sans erreur** — sécurité au niveau des
 * lignes sans politique, schéma au nom du rôle applicatif devant `public` dans
 * le `search_path`, règle `DO INSTEAD NOTHING`, trigger qui annule ou efface la
 * ligne. Les prémisses ci-dessous rejouent ce qui a été mesuré sur l'image
 * épinglée ; le bloc `#611` est rejoué comme le bloc `#608`.
 *
 * Tout tient dans une transaction annulée au `tearDown` : les rôles jetables
 * (`NOLOGIN`, aucun mot de passe), les `ALTER DATABASE` et les `REVOKE` sont
 * transactionnels et ne se voient que d'elle. Le reste de verify.sql n'est pas
 * rejoué ici (il lit le pg_hba.conf de l'image de préprod) ; la procédure
 * entière a été jouée sur l'image épinglée (.claude/rules/deploiement.md).
 */
final class DatabaseOwnerSettingsVerificationTest extends KernelTestCase
{
    use OpensProbeConnection;

    /** Une fonction de trigger qui annule la ligne (`BEFORE`) ou l'ignore (`AFTER`). */
    private const string NOTHING_FUNCTION = "CREATE FUNCTION public.{f}() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NULL; END'";

    /** Une fonction de trigger qui efface la ligne qu'on vient d'écrire. */
    private const string ERASING_FUNCTION = "CREATE FUNCTION public.{f}() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN DELETE FROM public.{t1} WHERE id = NEW.id; RETURN NULL; END'";

    private Connection $probe;

    private string $app;

    private string $migrator;

    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->probe = self::openProbeBeside(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        self::assertSame('on', $this->probe->fetchOne("SELECT current_setting('is_superuser')"), 'Prémisse : le rôle de test est le superutilisateur de l\'image, qui crée les rôles jetables.');

        $this->suffix = bin2hex(random_bytes(4));
        $this->app = 'cpg_app_probe_'.$this->suffix;
        $this->migrator = 'cpg_migrator_probe_'.$this->suffix;
        $reader = 'cpg_reader_probe_'.$this->suffix;

        $this->probe->beginTransaction();
        foreach ([$this->app, $this->migrator, $reader] as $role) {
            $this->probe->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE', $this->probe->quoteSingleIdentifier($role)));
        }
        // Ce que verify.sql reçoit de psql, par set_config, avant ses blocs.
        $this->probe->executeQuery(
            "SELECT set_config('cpg.app_role', ?, true), set_config('cpg.migrator_role', ?, true), set_config('cpg.reader_role', ?, true)",
            [$this->app, $this->migrator, $reader],
        );
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
        $this->probe->executeStatement(RolesVerification::blockOf('#608'));

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
        $this->probe->executeStatement(strtr($alteration, [
            '{db}' => $this->probe->quoteSingleIdentifier($this->database()),
            '{app}' => $this->probe->quoteSingleIdentifier($this->app),
            '{migrator}' => $this->probe->quoteSingleIdentifier($this->migrator),
        ]));

        try {
            $this->probe->executeStatement(RolesVerification::blockOf('#608'));
            self::fail('Le bloc #608 a accepté : '.$alteration);
        } catch (DriverException $exception) {
            self::assertStringContainsString($refusal, $exception->getMessage());
        }
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
     * La base de test, migrée, porte le seul trigger admis — le garde des
     * groupes de traduction, sur ses huit tables — et sa fonction : le bloc
     * passe, et la comparaison de la fonction n'est pas vide. Une migration qui
     * change cette fonction ou ajoute un trigger, une règle, un schéma rougit
     * ce test tant que le bloc ne l'admet pas.
     */
    public function testAMigratedDatabaseWithOnlyTheTranslationGroupGuardPasses(): void
    {
        self::assertSame(8, $this->probe->fetchOne("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relnamespace = 'public'::regnamespace AND NOT t.tgisinternal AND t.tgname = 'trg_translation_group_single_position'"), 'Prémisse : le garde sur ses huit tables.');
        self::assertSame(1, $this->probe->fetchOne("SELECT count(*) FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname = 'portfolio_translation_group_single_position'"), 'Prémisse : sa fonction existe, la comparaison porte.');

        $this->probe->executeStatement(RolesVerification::blockOf('#611'));
    }

    /** Une vue porte une règle `_RETURN` : elle n'est pas un geste du propriétaire, le bloc l'admet. */
    public function testAViewIsAdmitted(): void
    {
        $this->arm(['CREATE VIEW public.{t1} AS SELECT 1 AS id']);

        $this->probe->executeStatement(RolesVerification::blockOf('#611'));
        self::assertSame(1, $this->probe->fetchOne("SELECT count(*) FROM pg_rewrite r JOIN pg_class c ON c.oid = r.ev_class WHERE c.relnamespace = 'public'::regnamespace AND r.rulename = '_RETURN'"), 'Prémisse : la vue porte sa règle.');
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
        $table = $this->ownedTable();
        $this->arm([
            'CREATE SCHEMA {app}',
            'CREATE TABLE {app}.'.substr($table, \strlen('public.')).' (id integer)',
            'GRANT USAGE ON SCHEMA {app} TO {app}',
            'GRANT SELECT ON {app}.'.substr($table, \strlen('public.')).' TO {app}',
        ]);

        self::assertSame(0, $this->asRole($this->app, fn (): mixed => $this->probe->fetchOne('SELECT count(*) FROM '.substr($table, \strlen('public.')))));
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
     * Chaque geste du propriétaire qui viderait le DML de l'application est
     * refusé, avec sa cause ; le compte seul, jamais un nom.
     *
     * @param list<string> $gesture
     */
    #[DataProvider('ownerGesturesOnTables')]
    public function testEveryGestureEmptyingTheApplicationsDmlIsRefused(array $gesture, string $refusal): void
    {
        $this->arm($gesture);

        try {
            $this->probe->executeStatement(RolesVerification::blockOf('#611'));
            self::fail('Le bloc #611 a accepté : '.implode(' ; ', $gesture));
        } catch (DriverException $exception) {
            self::assertStringContainsString($refusal, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function ownerGesturesOnTables(): iterable
    {
        $rowSecurity = '#611 : %d table(s) de public en sécurité au niveau des lignes';
        yield 'sécurité de ligne activée' => [['CREATE TABLE public.{t1} (id integer)', 'ALTER TABLE public.{t1} ENABLE ROW LEVEL SECURITY'], \sprintf($rowSecurity, 1)];
        yield 'sécurité de ligne forcée seule' => [['CREATE TABLE public.{t1} (id integer)', 'ALTER TABLE public.{t1} FORCE ROW LEVEL SECURITY'], \sprintf($rowSecurity, 1)];
        yield 'sécurité de ligne avec une politique' => [['CREATE TABLE public.{t1} (id integer)', 'CREATE POLICY {r} ON public.{t1} USING (true)', 'ALTER TABLE public.{t1} ENABLE ROW LEVEL SECURITY'], \sprintf($rowSecurity, 1)];
        yield 'sécurité de ligne sur une table partitionnée' => [['CREATE TABLE public.{t1} (id integer) PARTITION BY RANGE (id)', 'ALTER TABLE public.{t1} ENABLE ROW LEVEL SECURITY'], \sprintf($rowSecurity, 1)];
        yield 'sécurité de ligne sur une partition' => [['CREATE TABLE public.{t1} (id integer) PARTITION BY RANGE (id)', 'CREATE TABLE public.{t2} PARTITION OF public.{t1} FOR VALUES FROM (0) TO (10)', 'ALTER TABLE public.{t2} ENABLE ROW LEVEL SECURITY'], \sprintf($rowSecurity, 1)];
        yield 'sécurité de ligne sur deux tables' => [['CREATE TABLE public.{t1} (id integer)', 'CREATE TABLE public.{t2} (id integer)', 'ALTER TABLE public.{t1} ENABLE ROW LEVEL SECURITY', 'ALTER TABLE public.{t2} ENABLE ROW LEVEL SECURITY'], \sprintf($rowSecurity, 2)];
        yield 'schéma au nom du rôle applicatif' => [['CREATE SCHEMA {app}'], '#611 : 1 schéma(s) hors de public et des schémas système'];
        yield 'règle DO INSTEAD NOTHING' => [['CREATE TABLE public.{t1} (id integer)', 'CREATE RULE {r} AS ON INSERT TO public.{t1} DO INSTEAD NOTHING'], '#611 : 1 règle(s) sur public hors des vues'];
        yield 'trigger BEFORE qui renvoie NULL' => [['CREATE TABLE public.{t1} (id integer)', self::NOTHING_FUNCTION, 'CREATE TRIGGER {r} BEFORE INSERT ON public.{t1} FOR EACH ROW EXECUTE FUNCTION public.{f}()'], '#611 : 1 trigger(s) de public hors du garde des groupes de traduction'];
        yield 'trigger AFTER qui efface la ligne' => [['CREATE TABLE public.{t1} (id integer)', self::ERASING_FUNCTION, 'CREATE TRIGGER {r} AFTER INSERT ON public.{t1} FOR EACH ROW EXECUTE FUNCTION public.{f}()'], '#611 : 1 trigger(s) de public hors du garde des groupes de traduction'];
        yield 'trigger au nom du garde sur une autre fonction' => [['CREATE TABLE public.{t1} (id integer)', self::NOTHING_FUNCTION, 'CREATE CONSTRAINT TRIGGER trg_translation_group_single_position AFTER INSERT ON public.{t1} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.{f}()'], '#611 : 1 trigger(s) de public hors du garde des groupes de traduction'];
        yield 'garde désactivé' => [['ALTER TABLE public.contribution DISABLE TRIGGER trg_translation_group_single_position'], '#611 : 1 trigger(s) de public hors du garde des groupes de traduction'];
        yield 'fonction du garde remplacée' => [["CREATE OR REPLACE FUNCTION public.portfolio_translation_group_single_position() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NULL; END'"], "#611 : la fonction du garde des groupes de traduction n'est pas celle des migrations"];
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

    /** @param list<string> $statements joués sous le superutilisateur, dans la transaction annulée au `tearDown` */
    private function arm(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->probe->executeStatement($this->expand($statement));
        }
    }

    /** Les noms jetables de ce test, quotés : `{t1}`, `{t2}`, `{f}`, `{r}`, `{app}`, `{migrator}`. */
    private function expand(string $statement): string
    {
        return strtr($statement, [
            '{t1}' => $this->probe->quoteSingleIdentifier('cpg_probe_t1_'.$this->suffix),
            '{t2}' => $this->probe->quoteSingleIdentifier('cpg_probe_t2_'.$this->suffix),
            '{f}' => $this->probe->quoteSingleIdentifier('cpg_probe_f_'.$this->suffix),
            '{r}' => $this->probe->quoteSingleIdentifier('cpg_probe_r_'.$this->suffix),
            '{app}' => $this->probe->quoteSingleIdentifier($this->app),
            '{migrator}' => $this->probe->quoteSingleIdentifier($this->migrator),
        ]);
    }

    /**
     * Joue `$operation` sous `$role` (`SET LOCAL ROLE`), rend la main au
     * superutilisateur, et renvoie ce qu'elle a renvoyé.
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
        try {
            return $operation();
        } finally {
            $this->probe->executeStatement('RESET ROLE');
        }
    }

    private function database(): string
    {
        $database = $this->probe->fetchOne('SELECT current_database()');
        self::assertIsString($database);

        return $database;
    }
}
