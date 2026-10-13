-- Issue #575 — création des rôles et transfert de la propriété.
--
-- Joué par le Job k8s/base/postgres-roles-job.yaml (psql, en tant que
-- superutilisateur de l'image, --single-transaction avec privileges.sql puis
-- verify.sql : tout ou rien), variables psql app_role, migrator_role,
-- reader_role. Rejouable : un rôle existant est conservé, son mot de passe
-- réécrit (c'est aussi la rotation), un objet déjà transféré est ignoré.
--
-- Les mots de passe sont lus dans le Secret monté par le Job, jamais passés
-- en argument : un `\set` par commande shell, psql ôte le saut de ligne final.
\set app_pw `cat /run/secrets/postgres-roles/app-password`
\set migrator_pw `cat /run/secrets/postgres-roles/migrator-password`

-- Un ALTER ROLE … PASSWORD qui échouerait serait journalisé avec son texte
-- par le serveur (log_min_error_statement = error par défaut) : relevé au
-- niveau que rien n'atteint, pour la seule durée de cette session.
SET log_min_error_statement = panic;

-- 1. Les trois rôles, créés s'ils manquent. Aucun n'est superutilisateur ni
--    ne peut créer une base ou un rôle.
SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS', r)
FROM (VALUES (:'app_role'), (:'migrator_role'), (:'reader_role')) AS v(r)
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = r) \gexec

-- 2. Les mots de passe (vérificateurs SCRAM, password_encryption par défaut).
--    Le lecteur n'en a pas : joignable par le seul socket local du pod
--    (`local all all trust` de l'image), jamais par TCP.
SELECT format('ALTER ROLE %I PASSWORD %L', :'app_role', :'app_pw') \gexec
SELECT format('ALTER ROLE %I PASSWORD %L', :'migrator_role', :'migrator_pw') \gexec
SELECT format('ALTER ROLE %I PASSWORD NULL', :'reader_role') \gexec

-- 3. La propriété au rôle de migration : la base (ALTER DATABASE … SET
--    timezone de Version20261011120000), le schéma, puis chaque objet de
--    public. `REASSIGN OWNED BY` est refusé sur le rôle bootstrap de l'image
--    (« required by the database system »), d'où l'énumération. Un
--    ALTER TABLE … OWNER TO entraîne ses index, contraintes, triggers et
--    séquences d'identité ; chaque ALTER prend un verrou ACCESS EXCLUSIVE
--    bref, borné par le lock_timeout du Job.
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
SELECT format('ALTER %s %I.%I(%s) OWNER TO %I', CASE p.prokind WHEN 'p' THEN 'PROCEDURE' ELSE 'FUNCTION' END, n.nspname, p.proname, pg_get_function_identity_arguments(p.oid), :'migrator_role')
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public' AND p.prokind IN ('f', 'p') AND pg_get_userbyid(p.proowner) <> :'migrator_role'
ORDER BY p.proname \gexec
SELECT format('ALTER TYPE %I.%I OWNER TO %I', n.nspname, t.typname, :'migrator_role')
FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
WHERE n.nspname = 'public' AND t.typtype IN ('e', 'd', 'c') AND pg_get_userbyid(t.typowner) <> :'migrator_role'
  AND NOT EXISTS (SELECT FROM pg_class c WHERE c.reltype = t.oid)
ORDER BY t.typname \gexec
