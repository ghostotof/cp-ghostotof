<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

use LogicException;

/**
 * Un Processor qui agit au nom du compte connecté a été atteint sans le compte
 * qu'il attend (issue #338) : aucun compte, ou un compte d'un autre type.
 *
 * L'access_control (^/api/backoffice) exige un ROLE_SUPER en amont, donc un
 * CpgUser : en arriver là est un défaut de câblage, jamais un appel à laisser
 * passer. Pas une erreur du client (un anonyme reçoit 401 du pare-feu bien
 * avant) : 500 `critical`, sans `ProblemExceptionInterface`. Le message nomme
 * le Processor, le type attendu et ce qui a été reçu, pour que le journal
 * oriente vers le bon défaut — pare-feu sans jeton, ou fournisseur d'un autre
 * type de compte.
 *
 * Même idée que UnauthenticatedAssistantCallException (#323), laissée à part :
 * rien n'attrape un type commun aux deux.
 */
final class UnexpectedActingUserException extends LogicException
{
    /**
     * @param class-string $processor
     * @param class-string $expected
     */
    public static function inProcessor(string $processor, string $expected, ?object $received): self
    {
        return new self(\sprintf(
            '%s::process() exige un compte %s ; reçu : %s.',
            $processor,
            $expected,
            null === $received ? 'aucun compte' : $received::class,
        ));
    }
}
