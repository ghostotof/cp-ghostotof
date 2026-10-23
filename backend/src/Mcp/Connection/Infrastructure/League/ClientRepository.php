<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use SensitiveParameter;

/**
 * Les clients MCP enregistrés (`app:mcp:client:register`), vus par league
 * (#647). Aucun enregistrement dynamique : un client inconnu ici est un
 * `invalid_client`.
 */
final readonly class ClientRepository implements ClientRepositoryInterface
{
    /**
     * Ce que la colonne `client_id` peut contenir : 1 à 512 caractères UTF-8,
     * aucun caractère de contrôle. Un identifiant hors de cette forme n'est
     * pas cherché : PostgreSQL lit un paramètre texte comme une chaîne C
     * (`…\0x` trouverait le client `…`, le piège de #418 côté comptes) et
     * refuse un octet hors UTF-8 par une erreur — un 500 à la main de
     * quiconque appelle le point d'autorisation. Le `u` fait échouer
     * `preg_match()` sur un UTF-8 invalide.
     */
    private const string SEARCHABLE_CLIENT_ID = '/\A[^\x00-\x1F\x7F]{1,'.McpClient::CLIENT_ID_MAX_LENGTH.'}\z/u';

    public function __construct(
        private McpClientRepositoryInterface $clients,
    ) {
    }

    public function getClientEntity(string $clientIdentifier): ?OAuthClient
    {
        if (1 !== preg_match(self::SEARCHABLE_CLIENT_ID, $clientIdentifier)) {
            return null;
        }

        $client = $this->clients->findOneByClientId($clientIdentifier);

        return null === $client ? null : new OAuthClient($client);
    }

    /**
     * Vrai pour un client connu, sans secret, sur l'un des deux grants
     * servis. league ne l'appelle que pour un client confidentiel — aucun ne
     * l'est ici —, et passe une chaîne vide quand aucun secret n'est présenté :
     * vide et absent valent « sans secret ».
     */
    public function validateClient(string $clientIdentifier, #[SensitiveParameter] ?string $clientSecret, ?string $grantType): bool
    {
        if (null !== $clientSecret && '' !== $clientSecret) {
            return false;
        }

        if (!\in_array($grantType, OAuthClient::SERVED_GRANTS, true)) {
            return false;
        }

        return null !== $this->getClientEntity($clientIdentifier);
    }
}
