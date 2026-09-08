<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\ValueObject\SnapshotFreshness;

/**
 * Dit si la donnée servie est encore d'actualité.
 *
 * Le seuil dépasse volontairement la période du travail planifié — 36 heures
 * pour une exécution quotidienne, décision D9. Un seuil égal à la période
 * afficherait « périmé » chaque jour dans l'heure précédant l'exécution, et un
 * seuil inférieur en permanence : il faut toujours laisser passer un cycle
 * manqué avant d'alerter, sans quoi l'avertissement devient un bruit de fond
 * qu'on cesse de lire.
 *
 * Le calcul se fait à la lecture, jamais à l'écriture. C'est ce qui permet à un
 * snapshot de devenir périmé tout seul lorsque le rafraîchissement échoue :
 * marquer l'état en base supposerait qu'un processus vienne le faire, c'est-à-
 * dire précisément celui qui vient d'échouer.
 */
final readonly class SnapshotFreshnessCalculator
{
    /**
     * 1,5 × la période quotidienne du travail planifié : un cycle manqué reste
     * toléré, deux ne le sont plus.
     */
    public const int STALE_AFTER_HOURS = 36;

    public function freshnessFor(?\DateTimeImmutable $refreshedAt, \DateTimeImmutable $now): SnapshotFreshness
    {
        if (null === $refreshedAt) {
            return SnapshotFreshness::NEVER_REFRESHED;
        }

        $deadline = $refreshedAt->modify(sprintf('+%d hours', self::STALE_AFTER_HOURS));

        return $now < $deadline ? SnapshotFreshness::FRESH : SnapshotFreshness::STALE;
    }
}
