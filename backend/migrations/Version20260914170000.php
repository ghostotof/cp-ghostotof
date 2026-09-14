<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 0004 D1 — pose le lien FR/EN en base : `translation_group uuid NOT NULL`
 * et un index unique `(translation_group, locale)` sur les huit contenus
 * localisés à position. Deux lignes de même groupe sont le même contenu dans
 * deux langues ; l'index dit qu'un groupe ne peut pas porter deux fois la même
 * langue.
 *
 * **L'appariement de l'existant est le vrai sujet de cette migration.** Le lien
 * n'existait nulle part : il faut le déduire. La seule correspondance fiable
 * est la position — les commandes `app:*:seed` créent le FR puis l'EN par index
 * aligné, et le backoffice reprend cette numérotation. La déduction est donc
 * faite, mais **jamais devinée** :
 *
 *  - une ligne FR et une ligne EN de même `position` (et même `category` pour
 *    `about_me_card`, dont le périmètre d'ordre est la catégorie) partagent un
 *    groupe **si et seulement si** ce couple est unique de chaque côté ;
 *  - toute autre ligne — position en double, langue orpheline, contenu ajouté
 *    hors seed — reçoit son propre groupe.
 *
 * Apparier deux lignes homonymes au jugé produirait un faux lien qu'aucune
 * relecture ne rattraperait ; un groupe isolé, lui, se corrige d'un clic au
 * backoffice (« Version de »). Dans le doute, isoler.
 *
 * Aucune ligne n'est supprimée ni modifiée au-delà de cette colonne.
 *
 * `uuidv7()` (PostgreSQL 18) est volatile : elle est évaluée une fois par ligne
 * produite, donc une fois par **paire** dans la CTE `pairs` — les deux lignes
 * d'une même paire reçoivent bien le même groupe. PostgreSQL n'inline jamais
 * une CTE contenant une fonction volatile, si bien que le `MATERIALIZED`
 * explicite ne corrige aucun comportement : il rend la garantie lisible sur
 * place, pour une propriété dont la violation serait silencieuse. Vérifié en
 * dev après coup (autant de groupes appariés que de couples uniques).
 *
 * Réversible, contrairement aux migrations de la spec 0003 : la colonne
 * n'existait pas avant, la retirer ne perd donc que l'appariement lui-même.
 */
final class Version20260914170000 extends AbstractMigration
{
    /**
     * Les huit tables localisées à position, avec la colonne qui restreint le
     * périmètre d'appariement quand il y en a une.
     *
     * @var array<string, ?string>
     */
    private const array TABLES = [
        'quality_principle' => null,
        'quality_trait' => null,
        'about_site_card' => null,
        'about_me_card' => 'category',
        'contribution' => null,
        'incident' => null,
        'anonymous_cv_section' => null,
        'case_study' => null,
    ];

    public function getDescription(): string
    {
        return 'Spec 0004 : translation_group (UUID, NOT NULL, unique par locale) sur les huit contenus localisés, avec appariement FR/EN de l\'existant par position.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $scopeColumn) {
            $this->addTranslationGroup($table, $scopeColumn);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $this->addSql(sprintf('DROP INDEX uniq_%s_translation_group_locale', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN translation_group', $table));
        }
    }

    private function addTranslationGroup(string $table, ?string $scopeColumn): void
    {
        // Nullable d'abord : les lignes existantes n'ont pas encore de groupe.
        $this->addSql(sprintf('ALTER TABLE %s ADD translation_group UUID', $table));

        $scopeSelect = null === $scopeColumn ? '' : sprintf(', %s', $scopeColumn);
        $scopeGroupBy = null === $scopeColumn ? '' : sprintf(', %s', $scopeColumn);
        $scopeJoin = static fn (string $left, string $right): string => null === $scopeColumn
            ? ''
            : sprintf(' AND %1$s.%3$s = %2$s.%3$s', $left, $right, $scopeColumn);

        $this->addSql(sprintf(
            <<<'SQL'
                WITH fr AS (
                    SELECT id, "position"%2$s FROM %1$s WHERE locale = 'fr'
                ),
                en AS (
                    SELECT id, "position"%2$s FROM %1$s WHERE locale = 'en'
                ),
                fr_unique AS (
                    SELECT "position"%2$s FROM fr GROUP BY "position"%3$s HAVING count(*) = 1
                ),
                en_unique AS (
                    SELECT "position"%2$s FROM en GROUP BY "position"%3$s HAVING count(*) = 1
                ),
                pairs AS MATERIALIZED (
                    SELECT fr.id AS fr_id, en.id AS en_id, uuidv7() AS translation_group
                    FROM fr
                    JOIN en ON en."position" = fr."position"%4$s
                    JOIN fr_unique ON fr_unique."position" = fr."position"%5$s
                    JOIN en_unique ON en_unique."position" = en."position"%6$s
                )
                UPDATE %1$s SET translation_group = pairs.translation_group
                FROM pairs
                WHERE %1$s.id IN (pairs.fr_id, pairs.en_id)
                SQL,
            $table,
            $scopeSelect,
            $scopeGroupBy,
            $scopeJoin('en', 'fr'),
            $scopeJoin('fr_unique', 'fr'),
            $scopeJoin('en_unique', 'en'),
        ));

        // Tout le reste : une ligne seule dans son groupe, un groupe par ligne.
        $this->addSql(sprintf('UPDATE %s SET translation_group = uuidv7() WHERE translation_group IS NULL', $table));

        $this->addSql(sprintf('ALTER TABLE %s ALTER translation_group SET NOT NULL', $table));
        $this->addSql(sprintf(
            'CREATE UNIQUE INDEX uniq_%1$s_translation_group_locale ON %1$s (translation_group, locale)',
            $table,
        ));
    }
}
