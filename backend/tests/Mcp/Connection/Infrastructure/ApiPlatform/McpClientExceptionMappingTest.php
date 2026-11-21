<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\ApiPlatform;

use App\Mcp\Connection\Domain\Exception\InvalidMcpClientRedirectsException;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\Exception\McpClientDeclaredByDocumentException;
use App\Mcp\Connection\Domain\Exception\McpClientIdOfVerifiedDomainException;
use App\Mcp\Connection\Domain\Exception\McpClientNotFoundException;
use App\Tests\Support\CompiledExceptionConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;

/**
 * Statuts et niveaux de journal des refus de client MCP (#652), lus dans la
 * configuration compilée du conteneur de test, comme le fait le noyau.
 *
 * `McpClientDeclaredByDocumentException` porte son statut (409) par
 * ProblemExceptionInterface : elle n'a pas d'entrée dans `exception_to_status`
 * et n'est donc pas dans le premier test.
 */
final class McpClientExceptionMappingTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string, int}>
     */
    public static function mappedStatuses(): iterable
    {
        yield 'client inconnu' => [McpClientNotFoundException::class, 404];
        yield 'client déjà enregistré' => [McpClientAlreadyRegisteredException::class, 409];
        yield 'redirections invalides' => [InvalidMcpClientRedirectsException::class, 422];
        yield 'identifiant d\'un domaine vérifié' => [McpClientIdOfVerifiedDomainException::class, 422];
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function loggedClasses(): iterable
    {
        yield 'client inconnu' => [McpClientNotFoundException::class];
        yield 'client déjà enregistré' => [McpClientAlreadyRegisteredException::class];
        yield 'redirections invalides' => [InvalidMcpClientRedirectsException::class];
        yield 'identifiant d\'un domaine vérifié' => [McpClientIdOfVerifiedDomainException::class];
        yield 'client déclaré par son document' => [McpClientDeclaredByDocumentException::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('mappedStatuses')]
    public function testTheStatusIsMappedAheadOfTheBroadSerializerEntry(string $class, int $status): void
    {
        $map = $this->exceptionToStatus();

        self::assertArrayHasKey($class, $map);
        self::assertSame($status, $map[$class]);

        // Première entrée qui correspond gagne : l'entrée large de fin
        // (Serializer\ExceptionInterface, 500) doit rester derrière.
        $order = array_flip(array_keys($map));
        self::assertArrayHasKey(SerializerExceptionInterface::class, $order);
        self::assertLessThan($order[SerializerExceptionInterface::class], $order[$class]);
    }

    public function testTheDocumentDeclaredRefusalCarriesItsOwnStatus(): void
    {
        self::assertSame(409, McpClientDeclaredByDocumentException::cannotBeRevised()->getStatus());
        self::assertSame(404, McpClientNotFoundException::forTheCaller()->getStatus());
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('loggedClasses')]
    public function testARefusalIsLoggedAtInfoWithoutStatusCode(string $class): void
    {
        $mapping = CompiledExceptionConfig::exceptionsMapping($this->errorListener());

        self::assertArrayHasKey($class, $mapping);
        self::assertSame('info', $mapping[$class]['log_level']);
        // Jamais de status_code : il ferait perdre le `type` problem+json (#322).
        self::assertNull($mapping[$class]['status_code']);
    }

    /**
     * @return array<class-string, int>
     */
    private function exceptionToStatus(): array
    {
        self::bootKernel();
        /** @var array<class-string, int> $map */
        $map = self::getContainer()->getParameter('api_platform.exception_to_status');

        return $map;
    }

    private function errorListener(): ErrorListener
    {
        self::bootKernel();

        return self::getContainer()->get('exception_listener');
    }
}
