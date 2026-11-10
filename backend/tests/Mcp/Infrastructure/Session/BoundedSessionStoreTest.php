<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Session;

use App\Mcp\Infrastructure\Session\BoundedSessionStore;
use App\Tests\Mcp\Fixtures\ConnectionPrincipal;
use App\Tests\Mcp\Fixtures\RecordingSessionStore;
use App\Tests\Mcp\Fixtures\UnreachableSimpleCache;
use Mcp\Exception\SessionStoreException;
use Mcp\Server\Protocol;
use Mcp\Server\Session\Psr16SessionStore;
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
 * exception d'une lecture, d'une écriture ou d'une destruction) laissent une
 * trace sur le canal `mcp` : sans elle, une session vidée en silence
 * n'expliquerait rien des erreurs que voit le client. Celui qu'il n'avale
 * pas — son contrôle d'existence lève quand le cache échoue — traverse le
 * décorateur tel quel (#731).
 *
 * Et la liaison d'une session à la connexion MCP qui l'a utilisée la
 * première (#648, décision de l'auteur du 2026-10-24) : une fois liée, elle
 * n'existe pour aucune autre requête que celles de cette connexion, et la
 * liaison part dans la même écriture que la session. Gardée en défense en
 * profondeur depuis #731 : la réponse d'un outil ne passe plus par la session
 * (GetNominativeCorpusToolTest l'épingle), qui porte encore ce que la
 * connexion a déclaré et la file des messages que le serveur initierait.
 */
final class BoundedSessionStoreTest extends TestCase
{
    /** Le `warning` d'une session liée à une autre connexion que celle de la requête, recopié du décorateur. */
    private const string FOREIGN_BINDING = 'MCP session refused: bound to another MCP connection than the request\'s.';

    /** L'en-tête d'une session liée, recopié du décorateur : un oracle à part. */
    private const string BOUND_TO = 'mcp-connection:';

    /**
     * La clé de la file sortante du SDK (`Protocol::SESSION_OUTGOING_QUEUE`,
     * privée) : les messages dont le serveur a l'initiative, jamais une
     * réponse.
     */
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
     * Un store injoignable n'est pas une session absente (#731, R7). Sous un
     * cache en panne, le `Psr16SessionStore` du SDK avale l'échec de la
     * lecture (`false`) mais lève {@see SessionStoreException} au contrôle
     * d'existence : le décorateur la laisse passer, telle quelle. Le SDK en
     * fait un 503 au POST (`Protocol::resolveSession()`), le contrôleur au
     * GET — la session existe peut-être, le client réessaie. Rendue `false`,
     * elle devenait un 404, qui fait se réinitialiser le client et abandonner
     * une session peut-être intacte.
     *
     * Aucun `warning` « MCP session unreadable » non plus : on ne sait rien
     * de la ligne, ni présente ni illisible. La trace de la panne est le
     * `error` de celui qui répond 503.
     */
    public function testAnUnreachableStoreIsNotAnAbsentSession(): void
    {
        $store = new BoundedSessionStore(new Psr16SessionStore(new UnreachableSimpleCache()), new TokenStorage(), new Logger('mcp', [$this->mcpLog]));

        $this->expectException(SessionStoreException::class);

        try {
            $store->exists(Uuid::v4());
        } finally {
            self::assertFalse($this->mcpLog->hasWarningRecords());
        }
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
        $stored = $this->bound(Uuid::v7(), '{"_mcp":{"outgoing_queue":[{"message":"server-initiated"}]}}');
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
     * Chaque écriture d'une session utilisée par une connexion porte sa
     * liaison : la séquence d'un `tools/call` rejouée avec la vraie classe
     * `Session` du SDK, telle que la version épinglée (composer.json) la
     * déroule sans verrou de session.
     *
     *  1. `Protocol::resolveSession()` : le contrôle d'existence de
     *     l'identifiant reçu, puis une `Session` sur cet identifiant ;
     *  2. `Protocol::handleRequest()` : le `_meta` de la requête posé dans la
     *     session, ce qui la lit — la réponse, elle, part au transport
     *     (`Protocol::sendResponse()`), jamais dans la session ;
     *  3. `Protocol::handleMessages()` : `Session::save()`, qui rappelle le
     *     contrôle d'existence, relit ce que le store porte, y reporte les
     *     clés changées, puis écrit ;
     *  4. `StreamableHttpTransport::createJsonResponse()` :
     *     `Protocol::consumeOutgoingMessages()`, une seconde `Session` sur le
     *     même identifiant, qui lit la file des messages initiés par le
     *     serveur et ne réécrit rien quand elle est vide.
     *
     * En 0.8.1 la réponse était mise en file dans la session à l'étape 3,
     * puis la file vidée et réécrite à l'étape 4 : deux écritures, dont une
     * portait le corpus. Il en reste une par requête, sans réponse.
     *
     * Deux requêtes de la même connexion, sur une session ouverte
     * anonymement : la première la lie, la seconde écrit sous la liaison que
     * la ligne porte. Chaque écriture confiée au store commence par la
     * connexion — aucune fenêtre où la ligne est écrite pour elle sans la
     * désigner.
     */
    public function testEveryWriteOfABoundSessionCarriesItsBinding(): void
    {
        $inner = new RecordingSessionStore();
        $connection = Uuid::v7();
        $id = Uuid::v4();
        // Ouverte anonymement : pas encore liée.
        $inner->seed($id, '{"initialized":true,"client_info":{"name":"client"}}');

        foreach ([['progressToken' => 'first'], ['progressToken' => 'second']] as $requestMeta) {
            // Une requête HTTP : son propre décorateur, sans mémoire de la précédente.
            $store = $this->storeAs($inner, $connection);

            self::assertTrue($store->exists($id));
            $handled = new Session($store, $id);
            $handled->set(Protocol::SESSION_ACTIVE_REQUEST_META, $requestMeta);
            self::assertTrue($handled->save());

            $drain = new Session($store, $id);
            self::assertSame([], $drain->get(self::OUTGOING_QUEUE, []));
            self::assertSame('client', $drain->get('client_info.name'), 'La connexion relit la session qu\'elle a écrite.');
        }

        self::assertCount(2, $inner->writes(), 'Une écriture par requête : la vidange d\'une file vide ne réécrit rien.');
        foreach ($inner->writes() as $written) {
            self::assertStringStartsWith(self::BOUND_TO.$connection->toRfc4122()."\n", $written);
        }
        self::assertStringContainsString('"progressToken":"second"', $inner->writes()[1]);
        self::assertFalse($this->mcpLog->hasWarningRecords());
    }

    /**
     * Une file de messages initiés par le serveur — aucun outil n'en émet
     * aujourd'hui (ni élicitation, ni échantillonnage, ni notification) — ne
     * sort que vers sa connexion, et sa vidange est écrite liée elle aussi :
     * c'est pour elle que la liaison reprendrait sa raison d'origine. La
     * vidange de `Protocol::consumeOutgoingMessages()` rejouée : la file lue,
     * puis réécrite vide.
     */
    public function testAQueueOfServerInitiatedMessagesIsDrainedByItsConnectionOnly(): void
    {
        $inner = new RecordingSessionStore();
        $connection = Uuid::v7();
        $id = Uuid::v4();
        $queue = [['message' => '{"jsonrpc":"2.0","id":"r1","method":"elicitation/create"}', 'context' => ['type' => 'request']]];
        $inner->seed($id, $this->bound($connection, '{"initialized":true,"_mcp":{"outgoing_queue":'.json_encode($queue, \JSON_THROW_ON_ERROR).'}}'));

        $foreign = new Session($this->storeAs($inner, null), $id);
        self::assertSame([], $foreign->get(self::OUTGOING_QUEUE, []), 'Une requête anonyme ne lit pas la file d\'une session liée.');
        self::assertSame([], $inner->writes());

        $drain = new Session($this->storeAs($inner, $connection), $id);
        self::assertSame($queue, $drain->get(self::OUTGOING_QUEUE, []));
        $drain->set(self::OUTGOING_QUEUE, []);
        self::assertTrue($drain->save());

        self::assertSame([$this->bound($connection, '{"initialized":true,"_mcp":{"outgoing_queue":[]}}')], $inner->writes());
    }

    /**
     * La course que le SDK laisse sans verrou (#762), mesurée sur sa vraie
     * classe `Session` : une requête a passé le contrôle d'existence sur une
     * session non liée, puis une autre connexion la lie avant sa sauvegarde.
     *
     * `Session::save()` rappelle le contrôle d'existence avant d'écrire : le
     * décorateur relit la ligne, la voit liée à une autre connexion, et la
     * dit absente — `save()` rend `false` **sans écrire**. La vidange
     * (`Protocol::consumeOutgoingMessages()`) relit ensuite la session : même
     * refus, une file vide, rien de réécrit. Deux `warning`, donc, dans cet
     * ordre : le contrôle d'existence de la sauvegarde, puis la lecture de la
     * vidange — aucune écriture refusée, la requête n'en tente pas. En 0.8.1
     * c'était la lecture de la vidange puis son écriture, refusée, et la
     * sauvegarde avait déjà réécrit la ligne avec ce que la requête avait lu.
     *
     * Ce test tient donc aussi un comportement du SDK : `save()` relit le
     * store avant d'écrire (le contrôle d'existence, puis la relecture sur
     * laquelle il reporte ses clés), et le décorateur relève la liaison à
     * chaque lecture. Un SDK qui écrirait de nouveau depuis sa mémoire, sans
     * relire, ferait réécrire la ligne par la requête dépassée, non liée : ce
     * test rougirait sur l'écriture. La fenêtre qui reste est entre cette
     * relecture et l'écriture, dans `save()` même (limite acceptée,
     * `.claude/rules/mcp-sessions.md`).
     */
    public function testARequestOvertakenByAnotherConnectionWritesNothing(): void
    {
        $inner = new RecordingSessionStore();
        $id = Uuid::v4();
        $inner->seed($id, '{"initialized":true}');
        $store = $this->storeAs($inner, null);

        self::assertTrue($store->exists($id));
        $handled = new Session($store, $id);
        $handled->set(Protocol::SESSION_ACTIVE_REQUEST_META, ['progressToken' => 'overtaken']);

        // Entre-temps, la requête d'une connexion a lié la session.
        $bound = $this->bound(Uuid::v7(), '{"initialized":true,"client_info":{"name":"other"}}');
        $inner->seed($id, $bound);

        self::assertFalse($handled->save());
        self::assertSame([], $inner->writes());
        self::assertSame([self::FOREIGN_BINDING], $this->warnings(), 'Un seul `warning` à la sauvegarde : son contrôle d\'existence.');

        $drain = new Session($store, $id);
        self::assertSame([], $drain->get(self::OUTGOING_QUEUE, []));
        self::assertSame([self::FOREIGN_BINDING, self::FOREIGN_BINDING], $this->warnings(), 'Le second `warning` : la lecture de la vidange.');

        self::assertSame([], $inner->writes());
        self::assertSame([], $inner->destroyed());
        self::assertSame($bound, $inner->row($id));
    }

    /**
     * La borne compte la session du SDK, pas l'en-tête de liaison : une
     * session exactement à la borne s'écrit, liée, sans `warning`.
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
     * Un identifiant détruit pour dépassement n'existe plus pour le reste de
     * la requête, quoi que le store contienne ensuite : ni contrôle
     * d'existence, ni lecture, ni écriture — jusqu'à `reset()`. En 0.8.1, le
     * SDK réécrivait aussitôt la session, vide, en vidant sa file sortante ;
     * depuis la 0.9 il ne le fait plus (il ne réécrit qu'une file non vide,
     * et une session détruite se relit absente), si bien que plus aucun test
     * du point d'entrée ne voit cette mémoire (mesuré le 2026-11-10, #731 :
     * retirée, `McpEndpointTest` reste vert). La règle est tenue ici, pas par
     * l'ordre des appels du SDK : ce test la garde. La ligne remise dans le
     * store tient lieu de ce qui la ferait réapparaître — une requête
     * concurrente de la même session, un SDK qui réécrirait.
     */
    public function testAnIdDestroyedOverTheBoundStaysGoneForTheRestOfTheRequest(): void
    {
        $inner = new RecordingSessionStore();
        $store = $this->storeAs($inner, null);
        $id = Uuid::v4();
        $inner->seed($id, '{"initialized":true}');

        self::assertTrue($store->exists($id));
        self::assertFalse($store->write($id, str_repeat('a', BoundedSessionStore::MAX_BYTES + 1)));
        self::assertSame([$id->toRfc4122()], $inner->destroyed());
        self::assertTrue($this->mcpLog->hasWarning('MCP session refused: over the size bound.'));

        $inner->seed($id, '{"initialized":true}');

        self::assertFalse($store->exists($id));
        self::assertFalse($store->read($id));
        self::assertFalse($store->write($id, '[]'));
        self::assertSame([], $inner->writes());
        self::assertSame('{"initialized":true}', $inner->row($id));

        // Un runtime persistant : la requête suivante repart du store.
        $store->reset();
        self::assertTrue($store->exists($id));
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

    /**
     * Les `warning` du canal `mcp`, dans l'ordre.
     *
     * @return list<string>
     */
    private function warnings(): array
    {
        $messages = [];
        foreach ($this->mcpLog->getRecords() as $record) {
            if (Level::Warning === $record->level) {
                $messages[] = $record->message;
            }
        }

        return $messages;
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
