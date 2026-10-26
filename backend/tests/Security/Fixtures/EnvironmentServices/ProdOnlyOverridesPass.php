<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\EnvironmentServices;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\Security\Http\Authentication\ExposeSecurityLevel;

/**
 * Fixture de SecurityServicesEnvironmentParityTest (relecture de #698) : une
 * passe de compilation qui n'agit qu'en `prod`, enregistrée par
 * prod-only-services.php. Trois surcharges que l'audit a mesurées, chacune
 * invisible de la garde déclarée comme de la première version de la garde
 * compilée :
 *  - la hiérarchie des rôles, un paramètre tableau que la compilation ne
 *    résout pas dans les définitions (`ROLE_USER` hérite de `ROLE_TRUSTED`) ;
 *  - le matcher de la première règle `access_control`, un identifiant caché
 *    (`.security.request_matcher.<hash>`) redéfini sous le même nom ;
 *  - le niveau d'exposition des erreurs du login, un paramètre enum que
 *    reçoit le gestionnaire d'authentification de chaque firewall.
 * Jamais chargé hors de ce test.
 */
final class ProdOnlyOverridesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ('prod' !== $container->getParameter('kernel.environment')) {
            return;
        }

        $container->setParameter('security.role_hierarchy.roles', ['ROLE_USER' => ['ROLE_TRUSTED']]);

        $firstRule = $container->getDefinition('security.access_map')->getMethodCalls()[0][1][0] ?? null;
        if ($firstRule instanceof Reference) {
            $container->setDefinition((string) $firstRule, new Definition(PathRequestMatcher::class, ['^/never-matches$']));
        }

        $container->setParameter('.security.authentication.expose_security_errors', ExposeSecurityLevel::All);
    }
}
