<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fusionne C, C++ et Assembleur en une seule entrée « C / C++ / Assembleur ».
 *
 * Ces trois lignes occupaient trois places dans l'énumération des
 * technologies repliées alors qu'elles décrivent le même registre — la
 * programmation bas niveau, croisée pendant les études. Les regrouper suit la
 * convention déjà en place dans cette table pour les familles de technologies
 * (« MySQL / PostgreSQL / SQL Server », « Cobol / SQL/DB2 / MVS / CICS »).
 *
 * Mise en œuvre par renommage de la ligne « C » plutôt que par
 * suppression-puis-insertion : l'id est conservé, et on ne heurte pas la
 * contrainte unique sur `name` au passage.
 *
 * icon_key passe à NULL : une ligne qui agrège plusieurs technologies n'en met
 * aucune en avant, même convention que « MySQL / PostgreSQL / SQL Server ».
 *
 * `years` reste à 3.0, le maximum des trois. La valeur n'est plus affichée
 * pour une technologie repliée, mais elle continue d'ordonner l'énumération.
 */
final class Version20260906180000 extends AbstractMigration
{
    private const string MERGED_NAME = 'C / C++ / Assembleur';

    public function getDescription(): string
    {
        return 'Fusionne C, C++ et Assembleur / Assembly en une entrée unique « C / C++ / Assembleur ».';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE experience_technology SET name = :merged, icon_key = NULL, years = 3.0, secondary = TRUE WHERE name = :c',
            ['merged' => self::MERGED_NAME, 'c' => 'C'],
        );

        $this->addSql(
            'DELETE FROM experience_technology WHERE name IN (:absorbed)',
            ['absorbed' => ['C++', 'Assembleur / Assembly']],
            ['absorbed' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE experience_technology SET name = :c, icon_key = :icon, years = 3.0, secondary = TRUE WHERE name = :merged',
            ['c' => 'C', 'icon' => 'c', 'merged' => self::MERGED_NAME],
        );

        // NOT EXISTS : rejouable si les lignes ont été recréées entre-temps
        // depuis le backoffice.
        $this->addSql(
            "INSERT INTO experience_technology (name, years, icon_key, related_technology_name, secondary)
             SELECT 'C++', 3.0, 'cpp', NULL, TRUE
             WHERE NOT EXISTS (SELECT 1 FROM experience_technology WHERE name = 'C++')",
        );
        $this->addSql(
            "INSERT INTO experience_technology (name, years, icon_key, related_technology_name, secondary)
             SELECT 'Assembleur / Assembly', 1.0, NULL, NULL, TRUE
             WHERE NOT EXISTS (SELECT 1 FROM experience_technology WHERE name = 'Assembleur / Assembly')",
        );
    }
}
