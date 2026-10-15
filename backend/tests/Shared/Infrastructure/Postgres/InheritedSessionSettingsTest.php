<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Postgres;

use App\Tests\Support\OpensProbeConnection;
use App\Tests\Support\RolesVerification;
use App\Tests\Support\TestCredentials;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Pdo\Pgsql;
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
 * ses réglages, puis ouvre une **nouvelle** connexion avec les options du
 * runbook, comme `psql` dans le pod.
 *
 * Issue #634 : ces réglages validés survivaient à un passage interrompu
 * (fatale, délai, Ctrl-C), et tous les autres tests en héritaient jusqu'au
 * passage suivant de celui-ci. Ils sont donc posés sur une **base jetable**,
 * créée vide au `setUp` et supprimée au `tearDown` — et au `setUp` suivant,
 * si un passage interrompu l'a laissée : la base de test partagée n'en porte
 * jamais aucun. `DROP DATABASE` emporte aussi les réglages de rôle propres à
 * la base. Coût mesuré : une base créée depuis `template0` par test.
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

    /** Le suffixe de la base jetable, ajouté au nom de la base de test partagée. */
    private const string THROWAWAY_SUFFIX = '_heritage';

    /** La base de test partagée : elle crée et supprime la base jetable, rien d'autre. */
    private Connection $shared;

    /** La base jetable, vide, où le test plante ses réglages. */
    private Connection $probe;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->shared = self::openProbeBeside(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        self::assertSame('on', $this->shared->fetchOne("SELECT current_setting('is_superuser')"), 'Prémisse : le rôle de test est le superutilisateur de l\'image, comme celui de la procédure.');

        $this->dropThrowawayDatabase();
        $this->shared->executeStatement(\sprintf('CREATE DATABASE %s TEMPLATE template0', $this->shared->quoteSingleIdentifier($this->throwawayDatabase())));
        $this->probe = self::openProbeOn($this->shared, $this->throwawayDatabase());
    }

    protected function tearDown(): void
    {
        if (isset($this->probe)) {
            $this->probe->close();
        }
        if (isset($this->shared)) {
            $this->dropThrowawayDatabase();
            $this->shared->close();
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
        $clean = $this->cleanFingerprint();
        $this->plant();
        self::assertSame([], $this->sharedDatabaseSettings(), 'Ce que le test plante ne touche jamais la base de test partagée.');

        $this->inProcedureSession(RolesVerification::sessionOptions(), function (Connection $session) use ($clean): void {
            $session->executeStatement("LOAD 'plpgsql'");
            $inherited = $session->fetchFirstColumn('SELECT name FROM pg_catalog.pg_settings WHERE source IN '.self::INHERITED.' ORDER BY name');
            foreach ([...array_keys(self::DATABASE_SETTINGS), ...array_keys(self::ROLE_IN_DATABASE_SETTINGS)] as $name) {
                self::assertContains($name, $inherited, 'Prémisse : la nouvelle session hérite de '.$name.'.');
            }
            self::assertNotSame($clean, RolesVerification::schemaFingerprint($session), 'Prémisse : hérité, quote_all_identifiers change l\'empreinte #611.');
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
            self::assertSame($clean, RolesVerification::schemaFingerprint($session));
        });
    }

    /**
     * Issue #634 : les deux tromperies mesurées à la main pour #618, qui
     * laissent la procédure réussir (psql sort en 0), rejouées sur l'écriture
     * d'un mot de passe telle que roles.sql la fait (`ALTER ROLE … PASSWORD`,
     * étape 2) dans une nouvelle session :
     *
     *  - `debug_print_parse` écrit l'arbre de chaque requête, au niveau `LOG`,
     *    dans le journal du serveur (`log_min_messages` le laisse passer). Le
     *    test ne lit pas ce journal : il abaisse `client_min_messages` à `log`
     *    par une option de connexion, que la remise ne touche pas, et reçoit
     *    les mêmes messages par le rappel d'avis de pdo_pgsql ;
     *  - `scram_iterations`, posé sur le rôle de la session dans la base,
     *    donne un vérificateur d'une seule itération, lu dans `pg_authid`.
     *
     * Sans la remise, le mot de passe est dans les messages et le vérificateur
     * faible ; avec elle, aucun message ne le porte et le vérificateur a les
     * itérations de démarrage. Le rôle jetable meurt avec la transaction.
     */
    public function testAPasswordWrittenAfterTheResetLeavesNoTraceAndKeepsItsIterations(): void
    {
        $this->plant();
        $options = RolesVerification::sessionOptions().' -c client_min_messages=log';

        $this->inProcedureSession($options, function (Connection $session): void {
            [$messages, $iterations] = $this->writeAPassword($session);
            self::assertNotSame([], $messages, 'Prémisse : hérité, debug_print_parse écrit le mot de passe.');
            self::assertSame(1, $iterations, 'Prémisse : hérité, scram_iterations affaiblit le vérificateur.');
        });

        $this->inProcedureSession($options, function (Connection $session): void {
            $session->executeStatement(RolesVerification::inheritedSettingsReset());
            [$messages, $iterations] = $this->writeAPassword($session);

            self::assertSame([], $messages, 'Aucun message ne porte le mot de passe.');
            self::assertSame((string) $iterations, $session->fetchOne("SELECT boot_val FROM pg_catalog.pg_settings WHERE name = 'scram_iterations'"), 'Le vérificateur a les itérations de démarrage.');
        });
    }

    /**
     * Un rôle jetable reçoit un mot de passe, comme à l'étape 2 de roles.sql :
     * renvoie les messages du serveur qui portent ce mot de passe, et les
     * itérations du vérificateur SCRAM écrit (`SCRAM-SHA-256$<itérations>:…`).
     *
     * @return array{list<string>, int}
     */
    private function writeAPassword(Connection $session): array
    {
        $password = TestCredentials::variant('roles-procedure');
        $role = $session->quoteSingleIdentifier('cpg_probe_pw_'.bin2hex(random_bytes(4)));
        $native = $session->getNativeConnection();
        self::assertInstanceOf(Pgsql::class, $native);

        $messages = [];
        $native->setNoticeCallback(static function (string $message) use (&$messages, $password): void {
            if (str_contains($message, $password)) {
                $messages[] = $message;
            }
        });
        $session->executeStatement('CREATE ROLE '.$role);
        $session->executeStatement(\sprintf('ALTER ROLE %s PASSWORD %s', $role, $session->quote($password)));
        $verifier = $session->fetchOne('SELECT rolpassword FROM pg_catalog.pg_authid WHERE oid = ?::regrole', [$role]);
        self::assertIsString($verifier);
        self::assertSame(1, preg_match('/\ASCRAM-SHA-256\$(\d+):/', $verifier, $iterations), 'Un vérificateur SCRAM (password_encryption de la procédure).');

        return [$messages, (int) $iterations[1]];
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

    /** Le nom de la base jetable : fixe, pour qu'un passage interrompu la retrouve. */
    private function throwawayDatabase(): string
    {
        $shared = $this->shared->fetchOne('SELECT current_database()');
        self::assertIsString($shared);

        return $shared.self::THROWAWAY_SUFFIX;
    }

    /** Supprime la base jetable, sessions ouvertes comprises, si elle existe. */
    private function dropThrowawayDatabase(): void
    {
        $this->shared->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $this->shared->quoteSingleIdentifier($this->throwawayDatabase())));
    }

    /**
     * L'empreinte du schéma de la base jetable, calculée comme le bloc `#611`
     * la calcule, dans une transaction annulée : avant tout réglage planté.
     */
    private function cleanFingerprint(): string
    {
        $this->probe->beginTransaction();
        try {
            return RolesVerification::schemaFingerprint($this->probe);
        } finally {
            $this->probe->rollBack();
        }
    }

    /**
     * Les réglages plantés par ce test que porte la base de test partagée,
     * sur elle ou sur un rôle en elle : ceux qu'hériteraient tous les autres
     * tests si un passage était interrompu.
     *
     * @return list<string>
     */
    private function sharedDatabaseSettings(): array
    {
        $shared = $this->shared->fetchFirstColumn(
            "SELECT split_part(c, '=', 1) FROM pg_db_role_setting s, unnest(s.setconfig) AS c
             WHERE s.setdatabase = (SELECT oid FROM pg_database WHERE datname = current_database()) ORDER BY 1",
        );
        $planted = [...array_keys(self::DATABASE_SETTINGS), ...array_keys(self::ROLE_IN_DATABASE_SETTINGS), self::WITHOUT_BOOT_VALUE];

        return array_values(array_filter($planted, static fn (string $name): bool => \in_array($name, $shared, true)));
    }

    private function database(): string
    {
        $database = $this->probe->fetchOne('SELECT current_database()');
        self::assertIsString($database);

        return $this->probe->quoteSingleIdentifier($database);
    }
}
