-- Issue #575 — privilèges du rôle applicatif et du rôle de lecture.
--
-- Joué deux fois, depuis ce seul fichier :
--   - par la procédure « Rôles PostgreSQL » de k8s/README.md (psql dans le
--     pod postgres, après roles.sql, variables -v app_role=cpg_app
--     -v migrator_role=cpg_migrator -v reader_role=cpg_reader), en préprod
--     puis en prod, à la main ;
--   - par backend/tests/Shared/Infrastructure/Postgres/ApplicationRolePrivilegesTest,
--     qui y substitue des rôles jetables et vérifie, sur le schéma migré de la
--     base de test, que chaque table et chaque séquence sont accessibles au
--     rôle applicatif, qu'une table créée ensuite par le rôle de migration
--     l'est aussi, et que le rôle applicatif ne peut ni désactiver le trigger
--     différé ni altérer le schéma.
-- D'où la forme : du SQL pur, une instruction par `;` (un bloc `DO $$ … $$`
-- compte pour une), aucune méta-commande psql, et seulement les variables
-- :"app_role" (identifiant), :'app_role' (littéral), :"migrator_role" et
-- :"reader_role". Rejouable : chaque GRANT/REVOKE est idempotent. Jouable sur
-- une base encore vide (environnement neuf : la procédure passe AVANT la première
-- migration, qui tourne sous le rôle de migration) : rien ici ne suppose une
-- table.

-- Défaut de PostgreSQL ≥ 15 (la base a été créée sur la 18) : écrit pour
-- qu'un schéma plus ancien ne laisse pas l'application créer une table.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- Le rôle applicatif : le DML sur tout le schéma, rien d'autre. Ni TRUNCATE,
-- ni TRIGGER, ni REFERENCES ; aucune propriété (ALTER TABLE … DISABLE TRIGGER
-- et DROP TRIGGER sont réservés au propriétaire), pas superutilisateur
-- (SET session_replication_role lui est refusé).
GRANT USAGE ON SCHEMA public TO :"app_role";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"app_role";
-- Les colonnes d'identité n'en ont pas besoin (nextval interne, sans
-- contrôle) ; une séquence nommée ou une colonne SERIAL future, si.
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"app_role";

-- L'application ne lit jamais l'historique des migrations. REVOKE n'a pas
-- de IF EXISTS, et la table n'existe pas sur une base neuve : le bloc lit le
-- nom du rôle dans un réglage de session, puisque psql n'interpole pas ses
-- variables entre $$.
SELECT set_config('cpg.app_role', :'app_role', true);
DO $$
BEGIN
    IF to_regclass('public.doctrine_migration_versions') IS NOT NULL THEN
        EXECUTE format('REVOKE ALL PRIVILEGES ON TABLE public.doctrine_migration_versions FROM %I', current_setting('cpg.app_role'));
    END IF;
END
$$;

-- Les tables et séquences de demain : les migrations tournent sous le rôle de
-- migration (migrate-job.yaml, Secret backend-migration-secrets), et les
-- privilèges par défaut sont attachés au rôle qui CRÉE l'objet. Un objet créé
-- par un autre rôle (à la main, en superutilisateur) n'en hérite pas :
-- rejouer la procédure, dont les deux GRANT … ON ALL ci-dessus le rattrapent.
ALTER DEFAULT PRIVILEGES FOR ROLE :"migrator_role" IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"app_role";
ALTER DEFAULT PRIVILEGES FOR ROLE :"migrator_role" IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO :"app_role";

-- Le lecteur des psql humains du runbook (k8s/README.md) : tout lire, dont
-- cpg_user pour nommer un compte, rien écrire — une frontière, là où
-- default_transaction_read_only n'était qu'un garde-fou.
GRANT pg_read_all_data TO :"reader_role";
