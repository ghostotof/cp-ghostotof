<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Incident\Infrastructure\ApiPlatform\BackofficeIncidentOrderProcessor;
use App\Portfolio\Shared\Presentation\ApiResource\CarriesOrderedKeys;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/backoffice/incidents/order — l'unique écrivain de la position des
 * incidents (spec 0004 D3), réservé ROLE_SUPER par la règle
 * `^/api/backoffice(/|$)` de security.yaml.
 *
 * Le littéral `order` ne peut pas être capturé par `PUT …/{id}` : cette
 * opération-là déclare `requirements: ['id' => Requirement::UUID]` depuis la
 * spec 0003, ce que ItemRouteRequirementTest pince pour toutes les routes
 * d'item.
 *
 * `read: false` : le DTO n'est mappé sur aucune entité Doctrine et l'opération
 * ne vise pas une ressource identifiée — il n'y a rien à lire avant d'écrire.
 * `output: false` / `status: 204` : la réponse ne porte rien, le frontend
 * recharge la liste (D6).
 *
 * Aucune propriété `id` ici, donc aucune route d'item synthétisée par API
 * Platform pour construire des IRI (le piège de BackofficeVulnerabilityResource
 * et de l'audit C6) — `debug:router` le confirme.
 */
#[ApiResource(
    shortName: 'BackofficeIncidentOrder',
    operations: [
        new Put(
            uriTemplate: '/backoffice/incidents/order',
            status: 204,
            read: false,
            output: false,
            processor: BackofficeIncidentOrderProcessor::class,
        ),
    ],
)]
final readonly class BackofficeIncidentOrderResource
{
    use CarriesOrderedKeys;

    /**
     * Entrée non fiable tant que la validation n'a pas tranché : les éléments
     * peuvent être de n'importe quel type. `keys()` rend la forme garantie.
     *
     * `Sequentially` plutôt qu'une simple liste de contraintes : `Assert\Uuid`
     * lève une UnexpectedValueException — donc un 500 — sur une valeur qui
     * n'est ni scalaire ni Stringable. S'arrêter au `Type` garde le refus en
     * 422.
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
