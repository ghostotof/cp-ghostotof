<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Les règles `access_control` de security.yaml sont des expressions régulières,
 * et Symfony n'applique que la **première** qui correspond. Sans ancre de fin,
 * `^/api/cv` capture aussi `/api/cv-export`, et `^/api/case-studies` capturerait
 * un futur `/api/case-studies-drafts` avec le rôle de la règle voisine, quel que
 * soit le palier que cette nouvelle route devait exiger (issue #78, pt 2).
 *
 * Ce test lit la carte d'accès telle qu'elle est compilée (pas le YAML) et
 * vérifie, pour chaque règle : qu'elle couvre bien sa route, son sous-arbre
 * (`/…/`), et **rien de plus** — un chemin qui prolonge le préfixe sans
 * séparateur ne doit être capturé par aucune règle. La protection d'une telle
 * route n'est alors jamais implicite : soit elle reçoit sa propre règle, soit
 * ApiRouteExposureTest la signale comme servie à qui n'y a pas droit.
 */
final class AccessControlAnchoringTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: list<string>|null}>
     */
    public static function provideRequestPathsAndExpectedRoles(): iterable
    {
        // --- Chaque règle couvre sa route exacte et son sous-arbre.
        yield '/api/backoffice → ROLE_SUPER' => ['/api/backoffice', ['ROLE_SUPER']];
        yield '/api/backoffice/users/1 → ROLE_SUPER' => ['/api/backoffice/users/1', ['ROLE_SUPER']];
        yield '/api/me → ROLE_TRUSTED' => ['/api/me', ['ROLE_TRUSTED']];
        yield '/api/cv → ROLE_TRUSTED' => ['/api/cv', ['ROLE_TRUSTED']];
        yield '/api/cv/ → ROLE_TRUSTED' => ['/api/cv/', ['ROLE_TRUSTED']];
        yield '/api/case-studies/fr → ROLE_USER' => ['/api/case-studies/fr', ['ROLE_USER']];
        yield '/api/anonymous-cv/en → ROLE_USER' => ['/api/anonymous-cv/en', ['ROLE_USER']];

        // --- Un voisin par préfixe n'hérite d'aucune règle : sa protection
        // doit être écrite, jamais déduite d'une regex trop large.
        yield '/api/backoffice-preview → aucune règle' => ['/api/backoffice-preview', null];
        yield '/api/me-too → aucune règle' => ['/api/me-too', null];
        yield '/api/cv-export → aucune règle' => ['/api/cv-export', null];
        yield '/api/cvs → aucune règle' => ['/api/cvs', null];
        yield '/api/case-studies-drafts → aucune règle' => ['/api/case-studies-drafts', null];
        yield '/api/anonymous-cv-full → aucune règle' => ['/api/anonymous-cv-full', null];
    }

    /**
     * @param list<string>|null $expectedRoles null : aucune règle ne doit correspondre
     */
    #[DataProvider('provideRequestPathsAndExpectedRoles')]
    public function testEachAccessControlRuleMatchesItsSubtreeAndNothingElse(string $path, ?array $expectedRoles): void
    {
        self::bootKernel();
        // Service privé, mais toujours présent dans le conteneur compilé (injecté
        // dans l'AccessListener) : le conteneur de test l'expose tel quel.
        [$attributes] = self::getContainer()->get('security.access_map')->getPatterns(Request::create($path));

        self::assertSame(
            $expectedRoles,
            $attributes,
            null === $expectedRoles
                ? sprintf('%s est capturé par une règle access_control alors qu\'il ne fait que prolonger un préfixe : la regex manque une ancre de fin.', $path)
                : sprintf('%s devrait relever de la règle %s.', $path, implode(',', $expectedRoles)),
        );
    }
}
