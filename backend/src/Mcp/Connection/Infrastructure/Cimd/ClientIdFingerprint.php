<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Domain\ValueObject\CimdClientId;

/**
 * L'empreinte d'un `client_id` CIMD (#651) : le SHA-256 de son URL, en
 * hexadécimal minuscule (64 caractères). Elle est la clé du magasin des
 * documents (CachedClientMetadataStore) et celle du compteur des clients déjà
 * déclarés (SymfonyClientMetadataFetchQuota) : une seule dérivation, pour que
 * les deux ne divergent jamais.
 *
 * Ce que l'empreinte apporte : une clé de longueur et d'alphabet fixes (l'URL
 * est choisie par un tiers, longue ou chargée de caractères que PSR-6 réserve),
 * et l'URL qui n'est pas écrite en clair dans `cache_items`. Ce qu'elle
 * n'apporte pas : un secret. Le `client_id` d'un client approuvé est une URL
 * publique, que quiconque en connaît la candidate recalcule en empreinte ; la
 * table ne cache donc rien à qui peut la lire. Aucune donnée personnelle n'y
 * est stockée.
 *
 * Une valeur **figée** : les documents déjà en cache sont sous cette clé, la
 * changer les rendrait tous introuvables d'un coup (ClientIdFingerprintTest en
 * garde la valeur écrite en dur). Une classe dédiée, sur le modèle de
 * {@see FetchQuotaKey}, plutôt que deux `hash()` recopiés.
 */
final readonly class ClientIdFingerprint
{
    public static function of(CimdClientId $clientId): string
    {
        return hash('sha256', $clientId->value());
    }
}
