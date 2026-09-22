<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
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
        $threshold = $this->clock->now()->sub($maxAge);

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
