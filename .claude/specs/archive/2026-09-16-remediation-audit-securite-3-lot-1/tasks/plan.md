# Plan — Remédiation du 3e audit de sécurité (2026-09-16) — lot 1

> **Lot 1 clos le 2026-09-16** : phases 0 à 3 livrées (phase 1 en production par v0.14.1, phases 2 et 3
> livrées par la release qui a suivi). Les phases 4, 5 et 6 continuent dans un second lot, sur une
> nouvelle branche de feature avec son propre `tasks/` (copié de ce plan, réduit aux phases restantes),
> pour que `develop` reçoive la remédiation par étapes sans jamais porter `tasks/`. Les checkpoints 2 et
> 3 se ferment sur la release du lot 1 (secrets d'environnement, journal observé sur un pod).

Source : audit complet du 2026-09-16 (session Claude), périmètre GitHub + code + Docker/nginx +
Kubernetes préprod/prod + tests en lecture seule sur `cp-ghostotof.com`. Numérotation reprise
ci-dessous : **A1** (élevé), **A2–A7** (moyens), **A8–A24** (faibles / informatifs). Les deux
audits précédents (C1–C8/I1–I9 du 2026-09-04, revue ADR 0003 du 2026-09-13) ne sont pas
rouverts.

Git flow (spec 0006) :
- **Phase 1 seule sur `hotfix/rate-limiter-storage`**, cut de `develop`, PR vers `develop`,
  puis `release/0.14.1` cut immédiatement (`fix:` → patch ; `develop` == `main` aujourd'hui,
  aucune feature en attente). Cette branche **ne porte pas `tasks/`**.
- Le reste sur `feature/security-audit-3-remediation` (cette branche), une branche de tâche
  empilée par phase, `develop` reçoit la feature une fois. `tasks/` s'archive dans
  `.claude/specs/archive/<date>-remediation-audit-securite-3/` à la PR de clôture.
- Trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>` sur chaque commit.

Gates à repasser à chaque checkpoint :
- backend : `make back-quality` (PHPStan max, Rector, Psalm, lsp:check) + `make back-test`
- frontend (si touché) : `make front-lint && make front-test && make front-build`
- k8s (si touché) : `kubectl kustomize k8s/overlays/prod` **et** `.../preprod` rendent sans erreur
- pipeline / scripts (si touchés) : `shellcheck -x --severity=warning tools/*.sh` + `actionlint`
- déploiement : chaque push de `release/*` redéploie la préprod ; `smoke-test-preprod` et
  `audit-preprod` sont les checks requis par le ruleset `main`.

---

## Décisions structurantes

- **D1 — A1, aucun état applicatif sur le système de fichiers du pod.** Le pool `cache.app`
  (dont hérite `cache.rate_limiter`, donc *tous* les limiteurs : `login_throttling`,
  `contact_form`, `account_password_setup`, `base_access`, `translation_assistant`, plus le
  cache de résultats Doctrine de `when@prod`) passe sur **`cache.adapter.doctrine_dbal`**,
  connexion `database_connection` (celle de `DATABASE_URL`). Raisons : partagé entre les deux
  réplicas (un `emptyDir` resterait par pod, les compteurs seraient divisés par deux), aucun
  service à ajouter (pas de Redis dans la stack), durable au redémarrage. La table
  `cache_items` est créée **par migration** (`item_id VARCHAR(255) PK, item_data BYTEA,
  item_lifetime INT NULL, item_time INT`), pas par le `createTable()` paresseux de
  l'adaptateur : le schéma reste sous Doctrine Migrations, comme tout le reste. `symfony/lock`
  n'est pas installé, les limiteurs tournent sans verrou (déjà le cas) : une course entre deux
  réplicas laisse passer une ou deux tentatives de plus, jamais un contournement.
  `cache.system` reste sur le filesystem : il est réchauffé au `docker build` et n'est lu qu'en
  prod. Un test de conteneur (`RateLimiterStorageTest`) pince l'invariant : le pool
  `cache.rate_limiter` n'est **jamais** un `FilesystemAdapter`.
- **D2 — A1, deux filets indépendants du stockage.** Une zone nginx `login` sur
  `/api/login_check` (les deux confs, miroirs) et un smoke test préprod qui **exerce** le
  throttling (6 logins erronés, le 6e doit répondre « Too many failed login attempts »). La
  CI ne peut pas voir ce défaut (son filesystem est inscriptible) ; seul un test contre un pod
  réel le voit, d'où le smoke test, pas un test PHPUnit.
- **D3 — A2/A4, réglages GitHub dans `tools/github-settings.sh`**, idempotents, jamais à la
  main : alertes Dependabot + correctifs de sécurité, Private Vulnerability Reporting,
  épinglage SHA imposé (`sha_pinning_required`), environnements `preprod` (branches
  `release/*`) et `production` (branche `main`) avec politique de branche. Les valeurs des
  secrets ne sont pas lisibles par l'API : leur déplacement (`gh secret set --env`) est une
  étape manuelle guidée, le script vérifie seulement la présence.
- **D4 — A3, le modèle de privilège du déployeur est documenté tel qu'il est**, pas tel qu'on
  le souhaitait : `jobs create` + `pods/log` + `externalsecrets create/update` = lecture de
  tout secret du namespace. Le retrait de `pods/exec` (audit C8) reste utile (pas de shell
  interactif) mais n'est pas une frontière. Le token durable
  (`kubernetes.io/service-account-token`) est remplacé par un **token lié à durée limitée**
  (`kubectl create token`), régénéré par un script de rotation ; le `Secret` bootstrap est
  retiré de `github-actions-rbac/`.
- **D5 — A5, Monolog + canal `security` en JSON sur stderr, niveau `info`.** Les événements
  journalisés : succès/échec de login (identifiant + IP, jamais le mot de passe), throttling,
  logout, émission d'un jeton de palier de base, rejet CSRF (les deux listeners), 403 sur
  `/api/backoffice`, et les actions d'administration (invitation, réinvitation, changement de
  rôle, changement de mot de passe, suppression) avec l'auteur. Un seul point d'entrée,
  `SecurityAuditLogger`, pour que la liste ci-dessus soit lisible d'un coup.
- **D6 — A7, le jeton de définition de mot de passe ne transite plus dans un chemin d'URL.**
  Lien e-mail `…/set-password#<token>` (le fragment n'est jamais envoyé au serveur, donc
  absent des access logs du frontend **et** de l'ingress) ; API `POST
  /api/account/password-setup/validate {token}` et `POST /api/account/password-setup
  {token, password}` (corps, jamais chemin). Compatibilité 48 h : l'ancienne route
  frontend `set-password/:token?` reste acceptée (param optionnel, le fragment prime) et
  traduit vers la nouvelle API ; l'ancien `GET …/{token}` disparaît.
- **D7 — A6, DNS mail** : DKIM TEM vérifié, DMARC `p=quarantine` (puis `reject` après un
  cycle de rapports propre), SPF `-all`, `rua` vers une adresse du domaine (Cloudflare Email
  Routing) plutôt que le Gmail personnel. Manuel, hors dépôt, mais avec critères
  d'acceptation `dig`.
- **D8 — erreurs sous `/api` toujours en JSON** (A15) : un listener `kernel.request` force
  `_format=json` pour tout chemin `/api` (via `CanonicalPath`), les 404 hors API Platform
  sortent en `application/problem+json` au lieu de la page HTML Symfony.

---

## Constats et couverture

| Réf. | Constat | Sévérité | Confiance | Traité par |
|---|---|---|---|---|
| A1 | Rate limiters Symfony inopérants en prod/préprod (FS read-only) | Élevée | Élevée (confirmé) | Phase 1 |
| A2 | Alertes Dependabot désactivées | Moyenne | Élevée | T2.1 |
| A3 | Rôle déployeur = lecture de tout secret ; token SA non expirant | Moyenne | Élevée | T2.3 |
| A4 | Secrets `KUBE_CONFIG_*` au niveau dépôt, environnements sans politique | Moyenne | Élevée | T2.2 |
| A5 | Aucun journal de sécurité (pas de Monolog) | Moyenne | Élevée | Phase 3 |
| A6 | DMARC `p=none`, SPF `~all` : `noreply@` usurpable | Moyenne | Moyenne | T4.3 |
| A7 | Jeton password-setup dans le chemin d'URL (access logs) | Faible-moyenne | Élevée | T4.1, T4.2 |
| A8 | Pas de zone nginx sur `/api/login_check` | Faible | Élevée | T1.3 |
| A9 | Pas de révocation JWT après logout | Faible | Élevée | Accepté, documenté dans l'ADR 0003 (T5.8) |
| A10 | Énumération d'utilisateurs par temps de réponse | Faible | Moyenne | T5.9 (hachage factice) |
| A11 | Adminer 4.8.1 en préprod (CVE connues) | Faible | Moyenne | T5.5 |
| A12 | `XDEBUG_TRIGGER_SECRET` jamais défini | Faible | Moyenne | T5.4 |
| A13 | `.dockerignore` sans `backend/config/jwt/` | Faible | Élevée | T5.3 |
| A14 | PVR désactivé, `sha_pinning_required=false`, pas de `security.txt` | Faible | Élevée | T2.1, T5.3 |
| A15 | Erreurs `/api` en HTML | Faible | Élevée | T5.1 |
| A16 | En-têtes manquants sur `/assets/`, `/healthz`, `/config.js` ; HSTS sans preload | Faible | Élevée | T5.2 |
| A17 | Namespaces sans PSA, `automountServiceAccountToken` actif | Faible | Élevée | T5.6 |
| A18 | RabbitMQ root sans FS read-only | Faible | Élevée | Accepté (incident v0.5.0) |
| A19 | Pas de sauvegarde documentée du PVC Postgres | Faible | Élevée | Phase 6 (différable) |
| A20 | Contact sans CAPTCHA (5/h/IP + honeypot) | Faible | Élevée | Accepté |
| A21 | `rua` DMARC expose le Gmail personnel | Faible | Élevée | T4.3 |
| A22 | `.claude/settings.local.json` non ignoré | Faible | Élevée | T5.3 |
| A23 | `framework.session: true` inutile ; pattern firewall `login` sans ancre | Info | Élevée | T5.7 |
| A24 | `og:title` avec le nom réel | Info | — | Choix documenté, rien à faire |
| A25 | IP cliente perdue : LB Scaleway sans proxy-protocol (Symfony voyait 2 IP de LB), sidecar nginx sans `100.64.0.0/10` (zones = 1 compteur global) — révélé par le smoke test de T1.4 | Élevée | Élevée (confirmé) | Phase 1, livré v0.14.1 (ADR 0005 D6/D7, `k8s/ingress-nginx-values.yaml`) |

---

## Graphe de dépendances

```
Phase 1 (hotfix, autonome)
  T1.1 cache.app → DBAL + migration cache_items
     ├── T1.2 test de conteneur (pince D1)
     ├── T1.5 prune quotidien (CronJob existant)
  T1.3 zone nginx login   (indépendant)
  T1.4 smoke test throttling (dépend d'un déploiement, exerce T1.1 + T1.3)
  T1.6 docs / ADR 0005

Phase 2 (GitHub / RBAC, indépendante du code)
  T2.1 réglages GitHub ──┐
  T2.2 environnements + secrets ── T2.3 rotation du token (le nouveau token va dans l'environnement)

Phase 3 (Monolog) : T3.1 bundle+config → T3.2 SecurityAuditLogger → T3.3 vérification préprod

Phase 4 : T4.1 API password-setup (contrat) → T4.2 frontend + lien e-mail ; T4.3 DNS (manuel, indépendant)

Phase 5 : T5.1 … T5.8 indépendantes entre elles (petites), T5.9 docs en dernier

Phase 6 (différable) : T6.1 sauvegardes Postgres
```

---

## Phase 1 — A1 : stockage des limiteurs hors du pod · priorité HAUTE · `hotfix/rate-limiter-storage`

> **Livrée le 2026-09-16, v0.14.1 en production** (PR #217 → develop, PR #218 → main). Le smoke
> test T1.4 a refusé les deux premiers déploiements et révélé A25 (proxy-protocol absent sur le LB,
> `100.64.0.0/10` absent des plages de confiance nginx), corrigé dans la même release : `helm
> upgrade` avec `k8s/ingress-nginx-values.yaml` (révision 2, lancé à la main, LB inchangé) et
> `real_ip_recursive on` + RFC 6598 dans les deux confs. Vérifié en prod : 6e login erroné freiné.

### Task 1.1 : `cache.app` sur Doctrine DBAL + migration `cache_items`
**Description :** `config/packages/cache.yaml` : `framework.cache.app: cache.adapter.doctrine_dbal`
(tous environnements — parité prod/dev/test, la migration est ainsi exercée en CI).
Migration `Version2026091700XXXX` créant `cache_items` (schéma de `DoctrineDbalAdapter`, cf. D1),
`down()` réversible (`DROP TABLE`). Retirer les pools Doctrine `when@prod` redondants si
`cache.app` suffit (garder `doctrine.system_cache_pool` sur `cache.system`).
**Acceptance :**
- [ ] `php bin/console debug:container cache.app` montre l'adaptateur DBAL ; `cache.rate_limiter` en hérite
- [ ] La migration crée `cache_items` ; `doctrine:schema:validate` ne signale rien de plus
- [ ] Un `POST /api/contact` en test écrit une ligne dans `cache_items` (test fonctionnel existant du 429 toujours vert)
**Verification :** `make back-quality && make back-test` ; `make db-migrate` ; en dev, 6 logins erronés → le 6e répond « Too many failed login attempts ».
**Dependencies :** aucune. **Fichiers :** `backend/config/packages/cache.yaml`, `backend/config/packages/doctrine.yaml`, `backend/migrations/…`. **Scope :** S.

### Task 1.2 : test de conteneur pinçant D1
**Description :** `tests/Security/RateLimiterStorageTest.php` : boot du kernel, récupère
`cache.rate_limiter` (et `cache.app`) via le conteneur de test, déballe un éventuel
`TraceableAdapter`, asserte `DoctrineDbalAdapter` et **jamais** `FilesystemAdapter`. Le
docblock raconte l'incident (FS read-only, `save()` = `false` silencieux, 14 × 401 en prod).
**Acceptance :** [ ] le test échoue si on remet `cache.app` par défaut, passe sinon.
**Verification :** `make back-test` ; inversion manuelle de la config pour voir le rouge.
**Dependencies :** T1.1. **Fichiers :** `backend/tests/Security/RateLimiterStorageTest.php`. **Scope :** XS.

### Task 1.3 : zone nginx `login` sur `/api/login_check`
**Description :** `limit_req_zone … zone=login:1m rate=10r/m` + `location ^~ /api/login_check
{ limit_req zone=login burst=5 nodelay; limit_req_status 429; try_files … }` dans
`docker/nginx/default.conf` **et** `k8s/base/backend-nginx.conf` (miroirs). Commentaire :
filet en amont de php-fpm, le vrai plafond reste `login_throttling` (5/15 min par IP+identifiant).
**Acceptance :** [ ] les deux fichiers portent la zone à l'identique ; [ ] en dev, 16 POST rapides → nginx 429 à partir du 16e (10 + burst 5).
**Verification :** `docker compose exec web nginx -t` ; boucle `curl` en dev ; `kubectl kustomize` préprod/prod (la ConfigMap change de hash → rollout, issue #18).
**Dependencies :** aucune. **Fichiers :** 2 confs nginx. **Scope :** S.

### Task 1.4 : smoke test préprod qui exerce le throttling
**Description :** dans `smoke-test-preprod`, après le check `/api/me`, 6 `POST /api/login_check`
(Basic Auth préprod via `-K` comme `audit-prod.sh`, `X-Requested-With`, identifiant
`smoke-throttling-probe`) ; échec du job si le 6e corps ne contient pas « Too many failed
login attempts ». Extraire dans `tools/smoke-login-throttling.sh` testable hors ligne
(`tools/tests/`) pour rester sous shellcheck/actionlint.
**Acceptance :** [ ] job rouge sur la préprod actuelle (avant T1.1), vert après ; [ ] `rollback-preprod` se déclenche sur ce rouge.
**Verification :** run du pipeline sur `release/0.14.1` ; `tools/tests/smoke-login-throttling.test.sh`.
**Dependencies :** T1.1, T1.3 (pour être vert). **Fichiers :** `.github/workflows/pipeline.yml`, `tools/smoke-login-throttling.sh`, `tools/tests/…`. **Scope :** S.

### Task 1.5 : purge quotidienne de `cache_items`
**Description :** `DoctrineDbalAdapter` est `PruneableInterface` : ajouter `php bin/console
cache:pool:prune` au CronJob quotidien existant (`messenger-purge-cronjob.yaml`, renommé
`housekeeping` si le nom devient trompeur — attention, il est dans `kustomization.yaml`,
le rename est un replace propre). Sans lui, une ligne par (IP, limiteur) s'accumule.
**Acceptance :** [ ] le CronJob exécute les deux commandes ; [ ] `cache:pool:prune` fonctionne en dev sur la table.
**Verification :** `kubectl kustomize` ; exécution manuelle en dev.
**Dependencies :** T1.1. **Fichiers :** `k8s/base/messenger-purge-cronjob.yaml` (+ `kustomization.yaml` si rename). **Scope :** XS.

### Task 1.6 : ADR 0005 + CLAUDE.md
**Description :** `docs/adr/0005-etat-hors-du-pod.md` : contexte (readOnlyRootFilesystem +
FilesystemAdapter = échec silencieux), décision D1/D2, alternatives écartées (emptyDir par
pod, Redis), conséquences (prune, migration). CLAUDE.md « Deployment invariants » : nouvel
invariant + pointeur sur `RateLimiterStorageTest` et le smoke test.
**Acceptance :** [ ] ADR relu ; [ ] CLAUDE.md cite le test et le smoke test.
**Dependencies :** T1.1–T1.5. **Scope :** S.

### CHECKPOINT 1 — hotfix en prod
- [ ] gates backend verts ; `kubectl kustomize` prod/préprod verts
- [ ] `release/0.14.1` : `smoke-test-preprod` vert **avec** le nouveau test de throttling
- [ ] merge sur `main`, `deploy-prod` vert, `finalize-release` a posé `v0.14.1`
- [ ] vérification manuelle sur `cp-ghostotof.com` : 6 logins erronés → 6e = « Too many failed login attempts »
- [ ] décision : entrée dans le journal des incidents public (question Q6)

---

## Phase 2 — A2/A3/A4 : GitHub et RBAC · priorité MOYENNE

### Task 2.1 : réglages GitHub de sécurité dans `tools/github-settings.sh`
**Description :** nouvelles étapes idempotentes : `PUT …/vulnerability-alerts`,
`PUT …/automated-security-fixes`, `PUT …/private-vulnerability-reporting`,
`PUT …/actions/permissions` avec `sha_pinning_required: true` (garder `allowed_actions`
tel quel, question Q8). `SECURITY.md` : le PVR devient le canal principal, le formulaire de
contact le canal de repli.
**Acceptance :** [ ] `gh api repos/…/vulnerability-alerts` → 204 ; [ ] PVR `enabled: true` ; [ ] `sha_pinning_required: true` ; [ ] le script rejoué ne change rien.
**Verification :** exécution du script + lectures `gh api` ; `shellcheck`.
**Dependencies :** aucune. **Fichiers :** `tools/github-settings.sh`, `SECURITY.md`. **Scope :** S.

### Task 2.2 : secrets d'environnement + politique de branche
**Description :** script : environnements `preprod` (politique `custom_branch_policies`,
motif `release/*`) et `production` (`main`) ; vérification de la présence des secrets
d'environnement `KUBE_CONFIG_PREPROD`, `PREPROD_BASIC_AUTH` (preprod), `KUBE_CONFIG_PROD`,
`RELEASE_DEPLOY_KEY` (production) avec un avertissement tant qu'ils sont encore au niveau
dépôt. Pipeline : `environment: preprod` sur `smoke-test-preprod`, `audit-preprod`,
`rollback-preprod` ; `environment: production` sur `finalize-release`. Étape manuelle (README
du script) : `gh secret set <NOM> --env <env>` puis `gh secret delete <NOM>` au niveau dépôt.
**Acceptance :** [ ] `gh api …/environments/production` montre la politique `main` ; [ ] aucun secret de déploiement au niveau dépôt ; [ ] un run `release/*` complet est vert ; [ ] un run sur `feature/*` ne peut pas référencer `KUBE_CONFIG_PROD` (test : job factice temporaire, non commité).
**Verification :** `actionlint` ; run de pipeline sur une branche `release/*` de test ou la prochaine release.
**Dependencies :** T2.1 (même script). **Fichiers :** `tools/github-settings.sh`, `.github/workflows/pipeline.yml`, `k8s/README.md`. **Scope :** M.

### Task 2.3 : token du déployeur lié et rotation
**Description :** `tools/rotate-deployer-token.sh <preprod|prod>` : `kubectl create token
github-actions-deployer -n <ns> --duration=<Q4>`, assemblage du kubeconfig (endpoint + CA
depuis `kubectl config view --raw`), `gh secret set KUBE_CONFIG_<ENV> --env <env>`. Retirer
`secret-token.yaml` de `github-actions-rbac/` et supprimer le `Secret` durable du cluster.
`k8s/README.md` §4 : procédure, périodicité, **et** le modèle de privilège réel (D4).
CLAUDE.md : corriger la phrase sur `pods/exec`.
**Acceptance :** [ ] le déploiement passe avec le token lié ; [ ] `kubectl -n prod get secret github-actions-deployer-token` → NotFound ; [ ] README et CLAUDE.md décrivent D4.
**Verification :** `shellcheck` ; un `deploy-preprod` vert avec le nouveau token.
**Dependencies :** T2.2. **Fichiers :** `tools/rotate-deployer-token.sh`, `k8s/base/github-actions-rbac/{kustomization,secret-token}.yaml`, `k8s/README.md`, `.claude/CLAUDE.md`. **Scope :** M.

### CHECKPOINT 2
- [ ] `shellcheck` + `actionlint` verts ; script rejoué sans diff
- [ ] un déploiement préprod complet vert avec secrets d'environnement + token lié
- [ ] alertes Dependabot visibles dans l'onglet Security

---

## Phase 3 — A5 : journal de sécurité · priorité MOYENNE

### Task 3.1 : Monolog, canal `security`, JSON sur stderr
**Description :** `composer require symfony/monolog-bundle` (recette officielle), `monolog.yaml` :
prod/préprod → handler `stream: php://stderr`, `formatter: monolog.formatter.json`,
`channels: ['security', 'app']` à `info`, le reste à `warning` (`LOG_LEVEL` de la préprod
respecté via `%env(default:warning:LOG_LEVEL)%`) ; dev/test = recette. Vérifier que
`error_log = /proc/self/fd/2` de `php.prod.ini` ne double pas les lignes.
**Acceptance :** [ ] en dev, un login raté produit une ligne JSON `security` ; [ ] `LOG_LEVEL=debug` en préprod donne le détail, prod reste sobre.
**Verification :** `make back-quality && make back-test` ; `docker compose logs backend`.
**Dependencies :** aucune. **Fichiers :** `backend/composer.json/lock`, `backend/config/packages/monolog.yaml`, `backend/config/bundles.php`. **Scope :** S.

### Task 3.2 : `SecurityAuditLogger`
**Description :** `Security/Authentication/Infrastructure/Log/SecurityAuditLogger` (canal
`security`, `#[WithMonologChannel]`) abonné à `LoginSuccessEvent`, `LoginFailureEvent`
(distingue `TooManyLoginAttemptsAuthenticationException`), `LogoutEvent`, plus une méthode
par action d'administration appelée depuis `CpgUserInviter`, `CpgUserRoleAdministrator`,
`CpgUserAdministrator`, `PasswordSetupService` (activation), `BaseAccessController`
(émission), et les deux listeners CSRF (rejet, avec le chemin). Champs : événement,
identifiant visé, auteur, IP (`getClientIp()`), chemin. **Jamais** un mot de passe, un jeton
ni un corps de requête. Tests unitaires avec `Psr\Log\Test\TestLogger` ; un test fonctionnel
par événement clé (login raté, 403 backoffice, invitation).
**Acceptance :** [ ] chaque événement de D5 a un test ; [ ] PHPStan max vert ; [ ] aucune donnée sensible dans les contextes (test dédié sur le contexte du login raté).
**Verification :** `make back-quality && make back-test`.
**Dependencies :** T3.1. **Fichiers :** 1 classe + 6 sites d'appel + tests. **Scope :** M (découper en 3.2a auth events / 3.2b actions admin si > 5 fichiers).

### Task 3.3 : vérification en préprod
**Description :** après déploiement, `kubectl -n preprod logs deploy/backend -c php-fpm | jq`
montre les lignes `security` d'un login raté et d'un 403. Documenter la requête `jq` dans
`k8s/README.md` (rubrique observabilité).
**Acceptance :** [ ] lignes JSON lisibles avec `channel: security`.
**Dependencies :** T3.2 + une release. **Scope :** XS.

### CHECKPOINT 3
- [ ] gates backend verts ; logs JSON observés en préprod

---

## Phase 4 — A6/A7 : parcours mot de passe et e-mail · priorité MOYENNE

### Task 4.1 : API password-setup, jeton dans le corps
**Description :** `AccountPasswordSetupStatusResource` → `POST /api/account/password-setup/validate`
(`{token}`, 204/404/410) ; `AccountPasswordSetupResource` → `POST /api/account/password-setup`
(`{token, password}`, 204). Supprimer le `GET …/{token}`. Ajuster :
`PasswordSetupRateLimitRequestListener` (préfixe `/api/account/password-setup`, POST seul),
`CsrfCookieRequestSubscriber::EXCLUDED_PATH_PREFIXES`, les deux confs nginx (`location ^~
/api/account/password-setup`), `ApiRouteExposureTest::PUBLIC_PATHS` (nouvelles entrées
justifiées, ancienne retirée), tests fonctionnels.
**Acceptance :** [ ] `debug:router` : plus aucune route `password-setup/{token}` ; [ ] 11e POST → 429 avant validation (test C1 conservé) ; [ ] `ApiRouteExposureTest` vert.
**Verification :** `make back-quality && make back-test`.
**Dependencies :** aucune (contrat défini ici, consommé en T4.2). **Fichiers :** 2 ressources, provider/processor, 2 listeners, 2 confs nginx, tests. **Scope :** M.

### Task 4.2 : lien e-mail en fragment + frontend
**Description :** `SendAccountInvitationHandler` : `…/{locale}/set-password#<token>`
(`AccountInvitationTemplateTest` à jour). Frontend : route `set-password/:token?`,
`SetPasswordPage.vue` lit `location.hash` d'abord, `route.params.token` sinon (48 h de
compatibilité), et **efface le fragment** après lecture (`history.replaceState`) pour qu'il
ne survive pas dans l'historique ; `HttpAccountRepository` → les deux POST ; specs Vitest.
**Acceptance :** [ ] parcours bout en bout en dev (invitation → e-mail → page → mot de passe) ; [ ] l'URL affichée ne contient plus le jeton après chargement ; [ ] ancien lien `/set-password/<token>` fonctionne encore.
**Verification :** `make back-test` ; `make front-lint && make front-test && make front-build`.
**Dependencies :** T4.1. **Fichiers :** handler + template test, `router/index.ts`, `SetPasswordPage.vue`, `HttpAccountRepository.ts`, specs. **Scope :** M.

### Task 4.3 : DNS mail (manuel)
**Description :** dans Scaleway TEM, vérifier que DKIM est publié pour `cp-ghostotof.com` (sinon
ajouter l'enregistrement) ; Cloudflare DNS : `_dmarc` → `v=DMARC1; p=quarantine;
rua=mailto:dmarc@cp-ghostotof.com` (alias Email Routing vers la boîte de ton choix), SPF
`-all` une fois confirmé que seuls TEM et Cloudflare émettent ; passer à `p=reject` après
deux semaines de rapports propres (tâche de suivi, hors dépôt).
**Acceptance :** [ ] `dig TXT _dmarc.cp-ghostotof.com` → `p=quarantine`, `rua` sans Gmail ; [ ] une invitation reçue sur Gmail affiche `DKIM: PASS`, `DMARC: PASS` dans « Afficher l'original ».
**Dependencies :** aucune. **Scope :** manuel.

### CHECKPOINT 4
- [ ] gates backend + frontend verts ; parcours d'invitation validé en préprod
- [ ] DNS vérifié par `dig` et par un e-mail réel

---

## Phase 5 — Hygiène code et infra · priorité FAIBLE (petites tâches indépendantes)

### Task 5.1 : erreurs `/api` en JSON (D8)
`Shared/Infrastructure/Http/ApiJsonFormatRequestListener` (priorité haute, `CanonicalPath`,
`setRequestFormat('json')` sur `/api`) ; test fonctionnel : `GET /api/inexistant` →
`application/problem+json`, 404. **Scope :** XS. **Fichiers :** 1 listener + 1 test.

### Task 5.2 : en-têtes nginx complets sur toutes les locations
`docker/node/nginx.conf` : ajouter CSP + HSTS sur `/assets/`, `/healthz`, `/config.js`
(K8s backend `/healthz` a déjà la CSP, pas HSTS) ; `audit-prod.sh` vérifie aussi `/config.js`
et un asset. `preload` HSTS : question Q3. **Scope :** S. **Fichiers :** 2 confs nginx, `tools/audit-prod.sh`.

### Task 5.3 : fichiers d'hygiène
`.dockerignore` += `backend/config/jwt/`, `backend/.env.test` ; `.gitignore` +=
`/.claude/settings.local.json` ; `frontend/public/.well-known/security.txt` (`Contact:` = URL
du formulaire ou du PVR, `Expires`, `Preferred-Languages: fr, en`, `Canonical`) servi par
la location `^~ /.well-known/` existante ; `audit-prod.sh` attend 200 dessus. **Scope :** XS.

### Task 5.4 : Xdebug préprod (Q2 : conservé et sécurisé)
`ExternalSecret` préprod `preprod-backend-xdebug-trigger` → `XDEBUG_TRIGGER_SECRET` dans
`backend-secrets` (valeur aléatoire longue, créée dans Scaleway Secret Manager avant le
déploiement, sinon `backend-secrets` reste incomplet et le pod ne démarre pas) ; patch
préprod montant un `emptyDir` sur `var/profiler` (sinon le profil ne s'écrit jamais) ;
`k8s/README.md` : comment déclencher un profil (`XDEBUG_TRIGGER=<secret>` en cookie ou
en-tête) et le récupérer (`kubectl cp`). Vérifier qu'un `XDEBUG_TRIGGER` sans la bonne
valeur ne profile rien. **Scope :** S.

### Task 5.5 : Adminer 5.5.1
`ADMINER_TAG=5.5.1-standalone` (`.env`, `versions.lock`, `k8s/base/adminer.yaml`) ; vérifier
`readOnlyRootFilesystem` avec l'image 5 (chemins de sessions). Préprod à `replicas: 0` par
défaut comme la prod ? Question Q5. **Scope :** XS.

### Task 5.6 : durcissement Kubernetes
Labels PSA sur les deux namespaces (`enforce: baseline`, `audit`/`warn: restricted` —
RabbitMQ interdit `restricted` en enforce, A18) ; `automountServiceAccountToken: false` sur
tous les pod specs (Deployments, Jobs, CronJobs). Rollout réel en préprod obligatoire
(règle « Postgres/RabbitMQ carry state on a PVC »). **Scope :** M (9 manifests, un seul
changement chacun).

### Task 5.7 : nettoyage Symfony
`framework.session.enabled: false` (vérifier qu'aucun composant ne démarre de session :
tests fonctionnels verts) ; pattern du firewall `login` → `^/api/login_check$` ; étendre
`AccessControlAnchoringTest` aux patterns de firewalls. **Scope :** XS.

### Task 5.9 : hachage factice sur identifiant inconnu (A10)
Listener sur `LoginFailureEvent` (firewall `login`) : si l'exception est une
`UserNotFoundException` (ou son masquage `BadCredentialsException` avec `previous`), calculer
un hachage d'une chaîne fixe avec le hasher de `CpgUser` pour égaliser le temps de réponse
entre identifiant inconnu et mot de passe faux. Test fonctionnel : les deux cas répondent 401
avec le même corps ; test unitaire : le hasher est appelé une fois pour un inconnu, jamais pour
un mot de passe faux. **Scope :** XS. **Fichiers :** 1 listener + 2 tests.

### Task 5.8 : `docs/rgpd` et CLAUDE.md
Mettre à jour `docs/rgpd/` (nouveaux journaux de sécurité : quoi, combien de temps —
rétention des logs de pod), CLAUDE.md (Monolog, password-setup en POST, en-têtes, réglages
GitHub, RBAC réel), `k8s/README.md` ; ADR 0003 : A9 (pas de révocation JWT, fenêtre d'une
heure, cookie `HttpOnly`/`Secure`) consigné comme risque accepté. **Scope :** S. **Dépend de**
toutes les tâches précédentes.

### CHECKPOINT 5
- [ ] gates backend + frontend + k8s + scripts verts
- [ ] `audit-prod.sh` étendu vert sur la préprod
- [ ] PR de clôture : archive `tasks/` sous `.claude/specs/archive/`

---

## Phase 6 — Différable

### Task 6.1 : sauvegardes Postgres (A19)
CronJob `pg_dump` quotidien vers Scaleway Object Storage (bucket dédié, clés via ESO,
rétention 14 j), procédure de restauration testée en préprod. **Scope :** L → à découper
(bucket + secret / CronJob / restauration documentée) quand il sera planifié. Question Q7.

---

## Risques et mitigations

| Risque | Impact | Mitigation |
|---|---|---|
| La migration `cache_items` rate en prod (droits DDL) | Élevé (release bloquée, fail-closed) | Même utilisateur que les autres migrations ; testée en CI et préprod avant |
| Une ligne `cache_items` par (IP, limiteur) fait grossir la table | Moyen | T1.5 prune quotidien ; lifetime = fenêtre glissante (≤ 1 h) |
| Le smoke test consomme le throttling de l'IP du runner pendant 15 min | Faible | Identifiant dédié ; l'audit ne se logue jamais |
| Déplacer les secrets d'environnement casse un run en cours | Moyen | Faire T2.2 entre deux releases, vérifier sur la suivante |
| Token lié : durée max imposée par Kapsule inconnue | Moyen | Q4 ; repli = 8760 h si accepté, sinon rotation plus fréquente |
| Ancien lien d'invitation en circulation à la sortie de T4.2 | Faible | Route `:token?` conservée 48 h ; les jetons expirent en 48 h |
| `automountServiceAccountToken: false` casse un pod qui parlait à l'API | Faible | Aucun ne le fait (vérifié : ESO est hors namespace) ; rollout préprod |
| Publication du plan avant le correctif A1 (dépôt public) | Élevé | Q1 : ne pousser cette branche qu'après `v0.14.1` en prod |

## Questions ouvertes

- **Q1 — Divulgation.** **Décidé le 2026-09-16** : le hotfix part d'abord (branche sans
  `tasks/`, message de commit sobre), cette branche n'est poussée qu'après `v0.14.1` en prod.
- **Q2 — Xdebug en préprod** : ~~conserver ou retirer ?~~ **Décidé le 2026-09-16 : conservé et sécurisé** (T5.4).
- **Q3 — HSTS `preload`** : **décidé le 2026-09-16 : non** (engagement sur tous les sous-domaines,
  retrait long, gain marginal derrière le 308 + HSTS un an). À reconsidérer plus tard.
- **Q4 — Durée du token lié** : **décidé : 90 jours** (rotation trimestrielle), à confronter à la
  durée max acceptée par Kapsule lors de T2.3.
- **Q5 — Adminer préprod** : **décidé : `replicas: 0`** par défaut, comme en prod (T5.5).
- **Q6 — Journal des incidents** : **décidé : oui**, entrée post-mortem A1 à saisir dans le
  backoffice après `v0.14.1` (invariant : « aucun état sur le filesystem du pod »).
- **Q7 — Sauvegardes** : **décidé : spec séparée** (Phase 6 reste un rappel, non planifiée ici).
- **Q8 — `allowed_actions`** : **décidé : `all` conservé**, épinglage SHA imposé (T2.1).
- **Q9 — A9/A10** : **décidé le 2026-09-16** : A9 accepté et documenté dans l'ADR 0003 (T5.8) ;
  A10 corrigé en phase 5 (T5.9, hachage factice).
