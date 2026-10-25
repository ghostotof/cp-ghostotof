<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Infrastructure\Doctrine\McpConnectionRepository;
use App\Security\User\Domain\Entity\CpgUser;
use DateInterval;
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
 * rafraîchissement (#649), jamais à chaque appel ; la révocation, par la
 * personne, par expiration ou par réutilisation d'un jeton tourné, l'est aussi
 * par #649 — ce modèle n'en porte que les colonnes.
 */
#[ORM\Entity(repositoryClass: McpConnectionRepository::class)]
#[ORM\Table(name: 'mcp_connection')]
class McpConnection
{
    /**
     * Le seul scope qu'une connexion accorde (spec 0007 phase 2) : lire le
     * corpus nominatif au nom de la personne qui a consenti. Écrit ici, dans
     * le domaine ; le serveur d'autorisation le relit (NominativeReadScope).
     */
    public const string SCOPE_NOMINATIVE_READ = 'nominative:read';

    /**
     * Délai d'inactivité (`DateInterval`) : une connexion inutilisée pendant
     * 30 jours expire, comptés depuis son dernier usage, ou depuis l'octroi
     * tant qu'aucun usage n'est écrit. Des jours de calendrier, comme la
     * durée d'un jeton de rafraîchissement (`P30D`).
     *
     * Une constante du domaine jusqu'à #649, qui la passe en configuration.
     */
    public const string IDLE_LIFETIME = 'P30D';

    /**
     * Durée maximale (`DateInterval`) : 90 jours après l'octroi, la connexion
     * expire quel que soit son usage — la personne consent de nouveau.
     *
     * Une constante du domaine jusqu'à #649, qui la passe en configuration.
     */
    public const string MAX_LIFETIME = 'P90D';

    /** Spec 0003 : UUID v7 posé par le constructeur. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CpgUser::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'CASCADE')]
    private CpgUser $account;

    /**
     * `mcp_client_id`, pas `client_id` : ce dernier nom est, dans `mcp_client`,
     * l'identifiant public d'OAuth (un texte), et ici une clé UUID.
     */
    #[ORM\ManyToOne(targetEntity: McpClient::class)]
    #[ORM\JoinColumn(name: 'mcp_client_id', nullable: false, onDelete: 'CASCADE')]
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

    /**
     * La connexion accorde-t-elle `nominative:read` ? Le scope stocké est la
     * liste que league a retenue, séparée par une espace (RFC 6749 §3.3) :
     * comparé mot pour mot et à la casse près (un scope est sensible à la
     * casse), jamais par préfixe.
     */
    public function grantsNominativeRead(): bool
    {
        return \in_array(self::SCOPE_NOMINATIVE_READ, explode(' ', $this->scope), true);
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

    /**
     * La connexion a-t-elle expiré à l'instant `$now` ? Deux échéances, la
     * première atteinte l'emporte, et chacune est exclusive (atteinte à la
     * seconde près, la connexion est morte) :
     *
     * - le délai d'inactivité, {@see self::IDLE_LIFETIME} après
     *   `lastUsedAt ?? grantedAt` ;
     * - la durée maximale, {@see self::MAX_LIFETIME} après `grantedAt`.
     *
     * `lastUsedAt` n'est écrit qu'au rafraîchissement, à partir de #649 —
     * jamais à chaque appel (ADR 0006 D6). D'ici là il reste nul, et une
     * connexion meurt 30 jours après l'octroi même utilisée chaque jour :
     * accepté, la phase 2 ne sort qu'entière.
     *
     * Les durées s'ajoutent par `add(new DateInterval(…))`, jamais par
     * `modify()` (règle des durées écoulées, #445). La révocation n'est pas
     * une expiration : {@see self::isRevoked()} se lit à part.
     */
    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        $idleDeadline = ($this->lastUsedAt ?? $this->grantedAt)->add(new DateInterval(self::IDLE_LIFETIME));
        $maxDeadline = $this->grantedAt->add(new DateInterval(self::MAX_LIFETIME));

        return $now >= $idleDeadline || $now >= $maxDeadline;
    }
}
