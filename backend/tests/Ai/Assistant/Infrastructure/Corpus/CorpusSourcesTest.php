<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\Corpus;

use App\Ai\Assistant\Infrastructure\Corpus\CorpusRenderer;
use App\Ai\Assistant\Infrastructure\Pdf\PopplerPdfTextExtractor;
use App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform\AnonymousCvProvider;
use App\Portfolio\CaseStudy\Infrastructure\ApiPlatform\CaseStudyProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pince la liste des sources du corpus (spec 0005 D5, M2) sur le service
 * réellement construit par le conteneur, pas sur une déclaration : ajouter une
 * source fait échouer ce test tant qu'elle n'est pas inscrite ici, avec sa
 * justification, comme PUBLIC_PATHS dans ApiRouteExposureTest. Une source
 * supplémentaire demande un amendement de la spec (§9, « Demander avant »).
 */
final class CorpusSourcesTest extends KernelTestCase
{
    /** Source → pourquoi l'assistant a le droit de la lire. */
    private const array ALLOWED_SOURCES = [
        AnonymousCvProvider::class => 'CV sans identité : lu par le palier de base, donc par tout ROLE_TRUSTED (ADR 0003 D5). Provider public, mêmes données que GET /api/anonymous-cv/{locale}.',
        CaseStudyProvider::class => 'Études de cas : même palier, même raison. Provider public, mêmes données que GET /api/case-studies/{locale}.',
        PopplerPdfTextExtractor::class => "CV nominatif : le fichier même que GET /api/cv sert à ROLE_TRUSTED, le palier exigé par ^/api/assistant (spec 0005 D7). Lecteur dédié plutôt que provider : le CV n'a ni entité ni ressource API Platform, seulement le paramètre app.cv_file_path que le contrôleur lit aussi. Il ne part que chez Scaleway, l'hébergeur du site (ADR 0004 D3 amendée).",
    ];

    public function testTheCorpusReadsExactlyTheAllowedSources(): void
    {
        $expected = array_keys(self::ALLOWED_SOURCES);
        sort($expected);

        self::assertSame($expected, $this->injectedSources());
    }

    public function testNoSourceIsARepositoryOrABackofficeClass(): void
    {
        foreach ($this->injectedSources() as $source) {
            $shortName = (new \ReflectionClass($source))->getShortName();
            self::assertStringNotContainsString('Backoffice', $shortName, $source);
            self::assertStringEndsNotWith('Repository', $shortName, $source);
        }
    }

    /**
     * @return list<class-string>
     */
    private function injectedSources(): array
    {
        self::bootKernel();
        $renderer = self::getContainer()->get(CorpusRenderer::class);

        $sources = [];
        foreach ((new \ReflectionObject($renderer))->getProperties() as $property) {
            $value = $property->getValue($renderer);
            if (\is_object($value)) {
                $sources[] = $value::class;
            }
        }
        sort($sources);

        return $sources;
    }
}
