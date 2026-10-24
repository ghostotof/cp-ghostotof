<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Session;

use App\Mcp\Infrastructure\Session\BoundedSessionStore;
use App\Tests\Mcp\Fixtures\ConnectionPrincipal;
use App\Tests\Mcp\Fixtures\RecordingSessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionStoreInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

/**
 * Les échecs que `Psr16SessionStore` avale (il rend `false` sur toute
 * exception) laissent une trace sur le canal `mcp` : sans elle, une session
 * vidée en silence n'expliquerait rien des erreurs que voit le client.
 *
 * Et la liaison d'une session à la connexion MCP qui l'a utilisée la
 * première (#648, décision de l'auteur du 2026-10-24) : une fois liée, elle
 * n'existe pour aucune autre requête que celles de cette connexion, et la
 * liaison part dans la même écriture que la file qui porte la réponse.
 */
final class BoundedSessionStoreTest extends TestCase
{
    /** L'en-tête d'une session liée, recopié du décorateur : un oracle à part. */
    private const string BOUND_TO = 'mcp-connection:';

    /** La clé de la file sortante du SDK (`Protocol::SESSION_OUTGOING_QUEUE`, privée). */
    private const string OUTGOING_QUEUE = '_mcp.outgoing_queue';

    private TestHandler $mcpLog;

    protected function setUp(): void
    {
        $this->mcpLog = new TestHandler();
    }

    /**
     * Une session présente mais illisible — le MAC du cache refusé après une
     * rotation d'`APP_SECRET`, deux réplicas sur deux secrets pendant un
     * déploiement — repartait vide sans un mot (relecture de la tâche 2), puis
     * vide mais journalisée : le SDK la réécrivait vide sous le même
     * identifiant, et le client ne se réinitialisait jamais. Elle n'existe
     * plus (#673) : le SDK répond 404, comme le GET.
     */
    public function testASessionPresentButUnreadableDoesNotExist(): void
    {
        $store = $this->storeOver(exists: true, read: false);
        $id = Uuid::v4();

        // L'identifiant reçu du client : le SDK en vérifie l'existence avant
        // de lire la session (Mcp\Server\Protocol::resolveSession()).
        self::assertFalse($store->exists($id));
        self::assertTrue($this->mcpLog->hasRecordThatContains('MCP session unreadable', Level::Warning));
    }

    /**
     * Le contrôle d'existence lit la session ; la lecture que le SDK fait
     * ensuite rend ce qu'il a lu, sans seconde requête.
     */
    public function testAReceivedSessionIsReadOnce(): void
    {
        $inner = $this->createMock(SessionStoreInterface::class);
        $inner->method('exists')->willReturn(true);
        $inner->expects(self::once())->method('read')->willReturn('{"queue":[]}');
        $store = new BoundedSessionStore($inner, new TokenStorage(), new Logger('mcp', [$this->mcpLog]));
        $id = Uuid::v4();

        self::assertTrue($store->exists($id));
        self::assertSame('{"queue":[]}', $store->read($id));
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
        $store = new BoundedSessionStore($inner, new TokenStorage(), new Logger('mcp', [$this->mcpLog]));

        self::assertFalse($store->read(Uuid::v4()));
        self::assertFalse($this->mcpLog->hasWarningRecords());
    }

    /**
     * Un identifiant reçu dont la session a expiré : la lecture échoue, et le
     * contrôle qui la suit la dit absente, pas illisible. Rien à journaliser.
     */
    public function testAReceivedSessionThatExpiredIsNotLogged(): void
    {
        $inner = self::createStub(SessionStoreInterface::class);
        $inner->method('exists')->willReturn(false);
        $inner->method('read')->willReturn(false);
        $store = new BoundedSessionStore($inner, new TokenStorage(), new Logger('mcp', [$this->mcpLog]));

        self::assertFalse($store->exists(Uuid::v4()));
        self::assertFalse($this->mcpLog->hasWarningRecords());
    }

    /**
     * Un runtime persistant : ce qu'une requête a lu ne vaut pas pour la
     * suivante. Après `reset()`, la lecture repasse par le store.
     */
    public function testResetForgetsTheSessionsReadByTheRequest(): void
    {
        $inner = $this->createMock(SessionStoreInterface::class);
        $inner->method('exists')->willReturn(true);
        $inner->expects(self::exactly(2))->method('read')->willReturn('{"queue":[]}', '{"queue":[1]}');
        $store = new BoundedSessionStore($inner, new TokenStorage(), new Logger('mcp', [$this->mcpLog]));
        $id = Uuid::v4();

        $store->exists($id);
        $store->reset();

        self::assertSame('{"queue":[1]}', $store->read($id));
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
        $store = new BoundedSessionStore($inner, new TokenStorage(), new Logger('mcp', [$this->mcpLog]));

        self::assertFalse($store->write(Uuid::v4(), '{"queue":[]}'));
        self::assertTrue($this->mcpLog->hasRecordThatContains('MCP session not saved by the store', Level::Warning));
    }

    /**
     * Un `initialize` authentifié : la session neuve est liée dès sa première
     * écriture, et la même connexion la relit telle que le SDK l'a écrite,
     * sans l'en-tête de liaison.
     */
    public function testASessionWrittenUnderAConnectionIsStoredBoundToIt(): void
    {
        $inner = new RecordingSessionStore();
        $connection = Uuid::v7();
        $store = $this->storeAs($inner, $connection);
        $id = Uuid::v4();

        // Session::save() lit la session neuve avant de l'écrire.
        self::assertFalse($store->read($id));
        self::assertTrue($store->write($id, '{"initialized":true}'));

        self::assertSame([$this->bound($connection, '{"initialized":true}')], $inner->writes());
        $next = $this->storeAs($inner, $connection);
        self::assertTrue($next->exists($id));
        self::assertSame('{"initialized":true}', $next->read($id));
    }

    /**
     * Une session liée n'existe pas pour une requête anonyme : le contrôle
     * d'existence rend `false` (le SDK répond 404, le client se
     * réinitialise), la lecture ne rend rien, l'écriture et la destruction
     * (`DELETE`) ne touchent pas la ligne. Un `warning` sans identifiant.
     */
    public function testABoundSessionDoesNotExistForAnAnonymousRequest(): void
    {
        $inner = new RecordingSessionStore();
        $id = Uuid::v4();
        $stored = $this->bound(Uuid::v7(), '{"_mcp":{"outgoing_queue":[{"message":"corpus"}]}}');
        $inner->seed($id, $stored);
        $store = $this->storeAs($inner, null);

        self::assertFalse($store->exists($id));
        self::assertFalse($store->read($id));
        self::assertFalse($store->write($id, '{"_mcp":{"outgoing_queue":[]}}'));
        self::assertFalse($store->destroy($id));

        self::assertSame([], $inner->writes());
        self::assertSame([], $inner->destroyed());
        self::assertSame($stored, $inner->row($id));
        self::assertTrue($this->mcpLog->hasRecordThatContains('MCP session refused: bound to another MCP connection', Level::Warning));
        self::assertFalse($this->mcpLog->hasRecordThatContains($id->toRfc4122(), Level::Warning));
    }

    /**
     * Ni pour une autre connexion : la liaison désigne une connexion, pas
     * « une requête authentifiée ».
     */
    public function testABoundSessionDoesNotExistForAnotherConnection(): void
    {
        $inner = new RecordingSessionStore();
        $id = Uuid::v4();
        $inner->seed($id, $this->bound(Uuid::v7(), '{"initialized":true}'));
        $store = $this->storeAs($inner, Uuid::v7());

        self::assertFalse($store->exists($id));
        self::assertFalse($store->read($id));
        self::assertFalse($store->write($id, '{}'));
        self::assertFalse($store->destroy($id));
        self::assertSame([], $inner->writes());
    }

    /**
     * La connexion de la liaison détruit sa session (`DELETE`), comme une
     * session non liée l'est par n'importe qui.
     */
    public function testTheBoundConnectionDestroysItsSession(): void
    {
        $inner = new RecordingSessionStore();
        $connection = Uuid::v7();
        $bound = Uuid::v4();
        $free = Uuid::v4();
        $inner->seed($bound, $this->bound($connection, '{}'));
        $inner->seed($free, '{}');

        self::assertTrue($this->storeAs($inner, $connection)->destroy($bound));
        self::assertTrue($this->storeAs($inner, null)->destroy($free));
        self::assertSame([$bound->toRfc4122(), $free->toRfc4122()], $inner->destroyed());
    }

    /**
     * Une session ouverte anonymement reste servie en anonyme tant qu'aucune
     * connexion ne l'a utilisée, écrite telle quelle ; la première connexion
     * qui l'utilise la lie, et la requête anonyme suivante la voit absente.
     */
    public function testAnUnboundSessionIsBoundByTheFirstConnectionThatUsesIt(): void
    {
        $inner = new RecordingSessionStore();
        $connection = Uuid::v7();
        $id = Uuid::v4();
        $inner->seed($id, '{"initialized":true}');

        $anonymous = $this->storeAs($inner, null);
        self::assertTrue($anonymous->exists($id));
        self::assertSame('{"initialized":true}', $anonymous->read($id));
        self::assertTrue($anonymous->write($id, '{"initialized":true,"n":1}'));
        self::assertSame(['{"initialized":true,"n":1}'], $inner->writes());

        $connected = $this->storeAs($inner, $connection);
        self::assertTrue($connected->exists($id));
        self::assertSame('{"initialized":true,"n":1}', $connected->read($id));
        self::assertTrue($connected->write($id, '{"initialized":true,"n":2}'));
        self::assertSame($this->bound($connection, '{"initialized":true,"n":2}'), $inner->row($id));

        self::assertFalse($this->storeAs($inner, null)->exists($id));
    }

    /**
     * La file qui porte la réponse n'est jamais écrite sans la liaison : la
     * séquence du SDK rejouée avec sa vraie classe `Session` — le contrôle
     * d'existence (`Protocol::resolveSession()`), la réponse mise en file
     * puis `save()`, enfin la vidange de la file
     * (`Protocol::consumeOutgoingMessages()`, une seconde `Session` sur le même
     * identifiant). Chaque écriture confiée au store, et pas seulement la
     * dernière, porte la connexion : aucune fenêtre où la ligne contient la
     * réponse et n'est pas liée.
     */
    public function testTheQueueHoldingTheAnswerIsNeverWrittenUnbound(): void
    {
        $inner = new RecordingSessionStore();
        $connection = Uuid::v7();
        $id = Uuid::v4();
        // Ouverte anonymement : pas encore liée.
        $inner->seed($id, '{"initialized":true}');
        $store = $this->storeAs($inner, $connection);
        $answer = [['message' => '{"jsonrpc":"2.0","id":2,"result":{"corpus":"CORPUS"}}', 'context' => ['type' => 'response']]];

        self::assertTrue($store->exists($id));
        $handled = new Session($store, $id);
        $handled->set(self::OUTGOING_QUEUE, $answer);
        self::assertTrue($handled->save());

        $drain = new Session($store, $id);
        self::assertSame($answer, $drain->get(self::OUTGOING_QUEUE, []));
        $drain->set(self::OUTGOING_QUEUE, []);
        self::assertTrue($drain->save());

        self::assertCount(2, $inner->writes());
        self::assertStringContainsString('CORPUS', $inner->writes()[0]);
        foreach ($inner->writes() as $written) {
            self::assertStringStartsWith(self::BOUND_TO.$connection->toRfc4122()."\n", $written);
        }
    }

    /**
     * La borne compte la session du SDK, pas l'en-tête de liaison : la
     * relation entre `QUEUED_ANSWER_MAX_BYTES` et `MAX_BYTES` ne bouge pas.
     */
    public function testTheBoundCountsTheSessionNotTheBinding(): void
    {
        $inner = new RecordingSessionStore();
        $store = $this->storeAs($inner, Uuid::v7());
        $id = Uuid::v4();

        self::assertFalse($store->read($id));
        self::assertTrue($store->write($id, str_repeat('a', BoundedSessionStore::MAX_BYTES)));
        self::assertFalse($this->mcpLog->hasWarningRecords());
    }

    /**
     * Un en-tête de liaison illisible (seul ce décorateur l'écrit, sous le
     * MAC du cache) : la session est présente mais illisible, donc absente.
     */
    public function testAMalformedBindingIsAnUnreadableSession(): void
    {
        $inner = new RecordingSessionStore();
        $id = Uuid::v4();
        $inner->seed($id, self::BOUND_TO."not-a-uuid\n{}");

        self::assertFalse($this->storeAs($inner, null)->exists($id));
        self::assertTrue($this->mcpLog->hasRecordThatContains('MCP session unreadable', Level::Warning));
    }

    /** Ce qu'une requête de `$connection` (anonyme si `null`) voit du store. */
    private function storeAs(SessionStoreInterface $inner, ?Uuid $connection): BoundedSessionStore
    {
        $tokenStorage = new TokenStorage();
        if (null !== $connection) {
            $user = new ConnectionPrincipal($connection);
            $tokenStorage->setToken(new PostAuthenticationToken($user, 'mcp', $user->getRoles()));
        }

        return new BoundedSessionStore($inner, $tokenStorage, new Logger('mcp', [$this->mcpLog]));
    }

    /** La ligne d'une session liée à `$connection`, telle que le décorateur l'écrit. */
    private function bound(Uuid $connection, string $session): string
    {
        return self::BOUND_TO.$connection->toRfc4122()."\n".$session;
    }

    private function storeOver(bool $exists, string|false $read): BoundedSessionStore
    {
        $inner = self::createStub(SessionStoreInterface::class);
        $inner->method('exists')->willReturn($exists);
        $inner->method('read')->willReturn($read);

        return new BoundedSessionStore($inner, new TokenStorage(), new Logger('mcp', [$this->mcpLog]));
    }
}
