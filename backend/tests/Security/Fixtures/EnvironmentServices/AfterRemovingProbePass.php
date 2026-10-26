<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\EnvironmentServices;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fixture de SecurityServicesEnvironmentParityTest (relecture de #698) : une
 * passe enregistrée après l'instantané (`TYPE_AFTER_REMOVING`), donc hors de
 * sa vue. Elle ne fait rien ; seule sa présence compte : la garde n'admet
 * après l'instantané que les passes d'une liste figée. Jamais chargée hors
 * de ce test.
 */
final class AfterRemovingProbePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->getParameter('kernel.environment');
    }
}
