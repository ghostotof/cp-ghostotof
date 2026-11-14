<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #651 (tâche 7) — `mcp_client.registration`, le mode d'enregistrement d'un
 * client MCP : `preregistered` (une personne l'a saisi : commande
 * `app:mcp:client:register`, futur backoffice) ou `cimd` (son document de
 * métadonnées l'a déclaré, #651).
 *
 * Pourquoi `DEFAULT 'preregistered'` : toute ligne existante, et toute ligne
 * qu'écrirait encore la version précédente de l'application (son `INSERT` ne
 * nomme pas la colonne), a été saisie par une personne. Le défaut est donc la
 * seule valeur juste pour elles, et la colonne est `NOT NULL` sans réécriture
 * de la table (PostgreSQL pose un défaut constant sans la réécrire).
 *
 * Pourquoi la contrainte `chk_mcp_client_registration` : la base ferme les
 * valeurs, comme elle ferme `revocation_cause`. Une valeur de plus se fera par
 * une nouvelle migration qui l'élargit, jamais par une chaîne libre. Le mode
 * décide si un document distant peut réécrire le nom et les redirections d'un
 * client : une valeur inconnue ne doit pas pouvoir y répondre « oui » par défaut.
 *
 * Migration additive (expand, #175) : aucun code de `Mcp/Connection` n'est
 * encore en production, et la version précédente ne lit ni n'écrit la colonne.
 * Le verrou de l'`ALTER TABLE` est bref (la table est petite) et borné par le
 * `lock_timeout` de 5 s du Job de migration (#525).
 */
final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute mcp_client.registration, le mode d\'enregistrement d\'un client MCP (#651).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_client ADD registration VARCHAR(16) DEFAULT \'preregistered\' NOT NULL');
        $this->addSql('ALTER TABLE mcp_client ADD CONSTRAINT chk_mcp_client_registration CHECK (registration IN (\'preregistered\', \'cimd\'))');
    }

    /** Retire la contrainte puis la colonne : le mode des clients déclarés par document est perdu. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_client DROP CONSTRAINT chk_mcp_client_registration');
        $this->addSql('ALTER TABLE mcp_client DROP registration');
    }
}
