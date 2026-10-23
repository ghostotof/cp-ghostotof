<?php

declare(strict_types=1);

// Fixture de RouteEnvironmentParityTest (issue #659) : routes PHP au format
// closure, qui choisissent selon l'environnement.

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    if ('prod' === $routes->env()) {
        $routes->add('fixture_closure_prod_only', '/fixture/closure-prod-only');
    }
};
