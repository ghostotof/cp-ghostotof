<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use DomainException;

/**
 * L'identifiant à pré-enregistrer est l'URL de métadonnées d'un client CIMD
 * d'un domaine vérifié (#652).
 *
 * Une ligne pré-enregistrée l'emporte sur CIMD (règle D-C de l'admission) :
 * l'accepter masquerait le vrai client du domaine avant sa première
 * approbation, sous un nom et des redirections choisis par l'enregistrant.
 *
 * Message constant, qui ne cite pas l'identifiant : un message d'exception
 * peut finir dans un journal, et `app:mcp:client:register` n'a pas à répéter
 * une valeur que l'opérateur vient de taper. Le backoffice rend ce refus par
 * une violation 422 au chemin `clientId` (`ValidMcpClientFieldsValidator`) ;
 * cette exception couvre ce que le validateur ne voit pas (le CLI), et reste
 * un 422 si elle atteignait l'API.
 */
final class McpClientIdOfVerifiedDomainException extends DomainException
{
    public static function cannotBePreregistered(): self
    {
        return new self('Cet identifiant est celui d\'un client CIMD d\'un domaine vérifié : il ne se pré-enregistre pas.');
    }
}
