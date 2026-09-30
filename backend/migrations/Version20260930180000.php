<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #287 — les produits suivis d'avant #19 passent en source `deployed`.
 *
 * #19 (commit 0f2c00b, 2026-09-09) a fait relever la version de PostgreSQL,
 * RabbitMQ, nginx, Node.js et Vue.js au `docker build`
 * (`config/watch/deployed-versions.json`) au lieu d'une saisie manuelle. Seul
 * le seed a changé : les lignes existantes sont restées en `manual`, avec la
 * version saisie à l'époque, et `app:watch:seed` ne touche jamais une base
 * déjà remplie (`GuardsExistingContent`). `/stack` affichait donc, en préprod
 * comme en production, PostgreSQL 18.4 alors que le cluster exécute 18.6.
 *
 * La liste des slugs est écrite en dur : une migration est un historique
 * figé, elle ne doit pas dépendre du code applicatif d'une version ultérieure.
 * Ce sont les clés que `bin/build-deployed-versions.php` produit.
 *
 * Seules les lignes encore en `manual` sont converties : une base neuve (déjà
 * en `deployed` via le seed) et un produit qu'un administrateur aurait passé
 * dans une autre source ne sont pas touchés, et la migration peut être rejouée
 * sans effet.
 *
 * Expand/contract (#175) : l'ancien code lit une ligne `deployed` sans
 * difficulté (la valeur existe dans l'enum depuis #19) — déploiement standard,
 * sans fenêtre de maintenance.
 */
final class Version20260930180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Passe en source deployed les produits suivis restés en manual depuis #19 (#287).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE watched_product SET version_source = 'deployed', version = NULL"
            ." WHERE version_source = 'manual'"
            ." AND slug IN ('postgresql', 'rabbitmq', 'nginx', 'nodejs', 'vue')",
        );
    }

    /**
     * Les versions saisies avant #19 sont effacées par `up()` : les
     * reconstituer serait inventer des valeurs. Et revenir en `manual` sans
     * version violerait l'invariant de `WatchedProduct` (une source manuelle
     * exige une version).
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les versions saisies à la main avant #19 ne sont pas conservées.',
        );
    }
}
