<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Support;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetchQuotaInterface;
use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use DateTimeImmutable;

/**
 * Doublure du quota de récupération (#651) : compte les unités consommées, par
 * voie (client inconnu, client connu), et refuse sur chaque voie à partir de la
 * `limit`-ième + 1 (`$limit` pour les clients inconnus, `$knownClientLimit` pour
 * les clients connus ; jamais, par défaut). Un refus ne consomme rien. Simplification
 * de test : le vrai quota ne refuse jamais sur le seul compteur d'un client connu
 * (il se replie sur le plafond commun), la limite d'un client connu simule ici
 * le refus final de la voie.
 */
final class CountingFetchQuota implements ClientMetadataFetchQuotaInterface
{
    private int $consumed = 0;

    private int $consumedForKnownClient = 0;

    private ?CimdClientId $lastKnownClientId = null;

    public function __construct(
        private readonly ?int $limit = null,
        private readonly ?int $knownClientLimit = null,
    ) {
    }

    public function consume(): void
    {
        if (null !== $this->limit && $this->consumed >= $this->limit) {
            throw new ClientMetadataFetchRateLimitExceededException(new DateTimeImmutable('2030-01-01 00:00:00 UTC'));
        }

        ++$this->consumed;
    }

    public function consumeForKnownClient(CimdClientId $clientId): void
    {
        // Mémorisé avant le refus : un test lit l'identifiant que la voie a reçu, refusée ou non.
        $this->lastKnownClientId = $clientId;

        if (null !== $this->knownClientLimit && $this->consumedForKnownClient >= $this->knownClientLimit) {
            throw new ClientMetadataFetchRateLimitExceededException(new DateTimeImmutable('2030-01-01 00:00:00 UTC'));
        }

        ++$this->consumedForKnownClient;
    }

    /** Les unités prises par la voie des clients inconnus. */
    public function consumed(): int
    {
        return $this->consumed;
    }

    /** Les unités prises par la voie des clients connus. */
    public function consumedForKnownClient(): int
    {
        return $this->consumedForKnownClient;
    }

    /** Le dernier `client_id` reçu par la voie des clients connus, `null` si elle n'a jamais été appelée. */
    public function lastKnownClientId(): ?CimdClientId
    {
        return $this->lastKnownClientId;
    }
}
