<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Constat relevé en passant lors du 3e audit de sécurité (PR #240) : une
 * route ajoutée hors `/api` n'est couverte ni par `ApiRouteExposureTest` (qui
 * n'énumère que les chemins commençant par `/api`), ni par aucun firewall (le
 * seul restant, `api`, est ancré `^/api`, cf. `AccessControlAnchoringTest`).
 * Une telle route serait donc servie à n'importe qui, sans authentificateur
 * ni `access_control`, sans qu'aucun test existant ne le remarque.
 *
 * Ce test ferme cette frontière : là où `ApiRouteExposureTest` s'arrête
 * (il ignore tout ce qui n'est pas `/api`), celui-ci commence — il énumère le
 * routeur compilé et affirme que chaque route est exactement `/api` ou
 * commence par `/api/`, sauf inscription explicite et justifiée dans
 * `NON_API_PATHS`, calquée sur le modèle `PUBLIC_PATHS` /
 * `NO_UUID_REQUIREMENT_PATHS` des tests voisins de ce répertoire.
 *
 * Cas notable volontairement absent de l'allow-list : `/_error/{code}.{_format}`
 * (`config/routes/framework.yaml`, sous `when@dev`) n'est compilé qu'en
 * environnement `dev` — jamais en `test` ni en `prod` — donc jamais rencontré
 * par ce test. S'il apparaissait un jour hors `dev`, c'est cette route qu'il
 * faudrait questionner, pas relâcher ce test pour l'accueillir.
 * RouteEnvironmentParityTest le vérifie (issue #659) : il la justifie comme
 * propre à `dev`, et rougit sur toute autre route qu'un environnement aurait
 * en propre.
 *
 * `testTheAllowListHasNoStaleEntry()` compare `NON_API_PATHS` à l'ensemble des
 * chemins compilés via `array_diff` et affirme que la différence est vide :
 * l'assertion s'exécute toujours, même sur une liste vide (elle affirme alors
 * que « aucune entrée morte » est vrai d'un ensemble vide), donc la suite ne
 * termine jamais sur un statut « risky ». Elle a été écrite avant la première
 * entrée, exactement comme `ApiRouteExposureTest::testThePublicAllowListHasNoStaleEntry`
 * et `ItemRouteRequirementTest::testTheExemptionListHasNoStaleEntry`, pour que
 * cette entrée soit immédiatement couverte : les deux documents de
 * métadonnées OAuth de la connexion MCP (#647), les premières routes hors
 * `/api`, l'ont été. Tant que la liste était vide, un helper restaurait son
 * type déclaré (PHPStan jugeait sinon la forme littérale `[]`) ; il est
 * retiré avec elles.
 */
final class RouterScopeTest extends KernelTestCase
{
    /**
     * Routes hors `/api` explicitement acceptées, chacune avec sa
     * justification et — l'énoncé de la règle l'exige — son propre firewall
     * et sa propre règle `access_control`.
     *
     * @var array<string, string> chemin de route (tel que déclaré au routeur) => raison
     */
    private const array NON_API_PATHS = [
        '/.well-known/oauth-protected-resource/api/mcp' => 'Métadonnées de la ressource protégée MCP (#647, RFC 9728 §3.1) : '
            .'un client MCP les cherche au chemin bien connu suivi du chemin de la ressource, hors /api par la norme. '
            .'Document public, sans donnée de personne, GET seulement. Son propre firewall (oauth_resource_metadata, '
            .'sans authentificateur, borné par $) et sa propre règle access_control (PUBLIC_ACCESS, même motif).',
        '/.well-known/oauth-authorization-server/api/oauth' => 'Métadonnées du serveur d\'autorisation (#647, RFC 8414 §3.1) : '
            .'un client MCP les cherche au chemin bien connu suivi du chemin de l\'émetteur, hors /api par la norme. '
            .'Document public, sans donnée de personne, GET seulement. Son propre firewall (oauth_server_metadata, '
            .'sans authentificateur, borné par $) et sa propre règle access_control (PUBLIC_ACCESS, même motif).',
    ];

    /**
     * L'invariant central : toute route compilée est `/api` ou commence par
     * `/api/`, sauf exemption explicite ci-dessus.
     */
    public function testEveryCompiledRouteIsUnderApiUnlessExplicitlyAllowListed(): void
    {
        self::bootKernel();
        $checked = 0;
        $nonApiPaths = self::NON_API_PATHS;

        foreach ($this->router()->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            ++$checked;

            if ('/api' === $path || str_starts_with($path, '/api/')) {
                continue;
            }

            self::assertArrayHasKey(
                $path,
                $nonApiPaths,
                \sprintf(
                    'La route "%s" (%s) est hors de /api : elle échappe au firewall "api" (ancré ^/api, '
                    .'cf. AccessControlAnchoringTest) donc à tout access_control, et hors du périmètre '
                    .'d\'ApiRouteExposureTest (qui n\'énumère que /api) — elle serait servie à n\'importe '
                    .'qui sans qu\'aucun test ne le dise. Place-la sous /api, ou donne-lui son propre '
                    .'firewall et sa propre règle access_control puis inscris-la dans NON_API_PATHS avec '
                    .'sa justification.',
                    $path,
                    $name,
                ),
            );
        }

        self::assertGreaterThan(0, $checked, 'Aucune route détectée : le test ne vérifie plus rien.');
    }

    /**
     * Garde d'hygiène, jumelle de celles d'ItemRouteRequirementTest et
     * ApiRouteExposureTest : une entrée qui ne correspond plus à aucune route
     * compilée est un reste de refactor — elle donnerait une fausse
     * impression de couverture pour une exemption qui n'a plus d'objet.
     *
     * Assertion unique via `array_diff`, toujours exécutée (y compris sur une
     * liste vide) : contrairement à un `foreach` sur les clés de
     * `NON_API_PATHS`, qui ne produit aucune assertion tant que la liste est
     * vide et laisse alors la suite se terminer en « risky ».
     */
    public function testTheAllowListHasNoStaleEntry(): void
    {
        self::bootKernel();
        $compiledPaths = [];

        foreach ($this->router()->getRouteCollection() as $route) {
            $compiledPaths[] = $route->getPath();
        }

        $staleEntries = array_diff(array_keys(self::NON_API_PATHS), $compiledPaths);

        self::assertSame(
            [],
            array_values($staleEntries),
            \sprintf(
                'NON_API_PATHS déclare une ou plusieurs exemptions mortes, qui n\'existent plus dans le '
                .'routeur : %s. Retire l\'entrée devenue obsolète.',
                implode(', ', $staleEntries),
            ),
        );
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get('router');
    }
}
