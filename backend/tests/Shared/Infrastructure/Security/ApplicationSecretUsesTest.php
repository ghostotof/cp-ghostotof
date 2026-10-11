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
 * (issue #455) : elle appelle `SecretKeyDerivation::derive(…, self::KEY_CONTEXT)`
 * et déclare un `KEY_CONTEXT` que nulle autre n'emploie. Le secret brut ne
 * signe plus rien dans `src/`, et deux usages ne partagent jamais une clé.
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
            self::assertTrue($this->derivesWithItsOwnContext($code), \sprintf('%s reçoit APP_SECRET sans appeler SecretKeyDerivation::derive(…, self::KEY_CONTEXT) (#455).', $file));
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
    }

    private function referencesTheSecret(string $code): bool
    {
        return array_any(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => $token->is(\T_CONSTANT_ENCAPSED_STRING) && \in_array(trim($token->text, '\'"'), self::SECRET_REFERENCES, true),
        );
    }

    /**
     * Un appel `SecretKeyDerivation::derive(` dont les arguments contiennent
     * `self::KEY_CONTEXT`, lu par jetons significatifs : un commentaire n'est
     * pas un appel, un contexte littéral pourrait être celui d'une autre classe.
     */
    private function derivesWithItsOwnContext(string $code): bool
    {
        $tokens = array_map(static fn (PhpToken $token): string => $token->text, PhpSources::significantTokens($code));
        $count = \count($tokens);
        for ($i = 0; $i + 3 < $count; ++$i) {
            if (['SecretKeyDerivation', '::', 'derive', '('] !== \array_slice($tokens, $i, 4)) {
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
}
