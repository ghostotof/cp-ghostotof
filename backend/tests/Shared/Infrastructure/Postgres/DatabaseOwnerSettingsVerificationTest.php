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
 * Issue #611 : propriétaire de chaque table de `public`, il peut aussi y
 * activer la sécurité au niveau des lignes sans politique — l'application,
 * sans `BYPASSRLS`, n'y voit plus rien. Le bloc `#611` est rejoué de même.
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

    private Connection $probe;

    private string $app;

    private string $migrator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->probe = self::openProbeBeside(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        self::assertSame('on', $this->probe->fetchOne("SELECT current_setting('is_superuser')"), 'Prémisse : le rôle de test est le superutilisateur de l\'image, qui crée les rôles jetables.');

        $suffix = bin2hex(random_bytes(4));
        $this->app = 'cpg_app_probe_'.$suffix;
        $this->migrator = 'cpg_migrator_probe_'.$suffix;
        $reader = 'cpg_reader_probe_'.$suffix;

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

    /** La base de test, migrée, n'a aucune table en sécurité au niveau des lignes : le bloc passe. */
    public function testAMigratedDatabaseWithoutRowSecurityPasses(): void
    {
        $this->probe->executeStatement(RolesVerification::blockOf('#611'));

        self::assertSame(0, $this->probe->fetchOne("SELECT count(*) FROM pg_class WHERE relnamespace = 'public'::regnamespace AND (relrowsecurity OR relforcerowsecurity)"), 'Prémisse : le projet n\'utilise pas la sécurité au niveau des lignes.');
    }

    /**
     * Prémisse du bloc `#611`, mesurée sur l'image épinglée : sans politique,
     * une table en sécurité au niveau des lignes ne montre plus aucune ligne au
     * rôle applicatif, qui a pourtant son `SELECT`.
     */
    public function testRowSecurityWithoutPolicyHidesEveryRowFromTheApplication(): void
    {
        $table = $this->throwawayTable('(id integer)');
        $this->probe->executeStatement(\sprintf('INSERT INTO %s VALUES (1)', $table));
        $this->probe->executeStatement(\sprintf('GRANT SELECT ON %s TO %s', $table, $this->probe->quoteSingleIdentifier($this->app)));
        $this->probe->executeStatement(\sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table));

        $this->probe->executeStatement(\sprintf('SET LOCAL ROLE %s', $this->probe->quoteSingleIdentifier($this->app)));
        self::assertSame(0, $this->probe->fetchOne(\sprintf('SELECT count(*) FROM %s', $table)));
    }

    /**
     * Chaque drapeau de sécurité au niveau des lignes posé sur une table de
     * `public` est refusé : `ENABLE` coupe l'application, `FORCE` seul est
     * inerte mais arme le prochain `ENABLE`, et une table partitionnée filtre
     * ce qu'on lit par elle.
     */
    #[DataProvider('rowSecurityAlterations')]
    public function testEveryRowSecurityFlagIsRefused(string $definition, string $alteration): void
    {
        $this->probe->executeStatement(\sprintf('ALTER TABLE %s %s', $this->throwawayTable($definition), $alteration));

        try {
            $this->probe->executeStatement(RolesVerification::blockOf('#611'));
            self::fail('Le bloc #611 a accepté : '.$alteration);
        } catch (DriverException $exception) {
            self::assertStringContainsString('#611 : 1 table(s) de public en sécurité au niveau des lignes', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rowSecurityAlterations(): iterable
    {
        yield 'activée' => ['(id integer)', 'ENABLE ROW LEVEL SECURITY'];
        yield 'forcée seule' => ['(id integer)', 'FORCE ROW LEVEL SECURITY'];
        yield 'activée sur une table partitionnée' => ['(id integer) PARTITION BY RANGE (id)', 'ENABLE ROW LEVEL SECURITY'];
    }

    /** Une table de `public`, créée dans la transaction annulée au `tearDown`. */
    private function throwawayTable(string $definition): string
    {
        $table = $this->probe->quoteSingleIdentifier('cpg_rls_probe_'.bin2hex(random_bytes(4)));
        $this->probe->executeStatement(\sprintf('CREATE TABLE public.%s %s', $table, $definition));

        return 'public.'.$table;
    }

    private function database(): string
    {
        $database = $this->probe->fetchOne('SELECT current_database()');
        self::assertIsString($database);

        return $database;
    }
}
