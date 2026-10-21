<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\ApiPlatform;

use ReflectionClass;
use ReflectionProperty;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\IpsRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\Security\Http\AccessMap;

/**
 * Le lecteur de payload lit le palier d'une route sur une requête
 * **synthétique** (`Request::create()` : `127.0.0.1`, `localhost`, port 80,
 * GET), faute de requête HTTP vers cette route. C'est juste tant que chaque
 * règle `access_control` ne dépend que du chemin : une règle conditionnée par
 * `ips`, `host`, `port`, `methods`, `attributes` ou `allow_if` donnerait au
 * client fictif un autre palier qu'au vrai — l'exemple classique,
 * `PUBLIC_ACCESS` réservé à `127.0.0.1` puis un refus, ferait servir à tout
 * client MCP ce que HTTP refuse (relecture de la tâche 2, audit B2).
 *
 * Ce test lit l'`AccessMap` compilée et refuse toute règle qui porte autre
 * chose qu'un chemin et une liste de rôles. Une telle règle ajoutée devra
 * d'abord apprendre au lecteur à la lire, ou à refuser les routes qu'elle
 * touche.
 */
final class AccessMapStaysPathOnlyTest extends KernelTestCase
{
    public function testEveryAccessControlRuleDependsOnThePathOnly(): void
    {
        self::bootKernel();
        $accessMap = self::getContainer()->get('security.access_map');

        self::assertSame([], $this->rulesNotOnThePathOnly($accessMap));
        self::assertNotEmpty((new ReflectionProperty(AccessMap::class, 'map'))->getValue($accessMap), 'AccessMap vide : le test ne vérifie plus rien.');
    }

    /**
     * Le garde-fou du garde-fou : une règle restreinte par IP est vue.
     */
    public function testARuleRestrictedByIpIsCaught(): void
    {
        $accessMap = new AccessMap();
        $accessMap->add(new ChainRequestMatcher([new PathRequestMatcher('^/api/internal(/|$)')]), ['ROLE_SUPER']);
        $accessMap->add(new ChainRequestMatcher([new PathRequestMatcher('^/api/internal(/|$)'), new IpsRequestMatcher('127.0.0.1')]), ['PUBLIC_ACCESS']);

        self::assertSame(['#1 : IpsRequestMatcher'], $this->rulesNotOnThePathOnly($accessMap));
    }

    /**
     * Une règle dont les attributs ne sont pas des rôles (une expression
     * `allow_if`) est vue aussi.
     */
    public function testARuleWhoseAttributesAreNotRolesIsCaught(): void
    {
        $accessMap = new AccessMap();
        $accessMap->add(new ChainRequestMatcher([new PathRequestMatcher('^/api/x(/|$)')]), [new stdClass()]);

        self::assertSame(['#0 : attribut stdClass'], $this->rulesNotOnThePathOnly($accessMap));
    }

    /**
     * @return list<string> « #rang : cause » de chaque règle qui dépend d'autre chose que du chemin
     */
    private function rulesNotOnThePathOnly(AccessMap $accessMap): array
    {
        $rules = (new ReflectionProperty(AccessMap::class, 'map'))->getValue($accessMap);
        self::assertIsArray($rules, 'AccessMap::$map n\'est plus un tableau : structure interne changée, adapter ce test.');

        $faulty = [];
        foreach (array_values($rules) as $rank => $rule) {
            self::assertIsArray($rule);
            [$requestMatcher, $attributes] = $rule;
            self::assertInstanceOf(ChainRequestMatcher::class, $requestMatcher, \sprintf('Règle #%d : matcher inattendu, adapter ce test.', $rank));
            self::assertIsArray($attributes);

            $matchers = (new ReflectionProperty(ChainRequestMatcher::class, 'matchers'))->getValue($requestMatcher);
            self::assertIsIterable($matchers, 'ChainRequestMatcher::$matchers n\'est plus itérable : structure interne changée, adapter ce test.');
            foreach ($matchers as $matcher) {
                self::assertIsObject($matcher);
                if (!$matcher instanceof PathRequestMatcher) {
                    $faulty[] = \sprintf('#%d : %s', $rank, new ReflectionClass($matcher)->getShortName());
                }
            }

            foreach ($attributes as $attribute) {
                if (!\is_string($attribute)) {
                    $faulty[] = \sprintf('#%d : attribut %s', $rank, get_debug_type($attribute));
                }
            }
        }

        return $faulty;
    }
}
