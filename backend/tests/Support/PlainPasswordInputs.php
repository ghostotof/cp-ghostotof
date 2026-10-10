<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\User\Presentation\Validator\PlainPasswordLength;
use PhpToken;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use SensitiveParameter;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\Sequentially;

/**
 * Recense ce par où un mot de passe en clair entre dans `src/` (issue #411),
 * pour {@see \App\Tests\Security\User\PlainPasswordInputsTest}.
 *
 * Trois recensements, qui se recoupent pour qu'un oubli de l'un soit vu par
 * un autre :
 *  - les **champs** : toute propriété qui peut porter une chaîne et dont le
 *    nom dit « mot de passe » ({@see self::NAME}) ;
 *  - les **paramètres** du même nom, dans les classes et dans leurs interfaces
 *    `App\…` ;
 *  - les **classes qui hachent** : celles dont le code nomme un type du
 *    composant PasswordHasher, quel que soit le nom de leurs paramètres.
 *
 * Plus, pour fermer la boucle, les **appelants** d'une méthode d'interface :
 * une voie nouvelle vers un cas d'usage existant est vue même si son champ
 * s'appelle autrement.
 *
 * Un nom « haché » (`$hashedPassword`, `$newHashedPassword`) n'est pas une
 * saisie : il est écarté par le recensement, pas par une liste d'exemptions.
 */
final class PlainPasswordInputs
{
    /**
     * Ce qu'un nom de mot de passe contient, en anglais ou en français, quelle
     * que soit la casse : `password`, `newPlainPassword`, `passwd`, `pwd`,
     * `passphrase`, `motDePasse`, `mot_de_passe`. Large exprès : un faux positif
     * se règle par une ligne justifiée dans le test, un faux négatif ne se voit
     * pas.
     */
    public const string NAME = '/pass(?:word|wd|phrase)|pwd|mot_?de_?passe/i';

    /** La séquence que tout champ de mot de passe porte, dans cet ordre (revue de #386). */
    public const array SHARED_SEQUENCE = [NotBlank::class, PlainPasswordLength::class, NotCompromisedPassword::class];

    private const string HASHED = '/hash/i';

    private const string HASHER_NAMESPACE = 'Symfony\\Component\\PasswordHasher\\';

    private const array NAMES = [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED];

    private const array OBJECT_OPERATORS = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR];

    /**
     * @param list<class-string> $classes
     *
     * @return list<ReflectionProperty> les champs de mot de passe en clair déclarés par ces classes
     */
    public static function fields(array $classes): array
    {
        $fields = [];
        foreach ($classes as $class) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() === $class
                    && self::namesAPlainPassword($property->getName())
                    && self::mayHoldAString($property->getType())) {
                    $fields[] = $property;
                }
            }
        }

        return $fields;
    }

    /**
     * Pourquoi le champ ne passe pas par la séquence commune, ou null s'il y
     * passe. Exactement une contrainte, la séquence, et rien d'autre à côté :
     * une contrainte posée hors de la séquence s'évaluerait même après un
     * refus — un `Assert\Length` recopié laisserait passer jusqu'au hasher ce
     * que la séquence refuse, et le hasher en fait un 500 (issue #386).
     */
    public static function defectOf(ReflectionProperty $field): ?string
    {
        $constraints = array_map(
            static fn (ReflectionAttribute $attribute): object => $attribute->newInstance(),
            $field->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF),
        );

        if (1 !== \count($constraints) || !$constraints[0] instanceof Sequentially) {
            return \sprintf('%s porte %s au lieu de la seule Assert\Sequentially([NotBlank, PlainPasswordLength, NotCompromisedPassword]).', self::label($field), self::describe(array_map(get_debug_type(...), $constraints)));
        }

        // Le Validator normalise une contrainte seule en liste ; la propriété
        // publique admet encore les deux formes.
        $inner = $constraints[0]->constraints;
        $inner = $inner instanceof Constraint ? [$inner] : $inner;
        $sequence = array_values(array_map(get_debug_type(...), $inner));

        if (self::SHARED_SEQUENCE !== $sequence) {
            return \sprintf('%s séquence %s au lieu de [NotBlank, PlainPasswordLength, NotCompromisedPassword], dans cet ordre.', self::label($field), self::describe($sequence));
        }

        return null;
    }

    /**
     * @param list<class-string> $classes
     *
     * @return list<ReflectionParameter> les paramètres de mot de passe en clair des méthodes de ces classes et de leurs interfaces `App\…`, sans doublon
     */
    public static function parameters(array $classes): array
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
        foreach ($owners as $name => $owner) {
            foreach ($owner->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $name) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    if (self::namesAPlainPassword($parameter->getName()) && self::mayHoldAString($parameter->getType())) {
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
     * @param list<class-string> $classes
     *
     * @return list<class-string> celles dont le fichier nomme un type du composant PasswordHasher
     */
    public static function hasherUsers(array $classes): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => self::namesAPasswordHasher(self::sourceOf($class)),
        ));
    }

    /** Un `use` ou un nom complet qui désigne un type du composant PasswordHasher. */
    public static function namesAPasswordHasher(string $code): bool
    {
        return array_any(
            PhpSources::significantTokens($code),
            static fn (PhpToken $token): bool => $token->is(self::NAMES) && str_starts_with(ltrim($token->text, '\\'), self::HASHER_NAMESPACE),
        );
    }

    /**
     * @param list<class-string> $classes
     * @param class-string       $interface
     *
     * @return list<class-string> celles qui appellent $method d'$interface, hors ses implémentations
     */
    public static function callers(array $classes, string $interface, string $method): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => !is_subclass_of($class, $interface)
                && self::callsMethodOf(self::sourceOf($class), $interface, $method),
        ));
    }

    /**
     * Le code désigne l'interface — importée, écrite en entier, ou par son nom
     * court depuis son propre espace de noms — et appelle une méthode de ce
     * nom sur un objet (`->`, `?->`, sans tenir compte de la casse, comme PHP).
     * Un appel homonyme sur un autre type, dans un fichier qui importe aussi
     * l'interface, compte : faux positif accepté, qui se lit au premier coup
     * d'œil, quand l'inverse laisserait passer une voie.
     *
     * @param class-string $interface
     */
    public static function callsMethodOf(string $code, string $interface, string $method): bool
    {
        $tokens = PhpSources::significantTokens($code);
        $shortName = substr($interface, (int) strrpos($interface, '\\') + 1);
        $interfaceNamespace = substr($interface, 0, (int) strrpos($interface, '\\'));

        $namespace = '';
        $designated = false;
        $called = false;
        foreach ($tokens as $index => $token) {
            if ($token->is(\T_NAMESPACE)) {
                $namespace = $tokens[$index + 1]->text ?? '';
            } elseif ($token->is(self::NAMES) && ltrim($token->text, '\\') === $interface) {
                $designated = true;
            } elseif ($token->is(\T_STRING) && $token->text === $shortName && $namespace === $interfaceNamespace) {
                $designated = true;
            } elseif ($token->is(self::OBJECT_OPERATORS) && self::isCallTo($tokens, $index + 1, $method)) {
                $called = true;
            }
        }

        return $designated && $called;
    }

    public static function label(ReflectionProperty|ReflectionParameter $element): string
    {
        if ($element instanceof ReflectionProperty) {
            return $element->getDeclaringClass()->getName().'::$'.$element->getName();
        }

        $function = $element->getDeclaringFunction();
        $owner = $element->getDeclaringClass()?->getName() ?? '';

        return $owner.'::'.$function->getName().'($'.$element->getName().')';
    }

    private static function namesAPlainPassword(string $name): bool
    {
        return 1 === preg_match(self::NAME, $name) && 1 !== preg_match(self::HASHED, $name);
    }

    /**
     * Un type absent, `string`, `mixed`, ou une union qui en contient un. Un
     * service (`UserPasswordHasherInterface $passwordHasher`) n'est pas une
     * saisie.
     */
    private static function mayHoldAString(?ReflectionType $type): bool
    {
        if (null === $type) {
            return true;
        }

        $members = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        return array_any($members, fn(ReflectionType $member): bool => $member instanceof ReflectionNamedType && \in_array($member->getName(), ['string', 'mixed'], true));
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function isCallTo(array $tokens, int $index, string $method): bool
    {
        return isset($tokens[$index], $tokens[$index + 1])
            && $tokens[$index]->is(\T_STRING)
            && 0 === strcasecmp($tokens[$index]->text, $method)
            && '(' === $tokens[$index + 1]->text;
    }

    /**
     * @param class-string $class
     */
    private static function sourceOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();

        return false === $file ? '' : (string) file_get_contents($file);
    }

    /**
     * @param array<array-key, string> $types noms complets des contraintes
     */
    private static function describe(array $types): string
    {
        if ([] === $types) {
            return 'aucune contrainte';
        }

        return '['.implode(', ', array_map(static fn (string $type): string => substr($type, (int) strrpos('\\'.$type, '\\')), $types)).']';
    }
}
