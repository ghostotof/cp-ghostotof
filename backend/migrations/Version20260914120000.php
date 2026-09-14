<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 0003 — bascule du contexte Security/User en clés primaires UUID v7
 * (`cpg_user` et `password_setup_token`, seule clé étrangère entre entités du
 * projet).
 *
 * Les lignes existantes reçoivent un UUID v7 décalé de leur rang, en
 * millisecondes, dans l'ordre de l'ancien `id` (décision D5) : l'ordre de repli
 * `ORDER BY id` est donc exactement celui d'avant la migration.
 * `gen_random_uuid()` (v4) aurait rendu ce tri aléatoire ; `uuidv7(interval)`
 * est disponible depuis PostgreSQL 18 (POSTGRES_TAG=18.6-alpine).
 *
 * L'ordre des opérations est contraint : la FK de `password_setup_token` est
 * remappée sur la nouvelle colonne AVANT que `cpg_user` ne perde son ancien
 * `id`, faute de quoi la correspondance entre les deux serait perdue.
 *
 * Irréversible (décision D4) : les entiers d'origine ne sont pas restituables,
 * et un `down()` qui les réinventerait mentirait sur l'état restauré.
 */
final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Security/User : clés primaires UUID v7 sur cpg_user et password_setup_token.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            version_compare($this->connection->getServerVersion(), '18', '<'),
            'Spec 0003 D5 : uuidv7() exige PostgreSQL 18.',
        );

        // 1. cpg_user : nouvelle colonne, remplie en v7 monotone dans l'ordre des anciens ids
        $this->addSql('ALTER TABLE cpg_user ADD id_uuid UUID');
        $this->addSql(<<<'SQL'
            UPDATE cpg_user SET id_uuid = ranked.new_id
            FROM (SELECT id, uuidv7(make_interval(secs => ROW_NUMBER() OVER (ORDER BY id) / 1000.0)) AS new_id FROM cpg_user) AS ranked
            WHERE cpg_user.id = ranked.id
            SQL);
        $this->addSql('ALTER TABLE cpg_user ALTER id_uuid SET NOT NULL');

        // 2. password_setup_token : la FK est remappée AVANT que cpg_user perde son ancien id
        $this->addSql('ALTER TABLE password_setup_token ADD user_id_uuid UUID');
        $this->addSql('UPDATE password_setup_token t SET user_id_uuid = u.id_uuid FROM cpg_user u WHERE u.id = t.user_id');
        $this->addSql('ALTER TABLE password_setup_token DROP CONSTRAINT FK_46600387A76ED395');
        $this->addSql('DROP INDEX IDX_46600387A76ED395');
        $this->addSql('ALTER TABLE password_setup_token DROP COLUMN user_id');
        $this->addSql('ALTER TABLE password_setup_token RENAME COLUMN user_id_uuid TO user_id');
        $this->addSql('ALTER TABLE password_setup_token ALTER user_id SET NOT NULL');

        // 3. password_setup_token : sa propre PK
        $this->addSql('ALTER TABLE password_setup_token ADD id_uuid UUID');
        $this->addSql(<<<'SQL'
            UPDATE password_setup_token SET id_uuid = ranked.new_id
            FROM (SELECT id, uuidv7(make_interval(secs => ROW_NUMBER() OVER (ORDER BY id) / 1000.0)) AS new_id FROM password_setup_token) AS ranked
            WHERE password_setup_token.id = ranked.id
            SQL);
        $this->addSql('ALTER TABLE password_setup_token ALTER id_uuid SET NOT NULL');
        $this->addSql('ALTER TABLE password_setup_token DROP CONSTRAINT password_setup_token_pkey');
        $this->addSql('ALTER TABLE password_setup_token DROP COLUMN id');
        $this->addSql('ALTER TABLE password_setup_token RENAME COLUMN id_uuid TO id');
        $this->addSql('ALTER TABLE password_setup_token ADD PRIMARY KEY (id)');

        // 4. cpg_user : bascule de la PK
        $this->addSql('ALTER TABLE cpg_user DROP CONSTRAINT cpg_user_pkey');
        $this->addSql('ALTER TABLE cpg_user DROP COLUMN id');
        $this->addSql('ALTER TABLE cpg_user RENAME COLUMN id_uuid TO id');
        $this->addSql('ALTER TABLE cpg_user ADD PRIMARY KEY (id)');

        // 5. FK et index recréés avec les noms que Doctrine attend (schema:validate)
        $this->addSql('CREATE INDEX IDX_46600387A76ED395 ON password_setup_token (user_id)');
        $this->addSql('ALTER TABLE password_setup_token ADD CONSTRAINT FK_46600387A76ED395 FOREIGN KEY (user_id) REFERENCES cpg_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les identifiants entiers d\'origine ne sont pas restituables.');
    }
}
