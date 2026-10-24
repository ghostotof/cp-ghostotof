<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use DateTimeImmutable;

/**
 * L'instant où la personne de la requête courante s'est authentifiée (#647,
 * F1 de la relecture), tel que le jeton **signé** de sa session le porte —
 * jamais une valeur que le client enverrait à part.
 *
 * Une interface plutôt que le jeton de Lexik : la couche Application ne
 * dépend ni du composant de sécurité ni du format du jeton
 * (Infrastructure\Security\JwtAuthenticationInstant).
 */
interface AuthenticationInstantInterface
{
    /**
     * L'instant d'authentification, ou null s'il ne peut être établi (aucun
     * jeton de session, jeton d'un autre type, instant absent ou illisible) :
     * l'appelant le tient alors pour trop ancien.
     */
    public function authenticatedAt(): ?DateTimeImmutable;
}
