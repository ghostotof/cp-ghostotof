<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\Exception\InvalidClientMetadataDocumentException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;

/**
 * La résolution d'un `client_id` CIMD (#651, D-G) : la liste des domaines
 * admis d'abord, puis le magasin et, sur un manque seulement, le quota, la
 * récupération, la validation et la garde.
 *
 * Cinq propriétés tenues ici, parce qu'aucun des collaborateurs ne peut les
 * tenir seul :
 *  - **la liste des domaines est jugée avant tout le reste**
 *    ({@see AllowedClientDomains}), même si le récupérateur la juge aussi
 *    (défense en profondeur : il ne compte pas sur son appelant). Deux
 *    raisons de la juger ici. Le document gardé vit en base (`cache_items`) et
 *    survit au retrait d'un domaine de `app.mcp.cimd.allowed_domains` : sans ce
 *    contrôle, un domaine retiré resterait admis jusqu'à {@see self::MAX_TTL}.
 *    Et un refus local ne sort pas sur le réseau : il ne coûte aucune unité de
 *    quota, qui ne doit pas s'user sur des `client_id` que le serveur refuse
 *    de toute façon. L'admission (Task 9) le juge aussi, avant d'appeler le
 *    résolveur : le résolveur ne compte pas sur elle non plus ;
 *  - **un cache qui répond ne coûte ni quota ni sortie réseau** ; un corps
 *    gardé est revalidé à chaque lecture, et s'il ne passe plus (règles
 *    durcies depuis, entrée altérée) il est traité comme un manque : aucun
 *    objet n'est jamais relu du cache, seulement du texte que la validation
 *    actuelle rejuge ;
 *  - **seul un document valide est gardé** : ni refus de récupération, ni
 *    document invalide, ne passent par `save()` (draft §6.2) ;
 *  - **le quota est consommé avant la récupération**, jamais après : un refus
 *    de l'hôte (réponse invalide, redirection, injoignable) compte, c'est la
 *    sortie réseau qui est bornée, pas le succès. Un refus local de domaine ne
 *    compte pas : aucune sortie n'a eu lieu ;
 *  - **un client déjà déclaré passe par la voie des clients connus** (D-F) :
 *    sur un cache manqué seulement, après la liste des domaines et le magasin,
 *    le résolveur demande au dépôt si le `client_id` est déjà déclaré (mode
 *    `cimd`) et choisit {@see ClientMetadataFetchQuotaInterface::consumeForKnownClient()}
 *    (le compteur de ce client puis le quota de l'appelant) plutôt que
 *    `consume()` (le plafond commun puis le quota de l'appelant). Pourquoi :
 *    dix sources saturent le plafond commun, et sans cette voie elles
 *    fermeraient aussi les clients que des personnes de confiance ont déjà
 *    approuvés. Un attaquant ne peut pas *rendre* un client connu : il lui
 *    faudrait un document valide sur un domaine de la liste et l'approbation
 *    d'une personne du palier de confiance, qui écrit la ligne. Il peut en
 *    revanche *nommer* un client approuvé : le compteur par client l'y borne,
 *    quels que soient ses appelants. Aucune lecture de la base pour un document
 *    en cache ni pour un domaine hors liste.
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
        private AllowedClientDomains $domains,
        private McpClientRepositoryInterface $registrations,
    ) {
    }

    public function resolve(CimdClientId $clientId): ClientMetadataDocument
    {
        // Avant le magasin (un document gardé d'un domaine retiré) et avant le quota.
        if (!$this->domains->allows($clientId)) {
            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::DomainNotAllowed);
        }

        $cached = $this->fromStore($clientId);
        if (null !== $cached) {
            return $cached;
        }

        try {
            // Un client déjà déclaré ne passe pas par le plafond commun, mais par le compteur de ce client (D-F) :
            // la lecture n'a lieu que sur ce cache manqué.
            if ($this->registrations->isDiscovered($clientId->toMcpClientId())) {
                $this->quota->consumeForKnownClient($clientId);
            } else {
                $this->quota->consume();
            }
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
