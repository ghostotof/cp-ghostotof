<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\Controller;

use App\Security\Authentication\Infrastructure\Http\CsrfCookieTokenSigner;
use App\Security\User\Domain\ValueObject\GuestUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
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
 * Pose les cookies BEARER + XSRF-TOKEN à la main plutôt que de passer par le
 * firewall "login" (json_login) : il n'y a ni identifiant ni mot de passe à
 * vérifier ici, donc pas d'authenticator Symfony à faire intervenir. Même
 * shape de cookies que LoginSuccessSubscriber/lexik_jwt_authentication.yaml,
 * mais durée de vie volontairement plus courte (D6 : « jeton court, sans
 * renouvellement »).
 */
final readonly class BaseAccessController
{
    /** 15 minutes — très inférieur aux 3600s du jeton de connexion normale (D6). */
    private const int TOKEN_TTL_SECONDS = 900;

    public function __construct(
        private JWTTokenManagerInterface $jwtTokenManager,
        private CsrfCookieTokenSigner $csrfCookieTokenSigner,
        #[Autowire('%kernel.environment%')] private string $environment,
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

        $response = new JsonResponse(['roles' => $guest->getRoles()]);

        $isProd = 'prod' === $this->environment;

        $response->headers->setCookie(
            Cookie::create('BEARER', $jwt)
                ->withExpires($expiresAt)
                ->withPath('/')
                ->withSecure($isProd)
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );

        $response->headers->setCookie(
            Cookie::create('XSRF-TOKEN', $this->csrfCookieTokenSigner->issue())
                ->withExpires($expiresAt)
                ->withPath('/')
                ->withSecure($isProd)
                ->withHttpOnly(false)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );

        return $response;
    }
}
