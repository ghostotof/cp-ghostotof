<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #501 — la base refuse un groupe de traduction de cartes « moi » rangé dans
 * plusieurs catégories.
 *
 * Les cartes « moi » s'ordonnent par catégorie, et les membres d'un groupe
 * partagent la sienne : l'application le garantit (pas de setter de catégorie,
 * une version se crée dans la catégorie de son groupe), la base ne le gardait
 * pas. Le trigger différé de #390 (`Version20261012120000`) ne comparait que
 * les positions, catégories confondues, et n'écoutait pas `category` : une
 * version EN insérée en SQL dans une autre catégorie à la même position, ou un
 * `UPDATE` de la seule catégorie, passaient. Le groupe recevait alors deux
 * rangs, un par catégorie (FR et EN rangés différemment sur la page publique),
 * « Détacher » ne voyait que l'entrée (les membres sont lus dans la
 * catégorie), et le réordonnancement d'une catégorie était refusé sous le nom
 * trompeur des positions dès qu'il déplaçait le membre égaré.
 *
 * Même fonction, même nom de trigger : la fonction reçoit désormais en
 * argument (`TG_ARGV[0]`) la colonne qui découpe l'ordre de sa table en
 * périmètres, et une seule requête rend le min et le max des positions et le
 * nombre de périmètres du groupe (la liste des périmètres, pour le message,
 * n'est lue que sur un refus). Plus d'un périmètre lève le SQLSTATE `23T91`
 * (voisin du `23T90` des positions, même classe 23), contrôlé **avant** les
 * positions : un groupe à la fois réparti et sur deux positions sort sous le
 * nom de la cause, la position n'en étant que la conséquence. Les sept autres
 * tables n'ont qu'un périmètre et ne passent aucun argument : leur trigger
 * n'est pas touché, le `COUNT(DISTINCT NULL)` y vaut 0. Sur `about_me_card`, le
 * trigger est recréé avec l'argument et écoute aussi `category`.
 *
 * Un argument plutôt qu'une seconde fonction ou un second trigger : une seule
 * lecture du groupe par ligne écrite au COMMIT (le coût de #390 inchangé), un
 * seul nom à mettre en `SET CONSTRAINTS`, et `TranslationGroupSinglePositionTest`
 * épingle, table par table, l'argument et les colonnes écoutées.
 *
 * Les groupes déjà répartis — `Version20261012120000` les signalait déjà sans
 * les corriger — sont signalés de nouveau en `warning`, sans être corrigés :
 * on ne sait pas quelle catégorie est la bonne. Ils restent en base, mais toute
 * écriture qui les touche (insertion, changement de position, de groupe ou de
 * catégorie d'un membre) est désormais refusée. La correction passe par le
 * backoffice : supprimer l'entrée égarée (un `DELETE` ne réveille pas le
 * trigger), puis recréer sa version dans la bonne catégorie. « Détacher » ne
 * suffit pas : il ne voit pas les membres rangés dans l'autre catégorie.
 *
 * **Cette migration n'écrit aucune donnée, et doit le rester** : un
 * `DROP TRIGGER` puis `CREATE` alors que des écritures attendent leur contrôle
 * différé dans la même transaction annule silencieusement ces contrôles (mesuré
 * à l'occasion de #505). Chaque migration tourne dans sa propre transaction
 * (`all_or_nothing` n'est pas activé), et celle-ci ne fait que lire, puis
 * changer la fonction et le trigger : rien n'est en attente quand le trigger
 * tombe.
 *
 * Expand/contract (#175) : l'ancien code n'écrit que des groupes cohérents,
 * catégorie comprise. Déploiement standard, sans fenêtre de maintenance.
 */
final class Version20261012160000 extends AbstractMigration
{
    private const string TABLE = 'about_me_card';

    private const string SCOPE_COLUMN = 'category';

    private const string FUNCTION = 'portfolio_translation_group_single_position';

    private const string TRIGGER = 'trg_translation_group_single_position';

    public function getDescription(): string
    {
        return 'Refuse en base un groupe de traduction de cartes « moi » réparti sur plusieurs catégories (#501).';
    }

    public function up(Schema $schema): void
    {
        $this->reportGroupsAcrossScopes();

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION portfolio_translation_group_single_position() RETURNS trigger
            LANGUAGE plpgsql AS $body$
            DECLARE
                lowest integer;
                highest integer;
                scope_count integer;
                scopes text;
                -- La colonne de périmètre, passée en argument par le trigger
                -- (issue #501) ; sans argument, la table n'a qu'un périmètre et
                -- `NULL::text` ne compte aucune valeur distincte.
                scope_column text := CASE WHEN TG_NARGS > 0 THEN quote_ident(TG_ARGV[0]) ELSE 'NULL::text' END;
            BEGIN
                EXECUTE format(
                    'SELECT MIN(position), MAX(position), COUNT(DISTINCT %1$s) FROM %2$I.%3$I WHERE translation_group = $1',
                    scope_column, TG_TABLE_SCHEMA, TG_TABLE_NAME)
                    INTO lowest, highest, scope_count
                    USING NEW.translation_group;

                -- Avant les positions : chaque périmètre numérote ses propres
                -- rangs, un groupe réparti sur deux positions l'est par voie de
                -- conséquence. C'est la cause qu'on nomme. La liste des
                -- périmètres, pour le message, n'est lue que sur ce refus.
                IF scope_count > 1 THEN
                    EXECUTE format(
                        'SELECT string_agg(DISTINCT (%1$s)::text, '', '' ORDER BY (%1$s)::text) FROM %2$I.%3$I WHERE translation_group = $1',
                        scope_column, TG_TABLE_SCHEMA, TG_TABLE_NAME)
                        INTO scopes
                        USING NEW.translation_group;
                    RAISE EXCEPTION 'Groupe de traduction % de la table % réparti sur plusieurs valeurs de % (%) : toutes ses entrées doivent partager son périmètre d''ordre (issue #501).',
                        NEW.translation_group, TG_TABLE_NAME, TG_ARGV[0], scopes
                        USING ERRCODE = '23T91', CONSTRAINT = TG_NAME, TABLE = TG_TABLE_NAME, COLUMN = TG_ARGV[0];
                END IF;

                IF lowest <> highest THEN
                    RAISE EXCEPTION 'Groupe de traduction % de la table % sur plusieurs positions (de % à %) : toutes ses entrées doivent partager sa position (spec 0004 D3, issue #390).',
                        NEW.translation_group, TG_TABLE_NAME, lowest, highest
                        USING ERRCODE = '23T90', CONSTRAINT = TG_NAME, TABLE = TG_TABLE_NAME;
                END IF;

                RETURN NULL;
            END
            $body$
            SQL);

        $this->addSql(\sprintf('DROP TRIGGER %s ON %s', self::TRIGGER, self::TABLE));
        $this->addSql(\sprintf(
            "CREATE CONSTRAINT TRIGGER %s AFTER INSERT OR UPDATE OF position, translation_group, %s ON %s"
            ." DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION %s('%s')",
            self::TRIGGER,
            self::SCOPE_COLUMN,
            self::TABLE,
            self::FUNCTION,
            self::SCOPE_COLUMN,
        ));
    }

    /**
     * Les groupes répartis existants ne sont pas corrigés (quelle catégorie
     * serait la bonne ?) : chacun est signalé en `warning`, que la sortie du
     * Job de migration affiche sans `-v`, avec ses catégories. Lu au moment où
     * la migration est planifiée, juste avant l'exécution de son SQL dans la
     * même transaction.
     */
    private function reportGroupsAcrossScopes(): void
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            "SELECT translation_group::text AS translation_group, string_agg(DISTINCT %2\$s, ', ' ORDER BY %2\$s) AS scopes"
            .' FROM %1$s GROUP BY translation_group HAVING COUNT(DISTINCT %2$s) > 1 ORDER BY translation_group',
            self::TABLE,
            self::SCOPE_COLUMN,
        ));

        foreach ($rows as $row) {
            $this->warnIf(true, \sprintf(
                'Table %s, groupe de traduction %s réparti sur plusieurs valeurs de %s (%s) : non corrigé, toute écriture qui le touche est désormais refusée (23T91) ; au backoffice, supprimer l\'entrée égarée puis recréer sa version dans la bonne catégorie (#501).',
                self::TABLE,
                $row['translation_group'],
                self::SCOPE_COLUMN,
                $row['scopes'],
            ));
        }
    }

    /**
     * Rend la fonction et le trigger de `Version20261012120000` : positions
     * seules, `category` non écoutée. N'écrit aucune donnée non plus.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION portfolio_translation_group_single_position() RETURNS trigger
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

        $this->addSql(\sprintf('DROP TRIGGER %s ON %s', self::TRIGGER, self::TABLE));
        $this->addSql(\sprintf(
            'CREATE CONSTRAINT TRIGGER %s AFTER INSERT OR UPDATE OF position, translation_group ON %s'
            .' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION %s()',
            self::TRIGGER,
            self::TABLE,
            self::FUNCTION,
        ));
    }
}
