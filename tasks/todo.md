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
- [ ] 5.1 C4 : `secretstore.yaml` `accessKey` → `secretRef` (`scaleway-eso-auth`/`access-key`) ; MAJ `k8s/README.md` (bootstrap 2 clés)
- [ ] 5.2 I2 : `CORS_ALLOW_ORIGIN` `…\.com$` → `…\.com\z` (overlays prod + preprod)
- [ ] 5.3 I5 : `docker/node/nginx.conf` — CSP `frame-ancestors 'none'` + `X-Frame-Options "DENY"`
- [ ] 5.4 I6 : `seccompProfile: { type: RuntimeDefault }` sur tous les pod specs (backend, frontend, worker, purge cronjob, postgres, rabbitmq, adminer)
- [ ] 5.5 Vérifs : `kubectl kustomize` prod + preprod OK ; frontend lint+build+test OK ; en-têtes `audit-prod.sh` inchangés (X-Frame-Options plus strict)
- [ ] **CHECKPOINT 5** — rendus kustomize + gates frontend + README

## Phase 6 — C7 + I4 + I8 : plafonds secondaires + hygiène  · DIFFÉRABLE
- [ ] 6.1 C7 : `limit_req` nginx sur `/api/contact` et `/api/account/password-setup/` (`backend-nginx-conf.yaml` + `docker/nginx/default.conf`), `limit_req_status 429` ; repli possible = limiteur Symfony à clé fixe
- [ ] 6.2 I4 : `CONTACT_*` vides dans `backend/.env`, valeurs dev via `init-symfony.sh` → `.env.local`
- [ ] 6.3 I8 : documenter (compte invité = `ROLE_USER` = accès CV + données authentifiées ; mot de passe fort + rotation) dans `CONTEXT.md`/ADR
- [ ] **CHECKPOINT 6** — gates + vérif `limit_req` en dev

## Phase 7 — C8 : migrations via Job k8s, drop pods/exec  · DIFFÉRABLE
- [ ] 7.1 `k8s/base/migrate-job.yaml` (hors `kustomization.yaml resources:`)
- [ ] 7.2 `pipeline.yml` deploy-preprod + deploy-prod : `delete` + `apply -f migrate-job.yaml` + `kubectl wait --for=condition=complete` (+ logs sur échec)
- [ ] 7.3 `role.yaml` : retirer `pods/exec: create` ; ajouter `batch/jobs` (get/list/watch/create/delete) ; garder `pods`/`pods/log` en lecture
- [ ] 7.4 Vérifs : `kubectl kustomize` ; `kubectl apply --dry-run=client -f migrate-job.yaml` ; revue pipeline ; MAJ `k8s/README.md` + mémoire `project_networkpolicy_incident_v040`
- [ ] **CHECKPOINT 7** — kustomize + dry-run + revue

## Phase 8 — C5 + clôture
- [ ] 8.1 C5 : `git grep` des anciens secrets sur `HEAD` (vide) ; consigner « sans effet, ESO » + prescription vérif Scaleway ; excision d'historique = option non planifiée
- [ ] 8.2 MAJ mémoire `project_security_audit.md` (numérotation C1–C8/I1–I9, état, commit/PR)
- [ ] 8.3 MAJ `.claude/CLAUDE.md` (changements structurants)
- [ ] **CHECKPOINT 8 (final)** — gates verts, PR vers `develop`, CI verte

## Non traités (acceptés + documentés)
- I1 — 401 vs 404 sur `/api/backoffice/*` : renvoyer 404 casserait la sémantique REST + la redirection frontend
- I9 — en-tête `Subject` du mail de contact : Symfony l'encode (RFC 2047), pas d'injection
