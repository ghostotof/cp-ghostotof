<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Mcp\Infrastructure\Log\SdkChannelRedactor;
use App\Mcp\Presentation\Prompt\RoleNeutralisationFailedException;
use DateTimeImmutable;
use JsonException;
use LogicException;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ToolNotFoundException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;
use TypeError;

/**
 * Le pendant de {@see SdkChannelRecordsTest}, qui provoque les fautes du client sur le
 * vrai serveur : ce que le processeur laisse passer tel quel. Une panne du
 * serveur garde son niveau — rétrogradée, elle disparaîtrait sous le plafond
 * `warning` du handler de production.
 */
final class SdkChannelRedactorTest extends TestCase
{
    /** Un témoin placé dans ce que le SDK valide : sa présence dans un enregistrement dirait une fuite. */
    private const string OUTPUT_SENTINEL = 'OUTPUT-SENTINEL-7d1f';

    /**
     * @return iterable<string, array{string, Throwable}>
     */
    public static function serverFaults(): iterable
    {
        yield 'échec d\'encodage d\'une réponse' => ['Failed to encode response to JSON.', new JsonException('Malformed UTF-8')];
        yield 'défaut de câblage d\'un outil' => ['Unhandled error during tool execution', new LogicException('wiring')];
        yield 'neutralisation du rôle en échec' => ['Unexpected error while handling prompt "present_for_role": "x".', RoleNeutralisationFailedException::fromPcre()];
        yield 'erreur du registre hors argument absent' => ['Unexpected error while handling prompt "p": "x".', RegistryException::internalError('Handler not callable.')];
        // Le SDK lève aussi sa propre InvalidArgumentException pour un défaut de
        // câblage (ReferenceHandler, HandlerResolver) : ce n'est pas le client.
        yield 'gestionnaire d\'un type invalide' => ['Unhandled error during tool execution', new InvalidArgumentException('Invalid handler type')];
        // « Rejected an unparseable modern-era request. » n'est une faute du client
        // que pour le JsonException du réencodage (un nombre lu infini) : toute
        // autre exception levée là — un TypeError de la fabrique du SDK — reste
        // à son niveau, elle dirait un défaut que le plafond ne doit pas cacher.
        yield 'requête moderne illisible pour une autre raison que son réencodage' => ['Rejected an unparseable modern-era request.', new TypeError('Cannot assign string to property of type ?array')];
    }

    #[DataProvider('serverFaults')]
    public function testAServerFaultKeepsItsLevel(string $message, Throwable $exception): void
    {
        $record = (new SdkChannelRedactor())($this->record(Level::Error, $message, ['exception' => $exception]));

        self::assertSame(Level::Error, $record->level);
    }

    public function testAClientFaultBelowWarningIsLeftAtItsLevel(): void
    {
        $record = (new SdkChannelRedactor())($this->record(Level::Info, 'Tool not found', ['exception' => new ToolNotFoundException('x')]));

        self::assertSame(Level::Info, $record->level);
    }

    /**
     * @return iterable<string, array{string, Throwable}>
     */
    public static function clientFaults(): iterable
    {
        yield 'outil inconnu' => ['Tool not found', new ToolNotFoundException('x')];
        yield 'argument requis absent' => ['Unexpected error while handling prompt "p": "x".', RegistryException::internalError('Missing required argument `role` for p.')];
        yield 'argument d\'un type inconvertible' => ['Unexpected error while handling prompt "p": "x".', RegistryException::internalError('Error processing parameter `role`: Array to string conversion')];
        yield 'paramètre invalide (-32602)' => ['Unhandled error during tool execution', RegistryException::invalidParams('Parameter `ids` must be a list of values, not an object.')];
        yield 'requête moderne que le SDK ne sait pas réencoder' => ['Rejected an unparseable modern-era request.', new JsonException('Inf and NaN cannot be JSON encoded')];
    }

    #[DataProvider('clientFaults')]
    public function testAClientFaultIsDowngradedToNotice(string $message, Throwable $exception): void
    {
        $record = (new SdkChannelRedactor())($this->record(Level::Error, $message, ['exception' => $exception]));

        self::assertSame(Level::Notice, $record->level);
    }

    public function testAnExceptionBecomesItsBoundedFormWithoutTrace(): void
    {
        $exception = new LogicException(str_repeat('é', 300), 7, new JsonException('inner'));

        $record = (new SdkChannelRedactor())($this->record(Level::Error, 'x', ['exception' => $exception]));

        $normalized = $record->context['exception'];
        self::assertIsArray($normalized);
        self::assertSame(LogicException::class, $normalized['class']);
        self::assertSame(str_repeat('é', 256).'… (300 characters)', $normalized['message']);
        self::assertSame(7, $normalized['code']);
        self::assertSame(__FILE__.':'.$exception->getLine(), $normalized['file']);
        self::assertSame(['class', 'message', 'code', 'file', 'previous'], array_keys($normalized));
        self::assertIsArray($normalized['previous']);
        self::assertSame(JsonException::class, $normalized['previous']['class']);
    }

    /** Une chaîne d'exceptions ne fait pas grossir la ligne sans fin : trois `previous`, pas plus. */
    public function testThePreviousChainIsCutAfterThreeLinks(): void
    {
        $chain = new LogicException('0');
        foreach (['1', '2', '3', '4', '5'] as $message) {
            $chain = new LogicException($message, 0, $chain);
        }

        $record = (new SdkChannelRedactor())($this->record(Level::Error, 'x', ['exception' => $chain]));

        $messages = [];
        $link = $record->context['exception'];
        while (\is_array($link)) {
            $messages[] = $link['message'];
            $link = $link['previous'] ?? null;
        }
        self::assertSame(['5', '4', '3', '2'], $messages);
    }

    public function testEachContextValueIsBoundedOrWithheld(): void
    {
        $record = (new SdkChannelRedactor())($this->record(Level::Debug, str_repeat('m', 257), [
            'message' => 'raw',
            'session_id' => '0199f1e2-0000-7000-8000-000000000000',
            'name' => str_repeat('n', 257),
            'limit' => 64,
            'ok' => true,
            'nothing' => null,
            'object' => new stdClass(),
            'list' => ['a' => 'b'],
            'at' => new DateTimeImmutable(),
        ]));

        self::assertSame(str_repeat('m', 256).'… (257 characters)', $record->message);
        self::assertSame([
            'message' => SdkChannelRedactor::WITHHELD,
            'session_id' => SdkChannelRedactor::WITHHELD,
            'name' => str_repeat('n', 256).'… (257 characters)',
            'limit' => 64,
            'ok' => true,
            'nothing' => null,
            'object' => stdClass::class,
            'list' => 'array',
            'at' => DateTimeImmutable::class,
        ], $record->context);
    }

    /**
     * Depuis la 0.9 le SDK valide la **sortie** d'un outil contre son
     * `outputSchema` avec le validateur qui ne voyait que les arguments. Quand
     * la validation elle-même échoue (un schéma qu'Opis ne sait pas résoudre),
     * ce validateur journalise en `error` ce qu'il validait, encodé en JSON
     * sous la clé `data` (`SchemaValidator::validateAgainstJsonSchema()`) : la
     * sortie de l'outil nominatif y serait le corpus. Bornée à 256 caractères
     * seulement, la ligne en portait le début dans les journaux du pod — la
     * clé est retenue (R11 de #731).
     *
     * L'enregistrement est celui que le vrai validateur écrit, sur un logger
     * qui porte le processeur comme le canal `mcp` : un SDK qui renommerait la
     * clé ferait rougir ce test. Les erreurs rendues à l'appelant ne citent
     * pas davantage la sortie.
     *
     * Rouge avant l'ajout de `data` à WITHHELD_KEYS (mesuré : la clé portait
     * le JSON de la sortie, témoin compris).
     */
    public function testTheOutputTheSdkFailedToValidateIsWithheld(): void
    {
        $probe = new TestHandler();
        $logger = new Logger('mcp', [$probe], [new SdkChannelRedactor()]);
        $unresolvable = ['type' => 'object', 'properties' => ['corpus' => ['$ref' => '#/definitions/missing']]];

        $errors = new SchemaValidator(logger: $logger)->validateAgainstJsonSchema((object) ['corpus' => self::OUTPUT_SENTINEL], $unresolvable);

        // Témoin : c'est bien l'échec interne de la validation qui a été pris.
        self::assertSame(['internal'], array_column($errors, 'keyword'));
        self::assertStringNotContainsString(self::OUTPUT_SENTINEL, json_encode($errors, \JSON_THROW_ON_ERROR));

        $records = $probe->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level, 'Une panne du serveur : elle garde son niveau.');
        self::assertSame('MCP SDK: JSON Schema validation failed internally.', $records[0]->message);
        self::assertSame(['exception', 'data', 'schema'], array_keys($records[0]->context), 'Le SDK journalise d\'autres clés : les juger.');
        self::assertSame(SdkChannelRedactor::WITHHELD, $records[0]->context['data']);
        self::assertStringNotContainsString(self::OUTPUT_SENTINEL, $records[0]->message.json_encode($records[0]->context, \JSON_THROW_ON_ERROR));
    }

    /**
     * L'identifiant de session vaut accès à la session, et le SDK l'écrit sous
     * plusieurs clés : `session_id` presque partout, mais `sessionId` dans
     * « Fiber yield handler failed. » (`BaseTransport::handleFiberYield()`, en
     * `error`). Chemin non emprunté aujourd'hui — aucun outil ne suspend sa
     * fibre —, retenu quand même : la clé n'était couverte par rien (R11 de
     * #731), et une chaîne de 36 caractères passe sous la borne.
     *
     * Rouge avant l'ajout de `sessionId` à WITHHELD_KEYS (mesuré).
     */
    public function testTheSessionIdOfAFailedFiberYieldIsWithheld(): void
    {
        $sessionId = '0199f1e2-0000-4000-8000-000000000000';

        $record = (new SdkChannelRedactor())($this->record(Level::Error, 'Fiber yield handler failed.', [
            'exception' => new LogicException('yield'),
            'sessionId' => $sessionId,
        ]));

        self::assertSame(SdkChannelRedactor::WITHHELD, $record->context['sessionId']);
        self::assertStringNotContainsString($sessionId, json_encode($record->context, \JSON_THROW_ON_ERROR));
    }

    public function testInvalidUtf8IsScrubbedRatherThanFailingTheRecord(): void
    {
        $record = (new SdkChannelRedactor())($this->record(Level::Warning, "bad \xC3", ['name' => "\xFF"]));

        self::assertSame('bad ?', $record->message);
        self::assertSame('?', $record->context['name']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(Level $level, string $message, array $context): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'mcp', $level, $message, $context);
    }
}
