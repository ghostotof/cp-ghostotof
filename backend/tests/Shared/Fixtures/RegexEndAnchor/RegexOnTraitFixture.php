<?php

declare(strict_types=1);

namespace App\Tests\Shared\Fixtures\RegexEndAnchor;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fixture de {@see \App\Tests\Shared\RegexEndAnchorTest} (issue #421) : une
 * contrainte posée sur la propriété d'un trait utilisé. La réflexion la montre
 * aussi dans la classe qui l'utilise : la garde ne doit la signaler qu'une
 * fois, sous la classe.
 */
trait RegexOnTraitFixture
{
    #[Assert\Regex(pattern: '/^[a-z]+\z/')]
    public string $code = '';
}
