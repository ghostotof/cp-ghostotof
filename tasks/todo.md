# TODO — Remédiation du 3e audit de sécurité (2026-09-16)

Détail, décisions (D1–D8), risques et questions ouvertes (Q1–Q9) : `tasks/plan.md`.

Branches : Phase 1 sur `hotfix/rate-limiter-storage` (sans `tasks/`, release `0.14.1` cut
aussitôt) ; Phases 2–5 sur `feature/security-audit-3-remediation`, une branche de tâche
empilée par phase. Trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

Gates par checkpoint : `make back-quality && make back-test` ; frontend (si touché)
`make front-lint && make front-test && make front-build` ; k8s (si touché) `kubectl kustomize`
prod **et** préprod ; scripts/pipeline (si touchés) `shellcheck -x --severity=warning` + `actionlint`.

## Phase 0 — Préparation
- [x] 0.1 `feature/security-audit-3-remediation` créée depuis `develop` (097a0ce)
- [x] 0.2 Q1–Q9 tranchées le 2026-09-16 (voir `tasks/plan.md`) ; cette branche n'est poussée qu'après `v0.14.1` en prod (Q1)
- [x] **CHECKPOINT 0** — plan validé par Christophe le 2026-09-16

## Phase 1 — A1 : stockage des limiteurs hors du pod · HAUTE · `hotfix/rate-limiter-storage` — **LIVRÉE, v0.14.1 (2026-09-16)**
- [x] 1.1 `framework.cache.app: cache.adapter.doctrine_dbal` (tous envs) + migration `cache_items` (`Version20260916180000`)
- [x] 1.2 `tests/Security/RateLimiterStorageTest`
- [x] 1.3 Zone nginx `login` (10 r/m, burst 10) dans les deux confs
- [x] 1.4 `tools/smoke-login-throttling.sh` (+ test hors ligne) dans `smoke-test-preprod` — a refusé deux déploiements et révélé A25
- [x] 1.5 `cache:pool:prune` dans le CronJob de ménage (nom conservé)
- [x] 1.6 ADR 0005 (D1–D7) + CLAUDE.md
- [x] 1.7 A25 : `100.64.0.0/10` + `real_ip_recursive on` (deux confs) ; `k8s/ingress-nginx-values.yaml` (proxy-protocol v2) appliqué par `helm upgrade` (révision 2) ; README k8s
- [x] **CHECKPOINT 1** — gates verts ; préprod verte au 3e run (throttling au 6e) ; `v0.14.1` posé ; prod vérifiée (6e login erroné → « Too many ») ; reste : entrée post-mortem publique (Q6, éditorial)

## Phase 2 — A2/A3/A4 : GitHub et RBAC · MOYENNE
- [x] 2.1 `tools/github-settings.sh` : alertes Dependabot + security updates, PVR, `sha_pinning_required` ; `SECURITY.md` (PVR = canal principal)
- [x] 2.2 Environnements `preprod` (`release/*`) et `production` (`main`) avec politique de branche (étape d du script) ; étape k : vérification de présence des secrets d'environnement ; `environment:` sur `smoke-test-preprod`, `audit-preprod`, `rollback-preprod`, `finalize-release` ; correctifs Dependabot automatiques désactivés (option 1 : leurs PR visent `main`, jamais mergeable hors flux de release), alertes conservées
  - **Reste à faire à la main** (l'API ne relit pas la valeur d'un secret), entre deux releases, dans cet ordre — détail et vérifications dans le README en bas de `tools/github-settings.sh` :
    ```bash
    gh secret set KUBE_CONFIG_PREPROD --env preprod    < kubeconfig-preprod
    gh secret set PREPROD_BASIC_AUTH  --env preprod    --body '<identifiant>:<mot-de-passe>'
    gh secret set KUBE_CONFIG_PROD    --env production < kubeconfig-prod
    gh secret set RELEASE_DEPLOY_KEY  --env production < release_bot
    ```
    puis `tools/github-settings.sh` (étape k verte côté environnement), puis — **seulement après un run de release complet vert** — `gh secret delete` des quatre mêmes noms au niveau dépôt, et un dernier passage du script.
- [x] 2.3 `tools/rotate-deployer-token.sh` (token lié 2160h = 90 j, Q4 ; `exp` du JWT décodé pour détecter une troncature par le control-plane ; `can-i create jobs`=yes / `create pods/exec`=no avant publication ; jeton par fichier/stdin seulement) + test hors ligne dans `tools-tests` ; `secret-token.yaml` retiré du kustomization et supprimé ; `k8s/README.md` §4 et CLAUDE.md décrivent le privilège réel (D4) ; `role.yaml`/`serviceaccount.yaml` commentés en conséquence
  - **Reste à faire à la main** (le mode auto refuse les écritures cluster/GitHub ; seul le `--dry-run` a été joué contre `cp-ghostotof-preprod`), dans cet ordre :
    1. `tools/rotate-deployer-token.sh preprod` puis `… prod` — **lire l'avertissement `TRONQUÉE` s'il apparaît** : la durée max de Kapsule est inconnue, c'est la durée accordée qui fixe le calendrier (Q4 à confronter ici) ;
    2. `gh secret set PREPROD_BASIC_AUTH --env preprod …` et `RELEASE_DEPLOY_KEY --env production` (reste de 2.2), puis `tools/github-settings.sh` (étape k : 4 présents dans l'environnement) ;
    3. un run de release complet vert avec les secrets d'environnement (= CHECKPOINT 2) ;
    4. `kubectl --context cp-ghostotof-preprod -n preprod delete secret github-actions-deployer-token` et idem `prod` ; `kubectl -n prod get secret github-actions-deployer-token` → NotFound ;
    5. `gh secret delete` des quatre noms au niveau dépôt, dernier passage de `tools/github-settings.sh` ;
    6. deux rappels calendaires à la date « Prochaine rotation » affichée par le script.
- [ ] **CHECKPOINT 2** — shellcheck/actionlint verts ; script rejoué sans diff ; un `deploy-preprod` vert avec secrets d'environnement + token lié ; onglet Security montre les alertes

## Phase 3 — A5 : journal de sécurité · MOYENNE
- [x] 3.1 `symfony/monolog-bundle` 4.1 ; `monolog.yaml` prod/préprod = JSON sur stderr, canaux `security` (Symfony) et `security_audit` (le nôtre, canal déclaré — nommé ainsi plutôt qu'`app` pour ne pas se confondre avec le canal par défaut) à `info` sur leur propre handler, reste à `%env(default:app.log_level:LOG_LEVEL)%` (défaut `warning`, `debug` dans l'image préprod) ; handler `main` excluant les deux canaux = pas de doublon ; pas de doublon non plus avec `error_log`
- [x] 3.2a `SecurityAuditLogger` : login succès/échec/throttling, logout, base-access, rejets CSRF, 403 backoffice — tests `TestLogger` + fonctionnels ; aucun secret dans les contextes (test dédié)
- [ ] 3.2b Actions d'administration journalisées avec l'auteur : invitation/réinvitation, rôle, mot de passe, suppression, activation — tests
- [ ] 3.3 Vérification préprod (`kubectl logs … | jq`) documentée dans `k8s/README.md`
- [ ] **CHECKPOINT 3** — gates backend verts ; lignes JSON `security` observées en préprod

## Phase 4 — A6/A7 : parcours mot de passe et e-mail · MOYENNE
- [ ] 4.1 API : `POST /api/account/password-setup/validate {token}` + `POST /api/account/password-setup {token, password}` ; `GET …/{token}` supprimé ; listeners rate-limit/CSRF, 2 confs nginx, `ApiRouteExposureTest::PUBLIC_PATHS`, tests fonctionnels (429 avant validation conservé)
- [ ] 4.2 Lien e-mail `…/set-password#<token>` ; route `set-password/:token?` ; page lit le fragment (puis l'efface via `replaceState`), repli sur le param 48 h ; `HttpAccountRepository` ; specs Vitest ; parcours bout en bout en dev
- [ ] 4.3 (manuel) DKIM TEM vérifié ; `_dmarc` `p=quarantine` + `rua` sur une adresse du domaine ; SPF `-all` ; passage à `p=reject` planifié après deux semaines de rapports propres
- [ ] **CHECKPOINT 4** — gates backend + frontend verts ; invitation validée en préprod ; `dig` + e-mail réel `DKIM/DMARC: PASS`

## Phase 5 — Hygiène code et infra · FAIBLE
- [ ] 5.1 `ApiJsonFormatRequestListener` (`/api` → `_format=json`) + test 404 `application/problem+json`
- [ ] 5.2 CSP + HSTS sur `/assets/`, `/healthz`, `/config.js` (front) et HSTS sur `/healthz` (backend k8s) ; `audit-prod.sh` vérifie `/config.js` + un asset  ; pas de `preload` (Q3)
- [ ] 5.3 `.dockerignore` (`backend/config/jwt/`, `backend/.env.test`) ; `.gitignore` (`/.claude/settings.local.json`) ; `frontend/public/.well-known/security.txt` + check dans `audit-prod.sh`
- [ ] 5.4 Xdebug préprod (Q2 : conservé) : `XDEBUG_TRIGGER_SECRET` via ESO + `emptyDir` `var/profiler` + procédure dans `k8s/README.md` ; un déclencheur sans la bonne valeur ne profile rien
- [ ] 5.5 Adminer `5.5.1-standalone` (`.env`, `versions.lock`, `k8s/base/adminer.yaml`) ; préprod `replicas: 0` par défaut (Q5)
- [ ] 5.6 Labels PSA (`enforce: baseline`, `audit`/`warn: restricted`) sur les deux namespaces ; `automountServiceAccountToken: false` sur tous les pod specs ; rollout réel en préprod
- [ ] 5.7 `framework.session.enabled: false` ; firewall `login` → `^/api/login_check$` ; `AccessControlAnchoringTest` étendu aux firewalls
- [ ] 5.9 Hachage factice sur identifiant inconnu (A10) : listener `LoginFailureEvent` + tests (temps égalisés, 401 identiques)
- [ ] 5.8 `docs/rgpd/` (rétention des journaux), CLAUDE.md, `k8s/README.md` à jour ; A9 (pas de révocation JWT) consigné comme risque accepté dans l'ADR 0003 (Q9)
- [ ] **CHECKPOINT 5** — tous gates verts ; `audit-prod.sh` étendu vert en préprod ; PR de clôture vers `develop` avec archivage de `tasks/` sous `.claude/specs/archive/<date>-remediation-audit-securite-3/`

## Phase 6 — Hors plan (Q7 : spec séparée)
- [ ] 6.1 Sauvegardes Postgres : bucket + clés ESO / CronJob `pg_dump` (rétention 14 j) / restauration testée en préprod — à découper quand planifié
