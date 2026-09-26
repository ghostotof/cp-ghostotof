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
        // Round de correction (M6) : plancher de rétention d'un jour. Sans
        // lui, un lancement manuel imprudent ("--older-than=1 hour") purgerait
        // la quasi-totalité des comptes en attente d'un coup — le CronJob,
        // lui, n'utilise jamais que la valeur par défaut (30 jours), le risque
        // ne vient que d'un appel humain direct. Un seuil devenu >= now
        // (intervalle négatif ou nul) reste un cas particulier de cette même
        // garde : le futur est encore plus proche que "maintenant − 1 jour".
        $minimumThreshold = $now->sub(new \DateInterval('P1D'));

        // Garde métier, avant toute lecture du dépôt : protège tout appelant,
        // pas seulement la commande CLI qui, elle, ne fait qu'attraper
        // l'exception.
        if ($threshold > $minimumThreshold) {
            throw InvalidPurgeRetentionException::forThreshold($threshold, $now);
        }

        $purged = [];
        $skipped = [];

        foreach ($this->cpgUserRepository->findAwaitingPasswordSetupInvitedBefore($threshold) as $user) {
            // Défense en profondeur (I4) : même si le dépôt renvoyait demain
            // un compte qui ne devrait plus l'être (régression du SQL), la
            // purge ne touche jamais rien d'autre qu'un compte au mot de
            // passe encore vide.
            if (!$user->isAwaitingPasswordSetup()) {
                continue;
            }

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
