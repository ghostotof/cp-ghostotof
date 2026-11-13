<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Mcp\Infrastructure\Log\SessionKeyCacheChannelRedactor;
use App\Tests\Mcp\Infrastructure\Log\Fixtures\KeyCarryingException;
use App\Tests\Support\ReadsAllChannelsLog;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * L'identifiant d'une session MCP ne sort pas par le canal `cache` (#731,
 * constat C2 de la relecture de la tâche 4, décision du 2026-11-10).
 *
 * Le store de sessions range chaque session sous la clé PSR-16
 * `mcp-portfolio-<uuid>`. Sur une panne du pool `cache.mcp_sessions`, Symfony
 * Cache écrit un `warning` par opération sur le canal `cache`, la clé dans le
 * contexte (`key`) et, une fois le message interpolé par le handler, dans le
 * message. Le handler `main` de `when@prod` reçoit ce canal : sans le
 * processeur, l'identifiant, qui vaut accès à la session, partait dans les
 * journaux de production et de préprod.
 */
final class SessionKeyCacheChannelRedactorTest extends KernelTestCase
{
    use ReadsAllChannelsLog;

    /**
     * Le préfixe des clés de session, écrit ici à part de la constante du
     * processeur : c'est celui que le bundle donne au store du serveur
     * `portfolio` (un oracle tenu à l'écart, comme le `/api/mcp` des tests).
     * Une constante du processeur qui s'en écarterait fait rougir les cas
     * unitaires, un préfixe que le bundle changerait le test de la vraie
     * chaîne.
     */
    private const string SESSION_KEY_PREFIX = 'mcp-portfolio-';

    /** Un identifiant de session comme le SDK les écrit dans la clé : un UUID RFC 4122. */
    private const string SESSION_ID = '6af5f9eb-6135-453e-8f4e-07791d2cb213';

    /**
     * La vraie chaîne : le store du conteneur (le décorateur, le store PSR-16
     * du SDK, le pool DBAL), le vrai logger du canal `cache`, la sonde de tous
     * les canaux. La panne est celle d'une transaction que PostgreSQL a
     * abandonnée : toute requête du pool y échoue (SQLSTATE 25P02), sur la
     * connexion même que le pool partage avec Doctrine.
     *
     * Les quatre opérations du store passent chacune par un site de Symfony
     * Cache qui journalise la clé : la lecture (`getItem`), le contrôle
     * d'existence (`hasItem`), l'écriture (`commit`) et la suppression
     * (`deleteItems`). Les quatre lignes doivent exister, préfixe lisible —
     * la ligne dit encore quel pool a échoué —, et aucun enregistrement
     * d'aucun canal ne doit porter l'identifiant. Si le bundle changeait le
     * préfixe des clés, le processeur ne les reconnaîtrait plus : ce test
     * rougirait sur l'identifiant.
     */
    public function testAFailingSessionPoolWritesTheSessionIdOnNoChannel(): void
    {
        self::bootKernel();
        $store = self::getContainer()->get('mcp.server.portfolio.session.store');
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $sessionId = Uuid::v4();

        $connection->beginTransaction();
        try {
            $this->abortTheTransaction($connection);

            self::assertFalse($store->exists($sessionId));
            self::assertFalse($store->read($sessionId));
            self::assertFalse($store->write($sessionId, '{}'));
            self::assertFalse($store->destroy($sessionId));
        } finally {
            $connection->rollBack();
        }

        $messages = array_map(
            static fn (LogRecord $record): string => $record->message,
            array_filter(self::allChannelsLogRecordsOf('cache'), static fn (LogRecord $record): bool => Level::Warning === $record->level),
        );
        foreach (['Failed to fetch key "', 'Failed to check if key "', 'Failed to save key "', 'Failed to delete key "'] as $site) {
            self::assertNotSame(
                [],
                array_filter($messages, static fn (string $message): bool => str_starts_with($message, $site.self::SESSION_KEY_PREFIX)),
                \sprintf('Le canal cache doit avoir écrit « %s%s… » : sans cette ligne, le test ne prouve rien pour ce site.', $site, self::SESSION_KEY_PREFIX),
            );
        }

        $formatter = new JsonFormatter();
        $leaks = [];
        foreach (self::allChannelsLogRecords() as $record) {
            // Les paramètres SQL : journalisés sous kernel.debug seulement,
            // jamais avec APP_DEBUG=0 (préprod et prod).
            if ('doctrine' === $record->channel && Level::Debug === $record->level) {
                continue;
            }

            $line = $formatter->format($record);
            if (str_contains($line, $sessionId->toRfc4122())) {
                $leaks[] = \sprintf('%s/%s : %s', $record->channel, $record->level->getName(), trim($line));
            }
        }

        self::assertSame([], $leaks, "L'identifiant de session est sorti par un journal.");
    }

    /**
     * Le site de Symfony Cache tel qu'il arrive au processeur : la clé dans
     * `key`, le message pas encore interpolé (`{key}`) — l'interpolation est
     * celle du handler, après les processeurs du canal — et, collé derrière,
     * le message de l'exception.
     */
    public function testASessionKeyLosesItsIdentifierAndKeepsItsPrefix(): void
    {
        $record = (new SessionKeyCacheChannelRedactor())($this->record(
            'Failed to fetch key "{key}": the database is gone',
            ['key' => self::SESSION_KEY_PREFIX.self::SESSION_ID, 'exception' => $exception = new RuntimeException('the database is gone'), 'cache-adapter' => 'DoctrineDbalAdapter'],
        ));

        self::assertSame(self::SESSION_KEY_PREFIX.SessionKeyCacheChannelRedactor::WITHHELD, $record->context['key']);
        self::assertSame('Failed to fetch key "{key}": the database is gone', $record->message);
        // Le reste de la ligne est le signal d'exploitation : il ne bouge pas,
        // l'exception sous la forme que le formateur JSON lui donne.
        self::assertSame([
            'class' => RuntimeException::class,
            'message' => 'the database is gone',
            'code' => 0,
            'file' => $exception->getFile().':'.$exception->getLine(),
        ], $record->context['exception']);
        self::assertSame('DoctrineDbalAdapter', $record->context['cache-adapter']);
        self::assertSame(Level::Warning, $record->level);
        self::assertSame('cache', $record->channel);
    }

    /**
     * Le message peut porter la clé avant toute interpolation : Symfony Cache
     * y colle le message de l'exception, et PostgreSQL cite les paramètres
     * liés dans son `CONTEXT:` dès que `log_parameter_max_length_on_error`
     * n'est pas nul (mesuré : `unnamed portal with parameters: $1 = '…'`).
     * Le paramètre est l'identifiant de la ligne, la clé derrière l'espace de
     * noms du pool.
     */
    public function testTheIdentifierIsRemovedFromAMessageThatAlreadyQuotesIt(): void
    {
        $quoted = \sprintf("ERROR:  division by zero\nCONTEXT:  unnamed portal with parameters: \$1 = 'XyZ:%s%s'", self::SESSION_KEY_PREFIX, self::SESSION_ID);

        $record = (new SessionKeyCacheChannelRedactor())($this->record(
            'Failed to check if key "{key}" is cached: '.$quoted,
            ['key' => self::SESSION_KEY_PREFIX.self::SESSION_ID],
        ));

        self::assertStringNotContainsString(self::SESSION_ID, $record->message);
        self::assertSame(
            \sprintf("Failed to check if key \"{key}\" is cached: ERROR:  division by zero\nCONTEXT:  unnamed portal with parameters: \$1 = 'XyZ:%s%s'", self::SESSION_KEY_PREFIX, SessionKeyCacheChannelRedactor::WITHHELD),
            $record->message,
        );
    }

    /**
     * L'exception du contexte, que le formateur JSON rend par sa classe, son
     * message, son code, son fichier et sa chaîne `previous` : un maillon qui
     * cite la clé (le message brut du pilote, sous l'exception de DBAL) est
     * réécrit, les autres gardés, la forme étant celle du formateur.
     */
    public function testAnExceptionQuotingTheKeyIsNormalisedWithoutTheIdentifier(): void
    {
        $driver = new PDOException(\sprintf("SQLSTATE[22012]: CONTEXT:  unnamed portal with parameters: \$1 = 'XyZ:%s%s'", self::SESSION_KEY_PREFIX, self::SESSION_ID));
        $exception = new RuntimeException('An exception occurred while executing a query', 7, $driver);

        $record = (new SessionKeyCacheChannelRedactor())($this->record(
            'Failed to save key "{key}" of type string: An exception occurred while executing a query',
            ['key' => self::SESSION_KEY_PREFIX.self::SESSION_ID, 'exception' => $exception],
        ));

        self::assertSame([
            'class' => RuntimeException::class,
            'message' => 'An exception occurred while executing a query',
            'code' => 7,
            'file' => $exception->getFile().':'.$exception->getLine(),
            'previous' => [
                'class' => PDOException::class,
                'message' => \sprintf("SQLSTATE[22012]: CONTEXT:  unnamed portal with parameters: \$1 = 'XyZ:%s%s'", self::SESSION_KEY_PREFIX, SessionKeyCacheChannelRedactor::WITHHELD),
                'code' => 0,
                'file' => $driver->getFile().':'.$driver->getLine(),
            ],
        ], $record->context['exception']);
        self::assertStringNotContainsString(self::SESSION_ID, (new JsonFormatter())->format($record));
    }

    /**
     * Une exception peut porter la clé ailleurs que dans un message : Monolog
     * rend une exception `JsonSerializable` par son `jsonSerialize()` (et un
     * `SoapFault` par son `detail`). Dès que l'enregistrement désigne une
     * session, toute exception du contexte devient donc sa forme normalisée,
     * qui ne garde que classe, message, code, fichier:ligne et `previous`
     * (constat n°1 de l'audit de sécurité de la PR 2).
     */
    public function testEveryExceptionOfASessionRecordIsNormalised(): void
    {
        $exception = new KeyCarryingException(self::SESSION_KEY_PREFIX.self::SESSION_ID);

        $record = (new SessionKeyCacheChannelRedactor())($this->record(
            'Failed to delete key "{key}": the database is gone',
            ['key' => self::SESSION_KEY_PREFIX.self::SESSION_ID, 'exception' => $exception],
        ));

        self::assertSame([
            'class' => KeyCarryingException::class,
            'message' => 'the database is gone',
            'code' => 0,
            'file' => $exception->getFile().':'.$exception->getLine(),
        ], $record->context['exception']);
        self::assertStringNotContainsString(self::SESSION_ID, (new JsonFormatter())->format($record));
    }

    /**
     * `getItems()` journalise la liste des clés demandées (`keys`), sans
     * `key` : chaque clé de session y perd son identifiant, les autres
     * restent.
     */
    public function testEverySessionKeyOfAListLosesItsIdentifier(): void
    {
        $other = Uuid::v4()->toRfc4122();

        $record = (new SessionKeyCacheChannelRedactor())($this->record(
            'Failed to fetch items: the database is gone',
            ['keys' => [self::SESSION_KEY_PREFIX.self::SESSION_ID, 'contact_form-203.0.113.7', self::SESSION_KEY_PREFIX.$other]],
        ));

        self::assertSame(
            [self::SESSION_KEY_PREFIX.SessionKeyCacheChannelRedactor::WITHHELD, 'contact_form-203.0.113.7', self::SESSION_KEY_PREFIX.SessionKeyCacheChannelRedactor::WITHHELD],
            $record->context['keys'],
        );
    }

    /**
     * Le processeur ne regarde pas le niveau : les lignes `info` du verrou de
     * calcul de Symfony Cache (`Item "{key}" is locked…`) portent la clé
     * aussi, et la préprod écrit le canal en `debug`.
     */
    public function testTheLevelDoesNotMatter(): void
    {
        $record = (new SessionKeyCacheChannelRedactor())($this->record(
            'Item "{key}" is locked, waiting for it to be released',
            ['key' => self::SESSION_KEY_PREFIX.self::SESSION_ID],
            Level::Info,
        ));

        self::assertSame(self::SESSION_KEY_PREFIX.SessionKeyCacheChannelRedactor::WITHHELD, $record->context['key']);
        self::assertSame(Level::Info, $record->level);
    }

    /**
     * Tout ce qui ne désigne pas une session MCP sort tel quel — le même
     * objet, rien n'est recopié : la clé d'un autre pool (un limiteur, le
     * signal de redémarrage des workers) est le signal d'exploitation que
     * l'exclusion `!cache` du handler aurait perdu.
     *
     * @param array<string, mixed> $context
     */
    #[DataProvider('recordsThatAreNotAboutAnMcpSession')]
    public function testARecordThatNamesNoMcpSessionIsLeftUntouched(string $message, array $context): void
    {
        $record = $this->record($message, $context);

        self::assertSame($record, (new SessionKeyCacheChannelRedactor())($record));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function recordsThatAreNotAboutAnMcpSession(): iterable
    {
        $exception = new LogicException('the database is gone');

        yield "la clé d'un autre pool" => ['Failed to fetch key "{key}": the database is gone', ['key' => 'contact_form-203.0.113.7', 'exception' => $exception]];
        yield 'une clé qui contient le préfixe sans commencer par lui' => ['Failed to fetch key "{key}"', ['key' => 'about-'.self::SESSION_KEY_PREFIX.self::SESSION_ID]];
        yield 'un contexte sans clé' => ['Failed to clear the cache: the database is gone', ['exception' => $exception, 'cache-adapter' => 'DoctrineDbalAdapter']];
        yield 'un contexte vide' => ['Failed to clear the cache: Namespace-prefix contains invalid characters.', []];
        yield "une clé qui n'est pas une chaîne" => ['Failed to fetch key "{key}"', ['key' => 42]];
        yield "une liste de clés d'un autre pool" => ['Failed to fetch items: the database is gone', ['keys' => ['contact_form-203.0.113.7', 42]]];
        yield "des clés qui ne sont pas une liste" => ['Failed to fetch items', ['keys' => 'contact_form-203.0.113.7']];
        yield 'le préfixe seul, sans identifiant' => ['Failed to fetch key "{key}"', ['key' => self::SESSION_KEY_PREFIX]];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(string $message, array $context, Level $level = Level::Warning): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'cache', $level, $message, $context);
    }

    /**
     * Une erreur dans une transaction ouverte : PostgreSQL refuse ensuite
     * toute commande jusqu'au ROLLBACK.
     */
    private function abortTheTransaction(Connection $connection): void
    {
        try {
            $connection->executeStatement('SELECT 1/0');
        } catch (DriverException) {
            return;
        }

        self::fail('La division par zéro devait échouer et abandonner la transaction.');
    }
}
