# TODO — Provisionnement d'utilisateurs par le super-admin

Détail, critères d'acceptation et vérifications : voir [`plan.md`](./plan.md).
Git flow : brancher `feature/admin-user-provisioning` depuis `develop` avant tout commit.

## Phase 0 — Décisions (bloquant)

- [ ] Valider / ajuster les décisions **D1–D11** de `plan.md` §2
- [ ] Créer la branche `feature/admin-user-provisioning` depuis `develop`
- [ ] **CHECKPOINT 0** — sign-off humain avant tout code

## Phase 1 — Fondations transverses

- [x] 1.1 `AdminLayout.vue` : onglet « Contenu » (+ sous-nav sur routes de contenu) + onglet « Utilisateurs », sans changer d'URL — i18n `admin.nav.content` (fr+en) + `AdminLayout.spec.ts`
- [x] 1.2 Fondation e-mail : `composer require symfony/twig-bundle` + `config/packages/twig.yaml` (global `brand_name`) + `templates/emails/base.html.twig` / `base.txt.twig` (charte : bandeau dégradé, carte blanche, pied légal) + `app.brand_name` dans `services.yaml` + `BrandedEmailRenderingTest`
- [x] 1.3 E-mail de contact : `SendContactMessageHandler` → `TemplatedEmail` (`emails/contact_notification.*`), corps HTML **et** texte contenant `senderName` + `senderEmail` (échappés) ; `from`/`replyTo`/`subject` inchangés ; `SendContactMessageHandlerTest` mis à jour + `ContactNotificationTemplateTest`
- [ ] **CHECKPOINT 1** — revue visuelle `/admin` (fr+en) + e-mail contact rendu dans Mailpit

## Phase 2 — Backend : inviter un utilisateur

- [x] 2.1 `CpgUser` : `email` (nullable, unique, `#[Assert\Email]`) + `invitedAt` + `activatedAt` + `getActivatedAt()` + `isPendingActivation()` (= invité ET non activé — pas seulement `activatedAt === null`, pour ne pas marquer les comptes CLI « en attente ») ; migration `Version20260903155605` (up/down vérifiés) ; `CpgUserTest` (+4)
- [x] 2.2 `UsernameGenerator::generateFromEmail()` (partie locale → minuscules → filtrée `[a-z0-9_.-]` → bornée à 60 → `< 3` ⇒ complète `user` → collision ⇒ suffixe `2,3,…`, base tronquée pour rester ≤ 60) ; `UsernameGeneratorTest` (+9)
- [x] 2.3 `PasswordSetupToken` (entité : `tokenHash` SHA-256 unique, `expiresAt`, `usedAt`, `isUsable(now)`, `markUsed()`, FK `CpgUser` `onDelete: CASCADE`) + `PasswordSetupTokenRepositoryInterface` (`save`/`remove`/`findOneByTokenHash`/`deleteForUser`) + impl Doctrine + migration `Version20260903160900` + `PasswordSetupTokenRepositoryTest` (+6, intégration base `_test`, CASCADE vérifié)
- [x] 2.4 `CpgUserInviterInterface`/`CpgUserInviter::invite(email, Locale)` (clock injecté) + `EmailAlreadyUsedException` + `CpgUserRepositoryInterface::findOneByEmail` + `SendAccountInvitationMessage` (`{recipientEmail, username, clearToken, locale}` — pas de `userId`, inutile au handler) + `SendAccountInvitationHandler` (`TemplatedEmail` `emails/account_invitation.*`, chaînes fr/en dans le handler, CTA, lien `{APP_FRONTEND_BASE_URL}/{locale}/set-password/{token}`) + `AccountInvitationDeliveryException` + routing messenger + `app.frontend_base_url` (`APP_FRONTEND_BASE_URL` dans `backend/.env` — couvre dev/test/CI, tâche 7.1 réduite) ; `CpgUserInviterTest` + `SendAccountInvitationHandlerTest` + `AccountInvitationTemplateTest`
- [x] 2.5 `POST /api/backoffice/users` (invite) : opération `Post` + `BackofficeUserInviteInput` `{email, locale}` + `BackofficeUserInviteProcessor` + `exception_to_status` (409/503) ; **inclut la tâche 4.3** (`email` + `status` sur `BackofficeUserResource` + presenter + `BackofficeUserProvider`) ; `BackofficeUserInviteResourceTest` (+6 : 201 + message dispatché ; anonyme 403 via CSRF ; `ROLE_USER` 403 ; e-mail pris 409 ; e-mail invalide 422 ; locale absente 422) + `BackofficeUserResourceTest` mis à jour
- [ ] **CHECKPOINT 2** — `composer phpstan && composer rector && php bin/phpunit` verts (214) ✅ ; reste : `curl` invite manuel + rendu Mailpit (ou attendre la page admin de la phase 5)

## Phase 3 — Backend : parcours public de définition du mot de passe

- [x] 3.1 `PasswordSetupServiceInterface`/`PasswordSetupService` (`validate` / `complete`, clock injecté) + `InvalidPasswordSetupTokenException` (→ 404) + `PasswordSetupTokenExpiredException` (→ 410, fusionne expiré + déjà utilisé, sans révéler qu'un lien a servi) ; `PasswordSetupServiceTest` (+6 : lookup par SHA-256, jeton inconnu, expiré, déjà utilisé, `complete` hache+active+consomme, `complete` sur jeton expiré ne touche rien)
- [x] 3.2 `GET /account/password-setup/{token}` → `AccountPasswordSetupStatusResource` (`{valid:true}`) + `POST` → `AccountPasswordSetupResource` (`{password}` + `Length(MIN..MAX)` + `NotCompromisedPassword`, 204 `output:false` `read:false`) + provider/processor + rate limiter `account_password_setup` (10/h IP + `Retry-After` : interface/impl/exception/listener calqués sur Contact) + `exception_to_status` (404/410/429) + **exclusion CSRF** `/api/account/password-setup/` (`CsrfCookieRequestSubscriber` + test) ; `AccountPasswordSetupResourceTest` (+6 : GET 200/404/410 anonyme ; POST 204 + login OK ; rejeu 410 ; mdp court 422 ; 11e appel 429 + `Retry-After`)
- [ ] **CHECKPOINT 3** — gate backend vert (227) ✅ ; bout-en-bout couvert par `AccountPasswordSetupResourceTest` (invite → jeton → POST → login) ; smoke curl optionnel

## Phase 4 — Backend : rôles + presenter + renvoi d'invitation

- [x] 4.1 `CpgUserRoleAdministratorInterface`/`CpgUserRoleAdministrator::setSuperAdmin(id, grant, actingUser)` (idempotent) + `CannotModifyOwnRolesException` (→ 409) + `CannotDemoteLastSuperAdminException` (→ 409, via `countByRole` `<= 1`) ; `CpgUserRoleAdministratorTest` (+7 : grant, revoke si autre super, revoke dernier super, soi-même, id inconnu, grant idempotent, revoke idempotent sans consulter la garde)
- [x] 4.2 `PUT /api/backoffice/users/{id}/roles` `BackofficeUserRoleResource` (`{superAdmin: bool}`, 204 `output:false`) + `BackofficeUserRoleProvider` (404) + `BackofficeUserRoleProcessor` (acting user via `Security`) + `exception_to_status` (409) ; `BackofficeUserRoleResourceTest` (+5 : anonyme 403 via CSRF ; `ROLE_USER` 403 ; promote→GET reflète→idempotent→demote ; soi-même 409 ; id inconnu 404). « Dernier super-admin » non atteignable via l'API (l'appelant est toujours super) — couvert par le test unitaire de 4.1
- [x] 4.3 `CpgUserAdminPresenter` + `BackofficeUserResource` : `email` (nullable) + `status` (`pending`/`active`) ; `BackofficeUserResourceTest` mis à jour — **fait dans la tâche 2.5**
- [x] 4.4 `POST /api/backoffice/users/{id}/invitation` (renvoi, 202 `output:false` `read:false`) `BackofficeUserInvitationResource` (`{locale}`) + `BackofficeUserInvitationProcessor` (404 via `CpgUserNotFoundException`) + `CpgUserInviter::reinvite(CpgUser, Locale)` (logique commune extraite dans `issueTokenAndDispatch`) + `AccountNotAwaitingActivationException` (409 — couvre « déjà activé » **et** « compte CLI non invité ») ; `BackofficeUserInvitationResourceTest` (+6 : anonyme 403 ; `ROLE_USER` 403 ; renvoi → 202 + nouveau message ; id inconnu 404 ; compte activé 409 ; locale absente 422)
- [x] **CHECKPOINT 4** — gate backend vert (245) ✅ ; API figée pour le front

## Phase 5 — Frontend : page d'administration des utilisateurs

- [x] 5.1 `domain/admin/users` : `AdminUser` (+ `email: string|null`, `status: 'pending'|'active'`) + `AdminUserRepository` (+ `invite(email, Locale)`, `setSuperAdmin(id, grant)`, `resendInvitation(id, Locale)`) + `AdminUserError` (+ `email-taken`, `cannot-modify-own-roles`, `cannot-demote-last-super`, `already-activated`) + `HttpAdminUserRepository` (mapping 409 par opération + fragment de `detail` pour les 2 gardes de rôle) + `HttpAdminUserRepository.spec.ts` (14) ; fixtures/stubs de `useAdminUsers.spec` + `AdminUsersPage.spec` mis à jour (compilation verte)
- [x] 5.2 `useAdminUsers` expose `invite(email, Locale)` (retourne le compte créé ou `null`, recharge) / `setSuperAdmin(id, grant)` (`runAction`, recharge) / `resendInvitation(id, Locale)` (`runAction`, pas de recharge) ; `useAdminUsers.spec` (+6)
- [x] 5.3 `AdminUsersPage.vue` : formulaire « Inviter » (`BaseTextInput` e-mail + `BaseSelect` langue, `LOCALE_NATIVE_NAMES`) + message de succès avec username + colonne Statut (badge) + bouton Promouvoir/Rétrograder (disabled + title sur sa ligne) + bouton « Renvoyer l'invitation » (lignes `pending`, succès inline) + alerte d'erreur partagée en tête de liste ; i18n fr+en (`admin.users.invite.*`, `statusPending/Active`, `promote/demote`, `resendInvitation/Success`, `errors.{email-taken,cannot-modify-own-roles,cannot-demote-last-super,already-activated}`) ; `AdminUsersPage.spec` (+6, +2 tests existants recalés sur `form.admin-user-password-form`)
- [x] **CHECKPOINT 5** — `npm run lint && npm run build && npm test` verts (362) ✅ ; reste : revue visuelle `/fr/admin/users`

## Phase 6 — Frontend : page publique de définition du mot de passe

- [x] 6.1 `domain/account` : `AccountRepository` (`validateSetupToken` / `completePasswordSetup`) + `PasswordSetupLinkError` (`invalid`/`expired`/`weak-password`/`rate-limited`/`unknown`) + `infrastructure/account/HttpAccountRepository` (fetch nu comme HttpContactRepository — endpoint public, pas de credentials/CSRF ; mapping 404→invalid / 410→expired / 422→weak-password / 429→rate-limited) + `HttpAccountRepository.spec` (8)
- [x] 6.2 `application/account/useAccountPasswordSetup` : `state` (`checking|ready|submitting|done|invalid|expired|error`) + `errorReason` + `validate(token)` / `submit(token, password)`. `invalid`/`expired` terminaux ; échec récupérable de `submit` (weak-password, rate-limited) → retour `ready` + `errorReason`. `ACCOUNT_REPOSITORY` fourni dans `main.ts`. `useAccountPasswordSetup.spec` (9)
- [x] 6.3 `SetPasswordPage.vue` (états checking/ready/submitting/done/invalid/expired/error, garde front « ≥ 8 car. » + confirmation, écran de succès + lien /login) + route publique `/:locale/set-password/:token` (pas de `requiresAuth`, `meta.noindex`) + `RouteMeta.noindex` + `seo.ts` gère `meta.noindex` + i18n `account.setPassword.*` / `seo.setPassword.*` fr+en + `SetPasswordPage.spec` (5) + `seo.spec` (2 : noindex) + `adminGuard.spec` (+1 : route publique non bloquée)
- [x] **CHECKPOINT 6** — gate frontend vert (388) ✅ ; reste : démo bout-en-bout (invite → Mailpit → set-password → login)

## Phase 7 — Wiring / CI / docs / smoke

- [x] 7.1 `APP_FRONTEND_BASE_URL` : `backend/.env` + `config/services.yaml` (`app.frontend_base_url`) faits en 2.4 (couvrent dev/test/CI via le `.env` commité). Ajouté au `configMapGenerator` `backend-config` des overlays k8s **prod** (`https://cp-ghostotof.com`) et **preprod** (`https://preprod.cp-ghostotof.com`) — consommé par le backend ET le worker Messenger. `init-symfony.sh` / pipeline CI : rien à changer (URL hôte, valeur de base dans `.env`). `kubectl kustomize` des deux overlays OK.
- [x] 7.2 Empaquetage Twig prod **vérifié** via `make build-prod` : les 6 `templates/emails/*.twig` sont dans l'image (`COPY backend/ ./`, non exclus par `.dockerignore` — seul `*.md` l'est) ; le `cache:clear --env=prod` du build (via `post-install-cmd`) pré-chauffe `var/cache/prod/twig/` (6 fichiers compilés référençant les templates e-mail) ; `lint:twig templates/emails --env=prod` OK sur un rootfs **read-only** (comme en k8s : seul `var/log` est monté inscriptible). Le code prod n'utilise que des templates nommés (`TemplatedEmail->htmlTemplate()`), jamais `createTemplate()` runtime (tests uniquement). Aucun changement Dockerfile/k8s nécessaire.
- [ ] 7.3 ADR `docs/adr/0001-admin-user-provisioning.md` + mise à jour `.claude/CLAUDE.md` (Security/User, Backoffice, « No Twig » nuancé, « CLI-only » / « no email stored » corrigés)
- [ ] 7.4 Smoke test complet (`make up` + `make consume` + Mailpit) : invitation, set-password, login, promotion, suppression, e-mail contact ; tous les gates `make` verts
- [ ] **CHECKPOINT 7 (final)** — PR `feature/admin-user-provisioning` → `develop`
