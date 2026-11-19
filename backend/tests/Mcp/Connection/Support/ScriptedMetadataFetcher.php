<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Support;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetcherInterface;
use App\Mcp\Connection\Application\Cimd\FetchedClientMetadata;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;

/**
 * Doublure du récupérateur (#651) : rend la réponse écrite par le test, ou
 * lève le refus qu'il a choisi, sans réseau. Elle compte ses appels : un test
 * prouve ainsi qu'une récupération n'a pas eu lieu.
 */
final class ScriptedMetadataFetcher implements ClientMetadataFetcherInterface
{
    private int $calls = 0;

    private function __construct(
        private readonly ?FetchedClientMetadata $response,
        private readonly ?ClientMetadataRefusal $refusal,
    ) {
    }

    public static function serving(string $body, ?int $maxAge = null): self
    {
        return new self(new FetchedClientMetadata($body, $maxAge), null);
    }

    public static function refusing(ClientMetadataRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function fetch(CimdClientId $clientId): FetchedClientMetadata
    {
        ++$this->calls;

        if (null !== $this->refusal) {
            throw ClientMetadataRefusedException::because($this->refusal);
        }

        \assert(null !== $this->response);

        return $this->response;
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
