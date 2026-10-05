<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Infrastructure\ApiPlatform\MalformedRequestBodyException;
use App\Shared\Infrastructure\ApiPlatform\MalformedRequestBodyProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
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
    }

    #[DataProvider('serializerFailures')]
    public function testASerializerFailureBecomesAMalformedRequestBody(\Throwable $failure): void
    {
        $provider = new MalformedRequestBodyProvider($this->throwing($failure));

        try {
            $provider->provide(new Post());
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

        $provider->provide(new Post());
    }

    /**
     * Le contre-exemple : un 422 de validation, déjà rendu au bon niveau, ne
     * doit pas être requalifié en 400.
     */
    public function testAnyOtherFailurePassesThroughUntouched(): void
    {
        $validation = new ValidationException(new ConstraintViolationList());
        $provider = new MalformedRequestBodyProvider($this->throwing($validation));

        $this->expectExceptionObject($validation);

        $provider->provide(new Post());
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

        self::assertSame($data, $provider->provide(new Post()));
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
}
