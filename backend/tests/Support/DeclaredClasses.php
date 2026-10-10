<?php

declare(strict_types=1);

namespace App\Tests\Support;

use LogicException;
use ReflectionClass;

/**
 * Recense les déclarations nommées d'un répertoire, d'après ce que déclarent
 * les sources — jamais d'après le nom des fichiers (issue #348).
 *
 * Déduire la classe du chemin (PSR-4) ignorait sans bruit un fichier mal nommé
 * ou qui en déclare plusieurs : un garde-fou qui ne voit pas une classe la
 * laisse passer. Ici, chaque déclaration est lue par jetons (PhpToken) ; si
 * l'autoload ne la trouve pas, le fichier est chargé directement, et une
 * déclaration qui reste introuvable fait échouer le recensement plutôt que de
 * disparaître.
 *
 * **Deux recensements, que chaque garde-fou choisit explicitement** (issue
 * #421) :
 *  - {@see self::classes()}, les classes seules (abstraites comprises), pour
 *    qui raisonne sur ce qui s'instancie ou se lève : les exceptions
 *    problème et les exceptions de quota de src/
 *    ({@see \App\Tests\Shared\Infrastructure\Http\ExceptionLogLevelCoverageTest},
 *    {@see \App\Tests\Shared\Infrastructure\Http\ProblemDetailStaysStaticTest},
 *    {@see \App\Tests\Shared\Infrastructure\Http\ProblemStatusMatchesExceptionToStatusTest},
 *    {@see \App\Tests\Shared\Domain\Exception\QuotaExceptionsContractTest},
 *    {@see \App\Tests\Security\Authentication\Infrastructure\Log\ThrottledRequestAuditCoverageTest}) ;
 *  - {@see self::classLikes()}, toutes les déclarations nommées — classes,
 *    interfaces, traits, enums —, pour qui lit des signatures, des attributs
 *    ou des sources ({@see \App\Tests\Security\SecretParametersTest},
 *    {@see \App\Tests\Security\User\PlainPasswordInputsTest},
 *    {@see \App\Tests\Shared\RegexEndAnchorTest}). Un enum, une interface
 *    sans implémentation ou un trait sans utilisateur portent des membres que
 *    la réflexion n'atteint par aucune classe ; un trait utilisé, lui, se lit
 *    dans sa classe ({@see self::traitsUsedBy()}).
 * Étendre {@see self::classes()} en silence aurait changé le périmètre de
 * gardes qui supposent une classe : `is_subclass_of()` est vrai d'une
 * interface qui étend le type, et un garde-fou qui lève ou instancie ce qu'il
 * recense aurait reçu une interface.
 *
 * Ni l'un ni l'autre ne voit les classes anonymes, les closures, les
 * fonctions fléchées ni les fonctions hors classe : aucune n'a de nom que la
 * réflexion puisse charger sans exécuter le code.
 */
final class DeclaredClasses
{
    /**
     * Les jetons qui ouvrent une déclaration nommée. `class` ne suffit pas :
     * précédé de `new`, il ouvre une classe anonyme, de `::`, il lit un nom.
     */
    private const array CLASS_LIKE = [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM];

    /**
     * Les classes du répertoire qui implémentent ou étendent un type donné.
     * Des classes seules : une interface qui étend le type n'en est pas une.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return list<class-string<T>> triées, pour un diagnostic stable
     */
    public static function implementing(string $directory, string $type): array
    {
        return array_values(array_filter(self::classes($directory), static fn (string $class): bool => is_subclass_of($class, $type)));
    }

    /**
     * Les classes nommées que déclarent les fichiers du répertoire, abstraites
     * comprises ; ni interface, ni trait, ni enum.
     *
     * @return list<class-string> triées, pour un diagnostic stable
     */
    public static function classes(string $directory): array
    {
        return array_values(array_filter(self::classLikes($directory), static fn (string $name): bool => class_exists($name, false) && !enum_exists($name, false)));
    }

    /**
     * Toutes les déclarations nommées des fichiers du répertoire : classes,
     * interfaces, traits et enums.
     *
     * @return list<class-string> triées, pour un diagnostic stable
     */
    public static function classLikes(string $directory): array
    {
        $found = [];
        foreach (PhpSources::files($directory) as $path) {
            array_push($found, ...self::declaredIn($path));
        }
        sort($found);

        return $found;
    }

    /**
     * Les traits qu'utilisent ces déclarations, directement ou par un autre
     * trait. La réflexion montre leurs méthodes et leurs propriétés dans la
     * classe qui les utilise, comme si elle les déclarait : un garde-fou qui
     * lit aussi le trait pour lui-même verrait chaque membre deux fois
     * (issue #421).
     *
     * @param list<class-string> $classes
     *
     * @return list<class-string>
     */
    public static function traitsUsedBy(array $classes): array
    {
        $used = [];
        $pending = array_map(static fn (string $class): ReflectionClass => new ReflectionClass($class), $classes);
        while ([] !== $pending) {
            foreach (array_pop($pending)->getTraits() as $trait) {
                if (!\in_array($trait->getName(), $used, true)) {
                    $used[] = $trait->getName();
                    $pending[] = $trait;
                }
            }
        }

        return $used;
    }

    /**
     * @return list<class-string> déclarations du fichier, chargées
     */
    private static function declaredIn(string $path): array
    {
        $loaded = [];
        foreach (self::declaredNames((string) file_get_contents($path)) as $name) {
            if (!self::isDeclared($name)) {
                // Hors PSR-4 : l'autoload ne sait pas où la trouver.
                require_once $path;
            }
            if (!self::isDeclared($name)) {
                throw new LogicException(\sprintf('%s déclare %s, introuvable même après chargement.', $path, $name));
            }
            $loaded[] = $name;
        }

        return $loaded;
    }

    /**
     * Déclarée, au besoin par l'autoload, qu'elle déclenche. `class_exists()`
     * couvre les enums ; interfaces et traits ont chacun leur fonction.
     *
     * @phpstan-assert-if-true class-string $name
     */
    private static function isDeclared(string $name): bool
    {
        return class_exists($name) || interface_exists($name) || trait_exists($name);
    }

    /**
     * @return list<string> FQCN des déclarations nommées (ni classe anonyme, ni `X::class`)
     */
    private static function declaredNames(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);

        $namespace = '';
        $names = [];
        foreach ($tokens as $index => $token) {
            if ($token->is(\T_NAMESPACE)) {
                $namespace = $tokens[$index + 1]->text ?? '';
            } elseif ($token->is(self::CLASS_LIKE)
                && (!isset($tokens[$index - 1]) || !$tokens[$index - 1]->is([\T_NEW, \T_DOUBLE_COLON]))
                && isset($tokens[$index + 1]) && $tokens[$index + 1]->is(\T_STRING)) {
                $names[] = ltrim($namespace.'\\'.$tokens[$index + 1]->text, '\\');
            }
        }

        return $names;
    }
}
