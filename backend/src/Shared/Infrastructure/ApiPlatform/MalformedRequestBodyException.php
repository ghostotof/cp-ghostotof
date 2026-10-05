<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

/**
 * Le corps d'une requête adressée à une opération API Platform n'a pas pu être
 * désérialisé : JSON illisible, ou JSON valide dont la racine n'est pas un
 * objet (`123`, `null`, `"x"`). Une erreur du client, rendue en 400 (issue #355).
 *
 * Elle remplace, à la seule frontière de la désérialisation, l'exception du
 * Serializer que rattrapait l'entrée large `Serializer\ExceptionInterface` de
 * `exception_to_status`. Le noyau journalisait celle-ci en `critical`, et
 * baisser son niveau aurait baissé aussi un JSON **de sortie** non encodable,
 * qui est un défaut du serveur. Une classe précise, elle, reçoit son propre
 * `log_level` (framework.yaml) sans rien entraîner d'autre.
 *
 * Le message est fixe : celui du Serializer cite parfois la classe de la
 * ressource visée. La cause reste chaînée (getPrevious()) pour le journal.
 */
final class MalformedRequestBodyException extends \InvalidArgumentException
{
    public static function fromSerializerFailure(\Throwable $failure): self
    {
        return new self('Le corps de la requête n\'est pas un document JSON exploitable.', previous: $failure);
    }
}
