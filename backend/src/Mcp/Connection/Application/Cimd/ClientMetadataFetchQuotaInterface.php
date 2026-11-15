<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;

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
 *    `cimd`), il passe par le quota de l'appelant et par un compteur propre à
 *    ce client, sans toucher au plafond commun tant que ce compteur a de la
 *    place ; compteur plein, il se replie sur le chemin de `consume()`.
 *
 * Un attaquant ne peut pas **rendre** un client connu : il lui faudrait un
 * document valide sur un domaine de la liste, **et** qu'une personne du palier
 * de confiance approuve ce client à la page de consentement — c'est cette
 * approbation qui écrit la ligne `cimd`. Il peut en revanche **nommer** un
 * client déjà approuvé (son `client_id` est public) et emprunter cette voie :
 * pendant une panne du document de ce client, rien n'est gardé en cache et
 * chaque essai repart vers son hôte. Le compteur par client borne alors le
 * total de ces récupérations, quel que soit le nombre d'appelants. Il **ne
 * refuse jamais seul** : épuisé par un attaquant (dix demandes simultanées à
 * l'expiration du cache, depuis une seule adresse), il renvoie les appelants
 * suivants au plafond commun, qui accepte tant qu'il a de la place. Fermer la
 * porte à un client connu demande donc de saturer son compteur et le plafond
 * commun ; un client connu n'est jamais plus refusé qu'un inconnu.
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
     * Consomme une unité pour un client **déjà déclaré** : le compteur de ce
     * client (regardé, puis consommé) et le quota de l'appelant courant. Le
     * plafond commun n'est pas touché tant que le compteur a de la place ;
     * plein, ou rempli entre le regard et la consommation, l'appel se replie sur
     * le plafond commun, l'appelant n'étant consommé qu'une fois.
     *
     * @throws ClientMetadataFetchRateLimitExceededException quand le quota de l'appelant est épuisé, ou quand le plafond commun l'est quand le compteur du client est plein
     */
    public function consumeForKnownClient(CimdClientId $clientId): void;
}
