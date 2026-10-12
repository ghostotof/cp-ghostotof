<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #390 — la base refuse un groupe de traduction sur plusieurs positions.
 *
 * Spec 0004 D3 : toutes les entrées d'un groupe partagent sa position.
 * `ContentPlacement::inGroup()` ne contrôlait l'invariant qu'au rattachement
 * d'une langue à un groupe précis, ce que le backoffice ne propose presque
 * jamais. Ailleurs, rien ne le voyait : la lecture publique rangeait le contenu
 * à des rangs différents en FR et en EN, et le réordonnancement suivant
 * réparait l'écart sans trace, effaçant la preuve d'un défaut amont.
 *
 * Un trigger de contrainte **différé** par table (même nom sur les huit, un
 * nom de trigger étant propre à sa table), qui appelle une seule fonction
 * plpgsql. Au COMMIT, pour chaque ligne insérée, ou dont la position ou le
 * groupe a changé, elle relit **l'état courant** du groupe (min et max des
 * positions) et lève `integrity_constraint_violation` en nommant la table et
 * le groupe s'il porte plus d'une position. Lire l'état courant plutôt que
 * `NEW.position` évite un faux refus quand la même ligne est réécrite deux
 * fois dans la transaction. Un groupe vidé entre-temps rend NULL, donc rien.
 * L'index unique `(translation_group, locale)` sert la lecture.
 *
 * Différé, parce que la renumérotation (`OrderAssigner`) et Doctrine écrivent
 * les membres d'un groupe un `UPDATE` à la fois : l'écart transitoire entre
 * deux instructions est légitime. Le contrôle tourne avant le COMMIT, donc
 * encore sous le verrou de périmètre de l'application (#389, un advisory lock
 * de transaction). Deux transactions SQL concurrentes qui ne prennent pas ce
 * verrou peuvent encore se croiser : chacune relit le groupe sans voir
 * l'écriture non validée de l'autre. Limite acceptée, documentée dans la règle.
 *
 * Écartés : un `EXCLUDE USING gist (translation_group WITH =, position WITH <>)`
 * (extension `btree_gist`), sûr sous concurrence, mais dont l'index, que DBAL
 * introspecte sans que le mapping le déclare, ressortirait en `DROP INDEX` à
 * chaque `doctrine:migrations:diff` (DBAL ne lit ni triggers ni fonctions).
 * Un `CHECK` ne peut pas lire d'autres lignes.
 *
 * Les groupes déjà divergents sont d'abord alignés sur leur plus petite
 * position, chacun signalé en `warning` avec ses positions d'origine : sans
 * ce préalable, la première écriture qui toucherait l'un d'eux serait refusée.
 * L'ordre qui en résulte peut placer deux clés à égalité, ce que le prochain
 * réordonnancement au backoffice renumérote (`0…n-1`).
 *
 * Expand/contract (#175) : l'ancien code écrit déjà des groupes cohérents, il
 * ne voit pas la différence. Déploiement standard, sans fenêtre de maintenance.
 */
final class Version20261012120000 extends AbstractMigration
{
    /**
     * Les huit tables des contenus localisés et ordonnés (`TranslatableContent`).
     * Écrites en dur : une migration est un historique figé.
     * `TranslationGroupSinglePositionTest` épingle que chaque entité de ce type
     * porte le trigger.
     */
    private const array TABLES = [
        'about_me_card',
        'about_site_card',
        'anonymous_cv_section',
        'case_study',
        'contribution',
        'incident',
        'quality_principle',
        'quality_trait',
    ];

    private const string FUNCTION = 'portfolio_translation_group_single_position';

    private const string TRIGGER = 'trg_translation_group_single_position';

    public function getDescription(): string
    {
        return 'Refuse en base un groupe de traduction sur plusieurs positions (#390).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->reportDivergentGroups($table);
            $this->addSql(\sprintf(
                'UPDATE %1$s AS entry SET position = aligned.position'
                .' FROM (SELECT translation_group, MIN(position) AS position FROM %1$s GROUP BY translation_group HAVING MIN(position) <> MAX(position)) AS aligned'
                .' WHERE entry.translation_group = aligned.translation_group',
                $table,
            ));
        }

        $this->addSql(<<<'SQL'
            CREATE FUNCTION portfolio_translation_group_single_position() RETURNS trigger
            LANGUAGE plpgsql AS $body$
            DECLARE
                lowest integer;
                highest integer;
            BEGIN
                EXECUTE format('SELECT MIN(position), MAX(position) FROM %I.%I WHERE translation_group = $1', TG_TABLE_SCHEMA, TG_TABLE_NAME)
                    INTO lowest, highest
                    USING NEW.translation_group;

                IF lowest <> highest THEN
                    RAISE EXCEPTION 'Groupe de traduction % de la table % sur plusieurs positions (de % à %) : toutes ses entrées doivent partager sa position (spec 0004 D3, issue #390).',
                        NEW.translation_group, TG_TABLE_NAME, lowest, highest
                        USING ERRCODE = 'integrity_constraint_violation', CONSTRAINT = TG_NAME, TABLE = TG_TABLE_NAME;
                END IF;

                RETURN NULL;
            END
            $body$
            SQL);

        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf(
                'CREATE CONSTRAINT TRIGGER %s AFTER INSERT OR UPDATE OF position, translation_group ON %s'
                .' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION %s()',
                self::TRIGGER,
                $table,
                self::FUNCTION,
            ));
        }
    }

    /**
     * Les positions d'origine ne sont conservées nulle part ailleurs : chaque
     * groupe réaligné est signalé avant de l'être. `warnIf()` journalise en
     * `warning`, que la sortie du Job de migration affiche sans `-v` (même
     * procédé que #372).
     */
    private function reportDivergentGroups(string $table): void
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            "SELECT translation_group::text AS translation_group, string_agg(locale || ' ' || position, ', ' ORDER BY locale) AS positions"
            .' FROM %s GROUP BY translation_group HAVING MIN(position) <> MAX(position) ORDER BY translation_group',
            $table,
        ));

        foreach ($rows as $row) {
            $this->warnIf(true, \sprintf(
                'Table %s, groupe de traduction %s sur plusieurs positions (%s) : aligné sur la plus petite, à vérifier au backoffice (#390).',
                $table,
                $row['translation_group'],
                $row['positions'],
            ));
        }
    }

    /**
     * Retire les triggers et la fonction ; les groupes réalignés par `up()`
     * restent alignés, les positions d'origine n'étant plus connues.
     */
    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('DROP TRIGGER %s ON %s', self::TRIGGER, $table));
        }
        $this->addSql(\sprintf('DROP FUNCTION %s()', self::FUNCTION));
    }
}
