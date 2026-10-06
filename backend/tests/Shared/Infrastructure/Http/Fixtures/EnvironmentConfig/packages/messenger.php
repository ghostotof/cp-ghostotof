<?php

declare(strict_types=1);

// Fixture (issue #357) : configuration PHP commune, sans condition
// d'environnement. Compilée en test : pas signalée.

return [
    'framework' => [
        'exceptions' => [\LogicException::class => ['log_level' => 'info']],
    ],
];
