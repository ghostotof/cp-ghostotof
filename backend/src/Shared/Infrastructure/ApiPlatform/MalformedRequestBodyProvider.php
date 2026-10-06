<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Exception\UnsupportedFormatException;

/**
 * Requalifie en erreur du client ce que le Serializer refuse dans le corps
 * d'une requête (issue #355).
 *
 * Décore le DeserializeProvider d'API Platform, l'étape de la chaîne des
 * providers qui lit le corps de la requête. Pourquoi là et pas ailleurs :
 *
 *  - pas sur l'entrée large `Serializer\ExceptionInterface` de
 *    `framework.exceptions` : `instanceof` sur toutes les routes, elle
 *    baisserait aussi l'encodage de sortie (`NaN` ou `Infinity` d'une colonne
 *    `double precision`, rendus en 500 depuis l'issue #360), qui
 *    se fait dans la chaîne des *processors* (SerializeProcessor), donc hors de
 *    portée de ce décorateur par construction ;
 *  - pas sur le décodeur JSON du Serializer : il décode aussi des données
 *    internes, dont l'échec est un défaut du serveur ou d'un tiers.
 *
 * Ne sont requalifiées que les exceptions qu'un client peut provoquer, la
 * famille UnexpectedValueException du Serializer : JSON illisible
 * (NotEncodableValueException), racine qui n'est pas un objet
 * (NotNormalizableValueException) ; et deux classes précises de sa famille
 * RuntimeException (issue #360), qu'aucune opération ne déclenche
 * aujourd'hui mais qu'un réglage de contexte suffirait à rendre atteignables :
 * attribut inconnu quand `allow_extra_attributes` est faux
 * (ExtraAttributesException), argument de constructeur absent quand
 * `collect_denormalization_errors` est coupé
 * (MissingConstructorArgumentsException). Jamais `RuntimeException`
 * elle-même, que lèvent aussi des défauts du serveur. Un défaut du serveur levé pendant la même
 * étape — Serializer mal configuré (LogicException), métadonnées de mapping
 * (MappingException), format d'entrée négocié mais sans encodeur
 * (UnsupportedFormatException, sous-classe de NotEncodableValueException) —
 * traverse intact et reste un 500 `critical`.
 *
 * Seulement sur une opération qui désérialise : sur une lecture, une
 * exception du Serializer ne peut venir que d'ailleurs que de la requête.
 * Reste une réserve : sur une écriture, DeserializeProvider appelle d'abord les
 * providers qu'il décore (lecture de l'élément existant, providers de src/),
 * dont une exception du Serializer serait requalifiée elle aussi. Aucun
 * provider de src/ ne se sert du Serializer aujourd'hui.
 *
 * Les erreurs de type par champ ne passent pas ici : collectées
 * (`collect_denormalization_errors`), elles sortent de DeserializeProvider en
 * ValidationException, un 422 qui traverse ce décorateur sans changement.
 *
 * @implements ProviderInterface<object>
 */
#[AsDecorator(decorates: 'api_platform.state_provider.deserialize')]
final readonly class MalformedRequestBodyProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<object> $inner
     */
    public function __construct(
        #[AutowireDecorated]
        private ProviderInterface $inner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (true !== $operation->canDeserialize()) {
            return $this->inner->provide($operation, $uriVariables, $context);
        }

        try {
            return $this->inner->provide($operation, $uriVariables, $context);
        } catch (UnsupportedFormatException $serverFault) {
            throw $serverFault;
        } catch (UnexpectedValueException|ExtraAttributesException|MissingConstructorArgumentsException $failure) {
            throw MalformedRequestBodyException::fromSerializerFailure($failure);
        }
    }
}
