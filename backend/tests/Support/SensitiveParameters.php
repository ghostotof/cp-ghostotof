<?php

declare(strict_types=1);

namespace App\Tests\Support;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use SensitiveParameter;

/**
 * Recense les paramètres de `src/` qui reçoivent un secret en clair, d'après
 * leur nom : les mots de passe de {@see PlainPasswordInputs} (issue #411),
 * les jetons et secrets de {@see \App\Tests\Security\SecretParametersTest}
 * (issue #414).
 *
 * Un nom de condensat (`$hashedPassword`, `$newHashedPassword`, `$tokenHash`)
 * n'est pas un secret en clair : le condensat d'un jeton aléatoire ne se
 * renverse pas. Il est écarté ici, pas par une liste d'exemptions — mais un
 * nom qui contient seulement « hash » reste vu : `$unhashedToken`,
 * `$tokenToHash`, une clé HMAC `$hashSecret` (revue de #414).
 *
 * Un paramètre est vu par son nom, ou par celui du paramètre de même position
 * dans une interface ou une classe mère `App\…` de sa classe : une
 * implémentation qui renomme `$clearToken` en `$value` reçoit le même secret,
 * et l'attribut posé sur l'interface ne cache rien à l'exécution (revue de
 * #414).
 */
final class SensitiveParameters
{
    /** `hashed` (sauf `unhashed`) n'importe où, ou `hash` en fin de nom (sauf `toHash`). */
    private const string HASHED = '/(?<!un)hashed|(?<!to)hash\z/i';

    /**
     * @param list<class-string> $classes
     * @param non-empty-string   $pattern l'expression qui dit qu'un nom désigne ce secret
     *
     * @return list<ReflectionParameter> les paramètres de ce nom, par le leur ou par celui de leur prototype, qui peuvent porter une chaîne, des méthodes de ces classes et de leurs interfaces `App\…`, sans doublon
     */
    public static function named(array $classes, string $pattern): array
    {
        $owners = [];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $owners[$class] = $reflection;
            foreach ($reflection->getInterfaces() as $interface) {
                if (str_starts_with($interface->getName(), 'App\\')) {
                    $owners[$interface->getName()] = $interface;
                }
            }
        }
        ksort($owners);

        $parameters = [];
        foreach ($owners as $ownerName => $owner) {
            foreach ($owner->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $ownerName) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    if ((self::namesAClearSecret($parameter->getName(), $pattern) || self::prototypeNamesAClearSecret($owner, $parameter, $pattern))
                        && self::mayHoldAString($parameter->getType())) {
                        $parameters[] = $parameter;
                    }
                }
            }
        }

        return $parameters;
    }

    /** `#[SensitiveParameter]` : la valeur ne figure dans aucune trace d'exception, quel que soit `zend.exception_ignore_args`. */
    public static function isHiddenFromTraces(ReflectionParameter $parameter): bool
    {
        return [] !== $parameter->getAttributes(SensitiveParameter::class);
    }

    /**
     * @param list<ReflectionParameter> $parameters
     *
     * @return list<string> les libellés de ceux qui ne sont pas cachés des traces
     */
    public static function exposed(array $parameters): array
    {
        return array_map(
            self::label(...),
            array_values(array_filter($parameters, static fn (ReflectionParameter $parameter): bool => !self::isHiddenFromTraces($parameter))),
        );
    }

    /** `Classe::méthode($paramètre)`, le libellé des messages des deux gardes. */
    public static function label(ReflectionParameter $parameter): string
    {
        return ($parameter->getDeclaringClass()?->getName() ?? '').'::'.$parameter->getDeclaringFunction()->getName().'($'.$parameter->getName().')';
    }

    /**
     * @param non-empty-string $pattern
     */
    public static function namesAClearSecret(string $name, string $pattern): bool
    {
        return 1 === preg_match($pattern, $name) && 1 !== preg_match(self::HASHED, $name);
    }

    /**
     * Le paramètre de même position, dans une interface ou une classe mère
     * `App\…` qui déclare la méthode, a un nom de secret.
     *
     * @param ReflectionClass<object> $owner
     * @param non-empty-string        $pattern
     */
    private static function prototypeNamesAClearSecret(ReflectionClass $owner, ReflectionParameter $parameter, string $pattern): bool
    {
        $ancestors = array_values($owner->getInterfaces());
        for ($parent = $owner->getParentClass(); false !== $parent; $parent = $parent->getParentClass()) {
            $ancestors[] = $parent;
        }

        $method = $parameter->getDeclaringFunction()->getName();
        foreach ($ancestors as $ancestor) {
            if (!str_starts_with($ancestor->getName(), 'App\\') || !$ancestor->hasMethod($method)) {
                continue;
            }
            $prototype = $ancestor->getMethod($method)->getParameters()[$parameter->getPosition()] ?? null;
            if (null !== $prototype && self::namesAClearSecret($prototype->getName(), $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un type absent, `string`, `mixed`, ou une union qui en contient un. Un
     * service (`UserPasswordHasherInterface $passwordHasher`,
     * `TokenStorageInterface $tokenStorage`) ou un compte (`int $maxTokens`)
     * n'est pas un secret.
     */
    public static function mayHoldAString(?ReflectionType $type): bool
    {
        if (null === $type) {
            return true;
        }

        $members = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        return array_any($members, static fn (ReflectionType $member): bool => $member instanceof ReflectionNamedType && \in_array($member->getName(), ['string', 'mixed'], true));
    }
}
