<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Http;

use SensitiveParameter;
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
 * - `Secure` partout sauf en `dev` (issue #714) : le dev est servi en http.
 *   Chrome et Firefox tiennent http://localhost pour un contexte sûr et y
 *   acceptent un cookie Secure, mais pas tous les navigateurs, et aucun sur
 *   une autre adresse (IP du réseau local, nom d'hôte) : l'exception évite
 *   un dev qui marche selon le navigateur. `test` le porte aussi, pour que les
 *   tests fonctionnels voient la vraie chaîne de pose le produire ; et un
 *   environnement ajouté demain l'a par défaut. Leurs clients restent en http
 *   sans rien perdre : le `CookieJar` de BrowserKit ignore le drapeau `secure`
 *   d'un cookie reçu sur une URI qui n'est pas `https`
 *   ({@see \Symfony\Component\BrowserKit\Cookie::fromString()}), et le renvoie
 *   donc.
 *
 * La pose du `BEARER` au login reste faite par Lexik depuis
 * config/packages/lexik_jwt_authentication.yaml ;
 * {@see \App\Tests\Security\Authentication\AuthCookieAttributesTest} vérifie,
 * en `test`, que ses attributs sont ceux de cette fabrique, à chaque nom,
 * `Secure` compris, et {@see \App\Tests\Security\Authentication\BearerCookieSecurePerEnvironmentTest}
 * que son `Secure` déclaré suit la règle ci-dessus dans chaque environnement
 * (issue #703).
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
    public function bearer(#[SensitiveParameter] string $jwt, ?int $expiresAt = null): Cookie
    {
        return $this->create(self::BEARER, $jwt, $expiresAt);
    }

    /**
     * @param int|null $expiresAt timestamp Unix, ou null pour un cookie de session
     */
    public function xsrf(#[SensitiveParameter] string $token, ?int $expiresAt = null): Cookie
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
     *
     * @throws UnknownAuthCookieException
     */
    public function expired(string $name): Cookie
    {
        if (self::BEARER !== $name && self::XSRF_TOKEN !== $name) {
            throw UnknownAuthCookieException::forName($name);
        }

        return $this->create($name, null, 1);
    }

    /**
     * `$value` porte le JWT ou le jeton CSRF : caché des traces comme dans
     * bearer() et xsrf(), bien que son nom échappe au recensement de
     * {@see \App\Tests\Security\SecretParametersTest}, qui l'épingle
     * (issue #414).
     *
     * @param self::BEARER|self::XSRF_TOKEN $name
     */
    private function create(string $name, #[SensitiveParameter] ?string $value, ?int $expiresAt): Cookie
    {
        return Cookie::create($name, $value)
            ->withExpires($expiresAt ?? 0)
            ->withPath(self::PATH)
            ->withDomain(null)
            ->withSecure('dev' !== $this->environment)
            ->withHttpOnly(self::BEARER === $name)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
