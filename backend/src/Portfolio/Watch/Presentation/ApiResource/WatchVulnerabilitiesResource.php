<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Presentation\ApiResource;

/**
 * Le volet « vulnérabilités » tel qu'un visiteur anonyme peut le voir :
 * **un décompte, et rien d'autre**.
 *
 * Décision D4. Publier la liste des failles affectant ce site en production
 * reviendrait à tendre une carte au premier scanner venu. Le détail —
 * identifiants, paquets touchés, versions correctives — existe bien dans le
 * snapshot, mais n'est servi qu'à ROLE_SUPER.
 *
 * Le compte invité `ROLE_USER` ne suffirait pas : il est partagé et ses
 * identifiants circulent, ce qui en fait l'équivalent du public dès qu'il
 * s'agit d'une surface d'attaque. La nuance est autre pour le CV, qui est une
 * donnée personnelle et non une faiblesse exploitable.
 *
 * `packagesScanned` vaut null quand aucune analyse n'a été tentée. C'est la
 * distinction qui empêche la page d'afficher un « 0 vulnérabilité » rassurant
 * là où personne n'a rien cherché.
 */
final readonly class WatchVulnerabilitiesResource
{
    /**
     * @param int|null    $packagesScanned taille du périmètre analysé, null si aucune analyse n'a eu lieu
     * @param int         $affectedCount   nombre de vulnérabilités connues sur ce périmètre
     * @param string|null $checkedAt       date de l'analyse, en UTC (ISO 8601), ou null si aucune
     * @param string      $freshness       valeur de SnapshotFreshness : fresh, stale ou never_refreshed
     */
    public function __construct(
        public ?int $packagesScanned,
        public int $affectedCount,
        public ?string $checkedAt,
        public string $freshness,
    ) {
    }
}
