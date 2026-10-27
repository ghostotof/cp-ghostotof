<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Infrastructure\League\TokenIdentifierHash;
use App\Tests\Support\ReadsSecurityAuditLog;
use Defuse\Crypto\Crypto;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->assertAudited($cause, 'authorization_code', $presentingClientId);
        $this->assertNoSecretIsLogged([$code, self::CODE_VERIFIER, $this->hashOf($code, 'auth_code_id')]);
    }

    /**
     * Un code présenté après ses dix minutes : league le juge sur sa charge,
     * avant le dépôt — le rejeu tardif d'un code intercepté, que le rejeu
     * sous verrou ne voit pas (limite acceptée de #649, « A replayed code is
     * judged under the same lock »), laisse une trace.
     */
    public function testAnExpiredCodeIsAudited(): void
    {
        $code = $this->grantedCode();

        $this->assertRefused($this->exchange($this->reencrypted($code, ['expire_time' => time() - 60])));

        $this->assertAudited('code-expired', 'authorization_code', self::CLIENT_ID);
    }

    /** Le code d'une connexion révoquée : refusé sous verrou, sans compromission. */
    public function testACodeOfARevokedConnectionIsAudited(): void
    {
        $code = $this->grantedCode();
        $this->revokeTheConnection();

        $this->assertRefused($this->exchange($code));

        $this->assertAudited('code-refused', 'authorization_code', self::CLIENT_ID);
    }

    /** Un jeton de rafraîchissement présenté comme code : authentique, mais pas un code. */
    public function testARefreshTokenPresentedAsACodeIsAudited(): void
    {
        $refreshToken = $this->issuedRefreshToken();

        $this->assertRefused($this->exchange($refreshToken));

        $this->assertAudited('not-a-code', 'authorization_code', self::CLIENT_ID);
        $this->assertNoSecretIsLogged([$refreshToken, $this->hashOf($refreshToken, 'refresh_token_id')]);
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

        $this->assertAudited($cause, 'refresh_token', $presentingClientId);
        $this->assertNoSecretIsLogged([$refreshToken, $this->hashOf($refreshToken, 'refresh_token_id')]);
    }

    public function testAnExpiredRefreshTokenIsAudited(): void
    {
        $refreshToken = $this->issuedRefreshToken();

        $this->assertRefused($this->refresh($this->reencrypted($refreshToken, ['expire_time' => time() - 60])));

        $this->assertAudited('token-expired', 'refresh_token', self::CLIENT_ID);
    }

    /** Le rafraîchissement d'une connexion révoquée : refusé sous verrou, rien n'est écrit en base. */
    public function testARefreshOfARevokedConnectionIsAudited(): void
    {
        $refreshToken = $this->issuedRefreshToken();
        $this->revokeTheConnection();

        $this->assertRefused($this->refresh($refreshToken));

        $this->assertAudited('token-refused', 'refresh_token', self::CLIENT_ID);
    }

    /** Un code présenté comme jeton de rafraîchissement : authentique, mais pas un rafraîchissement. */
    public function testACodePresentedAsARefreshTokenIsAudited(): void
    {
        $code = $this->grantedCode();

        $this->assertRefused($this->refresh($code));

        $this->assertAudited('not-a-refresh-token', 'refresh_token', self::CLIENT_ID);
        $this->assertNoSecretIsLogged([$code, $this->hashOf($code, 'auth_code_id')]);
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

    /** Un client inconnu est refusé pour lui-même, avant l'artefact : il ne désigne rien. */
    public function testAnUnknownClientIsNotAudited(): void
    {
        $response = $this->exchange($this->grantedCode(), ['client_id' => 'https://unknown.example.test/metadata.json']);

        $this->assertOAuthError($response, Response::HTTP_UNAUTHORIZED, 'invalid_client');
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
     * et le client qui a présenté l'artefact ; ses clés exactement.
     */
    private function assertAudited(string $cause, string $grantType, string $presentingClientId): void
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
    }

    /**
     * Aucun artefact, vérificateur ni empreinte dans le journal de sécurité,
     * quelle que soit la ligne.
     *
     * @param list<string> $secrets
     */
    private function assertNoSecretIsLogged(array $secrets): void
    {
        $journal = json_encode(array_map(static fn(LogRecord $record): array => $record->context, self::securityAuditRecords()), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

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

    /** L'empreinte que la base garde de l'identifiant que porte la charge. */
    private function hashOf(string $grant, string $identifierField): string
    {
        $identifier = $this->payloadOf($grant)[$identifierField] ?? null;
        self::assertIsString($identifier);

        return TokenIdentifierHash::of($identifier);
    }

    private function revokeTheConnection(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement("UPDATE mcp_connection SET revoked_at = now(), revocation_cause = 'client'");
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
