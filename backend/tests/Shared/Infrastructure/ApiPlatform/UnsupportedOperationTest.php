<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutMeCardProcessor;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutSiteCardProcessor;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutMeCardResource;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSiteCardResource;
use App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform\BackofficeAnonymousCvSectionProcessor;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\BackofficeAnonymousCvSectionResource;
use App\Portfolio\CaseStudy\Infrastructure\ApiPlatform\BackofficeCaseStudyProcessor;
use App\Portfolio\CaseStudy\Presentation\ApiResource\BackofficeCaseStudyResource;
use App\Portfolio\Contribution\Infrastructure\ApiPlatform\BackofficeContributionProcessor;
use App\Portfolio\Contribution\Presentation\ApiResource\BackofficeContributionResource;
use App\Portfolio\Experience\Infrastructure\ApiPlatform\BackofficeExperienceTechnologyProcessor;
use App\Portfolio\Experience\Presentation\ApiResource\BackofficeExperienceTechnologyResource;
use App\Portfolio\Incident\Infrastructure\ApiPlatform\BackofficeIncidentProcessor;
use App\Portfolio\Incident\Presentation\ApiResource\BackofficeIncidentResource;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\BackofficeQualityPrincipleProcessor;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\BackofficeQualityTraitProcessor;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityPrincipleResource;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityTraitResource;
use App\Portfolio\Watch\Infrastructure\ApiPlatform\BackofficeWatchedProductProcessor;
use App\Portfolio\Watch\Presentation\ApiResource\BackofficeWatchedProductResource;
use App\Shared\Infrastructure\ApiPlatform\UnsupportedOperationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Un Processor du backoffice ne sait traiter que les opérations que sa
 * ressource déclare (Post, Put, Delete). En recevoir une autre est un défaut
 * de câblage de la ressource, jamais une requête cliente (issue #338) : il
 * doit le dire sous un nom qui se reconnaît dans les journaux, plutôt qu'en
 * \LogicException anonyme — et surtout ne pas la traiter comme un Post.
 *
 * Les Processors sont tirés du conteneur, câblés comme en production : la
 * garde est atteinte sans qu'aucun service métier ne soit appelé.
 */
final class UnsupportedOperationTest extends KernelTestCase
{
    /**
     * @param class-string<ProcessorInterface<object, object|null>> $processor
     */
    #[DataProvider('backofficeProcessors')]
    public function testAnUndeclaredOperationIsRefusedUnderItsOwnName(string $processor, object $data): void
    {
        $service = self::getContainer()->get($processor);
        self::assertInstanceOf(ProcessorInterface::class, $service);

        try {
            $service->process($data, new Patch());
            self::fail('Une opération Patch ne doit jamais être traitée.');
        } catch (UnsupportedOperationException $exception) {
            self::assertStringContainsString(Patch::class, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{class-string, object}>
     */
    public static function backofficeProcessors(): iterable
    {
        yield 'carte « moi » de la page À propos' => [BackofficeAboutMeCardProcessor::class, new BackofficeAboutMeCardResource()];
        yield 'carte « site » de la page À propos' => [BackofficeAboutSiteCardProcessor::class, new BackofficeAboutSiteCardResource()];
        yield 'section du CV anonyme' => [BackofficeAnonymousCvSectionProcessor::class, new BackofficeAnonymousCvSectionResource()];
        yield 'étude de cas' => [BackofficeCaseStudyProcessor::class, new BackofficeCaseStudyResource()];
        yield 'contribution' => [BackofficeContributionProcessor::class, new BackofficeContributionResource()];
        // Lit `years` avant de choisir la branche : il faut une valeur que le domaine accepte.
        yield 'technologie d\'expérience' => [BackofficeExperienceTechnologyProcessor::class, new BackofficeExperienceTechnologyResource(years: 1.0)];
        yield 'incident' => [BackofficeIncidentProcessor::class, new BackofficeIncidentResource()];
        yield 'principe qualité' => [BackofficeQualityPrincipleProcessor::class, new BackofficeQualityPrincipleResource()];
        yield 'trait qualité' => [BackofficeQualityTraitProcessor::class, new BackofficeQualityTraitResource()];
        yield 'produit de la veille' => [BackofficeWatchedProductProcessor::class, new BackofficeWatchedProductResource()];
    }
}
