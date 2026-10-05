<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;

/**
 * Requalifie en erreur du client ce que le Serializer refuse dans le corps
 * d'une requête (issue #355).
 *
 * Décore le DeserializeProvider d'API Platform, l'étape de la chaîne des
 * providers qui lit le corps de la requête. Pourquoi là et pas ailleurs :
 *
 *  - pas sur l'entrée large `Serializer\ExceptionInterface` de
 *    `framework.exceptions` : `instanceof` sur toutes les routes, elle
 *    baisserait aussi l'encodage de sortie (UTF-8 invalide en base), qui
 *    se fait dans la chaîne des *processors* (SerializeProcessor), donc hors de
 *    portée de ce décorateur par construction ;
 *  - pas sur le décodeur JSON du Serializer : il décode aussi des données
 *    internes, dont l'échec est un défaut du serveur ou d'un tiers.
 *
 * Seule réserve : DeserializeProvider appelle d'abord les providers qu'il
 * décore (lecture, providers de src/), et une exception du Serializer levée
 * par l'un d'eux serait requalifiée elle aussi. Aucun provider de src/ ne se
 * sert du Serializer aujourd'hui ; celui qui le ferait devra rattraper ses
 * propres échecs.
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
        try {
            return $this->inner->provide($operation, $uriVariables, $context);
        } catch (SerializerExceptionInterface $failure) {
            throw MalformedRequestBodyException::fromSerializerFailure($failure);
        }
    }
}
