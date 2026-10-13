-- Issue #575 — contrôle, en fin de Job, de ce que roles.sql et privileges.sql
-- ont laissé : des noms de rôle (ceux du dépôt) et des compteurs, jamais un
-- mot de passe ni une donnée. Imprimé dans les journaux du Job, que
-- tools/run-job.sh recopie, PUIS vérifié : une valeur hors attente lève une
-- erreur, psql sort en 3 et --single-transaction annule tout — le Job ne peut
-- pas réussir sur un état faux (relecture de #575). Les valeurs attendues
-- sont en commentaire de chaque requête. Les tables sont désignées par
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

\echo '--- #575 : vérification (une erreur ici annule tout le Job)'
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
END
$$;
\echo '--- #575 : conforme'
