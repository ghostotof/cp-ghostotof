-- Issue #575 — création des rôles et transfert de la propriété ; depuis #610,
-- lecture des migrations sous le rôle de migration et remise en état de la
-- base de l'application (étapes 4 et 5).
--
-- Joué par la procédure « Rôles PostgreSQL » de k8s/README.md : un
-- `kubectl exec` dans le pod postgres, psql sur le socket local, en tant que
-- superutilisateur de l'image (issue #593 : le pg_hba.conf du dépôt lui ferme
-- le réseau), sur l'entrée standard avec privileges.sql, verify.sql puis
-- commit.sql, après un BEGIN que porte le flux : commit.sql ne valide que si
-- verify.sql est allé au bout, tout ou rien (aucun contrôle de transaction ici). Variables psql app_role, migrator_role,
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
-- contrôle ci-dessous la refuse. La marque de verify.sql est effacée : seule
-- sa dernière instruction, dans ce flux, peut la poser.
\unset verify_passed
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

-- Issue #618 : un réglage posé sur la base (ALTER DATABASE … SET, permis à
-- son propriétaire, le rôle de migration) ou sur un rôle dans la base
-- s'applique à cette session aussi. Les options de connexion (PGOPTIONS,
-- k8s/README.md) n'en écartent qu'une liste, celle des réglages qui agissent
-- avant toute instruction ; d'autres, mesurés sur l'image épinglée, changent
-- le sens de ce qui suit (quote_all_identifiers change l'empreinte `#611` :
-- la procédure échouait à chaque passage, sa réparation annulée avec elle),
-- ou trompent sans échouer (debug_print_parse écrit l'arbre de chaque
-- requête, mot de passe compris, dans le journal du serveur ; scram_iterations
-- affaiblit les vérificateurs écrits). La liste ne se ferme pas : tout réglage
-- hérité est rendu à sa valeur de démarrage, AVANT la première instruction qui
-- porte un mot de passe — posé pour tous les rôles (`ALTER ROLE ALL SET`,
-- source `global`, issue #634), sur la base, sur un rôle ou sur un rôle dans
-- la base. Ceux des options (source `client`) ne sont pas touchés. plpgsql
-- chargé d'abord, pour que ses réglages soient visibles. Du SQL simple, sans PL/pgSQL, sous les réglages encore hérités : ni littéral de
-- tableau, ni `= NULL`, ni antislash. Le CASE fixe l'ordre d'évaluation : seul
-- un réglage hérité est réécrit. Puis un refus s'il en reste un — un réglage
-- sans valeur de démarrage, que seule une option de connexion rend
-- (InheritedSessionSettingsTest les liste) : la procédure ne tourne pas sous
-- lui. Des noms, jamais une valeur. `(set_config(…) IS NULL) IS NOT NULL`
-- vaut toujours vrai (issue #634) : il appelle set_config pour son effet et
-- garde la ligne, dont le nom s'imprime ; aucune comparaison n'y est faite.
-- #618 : réglages hérités, début
LOAD 'plpgsql';
SELECT pg_catalog.array_agg(s.name ORDER BY s.name) AS "#618 : réglages hérités rendus à leur valeur de démarrage"
FROM pg_catalog.pg_settings AS s
WHERE CASE WHEN s.source IN ('global', 'database', 'user', 'database user') THEN (pg_catalog.set_config(s.name, s.boot_val, false) IS NULL) IS NOT NULL ELSE false END;
DO $$
DECLARE
    restants text[] := (SELECT pg_catalog.array_agg(name ORDER BY name) FROM pg_catalog.pg_settings WHERE source IN ('global', 'database', 'user', 'database user'));
BEGIN
    IF restants IS NOT NULL THEN
        RAISE EXCEPTION '#618 : réglages hérités non rendus : %', restants;
    END IF;
END
$$;
-- #618 : réglages hérités, fin

-- Un ALTER ROLE … PASSWORD qui échouerait serait journalisé avec son texte
-- par le serveur (log_min_error_statement = error par défaut) : relevé au
-- niveau que rien n'atteint, pour la seule durée de cette session, et le
-- journal des instructions et des durées coupé de même, sans se fier aux
-- défauts de l'image. AVANT la première requête qui porte un mot de passe, la
-- garde de longueur ci-dessous (relecture de #593). Le texte d'une instruction
-- reste visible dans pg_stat_activity le temps de son exécution (des
-- millisecondes, par le superutilisateur seul). Le mot de passe ne traverse
-- plus le réseau du cluster (#593) : il entre dans le pod par le flux de
-- l'exec (chiffré entre le poste et l'API server, puis jusqu'au kubelet) et
-- atteint le serveur par le socket local.
--
-- Une transaction laissée en attente (flux bloqué en route), le délai des
-- instructions, le rôle et le chemin de recherche de la session sont fixés
-- par les options de connexion de la procédure (PGOPTIONS, k8s/README.md) :
-- elles l'emportent dès la connexion sur un réglage de la base (#608).
SET log_min_error_statement = panic;
SET log_statement = none;
SET log_min_duration_statement = -1;
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
DO $$ BEGIN RAISE EXCEPTION '#575 : mots de passe absents ou trop courts sur l''entrée standard (32 caractères au moins, 48 générés par la procédure) : rien n''est écrit'; END $$;
\endif
\if :rotate_ok
\else
DO $$ BEGIN RAISE EXCEPTION '#593 : rotate vaut 0 ou 1 : rien n''est écrit'; END $$;
\endif

-- 1. Les trois rôles, créés s'ils manquent, puis RAMENÉS à leurs attributs à
--    chaque passage : un rôle qui existait déjà (ou qu'une injection aurait
--    altéré — ALTER ROLE sur soi-même est permis à tout rôle) perd tout
--    attribut de trop et tout réglage de session (`RESET ALL`). Aucun n'est
--    superutilisateur ni ne peut créer une base ou un rôle. `RESET ALL` ne
--    touche pas un réglage propre à la base (`ALTER ROLE … IN DATABASE …
--    SET`) : l'étape 5 le retire (#610).
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
-- Relecture de #593 : rejouée sans rotation, la procédure ignore les mots de
-- passe générés à l'étape 1 — les publier couperait l'application au
-- redémarrage suivant. Dit pour chacun s'il a été écrit, jamais sa valeur.
\echo '--- #593 : mots de passe écrits par ce passage (f : ne pas publier son URL)'
SELECT :'rotate' = '1' OR NOT :'app_existed'::boolean AS mot_de_passe_cpg_app_ecrit,
       :'rotate' = '1' OR NOT :'migrator_existed'::boolean AS mot_de_passe_cpg_migrator_ecrit;

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

-- 4. La table des migrations, lue SOUS LE RÔLE DE MIGRATION (revue de #610,
--    mesuré sur l'image épinglée). Elle lui appartient : il peut la
--    remplacer par une vue, ou donner à sa colonne un type de public et son
--    transtypage, dont la fonction s'exécutait sous le superutilisateur de
--    cette session à la moindre lecture — `SET LOCAL ROLE` n'y suffit pas, le
--    code lu fait `RESET ROLE`. D'où une fonction `SECURITY DEFINER` dont il
--    est le propriétaire : ce qu'il a posé tourne sous lui, avec ses seuls
--    droits, et le serveur y refuse tout changement de rôle ou de session.
--    Mais pas de réglage (issue #633, mesuré sur l'image épinglée) : un `SET`
--    ordinaire l'emporte sur la clause `SET` de la fonction et survit à sa
--    sortie — `public` placé devant le catalogue faisait exécuter une
--    fonction de `public`, homonyme d'une fonction du catalogue, sous le
--    superutilisateur dès l'étape 5 ; `client_min_messages` masquait ses avis.
--    D'où une sous-transaction, que la lecture annule elle-même par une
--    erreur sentinelle une fois les valeurs dans ses variables (PL/pgSQL ne
--    les annule pas) : tout réglage, tout objet que le code posé a créé ou
--    écrit, la fonction elle-même, disparaissent avec elle. Restent les
--    effets hors transaction (verrous consultatifs de session, valeurs de
--    séquence) et les avis qu'il envoie au terminal, jamais du code sous le
--    superutilisateur : la lecture est encadrée de deux marqueurs, écrits
--    sur la sortie d'erreur comme les avis (`\warn`, pas `\echo` : kubectl
--    n'ordonne pas deux flux entre eux), tout avis imprimé entre eux vient de
--    la table ; et bornée à 30 secondes (PGOPTIONS laisse statement_timeout
--    à 0), la borne retirée aussitôt après. La borne arrête une attente
--    naïve, pas le code qui attrape `query_canceled` et se rendort (mesuré) :
--    qui tient le rôle de migration peut bloquer l'API sans la procédure
--    (LOCK, DROP sur ses tables) ; la lecture ne tient de verrou ACCESS
--    EXCLUSIVE que si l'étape 3 a transféré un objet. Le code posé qui lève la sentinelle ne fait pas
--    passer une lecture inachevée pour faite. Ce qu'elle rend est rangé dans
--    des réglages de transaction que l'étape 5 et verify.sql relisent, sans
--    jamais lire la table eux-mêmes (ils refusent sans ces réglages), par des
--    noms qualifiés. Une table absente (base neuve) donne `cpg.migrations`
--    vide. Du SQL pur : rejoué par DatabaseOwnerSettingsVerificationTest.
SELECT set_config('cpg.app_role', :'app_role', true),
       set_config('cpg.migrator_role', :'migrator_role', true),
       set_config('cpg.reader_role', :'reader_role', true);
\warn '--- #633 : lecture des migrations, début (tout avis jusqu''à sa fin vient du code de la table, pas de la procédure)'
SET LOCAL statement_timeout = '30s';
DO $$
DECLARE
    lues record;
    lecture_faite boolean := false;
BEGIN
    <<lecture>>
    BEGIN
        EXECUTE $f$
            CREATE FUNCTION pg_temp.cpg_migrations(OUT presente boolean, OUT nombre bigint, OUT derniere text, OUT fuseau boolean)
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $corps$
            BEGIN
                presente := to_regclass('public.doctrine_migration_versions') IS NOT NULL;
                IF presente THEN
                    SELECT count(*), coalesce(max(version), ''), coalesce(bool_or(version = 'DoctrineMigrations\Version20261011120000'), false)
                    INTO nombre, derniere, fuseau
                    FROM public.doctrine_migration_versions;
                END IF;
            END
            $corps$
        $f$;
        EXECUTE format('ALTER FUNCTION pg_temp.cpg_migrations() OWNER TO %I', current_setting('cpg.migrator_role'));
        SELECT * INTO STRICT lues FROM pg_temp.cpg_migrations();
        lecture_faite := true;
        RAISE EXCEPTION USING ERRCODE = 'CP633';
    EXCEPTION WHEN SQLSTATE 'CP633' THEN
        NULL;
    END lecture;
    IF NOT lecture_faite THEN
        RAISE EXCEPTION '#633 : la lecture des migrations n''a pas abouti (sentinelle levée par le code de la table)';
    END IF;
    PERFORM pg_catalog.set_config('cpg.migrations', CASE WHEN lues.presente THEN lues.nombre::pg_catalog.text ELSE '' END, true),
            pg_catalog.set_config('cpg.derniere_migration', coalesce(lues.derniere, ''), true),
            pg_catalog.set_config('cpg.fuseau_migre', coalesce(lues.fuseau, false)::pg_catalog.text, true);
END
$$;
SET LOCAL statement_timeout = 0;
\warn '--- #633 : lecture des migrations, fin'
-- Issue #633 : le chemin de recherche de la session, contrôlé avant que la
-- suite résolve un seul nom par lui — une fonction, un opérateur, une relation
-- ou un type d'un autre schéma pourrait y prendre la place de ceux du
-- catalogue. Admis : exactement celui des options de la procédure, aux
-- espaces près (PGOPTIONS l'écrit sans espace, un SET avec) ; `pg_temp` y
-- désigne toujours le schéma temporaire de la session. Pas current_schemas
-- (relecture de #633) : il admettrait le schéma temporaire d'une autre
-- session nommé `pg_temp_N`, parcouru comme un schéma ordinaire, et
-- omettrait un schéma encore absent, que son créateur ferait entrer en cours
-- de transaction. Le même bloc ouvre verify.sql ; aucun de ses noms ne passe
-- par le chemin qu'il contrôle (PostgresRolesProcedureTest).
DO $$
BEGIN
    IF pg_catalog.replace(pg_catalog.current_setting('search_path'), ' ', '') OPERATOR(pg_catalog.<>) 'pg_catalog,pg_temp' THEN
        RAISE EXCEPTION '#633 : le chemin de recherche de la session n''est plus celui de la procédure (pg_catalog, pg_temp)';
    END IF;
END
$$;

-- 5. La base de l'application remise en l'état que le bloc `#608` de
--    verify.sql attend (issue #610) : son propriétaire, le rôle de migration,
--    peut la couper sans être superutilisateur, et tout rôle pose sur lui-même
--    un réglage propre à la base — une injection SQL sous cpg_app comprise.
--    Sans cette étape, aucun chemin écrit ne réparait ces états : la
--    procédure échouait tant qu'ils existaient, et un `exec` qui écrit hors
--    d'elle est interdit (#520). Chaque réparation n'écrit que sur un écart
--    (une base saine n'est pas réécrite) et l'annonce par un avis `#610` sur
--    le terminal de l'opérateur : des noms de réglage et des nombres, jamais
--    une valeur (choisie par qui tient le rôle de migration), jamais un nom
--    de rôle (celui du superutilisateur est dans un Secret).
--    - Les réglages de base : le fuseau seul, une fois Version20261011120000
--      passée (sa ligne, lue à l'étape 4), à la valeur que la migration figée
--      écrit — elle ne se rejoue pas, elle convertit aussi les horodatages ;
--      aucun avant elle. PostgresRolesProcedureTest lie la valeur à la
--      migration.
--    - Les réglages de rôle propres à la base, de tout rôle : verify.sql les
--      refuse tous.
--    - CONNECT rendu à PUBLIC, l'état que l'image crée (privileges.sql ne le
--      retire que sur postgres et template1), quand l'un des trois rôles ne
--      l'a plus — le critère même de verify.sql ; le pg_hba.conf du dépôt
--      filtre les rôles admis par le réseau.
--    - Ni limite de connexions, ni marque de modèle.
--    Laissés tels quels, comme verify.sql les laisse : TEMPORARY et
--    ALLOW_CONNECTIONS. Du SQL pur, sans variable psql : rejoué sur la base de
--    test par DatabaseOwnerSettingsVerificationTest, après chaque geste que le
--    bloc `#608` refuse.
DO $$
DECLARE
    base oid := (SELECT oid FROM pg_database WHERE datname = current_database());
    reglages text[] := (SELECT setconfig FROM pg_db_role_setting WHERE setdatabase = base AND setrole = 0);
    migree boolean := current_setting('cpg.fuseau_migre')::boolean;
    fuseau text := 'Europe/Paris';
    attendus text[];
    roles_regles oid[];
    r oid;
BEGIN
    PERFORM set_config('search_path', 'pg_catalog, pg_temp', true);
    attendus := CASE WHEN migree THEN ARRAY['TimeZone=' || fuseau] END;
    IF reglages IS DISTINCT FROM attendus THEN
        RAISE NOTICE '#610 : réglages de base remis en état (trouvés : %)', (SELECT array_agg(split_part(c, '=', 1) ORDER BY c COLLATE "C") FROM unnest(reglages) AS c);
        EXECUTE format('ALTER DATABASE %I RESET ALL', current_database());
        IF migree THEN
            EXECUTE format('ALTER DATABASE %I SET timezone TO %L', current_database(), fuseau);
            RAISE NOTICE '#610 : fuseau de la base remis à celui de Version20261011120000';
        END IF;
    END IF;
    roles_regles := ARRAY(SELECT setrole FROM pg_db_role_setting WHERE setdatabase = base AND setrole <> 0);
    FOREACH r IN ARRAY roles_regles LOOP
        EXECUTE format('ALTER ROLE %I IN DATABASE %I RESET ALL', pg_get_userbyid(r), current_database());
    END LOOP;
    IF cardinality(roles_regles) > 0 THEN
        RAISE NOTICE '#610 : réglages de rôle propres à la base retirés pour % rôle(s)', cardinality(roles_regles);
    END IF;
    IF EXISTS (SELECT FROM unnest(ARRAY[current_setting('cpg.app_role'), current_setting('cpg.migrator_role'), current_setting('cpg.reader_role')]) AS c(nom)
               WHERE NOT has_database_privilege(c.nom, current_database(), 'CONNECT')) THEN
        EXECUTE format('GRANT CONNECT ON DATABASE %I TO PUBLIC', current_database());
        RAISE NOTICE '#610 : CONNECT rendu à PUBLIC sur la base';
    END IF;
    IF (SELECT datconnlimit <> -1 FROM pg_database WHERE oid = base) THEN
        EXECUTE format('ALTER DATABASE %I CONNECTION LIMIT -1', current_database());
        RAISE NOTICE '#610 : limite de connexions de la base retirée';
    END IF;
    IF (SELECT datistemplate FROM pg_database WHERE oid = base) THEN
        EXECUTE format('ALTER DATABASE %I IS_TEMPLATE false', current_database());
        RAISE NOTICE '#610 : marque de modèle de la base retirée';
    END IF;
END
$$;
