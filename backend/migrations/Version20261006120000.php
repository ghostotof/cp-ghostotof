<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #372 — la base refuse elle-même un temps cumulé hors de [0, 100].
 *
 * Avant #372, `{"years":1e999}` au backoffice ou `--years=1e999` en CLI
 * persistaient `Infinity` ; une seule ligne non encodable suffit à mettre
 * GET /api/experience/technologies en 500 pour tout visiteur. Le Value Object
 * ExperienceYears garde désormais toute écriture qui passe par l'entité, mais
 * Doctrine n'appelle pas le constructeur en hydratant : seule la base protège
 * une ligne écrite en SQL, ou persistée avant la correction.
 *
 * Les lignes déjà fautives sont d'abord ramenées dans les bornes (NaN et
 * négatifs à 0, au-delà de 100 à 100) **et** passées en `secondary` : la
 * technologie reste publiée, mais dans l'énumération sans durée, si bien que
 * la valeur inventée ne s'affiche jamais ; elle se corrige au backoffice.
 * Sans ce préalable, `ADD CONSTRAINT` échouerait sur la première d'entre elles
 * et ferait échouer le déploiement. PostgreSQL range NaN au-dessus de tout
 * nombre : `years <= 100` est faux pour lui, la condition le couvre donc
 * aussi, et `years = 'NaN'` le distingue de +Infinity.
 *
 * La borne est écrite en dur, comme `ExperienceYears::MAX` : une migration est
 * un historique figé. `ExperienceTechnologyYearsConstraintTest` épingle que la
 * contrainte et le Value Object restent d'accord.
 *
 * Expand/contract (#175) : l'ancien code lit sans difficulté une ligne bornée.
 * Entre la migration et la fin du rollout, une écriture hors bornes par
 * l'ancien code est refusée par la base (500) au lieu d'être persistée —
 * c'est précisément le défaut corrigé. Déploiement standard, sans fenêtre de
 * maintenance.
 */
final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Borne experience_technology.years à [0, 100] par une contrainte CHECK (#372).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE experience_technology'
            ." SET years = CASE WHEN years = 'NaN'::float8 OR years < 0 THEN 0 ELSE 100 END,"
            .' secondary = true'
            .' WHERE NOT (years >= 0 AND years <= 100)',
        );
        $this->addSql(
            'ALTER TABLE experience_technology'
            .' ADD CONSTRAINT chk_experience_technology_years CHECK (years >= 0 AND years <= 100)',
        );
    }

    /**
     * Retire la contrainte ; les valeurs bornées par `up()` restent ce
     * qu'elles sont — y remettre `Infinity` rouvrirait le 500 public.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE experience_technology DROP CONSTRAINT chk_experience_technology_years');
    }
}
