<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Serializer\AbstractItemNormalizer;
use ApiPlatform\State\ProviderInterface;
use App\Mcp\Application\RoutePayloadReaderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 * d'égalité route/outil de chaque outil en est le juge.
 *
 * Seule l'opération GET hors backoffice est retenue : un outil n'expose
 * jamais une route d'administration, et la ressource doit n'en avoir qu'une
 * pour que l'outil désigne une route sans ambiguïté.
 */
final readonly class ApiPlatformRoutePayloadReader implements RoutePayloadReaderInterface
{
    private const string FORMAT = 'json';

    /**
     * @param ProviderInterface<object> $providers
     */
    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        // Le localisateur d'API Platform résout `$operation->getProvider()`
        // comme le fait la requête HTTP, sans liste de providers à tenir ici.
        #[Autowire(service: 'api_platform.state_provider.locator')]
        private ProviderInterface $providers,
        #[Autowire(service: 'serializer')]
        private NormalizerInterface $normalizer,
    ) {
    }

    public function read(string $resourceClass, array $uriVariables = []): array
    {
        $operation = $this->publicGetOperation($resourceClass);

        $data = $this->providers->provide($operation, $uriVariables, ['resource_class' => $resourceClass]);

        $payload = $this->normalizer->normalize($data, self::FORMAT, $this->normalizationContext($operation, $resourceClass, $uriVariables));

        if (!\is_array($payload)) {
            throw UnexpectedRoutePayloadException::forResource($resourceClass, get_debug_type($payload));
        }

        return $payload;
    }

    /**
     * @param class-string $resourceClass
     */
    private function publicGetOperation(string $resourceClass): HttpOperation
    {
        $candidates = [];
        foreach ($this->resourceMetadataFactory->create($resourceClass) as $resource) {
            foreach ($resource->getOperations() ?? [] as $operation) {
                if (HttpOperation::METHOD_GET === $operation->getMethod()
                    && !str_starts_with((string) $operation->getUriTemplate(), '/backoffice')
                ) {
                    $candidates[] = $operation;
                }
            }
        }

        if (1 !== \count($candidates)) {
            throw RouteOperationNotFoundException::forResource($resourceClass, \count($candidates));
        }

        return $candidates[0];
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
        $context['skip_null_values'] ??= true;
        $context[AbstractItemNormalizer::SKIP_NULL_TO_ONE_RELATIONS] ??= true;
        $context['iri_only'] ??= false;
        $context['input'] = $operation->getInput();
        $context['output'] = $operation->getOutput();
        $context['uri_variables'] = $uriVariables;

        return $context;
    }
}
