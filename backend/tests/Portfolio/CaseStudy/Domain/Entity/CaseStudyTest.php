<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\CaseStudy\Domain\Entity;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
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
}
