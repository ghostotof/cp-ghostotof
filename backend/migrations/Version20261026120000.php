<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #649 — une connexion MCP se révoque une fois, avec sa cause (spec 0007,
 * phase 2, durée de vie des jetons).
 *
 * `revocation_cause` (`client`, `refresh-token-reuse`, `code-replay`,
 * `superseded`, `expired` ; `person` viendra avec #650) est écrite avec
 * `revoked_at`, par la seule révocation du dépôt. La contrainte
 * `chk_mcp_connection_revocation_cause` refuse l'une sans l'autre, y compris
 * pour une écriture SQL qui contournerait le dépôt. Aucune ligne existante ne
 * la viole : avant #649, `revoked_at` n'avait aucun mutateur, aucune
 * connexion n'est révoquée — `ADD CONSTRAINT` ne peut donc pas échouer sur
 * une base peuplée.
 *
 * L'index partiel `idx_mcp_connection_active_pair` sert la recherche de
 * l'accord actif d'un couple compte/client, qu'un nouvel accord remplace
 * (cause `superseded`) ; déclaré aussi sur l'entité, pour que
 * `doctrine:migrations:diff` ne propose pas de le supprimer.
 *
 * Expand/contract (#175) : une colonne nullable, une contrainte que l'ancien
 * code ne peut violer (il n'écrit jamais `revoked_at`), un index ; la
 * migration passe avant le rollout, sans fenêtre de maintenance.
 */
final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la cause de révocation d\'une connexion MCP, sa contrainte et l\'index des accords actifs (#649).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_connection ADD revocation_cause VARCHAR(32) DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE mcp_connection ADD CONSTRAINT chk_mcp_connection_revocation_cause'
            .' CHECK ((revoked_at IS NULL) = (revocation_cause IS NULL))',
        );
        $this->addSql('CREATE INDEX idx_mcp_connection_active_pair ON mcp_connection (account_id, mcp_client_id) WHERE (revoked_at IS NULL)');
    }

    /**
     * Retire l'index, la contrainte puis la colonne : les causes écrites sont
     * perdues, les instants de révocation restent (l'ancien code les lit).
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_mcp_connection_active_pair');
        $this->addSql('ALTER TABLE mcp_connection DROP CONSTRAINT chk_mcp_connection_revocation_cause');
        $this->addSql('ALTER TABLE mcp_connection DROP revocation_cause');
    }
}
