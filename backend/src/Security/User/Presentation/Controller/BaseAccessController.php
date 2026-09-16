<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\Controller;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieTokenSigner;
use App\Security\User\Domain\ValueObject\GuestUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ADR 0003 D6 : émet un jeton portant exactement ROLE_USER, sans jamais créer
 * de compte (cf. GuestUser, GuestUserProvider). Rate-limité par
 * BaseAccessRateLimitRequestListener (avant ce contrôleur). Route publique —
 * voir ApiRouteExposureTest::PUBLIC_PATHS pour la justification, et
 * CsrfCookieRequestSubscriber::EXCLUDED_PATHS (appelant anonyme, aucun cookie
 * XSRF-TOKEN préexistant à double-soumettre).
 *
 * Pose les cookies BEARER + XSRF-TOKEN lui-même plutôt que de passer par le
 * firewall "login" (json_login) : il n'y a ni identifiant ni mot de passe à
 * vérifier ici, donc pas d'authenticator Symfony à faire intervenir. Leurs
 * attributs viennent d'AuthCookieFactory (issue #87), les mêmes qu'au login ;
 * seule la durée de vie est volontairement plus courte (D6 : « jeton court,
 * sans renouvellement »).
 */
final readonly class BaseAccessController
{
    /** 15 minutes — très inférieur aux 3600s du jeton de connexion normale (D6). */
    private const int TOKEN_TTL_SECONDS = 900;

    public function __construct(
        private JWTTokenManagerInterface $jwtTokenManager,
        private CsrfCookieTokenSigner $csrfCookieTokenSigner,
        private AuthCookieFactory $authCookieFactory,
        private SecurityAuditLoggerInterface $auditLogger,
    ) {
    }

    #[Route('/api/account/base-access', name: 'api_account_base_access', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $expiresAt = time() + self::TOKEN_TTL_SECONDS;
        $guest = new GuestUser('guest-'.bin2hex(random_bytes(16)));

        $jwt = $this->jwtTokenManager->createFromPayload($guest, [
            'guest' => true,
            'exp' => $expiresAt,
        ]);

        // `expiresAt` est le seul moyen pour le frontend de connaître
        // l'échéance : le cookie est httpOnly, le JWT illisible en JS. Sans
        // cette information, l'en-tête afficherait « Accès de base » jusqu'au
        // prochain rechargement, bien après que le jeton ait expiré.
        $response = new JsonResponse([
            'roles' => $guest->getRoles(),
            'expiresAt' => (new \DateTimeImmutable('@'.$expiresAt))->format(\DateTimeInterface::ATOM),
        ]);

        // Les deux cookies meurent avec le jeton : rien à « terminer » côté
        // navigateur passé les 15 minutes, et le XSRF-TOKEN n'a pas de raison
        // de survivre au BEARER qu'il accompagne.
        $response->headers->setCookie($this->authCookieFactory->bearer($jwt, $expiresAt));
        $response->headers->setCookie($this->authCookieFactory->xsrf($this->csrfCookieTokenSigner->issue(), $expiresAt));

        // Journal de sécurité (D5) : l'identifiant `guest-…` du jeton, jamais
        // le jeton lui-même — c'est lui qu'on retrouvera comme auteur d'un
        // éventuel 403 backoffice pendant ses 15 minutes.
        $this->auditLogger->baseAccessIssued($guest->getUserIdentifier());

        return $response;
    }
}
