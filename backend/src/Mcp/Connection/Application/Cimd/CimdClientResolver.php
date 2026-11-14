<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\Exception\InvalidClientMetadataDocumentException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;

/**
 * La résolution d'un `client_id` CIMD (#651, D-G) : le magasin d'abord, puis,
 * sur un manque seulement, le quota, la récupération, la validation et la
 * garde.
 *
 * Trois propriétés tenues ici, parce qu'aucun des trois collaborateurs ne peut
 * les tenir seul :
 *  - **un cache qui répond ne coûte ni quota ni sortie réseau** ; un corps
 *    gardé est revalidé à chaque lecture, et s'il ne passe plus (règles
 *    durcies depuis, entrée altérée) il est traité comme un manque : aucun
 *    objet n'est jamais relu du cache, seulement du texte que la validation
 *    actuelle rejuge ;
 *  - **seul un document valide est gardé** : ni refus de récupération, ni
 *    document invalide, ne passent par `save()` (draft §6.2) ;
 *  - **le quota est consommé avant la récupération**, jamais après : un refus
 *    de l'hôte compte, c'est la sortie réseau qui est bornée, pas le succès.
 *
 * Elle ne tient aucune transaction : le quota écrit en base, et le validateur
 * de la demande d'autorisation qui l'appelle tourne avant celle de
 * l'approbation.
 */
final readonly class CimdClientResolver implements CimdClientResolverInterface
{
    /**
     * Plancher de la durée de garde, en secondes : un hôte qui annonce `max-age=0`
     * (ou rien) n'obtient pas une récupération par requête.
     */
    public const int MIN_TTL = 300;

    /**
     * Plafond, en secondes : un hôte ne fige pas son document plus longtemps
     * qu'une heure, quoi qu'il annonce.
     */
    public const int MAX_TTL = 3600;

    public function __construct(
        private ClientMetadataStoreInterface $store,
        private ClientMetadataFetchQuotaInterface $quota,
        private ClientMetadataFetcherInterface $fetcher,
    ) {
    }

    public function resolve(CimdClientId $clientId): ClientMetadataDocument
    {
        $cached = $this->fromStore($clientId);
        if (null !== $cached) {
            return $cached;
        }

        try {
            $this->quota->consume();
        } catch (ClientMetadataFetchRateLimitExceededException $exceeded) {
            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::Throttled, $exceeded);
        }

        $fetched = $this->fetcher->fetch($clientId);

        try {
            $document = ClientMetadataDocument::fromJson($clientId, $fetched->body);
        } catch (InvalidClientMetadataDocumentException $invalid) {
            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::InvalidDocument, $invalid);
        }

        $this->store->save($clientId, $fetched->body, $this->lifetime($fetched->maxAge));

        return $document;
    }

    /**
     * Le document du magasin, `null` s'il n'y en a pas ou s'il ne valide plus.
     */
    private function fromStore(CimdClientId $clientId): ?ClientMetadataDocument
    {
        $body = $this->store->find($clientId);
        if (null === $body) {
            return null;
        }

        try {
            return ClientMetadataDocument::fromJson($clientId, $body);
        } catch (InvalidClientMetadataDocumentException) {
            return null;
        }
    }

    /**
     * La durée de garde : le `max-age` annoncé, borné à [MIN_TTL, MAX_TTL] ;
     * le plancher quand l'hôte n'annonce rien.
     */
    private function lifetime(?int $maxAge): int
    {
        return max(self::MIN_TTL, min(self::MAX_TTL, $maxAge ?? self::MIN_TTL));
    }
}
