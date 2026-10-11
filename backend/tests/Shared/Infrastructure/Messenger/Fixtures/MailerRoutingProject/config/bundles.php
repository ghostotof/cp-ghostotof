<?php

declare(strict_types=1);

// Fixture de SendEmailMessageStaysSynchronousTest (issue #426) : les bundles
// de l'application, pour que chaque extension soit là pour charger sa
// configuration.

return require \dirname(__DIR__, 7).'/config/bundles.php';
