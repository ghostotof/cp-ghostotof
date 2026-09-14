<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Routing\RouterInterface;

/**
 * Depuis la migration UUID (spec 0003, v0.11.0), chaque opération d'item
 * porte `requirements: ['id' => Requirement::UUID]` : c'est ce qui transforme
 * un `{id}` malformé en 404 du routeur, *avant* que le firewall ou un
 * Provider ne s'en mêle. `ResolvesUriVariables::uriVariableUuid()` s'appuie
 * ensuite sur `\assert()` pour retomber sur `Uuid::fromString()` — une garde
 * inactive en production (`zend.assertions=-1`). Le routeur est donc la
 * seule protection réelle contre un id malformé ; sans elle, la requête
 * atteindrait `Uuid::fromString()` et produirait un 500 au lieu d'un 404, en
 * silence.
 *
 * Rien dans le code n'empêche une future opération d'item d'omettre ce
 * `requirements` — ni PHPStan ni Rector ne peuvent le voir, puisque la
 * regex est une chaîne parmi d'autres options d'attribut API Platform. Ce
 * test parcourt donc le routeur compilé (calqué sur
 * `AccessControlAnchoringTest`, qui fait de même pour les règles
 * `access_control`) et affirme que toute route `/api` dont le chemin
 * contient `{id}` déclare exactement `Requirement::UUID` sur ce paramètre.
 * Les rares routes internes d'API Platform qui manipulent un `{id}` sans
 * jamais toucher une entité (identifiants anonymisés `genid`, ressource
 * d'erreur de validation) sont exclues explicitement, avec leur
 * justification, sur le même principe que `PUBLIC_PATHS` dans
 * `ApiRouteExposureTest`.
 *
 * La spec 0004 ajoutera `PUT …/order` à côté de `PUT …/{id}` sur plusieurs
 * ressources : c'est précisément ce `requirements` qui empêche `{id}` de
 * capturer le littéral `order` au routage. Cet invariant doit rester
 * vérifiable avant cette spec, pas seulement après.
 */
final class ItemRouteRequirementTest extends KernelTestCase
{
    /**
     * Routes /api internes à API Platform dont le paramètre `{id}` ne
     * désigne jamais une entité applicative : aucune ne doit jamais recevoir
     * `requirements: ['id' => Requirement::UUID]`, et aucune ne relève du
     * backoffice.
     *
     * @var array<string, string> chemin de route (tel que déclaré au routeur) => raison
     */
    private const array NO_UUID_REQUIREMENT_PATHS = [
        '/api/.well-known/genid/{id}' => 'Identifiant anonyme généré par API Platform pour une ressource sans IRI propre (ex. un objet imbriqué) — jamais une clé primaire d\'entité, jamais résolu via ResolvesUriVariables.',
        '/api/.well-known/genid/{id}.{_format}' => 'Idem, variante avec extension de format.',
        '/api/validation_errors/{id}' => 'Ressource d\'erreur de validation interne d\'API Platform : {id} y est un index de violation, pas un identifiant d\'entité.',
    ];

    /**
     * L'invariant central : toute route /api dont le chemin contient {id}
     * déclare le requirement UUID sur ce paramètre, sauf les rares routes
     * internes explicitement exclues ci-dessus.
     */
    public function testEveryApiItemRouteRequiresAUuidId(): void
    {
        self::bootKernel();
        $checked = 0;

        foreach ($this->router()->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            if (!str_starts_with($path, '/api/') || !str_contains($path, '{id}')) {
                continue;
            }

            if (\array_key_exists($path, self::NO_UUID_REQUIREMENT_PATHS)) {
                continue;
            }

            ++$checked;

            self::assertSame(
                Requirement::UUID,
                $route->getRequirement('id'),
                sprintf(
                    'La route "%s" (%s) porte un paramètre {id} sans requirements UUID : un id malformé '
                    .'atteindrait Uuid::fromString() (500) au lieu d\'être un 404 du routeur. Ajoute '
                    ."requirements: ['id' => Requirement::UUID] à cette opération, ou inscris ce chemin "
                    .'dans NO_UUID_REQUIREMENT_PATHS avec une justification si {id} n\'y désigne pas une entité.',
                    $path,
                    $name,
                ),
            );
        }

        self::assertGreaterThan(0, $checked, 'Aucune route /api avec {id} détectée : le test ne vérifie plus rien.');
    }

    /**
     * Garde structurel, jumeau de ApiRouteExposureTest::testNoBackofficeRouteCanBeAllowListedAsPublic() :
     * on ne peut pas « faire taire » ce test en exemptant une route de
     * backoffice, qui manipule justement des identifiants d'entités
     * sensibles (utilisateurs, contenu édité).
     */
    public function testNoBackofficeRouteCanBeExemptedFromTheUuidRequirement(): void
    {
        foreach (array_keys(self::NO_UUID_REQUIREMENT_PATHS) as $path) {
            self::assertStringStartsNotWith(
                '/api/backoffice',
                $path,
                sprintf('"%s" est une route de backoffice : elle ne peut jamais être exemptée du requirement UUID.', $path),
            );
        }
    }

    /**
     * Garde d'hygiène, jumelle de ApiRouteExposureTest::testThePublicAllowListHasNoStaleEntry() :
     * une entrée qui ne correspond plus à aucune route est un reste de
     * refactor, qui donnerait une fausse impression de couverture.
     */
    public function testTheExemptionListHasNoStaleEntry(): void
    {
        self::bootKernel();
        $declaredPaths = [];

        foreach ($this->router()->getRouteCollection() as $route) {
            $declaredPaths[$route->getPath()] = true;
        }

        foreach (array_keys(self::NO_UUID_REQUIREMENT_PATHS) as $path) {
            self::assertArrayHasKey(
                $path,
                $declaredPaths,
                sprintf('NO_UUID_REQUIREMENT_PATHS déclare "%s", qui n\'existe plus dans le routeur.', $path),
            );
        }
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get('router');
    }
}
