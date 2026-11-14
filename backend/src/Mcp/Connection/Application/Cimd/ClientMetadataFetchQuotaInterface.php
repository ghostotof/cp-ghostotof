<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;

/**
 * Le plafond des récupérations de documents CIMD (#651, D-F) : une unité par
 * récupération réelle (un cache qui répond n'en coûte aucune), jamais par
 * `client_id` (un attaquant en changerait à chaque essai).
 *
 * Deux bornes se cumulent pour un client **inconnu** : le quota de l'appelant
 * (son adresse IP, une IPv6 par son /64) et un plafond commun à tous les
 * appelants. Le plafond commun est atteignable : dix sources suffisent à le
 * remplir en dix minutes, et un refus reste possible pour un appelant à
 * l'adresse neuve, qui n'a rien consommé de son propre quota.
 *
 * Deux voies, pour que cette saturation ne ferme pas la porte aux clients que
 * des personnes de confiance ont déjà approuvés :
 *  - {@see self::consume()} : le client n'est pas déclaré dans `mcp_client`,
 *    il passe par le plafond commun et par le quota de l'appelant ;
 *  - {@see self::consumeForKnownClient()} : le client est déjà déclaré (mode
 *    `cimd`), il ne passe que par le quota de l'appelant.
 *
 * Un attaquant ne peut pas se faire passer pour un client connu : il lui faut
 * un document valide sur un domaine de la liste, **et** qu'une personne du
 * palier de confiance approuve ce client à la page de consentement — c'est
 * cette approbation qui écrit la ligne `cimd`. Sans elle, ses essais restent
 * ceux d'un client inconnu.
 */
interface ClientMetadataFetchQuotaInterface
{
    /**
     * Consomme une unité pour un client **inconnu** : le plafond commun
     * (regardé, puis consommé) et le quota de l'appelant courant.
     *
     * @throws ClientMetadataFetchRateLimitExceededException quand l'un des deux est épuisé
     */
    public function consume(): void;

    /**
     * Consomme une unité pour un client **déjà déclaré** : le quota de
     * l'appelant courant seul, jamais le plafond commun.
     *
     * @throws ClientMetadataFetchRateLimitExceededException quand le quota de l'appelant est épuisé
     */
    public function consumeForKnownClient(): void;
}
