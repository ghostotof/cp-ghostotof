<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR 0005 — table `cache_items` de l'adaptateur Doctrine DBAL du cache
 * applicatif (`framework.cache.app`, cf. config/packages/cache.yaml).
 *
 * Le pool `cache.app` quitte le système de fichiers du pod, en lecture seule
 * en production, pour la base : c'est lui qui porte l'état de tous les
 * limiteurs de débit et le cache de résultats Doctrine. Schéma identique à
 * celui que `DoctrineDbalAdapter::createTable()` produirait — l'adaptateur
 * sait créer sa table à la première écriture, mais on la déclare ici pour
 * qu'elle reste sous Doctrine Migrations comme le reste du schéma, et pour
 * que la première requête de la release n'ait pas un DDL à sa charge.
 *
 * Expand/contract (#175) : purement additive, l'ancien code ne lit pas cette
 * table — un déploiement standard, sans fenêtre de maintenance.
 */
final class Version20260916180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée cache_items, stockage Doctrine DBAL du cache applicatif (limiteurs de débit, cache de résultats) — ADR 0005.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cache_items (item_id VARCHAR(255) NOT NULL, item_data BYTEA NOT NULL, item_lifetime INT DEFAULT NULL, item_time INT NOT NULL, PRIMARY KEY (item_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cache_items');
    }
}
