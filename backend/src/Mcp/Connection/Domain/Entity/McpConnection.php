<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Infrastructure\Doctrine\McpConnectionRepository;
use App\Security\User\Domain\Entity\CpgUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Connexion MCP (glossaire, `CONTEXT.md`) : l'autorisation qu'une personne du
 * palier de confiance accorde à un client MCP de lire le corpus nominatif en
 * son nom. Ses codes et jetons s'y rattachent ; ils n'en sont que le support
 * technique.
 *
 * Le compte et le client sont des clés étrangères en cascade : un compte
 * supprimé n'a plus de connexion (ni, par cascade, de code ou de jeton), un
 * client retiré n'en laisse aucune derrière lui. Le dernier usage est écrit au
 * rafraîchissement (tâche 10 de la spec, #649), jamais à chaque appel ; la
 * révocation, par la personne, par expiration ou par réutilisation d'un jeton
 * tourné, l'est aussi plus tard — ce modèle n'en porte que les colonnes.
 */
#[ORM\Entity(repositoryClass: McpConnectionRepository::class)]
#[ORM\Table(name: 'mcp_connection')]
class McpConnection
{
    /** Spec 0003 : UUID v7 posé par le constructeur. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CpgUser::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'CASCADE')]
    private CpgUser $account;

    #[ORM\ManyToOne(targetEntity: McpClient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private McpClient $client;

    #[ORM\Column(length: 255)]
    private string $scope;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $grantedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    private function __construct(CpgUser $account, McpClient $client, string $scope, DateTimeImmutable $grantedAt)
    {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->client = $client;
        $this->scope = $scope;
        $this->grantedAt = $grantedAt;
    }

    /** Le consentement de `$account` à `$client`, pour `$scope`, à l'instant `$at`. */
    public static function grant(CpgUser $account, McpClient $client, string $scope, DateTimeImmutable $at): self
    {
        return new self($account, $client, $scope, $at);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function account(): CpgUser
    {
        return $this->account;
    }

    public function client(): McpClient
    {
        return $this->client;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function grantedAt(): DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }
}
