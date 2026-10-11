<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Tests\Support\PhpSources;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Toute classe de `src/` qui reçoit `APP_SECRET` en dérive sa propre clé
 * (issue #455) : elle appelle `KeyedMac::fromSecret(…, self::KEY_CONTEXT, …)`
 * ou `SecretKeyDerivation::derive(…, self::KEY_CONTEXT)` et déclare un
 * `KEY_CONTEXT` que nulle autre n'emploie. Le secret brut ne signe plus rien
 * dans `src/`, et deux usages ne partagent jamais une clé.
 *
 * Et elle ne calcule ni ne compare de MAC elle-même (issue #487) : ni
 * `hash_hmac()` ni `hash_equals()`, que KeyedMac écrit une fois. Une telle
 * classe n'a pas d'autre clé que celle qu'elle dérive du secret : un HMAC
 * écrit chez elle en est un sous clé dérivée.
 *
 * Recensement par jetons : une chaîne littérale (guillemets simples ou
 * doubles) `%kernel.secret%`, `kernel.secret`, `APP_SECRET` ou
 * `%env(APP_SECRET)%`, jamais un commentaire qui la cite. La configuration
 * (`config/**.yaml`) ne nomme le secret qu'à un endroit, `framework.secret`.
 *
 * Ne voit pas, limite acceptée : une classe qui transmettrait le secret brut à
 * une autre, et les usages de Symfony lui-même (`login_throttling`,
 * `SigningSerializer`…), qui gardent le secret brut — aucun ne rend sa sortie
 * au client, et HKDF ne l'emploie que comme message, jamais comme clé.
 */
final class ApplicationSecretUsesTest extends TestCase
{
    private const array SECRET_REFERENCES = ['%kernel.secret%', 'kernel.secret', 'APP_SECRET', '%env(APP_SECRET)%'];

    /**
     * Les appels qui dérivent une clé du secret : KeyedMac pour un MAC, et la
     * dérivation seule pour un usage qui ne serait pas un MAC.
     */
    private const array DERIVATIONS = [
        ['KeyedMac', '::', 'fromSecret', '('],
        ['SecretKeyDerivation', '::', 'derive', '('],
    ];

    /** Ce que KeyedMac écrit une fois (issue #487). */
    private const array MAC_FUNCTIONS = ['hash_hmac', 'hash_equals'];

    /** La seule ligne de configuration autorisée à nommer le secret. */
    private const string FRAMEWORK_SECRET_LINE = "secret: '%env(APP_SECRET)%'";

    public function testEveryUseOfTheApplicationSecretDerivesItsOwnKey(): void
    {
        $uses = [];
        foreach (PhpSources::files(\dirname(__DIR__, 4).'/src') as $file) {
            $code = file_get_contents($file);
            self::assertIsString($code);
            if ($this->referencesTheSecret($code)) {
                $uses[$file] = $code;
            }
        }
        self::assertNotSame([], $uses, 'Aucun usage du secret trouvé : la garde ne garderait rien.');

        $contexts = [];
        foreach ($uses as $file => $code) {
            self::assertTrue($this->derivesWithItsOwnContext($code), \sprintf('%s reçoit APP_SECRET sans appeler KeyedMac::fromSecret(…, self::KEY_CONTEXT, …) ni SecretKeyDerivation::derive(…, self::KEY_CONTEXT) (#455).', $file));
            self::assertFalse($this->computesAMacItself($code), \sprintf('%s reçoit APP_SECRET et appelle hash_hmac() ou hash_equals() : passer par KeyedMac (#487).', $file));
            self::assertSame(1, preg_match_all("/const string KEY_CONTEXT = '([^']+)'/", $code, $matches), \sprintf('%s doit déclarer un KEY_CONTEXT, et un seul.', $file));
            $contexts[$matches[1][0]][] = $file;
        }

        foreach ($contexts as $context => $files) {
            self::assertCount(1, $files, \sprintf('Contexte de clé « %s » partagé par %s.', $context, implode(', ', $files)));
        }
    }

    public function testTheConfigurationNamesTheSecretOnlyForTheFramework(): void
    {
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\dirname(__DIR__, 4).'/config'));
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
     * Les formes d'appel de `hash_hmac()` / `hash_equals()` que la garde de
     * #487 voit, et celles qui n'en sont pas.
     */
    public function testTheCensusSeesAMacComputedOutsideKeyedMac(): void
    {
        self::assertTrue($this->computesAMacItself("<?php hash_hmac('sha256', \$a, \$k);"));
        self::assertTrue($this->computesAMacItself("<?php \\hash_hmac('sha256', \$a, \$k);"));
        self::assertTrue($this->computesAMacItself('<?php return hash_equals($a, $b);'));
        self::assertFalse($this->computesAMacItself("<?php // hash_hmac('sha256', \$a, \$k);"));
        self::assertFalse($this->computesAMacItself('<?php $mac->verifies($a, $b); $x = "hash_equals";'));
        self::assertFalse($this->computesAMacItself('<?php $this->hash_equals($a, $b);'));
    }

    private function referencesTheSecret(string $code): bool
    {
        return array_any(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => $token->is(\T_CONSTANT_ENCAPSED_STRING) && \in_array(trim($token->text, '\'"'), self::SECRET_REFERENCES, true),
        );
    }

    /**
     * Un appel de {@see self::DERIVATIONS} dont les arguments contiennent
     * `self::KEY_CONTEXT`, lu par jetons significatifs : un commentaire n'est
     * pas un appel, un contexte littéral pourrait être celui d'une autre classe.
     */
    private function derivesWithItsOwnContext(string $code): bool
    {
        $tokens = array_map(static fn (PhpToken $token): string => $token->text, PhpSources::significantTokens($code));
        $count = \count($tokens);
        for ($i = 0; $i + 3 < $count; ++$i) {
            if (!\in_array(\array_slice($tokens, $i, 4), self::DERIVATIONS, true)) {
                continue;
            }
            $depth = 0;
            for ($j = $i + 3; $j < $count; ++$j) {
                $depth += match ($tokens[$j]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                if (0 === $depth) {
                    break;
                }
                if ('self' === $tokens[$j] && '::' === ($tokens[$j + 1] ?? null) && 'KEY_CONTEXT' === ($tokens[$j + 2] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Un appel d'une fonction de {@see self::MAC_FUNCTIONS}, qualifiée ou non,
     * lu par jetons significatifs : ni un commentaire, ni une chaîne, ni une
     * méthode du même nom.
     */
    private function computesAMacItself(string $code): bool
    {
        $tokens = array_map(static fn (PhpToken $token): string => $token->text, PhpSources::significantTokens($code));

        return array_any(
            $tokens,
            static fn (string $text, int $i): bool => \in_array(ltrim($text, '\\'), self::MAC_FUNCTIONS, true)
                && '(' === ($tokens[$i + 1] ?? null)
                && !\in_array($tokens[$i - 1] ?? null, ['->', '?->', '::', 'function'], true),
        );
    }
}
