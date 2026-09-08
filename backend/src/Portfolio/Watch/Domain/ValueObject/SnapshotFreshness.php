<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\ValueObject;

/**
 * Ce que vaut encore la donnée affichée.
 *
 * Calculée à la lecture, jamais stockée : c'est ce qui permet à un snapshot de
 * devenir périmé **tout seul** quand le rafraîchissement échoue. Un marqueur
 * écrit en base supposerait qu'un processus passe le mettre à jour — celui-là
 * même qui vient d'échouer.
 */
enum SnapshotFreshness: string
{
    /** Rafraîchie dans le délai attendu. */
    case FRESH = 'fresh';

    /** Plus rafraîchie depuis trop longtemps : lisible, mais à prendre avec précaution. */
    case STALE = 'stale';

    /** Aucun rafraîchissement n'a jamais abouti. */
    case NEVER_REFRESHED = 'never_refreshed';
}
