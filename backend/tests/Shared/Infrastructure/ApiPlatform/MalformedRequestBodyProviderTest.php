<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Infrastructure\ApiPlatform\MalformedRequestBodyException;
use App\Shared\Infrastructure\ApiPlatform\MalformedRequestBodyProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\LogicException as SerializerLogicException;
use Symfony\Component\Serializer\Exception\MappingException;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\UnsupportedFormatException;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Le décorateur de la désérialisation d'API Platform (issue #355) : ce que le
 * Serializer refuse dans le corps de la requête devient une erreur du client
 * précise, tout le reste passe tel quel.
 */
final class MalformedRequestBodyProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function serializerFailures(): iterable
    {
        yield 'JSON illisible' => [new NotEncodableValueException('Syntax error')];
        yield 'racine qui n\'est pas un objet' => [NotNormalizableValueException::createForUnexpectedDataType('Mauvais type.', 123, ['array'])];
        // Hors de la famille UnexpectedValueException, mais tout aussi
        // provoquées par le corps (issue #360) : aucune opération ne les
        // déclenche aujourd'hui — `allow_extra_attributes` reste à vrai,
        // `collect_denormalization_errors` collecte l'argument manquant en
        // 422 —, mais une opération qui changerait l'un ou l'autre en ferait,
        // depuis que l'entrée large du Serializer rend 500, un 500 `critical`
        // à la portée de n'importe quel anonyme.
        yield 'attribut inconnu refusé' => [new ExtraAttributesException(['zzz'])];
        yield 'argument de constructeur absent' => [new MissingConstructorArgumentsException('Cannot create an instance of "stdClass" from serialized data because its constructor requires the following parameters to be present : "$name".', 0, null, ['name'], \stdClass::class)];
    }

    #[DataProvider('serializerFailures')]
    public function testASerializerFailureBecomesAMalformedRequestBody(\Throwable $failure): void
    {
        $provider = new MalformedRequestBodyProvider($this->throwing($failure));

        try {
            $provider->provide(self::writing());
            self::fail('Une MalformedRequestBodyException était attendue.');
        } catch (MalformedRequestBodyException $exception) {
            // La cause reste chaînée : c'est elle que porte le journal.
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    /**
     * Le message rendu au client est fixe : celui du Serializer cite parfois
     * la classe de la ressource (« The type of the "App\…Resource" resource
     * must be… »), un détail interne qui n'a rien à faire dans un 400.
     */
    public function testTheMessageNamesNoInternalClass(): void
    {
        $provider = new MalformedRequestBodyProvider($this->throwing(NotNormalizableValueException::createForUnexpectedDataType('The type of the "App\Foo\BarResource" resource must be "array".', 123, ['array'])));

        $this->expectExceptionMessage('Le corps de la requête n\'est pas un document JSON exploitable.');

        $provider->provide(self::writing());
    }

    /**
     * @return iterable<string, array{\Throwable, HttpOperation}>
     */
    public static function failuresThatAreNotTheClients(): iterable
    {
        // Un 422 de validation, déjà rendu au bon niveau.
        yield 'validation (422)' => [new ValidationException(new ConstraintViolationList()), self::writing()];
        // Défauts du serveur levés pendant la désérialisation : configuration
        // du Serializer, métadonnées de mapping. Ils doivent rester des 500
        // journalisés en `critical`, pas devenir un 400 `info`.
        yield 'Serializer mal configuré' => [new SerializerLogicException('Cannot denormalize: no denormalizer.'), self::writing()];
        yield 'métadonnées de mapping invalides' => [new MappingException('Invalid mapping.'), self::writing()];
        // Sous-classe de NotEncodableValueException, mais le format d'entrée a
        // déjà passé la négociation de contenu : sans encodeur pour lui, c'est
        // la configuration des formats qui est fausse.
        yield 'format déclaré sans encodeur' => [new UnsupportedFormatException('Deserialization for the format "xml" is not supported.'), self::writing()];
        // Une opération qui ne lit pas de corps (lecture) : une exception du
        // Serializer y vient forcément d'ailleurs que de la requête.
        yield 'lecture, sans corps à désérialiser' => [new NotEncodableValueException('Syntax error'), new Get()->withDeserialize(false)];
    }

    /**
     * Le contre-exemple : seul le corps de la requête est requalifié, et
     * l'exception d'origine traverse intacte — la même instance, pas une
     * reconstruction qui perdrait sa trace.
     */
    #[DataProvider('failuresThatAreNotTheClients')]
    public function testAnyOtherFailurePassesThroughUntouched(\Throwable $failure, HttpOperation $operation): void
    {
        $provider = new MalformedRequestBodyProvider($this->throwing($failure));

        try {
            $provider->provide($operation);
            self::fail('L\'exception d\'origine était attendue.');
        } catch (\Throwable $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testTheProvidedDataIsReturnedAsIs(): void
    {
        $data = new \stdClass();
        $provider = new MalformedRequestBodyProvider(new readonly class($data) implements ProviderInterface {
            public function __construct(private object $data)
            {
            }

            public function provide(Operation $operation, array $uriVariables = [], array $context = []): object
            {
                return $this->data;
            }
        });

        self::assertSame($data, $provider->provide(self::writing()));
    }

    /**
     * @return ProviderInterface<object>
     */
    private function throwing(\Throwable $failure): ProviderInterface
    {
        return new readonly class($failure) implements ProviderInterface {
            public function __construct(private \Throwable $failure)
            {
            }

            public function provide(Operation $operation, array $uriVariables = [], array $context = []): never
            {
                throw $this->failure;
            }
        };
    }

    /**
     * Une écriture telle que la fabrique de métadonnées d'API Platform la
     * livre : `deserialize` vaut `null` sur une opération brute.
     */
    private static function writing(): Post
    {
        return new Post()->withDeserialize();
    }
}
