# v0.18.0 — Purge des invitations, messagerie en France, reliquat du 3e audit

Aucune migration de schéma, aucune fenêtre de maintenance. Côté Kubernetes : la
configuration nginx du sidecar backend change (`/healthz`), donc le `backend` redémarre par
rolling update ; le CronJob de maintenance gagne une commande ; les labels Pod Security
Admission des namespaces sont épinglés. Ni Postgres ni RabbitMQ ne sont touchés : pas de
coupure de l'API.

## Purge des invitations jamais activées (#238)

Un compte invité dont le mot de passe n'a jamais été défini n'a aucune raison de rester en
base avec l'adresse e-mail qu'il porte.

- Nouvelle commande `app:user:purge-pending-invitations`, exécutée chaque jour par le CronJob
  de maintenance : supprime les comptes au **hachage de mot de passe encore vide** invités
  depuis plus de **30 jours** (dernière invitation, une relance repousse le délai). Un
  compte `ROLE_SUPER` n'est jamais purgé automatiquement ; un compte dont le mot de passe a
  été posé depuis le backoffice n'est plus « en attente » et échappe à la purge.
- Chaque suppression est tracée dans le journal d'audit (`user-purged`, acteur `system`,
  jamais l'e-mail). `--dry-run` pour un lancement à blanc.
- Garde-fou : une rétention inférieure à un jour est refusée (exit 2) — un lancement manuel
  imprudent ne peut plus vider la quasi-totalité des comptes en attente d'un coup.
- Même famille de bug corrigée sur `app:contact:purge-failed-messages` (#248) : une durée
  négative ou nulle (`--older-than="-30 days"`) plaçait le seuil dans le **futur** et purgeait
  tous les messages en échec, exit 0. Un Value Object `RetentionPeriod` partagé refuse tout
  seuil qui n'est pas strictement dans le passé.
- Registre RGPD (§3.2, §6) et politique de confidentialité (FR/EN) annoncent cette purge.

## Boîte de contact et zone DNS en France, fin du transfert hors UE (#231)

La boîte `contact@` est désormais hébergée par OVHcloud (France) et la zone DNS par Scaleway ;
Cloudflare (redirection d'e-mails et DNS, États-Unis) est retiré. **Aucun transfert de données
hors Union européenne ne subsiste.** Le registre RGPD (§1, §8, daté du retrait effectif) et la
politique de confidentialité du site le disent ; le §5 consigne la rotation réelle des journaux
de conteneur mesurée sur les nœuds (50 Mio par conteneur au plus, borne effective : le
remplacement du pod à chaque release).

## Formulaire de contact : une saisie refusée n'est plus « un envoi qui a échoué » (#236)

Un message trop court s'affichait comme « L'envoi a échoué, réessayez plus tard », invitant à
réessayer une saisie que le serveur refuserait à chaque fois. Le formulaire distingue maintenant
un 422 (le champ fautif est signalé, avec `aria-invalid` et un libellé traduit), un 429 (quota
atteint) et une vraie panne (réseau ou 5xx, seul cas où « réessayez » est vrai). Un 422 qui ne
nomme aucun champ connu (le pot de miel) tombe sur un libellé générique sans rien révéler.
Aide visible sous les champs (longueurs min/max), contraste des messages relevé.

## La version déployée dans le pied de page

Le hero promet que « ce que vous lisez ici est exactement ce qui tourne » ; le pied de page le
rend vérifiable d'un clic : `v0.18.0` pointe vers cette release GitHub, le SHA du build en
infobulle. La version est fixée au **build** de l'image (`--build-arg APP_VERSION`), à l'inverse
de l'URL de l'API lue au runtime : une image promue de la préprod vers la prod annonce la même
version, ce qui est exactement ce qu'elle doit dire.

## Reliquat du 3e audit de sécurité (#239) et gardes

- **Garde CI contre la régression A16** : un `add_header` dans une `location` nginx annule
  l'héritage de tous ceux du bloc `server` — c'est ainsi que `/assets/`, `/config.js` et
  `/healthz` ont été servis sans CSP ni HSTS pendant des mois. `tools/check-frontend-image-headers.sh`
  lance l'image nginx du frontend localement et vérifie les 7 en-têtes sur un chemin de
  chacune de ses `location` ; le job `frontend-image-headers` le fait sur chaque push et
  conditionne la release. La fonction de contrôle d'`audit-prod.sh` est extraite dans une
  bibliothèque testée hors ligne (14 cas).
- **`/healthz` répondait avec deux `Content-Type` contradictoires** sur les deux nginx
  (`application/octet-stream` + `text/plain`) : `default_type` remplace l'`add_header`.
- **Un champ mal typé répond 422 avec ses violations** (`collect_denormalization_errors`), la
  forme que les formulaires du backoffice affichent déjà ; seul un JSON illisible reste un 400.
- **Firewall `dev` du squelette supprimé** : `^/(_profiler|_wdt|assets|build)/` en
  `security: false` existait en prod pour des routes qui n'existent nulle part. Neutre
  fonctionnellement, et un test empêche son retour.
- **`RouterScopeTest`** ferme la frontière hors `/api` : toute route compilée hors de ce
  préfixe doit être justifiée dans une allow-list (vide), sinon la suite rougit.
- **Image backend de production allégée** des fichiers d'outillage (tests, PHPStan, Psalm,
  Rector, PHPUnit, LSP) : −1,8 Mo, rien n'y était lu à l'exécution.
- **Pod Security Admission** : `enforce-version` épinglé sur la mineure du cluster (v1.36),
  `audit`/`warn` restent sur `latest` pour pré-annoncer ce qu'un futur `enforce` refuserait.
- **Plus aucun secret en argument** dans les recettes du README Kubernetes : les trois
  restantes passent par fichier sous `umask 077`.
- `robots.txt` interdit le crawl des pages sans contenu indexable (connexion, définition de
  mot de passe, accès refusé, backoffice) ; notes d'acceptation sur `Canonical` de
  `security.txt` et `date -d`.

## Dépendances

- Frontend : vue 3.5.43, vue-i18n 11.4.12, eslint 10.11.0, jsdom 30.1.0, @vue/test-utils
  2.5.1, @types/node 26.6.2, jeux d'icônes Lucide et Simple Icons.
- CI : `github/codeql-action` 4.38.1.
