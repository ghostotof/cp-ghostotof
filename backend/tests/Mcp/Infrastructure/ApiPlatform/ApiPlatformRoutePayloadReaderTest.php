<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\State\ProviderInterface;
use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Mcp\Infrastructure\ApiPlatform\ApiPlatformRoutePayloadReader;
use App\Mcp\Infrastructure\ApiPlatform\RouteAboveMcpTiersException;
use App\Mcp\Infrastructure\ApiPlatform\UnroutedOperationException;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSettingsResource;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\AnonymousCvSectionResource;
use App\Tests\Mcp\Infrastructure\ApiPlatform\Fixtures\PrefixedResource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Le lecteur appelle le provider directement, sans passer par le firewall :
 * c'est donc lui qui refuse une route qu'`access_control` réserve à un palier
 * supérieur au palier de base (ADR 0006 D2 : sans connexion MCP, le serveur
 * sert les paliers anonyme et de base, rien de plus). Le test symétrique ne
 * doit pas être la seule barrière.
 */
final class ApiPlatformRoutePayloadReaderTest extends KernelTestCase
{
    public function testARouteReservedAboveTheBaseTierIsRefused(): void
    {
        self::bootKernel();

        $this->expectException(RouteAboveMcpTiersException::class);

        self::getContainer()->get(RoutePayloadReaderInterface::class)->read(BackofficeAboutSettingsResource::class, ['locale' => 'fr']);
    }

    /**
     * API Platform construit le chemin de la route avec le `routePrefix` de
     * l'opération : reconstruit à partir de `/api` et du seul gabarit, le
     * chemin interrogé (`/api/summary/fr`) échappait à la règle `^/api/cv` et
     * une route du palier de confiance était servie (relecture de la tâche 2).
     * Le lecteur demande le chemin au routeur, sous le nom de l'opération.
     */
    public function testTheTierIsReadOnThePathTheRouterServesPrefixIncluded(): void
    {
        self::bootKernel();
        $reader = $this->readerFor(
            new Get(uriTemplate: '/summary/{locale}', routePrefix: '/cv', name: 'prefixed_get'),
            new Route('/api/cv/summary/{locale}'),
        );

        $this->expectException(RouteAboveMcpTiersException::class);

        $reader->read(PrefixedResource::class, ['locale' => 'fr']);
    }

    /**
     * Le générateur préfixe le chemin par l'URL de base de la requête (un
     * contrôleur frontal visible, un sous-répertoire), qu'`access_control` ne
     * voit pas : le palier est lu sur le chemin sans elle, sinon la règle
     * `^/api/cv` ne s'appliquerait plus.
     */
    public function testTheTierIsReadOnThePathWithoutTheRequestBaseUrl(): void
    {
        self::bootKernel();
        $reader = $this->readerFor(
            new Get(uriTemplate: '/summary/{locale}', routePrefix: '/cv', name: 'prefixed_get'),
            new Route('/api/cv/summary/{locale}'),
            new RequestContext('/index.php'),
        );

        $this->expectException(RouteAboveMcpTiersException::class);

        $reader->read(PrefixedResource::class, ['locale' => 'fr']);
    }

    /**
     * Une opération que le routeur ne connaît pas sous son nom n'est pas une
     * route : le lecteur refuse, plutôt que de deviner son chemin.
     */
    public function testAnOperationTheRouterDoesNotKnowIsRefused(): void
    {
        self::bootKernel();
        $reader = $this->readerFor(new Get(uriTemplate: '/summary/{locale}', name: 'prefixed_get'), null);

        $this->expectException(UnroutedOperationException::class);

        $reader->read(PrefixedResource::class, ['locale' => 'fr']);
    }

    public function testABaseTierRouteIsRead(): void
    {
        self::bootKernel();

        $payload = self::getContainer()->get(RoutePayloadReaderInterface::class)->read(AnonymousCvSectionResource::class, ['locale' => 'fr']);

        self::assertSame([], $payload);
    }

    /**
     * Le lecteur réel du conteneur (`AccessMap` du firewall comprise), avec
     * pour seules métadonnées l'opération donnée et un routeur qui ne connaît
     * qu'elle — sous son nom, quand une route est donnée.
     */
    private function readerFor(Get $operation, ?Route $route, RequestContext $context = new RequestContext()): ApiPlatformRoutePayloadReader
    {
        $metadata = self::createStub(ResourceMetadataCollectionFactoryInterface::class);
        $metadata->method('create')->willReturn(new ResourceMetadataCollection(PrefixedResource::class, [
            new ApiResource(class: PrefixedResource::class, operations: [(string) $operation->getName() => $operation]),
        ]));

        $routes = new RouteCollection();
        if (null !== $route) {
            $routes->add((string) $operation->getName(), $route);
        }

        $provider = self::createStub(ProviderInterface::class);
        $provider->method('provide')->willReturn(new PrefixedResource());
        $normalizer = self::createStub(NormalizerInterface::class);
        $normalizer->method('normalize')->willReturn(['served' => true]);

        return new ApiPlatformRoutePayloadReader($metadata, $provider, $normalizer, self::getContainer()->get('security.access_map'), new UrlGenerator($routes, $context));
    }
}
