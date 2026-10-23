<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #647 — le modèle de la connexion MCP (spec 0007, phase 2) : les clients
 * enregistrés, les connexions qu'une personne du palier de confiance leur
 * accorde, et les codes et jetons qui en sont le support technique.
 *
 * Aucun code ni jeton n'est stocké en clair : seule l'empreinte SHA-256 de
 * son identifiant, `CHAR(64)` sous une contrainte d'unicité nommée (une
 * violation cite son nom, qu'un appelant reconnaît). Tout ce qu'un compte a
 * ouvert disparaît avec lui, par la base (`mcp_connection.account_id` en
 * cascade vers `cpg_user`, chaque code et jeton en cascade vers sa
 * connexion) : la purge des invitations ou une suppression en SQL ne laissent
 * aucun jeton orphelin, encore moins utilisable. Le lien d'un jeton de
 * rafraîchissement vers son jeton d'accès est `ON DELETE SET NULL` : la purge
 * d'un jeton d'accès expiré (1 h) n'emporte pas le rafraîchissement (30 j).
 *
 * Clés UUID natives posées par l'application (spec 0003), horodatages
 * `TIMESTAMP(0) WITH TIME ZONE` (#434). Expand/contract (#175) : cinq tables
 * neuves que l'ancien code ignore, la migration passe avant le rollout sans
 * fenêtre de maintenance.
 */
final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée les tables de la connexion MCP : clients, connexions, codes et jetons (#647).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mcp_client (id UUID NOT NULL, client_id VARCHAR(512) NOT NULL, name VARCHAR(255) NOT NULL, redirect_uris JSON NOT NULL, registered_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mcp_client_client_id ON mcp_client (client_id)');

        $this->addSql('CREATE TABLE mcp_connection (id UUID NOT NULL, scope VARCHAR(255) NOT NULL, granted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, account_id UUID NOT NULL, client_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5CD483239B6B5FBA ON mcp_connection (account_id)');
        $this->addSql('CREATE INDEX IDX_5CD4832319EB6921 ON mcp_connection (client_id)');
        $this->addSql('ALTER TABLE mcp_connection ADD CONSTRAINT FK_5CD483239B6B5FBA FOREIGN KEY (account_id) REFERENCES cpg_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mcp_connection ADD CONSTRAINT FK_5CD4832319EB6921 FOREIGN KEY (client_id) REFERENCES mcp_client (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE mcp_authorization_code (id UUID NOT NULL, code_hash CHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, connection_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mcp_authorization_code_hash ON mcp_authorization_code (code_hash)');
        $this->addSql('CREATE INDEX IDX_9A3A06CFDD03F01 ON mcp_authorization_code (connection_id)');
        $this->addSql('ALTER TABLE mcp_authorization_code ADD CONSTRAINT FK_9A3A06CFDD03F01 FOREIGN KEY (connection_id) REFERENCES mcp_connection (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE mcp_access_token (id UUID NOT NULL, token_hash CHAR(64) NOT NULL, audience VARCHAR(512) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, connection_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mcp_access_token_hash ON mcp_access_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_8611E117DD03F01 ON mcp_access_token (connection_id)');
        $this->addSql('ALTER TABLE mcp_access_token ADD CONSTRAINT FK_8611E117DD03F01 FOREIGN KEY (connection_id) REFERENCES mcp_connection (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE mcp_refresh_token (id UUID NOT NULL, token_hash CHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, connection_id UUID NOT NULL, access_token_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mcp_refresh_token_hash ON mcp_refresh_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_7C5FE04DD03F01 ON mcp_refresh_token (connection_id)');
        $this->addSql('CREATE INDEX IDX_7C5FE042CCB2688 ON mcp_refresh_token (access_token_id)');
        $this->addSql('ALTER TABLE mcp_refresh_token ADD CONSTRAINT FK_7C5FE04DD03F01 FOREIGN KEY (connection_id) REFERENCES mcp_connection (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mcp_refresh_token ADD CONSTRAINT FK_7C5FE042CCB2688 FOREIGN KEY (access_token_id) REFERENCES mcp_access_token (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    /**
     * Des feuilles vers la racine : chaque table emporte ses propres clés
     * étrangères, aucune ne reste référencée quand elle part.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mcp_refresh_token');
        $this->addSql('DROP TABLE mcp_access_token');
        $this->addSql('DROP TABLE mcp_authorization_code');
        $this->addSql('DROP TABLE mcp_connection');
        $this->addSql('DROP TABLE mcp_client');
    }
}
