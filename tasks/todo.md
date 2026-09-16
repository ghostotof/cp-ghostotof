# TODO — Remédiation du 3e audit de sécurité (2026-09-16) — lot 2

Détail, décisions (D1–D8), risques et questions ouvertes (Q1–Q9) : `tasks/plan.md`.

Branche : `feature/security-audit-3-remediation-lot-2` (phases 4 et 5), une branche de tâche
empilée par phase. Lot 1 (phases 0–3) livré par v0.14.1 et v0.15.0, archivé.
T4.1 et T4.2 demandent Fable : confirmation de Christophe avant de lancer l'agent. Trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

Gates par checkpoint : `make back-quality && make back-test` ; frontend (si touché)
`make front-lint && make front-test && make front-build` ; k8s (si touché) `kubectl kustomize`
prod **et** préprod ; scripts/pipeline (si touchés) `shellcheck -x --severity=warning` + `actionlint`.

## Reliquat du lot 1 — à la main de Christophe (hors code)
- [ ] R.1 GitGuardian : incident 37338519 (fixtures de `rotate-deployer-token.test.sh`) marqué faux positif
- [ ] R.2 `tools/rotate-deployer-token.sh preprod` puis `prod` (noter une éventuelle troncature → Q4)
- [ ] R.3 `gh secret set PREPROD_BASIC_AUTH --env preprod`, `gh secret set RELEASE_DEPLOY_KEY --env production`, puis `tools/github-settings.sh` (étape k : 4 présents)
- [ ] R.4 Après le prochain run de release vert : suppression des 2 Secrets durables et des 4 secrets de dépôt, `tools/github-settings.sh` (étape k tout vert) → **CHECKPOINT 2 du lot 1**
- [ ] R.5 Rappels agenda « Prochaine rotation » (×2)
- [ ] R.6 Post-mortem public saisi dans le backoffice (Q6, champs par mail)
- [ ] R.7 Onglet Security : alertes Dependabot visibles ; notifications « Security alerts » routées

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
