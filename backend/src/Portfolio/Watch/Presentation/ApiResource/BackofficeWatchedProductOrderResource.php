<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Shared\Presentation\ApiResource\CarriesOrderedKeys;
use App\Portfolio\Watch\Infrastructure\ApiPlatform\BackofficeWatchedProductOrderProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/backoffice/watch/products/order — l'ordre du catalogue de veille,
 * réservé ROLE_SUPER par la règle `^/api/backoffice(/|$)` de security.yaml.
 *
 * Le champ s'appelle `ids` et non `groups` : `WatchedProduct` n'a pas de
 * groupe de traduction, et c'est délibéré — un numéro de version est un fait,
 * pas une traduction, ce contexte n'est pas localisé. La clé d'ordre y est
 * donc l'id de l'entrée. Le nom du champ dit laquelle des deux on envoie
 * plutôt que de laisser croire à un groupe qui n'existe pas.
 *
 * L'ordre ne se relit pas côté public : `GET /api/watch` sert un agrégat, la
 * collection de backoffice est le seul lecteur.
 *
 * Mêmes choix que BackofficeIncidentOrderResource, qui les documente.
 */
#[ApiResource(
    shortName: 'BackofficeWatchedProductOrder',
    operations: [
        new Put(
            uriTemplate: '/backoffice/watch/products/order',
            status: 204,
            read: false,
            output: false,
            processor: BackofficeWatchedProductOrderProcessor::class,
        ),
    ],
)]
final readonly class BackofficeWatchedProductOrderResource
{
    use CarriesOrderedKeys;

    /**
     * Entrée non fiable tant que la validation n'a pas tranché : les éléments
     * peuvent être de n'importe quel type. `keys()` rend la forme garantie.
     *
     * @param array<int|string, mixed> $ids
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\All([new Assert\Sequentially([new Assert\Type('string'), new Assert\Uuid()])])]
        #[Assert\Unique]
        public array $ids = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return self::validatedKeys($this->ids);
    }
}
