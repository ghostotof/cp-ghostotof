<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée par PendingInvitationPurger::purge() lorsque le
 * seuil calculé (maintenant − durée de rétention) n'est pas strictement dans
 * le passé (issue #238, round de correction). Deux cas concrets observés :
 * un intervalle négatif transmis par erreur (ex. "--older-than=-30 days",
 * doublement négativé par \DateInterval::createFromDateString()) fait avancer
 * l'horloge au lieu de la reculer, et un intervalle nul ("0 days") place le
 * seuil exactement sur l'instant présent. Dans les deux cas, la garde en
 * amont de la commande (\DateTimeImmutable('-'.$olderThan)) ne suffit pas à
 * s'en protéger — cette exception protège donc directement le cas d'usage,
 * avant toute lecture du dépôt, pour tout appelant (CLI aujourd'hui, un
 * futur appelant direct demain).
 */
final class InvalidPurgeRetentionException extends \DomainException
{
    public static function forThreshold(\DateTimeImmutable $threshold, \DateTimeImmutable $now): self
    {
        return new self(sprintf(
            'La durée de rétention doit être strictement positive : le seuil calculé (%s) n\'est pas antérieur à maintenant (%s).',
            $threshold->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
