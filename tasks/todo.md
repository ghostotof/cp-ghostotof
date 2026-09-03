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
- [ ] 2.5 `POST /api/backoffice/users` (invite) : opération `Post` sur `BackofficeUserResource` + DTO `{email, locale}` + `BackofficeUserInviteProcessor` + `exception_to_status` ; test fonctionnel (201 + message dispatché ; anonyme 401 ; `ROLE_USER` 403 ; e-mail pris 409 ; invalide 422)
- [ ] **CHECKPOINT 2** — `composer phpstan && composer rector && php bin/phpunit` verts + `curl` invite manuel (Mailpit)

## Phase 3 — Backend : parcours public de définition du mot de passe

- [ ] 3.1 `PasswordSetupService` (`validate` / `complete`) + `InvalidPasswordSetupTokenException` (404) + `PasswordSetupTokenExpiredException` (410, couvre aussi « déjà utilisé ») ; `PasswordSetupServiceTest`
- [ ] 3.2 `GET`/`POST /api/account/password-setup/{token}` (DTO `{password}` + `Length(MIN..MAX)` + `NotCompromisedPassword`, `POST` → 204 `output:false`) + provider/processor + rate limiter `account_password_setup` (IP + `Retry-After`) + `exception_to_status` ; tests fonctionnels (GET 200/404/410 anonyme ; POST 204 + compte activé + login OK ; rejeu 410 ; mdp court 422 ; 429)
- [ ] **CHECKPOINT 3** — gate backend vert + bout-en-bout manuel avec un vrai jeton de la Phase 2

## Phase 4 — Backend : rôles + presenter + renvoi d'invitation

- [ ] 4.1 `CpgUserRoleAdministrator::setSuperAdmin(id, grant, actingUser)` + `CannotModifyOwnRolesException` (409) + `CannotDemoteLastSuperAdminException` (409, via `countByRole`) ; `CpgUserRoleAdministratorTest`
- [ ] 4.2 `PUT /api/backoffice/users/{id}/roles` (`{superAdmin: bool}`, `output:false`, `provider` explicite pour le 404) + processor + `exception_to_status` ; test fonctionnel (promote/demote ok ; soi-même 409 ; dernier super 409 ; anonyme 401)
- [ ] 4.3 `CpgUserAdminPresenter` + `BackofficeUserResource` : `email` (nullable) + `status` (`pending`/`active`) ; `BackofficeUserResourceTest` mis à jour
- [ ] 4.4 `POST /api/backoffice/users/{id}/invitation` (renvoi) + `CpgUserInviter::reinvite(CpgUser)` + `AccountAlreadyActivatedException` (409) + provider explicite ; test fonctionnel
- [ ] **CHECKPOINT 4** — gate backend vert (API figée pour le front)

## Phase 5 — Frontend : page d'administration des utilisateurs

- [ ] 5.1 `domain/admin/users` : `AdminUser` (+ `email`, `status`) + `AdminUserRepository` (+ `invite`, `setSuperAdmin`, `resendInvitation`) + `AdminUserError` (+ `email-taken`, `cannot-modify-own-roles`, `cannot-demote-last-super`, `already-activated`) + `HttpAdminUserRepository` impl + spec
- [ ] 5.2 `useAdminUsers` expose `invite` / `setSuperAdmin` / `resendInvitation` (via `runAction`, reload liste) ; spec mise à jour
- [ ] 5.3 `AdminUsersPage.vue` : formulaire « Inviter » (e-mail + `BaseSelect` langue) + colonne Statut + bouton Promouvoir/Rétrograder (disabled sur sa ligne) + bouton « Renvoyer l'invitation » (si `pending`) ; i18n fr+en ; `AdminUsersPage.spec.ts`
- [ ] **CHECKPOINT 5** — `npm run lint && npm run build && npm test` verts + revue visuelle `/fr/admin/users`

## Phase 6 — Frontend : page publique de définition du mot de passe

- [ ] 6.1 `domain/account` (`AccountRepository`, `PasswordSetupLinkError`) + `infrastructure/account/HttpAccountRepository` (`GET`/`POST /api/account/password-setup/{token}`, mapping 404/410/422/429) + spec
- [ ] 6.2 `application/account/useAccountPasswordSetup` (états `checking|ready|invalid|expired|submitting|done|error`) + provide `ACCOUNT_REPOSITORY` dans `main.ts` + spec
- [ ] 6.3 `SetPasswordPage.vue` + route publique `/:locale/set-password/:token` (pas de `requiresAuth`, `meta.noindex`) + `seo.ts` gère `noindex` + i18n `account.setPassword.*` / `seo.setPassword.*` fr+en + specs (page + router public + noindex)
- [ ] **CHECKPOINT 6** — gate frontend vert + **démo bout-en-bout** (invite → Mailpit → set-password → login)

## Phase 7 — Wiring / CI / docs / smoke

- [ ] 7.1 `APP_FRONTEND_BASE_URL` : `backend/.env` + `config/services.yaml` (`app.frontend_base_url`) + `docker/php/init-symfony.sh` + `.github/workflows/pipeline.yml` (writer `.env.test.local`)
- [ ] 7.2 Vérifier l'empaquetage `backend/templates/` dans le stage `production` du `Dockerfile` + cache Twig sous `var/` (`make build-prod` + `cache:warmup --env=prod`)
- [ ] 7.3 ADR `docs/adr/0001-admin-user-provisioning.md` + mise à jour `.claude/CLAUDE.md` (Security/User, Backoffice, « No Twig » nuancé, « CLI-only » / « no email stored » corrigés)
- [ ] 7.4 Smoke test complet (`make up` + `make consume` + Mailpit) : invitation, set-password, login, promotion, suppression, e-mail contact ; tous les gates `make` verts
- [ ] **CHECKPOINT 7 (final)** — PR `feature/admin-user-provisioning` → `develop`
