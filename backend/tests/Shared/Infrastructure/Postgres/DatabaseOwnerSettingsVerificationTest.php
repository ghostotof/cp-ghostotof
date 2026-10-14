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
 * Tout tient dans une transaction annulée au `tearDown` : les rôles jetables
 * (`NOLOGIN`, aucun mot de passe), les `ALTER DATABASE`, les `REVOKE` et le DDL
 * sont transactionnels et ne se voient que d'elle. Le reste de verify.sql n'est
 * pas rejoué ici (il lit le pg_hba.conf de l'image de préprod) ; la procédure
 * entière a été jouée sur l'image épinglée (.claude/rules/deploiement.md).
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
     * L'empreinte du schéma de la base de test, migrée jusqu'à la dernière
     * migration du dépôt, figure dans verify.sql pour cette migration. Une
     * migration nouvelle rougit ce test : son message donne la ligne à ajouter.
     */
    public function testTheMigratedSchemaHasItsFingerprintInVerifySql(): void
    {
        $latest = $this->probe->fetchOne('SELECT max(version) FROM public.doctrine_migration_versions');
        self::assertIsString($latest);
        $this->probe->executeStatement("SET LOCAL search_path = pg_catalog, pg_temp");
        $fingerprint = $this->probe->fetchOne(\sprintf("SELECT md5(string_agg(l, E'\\n' ORDER BY l COLLATE \"C\")) FROM (%s) AS lignes(l)", RolesVerification::schemaLinesQuery()));

        self::assertSame(
            $fingerprint,
            RolesVerification::schemaFingerprints()[$latest] ?? null,
            \sprintf("verify.sql, bloc #611 : ajouter ('%s', '%s') à ses empreintes, sans retirer les précédentes.", $latest, \is_string($fingerprint) ? $fingerprint : ''),
        );
    }

    /** La base de test, migrée, a l'empreinte de sa dernière migration : le bloc passe. */
    public function testAMigratedDatabasePassesTheSchemaFingerprint(): void
    {
        $this->probe->executeStatement(RolesVerification::blockOf('#611'));

        self::assertSame(8, $this->probe->fetchOne("SELECT count(*) FROM pg_trigger WHERE tgname = 'trg_translation_group_single_position'"), 'Prémisse : le garde sur ses huit tables, dans l\'empreinte.');
    }

    /**
     * Une dernière migration que verify.sql ne connaît pas — un checkout en
     * retard sur l'environnement — est refusée comme telle, pas comme un
     * schéma altéré.
     */
    public function testAnUnknownLatestMigrationIsRefused(): void
    {
        $this->arm(["INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\\Version29991231000000', now(), 0)"]);

        $this->assertBlockRefuses('#611', '#611 : aucune empreinte de schéma connue pour la dernière migration de la base');
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

    private function assertBlockRefuses(string $issue, string $refusal): void
    {
        try {
            $this->probe->executeStatement(RolesVerification::blockOf($issue));
            self::fail(\sprintf('Le bloc %s a accepté.', $issue));
        } catch (DriverException $exception) {
            self::assertStringContainsString($refusal, $exception->getMessage());
        }
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

    /** Les noms jetables de ce test, quotés : `{t1}`, `{f}`, `{r}`, `{app}`, `{migrator}`, et la base `{db}`. */
    private function expand(string $statement): string
    {
        return strtr($statement, [
            '{t1}' => $this->probe->quoteSingleIdentifier('cpg_probe_t1_'.$this->suffix),
            '{f}' => $this->probe->quoteSingleIdentifier('cpg_probe_f_'.$this->suffix),
            '{r}' => $this->probe->quoteSingleIdentifier('cpg_probe_r_'.$this->suffix),
            '{app}' => $this->probe->quoteSingleIdentifier($this->app),
            '{migrator}' => $this->probe->quoteSingleIdentifier($this->migrator),
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

    private function database(): string
    {
        $database = $this->probe->fetchOne('SELECT current_database()');
        self::assertIsString($database);

        return $database;
    }
}
