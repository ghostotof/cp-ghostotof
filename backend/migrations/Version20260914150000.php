<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 0003 — bascule des quatre contextes éditoriaux `Portfolio/Contribution`,
 * `Portfolio/Incident`, `Portfolio/AnonymousCv` et `Portfolio/CaseStudy`
 * (`contribution`, `incident`, `anonymous_cv_section`, `case_study`) en clés
 * primaires UUID v7. Aucune de ces quatre tables n'est référencée par une clé
 * étrangère : les quatre bascules sont indépendantes et peuvent se lire dans
 * n'importe quel ordre — même remarque que Version20260914130000
 * (Experience/Quality) et Version20260914140000 (About), dont cette migration
 * reprend la même forme.
 *
 * Les lignes existantes reçoivent un UUID v7 décalé de leur rang, en
 * millisecondes, dans l'ordre de l'ancien `id` (décision D5) : l'ordre de repli
 * `ORDER BY id` est donc exactement celui d'avant la migration.
 * `gen_random_uuid()` (v4) aurait rendu ce tri aléatoire ; `uuidv7(interval)`
 * est disponible depuis PostgreSQL 18 (POSTGRES_TAG=18.6-alpine).
 *
 * Aucun index de ces tables ne porte sur `id` au-delà de la PK elle-même :
 * `idx_contribution_locale_position`, `idx_incident_locale_position`,
 * `idx_anonymous_cv_section_locale_position` et `idx_case_study_locale_position`
 * portent sur (`locale`, `position`) et restent intacts.
 *
 * Irréversible (décision D4) : les entiers d'origine ne sont pas restituables,
 * et un `down()` qui les réinventerait mentirait sur l'état restauré.
 */
final class Version20260914150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portfolio/Contribution, Incident, AnonymousCv, CaseStudy : clés primaires UUID v7 sur contribution, incident, anonymous_cv_section et case_study.';
    }

    public function up(Schema $schema): void
    {
        $this->migrateTableToUuidPrimaryKey('contribution');
        $this->migrateTableToUuidPrimaryKey('incident');
        $this->migrateTableToUuidPrimaryKey('anonymous_cv_section');
        $this->migrateTableToUuidPrimaryKey('case_study');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les identifiants entiers d\'origine ne sont pas restituables.');
    }

    private function migrateTableToUuidPrimaryKey(string $table): void
    {
        $this->addSql(sprintf('ALTER TABLE %s ADD id_uuid UUID', $table));
        $this->addSql(sprintf(<<<'SQL'
            UPDATE %1$s SET id_uuid = ranked.new_id
            FROM (SELECT id, uuidv7(make_interval(secs => ROW_NUMBER() OVER (ORDER BY id) / 1000.0)) AS new_id FROM %1$s) AS ranked
            WHERE %1$s.id = ranked.id
            SQL, $table));
        $this->addSql(sprintf('ALTER TABLE %s ALTER id_uuid SET NOT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT %s_pkey', $table, $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN id', $table));
        $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN id_uuid TO id', $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD PRIMARY KEY (id)', $table));
    }
}
