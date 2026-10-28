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
 * Platform. Une dépréciation lui appartient (`concerns()`) dans trois cas :
 *
 * - son message commence par `Since api-platform/` — la forme de
 *   `trigger_deprecation()` pour un paquet d'API Platform ;
 * - ou elle est levée par un fichier d'un paquet `api-platform/*` (un message
 *   libre, sans ce préfixe) ;
 * - ou **le premier appelant extérieur au paquet qui la lève** est un fichier
 *   de `vendor/api-platform/`. En remontant la pile depuis la méthode
 *   dépréciée, on saute les trames du paquet qui lève (`vendor/<vendor>/<paquet>/`,
 *   et `deprecation-contracts`, qui n'est que le relais de `trigger_error()`) :
 *   le premier fichier d'un autre paquet décide. C'est le cas d'API Platform
 *   qui appelle une API dépréciée de Symfony (« Since symfony/serializer
 *   8.x ») : le message est celui de Symfony, mais c'est API Platform qu'il
 *   faut migrer, et c'est l'angle mort de l'indirect ignoré. Si cet appelant
 *   est le projet, l'affaire est la sienne et PHPUnit la compte ; si c'est un
 *   autre paquet, API Platform n'appelle pas l'API dépréciée (la sous-requête
 *   de son `ErrorListener` passe par le noyau de Symfony, par exemple) : il
 *   n'a rien à migrer. Un fichier hors du projet et hors de `vendor/` (le
 *   conteneur compilé de `var/cache/`) est une colle, sautée aussi.
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
 * la compte, elle, hors du réglage indirect. Le premier appelant extérieur
 * seul décide : API Platform qui appelle un paquet intermédiaire de Symfony,
 * lequel appelle une API dépréciée d'un troisième, n'est pas relevé.
 *
 * @phpstan-type Frame array{file?: string, line?: int, class?: string, type?: string, function?: string}
 */
final class ApiPlatformDeprecations
{
    /** Le préfixe que `trigger_deprecation('api-platform/…', …)` donne au message. */
    private const string MESSAGE_PREFIX = 'Since api-platform/';

    /** Les paquets d'API Platform ; le `/` final écarte `api-platform-bridge`. */
    private const string PACKAGE_PREFIX = 'api-platform/';

    /** Le paquet qui relaie `trigger_error()` pour tout le monde : jamais « celui qui lève ». */
    private const string RELAY_PACKAGE = 'symfony/deprecation-contracts';

    /** Le paquet d'un fichier : `vendor/<vendor>/<paquet>`, le dernier `vendor/` du chemin. */
    private const string PACKAGE_PATTERN = '#^.*/vendor/([^/]+)/([^/]+)#';

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
     * `@internal` à PHPUnit — d'où le repli sur la closure
     * telle quelle si la classe n'existe pas ou si la liaison est refusée.
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
        return str_starts_with($message, self::MESSAGE_PREFIX) || null !== self::decidingFrame($frames);
    }

    /**
     * La trame d'API Platform qui fait de la dépréciation la sienne, ou `null`.
     *
     * En remontant la pile, la première trame d'un paquet fixe « le paquet qui
     * lève » ; si c'est un paquet d'API Platform, c'est elle. Sinon les trames
     * de ce paquet sont sautées, et la première d'un autre paquet décide : elle
     * est d'API Platform, ou non. Le projet qui s'y trouve avant décide « non ».
     *
     * @param list<Frame> $frames
     *
     * @return Frame|null
     */
    private static function decidingFrame(array $frames): ?array
    {
        $raising = null;
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? '';
            if ('' === $file) {
                continue;
            }

            if (self::isProjectFile($file)) {
                return null;
            }

            $package = self::packageOf($file);
            if (null === $package || self::RELAY_PACKAGE === $package) {
                continue;
            }

            if (null === $raising) {
                $raising = $package;
                if (self::isApiPlatformPackage($package)) {
                    return $frame;
                }

                continue;
            }

            if ($package === $raising) {
                continue;
            }

            return self::isApiPlatformPackage($package) ? $frame : null;
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
     * `vendor/<vendor>/<paquet>` d'un fichier du vendor, ou `null` hors vendor.
     */
    private static function packageOf(string $file): ?string
    {
        return 1 === preg_match(self::PACKAGE_PATTERN, $file, $matches) ? $matches[1].'/'.$matches[2] : null;
    }

    private static function isApiPlatformPackage(string $package): bool
    {
        return str_starts_with($package, self::PACKAGE_PREFIX);
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

        $apiPlatform = self::decidingFrame($frames);
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
