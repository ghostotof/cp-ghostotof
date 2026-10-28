<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Security\AccountReference;

/**
 * Révoque d'un coup toutes les connexions MCP d'un compte (#650, décision 3
 * du plan) : le port que `Security/User` appelle **dans sa propre
 * transaction** — celle du verrou du compte — pour que le mot de passe ou le
 * rôle et la révocation valident ensemble, ou pas du tout.
 *
 * Deux temps, parce que la ligne d'audit d'une écriture suit son COMMIT :
 *
 * 1. {@see self::revokeAllOf()} **dans** la fermeture du verrou : écrit les
 *    révocations, n'audite rien, rend ce qu'il a révoqué ;
 * 2. {@see self::auditRevoked()} **après** le retour du verrou, donc après le
 *    COMMIT : une ligne `mcp-connection-revoked` par connexion.
 *
 * Rejeté : un événement Symfony après le COMMIT (non atomique : un échec
 * laisserait un mot de passe changé et des connexions vivantes) ; un
 * événement dans la transaction (ordre implicite, audit avant le COMMIT).
 *
 * La raison est prise dans un ensemble fermé ({@see AccountAccessWithdrawal}),
 * jamais une cause générique : le révocateur de `Mcp/Connection` n'est pas
 * rouvert à n'importe quelle cause (#726, CR6).
 */
interface AccountMcpConnectionsRevokerInterface
{
    /**
     * À appeler **dans** la transaction de l'appelant (celle du verrou du
     * compte, qui tient déjà la ligne du compte) : refusé sinon, sous une
     * exception nommée, car l'instruction seule serait validée avant le reste
     * de l'opération de l'appelant. **Ordre des verrous** : la ligne du compte
     * d'abord, puis ses connexions, par identifiant — l'ordre de tout le
     * module, aucun chemin ne prend une connexion puis le compte.
     *
     * N'écrit aucune ligne d'audit. Une connexion déjà révoquée garde son
     * instant et sa cause, et n'est pas rendue.
     */
    public function revokeAllOf(AccountReference $account, AccountAccessWithdrawal $reason): RevokedAccountMcpConnections;

    /**
     * À appeler **après** le COMMIT de l'appelant : une ligne par connexion de
     * `$revoked`, l'acteur étant celui de la requête. Une panne de l'audit ne
     * change pas l'issue (elle est journalisée en `critical`, la classe
     * seule) et ne tait pas les lignes suivantes.
     */
    public function auditRevoked(RevokedAccountMcpConnections $revoked): void;
}
