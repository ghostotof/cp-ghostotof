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
        $id = Uuid::v4();

        // L'identifiant reçu du client : le SDK en vérifie l'existence avant
        // de lire la session (Mcp\Server\Protocol::resolveSession()).
        self::assertTrue($store->exists($id));
        self::assertFalse($store->read($id));
        self::assertTrue($this->mcpLog->hasRecordThatContains('MCP session unreadable', Level::Warning));
    }

    /**
     * Une session neuve (`initialize`) : le SDK la lit dans `save()` sans
     * jamais en avoir vérifié l'existence, et la lecture rend `false` à chaque
     * fois. Pas de contrôle d'existence pour elle — une requête SQL de plus
     * par `initialize` (#663, point 2) —, ni de trace : elle n'a jamais existé.
     */
    public function testANewSessionReadsWithoutAnExistenceCheck(): void
    {
        $inner = $this->createMock(SessionStoreInterface::class);
        $inner->expects(self::never())->method('exists');
        $inner->method('read')->willReturn(false);
        $store = new BoundedSessionStore($inner, new Logger('mcp', [$this->mcpLog]));

        self::assertFalse($store->read(Uuid::v4()));
        self::assertFalse($this->mcpLog->hasWarningRecords());
    }

    /**
     * Un identifiant reçu, présent au contrôle puis disparu à la lecture
     * (expiré entre les deux) : le second contrôle le dit absent, rien à
     * journaliser.
     */
    public function testAReceivedSessionGoneBeforeTheReadIsNotLogged(): void
    {
        $inner = self::createStub(SessionStoreInterface::class);
        $inner->method('exists')->willReturn(true, false);
        $inner->method('read')->willReturn(false);
        $store = new BoundedSessionStore($inner, new Logger('mcp', [$this->mcpLog]));
        $id = Uuid::v4();

        self::assertTrue($store->exists($id));
        self::assertFalse($store->read($id));
        self::assertFalse($this->mcpLog->hasWarningRecords());
    }

    /**
     * Un runtime persistant : ce qu'une requête a reçu ne vaut pas pour la
     * suivante. Après `reset()`, la lecture ne refait pas le contrôle.
     */
    public function testResetForgetsTheSessionsReceivedByTheRequest(): void
    {
        $inner = $this->createMock(SessionStoreInterface::class);
        $inner->expects(self::once())->method('exists')->willReturn(true);
        $inner->method('read')->willReturn(false);
        $store = new BoundedSessionStore($inner, new Logger('mcp', [$this->mcpLog]));
        $id = Uuid::v4();

        $store->exists($id);
        $store->reset();

        self::assertFalse($store->read($id));
        self::assertFalse($this->mcpLog->hasWarningRecords());
    }

    /**
     * Un identifiant inconnu n'est pas un échec : rien à journaliser.
     */
    public function testAnAbsentSessionIsNotLogged(): void
    {
        $store = $this->storeOver(exists: false, read: false);
        $id = Uuid::v4();

        self::assertFalse($store->exists($id));
        self::assertFalse($store->read($id));
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
