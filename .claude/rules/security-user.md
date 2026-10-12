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
    included, and an implementation through its prototype's name since #414 — see
    `.claude/rules/backend-architecture.md`); on any class of `src/` whose file, or the file of a trait it uses
    (#421 — a trait is attributed to the class using it, the only one that can implement the interface or be a
    processor), names a `Symfony\Component\PasswordHasher\`
    type that is not declared in its `HASHERS` with the interface method the password reaches it by; and on any caller of those methods
    that is not a declared entry point (`ENTRY_POINTS`) with what validates it (`VALIDATED_BY`: the API
    Platform resource it is the processor of, which must carry a password field, or the CLI, which must name
    both constraints). The caller census closes the gap of the name census: a field called `$secret` feeding
    `changePassword()` is caught there. A guard rather than a `PlainPassword` value object, settled in #411
    (justification in the test's docblock): the VO would only cover the length — not the breach check nor its
    order —, would write the rule a second time, and its other gain, keeping the value out of stack traces,
    is what `#[SensitiveParameter]` gives. Revisit the day the hashing use cases multiply.
    Domain exceptions: `UsernameAlreadyUsedException`, `EmailAlreadyUsedException`,
    `UnusablePasswordSetupLinkException` (→404, **every** unusable link — see the set-password bullet
    below, issue #362), `CannotModifyOwnRolesException` / `CannotDemoteLastSuperAdminException` (→409),
    `AccountNotAwaitingActivationException` (→409), `AccountInvitationDeliveryException` (→503),
    `PasswordSetupRateLimitExceededException` (→429). **No message quotes a username** (issue #363,
    `CannotDeleteOwnAccount`, `CannotDeleteLastSuperAdmin`, `CannotModifyOwnRoles`,
    `CannotDemoteLastSuperAdmin`, `AccountNotAwaitingActivation`, `UsernameAlreadyUsed`): the kernel and API
    Platform log them (`info`, emitted by preprod's `debug`), and an invitee's username is derived from
    its address. The backoffice frontend reads the status and the `type`, never the `detail`;
    `app:user:create` repeats the username it was given in its own output. The purge reports and prints
    accounts by **UUID** (`PendingInvitationPurgeResult`, the CronJob's output is a pod log), and its
    `ROLE_SUPER` warning carries `userId`.
  - `Domain/Service/UsernameGenerator.php` — derives a free username from the email local part
    (filter to `[a-z0-9_.-]`, cap 60, pad with `user` if < 3, numeric suffix on collision).
  - `Application/` use cases (each with an interface, autowired single-impl):
    `CpgUserRegistrar` (CLI creation, password provided up front) · `CpgUserInviter`
    (`invite(email, Locale)` / `reinvite(user, Locale)`: derives username, creates/marks the pending account,
    then **only** dispatches `SendAccountInvitationMessage`). **Every account creation runs under one lock**
    (issue #364), `Application/UsernameAllocationLockInterface` — adapter
    `Infrastructure/Doctrine/PostgresUsernameAllocationLock`, one transaction-scoped advisory lock for all, its
    wait bounded by the shared skeleton (`Shared/Infrastructure/Lock/PostgresLockedTransaction`, issue #512,
    see `.claude/rules/deploiement.md`) → `UsernameAllocationLockTimeoutException` (500 `critical`):
    `invite()` checks the address, derives the username and saves under it, and dispatches only after its
    commit; `register()` refuses a taken username fast, hashes **outside** (slow), then re-checks and saves under
    it. Two simultaneous invitations of the same local part get `jean` and `jean2` — the second used to fail on
    the username index and be reported as « adresse déjà utilisée ». A new account is saved with
    `CpgUserRepositoryInterface::add()`, never `save()`: a unique violation that still gets through (a writer
    outside the lock, hand-written SQL) is named there by `Infrastructure/Doctrine/CpgUserUniqueViolation`, from
    the **first quoted identifier** of PostgreSQL's message (`"…"`, or `« … »` under a French `lc_messages`) —
    never a search of the whole message, whose `DETAIL` quotes the value — against
    `CpgUser::{EMAIL,USERNAME}_UNIQUE_INDEX`: email → `EmailAlreadyUsedException` (409), username →
    `UsernameAlreadyUsedException::takenMeanwhile()`, **unmapped on purpose** (500 — the frontend reads any 409
    of the invite route as `email-taken`), any other → `UnrecognisedCpgUserUniqueViolationException` (500, names
    the index only). None quotes a value or keeps the violation as `previous` (#356). Pinned by
    `InviteUsernameConcurrencyTest` (real processes, interleaved at the `preFlush`), `InviteUniqueViolationTest`
    (the real PostgreSQL message), `CpgUserUniqueViolationTest` and `PostgresUsernameAllocationLockTest`)
    · `PasswordSetupService` (`validate` / `complete`
    the public flow) · `CpgUserAdministrator` (delete / change-password; a password set from the backoffice on an account still
    pending activation **revokes its unused invitation tokens**, under the account lock — the admin's password
    is authoritative and the link in circulation dies, the useful case when the link is doubtful, decided on
    2026-10-11, issue #460; a new invitation remains possible) · `CpgUserRoleAdministrator`
    (`setSuperAdmin`, idempotent, anti-lockout guards; on demotion `ROLE_TRUSTED` is kept **only if the account
    has an `email`** — nominative grant, ADR 0003 D1, issue #78 pt 3 — a CLI account falls back to the base
    tier) · `PendingInvitationPurger` (deletes accounts whose password hash is still empty — the literal
    definition of "never activated", issue #238, so a password set for the person from the backoffice
    exempts the account — invited more than N days ago, 30 by default, never a `ROLE_SUPER` one;
    `app:user:purge-pending-invitations`, daily in the housekeeping CronJob, `--dry-run` for a hand run;
    retention ≥ 1 day, `InvalidPurgeRetentionException` → exit 2, a shorter one would otherwise purge nearly
    every pending account in one manual run; each deletion runs under the account lock, `AccountLockInterface`,
    and re-checks the criteria on the re-read account — a resend, an activation or a password set from the
    backoffice committed after the list was read spares it, an account already deleted is ignored, issue #459;
    `--dry-run` writes nothing and takes no lock) · `PasswordSetupRateLimiterInterface` (calqued on the
    Contact rate limiter). Presenters: `CpgUserPresenter` (`/api/me`), `CpgUserAdminPresenter`
    (backoffice list — `id`, `username`, `email`, `roles`, `status`).
  - **The invitation token is created by the Messenger handler, never by the use case** (audit C2):
    `SendAccountInvitationMessage` carries `{userId, locale, invitationGeneration}` and nothing else, so a message parked in the
    doctrine `failure_transport` (SMTP down…) exposes no usable secret. `SendAccountInvitationHandler` reloads
    the user (missing or already activated → `logger->warning` + return, no retry), then in a single
    `wrapInTransaction` purges the previous `PasswordSetupToken` and issues a fresh one. Keep it that way: do
    not move token creation back up into `CpgUserInviter`, and never add a secret to the message.
    **Nor in the mailer's own message** (issue #426): `Symfony\Component\Mailer\Messenger\SendEmailMessage`
    is routed to **no** transport, `sync` included, so `send()` handles it synchronously. Routed to `async`,
    the e-mail would leave **unrendered** — its context holds the set-password URL and its clear token —
    into RabbitMQ, then the `failure_transport`; and `send()` would return at enqueueing, so neither #419's
    conversion nor #425's removal would ever see the failure. `tests/Shared/Infrastructure/Messenger/SendEmailMessageStaysSynchronousTest.php`
    reads the declared `framework.messenger.routing` of **every** allowed environment (`EnvironmentConfigKernel`,
    preprod and prod run `prod`) and matches it as Messenger does (`HandlersLocator::listTypes()`: class,
    parents, interfaces, namespace wildcards, `*`), plus the compiled senders locator of `test`; its
    fixture project proves each form is caught.
    **Nor in the worker's failure** (issue #419): the template is rendered inside `mailer->send()`, after
    the token is valid — first on the "queued" `MessageEvent` `Mailer` dispatches before its bus
    (`MessageListener` renders regardless of `isQueued()`, so a broken template fails **bare**), then in the
    transport behind the bus (wrapped in a `HandlerFailedException`) — and a Twig message can quote a
    context value (`Key "…/set-password#<token>" … does not exist`). The worker's `ErrorDetailsStamp`
    keeps the message, the whole `previous` chain and each one's `traceAsString` (it empties the
    structured trace only; string arguments are cut at `zend.exception_string_param_max_len`, absent when
    `zend.exception_ignore_args` is on) into the `failure_transport`, and the retry listener logs the
    message on every attempt — prod included. So **any** failure of building the e-mail (an `Address`
    Mime refuses, which it quotes — #356) or of `send()` becomes `AccountInvitationDeliveryException` —
    `becauseTransportFailed()` (class + code) or `becauseSendingFailed()` (type plus `<template>:<line>`
    for a Twig error, `<file basename>:<line>` otherwise, described from the innermost exception of nested
    `HandlerFailedException`s by `Shared/Infrastructure/Mailer/MailerSendingFailure`, shared with the contact
    handler since #427) —, never with a `previous`, never quoting the original message. Retry
    is unchanged in this wiring: neither the replaced exception nor this one is (un)recoverable, the
    transport's strategy applies; one bare from `send()` would lose that effect (none exists).
    `SendAccountInvitationHandlerTest` drives the real mailer chain (transport, bus, dispatcher,
    `MessageListener`, `BodyRenderer`, Twig), checks the serialized stamp and the logged failure for the
    token with verbose trace settings, checks that each leaking case did quote it before conversion, and
    runs the real retry listener.
    **A token whose e-mail did not leave does not stay valid** (issue #425): on any failure of building or
    sending the e-mail, the handler removes **its own** token (`remove($token)`, a DQL `DELETE` by id that
    depends neither on the unit of work nor on a global `flush()`) first, then converts the failure, and the
    Messenger retry issues a fresh one. Never `deleteForUser()` there: a second consumer (a hand-run
    `messenger:consume failed` while the worker runs) may already have purged this token and sent its own.
    A failed removal never replaces the delivery exception (the retry strategy reads it): it is logged
    `error` with `exceptionClass`, `userId` and `tokenId` only — a Doctrine message can quote the digest,
    the id is no secret and lets an operator revoke that token alone — and the token then lives until the
    next successful send purges it, or until it expires. Accepted limits, settled in the review of #425:
    a transport exception does not prove the e-mail stayed home (lost response after acceptance, SMTP
    reconnection failing after the `250`), so a received link can be dead — the retry sends another; a
    **resend during an outage** leaves the account with no valid link until a retry succeeds, since the
    transaction purged the previous link before sending (kept on purpose: one active token at a time, and
    a resent link is often lost or doubtful); an asynchronous bounce after the provider accepted the
    message is out of reach (only a TEM events webhook would close it).
    `SendAccountInvitationDeliveryFailureTest` reads the table on the real `_test` database.
    **A message of a superseded invitation is dropped** (issue #434): `CpgUser::$invitationGeneration`
    (`invitation_generation`, default 0) is advanced by every `markInvited()`, and the message carries the
    generation it serves. When `CpgUser::wasReinvitedSince($message->invitationGeneration)` — the account's
    generation is **higher** —, the handler logs a `warning` and returns without retry: a delayed retry,
    or a message replayed from the `failure_transport` days later, would otherwise purge the token of a
    resend already delivered. A counter rather than the instant, chosen in the review of #434: an instant
    compared at the second missed two resends in the same second, and `invited_at` was then a wall time
    with no offset (see the timestamp rule in `.claude/rules/backend-architecture.md`). A message **ahead**
    of the stored generation (a resend not committed yet when the worker reads it) is processed: the last
    message is never lost. `PhpSerializer` never calls the constructor, so `__unserialize()` checks the
    shape: a malformed message (missing or mistyped field, invalid UUID or locale, a generation below 1,
    which `CpgUserInviter` never sends) throws `MalformedAccountInvitationMessageException`: a decoding
    failure, which follows the transport's retries then the `failure_transport` without ever reaching the
    handler (`InvitationEnvelopeDecodingTest`, through the container's serializer). The `C:` (Serializable)
    form bypasses `__unserialize()` — PHP returns the message with its `readonly` properties uninitialised
    and only an `E_WARNING` —: what refuses it is `PhpSerializer::safelyUnserialize()`'s error handler, a
    Symfony detail no code of ours writes, pinned by the same test (issue #465). **A message without a
    generation is refused too** (issue #448): the format queued before #434 was tolerated through v0.23.0,
    then refused once production's queues were checked empty of it. Only the code of v0.22.0 or earlier
    writes it — redeploying such an image, or a consumer still running it. Its refusal has its own
    factory, `withoutGeneration()`, whose message tells the two cases apart in `failed` (a resend to
    do, not a tampered queue to investigate) and quotes no value. **What to do with one**: read
    `messenger:failed:show <id>` for its `userId`, resend the invitation from the backoffice, then
    `messenger:failed:remove <id>`. Never `messenger:failed:retry` it: it fails to decode again, every
    time — at best it comes back to `failed` after the retries, at worst the entry is dropped and the
    `userId` to resend with it (not measured: `SendFailedMessageToFailureTransportListener` declines
    to send a message back to the failure transport it came from, in some cases).
    `SendAccountInvitationStaleMessageTest` replays the issue's scenario with the real `CpgUserInviter` on
    the `_test` database. **Concurrency, per account** (issue #435): `reinvite()` advances the generation
    in the database in one statement (`CpgUserRepositoryInterface::advanceInvitationGeneration()`, an
    `UPDATE … SET invitation_generation = invitation_generation + 1 … RETURNING`) and the entity takes the
    returned value (`CpgUser::markReinvited()`), so two concurrent resends (two FPM workers on a double
    click) publish two distinct generations — advanced in memory then written as an absolute value, both
    got N+1. The same statement carries the "pending activation" condition (an account activated
    meanwhile → `AccountNotAwaitingActivationException`, 409; deleted → `CpgUserNotFoundException`, 404), and
    the repository gives the unit of work the written values as original state — otherwise a later
    `flush()` rewrote the entity's generation as an absolute value over a concurrent resend. **One account
    lock for the handler and the activation** (issues #435, #458): `Application/AccountLockInterface::whileLocked()`,
    adapter `Infrastructure/Doctrine/PostgresAccountRowLock` — on the skeleton of the three PostgreSQL locks,
    `Shared/Infrastructure/Lock/PostgresLockedTransaction` (issue #512: a hand-made transaction, so that a
    refusal inside does not close the EntityManager and a refused COMMIT surfaces as the database's error —
    `Connection::transactional()`, used until #512, masked it behind « There is no active transaction »; a
    `lock_timeout` of 5 s when the session has none, the console having no FPM `PGOPTIONS`), a
    `SELECT 1 … FOR UPDATE` that says whether the row still exists (`refresh()` on a
    deleted row raises nothing, and the token insert then failed on the foreign key) →
    `CpgUserNotFoundException`, then `refresh()` of the account. Order for every operation: **the account,
    then its tokens**, or the two deadlock. The handler re-checks activation and generation under it before
    `deleteForUser()` — in `READ COMMITTED` a second consumer's purge did not see the first one's
    uncommitted token, and two tokens stayed usable —; a deleted, activated or resent account drops the
    message (`warning` "constaté sous verrou"). `PasswordSetupService::complete()` hashes **outside** the
    lock (slow), then under it refuses an account already activated (`replayed`), claims the token,
    activates, and **purges the account's unused tokens** (`deleteUnusedForUser()`; the consumed one stays,
    so a replay is still logged `replayed`, not `rejected` — the response is the same either way, #362). The purge alone guarantees no usable token on an
    activated account; the lock adds that a concurrent handler waits instead of deleting the claimed token —
    without it, the link's replay passed for an unknown link and the audit lost `replayed`. `claim()` says why it failed
    (`Domain/ValueObject/TokenClaim`: `Claimed`, `AlreadyUsed`, `Expired`, `Gone`, read back on the table):
    only `AlreadyUsed` is a `replayed`; `Expired` is no event; `Gone` — a resend replaced the token while
    the password was being hashed, or the account was deleted — is an unknown link (`rejected`), never
    a replay. `validate()` refuses a still-usable link of an account already active (`replayed`, a token
    left over from before #458), as `complete()` does under the lock. The removal of an undelivered token
    (#425, `remove()`) never takes a consumed one (`used_at IS NULL`): the e-mail may have left despite the
    error and the person activated meanwhile. A lock held past `lock_timeout` raises
    `Infrastructure/Doctrine/AccountLockTimeoutException` (SQLSTATE `55P03`, named, unmapped: a `critical`
    500, like the order-scope lock of #389; the worker's Messenger retry takes over).
    The operation receives the account as re-read under the lock (`Closure(CpgUser)`): read that one, never
    a state from before the lock. Pinned by three real-process tests — `ReinviteConcurrencyTest`,
    `SendAccountInvitationConcurrencyTest` (interleaved at the `postFlush`) and
    `PasswordSetupActivationConcurrencyTest` (at the `preFlush`), like `ContributionOrderConcurrencyTest`,
    child scripts under `tests/Security/User/Fixtures/` — and `PostgresAccountRowLockTest`.
    Accepted limits:
    if `reinvite()` saves the new generation but its dispatch fails (broker down, refusal or missing ack,
    #479 — the admin gets a 503 `InvitationQueueUnavailableException`, issue #485, no audit entry), a
    pending retry of the previous invitation is dropped too, until the admin resends. **A first invitation
    that cannot be queued is undone** (#485): `invite()` removes the account it has just saved, so the
    admin retries without an « adresse déjà utilisée » 409 and the log announces no invitation that never
    left — by `removePendingInvitation()`, a DQL `DELETE` by id that never touches an account activated
    meanwhile and needs neither the unit of work nor a global `flush()` (the #425 rule); were the message
    stored anyway (a lost ack), the handler finds no account, logs a `warning` and stops without retry —
    or, had it run before the removal, its link is dead (the token leaves with the account) and the admin
    resends; a failure of that removal surfaces as itself (a `critical` 500), the account left pending; an **old worker**
    cannot decode a message carrying the generation (`Cannot create dynamic property`) — during a rollout,
    a new API pod can publish before the `Recreate` worker is replaced, and after a rollback every new
    message is concerned —: it goes to `failed` with its raw body and replays once the new code runs, so
    read `messenger:failed:show` after a release or a rollback.
  - `Infrastructure/` — `Doctrine/{CpgUser,PasswordSetupToken}Repository.php` (sole impls;
    `CpgUserRepository` is also the **loader of the `cpg_users` provider**, `UserLoaderInterface`, issue #418:
    the `entity` provider has no `property` option on purpose — with it, Doctrine's provider threw
    `User "<identifier>" not found.`, which the `security` channel logs at `info` in production, and the tried
    identifier is sometimes a password typed in the wrong field; `loadUserByIdentifier()` throws a
    `UserNotFoundException` whose message quotes nothing. Never put `property: username` back. An
    identifier outside `CpgUser::USERNAME_PATTERN` is refused **without a query**: PostgreSQL reads a text
    parameter as a C string, so `jane\0…` used to load `jane` — a valid login — while `login_throttling`
    counted each suffix apart, and a non-UTF-8 byte failed the query);
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
    « Créer un compte super-administrateur »; the scope of « never `kubectl exec` », and its one read-only
    exception for a human, is in `.claude/rules/deploiement.md`, issue #520) and
    `Presentation/Controller/CurrentUserController.php` (`GET /api/me`). Everything else is API Platform
    resources — see `.claude/rules/backoffice-api.md` for the `ROLE_SUPER` ones, plus the two **public** (no auth, no CSRF,
    IP rate-limited) ones of the set-password flow.
  - **A secret never travels in a URL path** (audit A7, T4.1/T4.2). `AccountPasswordSetupValidationResource`
    (`POST /api/account/password-setup/validate` `{token}`) and `AccountPasswordSetupResource`
    (`POST /api/account/password-setup` `{token, password}`) both carry the token **in the body**, both
    answer 204, and both answer identically on failure — missing/blank/over-255 token 422, and **one 404
    for every unusable link** (issue #362, ADR 0001 amended 2026-10-12): unknown, replaced by a resend,
    purged or deleted with its account, expired, already used, or aimed at an account already active all
    get the same status, `type`, `title` and `detail`. One domain exception,
    `UnusablePasswordSetupLinkException`, with a **constant** message (its named constructors `unknown()` /
    `expiredOrAlreadyUsed()` are for the reader only): the `detail` is that message, the `type` derives
    from the status. Until #362, 404 (unknown) and 410 (expired or used) were apart, and since a consumed
    token is kept while a never-activated account is purged at 30 days with its tokens, a 410 past that
    delay meant « this account was activated » — the e-mail also carries the username. What tells the
    cases apart is the audit log (`rejected` / `replayed`), never the response. **Never** give one of them
    its own status, exception or message again; `testEveryUnusableLinkGetsTheSameResponse` (status,
    every header but `Date` and the profiler's, body minus the debug `trace`, seven fates of a link, both
    routes) and `UnusablePasswordSetupLinkExceptionTest` pin it. Accepted limit: the paths do not do quite
    the same work — an unknown or replayed link writes an audit line, a merely expired one none (#356) —,
    a Monolog write under network jitter, with no SQL round trip between them (keep the join below). The frontend reads 404 — and 410, for a backend from
    before #362 during a rollout or after a rollback — as `unusable`; see `.claude/rules/frontend-backoffice.md`.
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
