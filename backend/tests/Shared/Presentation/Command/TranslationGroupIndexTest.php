<?php

declare(strict_types=1);

namespace App\Tests\Shared\Presentation\Command;

use App\Shared\Presentation\Command\TranslationGroupIndex;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 0004 D1 — l'appariement FR/EN des commandes de peuplement tient
 * entièrement à cette classe : c'est elle qui décide que deux entrées sont le
 * même contenu. Trois propriétés la définissent, et les trois comptent :
 *
 *  - un couple (périmètre, index) encore inconnu rend `null` — la première
 *    langue crée son entrée **sans groupe** (spec 0004 D3 : un groupe forgé
 *    d'avance n'est porté par aucune entrée, et le domaine le refuse) ;
 *  - une fois retenu, ce couple rend **toujours** le même groupe — sans quoi la
 *    version EN ne serait liée à rien ;
 *  - deux couples différents rendent des groupes **différents** — sans quoi des
 *    contenus étrangers se prétendraient traductions les uns des autres.
 */
final class TranslationGroupIndexTest extends TestCase
{
    /**
     * Ce `null` est le contrat, pas un défaut : c'est lui qui fait créer la
     * première langue sans groupe, l'entité s'en forgeant un que la suite
     * retient.
     */
    public function testAnUnknownPairYieldsNoGroupYet(): void
    {
        self::assertNull((new TranslationGroupIndex())->forIndex('incident', 0));
    }

    public function testARememberedPairAlwaysYieldsTheSameGroup(): void
    {
        $index = new TranslationGroupIndex();
        $group = Uuid::v7();

        $index->remember('incident', 0, $group);

        self::assertSame($group, $index->forIndex('incident', 0));
        self::assertSame($group, $index->forIndex('incident', 0));
    }

    /**
     * C'est la **première** langue qui fait foi : les suivantes se rattachent à
     * son groupe et le redonnent en retour, ce qui ne doit rien changer.
     */
    public function testRememberingTwiceKeepsTheFirstGroup(): void
    {
        $index = new TranslationGroupIndex();
        $first = Uuid::v7();

        $index->remember('incident', 0, $first);
        $index->remember('incident', 0, Uuid::v7());

        self::assertSame($first, $index->forIndex('incident', 0));
    }

    public function testEachIndexOfAScopeKeepsItsOwnGroup(): void
    {
        $index = new TranslationGroupIndex();
        $group = Uuid::v7();

        $index->remember('incident', 0, $group);

        self::assertNull($index->forIndex('incident', 1));
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

        $index->remember('principle', 0, Uuid::v7());

        self::assertNull($index->forIndex('trait', 0));
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

        $first->remember('incident', 0, Uuid::v7());

        self::assertNull($second->forIndex('incident', 0));
    }
}
