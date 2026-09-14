<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Seul détenteur des attributs des deux cookies d'authentification (issue #87).
 *
 * `BEARER` (le JWT, httpOnly) et `XSRF-TOKEN` (le jeton du double-submit,
 * lisible en JS) sont posés au login (LoginSuccessSubscriber), posés pour le
 * palier de base (BaseAccessController, ADR 0003 D6) et expirés au logout
 * (CookieLogoutListener). Trois sites, et un cookie expiré dont les attributs
 * ne correspondent pas à la pose n'est pas supprimé par le navigateur : ces
 * attributs ne se recalculent donc plus ailleurs qu'ici.
 *
 * - `Path=/`, aucun `Domain` : le cookie vaut pour tout le site, et pour lui
 *   seul (un `Domain` explicite l'enverrait aux sous-domaines).
 * - `SameSite=Lax` : non envoyé sur une sous-requête cross-site (le double-submit
 *   couvre le reste, cf. CsrfCookieRequestSubscriber), mais envoyé sur une
 *   navigation de premier niveau — ce qui permet le login-CSRF de l'issue #76,
 *   traité par LoginCsrfRequestListener et non par le cookie.
 * - `Secure` en `prod` uniquement : le dev est servi en http, un cookie Secure
 *   n'y reviendrait jamais.
 *
 * La pose du `BEARER` au login reste faite par Lexik depuis
 * config/packages/lexik_jwt_authentication.yaml ; AuthCookieAttributesTest
 * vérifie que ses attributs sont ceux de cette fabrique, à chaque nom.
 */
final readonly class AuthCookieFactory
{
    /** Nom lu par l'extracteur Lexik (`token_extractors.cookie.name`). */
    public const string BEARER = 'BEARER';

    /** Nom lu par le frontend (`infrastructure/auth/csrfCookie.ts`). */
    public const string XSRF_TOKEN = 'XSRF-TOKEN';

    private const string PATH = '/';

    public function __construct(
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {
    }

    /**
     * @param int|null $expiresAt timestamp Unix, ou null pour un cookie de session
     */
    public function bearer(string $jwt, ?int $expiresAt = null): Cookie
    {
        return $this->create(self::BEARER, $jwt, $expiresAt);
    }

    /**
     * @param int|null $expiresAt timestamp Unix, ou null pour un cookie de session
     */
    public function xsrf(string $token, ?int $expiresAt = null): Cookie
    {
        return $this->create(self::XSRF_TOKEN, $token, $expiresAt);
    }

    /**
     * Le cookie « supprimé » : mêmes attributs que la pose, valeur vide,
     * échéance dans le passé — exactement ce que ResponseHeaderBag::clearCookie()
     * construit, sans que l'appelant ait à redire `Secure`, `HttpOnly` et
     * `SameSite`.
     *
     * Reçoit un `string` et non l'union des deux constantes : la garde est
     * volontairement à l'exécution, pour qu'un appel construit dynamiquement
     * échoue net plutôt que d'expirer un cookie qui n'est pas le sien.
     */
    public function expired(string $name): Cookie
    {
        if (self::BEARER !== $name && self::XSRF_TOKEN !== $name) {
            throw new \InvalidArgumentException(sprintf('"%s" n\'est pas un cookie d\'authentification (attendu : %s ou %s).', $name, self::BEARER, self::XSRF_TOKEN));
        }

        return $this->create($name, null, 1);
    }

    /**
     * @param self::BEARER|self::XSRF_TOKEN $name
     */
    private function create(string $name, ?string $value, ?int $expiresAt): Cookie
    {
        return Cookie::create($name, $value)
            ->withExpires($expiresAt ?? 0)
            ->withPath(self::PATH)
            ->withDomain(null)
            ->withSecure('prod' === $this->environment)
            ->withHttpOnly(self::BEARER === $name)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
