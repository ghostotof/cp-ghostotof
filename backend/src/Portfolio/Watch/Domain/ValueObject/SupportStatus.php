<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\ValueObject;

/**
 * État de maintenance d'une version installée, au moment où on la regarde.
 *
 * `UNKNOWN` n'est pas une valeur de repli commode : c'est une réponse à part
 * entière, qui dit « je ne sais pas » plutôt que d'inventer un statut. Une
 * veille qui devine ne vaut pas mieux qu'une absence de veille.
 */
enum SupportStatus: string
{
    /** Correctifs fonctionnels et de sécurité. */
    case SUPPORTED = 'supported';

    /** Support actif terminé : correctifs de sécurité uniquement. */
    case SECURITY_ONLY = 'security_only';

    /** Fin de vie : plus aucun correctif, pas même de sécurité. */
    case EOL = 'eol';

    /** Version absente des cycles publiés, ou source sans signal exploitable. */
    case UNKNOWN = 'unknown';
}
