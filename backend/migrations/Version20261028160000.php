<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #650 (relecture R1, F2) — `cpg_user.credentials_changed_at`, l'instant où le
 * mot de passe du compte a changé pour la dernière fois, par le `PUT` du
 * backoffice (`CpgUserAdministrator::changePassword()`) ou par le lien
 * d'invitation, qu'il pose le premier mot de passe ou en remplace un
 * (`PasswordSetupService::activate()`). Le consentement MCP refuse un cookie de
 * session authentifié avant cet instant : la révocation des connexions MCP au
 * changement de mot de passe (tâches 5 et 5b) coupait ce qui existait, pas ce
 * qu'un cookie volé — non révocable, une heure (ADR 0003) — pouvait accorder
 * ensuite pendant ses dix minutes de grâce.
 *
 * **Aucun rattrapage, voulu** : la colonne reste nulle pour tout compte
 * existant, ce qui se lit « mot de passe jamais changé depuis la colonne » et ne
 * refuse aucun cookie — le comportement d'avant. Écrire `now()` aurait invalidé
 * d'un coup, pour le consentement, chaque session ouverte, sans qu'aucun mot de
 * passe n'ait changé.
 *
 * Colonne nullable, ajoutée sans valeur par défaut, `TIMESTAMP(0) WITH TIME
 * ZONE` comme tous les instants du schéma (#434) : un `ALTER TABLE … ADD
 * COLUMN` sans valeur par défaut ne réécrit pas la table, verrou bref borné par
 * le `lock_timeout` de 5 s du Job de migration (#525). Elle passe avant le
 * rollout (#175) : le code de la version précédente ignore la colonne (ses
 * `INSERT` la laissent nulle, ses lectures ne la sélectionnent pas) ; le code
 * nouveau, lui, la sélectionne et exige la migration — le Job la joue avant le
 * rollout, sans fenêtre.
 */
final class Version20261028160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute cpg_user.credentials_changed_at (dernier changement de mot de passe) (#650).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cpg_user ADD credentials_changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    /** Retire la colonne : l'information du dernier changement est perdue, rien d'autre n'en dépend. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cpg_user DROP credentials_changed_at');
    }
}
