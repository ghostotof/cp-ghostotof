<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\ValueObject\ReleaseCycle;
use App\Portfolio\Watch\Domain\ValueObject\SupportStatus;

/**
 * Détermine l'état de maintenance d'un cycle à une date donnée.
 *
 * Le calcul s'appuie d'abord sur les **dates** publiées, et seulement à défaut
 * sur les drapeaux `isEol`/`isEoas` du fournisseur. Ce n'est pas un détail :
 * ces drapeaux sont figés à l'instant où la source a généré sa réponse, alors
 * que notre snapshot est relu pendant 24 à 36 h. Une échéance franchie entre
 * deux rafraîchissements doit se voir immédiatement — c'est précisément ce que
 * cette page prétend surveiller.
 *
 * La date de référence est un paramètre, jamais un `new \DateTimeImmutable()`
 * caché : sans cela, aucun cas limite ne serait testable.
 */
final readonly class SupportStatusCalculator
{
    public function statusFor(?ReleaseCycle $cycle, \DateTimeImmutable $now): SupportStatus
    {
        if (null === $cycle) {
            return SupportStatus::UNKNOWN;
        }

        if ($this->hasBeenReached($cycle->eolFrom, $now)) {
            return SupportStatus::EOL;
        }

        if ($this->hasBeenReached($cycle->endOfActiveSupportFrom, $now)) {
            return SupportStatus::SECURITY_ONLY;
        }

        // Une échéance est publiée et n'est pas encore atteinte : le cycle est
        // vivant, quoi qu'en disent des drapeaux calculés plus tôt.
        if (null !== $cycle->eolFrom || null !== $cycle->endOfActiveSupportFrom) {
            return SupportStatus::SUPPORTED;
        }

        return $this->fallbackOnFlags($cycle);
    }

    /**
     * L'échéance est inclusive : le jour dit, le support est terminé.
     */
    private function hasBeenReached(?\DateTimeImmutable $deadline, \DateTimeImmutable $now): bool
    {
        return null !== $deadline && $now >= $deadline;
    }

    /**
     * Certains produits n'ont pas de date d'échéance publiée (support « tant
     * qu'il y a des correctifs »). Les drapeaux restent alors le seul signal.
     */
    private function fallbackOnFlags(ReleaseCycle $cycle): SupportStatus
    {
        if ($cycle->isEol) {
            return SupportStatus::EOL;
        }

        if ($cycle->isEndOfActiveSupport) {
            return SupportStatus::SECURITY_ONLY;
        }

        if ($cycle->isMaintained) {
            return SupportStatus::SUPPORTED;
        }

        return SupportStatus::UNKNOWN;
    }
}
