<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #730 (piste 1, tâche 7 de #650) — `mcp_connection.code_exchanged_at`,
 * l'instant du premier échange du code d'un octroi, nul tant que ce code n'a
 * jamais été échangé. Une approbation dont le code n'est jamais échangé écrit une
 * connexion que rien ne remplace (le remplacement a lieu au premier échange,
 * #726) ni n'use ; elle ne connaissait que l'inactivité, trente jours. Avec
 * cette colonne la connexion jamais échangée expire une heure après son octroi
 * (`app.mcp.lifetime.connection_unexchanged`).
 *
 * **Le rattrapage** : sans lui, toute connexion existante serait lue comme
 * jamais échangée et expirée une heure après son octroi. La migration ne sait
 * pas *quand* un code a été échangé, seulement *si* : une connexion qui porte un
 * dernier usage (écrit au rafraîchissement), un jeton d'accès ou un jeton de
 * rafraîchissement (émis à l'échange du code, ou à un rafraîchissement qui
 * suppose un échange) l'a été. Elle reçoit son octroi — un instant approché,
 * que seul « nul ou non » lit, jamais pour une durée. Une connexion sans rien
 * de cela reste nulle. Un jeton purgé à son échéance a pu disparaître d'une
 * connexion échangée, jamais rafraîchie, dont les jetons expirent avant
 * l'inactivité (une durée de jeton de rafraîchissement réglée sous celle de
 * l'inactivité) : avec les valeurs livrées (30 jours de part et d'autre) le
 * jeton survit à l'échéance d'inactivité de la connexion. La phase 2 n'étant
 * jamais sortie, aucune base de production n'a de ligne : le rattrapage sert
 * les bases de développement et de préproduction.
 *
 * Colonne nullable, ajoutée sans valeur par défaut, `TIMESTAMP(0) WITH TIME
 * ZONE` comme tous les instants de la table (#434) : un `ALTER TABLE … ADD
 * COLUMN` sans valeur par défaut ne réécrit pas la table, verrou bref borné par
 * le `lock_timeout` de 5 s du Job de migration (#525). Elle passe avant le
 * rollout (#175) : le code de la version précédente ignore la colonne (ses
 * `INSERT` la laissent nulle, ses lectures ne la sélectionnent pas) — une
 * connexion octroyée par l'ancien code pendant la fenêtre serait lue nulle
 * donc expirée au bout d'une heure, ce que la phase 2 jamais sortie exclut.
 */
final class Version20261028140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute mcp_connection.code_exchanged_at (premier échange du code) et rattrape les connexions déjà échangées (#730).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_connection ADD code_exchanged_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql(
            'UPDATE mcp_connection SET code_exchanged_at = granted_at'
            .' WHERE last_used_at IS NOT NULL'
            .' OR EXISTS (SELECT 1 FROM mcp_access_token t WHERE t.connection_id = mcp_connection.id)'
            .' OR EXISTS (SELECT 1 FROM mcp_refresh_token r WHERE r.connection_id = mcp_connection.id)',
        );
    }

    /** Retire la colonne : l'information du premier échange est perdue, rien d'autre n'en dépend. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_connection DROP code_exchanged_at');
    }
}
