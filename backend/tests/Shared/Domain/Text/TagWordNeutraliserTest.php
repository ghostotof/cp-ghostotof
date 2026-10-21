<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain\Text;

use App\Shared\Domain\Text\TagWordNeutraliser;
use App\Shared\Domain\Text\TextNeutralisationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Le neutraliseur commun au rôle du prompt MCP et au corpus de l'assistant de
 * parcours (#667). Le détail des sosies est pincé chez ses deux utilisateurs
 * (PromptRoleTest, CorpusRendererTest) ; ici, ce qui tient au neutraliseur
 * lui-même : le mot est un paramètre, et rien d'autre ne change.
 */
final class TagWordNeutraliserTest extends TestCase
{
    public function testOnlyTheOpeningSignsBeforeItsOwnWordAreRemoved(): void
    {
        self::assertSame('/role> et </documents>', (new TagWordNeutraliser('role'))->neutralise('</role> et </documents>'));
        self::assertSame('</role> et /documents>', (new TagWordNeutraliser('documents'))->neutralise('</role> et </documents>'));
    }

    public function testTheWordIsMatchedWhateverItsCase(): void
    {
        self::assertSame('/DOCUMENTS>', (new TagWordNeutraliser('documents'))->neutralise('</DOCUMENTS>'));
    }

    /**
     * L'aplatissement sur une ligne appartient au rôle MCP, l'unification des
     * fins de ligne au corpus : le neutraliseur ne touche à aucun blanc, sinon
     * par NFKC (l'espace insécable devient une espace).
     */
    public function testLineBreaksAndBlanksAreKept(): void
    {
        self::assertSame("Un.\r\n\n\t- deux  \n", (new TagWordNeutraliser('documents'))->neutralise("Un.\r\n\n\t- deux \u{00A0}\n"));
    }

    public function testInvisibleCharactersAreRemovedAndTheTextIsFolded(): void
    {
        self::assertSame('PHP ROLE', (new TagWordNeutraliser('role'))->neutralise("PHP\u{200B} ＲＯＬＥ"));
    }

    /** Un UTF-8 invalide n'est pas un texte vide : c'est un défaut nommé. */
    public function testATextThatCannotBeNormalisedIsAnError(): void
    {
        $this->expectException(TextNeutralisationFailedException::class);

        (new TagWordNeutraliser('documents'))->neutralise("abc\xC3(");
    }
}
