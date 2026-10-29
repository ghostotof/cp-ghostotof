<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Closure;
use PHPUnit\Runner\ErrorHandler;

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
 * - ou **la pile traverse un fichier de `vendor/api-platform/` avant le premier
 *   fichier du projet**. En remontant depuis l'appel déprécié (les trames de
 *   `trigger_error()` et de `deprecation-contracts`, qui n'en sont que le
 *   relais, ne décident de rien), le premier fichier du projet clôt la
 *   recherche : le projet appelle, c'est son affaire, et PHPUnit la compte ; un
 *   fichier d'API Platform rencontré avant lui la retient. C'est le cas d'API
 *   Platform qui appelle une API dépréciée de Symfony (« Since symfony/serializer
 *   8.x ») : le message est celui de Symfony, mais c'est API Platform qu'il
 *   faut migrer, et c'est l'angle mort de l'indirect ignoré.
 *
 * **Le critère est volontairement large.** Entre API Platform et la
 * dépréciation, rien n'est supposé : un proxy paresseux de `var-exporter`, un
 * décorateur, l'autoloader de Composer (une dépréciation levée au chargement
 * d'une classe) ne la masquent pas. Le prix est un faux positif : la
 * sous-requête de l'`ErrorListener` d'API Platform passe par le noyau de
 * Symfony, et une dépréciation interne à Symfony qu'elle lève y est imputée à
 * API Platform, qui n'a rien à migrer. Pour une garde, un faux positif est
 * bruyant et se corrige d'une ligne ; un faux négatif est silencieux et coûte
 * cher. Un critère plus fin (le premier appelant extérieur au paquet qui lève)
 * a été essayé : il rendait la garde aveugle dans ces cas.
 * Un fichier hors du projet et hors d'API Platform (le conteneur compilé de
 * `var/cache/`) est une colle : ni l'un ni l'autre, la remontée continue.
 *
 * **Il ne masque rien d'autre.** Le gestionnaire qu'il pose ne garde que les
 * dépréciations d'API Platform ; toute autre erreur est rendue au gestionnaire
 * qu'il a trouvé en place (celui de PHPUnit, ou de Symfony), qui la traite
 * comme avant. Il le restaure toujours, action en échec comprise.
 *
 * **Le `@` n'est pas regardé, à dessein.** `trigger_deprecation()` appelle
 * `@trigger_error()` : toute dépréciation de ce genre arrive au gestionnaire
 * avec un `error_reporting()` réduit aux niveaux fatals, et c'est pour cela que
 * `phpunit.dist.xml` pose `ignoreSuppressionOfDeprecations="true"`, que le
 * collecteur reproduit. Écarter les erreurs « supprimées » le rendrait aveugle
 * à tout API Platform (mesuré : les 4 cas de dépréciation d'API Platform du
 * `ApiPlatformDeprecationCollectorTest` rougissaient). Sous PHPUnit,
 * `error_reporting()` est de toute façon ramené aux niveaux inattrapables
 * pendant un test, `@` ou non.
 *
 * Limites : une `E_USER_DEPRECATED` seulement. Une dépréciation du moteur PHP
 * (`E_DEPRECATED`) levée par le code d'API Platform n'est pas relevée : PHPUnit
 * la compte, elle, hors du réglage indirect. Une API dépréciée que le projet
 * appelle lui-même reste au gestionnaire en place, même si API Platform figure
 * plus haut dans la pile : c'est le projet qui appelle.
 *
 * @phpstan-type Frame array{file?: string, line?: int, class?: string, type?: string, function?: string}
 */
final class ApiPlatformDeprecations
{
    /** Le préfixe que `trigger_deprecation('api-platform/…', …)` donne au message. */
    private const string MESSAGE_PREFIX = 'Since api-platform/';

    /** Un fichier d'API Platform ; le `/` final écarte `api-platform-bridge`. */
    private const string VENDOR_DIRECTORY = '/vendor/api-platform/';

    /** La fonction de `symfony/deprecation-contracts` qui appelle `trigger_error()` pour tout le monde. */
    private const string TRIGGER_DEPRECATION_FUNCTION = 'trigger_deprecation';

    private const string TRIGGER_ERROR_FUNCTION = 'trigger_error';

    /** Le gestionnaire d'erreurs de PHPUnit, `@internal` : seul son nom est utilisé, jamais une instance. */
    private const string PHPUNIT_ERROR_HANDLER = ErrorHandler::class;

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
        /** @var (callable(int, string, string, int): mixed)|null $previous le gestionnaire remplacé, connu une fois le nôtre posé */
        $previous = null;

        // Retient la dépréciation d'API Platform. La pile commence à la trame de
        // `trigger_error()` : ce qui la précède est le gestionnaire et cette
        // closure (dont le `file` est celui de cette classe, un fichier du
        // projet qui ferait conclure « le projet appelle »), voire un
        // gestionnaire posé entre eux. Les arguments des trames ne servent à
        // rien, et ne sont pas gardés.
        $keep = static function (int $level, string $message) use (&$findings): bool {
            if (\E_USER_DEPRECATED !== $level) {
                return false;
            }

            $frames = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
            $frames = \array_slice($frames, self::indexOfFunction($frames, self::TRIGGER_ERROR_FUNCTION) ?? 0);
            if (!self::concerns($message, $frames)) {
                return false;
            }

            $findings[] = self::describe($message, $frames);

            return true;
        };

        $handler = static function (int $level, string $message, string $file, int $line) use (&$previous, $keep): bool {
            if ($keep($level, $message)) {
                return true;
            }

            // Tout le reste appartient au gestionnaire remplacé ; sans lui,
            // `false` laisse faire celui de PHP. Seul un `false` explicite
            // dit « non traité » : un gestionnaire qui ne rend rien a traité.
            return null !== $previous && false !== $previous($level, $message, $file, $line);
        };

        $previous = set_error_handler(self::asPhpunitReadsIt($handler));

        try {
            $action();
        } finally {
            restore_error_handler();
        }

        return $findings;
    }

    /**
     * La closure, vue de PHPUnit comme une de ses propres trames.
     *
     * Quand ce gestionnaire rend une erreur à celui de PHPUnit
     * (`PHPUnit\Runner\ErrorHandler`), celui-ci classe la dépréciation
     * (directe, indirecte) d'après les deux premières trames de la pile, une
     * fois retirées les siennes : `errorStackTrace()` retire d'abord la trame
     * en cours, puis tant que la classe de la suivante est `ErrorHandler`. La
     * trame d'une closure porte la classe de sa portée ; liée à
     * `ErrorHandler`, celle du collecteur est retirée avec elles, et la pile
     * que PHPUnit lit est celle qu'il aurait lue sans nous. Sans cela, un
     * `trigger_error()` brut (pas de `trigger_deprecation()`, dont PHPUnit
     * repère la trame et coupe la pile en amont) était classé « inconnu » et
     * compté, là où le même appel sans collecteur est ignoré comme indirect
     * (`ApiPlatformDeprecationHandoverTest`).
     *
     * Le prix : ce n'est lisible qu'en connaissant `ErrorHandler`, qui est
     * `@internal` à PHPUnit — d'où le repli sur la closure telle quelle si la
     * classe n'existe pas ou si la liaison est refusée.
     *
     * **Limite : ce repli est silencieux ici.** Si PHPUnit renomme cette
     * classe, ou si elle n'est pas chargée au moment de l'appel, rien ne
     * l'annonce : le collecteur redevient celui qui laissait compter une
     * dépréciation indirecte brute. Le bruit est ailleurs :
     * `ApiPlatformDeprecationHandoverTest` rougit, d'abord parce que la classe
     * attendue n'est plus là, puis parce que son PHPUnit enfant compte la
     * dépréciation.
     *
     * @param Closure(int, string, string, int): bool $handler
     *
     * @return Closure(int, string, string, int): bool
     */
    private static function asPhpunitReadsIt(Closure $handler): Closure
    {
        if (!class_exists(self::PHPUNIT_ERROR_HANDLER, false)) {
            return $handler;
        }

        return Closure::bind($handler, null, self::PHPUNIT_ERROR_HANDLER) ?? $handler;
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
     * La première trame appelée depuis un fichier d'API Platform, avant tout
     * fichier du projet, ou `null`.
     *
     * Le premier fichier du projet clôt la recherche (« le projet appelle ») ;
     * un fichier d'un autre paquet ne décide de rien et la remontée continue,
     * de même qu'un fichier de ni l'un ni l'autre (le conteneur compilé).
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

    /**
     * Un fichier du projet est sous `src/` ou `tests/` **du backend** — pas
     * n'importe quel `/src/` ou `/tests/` d'un chemin absolu, qu'un clone ou un
     * dossier de cache peut contenir (`/home/x/src/clone/backend/var/cache/…`).
     */
    private static function isProjectFile(string $file): bool
    {
        return str_starts_with($file, RepositoryFiles::backend('src').'/')
            || str_starts_with($file, RepositoryFiles::backend('tests').'/');
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
        // Les trames se repèrent par leur nom, pas par leur rang : un
        // gestionnaire posé entre le nôtre et `trigger_error()` ajoute une
        // trame. `trigger_error()` est appelé depuis l'endroit qui lève ; si
        // c'est `trigger_deprecation()`, la trame suivante en est l'appel, fait
        // depuis le corps de la méthode dépréciée, dont la trame vient
        // ensuite ; sinon la méthode a appelé `trigger_error()` elle-même.
        $errorIndex = self::indexOfFunction($frames, self::TRIGGER_ERROR_FUNCTION) ?? 0;
        $raisedAt = $frames[$errorIndex] ?? [];
        $methodIndex = $errorIndex + 1;
        if (self::TRIGGER_DEPRECATION_FUNCTION === ($frames[$methodIndex]['function'] ?? null)) {
            $raisedAt = $frames[$methodIndex];
            ++$methodIndex;
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
     * @param list<Frame> $frames
     */
    private static function indexOfFunction(array $frames, string $function): ?int
    {
        foreach ($frames as $index => $frame) {
            if (!isset($frame['class']) && ($frame['function'] ?? null) === $function) {
                return $index;
            }
        }

        return null;
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
