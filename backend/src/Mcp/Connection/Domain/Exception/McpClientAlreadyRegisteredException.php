<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use DomainException;

/**
 * Un client MCP est déjà enregistré sous cet identifiant public.
 *
 * Message constant, qui ne cite pas l'identifiant : un message d'exception
 * peut finir dans un journal, et `app:mcp:client:register` n'a pas à répéter
 * une valeur que l'opérateur vient de taper.
 */
final class McpClientAlreadyRegisteredException extends DomainException
{
    public static function alreadyTaken(): self
    {
        return new self('Un client MCP est déjà enregistré sous cet identifiant.');
    }

    /**
     * L'identifiant a été pris entre la vérification et l'enregistrement, et la
     * contrainte unique de l'identifiant public l'a refusé : deux
     * enregistrements simultanés du même client.
     */
    public static function takenMeanwhile(): self
    {
        return new self('Un client MCP a été enregistré sous cet identifiant par une autre écriture, avant cet enregistrement.');
    }
}
