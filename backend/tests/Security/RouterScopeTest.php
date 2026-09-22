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
 *
 * `testTheAllowListHasNoStaleEntry()` compare `NON_API_PATHS` à l'ensemble des
 * chemins compilés via `array_diff` et affirme que la différence est vide :
 * l'assertion s'exécute toujours, même sur une liste vide (elle affirme alors
 * que « aucune entrée morte » est vrai d'un ensemble vide), donc la suite ne
 * termine jamais sur un statut « risky ». Elle reste écrite dès maintenant,
 * exactement comme `ApiRouteExposureTest::testThePublicAllowListHasNoStaleEntry`
 * et `ItemRouteRequirementTest::testTheExemptionListHasNoStaleEntry`, pour que
 * la première entrée ajoutée à `NON_API_PATHS` soit immédiatement couverte.
 */
final class RouterScopeTest extends KernelTestCase
{
    /**
     * Routes hors `/api` explicitement acceptées, chacune avec sa
     * justification et — l'énoncé de la règle l'exige — son propre firewall
     * et sa propre règle `access_control`. Vide aujourd'hui : aucune route
     * hors `/api` n'est compilée en environnement `test` ou `prod`.
     *
     * @var array<string, string> chemin de route (tel que déclaré au routeur) => raison
     */
    private const array NON_API_PATHS = [];

    /**
     * L'invariant central : toute route compilée est `/api` ou commence par
     * `/api/`, sauf exemption explicite ci-dessus.
     */
    public function testEveryCompiledRouteIsUnderApiUnlessExplicitlyAllowListed(): void
    {
        self::bootKernel();
        $checked = 0;
        $nonApiPaths = $this->nonApiPaths();

        foreach ($this->router()->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            ++$checked;

            if ('/api' === $path || str_starts_with($path, '/api/')) {
                continue;
            }

            self::assertArrayHasKey(
                $path,
                $nonApiPaths,
                sprintf(
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

        $staleEntries = array_diff(array_keys($this->nonApiPaths()), $compiledPaths);

        self::assertSame(
            [],
            array_values($staleEntries),
            sprintf(
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

    /**
     * NON_API_PATHS est vide aujourd'hui : PHPStan connaît sa valeur exacte
     * au niveau de la constante (littéral `[]`) et signalerait tout usage
     * direct comme figé sur ce type (`impossibleType`, `foreach.emptyArray`),
     * indépendamment du `@var` porté par la constante — ce dernier ne
     * « certifie » pas le type d'une constante comme il le ferait pour une
     * variable locale. Le passage par cette méthode, avec son propre
     * `@return`, restaure le type déclaré (array<string, string>) — celui
     * d'une liste appelée à recevoir des entrées, pas celui, plus étroit, de
     * son état vide actuel. Les deux tests de cette classe l'appellent plutôt
     * que de dupliquer ce contournement.
     *
     * @return array<string, string>
     */
    private function nonApiPaths(): array
    {
        /** @var array<string, string> $nonApiPaths */
        $nonApiPaths = self::NON_API_PATHS;

        return $nonApiPaths;
    }
}
