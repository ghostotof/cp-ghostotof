<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Portfolio\Watch\Infrastructure\ApiPlatform\WatchProvider;

/**
 * État de la veille technique, servi à la page /stack.
 *
 * Public : ces données ne sont pas personnelles identifiantes (objectif n°9 du
 * projet) — ce sont les versions d'une stack et leurs échéances de support,
 * c'est-à-dire du contenu de démonstration. L'entrée correspondante figure
 * dans PUBLIC_PATHS d'ApiRouteExposureTest avec sa justification.
 *
 * La ressource est lue **exclusivement** dans le snapshot local (décision D5) :
 * servir cette page ne déclenche aucun appel sortant, si bien qu'une panne
 * d'endoflife.date ne peut ni la ralentir ni la casser. Un test le vérifie en
 * remplaçant le client HTTP par un mock qui échoue au moindre appel.
 *
 * Pas de segment {locale}, contrairement aux autres ressources publiques du
 * portfolio : une version installée est un fait, pas une traduction (D6). Les
 * libellés d'interface sont pris en charge côté frontend.
 *
 * **Structure par volet**, et non à plat : le second volet, les vulnérabilités
 * connues, portera sa propre date de vérification. Grouper dès maintenant évite
 * d'avoir à casser ce contrat pour l'accueillir, et surtout d'avoir à choisir
 * lequel des deux instantanés une date unique décrirait.
 */
#[ApiResource(
    shortName: 'Watch',
    operations: [
        new Get(
            uriTemplate: '/watch',
            provider: WatchProvider::class,
        ),
    ],
    // Sans cela, API Platform élide les champs nuls : sur une installation
    // jamais rafraîchie, la réponse se réduirait à {"products":[]} et le client
    // devrait déduire l'état « jamais rafraîchi » d'une clé absente. Le contrat
    // reste stable, les champs sont toujours là — explicitement nuls.
    normalizationContext: ['skip_null_values' => false],
)]
final readonly class WatchResource
{
    public function __construct(
        public WatchReleaseCyclesResource $releaseCycles,
    ) {
    }
}
