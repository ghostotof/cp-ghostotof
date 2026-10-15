-- Issue #575 — contrôle, en fin de procédure, de ce que roles.sql et
-- privileges.sql ont laissé : des noms de rôle (ceux du dépôt) et des
-- compteurs, jamais un mot de passe ni une donnée. Imprimé sur le terminal de
-- l'opérateur (l'exec de la procédure, #593), PUIS vérifié : une valeur hors
-- attente lève une erreur, psql sort en 3 avant commit.sql et la session
-- close annule tout — la procédure ne peut pas réussir sur un état faux
-- (relecture de #575). Sa dernière instruction pose `verify_passed`, sans
-- quoi commit.sql ne valide pas. Les valeurs attendues sont en commentaire de
-- chaque requête. Les tables sont désignées par
-- to_regclass : absentes (base neuve, avant la première migration), la
-- colonne est NULL et le contrôle ne porte pas dessus.
--
-- Issue #633 : le garde du chemin de recherche de roles.sql, à l'identique,
-- avant la première requête — chacune ci-dessous résout ses noms par lui.
DO $$
BEGIN
    IF pg_catalog.replace(pg_catalog.current_setting('search_path'), ' ', '') OPERATOR(pg_catalog.<>) 'pg_catalog,pg_temp' THEN
        RAISE EXCEPTION '#633 : le chemin de recherche de la session n''est plus celui de la procédure (pg_catalog, pg_temp)';
    END IF;
END
$$;
SELECT set_config('cpg.app_role', :'app_role', true),
       set_config('cpg.migrator_role', :'migrator_role', true),
       set_config('cpg.reader_role', :'reader_role', true);

\echo '--- #575 : rôles (attendu : trois lignes, rolsuper f partout, lecteur sans mot de passe)'
SELECT r.rolname, r.rolsuper, r.rolcanlogin, r.rolcreaterole, r.rolcreatedb,
       (SELECT a.rolpassword IS NOT NULL FROM pg_authid a WHERE a.oid = r.oid) AS a_un_mot_de_passe
FROM pg_roles r
WHERE r.rolname IN (:'app_role', :'migrator_role', :'reader_role')
ORDER BY r.rolname;

\echo '--- #575 : propriétaires (attendu : le rôle de migration, deux fois)'
SELECT pg_get_userbyid(datdba) AS proprietaire_de_la_base FROM pg_database WHERE datname = current_database();
SELECT pg_get_userbyid(nspowner) AS proprietaire_du_schema_public FROM pg_namespace WHERE nspname = 'public';

\echo '--- #575 : objets de public qui ne sont pas au rôle de migration (attendu : 0)'
SELECT count(*) AS objets_hors_migration
FROM (
  SELECT c.relowner AS owner FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
  WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'i')
  UNION ALL
  SELECT p.proowner FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
  WHERE n.nspname = 'public' AND p.prokind IN ('f', 'p', 'a', 'w')
  UNION ALL
  SELECT t.typowner FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
  WHERE n.nspname = 'public' AND t.typtype IN ('e', 'd', 'c', 'r')
    AND NOT EXISTS (SELECT FROM pg_class c WHERE c.reltype = t.oid)
) AS objets
WHERE pg_get_userbyid(owner) <> :'migrator_role';

\echo '--- #575 : tables sans DML pour le rôle applicatif, migrations exclues (attendu : 0)'
SELECT count(*) AS tables_sans_dml
FROM information_schema.tables t
WHERE t.table_schema = 'public' AND t.table_type = 'BASE TABLE' AND t.table_name <> 'doctrine_migration_versions'
  AND NOT has_table_privilege(:'app_role', format('%I.%I', t.table_schema, t.table_name), 'SELECT,INSERT,UPDATE,DELETE');

\echo '--- #575 : ce que le rôle applicatif ne doit pas pouvoir (attendu : f, f, f ; NULL si la table n''existe pas encore)'
SELECT has_table_privilege(:'app_role', to_regclass('public.doctrine_migration_versions'), 'SELECT') AS lit_les_migrations,
       has_schema_privilege(:'app_role', 'public', 'CREATE') AS cree_dans_public,
       has_table_privilege(:'app_role', to_regclass('public.contribution'), 'TRUNCATE') AS vide_une_table;

\echo '--- #575 : privilèges par défaut attachés au rôle de migration (attendu : 2)'
SELECT count(*) AS privileges_par_defaut
FROM pg_default_acl d
WHERE pg_get_userbyid(d.defaclrole) = :'migrator_role' AND d.defaclnamespace = 'public'::regnamespace;

\echo '--- #575 : le lecteur lit cpg_user et n''y écrit pas (attendu : t, f ; NULL si la table n''existe pas encore)'
SELECT has_table_privilege(:'reader_role', to_regclass('public.cpg_user'), 'SELECT') AS lit,
       has_table_privilege(:'reader_role', to_regclass('public.cpg_user'), 'INSERT,UPDATE,DELETE') AS ecrit;

\echo '--- #593 : pg_hba.conf actif (attendu : le chemin des args de postgres.yaml, 0, 0, reject)'
SELECT current_setting('hba_file') AS fichier_hba,
       (SELECT count(*) FROM pg_hba_file_rules WHERE error IS NOT NULL) AS regles_en_erreur,
       (SELECT count(*) FROM pg_hba_file_rules WHERE auth_method = 'trust' AND type <> 'local' AND NOT (user_name = ARRAY[:'reader_role'] AND address IN ('127.0.0.1', '::1'))) AS confiance_hors_socket_et_lecteur,
       (SELECT auth_method FROM pg_hba_file_rules ORDER BY rule_number DESC LIMIT 1) AS derniere_regle;

\echo '--- #597 : bases de l''image fermées (attendu : t, f, 0, 0)'
SELECT EXISTS (
         SELECT FROM pg_hba_file_rules
         WHERE type = 'host' AND database @> ARRAY['postgres', 'template0', 'template1'] AND user_name = ARRAY['all'] AND address = 'all' AND auth_method = 'reject'
           AND rule_number < (SELECT min(rule_number) FROM pg_hba_file_rules WHERE type <> 'local' AND auth_method <> 'reject')
       ) AS refus_avant_toute_acceptation,
       current_database() IN ('postgres', 'template0', 'template1') AS base_au_nom_reserve,
       (SELECT count(*) FROM pg_database WHERE datname NOT IN (current_database(), 'postgres', 'template0', 'template1')) AS bases_en_trop,
       (SELECT count(*) FROM unnest(ARRAY['postgres', 'template1']) AS d WHERE has_database_privilege('public', d, 'CONNECT') OR has_database_privilege('public', d, 'TEMPORARY')) AS bases_ouvertes_a_public;

\echo '--- #608 : base de l''application (attendu : {TimeZone}, vide avant la migration du fuseau ; 0, -1, f, 3)'
SELECT (SELECT array_agg(split_part(c, '=', 1) ORDER BY c COLLATE "C") FROM pg_db_role_setting, unnest(setconfig) AS c WHERE setdatabase = d.oid AND setrole = 0) AS reglages_de_la_base,
       (SELECT count(*) FROM pg_db_role_setting WHERE setdatabase = d.oid AND setrole <> 0) AS reglages_de_role_dans_la_base,
       d.datconnlimit AS limite_de_connexions,
       d.datistemplate AS modele,
       (SELECT count(*) FROM unnest(ARRAY[:'app_role', :'migrator_role', :'reader_role']) AS r WHERE has_database_privilege(r, current_database(), 'CONNECT')) AS roles_avec_connect
FROM pg_database d WHERE d.datname = current_database();

\echo '--- #575 : vérification (une erreur ici annule toute la procédure)'
DO $$
DECLARE
    app text := current_setting('cpg.app_role');
    migrator text := current_setting('cpg.migrator_role');
    reader text := current_setting('cpg.reader_role');
    n integer;
BEGIN
    SELECT count(*) INTO n FROM pg_roles
    WHERE rolname IN (app, migrator, reader) AND NOT rolsuper AND rolcanlogin AND NOT rolcreaterole AND NOT rolcreatedb
      AND NOT rolreplication AND NOT rolbypassrls AND rolconfig IS NULL;
    IF n <> 3 THEN RAISE EXCEPTION '#575 : % rôle(s) conforme(s) sur 3 (LOGIN, sans superutilisateur, CREATEROLE, CREATEDB, REPLICATION, BYPASSRLS ni réglage de session)', n; END IF;
    SELECT count(*) INTO n FROM pg_auth_members m JOIN pg_roles r ON r.oid = m.member WHERE r.rolname IN (app, migrator);
    IF n <> 0 THEN RAISE EXCEPTION '#575 : % appartenance(s) de rôle pour % ou %, aucune attendue', n, app, migrator; END IF;
    IF (SELECT rolpassword IS NOT NULL FROM pg_authid WHERE rolname = reader) THEN RAISE EXCEPTION '#575 : le lecteur % a un mot de passe', reader; END IF;
    IF (SELECT rolpassword IS NULL FROM pg_authid WHERE rolname = app) THEN RAISE EXCEPTION '#575 : % n''a pas de mot de passe', app; END IF;
    IF (SELECT rolpassword IS NULL FROM pg_authid WHERE rolname = migrator) THEN RAISE EXCEPTION '#575 : % n''a pas de mot de passe', migrator; END IF;
    IF (SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = current_database()) <> migrator THEN RAISE EXCEPTION '#575 : la base n''appartient pas à %', migrator; END IF;
    IF (SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = 'public') <> migrator THEN RAISE EXCEPTION '#575 : le schéma public n''appartient pas à %', migrator; END IF;
    SELECT count(*) INTO n FROM (
        SELECT c.relowner AS owner FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace WHERE ns.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'i')
        UNION ALL
        SELECT p.proowner FROM pg_proc p JOIN pg_namespace ns ON ns.oid = p.pronamespace WHERE ns.nspname = 'public' AND p.prokind IN ('f', 'p', 'a', 'w')
        UNION ALL
        SELECT t.typowner FROM pg_type t JOIN pg_namespace ns ON ns.oid = t.typnamespace WHERE ns.nspname = 'public' AND t.typtype IN ('e', 'd', 'c', 'r') AND NOT EXISTS (SELECT FROM pg_class c WHERE c.reltype = t.oid)
    ) AS objets WHERE pg_get_userbyid(owner) <> migrator;
    IF n <> 0 THEN RAISE EXCEPTION '#575 : % objet(s) de public n''appartiennent pas à %', n, migrator; END IF;
    SELECT count(*) INTO n FROM information_schema.tables t
    WHERE t.table_schema = 'public' AND t.table_type = 'BASE TABLE' AND t.table_name <> 'doctrine_migration_versions'
      AND NOT has_table_privilege(app, format('%I.%I', t.table_schema, t.table_name), 'SELECT,INSERT,UPDATE,DELETE');
    IF n <> 0 THEN RAISE EXCEPTION '#575 : % table(s) sans DML pour %', n, app; END IF;
    IF has_table_privilege(app, to_regclass('public.doctrine_migration_versions'), 'SELECT') THEN RAISE EXCEPTION '#575 : % lit la table des migrations', app; END IF;
    IF has_schema_privilege(app, 'public', 'CREATE') THEN RAISE EXCEPTION '#575 : % peut créer dans public', app; END IF;
    IF has_table_privilege(app, to_regclass('public.contribution'), 'TRUNCATE') THEN RAISE EXCEPTION '#575 : % peut vider une table', app; END IF;
    SELECT count(*) INTO n FROM pg_default_acl d WHERE pg_get_userbyid(d.defaclrole) = migrator AND d.defaclnamespace = 'public'::regnamespace;
    IF n <> 2 THEN RAISE EXCEPTION '#575 : % privilège(s) par défaut pour %, 2 attendus', n, migrator; END IF;
    IF has_table_privilege(reader, to_regclass('public.cpg_user'), 'INSERT,UPDATE,DELETE') THEN RAISE EXCEPTION '#575 : % écrit dans cpg_user', reader; END IF;
    IF NOT pg_has_role(reader, 'pg_read_all_data', 'MEMBER') THEN RAISE EXCEPTION '#575 : % n''est pas membre de pg_read_all_data', reader; END IF;
    -- #593 : le serveur lit le pg_hba.conf du dépôt (args de postgres.yaml),
    -- sans règle en erreur, sans confiance hors du socket et du lecteur sur le
    -- loopback (une règle `trust` du lecteur ailleurs l'ouvrirait au réseau), et sa
    -- dernière règle refuse. Seule preuve dans le cluster : un patch d'args
    -- ou un vieux manifeste ramènerait en silence le fichier de PGDATA.
    IF current_setting('hba_file') <> '/etc/postgresql/pg_hba.conf' THEN RAISE EXCEPTION '#593 : le serveur lit %, pas le pg_hba.conf du dépôt', current_setting('hba_file'); END IF;
    SELECT count(*) INTO n FROM pg_hba_file_rules WHERE error IS NOT NULL;
    IF n <> 0 THEN RAISE EXCEPTION '#593 : % règle(s) du pg_hba.conf en erreur', n; END IF;
    SELECT count(*) INTO n FROM pg_hba_file_rules WHERE auth_method = 'trust' AND type <> 'local' AND NOT (user_name = ARRAY[reader] AND address IN ('127.0.0.1', '::1'));
    IF n <> 0 THEN RAISE EXCEPTION '#593 : % règle(s) TCP en trust hors de % sur le loopback', n, reader; END IF;
    IF (SELECT auth_method FROM pg_hba_file_rules ORDER BY rule_number DESC LIMIT 1) <> 'reject' THEN RAISE EXCEPTION '#593 : la dernière règle du pg_hba.conf ne refuse pas'; END IF;
    -- #597 : les bases que l'image crée restent fermées. Le refus de pg_hba,
    -- qui les nomme, précède toute règle TCP qui accepte (un rollout undo de
    -- postgres ramènerait une ConfigMap sans lui) ; aucune autre base n'existe,
    -- qu'il ne couvrirait pas (une restauration sous le superutilisateur) ; la
    -- base de l'application ne porte pas un de leurs noms (le refus la
    -- couperait) ; PUBLIC n'y a plus CONNECT ni TEMPORARY (privileges.sql).
    IF NOT EXISTS (
        SELECT FROM pg_hba_file_rules
        WHERE type = 'host' AND database @> ARRAY['postgres', 'template0', 'template1'] AND user_name = ARRAY['all'] AND address = 'all' AND auth_method = 'reject'
          AND rule_number < (SELECT min(rule_number) FROM pg_hba_file_rules WHERE type <> 'local' AND auth_method <> 'reject')
    ) THEN RAISE EXCEPTION '#597 : le pg_hba.conf ne refuse pas les bases de l''image avant toute règle TCP qui accepte'; END IF;
    IF current_database() IN ('postgres', 'template0', 'template1') THEN RAISE EXCEPTION '#597 : la base de l''application porte le nom d''une base de l''image (%)', current_database(); END IF;
    SELECT count(*) INTO n FROM pg_database WHERE datname NOT IN (current_database(), 'postgres', 'template0', 'template1');
    IF n <> 0 THEN RAISE EXCEPTION '#597 : % base(s) hors de celle de l''application et de celles de l''image', n; END IF;
    SELECT count(*) INTO n FROM unnest(ARRAY['postgres', 'template1']) AS d WHERE has_database_privilege('public', d, 'CONNECT') OR has_database_privilege('public', d, 'TEMPORARY');
    IF n <> 0 THEN RAISE EXCEPTION '#597 : PUBLIC a encore CONNECT ou TEMPORARY sur % base(s) de l''image', n; END IF;
END
$$;

-- #608 : propriétaire de la base, le rôle de migration peut la rendre
-- inutilisable pour l'application sans être superutilisateur (mesuré sur
-- l'image épinglée) : un réglage de base, appliqué à chaque nouvelle session
-- (search_path, role, idle_session_timeout, default_transaction_read_only,
-- statement_timeout…), CONNECTION LIMIT, IS_TEMPLATE, REVOKE CONNECT. Et tout
-- rôle pose sur lui-même un réglage propre à la base (ALTER ROLE … IN
-- DATABASE … SET), que le RESET ALL de roles.sql et rolconfig ne voient pas.
-- Seul réglage de base admis : le fuseau, une fois Version20261011120000
-- passée — sa ligne, pas l'existence de la table, qu'une base restaurée
-- d'avant elle porte sans le fuseau ; lue par roles.sql (étape 4) sous le rôle
-- de migration, jamais ici sous le superutilisateur : la table est à lui, et
-- la lire exécuterait ce qu'il y pose (revue de #610) —, à la valeur que la migration écrit et
-- que date.timezone des images porte (PostgresRolesProcedureTest lie les
-- trois). Les valeurs, choisies par qui tient le rôle de migration, ne sont
-- jamais imprimées : les noms seulement. Laissés passer : REVOKE TEMPORARY
-- (l'application n'a pas de table temporaire) et ALLOW_CONNECTIONS false,
-- refusé sur la base courante et qui rendrait ce contrôle injoignable. Les
-- options de connexion de la procédure (PGOPTIONS) écartent ces réglages de
-- sa propre session : celui qui l'aurait arrêtée arrive ici. L'étape 4 de
-- roles.sql répare chacun de ces écarts plus tôt dans la transaction (#610) :
-- ce bloc reste le contrôle, et un refus ici est un défaut de la réparation.
-- Du SQL pur, rejoué sur la base de test par DatabaseOwnerSettingsVerificationTest.
\echo '--- #608 : vérification'
DO $$
DECLARE
    app text := current_setting('cpg.app_role');
    migrator text := current_setting('cpg.migrator_role');
    reader text := current_setting('cpg.reader_role');
    base oid := (SELECT oid FROM pg_database WHERE datname = current_database());
    reglages text[] := (SELECT setconfig FROM pg_db_role_setting WHERE setdatabase = base AND setrole = 0);
    noms text[] := (SELECT array_agg(split_part(c, '=', 1) ORDER BY c COLLATE "C") FROM unnest(reglages) AS c);
    migree boolean := current_setting('cpg.fuseau_migre')::boolean;
    attendus text[];
    n integer;
BEGIN
    attendus := CASE WHEN migree THEN ARRAY['TimeZone'] END;
    IF noms IS DISTINCT FROM attendus THEN RAISE EXCEPTION '#608 : la base porte les réglages %, % attendu (ALTER DATABASE … SET)', noms, attendus; END IF;
    IF migree AND reglages IS DISTINCT FROM ARRAY['TimeZone=Europe/Paris'] THEN RAISE EXCEPTION '#608 : le fuseau de la base n''est pas Europe/Paris (ALTER DATABASE … SET timezone)'; END IF;
    SELECT count(*) INTO n FROM pg_db_role_setting WHERE setdatabase = base AND setrole <> 0;
    IF n <> 0 THEN RAISE EXCEPTION '#608 : % réglage(s) de rôle propre(s) à la base (ALTER ROLE … IN DATABASE … SET)', n; END IF;
    IF (SELECT datconnlimit <> -1 FROM pg_database WHERE oid = base) THEN RAISE EXCEPTION '#608 : la base limite ses connexions (CONNECTION LIMIT)'; END IF;
    IF (SELECT datistemplate FROM pg_database WHERE oid = base) THEN RAISE EXCEPTION '#608 : la base est marquée modèle (IS_TEMPLATE)'; END IF;
    SELECT count(*) INTO n FROM unnest(ARRAY[app, migrator, reader]) AS r WHERE NOT has_database_privilege(r, current_database(), 'CONNECT');
    IF n <> 0 THEN RAISE EXCEPTION '#608 : % rôle(s) sur 3 sans CONNECT sur la base', n; END IF;
END
$$;

-- #611 : propriétaire de la base et de chaque objet de public, le rôle de
-- migration peut vider le DML de l'application SANS ERREUR. Mesuré sur l'image
-- épinglée, sous des rôles de la forme de privileges.sql : la sécurité au
-- niveau des lignes sans politique (le rôle applicatif, sans BYPASSRLS, ne lit
-- plus rien, ses UPDATE et DELETE ne touchent rien) ; un schéma au nom du rôle
-- applicatif, que le search_path par défaut ("$user", public) place devant
-- public ; une règle DO INSTEAD NOTHING ; un trigger qui annule (BEFORE) ou
-- efface (AFTER) la ligne ; un opérateur de public plus exact que celui du
-- catalogue (le schéma de la CVE-2018-1058 : `username = 'x'` ne trouve plus
-- rien). Une liste de gestes ne se ferme pas ; une liste d'admission, si : le
-- schéma doit être exactement celui que les migrations laissent.
-- Ce bloc lit une ligne canonique par objet hors du système — schémas ; dans
-- public, relations, colonnes, index, contraintes, triggers et règles avec
-- leur activation, politiques et leurs rôles, héritage, séquences,
-- publications, fonctions, opérateurs, types, transtypages et le reste du
-- catalogue —, sans propriétaire ni privilège (#575 les contrôle) ni colonne
-- supprimée, sous un search_path fixé (pg_get_*def qualifient un nom selon
-- lui), hache chacune (une ligne qui contient un saut de ligne ne peut en
-- imiter deux) et compare le md5 de l'ensemble aux empreintes admises pour
-- les migrations passées, lues par roles.sql (étape 4) sous le rôle de
-- migration : la dernière et leur nombre (une migration plus
-- ancienne fusionnée après une plus récente change le nombre, pas la
-- dernière). Mesuré : une base de dev migrée pas à pas depuis le début du
-- projet, une base neuve migrée sous un rôle non superutilisateur jusqu'à la
-- même version et une restauration pg_dump ont les mêmes lignes.
-- Toutes les empreintes sont gardées : un checkout en avance sur
-- l'environnement reconnaît encore ses migrations ; des migrations inconnues
-- (checkout en retard, base antérieure aux empreintes) sont refusées comme
-- telles. Plusieurs empreintes par migrations sont admises : une montée de
-- PostgreSQL qui change l'impression d'une définition en demande deux, le
-- temps que tous les environnements la passent. DatabaseOwnerSettingsVerificationTest
-- calcule, par la requête entre les marqueurs « empreinte », celle de la base
-- de test migrée : une migration nouvelle le rougit, et son message donne la
-- ligne à ajouter. La requête entre les marqueurs « lignes du schéma » est
-- recopiée à l'identique dans k8s/README.md, pour nommer les lignes qui
-- diffèrent ; ce bloc n'imprime que des empreintes, jamais un nom. Pas une
-- frontière contre qui tient le rôle de migration (il peut aussi effacer des
-- lignes) : un filet contre un geste persistant qui couperait l'application
-- en silence.
\echo '--- #611 : vérification'
DO $$
DECLARE
    derniere text := '';
    nombre bigint := 0;
    empreinte text;
    connues boolean;
    admise boolean;
BEGIN
    -- Ce qui change l'impression d'une définition, fixé ici plutôt que reçu de
    -- la session (issue #634) : le chemin qualifie les noms, le fuseau et le
    -- style de date un horodatage constant, le style d'intervalle un
    -- intervalle, extra_float_digits un flottant, bytea_output des octets,
    -- standard_conforming_strings un antislash (celui des empreintes
    -- ci-dessous compris : PL/pgSQL prépare chaque requête à sa première
    -- exécution, après ces réglages), quote_all_identifiers tout nom. La session de la procédure
    -- tourne en GMT (remise de #618), celle des tests en Europe/Paris : sans
    -- eux, une migration posant une telle constante dans un DEFAULT, un CHECK
    -- ou un prédicat d'index serait refusée à chaque passage. Les tests
    -- calculent les empreintes sous ces réglages, lus entre les marqueurs, et
    -- le runbook les pose avant sa copie de la requête des lignes. Posés pour
    -- la transaction, pas pour ce seul bloc : il est le dernier du fichier,
    -- et commit.sql ne lit rien qu'ils changent.
    -- #611 : réglages d'impression, début
    PERFORM set_config('search_path', 'pg_catalog, pg_temp', true),
            set_config('TimeZone', 'UTC', true),
            set_config('DateStyle', 'ISO, YMD', true),
            set_config('IntervalStyle', 'postgres', true),
            set_config('extra_float_digits', '1', true),
            set_config('bytea_output', 'hex', true),
            set_config('standard_conforming_strings', 'on', true),
            set_config('quote_all_identifiers', 'off', true);
    -- #611 : réglages d'impression, fin
    IF current_setting('cpg.migrations') <> '' THEN
        derniere := current_setting('cpg.derniere_migration');
        nombre := current_setting('cpg.migrations')::bigint;
        IF nombre = 0 THEN RAISE EXCEPTION '#611 : la table des migrations est vide (un premier migrate interrompu ?) : relancer la migration, puis la procédure'; END IF;
    END IF;
    empreinte := (
        -- #611 : empreinte, début
        SELECT md5(string_agg(md5(l), '' ORDER BY l COLLATE "C")) FROM (
            -- #611 : lignes du schéma, début
            SELECT 'schema ' || quote_ident(n.nspname) FROM pg_namespace n WHERE n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'
            UNION ALL
            SELECT concat_ws(' ', 'relation', quote_ident(c.relname), c.relkind, c.relpersistence, c.relrowsecurity, c.relforcerowsecurity, c.relispartition, c.relreplident, pg_get_expr(c.relpartbound, c.oid), c.reloptions::text, am.amname, ts.spcname)
            FROM pg_class c LEFT JOIN pg_am am ON am.oid = c.relam LEFT JOIN pg_tablespace ts ON ts.oid = c.reltablespace
            WHERE c.relnamespace = 'public'::regnamespace
            UNION ALL
            SELECT concat_ws(' ', 'colonne', quote_ident(c.relname), quote_ident(a.attname), format_type(a.atttypid, a.atttypmod), a.attnotnull, a.attidentity, a.attgenerated, pg_get_expr(d.adbin, d.adrelid), co.collname)
            FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_type ty ON ty.oid = a.atttypid
            LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
            LEFT JOIN pg_collation co ON co.oid = a.attcollation AND a.attcollation <> ty.typcollation
            WHERE c.relnamespace = 'public'::regnamespace AND c.relkind IN ('r', 'p', 'v', 'm', 'c', 'f') AND a.attnum > 0 AND NOT a.attisdropped
            UNION ALL
            SELECT 'index ' || pg_get_indexdef(i.indexrelid) FROM pg_index i JOIN pg_class c ON c.oid = i.indrelid WHERE c.relnamespace = 'public'::regnamespace
            UNION ALL
            SELECT concat_ws(' ', 'contrainte', quote_ident(coalesce(c.relname, t.typname)), quote_ident(k.conname), pg_get_constraintdef(k.oid))
            FROM pg_constraint k LEFT JOIN pg_class c ON c.oid = k.conrelid LEFT JOIN pg_type t ON t.oid = k.contypid
            WHERE k.connamespace = 'public'::regnamespace AND k.contype <> 'n'
            UNION ALL
            SELECT concat_ws(' ', 'trigger', pg_get_triggerdef(t.oid), t.tgenabled::text) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relnamespace = 'public'::regnamespace AND NOT t.tgisinternal
            UNION ALL
            SELECT concat_ws(' ', 'regle', pg_get_ruledef(r.oid), r.ev_enabled::text) FROM pg_rewrite r JOIN pg_class c ON c.oid = r.ev_class WHERE c.relnamespace = 'public'::regnamespace
            UNION ALL
            SELECT concat_ws(' ', 'politique', quote_ident(c.relname), quote_ident(p.polname), p.polcmd::text, p.polpermissive, pg_get_expr(p.polqual, p.polrelid), pg_get_expr(p.polwithcheck, p.polrelid),
                   (SELECT string_agg(r.nom, ',' ORDER BY r.nom) FROM (SELECT CASE WHEN x = 0 THEN 'public' ELSE quote_ident(pg_get_userbyid(x)) END AS nom FROM unnest(p.polroles) AS x) AS r))
            FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid WHERE c.relnamespace = 'public'::regnamespace
            UNION ALL
            SELECT concat_ws(' ', 'heritage', quote_ident(c.relname), h.inhparent::regclass::text) FROM pg_inherits h JOIN pg_class c ON c.oid = h.inhrelid WHERE c.relnamespace = 'public'::regnamespace
            UNION ALL
            SELECT concat_ws(' ', 'sequence', quote_ident(c.relname), format_type(s.seqtypid, NULL), s.seqstart, s.seqincrement, s.seqmin, s.seqmax, s.seqcache, s.seqcycle)
            FROM pg_sequence s JOIN pg_class c ON c.oid = s.seqrelid WHERE c.relnamespace = 'public'::regnamespace
            UNION ALL
            SELECT concat_ws(' ', 'publication', quote_ident(pb.pubname), quote_ident(c.relname), pg_get_expr(pr.prqual, pr.prrelid))
            FROM pg_publication_rel pr JOIN pg_publication pb ON pb.oid = pr.prpubid JOIN pg_class c ON c.oid = pr.prrelid WHERE c.relnamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'publication du schema ' || quote_ident(pb.pubname) FROM pg_publication_namespace pn JOIN pg_publication pb ON pb.oid = pn.pnpubid WHERE pn.pnnspid = 'public'::regnamespace
            UNION ALL
            SELECT concat_ws(' ', 'transtypage', format_type(k.castsource, NULL), format_type(k.casttarget, NULL), k.castfunc::regprocedure::text, k.castcontext::text, k.castmethod::text)
            FROM pg_cast k JOIN pg_type ts ON ts.oid = k.castsource JOIN pg_type tt ON tt.oid = k.casttarget
            WHERE ts.typnamespace = 'public'::regnamespace OR tt.typnamespace = 'public'::regnamespace
            UNION ALL
            SELECT CASE WHEN p.prokind = 'a' THEN 'agregat ' || p.oid::regprocedure::text ELSE 'fonction ' || pg_get_functiondef(p.oid) END FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace
            UNION ALL
            SELECT concat_ws(' ', 'operateur', o.oid::regoperator::text, o.oprcode::regprocedure::text) FROM pg_operator o WHERE o.oprnamespace = 'public'::regnamespace
            UNION ALL
            SELECT concat_ws(' ', 'type', quote_ident(t.typname), t.typtype, format_type(t.typbasetype, t.typtypmod), t.typnotnull, t.typdefault,
                   (SELECT string_agg(quote_literal(e.enumlabel), ',' ORDER BY e.enumsortorder) FROM pg_enum e WHERE e.enumtypid = t.oid))
            FROM pg_type t WHERE t.typnamespace = 'public'::regnamespace AND t.typrelid = 0 AND NOT (t.typcategory = 'A' AND t.typelem <> 0)
            UNION ALL
            SELECT 'classe ' || quote_ident(opcname) FROM pg_opclass WHERE opcnamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'famille ' || quote_ident(opfname) FROM pg_opfamily WHERE opfnamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'collation ' || quote_ident(collname) FROM pg_collation WHERE collnamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'conversion ' || quote_ident(conname) FROM pg_conversion WHERE connamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'recherche ' || quote_ident(cfgname) FROM pg_ts_config WHERE cfgnamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'dictionnaire ' || quote_ident(dictname) FROM pg_ts_dict WHERE dictnamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'analyseur ' || quote_ident(prsname) FROM pg_ts_parser WHERE prsnamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'modele ' || quote_ident(tmplname) FROM pg_ts_template WHERE tmplnamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'statistique ' || quote_ident(stxname) FROM pg_statistic_ext WHERE stxnamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'extension ' || quote_ident(extname) FROM pg_extension WHERE extnamespace = 'public'::regnamespace
            -- #611 : lignes du schéma, fin
        ) AS lignes(l)
        -- #611 : empreinte, fin
    );
    -- Dernière migration passée ('' : aucune), nombre de migrations passées,
    -- empreinte du schéma qu'elles laissent. Ajouter, ne jamais retirer.
    SELECT bool_or(true), bool_or(e = empreinte) INTO connues, admise FROM (VALUES
        ('', 0, '00c92ee65ecb7af3a7453bb7404b4e67'),
        ('DoctrineMigrations\Version20261012120000', 31, 'c221d88f9f1fb58395de06ebd3c560f2'),
        ('DoctrineMigrations\Version20261012160000', 32, 'cf3fa860c81a7d142a545f81179dd130')
    ) AS empreintes(v, n, e) WHERE v = derniere AND n = nombre;
    IF connues IS NULL THEN RAISE EXCEPTION '#611 : aucune empreinte de schéma connue pour ces migrations (% passées, empreinte %) : checkout en retard sur l''environnement, ou base antérieure aux empreintes', nombre, empreinte; END IF;
    IF NOT admise THEN RAISE EXCEPTION '#611 : le schéma de la base n''est pas celui des migrations (empreinte %)', empreinte; END IF;
END
$$;

\echo '--- #575 : conforme'
\set verify_passed 1
