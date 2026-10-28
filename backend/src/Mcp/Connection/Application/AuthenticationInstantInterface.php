<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use DateTimeImmutable;

/**
 * Ce que le jeton **signé** de la session de la requête courante dit de
 * l'authentification de la personne — jamais une valeur que le client enverrait
 * à part : l'instant où elle s'est authentifiée (#647, F1 de la relecture) et la
 * version des identifiants que son compte avait alors (#650, S1 de la seconde
 * relecture).
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

    /**
     * La version des identifiants du compte que le jeton de la session déclare
     * (revendication {@see \App\Shared\Domain\Security\CredentialsVersionClaim}),
     * ou null quand le jeton n'en porte pas ou n'est pas lisible : un jeton émis
     * avant le déploiement de S1, un jeton d'un autre type, un JWT devenu illisible.
     * `CpgUser::claimsAStaleCredentialsVersion()` tient null pour la version 0.
     */
    public function credentialsVersion(): ?int;
}
