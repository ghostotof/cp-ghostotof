<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

/**
 * Un Processor qui agit au nom du compte connecté a été atteint sans compte
 * authentifié (issue #338).
 *
 * L'access_control (^/api/backoffice) exige l'authentification en amont : en
 * arriver là est un défaut de câblage, jamais un appel à laisser passer
 * anonymement. Pas une erreur du client (un anonyme reçoit 401 du pare-feu
 * bien avant) : 500 `critical`, sans `ProblemExceptionInterface`. Le message
 * nomme le Processor, la garde étant partagée par plusieurs contextes.
 *
 * Même idée que UnauthenticatedAssistantCallException (#323), laissée à part :
 * rien n'attrape un type commun aux deux.
 */
final class UnauthenticatedProcessorCallException extends \LogicException
{
    /**
     * @param class-string $processor
     */
    public static function in(string $processor): self
    {
        return new self(\sprintf('%s::process() appelé sans utilisateur authentifié.', $processor));
    }
}
