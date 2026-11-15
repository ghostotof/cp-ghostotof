<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Application\Cimd\CimdClientAdmission;
use App\Mcp\Connection\Application\Cimd\CimdClientResolverInterface;
use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Tests\Mcp\Connection\Application\McpClientRepositoryFake;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Exception as MockException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/**
 * L'admission d'un `client_id` CIMD (#651) : la ligne en base d'abord, puis la
 * liste des domaines, puis le document. Le résolveur et le journal d'audit
 * sont des doublures qui comptent leurs appels : ce qu'on vérifie ici, c'est
 * surtout ce qui n'est PAS appelé (aucune récupération pour une ligne
 * pré-enregistrée ou un hôte hors liste, aucun audit pour un hôte hors liste).
 */
final class CimdClientAdmissionTest extends TestCase
{
    public function testAnIdentifierThatIsNotCimdShapedIsLeftToTheRows(): void
    {
        $resolver = $this->createMock(CimdClientResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        $admitted = $this->admission(new McpClientRepositoryFake(null, null), $resolver, $this->neverAudited())->admit('claude-code');

        self::assertNull($admitted, 'Un identifiant qui n\'est pas une URL CIMD reste jugé par les lignes en base.');
    }

    public function testAPreregisteredRowWinsWithoutAnyFetch(): void
    {
        $row = RegisteredMcpClient::of(ClaudeCodeMetadata::CLIENT_ID, 'Claude Code', ['http://localhost/callback']);
        $resolver = $this->createMock(CimdClientResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        $admitted = $this->admission(new McpClientRepositoryFake($row, null), $resolver, $this->neverAudited())->admit(ClaudeCodeMetadata::CLIENT_ID);

        self::assertNull($admitted, 'Une ligne pré-enregistrée prime : aucune récupération, league la juge.');
    }

    public function testADomainOutsideTheListIsRefusedWithoutAnyFetchNorAudit(): void
    {
        $resolver = $this->createMock(CimdClientResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        $admission = $this->admission(
            new McpClientRepositoryFake(null, null),
            $resolver,
            $this->neverAudited(),
            AllowedClientDomains::fromList(['chatgpt.com']),
        );

        self::assertSame(ClientMetadataRefusal::DomainNotAllowed, $this->refusalOf($admission)->refusal);
    }

    public function testADomainNotAllowedRaisedByTheResolverIsNotAudited(): void
    {
        $resolver = $this->createMock(CimdClientResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')
            ->willThrowException(ClientMetadataRefusedException::because(ClientMetadataRefusal::DomainNotAllowed));

        $admission = $this->admission(new McpClientRepositoryFake(null, null), $resolver, $this->neverAudited());

        self::assertSame(ClientMetadataRefusal::DomainNotAllowed, $this->refusalOf($admission)->refusal);
    }

    public function testAnAllowedDomainYieldsTheDiscoveredClient(): void
    {
        $admitted = $this->admission(new McpClientRepositoryFake(null, null), $this->resolverServingClaudeCode(), $this->neverAudited())
            ->admit(ClaudeCodeMetadata::CLIENT_ID);

        self::assertInstanceOf(McpClient::class, $admitted);
        self::assertSame(ClientRegistration::Cimd, $admitted->registration());
        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $admitted->getClientId());
        self::assertSame('Claude Code', $admitted->getName(), 'Le nom vient du document.');
    }

    public function testADiscoveredRowIsJudgedAgainByItsDocument(): void
    {
        $document = ClientMetadataDocument::fromJson($this->claudeClientId(), ClaudeCodeMetadata::json());
        $row = McpClient::discovered($document, new MockClock('2026-11-01 10:00:00')->now());

        $resolver = $this->createMock(CimdClientResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->willReturn($document);

        $admitted = $this->admission(new McpClientRepositoryFake($row, null), $resolver, $this->neverAudited())->admit(ClaudeCodeMetadata::CLIENT_ID);

        self::assertInstanceOf(McpClient::class, $admitted, 'Une ligne déclarée n\'est pas court-circuitée : le document la rejuge.');
        self::assertSame(ClientRegistration::Cimd, $admitted->registration());
        self::assertNotSame($row, $admitted, 'Le client rendu est issu du document, pas de la ligne.');
    }

    public function testADiscoveredRowWhoseDomainLeftTheListIsRefused(): void
    {
        $document = ClientMetadataDocument::fromJson($this->claudeClientId(), ClaudeCodeMetadata::json());
        $row = McpClient::discovered($document, new MockClock('2026-11-01 10:00:00')->now());

        $resolver = $this->createMock(CimdClientResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        $admission = $this->admission(
            new McpClientRepositoryFake($row, null),
            $resolver,
            $this->neverAudited(),
            AllowedClientDomains::fromList(['chatgpt.com']),
        );

        self::assertSame(ClientMetadataRefusal::DomainNotAllowed, $this->refusalOf($admission)->refusal);
    }

    #[DataProvider('refusalsAfterTheList')]
    public function testARefusalAfterTheListIsAuditedWithHostAndCause(ClientMetadataRefusal $refusal): void
    {
        $refused = ClientMetadataRefusedException::because($refusal);
        $resolver = self::createStub(CimdClientResolverInterface::class);
        $resolver->method('resolve')->willThrowException($refused);

        $audit = $this->createMock(SecurityAuditLoggerInterface::class);
        $audit->expects(self::once())->method('mcpClientMetadataRefused')->with('claude.ai', $refusal->value);

        $admission = $this->admission(new McpClientRepositoryFake(null, null), $resolver, $audit);

        self::assertSame($refused, $this->refusalOf($admission), 'L\'exception est relancée telle quelle.');
    }

    /**
     * Un audit qui lève (handler du canal de sécurité en panne) ne remplace
     * jamais le refus : l'appelant n'attrape que ClientMetadataRefusedException,
     * l'exception de l'audit sortirait en 500 au lieu du 400/429/503 attendu.
     */
    public function testAFailingAuditNeverReplacesTheRefusal(): void
    {
        $refused = ClientMetadataRefusedException::because(ClientMetadataRefusal::Unresolvable);
        $resolver = self::createStub(CimdClientResolverInterface::class);
        $resolver->method('resolve')->willThrowException($refused);

        $audit = $this->createMock(SecurityAuditLoggerInterface::class);
        $audit->expects(self::once())->method('mcpClientMetadataRefused')
            ->willThrowException(new RuntimeException('handler de sécurité en panne'));

        $admission = $this->admission(new McpClientRepositoryFake(null, null), $resolver, $audit);

        self::assertSame($refused, $this->refusalOf($admission), 'Le refus est relancé tel quel, malgré la panne de l\'audit.');
    }

    /**
     * @return iterable<string, array{ClientMetadataRefusal}>
     */
    public static function refusalsAfterTheList(): iterable
    {
        foreach (ClientMetadataRefusal::cases() as $refusal) {
            if (ClientMetadataRefusal::DomainNotAllowed !== $refusal) {
                yield $refusal->value => [$refusal];
            }
        }
    }

    private function admission(
        McpClientRepositoryFake $clients,
        CimdClientResolverInterface $resolver,
        SecurityAuditLoggerInterface $audit,
        ?AllowedClientDomains $allowed = null,
    ): CimdClientAdmission {
        return new CimdClientAdmission(
            $clients,
            $allowed ?? AllowedClientDomains::fromList(['claude.ai']),
            $resolver,
            $audit,
            new MockClock('2026-11-13 10:00:00'),
            new FailSafeAudit(new NullLogger()),
        );
    }

    /**
     * @throws MockException
     */
    private function neverAudited(): SecurityAuditLoggerInterface
    {
        $audit = $this->createMock(SecurityAuditLoggerInterface::class);
        $audit->expects(self::never())->method('mcpClientMetadataRefused');

        return $audit;
    }

    private function resolverServingClaudeCode(): CimdClientResolverInterface
    {
        $resolver = self::createStub(CimdClientResolverInterface::class);
        $resolver->method('resolve')->willReturn(ClientMetadataDocument::fromJson($this->claudeClientId(), ClaudeCodeMetadata::json()));

        return $resolver;
    }

    private function claudeClientId(): CimdClientId
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID);
        self::assertNotNull($clientId);

        return $clientId;
    }

    private function refusalOf(CimdClientAdmission $admission): ClientMetadataRefusedException
    {
        try {
            $admission->admit(ClaudeCodeMetadata::CLIENT_ID);
        } catch (ClientMetadataRefusedException $refused) {
            return $refused;
        }

        self::fail('L\'admission aurait dû être refusée.');
    }
}
