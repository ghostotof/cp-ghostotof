# TODO — Remédiation de l'audit de sécurité

Détail, décisions (D1–D8), critères d'acceptation et vérifications : voir [`plan.md`](./plan.md).
Git flow : brancher `feature/security-audit-remediation` depuis `develop` avant tout commit.

Gates par checkpoint : backend `composer phpstan && composer rector && php bin/phpunit` ;
frontend (si touché) `npm run lint && npm run build && npm test` ;
k8s (si touché) `kubectl kustomize` prod **et** preprod.

## Phase 0 — Préparation
- [ ] 0.1 Créer `feature/security-audit-remediation` depuis `develop`
- [ ] **CHECKPOINT 0** — plan validé

## Phase 1 — C1 : rate-limit avant validation (set-password public)  · priorité HAUTE
- [ ] 1.1 `PasswordSetupRateLimitRequestListener` (`kernel.request` prio 15, préfixe `/api/account/password-setup/`, GET+POST) → `rateLimiter->consume(clientIp)`
- [ ] 1.2 Retirer `consume()` + la dépendance du `AccountPasswordSetupProvider` et `AccountPasswordSetupProcessor`
- [ ] 1.3 `#[Assert\NotCompromisedPassword(skipOnError: true)]` sur `AccountPasswordSetupResource` + `BackofficeUserPasswordResource`
- [ ] 1.4 Tests : listener unitaire (hors préfixe ignoré, GET+POST consomment, quota → 429) ; fonctionnel 11ᵉ POST jeton bidon → 429 sans appel HIBP + `Retry-After` ; GET soumis au quota ; set-password nominal inchangé
- [ ] **CHECKPOINT 1** — gates backend

## Phase 2 — C2 : jeton hors du message (donc hors failure transport)  · priorité HAUTE
- [ ] 2.1 `SendAccountInvitationMessage` → `{ userId: int, locale: string }` uniquement
- [ ] 2.2 `SendAccountInvitationHandler` : charge l'user (absent/activé → log+return) ; transaction { `deleteForUser` + nouveau `PasswordSetupToken` (SHA-256, +48h) } ; construit `setupUrl` ; envoie ; `AccountInvitationDeliveryException` sur `TransportException`
- [ ] 2.3 `CpgUserInviter` : `invite()`/`reinvite()` créent/gardent le compte *pending* et publient `{userId, locale}` — plus de création de jeton ; supprimer `issueTokenAndDispatch()`
- [ ] 2.4 Tests : `SendAccountInvitationHandlerTest` (jeton frais + envoi ; retry → nouveau jeton, ancien supprimé ; user absent/activé → rien ; transport KO → 503) ; `CpgUserInviterTest` (compte pending + message, plus d'assertion jeton) ; `BackofficeUserInvitation/InviteResourceTest` (message sans `clearToken`) ; bout-en-bout set-password vert
- [ ] **CHECKPOINT 2** — gates + smoke Mailpit invite→e-mail→set-password→login ; `messenger:failed:show` sans jeton

## Phase 3 — C3 : filtrage serveur section « moi » de /api/about/{locale}  · priorité MOYENNE
- [ ] 3.1 `AboutContentProvider` injecte `Security` ; anonyme → `personalCards = []` + `hobbiesCards = []` (technical conservé)
- [ ] 3.2 Tests fonctionnels : `/api/about/fr` anonyme (personal/hobbies vides, technical présent) vs `ROLE_USER` (complet) ; adapter les tests provider
- [ ] 3.3 (opt.) note « filtre client = défense en profondeur » dans `AboutPage.vue`
- [ ] **CHECKPOINT 3** — gates + `curl` anonyme `/api/about/{fr,en}`

## Phase 4 — C6 + I3 : nettoyage surface API  · priorité MOYENNE
- [ ] 4.1 C6 : `Get('/backoffice/users/{id}')` explicite sur `BackofficeUserResource` (même provider que `Delete`) → `/api/backoffice_users/{id}` disparaît ; tests item `ROLE_SUPER` 200 / anonyme 401
- [ ] 4.2 I3 : `Locale::fromString()` + `InvalidLocaleException` (→404) ; `uriVariableLocale()` dans `ResolvesUriVariables` ; retirer `ValueError: 404` d'`api_platform.yaml` ; MAJ providers `Portfolio/*` ; test `/api/about/zz` → 404
- [ ] **CHECKPOINT 4** — `debug:router` (diff), gates backend

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
