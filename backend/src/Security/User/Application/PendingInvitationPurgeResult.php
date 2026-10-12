<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use DateTimeImmutable;

/**
 * Compte-rendu de PendingInvitationPurger::purge(), destiné à l'appelant
 * (commande CLI planifiée). Ce n'est pas un concept métier : il ne franchit
 * jamais la frontière du domaine et n'est jamais persisté.
 */
final readonly class PendingInvitationPurgeResult
{
    /**
     * Les comptes par leur UUID, jamais par leur identifiant (issue #363) :
     * la commande les affiche, et la sortie d'un CronJob est un journal de pod.
     *
     * @param list<string> $purged  UUID des comptes supprimés (ou qui le seraient, en dry-run)
     * @param list<string> $skipped UUID des comptes ignorés parce qu'ils portent ROLE_SUPER
     */
    public function __construct(
        public array $purged,
        public array $skipped,
        public DateTimeImmutable $threshold,
    ) {
    }
}
