<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Tests\Support\PhpSources;
use PhpToken;
use PHPUnit\Framework\TestCase;

/**
 * Toute classe de `src/` qui reçoit `kernel.secret` (`APP_SECRET`) en dérive
 * sa propre clé (issue #455) : elle appelle `SecretKeyDerivation::derive()` et
 * déclare un `KEY_CONTEXT` que nulle autre n'emploie. Le secret brut ne signe
 * plus rien, et deux usages ne partagent jamais une clé.
 *
 * Recensement par jetons : une chaîne littérale `'%kernel.secret%'` ou
 * `'kernel.secret'` (l'injection par `#[Autowire]`), jamais un commentaire qui
 * la cite.
 */
final class ApplicationSecretUsesTest extends TestCase
{
    private const array SECRET_REFERENCES = ["'%kernel.secret%'", "'kernel.secret'"];

    public function testEveryUseOfTheApplicationSecretDerivesItsOwnKey(): void
    {
        $uses = $this->secretUses();
        self::assertNotSame([], $uses, 'Aucun usage de kernel.secret trouvé : la garde ne garderait rien.');

        $contexts = [];
        foreach ($uses as $file => $code) {
            self::assertStringContainsString('SecretKeyDerivation::derive(', $code, \sprintf('%s reçoit kernel.secret sans en dériver de clé (#455).', $file));
            self::assertSame(1, preg_match_all("/const string KEY_CONTEXT = '([^']+)'/", $code, $matches), \sprintf('%s doit déclarer un KEY_CONTEXT, et un seul.', $file));
            $contexts[$matches[1][0]][] = $file;
        }

        foreach ($contexts as $context => $files) {
            self::assertCount(1, $files, \sprintf('Contexte de clé « %s » partagé par %s.', $context, implode(', ', $files)));
        }
    }

    /**
     * @return array<string, string> chemin => source, pour chaque fichier qui injecte le secret
     */
    private function secretUses(): array
    {
        $uses = [];
        foreach (PhpSources::files(\dirname(__DIR__, 4).'/src') as $file) {
            $code = file_get_contents($file);
            self::assertIsString($code);
            foreach (PhpToken::tokenize($code) as $token) {
                if ($token->is(\T_CONSTANT_ENCAPSED_STRING) && \in_array($token->text, self::SECRET_REFERENCES, true)) {
                    $uses[$file] = $code;

                    break;
                }
            }
        }

        return $uses;
    }
}
