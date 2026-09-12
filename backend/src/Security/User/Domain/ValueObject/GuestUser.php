<?php

declare(strict_types=1);

namespace App\Security\User\Domain\ValueObject;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Sujet d'authentification du palier de base (ADR 0003 D6) : jamais persisté,
 * reconstruit à chaque requête depuis le seul contenu signé du JWT (cf.
 * App\Security\User\Infrastructure\Security\GuestUserProvider). Le rôle est
 * volontairement figé ici plutôt que lu depuis le jeton : même si un jeton
 * "guest" portait un jour des claims supplémentaires par erreur, cette classe
 * ne peut matériellement pas accorder plus que ROLE_USER.
 */
final readonly class GuestUser implements UserInterface
{
    public function __construct(private string $identifier)
    {
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getUserIdentifier(): string
    {
        // Le constructeur ne garantit rien structurellement, mais
        // BaseAccessController n'appelle jamais ce constructeur avec une
        // chaîne vide (UserInterface::getUserIdentifier() attend un
        // non-empty-string).
        \assert('' !== $this->identifier);

        return $this->identifier;
    }
}
