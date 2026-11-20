<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #652 (tâche 13, S4d) — la lecture par pages des expirées échangées se fait
 * désormais par **activité**, `COALESCE(last_used_at, granted_at) DESC, id DESC`,
 * et non plus par `revoked_at` : une purge marque tout un lot au même
 * `revoked_at`, ce qui interdisait l'arrêt anticipé et obligeait à lire toutes les
 * pages. L'activité borne la fin réelle par le haut (échéance d'inactivité de
 * l'activité), elle permet l'arrêt.
 *
 * Remplace `idx_mcp_connection_admin_exchanged_expired` (clé `(revoked_at, id)`,
 * migration `Version20261120120000`) par un index **d'expression partiel**
 * `((COALESCE(last_used_at, granted_at)) DESC, id DESC)`. Le prédicat reprend mot
 * pour mot le `WHERE` de la requête (cause `expired` en littéral, voir
 * McpConnectionRepository::expiredLiteral()) ; l'expression est écrite à
 * l'identique dans l'`ORDER BY` et dans la comparaison de lignes de reprise
 * `(COALESCE(…), id) < (:activité, :id)`, que PostgreSQL sert comme *Index Cond*.
 *
 * L'ORM ne sait pas déclarer un index d'expression : il n'est plus dans les
 * attributs de `McpConnection`, seulement ici (voir le commentaire de l'entité).
 *
 * Mêmes raisons que `Version20261120120000` pour ne pas utiliser
 * `CREATE INDEX CONCURRENTLY` (table de quelques dizaines de lignes, migration
 * transactionnelle, tout ou rien). L'ancien index est retiré dans la même
 * transaction : la version précédente de l'application lit encore par
 * `revoked_at`, sans index pendant le déploiement — sur cette taille de table,
 * un balayage séquentiel, sans conséquence sur le résultat.
 */
final class Version20261120130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace l\'index des expirées échangées par un index d\'expression partiel sur l\'activité (#652).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_mcp_connection_admin_exchanged_expired_activity ON mcp_connection ((COALESCE(last_used_at, granted_at)) DESC, id DESC) WHERE (((revocation_cause)::text = \'expired\'::text) AND (code_exchanged_at IS NOT NULL))');
        $this->addSql('DROP INDEX idx_mcp_connection_admin_exchanged_expired');
    }

    /** Rétablit l'index par `revoked_at` de la migration précédente, sans perte de donnée. */
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_mcp_connection_admin_exchanged_expired ON mcp_connection (revoked_at, id) WHERE (((revocation_cause)::text = \'expired\'::text) AND (code_exchanged_at IS NOT NULL))');
        $this->addSql('DROP INDEX idx_mcp_connection_admin_exchanged_expired_activity');
    }
}
