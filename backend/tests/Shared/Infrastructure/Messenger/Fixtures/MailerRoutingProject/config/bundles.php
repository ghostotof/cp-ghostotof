<?php

declare(strict_types=1);

use App\Tests\Support\RepositoryFiles;

// Fixture de SendEmailMessageStaysSynchronousTest (issue #426) : les bundles
// de l'application, pour que chaque extension soit là pour charger sa
// configuration.

return require RepositoryFiles::backend().'/config/bundles.php';
