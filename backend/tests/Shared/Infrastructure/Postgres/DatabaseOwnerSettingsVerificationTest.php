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

    private function database(): string
    {
        $database = $this->probe->fetchOne('SELECT current_database()');
        self::assertIsString($database);

        return $database;
    }
}
