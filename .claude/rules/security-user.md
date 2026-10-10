---
paths:
  - "backend/src/Security/**"
  - "backend/tests/Security/**"
  - "frontend/src/*/account/**"
  - "docker/nginx/**"
  - "k8s/base/backend-nginx.conf"
---

# Backend — `Security/User`

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture › `Security/User/` ». The index of every rule is at the top of `.claude/CLAUDE.md`.

- **`Security/User/`** — the `CpgUser` aggregate. Two creation paths (see ADR 0001): the CLI command
  (`app:user:create`, bootstrap — notably the first `ROLE_SUPER`) and **invitation from the backoffice**
  (`CpgUserInviter`, `POST /api/backoffice/users`). Login identifier is a plain `username`; an `email` **is**
  stored for invited accounts (nullable, unique, `ROLE_SUPER`-only, never exposed before authentication —
  Goal #9 still holds). Invited accounts are created "pending activation" (empty password) and the person sets
  their own password via an emailed link.
  - `Domain/Entity/CpgUser.php` (+ `email`, `invitedAt`, `activatedAt`, `isPendingActivation()`),
    `Domain/Entity/PasswordSetupToken.php` (SHA-256 of the token only, 48h expiry, single-use, FK `ON DELETE CASCADE`),
    `Domain/Repository/{CpgUser,PasswordSetupToken}RepositoryInterface.php` (the DIP boundary — `Application/`
    never depends on Doctrine directly).
    `CpgUser::ROLE_SUPER` is the role reserved for backoffice access; `CpgUser::MIN_PASSWORD_LENGTH` /
    `MAX_PASSWORD_LENGTH` are the single source of truth, applied through one compound constraint,
    `Presentation/Validator/PlainPasswordLength`, by the CLI, the backoffice password-change endpoint and the
    public set-password endpoint — never a hand-written `Assert\Length` or `strlen()` (issue #386: the CLI
    counted the minimum in bytes, the API the maximum in characters). The minimum is in **characters**, the
    maximum in **bytes**, the unit the hasher checks (`PasswordHasherInterface::MAX_PASSWORD_LENGTH`, `strlen`):
    counted in characters, a multibyte password passed validation and the hasher turned it into a 500.
    On both DTOs it sits inside one `Assert\Sequentially([NotBlank, PlainPasswordLength, NotCompromisedPassword])`
    — each constraint only if the previous one passed — so a password already refused never reaches
    api.pwnedpasswords.com: the DTO is validated before the token is read, so any anonymous caller of the
    set-password route could otherwise trigger that outbound call (`PasswordBreachCheckOrderTest` counts them).
    **A fourth entry point cannot skip it** (issue #411): `tests/Security/User/PlainPasswordInputsTest.php`
    (census in `tests/Support/PlainPasswordInputs`) fails on any field of `src/` whose name says "password"
    (`pass(word|wd|phrase)`, `pwd`, `mot_de_passe`, any case, `hash` excluded) and may hold a string unless
    it carries that sequence **alone**; on any such parameter without `#[SensitiveParameter]` (interfaces
    included); on any class of `src/` naming a `Symfony\Component\PasswordHasher\` type that is not declared
    in its `HASHERS` with the interface method the password reaches it by; and on any caller of those methods
    that is not a declared entry point (`ENTRY_POINTS`) with what validates it (`VALIDATED_BY`: the API
    Platform resource it is the processor of, which must carry a password field, or the CLI, which must name
    both constraints). The caller census closes the gap of the name census: a field called `$secret` feeding
    `changePassword()` is caught there. A guard rather than a `PlainPassword` value object, settled in #411
    (justification in the test's docblock): the VO would only cover the length — not the breach check nor its
    order —, would write the rule a second time, and its other gain, keeping the value out of stack traces,
    is what `#[SensitiveParameter]` gives. Revisit the day the hashing use cases multiply.
    Domain exceptions: `UsernameAlreadyUsedException`, `EmailAlreadyUsedException`,
    `InvalidPasswordSetupTokenException` (→404), `PasswordSetupTokenExpiredException` (→410, covers "already
    used"), `CannotModifyOwnRolesException` / `CannotDemoteLastSuperAdminException` (→409),
    `AccountNotAwaitingActivationException` (→409), `AccountInvitationDeliveryException` (→503),
    `PasswordSetupRateLimitExceededException` (→429).
  - `Domain/Service/UsernameGenerator.php` — derives a free username from the email local part
    (filter to `[a-z0-9_.-]`, cap 60, pad with `user` if < 3, numeric suffix on collision).
  - `Application/` use cases (each with an interface, autowired single-impl):
    `CpgUserRegistrar` (CLI creation, password provided up front) · `CpgUserInviter`
    (`invite(email, Locale)` / `reinvite(user, Locale)`: derives username, creates/marks the pending account,
    then **only** dispatches `SendAccountInvitationMessage`) · `PasswordSetupService` (`validate` / `complete`
    the public flow) · `CpgUserAdministrator` (delete / change-password) · `CpgUserRoleAdministrator`
    (`setSuperAdmin`, idempotent, anti-lockout guards; on demotion `ROLE_TRUSTED` is kept **only if the account
    has an `email`** — nominative grant, ADR 0003 D1, issue #78 pt 3 — a CLI account falls back to the base
    tier) · `PendingInvitationPurger` (deletes accounts whose password hash is still empty — the literal
    definition of "never activated", issue #238, so a password set for the person from the backoffice
    exempts the account — invited more than N days ago, 30 by default, never a `ROLE_SUPER` one;
    `app:user:purge-pending-invitations`, daily in the housekeeping CronJob, `--dry-run` for a hand run;
    retention ≥ 1 day, `InvalidPurgeRetentionException` → exit 2, a shorter one would otherwise purge nearly
    every pending account in one manual run) · `PasswordSetupRateLimiterInterface` (calqued on the
    Contact rate limiter). Presenters: `CpgUserPresenter` (`/api/me`), `CpgUserAdminPresenter`
    (backoffice list — `id`, `username`, `email`, `roles`, `status`).
  - **The invitation token is created by the Messenger handler, never by the use case** (audit C2):
    `SendAccountInvitationMessage` carries `{userId, locale}` and nothing else, so a message parked in the
    doctrine `failure_transport` (SMTP down…) exposes no usable secret. `SendAccountInvitationHandler` reloads
    the user (missing or already activated → `logger->warning` + return, no retry), then in a single
    `wrapInTransaction` purges the previous `PasswordSetupToken` and issues a fresh one. Keep it that way: do
    not move token creation back up into `CpgUserInviter`, and never add a secret to the message.
  - `Infrastructure/` — `Doctrine/{CpgUser,PasswordSetupToken}Repository.php` (sole impls);
    `Messenger/SendAccountInvitationHandler.php` (creates the token — see above — then the `TemplatedEmail`
    `emails/account_invitation.*`, subject + localized strings built here);
    `RateLimiter/SymfonyPasswordSetupRateLimiter.php`;
    `Http/PasswordSetupRateLimitRequestListener.php` (audit C1 — a `kernel.request` listener at priority 15
    that consumes the per-IP quota on **`POST`** for the two exact paths of the flow **before** API Platform
    deserializes or validates anything; consuming it again in the Processor would halve the effective
    quota, so don't) — the 429's `Retry-After` comes from the shared
    `Shared/Infrastructure/Http/RetryAfterListener` (issue #273, see "Errors under `/api`" in `.claude/rules/backoffice-api.md`);
    the API Platform processors.
  - `Presentation/Command/CreateCpgUserCommand.php` (`app:user:create`, `--role` allow-list, password on
    standard input through `--password-stdin` — refused on a terminal, never an option value, issue #386;
    in preprod/prod it runs **only** through `k8s/base/create-user-job.yaml`, never `kubectl exec`, whose
    stderr is the operator's terminal and loses the `user-created` audit line — procedure in `k8s/README.md`,
    « Créer un compte super-administrateur ») and
    `Presentation/Controller/CurrentUserController.php` (`GET /api/me`). Everything else is API Platform
    resources — see `.claude/rules/backoffice-api.md` for the `ROLE_SUPER` ones, plus the two **public** (no auth, no CSRF,
    IP rate-limited) ones of the set-password flow.
  - **A secret never travels in a URL path** (audit A7, T4.1/T4.2). `AccountPasswordSetupValidationResource`
    (`POST /api/account/password-setup/validate` `{token}`) and `AccountPasswordSetupResource`
    (`POST /api/account/password-setup` `{token, password}`) both carry the token **in the body**, both
    answer 204, and both answer identically on failure — missing/blank/over-255 token 422, unknown 404,
    expired *or already used* 410 (merged on purpose: a distinct status would say a link had served).
    They replace `GET|POST /api/account/password-setup/{token}`, removed along with
    `AccountPasswordSetupStatusResource` and `AccountPasswordSetupProvider`; a `GET` cannot carry a secret
    anywhere but its URL, and a URL is written to the nginx sidecar's and the ingress's access logs, a body
    is not. **Never reintroduce a `{token}` in a `uriTemplate`.** Consequences to keep aligned: the two
    paths are listed **exactly** (not as a prefix) in `CsrfCookieRequestSubscriber::EXCLUDED_PATHS` and in
    `PasswordSetupRateLimitRequestListener::RATE_LIMITED_PATHS` — a prefix would silently exempt a future
    sibling route such as `…/password-setup-other`; both have their own justified `PUBLIC_PATHS` entry in
    `ApiRouteExposureTest`; the nginx `pwsetup` zone is `location ^~ /api/account/password-setup` (no
    trailing slash, both confs) so it covers the root path too.
    `AccountPasswordSetupResourceTest` pins the status matrix and the fact that no trailing-slash variant
    is served.
