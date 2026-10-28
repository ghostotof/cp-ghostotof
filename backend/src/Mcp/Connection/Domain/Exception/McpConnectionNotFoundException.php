<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * La connexion MCP que la personne veut révoquer n'est pas à elle, ou
 * n'existe pas (#650) : un seul 404, **le même pour les deux** — que le
 * corps dise l'un ou l'autre ferait de l'API un oracle sur l'existence d'un
 * UUID de connexion d'un autre compte.
 *
 * Le message est une constante, sans identifiant ni compte : le `detail` est
 * rendu tel quel (ProblemDetailStaysStaticTest), et deux connexions inconnues
 * ne doivent pas se distinguer par lui. `log_level: info` : l'erreur d'un
 * client (une page restée ouverte sur une connexion déjà supprimée par la
 * purge), pas une alerte.
 */
final class McpConnectionNotFoundException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function forTheCaller(): self
    {
        return new self('Cette connexion MCP n\'existe pas, ou n\'est pas la vôtre.');
    }

    protected function problemType(): string
    {
        return 'mcp-connection-not-found';
    }

    protected function problemStatus(): int
    {
        return 404;
    }
}
