<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Exception\MalformedTokenHashException;
use App\Mcp\Connection\Domain\Service\TokenHashShape;
use App\Mcp\Connection\Infrastructure\Doctrine\McpAuthorizationCodeRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Code d'autorisation émis au consentement d'une connexion MCP, échangé une
 * seule fois contre des jetons au point de jeton (10 minutes de vie).
 *
 * Seule l'empreinte SHA-256 de son identifiant est stockée (`code_hash`,
 * unique) : un code lu en base ne s'échange pas. Rattaché à sa connexion en
 * cascade.
 */
#[ORM\Entity(repositoryClass: McpAuthorizationCodeRepository::class)]
#[ORM\Table(name: 'mcp_authorization_code')]
#[ORM\UniqueConstraint(name: self::HASH_UNIQUE_INDEX, columns: ['code_hash'])]
class McpAuthorizationCode
{
    use RevokedOnce;

    /** Nom de la contrainte d'unicité de l'empreinte, que la base cite dans sa violation. */
    public const string HASH_UNIQUE_INDEX = 'uniq_mcp_authorization_code_hash';

    /** Spec 0003 : UUID v7 posé par le constructeur. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: McpConnection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private McpConnection $connection;

    /** SHA-256 hexadécimal (64 caractères) de l'identifiant du code. */
    #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true])]
    private string $codeHash;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    /**
     * @throws MalformedTokenHashException quand `$codeHash` n'est pas un SHA-256 hexadécimal minuscule
     */
    public function __construct(McpConnection $connection, string $codeHash, DateTimeImmutable $expiresAt)
    {
        TokenHashShape::assertWellFormed($codeHash);

        $this->id = Uuid::v7();
        $this->connection = $connection;
        $this->codeHash = $codeHash;
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

    public function codeHash(): string
    {
        return $this->codeHash;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
