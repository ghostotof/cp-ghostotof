-- Issue #575 — création des rôles et transfert de la propriété.
--
-- Joué par le Job k8s/base/postgres-roles-job.yaml (psql, en tant que
-- superutilisateur de l'image, --single-transaction avec privileges.sql puis
-- verify.sql : tout ou rien), variables psql app_role, migrator_role,
-- reader_role. Rejouable : un rôle existant est conservé, un objet déjà
-- transféré est ignoré, et un mot de passe n'est écrit qu'à la création du
-- rôle — ou sur demande (rotation) : réécrire celui de cpg_app à chaque
-- passage couperait l'application jusqu'à la republication de son URL et au
-- redémarrage des pods (relecture de #575).
--
-- Les mots de passe sont lus dans le Secret monté par le Job, jamais passés
-- en argument : un `\set` par commande shell, psql ôte le saut de ligne final.
-- La rotation se demande par une clé `rotate` de plus dans ce Secret (un
-- fichier, vide suffit), pas par un argument.
\set app_pw `cat /run/secrets/postgres-roles/app-password`
\set migrator_pw `cat /run/secrets/postgres-roles/migrator-password`
\set rotate `test -e /run/secrets/postgres-roles/rotate && echo 1 || echo 0`
SELECT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_role') AS app_existed,
       EXISTS (SELECT FROM pg_roles WHERE rolname = :'migrator_role') AS migrator_existed,
       length(:'app_pw') >= 32 AND length(:'migrator_pw') >= 32 AS passwords_ok \gset

-- Un fichier absent ou mal nommé donne une variable vide, et `ALTER ROLE …
-- PASSWORD ''` poserait un mot de passe NULL sans bruit : refusé avant toute
-- écriture (une erreur, pour que psql sorte en 3 et annule la transaction).
\if :passwords_ok
\else
DO $$ BEGIN RAISE EXCEPTION '#575 : mots de passe absents ou trop courts dans /run/secrets/postgres-roles (48 caractères attendus) : rien n''est écrit'; END $$;
\endif

-- Un ALTER ROLE … PASSWORD qui échouerait serait journalisé avec son texte
-- par le serveur (log_min_error_statement = error par défaut) : relevé au
-- niveau que rien n'atteint, pour la seule durée de cette session, et le
-- journal des instructions et des durées coupé de même, sans se fier aux
-- défauts de l'image. Le texte de l'instruction reste visible dans
-- pg_stat_activity le temps de son exécution (des millisecondes, par le
-- superutilisateur seul), et le mot de passe traverse le réseau du cluster
-- en clair, une fois, entre le pod du Job et `database` (limite acceptée,
-- .claude/rules/deploiement.md).
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
--    en `trust` dans l'image), de nulle part par le réseau.
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
--    plus, verify.sql compris) ; l'attente est bornée par le lock_timeout du
--    Job, et un refus (55P03, ou 40P01 face au trafic) annule tout : relancer
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
