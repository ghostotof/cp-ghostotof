<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\InvalidPurgeRetentionException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class PendingInvitationPurger implements PendingInvitationPurgerInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private ClockInterface $clock,
        private SecurityAuditLoggerInterface $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    public function purge(\DateInterval $maxAge, bool $dryRun = false): PendingInvitationPurgeResult
    {
        $now = $this->clock->now();
        $threshold = $now->sub($maxAge);

        // Garde métier, avant toute lecture du dépôt : un intervalle négatif
        // (double négation côté appelant, ex. "--older-than=-30 days") ou nul
        // ("0 days") place le seuil dans le futur ou sur l'instant présent —
        // ce qui purgerait tous les comptes en attente au lieu des seuls
        // comptes réellement anciens. Protège tout appelant, pas seulement la
        // commande CLI qui, elle, ne fait qu'attraper l'exception.
        if ($threshold >= $now) {
            throw InvalidPurgeRetentionException::forThreshold($threshold, $now);
        }

        $purged = [];
        $skipped = [];

        foreach ($this->cpgUserRepository->findPendingActivationInvitedBefore($threshold) as $user) {
            // Garde anti-lockout (comme CpgUserAdministrator::delete) : un
            // compte ROLE_SUPER en attente d'activation reste une décision
            // humaine, jamais une suppression automatique.
            if (\in_array(CpgUser::ROLE_SUPER, $user->getRoles(), true)) {
                $skipped[] = $user->getUsername();
                $this->logger->warning(
                    'Invitation en attente ignorée par la purge automatique : le compte porte ROLE_SUPER.',
                    ['username' => $user->getUsername()],
                );

                continue;
            }

            if (!$dryRun) {
                $this->cpgUserRepository->remove($user);
                // Journal de sécurité : après la suppression effective,
                // jamais en dry-run (rien ne s'est réellement passé).
                $this->auditLogger->userPurged($user);
            }

            $purged[] = $user->getUsername();
        }

        return new PendingInvitationPurgeResult($purged, $skipped, $threshold);
    }
}
