<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Http;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Défense anti « login-CSRF » (issue #76) des deux points d'entrée anonymes
 * qui posent un cookie BEARER : POST /api/login_check et
 * POST /api/account/base-access (ADR 0003 D6).
 *
 * Ces routes sont, à raison, hors du double-submit de CsrfCookieRequestSubscriber :
 * l'appelant est anonyme, il n'a aucun XSRF-TOKEN à recopier. Mais un
 * formulaire HTML sur un site tiers peut les faire soumettre par le
 * navigateur d'un visiteur en navigation de premier niveau. Le BEARER du
 * visiteur n'est pas envoyé (SameSite=Lax), et pourtant la réponse est reçue
 * dans un contexte de premier niveau : ses Set-Cookie sont acceptés et
 * REMPLACENT le BEARER en place. Un compte du palier de confiance se retrouve
 * rétrogradé au palier de base (ou connecté sur le compte de l'attaquant)
 * jusqu'à reconnexion. Rien ne fuit, mais la session est cassée à distance.
 *
 * La garde : exiger un en-tête personnalisé, `X-Requested-With`. Un formulaire
 * HTML ne peut poser aucun en-tête personnalisé, et un `fetch()` cross-site
 * qui en pose un déclenche un preflight CORS, que nelmio_cors refuse pour
 * toute origine hors CORS_ALLOW_ORIGIN — la requête n'est alors jamais
 * envoyée. Seul le SPA légitime, même origine ou origine autorisée, passe.
 * C'est la présence de l'en-tête qui protège, pas sa valeur : elle n'est
 * donc pas vérifiée (une valeur vide, elle, compte comme absente).
 *
 * Priorité 20, comme CsrfCookieRequestSubscriber et pour la même raison :
 * au-dessus du firewall (8), sinon le json_login authenticator poserait le
 * cookie avant que la garde ne parle ; et au-dessus des rate limiters (15),
 * pour qu'une soumission forgée ne consomme pas le quota IP de la victime.
 *
 * Chemin comparé sous sa forme décodée (CanonicalPath, issue #77).
 */
#[AsEventListener(event: RequestEvent::class, priority: 20)]
final readonly class LoginCsrfRequestListener
{
    public const string HEADER_NAME = 'X-Requested-With';

    /** @var list<string> */
    private const array GUARDED_PATHS = ['/api/login_check', '/api/account/base-access'];

    public function __construct(private SecurityAuditLoggerInterface $auditLogger)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Un formulaire HTML ne soumet qu'en GET ou POST ; ces routes ne
        // répondent de toute façon qu'au POST.
        if ('POST' !== $request->getMethod() || !\in_array(CanonicalPath::of($request), self::GUARDED_PATHS, true)) {
            return;
        }

        $header = $request->headers->get(self::HEADER_NAME);

        if (!\is_string($header) || '' === $header) {
            // Journal de sécurité (D5) : le chemin dit lequel des deux gardes a parlé.
            $this->auditLogger->csrfRejected();

            throw new AccessDeniedHttpException(\sprintf('En-tête %s requis sur cette route.', self::HEADER_NAME));
        }
    }
}
