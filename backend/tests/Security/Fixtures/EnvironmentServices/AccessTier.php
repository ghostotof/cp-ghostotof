<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\EnvironmentServices;

/**
 * Fixture de SecurityServicesEnvironmentParityTest (relecture de #698) : un
 * argument enum, que la description d'une définition réduisait au nom de sa
 * classe — deux cas se comparaient égaux.
 */
enum AccessTier
{
    case Base;
    case Trusted;
}
