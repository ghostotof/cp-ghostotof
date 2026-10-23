<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\RouteAttributes;

use Symfony\Component\Routing\Attribute\Route;

/**
 * Fixture de RouteEnvironmentParityTest (issue #659) : des routes déclarées
 * par attribut pour un environnement, sur la classe et sur une méthode, à
 * côté d'une méthode commune, qui ne ressort pas. Jamais chargée par le
 * routeur : elle est hors de src/.
 */
#[Route('/fixture/attributes', env: 'prod')]
final class EnvironmentRoutedController
{
    #[Route('/dev-only', env: ['dev'])]
    public function devOnly(): string
    {
        return 'dev';
    }

    #[Route('/common')]
    public function common(): string
    {
        return 'common';
    }
}
