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
 * Platform. Une dépréciation lui appartient (`concerns()`) quand son message
 * commence par `Since api-platform/` — la forme de `trigger_deprecation()` —,
 * ou quand elle part d'un fichier de `vendor/api-platform/`.
 *
 * **Il ne masque rien d'autre.** Le gestionnaire qu'il pose ne garde que les
 * dépréciations d'API Platform ; toute autre erreur est rendue au gestionnaire
 * qu'il a trouvé en place (celui de PHPUnit, ou de Symfony), qui la traite
 * comme avant. Il le restaure toujours, action en échec comprise.
 *
 * Limite : une `E_USER_DEPRECATED` seulement. Une dépréciation du moteur PHP
 * (`E_DEPRECATED`) levée par le code d'API Platform n'est pas relevée : PHPUnit
 * la compte, elle, hors du réglage indirect.
 */
final class ApiPlatformDeprecations
{
    /** Le préfixe que `trigger_deprecation('api-platform/…', …)` donne au message. */
    private const string MESSAGE_PREFIX = 'Since api-platform/';

    /** Où vit le code d'API Platform ; le `/` final écarte `api-platform-bridge`. */
    private const string VENDOR_DIRECTORY = '/vendor/api-platform/';

    /** Le fichier qui appelle `trigger_error()` pour toutes les dépréciations de Symfony. */
    private const string DEPRECATION_CONTRACTS = '/vendor/symfony/deprecation-contracts/';

    /**
     * Exécute l'action et rend les dépréciations d'API Platform qu'elle a
     * déclenchées, chacune avec son message et son origine
     * (`fichier:ligne` de l'appelant de `trigger_deprecation()`).
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
                $origin = self::origin($file, $line);
                if (self::concerns($message, $origin)) {
                    $findings[] = \sprintf('%s (origine : %s)', $message, $origin);

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
     * @param string $origin le fichier (et la ligne) d'où part la dépréciation
     */
    public static function concerns(string $message, string $origin): bool
    {
        return str_starts_with($message, self::MESSAGE_PREFIX) || str_contains($origin, self::VENDOR_DIRECTORY);
    }

    /**
     * L'endroit qui a demandé la dépréciation. `trigger_error()` est appelé
     * depuis `deprecation-contracts`, qui n'apprend rien : l'origine est
     * l'appelant de `trigger_deprecation()`, la première trame hors de ce
     * paquet.
     */
    private static function origin(string $file, int $line): string
    {
        if (!str_contains($file, self::DEPRECATION_CONTRACTS)) {
            return $file.':'.$line;
        }

        foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $frameFile = $frame['file'] ?? null;
            if (null !== $frameFile && __FILE__ !== $frameFile && !str_contains($frameFile, self::DEPRECATION_CONTRACTS)) {
                return $frameFile.':'.($frame['line'] ?? 0);
            }
        }

        return $file.':'.$line;
    }
}
