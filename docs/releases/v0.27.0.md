# v0.27.0 — Procédure des rôles PostgreSQL verrouillée

Release de durcissement, suite de #593 (v0.26.0). Les bases de l'image PostgreSQL sont fermées au réseau. La
procédure des rôles contrôle et répare désormais tout ce que le propriétaire de la base, ou un rôle sur
lui-même, peut altérer, et sa session ne garde rien de ce qu'elle hérite. La préprod vérifie elle-même le
`pg_hba.conf` à chaque déploiement.

## Ce qui change

- **Bases de l'image fermées** (#597) : `pg_hba.conf` refuse `postgres`, `template0` et `template1` à
  tout rôle et à toute adresse, avant toute règle qui accepte, loopback compris. `privileges.sql` retire à
  `PUBLIC` `CONNECT` et `TEMPORARY` sur `postgres` et `template1`. **Adminer exige le nom de la base** : un
  champ vide vise `postgres`, qui est refusé.
- **Sonde `pg_hba` en préprod** (#596, #600) : un Job tente le superutilisateur sans mot de passe, ainsi que
  `cpg_app` sur `postgres`, et exige trois refus du `pg_hba.conf`. Il tourne dans son propre job,
  `postgres-hba-preprod`, **troisième check requis** de `main`. Un échec de la sonde ne déclenche plus le
  retour arrière de l'application.
- **Smoke préprod** (#603) : le contrôle de `postgres` et `rabbitmq` passe en tête du job, pour que le
  premier rouge nomme le datastore.
- **Procédure des rôles** (`k8s/README.md`, « Rôles PostgreSQL ») :
  - `verify.sql` refuse ce qui couperait l'application sans erreur (#608, #611, #634) :
    - un réglage de la base autre que le fuseau, ou un réglage de rôle propre à la base ;
    - un réglage posé hors de la base (un rôle pour toutes les bases, tous les rôles, une base de l'image) ;
    - une limite de connexions, une marque de modèle, un `CONNECT` retiré ;
    - et tout schéma qui n'est pas celui des migrations, par une empreinte admise par migration.
  - `roles.sql` répare ce que `verify.sql` refuse sur les réglages et les droits de la base (#610, #634). Un
    avis `#610` est imprimé à chaque réparation.
  - La session de la procédure est fixée par `PGOPTIONS`. Tout autre réglage hérité est rendu à sa valeur de
    démarrage, avant tout mot de passe (#618, #634).
  - La table des migrations est lue sous le rôle de migration, dans une lecture confinée et annulée
    elle-même. Le code de son propriétaire ne peut plus rien laisser au superutilisateur, et un garde
    contrôle le chemin de recherche (#610, #633).
- **`psql` du lecteur du runbook** (#634) : nouvelles options de session (encodage, bibliothèques, chemin de
  recherche), et `public.cpg_user` qualifié. La lecture de preuve « avant l'étape 2 » montre aussi les
  réglages hors de la base.
- **Vocabulaire** (#602) : `CONTEXT.md` du domaine. Libellés du backoffice « Version of » et « Proposition
  générée par IA ». La page du CV sans identité propose « demander le CV nominatif ». La description CLI de
  `app:user:purge-pending-invitations` change.
- **`/stack`** (#615) : 30 paquets npm de build passent en dépendances de développement dans le lockfile et
  sortent de la veille. Le compteur de paquets analysés baisse, c'est attendu.
- **Dépendances** : groupes mineurs et correctifs du frontend (#622), du backend (#623) et des GitHub
  Actions (#624).
- **Interne** : règles `.claude` découpées, élaguées et bornées (#605, #620, #627). Tests de `php.prod.ini`
  et des sources d'ini (#612, #616). CI `test-backend` passée de PostgreSQL 18.4 à 18.6.

## À savoir pour le déploiement

- **Aucune migration**, aucun changement de clé : pas de fenêtre de maintenance.
- **PostgreSQL redémarre une fois** (`Recreate`, ConfigMap `postgres-hba` hachée) : coupure courte de l'API,
  en préprod puis en prod. Le backend fait aussi un rollout sans coupure : le hash de `backend-nginx-conf`
  change, sur un commentaire seulement.
- **Avant le merge vers `main`** : vérifier que la base de prod ne s'appelle pas `postgres`, `template0` ou
  `template1`. Sinon, le nouveau `pg_hba.conf` coupe l'API au redémarrage, sans retour arrière en prod.
- **Après le déploiement de chaque environnement**, préprod puis prod : **rejouer la procédure des rôles avec
  la ligne d'`exec` du runbook** (pas celle d'un script plus ancien), après la lecture de preuve sous le
  lecteur. C'est elle qui applique le `REVOKE` de #597. C'est aussi la première confrontation de l'empreinte
  `#611` à un schéma réel. Sur une base saine :
  - la ligne `#618 : réglages hérités rendus…` vaut `{TimeZone}` ;
  - aucun avis `#610` (un avis signale un incident) ;
  - la colonne `reglages_hors_de_la_base` vaut `0` ;
  - la sortie se termine par `conforme`.

  Un refus `#611` se nomme avec la requête « Un refus `#611` » du runbook, avant toute décision.
- **Vérifier en préprod** que `postgres-hba-preprod` est vert, et que le check requis est satisfait sur la
  PR vers `main`.
