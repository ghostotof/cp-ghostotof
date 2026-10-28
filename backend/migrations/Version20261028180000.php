<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #650 (seconde relecture, S1, F-A) — `cpg_user.credentials_version`, la
 * **version des identifiants** du compte : un entier avancé d'un cran par chaque
 * remplacement de mot de passe, par le `PUT` du backoffice
 * (`CpgUserAdministrator::changePassword()`) ou par le lien d'invitation
 * (`PasswordSetupService::activate()`), tous deux par `CpgUser::replacePassword()`
 * et sous le verrou du compte. Jamais par le re-hachage à la connexion (même
 * secret).
 *
 * Le JWT de session en porte une copie, posée à sa création depuis le `CpgUser`
 * dont le hachage vient d'être vérifié (revendication `cv`), et chaque requête
 * la compare **par égalité** à la version courante. Elle remplace la
 * comparaison `iat` ≤ `credentials_changed_at` de R1/R1b, que deux défauts
 * rendaient fausse : une connexion qui avait lu l'ancien hachage avant le
 * COMMIT du changement mais signait son JWT une seconde après la date du
 * changement passait (F-A), et un décalage d'horloge entre pods refusait à tort
 * une reconnexion légitime (F-B). Une version ne dépend d'aucune horloge.
 *
 * **`INT NOT NULL DEFAULT 0`** : un compte existant naît à 0, sans rattrapage.
 * Un JWT émis avant le déploiement n'a pas de revendication ; l'absence vaut 0,
 * donc servi tant que le mot de passe n'a pas changé — sinon chaque session
 * ouverte au déploiement sauterait — et refusé dès qu'il a changé. Une valeur par
 * défaut constante, non volatile, n'entraîne aucune réécriture de la table
 * (PostgreSQL 11+) : verrou bref borné par le `lock_timeout` de 5 s du Job de
 * migration (#525). Un entier plutôt qu'un UUID ou un hachage : il s'incrémente
 * sans lecture ni aléa, sous un verrou déjà tenu, et se compare par égalité ;
 * il ne dit rien du mot de passe.
 *
 * Elle passe avant le rollout (#175) : le code de la version précédente ignore
 * la colonne (ses `INSERT` la laissent à 0, ses lectures ne la sélectionnent
 * pas) ; le code nouveau l'exige, et le Job la joue avant le rollout, sans
 * fenêtre. `credentials_changed_at` (Version20261028160000) reste en place :
 * plus rien ne la lit pour décider, la retirer est une migration à discuter.
 */
final class Version20261028180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute cpg_user.credentials_version (version des identifiants, avancée à chaque remplacement de mot de passe) (#650).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cpg_user ADD credentials_version INT DEFAULT 0 NOT NULL');
    }

    /** Retire la colonne : les JWT en cours, qui portent `cv`, ne sont plus comparés à rien. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cpg_user DROP credentials_version');
    }
}
