<?php

declare(strict_types=1);

use App\Tests\Support\RepositoryFiles;

// Fixture d'ExceptionMappingEnvironmentParityTest (issue #357) : les bundles
// de l'application, pour que chaque extension soit là pour charger sa
// configuration.

return require RepositoryFiles::backend('config/bundles.php');
