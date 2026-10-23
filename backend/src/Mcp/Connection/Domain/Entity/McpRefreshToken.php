<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Exception\MalformedTokenHashException;
use App\Mcp\Connection\Domain\Service\TokenHashShape;
use App\Mcp\Connection\Infrastructure\Doctrine\McpRefreshTokenRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Jeton de rafraîchissement d'une connexion MCP (30 jours de vie).
 *
 * Seule l'empreinte SHA-256 de son identifiant est stockée (`token_hash`,
 * unique). Il garde le jeton d'accès émis avec lui, que la rotation (#649)
 * révoque ; ce lien est `ON DELETE SET NULL` : la purge d'un jeton d'accès
 * expiré n'emporte pas le rafraîchissement, qui lui survit de 30 jours.
 * Rattaché à sa connexion en cascade.
 */
#[ORM\Entity(repositoryClass: McpRefreshTokenRepository::class)]
#[ORM\Table(name: 'mcp_refresh_token')]
#[ORM\UniqueConstraint(name: self::HASH_UNIQUE_INDEX, columns: ['token_hash'])]
class McpRefreshToken
{
    use RevokedOnce;

    /** Nom de la contrainte d'unicité de l'empreinte, que la base cite dans sa violation. */
    public const string HASH_UNIQUE_INDEX = 'uniq_mcp_refresh_token_hash';

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

    #[ORM\ManyToOne(targetEntity: McpAccessToken::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?McpAccessToken $accessToken;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    /**
     * @throws MalformedTokenHashException quand `$tokenHash` n'est pas un SHA-256 hexadécimal minuscule
     */
    public function __construct(McpConnection $connection, string $tokenHash, ?McpAccessToken $accessToken, DateTimeImmutable $expiresAt)
    {
        TokenHashShape::assertWellFormed($tokenHash);

        $this->id = Uuid::v7();
        $this->connection = $connection;
        $this->tokenHash = $tokenHash;
        $this->accessToken = $accessToken;
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

    public function accessToken(): ?McpAccessToken
    {
        return $this->accessToken;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
