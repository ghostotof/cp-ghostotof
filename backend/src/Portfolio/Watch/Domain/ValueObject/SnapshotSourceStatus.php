<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\ValueObject;

/**
 * Qualité du dernier rafraîchissement ayant produit le snapshot.
 *
 * Volontairement binaire : un rafraîchissement qui n'a rien rapporté du tout
 * n'écrit pas de snapshot (voir WatchSnapshot::refresh()), il n'y a donc pas
 * d'état « échec total » à représenter ici — l'échec se lit dans l'âge de la
 * donnée précédente, restée en place.
 */
enum SnapshotSourceStatus: string
{
    /** Toutes les entrées ont été rafraîchies. */
    case OK = 'ok';

    /** Une partie seulement : au moins une source a échoué, les autres tiennent. */
    case PARTIAL = 'partial';
}
