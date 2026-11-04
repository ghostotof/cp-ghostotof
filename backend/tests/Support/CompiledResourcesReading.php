<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Ce que la première étape de `ApiPlatformOperationsCarryNoSecurityTest` a lu des métadonnées compilées :
 * les ressources et leurs opérations, les classes dont lire ensuite les propriétés, et les constats (#748, R118).
 *
 * Un objet plutôt qu'un tableau à clés nommées et un paramètre par référence : le test nomme ce qu'il lit,
 * et un constat ne se perd pas dans une variable que l'appelant aurait oublié de passer.
 */
final readonly class CompiledResourcesReading
{
    /**
     * @param array<string, true>       $classes    les classes dont lire les propriétés : les ressources, puis leurs DTO
     * @param array<class-string, true> $dtoClasses les seules classes `input` et `output` de ces ressources et opérations
     * @param int                       $operations le nombre d'opérations parcourues
     * @param int                       $links      le nombre de liens (`uriVariables` compilés) parcourus
     * @param list<string>              $found      les constats, « <contexte> : <attribut> = <expression> »
     */
    public function __construct(
        public array $classes,
        public array $dtoClasses,
        public int $operations,
        public int $links,
        public array $found,
    ) {
    }
}
