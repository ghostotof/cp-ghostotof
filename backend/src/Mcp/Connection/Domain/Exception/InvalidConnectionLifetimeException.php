<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use InvalidArgumentException;

/**
 * Une durée de la connexion MCP lue en configuration (#649, paramètres
 * `app.mcp.lifetime.*` de `config/services.yaml`) n'est pas admissible :
 * illisible, nulle, ou en contradiction avec une autre.
 *
 * Défaut de configuration, jamais une erreur client : levée à la construction
 * du service {@see \App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes},
 * aucune entrée `framework.exceptions`, un 500 `critical` au premier usage.
 *
 * Le message nomme le paramètre (`authorizationCode`, `accessToken`…), jamais
 * la valeur reçue ; l'exception native de PHP sur une durée illisible n'est
 * pas chaînée, son message citant la valeur.
 */
final class InvalidConnectionLifetimeException extends InvalidArgumentException
{
    public static function unreadable(string $parameter): self
    {
        return new self(\sprintf('La durée « %s » des connexions n\'est pas un intervalle ISO 8601 lisible.', $parameter));
    }

    public static function notPositive(string $parameter): self
    {
        return new self(\sprintf('La durée « %s » des connexions doit être strictement positive.', $parameter));
    }

    public static function idleExceedsMaximum(): self
    {
        return new self('Le délai d\'inactivité « idle » des connexions ne peut dépasser leur durée maximale « maximum ».');
    }

    public static function accessTokenOutlivesRefreshToken(): self
    {
        return new self('La durée « accessToken » ne peut dépasser celle du jeton de rafraîchissement « refreshToken ».');
    }
}
