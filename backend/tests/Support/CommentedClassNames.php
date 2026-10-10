<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Composer\Autoload\ClassLoader;
use PhpToken;

/**
 * Recense, dans les commentaires d'un répertoire, les noms de classe écrits en
 * entier qui ne désignent rien (issue #391).
 *
 * Une classe que le code d'un fichier n'importe pas est citée en commentaire par
 * son nom complet, sans `use` : un import qui ne servirait qu'à la
 * documentation n'est vérifié par aucun outil, et une classe supprimée le
 * laisserait pointer dans le vide. Ce recensement prend le relais : chaque nom
 * complet cité doit exister, et une méthode citée (`Foo::bar()`) aussi.
 *
 * Un nom est complet quand il commence par `\` (`\ValueError`) ou par un espace
 * de noms racine que l'autoloader connaît (`App\…`, `Symfony\…`). Un espace de
 * noms cité (`App\Shared`) est accepté s'il correspond à un dossier. Ne sont pas
 * vérifiés, faute de pouvoir les résoudre : un nom relatif au contexte
 * (`Infrastructure\Doctrine\CpgUserRepository`), le raccourci `Assert\…`, une
 * constante (`\PHP_EOL`) ou une fonction (`\sprintf`).
 */
final class CommentedClassNames
{
    /**
     * Un `\` suivi d'une majuscule (ni `\n` ni `\sprintf`), ou plusieurs segments
     * séparés par `\`, suivis peut-être de `::methode(`.
     */
    private const string NAME = '/(?<![\w\\\\])(\\\\[A-Z]\w*(?:\\\\\w+)*|[A-Za-z_]\w*(?:\\\\\w+)+)(?:::(\w+)\()?/';

    /** @var array<string, true>|null premiers segments des espaces de noms connus de l'autoloader */
    private static ?array $roots = null;

    /** @var array<string, list<string>>|null préfixes PSR-4 => dossiers */
    private static ?array $prefixes = null;

    /**
     * @return list<string> `chemin:ligne nom` de chaque nom complet introuvable, triés pour un diagnostic stable
     */
    public static function in(string ...$directories): array
    {
        $found = [];
        foreach ($directories as $directory) {
            foreach (PhpSources::files($directory) as $path) {
                foreach (self::inCode((string) file_get_contents($path)) as $missing) {
                    $found[] = $path.':'.$missing;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string> `ligne nom` de chaque nom complet introuvable, dans l'ordre du code
     */
    public static function inCode(string $code): array
    {
        $missing = [];
        foreach (PhpToken::tokenize($code) as $token) {
            if (!$token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }
            preg_match_all(self::NAME, $token->text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                [$name, $offset] = $match[1];
                $method = isset($match[2]) ? $match[2][0] : null;
                $problem = self::problem($name, $method);
                if (null !== $problem) {
                    $line = $token->line + substr_count($token->text, "\n", 0, $offset);
                    $missing[] = $line.' '.$problem;
                }
            }
        }

        return $missing;
    }

    /**
     * Le nom tel qu'il faut le signaler, ou null s'il n'y a rien à signaler.
     */
    private static function problem(string $name, ?string $method): ?string
    {
        $fullyQualified = str_starts_with($name, '\\');
        $class = ltrim($name, '\\');
        if (!$fullyQualified && !isset(self::roots()[strstr($class, '\\', true)])) {
            return null;
        }

        if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
            if (null === $method || method_exists($class, $method)) {
                return null;
            }

            return $name.'::'.$method.'()';
        }

        if (\defined($class) || self::isNamespace($class)) {
            return null;
        }

        return $name;
    }

    /**
     * Un espace de noms se reconnaît à son dossier, résolu par les préfixes
     * PSR-4, ou à un préfixe qu'il contient (`Symfony\AI` pour `Symfony\AI\Platform\`).
     */
    private static function isNamespace(string $name): bool
    {
        $namespace = $name.'\\';
        foreach (self::prefixes() as $prefix => $directories) {
            if (str_starts_with($prefix, $namespace)) {
                return true;
            }
            if (!str_starts_with($namespace, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', rtrim(substr($namespace, \strlen($prefix)), '\\'));
            foreach ($directories as $directory) {
                if (is_dir(rtrim($directory.'/'.$relative, '/'))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private static function roots(): array
    {
        if (null === self::$roots) {
            self::$roots = [];
            foreach (self::loaders() as $loader) {
                foreach ([...array_keys($loader->getPrefixesPsr4()), ...array_keys($loader->getClassMap())] as $name) {
                    if (str_contains($name, '\\')) {
                        self::$roots[(string) strstr($name, '\\', true)] = true;
                    }
                }
            }
        }

        return self::$roots;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function prefixes(): array
    {
        if (null === self::$prefixes) {
            self::$prefixes = [];
            foreach (self::loaders() as $loader) {
                foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
                    self::$prefixes[$prefix] = [...self::$prefixes[$prefix] ?? [], ...$directories];
                }
            }
        }

        return self::$prefixes;
    }

    /**
     * @return list<ClassLoader>
     */
    private static function loaders(): array
    {
        return array_values(ClassLoader::getRegisteredLoaders());
    }
}
