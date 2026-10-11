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
 * `$tokenToHash`, une clé HMAC `$hashSecret` (revue de #414),
 * `$secretToBeHashed` (issue #428).
 *
 * Un paramètre est vu par son nom, ou par celui du paramètre de même position
 * dans une interface ou une classe mère `App\…` de sa classe : une
 * implémentation qui renomme `$clearToken` en `$value` reçoit le même secret,
 * et l'attribut posé sur l'interface ne cache rien à l'exécution (revue de
 * #414). Une méthode privée de la mère n'est pas un prototype : elle n'est
 * pas héritée (issue #428). La méthode d'un trait utilisé est vue dans chaque classe qui
 * l'utilise, celle d'un trait sans utilisateur pour elle-même (issue #421).
 */
final class SensitiveParameters
{
    /**
     * `hashed` (sauf `unhashed`) qui qualifie le mot suivant — une majuscule
     * ou `_` après lui : `$hashedPassword`, `$newHashedToken`, `$HASHED_TOKEN`
     * —, ou `hash` en fin de nom (sauf `toHash`). Un `hashed` final reste
     * vu : `$secretToBeHashed` n'est pas haché, et `$tokenHashed`, qui le
     * serait, se renomme `$tokenHash` — un faux positif se voit, un faux
     * négatif non (issue #428).
     */
    private const string HASHED = '/(?i:(?<!un)hashed)(?=[A-Z_])|(?i:(?<!to)hash)\z/';

    /**
     * @param list<class-string> $classes
     * @param non-empty-string   $pattern l'expression qui dit qu'un nom désigne ce secret
     *
     * @return list<ReflectionParameter> les paramètres de ce nom, par le leur ou par celui de leur prototype, qui peuvent porter une chaîne, des méthodes de ces classes et de leurs interfaces `App\…`, sans doublon
     */
    public static function named(array $classes, string $pattern): array
    {
        $owners = [];
        // Un trait utilisé est lu dans chaque classe qui l'utilise, où se
        // trouve le prototype de ses méthodes : le lire aussi pour lui-même
        // signalerait le défaut une fois de plus (issue #421).
        foreach (DeclaredClasses::withoutUsedTraits($classes) as $class) {
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
            $declaration = $ancestor->getMethod($method);
            // Une méthode privée n'est pas héritée : son homonyme dans la
            // fille ne reçoit rien d'elle. Un constructeur de classe mère
            // reste un prototype, bien que PHP n'impose rien entre leurs
            // signatures : une fille qui reçoit le secret sous un autre nom
            // pour le passer à parent::__construct() serait sinon invisible,
            // et un faux négatif ne se voit pas (revue de #428).
            if ($declaration->isPrivate()) {
                continue;
            }
            $prototype = $declaration->getParameters()[$parameter->getPosition()] ?? null;
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
