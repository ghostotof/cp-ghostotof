<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 0003 — bascule des contextes Portfolio/Experience et Portfolio/Quality
 * en clés primaires UUID v7 (`experience_technology`, `quality_principle`,
 * `quality_trait`). Aucune de ces trois tables n'est référencée par une clé
 * étrangère (à la différence de Security/User, cf. Version20260914120000) :
 * les trois bascules sont indépendantes et peuvent se lire dans n'importe
 * quel ordre.
 *
 * Les lignes existantes reçoivent un UUID v7 décalé de leur rang, en
 * millisecondes, dans l'ordre de l'ancien `id` (décision D5) : l'ordre de repli
 * `ORDER BY id` est donc exactement celui d'avant la migration.
 * `gen_random_uuid()` (v4) aurait rendu ce tri aléatoire ; `uuidv7(interval)`
 * est disponible depuis PostgreSQL 18 (POSTGRES_TAG=18.6-alpine).
 *
 * Aucun index de ces tables ne porte sur `id` au-delà de la PK elle-même :
 * les index `(locale, position)` de quality_principle/quality_trait et
 * l'unique `(name)` d'experience_technology restent intacts.
 *
 * Irréversible (décision D4) : les entiers d'origine ne sont pas restituables,
 * et un `down()` qui les réinventerait mentirait sur l'état restauré.
 */
final class Version20260914130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portfolio/Experience et Portfolio/Quality : clés primaires UUID v7 sur experience_technology, quality_principle et quality_trait.';
    }

    public function up(Schema $schema): void
    {
        $this->migrateTableToUuidPrimaryKey('experience_technology');
        $this->migrateTableToUuidPrimaryKey('quality_principle');
        $this->migrateTableToUuidPrimaryKey('quality_trait');
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
