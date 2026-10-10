<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * Recense les appels de fonction mal qualifiés d'un répertoire (issue #394).
 * Une fonction que le compilateur optimise s'écrit `\count(…)`, toute autre
 * `array_map(…)`, sans `\`. C'est ce que fait php-cs-fixer
 * `native_function_invocation` avec `include: ['@compiler_optimized']` et
 * `strict: true`.
 *
 * Un appel est un nom suivi de `(`. Ne sont donc pas des appels :
 * - une méthode (`->count(`, `?->count(`, `::count(`) ;
 * - une déclaration (`function count(`) ;
 * - une instanciation (`new Count(`) ;
 * - la classe d'un attribut (`#[Count(1)]`) ;
 * - un callable de première classe (`is_string(...)`), qui reste tel qu'écrit.
 *
 * Seul un nom d'un seul segment est concerné : `Foo\count(`, avec ou sans `\`
 * initial, désigne la fonction d'un espace de noms, et non une fonction globale.
 */
final class MisqualifiedNativeCalls
{
    /**
     * L'ensemble `@compiler_optimized` de php-cs-fixer v3.95.27
     * (`NativeFunctionInvocationFixer::getAllCompilerOptimizedFunctionsNormalized()`),
     * recopié parce que php-cs-fixer n'est pas une dépendance. Les fonctions
     * viennent de `zend_try_compile_special_func()` (`Zend/zend_compile.c`), puis
     * de l'optimiseur d'OPcache (`Zend/Optimizer/`). La liste suit PHP :
     * `sprintf` y est entré avec PHP 8.4, qui le compile en concaténation.
     * Après une montée de php-cs-fixer ou de PHP, recopier la nouvelle liste.
     *
     * @var list<string>
     */
    public const array COMPILER_OPTIMIZED = [
        'array_key_exists', 'array_slice', 'assert', 'boolval', 'call_user_func', 'call_user_func_array',
        'chr', 'count', 'defined', 'doubleval', 'floatval', 'func_get_args', 'func_num_args',
        'get_called_class', 'get_class', 'gettype', 'in_array', 'intval', 'is_array', 'is_bool',
        'is_double', 'is_float', 'is_int', 'is_integer', 'is_long', 'is_null', 'is_object', 'is_real',
        'is_resource', 'is_scalar', 'is_string', 'ord', 'sizeof', 'sprintf', 'strlen', 'strval',
        'constant', 'define', 'dirname', 'extension_loaded', 'function_exists', 'is_callable', 'ini_get',
    ];

    /**
     * Les noms de la liste qui ne sont plus des fonctions du PHP du projet.
     * php-cs-fixer les garde pour les versions plus anciennes. `is_real` a été
     * retiré en PHP 8.0.
     *
     * @var list<string>
     */
    public const array ABSENT_FROM_PHP = ['is_real'];

    public const string FIX = "Fonctions natives mal qualifiées. Correction ponctuelle, depuis la racine du dépôt :\n"
        ."docker compose exec -u dev backend sh -c '".PhpSources::CS_FIXER_DOWNLOAD
        .' && for d in src tests; do php var/php-cs-fixer.phar fix --using-cache=no --allow-risky=yes'
        .' --rules=\'"\'"\'{"native_function_invocation":{"include":["@compiler_optimized"],"scope":"all","strict":true}}\'"\'"\' $d; done\'';

    /**
     * @return list<string> `chemin:ligne problème` pour chaque appel fautif, triés pour un diagnostic stable
     */
    public static function in(string ...$directories): array
    {
        $found = [];
        foreach ($directories as $directory) {
            foreach (PhpSources::files($directory) as $path) {
                foreach (self::inCode((string) file_get_contents($path)) as $problem) {
                    $found[] = $path.':'.$problem;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string> `ligne problème` pour chaque appel fautif, dans l'ordre du code
     */
    public static function inCode(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);
        $classNames = self::attributeClassNames($tokens);
        $problems = [];
        foreach ($tokens as $index => $token) {
            if (!self::isCall($tokens, $index) || isset($classNames[$index])) {
                continue;
            }

            if ($token->is(\T_STRING) && self::isOptimized($token->text)) {
                $problems[] = $token->line.' '.$token->text.'() doit s\'écrire \\'.$token->text.'()';
            } elseif ($token->is(\T_NAME_FULLY_QUALIFIED) && 1 === substr_count($token->text, '\\')
                && !self::isOptimized(substr($token->text, 1))) {
                $problems[] = $token->line.' '.$token->text.'() doit s\'écrire '.substr($token->text, 1).'()';
            }
        }

        return $problems;
    }

    /**
     * Un nom suivi de `(`, qui n'est ni une méthode, ni une déclaration, ni une
     * instanciation, ni un callable de première classe.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isCall(array $tokens, int $index): bool
    {
        if ('(' !== ($tokens[$index + 1] ?? null)?->text) {
            return false;
        }
        if (true === ($tokens[$index + 2] ?? null)?->is(\T_ELLIPSIS) && ')' === ($tokens[$index + 3] ?? null)?->text) {
            return false;
        }

        $previous = $tokens[$index - 1] ?? null;
        if ('&' === $previous?->text) {
            // `function &count(` : la déclaration d'une fonction qui renvoie une référence.
            $previous = $tokens[$index - 2] ?? null;
        }

        return null === $previous
            || !$previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW]);
    }

    /**
     * Les index des noms de classe d'attribut : le premier nom après `#[`, et
     * chaque nom après une virgule du même groupe. Une virgule des arguments
     * (`#[Foo(1, 2)]`) est à une profondeur de parenthèses différente.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array<int, true>
     */
    private static function attributeClassNames(array $tokens): array
    {
        $names = [];
        $brackets = 0;
        $parentheses = 0;
        /** @var list<array{int, int}> $groups profondeurs de crochets et de parenthèses à l'ouverture de chaque `#[` */
        $groups = [];
        foreach ($tokens as $index => $token) {
            $group = $groups[array_key_last($groups) ?? -1] ?? null;
            if ($token->is(\T_ATTRIBUTE)) {
                $groups[] = [++$brackets, $parentheses];
                $names[$index + 1] = true;
            } elseif ('[' === $token->text) {
                ++$brackets;
            } elseif (']' === $token->text) {
                if (null !== $group && $group[0] === $brackets) {
                    array_pop($groups);
                }
                --$brackets;
            } elseif ('(' === $token->text) {
                ++$parentheses;
            } elseif (')' === $token->text) {
                --$parentheses;
            } elseif (',' === $token->text && [$brackets, $parentheses] === $group) {
                $names[$index + 1] = true;
            }
        }

        return $names;
    }

    private static function isOptimized(string $function): bool
    {
        // Les noms de fonction ne tiennent pas compte de la casse, la liste est en minuscules.
        return \in_array(strtolower($function), self::COMPILER_OPTIMIZED, true);
    }
}
