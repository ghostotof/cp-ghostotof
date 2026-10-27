<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\McpConnectionPurger;
use App\Mcp\Connection\Application\McpConnectionPurgerInterface;
use App\Mcp\Connection\Application\TransactionInterface;
use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Domain\Exception\InvalidRetentionPeriodException;
use App\Shared\Domain\Security\McpConnectionReference;
use App\Shared\Domain\ValueObject\RetentionPeriod;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\ReadsSecurityAuditLog;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

/**
 * La purge quotidienne des connexions MCP et de leurs jetons (#649), sur la
 * base `_test`, l'horloge du conteneur figée (`mockTime()`).
 *
 * L'instant jugé, le 10 novembre 2026 à 10:00 à Paris, place les échéances
 * de part et d'autre du passage à l'heure d'hiver (25 octobre) : trente jours
 * de calendrier y durent 721 heures. Les seuils attendus sont écrits en
 * toutes lettres (règle de #709), jamais recalculés par le test :
 *
 * - inactivité (`P30D`) : dernier usage au 11 octobre, 10:00 CEST ;
 * - durée maximale (`P90D`) : octroi au 12 août, 10:00 CEST ;
 * - rétention des connexions révoquées (6 mois) : révocation au 10 mai,
 *   10:00 CEST.
 */
final class McpConnectionPurgerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ReadsAllChannelsLog;
    use ReadsSecurityAuditLog;

    /** Les tables du modèle, des feuilles vers la racine. */
    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    private const string NOW = '2026-11-10 10:00:00';
    private const string IDLE_DEADLINE_LAST_USE = '2026-10-11T10:00:00+02:00';
    private const string MAXIMUM_DEADLINE_GRANT = '2026-08-12T10:00:00+02:00';
    private const string RETENTION_THRESHOLD = '2026-05-10T10:00:00+02:00';

    private const string RETENTION = '6 months';
    private const string CLIENT_ID = 'https://client.example.test/metadata.json';
    private const string SCOPE = 'nominative:read';
    private const string AUDIENCE = 'https://example.test/api/mcp';
    private const string REVOKED_EVENT = 'mcp-connection-revoked';
    private const string NOT_AUDITED = 'Expired connection not audited.';

    private EntityManagerInterface $em;

    private CpgUser $account;

    private McpClient $client;

    /** Compteur des empreintes de jeton, uniques dans un test. */
    private int $tokens = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime($this->now());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->purgeTables();

        $this->account = new CpgUser('purge-account', 'hashed-password');
        $this->em->persist($this->account);
        $this->em->flush();
        $this->client = RegisteredMcpClient::of(self::CLIENT_ID, 'Client de test', ['https://client.example.test/callback']);
        self::getContainer()->get(McpClientRepositoryInterface::class)->add($this->client);
    }

    protected function tearDown(): void
    {
        $this->purgeTables();
        parent::tearDown();
    }

    /**
     * Une connexion inactive depuis trente jours est marquée expirée par la
     * purge, à l'instant de l'horloge, et auditée une fois, acteur `system`.
     * Une seconde purge n'écrit plus rien : la connexion garde son instant,
     * sa cause et sa ligne.
     */
    public function testAnExpiredConnectionIsMarkedOnceAndAudited(): void
    {
        $connection = $this->connection(new DateTimeImmutable('2026-10-01T10:00:00+02:00'), new DateTimeImmutable(self::IDLE_DEADLINE_LAST_USE));

        $result = $this->purger()->purge($this->retention());

        self::assertSame([$connection->getId()->toRfc4122()], $result->expired);
        $row = $this->revocationOf($connection);
        self::assertSame('expired', $row['cause']);
        self::assertSame($this->now()->getTimestamp(), $row['revokedAt']?->getTimestamp());

        $event = self::singleSecurityAuditEvent(self::REVOKED_EVENT);
        self::assertSame('system', $event['actor']);
        self::assertSame('expired', $event['cause']);
        self::assertSame($this->account->getId()->toRfc4122(), $event['userId']);
        self::assertSame($connection->getId()->toRfc4122(), $event['connectionId']);
        self::assertSame(self::CLIENT_ID, $event['clientId']);

        // Une heure plus tard, la même purge ne trouve plus rien à marquer.
        self::mockTime($this->now()->add(new DateInterval('PT1H')));
        $second = $this->purger()->purge($this->retention());

        self::assertSame([], $second->expired);
        $again = $this->revocationOf($connection);
        self::assertSame($row['cause'], $again['cause'], 'La première révocation garde sa cause.');
        self::assertSame($row['revokedAt']->getTimestamp(), $again['revokedAt']?->getTimestamp(),'La première révocation garde son instant.');
        self::assertCount(1, self::securityAuditEvents(self::REVOKED_EVENT), 'Une seconde purge n\'audite rien de plus.');
    }

    /**
     * Review Focus 5 de #649 : la purge et l'authentificateur jugent la même
     * échéance à la seconde près. Dernier usage exactement au seuil
     * d'inactivité, puis une seconde après ; octroi exactement au seuil
     * absolu, puis une seconde après. Pour chacune, marquée par la purge ⇔
     * `isExpiredAt()` relu en base. Les deux premières bornes passent le
     * changement d'heure : une purge qui jugerait en SQL par `now - 720
     * heures` marquerait aussi la seconde connexion.
     */
    public function testThePurgeAndIsExpiredAtAgreeAtTheBoundary(): void
    {
        $recentUse = new DateTimeImmutable('2026-11-09T10:00:00+01:00');
        $idleAtDeadline = new DateTimeImmutable(self::IDLE_DEADLINE_LAST_USE);
        $grantAtDeadline = new DateTimeImmutable(self::MAXIMUM_DEADLINE_GRANT);
        $cases = [
            'dernier usage au seuil d\'inactivité' => [$this->connection(new DateTimeImmutable('2026-10-01T10:00:00+02:00'), $idleAtDeadline), true],
            'dernier usage une seconde après' => [$this->connection(new DateTimeImmutable('2026-10-01T10:00:00+02:00'), $idleAtDeadline->add(new DateInterval('PT1S'))), false],
            'octroi au seuil absolu' => [$this->connection($grantAtDeadline, $recentUse), true],
            'octroi une seconde après' => [$this->connection($grantAtDeadline->add(new DateInterval('PT1S')), $recentUse), false],
        ];

        $result = $this->purger()->purge($this->retention());

        $this->em->clear();
        $lifetimes = self::getContainer()->get(ConnectionLifetimes::class);
        $connections = self::getContainer()->get(McpConnectionRepositoryInterface::class);
        foreach ($cases as $case => [$connection, $expectedExpired]) {
            $reread = $connections->findOneById($connection->getId());
            self::assertNotNull($reread, $case);
            $marked = \in_array($connection->getId()->toRfc4122(), $result->expired, true);

            self::assertSame($expectedExpired, $reread->isExpiredAt($this->now(), $lifetimes), $case.' : échéance attendue.');
            self::assertSame($reread->isExpiredAt($this->now(), $lifetimes), $marked, $case.' : la purge et isExpiredAt() divergent.');
            self::assertSame($marked, $reread->isRevoked(), $case.' : marquée ⇔ révoquée en base.');
        }
    }

    /**
     * Partent les codes et jetons échus (`expires_at <= now`) et tous ceux
     * d'une connexion révoquée ; restent ceux d'une connexion vivante non
     * échus — un rafraîchissement tourné compris, dont la ligne permet de
     * reconnaître la réutilisation. Un rafraîchissement vivant dont le jeton
     * d'accès échu est purgé lui survit (`ON DELETE SET NULL`).
     */
    public function testDeadTokensAndCodesAreDeletedAndLiveOnesKept(): void
    {
        $now = $this->now();
        $past = $now->sub(new DateInterval('PT1S'));
        $live = $this->connection($now->sub(new DateInterval('P1D')), null);
        $revoked = $this->connection($now->sub(new DateInterval('P2D')), null);

        $expiredCode = $this->code($live, $now);
        $liveCode = $this->code($live, $now->add(new DateInterval('PT5M')));
        $expiredAccess = $this->accessToken($live, $past);
        $liveAccess = $this->accessToken($live, $now->add(new DateInterval('PT30M')));
        $refreshOfExpiredAccess = $this->refreshToken($live, $expiredAccess, $now->add(new DateInterval('P29D')));
        $expiredRefresh = $this->refreshToken($live, null, $now);
        $rotatedRefresh = $this->refreshToken($live, null, $now->add(new DateInterval('P20D')));
        $this->sql('UPDATE mcp_refresh_token SET revoked_at = :at WHERE id = :id', $rotatedRefresh->getId(), $past);

        $codeOfRevoked = $this->code($revoked, $now->add(new DateInterval('PT5M')));
        $accessOfRevoked = $this->accessToken($revoked, $now->add(new DateInterval('PT30M')));
        $refreshOfRevoked = $this->refreshToken($revoked, $accessOfRevoked, $now->add(new DateInterval('P29D')));
        $this->sql("UPDATE mcp_connection SET revoked_at = :at, revocation_cause = 'client' WHERE id = :id", $revoked->getId(), $past);

        $result = $this->purger()->purge($this->retention());

        self::assertSame(2, $result->deletedAuthorizationCodes);
        self::assertSame(2, $result->deletedAccessTokens);
        self::assertSame(2, $result->deletedRefreshTokens);
        self::assertSame(0, $result->deletedConnections, 'Une connexion révoquée hier reste six mois.');
        self::assertSame([], $result->expired);

        self::assertSame([$liveCode->getId()->toRfc4122()], $this->idsIn('mcp_authorization_code'));
        self::assertSame([$liveAccess->getId()->toRfc4122()], $this->idsIn('mcp_access_token'));
        self::assertEqualsCanonicalizing(
            [$refreshOfExpiredAccess->getId()->toRfc4122(), $rotatedRefresh->getId()->toRfc4122()],
            $this->idsIn('mcp_refresh_token'),
        );
        self::assertNull(
            $this->em->getConnection()->fetchOne('SELECT access_token_id FROM mcp_refresh_token WHERE id = :id', ['id' => $refreshOfExpiredAccess->getId()], ['id' => UuidType::NAME]),
            'Le lien vers le jeton d\'accès purgé est remis à NULL.',
        );
        self::assertNotNull($this->revocationOf($revoked)['revokedAt']);
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT), 'Rien n\'a expiré : aucune ligne.');
        // Écartés pour la lisibilité : ils n'ont plus de ligne.
        unset($expiredCode, $expiredRefresh, $codeOfRevoked, $refreshOfRevoked);
    }

    /**
     * Une connexion révoquée depuis plus de six mois disparaît, ses lignes
     * avec elle ; une révoquée une seconde trop tard reste. Le seuil est
     * strict (`revoked_at < :threshold`) et rendu tel quel.
     */
    public function testAConnectionRevokedBeforeTheRetentionIsDeleted(): void
    {
        $threshold = new DateTimeImmutable(self::RETENTION_THRESHOLD);
        $old = $this->connection($threshold->sub(new DateInterval('P1D')), null);
        $recent = $this->connection($threshold->sub(new DateInterval('P1D')), null);
        $this->sql("UPDATE mcp_connection SET revoked_at = :at, revocation_cause = 'client' WHERE id = :id", $old->getId(), $threshold->sub(new DateInterval('PT1S')));
        $this->sql("UPDATE mcp_connection SET revoked_at = :at, revocation_cause = 'superseded' WHERE id = :id", $recent->getId(), $threshold->add(new DateInterval('PT1S')));

        $result = $this->purger()->purge($this->retention());

        self::assertSame($threshold->getTimestamp(), $result->threshold->getTimestamp());
        self::assertSame(1, $result->deletedConnections);
        self::assertSame([$recent->getId()->toRfc4122()], $this->idsIn('mcp_connection'));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT), 'Supprimer une connexion déjà révoquée n\'est pas la révoquer.');
    }

    /**
     * Sous un jour, la rétention supprimerait des connexions révoquées dans
     * l'heure : refusée avant toute écriture — rien marqué, rien supprimé,
     * rien audité.
     */
    public function testARetentionUnderADayIsRefused(): void
    {
        $expired = $this->connection(new DateTimeImmutable('2026-10-01T10:00:00+02:00'), new DateTimeImmutable(self::IDLE_DEADLINE_LAST_USE));
        $this->code($expired, $this->now()->sub(new DateInterval('PT1H')));

        try {
            $this->purger()->purge(RetentionPeriod::fromString('12 hours', $this->now()));
            self::fail('Une rétention de douze heures a été acceptée.');
        } catch (InvalidRetentionPeriodException) {
        }

        self::assertNull($this->revocationOf($expired)['revokedAt']);
        self::assertCount(1, $this->idsIn('mcp_authorization_code'));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    /**
     * L'audit suit le COMMIT, jamais l'intérieur de la transaction : une purge
     * annulée (ici après tout son travail, au moment de valider) ne laisse ni
     * révocation en base ni ligne d'audit pour une révocation qui n'a pas eu
     * lieu.
     */
    public function testARolledBackPurgeAuditsNothing(): void
    {
        $expired = $this->connection(new DateTimeImmutable('2026-10-01T10:00:00+02:00'), new DateTimeImmutable(self::IDLE_DEADLINE_LAST_USE));
        $purger = $this->purgerRunningIn(new RollingBackTransaction(self::getContainer()->get(TransactionInterface::class)));

        try {
            $purger->purge($this->retention());
            self::fail('La transaction annulée n\'a pas fait échouer la purge.');
        } catch (RolledBackOnPurposeException) {
        }

        self::assertNull($this->revocationOf($expired)['revokedAt'], 'La révocation a été annulée avec la transaction.');
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT), 'Une purge annulée n\'audite rien.');
    }

    /**
     * Le marquage et les suppressions sont deux transactions (#726) : le
     * marquage validé reste quand les suppressions sont annulées — la
     * connexion révoquée `expired`, sa ligne d'audit écrite après le premier
     * COMMIT —, et ce que la seconde transaction supprimait est toujours là,
     * pour la nuit suivante.
     */
    public function testTheMarkingIsAuditedEvenWhenTheDeletionsRollBack(): void
    {
        $expired = $this->connection(new DateTimeImmutable('2026-10-01T10:00:00+02:00'), new DateTimeImmutable(self::IDLE_DEADLINE_LAST_USE));
        $deadCode = $this->code($expired, $this->now()->sub(new DateInterval('PT1H')));
        $purger = $this->purgerRunningIn(new RollingBackTheSecondRun(self::getContainer()->get(TransactionInterface::class)));

        try {
            $purger->purge($this->retention());
            self::fail('Les suppressions annulées n\'ont pas fait échouer la purge.');
        } catch (RolledBackOnPurposeException) {
        }

        $row = $this->revocationOf($expired);
        self::assertSame('expired', $row['cause'], 'Le marquage a été validé avant les suppressions.');
        self::assertSame($this->now()->getTimestamp(), $row['revokedAt']?->getTimestamp());
        $event = self::singleSecurityAuditEvent(self::REVOKED_EVENT);
        self::assertSame('expired', $event['cause']);
        self::assertSame($expired->getId()->toRfc4122(), $event['connectionId']);
        self::assertSame([$deadCode->getId()->toRfc4122()], $this->idsIn('mcp_authorization_code'), 'Les suppressions ont été annulées.');
    }

    /**
     * Une panne de l'audit d'expiration ne saute ni les autres lignes ni les
     * suppressions (#726, B4 ; la règle de #725, « An audit failure never
     * changes the outcome ») : le marquage est validé, la révocation a eu
     * lieu. Le premier appel lève, le second est écrit, le jeton mort part
     * dans la seconde transaction, la purge rend son résultat, et la panne
     * sort une fois en `critical` sur le canal principal, la classe seule.
     *
     * L'ordre des lignes rendues par `RETURNING` n'est pas garanti : le test
     * ne suppose pas laquelle des deux connexions échoue.
     */
    public function testAnAuditFailureSkipsNeitherTheOtherLinesNorTheDeletions(): void
    {
        $first = $this->connection(new DateTimeImmutable('2026-10-01T10:00:00+02:00'), new DateTimeImmutable(self::IDLE_DEADLINE_LAST_USE));
        $second = $this->connection(new DateTimeImmutable('2026-10-01T10:00:00+02:00'), new DateTimeImmutable(self::IDLE_DEADLINE_LAST_USE));
        $live = $this->connection($this->now()->sub(new DateInterval('P1D')), null);
        $deadToken = $this->accessToken($live, $this->now()->sub(new DateInterval('PT1S')));

        /** @var list<string> $attempted les connexions dont l'audit a été tenté, dans l'ordre */
        $attempted = [];
        /** @var list<string> $audited les connexions dont l'audit a été écrit */
        $audited = [];
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $auditLogger->method('mcpConnectionExpired')->willReturnCallback(
            static function (McpConnectionReference $connection) use (&$attempted, &$audited): void {
                $attempted[] = $connection->connectionId()->toRfc4122();
                if (1 === \count($attempted)) {
                    throw new RuntimeException('Journal de sécurité indisponible');
                }
                $audited[] = $connection->connectionId()->toRfc4122();
            },
        );

        $result = $this->purgerWith(self::getContainer()->get(TransactionInterface::class), $auditLogger)->purge($this->retention());

        $expected = [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()];
        self::assertEqualsCanonicalizing($expected, $result->expired);
        self::assertSame('expired', $this->revocationOf($first)['cause']);
        self::assertSame('expired', $this->revocationOf($second)['cause']);
        self::assertEqualsCanonicalizing($expected, $attempted, 'Chaque connexion marquée est tentée.');
        self::assertSame([$attempted[1]], $audited, 'La seconde est auditée malgré la panne de la première.');
        self::assertSame(1, $result->deletedAccessTokens);
        self::assertNotContains($deadToken->getId()->toRfc4122(), $this->idsIn('mcp_access_token'), 'Les suppressions ont eu lieu.');

        $failures = array_values(array_filter(
            self::allChannelsLogRecords(),
            static fn (LogRecord $record): bool => self::NOT_AUDITED === $record->message,
        ));
        self::assertCount(1, $failures);
        self::assertSame('app', $failures[0]->channel, 'Le canal principal, jamais `security_audit`.');
        self::assertSame(Level::Critical, $failures[0]->level);
        self::assertSame(['exceptionClass' => RuntimeException::class], $failures[0]->context);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('Europe/Paris'));
    }

    private function retention(): RetentionPeriod
    {
        return RetentionPeriod::fromString(self::RETENTION, $this->now());
    }

    private function purger(): McpConnectionPurgerInterface
    {
        return self::getContainer()->get(McpConnectionPurgerInterface::class);
    }

    /** La purge du conteneur, sa transaction remplacée par un double. */
    private function purgerRunningIn(TransactionInterface $transaction): McpConnectionPurger
    {
        return $this->purgerWith($transaction, self::getContainer()->get(SecurityAuditLoggerInterface::class));
    }

    /** La purge du conteneur, sa transaction et l'écrivain du journal de sécurité remplacés. */
    private function purgerWith(TransactionInterface $transaction, SecurityAuditLoggerInterface $auditLogger): McpConnectionPurger
    {
        $container = self::getContainer();

        return new McpConnectionPurger(
            $container->get(McpConnectionRepositoryInterface::class),
            $container->get(McpAuthorizationCodeRepositoryInterface::class),
            $container->get(McpAccessTokenRepositoryInterface::class),
            $container->get(McpRefreshTokenRepositoryInterface::class),
            $transaction,
            $container->get(ConnectionLifetimes::class),
            $auditLogger,
            $container->get(ClockInterface::class),
            $container->get(LoggerInterface::class),
        );
    }

    /** Une connexion du compte et du client du test, accordée et utilisée aux instants donnés. */
    private function connection(DateTimeImmutable $grantedAt, ?DateTimeImmutable $lastUsedAt): McpConnection
    {
        $connection = McpConnection::grant($this->account, $this->client, self::SCOPE, $grantedAt);
        if (null !== $lastUsedAt) {
            $connection->recordUseAt($lastUsedAt);
        }
        self::getContainer()->get(McpConnectionRepositoryInterface::class)->add($connection);

        return $connection;
    }

    private function code(McpConnection $connection, DateTimeImmutable $expiresAt): McpAuthorizationCode
    {
        $code = new McpAuthorizationCode($connection, $this->nextHash(), $expiresAt);
        self::getContainer()->get(McpAuthorizationCodeRepositoryInterface::class)->add($code);

        return $code;
    }

    private function accessToken(McpConnection $connection, DateTimeImmutable $expiresAt): McpAccessToken
    {
        $token = new McpAccessToken($connection, $this->nextHash(), self::AUDIENCE, $expiresAt);
        self::getContainer()->get(McpAccessTokenRepositoryInterface::class)->add($token);

        return $token;
    }

    private function refreshToken(McpConnection $connection, ?McpAccessToken $accessToken, DateTimeImmutable $expiresAt): McpRefreshToken
    {
        $token = new McpRefreshToken($connection, $this->nextHash(), $accessToken, $expiresAt);
        self::getContainer()->get(McpRefreshTokenRepositoryInterface::class)->add($token);

        return $token;
    }

    /** Une empreinte bien formée et unique : le test ne présente aucun jeton. */
    private function nextHash(): string
    {
        return hash('sha256', 'purge-test-token-'.++$this->tokens);
    }

    /** Une écriture SQL sur une ligne, l'instant typé comme sa colonne. */
    private function sql(string $statement, Uuid $id, DateTimeImmutable $at): void
    {
        $this->em->getConnection()->executeStatement(
            $statement,
            ['id' => $id, 'at' => $at],
            ['id' => UuidType::NAME, 'at' => 'datetimetz_immutable'],
        );
    }

    /**
     * La révocation telle qu'en base, relue hors de l'EntityManager.
     *
     * @return array{revokedAt: ?DateTimeImmutable, cause: ?string}
     */
    private function revocationOf(McpConnection $connection): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT revoked_at, revocation_cause FROM mcp_connection WHERE id = :id',
            ['id' => $connection->getId()],
            ['id' => UuidType::NAME],
        );
        self::assertIsArray($row, 'La connexion a disparu.');
        $revokedAt = $row['revoked_at'];
        $cause = $row['revocation_cause'];
        self::assertTrue(null === $revokedAt || \is_string($revokedAt));
        self::assertTrue(null === $cause || \is_string($cause));

        return ['revokedAt' => null === $revokedAt ? null : new DateTimeImmutable($revokedAt), 'cause' => $cause];
    }

    /**
     * Les identifiants restant dans une table du modèle.
     *
     * @return list<string>
     */
    private function idsIn(string $table): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM '.$table.' ORDER BY id');

        return array_map(static fn (mixed $id): string => \is_string($id) ? $id : self::fail('Un identifiant non textuel.'), $ids);
    }

    private function purgeTables(): void
    {
        $connection = $this->em->getConnection();
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }
}
