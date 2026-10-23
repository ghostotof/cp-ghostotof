<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use InvalidArgumentException;

/**
 * Une redirection proposée à l'enregistrement d'un client MCP n'est pas de
 * celles que {@see \App\Mcp\Connection\Domain\ValueObject\RedirectUri} admet :
 * `https` sans fragment ni identifiants, ou
 * `http` sur la seule boucle locale (`localhost`, `127.0.0.1`, `[::1]`).
 *
 * Levée aujourd'hui par la seule commande `app:mcp:client:register`, qui la
 * rattrape : aucune entrée `framework.exceptions`. Le message ne cite jamais la
 * valeur, que l'opérateur a sous les yeux — celui de League\Uri la cite, il
 * n'est pas repris en `previous`.
 */
final class InvalidRedirectUriException extends InvalidArgumentException
{
    public static function unparsable(): self
    {
        return new self('La redirection n\'est pas une URI lisible.');
    }

    /**
     * La redirection n'est pas écrite comme {@see \League\Uri\Uri} la rend (casse du schéma
     * ou de l'hôte, caractère à encoder, port vide, nom de domaine international
     * non converti). La comparaison `https` étant une égalité stricte de la
     * chaîne, seule la forme normalisée est admise : c'est celle qu'un client
     * conforme envoie.
     */
    public static function notNormalized(): self
    {
        return new self('La redirection n\'est pas écrite sous sa forme normalisée (schéma et hôte en minuscules, caractères encodés, aucun port vide).');
    }

    public static function unsupportedScheme(): self
    {
        return new self('La redirection doit être en https, ou en http sur la boucle locale ; les schémas privés (RFC 8252 §7.1) ne sont pas admis.');
    }

    public static function withoutHost(): self
    {
        return new self('La redirection doit nommer un hôte.');
    }

    public static function plainHttpOutsideLoopback(): self
    {
        return new self('Une redirection en http n\'est admise que sur la boucle locale : localhost, 127.0.0.1 ou [::1].');
    }

    public static function withUserInfo(): self
    {
        return new self('La redirection ne doit porter aucun identifiant (partie « utilisateur@ »).');
    }

    public static function withFragment(): self
    {
        return new self('La redirection ne doit porter aucun fragment (« # »).');
    }
}
