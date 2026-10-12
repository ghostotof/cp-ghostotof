<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Domain\Exception;

use App\Security\User\Domain\Exception\ActingAccountNoLongerSuperAdminException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issue #551 : l'acteur relu sous le verrou d'administration n'est plus
 * ROLE_SUPER, ou n'existe plus. Un 403 — l'autorisation n'existe plus, comme
 * le pare-feu le dira à la requête suivante —, sous un `type` stable que le
 * frontend pourrait lire, et un `detail` qui ne cite aucun compte (#363).
 * Injoignable par une requête HTTP de test (le pare-feu recharge le compte à
 * chaque requête, un acteur rétrogradé entre deux requêtes reçoit son 403) :
 * la réponse est épinglée par l'exception et par le mapping compilé.
 */
final class ActingAccountNoLongerSuperAdminExceptionTest extends KernelTestCase
{
    public function testItIsAForbiddenWithAStableType(): void
    {
        foreach ([ActingAccountNoLongerSuperAdminException::demotedMeanwhile(), ActingAccountNoLongerSuperAdminException::deletedMeanwhile()] as $exception) {
            self::assertSame(403, $exception->getStatus());
            self::assertSame('/errors/acting-account-no-longer-super', $exception->getType());
            self::assertSame($exception->getMessage(), $exception->getDetail());
        }
    }

    public function testApiPlatformRendersItAs403(): void
    {
        self::bootKernel();
        /** @var array<class-string, int> $mapping */
        $mapping = self::getContainer()->getParameter('api_platform.exception_to_status');

        self::assertSame(403, $mapping[ActingAccountNoLongerSuperAdminException::class] ?? null, 'Sans entrée dans exception_to_status, API Platform rendrait un 500.');
    }

    /**
     * Les deux fabriques servent le journal du noyau ; aucune ne prend de
     * compte en argument, aucun message ne peut donc en citer un.
     */
    public function testNoMessageQuotesAnAccount(): void
    {
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}|@/', ActingAccountNoLongerSuperAdminException::demotedMeanwhile()->getMessage());
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}|@/', ActingAccountNoLongerSuperAdminException::deletedMeanwhile()->getMessage());
    }
}
