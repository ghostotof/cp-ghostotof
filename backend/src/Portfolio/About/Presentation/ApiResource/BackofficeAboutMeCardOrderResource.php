<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutMeCardOrderProcessor;
use App\Portfolio\Shared\Presentation\ApiResource\CarriesOrderedKeys;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/backoffice/about/me-cards/order — l'unique écrivain de la position
 * des cartes « à propos de moi » (spec 0004 D3), réservé ROLE_SUPER par la
 * règle `^/api/backoffice(/|$)` de security.yaml.
 *
 * La seule des neuf ressources d'ordre à porter un second champ. Le périmètre
 * d'ordre de ces cartes est la **catégorie**, toutes langues confondues (le
 * tri de lecture est `(locale, category, position)`) : `groups` doit donc être
 * exactement l'ensemble des groupes de cette catégorie, et les deux autres ne
 * sont ni lues ni écrites. Sans ce champ, la règle d'ensemble exact (D4)
 * refuserait toute liste, chaque catégorie paraissant incomplète face au total.
 *
 * Mêmes choix que BackofficeIncidentOrderResource, qui les documente.
 */
#[ApiResource(
    shortName: 'BackofficeAboutMeCardOrder',
    operations: [
        new Put(
            uriTemplate: '/backoffice/about/me-cards/order',
            status: 204,
            read: false,
            output: false,
            processor: BackofficeAboutMeCardOrderProcessor::class,
        ),
    ],
)]
final readonly class BackofficeAboutMeCardOrderResource
{
    use CarriesOrderedKeys;

    /**
     * Entrée non fiable tant que la validation n'a pas tranché : les éléments
     * peuvent être de n'importe quel type. `keys()` rend la forme garantie.
     *
     * `category` est nullable + NotNull (même patron que
     * BackofficeUserRoleResource) : un corps qui l'omet échoue en 422 plutôt
     * que de réordonner une catégorie choisie par défaut. Le `Choice` pointe
     * sur l'enum, jamais sur une liste littérale.
     *
     * @param array<int|string, mixed> $groups
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Choice(callback: [AboutMeCardCategory::class, 'values'])]
        public ?string $category = null,
        #[Assert\NotBlank]
        #[Assert\All([new Assert\Sequentially([new Assert\Type('string'), new Assert\Uuid()])])]
        #[Assert\Unique]
        public array $groups = [],
    ) {
    }

    /**
     * La catégorie telle que la validation la garantit. `from()` et non
     * `fromString()` : la valeur est bornée en amont par `Assert\Choice`, une
     * `\ValueError` ici serait un vrai défaut, pas une saisie (règle d'audit I3).
     */
    public function validatedCategory(): AboutMeCardCategory
    {
        if (null === $this->category) {
            throw new \LogicException('Une catégorie absente aurait dû être refusée par la validation.');
        }

        return AboutMeCardCategory::from($this->category);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return self::validatedKeys($this->groups);
    }
}
