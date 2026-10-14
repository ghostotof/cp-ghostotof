<?php

declare(strict_types=1);

namespace App\Security\User\Domain\ValueObject;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Sujet d'authentification du palier de base (ADR 0003 D6) : jamais persisté,
 * reconstruit à chaque requête depuis le seul contenu signé du JWT (cf.
 * {@see \App\Security\User\Infrastructure\Security\BaseAccessUserProvider}). Le rôle est
 * volontairement figé ici plutôt que lu depuis le jeton : même si un jeton
 * "guest" portait un jour des claims supplémentaires par erreur, cette classe
 * ne peut matériellement pas accorder plus que ROLE_USER.
 *
 * Nommée d'après l'accès de base du glossaire (CONTEXT.md, #602). Le claim
 * `guest` du jeton et le marqueur `guest-…` du journal gardent leur nom
 * d'avant : identifiants d'exploitation, sur lesquels filtrent le runbook et
 * l'historique des logs.
 */
final readonly class BaseAccessUser implements UserInterface
{
    /**
     * Claim booléen qui distingue un jeton du palier de base d'un jeton de
     * compte. Écrit par le contrôleur qui l'émet, lu par le provider qui le
     * reconnaît : un seul nom pour les deux.
     */
    public const string TOKEN_CLAIM = 'guest';

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
