<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;

/**
 * Le corps d'une requête à l'assistant dépasse la borne applicative (spec 0005
 * M4, 64 Kio) : 413 avec un `type` stable (`/errors/request-too-large`), rendu
 * par AssistantProblemResponseListener. Une borne de transport, pas une règle
 * du domaine : la conversation elle-même est bornée par ses VO (D6).
 *
 * Journalisée en `info` par le noyau : voir `framework.exceptions`.
 */
final class RequestBodyTooLargeException extends \RuntimeException implements ProblemExceptionInterface
{
    use HasProblemType;

    public function __construct()
    {
        parent::__construct('Le corps de la requête est trop volumineux.');
    }

    protected function problemType(): string
    {
        return 'request-too-large';
    }

    protected function problemStatus(): int
    {
        return 413;
    }
}
