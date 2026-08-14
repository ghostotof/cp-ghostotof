<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Presentation\ApiResource;

/**
 * DTO imbriqué dans QualityContentResource, jamais exposé seul (pas
 * d'#[ApiResource] ici).
 */
final readonly class QualityTraitResource
{
    public function __construct(
        public string $label,
    ) {
    }
}
