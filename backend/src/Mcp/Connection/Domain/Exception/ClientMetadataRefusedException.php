<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;
use RuntimeException;
use Throwable;

/**
 * Le document de métadonnées d'un client CIMD n'a pas été admis (#651).
 *
 * Le message ne porte que la valeur de la cause
 * ({@see ClientMetadataRefusal}), un mot stable : ni l'URL du `client_id`, ni
 * l'adresse résolue, ni rien de ce que l'hôte distant a répondu, tout cela
 * étant choisi par un tiers. L'exception précédente, quand il y en a une, ne
 * porte rien d'un tiers non plus : pour le transport, c'est
 * {@see \App\Mcp\Connection\Infrastructure\Cimd\ClientMetadataTransportFailedException},
 * au message constant ; elle sert au diagnostic, jamais à décider.
 *
 * N'implémente pas `ProblemExceptionInterface`, volontairement : la règle des
 * exceptions (`.claude/rules/backend-conventions.md`) réserve l'interface aux
 * erreurs que l'API rend en `application/problem+json` par un mapping. Celle-ci
 * ne sort jamais d'une route de l'API : le point d'autorisation OAuth la
 * convertit en `OAuthServerException` (plan de la tâche 12, Task 9, Step 3),
 * dont la réponse suit le protocole OAuth, et elle n'a ni `type` ni `detail`
 * de problème à porter. Rendue telle quelle, faute de conversion, elle reste
 * un 500 `critical`, ce qui est le comportement voulu d'un défaut de câblage.
 */
final class ClientMetadataRefusedException extends RuntimeException
{
    private function __construct(public readonly ClientMetadataRefusal $refusal, ?Throwable $previous)
    {
        parent::__construct($refusal->value, 0, $previous);
    }

    public static function because(ClientMetadataRefusal $refusal, ?Throwable $previous = null): self
    {
        return new self($refusal, $previous);
    }
}
