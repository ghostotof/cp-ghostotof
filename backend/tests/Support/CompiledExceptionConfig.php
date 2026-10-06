<?php

declare(strict_types=1);

namespace App\Tests\Support;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;

/**
 * Ce que le conteneur compilé fait des exceptions, plutôt que ce qu'en dit un
 * fichier YAML (issue #357).
 *
 * Lire `framework.yaml` ou `api_platform.yaml` par Yaml::parseFile laissait
 * échapper les blocs `when@test`, toute autre source de configuration qui se
 * fusionne (un second fichier de config/packages/, du PHP) et tout ce
 * qu'aucun fichier ne déclare. Le conteneur, lui, porte la configuration telle
 * que le noyau l'applique.
 */
final class CompiledExceptionConfig
{
    /**
     * `framework.exceptions` compilé : le 4e argument du service
     * `exception_listener` (FrameworkExtension), que l'ErrorListener garde dans
     * une propriété protégée. La réflexion échoue bruyamment si Symfony la
     * renomme, plutôt que de rendre une liste vide.
     *
     * Le service est passé par l'appelant, qui le tient du conteneur de test :
     * il est privé, et seul ce conteneur-là l'expose.
     *
     * @return array<class-string, array{log_level: ?string, status_code: int<100, 599>|null, log_channel: ?string}>
     */
    public static function exceptionsMapping(ErrorListener $listener): array
    {
        /** @var array<class-string, array{log_level: ?string, status_code: int<100, 599>|null, log_channel: ?string}> */
        return (new \ReflectionProperty(ErrorListener::class, 'exceptionsMapping'))->getValue($listener);
    }

    /**
     * `api_platform.exception_to_status` compilé, dans l'ordre de résolution.
     *
     * @return array<string, int>
     */
    public static function exceptionToStatus(ContainerInterface $container): array
    {
        /** @var array<string, int> */
        return $container->getParameter('api_platform.exception_to_status');
    }

    /**
     * Les `exceptionToStatus` portés par une ressource ou une opération API
     * Platform, que le vendor fusionne avec le paramètre global au moment de
     * rendre l'erreur (ErrorListener::getOperationExceptionToStatus). Une même
     * classe peut y recevoir plusieurs statuts, d'une opération à l'autre :
     * chacun est gardé, pour que le niveau soit jugé contre tous.
     *
     * @param iterable<string> $resourceClasses
     *
     * @return array<string, list<int>> classe => statuts distincts
     */
    public static function resourceExceptionToStatus(ResourceMetadataCollectionFactoryInterface $factory, iterable $resourceClasses): array
    {
        $statuses = [];
        foreach ($resourceClasses as $resourceClass) {
            foreach ($factory->create($resourceClass) as $resource) {
                $mappings = [$resource->getExceptionToStatus() ?? []];
                foreach ($resource->getOperations() ?? [] as $operation) {
                    $mappings[] = $operation->getExceptionToStatus() ?? [];
                }
                foreach ($mappings as $mapping) {
                    // Non typé par API Platform (`?array`) : la forme est celle
                    // de `exception_to_status`, classe => statut.
                    /** @var array<string, int> $mapping */
                    foreach ($mapping as $class => $status) {
                        if (!\in_array($status, $statuses[$class] ?? [], true)) {
                            $statuses[$class][] = $status;
                        }
                    }
                }
            }
        }

        return $statuses;
    }

    /**
     * Les exceptions que le noyau convertit en HttpException d'après leur
     * #[WithHttpStatus] (ErrorListener::logKernelException), avec ce statut.
     * Le niveau, lui, est résolu sur l'exception d'origine : sans entrée,
     * `critical`.
     *
     * L'attribut est lu par la méthode même du noyau, qui le cherche aussi sur
     * les classes parentes et les interfaces : la réflexion échoue bruyamment
     * si Symfony la renomme, plutôt que de réimplémenter sa résolution.
     *
     * @param iterable<class-string> $classes
     *
     * @return array<class-string, list<int>> classe => statut déclaré
     */
    public static function withHttpStatus(ErrorListener $listener, iterable $classes): array
    {
        $resolve = new \ReflectionMethod(ErrorListener::class, 'getInheritedAttribute');
        $statuses = [];
        foreach ($classes as $class) {
            // Une HttpExceptionInterface garde son propre statut : le noyau ne
            // lit l'attribut que sur les autres exceptions.
            if (!is_subclass_of($class, \Throwable::class) || is_subclass_of($class, HttpExceptionInterface::class)) {
                continue;
            }
            $attribute = $resolve->invoke($listener, $class, WithHttpStatus::class);
            if ($attribute instanceof WithHttpStatus) {
                $statuses[$class] = [$attribute->statusCode];
            }
        }

        return $statuses;
    }
}
