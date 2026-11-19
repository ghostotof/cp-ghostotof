<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * Un client MCP déclaré par son document de métadonnées (CIMD, #651) ne se
 * révise pas à la main : son nom et ses redirections sont ceux de son document,
 * qui fait foi et que le dépôt rafraîchit ; une révision manuelle serait
 * écrasée au prochain rafraîchissement.
 *
 * Message constant, qui ne cite ni l'identifiant ni le nom du client : un
 * message d'exception peut finir dans un journal.
 */
final class McpClientDeclaredByDocumentException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function cannotBeRevised(): self
    {
        return new self('Un client MCP déclaré par son document de métadonnées ne peut pas être révisé : son document fait foi.');
    }

    protected function problemType(): string
    {
        return 'client-declared-by-document';
    }

    protected function problemStatus(): int
    {
        return 409;
    }
}
