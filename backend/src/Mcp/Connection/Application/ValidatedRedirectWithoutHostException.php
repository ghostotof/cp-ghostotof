<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use LogicException;

/**
 * Une redirection que league a validée, mais dont League\Uri ne lit pas
 * d'hôte (#647). Impossible tant que McpClient::allowsRedirect() juge les
 * redirections : `RedirectUri::matches()` n'admet qu'une URI lisible, sous
 * sa forme normalisée, égale (au port près) à une redirection enregistrée,
 * qui a un hôte. Un invariant cassé, donc une faute du serveur : un 500
 * `critical`, sans entrée de journalisation propre.
 */
final class ValidatedRedirectWithoutHostException extends LogicException
{
    public static function create(): self
    {
        return new self('A redirect URI validated by the authorization server has no readable host.');
    }
}
