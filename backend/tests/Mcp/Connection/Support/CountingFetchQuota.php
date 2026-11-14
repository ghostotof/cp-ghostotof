<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Support;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetchQuotaInterface;
use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use DateTimeImmutable;

/**
 * Doublure du quota de récupération (#651) : compte les unités consommées, par
 * voie (client inconnu, client connu), et refuse à partir de la `$limit`-ième + 1
 * de la voie des clients inconnus (jamais, par défaut) ; la voie des clients
 * connus ne refuse jamais ici.
 */
final class CountingFetchQuota implements ClientMetadataFetchQuotaInterface
{
    private int $consumed = 0;

    private int $consumedForKnownClient = 0;

    public function __construct(private readonly ?int $limit = null)
    {
    }

    public function consume(): void
    {
        if (null !== $this->limit && $this->consumed >= $this->limit) {
            throw new ClientMetadataFetchRateLimitExceededException(new DateTimeImmutable('2030-01-01 00:00:00 UTC'));
        }

        ++$this->consumed;
    }

    public function consumeForKnownClient(): void
    {
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
}
