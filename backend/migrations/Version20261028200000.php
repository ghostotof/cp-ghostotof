<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #650 (seconde relecture, décision de l'auteur du 2026-10-28) — retire
 * `cpg_user.credentials_changed_at` (Version20261028160000).
 *
 * La colonne datait le dernier changement de mot de passe, et le jugement d'une
 * session comparait l'`iat` de son JWT à cette date. Deux défauts (F-A : une
 * connexion qui avait lu l'ancien hachage avant le COMMIT du changement mais
 * signait son jeton une seconde après passait ; F-B : un décalage d'horloge
 * entre pods refusait une reconnexion légitime) ont fait remplacer la date par
 * la version des identifiants (`credentials_version`, Version20261028180000).
 * Depuis, la date était écrite et plus lue par aucune règle : un état sans lecteur
 * est un état qui ment un jour. Elle est retirée plutôt que gardée « au cas où ».
 *
 * **Pourquoi la retirer dans la même livraison que celle qui cesse de la lire,
 * contre la règle du contrat expand/contract** (#175 : « jamais retirer une
 * colonne dans la version qui cesse de la lire »). Cette règle protège le code
 * *déjà déployé* qui la lit pendant le rollout. Ici la colonne n'a **jamais été
 * livrée** : `Version20261028160000` n'est ni dans `main` ni dans `develop`, la
 * version en production ne la connaît pas (ses `INSERT` ne la nomment pas, ses
 * lectures ne la sélectionnent pas), et la livraison qui apporte le code
 * nouveau apporte les deux migrations, jouées dans l'ordre avant le rollout
 * (l'ajout, puis le retrait). Aucun code ne lit ou n'écrit la colonne à aucun
 * moment d'un déploiement. Pour une colonne livrée, deux versions seraient requises.
 *
 * `Version20261028160000` n'est pas modifiée (déjà jouée sur les bases de
 * développement et de test) : sa trace reste, celle-ci la défait. Les bases qui
 * ont joué les deux ne portent plus la colonne, comme celles qui n'ont jamais
 * joué la première.
 *
 * `down()` recrée la colonne, nullable et vide : la date du dernier changement de
 * chaque compte est perdue, rien d'autre n'en dépend. Comme à l'origine, une colonne
 * nullable sans valeur par défaut n'entraîne aucune réécriture de la table ; le
 * `DROP COLUMN` ne la réécrit pas non plus (la colonne est marquée supprimée), verrou
 * bref borné par le `lock_timeout` de 5 s du Job de migration (#525).
 */
final class Version20261028200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retire cpg_user.credentials_changed_at, remplacée par credentials_version (#650).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cpg_user DROP credentials_changed_at');
    }

    /** Recrée la colonne, nullable et vide : la date des changements passés est perdue. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cpg_user ADD credentials_changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }
}
