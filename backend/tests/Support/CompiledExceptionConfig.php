<?php

declare(strict_types=1);

namespace App\Tests\Support;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;
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
        return self::vendorMember(static fn (): \ReflectionProperty => new \ReflectionProperty(ErrorListener::class, 'exceptionsMapping'))->getValue($listener);
    }

    /**
     * Un ErrorListener du noyau construit avec ces seules entrées de
     * `framework.exceptions`, pour éprouver les lectures de cette classe hors
     * de la configuration de l'application.
     *
     * @param array<class-string, array{log_level?: string, status_code?: int<100, 599>}> $entries dans l'ordre
     */
    public static function listenerWith(array $entries): ErrorListener
    {
        return new ErrorListener(null, null, false, array_map(
            static fn (array $options): array => ['log_level' => $options['log_level'] ?? null, 'status_code' => $options['status_code'] ?? null, 'log_channel' => null],
            $entries,
        ));
    }

    /**
     * Le statut avec lequel le noyau rend chaque exception qu'il convertit
     * lui-même (ErrorListener::logKernelException), dans son ordre : la
     * première entrée de `framework.exceptions` qui correspond par
     * `instanceof` et fixe un `status_code`, à défaut un #[WithHttpStatus]
     * hérité — que le noyau ne lit pas sur une HttpExceptionInterface. Le niveau
     * reste résolu sur l'exception d'origine : sans entrée, `critical`.
     *
     * Une classe abstraite ou une interface n'est jamais levée telle quelle :
     * ses sous-classes concrètes le sont, et sont recensées pour elles-mêmes.
     *
     * @param iterable<string> $classes
     *
     * @return array<string, int> classe => statut rendu
     */
    public static function kernelHttpStatus(ErrorListener $listener, iterable $classes): array
    {
        $mapping = self::exceptionsMapping($listener);
        $statuses = [];
        foreach ($classes as $class) {
            if (!is_subclass_of($class, \Throwable::class) || self::neverThrown($class)) {
                continue;
            }
            $status = array_find($mapping, static fn (array $options, string $key): bool => null !== $options['status_code'] && is_a($class, $key, true))['status_code'] ?? null;
            if (null === $status && !is_subclass_of($class, HttpExceptionInterface::class)) {
                $attribute = self::inheritedAttribute($listener, $class, WithHttpStatus::class);
                $status = $attribute instanceof WithHttpStatus ? $attribute->statusCode : null;
            }
            if (null !== $status) {
                $statuses[$class] = $status;
            }
        }

        return $statuses;
    }

    /**
     * Les tables `exception_to_status` qu'API Platform peut consulter pour
     * rendre une erreur (ErrorListener::getStatusCode) : le paramètre global
     * seul — hors de toute opération —, et pour chaque opération ce même
     * paramètre fusionné avec la table de l'opération, puis aussi avec celles
     * de la ressource (getOperationExceptionToStatus, quand l'opération est
     * retrouvée depuis la requête). La fusion est celle du vendor, array_merge :
     * une clé déjà présente garde sa place et prend la nouvelle valeur.
     *
     * @param array<string, int> $global          `api_platform.exception_to_status`
     * @param iterable<string>   $resourceClasses
     *
     * @return list<array<string, int>>
     */
    public static function apiPlatformMappings(ResourceMetadataCollectionFactoryInterface $factory, iterable $resourceClasses, array $global): array
    {
        $mappings = [$global];
        foreach ($resourceClasses as $resourceClass) {
            $collection = $factory->create($resourceClass);
            $resourceMappings = [];
            foreach ($collection as $resource) {
                $resourceMappings[] = self::mapping($resource->getExceptionToStatus());
            }
            foreach ($collection as $resource) {
                foreach ($resource->getOperations() ?? [] as $operation) {
                    $operationMapping = self::mapping($operation->getExceptionToStatus());
                    $mappings[] = array_merge($global, $operationMapping);
                    $mappings[] = array_merge($global, $operationMapping, ...$resourceMappings);
                }
            }
        }

        return $mappings;
    }

    /**
     * Les statuts qu'une exception peut recevoir de ces tables : dans chacune,
     * celui de la première clé qui correspond par is_a(), comme le vendor.
     *
     * @param list<array<string, int>> $mappings
     *
     * @return list<int> distincts, dans l'ordre des tables
     */
    public static function statusesFor(array $mappings, string $class): array
    {
        $statuses = [];
        foreach ($mappings as $mapping) {
            $status = array_find($mapping, static fn (int $status, string $key): bool => is_a($class, $key, true));
            if (null !== $status && !\in_array($status, $statuses, true)) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    /**
     * Une table d'API Platform, non typée par le vendor (`?array`) : sa forme
     * est celle de `exception_to_status`, classe => statut.
     *
     * @param array<mixed>|null $mapping
     *
     * @return array<string, int>
     */
    private static function mapping(?array $mapping): array
    {
        /** @var array<string, int> $typed */
        $typed = $mapping ?? [];

        return $typed;
    }

    /**
     * Une classe abstraite ou une interface : jamais levée telle quelle.
     */
    private static function neverThrown(string $class): bool
    {
        if (!class_exists($class) && !interface_exists($class)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);

        return $reflection->isAbstract() || $reflection->isInterface();
    }

    /**
     * Le niveau que fixe #[WithLogLevel], lu par la même méthode du noyau que
     * #[WithHttpStatus] : sur la classe, ses parentes et ses interfaces. Le
     * noyau ne s'en sert qu'à défaut d'entrée dans `framework.exceptions`.
     *
     * @param iterable<string> $classes
     *
     * @return array<string, string> classe => niveau
     */
    public static function logLevelAttributes(ErrorListener $listener, iterable $classes): array
    {
        $levels = [];
        foreach ($classes as $class) {
            $attribute = is_subclass_of($class, \Throwable::class) ? self::inheritedAttribute($listener, $class, WithLogLevel::class) : null;
            if ($attribute instanceof WithLogLevel) {
                $levels[$class] = $attribute->level;
            }
        }

        return $levels;
    }

    /**
     * Le niveau auquel le noyau journalise une exception de cette classe : sa
     * propre résolution (ErrorListener::resolveLogLevel) — première entrée de
     * `framework.exceptions` qui correspond par `instanceof` et fixe un niveau,
     * à défaut #[WithLogLevel] hérité, à défaut `critical` (ou `error` pour une
     * HttpException 4xx) —, appelée plutôt que réécrite, pour ne jamais en
     * diverger.
     *
     * L'exception est construite sans son constructeur — la résolution ne lit
     * que sa classe —, ce qui vaut aussi pour un constructeur privé (les
     * exceptions à constructeur nommé). Une classe abstraite ou une interface
     * n'est jamais levée telle quelle : null — kernelLogLevelOf() en résout
     * une implémentation.
     *
     * @param class-string<\Throwable> $class
     */
    public static function kernelLogLevel(ErrorListener $listener, string $class): ?string
    {
        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return null;
        }

        return self::kernelLogLevelOf($listener, $reflection->newInstanceWithoutConstructor());
    }

    /**
     * Le niveau que le noyau retient pour cette exception-ci, par la même
     * résolution que kernelLogLevel(). Pour une clé qui est une interface ou
     * une classe abstraite, l'appelant passe un double de test (issue #373) :
     * son niveau est celui d'une implémentation qu'aucune entrée plus précise
     * ne vise.
     */
    public static function kernelLogLevelOf(ErrorListener $listener, \Throwable $throwable): ?string
    {
        $resolve = self::vendorMember(static fn (): \ReflectionMethod => new \ReflectionMethod(ErrorListener::class, 'resolveLogLevel'));
        $level = $resolve->invoke($listener, $throwable);

        return \is_string($level) ? $level : null;
    }

    /**
     * Un attribut de la classe, de ses parentes ou de ses interfaces, lu par la
     * méthode du noyau (ErrorListener::getInheritedAttribute).
     */
    private static function inheritedAttribute(ErrorListener $listener, string $class, string $attribute): ?object
    {
        $resolve = self::vendorMember(static fn (): \ReflectionMethod => new \ReflectionMethod(ErrorListener::class, 'getInheritedAttribute'));
        $found = $resolve->invoke($listener, $class, $attribute);

        return \is_object($found) ? $found : null;
    }

    /**
     * Un membre interne de l'ErrorListener, que Symfony peut renommer à toute
     * montée de version : l'échec nomme le test de contrat à consulter plutôt
     * qu'une ReflectionException sans contexte.
     *
     * @template T of \Reflector
     *
     * @param \Closure(): T $reflect
     *
     * @return T
     */
    private static function vendorMember(\Closure $reflect): \Reflector
    {
        try {
            return $reflect();
        } catch (\ReflectionException $exception) {
            throw new \LogicException('Interne de l\'ErrorListener introuvable, Symfony l\'a sans doute changé : voir ErrorListenerInternalsTest, puis adapter CompiledExceptionConfig.', 0, $exception);
        }
    }
}
