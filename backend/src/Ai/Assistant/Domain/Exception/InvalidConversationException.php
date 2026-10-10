<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * La conversation reçue ne respecte pas ses invariants : 422 avec un `type`
 * stable (`/errors/invalid-conversation`). La route n'étant pas une opération
 * API Platform, c'est ApiProblemResponseListener (Shared) qui la rend, grâce à
 * ProblemExceptionInterface. Le message ne cite jamais le contenu reçu.
 *
 * Journalisée en `info` par le noyau : voir `framework.exceptions`.
 */
final class InvalidConversationException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    protected function problemType(): string
    {
        return 'invalid-conversation';
    }

    protected function problemStatus(): int
    {
        return 422;
    }
}
