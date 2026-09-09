<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Presentation\ApiResource;

/**
 * Une ligne du radar, telle que la page la consomme.
 *
 * Les dates sont des chaînes `Y-m-d` et non des objets : ce sont des échéances
 * de calendrier, sans heure ni fuseau — « le support s'arrête le 31 décembre
 * 2027 » ne dépend pas de l'endroit d'où on le lit.
 */
final readonly class WatchedProductResource
{
    public function __construct(
        public string $slug,
        public string $label,
        public ?string $version,
        /** Valeur de SupportStatus : supported, security_only, eol ou unknown. */
        public string $status,
        /** Cycle de vie auquel appartient la version installée, ex. « 8.5 ». */
        public ?string $cycle,
        public ?string $endOfActiveSupportFrom,
        public ?string $eolFrom,
        public ?string $latestVersion,
        public bool $hasNewerPatch,
        public ?string $documentationUrl,
    ) {
    }
}
