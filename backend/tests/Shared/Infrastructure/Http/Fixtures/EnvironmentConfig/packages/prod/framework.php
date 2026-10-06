<?php

declare(strict_types=1);

// Fixture d'ExceptionLogLevelCoverageTest (issue #357) : configuration PHP
// propre à `prod`, au format config builder. Signalée.

use Symfony\Config\FrameworkConfig;

return static function (FrameworkConfig $framework): void {
    $framework->exception(\RuntimeException::class)->logLevel('info');
};
