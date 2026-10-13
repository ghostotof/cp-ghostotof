-- Issue #575 — contrôle, en fin de Job, de ce que roles.sql et privileges.sql
-- ont laissé : des noms de rôle (ceux du dépôt) et des compteurs, jamais un
-- mot de passe ni une donnée. Imprimé dans les journaux du Job, que
-- tools/run-job.sh recopie. Les valeurs attendues sont en commentaire de
-- chaque requête ; une ligne hors attente demande une lecture avant toute
-- bascule de DATABASE_URL (k8s/README.md, « Rôles PostgreSQL »).
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
  SELECT p.proowner FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public'
) AS objets
WHERE pg_get_userbyid(owner) <> :'migrator_role';

\echo '--- #575 : tables sans DML pour le rôle applicatif, migrations exclues (attendu : 0)'
SELECT count(*) AS tables_sans_dml
FROM information_schema.tables t
WHERE t.table_schema = 'public' AND t.table_type = 'BASE TABLE' AND t.table_name <> 'doctrine_migration_versions'
  AND NOT has_table_privilege(:'app_role', format('%I.%I', t.table_schema, t.table_name), 'SELECT,INSERT,UPDATE,DELETE');

\echo '--- #575 : ce que le rôle applicatif ne doit pas pouvoir (attendu : f, f, f)'
SELECT has_table_privilege(:'app_role', 'public.doctrine_migration_versions', 'SELECT') AS lit_les_migrations,
       has_schema_privilege(:'app_role', 'public', 'CREATE') AS cree_dans_public,
       has_table_privilege(:'app_role', 'public.contribution', 'TRUNCATE') AS vide_une_table;

\echo '--- #575 : privilèges par défaut attachés au rôle de migration (attendu : 2)'
SELECT count(*) AS privileges_par_defaut
FROM pg_default_acl d
WHERE pg_get_userbyid(d.defaclrole) = :'migrator_role' AND d.defaclnamespace = 'public'::regnamespace;

\echo '--- #575 : le lecteur lit cpg_user et n''y écrit pas (attendu : t, f)'
SELECT has_table_privilege(:'reader_role', 'public.cpg_user', 'SELECT') AS lit,
       has_table_privilege(:'reader_role', 'public.cpg_user', 'INSERT,UPDATE,DELETE') AS ecrit;
