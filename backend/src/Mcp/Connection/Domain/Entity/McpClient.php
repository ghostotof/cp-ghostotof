<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Exception\InvalidRedirectUriException;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use App\Mcp\Connection\Infrastructure\Doctrine\McpClientRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Client MCP autorisé à demander une connexion MCP (spec 0007, phase 2) : un
 * client public, sans secret, connu par son identifiant (l'URL de son document
 * de métadonnées pour un client CIMD), son nom déclaré et ses redirections
 * enregistrées.
 *
 * Le nom est **déclaré** par le client : la page de consentement le marque
 * comme tel, c'est le domaine de l'identifiant qui fait foi. Les redirections
 * sont stockées telles qu'enregistrées, en liste JSON ; leur forme (https
 * exacte, ou boucle locale comparée sans port) est jugée par le Value Object
 * {@see RedirectUri} (#647), pas ici : `app:mcp:client:register` les y fait
 * passer avant l'enregistrement, et allowsRedirect() les y relit.
 */
#[ORM\Entity(repositoryClass: McpClientRepository::class)]
#[ORM\Table(name: 'mcp_client')]
#[ORM\UniqueConstraint(name: self::CLIENT_ID_UNIQUE_INDEX, columns: ['client_id'])]
class McpClient
{
    /** Nom de la contrainte d'unicité de l'identifiant public, que la base cite dans sa violation. */
    public const string CLIENT_ID_UNIQUE_INDEX = 'uniq_mcp_client_client_id';

    /**
     * 512 caractères : une URL de document de métadonnées plus longue n'a rien
     * de raisonnable, et 512 caractères de 4 octets (2 048) tiennent sous la
     * limite d'une entrée d'index B-tree de PostgreSQL (~2 700 octets) — une
     * insertion ne peut pas échouer sur l'index pour sa seule longueur.
     */
    public const int CLIENT_ID_MAX_LENGTH = 512;

    public const int NAME_MAX_LENGTH = 255;

    /** Spec 0003 : UUID v7 posé par le constructeur. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: self::CLIENT_ID_MAX_LENGTH)]
    private string $clientId;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $redirectUris;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $registeredAt;

    /**
     * @param list<string> $redirectUris
     */
    private function __construct(string $clientId, string $name, array $redirectUris, DateTimeImmutable $registeredAt)
    {
        $this->id = Uuid::v7();
        $this->clientId = $clientId;
        $this->name = $name;
        $this->redirectUris = $redirectUris;
        $this->registeredAt = $registeredAt;
    }

    /**
     * Enregistre un client à l'instant `$at`.
     *
     * @param list<string> $redirectUris
     */
    public static function register(string $clientId, string $name, array $redirectUris, DateTimeImmutable $at): self
    {
        return new self($clientId, $name, $redirectUris, $at);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function redirectUris(): array
    {
        return $this->redirectUris;
    }

    public function registeredAt(): DateTimeImmutable
    {
        return $this->registeredAt;
    }

    /**
     * Vrai si la redirection demandée désigne l'une des redirections
     * enregistrées, selon la règle de RedirectUri::matches().
     *
     * Une valeur enregistrée que RedirectUri ne lit plus (écrite à la main en
     * base, ou avant une règle plus stricte) n'autorise rien, sans exception :
     * le point d'autorisation doit répondre par un refus, jamais par un 500.
     */
    public function allowsRedirect(string $requested): bool
    {
        foreach ($this->redirectUris as $registered) {
            try {
                $redirectUri = RedirectUri::fromString($registered);
            } catch (InvalidRedirectUriException) {
                continue;
            }

            if ($redirectUri->matches($requested)) {
                return true;
            }
        }

        return false;
    }
}
