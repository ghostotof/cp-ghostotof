<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Support;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetchQuotaInterface;
use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use DateTimeImmutable;

/**
 * Doublure du quota de récupération (#651) : compte les unités consommées, par
 * voie (client inconnu, client connu). La voie des clients inconnus refuse à
 * partir de la `limit`-ième + 1 (`$limit`, jamais par défaut). La voie des
 * clients connus se comporte comme en production : tant que son compteur
 * (`$knownClientLimit`, jamais plein par défaut) a de la place, elle le consomme
 * et ne touche pas au plafond ; une fois plein, elle se replie sur la voie des
 * clients inconnus (`consume()`), qui seule peut alors refuser, sans que le
 * compteur du client compte une unité de plus. Un refus ne consomme rien.
 * Simplification de test : le quota de l'appelant n'est pas modélisé ici.
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
        // Mémorisé avant tout refus : un test lit l'identifiant que la voie a reçu, refusée ou non.
        $this->lastKnownClientId = $clientId;

        if (null !== $this->knownClientLimit && $this->consumedForKnownClient >= $this->knownClientLimit) {
            // Compteur plein : repli sur le plafond commun, qui décide seul du refus.
            $this->consume();

            return;
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
