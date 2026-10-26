<?php

declare(strict_types=1);

use App\Tests\Security\Fixtures\EnvironmentServices\AfterRemovingProbePass;
use App\Tests\Security\Fixtures\EnvironmentServices\GrantsTrustedVoter;
use App\Tests\Security\Fixtures\EnvironmentServices\ProdOnlyOverridesPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Fixture de SecurityServicesEnvironmentParityTest (relecture de #698),
// chargée après prod-only-services.yaml dans chaque environnement : les formes
// qu'un fichier YAML ne peut pas prendre.
return static function (ContainerConfigurator $configurator, ContainerBuilder $builder): void {
    // Des passes de compilation : l'une n'agit qu'en prod, l'autre suit
    // l'instantané.
    $builder->addCompilerPass(new ProdOnlyOverridesPass());
    $builder->addCompilerPass(new AfterRemovingProbePass(), PassConfig::TYPE_AFTER_REMOVING);

    // Une branche sur l'environnement du processus, lue au chargement : sous
    // PHPUnit (APP_ENV=test), elle ne serait jamais prise sans
    // ProcessEnvironment, alors que l'image de production la prend.
    if ('prod' === ($_SERVER['APP_ENV'] ?? null)) {
        $configurator->services()->set('fixture.env_branch_voter', GrantsTrustedVoter::class)->autoconfigure();
    }
};
