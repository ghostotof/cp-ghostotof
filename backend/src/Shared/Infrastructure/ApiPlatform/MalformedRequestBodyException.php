<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use UnexpectedValueException as NativeUnexpectedValueException;

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
 * {@see NativeUnexpectedValueException} plutôt qu'{@see \InvalidArgumentException} : la SPL
 * range la seconde parmi les erreurs de programmation, alors qu'il s'agit ici
 * d'une donnée reçue à l'exécution. La classe native passe par l'alias
 * NativeUnexpectedValueException parce que le nom court UnexpectedValueException
 * est déjà celui du Serializer, que fromSerializerFailure() reçoit.
 *
 * Le message est fixe : celui du Serializer cite parfois la classe de la
 * ressource visée. La cause reste chaînée (getPrevious()) pour le journal.
 */
final class MalformedRequestBodyException extends NativeUnexpectedValueException
{
    public static function fromSerializerFailure(UnexpectedValueException|ExtraAttributesException|MissingConstructorArgumentsException $failure): self
    {
        return new self('Le corps de la requête n\'est pas un document JSON exploitable.', previous: $failure);
    }
}
