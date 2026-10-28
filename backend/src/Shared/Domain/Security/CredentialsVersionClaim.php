<?php

declare(strict_types=1);

namespace App\Shared\Domain\Security;

/**
 * Le nom de la revendication du JWT de session qui porte la **version des
 * identifiants** du compte (#650, S1 de la seconde relecture) : posée à la
 * création du jeton ({@see \App\Security\Authentication\Infrastructure\Jwt\CredentialsVersionClaimListener}),
 * lue à chaque requête de l'API ({@see \App\Security\Authentication\Infrastructure\Security\SessionBeforePasswordChangeRefusalListener})
 * et par le consentement MCP ({@see \App\Mcp\Connection\Infrastructure\Security\JwtAuthenticationInstant}).
 *
 * Dans `Shared` pour que les deux contextes citent le même nom sans que l'un
 * importe l'autre : une revendication lue ici et écrite là, de deux orthographes,
 * refuserait ou laisserait passer toutes les sessions.
 *
 * **Court et neutre** : le JWT est lisible par qui détient le cookie (httpOnly,
 * mais un JWT n'est qu'encodé, pas chiffré). `cv` ne dit pas ce qu'elle protège,
 * et sa valeur — un compte de changements de mot de passe — ne dit rien du secret.
 */
final class CredentialsVersionClaim
{
    public const string NAME = 'cv';
}
