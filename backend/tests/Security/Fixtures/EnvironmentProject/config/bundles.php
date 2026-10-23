<?php

declare(strict_types=1);

use App\Tests\Support\RepositoryFiles;

// Fixture de SecurityConfigEnvironmentParityTest (issue #659) : les bundles de
// l'application, pour que SecurityBundle et les fabriques d'authentificateurs
// (dont celle de Lexik) soient là pour normaliser sa configuration.

return require RepositoryFiles::backend('config/bundles.php');
