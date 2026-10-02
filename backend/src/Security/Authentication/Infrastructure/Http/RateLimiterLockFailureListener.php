<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Http;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Lock\Exception\LockAcquiringException;

/**
 * Panne du verrou des limiteurs de débit → 503 problem+json avec Retry-After
 * (issue #276, ADR 0005 D8/D10).
 *
 * Chaque limiteur prend un advisory lock PostgreSQL sur une seconde connexion.
 * Quand elle est refusée (`max_connections` atteint) ou que `lock_timeout`
 * expire, `Lock::acquire()` lève une `LockAcquiringException`. Le sens de
 * défaillance était déjà le bon — la requête est refusée, jamais laissée
 * passer sans décompte — mais elle sortait en 500 générique : pas de
 * Retry-After pour le client, et sur le login aucune trace au journal
 * d'audit, l'exception naissant avant toute vérification d'identifiants
 * (`LoginThrottlingListener`, sur `CheckPassportEvent`), donc avant
 * `LoginFailureEvent`.
 *
 * Un seul listener pour toutes les routes limitées, qu'elles soient servies
 * par API Platform (`/api/contact`, `/api/backoffice/translations`) ou non
 * (`/api/login_check`, `/api/account/base-access`, et les deux routes du
 * set-password, limitées dans un listener `kernel.request`) : la réponse est
 * construite ici plutôt que confiée à `exception_to_status`, qui ne couvrirait
 * que la première famille et ne sait pas poser d'en-tête.
 *
 * **Priorité -50.** Au-dessous de 0, pour que `ErrorListener::logKernelException`
 * (Symfony, priorité 0) journalise d'abord l'erreur — c'est un incident
 * d'infrastructure, il doit rester visible côté exploitation ; `setResponse()`
 * arrête la propagation, un listener au-dessus de 0 l'aurait fait taire.
 * Au-dessus de l'`ExceptionListener` d'API Platform (-96) et
 * d'`ApiJsonErrorFormatListener` (-100), pour passer avant leur rendu en 500.
 *
 * **Ce que la réponse ne dit pas.** Le message de `LockAcquiringException`
 * nomme la ressource verrouillée, qui contient la clé du limiteur — l'IP du
 * visiteur, l'identifiant tenté au login. Il ne sort ni dans le corps (texte
 * fixe) ni au journal d'audit (événement sans sujet, le chemin dit quel
 * limiteur a cédé).
 */
#[AsEventListener(event: ExceptionEvent::class, priority: -50)]
final readonly class RateLimiterLockFailureListener
{
    /**
     * Délai suggéré au client. Le double du `lock_timeout` de 5 s
     * (`www.prod.conf`) : une panne du verrou signale une base saturée, et un
     * client qui revient aussitôt l'entretient. Assez court pour qu'un humain
     * qui retente le formulaire ne se croie pas banni.
     */
    public const int RETRY_AFTER_SECONDS = 10;

    /** Slug stable sur lequel un client peut se brancher, comme les `/errors/<slug>` d'API Platform. */
    private const string PROBLEM_TYPE = '/errors/rate-limiter-unavailable';

    public function __construct(
        private SecurityAuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isLockFailure($event->getThrowable())) {
            return;
        }

        // Chemin décodé (CanonicalPath, issue #77) : `/%61pi/contact` est servi
        // comme `/api/contact`, il doit recevoir la même réponse.
        if (!CanonicalPath::isUnderApi($event->getRequest())) {
            return;
        }

        $this->auditLogger->rateLimiterUnavailable();

        $event->setResponse(new JsonResponse(
            [
                'type' => self::PROBLEM_TYPE,
                'title' => 'Service Unavailable',
                'status' => 503,
                'detail' => 'The rate limiter is temporarily unavailable. Please retry later.',
            ],
            503,
            [
                'Content-Type' => 'application/problem+json',
                'Retry-After' => (string) self::RETRY_AFTER_SECONDS,
            ],
        ));
    }

    /**
     * La chaîne des `previous` est parcourue : un appelant qui rattraperait la
     * panne pour la relancer en contexte ne doit pas la faire retomber en 500.
     */
    private function isLockFailure(\Throwable $throwable): bool
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof LockAcquiringException) {
                return true;
            }
        }

        return false;
    }
}
