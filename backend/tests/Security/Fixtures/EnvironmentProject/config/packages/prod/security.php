<?php

declare(strict_types=1);

// Fixture de SecurityConfigEnvironmentParityTest (issue #659) : configuration
// PHP propre à `prod`, au format closure, qui modifie un firewall existant.

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('security', ['firewalls' => ['api' => ['stateless' => false]]]);
};
