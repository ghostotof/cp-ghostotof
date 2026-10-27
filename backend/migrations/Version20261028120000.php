<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #650 (tâche 1) — la base admet dans `mcp_connection.revocation_cause` les trois
 * causes de plus que #650 écrit : `person` (la personne révoque une connexion
 * depuis « Mes connexions MCP »), `password-changed` (le mot de passe du compte
 * a changé) et `trusted-tier-withdrawn` (le compte a perdu le palier de
 * confiance), en plus des cinq de #649 : `client`, `refresh-token-reuse`,
 * `code-replay`, `superseded`, `expired`.
 *
 * Pourquoi avant le code (expand/contract, #175) : la contrainte
 * `chk_mcp_connection_revocation_cause_value` (`Version20261027120000`) refuse
 * toute valeur hors de la liste. Une cause ajoutée à l'énumération `RevocationCause`
 * seule, ou une migration livrée avec le code qui l'écrit, ferait refuser en
 * 23514 la première révocation `person` de la production. Cette migration est
 * donc la première de la tâche et passe avant tout code qui écrit une des trois
 * valeurs ; `McpConnectionRepositoryTest` relit chaque cas de l'énumération contre
 * la base migrée et tombe sur une valeur ajoutée à l'énumération sans elle.
 *
 * Les huit valeurs sont écrites en clair, jamais lues sur l'énumération : une
 * migration est figée (celle de #649 l'annonçait). Une valeur de plus se fera par
 * une nouvelle migration qui élargit de nouveau cette contrainte.
 *
 * Aucune ligne existante ne peut violer l'élargissement : toute valeur admise
 * hier l'est encore. Le verrou de l'`ALTER TABLE` est bref (la table est petite,
 * `DROP` et `ADD` dans la transaction propre de la migration) et borné par le
 * `lock_timeout` de 5 s du Job de migration (#525). Elle passe avant le rollout
 * (#175), sans fenêtre de maintenance : le code de la version précédente n'écrit
 * que les cinq causes d'avant, que la nouvelle contrainte admet toujours.
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admet les causes de révocation person, password-changed et trusted-tier-withdrawn d\'une connexion MCP (#650).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_connection DROP CONSTRAINT chk_mcp_connection_revocation_cause_value');
        $this->addSql(
            'ALTER TABLE mcp_connection ADD CONSTRAINT chk_mcp_connection_revocation_cause_value'
            .' CHECK (revocation_cause IN (\'client\', \'refresh-token-reuse\', \'code-replay\', \'superseded\', \'expired\','
            .' \'person\', \'password-changed\', \'trusted-tier-withdrawn\'))',
        );
    }

    /**
     * Rend la contrainte à cinq valeurs de `Version20261027120000`. Tant qu'une
     * ligne porte une des trois causes ajoutées, l'`ADD CONSTRAINT` échoue
     * (23514) et la migration est annulée : le retour arrière exige de décider
     * quoi faire de ces lignes, jamais de les réécrire en silence — ce qu'une
     * personne a révoqué ne devient pas une révocation du client.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_connection DROP CONSTRAINT chk_mcp_connection_revocation_cause_value');
        $this->addSql(
            'ALTER TABLE mcp_connection ADD CONSTRAINT chk_mcp_connection_revocation_cause_value'
            .' CHECK (revocation_cause IN (\'client\', \'refresh-token-reuse\', \'code-replay\', \'superseded\', \'expired\'))',
        );
    }
}
