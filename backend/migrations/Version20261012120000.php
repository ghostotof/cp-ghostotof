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
 * positions) et lève le SQLSTATE `23T90` (classe 23, violation d'intégrité ;
 * propre au projet, pour que l'application le reconnaisse — depuis #506,
 * `TranslationGroupRefusal` —
 * sans lire le message) en nommant la table et le groupe s'il porte plus
 * d'une position. Lire l'état courant plutôt que
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
 * Coût : au COMMIT, une requête indexée par ligne écrite. Un réordonnancement
 * de N paires FR/EN en lance 2N (chaque groupe lu une fois par membre), sous
 * le verrou de périmètre ; négligeable pour des tables de quelques dizaines de
 * lignes, à mesurer si un périmètre devait en compter des milliers.
 *
 * Piège pour les migrations à venir : un trigger différé laisse des
 * événements en attente jusqu'au COMMIT, et PostgreSQL refuse un `ALTER TABLE`
 * sur une table qui en a (« pending trigger events », 55006). Une migration
 * qui écrit dans l'une de ces tables puis la modifie commence par
 * `SET CONSTRAINTS trg_translation_group_single_position IMMEDIATE`.
 *
 * Les groupes déjà divergents sont signalés en `warning` avec leurs positions
 * d'origine, puis leur périmètre est **renuméroté** `0…n-1`, dans l'ordre des
 * plus petites positions, le groupe départageant les égalités : sans ce
 * préalable, la première écriture qui toucherait l'un d'eux serait refusée.
 * Aligner sur la plus petite sans renuméroter laissait deux groupes à égalité,
 * que les dépôts départagent par l'id de chaque ligne, donc FR et EN dans deux
 * ordres. Une table sans groupe divergent n'est pas touchée. Les cartes « moi »
 * s'ordonnent par catégorie, et se renumérotent par catégorie ; un groupe à
 * cheval sur deux catégories, que l'application interdit, est laissé hors de
 * la renumérotation (il y recevrait deux rangs) et signalé : le
 * réordonnancement de l'une de ses catégories serait refusé tant qu'il n'est
 * pas corrigé au backoffice.
 *
 * Expand/contract (#175) : l'ancien code écrit déjà des groupes cohérents, il
 * ne voit pas la différence. Déploiement standard, sans fenêtre de maintenance.
 */
final class Version20261012120000 extends AbstractMigration
{
    /**
     * Les huit tables des contenus localisés et ordonnés (`TranslatableContent`),
     * chacune avec la colonne qui découpe son ordre en périmètres, ou null si
     * la table entière n'en forme qu'un. Écrites en dur : une migration est un
     * historique figé. `TranslationGroupSinglePositionTest` épingle que chaque
     * entité de ce type porte le trigger.
     */
    private const array TABLES = [
        'about_me_card' => 'category',
        'about_site_card' => null,
        'anonymous_cv_section' => null,
        'case_study' => null,
        'contribution' => null,
        'incident' => null,
        'quality_principle' => null,
        'quality_trait' => null,
    ];

    private const string FUNCTION = 'portfolio_translation_group_single_position';

    private const string TRIGGER = 'trg_translation_group_single_position';

    public function getDescription(): string
    {
        return 'Refuse en base un groupe de traduction sur plusieurs positions (#390).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $scopeColumn) {
            if (null !== $scopeColumn) {
                $this->reportGroupsAcrossScopes($table, $scopeColumn);
            }
            if ($this->reportAndCountDivergentGroups($table) > 0) {
                $this->addSql($this->renumberingSql($table, $scopeColumn));
            }
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
                        USING ERRCODE = '23T90', CONSTRAINT = TG_NAME, TABLE = TG_TABLE_NAME;
                END IF;

                RETURN NULL;
            END
            $body$
            SQL);

        foreach (array_keys(self::TABLES) as $table) {
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
     * procédé que #372). Lu au moment où la migration est planifiée, juste
     * avant l'exécution de son SQL dans la même transaction.
     *
     * @return int le nombre de groupes divergents
     */
    private function reportAndCountDivergentGroups(string $table): int
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            "SELECT translation_group::text AS translation_group, string_agg(locale || ' ' || position, ', ' ORDER BY locale) AS positions"
            .' FROM %s GROUP BY translation_group HAVING MIN(position) <> MAX(position) ORDER BY translation_group',
            $table,
        ));

        foreach ($rows as $row) {
            $this->warnIf(true, \sprintf(
                'Table %s, groupe de traduction %s sur plusieurs positions (%s) : réaligné, son périmètre renuméroté, à vérifier au backoffice (#390).',
                $table,
                $row['translation_group'],
                $row['positions'],
            ));
        }

        return \count($rows);
    }

    /**
     * Un groupe dont les membres sont rangés dans deux périmètres (deux
     * catégories de cartes « moi ») ne se réaligne pas en renumérotant chacun :
     * il est signalé, à corriger au backoffice.
     */
    private function reportGroupsAcrossScopes(string $table, string $scopeColumn): void
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            "SELECT translation_group::text AS translation_group, string_agg(DISTINCT %2\$s, ', ' ORDER BY %2\$s) AS scopes"
            .' FROM %1$s GROUP BY translation_group HAVING COUNT(DISTINCT %2$s) > 1 ORDER BY translation_group',
            $table,
            $scopeColumn,
        ));

        foreach ($rows as $row) {
            $this->warnIf(true, \sprintf(
                'Table %s, groupe de traduction %s rangé dans plusieurs %s (%s) : le réordonnancement de chacune sera refusé tant que le groupe n\'est pas corrigé au backoffice (#390).',
                $table,
                $row['translation_group'],
                $scopeColumn,
                $row['scopes'],
            ));
        }
    }

    /**
     * Renumérote `0…n-1` chaque périmètre de la table, groupe par groupe, dans
     * l'ordre de leur plus petite position, le groupe départageant les
     * égalités : un même ordre pour toutes les langues.
     */
    private function renumberingSql(string $table, ?string $scopeColumn): string
    {
        if (null === $scopeColumn) {
            return \sprintf(
                'UPDATE %1$s AS entry SET position = ranked.position'
                .' FROM (SELECT translation_group, ROW_NUMBER() OVER (ORDER BY MIN(position), translation_group) - 1 AS position'
                .' FROM %1$s GROUP BY translation_group) AS ranked'
                .' WHERE entry.translation_group = ranked.translation_group',
                $table,
            );
        }

        // Un groupe à cheval sur deux périmètres y recevrait deux rangs : la
        // renumérotation créerait la divergence qu'elle répare. Il garde ses
        // positions, et il est signalé (reportGroupsAcrossScopes()).
        return \sprintf(
            'UPDATE %1$s AS entry SET position = ranked.position'
            .' FROM (SELECT translation_group, %2$s, ROW_NUMBER() OVER (PARTITION BY %2$s ORDER BY MIN(position), translation_group) - 1 AS position'
            .' FROM %1$s WHERE translation_group NOT IN (SELECT translation_group FROM %1$s GROUP BY translation_group HAVING COUNT(DISTINCT %2$s) > 1)'
            .' GROUP BY %2$s, translation_group) AS ranked'
            .' WHERE entry.translation_group = ranked.translation_group AND entry.%2$s = ranked.%2$s',
            $table,
            $scopeColumn,
        );
    }

    /**
     * Retire les triggers et la fonction ; les groupes réalignés par `up()`
     * restent alignés, les positions d'origine n'étant plus connues.
     */
    public function down(Schema $schema): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $this->addSql(\sprintf('DROP TRIGGER %s ON %s', self::TRIGGER, $table));
        }
        $this->addSql(\sprintf('DROP FUNCTION %s()', self::FUNCTION));
    }
}
