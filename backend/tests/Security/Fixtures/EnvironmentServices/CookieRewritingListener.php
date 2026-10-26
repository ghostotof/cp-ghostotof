<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\EnvironmentServices;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Fixture de SecurityServicesEnvironmentParityTest (issue #698) : un écouteur
 * `kernel.response` hors de `App\Security\`, déclaré pour `prod` seul — la
 * forme que redoutait le commentaire de #714, qui réécrirait le cookie
 * `BEARER` sans `Secure`. Le corps reste vide : seule sa déclaration compte.
 * Jamais chargé hors de ce test.
 */
final class CookieRewritingListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        $event->getResponse();
    }
}
