<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #649 (relecture du lot B, D5) — la base n'admet dans
 * `mcp_connection.revocation_cause` que les valeurs de l'énumération
 * `RevocationCause` : `client`, `refresh-token-reuse`, `code-replay`,
 * `superseded`, `expired`.
 *
 * Doctrine hydrate la colonne en énumération (`enumType`) : une valeur hors
 * de la liste — une écriture SQL qui contournerait le dépôt — ferait lever
 * `ValueError` à chaque lecture de la connexion, un 500 là où
 * l'authentificateur répond 401. La contrainte le refuse à l'écriture. Une
 * ligne non révoquée (`NULL`) passe : un `CHECK` n'est violé que par un faux,
 * et `chk_mcp_connection_revocation_cause` (`Version20261026120000`) lie déjà
 * `NULL` à `revoked_at IS NULL`.
 *
 * Les valeurs sont écrites en clair, jamais lues sur l'énumération : une
 * migration est figée, et la cause `person` de #650 **élargit** cette
 * contrainte par une nouvelle migration (`DROP CONSTRAINT`, `ADD CONSTRAINT`
 * avec la valeur de plus) avant que le code ne l'écrive — expand/contract
 * (#175) ; `McpConnectionRepositoryTest` relit chaque cas de l'énumération
 * contre la base et tombe sur une valeur ajoutée à l'énumération seule.
 *
 * Aucune ligne existante ne la viole : seul le dépôt de #649 écrit la cause,
 * depuis l'énumération, et l'ancien code n'écrit jamais `revoked_at` —
 * `ADD CONSTRAINT` ne peut donc pas échouer sur une base peuplée, et la
 * migration passe avant le rollout sans fenêtre de maintenance. Le verrou de
 * l'`ALTER TABLE` est bref (la table est petite) et borné par le
 * `lock_timeout` de 5 s du Job de migration (#525).
 */
final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restreint la cause de révocation d\'une connexion MCP aux valeurs de RevocationCause (#649).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE mcp_connection ADD CONSTRAINT chk_mcp_connection_revocation_cause_value'
            ." CHECK (revocation_cause IN ('client', 'refresh-token-reuse', 'code-replay', 'superseded', 'expired'))",
        );
    }

    /**
     * Retire la contrainte, rien d'autre : les causes écrites restent lues
     * telles quelles, et la base admet de nouveau n'importe quelle valeur.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_connection DROP CONSTRAINT chk_mcp_connection_revocation_cause_value');
    }
}
