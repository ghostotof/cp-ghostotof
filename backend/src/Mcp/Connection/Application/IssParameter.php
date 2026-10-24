<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

/**
 * Le paramètre `iss` (RFC 9207) de toute redirection vers un client (#647) :
 * il nomme le serveur d'autorisation qui répond, pour qu'un client parlant à
 * plusieurs serveurs ne prenne pas la réponse de l'un pour celle de l'autre
 * (attaque par confusion de serveurs, « mix-up »). Les métadonnées l'annoncent
 * (`authorization_response_iss_parameter_supported`) : un client conforme
 * refuse alors une réponse qui ne le porte pas, erreur comprise.
 *
 * Écrit une fois, pour les refus du point d'autorisation et pour la réponse du
 * consentement (tâche 6). Une fonction pure, sans état : rien à injecter.
 */
final class IssParameter
{
    private function __construct()
    {
    }

    /**
     * Ajoute `iss=<émetteur>` à la requête de `$location`, après les
     * paramètres présents, gardés tels quels. L'émetteur est encodé comme
     * league encode les autres (`http_build_query`). Un fragment, qu'aucune
     * redirection de ce serveur ne porte (une redirection enregistrée n'en a
     * pas, `RedirectUri`), resterait en dernier : la requête précède toujours
     * le fragment (RFC 3986 §3), un `?` situé dans le fragment n'en ouvre pas.
     */
    public static function append(string $location, string $issuer): string
    {
        $fragmentAt = strpos($location, '#');
        $beforeFragment = false === $fragmentAt ? $location : substr($location, 0, $fragmentAt);
        $fragment = false === $fragmentAt ? '' : substr($location, $fragmentAt);
        $separator = str_contains($beforeFragment, '?') ? '&' : '?';

        return $beforeFragment.$separator.http_build_query(['iss' => $issuer]).$fragment;
    }
}
