<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\EnvironmentServices;

use Doctrine\ORM\Event\PostLoadEventArgs;

/**
 * Fixture de SecurityServicesEnvironmentParityTest (relecture de #698) : un
 * écouteur Doctrine `postLoad` déclaré pour `prod` seul, qui pourrait
 * réécrire les rôles d'un compte chargé (mesuré par l'audit). Il ne réécrit
 * rien : seule sa déclaration compte. Jamais chargé hors de ce test.
 */
final class RolesRewritingDoctrineListener
{
    public function postLoad(PostLoadEventArgs $event): void
    {
        $event->getObject();
    }
}
