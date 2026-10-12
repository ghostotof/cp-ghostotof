<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Presentation\ApiResource;

use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutMeCardOrderResource;
use App\Shared\Presentation\ApiResource\InputContradictsValidationException;
use PHPUnit\Framework\TestCase;

/**
 * `keys()` rend la liste telle que la validation la garantit (spec 0004 B4).
 * Une clé non textuelle qui l'atteint est un défaut du pipeline, jamais une
 * saisie (issue #338) : refusée sous un nom dédié plutôt que filtrée en
 * silence, ce qui réordonnerait un périmètre amputé.
 *
 * Le trait est exercé par l'une des neuf ressources qui l'utilisent, construite
 * sans passer par le validateur.
 */
final class CarriesOrderedKeysTest extends TestCase
{
    public function testTextualKeysComeBackInTheirOrder(): void
    {
        $resource = new BackofficeAboutMeCardOrderResource(category: 'hobby', groups: ['b', 'a']);

        self::assertSame(['b', 'a'], $resource->keys());
    }

    public function testANonTextualKeyIsRefused(): void
    {
        $this->expectException(InputContradictsValidationException::class);

        new BackofficeAboutMeCardOrderResource(category: 'hobby', groups: ['019968a0-0000-7000-8000-000000000001', 42])->keys();
    }
}
