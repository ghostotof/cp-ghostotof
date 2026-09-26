<?php

declare(strict_types=1);

namespace App\Security\User\Application;

/**
 * Compte-rendu de PendingInvitationPurger::purge(), destiné à l'appelant
 * (commande CLI planifiée). Ce n'est pas un concept métier : il ne franchit
 * jamais la frontière du domaine et n'est jamais persisté.
 */
final readonly class PendingInvitationPurgeResult
{
    /**
     * @param list<string> $purged  usernames supprimés (ou qui le seraient, en dry-run)
     * @param list<string> $skipped usernames ignorés parce que le compte porte ROLE_SUPER
     */
    public function __construct(
        public array $purged,
        public array $skipped,
        public \DateTimeImmutable $threshold,
    ) {
    }
}
