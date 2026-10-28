<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\DeadlockRetryInterface;
use Closure;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * {@see DeadlockRetryInterface} sur Doctrine : un `40P01` du premier essai
 * (DBAL le range en {@see DeadlockException}, et elle seule) est relancé une
 * fois, sur un EntityManager remis à neuf.
 *
 * **Pourquoi remettre à neuf.** La transaction annulée avait déjà écrit (le
 * mot de passe, au point de sauvegarde) : `PostgresLockedTransaction` ferme
 * alors l'EntityManager (issue #549), carte d'identité vidée, et toute écriture
 * suivante par l'ORM échoue en `EntityManagerClosed`. `resetManager()` le
 * remplace **en place** : le gestionnaire est un objet paresseux
 * (`doctrine.orm.entity_manager`, `->lazy()`), que `ManagerRegistry` ré-initialise
 * sans que les services qui le tiennent (les verrous, le dépôt) aient à changer
 * de référence. Toujours, et non seulement s'il est fermé : un interblocage levé
 * avant toute écriture le laisserait ouvert, mais avec des entités modifiées en
 * mémoire que le second essai ne doit pas retrouver.
 *
 * **Exactement un second essai.** Il n'y a pas de boucle : un second
 * interblocage remonte tel quel (le second, qui est le plus récent), comme avant
 * S3. Pas d'attente entre les essais : PostgreSQL a déjà rompu le cycle, la
 * transaction qui l'a gagné tient les verrous que le second essai attendra
 * normalement (bornée par `lock_timeout`). Rejetés : une boucle bornée à trois
 * (un cycle qui se reforme trois fois d'affilée est un défaut de conception à
 * voir, pas un aléa à absorber) ; une attente aléatoire (rien à désynchroniser,
 * la file de PostgreSQL ordonne).
 *
 * Le journal ne cite que le SQLSTATE : le message du pilote recopie
 * l'instruction et ses paramètres.
 */
final readonly class DoctrineDeadlockRetry implements DeadlockRetryInterface
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private LoggerInterface $logger,
    ) {
    }

    public function retryOnce(Closure $attempt): mixed
    {
        try {
            return $attempt(false);
        } catch (DeadlockException $deadlock) {
            $this->logger->warning('Interblocage PostgreSQL : la transaction a été annulée, relancée une fois.', [
                'event' => 'deadlock-retried',
                'sqlState' => $deadlock->getSQLState(),
            ]);

            $this->managerRegistry->resetManager();

            return $attempt(true);
        }
    }
}
