<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\Controller;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieTokenSigner;
use App\Security\User\Domain\ValueObject\BaseAccessUser;
use DateTimeImmutable;
use DateTimeInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ADR 0003 D6 : émet un jeton portant exactement ROLE_USER, sans jamais créer
 * de compte (cf. BaseAccessUser, BaseAccessUserProvider). Rate-limité par
 * BaseAccessRateLimitRequestListener (avant ce contrôleur). Route publique —
 * voir ApiRouteExposureTest::PUBLIC_PATHS pour la justification, et
 * CsrfCookieRequestSubscriber::EXCLUDED_PATHS (appelant anonyme, aucun cookie
 * XSRF-TOKEN préexistant à double-soumettre).
 *
 * Pose les cookies BEARER + XSRF-TOKEN lui-même plutôt que de passer par le
 * firewall "login" (json_login) : il n'y a ni identifiant ni mot de passe à
 * vérifier ici, donc pas d'authenticator Symfony à faire intervenir. Leurs
 * attributs viennent d'AuthCookieFactory (issue #87), les mêmes qu'au login, et
 * ce sont des cookies de session comme au login (#748, F1). Seul le JWT a une
 * durée de vie volontairement plus courte (D6 : « jeton court, sans
 * renouvellement ») : c'est son `exp`, non le cookie, qui la porte.
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
        $baseAccessUser = new BaseAccessUser('guest-'.bin2hex(random_bytes(16)));

        $jwt = $this->jwtTokenManager->createFromPayload($baseAccessUser, [
            BaseAccessUser::TOKEN_CLAIM => true,
            'exp' => $expiresAt,
        ]);

        // `expiresAt` est le seul moyen pour le frontend de connaître
        // l'échéance : le cookie est httpOnly, le JWT illisible en JS. Sans
        // cette information, l'en-tête afficherait « Accès de base » jusqu'au
        // prochain rechargement, bien après que le jeton ait expiré.
        $response = new JsonResponse([
            'roles' => $baseAccessUser->getRoles(),
            'expiresAt' => (new DateTimeImmutable('@'.$expiresAt))->format(DateTimeInterface::ATOM),
        ]);

        // Cookies de session, sans échéance (#748, F1) : le navigateur fermé finit l'accès, et
        // l'`exp` du JWT le borne à quinze minutes. Un `BEARER` refusé (expiré, invalide) est
        // ignoré sur cette route publique (#748, R35 : le firewall `api` sert l'anonyme, voir
        // PublicRouteIgnoresRefusedSessionListener) : le frontend n'a pas à rejouer l'appel, un
        // seul jeton du limiteur est consommé, et les cookies neufs ci-dessous remplacent le mort.
        $response->headers->setCookie($this->authCookieFactory->bearer($jwt));
        $response->headers->setCookie($this->authCookieFactory->xsrf($this->csrfCookieTokenSigner->issue()));

        // Journal de sécurité (D5) : l'identifiant `guest-…` du jeton, jamais
        // le jeton lui-même — c'est lui qu'on retrouvera comme auteur d'un
        // éventuel 403 backoffice pendant ses 15 minutes.
        $this->auditLogger->baseAccessIssued($baseAccessUser->getUserIdentifier());

        return $response;
    }
}
