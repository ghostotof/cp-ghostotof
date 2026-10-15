<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Postgres;

use App\Tests\Support\OpensProbeConnection;
use App\Tests\Support\RolesVerification;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issue #618 : un réglage posé sur la base (`ALTER DATABASE … SET`, permis à
 * son propriétaire, le rôle de migration) ou sur un rôle dans la base
 * s'applique à **chaque nouvelle session**, celle de la procédure des rôles
 * comprise. Les options de connexion (`PGOPTIONS`, #608) n'en écartent qu'une
 * liste, qui ne se ferme pas : roles.sql rend donc en tête à sa session la
 * valeur de démarrage de tout réglage hérité, puis refuse s'il en reste un.
 *
 * {@see DatabaseOwnerSettingsVerificationTest} rejoue ses blocs dans une
 * session déjà ouverte, qui n'hérite pas de ce qu'il pose : ce test-ci valide
 * ses réglages sur la base de test, puis ouvre une **nouvelle** connexion avec
 * les options du runbook, comme `psql` dans le pod. Les réglages posés sont
 * retirés un par un au `tearDown` (et au `setUp`, si un passage interrompu en
 * a laissé) : le fuseau que la migration pose sur la base reste intact.
 */
final class InheritedSessionSettingsTest extends KernelTestCase
{
    use OpensProbeConnection;

    /** Les sources d'un réglage hérité : la base, le rôle, le rôle dans la base. */
    private const string INHERITED = "('database', 'user', 'database user')";

    /**
     * Ce qu'un propriétaire de la base peut poser sur elle (mesuré sur l'image
     * épinglée), réglage => valeur : l'impression des identifiants change
     * l'empreinte `#611` (la procédure échouait à chaque passage), les trois
     * suivants changent le sens du SQL ou de PL/pgSQL, le dernier écrit l'arbre
     * de chaque requête — un mot de passe compris — dans le journal du serveur.
     */
    private const array DATABASE_SETTINGS = [
        'quote_all_identifiers' => 'on',
        'array_nulls' => 'off',
        'transform_null_equals' => 'on',
        'plpgsql.extra_errors' => 'all',
        'debug_print_parse' => 'on',
    ];

    /** Posé sur le rôle de la session dans la base : un vérificateur SCRAM d'une seule itération. */
    private const array ROLE_IN_DATABASE_SETTINGS = ['scram_iterations' => '1'];

    /** Le seul réglage `user` sans valeur de démarrage : la remise ne peut que le laisser. */
    private const string WITHOUT_BOOT_VALUE = 'timezone_abbreviations';

    private Connection $probe;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->probe = self::openProbeBeside(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        self::assertSame('on', $this->probe->fetchOne("SELECT current_setting('is_superuser')"), 'Prémisse : le rôle de test est le superutilisateur de l\'image, comme celui de la procédure.');
        $this->removePlantedSettings();
    }

    protected function tearDown(): void
    {
        if (isset($this->probe)) {
            $this->removePlantedSettings();
            $this->probe->close();
        }
        parent::tearDown();
    }

    /**
     * Prémisse : sans la remise, la session de la procédure hérite de chaque
     * réglage posé — et l'empreinte du schéma n'est plus celle des migrations.
     * Avec elle, plus aucun réglage hérité, chacun à sa valeur de démarrage,
     * et l'empreinte est celle d'une session saine.
     */
    public function testTheProcedureSessionKeepsNothingTheDatabaseSets(): void
    {
        $clean = $this->fingerprint($this->probe);
        $this->plant();

        $this->inProcedureSession(RolesVerification::sessionOptions(), function (Connection $session) use ($clean): void {
            $session->executeStatement("LOAD 'plpgsql'");
            $inherited = $session->fetchFirstColumn('SELECT name FROM pg_catalog.pg_settings WHERE source IN '.self::INHERITED.' ORDER BY name');
            foreach ([...array_keys(self::DATABASE_SETTINGS), ...array_keys(self::ROLE_IN_DATABASE_SETTINGS)] as $name) {
                self::assertContains($name, $inherited, 'Prémisse : la nouvelle session hérite de '.$name.'.');
            }
            self::assertNotSame($clean, $this->fingerprint($session), 'Prémisse : hérité, quote_all_identifiers change l\'empreinte #611.');
        });

        $this->inProcedureSession(RolesVerification::sessionOptions(), function (Connection $session) use ($clean): void {
            $session->executeStatement(RolesVerification::inheritedSettingsReset());

            self::assertSame([], $session->fetchFirstColumn('SELECT name FROM pg_catalog.pg_settings WHERE source IN '.self::INHERITED));
            foreach ([...array_keys(self::DATABASE_SETTINGS), ...array_keys(self::ROLE_IN_DATABASE_SETTINGS)] as $name) {
                self::assertSame(
                    $session->fetchOne('SELECT boot_val FROM pg_catalog.pg_settings WHERE name = ?', [$name]),
                    $session->fetchOne('SELECT pg_catalog.current_setting(?)', [$name]),
                    $name.' : rendu à sa valeur de démarrage.',
                );
            }
            self::assertSame($clean, $this->fingerprint($session));
        });
    }

    /**
     * Un réglage hérité que la remise ne peut pas rendre (sans valeur de
     * démarrage) arrête la procédure en le nommant, plutôt que de la laisser
     * tourner sous lui ; l'option de connexion du runbook, elle, le couvre.
     */
    public function testAnInheritedSettingLeftInPlaceStopsTheProcedure(): void
    {
        $this->probe->executeStatement(\sprintf("ALTER DATABASE %s SET %s = 'Australia'", $this->database(), self::WITHOUT_BOOT_VALUE));
        $withoutItsOption = trim((string) preg_replace('/-c '.self::WITHOUT_BOOT_VALUE.'=\S*/', '', RolesVerification::sessionOptions()));

        $this->inProcedureSession($withoutItsOption, function (Connection $session): void {
            try {
                $session->executeStatement(RolesVerification::inheritedSettingsReset());
                self::fail('La remise a laissé passer un réglage hérité.');
            } catch (DriverException $exception) {
                self::assertStringContainsString('#618 : réglages hérités non rendus : {'.self::WITHOUT_BOOT_VALUE.'}', $exception->getMessage());
            }
        });

        $this->inProcedureSession(RolesVerification::sessionOptions(), function (Connection $session): void {
            $session->executeStatement(RolesVerification::inheritedSettingsReset());
            self::assertSame('Default', $session->fetchOne('SHOW '.self::WITHOUT_BOOT_VALUE));
        });
    }

    /**
     * Mesuré sur l'image épinglée : un réglage sans valeur de démarrage, que
     * le propriétaire peut poser, ne se rend que par une option de connexion.
     * Une montée de PostgreSQL qui en ajouterait un fait rougir ce test.
     */
    public function testEverySettingWithoutBootValueIsAConnectionOption(): void
    {
        $this->probe->executeStatement("LOAD 'plpgsql'");
        $withoutBootValue = $this->probe->fetchFirstColumn("SELECT name FROM pg_catalog.pg_settings WHERE context = 'user' AND boot_val IS NULL ORDER BY name");
        self::assertSame([self::WITHOUT_BOOT_VALUE], $withoutBootValue);

        foreach ($withoutBootValue as $name) {
            self::assertMatchesRegularExpression('/(^| )-c '.preg_quote($name, '/').'=\S/', RolesVerification::sessionOptions(), $name.' : dans PGOPTIONS, avec une valeur.');
        }
    }

    /**
     * Ouvre une nouvelle connexion comme `psql` dans le pod : libpq lit
     * `PGOPTIONS` dans l'environnement du processus. La transaction ouverte
     * comme le flux l'ouvre est annulée, la connexion fermée, l'environnement
     * rendu.
     *
     * @param callable(Connection): void $operation
     */
    private function inProcedureSession(string $options, callable $operation): void
    {
        $previous = getenv('PGOPTIONS');
        putenv('PGOPTIONS='.$options);
        try {
            $session = self::openProbeBeside($this->probe);
            $session->executeStatement('BEGIN ISOLATION LEVEL READ COMMITTED');
        } finally {
            putenv(false === $previous ? 'PGOPTIONS' : 'PGOPTIONS='.$previous);
        }

        try {
            $operation($session);
        } finally {
            $session->close();
        }
    }

    /** Les réglages de {@see self::DATABASE_SETTINGS} sur la base, ceux de {@see self::ROLE_IN_DATABASE_SETTINGS} sur le rôle de test dans la base : validés. */
    private function plant(): void
    {
        foreach (self::DATABASE_SETTINGS as $name => $value) {
            $this->probe->executeStatement(\sprintf('ALTER DATABASE %s SET %s = %s', $this->database(), $name, $this->probe->quote($value)));
        }
        foreach (self::ROLE_IN_DATABASE_SETTINGS as $name => $value) {
            $this->probe->executeStatement(\sprintf('ALTER ROLE CURRENT_USER IN DATABASE %s SET %s = %s', $this->database(), $name, $this->probe->quote($value)));
        }
    }

    /** Retire ce que ce test pose, réglage par réglage : jamais `RESET ALL`, qui emporterait le fuseau de la migration. */
    private function removePlantedSettings(): void
    {
        foreach ([...array_keys(self::DATABASE_SETTINGS), self::WITHOUT_BOOT_VALUE] as $name) {
            $this->probe->executeStatement(\sprintf('ALTER DATABASE %s RESET %s', $this->database(), $name));
        }
        foreach (array_keys(self::ROLE_IN_DATABASE_SETTINGS) as $name) {
            $this->probe->executeStatement(\sprintf('ALTER ROLE CURRENT_USER IN DATABASE %s RESET %s', $this->database(), $name));
        }
    }

    /** L'empreinte du schéma, calculée par la requête même du bloc `#611`, sous son search_path. */
    private function fingerprint(Connection $connection): string
    {
        $connection->executeStatement('SET search_path = pg_catalog, pg_temp');
        $fingerprint = $connection->fetchOne(RolesVerification::schemaFingerprintQuery());
        self::assertIsString($fingerprint);

        return $fingerprint;
    }

    private function database(): string
    {
        $database = $this->probe->fetchOne('SELECT current_database()');
        self::assertIsString($database);

        return $this->probe->quoteSingleIdentifier($database);
    }
}
