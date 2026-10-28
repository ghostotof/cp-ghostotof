<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Application\RevokedConnectionAudit;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * La ligne `mcp-connection-revoked` des révocations de la personne et de tout
 * un compte (#650, R5a), écrite à un seul endroit : la ligne au journal de
 * sécurité avec la cause donnée, et sa panne — jamais remontée — en
 * `critical`, message constant, classe seule.
 */
final class RevokedConnectionAuditTest extends TestCase
{
    private const string ACCOUNT_ID = '01929a4e-1111-7000-8000-000000000001';
    private const string CLIENT_ID = 'https://client.example.test/metadata.json';
    private const string NOT_AUDITED = 'Revoked connection not audited.';

    /** Le cas nominal : une ligne, la connexion et la cause telles que reçues, rien au journal principal. */
    public function testItWritesOneLineWithTheGivenCause(): void
    {
        $connection = new ConnectionReference(Uuid::v7(), self::ACCOUNT_ID, self::CLIENT_ID);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('mcpConnectionRevoked')->with($connection, 'person');
        $mainChannel = new TestHandler();

        $this->audit($auditLogger, $mainChannel)->record($connection, 'person');

        self::assertSame([], $mainChannel->getRecords());
    }

    /** Une panne de l'audit ne remonte pas : `critical`, le message constant, la classe seule. */
    public function testAFailureIsNeitherRaisedNorQuoted(): void
    {
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $auditLogger->method('mcpConnectionRevoked')->willThrowException(new RuntimeException('connexion citée 01929a4e'));
        $mainChannel = new TestHandler();

        $this->audit($auditLogger, $mainChannel)
            ->record(new ConnectionReference(Uuid::v7(), self::ACCOUNT_ID, self::CLIENT_ID), 'password-changed');

        $records = $mainChannel->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Critical, $records[0]->level);
        self::assertSame(self::NOT_AUDITED, $records[0]->message);
        self::assertSame(['exceptionClass' => RuntimeException::class], $records[0]->context);
    }

    private function audit(SecurityAuditLoggerInterface $auditLogger, TestHandler $mainChannel): RevokedConnectionAudit
    {
        return new RevokedConnectionAudit($auditLogger, new FailSafeAudit(new Logger('app', [$mainChannel])));
    }
}
