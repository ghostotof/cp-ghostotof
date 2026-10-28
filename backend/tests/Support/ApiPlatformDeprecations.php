<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Closure;


/**
 * Relève, le temps d'une action, les dépréciations que déclenche API Platform
 * (issue #701) — celles que PHPUnit ne montre pas.
 *
 * `phpunit.dist.xml` pose `ignoreIndirectDeprecations` : une dépréciation que
 * le vendor lève en appelant le vendor n'y est pas comptée, et c'est ainsi que
 * `ItemNormalizer::denormalize()` a écrit dans les journaux de production
 * sans qu'aucun test ne rougisse. Ce réglage reste, parce qu'il évite le bruit
 * de tout le reste du vendor ; ce collecteur, lui, ne regarde qu'API
 * Platform. Une dépréciation lui appartient (`concerns()`) dans deux cas :
 *
 * - son message commence par `Since api-platform/` — la forme de
 *   `trigger_deprecation()` pour un paquet d'API Platform ;
 * - ou la pile d'appels, remontée depuis la méthode dépréciée, traverse un
 *   fichier de `vendor/api-platform/` **avant** le premier fichier du projet
 *   (`src/`, `tests/`). C'est le cas d'API Platform qui appelle une API
 *   dépréciée d'un autre paquet (« Since symfony/serializer 8.x ») : le
 *   message est celui de Symfony, l'origine du fichier qui lève aussi, mais
 *   c'est API Platform qu'il faut migrer, et c'est exactement l'angle mort de
 *   l'indirect ignoré. Si le projet appelle lui-même l'API dépréciée, la
 *   pile le rencontre d'abord : l'affaire est la sienne, pas celle d'API
 *   Platform, et PHPUnit la compte.
 *
 * **Il ne masque rien d'autre.** Le gestionnaire qu'il pose ne garde que les
 * dépréciations d'API Platform ; toute autre erreur est rendue au gestionnaire
 * qu'il a trouvé en place (celui de PHPUnit, ou de Symfony), qui la traite
 * comme avant. Il le restaure toujours, action en échec comprise.
 *
 * Limite : une `E_USER_DEPRECATED` seulement. Une dépréciation du moteur PHP
 * (`E_DEPRECATED`) levée par le code d'API Platform n'est pas relevée : PHPUnit
 * la compte, elle, hors du réglage indirect.
 *
 * @phpstan-type Frame array{file?: string, line?: int, class?: string, type?: string, function?: string}
 */
final class ApiPlatformDeprecations
{
    /** Le préfixe que `trigger_deprecation('api-platform/…', …)` donne au message. */
    private const string MESSAGE_PREFIX = 'Since api-platform/';

    /** Où vit le code d'API Platform ; le `/` final écarte `api-platform-bridge`. */
    private const string VENDOR_DIRECTORY = '/vendor/api-platform/';

    /** Le vendor, dont aucun fichier n'est « du projet » même sous un `src/` ou un `tests/`. */
    private const string ANY_VENDOR_DIRECTORY = '/vendor/';

    /** La fonction de `symfony/deprecation-contracts` qui appelle `trigger_error()` pour tout le monde. */
    private const string TRIGGER_FUNCTION = 'trigger_deprecation';

    /**
     * Exécute l'action et rend les dépréciations d'API Platform qu'elle a
     * déclenchées, chacune avec son message, la méthode dépréciée, l'endroit
     * où elle l'a levée et, le cas échéant, l'appel d'API Platform qui l'a
     * provoquée.
     *
     * @param Closure(): void $action
     *
     * @return list<string>
     */
    public static function during(Closure $action): array
    {
        $findings = [];
        $previous = set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$findings, &$previous): bool {
            if (\E_USER_DEPRECATED === $level) {
                // La pile est lue ici : un appel de plus la décalerait. Les
                // arguments ne servent à rien, et ne sont pas gardés.
                $frames = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
                if (self::concerns($message, $frames)) {
                    $findings[] = self::describe($message, $frames);

                    return true;
                }
            }

            // Tout le reste appartient au gestionnaire remplacé ; sans lui,
            // `false` laisse faire celui de PHP. Seul un `false` explicite
            // dit « non traité » : un gestionnaire qui ne rend rien a traité.
            return null !== $previous && false !== $previous($level, $message, $file, $line);
        });

        try {
            $action();
        } finally {
            restore_error_handler();
        }

        return $findings;
    }

    /**
     * @param list<Frame> $frames la pile au moment du `trigger_error()`
     *                            (`debug_backtrace()` lu dans le gestionnaire),
     *                            de la plus proche à la plus lointaine ;
     *                            le `file` d'une trame est l'endroit d'où
     *                            elle a été appelée
     */
    public static function concerns(string $message, array $frames): bool
    {
        return str_starts_with($message, self::MESSAGE_PREFIX) || null !== self::apiPlatformFrame($frames);
    }

    /**
     * La première trame d'un fichier d'API Platform que la pile traverse avant
     * d'atteindre le code du projet, ou `null`.
     *
     * @param list<Frame> $frames
     *
     * @return Frame|null
     */
    private static function apiPlatformFrame(array $frames): ?array
    {
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? '';
            if (self::isProjectFile($file)) {
                return null;
            }

            if (str_contains($file, self::VENDOR_DIRECTORY)) {
                return $frame;
            }
        }

        return null;
    }

    private static function isProjectFile(string $file): bool
    {
        return !str_contains($file, self::ANY_VENDOR_DIRECTORY)
            && (str_contains($file, '/src/') || str_contains($file, '/tests/'));
    }

    /**
     * Le texte de l'échec : le message, la méthode dépréciée, l'endroit où la
     * dépréciation est levée et, si API Platform en est l'appelant, l'appel
     * d'API Platform qui y mène.
     *
     * @param list<Frame> $frames
     */
    private static function describe(string $message, array $frames): string
    {
        // La trame 0 est le gestionnaire (appelé par le moteur, sans fichier) ;
        // la 1 est `trigger_error()`, appelé depuis l'endroit qui lève. Si
        // c'est `trigger_deprecation()`, la trame suivante en est l'appel,
        // fait depuis le corps de la méthode dépréciée, dont la trame vient
        // ensuite ; sinon la méthode a appelé `trigger_error()` elle-même.
        $raisedAt = $frames[1] ?? [];
        $methodIndex = 2;
        if (self::TRIGGER_FUNCTION === ($frames[2]['function'] ?? null)) {
            $raisedAt = $frames[2];
            $methodIndex = 3;
        }

        $method = $frames[$methodIndex] ?? [];
        $parts = [
            \sprintf('méthode dépréciée : %s', self::callable($method)),
            \sprintf('levée à %s', self::location($raisedAt)),
        ];

        $apiPlatform = self::apiPlatformFrame($frames);
        // Quand c'est API Platform qui lève lui-même, l'endroit est déjà dit.
        if (null !== $apiPlatform && self::location($apiPlatform) !== self::location($raisedAt)) {
            $parts[] = \sprintf('appelée depuis API Platform à %s', self::location($apiPlatform));
        }

        return \sprintf('%s (%s)', $message, implode(' ; ', $parts));
    }

    /**
     * @param Frame $frame
     */
    private static function callable(array $frame): string
    {
        $function = $frame['function'] ?? null;

        return null === $function ? 'inconnue' : ($frame['class'] ?? '').($frame['type'] ?? '').$function.'()';
    }

    /**
     * @param Frame $frame
     */
    private static function location(array $frame): string
    {
        return ($frame['file'] ?? '?').':'.($frame['line'] ?? 0);
    }
}
