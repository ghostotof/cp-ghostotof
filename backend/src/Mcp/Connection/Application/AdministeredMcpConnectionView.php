<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Une connexion MCP telle que l'administration la lit (#652) : celle de
 * {@see OwnMcpConnectionView}, plus le compte qui l'a ouverte et le mode
 * d'enregistrement de son client. Les dates sont dans le fuseau de l'horloge.
 *
 * `expiresAt` est **toujours** rendue, y compris pour une connexion révoquée :
 * l'administration lit l'échéance que la connexion aurait eue, la page décide
 * de la montrer ou non. Pour un accord abandonné (code jamais échangé, expiré
 * d'une heure mais pas encore marqué par la purge), `revokedAt` et
 * `revocationCause` sont `null` et l'état est `Expired`.
 */
final readonly class AdministeredMcpConnectionView
{
    public function __construct(
        public Uuid $id,
        public string $accountUsername,
        public string $clientId,
        public string $clientName,
        public ClientRegistration $clientRegistration,
        public DateTimeImmutable $grantedAt,
        public bool $codeExchanged,
        public ?DateTimeImmutable $lastUsedAt,
        public DateTimeImmutable $expiresAt,
        public OwnMcpConnectionState $state,
        public ?DateTimeImmutable $revokedAt,
        public ?string $revocationCause,
    ) {
    }
}
