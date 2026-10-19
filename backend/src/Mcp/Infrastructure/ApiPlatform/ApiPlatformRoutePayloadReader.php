<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Serializer\AbstractItemNormalizer;
use ApiPlatform\State\ProviderInterface;
use App\Mcp\Application\RoutePayloadReaderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Rend le payload d'une route API Platform en reproduisant ce que la requête
 * HTTP ferait : le provider de l'opération, puis la normalisation `json` avec
 * le contexte que `SerializerContextBuilder` construit pour une requête.
 *
 * Ce contexte n'est pas un détail : il porte `skip_null_values`, qui fait
 * omettre un champ `null` par la route (une carte sans icône n'a pas
 * d'`iconKey`). Normaliser avec un contexte vide rendrait `"iconKey": null`,
 * et l'outil ne servirait plus exactement la route (ADR 0006 D1). Le test
 * d'égalité route/outil de chaque outil en est le juge — c'est aussi lui qui
 * signalera une montée d'API Platform qui changerait ce contexte.
 *
 * **Le palier est vérifié ici.** Le provider est appelé directement, sans
 * firewall ni `access_control` : une route que ceux-ci réservent au-delà du
 * palier de base (le CV nominatif, le backoffice) serait servie à un client
 * MCP anonyme. Le lecteur interroge donc la même `AccessMap` que le firewall
 * pour le chemin de la route, et refuse toute opération qui exige plus que
 * `ROLE_USER` ou qui porte un attribut `security`.
 *
 * **Ce chemin est celui du routeur**, généré sous le nom de l'opération (le
 * nom de sa route chez API Platform), jamais reconstruit : un `routePrefix`
 * ou un préfixe de montage changé faisait interroger un autre chemin que la
 * route servie, auquel aucune règle ne s'appliquait — le contrôle échouait
 * ouvert (relecture de la tâche 2). Une opération sans route est refusée.
 */
final readonly class ApiPlatformRoutePayloadReader implements RoutePayloadReaderInterface
{
    private const string FORMAT = 'json';

    /** Rôles qu'une route peut exiger et rester servie sans connexion MCP. */
    private const array ROLES_SERVED_WITHOUT_CONNECTION = ['PUBLIC_ACCESS', 'ROLE_USER'];

    /**
     * @param ProviderInterface<object> $providerLocator
     */
    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        // Le localisateur d'API Platform résout `$operation->getProvider()`
        // comme le fait la requête HTTP, sans liste de providers à tenir ici.
        #[Autowire(service: 'api_platform.state_provider.locator')]
        private ProviderInterface $providerLocator,
        #[Autowire(service: 'serializer')]
        private NormalizerInterface $normalizer,
        #[Autowire(service: 'security.access_map')]
        private AccessMapInterface $accessMap,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function read(string $resourceClass, array $uriVariables = []): array
    {
        $operation = $this->operationServedWithoutConnection($resourceClass, $uriVariables);

        $data = $this->providerLocator->provide($operation, $uriVariables, [
            'resource_class' => $resourceClass,
            'operation' => $operation,
            'uri_variables' => $uriVariables,
        ]);

        $payload = $this->normalizer->normalize($data, self::FORMAT, $this->normalizationContext($operation, $resourceClass, $uriVariables));

        if (!\is_array($payload)) {
            throw UnexpectedRoutePayloadException::forResource($resourceClass, get_debug_type($payload));
        }

        return $payload;
    }

    /**
     * L'unique opération GET exposée de la ressource que le serveur peut
     * servir sans connexion MCP. Les opérations `NotExposed` (qu'API Platform
     * synthétise pour construire des IRI) ne sont pas des routes.
     *
     * @param class-string          $resourceClass
     * @param array<string, string> $uriVariables
     */
    private function operationServedWithoutConnection(string $resourceClass, array $uriVariables): HttpOperation
    {
        $served = [];
        $reservedAbove = 0;
        foreach ($this->resourceMetadataFactory->create($resourceClass) as $resource) {
            foreach ($resource->getOperations() ?? [] as $operation) {
                if ($operation instanceof NotExposed || HttpOperation::METHOD_GET !== $operation->getMethod()) {
                    continue;
                }

                if ($this->isServedWithoutConnection($resourceClass, $operation, $uriVariables)) {
                    $served[] = $operation;
                } else {
                    ++$reservedAbove;
                }
            }
        }

        if ([] === $served && $reservedAbove > 0) {
            throw RouteAboveMcpTiersException::forResource($resourceClass);
        }

        if (1 !== \count($served)) {
            throw RouteOperationNotFoundException::forResource($resourceClass, \count($served));
        }

        return $served[0];
    }

    /**
     * @param class-string          $resourceClass
     * @param array<string, string> $uriVariables
     */
    private function isServedWithoutConnection(string $resourceClass, HttpOperation $operation, array $uriVariables): bool
    {
        if (null !== $operation->getSecurity()) {
            return false;
        }

        [$roles] = $this->accessMap->getPatterns(Request::create($this->routePath($resourceClass, $operation, $uriVariables)));

        foreach ($roles ?? [] as $role) {
            if (!\in_array($role, self::ROLES_SERVED_WITHOUT_CONNECTION, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Le chemin que la route sert pour ces variables, tel qu'`access_control`
     * le voit, généré par le routeur : préfixes compris, et les `requirements`
     * de la route appliqués aux variables.
     *
     * @param class-string          $resourceClass
     * @param array<string, string> $uriVariables
     */
    private function routePath(string $resourceClass, HttpOperation $operation, array $uriVariables): string
    {
        $name = (string) $operation->getName();

        try {
            $path = $this->urlGenerator->generate($name, $uriVariables, UrlGeneratorInterface::ABSOLUTE_PATH);
        } catch (RouteNotFoundException $routeNotFound) {
            throw UnroutedOperationException::forOperation($resourceClass, $name, $routeNotFound);
        }

        // Le générateur préfixe l'URL de base de la requête (`/index.php`, un
        // sous-répertoire) ; `access_control` compare le chemin sans elle.
        $baseUrl = $this->urlGenerator->getContext()->getBaseUrl();

        return '' !== $baseUrl && str_starts_with($path, $baseUrl) ? substr($path, \strlen($baseUrl)) : $path;
    }

    /**
     * Les clés que `SerializerContextBuilder::createFromRequest()` pose pour
     * une lecture ; celles qui dépendent de la requête HTTP elle-même
     * (`request_uri`, `uri`) n'influent pas sur un payload JSON sans IRI.
     *
     * @param class-string          $resourceClass
     * @param array<string, string> $uriVariables
     *
     * @return array<string, mixed>
     */
    private function normalizationContext(HttpOperation $operation, string $resourceClass, array $uriVariables): array
    {
        /** @var array<string, mixed> $context */
        $context = $operation->getNormalizationContext() ?? [];
        $context['operation_name'] = $operation->getName();
        $context['operation'] = $operation;
        $context['resource_class'] = $resourceClass;
        $context[AbstractObjectNormalizer::SKIP_NULL_VALUES] ??= true;
        $context[AbstractItemNormalizer::SKIP_NULL_TO_ONE_RELATIONS] ??= true;
        $context['iri_only'] ??= false;
        $context['input'] = $operation->getInput();
        $context['output'] = $operation->getOutput();
        $context['uri_variables'] = $uriVariables;

        return $context;
    }
}
