<?php

declare(strict_types=1);

// Fixture (issue #357) : configuration PHP propre à `prod`, au format closure.

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', ['exceptions' => [RangeException::class => ['log_level' => 'info']]]);
};
