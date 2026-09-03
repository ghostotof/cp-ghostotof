<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute à cpg_user les colonnes du cycle d'invitation depuis le backoffice :
 * email (nullable, unique — les comptes CLI n'en ont pas), invited_at et
 * activated_at. Toutes nullable : les comptes existants restent valides tels
 * quels (compte CLI = email/invited_at/activated_at à NULL).
 */
final class Version20260903155605 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute email, invited_at et activated_at sur cpg_user (invitation depuis le backoffice).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cpg_user ADD email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE cpg_user ADD invited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cpg_user ADD activated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_cpg_user_email ON cpg_user (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cpg_user_email');
        $this->addSql('ALTER TABLE cpg_user DROP email');
        $this->addSql('ALTER TABLE cpg_user DROP invited_at');
        $this->addSql('ALTER TABLE cpg_user DROP activated_at');
    }
}
