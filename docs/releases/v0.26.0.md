# v0.26.0 — Superutilisateur PostgreSQL fermé au réseau

Release de durcissement, suite de #575 (v0.25.0) : le superutilisateur créé par l'image PostgreSQL n'est plus
joignable que de l'intérieur du pod `postgres` (#593).

## Ce qui change

- **`pg_hba.conf` versionné** (`k8s/base/postgres-pg_hba.conf`, lu par `-c hba_file`) : par le réseau, seuls
  `cpg_app` et `cpg_migrator` sont admis, en SCRAM ; le superutilisateur et la réplication sont refusés ;
  `cpg_reader` n'est admis que sur le loopback. Avant, n'importe quel pod du namespace pouvait atteindre le
  superutilisateur avec le mot de passe de `postgres-credentials`, et `kubectl port-forward` sans mot de passe.
- **Procédure des rôles** (`k8s/README.md`, « Rôles PostgreSQL ») : un `kubectl exec` sur le socket local
  remplace le Job `postgres-roles-job.yaml`. Les mots de passe ne traversent plus le réseau du cluster, et
  `commit.sql` ne valide que si `verify.sql` est allé au bout. `verify.sql` contrôle aussi le `pg_hba` actif.
- **Adminer** n'admet plus que `cpg_app` et `cpg_migrator`.
- **CI** : nouveau job `postgres-hba`, requis par `release-version`, qui démarre l'image épinglée et mesure
  les refus et les admissions.

## À savoir pour le déploiement

- **PostgreSQL redémarre une fois** (`Recreate`, nouvelle ConfigMap) : coupure courte de l'API, en préprod
  puis en prod. Aucune migration, rien d'autre ne change pour l'application.
- **Après le rollout de `postgres`**, en préprod puis en prod : commande « Le `pg_hba.conf` vu du réseau »
  du runbook. Un refus est attendu ; un `SELECT` réussi arrête la release.
- **Retour arrière de l'application vers l'URL du superutilisateur** : il suppose maintenant de revenir
  d'abord sur ce `pg_hba`.

Suivis : #596 (test automatique du refus en préprod), #597 (restreindre la base et la plage d'adresses).
