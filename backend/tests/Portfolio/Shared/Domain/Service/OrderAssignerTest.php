<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Domain\Service;

use App\Portfolio\Shared\Domain\Exception\IncompleteOrderException;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupHasSeveralPositionsException;
use App\Portfolio\Shared\Domain\Exception\UnknownOrderEntryException;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Tests\Portfolio\Shared\Support\FakeOrderable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec 0004 D4/D5 : la règle d'ensemble exact, une fois, pour les neuf
 * périmètres qui la partagent.
 */
final class OrderAssignerTest extends TestCase
{
    public function testNominalWritesTheIndexOfEachKeyOnItsEntity(): void
    {
        $a = new FakeOrderable('a');
        $b = new FakeOrderable('b');
        $c = new FakeOrderable('c');

        (new OrderAssigner())->assign([$a, $b, $c], ['c', 'a', 'b']);

        self::assertSame(1, $a->getPosition());
        self::assertSame(2, $b->getPosition());
        self::assertSame(0, $c->getPosition());
    }

    /**
     * Spec 0004 D5 : un groupe à deux locales est deux entités qui répondent
     * la même clé — elles reçoivent donc la même position.
     */
    public function testAGroupWithTwoLocalesReceivesTheSamePosition(): void
    {
        $fr = new FakeOrderable('group-1');
        $en = new FakeOrderable('group-1');
        $other = new FakeOrderable('group-2');

        (new OrderAssigner())->assign([$fr, $en, $other], ['group-2', 'group-1']);

        self::assertSame(1, $fr->getPosition());
        self::assertSame(1, $en->getPosition());
        self::assertSame(0, $other->getPosition());
    }

    public function testAKeyNotInTheScopeIsRefused(): void
    {
        $a = new FakeOrderable('a');

        $this->expectException(UnknownOrderEntryException::class);

        (new OrderAssigner())->assign([$a], ['a', 'ghost']);
    }

    public function testAScopeKeyMissingFromTheListIsRefused(): void
    {
        $a = new FakeOrderable('a');
        $b = new FakeOrderable('b');

        $this->expectException(IncompleteOrderException::class);

        (new OrderAssigner())->assign([$a, $b], ['a']);
    }

    /**
     * Choix documenté : une clé dupliquée est traitée comme une entrée
     * inconnue plutôt que comme un cas à part. Une fois consommée par sa
     * première occurrence, la seconde ne se distingue plus d'une clé qui
     * n'aurait jamais été dans le périmètre — dans les deux cas la liste
     * n'est pas une permutation valide du périmètre, et le 422 est le même.
     */
    public function testADuplicatedKeyIsRefused(): void
    {
        $a = new FakeOrderable('a');

        $this->expectException(UnknownOrderEntryException::class);

        (new OrderAssigner())->assign([$a], ['a', 'a']);
    }

    public function testAnEmptyListOnAnEmptyScopeHasNoEffectAndRaisesNothing(): void
    {
        (new OrderAssigner())->assign([], []);

        $this->expectNotToPerformAssertions();
    }

    /**
     * Une entité hors périmètre n'est jamais touchée — par construction :
     * `OrderAssigner` ne connaît que ce que `$scope` lui donne.
     */
    public function testAnEntityOutsideTheScopeIsNeverTouched(): void
    {
        $inScope = new FakeOrderable('in');
        $outsideScope = new FakeOrderable('out');

        (new OrderAssigner())->assign([$inScope], ['in']);

        self::assertTrue($inScope->wasMoved());
        self::assertFalse($outsideScope->wasMoved());
    }

    /**
     * Issue #503 : un groupe dont les membres divergent déjà sur la position
     * (une écriture qui a contourné le trigger différé) n'est plus renuméroté
     * en silence — l'écrire réparait le groupe et effaçait la preuve du défaut
     * amont. Le refus passe avant toute écriture : aucune entité ne bouge.
     */
    public function testAGroupLeftOnSeveralPositionsIsRefusedAndNothingMoves(): void
    {
        [$french, $english, $other] = $this->scopeWithADivergentGroup();

        try {
            (new OrderAssigner())->assign([$french, $english, $other], ['group-2', 'group-1']);
            self::fail('Le groupe sur deux positions a été renuméroté en silence.');
        } catch (TranslationGroupHasSeveralPositionsException $refusal) {
            self::assertStringContainsString('group-1', $refusal->getMessage(), 'Le refus ne nomme pas le groupe fautif.');
            self::assertStringContainsString('0, 3', $refusal->getMessage(), 'Le refus ne donne pas les positions trouvées.');
            self::assertStringContainsString(FakeOrderable::class, $refusal->getMessage(), 'Le refus ne nomme pas le type de contenu, donc la table.');
        }

        foreach ([$french, $english, $other] as $entity) {
            self::assertFalse($entity->wasMoved(), 'Une entité a bougé avant le refus.');
        }
        self::assertSame([0, 3, 1], [$french->getPosition(), $english->getPosition(), $other->getPosition()]);
    }

    /**
     * Le défaut du pipeline passe avant l'erreur du client (même ordre que
     * #384 dans `ContentPlacement::inGroup()`) : dans l'ordre inverse, un 422
     * ordinaire (une liste périmée, `info`) masquerait le groupe corrompu, que
     * seul l'envoi suivant nommerait.
     *
     * @param list<string> $orderedKeys
     */
    #[DataProvider('provideOrdersTheClientMayGetWrong')]
    public function testTheDivergentGroupIsRefusedBeforeTheExactSetRule(array $orderedKeys): void
    {
        $this->expectException(TranslationGroupHasSeveralPositionsException::class);

        (new OrderAssigner())->assign($this->scopeWithADivergentGroup(), $orderedKeys);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideOrdersTheClientMayGetWrong(): iterable
    {
        yield 'clé inconnue (unknown-order-entry)' => [['group-1', 'ghost', 'group-2']];
        yield 'clé dupliquée (unknown-order-entry)' => [['group-1', 'group-1', 'group-2']];
        yield 'clé manquante (incomplete-order)' => [['group-2']];
    }

    /**
     * Un groupe FR/EN resté sur deux positions (0 et 3), et un autre groupe
     * sain à la position 1.
     *
     * @return list<FakeOrderable>
     */
    private function scopeWithADivergentGroup(): array
    {
        return [new FakeOrderable('group-1', 0), new FakeOrderable('group-1', 3), new FakeOrderable('group-2', 1)];
    }
}
