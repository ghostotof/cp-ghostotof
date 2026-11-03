<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\PhpSources;
use AssertionError;
use PhpToken;
use PHPUnit\Framework\TestCase;

/**
 * La lecture d'un caractère du code par l'identifiant du jeton, que partagent
 * les recenseurs de `tests/Support` : `isChar()` et `isAnyChar()` ne lisent que
 * le premier octet de leur argument, et une chaîne plus longue ne doit pas
 * passer pour le caractère qu'elle commence.
 */
final class PhpSourcesTest extends TestCase
{
    public function testACharacterOfTheCodeIsRecognisedByItsIdentifierNotByItsText(): void
    {
        // `"$x("` : le fragment `(` de la chaîne a le texte d'une parenthèse, son identifiant n'est pas celui du caractère.
        $tokens = PhpSources::significantTokens('<?php $s = "$x("; f();');
        $fragment = $this->firstTokenWithText($tokens, '(');
        self::assertFalse(PhpSources::isChar($fragment, '('));

        $call = $tokens[\count($tokens) - 3];
        self::assertSame('(', $call->text);
        self::assertTrue(PhpSources::isChar($call, '('));
        self::assertTrue(PhpSources::isAnyChar($call, '[', '('));
        self::assertFalse(PhpSources::isAnyChar($call, '[', ')'));
    }

    public function testACharacterOfSeveralBytesIsRefusedByIsChar(): void
    {
        $this->requireAssertions();
        $token = PhpSources::significantTokens('<?php (')[1];

        $this->expectException(AssertionError::class);
        PhpSources::isChar($token, '((');
    }

    public function testACharacterOfSeveralBytesIsRefusedByIsAnyChar(): void
    {
        $this->requireAssertions();
        // Un `)` : aucun des deux ne le reconnaît, `array_any` va jusqu'au second argument.
        $token = PhpSources::significantTokens('<?php )')[1];

        $this->expectException(AssertionError::class);
        PhpSources::isAnyChar($token, '(', '[[');
    }

    /**
     * `\assert()` est compilé hors du code quand `zend.assertions` vaut -1 (le `php.ini-production` de la CI) : le cas ne
     * peut rien affirmer là, et le dit au lieu de rester vert à tort.
     */
    private function requireAssertions(): void
    {
        if ('1' !== \ini_get('zend.assertions')) {
            self::markTestSkipped('zend.assertions n\'est pas à 1 : \assert() de PhpSources::isChar() n\'est pas exécuté.');
        }
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private function firstTokenWithText(array $tokens, string $text): PhpToken
    {
        foreach ($tokens as $token) {
            if ($text === $token->text) {
                return $token;
            }
        }

        self::fail('Aucun jeton de texte « '.$text.' ».');
    }
}
