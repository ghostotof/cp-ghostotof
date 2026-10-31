<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Ce que la seconde étape de `ApiPlatformOperationsCarryNoSecurityTest` a lu des propriétés des classes de la
 * première : leur nombre, les objets `App\` que leurs types ont fait entrer dans la file, et les constats (#748, R118).
 */
final readonly class CompiledPropertiesReading
{
    /**
     * @param int                       $properties    le nombre de propriétés parcourues
     * @param array<class-string, true> $nestedClasses les classes que seul le type d'une propriété a fait lire
     * @param list<string>              $found         les constats, « <classe>, propriété <nom> : <attribut> = <expression> »
     */
    public function __construct(
        public int $properties,
        public array $nestedClasses,
        public array $found,
    ) {
    }
}
