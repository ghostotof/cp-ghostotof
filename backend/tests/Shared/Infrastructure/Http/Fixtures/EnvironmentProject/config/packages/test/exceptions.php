<?php

declare(strict_types=1);

// Fixture d'ExceptionMappingEnvironmentParityTest (issue #357) : configuration
// PHP propre à `test`, le faux vert. Sous packages/test/ plutôt qu'en
// services_test.php : le noyau ignore services_<env>.php dès qu'un
// services.yaml existe (KernelTrait::configureContainer).

return [
    'api_platform' => [
        'exception_to_status' => [InvalidArgumentException::class => 404],
    ],
];
