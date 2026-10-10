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
 * Un nom « haché » (`$hashedPassword`, `$tokenHash`) n'est pas un secret en
 * clair : le condensat d'un jeton aléatoire ne se renverse pas. Il est écarté
 * ici, pas par une liste d'exemptions.
 */
final class SensitiveParameters
{
    private const string HASHED = '/hash/i';

    /**
     * @param list<class-string> $classes
     * @param non-empty-string   $name    l'expression qui dit qu'un nom désigne ce secret
     *
     * @return list<ReflectionParameter> les paramètres de ce nom, qui peuvent porter une chaîne, des méthodes de ces classes et de leurs interfaces `App\…`, sans doublon
     */
    public static function named(array $classes, string $name): array
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
                    if (self::namesAClearSecret($parameter->getName(), $name) && self::mayHoldAString($parameter->getType())) {
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
     * @param non-empty-string $name
     */
    public static function namesAClearSecret(string $element, string $name): bool
    {
        return 1 === preg_match($name, $element) && 1 !== preg_match(self::HASHED, $element);
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
