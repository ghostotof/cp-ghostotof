<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Presentation\ApiResource;

/**
 * DTO imbriqué dans QualityContentResource, jamais exposé seul (pas
 * d'#[ApiResource] ici).
 */
final readonly class QualityPrincipleResource
{
    public function __construct(
        public string $title,
        public string $description,
        public string $iconKey,
    ) {
    }
}
