<?php

declare(strict_types=1);

// Fixture (issue #357) : configuration PHP commune, au format tableau, dont un
// bloc `when@prod` déclare un mapping. Signalée.

return [
    'when@prod' => [
        'api_platform' => [
            'exception_to_status' => [\RuntimeException::class => 404],
        ],
    ],
];
