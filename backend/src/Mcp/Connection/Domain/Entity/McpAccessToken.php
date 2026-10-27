<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Exception\MalformedTokenHashException;
use App\Mcp\Connection\Domain\Service\TokenHashShape;
use App\Mcp\Connection\Domain\ValueObject\BearerRefusal;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Infrastructure\Doctrine\McpAccessTokenRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Jeton d'accès d'une connexion MCP (sa durée de vie est celle de la
 * configuration, {@see ConnectionLifetimes::accessToken()}), présenté en
 * `Authorization: Bearer` sur `/api/mcp`.
 *
 * Seule l'empreinte SHA-256 de son identifiant est stockée (`token_hash`,
 * unique) : un jeton lu en base ne s'emprunte pas. L'audience (RFC 8707) est
 * celle accordée à l'émission, l'URL canonique de `/api/mcp` : l'authentificateur
 * refuse un jeton d'une autre. Rattaché à sa connexion en cascade.
 */
#[ORM\Entity(repositoryClass: McpAccessTokenRepository::class)]
#[ORM\Table(name: 'mcp_access_token')]
#[ORM\UniqueConstraint(name: self::HASH_UNIQUE_INDEX, columns: ['token_hash'])]
class McpAccessToken
{
    use RevokedOnce;

    /** Nom de la contrainte d'unicité de l'empreinte, que la base cite dans sa violation. */
    public const string HASH_UNIQUE_INDEX = 'uniq_mcp_access_token_hash';

    /** Spec 0003 : UUID v7 posé par le constructeur. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: McpConnection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private McpConnection $connection;

    /** SHA-256 hexadécimal (64 caractères) de l'identifiant du jeton. */
    #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[ORM\Column(length: 512)]
    private string $audience;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    /**
     * @throws MalformedTokenHashException quand `$tokenHash` n'est pas un SHA-256 hexadécimal minuscule
     */
    public function __construct(McpConnection $connection, string $tokenHash, string $audience, DateTimeImmutable $expiresAt)
    {
        TokenHashShape::assertWellFormed($tokenHash);

        $this->id = Uuid::v7();
        $this->connection = $connection;
        $this->tokenHash = $tokenHash;
        $this->audience = $audience;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function connection(): McpConnection
    {
        return $this->connection;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function audience(): string
    {
        return $this->audience;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Ce qui refuse ce jeton à l'instant `$now` pour la ressource
     * `$expectedAudience`, ou `null` s'il est recevable. Les causes se jugent
     * dans un ordre fixe, la première trouvée est rendue : jeton révoqué,
     * jeton expiré, audience, connexion révoquée, connexion expirée, scope de
     * la connexion — le jeton d'abord, sa connexion ensuite.
     *
     * L'échéance du jeton est exclusive (`expiresAt <= now` : à la seconde de
     * son échéance, il est mort) ; league ne la vérifie pas sur un jeton
     * opaque, c'est donc ici qu'elle l'est. L'audience se compare octet pour
     * octet (RFC 8707) : une barre oblique finale de plus est une autre
     * ressource. Le palier du compte n'est pas jugé ici — le domaine du jeton
     * ne connaît pas la hiérarchie des rôles — mais par l'authentificateur
     * ({@see BearerRefusal::AccountNotTrusted}). Le scope est celui que la
     * connexion a stocké au consentement, comparé mot pour mot : `/api/mcp`
     * n'honore que `nominative:read`, jamais présumé du seul fait qu'une
     * connexion existe ({@see McpConnection::grantsNominativeRead()}).
     * L'échéance de la connexion est celle que jugent ses durées configurées
     * (`$lifetimes`, {@see McpConnection::isExpiredAt()}).
     */
    public function refusalAt(DateTimeImmutable $now, string $expectedAudience, ConnectionLifetimes $lifetimes): ?BearerRefusal
    {
        return match (true) {
            $this->isRevoked() => BearerRefusal::TokenRevoked,
            $this->expiresAt <= $now => BearerRefusal::TokenExpired,
            $this->audience !== $expectedAudience => BearerRefusal::ForeignAudience,
            $this->connection->isRevoked() => BearerRefusal::ConnectionRevoked,
            $this->connection->isExpiredAt($now, $lifetimes) => BearerRefusal::ConnectionExpired,
            !$this->connection->grantsNominativeRead() => BearerRefusal::InsufficientScope,
            default => null,
        };
    }
}
