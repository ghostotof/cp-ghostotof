<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #468 — le cache en base est vidé une fois, au passage aux valeurs signées.
 *
 * Depuis #468, chaque valeur de `cache_items` porte un MAC, vérifié avant
 * `unserialize()` (AuthenticatedCacheMarshaller) ; une ligne sans MAC est
 * refusée et lue comme une absence de cache, mais jamais supprimée. Les
 * compteurs des limiteurs expirent d'eux-mêmes. Le signal de redémarrage du
 * worker, lui, est posé sans expiration par `messenger:stop-workers` : refusé
 * à chaque tour du worker, il produirait un `warning` par tour, indéfiniment,
 * et `cache:pool:prune` ne l'efface jamais. Vider la table règle tous les cas
 * d'un coup ; ce n'est qu'un cache, qui se reconstruit.
 *
 * Expand/contract (#175) : déploiement standard, migration avant le rollout.
 * Les anciens pods, qui servent encore, réécrivent des valeurs sans MAC
 * jusqu'à la fin du rollout : les nouveaux les refusent et les écrasent, et
 * leurs compteurs repartent de zéro à chaque alternance (note de release).
 * Aucun d'eux ne pose le signal de redémarrage.
 */
final class Version20261011140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vide cache_items au passage aux valeurs de cache signées (#468).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM cache_items');
    }

    public function down(Schema $schema): void
    {
        // Un cache vidé ne se restaure pas, et n'a pas à l'être : l'ancien code
        // le reconstruit comme le nouveau.
    }
}
