<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Watch\Infrastructure\ApiPlatform\BackofficeWatchedProductProcessor;
use App\Portfolio\Watch\Infrastructure\ApiPlatform\BackofficeWatchedProductProvider;
use App\Portfolio\Watch\Infrastructure\Validator\WatchedProductSlugExists;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice). DTO séparé de
 * WatchResource, qui est le contrat public en lecture seule : celui-ci porte
 * l'id, le slug et la source de version, nécessaires au formulaire d'édition.
 *
 * `skip_null_values` à false pour que `version` reste présent — le formulaire
 * doit distinguer « pas de version stockée » (source runtime) d'un champ absent.
 */
#[ApiResource(
    shortName: 'BackofficeWatchedProduct',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/watch/products',
            provider: BackofficeWatchedProductProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/watch/products/{id}',
            provider: BackofficeWatchedProductProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/watch/products',
            processor: BackofficeWatchedProductProcessor::class,
            // La vérification du slug auprès du fournisseur n'a lieu qu'ici.
            // Le slug étant immuable, la refaire à chaque modification
            // n'apprendrait rien — et bloquerait l'édition d'une entrée dont le
            // produit aurait été retiré du catalogue entre-temps, ce qui arrive
            // (produits fusionnés ou renommés).
            validationContext: ['groups' => ['Default', self::CREATION_GROUP]],
        ),
        new Put(
            uriTemplate: '/backoffice/watch/products/{id}',
            provider: BackofficeWatchedProductProvider::class,
            processor: BackofficeWatchedProductProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/watch/products/{id}',
            provider: BackofficeWatchedProductProvider::class,
            processor: BackofficeWatchedProductProcessor::class,
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
)]
final class BackofficeWatchedProductResource
{
    /** Groupe de validation appliqué à la seule création (cf. l'opération Post). */
    public const string CREATION_GROUP = 'watched_product:create';

    public function __construct(
        public ?int $id = null,
        /**
         * Identifiant du produit chez endoflife.date. Le motif reprend ce que le
         * catalogue du fournisseur accepte réellement (minuscules, chiffres,
         * tirets, points) : une majuscule ou un espace produirait une entrée
         * « inconnue » sans autre explication.
         */
        #[Assert\NotBlank]
        #[Assert\Length(max: 60)]
        #[Assert\Regex(
            pattern: '/^[a-z0-9][a-z0-9.-]*$/',
            message: 'Le slug ne peut contenir que des minuscules, des chiffres, des tirets et des points.',
        )]
        #[WatchedProductSlugExists(groups: [self::CREATION_GROUP])]
        public string $slug = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $label = '',
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['manual', 'runtime_php', 'runtime_symfony'])]
        public string $versionSource = 'manual',
        /**
         * Obligatoire pour une source « manual », interdit pour les autres —
         * un invariant du domaine, vérifié par l'entité et rendu en 422.
         */
        #[Assert\Length(max: 30)]
        public ?string $version = null,
        #[Assert\PositiveOrZero]
        public int $position = 0,
    ) {
    }
}
