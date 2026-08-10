<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remplace l'email par un simple nom d'utilisateur comme identifiant de
 * connexion (aucune donnée personnelle identifiante stockée). Renomme la
 * colonne plutôt que de la recréer, pour conserver les comptes existants.
 */
final class Version20260808212823 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Remplace la colonne email par username sur cpg_user (identifiant de connexion).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cpg_user RENAME COLUMN email TO username');
        $this->addSql('ALTER TABLE cpg_user ALTER COLUMN username TYPE VARCHAR(60)');
        $this->addSql('DROP INDEX uniq_cpg_user_email');
        $this->addSql('CREATE UNIQUE INDEX uniq_cpg_user_username ON cpg_user (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cpg_user_username');
        $this->addSql('ALTER TABLE cpg_user ALTER COLUMN username TYPE VARCHAR(180)');
        $this->addSql('ALTER TABLE cpg_user RENAME COLUMN username TO email');
        $this->addSql('CREATE UNIQUE INDEX uniq_cpg_user_email ON cpg_user (email)');
    }
}
