<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\CaseStudy\Domain\Entity;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

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

    public function testConstructorSetsAllProperties(): void
    {
        $caseStudy = $this->caseStudy();

        self::assertNull($caseStudy->getId());
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
