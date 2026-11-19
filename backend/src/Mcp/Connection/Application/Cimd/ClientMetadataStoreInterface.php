<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Domain\ValueObject\CimdClientId;

/**
 * Garde, un temps borné, le corps du document de métadonnées d'un client CIMD
 * (#651). Ne garde que du texte : jamais un objet, jamais une erreur, jamais
 * un document qui n'a pas passé la validation (draft §6.2). C'est l'appelant
 * qui revalide à chaque lecture, le magasin ne juge pas ce qu'il rend.
 */
interface ClientMetadataStoreInterface
{
    /**
     * Le corps gardé pour ce client, `null` s'il n'y en a pas ou s'il a expiré.
     */
    public function find(CimdClientId $clientId): ?string;

    /**
     * @param int $ttl durée de garde en secondes
     */
    public function save(CimdClientId $clientId, string $body, int $ttl): void;
}
