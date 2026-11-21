<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\Exception\McpClientIdOfVerifiedDomainException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Security\Authentication\Application\AuditOrigin;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Infrastructure\Doctrine\ViolatedUniqueIndex;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

final readonly class McpClientRegistrar implements McpClientRegistrarInterface
{
    /** Constant : il nomme ce qui n'a pas été audité, jamais la panne ni le client. */
    private const string REGISTRATION_NOT_AUDITED = 'MCP client registration not audited.';

    public function __construct(
        private McpClientRepositoryInterface $clients,
        private ClockInterface $clock,
        private SecurityAuditLoggerInterface $auditLogger,
        private FailSafeAudit $failSafeAudit,
        private AllowedClientDomains $allowedClientDomains,
    ) {
    }

    public function register(McpClientId $clientId, McpClientName $name, array $redirectUris, AuditOrigin $origin): McpClient
    {
        // Avant la pré-lecture et toute écriture : une ligne pré-enregistrée
        // l'emporte sur CIMD (règle D-C de l'admission), l'enregistrer
        // masquerait le vrai client d'un domaine vérifié. Le refus n'écrit et
        // n'audite rien, et vaut pour le CLI, qui ne passe pas par le
        // validateur du backoffice.
        if ($this->allowedClientDomains->isVerifiedClientId((string) $clientId)) {
            throw McpClientIdOfVerifiedDomainException::cannotBePreregistered();
        }

        if (null !== $this->clients->findOneByClientId((string) $clientId)) {
            throw McpClientAlreadyRegisteredException::alreadyTaken();
        }

        $client = McpClient::register($clientId, $name, $redirectUris, $this->clock->now());

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
            if (McpClient::CLIENT_ID_UNIQUE_INDEX !== ViolatedUniqueIndex::of($violation)) {
                throw $violation;
            }

            throw McpClientAlreadyRegisteredException::takenMeanwhile();
        }

        // L'audit suit l'écriture, jamais avant : un refus (lecture ou
        // contrainte unique) a levé plus haut et n'a rien à auditer. La valeur
        // d'enregistrement vient de l'énuméré du domaine, pas d'une chaîne
        // recopiée. Une panne du journal ne défait pas l'inscription déjà
        // écrite, ni ne la fait croire échouée : FailSafeAudit la journalise.
        $this->failSafeAudit->attempt(
            fn () => $this->auditLogger->mcpClientRegistered((string) $clientId, ClientRegistration::Preregistered->value, $origin),
            self::REGISTRATION_NOT_AUDITED,
        );

        return $client;
    }
}
