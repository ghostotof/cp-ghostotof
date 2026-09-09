<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\ValueObject;

/**
 * Nature d'un instantané de veille. Il en existe un seul par type à la fois :
 * le type est la clé d'identification du snapshot courant, pas une catégorie.
 */
enum WatchSnapshotType: string
{
    /** Cycles de vie des versions, issus d'endoflife.date. */
    case RELEASE_CYCLES = 'release_cycles';

    /** Vulnérabilités connues des dépendances, issues d'OSV.dev. */
    case VULNERABILITIES = 'vulnerabilities';
}
