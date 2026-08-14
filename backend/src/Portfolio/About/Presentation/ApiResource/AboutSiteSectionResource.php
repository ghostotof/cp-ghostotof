<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

/**
 * DTO imbriqué dans AboutContentResource, jamais exposé seul.
 */
final readonly class AboutSiteSectionResource
{
    /**
     * @param list<AboutCardResource> $cards
     */
    public function __construct(
        public string $eyebrow,
        public array $cards,
    ) {
    }
}
