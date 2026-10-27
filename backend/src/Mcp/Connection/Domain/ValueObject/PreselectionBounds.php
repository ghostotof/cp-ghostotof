<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Les deux bornes de la présélection SQL de la purge à un instant (#726) :
 * une connexion active est candidate quand son dernier usage (ou son octroi)
 * précède {@see self::idleBefore()}, ou son octroi
 * {@see self::maximumBefore()}. Calculées par
 * {@see ConnectionLifetimes::preselectionBoundsAt()}, qui détient les durées et
 * la marge : nulle part ailleurs.
 *
 * Un objet valeur nommé plutôt qu'un couple positionnel : les deux bornes
 * sont des instants du même type, qu'un `[$a, $b]` inversé échangerait sans
 * qu'aucun typage ne le voie.
 */
final readonly class PreselectionBounds
{
    public function __construct(
        private DateTimeImmutable $idleBefore,
        private DateTimeImmutable $maximumBefore,
    ) {
    }

    /** La borne d'inactivité : `maintenant − délai d'inactivité + marge`. */
    public function idleBefore(): DateTimeImmutable
    {
        return $this->idleBefore;
    }

    /** La borne de durée maximale : `maintenant − durée maximale + marge`. */
    public function maximumBefore(): DateTimeImmutable
    {
        return $this->maximumBefore;
    }
}
