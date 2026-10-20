<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Log;

use Mcp\Exception\InvalidInputMessageException;
use Mcp\Exception\PromptGetException;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Borne ce que le SDK MCP écrit sur son canal `mcp` (spec 0007, tâche 4, #643 ;
 * reports des relectures de #640 et #642).
 *
 * Le SDK 0.8.1 et le bundle étiquettent tous leurs services sur ce canal, en
 * dur. Trois défauts, que ce processeur corrige pour tout enregistrement, à
 * tout niveau, avant qu'un handler ne le reçoive :
 *
 *  - **le contenu des échanges** : « Received message to process. » porte le
 *    JSON-RPC brut, « Handling request. » la requête décodée, et les
 *    gestionnaires les arguments — le `role` du prompt est du texte libre où une
 *    personne peut écrire son nom ou celui de son entreprise. Ces clés
 *    ({@see self::WITHHELD_KEYS}) sont remplacées par {@see self::WITHHELD}, de
 *    même que l'identifiant de session, qui vaut accès à la session ;
 *  - **les chaînes choisies par l'appelant**, citées sans borne (un nom d'outil
 *    ou de méthode inconnu de 100 Ko, jusqu'à 100 par lot) : le message, chaque
 *    chaîne du contexte et le message de chaque exception sont tronqués à
 *    {@see self::MAX_CHARACTERS} caractères, et une exception devient sa forme
 *    normalisée, sans la pile ;
 *  - **le niveau des fautes du client** : le SDK écrit en `error` un outil ou un
 *    prompt inconnu, un refus du prompt, un argument requis absent ou d'un type
 *    inconvertible — un anonyme
 *    pouvait écrire une ligne `error` par requête en production. Ces
 *    enregistrements passent en `notice`, sous le plafond `warning` du handler
 *    de production (monolog.yaml) : le journal d'appels `mcp_usage` en garde
 *    l'issue et le code JSON-RPC, rien n'est perdu.
 *
 * Le classement des fautes du client est une liste fermée, écrite contre le SDK
 * 0.8.1 : par classe d'exception quand le SDK en a une qui ne désigne que le
 * client, par code (-32602) ou début de message pour une RegistryException, par
 * message exact sinon. Une faute serveur (une exception de câblage,
 * {@see \App\Mcp\Presentation\Prompt\RoleNeutralisationFailedException}) garde son
 * niveau. {@see \App\Tests\Mcp\Infrastructure\Log\SdkChannelRecordsTest} provoque
 * chaque faute sur le vrai serveur : une montée de version du SDK qui change un
 * message ou une classe le fait rougir.
 */
#[AsMonologProcessor(channel: 'mcp')]
final class SdkChannelRedactor
{
    /** Au-delà, une chaîne est tronquée : une ligne de journal n'a pas à citer plus. */
    public const int MAX_CHARACTERS = 256;

    public const string WITHHELD = '[withheld, see issue #643]';

    /**
     * Les clés de contexte du SDK qui portent un échange ou une session.
     *
     * @var list<string>
     */
    public const array WITHHELD_KEYS = [
        'message', 'request', 'response', 'notification', 'arguments', 'params',
        'structured_content', 'error_args',
        'session_id', 'payload_session_id', 'expected_session_id',
    ];

    /**
     * Les exceptions du SDK qui ne désignent qu'une faute du client.
     *
     * @var list<class-string<Throwable>>
     */
    private const array CLIENT_FAULTS = [
        ToolNotFoundException::class,
        PromptNotFoundException::class,
        ResourceNotFoundException::class,
        // Refus d'un prompt : rôle vide ou trop long, langue inconnue.
        PromptGetException::class,
        // Message JSON-RPC mal formé, méthode inconnue.
        InvalidInputMessageException::class,
        // Pas Mcp\Exception\InvalidArgumentException : le SDK la lève aussi pour
        // un défaut de câblage (« Invalid handler type »), et un argument
        // inconvertible arrive enveloppé dans une RegistryException.
    ];

    /**
     * Les messages du SDK qui ne désignent qu'une faute du client, quand
     * l'exception est absente ou générique : un JsonException n'est une faute du
     * client qu'au décodage de l'entrée, à l'encodage d'une réponse c'est une
     * panne du serveur.
     *
     * @var list<string>
     */
    private const array CLIENT_FAULT_MESSAGES = [
        'Failed to decode json message.',
        'Received an id-less error response from client; cannot correlate it to a pending request.',
    ];

    /**
     * RegistryException est générique : seuls ces débuts de message, levés par
     * ReferenceHandler sur un argument reçu, sont une faute du client — un
     * argument requis absent, un argument que le SDK n'a pas su convertir.
     *
     * @var list<string>
     */
    private const array CLIENT_ARGUMENT_FAULTS = [
        'Internal error: Missing required argument `',
        'Internal error: Error processing parameter `',
    ];

    /** Profondeur de la chaîne `previous` gardée. */
    private const int MAX_PREVIOUS = 3;

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = [];
        foreach ($record->context as $key => $value) {
            $context[$key] = \in_array($key, self::WITHHELD_KEYS, true) ? self::WITHHELD : $this->bounded($value);
        }

        return $record->with(
            message: self::boundedString($record->message),
            context: $context,
            level: $this->isClientFault($record) ? Level::Notice : $record->level,
        );
    }

    private function isClientFault(LogRecord $record): bool
    {
        if (!Level::Warning->includes($record->level)) {
            return false;
        }

        if (\in_array($record->message, self::CLIENT_FAULT_MESSAGES, true)) {
            return true;
        }

        $exception = $record->context['exception'] ?? null;
        if (!$exception instanceof Throwable) {
            return false;
        }

        if ($exception instanceof RegistryException) {
            return Error::INVALID_PARAMS === $exception->getCode()
                || array_any(self::CLIENT_ARGUMENT_FAULTS, static fn (string $prefix): bool => str_starts_with($exception->getMessage(), $prefix));
        }

        return array_any(self::CLIENT_FAULTS, static fn (string $class): bool => $exception instanceof $class);
    }

    private function bounded(mixed $value): mixed
    {
        return match (true) {
            \is_string($value) => self::boundedString($value),
            null === $value, \is_int($value), \is_float($value), \is_bool($value) => $value,
            $value instanceof Throwable => self::normalized($value, self::MAX_PREVIOUS),
            \is_object($value) => $value::class,
            default => get_debug_type($value),
        };
    }

    /**
     * La forme normalisée du formateur JSON, sans la pile ni les arguments.
     *
     * @return array<string, mixed>
     */
    private static function normalized(Throwable $exception, int $depth): array
    {
        $normalized = [
            'class' => $exception::class,
            'message' => self::boundedString($exception->getMessage()),
            'code' => $exception->getCode(),
            'file' => $exception->getFile().':'.$exception->getLine(),
        ];

        $previous = $exception->getPrevious();
        if ($previous instanceof Throwable && $depth > 0) {
            $normalized['previous'] = self::normalized($previous, $depth - 1);
        }

        return $normalized;
    }

    private static function boundedString(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $length = mb_strlen($value, 'UTF-8');
        if ($length <= self::MAX_CHARACTERS) {
            return $value;
        }

        return \sprintf('%s… (%d characters)', mb_substr($value, 0, self::MAX_CHARACTERS, 'UTF-8'), $length);
    }
}
