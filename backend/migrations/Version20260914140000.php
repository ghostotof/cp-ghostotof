<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 0003 — bascule du contexte Portfolio/About (`about_settings`,
 * `about_site_card`, `about_me_card`) en clés primaires UUID v7. Aucune de
 * ces trois tables n'est référencée par une clé étrangère : les trois
 * bascules sont indépendantes et peuvent se lire dans n'importe quel ordre —
 * même remarque que Version20260914130000 (Experience/Quality), dont cette
 * migration reprend la même forme.
 *
 * Les lignes existantes reçoivent un UUID v7 décalé de leur rang, en
 * millisecondes, dans l'ordre de l'ancien `id` (décision D5) : l'ordre de repli
 * `ORDER BY id` est donc exactement celui d'avant la migration.
 * `gen_random_uuid()` (v4) aurait rendu ce tri aléatoire ; `uuidv7(interval)`
 * est disponible depuis PostgreSQL 18 (POSTGRES_TAG=18.6-alpine).
 *
 * Aucun index de ces tables ne porte sur `id` au-delà de la PK elle-même :
 * l'unique `uniq_about_settings_locale` porte sur `locale`, et les index
 * `idx_about_site_card_locale` / `idx_about_me_card_locale_category` restent
 * intacts. AboutSettings est un singleton par locale, identifié côté
 * ressource par `locale` (BackofficeAboutSettingsResource n'a pas
 * d'opération `{id}`) : la bascule ne change donc rien à sa surface HTTP,
 * seulement au mapping Doctrine et à la table.
 *
 * Irréversible (décision D4) : les entiers d'origine ne sont pas restituables,
 * et un `down()` qui les réinventerait mentirait sur l'état restauré.
 */
final class Version20260914140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portfolio/About : clés primaires UUID v7 sur about_settings, about_site_card et about_me_card.';
    }

    public function up(Schema $schema): void
    {
        $this->migrateTableToUuidPrimaryKey('about_settings');
        $this->migrateTableToUuidPrimaryKey('about_site_card');
        $this->migrateTableToUuidPrimaryKey('about_me_card');
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
