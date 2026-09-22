# TODO — Remédiation du 3e audit de sécurité (2026-09-16) — lot 2

Détail, décisions (D1–D8), risques et questions ouvertes (Q1–Q9) : `tasks/plan.md`.

Branche : `feature/security-audit-3-remediation-lot-2` (phases 4 et 5), une branche de tâche
empilée par phase. Lot 1 (phases 0–3) livré par v0.14.1 et v0.15.0, archivé.
T4.1 et T4.2 demandent Fable : confirmation de Christophe avant de lancer l'agent. Trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

Gates par checkpoint : `make back-quality && make back-test` ; frontend (si touché)
`make front-lint && make front-test && make front-build` ; k8s (si touché) `kubectl kustomize`
prod **et** préprod ; scripts/pipeline (si touchés) `shellcheck -x --severity=warning` + `actionlint`.

## Clôture (2026-09-23) — poussée et PR vers `develop`

- Décision de Christophe : pousser la branche du lot telle quelle, **une seule PR de clôture** vers
  `develop` (les branches de phase, déjà fusionnées en `--no-ff`, ne sont pas poussées). Les checkpoints
  4 et 5 se jouent en préprod, donc après ce merge et la coupe d'une `release/*` : leurs cases restent
  ouvertes dans cette archive, c'est l'état du plan à la clôture.
- Ajouté depuis le point de reprise : commit `136d6fd` — registre RGPD §3 repris (accès de base,
  invitation, authentification), §7/§8 complétés, page publique FR/EN corrigée (elle affirmait « aucun
  transfert hors UE ») ; l'arbitrage « registre RGPD §3 » du point 8 est donc traité. Restent à relire :
  la base légale de l'invitation (intérêt légitime) et l'absence de purge des invitations jamais activées.
- T4.3 **découplée du checkpoint 4** : le DNS et la messagerie du domaine migrent chez des prestataires
  français (issue #231) ; DMARC se durcit dans la nouvelle zone. L'exposition de l'adresse nominative
  dans le `rua` a été corrigée le 2026-09-22 (`_dmarc` = `v=DMARC1; p=none`).
- Redémarrage de Postgres/RabbitMQ (point 2) : coupure acceptée pour cette release ; une release future
  la supprimera définitivement (notée en mémoire de projet).

## Point de reprise (2026-09-21, fin de journée)

- **Tout le code du lot 2 est écrit, relu, passé aux gates et committé — en local seulement.** Les
  branches `…-lot-2-phase-4` et `…-lot-2-phase-5` sont fusionnées (`--no-ff`) dans
  `feature/security-audit-3-remediation-lot-2`. **Rien n'est poussé, aucune PR, rien en préprod ni en
  prod.** Ne pousser qu'à la demande explicite de Christophe.
- Gates repassés d'un seul tenant sur la tête de branche le 2026-09-21 : backend 1138 tests +
  PHPStan/Rector/Psalm/lsp:check, frontend lint + 959 tests + build, kustomize prod et préprod,
  shellcheck, actionlint, les 5 tests d'outils — tout vert (1 notice PHPUnit antérieure, contexte Watch).
- Méthode suivie : un agent par tâche (Fable pour T4.1/T4.2, Opus pour la phase 5), diff relu et gates
  repassés par l'orchestrateur, un commit par tâche. Trailer Fable sur tous les commits.
- **Ce qui reste, et qui dépend de Christophe :**
  1. **Décider du push** de la branche du lot et de l'ouverture de la PR vers `develop` (la PR de clôture
     archive `tasks/` sous `.claude/specs/archive/<date>-remediation-audit-securite-3-lot-2/`).
  2. **Avant de promouvoir, savoir que cette release redémarre Postgres et RabbitMQ** (T5.6, pod template
     modifié, stratégie `Recreate`) : courte indisponibilité franche de l'API, en préprod puis en prod,
     une fois. Procédure de validation préprod en 7 étapes dans `k8s/README.md`.
  3. **Checkpoint 4** (préprod, vrai navigateur) : parcours d'invitation complet, URL nettoyée pour le
     lien en fragment **et** pour un ancien lien, voir la case dédiée plus bas.
  4. **Checkpoint 5** (préprod) : `audit-prod.sh` étendu vert ; rollout réel d'Adminer 5 (`kubectl patch`
     replicas=1, `rollout status`, logs) ; labels PSA en `warn`/`audit` avant `enforce`.
  5. **T4.3 (DNS mail)**, manuel, deux semaines de rapports DMARC avant `p=reject` — à lancer tôt.
  6. **Secret Xdebug** `preprod-backend-xdebug-trigger` à créer dans Secret Manager, terminal séparé,
     sans contrainte d'ordre (d'ici là l'ExternalSecret est `Ready: False`, attendu).
  7. **T4.4** dans la release **suivante**, ≥ 48 h après T4.2 en prod : retrait du repli `:token?`.
  8. **Arbitrages en attente** : `collect_denormalization_errors` (T5.1) ; bcrypt `cost: 13` (T5.9) ;
     les trois autres secrets en argument dans `k8s/README.md` (T5.10) ; firewall `dev` sous `when@dev`
     (T5.7) ; le correctif de pipeline ajouté en T5.6 (attente de postgres/rabbitmq/worker) à confirmer ;
     **registre RGPD §3 à reprendre** (il nie stocker des e-mails).
  9. Consigne inchangée tant que T4.1/T4.2 ne sont pas en prod : **ne pas émettre d'invitation de compte**.
- Secrets : toute commande qui pose une valeur secrète se lance dans un terminal séparé, par fichier
  ou stdin, jamais via `!` ni `--body`.

## Reliquat du lot 1 — à la main de Christophe (hors code) — CLOS le 2026-09-19
- [x] R.1 GitGuardian : incident 37338519 (fixtures de `rotate-deployer-token.test.sh`) marqué faux positif le 2026-09-16
- [x] R.2 Rotation faite le 2026-09-16 : `preprod` (18:09 UTC) et `prod` (18:10 UTC), 90 jours accordés par Kapsule sans troncature (Q4 confirmée), `can-i` jobs=yes / pods/exec=no sur les deux ; `KUBE_CONFIG_PREPROD` posé sur l'environnement `preprod`, `KUBE_CONFIG_PROD` sur `production`. Expiration : 2026-12-15
- [x] R.3a `PREPROD_BASIC_AUTH` posé sur l'environnement `preprod` (2026-09-16 18:25 UTC), **avec le nouveau mot de passe** de R.3c
- [x] R.3b Clé `release-bot` régénérée le 2026-09-16 (l'ancienne privée avait été détruite après upload, un secret GitHub est illisible) : ancienne deploy key 163489899 supprimée, nouvelle posée en écriture, `RELEASE_DEPLOY_KEY` posé sur `production` et **supprimé du niveau dépôt** ; `tools/github-settings.sh` : étapes a–k vertes, 4 secrets présents dans leur environnement. Première utilisation réelle : `finalize-release` de la prochaine release
- [x] R.3c Mot de passe Basic Auth de la préprod changé le 2026-09-16 : les identifiants avaient été collés dans le transcript de la session (consigne `--body` fautive, cf. mémoire « secrets hors session »). Nouvelle version rév. 3 de `preprod-basic-auth-htpasswd` (Secret Manager, 18:23:58 UTC), ExternalSecret resynchronisé à 18:24:23 UTC (`force-sync`)
- [x] R.3d Révisions 1 et 2 de `preprod-basic-auth-htpasswd` désactivées le 2026-09-16 (vérifié : rév. 3 seule `enabled`, `latest`)
- [x] R.3e `PREPROD_BASIC_AUTH` de dépôt supprimé le 2026-09-16 (ancien mot de passe, inopérant) ; ses deux lecteurs, `smoke-test-preprod` et `audit-preprod`, déclarent `environment: preprod`
- [x] R.4 **Fait le 2026-09-19** → **CHECKPOINT 2 du lot 1 atteint**. Condition levée d'abord : la release v0.15.1 est passée verte de bout en bout, `finalize-release` compris, donc la clé `release-bot` régénérée en R.3b a servi pour de vrai (tag, commit de notes, synchro `main` → `develop`). Vérifié avant suppression : les quatre jobs lisant `KUBE_CONFIG_*` (`deploy-preprod`, `deploy-prod`, `rollback-preprod`, `smoke-test-preprod`) déclarent tous leur `environment`, et aucun ServiceAccount ni pod ne référençait les Secrets durables. Supprimés : les 2 secrets de dépôt `KUBE_CONFIG_PREPROD`/`KUBE_CONFIG_PROD` (datés du 2026-09-01) et les 2 Secrets `github-actions-deployer-token` des namespaces `preprod` et `prod` ; les ServiceAccounts sont intacts et tous les pods sont restés `Running`. `tools/github-settings.sh` rejoué : étapes a–k vertes, **étape k entièrement verte** (4 secrets présents dans leur environnement, 4 absents du niveau dépôt)
- [x] R.5 **Fait le 2026-09-19** : rappel agenda posé par Christophe pour le **2026-12-08** (les jetons `preprod` et `prod` expirent le 2026-12-15). Commande le jour venu : `tools/rotate-deployer-token.sh preprod` puis `… prod`, dans un terminal séparé
- [x] R.6 **Fait le 2026-09-19** : post-mortem de l'incident **A1** (invariant « aucun état sur le filesystem du pod », décision Q6). Contenu rédigé en FR et EN — titre, impact, cause racine, résolution, invariant —, versionné dans `tasks/postmortem-a1.md` et envoyé par mail champ par champ pour la saisie dans `/admin/incidents` (entrée française puis « Créer la version EN », pour que les deux partagent le groupe de traduction). `version` = `v0.14.1`, `occurredAt` = `2026-09-16`
- [x] R.7 **Fait le 2026-09-19** : alertes Dependabot actives (vérifié, l'API répond 204 et `dependabot/alerts` renvoie une liste — 0 alerte ouverte à cette date), et **routage des notifications « Security alerts » posé** par Christophe (préférences du compte + abonnement au dépôt). Rappel pour plus tard : `dependabot_security_updates` reste **volontairement désactivé** — ces PR viseraient `main`, qui n'accepte qu'une PR validée par `smoke-test-preprod`/`audit-preprod`, elles ne seraient jamais mergeables. Ne pas l'activer en passant sur la page Code security

## Phase 4 — A6/A7 : parcours mot de passe et e-mail · MOYENNE
- [x] 4.1 (fait le 2026-09-21, local) API : `POST /api/account/password-setup/validate {token}` + `POST /api/account/password-setup {token, password}` ; `GET …/{token}` supprimé ; listeners rate-limit/CSRF, 2 confs nginx, `ApiRouteExposureTest::PUBLIC_PATHS`, tests fonctionnels (429 avant validation conservé ; 422/404/410 sur les deux POST) ; retrait de `TOKEN_BEARING_PATH_PATTERN` de `SecurityAuditLogger`
- [x] 4.2 (fait le 2026-09-21, local ; parcours bout en bout **non fait en dev** — pas de navigateur ni de catcher mail, reporté au checkpoint 4) Lien e-mail `…/set-password#<token>` ; route `set-password/:token?` ; page lit le fragment (puis l'efface via `replaceState`), repli sur le param 48 h ; `HttpAccountRepository` ; specs Vitest ; parcours bout en bout en dev
- [ ] 4.3 (manuel) DKIM TEM vérifié ; `_dmarc` `p=quarantine` + `rua` sur une adresse du domaine ; SPF `-all` ; passage à `p=reject` planifié après deux semaines de rapports propres
- [ ] 4.4 (suivi, release **suivante**, ≥ 48 h après T4.2 en prod) retrait du repli `set-password/:token?` côté frontend
- [ ] **À rejouer au checkpoint 4, en préprod, dans un vrai navigateur** (doutes de l'agent de T4.2) :
  (a) `router.replace` dans `onMounted` au tout premier chargement, avec le routeur réel et ses gardes —
  l'URL doit être nettoyée (fragment **et** ancien lien `/set-password/<jeton>`), le formulaire rester
  utilisable ; (b) un `complete` avec une phrase de passe longue a répondu **422** en dev pendant la sonde
  de l'agent, cause non élucidée (quoting shell probable ; les contraintes de `password` n'ont pas changé
  et le parcours fonctionne en prod aujourd'hui) — lire le corps du 422 s'il se reproduit ; (c) rappel :
  `NotCompromisedPassword` est actif en dev, tout parcours manuel y appelle `api.pwnedpasswords.com`
  (préfixe SHA-1 de 5 caractères, k-anonymat) ; `MAILER_DSN` de dev pointe sur un `mailpit` qui n'est pas
  dans le compose
- [ ] **CHECKPOINT 4** — gates backend + frontend verts ; invitation validée en préprod ; `dig` + e-mail réel `DKIM/DMARC: PASS`

## Phase 5 — Hygiène code et infra · FAIBLE
- [x] 5.1 (fait le 2026-09-21, local) `ApiJsonErrorFormatListener` sur **`kernel.exception`** (D8 amendée : `kernel.request` cassait la négociation de contenu, 406 sur `/api/docs`) ; `Content-Type: application/json`, corps RFC 7807 ; corps malformé 500 → 400 (`exception_to_status` écrasait les défauts d'API Platform). **À arbitrer par Christophe** : `collect_denormalization_errors` (mauvais type → 422 avec `violations` ; testé sans régression, non activé)
- [x] 5.2 (fait le 2026-09-21, local ; prouvé sur conteneur nginx jetable, **pas** sur la chaîne réelle ingress → pod : à confirmer par `audit-prod.sh` en préprod au checkpoint 5 — il ne juge désormais que la réponse finale, il peut donc rougir là où seule une redirection portait les en-têtes) CSP + HSTS sur `/assets/`, `/healthz`, `/config.js` (front) et HSTS sur `/healthz` (backend k8s) ; `audit-prod.sh` vérifie `/config.js` + un asset  ; pas de `preload` (Q3)
- [x] 5.3 (fait le 2026-09-21, local ; PVR vérifié actif, donc le `Contact:` est vivant ; `Expires` = 2027-09-01, l'audit échouera passé cette date) `.dockerignore` (`backend/config/jwt/`, `backend/.env.test`) ; `.gitignore` (`/.claude/settings.local.json`) ; `frontend/public/.well-known/security.txt` + check dans `audit-prod.sh`
- [x] 5.4 (code fait le 2026-09-21, local ; les trois preuves rejouées par l'orchestrateur sur l'image préprod 0.14.1. **Solution différente du plan** : Secret dédié et optionnel + verrou sur `xdebug.mode`, `profile` seul — le mode `trace` écrivait les arguments, donc les mots de passe. **Reste de la main de Christophe**, sans contrainte d'ordre avec le déploiement : créer `preprod-backend-xdebug-trigger` dans Secret Manager, terminal séparé, procédure dans `k8s/README.md` ; d'ici là l'ExternalSecret est `Ready: False`, c'est attendu) Xdebug préprod (Q2 : conservé) : `XDEBUG_TRIGGER_SECRET` via ESO + `emptyDir` `var/profiler` + procédure dans `k8s/README.md` ; un déclencheur sans la bonne valeur ne profile rien
- [x] 5.5 (fait le 2026-09-21, local ; prouvé sur conteneur jetable aux contraintes du pod, **pas de rollout réel** : au checkpoint 5, `kubectl patch` replicas=1 en préprod + `rollout status` + logs, puis laisser le déploiement suivant le rééteindre) Adminer `5.5.1-standalone` (`.env`, `versions.lock`, `k8s/base/adminer.yaml`) ; préprod `replicas: 0` par défaut (Q5)
- [x] 5.6 (code fait le 2026-09-21, local — **le rollout réel en préprod reste à faire**, checkpoint 5 : cette release redémarre Postgres et RabbitMQ en `Recreate`, donc **courte indisponibilité franche de l'API, préprod puis prod** ; la pipeline attend désormais ces workloads ; procédure en 7 étapes dans `k8s/README.md`) Labels PSA (`enforce: baseline`, `audit`/`warn: restricted`) sur les deux namespaces ; `automountServiceAccountToken: false` sur tous les pod specs ; rollout réel en préprod
- [x] 5.7 (fait le 2026-09-21, local ; firewall `api` laissé `^/api` exprès ; profiler/WDT de dev non exercés sur un serveur réel — à regarder au prochain `make up`) `framework.session.enabled: false` ; firewall `login` → `^/api/login_check$` ; `AccessControlAnchoringTest` étendu aux firewalls
- [x] 5.9 (fait le 2026-09-21, local ; écart mesuré avant correction ~273 ms au coût de prod, 0,65 ms après) Hachage factice sur identifiant inconnu (A10) : listener `LoginFailureEvent` + tests (temps égalisés, 401 identiques) ; couvre aussi le compte en attente d'activation (hachage vide)
- [x] 5.10 (fait le 2026-09-21, local ; recette `htpasswd -niB`/`-vi` rejouée avec un mot de passe factice) `k8s/README.md` §2bis : documenter la **rotation** du mot de passe Basic Auth (nouvelle version via `scw secret version create <id> data=@fichier`, pas `secret create` ; `force-sync` ESO ; secret GitHub d'environnement posé par fichier) et remplacer `--body '<identifiant>:<mot-de-passe>'` et `data="$(cat …)"` par des lectures de fichier, en précisant « terminal séparé, jamais dans une session d'agent »
- [x] 5.8 (fait le 2026-09-21, local) `docs/rgpd/` (rétention des journaux), CLAUDE.md, `k8s/README.md` à jour ; A9 (pas de révocation JWT) consigné comme risque accepté dans l'ADR 0003 (Q9)
- [ ] **CHECKPOINT 5** — tous gates verts ; `audit-prod.sh` étendu vert en préprod ; PR de clôture vers `develop` avec archivage de `tasks/` sous `.claude/specs/archive/<date>-remediation-audit-securite-3-lot-2/`

## Suivis relevés pendant le lot (hors périmètre, à trier)
- [ ] `audit-prod.sh` n'a aucun test sous `tools/tests/` ; `check_security_headers` est désormais une fonction pure, testable hors ligne (agent T5.2)
- [ ] La régression A16 n'a aucun garde avant déploiement : un job CI lançant l'image nginx du frontend et vérifiant les en-têtes par location l'attraperait au merge (agent T5.2)
- [ ] `/healthz` renvoie deux `Content-Type` (`add_header Content-Type` s'ajoute au type par défaut) sur les deux nginx ; `default_type text/plain;` serait la forme correcte. Antérieur au lot, sans effet sur la sonde (agent T5.2)
- [ ] `WatchedProductAdministratorTest::testTheFirstProductOfAnEmptyCatalogueTakesPositionZero` : notice PHPUnit (mock sans expectation), antérieure au lot

- [ ] L'image backend de prod embarque tout `backend/` (`tests/`, `phpunit.dist.xml`, `psalm.xml`, `rector.php`, `.phpunit.cache`) : ni secret ni fichier de poste, mais poids mort et surface marginale (agent T5.3)
- [ ] `security.txt` : `Canonical` pointe la prod, y compris quand l'image est servie en préprod (artefact unique promu tel quel) — accepté, l'audit ne vérifie pas `Canonical` ; `date -u -d` de l'audit est GNU (faux « illisible » sur macOS) (agent T5.3)
- [ ] Pas de `robots.txt` : `/robots.txt` retombe sur le fallback SPA en 200 (agent T5.3)

- [ ] **Même défaut que T5.10, ailleurs dans `k8s/README.md`, à valider point par point par Christophe avant correction** (agent T5.10) : (a) `kubectl create secret generic scaleway-eso-auth --from-literal=access-key=… --from-literal=secret-key=…` → `--from-file` (clé `SecretManagerReadOnly` : lecture de tous les secrets du projet ; sévérité moyenne) ; (b) `kubectl patch secret scaleway-eso-auth … -p '{"stringData":…}'` → `--patch-file` (moyenne) ; (c) `kubectl create secret docker-registry ghcr-registry … --docker-password=<PAT>`, bloc conditionnel probablement jamais joué (faible)
- [ ] Étape 3 de la rotation : `status.refreshTime` et la condition `Ready` de l'ExternalSecret n'ont pas pu être vérifiés hors cluster ; la comparaison de `resourceVersion` reste valable seule — à confirmer à la première rotation réelle (agent T5.10)

- [ ] Le firewall `dev` (`security: false`, `^/(_profiler|_wdt|assets|build)/`) existe dans **tous** les environnements, prod comprise : inerte aujourd'hui (aucune route dessous), mais il désactiverait la sécurité d'une future route `/assets/…` ou `/build/…`. Remède : le placer sous `when@dev`. Sévérité faible, confiance élevée (agent T5.7)
- [ ] T5.8 : le CLAUDE.md ne décrit pas le firewall `dev` réel ; nginx `location ^~ /api/login_check` est un préfixe, plus large que le firewall désormais exact (bénin) (agent T5.7)

- [ ] Adminer : `memory_limit = 1G` dans l'image contre `limits.memory: 128Mi` (antérieur, risque d'OOMKill un peu accru avec PHP 8.4) ; `sizeLimit: 256Mi` sur `/tmp` est un choix, pas une mesure (agent T5.5)

- [ ] **À arbitrer par Christophe** : bcrypt `cost: 13` (défaut Symfony) = ~273 ms par login, réussi compris ; l'OWASP recommande 10–12. C'est aussi ce qui fixe le coût d'un login raté sur identifiant inconnu depuis T5.9 (borné par la zone nginx `login` 10 r/m et 25 tentatives/IP/15 min). Levier si la saturation de FPM inquiète : nginx, pas le listener (agent T5.9)
- [ ] Le throttling du login répond **401** (« Too many failed login attempts », via le failure handler Lexik), pas 429 : comportement antérieur, à corriger dans la doc si elle dit 429 (agent T5.9)

- [ ] PSA : `enforce-version: latest` faute de version de control-plane connue dans le dépôt ; l'épingler sur la mineure courante le jour où `kubectl version` est relevé (agent T5.6)
- [ ] `smoke-test-preprod` : `rollout status deployment/postgres --timeout=60s` peut être juste après un `Recreate` avec rattachement de volume (confiance faible) (agent T5.6)

- [ ] Xdebug : le `trim` et le `template` de l'ExternalSecret n'ont pas pu être testés hors cluster (défaillance fermée : Secret non créé → `off`) — à constater quand le secret sera créé (`Ready: True`) ; `profiler_output_name` sans `%r` : deux requêtes du même worker dans la même seconde s'écrasent (agent T5.4)

- [ ] **Registre RGPD §3 « Authentification » factuellement faux, à reprendre par Christophe** (agent T5.8, sévérité moyenne à élevée, confiance élevée) : il affirme « l'unique utilisateur authentifié » et « aucun email n'est stocké », alors que `CpgUser.email` existe depuis l'ADR 0001 pour tout compte invité, et le traitement « invitation de compte » (e-mail + jeton + envoi par Scaleway TEM) n'y figure pas ; les cookies du palier de base et le risque A9 non plus. Non corrigé : base légale et destinataires d'un traitement d'adresses e-mail sont sa décision
- [ ] Registre RGPD §5/§6 : durée de conservation des journaux de pod à chiffrer — aucun collecteur dans `k8s/`, la borne est la rotation kubelet de Kapsule (`containerLogMaxSize`/`containerLogMaxFiles`), valeur à confirmer chez l'hébergeur (agent T5.8)
- [ ] CLAUDE.md, tableau « Services and ports (dev) » : `adminer` « dev uniquement » est vrai du compose, mais un Adminer à 0 réplica existe en préprod et prod (agent T5.8, cosmétique)

## Phase 6 — Hors plan (Q7 : spec séparée)
- [ ] 6.1 Sauvegardes Postgres : bucket + clés ESO / CronJob `pg_dump` (rétention 14 j) / restauration testée en préprod — à découper quand planifié
