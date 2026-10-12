<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Chemin de la requête tel que Symfony le voit pour router et sécuriser.
 *
 * `Request::getPathInfo()` renvoie le chemin NON décodé (documenté :
 * `/enco%20ded` → `'/enco%20ded'`), alors que le routeur (`UrlMatcher`), les
 * firewalls et l'`access_control` (`PathRequestMatcher`) décident tous sur
 * `rawurldecode()`. Un listener `kernel.request` qui compare le chemin brut
 * voit donc une autre route que Symfony : `POST /%61pi/logout` atteignait le
 * LogoutListener sans passer par la vérification CSRF, et
 * `/api/account/base%2Daccess` échappait au rate limiter (issue #77).
 *
 * Toute décision « ce chemin est-il /api/… ? » prise en dehors du routeur
 * doit passer par ici — jamais par `getPathInfo()` directement.
 *
 * `rawurldecode()` et non `urldecode()` : dans un chemin, `+` est un
 * caractère littéral (seul `%20` est une espace), exactement comme pour le
 * routeur. Rien n'est « nettoyé » au-delà du décodage : un chemin qui reste
 * inconnu après décodage doit rester un 404 du routeur, pas être réparé ici.
 */
final class CanonicalPath
{
    private const string API_PATH = '/api';

    private function __construct()
    {
    }

    public static function of(Request $request): string
    {
        return rawurldecode($request->getPathInfo());
    }

    /**
     * La requête vise-t-elle l'API ? Ancré sur « `/api` exactement, ou
     * `/api/…` », sur le chemin décodé : un `str_starts_with($path, '/api')`
     * nu attraperait aussi un futur `/apix` ou `/api-docs`, qui ne sont pas
     * l'API, et le chemin brut laisserait `/%61pi/…` passer à côté.
     */
    public static function isUnderApi(Request $request): bool
    {
        return self::isUnder($request, self::API_PATH);
    }

    /**
     * La requête vise-t-elle ce sous-arbre ? Même ancrage que `isUnderApi()`
     * (« `$prefix` exactement, ou `$prefix/…` », chemin décodé), pour
     * n'importe quel préfixe : c'est la règle des `access_control` (issue
     * #78), elle ne doit être écrite qu'ici (issue #322).
     *
     * @param string $prefix chemin absolu, sans `/` final (`/api/assistant`)
     *
     * @throws MalformedPathPrefixException sur un préfixe vide, relatif ou
     *                                      terminé par `/`
     */
    public static function isUnder(Request $request, string $prefix): bool
    {
        if (!str_starts_with($prefix, '/') || str_ends_with($prefix, '/')) {
            throw MalformedPathPrefixException::forPrefix($prefix);
        }

        $path = self::of($request);

        return $prefix === $path || str_starts_with($path, $prefix.'/');
    }
}
