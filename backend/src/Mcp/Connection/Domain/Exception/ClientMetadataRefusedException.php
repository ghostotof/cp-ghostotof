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
 * étant choisi par un tiers. L'exception précédente, quand il y en a une, est
 * celle du transport : elle reste chaînée pour le diagnostic, jamais lue pour
 * décider.
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
