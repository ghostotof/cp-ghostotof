<?php

declare(strict_types=1);

// Fixture de RouteEnvironmentParityTest (issue #659) : routes PHP au format
// tableau, dont un bloc when@dev.

return [
    'when@dev' => [
        'fixture_dev_only' => ['path' => '/fixture/dev-only'],
    ],
];
