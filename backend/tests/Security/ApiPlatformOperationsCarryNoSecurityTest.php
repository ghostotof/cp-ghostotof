<?php

declare(strict_types=1);

namespace App\Tests\Security;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Metadata;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Aucune opération d'API Platform ne porte d'expression de sécurité, quelle que soit sa
 * source (#748, R61, CR6-7 pour la configuration).
 *
 * Toute autorisation de `/api` est une règle d'`access_control` :
 * {@see \App\Security\Authentication\Infrastructure\Security\PublicRouteIgnoresRefusedSessionListener}
 * tient « route publique = route sans règle » et sert comme l'anonyme une requête dont le
 * cookie `BEARER` est refusé. Un `security:` d'API Platform posé sur une route sans règle
 * romprait cette prémisse en silence (voir {@see AuthorizationLivesInAccessControlTest}, dont
 * celle-ci est le pendant). Les témoins de cette prémisse sont listés une fois, dans
 * `.claude/rules/security-authentication-sessions.md` (puce de
 * `PublicRouteIgnoresRefusedSessionListener`).
 *
 * **Ce que cette garde voit de plus que celle des jetons** : elle lit les **métadonnées
 * compilées** (chaque ressource et chaque opération que `ResourceMetadataCollectionFactoryInterface`
 * construit, comme `ApiPlatformStartupRaisesNoDeprecationTest` les parcourt), donc l'attribut
 * PHP, mais aussi un `security:` déclaré en YAML ou en XML, ou posé par tout ce qu'API
 * Platform fusionne (un décorateur de factory, les valeurs par défaut globales de
 * `api_platform.defaults`). Lire `config/**` par motifs ne verrait que la forme écrite, et
 * `security:` est aussi la clé racine de `security.yaml`.
 *
 * **Ce qu'elle ne voit pas** : une autorisation hors d'API Platform (`#[IsGranted]` d'un
 * contrôleur, `isGranted()`, un refus levé à la main) — c'est l'autre garde, par jetons.
 * Les opérations GraphQL ne sont pas parcourues : `testGraphQlStaysDisabled` (voir
 * `.claude/rules/backoffice-api.md`) tient GraphQL éteint.
 *
 * Les trois expressions d'API Platform sont refusées : `security`, `securityPostDenormalize`
 * et `securityPostValidation` (leurs `…Message` ne servent qu'à les accompagner).
 */
final class ApiPlatformOperationsCarryNoSecurityTest extends KernelTestCase
{
    public function testNoOperationCarriesASecurityExpression(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $names = $container->get(ResourceNameCollectionFactoryInterface::class);
        $metadata = $container->get(ResourceMetadataCollectionFactoryInterface::class);

        $visited = 0;
        $found = [];
        foreach ($names->create() as $resourceClass) {
            foreach ($metadata->create($resourceClass) as $resource) {
                // La ressource elle-même : API Platform recopie ses valeurs sur les opérations
                // qui n'ont pas les leurs, mais un défaut posé ici se verrait au plus tôt.
                foreach ($this->expressionsOf($resource) as $attribute => $expression) {
                    $found[] = \sprintf('%s (ressource) : %s = %s', $resourceClass, $attribute, $expression);
                }
                foreach ($resource->getOperations() ?? [] as $operationName => $operation) {
                    ++$visited;
                    foreach ($this->expressionsOf($operation) as $attribute => $expression) {
                        $found[] = \sprintf('%s, opération %s : %s = %s', $resourceClass, $operationName, $attribute, $expression);
                    }
                }
            }
        }

        // Une garde qui ne parcourt rien rougirait jamais.
        self::assertGreaterThan(0, $visited, 'Aucune opération parcourue : la garde ne verrait rien.');
        self::assertSame(
            [],
            $found,
            "Toute autorisation de /api est une règle d'access_control de security.yaml, jamais un `security:` d'API Platform "
            ."(attribut, YAML ou XML) : PublicRouteIgnoresRefusedSessionListener tient « route publique = route sans règle ».\n - "
            .implode("\n - ", $found),
        );
    }

    /**
     * @param array<string, string> $expected attribut => expression que la garde doit relever
     */
    #[DataProvider('metadataThatCarrySecurity')]
    public function testTheCheckSeesEachSecurityExpression(Metadata $metadata, array $expected): void
    {
        self::assertSame($expected, $this->expressionsOf($metadata));
    }

    /**
     * @return iterable<string, array{Metadata, array<string, string>}>
     */
    public static function metadataThatCarrySecurity(): iterable
    {
        yield 'security d\'une opération' => [new Get(security: 'is_granted("ROLE_USER")'), ['security' => 'is_granted("ROLE_USER")']];
        yield 'securityPostDenormalize' => [new Put(securityPostDenormalize: 'object.owner == user'), ['securityPostDenormalize' => 'object.owner == user']];
        yield 'securityPostValidation' => [new Post(securityPostValidation: 'is_granted("ROLE_X")'), ['securityPostValidation' => 'is_granted("ROLE_X")']];
        yield 'security d\'une ressource' => [new ApiResource(security: 'is_granted("ROLE_USER")'), ['security' => 'is_granted("ROLE_USER")']];
        yield 'les trois à la fois' => [
            new Put(security: 'a', securityPostDenormalize: 'b', securityPostValidation: 'c'),
            ['security' => 'a', 'securityPostDenormalize' => 'b', 'securityPostValidation' => 'c'],
        ];
        yield 'aucune expression' => [new Get(), []];
    }

    /**
     * @return array<string, string> attribut => expression, pour ceux que l'élément déclare
     */
    private function expressionsOf(Metadata $metadata): array
    {
        return array_filter([
            'security' => $metadata->getSecurity(),
            'securityPostDenormalize' => $metadata->getSecurityPostDenormalize(),
            'securityPostValidation' => $metadata->getSecurityPostValidation(),
        ], static fn (?string $expression): bool => null !== $expression);
    }
}
