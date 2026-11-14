<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Support;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetchQuotaInterface;
use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use DateTimeImmutable;

/**
 * Doublure du quota de récupération (#651) : compte les unités consommées et
 * refuse à partir de la `$limit`-ième + 1 (jamais, par défaut).
 */
final class CountingFetchQuota implements ClientMetadataFetchQuotaInterface
{
    private int $consumed = 0;

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

    public function consumed(): int
    {
        return $this->consumed;
    }
}
