# Plan — Remédiation du 3e audit de sécurité (2026-09-16) — lot 2

> **Lot 2, ouvert le 2026-09-16** après la mise en production du lot 1 (v0.14.1 : phase 1 ; v0.15.0 :
> phases 2 et 3, archivées sous `.claude/specs/archive/2026-09-16-remediation-audit-securite-3-lot-1/`).
> Ce lot porte les phases 4 et 5 ; la phase 6 reste hors plan (spec séparée). Même règle : `tasks/`
> vit sur `feature/security-audit-3-remediation-lot-2`, une branche de tâche empilée par phase, et
> s'archive à la PR de clôture. Les tâches T4.1 et T4.2 demandent Fable : **confirmation de
> Christophe avant de lancer l'agent**.

Source : audit complet du 2026-09-16 (session Claude), périmètre GitHub + code + Docker/nginx +
Kubernetes préprod/prod + tests en lecture seule sur `cp-ghostotof.com`. Numérotation reprise
ci-dessous : **A1** (élevé), **A2–A7** (moyens), **A8–A24** (faibles / informatifs). Les deux
audits précédents (C1–C8/I1–I9 du 2026-09-04, revue ADR 0003 du 2026-09-13) ne sont pas
rouverts.

Git flow (spec 0006) :
- Ce lot vit sur `feature/security-audit-3-remediation-lot-2` (cut de `develop` après v0.15.0),
  une branche de tâche empilée par phase, `develop` reçoit le lot une fois. `tasks/` s'archive dans
  `.claude/specs/archive/<date>-remediation-audit-securite-3-lot-2/` à la PR de clôture.
- La phase 4 (`feat:` sur l'API d'invitation) donnera une version mineure ; la phase 5 peut partir
  dans la même release ou la suivante.
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
  **Amendée le 2026-09-21 (T5.1)** : le listener est sur **`kernel.exception`** (priorité -100, entre
  l'`ExceptionListener` d'API Platform à -96 et l'`ErrorListener` de Symfony à -128), pas sur
  `kernel.request`. Mesuré : poser le format sur toute requête `/api` fait répondre **406** à
  `GET /api/docs` et `GET /api` sans en-tête `Accept` (`ContentNegotiationTrait` relit le format déjà
  posé). Le `Content-Type` est `application/json` (le corps reste un document RFC 7807) : `jsonproblem`
  n'est pas un format de requête Symfony. L'intention de D8 est tenue, sa lettre a bougé.

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
Phase 4 : T4.1 API password-setup (contrat, Fable) → T4.2 frontend + lien e-mail (Fable) ; T4.3 DNS (manuel, indépendant)
Phase 5 : T5.1 … T5.7, T5.9 indépendantes entre elles (Opus), T5.8 docs en dernier
Reliquat lot 1 (à la main, hors code) : checkpoint 2 (rotation, secrets d'environnement, suppressions) ; post-mortem Q6
```

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
Précisions ajoutées le 2026-09-21 : le champ `token` des deux DTO porte `#[Assert\NotBlank]` +
`#[Assert\Length(max: 255)]` — corps sans jeton ou jeton vide → **422** (validation), jeton inconnu →
**404**, expiré ou déjà utilisé → **410**, à l'identique sur les deux POST ; le quota par IP reste
consommé par le listener **avant** désérialisation, donc un 422 consomme aussi. Le jeton n'étant plus
dans aucun chemin, **retirer `TOKEN_BEARING_PATH_PATTERN`** de `SecurityAuditLogger` (et le test qui
l'épingle) : la règle transitoire du CLAUDE.md tombe avec cette tâche. Le frontend reste sur l'ancien
contrat jusqu'à T4.2 : les deux tâches partent dans la même release, jamais l'une sans l'autre.
**Acceptance :** [ ] `debug:router` : plus aucune route `password-setup/{token}` ; [ ] 11e POST → 429 avant validation (test C1 conservé) ; [ ] `ApiRouteExposureTest` vert ; [ ] 422/404/410 testés sur les deux POST ; [ ] plus de rédaction de chemin dans `SecurityAuditLogger`, et un test prouve que le jeton du corps n'apparaît dans aucun enregistrement.
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

### Task 4.4 : retrait du repli `set-password/:token?` (suivi, hors release de T4.2)
Ajoutée le 2026-09-21. Le repli de 48 h est le dernier endroit où un jeton peut encore voyager dans un
chemin d'URL (côté frontend : access logs du nginx et de l'ingress). Une fois T4.2 en production depuis
plus de 48 h (durée de vie d'un jeton), la route redevient `set-password` sans paramètre, la lecture de
`route.params.token` disparaît de `SetPasswordPage.vue` avec ses specs. À faire dans la release
**suivante**, jamais dans celle de T4.2. **Scope :** XS. **Dependencies :** T4.2 en prod + 48 h.

### CHECKPOINT 4
- [ ] gates backend + frontend verts ; parcours d'invitation validé en préprod
- [ ] DNS vérifié par `dig` et par un e-mail réel

---

## Phase 5 — Hygiène code et infra · priorité FAIBLE (petites tâches indépendantes)

### Task 5.1 : erreurs `/api` en JSON (D8)
`Shared/Infrastructure/Http/ApiJsonFormatRequestListener` (priorité haute, `CanonicalPath`,
`setRequestFormat('json')` sur `/api`) ; test fonctionnel : `GET /api/inexistant` →
`application/problem+json`, 404.
**Ajouté le 2026-09-21, constat de l'agent de T4.1, à vérifier puis traiter ici** : un corps JSON
malformé ou un champ du mauvais type (`{"token":123}`, `not-json`, `{"name":123}` sur `/api/contact`)
répond **500** au lieu de 400 sur les POST publics. Cause probable : le `exception_to_status` du projet
remplace les valeurs par défaut d'API Platform au lieu de les compléter (`SerializerExceptionInterface`
et `InvalidArgumentException` → 400 perdus). Un anonyme peut produire des 500 à volonté (borné par les
quotas). Reproduire par un test fonctionnel, rétablir les 400, vérifier qu'aucune trace ne sort en prod.
Sévérité faible à moyenne, confiance moyenne (constaté en dev, cause non confirmée).
**Scope :** S. **Fichiers :** 1 listener + tests, `api_platform.yaml`.

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
**Réalisé autrement, le 2026-09-21** — la preuve exigée a invalidé le plan ci-dessus sur deux points.
(1) Le verrou ne peut pas être `xdebug.trigger_value` : une variable absente y donne une valeur vide,
et pour Xdebug une valeur vide veut dire « n'importe quel déclencheur » ; le verrou est donc
`xdebug.mode = "off"` dans l'image, armé par `XDEBUG_MODE` issu d'un Secret **dédié et optionnel**
(`backend-xdebug-trigger`, `ExternalSecret` séparé, template `profile` seulement si le secret fait au
moins 32 caractères), et non une clé de `backend-secrets` — le backend démarre sans lui, et ni le worker
ni les Jobs ni les CronJobs ne le reçoivent. (2) Le mode `trace` est retiré : une trace écrit les
arguments des appels, donc le mot de passe d'un login profilé. (3) Xdebug 3.5 ne lit pas les en-têtes
HTTP : le déclencheur est un cookie, passé par `curl -K`.

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
un mot de passe faux. **Étendu le 2026-09-21** (vérifié dans le code : aucun `UserChecker`, et un
compte invité non activé a un hachage vide, donc `password_verify` échoue sans rien calculer) : le
hachage factice est aussi calculé quand l'utilisateur existe mais `isPendingActivation()`, sinon un
compte en attente se distingue d'un compte actif par le temps de réponse. Test unitaire : hasher appelé
une fois pour un inconnu **et** pour un compte en attente, jamais pour un compte actif.
**Scope :** XS. **Fichiers :** 1 listener + 2 tests.

### Task 5.10 : rotation du mot de passe Basic Auth de la préprod, documentée
`k8s/README.md` §2bis ne décrit que la création (`scw secret secret create`, qui échoue sur un secret
existant). Ajouter la rotation : nouvelle version (`scw secret version create <id> data=@fichier`),
`force-sync` de l'ExternalSecret, secret GitHub d'environnement posé par fichier, désactivation des
anciennes révisions, vérification en navigation privée. Remplacer `--body '<identifiant>:<mot-de-passe>'`
et `data="$(cat …)"` par des lectures de fichier, avec la consigne « terminal séparé » : la valeur ne
doit apparaître ni dans les arguments d'un processus ni dans une session d'agent (incident du
2026-09-16, identifiants collés dans le transcript, mot de passe changé aussitôt). **Scope :** XS.

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
| Token lié : durée max imposée par Kapsule | — | Levé le 2026-09-16 : 90 jours accordés sans troncature |
| Ancien lien d'invitation en circulation à la sortie de T4.2 | Faible | Route `:token?` conservée 48 h ; les jetons expirent en 48 h |
| `automountServiceAccountToken: false` casse un pod qui parlait à l'API | Faible | Aucun ne le fait (vérifié : ESO est hors namespace) ; rollout préprod |
| Publication du plan avant le correctif A1 (dépôt public) | Élevé | Q1 : ne pousser cette branche qu'après `v0.14.1` en prod |

## Questions ouvertes

- **Q1 — Divulgation.** **Décidé le 2026-09-16** : le hotfix part d'abord (branche sans
  `tasks/`, message de commit sobre), cette branche n'est poussée qu'après `v0.14.1` en prod.
- **Q2 — Xdebug en préprod** : ~~conserver ou retirer ?~~ **Décidé le 2026-09-16 : conservé et sécurisé** (T5.4).
- **Q3 — HSTS `preload`** : **décidé le 2026-09-16 : non** (engagement sur tous les sous-domaines,
  retrait long, gain marginal derrière le 308 + HSTS un an). À reconsidérer plus tard.
- **Q4 — Durée du token lié** : **décidé : 90 jours** (rotation trimestrielle), **confirmé le 2026-09-16** :
  Kapsule a accordé les 2160 h demandées, sans troncature, sur `preprod` comme sur `prod`.
- **Q5 — Adminer préprod** : **décidé : `replicas: 0`** par défaut, comme en prod (T5.5).
- **Q6 — Journal des incidents** : **décidé : oui**, entrée post-mortem A1 à saisir dans le
  backoffice après `v0.14.1` (invariant : « aucun état sur le filesystem du pod »).
- **Q7 — Sauvegardes** : **décidé : spec séparée** (Phase 6 reste un rappel, non planifiée ici).
- **Q8 — `allowed_actions`** : **décidé : `all` conservé**, épinglage SHA imposé (T2.1).
- **Q9 — A9/A10** : **décidé le 2026-09-16** : A9 accepté et documenté dans l'ADR 0003 (T5.8) ;
  A10 corrigé en phase 5 (T5.9, hachage factice).
