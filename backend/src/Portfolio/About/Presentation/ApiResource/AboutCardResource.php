<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

/**
 * DTO imbriqué dans AboutContentResource (site.cards, me.technicalCards,
 * me.personalCards, me.hobbiesCards), jamais exposé seul (pas d'#[ApiResource]
 * ici). Forme identique pour AboutSiteCard et AboutMeCard.
 */
final readonly class AboutCardResource
{
    public function __construct(
        public string $title,
        public string $description,
        public ?string $iconKey,
    ) {
    }
}
