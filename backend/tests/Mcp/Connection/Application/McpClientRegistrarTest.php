<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\McpClientRegistrar;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use DateTimeImmutable;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Throwable;

/**
 * L'enregistrement d'un client MCP (#647), sur un dépôt factice : la lecture
 * préalable, puis la contrainte unique comme dernier rempart — reconnue par le
 * nom de son index, jamais supposée.
 */
final class McpClientRegistrarTest extends TestCase
{
    private const string CLIENT_ID = 'claude-code-dev';

    public function testRegistersTheClientWithItsRedirectsAtTheClockInstant(): void
    {
        $repository = $this->repository();
        $clock = new MockClock(new DateTimeImmutable('2026-10-25T02:30:00+02:00'));

        $client = new McpClientRegistrar($repository, $clock)->register(self::CLIENT_ID, 'Claude Code', [
            RedirectUri::fromString('http://localhost/callback'),
            RedirectUri::fromString('https://app.example/cb'),
        ]);

        self::assertSame([$client], $repository->added);
        self::assertSame(self::CLIENT_ID, $client->getClientId());
        self::assertSame('Claude Code', $client->getName());
        self::assertSame(['http://localhost/callback', 'https://app.example/cb'], $client->redirectUris());
        self::assertSame($clock->now()->getTimestamp(), $client->registeredAt()->getTimestamp());
    }

    public function testAClientIdAlreadyRegisteredIsRefusedBeforeAnyWrite(): void
    {
        $repository = $this->repository(existing: McpClient::register(self::CLIENT_ID, 'Premier', ['http://localhost/callback'], new DateTimeImmutable()));

        try {
            $this->register($repository);
            self::fail('L\'enregistrement aurait dû être refusé.');
        } catch (McpClientAlreadyRegisteredException $exception) {
            self::assertSame(McpClientAlreadyRegisteredException::alreadyTaken()->getMessage(), $exception->getMessage());
        }

        self::assertSame([], $repository->added);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clientIdViolations(): iterable
    {
        yield 'message anglais' => [self::english(McpClient::CLIENT_ID_UNIQUE_INDEX, 'client_id', self::CLIENT_ID)];
        // lc_messages en français : chevrons, et espaces insécables autour.
        yield 'message français' => ["ERREUR:  la valeur d'une clé dupliquée rompt la contrainte unique «\u{a0}".McpClient::CLIENT_ID_UNIQUE_INDEX."\u{a0}»\nDÉTAIL : La clé « (client_id)=(claude-code-dev) » existe déjà."];
    }

    /**
     * Deux enregistrements simultanés : la lecture n'a rien vu, la base refuse
     * sous l'index de `client_id`. Le refus nommé ne cite pas l'identifiant et
     * ne garde pas la violation.
     */
    #[DataProvider('clientIdViolations')]
    public function testAViolationOfTheClientIdIndexIsTakenMeanwhile(string $driverMessage): void
    {
        $repository = $this->repository(failure: $this->violation($driverMessage));

        try {
            $this->register($repository);
            self::fail('L\'enregistrement aurait dû être refusé.');
        } catch (McpClientAlreadyRegisteredException $exception) {
            self::assertSame(McpClientAlreadyRegisteredException::takenMeanwhile()->getMessage(), $exception->getMessage());
            self::assertStringNotContainsString(self::CLIENT_ID, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherViolations(): iterable
    {
        yield 'autre index' => [self::english('uniq_mcp_client_other', 'other', 'x')];
        // Le DETAIL cite la valeur : un identifiant qui porte le nom de l'index
        // ne doit pas faire prendre une autre violation pour la sienne.
        yield 'autre index, valeur qui cite le nôtre' => [self::english('uniq_mcp_client_other', 'other', '"'.McpClient::CLIENT_ID_UNIQUE_INDEX.'"')];
        yield 'aucun index cité' => ['ERROR:  duplicate key value'];
    }

    /** Toute autre unicité remonte telle quelle : aucune cause affirmée à tort. */
    #[DataProvider('otherViolations')]
    public function testAnyOtherUniqueViolationIsRethrownAsIs(string $driverMessage): void
    {
        $violation = $this->violation($driverMessage);
        $repository = $this->repository(failure: $violation);

        try {
            $this->register($repository);
            self::fail('La violation aurait dû remonter.');
        } catch (Throwable $thrown) {
            self::assertSame($violation, $thrown);
        }
    }

    private function register(McpClientRepositoryInterface $repository): McpClient
    {
        return new McpClientRegistrar($repository, new MockClock())->register(self::CLIENT_ID, 'Claude Code', [RedirectUri::fromString('http://localhost/callback')]);
    }

    private static function english(string $index, string $column, string $value): string
    {
        return \sprintf("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"%s\"\nDETAIL:  Key (%s)=(%s) already exists.", $index, $column, $value);
    }

    private function violation(string $driverMessage): UniqueConstraintViolationException
    {
        $driverException = new class($driverMessage, '23505') extends AbstractException {
        };

        return new UniqueConstraintViolationException($driverException, null);
    }

    /**
     * Un dépôt en mémoire : `existing` répond à la lecture, `failure` est levée
     * par l'ajout, `added` garde ce qui a été ajouté.
     */
    private function repository(?McpClient $existing = null, ?Throwable $failure = null): McpClientRepositoryFake
    {
        return new McpClientRepositoryFake($existing, $failure);
    }
}
