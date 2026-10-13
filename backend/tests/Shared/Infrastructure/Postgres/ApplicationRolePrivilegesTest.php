<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Postgres;

use App\Portfolio\Shared\Infrastructure\Doctrine\TranslationGroupRefusal;
use App\Tests\Support\KubernetesManifests;
use App\Tests\Support\OpensProbeConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #575 : en préprod et en prod, l'application tourne sous un rôle
 * PostgreSQL sans privilège de superutilisateur ni propriété des tables
 * (`cpg_app`, DML seulement), les migrations sous le propriétaire
 * (`cpg_migrator`), et les `psql` humains du runbook sous un lecteur
 * (`cpg_reader`). Les privilèges sont écrits une fois, dans
 * `k8s/base/postgres-roles/privileges.sql`, joué par le Job
 * `postgres-roles-job.yaml` ; ce test rejoue **ce même fichier** sur le schéma
 * réel de la base de test, avec des rôles jetables, pour que le CI attrape
 * avant la release ce qui ne se verrait sinon qu'en 500 sur un pod :
 *
 * - un GRANT oublié : chaque table et chaque séquence du schéma, migrations
 *   comprises, est lisible et modifiable par le rôle applicatif, sauf la table
 *   des migrations, qui lui est retirée ;
 * - les privilèges par défaut : une table que le rôle de migration crée après
 *   coup est accessible sans geste ;
 * - ce que le rôle applicatif ne peut PAS faire — désactiver ou supprimer le
 *   trigger différé des groupes de traduction (#390, #501, #503), couper tous
 *   les triggers de la session, créer ou altérer une table, vider une table ;
 * - ce dont l'application a besoin sous ce rôle : le trigger se déclenche
 *   encore (il s'exécute avec les droits de celui qui écrit, `SECURITY
 *   INVOKER`), les verrous consultatifs, `set_config('lock_timeout')`,
 *   `SET CONSTRAINTS`, les tables `cache_items` et `messenger_messages`.
 *
 * Le rôle de la base de test reste le superutilisateur de l'image (dev et
 * CI, décidé pour #575) : il crée les rôles jetables et prend leur identité par
 * `SET ROLE`, le tout dans une transaction annulée au `tearDown` — `CREATE
 * ROLE` et `GRANT` sont transactionnels en PostgreSQL, rien ne survit. Les
 * rôles sont `NOLOGIN` : aucun mot de passe, donc aucun secret dans ce test.
 */
final class ApplicationRolePrivilegesTest extends KernelTestCase
{
    use OpensProbeConnection;

    private const string PRIVILEGES_SQL = 'postgres-roles/privileges.sql';

    private const string TRIGGER = 'trg_translation_group_single_position';

    /** SQLSTATE de PostgreSQL : privilège insuffisant. */
    private const string INSUFFICIENT_PRIVILEGE = '42501';

    private const string MIGRATIONS_TABLE = 'doctrine_migration_versions';

    /**
     * Les colonnes NOT NULL de la table, recopiées par décision (issue #585,
     * {@see \App\Tests\Migrations\DeferredTriggerPendingEventsTest}).
     */
    private const string INSERT_CONTRIBUTION = "INSERT INTO contribution (id, translation_group, locale, position, title, project, reference, url, summary, body)
        VALUES (?, ?, ?, ?, 'T', 'P', 'R', 'https://example.org', 'S', 'B')";

    private Connection $probe;

    private string $app;

    private string $migrator;

    private string $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->probe = self::openProbeBeside(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        self::assertSame('on', $this->probe->fetchOne("SELECT current_setting('is_superuser')"), 'Prémisse : le rôle de test est le superutilisateur de l\'image, qui crée les rôles jetables.');

        $suffix = bin2hex(random_bytes(4));
        $this->app = 'cpg_app_probe_'.$suffix;
        $this->migrator = 'cpg_migrator_probe_'.$suffix;
        $this->reader = 'cpg_reader_probe_'.$suffix;

        $this->probe->beginTransaction();
        foreach ([$this->app, $this->migrator, $this->reader] as $role) {
            $this->probe->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE', $this->probe->quoteSingleIdentifier($role)));
        }
        foreach ($this->privilegeStatements() as $statement) {
            $this->probe->executeStatement($statement);
        }
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

    /**
     * Le GRANT oublié : chaque table du schéma — celles de demain comprises,
     * puisque le test lit le schéma migré — est lisible et modifiable, jamais
     * vidable ; la table des migrations est hors de portée.
     */
    public function testTheApplicationRoleReadsAndWritesEveryTableButTheMigrationsOne(): void
    {
        $tables = $this->tablesOfPublic();
        self::assertContains(self::MIGRATIONS_TABLE, $tables);
        self::assertGreaterThan(5, \count($tables), 'Prémisse : le schéma est migré.');

        foreach ($tables as $table) {
            if (self::MIGRATIONS_TABLE === $table) {
                self::assertFalse($this->hasTablePrivilege($this->app, $table, 'SELECT'), 'La table des migrations ne regarde pas l\'application.');

                continue;
            }
            self::assertTrue($this->hasTablePrivilege($this->app, $table, 'SELECT,INSERT,UPDATE,DELETE'), \sprintf('GRANT oublié : le rôle applicatif n\'a pas le DML sur %s.', $table));
            self::assertFalse($this->hasTablePrivilege($this->app, $table, 'TRUNCATE'), \sprintf('Le rôle applicatif ne doit pas pouvoir vider %s.', $table));
        }

        foreach ($this->sequencesOfPublic() as $sequence) {
            self::assertTrue($this->hasSequencePrivilege($this->app, $sequence, 'USAGE'), \sprintf('GRANT oublié : le rôle applicatif n\'a pas USAGE sur la séquence %s.', $sequence));
        }
    }

    /**
     * Les privilèges par défaut : ce qu'une migration future crée (sous le
     * rôle de migration) est accessible au rôle applicatif sans aucun geste.
     */
    public function testATableTheMigratorCreatesLaterIsOpenToTheApplicationRole(): void
    {
        $this->probe->executeStatement(\sprintf('GRANT CREATE ON SCHEMA public TO %s', $this->identifier($this->migrator)));
        $this->setRole($this->migrator);
        $this->probe->executeStatement('CREATE TABLE public.probe_575 (id integer GENERATED BY DEFAULT AS IDENTITY, v text)');
        $this->probe->executeStatement('CREATE SEQUENCE public.probe_575_seq');
        $this->resetRole();

        self::assertTrue($this->hasTablePrivilege($this->app, 'probe_575', 'SELECT,INSERT,UPDATE,DELETE'));
        self::assertTrue($this->hasSequencePrivilege($this->app, 'probe_575_seq', 'USAGE'));
        self::assertTrue($this->hasSequencePrivilege($this->app, 'probe_575_id_seq', 'USAGE'), 'La séquence d\'identité suit la table.');

        $this->setRole($this->app);
        $this->probe->executeStatement("INSERT INTO public.probe_575 (v) VALUES ('x')");
        self::assertSame(1, $this->probe->fetchOne("SELECT nextval('public.probe_575_seq')"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDefinitionsRefusedToTheApplicationRole(): iterable
    {
        yield 'désactiver le trigger' => ['ALTER TABLE contribution DISABLE TRIGGER '.self::TRIGGER];
        yield 'supprimer le trigger' => ['DROP TRIGGER '.self::TRIGGER.' ON contribution'];
        yield 'supprimer sa fonction' => ['DROP FUNCTION portfolio_translation_group_single_position()'];
        yield 'couper les triggers de la session' => ['SET session_replication_role = replica'];
        yield 'altérer une table' => ['ALTER TABLE contribution ADD COLUMN probe_575 integer'];
        yield 'créer une table' => ['CREATE TABLE public.probe_575_refused (id integer)'];
        yield 'vider une table' => ['TRUNCATE contribution'];
        yield 'lire la table des migrations' => ['SELECT COUNT(*) FROM '.self::MIGRATIONS_TABLE];
    }

    /**
     * Ce que #503 laissait ouvert tant que l'application était superutilisateur
     * et propriétaire : chaque voie qui contourne ou démonte le trigger est
     * refusée en `42501`, de même que tout DDL.
     */
    #[DataProvider('provideDefinitionsRefusedToTheApplicationRole')]
    public function testTheApplicationRoleCanNeitherBypassTheTriggerNorAlterTheSchema(string $definition): void
    {
        $this->setRole($this->app);
        self::assertSame('off', $this->probe->fetchOne("SELECT current_setting('is_superuser')"));

        $this->assertRefusedWith(self::INSUFFICIENT_PRIVILEGE, $definition);
    }

    /**
     * Le trigger s'exécute avec les droits de celui qui écrit : sous le rôle
     * applicatif, un groupe sur deux positions est encore refusé en `23T90`.
     */
    public function testTheTriggerStillFiresUnderTheApplicationRole(): void
    {
        $this->setRole($this->app);
        $group = Uuid::v7();
        $this->insertContribution($group, 'fr', 900);
        $this->insertContribution($group, 'en', 950);

        $this->assertRefusedWith(TranslationGroupRefusal::ON_SEVERAL_POSITIONS, 'SET CONSTRAINTS '.self::TRIGGER.' IMMEDIATE');
    }

    /**
     * Un groupe cohérent écrit, contrôlé (`SET CONSTRAINTS`, que tout rôle
     * peut poser) et supprimé sous le rôle applicatif : l'écriture ordinaire
     * d'un `Administrator`.
     */
    public function testTheApplicationRoleWritesOrderedContent(): void
    {
        $this->setRole($this->app);
        $group = Uuid::v7();
        $this->insertContribution($group, 'fr', 900);
        $this->insertContribution($group, 'en', 900);
        $this->probe->executeStatement('SET CONSTRAINTS '.self::TRIGGER.' IMMEDIATE');
        $this->probe->executeStatement('SET CONSTRAINTS ALL DEFERRED');

        self::assertSame(2, $this->probe->executeStatement('DELETE FROM contribution WHERE translation_group = ?', [$group->toRfc4122()]));
    }

    /**
     * Ce que les verrous demandent à la session (#272, #389, #512) : les
     * verrous consultatifs, de transaction et de session, et la borne d'attente.
     */
    public function testTheApplicationRoleHasWhatTheLocksNeed(): void
    {
        $this->setRole($this->app);

        $this->probe->executeStatement('SELECT pg_advisory_xact_lock(575, 1)');
        self::assertTrue($this->probe->fetchOne('SELECT pg_try_advisory_lock(575)'));
        self::assertTrue($this->probe->fetchOne('SELECT pg_advisory_unlock(575)'));
        self::assertSame('5s', $this->probe->fetchOne("SELECT set_config('lock_timeout', '5s', true)"));
    }

    /**
     * Le cache sur DBAL (ADR 0005) et le transport Doctrine de `failed`
     * (écriture, suppression, `LISTEN`) : tout passe sous le rôle applicatif.
     */
    public function testTheApplicationRoleWritesTheCacheAndTheFailedTransport(): void
    {
        $this->setRole($this->app);

        $this->probe->executeStatement('INSERT INTO cache_items (item_id, item_data, item_lifetime, item_time) VALUES (?, ?, 60, 0)', ['probe-575', 'x']);
        self::assertSame(1, $this->probe->executeStatement('DELETE FROM cache_items WHERE item_id = ?', ['probe-575']));
        $this->probe->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('b', '[]', 'probe-575', now(), now())");
        self::assertSame(1, $this->probe->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'probe-575'"));
        $this->probe->executeStatement('LISTEN "messenger_messages"');
        $this->probe->executeStatement('UNLISTEN "messenger_messages"');
        self::assertIsInt($this->probe->fetchOne('SELECT COUNT(*) FROM cpg_user'));
    }

    /** Le lecteur du runbook lit tout et n'écrit rien. */
    public function testTheReaderRoleReadsEverythingAndWritesNothing(): void
    {
        self::assertTrue($this->hasTablePrivilege($this->reader, 'cpg_user', 'SELECT'));
        self::assertFalse($this->hasTablePrivilege($this->reader, 'cpg_user', 'INSERT,UPDATE,DELETE'));

        $this->setRole($this->reader);
        self::assertIsInt($this->probe->fetchOne('SELECT COUNT(*) FROM cpg_user'));
        $this->assertRefusedWith(self::INSUFFICIENT_PRIVILEGE, "INSERT INTO cache_items (item_id, item_data, item_lifetime, item_time) VALUES ('probe-575', 'x', 60, 0)");
    }

    /**
     * Les instructions du fichier, les variables psql remplacées par les rôles
     * jetables : du SQL pur, que psql et ce test lisent de la même façon.
     *
     * @return list<string>
     */
    private function privilegeStatements(): array
    {
        $source = KubernetesManifests::base()->source(self::PRIVILEGES_SQL);
        self::assertDoesNotMatchRegularExpression('/^\\\\/m', $source, 'privileges.sql est du SQL pur : une méta-commande psql ne se rejoue pas ici.');

        $sql = strtr($source, [
            ':"app_role"' => $this->identifier($this->app),
            ':"migrator_role"' => $this->identifier($this->migrator),
            ':"reader_role"' => $this->identifier($this->reader),
        ]);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        self::assertIsString($sql);
        self::assertStringNotContainsString(':"', $sql, 'privileges.sql n\'utilise que :"app_role", :"migrator_role" et :"reader_role".');

        $statements = array_values(array_filter(array_map(trim(...), explode(';', $sql)), static fn (string $statement): bool => '' !== $statement));
        self::assertNotEmpty($statements);

        return $statements;
    }

    private function setRole(string $role): void
    {
        $this->probe->executeStatement('SET ROLE '.$this->identifier($role));
    }

    private function resetRole(): void
    {
        $this->probe->executeStatement('RESET ROLE');
    }

    private function identifier(string $role): string
    {
        return $this->probe->quoteSingleIdentifier($role);
    }

    private function hasTablePrivilege(string $role, string $table, string $privileges): bool
    {
        return (bool) $this->probe->fetchOne('SELECT has_table_privilege(?, ?, ?)', [$role, 'public.'.$table, $privileges]);
    }

    private function hasSequencePrivilege(string $role, string $sequence, string $privileges): bool
    {
        return (bool) $this->probe->fetchOne('SELECT has_sequence_privilege(?, ?, ?)', [$role, 'public.'.$sequence, $privileges]);
    }

    /**
     * @return list<string>
     */
    private function tablesOfPublic(): array
    {
        /** @var list<string> $tables */
        $tables = $this->probe->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY 1");

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function sequencesOfPublic(): array
    {
        /** @var list<string> $sequences */
        $sequences = $this->probe->fetchFirstColumn("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'S' ORDER BY 1");

        return $sequences;
    }

    private function insertContribution(Uuid $group, string $locale, int $position): void
    {
        $this->probe->executeStatement(self::INSERT_CONTRIBUTION, [Uuid::v7()->toRfc4122(), $group->toRfc4122(), $locale, $position]);
    }

    /**
     * L'instruction doit être refusée sous ce SQLSTATE ; dans un point de
     * sauvegarde, pour que la transaction du test reste utilisable.
     */
    private function assertRefusedWith(string $sqlState, string $statement): void
    {
        $this->probe->executeStatement('SAVEPOINT refusal');
        try {
            $this->probe->executeStatement($statement);
        } catch (DriverException $refusal) {
            $this->probe->executeStatement('ROLLBACK TO SAVEPOINT refusal');
            self::assertSame($sqlState, $refusal->getSQLState(), $refusal->getMessage());

            return;
        }

        self::fail(\sprintf('La base devait refuser en %s, l\'instruction est passée : %s', $sqlState, $statement));
    }
}
