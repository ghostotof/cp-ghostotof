<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\CaseStudy\Domain\Entity;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class CaseStudyTest extends TestCase
{
    private function caseStudy(): CaseStudy
    {
        return new CaseStudy(
            Locale::FR,
            'Un cache partagé qui servait des réponses à la mauvaise organisation',
            'Une API multi-tenant renvoyait parfois les données du mauvais client sous forte charge.',
            'Clé de cache incluant systématiquement l\'identifiant de tenant, invalidation testée sous charge.',
            'Complexité de clé accrue, taux de cache-hit légèrement réduit.',
            'Zéro fuite inter-tenant sur 3 mois de production, latence p99 inchangée.',
            0,
        );
    }

    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewCaseStudyIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        self::assertInstanceOf(UuidV7::class, $this->caseStudy()->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants, sur quoi repose l'ordre de
     * repli `ORDER BY id` du repository.
     */
    public function testTwoCaseStudiesBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = $this->caseStudy();
        $second = $this->caseStudy();

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testConstructorSetsAllProperties(): void
    {
        $caseStudy = $this->caseStudy();

        self::assertSame(Locale::FR, $caseStudy->getLocale());
        self::assertSame('Un cache partagé qui servait des réponses à la mauvaise organisation', $caseStudy->getTitle());
        self::assertSame('Une API multi-tenant renvoyait parfois les données du mauvais client sous forte charge.', $caseStudy->getProblem());
        self::assertSame('Clé de cache incluant systématiquement l\'identifiant de tenant, invalidation testée sous charge.', $caseStudy->getSolution());
        self::assertSame('Complexité de clé accrue, taux de cache-hit légèrement réduit.', $caseStudy->getTradeoffs());
        self::assertSame('Zéro fuite inter-tenant sur 3 mois de production, latence p99 inchangée.', $caseStudy->getMeasuredResult());
        self::assertSame(0, $caseStudy->getPosition());
    }

    public function testUpdateReplacesEveryMutablePropertyButNotTheLocale(): void
    {
        $caseStudy = $this->caseStudy();

        $caseStudy->update(
            'Nouveau titre',
            'Nouveau problème.',
            'Nouvelle solution.',
            'Nouveaux compromis.',
            'Nouveau résultat.',
            3,
        );

        self::assertSame('Nouveau titre', $caseStudy->getTitle());
        self::assertSame('Nouveau problème.', $caseStudy->getProblem());
        self::assertSame('Nouvelle solution.', $caseStudy->getSolution());
        self::assertSame('Nouveaux compromis.', $caseStudy->getTradeoffs());
        self::assertSame('Nouveau résultat.', $caseStudy->getMeasuredResult());
        self::assertSame(3, $caseStudy->getPosition());
        // La locale n'est pas modifiable : changer la langue d'une étude de cas
        // revient à en créer une autre, pas à éditer celle-ci (même choix que
        // Contribution::update()).
        self::assertSame(Locale::FR, $caseStudy->getLocale());
    }

    /**
     * Spec 0004 D1 : le groupe de traduction est un identifiant partagé, pas
     * une entité. Une entrée construite sans groupe en reçoit un neuf — elle
     * est donc toujours dans un groupe, fût-il d'une seule langue, ce qui
     * permet à `translation_group` d'être NOT NULL.
     */
    public function testAnEntryBuiltWithoutAGroupGetsAFreshTranslationGroup(): void
    {
        $first = $this->caseStudy();
        $second = $this->caseStudy();

        self::assertInstanceOf(UuidV7::class, $first->getTranslationGroup());
        self::assertFalse($first->getTranslationGroup()->equals($second->getTranslationGroup()));
    }

    /**
     * L'autre voie : la version d'une autre langue reçoit le groupe de
     * l'entrée existante dès la construction — c'est ce que font les commandes
     * de peuplement pour apparier FR et EN au même index.
     */
    public function testAnEntryBuiltWithAGroupCarriesIt(): void
    {
        $group = Uuid::v7();

        $entry = new CaseStudy(
            Locale::EN,
            'A shared cache serving responses to the wrong organisation',
            'A multi-tenant API sometimes returned another customer\'s data under load.',
            'Cache key always including the tenant identifier.',
            'Higher key complexity, slightly lower hit rate.',
            'Zero cross-tenant leak over 3 months in production.',
            0,
            $group,
        );

        self::assertTrue($group->equals($entry->getTranslationGroup()));
    }

    public function testAttachToTranslationGroupLinksTheEntryToAnExistingGroup(): void
    {
        $entry = $this->caseStudy();
        $group = Uuid::v7();

        $entry->attachToTranslationGroup($group);

        self::assertTrue($group->equals($entry->getTranslationGroup()));
    }

    /**
     * Détacher ne remet pas le groupe à `null` — la colonne est NOT NULL : elle
     * en reçoit un neuf, ce qui isole l'entrée de ses anciennes traductions.
     */
    public function testDetachFromTranslationGroupGivesAFreshGroup(): void
    {
        $entry = $this->caseStudy();
        $previous = $entry->getTranslationGroup();

        $entry->detachFromTranslationGroup();

        self::assertInstanceOf(UuidV7::class, $entry->getTranslationGroup());
        self::assertFalse($previous->equals($entry->getTranslationGroup()));
    }

    /**
     * Spec 0004 D5 : la clé d'ordre d'un contenu localisé est son groupe, pas
     * son id — c'est ce qui fait qu'un déplacement suit le contenu dans toutes
     * les langues.
     */
    public function testOrderingKeyIsTheTranslationGroupAndNotTheId(): void
    {
        $entry = $this->caseStudy();

        self::assertSame($entry->getTranslationGroup()->toRfc4122(), $entry->orderingKey());
        self::assertNotSame($entry->getId()->toRfc4122(), $entry->orderingKey());
    }

    public function testMoveToPositionWritesThePosition(): void
    {
        $entry = $this->caseStudy();

        $entry->moveToPosition(4);

        self::assertSame(4, $entry->getPosition());
    }
}
