<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use App\Tests\Support\PhpSources;
use App\Tests\Support\RepositoryFiles;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Toute classe de `src/` qui reçoit `APP_SECRET` en dérive sa propre clé
 * (issue #455) : chacune de ses dérivations — `KeyedMac::fromSecret($secret,
 * self::KEY_CONTEXT, …)` ou `SecretKeyDerivation::derive($secret,
 * self::KEY_CONTEXT)` — prend exactement `self::KEY_CONTEXT` en deuxième
 * argument, et elle déclare un `KEY_CONTEXT` au format de
 * {@see SecretKeyDerivation::CONTEXT_PATTERN} que nulle autre n'emploie. Le secret
 * brut ne signe plus rien dans `src/`, et deux usages ne partagent jamais une
 * clé.
 *
 * Et elle ne calcule ni ne compare de MAC elle-même (issue #487) : aucune
 * fonction de {@see self::MAC_FUNCTIONS}, appelée, importée ou nommée en
 * chaîne, que {@see \App\Shared\Infrastructure\Security\KeyedMac} remplace.
 * Une telle classe n'a pas d'autre clé que celle qu'elle dérive du secret : un
 * MAC écrit chez elle en est un sous clé dérivée.
 *
 * Recensement par jetons, jamais un commentaire : une chaîne littérale
 * (guillemets simples ou doubles) de {@see self::SECRET_REFERENCES}, ou un
 * appel de dérivation — une classe qui dérive une clé d'un secret reçu d'une
 * autre est un usage comme les autres (relecture de #487). La configuration
 * (`config/**.yaml`) ne nomme le secret qu'à un endroit, `framework.secret`.
 *
 * Ne voit pas, limites acceptées : une classe qui transmettrait le secret brut
 * à une autre, qui l'emploierait sans le dériver ; une écriture obfusquée
 * (`getenv('APP_'.'SECRET')`) ; un MAC comparé à `===` après un `sign()` public
 * plutôt que par `verifies()` (aucune heuristique sur `sign` n'y distinguerait un appel
 * légitime) ; les usages de Symfony lui-même (`login_throttling`,
 * `SigningSerializer`…), qui gardent le secret brut — aucun ne rend sa sortie
 * au client, et HKDF ne l'emploie que comme message, jamais comme clé.
 */
final class ApplicationSecretUsesTest extends TestCase
{
    /**
     * Les noms du secret : paramètre et variable d'environnement, sous leurs
     * formes `%…%`, `#[Autowire(param:|env:)]` et d'expression language
     * (`#[Autowire(expression: …)]`, relecture de #487).
     */
    private const array SECRET_REFERENCES = [
        '%kernel.secret%', 'kernel.secret', 'APP_SECRET', '%env(APP_SECRET)%',
        "parameter('kernel.secret')", 'parameter("kernel.secret")', "env('APP_SECRET')", 'env("APP_SECRET")',
    ];

    /**
     * Les appels qui dérivent une clé du secret, et leur nombre d'arguments :
     * `KeyedMac::fromSecret()` pour un MAC, `SecretKeyDerivation::derive()`
     * seule pour un usage qui ne serait pas un MAC. Le contexte est toujours le deuxième.
     */
    private const array DERIVATIONS = [
        'KeyedMac::fromSecret' => 3,
        'SecretKeyDerivation::derive' => 2,
    ];

    /**
     * L'abstraction elle-même : elle reçoit le contexte en paramètre, c'est
     * l'usage qui le fixe.
     */
    private const string KEYED_MAC_FILE = '/src/Shared/Infrastructure/Security/KeyedMac.php';

    /**
     * Ce que `KeyedMac::sign()` et `verifies()` écrivent une fois (issue #487),
     * et les autres façons d'en
     * calculer ou d'en comparer un : HMAC d'un fichier, HMAC incrémental,
     * authentificateur de libsodium.
     */
    private const array MAC_FUNCTIONS = ['hash_hmac', 'hash_equals', 'hash_hmac_file', 'hash_init', 'sodium_crypto_auth', 'sodium_crypto_auth_verify'];

    /** La seule ligne de configuration autorisée à nommer le secret. */
    private const string FRAMEWORK_SECRET_LINE = "secret: '%env(APP_SECRET)%'";

    public function testEveryUseOfTheApplicationSecretDerivesItsOwnKey(): void
    {
        $uses = [];
        foreach (PhpSources::files(RepositoryFiles::backend('src')) as $file) {
            $code = RepositoryFiles::read($file);
            if (!str_ends_with($file, self::KEYED_MAC_FILE) && $this->isAUse($code)) {
                $uses[$file] = $code;
            }
        }
        self::assertNotSame([], $uses, 'Aucun usage du secret trouvé : la garde ne garderait rien.');

        $contexts = [];
        foreach ($uses as $file => $code) {
            self::assertTrue($this->derivesWithItsOwnContext($code), \sprintf('%s reçoit APP_SECRET ou en dérive une clé : chaque KeyedMac::fromSecret($secret, self::KEY_CONTEXT, $encoding) ou SecretKeyDerivation::derive($secret, self::KEY_CONTEXT) prend exactement self::KEY_CONTEXT en deuxième argument (#455, #487).', $file));
            self::assertFalse($this->holdsTheSecretAsAString($code), \sprintf('%s garde le secret dans une propriété string, que tout dump du service affiche : SensitiveParameterValue (#493).', $file));
            self::assertFalse($this->computesAMacItself($code), \sprintf('%s reçoit APP_SECRET et calcule ou compare un MAC (%s) : passer par KeyedMac (#487).', $file, implode(', ', self::MAC_FUNCTIONS)));
            self::assertSame(1, preg_match_all("/const string KEY_CONTEXT = '([^']+)'/", $code, $matches), \sprintf('%s doit déclarer un KEY_CONTEXT, et un seul.', $file));
            self::assertMatchesRegularExpression(SecretKeyDerivation::CONTEXT_PATTERN, $matches[1][0], \sprintf('%s : KEY_CONTEXT hors du format cp-ghostotof/<usage>/v<n> (#487).', $file));
            $contexts[$matches[1][0]][] = $file;
        }

        foreach ($contexts as $context => $files) {
            self::assertCount(1, $files, \sprintf('Contexte de clé « %s » partagé par %s.', $context, implode(', ', $files)));
        }
    }

    public function testTheConfigurationNamesTheSecretOnlyForTheFramework(): void
    {
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RepositoryFiles::backend('config')));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || 'yaml' !== $file->getExtension()) {
                continue;
            }
            $lines = file($file->getPathname());
            self::assertIsArray($lines);
            foreach ($lines as $index => $line) {
                $content = trim(explode('#', $line, 2)[0]);
                if (self::FRAMEWORK_SECRET_LINE !== $content && 1 === preg_match('/kernel\.secret|APP_SECRET/', $content)) {
                    $offenders[] = \sprintf('%s:%d', $file->getPathname(), $index + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'Secret applicatif injecté par la configuration : passer par une classe qui en dérive sa clé (#455).');
    }

    /**
     * Les formes que la première version de la garde laissait passer
     * (seconde passe d'audit de #455).
     */
    public function testTheCensusSeesEveryFormOfInjectionAndOnlyARealDerivation(): void
    {
        self::assertTrue($this->referencesTheSecret('<?php #[Autowire("%kernel.secret%")] $a;'));
        self::assertTrue($this->referencesTheSecret("<?php #[Autowire(env: 'APP_SECRET')] \$a;"));
        self::assertTrue($this->referencesTheSecret("<?php \$x = '%env(APP_SECRET)%';"));
        self::assertFalse($this->referencesTheSecret("<?php // #[Autowire('%kernel.secret%')]"));

        self::assertTrue($this->derivesWithItsOwnContext('<?php SecretKeyDerivation::derive($this->secret, self::KEY_CONTEXT);'));
        self::assertFalse($this->derivesWithItsOwnContext('<?php // SecretKeyDerivation::derive($this->secret, self::KEY_CONTEXT)'));
        self::assertFalse($this->derivesWithItsOwnContext("<?php SecretKeyDerivation::derive(\$this->secret, 'cp-ghostotof/csrf-cookie/v1');"));
        self::assertTrue($this->derivesWithItsOwnContext('<?php KeyedMac::fromSecret($this->secret, self::KEY_CONTEXT, MacEncoding::Hex);'));
        self::assertFalse($this->derivesWithItsOwnContext("<?php KeyedMac::fromSecret(\$this->secret, 'cp-ghostotof/csrf-cookie/v1', MacEncoding::Hex);"));
        // Le contexte d'une autre dérivation, plus loin, ne vaut pas pour celle-ci.
        self::assertFalse($this->derivesWithItsOwnContext("<?php KeyedMac::fromSecret(\$s, 'x', MacEncoding::Hex); f(self::KEY_CONTEXT);"));
    }

    /**
     * Relecture de #487 : le contexte doit être **le** deuxième argument de
     * **chaque** dérivation du fichier, rien d'autre.
     */
    public function testTheContextMustBeTheSecondArgumentOfEveryDerivation(): void
    {
        self::assertTrue($this->derivesWithItsOwnContext('<?php KeyedMac::fromSecret($this->secret, self::KEY_CONTEXT, MacEncoding::Hex,);'));
        // Arguments inversés : la clé serait HKDF d'une constante publique.
        self::assertFalse($this->derivesWithItsOwnContext('<?php KeyedMac::fromSecret(self::KEY_CONTEXT, $this->secret, MacEncoding::Hex);'));
        self::assertFalse($this->derivesWithItsOwnContext('<?php SecretKeyDerivation::derive(self::KEY_CONTEXT, $this->secret);'));
        // Un argument de trop, que PHP accepte en silence.
        self::assertFalse($this->derivesWithItsOwnContext("<?php KeyedMac::fromSecret(\$s, 'cp-ghostotof/csrf-cookie/v1', MacEncoding::Hex, self::KEY_CONTEXT);"));
        // Le contexte collé au secret, ou complété.
        self::assertFalse($this->derivesWithItsOwnContext("<?php KeyedMac::fromSecret(\$s.self::KEY_CONTEXT, 'cp-ghostotof/csrf-cookie/v1', MacEncoding::Hex);"));
        self::assertFalse($this->derivesWithItsOwnContext("<?php KeyedMac::fromSecret(\$s, self::KEY_CONTEXT.'x', MacEncoding::Hex);"));
        // Une seconde dérivation sous le contexte d'un autre usage.
        self::assertFalse($this->derivesWithItsOwnContext("<?php KeyedMac::fromSecret(\$s, self::KEY_CONTEXT, MacEncoding::Hex); KeyedMac::fromSecret(\$s, 'cp-ghostotof/csrf-cookie/v1', MacEncoding::Hex);"));
    }

    /**
     * Relecture de #487 : un fichier qui dérive une clé est un usage, même
     * sans nommer le secret (il le reçoit d'une autre classe), et
     * l'expression language d'`#[Autowire]` le nomme aussi.
     */
    public function testTheCensusSeesADerivationWithoutTheSecretAndAnExpression(): void
    {
        self::assertTrue($this->isAUse("<?php #[Autowire(expression: \"parameter('kernel.secret')\")] \$a;"));
        self::assertTrue($this->isAUse("<?php #[Autowire(expression: 'env(\"APP_SECRET\")')] \$a;"));
        self::assertTrue($this->isAUse('<?php KeyedMac::fromSecret($fromElsewhere, self::KEY_CONTEXT, MacEncoding::Hex);'));
        self::assertTrue($this->isAUse('<?php SecretKeyDerivation::derive($fromElsewhere, self::KEY_CONTEXT);'));
        self::assertFalse($this->isAUse("<?php \$m = 'kernel.secret (APP_SECRET) est vide.';"));
        self::assertFalse($this->isAUse('<?php // KeyedMac::fromSecret($s, self::KEY_CONTEXT, MacEncoding::Hex);'));
    }

    /**
     * Issue #493 : une propriété `string` qui porte le secret l'affiche dans
     * tout dump du service ; un paramètre, lui, n'est pas gardé.
     */
    public function testTheCensusSeesTheSecretHeldAsAString(): void
    {
        self::assertTrue($this->holdsTheSecretAsAString('<?php final class A { public function __construct(private string $secret) {} }'));
        self::assertTrue($this->holdsTheSecretAsAString('<?php final class A { public function __construct(private readonly string $secret) {} }'));
        self::assertTrue($this->holdsTheSecretAsAString('<?php final class A { readonly private ?string $appSecret; }'));
        self::assertTrue($this->holdsTheSecretAsAString('<?php final class A { public string $SECRET = ""; }'));
        self::assertFalse($this->holdsTheSecretAsAString('<?php final class A { public function __construct(string $secret) {} }'));
        self::assertFalse($this->holdsTheSecretAsAString('<?php final class A { private SensitiveParameterValue $secret; }'));
        self::assertFalse($this->holdsTheSecretAsAString('<?php final class A { private string $context; }'));
        self::assertFalse($this->holdsTheSecretAsAString('<?php // private string $secret;'));
    }

    /**
     * Les formes d'appel de `hash_hmac()` / `hash_equals()` que la garde de
     * #487 voit, et celles qui n'en sont pas.
     */
    public function testTheCensusSeesAMacComputedOutsideKeyedMac(): void
    {
        self::assertTrue($this->computesAMacItself("<?php hash_hmac('sha256', \$a, \$k);"));
        self::assertTrue($this->computesAMacItself("<?php \\hash_hmac('sha256', \$a, \$k);"));
        self::assertTrue($this->computesAMacItself('<?php return hash_equals($a, $b);'));
        self::assertFalse($this->computesAMacItself("<?php // hash_hmac('sha256', \$a, \$k);"));
        self::assertFalse($this->computesAMacItself('<?php $mac->verifies($a, $b); $x = "hash_equals est en temps constant";'));
        self::assertFalse($this->computesAMacItself('<?php $this->hash_equals($a, $b);'));
        self::assertFalse($this->computesAMacItself('<?php Foo::hash_equals($a, $b);'));
        self::assertFalse($this->computesAMacItself('<?php class A { public function hash_equals(): void {} }'));
        // Relecture de #487 : les formes détournées.
        self::assertTrue($this->computesAMacItself('<?php use function hash_hmac as h; h($a, $b, $c);'));
        self::assertTrue($this->computesAMacItself("<?php call_user_func('hash_equals', \$a, \$b);"));
        self::assertTrue($this->computesAMacItself('<?php $f = hash_equals(...);'));
        self::assertTrue($this->computesAMacItself("<?php \$c = hash_init('sha256', HASH_HMAC, \$k);"));
        self::assertTrue($this->computesAMacItself("<?php hash_hmac_file('sha256', \$path, \$k);"));
        self::assertTrue($this->computesAMacItself('<?php sodium_crypto_auth($m, $k);'));
        self::assertTrue($this->computesAMacItself('<?php sodium_crypto_auth_verify($mac, $m, $k);'));
    }

    private function referencesTheSecret(string $code): bool
    {
        return array_any(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => $token->is(\T_CONSTANT_ENCAPSED_STRING) && \in_array(trim($token->text, '\'"'), self::SECRET_REFERENCES, true),
        );
    }

    /**
     * Un usage du secret : un fichier qui le nomme, ou qui en dérive une clé.
     */
    private function isAUse(string $code): bool
    {
        return $this->referencesTheSecret($code) || [] !== $this->derivations($code);
    }

    /**
     * Au moins une dérivation, et chacune avec le nombre d'arguments de
     * {@see self::DERIVATIONS} et `self::KEY_CONTEXT` seul en deuxième, lus par
     * jetons significatifs : un commentaire n'est pas un appel, un contexte
     * littéral pourrait être celui d'une autre classe, des arguments inversés
     * feraient du secret le contexte.
     */
    private function derivesWithItsOwnContext(string $code): bool
    {
        $derivations = $this->derivations($code);

        return [] !== $derivations && array_all(
            $derivations,
            static fn (array $derivation): bool => \count($derivation['arguments']) === self::DERIVATIONS[$derivation['call']]
                && ['self', '::', 'KEY_CONTEXT'] === $derivation['arguments'][1],
        );
    }

    /**
     * Les appels de {@see self::DERIVATIONS} du code, chacun avec ses arguments
     * de premier niveau (une virgule finale ne fait pas d'argument).
     *
     * @return list<array{call: key-of<self::DERIVATIONS>, arguments: list<list<string>>}>
     */
    private function derivations(string $code): array
    {
        $tokens = array_map(static fn (PhpToken $token): string => $token->text, PhpSources::significantTokens($code));
        $derivations = [];
        foreach (array_keys($tokens) as $i) {
            $call = $tokens[$i].'::'.($tokens[$i + 2] ?? '');
            if ('::' === ($tokens[$i + 1] ?? null) && '(' === ($tokens[$i + 3] ?? null) && \array_key_exists($call, self::DERIVATIONS)) {
                $derivations[] = ['call' => $call, 'arguments' => $this->arguments($tokens, $i + 3)];
            }
        }

        return $derivations;
    }

    /**
     * Les arguments de premier niveau de l'appel dont la parenthèse ouvrante
     * est à `$open`.
     *
     * @param list<string> $tokens
     *
     * @return list<list<string>>
     */
    private function arguments(array $tokens, int $open): array
    {
        $arguments = [[]];
        $depth = 0;
        for ($j = $open, $count = \count($tokens); $j < $count; ++$j) {
            $depth += match ($tokens[$j]) {
                '(', '[', '{' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };
            if (0 === $depth) {
                break;
            }
            if (1 === $depth && ',' === $tokens[$j]) {
                $arguments[] = [];
            } elseif ($j > $open) {
                $arguments[array_key_last($arguments)][] = $tokens[$j];
            }
        }

        return array_values(array_filter($arguments, static fn (array $argument): bool => [] !== $argument));
    }

    /**
     * Une propriété — déclarée ou promue — typée `string` ou `?string` dont le
     * nom dit « secret », lue par jetons significatifs : un paramètre sans
     * modificateur n'est pas gardé, une propriété d'un autre type non plus.
     */
    private function holdsTheSecretAsAString(string $code): bool
    {
        $tokens = PhpSources::significantTokens($code);

        return array_any(array_keys($tokens), static function (int $i) use ($tokens): bool {
            if (!$tokens[$i]->is(\T_VARIABLE) || 1 !== preg_match('/secret/i', $tokens[$i]->text) || 'string' !== ($tokens[$i - 1]->text ?? null)) {
                return false;
            }
            $modifier = '?' === ($tokens[$i - 2]->text ?? null) ? $i - 3 : $i - 2;

            return \in_array(strtolower($tokens[$modifier]->text ?? ''), ['private', 'protected', 'public', 'readonly', 'var'], true);
        });
    }

    /**
     * Une fonction de {@see self::MAC_FUNCTIONS} appelée, importée
     * (`use function`), prise en callable de première classe ou nommée dans
     * une chaîne (`call_user_func('hash_equals', …)`), qualifiée ou non, lue
     * par jetons significatifs : ni un commentaire, ni une phrase qui la cite,
     * ni une méthode ou une constante du même nom, ni sa déclaration.
     */
    private function computesAMacItself(string $code): bool
    {
        $tokens = PhpSources::significantTokens($code);

        return array_any(array_keys($tokens), fn (int $i): bool => $this->namesAMacFunction($tokens, $i));
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private function namesAMacFunction(array $tokens, int $i): bool
    {
        $token = $tokens[$i];
        $name = $token->is(\T_CONSTANT_ENCAPSED_STRING) ? trim($token->text, '\'"') : ltrim($token->text, '\\');
        if (!\in_array($name, self::MAC_FUNCTIONS, true)) {
            return false;
        }

        $before = $tokens[$i - 1]->text ?? null;
        if (\in_array($before, ['->', '?->', '::'], true)) {
            return false;
        }

        return 'function' !== $before || 'use' === ($tokens[$i - 2]->text ?? null);
    }
}
