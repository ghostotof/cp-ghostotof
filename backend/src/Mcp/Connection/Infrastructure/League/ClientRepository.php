<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\OAuthProfile;
use App\Mcp\Connection\Domain\Exception\InvalidMcpClientIdException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use SensitiveParameter;

/**
 * Les clients MCP enregistrés (`app:mcp:client:register`), vus par league
 * (#647). Aucun enregistrement dynamique : un client inconnu ici est un
 * `invalid_client`.
 */
final readonly class ClientRepository implements ClientRepositoryInterface
{
    public function __construct(
        private McpClientRepositoryInterface $clients,
    ) {
    }

    /**
     * Un identifiant hors de la forme du domaine (McpClientId, la seule
     * qu'un client enregistré puisse avoir) n'est pas cherché : PostgreSQL
     * lit un paramètre texte comme une chaîne C (`…\0x` trouverait le client
     * `…`, le piège de #418 côté comptes) et refuse un octet hors UTF-8 par
     * une erreur — un 500 à la main de quiconque appelle le point
     * d'autorisation.
     */
    public function getClientEntity(string $clientIdentifier): ?OAuthClient
    {
        try {
            $clientId = McpClientId::fromString($clientIdentifier);
        } catch (InvalidMcpClientIdException) {
            return null;
        }

        $client = $this->clients->findOneByClientId((string) $clientId);

        return null === $client ? null : new OAuthClient($client);
    }

    /**
     * Vrai pour un client connu, sans secret, sur l'un des deux grants
     * servis. Exigée par l'interface, mais **jamais appelée** : league 9.4.1
     * ne la consulte que pour un client confidentiel
     * (`AbstractGrant::validateClient()`, sous `isConfidential()`) — aucun ne
     * l'est ici —, et la révocation reconnaît le client sans elle
     * (LeagueRevocationEndpoint). Un `client_secret` qu'un client public
     * envoie au point de jeton n'arrive donc pas ici : il est **ignoré**, pas
     * refusé (`TokenControllerTest::testASuperfluousClientSecretIsIgnored`).
     * Le refus d'un secret présenté reste écrit pour qui l'appellerait un
     * jour : la réponse prudente d'un serveur qui n'en délivre aucun. league
     * passe une chaîne vide quand aucun secret n'est présenté : vide et absent
     * valent « sans secret ».
     */
    public function validateClient(string $clientIdentifier, #[SensitiveParameter] ?string $clientSecret, ?string $grantType): bool
    {
        if (null !== $clientSecret && '' !== $clientSecret) {
            return false;
        }

        if (!\in_array($grantType, OAuthProfile::SERVED_GRANTS, true)) {
            return false;
        }

        return null !== $this->getClientEntity($clientIdentifier);
    }
}
