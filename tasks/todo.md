# TODO — Remédiation du 3e audit de sécurité (2026-09-16) — lot 2

Détail, décisions (D1–D8), risques et questions ouvertes (Q1–Q9) : `tasks/plan.md`.

Branche : `feature/security-audit-3-remediation-lot-2` (phases 4 et 5), une branche de tâche
empilée par phase. Lot 1 (phases 0–3) livré par v0.14.1 et v0.15.0, archivé.
T4.1 et T4.2 demandent Fable : confirmation de Christophe avant de lancer l'agent. Trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

Gates par checkpoint : `make back-quality && make back-test` ; frontend (si touché)
`make front-lint && make front-test && make front-build` ; k8s (si touché) `kubectl kustomize`
prod **et** préprod ; scripts/pipeline (si touchés) `shellcheck -x --severity=warning` + `actionlint`.

## Point de reprise (2026-09-19)

- **Tout est local** : la branche `feature/security-audit-3-remediation-lot-2` n'est **pas poussée**,
  aucune PR, rien en préprod ni en prod pour ce lot. Ne pousser qu'à la demande explicite de Christophe.
  Rebasée le 2026-09-19 sur `develop` (`771f394`) — elle n'apporte toujours que `tasks/`.
- Production : **v0.15.1**, livrée le 2026-09-19 (hotfix d'intitulé, sans rapport avec ce lot).
  Le lot 1 reste intégralement livré par v0.14.1 et v0.15.0. `develop` = `main` + les quatre montées
  de version Dependabot du 2026-09-19 (CodeQL 4.38.0 ; backend patch api-platform/doctrine/symfony ;
  frontend mineur vite 8.3.0 ; `unplugin-icons` 24, rendu des icônes vérifié avant fusion).
- **Décision prise le 2026-09-21** : Fable là où le plan le demande (T4.1, T4.2), Opus pour le reste,
  tâches enchaînées par délégation dans l'ordre conseillé (T4.1 → T4.2 → phase 5 ; T4.3 manuel en parallèle).
- Méthode : un agent par tâche, dans l'ordre du plan, relecture du diff et gates par l'orchestrateur,
  un commit par tâche ; une branche de tâche par phase, empilée sur celle-ci, fusionnée **en local**
  tant que Christophe n'a pas demandé de push.
- Secrets : toute commande qui pose une valeur secrète se lance dans un terminal séparé, par fichier
  ou stdin, jamais via `!` ni `--body`.
- **Reliquat manuel du lot 1 : intégralement clos le 2026-09-19** (R.1 à R.7), checkpoint 2 compris.
  Le lot 1 n'a donc plus rien d'ouvert, ni en code ni à la main. Aucun de ces sept points n'était un
  changement de code : ni branche, ni release.
- **Reste donc uniquement le lot 2 lui-même** (phases 4 et 5), dont pas une ligne n'est écrite.
- **Reprise prévue le lundi 2026-09-21.** Évaluation du 2026-09-19 : rien dans le lot 2 ne justifie de
  travailler en urgence. Le lot 1, en production, couvre ce qui était réellement ouvert (limiteurs
  opérants, IP cliente correcte, journal d'audit) ; le lot 2 durcit, il ne bouche pas de trou béant.
  **Une seule mesure d'ici là : ne pas émettre d'invitation de compte** — A7 n'a de surface que pendant
  la vie d'un jeton, et un jeton n'existe que si une invitation est partie. Non vérifiés faute d'accès
  depuis la session : l'état DNS de SPF/DMARC (résolution sortante bloquée) et le nombre de jetons
  actifs en prod (lecture en base refusée).
- **Ordre conseillé à la reprise**, si l'on suit le risque plutôt que la numérotation : T4.1 puis T4.2
  (le seul point à surface réelle), T4.3 en parallèle car manuel et lent (deux semaines de rapports
  DMARC avant `p=reject`), la phase 5 ensuite.

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
- [ ] 5.2 CSP + HSTS sur `/assets/`, `/healthz`, `/config.js` (front) et HSTS sur `/healthz` (backend k8s) ; `audit-prod.sh` vérifie `/config.js` + un asset  ; pas de `preload` (Q3)
- [ ] 5.3 `.dockerignore` (`backend/config/jwt/`, `backend/.env.test`) ; `.gitignore` (`/.claude/settings.local.json`) ; `frontend/public/.well-known/security.txt` + check dans `audit-prod.sh`
- [ ] 5.4 Xdebug préprod (Q2 : conservé) : `XDEBUG_TRIGGER_SECRET` via ESO + `emptyDir` `var/profiler` + procédure dans `k8s/README.md` ; un déclencheur sans la bonne valeur ne profile rien
- [ ] 5.5 Adminer `5.5.1-standalone` (`.env`, `versions.lock`, `k8s/base/adminer.yaml`) ; préprod `replicas: 0` par défaut (Q5)
- [ ] 5.6 Labels PSA (`enforce: baseline`, `audit`/`warn: restricted`) sur les deux namespaces ; `automountServiceAccountToken: false` sur tous les pod specs ; rollout réel en préprod
- [ ] 5.7 `framework.session.enabled: false` ; firewall `login` → `^/api/login_check$` ; `AccessControlAnchoringTest` étendu aux firewalls
- [ ] 5.9 Hachage factice sur identifiant inconnu (A10) : listener `LoginFailureEvent` + tests (temps égalisés, 401 identiques) ; couvre aussi le compte en attente d'activation (hachage vide)
- [ ] 5.10 `k8s/README.md` §2bis : documenter la **rotation** du mot de passe Basic Auth (nouvelle version via `scw secret version create <id> data=@fichier`, pas `secret create` ; `force-sync` ESO ; secret GitHub d'environnement posé par fichier) et remplacer `--body '<identifiant>:<mot-de-passe>'` et `data="$(cat …)"` par des lectures de fichier, en précisant « terminal séparé, jamais dans une session d'agent »
- [ ] 5.8 `docs/rgpd/` (rétention des journaux), CLAUDE.md, `k8s/README.md` à jour ; A9 (pas de révocation JWT) consigné comme risque accepté dans l'ADR 0003 (Q9)
- [ ] **CHECKPOINT 5** — tous gates verts ; `audit-prod.sh` étendu vert en préprod ; PR de clôture vers `develop` avec archivage de `tasks/` sous `.claude/specs/archive/<date>-remediation-audit-securite-3-lot-2/`

## Phase 6 — Hors plan (Q7 : spec séparée)
- [ ] 6.1 Sauvegardes Postgres : bucket + clés ESO / CronJob `pg_dump` (rétention 14 j) / restauration testée en préprod — à découper quand planifié
