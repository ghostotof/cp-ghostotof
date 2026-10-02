<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Http;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;

/**
 * Panne du verrou des limiteurs de débit → 503 problem+json avec Retry-After
 * (issue #276, ADR 0005 D8/D10).
 *
 * Chaque limiteur prend un advisory lock PostgreSQL sur une seconde connexion.
 * Quand elle est refusée (`max_connections` atteint) ou que `lock_timeout`
 * expire, `Lock::acquire()` lève une `LockAcquiringException` ; si elle tombe
 * après la prise, la libération (dans le `finally` du limiteur) lève une
 * `LockReleasingException` ; et `acquire()` relaie tel quel un
 * `LockConflictedException` de son store en mémoire interne. Les trois sont
 * une panne du verrou, et reçoivent la même réponse. Le sens de
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
 * **Priorité 16, et une ligne de journal écrite ici.** Le message de ces
 * exceptions nomme la ressource verrouillée, qui contient la clé du limiteur —
 * l'IP du visiteur, l'identifiant tenté au login (où un mot de passe saisi
 * dans le mauvais champ finit aussi). `ErrorListener::logKernelException`
 * (Symfony, priorité 0) l'écrirait en `critical` sur le canal principal, que
 * la production garde. Passer au-dessus de 0 et répondre (`setResponse()`
 * arrête la propagation) le fait taire ; l'incident reste visible par la ligne
 * `error` écrite ici, qui porte les classes des exceptions et le chemin,
 * jamais leur message. C'est la seule trace de la panne en production : le
 * canal `lock` du composant, qui écrit la ressource en `notice`, y est muet
 * (handler à `warning`, issue #315, monolog.yaml). 16 reste au-dessus de l'`ExceptionListener` du firewall (1),
 * qui ne traite que les exceptions de sécurité, et loin devant API Platform
 * (-96) et `ApiJsonErrorFormatListener` (-100).
 *
 * **Ce que la réponse ne dit pas.** Ni le message de l'exception (corps fixe),
 * ni de sujet au journal d'audit : le chemin dit quel limiteur a cédé.
 *
 * **Portée : toute panne du composant Lock sous `/api`.** Aujourd'hui, seuls
 * les limiteurs utilisent ce composant (ADR 0005 D8), donc toute panne est
 * une panne de limiteur. Un futur verrou métier serait rendu ici sous le nom
 * `rate-limiter-unavailable`, à tort : l'introduire impose de revoir ce
 * listener. Filtrer sur le nom du verrou serait fragile, et c'est justement
 * la donnée sensible.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: 16)]
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
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if (!$event->isMainRequest() || !$this->isLockFailure($throwable)) {
            return;
        }

        $request = $event->getRequest();

        // Chemin décodé (CanonicalPath, issue #77) : `/%61pi/contact` est servi
        // comme `/api/contact`, il doit recevoir la même réponse.
        if (!CanonicalPath::isUnderApi($request)) {
            return;
        }

        // Jamais la clé `exception` : Monolog la normaliserait avec son message.
        $this->logger->error('Rate limiter lock unavailable, request refused with 503.', [
            'exceptionClasses' => $this->exceptionClasses($throwable),
            'path' => CanonicalPath::of($request),
        ]);
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
            if ($current instanceof LockAcquiringException
                || $current instanceof LockReleasingException
                || $current instanceof LockConflictedException) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<class-string<\Throwable>>
     */
    private function exceptionClasses(\Throwable $throwable): array
    {
        $classes = [];

        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            $classes[] = $current::class;
        }

        return $classes;
    }
}
