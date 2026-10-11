<?php

declare(strict_types=1);

namespace App\Tests\Shared\Fixtures\RegexEndAnchor;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fixture de {@see \App\Tests\Shared\RegexEndAnchorTest} (issue #421) : une
 * contrainte posée sur une méthode d'interface, que le Validator applique à
 * chaque implémentation, et qu'aucune classe ne porte elle-même. Un getter
 * (`get…`, `is…`, `has…`) : le Validator refuse une contrainte sur toute
 * autre méthode.
 */
interface RegexOnInterfaceFixture
{
    #[Assert\Regex(pattern: '/^[a-z]+\z/')]
    public function getSlug(): string;
}
