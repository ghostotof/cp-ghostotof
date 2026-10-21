<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Fixtures;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Un provider qui lirait la requête et l'appelant : ce que le test
 * d'exposition symétrique refuse pour une route servie par MCP. Hors de
 * `src/`, jamais chargé par l'application.
 */
final readonly class CallerReadingProvider
{
    public function __construct(
        public RequestStack $requestStack,
        public Security $security,
    ) {
    }
}
