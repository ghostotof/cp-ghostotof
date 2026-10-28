<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Application\GrantRefusalAuditInterface;
use App\Mcp\Connection\Infrastructure\League\TokenIdentifierHash;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\ReadsSecurityAuditLog;
use Defuse\Crypto\Crypto;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Les refus du point de jeton audités (#725) : **tout refus d'un code ou d'un
 * jeton de rafraîchissement authentique** — que le serveur déchiffre, donc
 * qu'il a émis — écrit une ligne `mcp-grant-refused` qui désigne sa connexion
 * (`userId`, `connectionId`, `clientId`), la cause du refus, le grant et le
 * client qui l'a présenté. Jamais pour un artefact illisible, un client
 * inconnu ni une connexion disparue : rien n'y est désigné, et un flot de
 * requêtes quelconques n'écrit aucune ligne. Jamais en double d'une
 * compromission, que `mcp-connection-revoked` trace déjà.
 *
 * Chaque cause est produite par le vrai league : c'est ce qui épingle le
 * classement de ses refus, et une montée de league qui changerait un libellé
 * fait rougir sa cause ici. Le journal de sécurité ne garde que la dernière
 * requête (ReadsSecurityAuditLog) : le refus est toujours la dernière.
 */
final class GrantRefusalAuditTest extends WebTestCase
{
    use DrivesTheOAuthFlow;
    use ReadsAllChannelsLog;
    use ReadsSecurityAuditLog;

    private const string EVENT = 'mcp-grant-refused';

    /** Les clés d'une ligne, exactement : aucune autre ne peut porter un secret. */
    private const array LINE_KEYS = ['event', 'userId', 'connectionId', 'clientId', 'cause', 'grantType', 'presentingClientId', 'actor', 'ip', 'path'];

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /**
     * Les refus de l'échange d'un code authentique, produits par le corps de
     * la requête seul.
     *
     * @return iterable<string, array{array<string, string|null>, string, string}>
     */
    public static function refusedExchanges(): iterable
    {
        yield 'vérificateur faux' => [['code_verifier' => str_repeat('a', 43)], 'verifier-mismatch', self::CLIENT_ID];
        yield 'vérificateur absent' => [['code_verifier' => null], 'verifier-missing', self::CLIENT_ID];
        yield 'vérificateur mal formé' => [['code_verifier' => 'court'], 'verifier-malformed', self::CLIENT_ID];
        yield 'code d\'un autre client' => [['client_id' => self::OTHER_CLIENT_ID], 'foreign-client', self::OTHER_CLIENT_ID];
        yield 'autre redirection' => [['redirect_uri' => 'http://localhost:49153/callback'], 'redirect-mismatch', self::CLIENT_ID];
        yield 'redirection absente' => [['redirect_uri' => null], 'redirect-mismatch', self::CLIENT_ID];
        yield 'ressource étrangère' => [['resource' => 'https://other.example.test/api/mcp'], 'foreign-resource', self::CLIENT_ID];
    }

    /**
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('refusedExchanges')]
    public function testARefusedExchangeOfAnAuthenticCodeIsAudited(array $overrides, string $cause, string $presentingClientId): void
    {
        $code = $this->grantedCode();

        $this->assertRefused($this->exchange($code, $overrides));

        $this->assertAudited($cause, 'authorization_code', $presentingClientId, $code);
    }

    /**
     * Un code présenté après ses dix minutes : league le juge sur sa charge,
     * avant le dépôt — le rejeu tardif d'un code intercepté, que le rejeu
     * sous verrou ne voit pas (limite acceptée de #649, « A replayed code is
     * judged under the same lock »), laisse une trace.
     */
    public function testAnExpiredCodeIsAudited(): void
    {
        $code = $this->reencrypted($this->grantedCode(), ['expire_time' => time() - 60]);

        $this->assertRefused($this->exchange($code));

        $this->assertAudited('code-expired', 'authorization_code', self::CLIENT_ID, $code);
    }

    /**
     * Ce que le juge sous verrou tient pour mort (TokenUnderConnectionLock),
     * sans compromission : refusé « has been revoked » par league.
     *
     * @return iterable<string, array{string}>
     */
    public static function codesDeadUnderTheLock(): iterable
    {
        yield 'connexion révoquée' => ["UPDATE mcp_connection SET revoked_at = now(), revocation_cause = 'client'"];
        yield 'ligne du code purgée' => ['DELETE FROM mcp_authorization_code'];
    }

    #[DataProvider('codesDeadUnderTheLock')]
    public function testACodeDeadUnderTheLockIsAudited(string $alteration): void
    {
        $code = $this->grantedCode();
        $this->executeStatement($alteration);

        $this->assertRefused($this->exchange($code));

        $this->assertAudited('code-refused', 'authorization_code', self::CLIENT_ID, $code);
    }

    /** Un jeton de rafraîchissement présenté comme code : authentique, mais pas un code. */
    public function testARefreshTokenPresentedAsACodeIsAudited(): void
    {
        $refreshToken = $this->issuedRefreshToken();

        $this->assertRefused($this->exchange($refreshToken));

        $this->assertAudited('not-a-code', 'authorization_code', self::CLIENT_ID, $refreshToken);
    }

    /**
     * Les refus d'un rafraîchissement authentique, produits par le corps de
     * la requête seul.
     *
     * @return iterable<string, array{array<string, string|null>, string, string}>
     */
    public static function refusedRefreshes(): iterable
    {
        yield 'jeton d\'un autre client' => [['client_id' => self::OTHER_CLIENT_ID], 'foreign-client', self::OTHER_CLIENT_ID];
        yield 'scope inconnu' => [['scope' => 'other:scope'], 'invalid-scope', self::CLIENT_ID];
    }

    /**
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('refusedRefreshes')]
    public function testARefusedRefreshOfAnAuthenticTokenIsAudited(array $overrides, string $cause, string $presentingClientId): void
    {
        $refreshToken = $this->issuedRefreshToken();

        $this->assertRefused($this->refresh($refreshToken, $overrides));

        $this->assertAudited($cause, 'refresh_token', $presentingClientId, $refreshToken);
    }

    public function testAnExpiredRefreshTokenIsAudited(): void
    {
        $refreshToken = $this->reencrypted($this->issuedRefreshToken(), ['expire_time' => time() - 60]);

        $this->assertRefused($this->refresh($refreshToken));

        $this->assertAudited('token-expired', 'refresh_token', self::CLIENT_ID, $refreshToken);
    }

    /**
     * Chaque mort que le juge sous verrou prononce sur un rafraîchissement,
     * sans compromission (`.claude/rules/mcp-oauth-cycle-de-vie.md`, « The
     * refresh is judged under the lock of its connection ») : refusé « has
     * been revoked » par league, rien n'est écrit en base.
     *
     * @return iterable<string, array{string}>
     */
    public static function refreshTokensDeadUnderTheLock(): iterable
    {
        yield 'connexion révoquée' => ["UPDATE mcp_connection SET revoked_at = now(), revocation_cause = 'client'"];
        yield 'ligne du jeton purgée' => ['DELETE FROM mcp_refresh_token'];
        // La charge reste valide pour `time()` : seule l'horloge du serveur le juge échu.
        yield 'jeton échu à l\'horloge du serveur' => ["UPDATE mcp_refresh_token SET expires_at = now() - interval '1 minute'"];
        yield 'connexion expirée' => ["UPDATE mcp_connection SET granted_at = now() - interval '91 days', last_used_at = NULL"];
        yield 'compte hors palier de confiance' => ["UPDATE cpg_user SET roles = '[]'"];
    }

    #[DataProvider('refreshTokensDeadUnderTheLock')]
    public function testARefreshTokenDeadUnderTheLockIsAudited(string $alteration): void
    {
        $refreshToken = $this->issuedRefreshToken();
        $this->executeStatement($alteration);

        $this->assertRefused($this->refresh($refreshToken));

        $this->assertAudited('token-refused', 'refresh_token', self::CLIENT_ID, $refreshToken);
    }

    /** Un code présenté comme jeton de rafraîchissement : authentique, mais pas un rafraîchissement. */
    public function testACodePresentedAsARefreshTokenIsAudited(): void
    {
        $code = $this->grantedCode();

        $this->assertRefused($this->refresh($code));

        $this->assertAudited('not-a-refresh-token', 'refresh_token', self::CLIENT_ID, $code);
    }

    /**
     * Rien d'authentique, ou rien qui désigne une connexion encore en base :
     * aucune ligne. Un artefact quelconque, quel qu'en soit le volume,
     * n'écrit rien.
     */
    public function testAnUnreadableCodeIsNotAudited(): void
    {
        $this->grantedCode();

        $this->assertRefused($this->exchange('pas-un-code'));

        self::assertSame([], self::securityAuditEvents(self::EVENT));
    }

    public function testAnUnreadableRefreshTokenIsNotAudited(): void
    {
        $this->assertRefused($this->refresh('pas-un-jeton'));

        self::assertSame([], self::securityAuditEvents(self::EVENT));
    }

    /**
     * Régression de la relecture de #725 : league retire les blancs d'une
     * valeur, l'audit les lisait. Un code entouré d'une espace, avec un
     * vérificateur faux, atteignait league — refusé `verifier-mismatch`
     * comme le code nu — sans écrire de ligne. Il est désormais refusé avant
     * league (`invalid_request`, FormEncodedRequest), qui ne juge donc rien :
     * plus de vérificateur éprouvé en silence, et le code reste échangeable.
     */
    public function testACodeWithSurroundingBlanksNeverReachesLeague(): void
    {
        $code = $this->grantedCode();

        $response = $this->exchange(' '.$code, ['code_verifier' => str_repeat('a', 43)]);

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_request');
        self::assertStringContainsString('must not start or end with a blank', (string) $response->getContent());
        self::assertSame(Response::HTTP_OK, $this->exchange($code)->getStatusCode());
    }

    /** Un client inconnu est refusé pour lui-même, avant l'artefact : il ne désigne rien. */
    public function testAnUnknownClientIsNotAudited(): void
    {
        $response = $this->exchange($this->grantedCode(), ['client_id' => 'https://unknown.example.test/metadata.json']);

        $this->assertOAuthError($response, Response::HTTP_UNAUTHORIZED, 'invalid_client');
        self::assertSame([], self::securityAuditEvents(self::EVENT));
    }

    /**
     * Les refus qui précèdent la lecture de l'artefact : aucun client dans le
     * corps (TokenEndpoint), un corps aux paramètres ambigus
     * (FormEncodedRequest), un grant qui ne porte aucun artefact (league).
     *
     * @return iterable<string, array{array<string, string|null>|string}>
     */
    public static function refusalsBeforeTheGrantIsRead(): iterable
    {
        yield 'aucun client' => [['client_id' => null]];
        yield 'paramètre répété' => ['grant_type=authorization_code&client_id=a&client_id=b&code=%s'];
        yield 'grant sans artefact' => [['grant_type' => 'client_credentials']];
    }

    /**
     * @param array<string, string|null>|string $parameters les paramètres de l'échange à remplacer, ou un corps brut (`%s` : le code)
     */
    #[DataProvider('refusalsBeforeTheGrantIsRead')]
    public function testARefusalBeforeTheGrantIsReadIsNotAudited(array|string $parameters): void
    {
        $code = $this->grantedCode();
        $body = \is_string($parameters) ? \sprintf($parameters, rawurlencode($code)) : $this->exchangeParameters($code, $parameters);

        $this->assertRefused($this->postForm(self::TOKEN_PATH, $body));

        self::assertSame([], self::securityAuditEvents(self::EVENT));
    }

    /** Une charge qui désigne une connexion absente de la base : personne à désigner. */
    public function testAGrantOfAVanishedConnectionIsNotAudited(): void
    {
        $code = $this->grantedCode();

        $this->assertRefused($this->exchange($this->reencrypted($code, ['expire_time' => time() - 60, 'user_id' => Uuid::v7()->toRfc4122()])));

        self::assertSame([], self::securityAuditEvents(self::EVENT));
    }

    /**
     * Un code rejoué est une compromission : la connexion révoquée et sa ligne
     * `mcp-connection-revoked` (#649), jamais une seconde ligne pour le même
     * refus.
     */
    public function testACompromiseIsNotAuditedTwice(): void
    {
        $code = $this->grantedCode();
        self::assertSame(Response::HTTP_OK, $this->exchange($code)->getStatusCode());

        $this->assertRefused($this->exchange($code));

        self::assertCount(1, self::securityAuditEvents('mcp-connection-revoked'));
        self::assertSame([], self::securityAuditEvents(self::EVENT));
    }

    /**
     * Une panne de l'audit ne change pas la réponse (#725, décision de
     * l'auteur du 2026-10-27) : le client reçoit le refus de league, et la
     * panne sort en `critical` sur le canal principal — un refus non audité
     * reste visible. La lecture de la connexion échoue ici pour de vrai : sa
     * table renommée le temps de la requête, que league n'a pas lue pour un
     * code échu (refusé sur sa charge, avant le dépôt).
     */
    public function testAnAuditFailureLeavesLeaguesRefusalUnchanged(): void
    {
        $code = $this->reencrypted($this->grantedCode(), ['expire_time' => time() - 60]);
        $this->executeStatement('ALTER TABLE mcp_connection RENAME TO mcp_connection_hidden');

        try {
            $response = $this->exchange($code);
        } finally {
            $this->executeStatement('ALTER TABLE mcp_connection_hidden RENAME TO mcp_connection');
        }

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame([], self::securityAuditEvents(self::EVENT));
        $failures = array_values(array_filter(
            self::allChannelsLogRecords(),
            static fn (LogRecord $record): bool => 'Grant refusal not audited.' === $record->message,
        ));
        self::assertCount(1, $failures);
        self::assertSame(Level::Critical, $failures[0]->level);
        self::assertSame(['exceptionClass'], array_keys($failures[0]->context));
    }

    /**
     * Le jumeau du précédent pour le rejeu non prouvé (#726) : sa ligne
     * `replay-unproven` s'écrit depuis le `catch` de la compromission, par
     * `recordUnprovenReplay()`, et sa panne ne change pas davantage la
     * réponse — le refus de league mot pour mot (« Authorization code has been
     * revoked », `invalid_grant`), aucune révocation, aucune ligne, et la
     * panne en `critical`, la classe seule.
     *
     * La panne est celle d'un écrivain remplacé, non d'une table renommée :
     * league lit la connexion sous verrou avant que le rejeu soit reconnu
     * (`isAuthCodeRevoked()`), la cacher ferait échouer league avant l'audit.
     * Le premier échange a construit le point de jeton : le noyau du client
     * est redémarré — un conteneur neuf, rien d'initialisé, la base gardée —,
     * le service remplacé, puis le client ne redémarre plus jusqu'au rejeu.
     * **Mesuré** : l'appel de `auditUnprovenReplay()` sans
     * `FailSafeAudit::attempt()` le fait rougir — la panne sort en 500.
     */
    public function testAnUnprovenReplayAuditFailureLeavesLeaguesRefusalUnchanged(): void
    {
        $code = $this->grantedCode();
        self::assertSame(Response::HTTP_OK, $this->exchange($code)->getStatusCode());
        $failing = self::createStub(GrantRefusalAuditInterface::class);
        $failing->method('recordUnprovenReplay')->willThrowException(new RuntimeException('Journal de sécurité indisponible'));
        $kernel = $this->client->getKernel();
        $kernel->shutdown();
        $kernel->boot();
        self::getContainer()->set(GrantRefusalAuditInterface::class, $failing);
        $this->client->disableReboot();

        $replay = $this->exchange($code, ['code_verifier' => null]);

        $this->assertOAuthError($replay, Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame('Authorization code has been revoked', $this->json($replay)['hint'] ?? null);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE revoked_at IS NOT NULL'), 'Un rejeu non prouvé a révoqué la connexion.');
        self::assertSame([], self::securityAuditEvents('mcp-connection-revoked'));
        self::assertSame([], self::securityAuditEvents(self::EVENT));
        $failures = array_values(array_filter(
            self::allChannelsLogRecords(),
            static fn (LogRecord $record): bool => 'Grant refusal not audited.' === $record->message,
        ));
        self::assertCount(1, $failures);
        self::assertSame(Level::Critical, $failures[0]->level);
        self::assertSame(['exceptionClass' => RuntimeException::class], $failures[0]->context);
    }

    public function testAServedExchangeIsNotAudited(): void
    {
        self::assertSame(Response::HTTP_OK, $this->exchange($this->grantedCode())->getStatusCode());

        self::assertSame([], self::securityAuditEvents(self::EVENT));
    }

    private function assertRefused(Response $response): void
    {
        self::assertContains($response->getStatusCode(), [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED], substr((string) $response->getContent(), 0, 500));
        self::assertArrayNotHasKey('access_token', $this->json($response));
    }

    /**
     * Une ligne et une seule, qui désigne la connexion de la base — le compte
     * par son UUID, le client par son identifiant public —, la cause, le grant
     * et le client qui a présenté l'artefact ; ses clés exactement. Ni
     * l'artefact présenté, ni le vérificateur, ni l'empreinte de son
     * identifiant ne sont au journal.
     */
    private function assertAudited(string $cause, string $grantType, string $presentingClientId, string $presented): void
    {
        $line = self::singleSecurityAuditEvent(self::EVENT);
        $connection = $this->theConnection();

        self::assertEqualsCanonicalizing(self::LINE_KEYS, array_keys($line));
        self::assertSame($connection['account_id'], $line['userId']);
        self::assertSame($connection['id'], $line['connectionId']);
        self::assertSame(self::CLIENT_ID, $line['clientId']);
        self::assertSame($cause, $line['cause']);
        self::assertSame($grantType, $line['grantType']);
        self::assertSame($presentingClientId, $line['presentingClientId']);
        self::assertSame('anonymous', $line['actor']);
        self::assertSame(self::TOKEN_PATH, $line['path']);
        $this->assertNoSecretIsLogged([$presented, self::CODE_VERIFIER, $this->hashOf($presented)]);
    }

    /**
     * Aucun artefact, vérificateur ni empreinte dans le journal de sécurité,
     * quelle que soit la ligne.
     *
     * @param list<string> $secrets
     */
    private function assertNoSecretIsLogged(array $secrets): void
    {
        $journal = json_encode(array_map(static fn (LogRecord $record): array => $record->context, self::securityAuditRecords()), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $journal);
        }
    }

    /** Le jeton de rafraîchissement qu'émet l'échange d'un code accordé. */
    private function issuedRefreshToken(): string
    {
        $issued = $this->json($this->exchange($this->grantedCode()));
        self::assertIsString($issued['refresh_token'] ?? null);

        return $issued['refresh_token'];
    }

    /**
     * L'artefact rechiffré sous la clé du serveur, sa charge modifiée : ce que
     * le serveur aurait pu émettre, donc authentique pour lui.
     *
     * @param array<string, int|string> $changes
     */
    private function reencrypted(string $grant, array $changes): string
    {
        return Crypto::encrypt(json_encode([...$this->payloadOf($grant), ...$changes], \JSON_THROW_ON_ERROR), $this->encryptionKey());
    }

    /** L'empreinte que la base garde de l'identifiant que porte la charge, celui d'un code ou d'un rafraîchissement. */
    private function hashOf(string $grant): string
    {
        $payload = $this->payloadOf($grant);
        $identifier = $payload['auth_code_id'] ?? $payload['refresh_token_id'] ?? null;
        self::assertIsString($identifier);

        return TokenIdentifierHash::of($identifier);
    }

    private function executeStatement(string $sql): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement($sql);
    }

    /**
     * La seule connexion du test, lue en base.
     *
     * @return array{id: string, account_id: string}
     */
    private function theConnection(): array
    {
        $rows = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAllAssociative('SELECT id, account_id FROM mcp_connection');
        self::assertCount(1, $rows);
        self::assertIsString($rows[0]['id']);
        self::assertIsString($rows[0]['account_id']);

        return ['id' => $rows[0]['id'], 'account_id' => $rows[0]['account_id']];
    }
}
