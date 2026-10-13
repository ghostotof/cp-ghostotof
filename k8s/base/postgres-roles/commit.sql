-- Issue #593 — la validation de la procédure des rôles : dernier fichier du
-- flux (k8s/README.md, « Rôles PostgreSQL »), après roles.sql, privileges.sql
-- et verify.sql, dans la transaction que le flux ouvre par `BEGIN;`.
--
-- Ne valide que si verify.sql est allé jusqu'au bout : sa dernière instruction
-- pose `verify_passed`, que roles.sql efface en tête. Un fichier qui n'a pas
-- été lu — `cat` lancé hors de la racine du checkout, fichier renommé — laisse
-- la marque absente : une erreur, psql sort en 3 et la session close annule
-- tout. Sans ce fichier, rien ne valide non plus. Relecture de #593 : un
-- `COMMIT` inconditionnel en fin de flux validait sans verify.sql, sortie 0.
\if :{?verify_passed}
COMMIT;
\else
DO $$ BEGIN RAISE EXCEPTION '#593 : verify.sql n''est pas allé jusqu''au bout (fichier absent du flux ?) : rien n''est validé'; END $$;
\endif
