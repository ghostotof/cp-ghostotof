<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Domain\Security\McpConnectionReference;

/**
 * La ligne `mcp-connection-revoked` des révocations **que la personne ou le
 * compte déclenchent** (#650) — `OwnMcpConnections` (cause `person`) et
 * `AccountMcpConnectionsRevoker` (`password-changed`, `trusted-tier-withdrawn`)
 * —, écrite ici une fois : l'appel au journal de sécurité, la panne qui ne
 * change jamais l'issue ({@see FailSafeAudit}, #725) et son message constant.
 * Les deux sites répétaient la même ligne, et un troisième aurait pu
 * l'oublier ou changer le message que la règle et les tests nomment.
 *
 * **Emplacement** : `Mcp/Connection/Application`, à côté de {@see FailSafeAudit}
 * et des deux appelants, la même couche. La dépendance sur
 * {@see SecurityAuditLoggerInterface} est la deuxième porte assumée de
 * `Mcp/Connection` vers `Security` (`.claude/rules/mcp-oauth.md`), déjà prise
 * par ces deux classes — aucune dépendance nouvelle, une de moins par appelant
 * (ils ne tiennent plus ni le journal ni le {@see FailSafeAudit}). Placée dans
 * `Shared`, elle aurait fait connaître le vocabulaire de `Mcp` à un dossier
 * transverse ; ajoutée à {@see FailSafeAudit}, elle aurait donné à ce service
 * générique une dépendance sur `Security` qu'il n'a pas. Ne sont pas
 * concernés : l'expiration (acteur `system`), le remplacement
 * (`supersededBy`) et la révocation d'une compromission, qui ont chacun leur
 * méthode d'audit et leur message.
 *
 * L'acteur est lu comme à l'ordinaire par le journal (le jeton de sécurité).
 * La panne sort en `critical` sur le canal principal, la classe seule, jamais
 * le message de la panne.
 */
final readonly class RevokedConnectionAudit
{
    /** Le message d'une panne de l'audit, constant : il nomme ce qui n'a pas été audité. */
    private const string NOT_AUDITED = 'Revoked connection not audited.';

    public function __construct(
        private SecurityAuditLoggerInterface $auditLogger,
        private FailSafeAudit $failSafeAudit,
    ) {
    }

    /**
     * À appeler **après** le COMMIT de la révocation, une fois par connexion
     * que l'appel a effectivement révoquée.
     *
     * @param string $cause la valeur de la {@see \App\Mcp\Connection\Domain\ValueObject\RevocationCause}
     */
    public function record(McpConnectionReference $connection, string $cause): void
    {
        $this->failSafeAudit->attempt(
            fn () => $this->auditLogger->mcpConnectionRevoked($connection, $cause),
            self::NOT_AUDITED,
        );
    }
}
