<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\Corpus;

use App\Ai\Assistant\Infrastructure\Corpus\CorpusRenderer;
use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les DTO publics ne portent pas `position` : l'ordre du corpus est celui des
 * providers. Ce test pince la chaîne entière, du tri SQL au rendu, avec des
 * lignes insérées dans le désordre et une ligne d'une autre locale.
 */
final class CorpusRendererOrderTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM anonymous_cv_section');
        $connection->executeStatement('DELETE FROM case_study');
        parent::tearDown();
    }

    public function testEntriesFollowAscendingPositionAndOnlyTheRequestedLocale(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([2 => 'Troisième', 0 => 'Première', 1 => 'Deuxième'] as $position => $title) {
            $entityManager->persist(new AnonymousCvSection(Locale::FR, $title, 'S', 1, 'R', $position));
            $entityManager->persist(new CaseStudy(Locale::FR, 'Étude '.$title, 'P', 'S', 'C', 'R', $position));
        }
        $entityManager->persist(new AnonymousCvSection(Locale::EN, 'English only', 'S', 1, 'R', 0));
        $entityManager->flush();

        // La classe concrète, rendue publique en test (services.yaml, when@test) :
        // l'alias d'interface pourrait être inliné et introuvable ici.
        $corpus = self::getContainer()->get(CorpusRenderer::class)->render(Locale::FR);

        self::assertMatchesRegularExpression('/## Première.*## Deuxième.*## Troisième/s', $corpus);
        self::assertMatchesRegularExpression('/## Étude Première.*## Étude Deuxième.*## Étude Troisième/s', $corpus);
        self::assertStringNotContainsString('English only', $corpus);
    }
}
