<?php

declare(strict_types=1);

// Fixture (issue #357) : services PHP propres à `test`, compilés : pas
// signalée.

return [
    'api_platform' => [
        'exception_to_status' => [\LogicException::class => 404],
    ],
];
