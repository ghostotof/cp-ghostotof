<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Exception\InvalidRedirectUriException;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
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
 * Chaque valeur est jugée par son Value Object avant d'arriver ici
 * (register() ne prend qu'eux) : l'identifiant par McpClientId, le nom par
 * McpClientName, chaque redirection par {@see RedirectUri} (https exacte, ou
 * boucle locale comparée sans port). La commande `app:mcp:client:register` et
 * le futur backoffice (#652) partagent ainsi les mêmes règles. Le nom d'un
 * client pré-enregistré est celui que l'administrateur du site a saisi ; celui
 * d'un client CIMD (#651, {@see self::discovered()}) vient de son document, et
 * la page de consentement le marque comme tel. Le mode d'enregistrement
 * ({@see ClientRegistration}) distingue les deux. Les redirections sont stockées en liste JSON, et
 * allowsRedirect() les relit par RedirectUri.
 */
#[ORM\Entity(repositoryClass: McpClientRepository::class)]
#[ORM\Table(name: 'mcp_client')]
#[ORM\UniqueConstraint(name: self::CLIENT_ID_UNIQUE_INDEX, columns: ['client_id'])]
class McpClient
{
    /** Nom de la contrainte d'unicité de l'identifiant public, que la base cite dans sa violation. */
    public const string CLIENT_ID_UNIQUE_INDEX = 'uniq_mcp_client_client_id';

    /**
     * La borne du domaine (McpClientId), pas une autre : la colonne ne refuse
     * rien que le domaine admette, et n'admet rien qu'il refuse. 255
     * caractères ASCII tiennent largement sous la limite d'une entrée d'index
     * B-tree de PostgreSQL (~2 700 octets) — une insertion ne peut pas échouer
     * sur l'index pour sa seule longueur.
     */
    public const int CLIENT_ID_MAX_LENGTH = McpClientId::MAX_LENGTH;

    /** La borne du domaine (McpClientName), en caractères comme `VARCHAR`. */
    public const int NAME_MAX_LENGTH = McpClientName::MAX_LENGTH;

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

    /** Qui a jugé ce client : une personne (`preregistered`) ou son document (`cimd`), #651. */
    #[ORM\Column(type: 'string', length: 16, enumType: ClientRegistration::class, options: ['default' => 'preregistered'])]
    private ClientRegistration $registration;

    /**
     * @param list<string> $redirectUris
     */
    private function __construct(string $clientId, string $name, array $redirectUris, DateTimeImmutable $registeredAt, ClientRegistration $registration)
    {
        $this->id = Uuid::v7();
        $this->clientId = $clientId;
        $this->name = $name;
        $this->redirectUris = $redirectUris;
        $this->registeredAt = $registeredAt;
        $this->registration = $registration;
    }

    /**
     * Enregistre un client à l'instant `$at`, à partir de valeurs que le
     * domaine a déjà jugées : le type l'impose, aucune chaîne brute n'atteint
     * l'entité par ici. Une redirection répétée à l'identique n'est gardée
     * qu'une fois, dans l'ordre reçu.
     *
     * @param list<RedirectUri> $redirectUris
     */
    public static function register(McpClientId $clientId, McpClientName $name, array $redirectUris, DateTimeImmutable $at): self
    {
        $distinct = array_values(array_unique(array_map(static fn (RedirectUri $redirectUri): string => (string) $redirectUri, $redirectUris)));

        return new self((string) $clientId, (string) $name, $distinct, $at, ClientRegistration::Preregistered);
    }

    /**
     * Déclare un client à l'instant `$at`, à partir du document que le domaine
     * vient de juger ({@see ClientMetadataDocument}) : l'identifiant est
     * l'URL du document, le nom et les redirections sont les siens. Le mode est
     * `cimd` : ce client peut être rafraîchi depuis un document plus récent, ce
     * qu'un client pré-enregistré ne peut jamais.
     *
     * Le document a déjà écarté les redirections inadmissibles ; une
     * redirection répétée n'est gardée qu'une fois, dans l'ordre reçu.
     */
    public static function discovered(ClientMetadataDocument $document, DateTimeImmutable $at): self
    {
        $distinct = array_values(array_unique(array_map(static fn (RedirectUri $redirectUri): string => (string) $redirectUri, $document->redirectUris)));

        return new self((string) $document->clientId->toMcpClientId(), (string) $document->name, $distinct, $at, ClientRegistration::Cimd);
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

    public function registration(): ClientRegistration
    {
        return $this->registration;
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
