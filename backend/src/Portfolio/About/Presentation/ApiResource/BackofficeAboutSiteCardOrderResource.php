<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutSiteCardOrderProcessor;
use App\Portfolio\Shared\Presentation\ApiResource\CarriesOrderedKeys;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/backoffice/about/site-cards/order — l'unique écrivain de la position
 * des cartes « site » de la page À propos (spec 0004 D3), réservé ROLE_SUPER par la règle
 * `^/api/backoffice(/|$)` de security.yaml.
 *
 * Mêmes choix que BackofficeIncidentOrderResource, qui les documente : `read: false`
 * (rien à lire avant d'écrire), `output: false` / `status: 204`, aucune propriété
 * `id` donc aucune route d'item synthétisée, et `Sequentially` dans le `All` pour
 * qu'une valeur non textuelle soit un 422 et non le 500 d'`Assert\Uuid`.
 */
#[ApiResource(
    shortName: 'BackofficeAboutSiteCardOrder',
    operations: [
        new Put(
            uriTemplate: '/backoffice/about/site-cards/order',
            status: 204,
            read: false,
            output: false,
            processor: BackofficeAboutSiteCardOrderProcessor::class,
        ),
    ],
)]
final readonly class BackofficeAboutSiteCardOrderResource
{
    use CarriesOrderedKeys;

    /**
     * Entrée non fiable tant que la validation n'a pas tranché : les éléments
     * peuvent être de n'importe quel type. `keys()` rend la forme garantie.
     *
     * @param array<int|string, mixed> $groups
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\All([new Assert\Sequentially([new Assert\Type('string'), new Assert\Uuid()])])]
        #[Assert\Unique]
        public array $groups = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return self::validatedKeys($this->groups);
    }
}
