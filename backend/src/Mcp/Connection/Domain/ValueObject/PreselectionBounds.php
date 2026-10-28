<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Les trois bornes de la présélection SQL de la purge à un instant (#726,
 * #730) : une connexion active est candidate quand son dernier usage (ou son
 * octroi) précède {@see self::idleBefore()}, ou son octroi
 * {@see self::maximumBefore()}, ou — son code n'ayant jamais été échangé — son
 * octroi {@see self::unexchangedBefore()}. Calculées par
 * {@see ConnectionLifetimes::preselectionBoundsAt()}, qui détient les durées et
 * la marge : nulle part ailleurs.
 *
 * Un objet valeur nommé plutôt que des paramètres positionnels : les trois
 * bornes sont des instants du même type, qu'un `($a, $b, $c)` permuté
 * échangerait sans qu'aucun typage ne le voie — et le dépôt les lit ensemble,
 * corrélées par l'instant dont elles dérivent.
 */
final readonly class PreselectionBounds
{
    public function __construct(
        private DateTimeImmutable $idleBefore,
        private DateTimeImmutable $maximumBefore,
        private DateTimeImmutable $unexchangedBefore,
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

    /**
     * La borne des connexions jamais échangées (#730) : `maintenant − durée
     * des non échangées + la minute de jeu`, en temps écoulé. Une connexion
     * candidate par cette borne a au moins la durée moins une minute, bien plus
     * que le code le plus long encore échangeable (plancher de
     * {@see ConnectionLifetimes::fromConfiguration()}).
     */
    public function unexchangedBefore(): DateTimeImmutable
    {
        return $this->unexchangedBefore;
    }
}
