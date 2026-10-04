<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\RateLimiter;

/**
 * L'assistant a été appelé sans compte authentifié (issue #323). L'access_control
 * réserve la route à ROLE_TRUSTED, donc arriver jusqu'au quota sans compte est
 * un défaut de câblage — jamais un appel à laisser passer sans le compter.
 *
 * Pas une erreur du client (un anonyme reçoit 401/403 du pare-feu bien avant) :
 * elle sort en 500 et n'implémente pas ProblemExceptionInterface. Elle étend
 * \LogicException parce que c'en est une, sous un nom qui se cible dans
 * `framework.exceptions` et se reconnaît dans les journaux. Message littéral.
 */
final class UnauthenticatedAssistantCallException extends \LogicException
{
    public function __construct()
    {
        parent::__construct("L'assistant exige un compte authentifié.");
    }
}
