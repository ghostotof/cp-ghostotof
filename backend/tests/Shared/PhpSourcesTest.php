<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\NotASingleCharacterException;
use App\Tests\Support\PhpSources;
use PhpToken;
use PHPUnit\Framework\TestCase;

/**
 * La lecture d'un caractère du code par l'identifiant du jeton, que partagent
 * les recenseurs de `tests/Support` : `isChar()` et `isAnyChar()` ne lisent que
 * le premier octet de leur argument, et une chaîne plus longue ne doit pas
 * passer pour le caractère qu'elle commence. Le refus est une exception
 * nommée, non un `\assert()` : ces cas tournent sous `zend.assertions=-1` (la
 * CI) comme sous 1 (le conteneur de dev), sans saut (#748, R220b).
 */
final class PhpSourcesTest extends TestCase
{
    public function testACharacterOfTheCodeIsRecognisedByItsIdentifierNotByItsText(): void
    {
        // `"$x("` : le fragment `(` de la chaîne a le texte d'une parenthèse, son identifiant n'est pas celui du caractère.
        $tokens = PhpSources::significantTokens('<?php $s = "$x("; f();');
        $fragment = $this->firstTokenWithText($tokens, '(');
        self::assertFalse(PhpSources::isChar($fragment, '('));

        $call = $this->tokenAfterName($tokens, 'f');
        self::assertSame('(', $call->text);
        self::assertTrue(PhpSources::isChar($call, '('));
        self::assertTrue(PhpSources::isAnyChar($call, '[', '('));
        self::assertFalse(PhpSources::isAnyChar($call, '[', ')'));
    }

    public function testACharacterOfSeveralBytesIsRefusedByIsChar(): void
    {
        $token = PhpSources::significantTokens('<?php (')[1];

        $this->expectException(NotASingleCharacterException::class);
        $this->expectExceptionMessage("reçu 2 octet(s) : '(('");
        PhpSources::isChar($token, '((');
    }

    /**
     * Le contrat ne dépend pas du jeton lu : un argument hors contrat placé
     * après celui qui reconnaît le jeton est refusé aussi, au lieu d'attendre
     * le jour où un autre jeton ferait aller `array_any` jusqu'à lui.
     */
    public function testIsAnyCharRefusesAnArgumentOfSeveralBytesEvenAfterAMatch(): void
    {
        $token = PhpSources::significantTokens('<?php (')[1];

        $this->expectException(NotASingleCharacterException::class);
        PhpSources::isAnyChar($token, '(', '[[');
    }

    /**
     * Le jeton qui suit le premier nom donné : la parenthèse d'appel de `f()`,
     * plutôt qu'un indice compté depuis la fin.
     *
     * @param list<PhpToken> $tokens
     */
    private function tokenAfterName(array $tokens, string $name): PhpToken
    {
        foreach ($tokens as $index => $token) {
            if ($token->is(\T_STRING) && $name === $token->text && isset($tokens[$index + 1])) {
                return $tokens[$index + 1];
            }
        }

        self::fail('Aucun nom « '.$name.' » suivi d\'un jeton.');
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
