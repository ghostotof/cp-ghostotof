<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\EnvironmentServices;

use Psr\Log\LoggerInterface;

/**
 * Fixture de SecurityServicesEnvironmentParityTest (relecture de #698) : un
 * service défini dans tous les environnements, dont `prod` change un seul
 * argument — le cas de l'enum, ou le mode d'une référence (`@logger` devenu
 * `@?logger`) —, deux changements que la description d'une définition ne
 * voyait pas. Jamais chargé hors de ce test.
 */
final readonly class TieredService
{
    public function __construct(public AccessTier $tier, public ?LoggerInterface $logger)
    {
    }
}
