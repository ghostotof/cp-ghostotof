<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

/**
 * DTO imbriqué dans AboutContentResource, jamais exposé seul.
 */
final readonly class AboutMeSectionResource
{
    /**
     * @param list<AboutCardResource> $technicalCards
     * @param list<AboutCardResource> $personalCards
     * @param list<AboutCardResource> $hobbiesCards
     */
    public function __construct(
        public string $eyebrow,
        public string $technicalSubtitle,
        public array $technicalCards,
        public string $personalSubtitle,
        public array $personalCards,
        public string $hobbiesSubtitle,
        public array $hobbiesCards,
    ) {
    }
}
