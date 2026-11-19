<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * Le client MCP que l'administrateur veut réviser ou supprimer n'existe pas
 * (#652) : jamais enregistré, ou supprimé entre l'affichage de la liste et
 * l'action (une autre session, la découverte qui le recrée sous un autre
 * identifiant).
 *
 * Le message est une constante, sans identifiant : le `detail` est rendu tel
 * quel (ProblemDetailStaysStaticTest). `log_level: info` : l'erreur d'une
 * page restée ouverte, pas une alerte.
 */
final class McpClientNotFoundException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function withId(): self
    {
        return new self('Ce client MCP n\'existe pas, ou n\'existe plus.');
    }

    protected function problemType(): string
    {
        return 'mcp-client-not-found';
    }

    protected function problemStatus(): int
    {
        return 404;
    }
}
