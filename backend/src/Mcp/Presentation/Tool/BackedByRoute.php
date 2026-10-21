<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use Attribute;

/**
 * Déclare la route qu'un outil MCP sert (ADR 0006 D1) : la ressource API
 * Platform dont il lit l'unique opération GET, et le palier de cette route.
 *
 * C'est ce que lit le test d'exposition symétrique : chaque outil déclare sa
 * route, qui doit exister au routeur avec ce palier exactement — un outil
 * adossé à une route du palier de base ne peut pas se dire anonyme —, et
 * chaque route de contenu des paliers servis sans connexion a son outil. Un
 * outil sans route figure dans la liste fermée de ce test, jamais ici.
 *
 * L'outil passe la même constante à cet attribut et à son lecteur de payload,
 * pour que la route déclarée soit celle qu'il lit.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class BackedByRoute
{
    /**
     * @param class-string $resourceClass ressource API Platform de la route
     */
    public function __construct(
        public string $resourceClass,
        public AccessTier $tier,
    ) {
    }
}
