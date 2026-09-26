<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée par PendingInvitationPurger::purge() lorsque le
 * seuil calculé (maintenant − durée de rétention) est plus récent que le
 * plancher d'un jour (issue #238, round de correction M6). Trois cas
 * concrets couverts : un intervalle négatif transmis par erreur (ex.
 * "--older-than=-30 days", doublement négativé par
 * \DateInterval::createFromDateString()) fait avancer l'horloge au lieu de la
 * reculer ; un intervalle nul ("0 days") place le seuil exactement sur
 * l'instant présent ; et, plus généralement, tout intervalle strictement
 * inférieur à un jour ("1 hour") purgerait en un lancement manuel la quasi-
 * totalité des comptes en attente — le CronJob quotidien n'utilise jamais que
 * la valeur par défaut (30 jours), seul un appel humain direct est concerné.
 * La garde en amont de la commande (\DateTimeImmutable('-'.$olderThan)) ne
 * suffit à couvrir aucun de ces trois cas — cette exception protège donc
 * directement le cas d'usage, avant toute lecture du dépôt, pour tout
 * appelant (CLI aujourd'hui, un futur appelant direct demain).
 */
final class InvalidPurgeRetentionException extends \DomainException
{
    public static function forThreshold(\DateTimeImmutable $threshold, \DateTimeImmutable $now): self
    {
        return new self(sprintf(
            'La durée de rétention doit être d\'au moins un jour : le seuil calculé (%s) est trop récent par rapport à maintenant (%s).',
            $threshold->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
