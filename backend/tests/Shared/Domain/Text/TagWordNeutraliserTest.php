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

    /**
     * Relecture de #667 : NFKC changeait la valeur d'un nombre (`10⁶` → `106`,
     * `2³²` → `232`, `½` → `1⁄2`), et le modèle aurait cité un résultat mesuré
     * faux. Un chiffre en exposant, en indice ou une fraction garde sa forme ;
     * le reste est replié comme avant, lettres en exposant comprises.
     */
    public function testNumbersInSuperscriptSubscriptOrFractionKeepTheirValue(): void
    {
        $text = '10⁶ requêtes, 2³² valeurs, 40 m², CO₂, ½ journée';

        self::assertSame($text, (new TagWordNeutraliser('documents'))->neutralise($text));
    }

    public function testEverythingElseIsStillFoldedByNfkc(): void
    {
        self::assertSame('fi, 1, d, A, ...', (new TagWordNeutraliser('documents'))->neutralise("ﬁ, ①, \u{1D48}, Ａ, …"));
    }

    /**
     * Un UTF-8 invalide n'est pas un texte vide : c'est un défaut nommé, que
     * PCRE signale dès le premier découpage.
     *
     * Le seul échec de PCRE qu'un test provoque à coup sûr (mesuré pendant la
     * relecture de #667) : une limite de retour arrière basse n'a plus d'effet
     * sur un motif qu'un test précédent a déjà compilé avec le JIT — PHP le
     * garde en cache —, si bien qu'un test sur la limite passerait seul et
     * échouerait dans la suite. Les échecs des passes suivantes ne sont donc
     * pas provoqués, accepté : chacune lève la même exception.
     */
    public function testAnInvalidUtf8TextIsAnError(): void
    {
        $this->expectException(TextNeutralisationFailedException::class);
        $this->expectExceptionMessage('Malformed UTF-8');

        (new TagWordNeutraliser('documents'))->neutralise("abc\xC3(");
    }
}
