<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Session;

use App\Mcp\Infrastructure\Session\BoundedSessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Les échecs que `Psr16SessionStore` avale (il rend `false` sur toute
 * exception) laissent une trace sur le canal `mcp` : sans elle, une session
 * vidée en silence n'expliquerait rien des erreurs que voit le client.
 */
final class BoundedSessionStoreTest extends TestCase
{
    private TestHandler $mcpLog;

    protected function setUp(): void
    {
        $this->mcpLog = new TestHandler();
    }

    /**
     * Une session présente mais illisible — le MAC du cache refusé après une
     * rotation d'`APP_SECRET`, deux réplicas sur deux secrets pendant un
     * déploiement — repartait vide sans un mot (relecture de la tâche 2).
     */
    public function testASessionPresentButUnreadableIsLogged(): void
    {
        $store = $this->storeOver(exists: true, read: false);

        self::assertFalse($store->read(Uuid::v4()));
        self::assertTrue($this->mcpLog->hasRecordThatContains('MCP session unreadable', Level::Warning));
    }

    /**
     * Un identifiant inconnu n'est pas un échec : rien à journaliser.
     */
    public function testAnAbsentSessionIsNotLogged(): void
    {
        $store = $this->storeOver(exists: false, read: false);

        self::assertFalse($store->read(Uuid::v4()));
        self::assertFalse($this->mcpLog->hasWarningRecords());
    }

    public function testAReadableSessionIsReturnedAsIs(): void
    {
        $store = $this->storeOver(exists: true, read: '{"queue":[]}');

        self::assertSame('{"queue":[]}', $store->read(Uuid::v4()));
        self::assertFalse($this->mcpLog->hasWarningRecords());
    }

    /**
     * Un échec d'écriture du store interne (sous la borne) est journalisé
     * lui aussi, avec la seule taille.
     */
    public function testAWriteTheInnerStoreRefusesIsLogged(): void
    {
        $inner = self::createStub(SessionStoreInterface::class);
        $inner->method('write')->willReturn(false);
        $store = new BoundedSessionStore($inner, new Logger('mcp', [$this->mcpLog]));

        self::assertFalse($store->write(Uuid::v4(), '{"queue":[]}'));
        self::assertTrue($this->mcpLog->hasRecordThatContains('MCP session not saved by the store', Level::Warning));
    }

    private function storeOver(bool $exists, string|false $read): BoundedSessionStore
    {
        $inner = self::createStub(SessionStoreInterface::class);
        $inner->method('exists')->willReturn($exists);
        $inner->method('read')->willReturn($read);

        return new BoundedSessionStore($inner, new Logger('mcp', [$this->mcpLog]));
    }
}
