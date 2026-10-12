<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Exception\InvalidPurgeRetentionException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class PendingInvitationPurger implements PendingInvitationPurgerInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private ClockInterface $clock,
        private SecurityAuditLoggerInterface $auditLogger,
        private LoggerInterface $logger,
        private AccountLockInterface $accountLock,
    ) {
    }

    public function purge(DateInterval $maxAge, bool $dryRun = false): PendingInvitationPurgeResult
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
        $minimumThreshold = $now->sub(new DateInterval('P1D'));

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
                // Par l'UUID, jamais l'identifiant (issue #363) : celui d'un
                // invité est dérivé de son adresse.
                $skipped[] = $user->accountId();
                $this->logger->warning(
                    'Invitation en attente ignorée par la purge automatique : le compte porte ROLE_SUPER.',
                    ['userId' => $user->accountId()],
                );

                continue;
            }

            if (!$dryRun) {
                if (!$this->removeIfStillPurgeable($user, $threshold)) {
                    continue;
                }
                // Journal de sécurité : après la suppression effective,
                // jamais en dry-run (rien ne s'est réellement passé).
                $this->auditLogger->userPurged($user);
            }

            $purged[] = $user->accountId();
        }

        return new PendingInvitationPurgeResult($purged, $skipped, $threshold);
    }

    /**
     * Sous le verrou du compte (issue #459), celui de l'invitation et de
     * l'activation : la liste a été lue hors verrou, et une relance, une
     * activation ou un mot de passe posé depuis le backoffice a pu être
     * commité depuis. Les critères de la lecture sont refaits sur le compte
     * relu ; un compte déjà supprimé (par l'admin) n'est plus à purger.
     */
    private function removeIfStillPurgeable(CpgUser $user, DateTimeImmutable $threshold): bool
    {
        try {
            return $this->accountLock->whileLocked($user, function (CpgUser $locked) use ($threshold): bool {
                $invitedAt = $locked->getInvitedAt();
                if (!$locked->isAwaitingPasswordSetup() || null === $invitedAt || $invitedAt >= $threshold
                    || \in_array(CpgUser::ROLE_SUPER, $locked->getRoles(), true)) {
                    return false;
                }

                $this->cpgUserRepository->remove($locked);

                return true;
            });
        } catch (CpgUserNotFoundException) {
            return false;
        }
    }
}
