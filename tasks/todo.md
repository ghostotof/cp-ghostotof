# TODO — Remédiation de l'audit de sécurité

Détail, décisions (D1–D8), critères d'acceptation et vérifications : voir [`plan.md`](./plan.md).
Git flow : brancher `feature/security-audit-remediation` depuis `develop` avant tout commit.

Gates par checkpoint : backend `composer phpstan && composer rector && php bin/phpunit` ;
frontend (si touché) `npm run lint && npm run build && npm test` ;
k8s (si touché) `kubectl kustomize` prod **et** preprod.

## Phase 0 — Préparation
- [x] 0.1 Créer `feature/security-audit-remediation` depuis `develop`
- [x] **CHECKPOINT 0** — plan validé

## Phase 1 — C1 : rate-limit avant validation (set-password public)  · priorité HAUTE
- [x] 1.1 `PasswordSetupRateLimitRequestListener` (`kernel.request` prio 15, préfixe `/api/account/password-setup/`, GET+POST) → `rateLimiter->consume(clientIp)`
- [x] 1.2 Retirer `consume()` + la dépendance du `AccountPasswordSetupProvider` et `AccountPasswordSetupProcessor`
- [x] 1.3 `#[Assert\NotCompromisedPassword(skipOnError: true)]` sur `AccountPasswordSetupResource` + `BackofficeUserPasswordResource`
- [x] 1.4 Tests : listener unitaire (hors préfixe ignoré, GET+POST consomment, sous-requête ignorée, sans IP → clé partagée, quota → exception) ; fonctionnel 11ᵉ POST jeton bidon → 429 avant validation du corps + `Retry-After` ; GET+POST partagent le quota ; set-password nominal inchangé
- [x] **CHECKPOINT 1** — gates backend verts (PHPStan max, Rector, PHPUnit 256 tests)

## Phase 2 — C2 : jeton hors du message (donc hors failure transport)  · priorité HAUTE
- [x] 2.1 `SendAccountInvitationMessage` → `{ userId: int, locale: string }` uniquement
- [x] 2.2 `SendAccountInvitationHandler` : charge l'user (absent/activé → log+return) ; `wrapInTransaction { deleteForUser + nouveau PasswordSetupToken (SHA-256, +48h) }` ; construit `setupUrl` ; envoie ; `AccountInvitationDeliveryException` sur `TransportException`
- [x] 2.3 `CpgUserInviter` : `invite()`/`reinvite()` créent/gardent le compte *pending* et publient `{userId, locale}` — plus de création de jeton ; `issueTokenAndDispatch()` supprimé (`TOKEN_LIFETIME` déménagé dans le handler)
- [x] 2.4 Tests : `SendAccountInvitationHandlerTest` (jeton frais + lien = seul porteur du clair ; retry → nouveau jeton ; user absent/activé → rien ; transport KO → 503) ; `CpgUserInviterTest` (compte pending + message `{userId, locale}`, reinvite pending/activé) ; `BackofficeUserInvitation/InviteResourceTest` (message = `userId`+`locale`) ; bout-en-bout set-password vert (jeton relu dans l'e-mail via `tests/Support/InvitesUsers`)
- [x] **CHECKPOINT 2** — gates backend verts (PHPStan max, Rector, PHPUnit 261) ; `messenger:failed:show` structurellement sans jeton (message à 2 champs). Smoke Mailpit non exécuté : aucun service `mailpit` dans le stack local (DSN `.env.local` pointe vers un hôte absent) — couvert par le test fonctionnel bout-en-bout + `AccountInvitationTemplateTest`.

## Phase 3 — C3 : contenu « À propos » entièrement public  · D4 RÉVISÉE, clos sans correctif
> D4 affirmait reproduire « le masquage actuel de `AboutPage.vue` » en vidant `personalCards`
> **et** `hobbiesCards` — or le frontend ne masquait que *hobbies*. Arbitrage produit du
> 2026-09-04 : tout le contenu « À propos » est public, on retire le filtre au lieu de l'étendre.
- [x] 3.1 `AboutContentProvider` inchangé ; docblock de `AboutContentResource` réécrit (trois volets publics, ne pas réintroduire de filtre)
- [x] 3.2 `AboutPage.vue` : `<template v-if="isAuthenticated">` du volet *hobbies* supprimé + import `useAuth` retiré
- [x] 3.3 Tests : `AboutContentResourceTest` (assertions `hobbies*` = garde-fou C3) ; `AboutPage.spec.ts` (un seul test « hobbies visibles sans authentification », plumbing auth supprimé)
- [x] **CHECKPOINT 3** — gates backend (PHPStan max, Rector, PHPUnit 261) + frontend (ESLint, build `vue-tsc`, Vitest 391) verts

## Phase 4 — C6 + I3 : nettoyage surface API  · priorité MOYENNE
- [x] 4.1 C6 : `Get('/backoffice/users/{id}')` explicite sur `BackofficeUserResource` (même provider que `Delete`) → `/api/backoffice_users/{id}` disparu ; tests item `ROLE_SUPER` 200 + DTO / id inconnu 404 / ancien chemin 404 / anonyme 401
- [x] 4.2 I3 : `Locale::fromString()` + `InvalidLocaleException` (→404) ; `uriVariableLocale()` dans `ResolvesUriVariables` ; `ValueError: 404` retiré d'`api_platform.yaml` ; 5 sites `{locale}` migrés (About/Quality/Stats providers + `BackofficeAboutSettings` provider/processor) ; `LocaleTest` (nominal, rejets, message, hiérarchie ≠ `\ValueError`)
  - Les champs `locale`/`category` des DTO de backoffice gardent `Locale::from()` : bornés en amont par `#[Assert\Choice]` (422 avant le processor), une `\ValueError` y signalerait un vrai bug et doit rester un 500
- [x] **CHECKPOINT 4** — `debug:router` conforme ; gates backend verts (PHPStan max, Rector, PHPUnit 276) ; vérif runtime curl des 404 de locale

## Phase 5 — C4 + I2 + I5 + I6 : durcissement k8s/frontend  · priorité MOYENNE-BASSE
- [x] 5.1 C4 : `secretstore.yaml` `accessKey` → `secretRef` (`scaleway-eso-auth`/`access-key`) ; `projectId` reste inline ; `k8s/README.md` à jour (bootstrap 2 clés + procédure `kubectl patch` pour un cluster existant)
- [x] 5.2 I2 : `CORS_ALLOW_ORIGIN` `…\.com$` → `…\.com\z` (overlays prod + preprod) — écart prouvé en PCRE : `$` acceptait `https://cp-ghostotof.com\n`
- [x] 5.3 I5 : `docker/node/nginx.conf` — CSP `frame-ancestors 'none'` (×2) + `X-Frame-Options "DENY"` (×5) ; aucun `<iframe>` dans le frontend (vérifié) ; `nginx -t` OK + en-têtes servis vérifiés
- [x] 5.4 I6 : `seccompProfile: { type: RuntimeDefault }` sur les 7 pod specs (backend, frontend, worker, purge cronjob, postgres, rabbitmq, adminer)
- [x] 5.5 Vérifs : `kubectl kustomize` prod + preprod OK ; frontend lint+build+test OK (391) ; backend OK (276) ; `audit-prod.sh` ne teste que la présence des en-têtes → insensible
- [x] **CHECKPOINT 5** — rendus kustomize + gates + README

> **⚠ Avant tout déploiement de cette phase :**
> 1. **C4 est breaking sur cluster existant** — ajouter `access-key` au Secret `scaleway-eso-auth` AVANT
>    d'appliquer, sinon SecretStore `NotReady` et ExternalSecrets figés (procédure dans `k8s/README.md`).
> 2. **I6 touche Postgres/RabbitMQ (PVC)** — rollout préprod réel avec `rollout status` + logs avant prod,
>    par la règle tirée de l'incident v0.5.0. Risque jugé faible (seccomp ≠ `fsGroup`, cause du crash), mais
>    la règle s'applique quand même.
> 3. **`accessKey` reste dans l'historique git public** — la retirer du HEAD ne la retire pas du passé.
>    Rotation de la paire IAM ESO à envisager (à rapprocher de C5, phase 8).

## Phase 6 — C7 + I4 + I8 : plafonds secondaires + hygiène  · FAIT
- [x] 6.1 C7 : `limit_req` sur `/api/contact` (10r/m, burst 5) et `/api/account/password-setup/` (20r/m, burst 10), `limit_req_status 429`, dans les deux confs synchronisées
  - **Ajout non prévu au plan, indispensable** : bloc `real_ip` (`set_real_ip_from` sur les plages privées + `real_ip_header X-Forwarded-For`). Sans lui, derrière l'ingress `$binary_remote_addr` vaut l'IP du pod ingress-nginx → **un seul compteur pour tout Internet**, déni de service auto-infligé. Aligné sur `trusted_proxies: private_ranges` de Symfony.
- [x] 6.2 I4 : `CONTACT_*` vidés dans `backend/.env` ; valeurs dev via `init-symfony.sh` → `.env.local` ; **valeurs de test dans `phpunit.dist.xml`** (l'env test ne charge jamais `.env.local` — sans ça, 7 tests en erreur `RfcComplianceException`), avec `force="true"` sinon Dotenv écrase par la valeur vide du `.env`
  - ⚠ `init-symfony.sh` sort en `exit 0` si `composer.json` existe : sur un projet déjà initialisé, ajouter les 2 variables à la main dans `.env.local` (fait sur ce poste)
- [x] 6.3 I8 : risque du compte invité générique documenté dans `docs/adr/0001` (pas de `CONTEXT.md` dans le dépôt) — `ROLE_USER` = accès `GET /api/cv`, aucun palier intermédiaire ; hygiène (mot de passe dédié, rotation, jamais `ROLE_SUPER`) + piste `ROLE_GUEST`
- [x] **CHECKPOINT 6** — `nginx -t` sur les 2 confs ; 429 vérifié en dev (6 puis 429 sur contact, 11 puis 429 sur set-password) ; aucun autre endpoint affecté ; PHPStan/Rector/PHPUnit 276 verts

## Phase 7 — C8 : migrations via Job k8s, drop pods/exec  · FAIT
- [x] 7.1 `k8s/base/migrate-job.yaml`, hors `resources:` — mêmes `securityContext`/limites que le Deployment, `backoffLimit: 1`, `ttlSecondsAfterFinished: 600`
  - **Écart au plan** : hors kustomize, le transformateur d'images ne s'applique pas. `image: backend` serait résolu en `docker.io/library/backend` → placeholder `${BACKEND_IMAGE}` substitué par `envsubst` au moment de l'apply (idiome déjà utilisé dans `docker/node/docker-entrypoint.sh`)
- [x] 7.2 `pipeline.yml` deploy-preprod + deploy-prod : `delete --ignore-not-found` + `envsubst | apply -f -` + `wait --for=condition=complete --timeout=180s` + dump des logs sur échec
- [x] 7.3 `role.yaml` : `pods/exec: create` retiré ; `batch/jobs` (get/list/watch/create/**delete**, la spec d'un Job étant immuable) ajouté ; `pods`/`pods/log` conservés en lecture
- [x] 7.4 Vérifs : les 2 overlays rendent sans erreur et **ne contiennent pas** `backend-migrate` ; `envsubst` + `kubectl apply --dry-run=client` OK ; workflow YAML valide (12 jobs, bloc migrate dans les deux) ; plus aucun `kubectl exec` ni `pods/exec` actif ; NetworkPolicy vérifiée (`allow-datastores-from-app` utilise `podSelector: {}` = tous les pods du namespace, le Job joindra Postgres)
- [x] **CHECKPOINT 7** — kustomize + dry-run + revue pipeline + Role rendu vérifié

> **⚠ Avant le prochain déploiement — bootstrap RBAC à rejouer.** Le `Role` est
> un bootstrap manuel, jamais réappliqué par le pipeline. Sans le rejouer,
> `deploy-preprod`/`deploy-prod` échoueront sur `cannot create resource "jobs"`.
> Procédure et commandes `auth can-i` de vérification dans `k8s/README.md` §4.

## Phase 8 — C5 + clôture
- [ ] 8.1 C5 : `git grep` des anciens secrets sur `HEAD` (vide) ; consigner « sans effet, ESO » + prescription vérif Scaleway ; excision d'historique = option non planifiée
- [ ] 8.2 MAJ mémoire `project_security_audit.md` (numérotation C1–C8/I1–I9, état, commit/PR)
- [ ] 8.3 MAJ `.claude/CLAUDE.md` (changements structurants)
- [ ] **CHECKPOINT 8 (final)** — gates verts, PR vers `develop`, CI verte

## Non traités (acceptés + documentés)
- I1 — 401 vs 404 sur `/api/backoffice/*` : renvoyer 404 casserait la sémantique REST + la redirection frontend
- I9 — en-tête `Subject` du mail de contact : Symfony l'encode (RFC 2047), pas d'injection
