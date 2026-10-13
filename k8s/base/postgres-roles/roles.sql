-- Issue #575 — création des rôles et transfert de la propriété.
--
-- Joué par la procédure « Rôles PostgreSQL » de k8s/README.md : un
-- `kubectl exec` dans le pod postgres, psql sur le socket local, en tant que
-- superutilisateur de l'image (issue #593 : le pg_hba.conf du dépôt lui ferme
-- le réseau), sur l'entrée standard avec privileges.sql puis verify.sql, entre
-- un BEGIN et un COMMIT que le flux de la procédure porte : tout ou rien, même
-- si le flux est coupé en route (aucun contrôle de transaction ici). Variables psql app_role, migrator_role,
-- reader_role (ligne de commande), app_pw, migrator_pw, rotate (`\set` en tête
-- de l'entrée standard). Rejouable : un rôle existant est conservé, un objet
-- déjà transféré est ignoré, et un mot de passe n'est écrit qu'à la création
-- du rôle — ou sur demande (rotate = 1) : réécrire celui de cpg_app à chaque
-- passage couperait l'application jusqu'à la republication de son URL et au
-- redémarrage des pods (relecture de #575).
--
-- Les mots de passe arrivent sur l'entrée standard, lus dans leurs fichiers
-- par `cat` sur le poste de l'opérateur : jamais un argument, ni de l'exec ni
-- d'un processus. Aucune commande shell n'est lancée dans le pod
-- (PostgresRolesProcedureTest). Une variable absente devient vide, et le
-- contrôle ci-dessous la refuse.
\if :{?app_pw}
\else
\set app_pw ''
\endif
\if :{?migrator_pw}
\else
\set migrator_pw ''
\endif
\if :{?rotate}
\else
\set rotate ''
\endif
SELECT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_role') AS app_existed,
       EXISTS (SELECT FROM pg_roles WHERE rolname = :'migrator_role') AS migrator_existed,
       length(:'app_pw') >= 32 AND length(:'migrator_pw') >= 32 AS passwords_ok,
       :'rotate' IN ('0', '1') AS rotate_ok \gset

-- Un fichier absent ou vide donne une variable vide, et `ALTER ROLE …
-- PASSWORD ''` poserait un mot de passe NULL sans bruit : refusé avant toute
-- écriture (une erreur, pour que psql sorte en 3 et annule la transaction).
-- La rotation se demande explicitement, 0 ou 1 : toute autre valeur est une
-- faute de frappe, refusée de même.
\if :passwords_ok
\else
DO $$ BEGIN RAISE EXCEPTION '#575 : mots de passe absents ou trop courts sur l''entrée standard (48 caractères attendus) : rien n''est écrit'; END $$;
\endif
\if :rotate_ok
\else
DO $$ BEGIN RAISE EXCEPTION '#593 : rotate vaut 0 ou 1 : rien n''est écrit'; END $$;
\endif

-- Un ALTER ROLE … PASSWORD qui échouerait serait journalisé avec son texte
-- par le serveur (log_min_error_statement = error par défaut) : relevé au
-- niveau que rien n'atteint, pour la seule durée de cette session, et le
-- journal des instructions et des durées coupé de même, sans se fier aux
-- défauts de l'image. Le texte de l'instruction reste visible dans
-- pg_stat_activity le temps de son exécution (des millisecondes, par le
-- superutilisateur seul). Le mot de passe ne traverse plus le réseau du
-- cluster (#593) : il entre dans le pod par le flux de l'exec (chiffré entre
-- le poste et l'API server, puis jusqu'au kubelet) et atteint le serveur par
-- le socket local.
SET log_min_error_statement = panic;
SET log_statement = none;
SET log_min_duration_statement = -1;

-- 1. Les trois rôles, créés s'ils manquent, puis RAMENÉS à leurs attributs à
--    chaque passage : un rôle qui existait déjà (ou qu'une injection aurait
--    altéré — ALTER ROLE sur soi-même est permis à tout rôle) perd tout
--    attribut de trop et tout réglage de session (`RESET ALL`). Aucun n'est
--    superutilisateur ni ne peut créer une base ou un rôle.
SELECT format('CREATE ROLE %I', r)
FROM (VALUES (:'app_role'), (:'migrator_role'), (:'reader_role')) AS v(r)
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = r) \gexec
SELECT format('ALTER ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT VALID UNTIL ''infinity''', r)
FROM (VALUES (:'app_role'), (:'migrator_role'), (:'reader_role')) AS v(r) \gexec
SELECT format('ALTER ROLE %I INHERIT', :'reader_role') \gexec
SELECT format('ALTER ROLE %I RESET ALL', r)
FROM (VALUES (:'app_role'), (:'migrator_role'), (:'reader_role')) AS v(r) \gexec

-- 2. Les mots de passe (vérificateurs SCRAM, password_encryption par défaut),
--    à la création ou sur rotation seulement. Le lecteur n'en a jamais :
--    joignable depuis l'intérieur du pod seulement (socket local et 127.0.0.1,
--    en `trust`), refusé par le réseau (k8s/base/postgres-pg_hba.conf, #593).
SELECT format('ALTER ROLE %I PASSWORD %L', :'app_role', :'app_pw') WHERE :'rotate' = '1' OR NOT :'app_existed'::boolean \gexec
SELECT format('ALTER ROLE %I PASSWORD %L', :'migrator_role', :'migrator_pw') WHERE :'rotate' = '1' OR NOT :'migrator_existed'::boolean \gexec
SELECT format('ALTER ROLE %I PASSWORD NULL', :'reader_role') \gexec

-- 3. La propriété au rôle de migration : la base (ALTER DATABASE … SET
--    timezone de Version20261011120000), le schéma, puis chaque objet de
--    public. `REASSIGN OWNED BY` est refusé sur le rôle bootstrap de l'image
--    (« required by the database system »), d'où l'énumération. Un
--    ALTER TABLE … OWNER TO entraîne ses index, contraintes, triggers et
--    séquences d'identité. Chaque ALTER prend un verrou ACCESS EXCLUSIVE,
--    tenu jusqu'au COMMIT de la transaction unique (quelques secondes au
--    plus, verify.sql compris) ; l'attente est bornée par le lock_timeout de
--    la procédure (PGOPTIONS), et un refus (55P03, ou 40P01 face au trafic) annule tout : relancer
--    hors déploiement, à une heure calme.
SELECT format('ALTER DATABASE %I OWNER TO %I', current_database(), :'migrator_role')
WHERE (SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = current_database()) <> :'migrator_role' \gexec
SELECT format('ALTER SCHEMA public OWNER TO %I', :'migrator_role')
WHERE (SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = 'public') <> :'migrator_role' \gexec
SELECT format('ALTER TABLE %I.%I OWNER TO %I', n.nspname, c.relname, :'migrator_role')
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND pg_get_userbyid(c.relowner) <> :'migrator_role'
ORDER BY c.relname \gexec
SELECT format('ALTER %s %I.%I OWNER TO %I', CASE c.relkind WHEN 'v' THEN 'VIEW' ELSE 'MATERIALIZED VIEW' END, n.nspname, c.relname, :'migrator_role')
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('v', 'm') AND pg_get_userbyid(c.relowner) <> :'migrator_role'
ORDER BY c.relname \gexec
-- Les séquences indépendantes seulement : celles d'une colonne ont suivi leur table.
SELECT format('ALTER SEQUENCE %I.%I OWNER TO %I', n.nspname, c.relname, :'migrator_role')
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind = 'S' AND pg_get_userbyid(c.relowner) <> :'migrator_role'
  AND NOT EXISTS (SELECT FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype IN ('a', 'i'))
ORDER BY c.relname \gexec
-- Fonctions, procédures, agrégats, fonctions de fenêtrage : les mêmes genres
-- que verify.sql compte.
SELECT format('ALTER %s %I.%I(%s) OWNER TO %I', CASE p.prokind WHEN 'p' THEN 'PROCEDURE' WHEN 'a' THEN 'AGGREGATE' ELSE 'FUNCTION' END, n.nspname, p.proname, pg_get_function_identity_arguments(p.oid), :'migrator_role')
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public' AND p.prokind IN ('f', 'p', 'a', 'w') AND pg_get_userbyid(p.proowner) <> :'migrator_role'
ORDER BY p.proname \gexec
-- Énumérations, domaines, types composés, types d'intervalle (le multirange
-- suit son intervalle) ; pas les types de ligne des tables, qui suivent la table.
SELECT format('ALTER TYPE %I.%I OWNER TO %I', n.nspname, t.typname, :'migrator_role')
FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
WHERE n.nspname = 'public' AND t.typtype IN ('e', 'd', 'c', 'r') AND pg_get_userbyid(t.typowner) <> :'migrator_role'
  AND NOT EXISTS (SELECT FROM pg_class c WHERE c.reltype = t.oid)
ORDER BY t.typname \gexec
