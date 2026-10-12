<?php

declare(strict_types=1);

// Fixture (issue #357) : configuration PHP commune, sans condition
// d'environnement : aucune différence.

return [
    'framework' => [
        'exceptions' => [UnexpectedValueException::class => ['log_level' => 'info']],
    ],
];
