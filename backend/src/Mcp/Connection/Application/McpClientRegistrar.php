<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

final readonly class McpClientRegistrar implements McpClientRegistrarInterface
{
    /**
     * Le premier identifiant SQL entre guillemets droits ou chevrons du
     * message de PostgreSQL, espaces insécables comprises : le nom de l'index
     * violé, jamais traduit. Même lecture que
     * {@see \App\Security\User\Infrastructure\Doctrine\CpgUserUniqueViolation} :
     * jamais une recherche dans tout le message, dont le `DETAIL` cite la valeur.
     */
    private const string QUOTED_IDENTIFIER = '/["«]\s*([A-Za-z0-9_]+)\s*["»]/u';

    public function __construct(
        private McpClientRepositoryInterface $clients,
        private ClockInterface $clock,
    ) {
    }

    public function register(string $clientId, string $name, array $redirectUris): McpClient
    {
        if (null !== $this->clients->findOneByClientId($clientId)) {
            throw McpClientAlreadyRegisteredException::alreadyTaken();
        }

        $client = McpClient::register(
            $clientId,
            $name,
            array_map(static fn (RedirectUri $redirectUri): string => (string) $redirectUri, $redirectUris),
            $this->clock->now(),
        );

        try {
            $this->clients->add($client);
        } catch (UniqueConstraintViolationException $violation) {
            // Deux enregistrements simultanés ont passé la lecture ci-dessus :
            // la contrainte unique de `client_id` est le dernier rempart. Seule
            // elle dit « identifiant pris » ; toute autre unicité (une
            // contrainte ajoutée un jour sans ce code) remonte telle quelle,
            // aucune cause n'étant affirmée à tort. La violation reconnue n'est
            // pas gardée en `previous` : elle n'apprend rien de plus à
            // l'opérateur.
            if (McpClient::CLIENT_ID_UNIQUE_INDEX !== $this->violatedIndex($violation)) {
                throw $violation;
            }

            throw McpClientAlreadyRegisteredException::takenMeanwhile();
        }

        return $client;
    }

    private function violatedIndex(UniqueConstraintViolationException $violation): ?string
    {
        return 1 === preg_match(self::QUOTED_IDENTIFIER, $violation->getMessage(), $matches) ? $matches[1] : null;
    }
}
