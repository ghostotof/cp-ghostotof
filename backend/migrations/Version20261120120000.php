<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #652 (tâche 13, S4) — trois index partiels pour les trois lectures bornées de
 * la liste d'administration des connexions MCP, qui trient sur un instant de
 * fin et gardent les 200 premières :
 *
 *  - `idx_mcp_connection_admin_terminated` : les terminées autrement que par
 *    expiration, `ORDER BY revoked_at DESC, id DESC` ;
 *  - `idx_mcp_connection_admin_abandoned` : les codes jamais échangés,
 *    `ORDER BY granted_at DESC, id DESC` ;
 *  - `idx_mcp_connection_admin_exchanged_expired` : les expirées après échange,
 *    lues par pages sur la clé `(revoked_at, id)`.
 *
 * Chaque index est dans l'ordre croissant : PostgreSQL le parcourt à l'envers
 * pour un `ORDER BY … DESC` (mesuré, `Index Scan Backward`, sans `Sort`).
 *
 * Pourquoi des prédicats partiels : chaque lecture ne voit qu'une fraction de la
 * table (les connexions actives, les plus nombreuses, n'y sont pas), et un index
 * partiel ne grossit qu'avec cette fraction. Le prédicat de `terminated` reprend
 * mot pour mot le `WHERE` de la requête — la cause `IS NULL` y est tenue même si
 * le `CHECK` `chk_mcp_connection_revocation_cause` la lie à `revoked_at` : un
 * index n'utilise pas les contraintes pour prouver l'implication, seulement le
 * texte du prédicat. Les requêtes écrivent `expired` en littéral, pour la même
 * raison (voir McpConnectionRepository::expiredLiteral()).
 *
 * Pourquoi pas `CREATE INDEX CONCURRENTLY` : la table est celle des connexions
 * d'un portfolio (quelques dizaines de lignes, jamais des millions), la
 * construction dure quelques millisecondes et ne bloque que les écritures de
 * `mcp_connection` pendant ce temps (un `SHARE` ne bloque pas les lectures),
 * sous le `lock_timeout` de 5 s du Job de migration (#525). `CONCURRENTLY`
 * imposerait `isTransactional(): false` : les trois index ne seraient plus
 * atomiques (un échec laisserait un index `INVALID` à nettoyer à la main) et
 * `SET LOCAL lock_timeout` n'aurait plus de transaction où s'appliquer. La
 * migration reste transactionnelle, tout ou rien.
 *
 * Migration additive (expand, #175) : la version précédente de l'application
 * ignore ces index, et aucune lecture n'en dépend pour son résultat.
 */
final class Version20261120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les trois index partiels de la liste d\'administration des connexions MCP (#652).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_mcp_connection_admin_terminated ON mcp_connection (revoked_at, id) WHERE ((revoked_at IS NOT NULL) AND ((revocation_cause IS NULL) OR ((revocation_cause)::text <> \'expired\'::text)))');
        $this->addSql('CREATE INDEX idx_mcp_connection_admin_abandoned ON mcp_connection (granted_at, id) WHERE (code_exchanged_at IS NULL)');
        $this->addSql('CREATE INDEX idx_mcp_connection_admin_exchanged_expired ON mcp_connection (revoked_at, id) WHERE (((revocation_cause)::text = \'expired\'::text) AND (code_exchanged_at IS NOT NULL))');
    }

    /** Retire les trois index : les lectures reviennent à un tri de la table, sans perte de donnée. */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_mcp_connection_admin_exchanged_expired');
        $this->addSql('DROP INDEX idx_mcp_connection_admin_abandoned');
        $this->addSql('DROP INDEX idx_mcp_connection_admin_terminated');
    }
}
