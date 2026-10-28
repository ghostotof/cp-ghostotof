<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

// Fixture de DeprecatedReferencesOfApiPlatformTest (issue #701) : un service d'API
// Platform référence un alias déprécié d'un autre paquet, et un service déprécié.
// En PHP et non en YAML : le gabarit d'une dépréciation porte `%alias_id%`, que
// `symfony lsp:check` prendrait pour un paramètre inconnu.
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('probe.target', stdClass::class);

    $services->alias('probe.legacy_alias', 'probe.target')
        ->deprecate('vendor/probe', '1.0', 'L\'alias "%alias_id%" est remplacé.');

    $services->set('probe.legacy_service', stdClass::class)
        ->deprecate('vendor/probe', '1.0', 'Le service "%service_id%" est remplacé.');

    $services->set('api_platform.probe_reader', stdClass::class)
        ->args([new Reference('probe.legacy_alias'), new Reference('probe.legacy_service')]);
};
