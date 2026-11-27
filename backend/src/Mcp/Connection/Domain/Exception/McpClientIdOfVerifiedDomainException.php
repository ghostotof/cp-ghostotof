<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
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
 *
 * Elle sert aussi à la révision (#652) : une ligne pré-enregistrée de cet
 * identifiant, écrite avant que son domaine entre dans la liste, masque déjà le
 * vrai client ; lui laisser changer ses redirections l'entretiendrait. Elle se
 * supprime, elle ne se révise pas ({@see self::cannotBeRevised()}).
 *
 * Ses deux fabriques portent le type problem+json
 * `/errors/client-id-of-verified-domain` (422, qui concorde avec son entrée de
 * `exception_to_status`), pour que le frontend distingue ce refus d'un 422 de
 * validation ordinaire.
 */
final class McpClientIdOfVerifiedDomainException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function cannotBePreregistered(): self
    {
        return new self('Cet identifiant est celui d\'un client CIMD d\'un domaine vérifié : il ne se pré-enregistre pas.');
    }

    public static function cannotBeRevised(): self
    {
        return new self('Ce client masque le client CIMD d\'un domaine vérifié : il ne se révise pas, il se supprime.');
    }

    protected function problemType(): string
    {
        return 'client-id-of-verified-domain';
    }

    protected function problemStatus(): int
    {
        return 422;
    }
}
