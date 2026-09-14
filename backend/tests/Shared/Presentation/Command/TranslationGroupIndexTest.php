<?php

declare(strict_types=1);

namespace App\Tests\Shared\Presentation\Command;

use App\Shared\Presentation\Command\TranslationGroupIndex;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Spec 0004 D1 — l'appariement FR/EN des commandes de peuplement tient
 * entièrement à cette classe : c'est elle qui décide que deux entrées sont le
 * même contenu. Deux propriétés la définissent, et les deux comptent autant
 * l'une que l'autre :
 *
 *  - un même couple (périmètre, index) rend **toujours** le même groupe — sans
 *    quoi la version EN ne serait liée à rien ;
 *  - deux couples différents rendent des groupes **différents** — sans quoi des
 *    contenus étrangers se prétendraient traductions les uns des autres.
 */
final class TranslationGroupIndexTest extends TestCase
{
    public function testTheSameScopeAndIndexAlwaysYieldTheSameGroup(): void
    {
        $index = new TranslationGroupIndex();

        $first = $index->forIndex('incident', 0);
        $second = $index->forIndex('incident', 0);

        self::assertTrue($first->equals($second));
        // Même instance : le groupe est mémorisé, pas reconstruit à l'identique.
        self::assertSame($first, $second);
    }

    public function testEachIndexOfAScopeGetsItsOwnGroup(): void
    {
        $index = new TranslationGroupIndex();

        self::assertFalse($index->forIndex('incident', 0)->equals($index->forIndex('incident', 1)));
    }

    /**
     * La raison d'être du périmètre : dans une même commande, plusieurs
     * contenus numérotent chacun depuis zéro — principes et traits pour
     * Qualité, cartes site et cartes « moi » par catégorie pour À propos. Sans
     * cette séparation, l'entrée 0 de l'un et l'entrée 0 de l'autre
     * partageraient un groupe, et le backoffice les afficherait sur une même
     * ligne comme deux langues d'un même contenu.
     */
    public function testTwoScopesDoNotShareAGroupAtTheSameIndex(): void
    {
        $index = new TranslationGroupIndex();

        self::assertFalse($index->forIndex('principle', 0)->equals($index->forIndex('trait', 0)));
    }

    /**
     * L'état ne doit pas fuir d'une exécution à l'autre : la classe est
     * instanciée dans `execute()`, jamais injectée. Deux instances ne se
     * connaissent donc pas.
     */
    public function testTwoInstancesShareNothing(): void
    {
        $first = new TranslationGroupIndex();
        $second = new TranslationGroupIndex();

        self::assertFalse($first->forIndex('incident', 0)->equals($second->forIndex('incident', 0)));
    }

    /**
     * Les groupes sont des UUID v7 comme les identifiants (spec 0003) :
     * croissants dans le temps, donc classables sans clé supplémentaire.
     */
    public function testGroupsAreUuidV7(): void
    {
        self::assertInstanceOf(UuidV7::class, (new TranslationGroupIndex())->forIndex('incident', 0));
    }
}
