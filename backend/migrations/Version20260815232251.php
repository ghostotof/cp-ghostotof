<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute les index manquants sur about_site_card.locale et
 * about_me_card.(locale, category), seules colonnes de filtrage fréquent de
 * ces deux tables à ne pas déjà en avoir un (about_settings.locale est
 * couvert par sa contrainte unique).
 */
final class Version20260815232251 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les index sur about_site_card.locale et about_me_card.(locale, category).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_about_me_card_locale_category ON about_me_card (locale, category)');
        $this->addSql('CREATE INDEX idx_about_site_card_locale ON about_site_card (locale)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_about_me_card_locale_category');
        $this->addSql('DROP INDEX idx_about_site_card_locale');
    }
}
