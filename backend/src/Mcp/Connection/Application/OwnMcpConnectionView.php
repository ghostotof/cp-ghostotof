<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Une connexion MCP de la personne, ce qu'elle en voit (#650) : le client par
 * son identifiant public et son nom, les instants, l'échéance, l'état et, si
 * elle est coupée, quand et pourquoi.
 *
 * **Aucun jeton, aucune empreinte, aucun scope, aucun compte** : la liste ne
 * porte que ce que la personne doit reconnaître. Les instants sont rendus dans
 * le fuseau de l'horloge (Paris), celui de l'échéance.
 */
final readonly class OwnMcpConnectionView
{
    public function __construct(
        public Uuid $id,
        /** L'identifiant public OAuth du client. */
        public string $clientId,
        /** Le nom que l'administrateur du site a saisi pour le client. */
        public string $clientName,
        public DateTimeImmutable $grantedAt,
        /**
         * Le code de l'accord a-t-il été échangé ? `false` : un accord
         * abandonné, jamais abouti (#730) ; `true` : le client a reçu ses
         * jetons, même s'il n'a pas encore rafraîchi. Un booléen, pas
         * l'instant (`code_exchanged_at` est approché par la migration de
         * #730, seul « nul ou non » est lu) : la page distingue deux cas, pas
         * plus.
         */
        public bool $codeExchanged,
        /** Le dernier rafraîchissement ; `null` tant que le client n'a pas rafraîchi (l'échange du code ne l'écrit pas). */
        public ?DateTimeImmutable $lastUsedAt,
        /**
         * L'instant où la connexion expire ({@see \App\Mcp\Connection\Domain\Entity\McpConnection::deadline()}) ;
         * `null` pour une connexion **révoquée**, qui n'en a plus (#650, R5a).
         */
        public ?DateTimeImmutable $expiresAt,
        public OwnMcpConnectionState $state,
        public ?DateTimeImmutable $revokedAt,
        public ?RevocationCause $revocationCause,
    ) {
    }
}
