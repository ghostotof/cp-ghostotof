<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recentre le parcours technique sur un profil backend senior.
 *
 * Trois changements, de schéma puis de données :
 *
 * 1. Colonne `secondary` : une technologie pratiquée au fil du parcours sans
 *    structurer le profil sort du classement chiffré pour rejoindre une
 *    énumération sans durée. C'est un arbitrage éditorial par technologie,
 *    donc une donnée modifiable depuis le backoffice — et non un seuil
 *    automatique sur `years`, qui réorganiserait le classement tout seul.
 *
 * 2. PHP ne porte plus HTML/CSS/JavaScript en technologie liée, et ce dernier
 *    devient une entrée autonome. Les afficher ensemble tirait treize ans et
 *    demi de backend vers un profil de webmaster.
 *
 * 3. Les huit dernières technologies passent en `secondary`. Afficher
 *    « Python — 6 mois » revient à publier l'endroit où l'on débute, sans que
 *    personne l'ait demandé.
 *
 * Migration de données : elle modifie le contenu éditorial du site, pas
 * seulement sa structure. `down()` restaure l'état antérieur, y compris la
 * suppression de la ligne créée ici.
 */
final class Version20260906160000 extends AbstractMigration
{
    /**
     * Rangs 9 à 16 du classement au moment de la migration. Liste figée
     * volontairement : rejouer cette migration sur une base dont le contenu a
     * changé depuis ne doit pas reclasser des technologies arbitraires.
     */
    private const array SECONDARY_TECHNOLOGIES = [
        'C',
        'C++',
        'OCaml',
        'Java',
        'Cobol / SQL/DB2 / MVS / CICS',
        'R',
        'Assembleur / Assembly',
        'Python',
    ];

    private const string RELATED_FRONT_STACK = 'HTML / CSS / JavaScript';

    public function getDescription(): string
    {
        return 'Ajoute experience_technology.secondary, détache HTML/CSS/JavaScript de PHP et replie les technologies secondaires.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE experience_technology ADD secondary BOOLEAN DEFAULT FALSE NOT NULL');

        // PHP retrouve sa propre ligne, sans stack front accrochée.
        $this->addSql(
            'UPDATE experience_technology SET related_technology_name = NULL WHERE related_technology_name = :front',
            ['front' => self::RELATED_FRONT_STACK],
        );

        // HTML/CSS/JavaScript devient une entrée à part entière, avec la même
        // ancienneté que PHP. Pas d'icon_key : même convention que
        // « MySQL / PostgreSQL / SQL Server », une ligne qui agrège plusieurs
        // technologies n'en met aucune en avant.
        // NOT EXISTS : la migration reste rejouable si la ligne a déjà été
        // créée à la main depuis le backoffice.
        $this->addSql(
            'INSERT INTO experience_technology (name, years, icon_key, related_technology_name, secondary)
             SELECT :name, 13.5, NULL, NULL, FALSE
             WHERE NOT EXISTS (SELECT 1 FROM experience_technology WHERE name = :name)',
            ['name' => self::RELATED_FRONT_STACK],
        );

        $this->addSql(
            'UPDATE experience_technology SET secondary = TRUE WHERE name IN (:names)',
            ['names' => self::SECONDARY_TECHNOLOGIES],
            ['names' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM experience_technology WHERE name = :name',
            ['name' => self::RELATED_FRONT_STACK],
        );

        $this->addSql(
            'UPDATE experience_technology SET related_technology_name = :front WHERE name = :php',
            ['front' => self::RELATED_FRONT_STACK, 'php' => 'PHP'],
        );

        $this->addSql('ALTER TABLE experience_technology DROP secondary');
    }
}
