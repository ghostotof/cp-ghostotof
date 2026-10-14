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
-- d'avant elle porte sans le fuseau —, à la valeur que la migration écrit et
-- que date.timezone des images porte (PostgresRolesProcedureTest lie les
-- trois). Les valeurs, choisies par qui tient le rôle de migration, ne sont
-- jamais imprimées : les noms seulement. Laissés passer : REVOKE TEMPORARY
-- (l'application n'a pas de table temporaire) et ALLOW_CONNECTIONS false,
-- refusé sur la base courante et qui rendrait ce contrôle injoignable. Les
-- options de connexion de la procédure (PGOPTIONS) écartent ces réglages de
-- sa propre session : celui qui l'aurait arrêtée arrive ici. Du SQL pur,
-- rejoué sur la base de test par DatabaseOwnerSettingsVerificationTest.
\echo '--- #608 : vérification'
DO $$
DECLARE
    app text := current_setting('cpg.app_role');
    migrator text := current_setting('cpg.migrator_role');
    reader text := current_setting('cpg.reader_role');
    base oid := (SELECT oid FROM pg_database WHERE datname = current_database());
    reglages text[] := (SELECT setconfig FROM pg_db_role_setting WHERE setdatabase = base AND setrole = 0);
    noms text[] := (SELECT array_agg(split_part(c, '=', 1) ORDER BY c COLLATE "C") FROM unnest(reglages) AS c);
    migree boolean := false;
    attendus text[];
    n integer;
BEGIN
    IF to_regclass('public.doctrine_migration_versions') IS NOT NULL THEN
        EXECUTE 'SELECT EXISTS (SELECT FROM public.doctrine_migration_versions WHERE version = $1)' INTO migree USING 'DoctrineMigrations\Version20261011120000';
    END IF;
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
\echo '--- #575 : conforme'
\set verify_passed 1
