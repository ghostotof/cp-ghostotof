---
paths:
  - "backend/src/Security/**"
  - "backend/src/Shared/Infrastructure/Http/**"
  - "backend/config/packages/security.yaml"
  - "backend/config/packages/lexik_jwt_authentication.yaml"
  - "backend/config/packages/nelmio_cors.yaml"
  - "backend/config/packages/monolog.yaml"
  - "backend/tests/Security/**"
  - "frontend/src/infrastructure/http/**"
---

# Backend — `Security/Authentication`

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture › `Security/Authentication/` ». The index of every rule is at the top of `.claude/CLAUDE.md`.

- **`Security/Authentication/`** — login/logout/JWT/CSRF mechanics, deliberately kept out of `User/`: this is
  infrastructure wiring around Symfony Security + LexikJWTAuthenticationBundle, not a domain concept of its own.
  - `Infrastructure/Jwt/LoginSuccessSubscriber.php` (attaches the `XSRF-TOKEN` cookie, shapes the
    `login_check` response body — includes `roles` alongside `username`, needed by the frontend to gate `/admin`
    without waiting for a full `checkAuth()` round-trip) and `CookieLogoutListener.php` (expires both cookies on
    `/api/logout`).
  - **`Infrastructure/Http/AuthCookieFactory.php` is the only place that builds a `BEARER` or `XSRF-TOKEN`
    cookie** (issue #87): names (`AuthCookieFactory::BEARER` / `::XSRF_TOKEN`, also what
    `CsrfCookieRequestSubscriber` reads), `Path=/`, no `Domain`, `SameSite=Lax`, `HttpOnly` on `BEARER`
    only, `Secure` iff `prod`. `bearer()` / `xsrf()` take an optional expiry (session cookie without),
    `expired()` mirrors the issued attributes with a past date — a cleared cookie whose attributes differ
    from the set one is *not* removed by the browser, which is the drift the factory exists to prevent. The
    three sites (`LoginSuccessSubscriber`, `BaseAccessController`, `CookieLogoutListener`) go through it;
    the one exception is the login `BEARER`, still set by Lexik from `lexik_jwt_authentication.yaml`
    (`set_cookies` + `when@prod`), and `tests/Security/Authentication/AuthCookieAttributesTest.php` pins
    that config to the factory by comparing the real `Set-Cookie` of login, base-access and logout per
    name. Any new auth cookie goes through the factory, never `Cookie::create()` or `clearCookie()`
    inline. `__Host-` prefixing is a separate, preprod-tested step (it renames what Lexik and the frontend
    read by name).
  - **Tokens, JWTs and CSRF values are hidden from stack traces** (`#[SensitiveParameter]`, issue #414): the
    guard covers all of `src/`, so its rule lives in `.claude/rules/backend-architecture.md`.
  - `Infrastructure/Http/CsrfCookieRequestSubscriber.php` — double-submit-cookie CSRF check, a `kernel.request`
    listener at priority 20 (must run *above* the Security firewall's priority 8 — see the class docblock).
  - `Infrastructure/Http/LoginCsrfRequestListener.php` — **login-CSRF guard** (issue #76) on the two anonymous
    routes that *set* a `BEARER` cookie, `POST /api/login_check` and `POST /api/account/base-access`. Both are
    rightly outside the double-submit (an anonymous caller has no `XSRF-TOKEN` to echo), but a cross-site
    HTML form could submit them top-level: the victim's cookie isn't sent (`SameSite=Lax`), yet the
    response's `Set-Cookie` *is* accepted and **replaces** the trusted `BEARER` with the attacker's (or a
    15-minute base-access one). The guard requires the `X-Requested-With` header, which a form cannot set and
    which makes a cross-site `fetch()` fail its CORS preflight. **Presence is the protection, not the
    value.** Same priority 20 as the CSRF subscriber: above the firewall (or `json_login` sets the cookie
    first) and above the rate limiters (15), so a forged submission never burns the victim's IP quota.
    `nelmio_cors.yaml` lists the header in `allow_headers`; the frontend sends it via
    `infrastructure/http/loginCsrfHeader.ts` on both calls. Functional tests therefore pass
    `'HTTP_X_REQUESTED_WITH' => 'fetch'` in `server:` on every login/base-access request.
  - **Base access (ADR 0003 D6; moved from `.claude/CLAUDE.md`'s Goal #9, #602).** `POST /api/account/base-access`
    (`Security/User/Presentation/Controller/BaseAccessController`, per-IP rate-limited, excluded from the
    double-submit CSRF like `/api/contact` but guarded by `LoginCsrfRequestListener` above) issues a 15-minute
    JWT carrying exactly `ROLE_USER` and the `guest` claim, for a `BaseAccessUser` that is **never
    materialised in the database** (`BaseAccessUserProvider` rebuilds it from the signed payload alone, first
    in the `app_users` chain). « Terminer cet accès » (issue #65) ends it through the unchanged
    `POST /api/logout`, which expires the cookie whoever holds it. The tier is read from the HTTP status of
    `GET /api/me` — 401 anonymous, 403 base, 200 trusted —, no dedicated endpoint. The response body carries
    `expiresAt`, the only way the frontend can know when the httpOnly cookie dies (see
    `.claude/rules/frontend.md`, « Base access »).
  - **Any listener that decides on the request path must use `App\Shared\Infrastructure\Http\CanonicalPath::of()`,
    never `getPathInfo()` directly** (issue #77). `getPathInfo()` is *not* decoded, while the router, the
    firewalls and `access_control` all decide on `rawurldecode()`: `POST /%61pi/logout` reached the
    `LogoutListener` without ever passing the CSRF check, and `/api/account/base%2Daccess` escaped its rate
    limiter. The `kernel.request` guards go through the helper
    (`CsrfCookieRequestSubscriber`, `LoginCsrfRequestListener`, `BaseAccessRateLimitRequestListener`,
    `PasswordSetupRateLimitRequestListener`, `AssistantRequestSizeListener`), each with a `%XX`
    regression test; two `kernel.exception` listeners use it too, `Shared/Infrastructure/Http/`'s
    `ApiProblemResponseListener` and `ApiJsonErrorFormatListener` (see "Errors under `/api`" in `.claude/rules/backoffice-api.md`) — and
    `SecurityAuditLogger` logs the canonical form for the same reason. A subtree check goes through
    `CanonicalPath::isUnder($request, '/api/<x>')` (`isUnderApi()` is that, for `/api`): "the prefix exactly,
    or the prefix followed by `/`", the `access_control` anchoring rule, written once (issue #322).
  - **The `login` firewall is anchored on its `check_path`, the `api` one deliberately is not** (audit A23,
    `security.yaml`). `login` is `^/api/login_check$`: it holds a `json_login` and reads **no** JWT, so any
    route ever added under a looser `^/api/login` would be served anonymously, the visitor's `BEARER` never
    looked at. `api` stays the bare `^/api` **on purpose** — anchoring it would push a neighbour like
    `/apix` out of *every* firewall, hence out of every `access_control` (the `AccessListener` is a firewall
    listener), i.e. remove protection rather than add it; it is allow-listed with that justification in
    `tests/Security/AccessControlAnchoringTest.php`, which pins firewall patterns as well as access rules.
    `tests/Security/Authentication/LoginFirewallScopeTest.php` pins the behaviour.
  - **No session, in any environment** (`framework.session: false`, audit A23). Nothing needs one — both
    firewalls are `stateless`, auth is a JWT in a cookie, the CSRF is a double-submit cookie, there is no
    Symfony form. The point is not tidiness: the default handler writes under `var/`, and on a
    `readOnlyRootFilesystem` pod that is exactly the silent failure ADR 0005 exists to forbid. Without a
    session the error is loud (`SessionNotFoundException`) and surfaces in test or dev instead. Re-enabling
    `framework.form` or a session-token CSRF would need one — `tests/Security/StatelessSessionTest.php`
    pins the invariant, including in `test`.
  - **`Infrastructure/Security/FailedLoginTimingEqualizer.php` — a failed login must not be timeable**
    (audit A10). The three failures already return the same 401, but not in the same time: a known active
    account paid a full bcrypt/argon `verify()`, while an **unknown identifier** (the user is never loaded)
    and an **account without a password** (an invitation whose person has not chosen one yet: empty hash,
    `password_verify($p, '')` returns at once) cost nothing — the gap said which identifiers exist and which
    are pending invitations. On `LoginFailureEvent`,
    `login` firewall only, priority **-100** (after the throttling counter and the audit log, neither of
    which should wait on a hash), it pays a dummy `hash()` of a constant with `CpgUser`'s configured hasher
    in **those two cases only**: never for an account that has a password (the real hash already happened —
    a second one would recreate the gap the other way), never for the throttling (its answer must stay cheap), never for
    a blank password (refused before any DB access, identically for everyone). `hash()` of a constant, not
    `verify()` against a frozen hash, so it follows `password_hashers` if the algorithm or cost
    changes. The submitted password is never read. The response is untouched — the three 401s must stay
    byte-identical, which is the other half of non-enumeration. **What decides is the absence of a
    password, `CpgUser::hasPassword()`, not pending activation** (issue #560): a `ROLE_SUPER` can set the
    password of an invited account from the backoffice (#238, #460), which stays pending activation while
    `verify()` does the real hash — compensating it as well doubled its response time and singled it out as
    « an invitation whose password was set for the person ». Until #560 the listener read
    `isPendingActivation()`, written when pending meant an empty hash. **A password over the hasher's bound
    is refused before any account is loaded** (issue #588): past `CpgUser::MAX_PASSWORD_LENGTH` bytes
    (`PasswordHasherInterface::MAX_PASSWORD_LENGTH`, `strlen`), `verify()` returns `false` without hashing,
    so an existing account with a password answered one bcrypt faster than an unknown identifier — about
    270 ms at production cost, measured with `password_hash` on a workstation (#588) and by A10 (273 ms), not
    on a pod — enough for one request to tell. `Infrastructure/Security/OverlongPasswordRefusalListener` (`CheckPassportEvent`, priority
    **1500**: below `login_throttling`'s `checkPassport` at 2080, which sets the tried identifier — so the
    refusal counts per (IP, identifier) and a throttled pair still gets its 429 —, above
    `UserCheckerListener` at 256, the first to load the account) throws `SubmittedPasswordTooLongException`,
    a `BadCredentialsException` that overrides neither `getMessageKey()` nor the code: Lexik's 401, byte for
    byte. No account loaded, nothing hashed, the same path for every identifier. **This listener and the
    audit subscriber recognise that exception before calling `Passport::getUser()`**, which would load the
    account (a query, and the unknown identifier alone compensated — the gap back): no dummy hash,
    `login-failed` with the fingerprint and no `userId`. Only the length is read, never the value; it is
    not a validation of a password being set (see `.claude/rules/security-user.md`). Refusing rather than
    compensating (the equalizer would have had to read the submitted password, pay a bcrypt and keep the
    query) or truncating (a wrong input whose first 4096 bytes are the password would pass). **Accepted
    limits** (security review of #588): the refusal is faster than an ordinary failure (no bcrypt), so a
    stopwatch tells « too long » from « wrong », never which account exists; and below the bound,
    `verify()` pre-hashes in sha512 an input over 72 bytes or holding a NUL while the dummy `hash()` of a
    short constant does not — microseconds under bcrypt and network noise, out of reach under
    `login_throttling`, and equalizing it would mean reading the input. Which failures leave no account
    loaded is written once, `Infrastructure/Security/LoginFailureCause` (throttled, unknown identifier,
    overlong password, other), read by this listener and the audit subscriber — a new early refusal adds
    its case there. The
    exception has a `log_level: info` entry it never uses: it inherits `AuthenticationException`'s
    `#[WithHttpStatus(401)]`, which `ExceptionLogLevelCoverageTest` reads, but the `AuthenticatorManager`
    catches it before the kernel. `OverlongPasswordRefusalListenerPriorityTest` pins the window on the real
    dispatcher, and the exact list of listeners allowed above it (the throttling, the firewall's
    `UserProviderListener` at 2048, which only sets the loader): a new one there must be shown not to
    load the account. `CpgUser::MAX_PASSWORD_LENGTH` is pinned equal to the hasher's constant.
    **One derivation whatever the stored hash's age** (issue #590): `security.yaml` writes
    `algorithm: bcrypt` and `cost: 13` (`4` under `when@test`), **never `auto`**. Under `auto`,
    `MigratingPasswordHasher` tried `SodiumPasswordHasher` after a failure on a hash that `needsRehash()`,
    which falls back on `password_verify` — two bcrypt, the equalizer pays one. With `bcrypt` the
    secondaries left (Pbkdf2, MessageDigest) refuse any hash holding a `$` before computing anything; an
    argon hash still verifies and a successful login still rehashes (`StalePasswordHashRehashTest`); the
    `$2y$13$` hashes `auto` produced are not stale. The cost is written so that no new Symfony default
    changes it silently. `CpgUserPasswordHasherTest` proves no secondary verifies a stale bcrypt or argon
    hash (behaviour, with the right password), pins the exact list of secondary classes (a new one must be
    shown to refuse before deriving), and pins per environment (`EnvironmentConfigKernel`) the **whole**
    `CpgUser` entry (an `id` would replace everything), `CpgUser` as the only key of `password_hashers`, and
    no `PasswordHasherAwareInterface` on `CpgUser`. **Accepted limit** (decided on
    2026-10-13): that one derivation runs at the **stored** cost, the equalizer pays the configured one —
    after a change of cost or algorithm, each account with an older hash answers in a time of its own
    (34 ms at cost 10, 539 ms at 14, against 270 ms at 13, workstation) until its next successful login
    rehashes it; a dormant account keeps it. Rehashing ahead needs the plaintext; the two workarounds were
    not retained — an onion hash (bcrypt of the old hash, which no Symfony hasher verifies) or emptying the
    password and reinviting (the account becomes « without a password », compensated, but the person must
    choose a new one; never on the last `ROLE_SUPER` with a password). Paying the difference here would
    mean reading the stored hash and works one way only. **Before changing
    `password_hashers`**, read `k8s/README.md` « Avant de changer `password_hashers` » (read-only SQL count
    of hashes per format, a human's `exec`) and update the test in the same change. Also accepted: an
    unknown identifier matching `USERNAME_PATTERN` pays a query returning no row, an account one row and
    its hydration — about 100 µs against bcrypt's ~3 ms standard deviation, thousands of tries per
    identifier, weeks for a single IP under `login_throttling`, hours for an attacker spread over many
    addresses (the per-IP counters' limit, already accepted).
    `FailedLoginTimingEqualizerTest` + `tests/Security/Authentication/LoginFailureTimingTest.php` pin it.
    Related fact worth knowing: `login_throttling` answers a **429** `/errors/rate-limited` with
    `Retry-After` since issue #399, the only failure that leaves Lexik's 401 — it says nothing about the
    account. `LoginThrottlingRefusalListener` throws it at priority -200, below this listener; see "Every
    quota 429" in `.claude/rules/backoffice-api.md`.
  - **`Infrastructure/Log/SecurityAuditLogger.php` is the single entry point of the security audit log**
    (3rd audit, A5/D5, Monolog channel `security_audit`, `info`, JSON on stderr in prod — see
    `monolog.yaml`). Implements `Application/SecurityAuditLoggerInterface`, one method per event:
    `login-succeeded`, `login-failed`, `login-throttled`, `logged-out`, `base-access-issued`,
    `csrf-rejected`, `backoffice-access-denied`, `rate-limiter-unavailable`, `user-invited`, `user-reinvited`, `role-changed`
    (`superAdmin` bool), `password-changed`, `user-deleted`, `account-activated`, `password-setup-token-rejected`,
    `password-setup-token-replayed`, `password-setup-throttled`, `contact-throttled`, `base-access-throttled`, `user-purged`
    (`actor: system`, a scheduled command), `user-created` (issue #386, `app:user:create` through `CpgUserRegistrar`,
    with `superAdmin` like `role-changed`, `actor: console` — a person at a terminal the log cannot name, never
    `system` nor `anonymous`) — the two events whose actor is not read from the token storage; `record()` takes an
    explicit actor for CLI callers). Every record carries
    `event` (the stable kebab-case key to filter on), `actor`, `ip`, `path` (canonical), plus the subject.
    **An account is designated by its `userId` (RFC 4122) only, as subject and as `actor`, never by its
    login identifier** (issue #363): an invited account's username is derived from the local part of its
    address (`UsernameGenerator`, ADR 0001 D2), usually a first and last name. `actor` is the UUID of the
    authenticated account, the base tier's random `guest-…` marker, `anonymous`, `system`, `console`, or
    `unidentified` for any other `UserInterface` (none exists). `user` now only ever carries that
    `guest-…` marker (`base-access-issued`, a guest's `logged-out`). Both go through
    `App\Shared\Domain\Security\AccountReference` (`CpgUser::accountId()`), the interface `Ai` reads too,
    so no context depends on `Security`. To name an account during an incident:
    `SELECT username, email FROM cpg_user WHERE id = '<userId>'` — once deleted, its lines designate no one,
    which is the point (minimisation, RGPD register §6; not Goal #9, which is about what the API exposes).
    It runs through a human's read-only `kubectl exec` on the `postgres` pod (`k8s/README.md`, « Journal de
    sécurité »), **never a Job**: a Job would print the name and address into its pod's logs, readable by
    the CI token's `pods/log` — the exception and its bounds are in `.claude/rules/deploiement.md` (issue #520).
    **Never a password, a token (JWT, XSRF, invitation), an e-mail, a login identifier, a request body or a
    serialized exception** in any context.
    **The identifier tried at login is never written as typed** (issue #418): a password typed in the
    username field would otherwise reach the production log. `login-failed` and `login-throttled` carry
    `userFingerprint` instead — `Infrastructure/Log/LoginIdentifierFingerprint`, an HMAC-SHA256 keyed by a
    key derived from `kernel.secret` for this use alone (issue #455, see `.claude/rules/backend-architecture.md`), case-folded like `login_throttling`'s counter (`mb_strtolower`), **6 hex chars
    (24 bits) on purpose**, an empty key refused (with the key, a longer fingerprint would confirm
    offline, at SHA-256 speed, a password typed as identifier; the class docblock has the numbers) — and
    designate the account (`userId`, from the loaded `CpgUser`, never from the input) **only when the
    authentication already loaded it**: no `userId` for an unknown identifier and never for
    `login-throttled`, which refuses before the account is loaded (loading it there would cost a query on
    the path an attacker hammers). The fingerprint is the link between a `login-throttled` and the
    failures that caused it — when the per-(IP, identifier) counter refused; a refusal by the per-IP
    counter (25 / 15 min) may carry a fingerprint no earlier failure has, read it by `ip` then. Filtering
    the **log** on `CpgUser::USERNAME_PATTERN` was rejected: a keychain-generated or plain alphanumeric
    password matches it (the `cpg_users` loader does filter on it, for another reason, see
    `.claude/rules/security-user.md`). Price: rotating `APP_SECRET` changes every fingerprint.
    Symfony's own `security` channel had the same leak — `Authenticator failed.` logs the user provider's
    exception — closed at the source by the `cpg_users` loader (see `.claude/rules/security-user.md`) on
    the `login` firewall, the only one that receives a typed identifier. On the `api` firewall the
    `app_users` chain still quotes the identifier in its own `UserNotFoundException` (`There is no user
    with identifier "…"`, a JWT for a deleted account): not a #418 leak (a signed JWT, never a form
    field), but a #363 one, closed by **`Infrastructure/Log/SecurityChannelAccountRedactor`**, a Monolog
    processor on the `security` channel only. It also rewrites the token of `Authenticator successful!`,
    written at `info` on **every authenticated request** of the `api` firewall, which `JsonFormatter`
    rendered by its `__toString()` (`JWTPostAuthenticationToken(user="jean.dupont", roles="…")`):
    now `Class(userId="…", roles="…")` (`user="guest-…"` for the base tier, roles only otherwise). Any
    exception whose chain holds a `UserNotFoundException` becomes its normalised form (class, message,
    code, `file:line`, `previous`, no trace), that link's message replaced by `WITHHELD`. Both lines stay:
    `Authenticator failed.` is the trace of a forged or expired JWT — raising the handler to `notice` was
    rejected for that reason. `SecurityChannelAccountRedactorTest` pins the rewriting; the guard below the wiring;
    `SecurityAuditLogTest::testAPasswordTypedAsTheIdentifierReachesNoLog` formats every channel's records
    with Monolog's `JsonFormatter`, the production one, on an unknown identifier and on the throttling
    refusal, and skips only `doctrine`'s `debug` records (SQL parameters, logged under `kernel.debug` only,
    never with `APP_DEBUG=0`).
    The same rule now holds for the **messages** of the exceptions the kernel logs (issue #356):
    `EmailAlreadyUsedException` no longer quotes the address (`alreadyLinkedToAnAccount()`, the backoffice
    reads the 409 status, never the `detail`), and the two delivery exceptions
    (`ContactMessageDeliveryException`, `AccountInvitationDeliveryException`) **never chain the mail
    transport's exception**: its message copies the server's answer (SMTP line, Scaleway API body), which
    can quote the sender or the recipient, and the worker logs the whole `previous` chain.
    `Shared/Infrastructure/Mailer/MailerTransportFailure` keeps its class and a numeric code (HTTP status
    for the API transport, SMTP reply code otherwise), the e-mail counterpart of `ProviderFailure`; each
    context keeps its own delivery exception, whose transport factory is identical on purpose (`Domain/`
    never imports `Shared/Infrastructure`). The invitation one has a second factory since issue #419,
    `becauseSendingFailed()`, for any other failure of `send()` (template rendering, after the token is
    valid — see `.claude/rules/security-user.md`); the contact one too since issue #427
    (`ContactMessageDeliveryException::becauseSendingFailed()`): a template error or an `Address` Mime
    refuses quotes what the visitor typed (address, name, text), on every retry and up to the
    `failure_transport`. Both handlers build the e-mail **inside** the `try`, catch any `Throwable` and
    describe it through `Shared/Infrastructure/Mailer/MailerSendingFailure::describe()` (type plus
    `<template>:<line>`, or `<file basename>:<line>`, from the innermost exception under every Messenger
    wrapper — `MailerSendingFailure::innermost()`, which is also where a transport failure is recognised, so a
    wrapped one keeps its class and code), the one place that description is written; the contact handler
    names a refused `Address` by its **role** (`from`, `to`, `replyTo` — visitor input or the site's own
    configuration, e.g. a secret left empty), never by its value (`becauseAddressRefused()`); `tests/Support/DrivesTheMailerChain` is the real mailer
    chain their tests fail the rendering through. A test checking that a value never reaches a verbose
    trace passes the value's **name** to its data provider, never the value: the test method's own
    arguments are in the trace. The price: the SMTP enhanced status (`5.1.1`) and Scaleway's error
    text are gone from the logs. **Nor does a database error quote a value** (issue #428):
    PostgreSQL copies it into its message — `DETAIL: Key (email)=(…) already exists`, `DETAIL: Failing row
    contains (…)` (the password hash included), `invalid input syntax for type uuid: "…"`, a bound parameter
    in `CONTEXT:` — and the kernel and the worker log the message and the whole `previous` chain.
    `Shared/Infrastructure/Doctrine/DriverErrorRedactionMiddleware` (`#[AsMiddleware]`, every connection)
    wraps the driver's exception converter: each driver exception is handed to PostgreSQL's converter as a
    `RedactedDriverException` — `DETAIL`/`CONTEXT` lines dropped, a value quoted after a colon at the end
    of a line or in `value "…" is out of range` masked, chain cut —, so the DBAL class and the SQLSTATE
    that callers read (`CpgUserInviter`'s unique violation, the order lock's `55P03`) are unchanged, and
    the constraint, column and table names stay. `DriverErrorRedactionMiddlewareTest` checks the real
    server's messages, `RedactedDriverExceptionTest` the other forms. Not covered: a value PostgreSQL would
    quote in a form neither pattern knows — add it to `RedactedDriverException::redact()` with its test.
    **An address that `Mime\Address` would refuse never gets past
    validation**: `framework.validation.email_validation_mode: strict` (`validator.yaml`), egulias'
    grammar, the one Mime uses — the default `html5` mode accepted `a..b@example.com`, and the worker then
    failed on a `RfcComplianceException` quoting it, logged on every retry. Don't override `mode:` on an
    `Assert\Email` whose value ends up in an e-mail.
    `SecurityAuditLoggerTest::testNoContextValueEverCarriesAPasswordATokenOrAnEmail` pins that with
    sentinel values run through every method (login identifiers of the actor and the subject included);
    extend it when adding one. **`tests/Security/AccountIdentifierReachesNoLogTest.php` is the guard on
    every channel** (issue #363): an account invited from `prenom.sentinelle@…` goes through invitation,
    activation, replay, failed and successful login, authenticated requests, a backoffice 403, both
    per-account AI quotas, logout, the three reachable backoffice 409s, role and password changes,
    deletion, its JWT replayed after deletion, and the purge (one purged, one `ROLE_SUPER` skipped); no
    record of any channel at any level (`doctrine`'s `debug` aside, as above) may contain `sentinelle` —
    an e-mail address would fail it too — and the records it expects must have been seen. A new site that
    writes a login identifier belongs in that scenario. Who calls what:
    `Infrastructure/Log/SecurityEventsSubscriber` for `LoginSuccessEvent`/`LoginFailureEvent` (**`login`
    firewall only** — the `api` firewall re-authenticates the JWT on every request and dispatches the
    same events), `LogoutEvent`, and the backoffice 403 on `kernel.exception` at priority 0 (after the
    firewall's `ExceptionListener` at 1, which wraps the voter's `AccessDeniedException` in an
    `AccessDeniedHttpException` — that `previous` is required, so the CSRF guards' bare
    `AccessDeniedHttpException` isn't logged twice; an anonymous hit is a 401 that never reaches it); the
    two CSRF guards call `csrfRejected()` right before throwing (actor is `anonymous` there by
    construction — priority 20 runs before the firewall);
    `Infrastructure/Http/RateLimiterLockFailureListener` logs `rate-limiter-unavailable` (issue
    #276) when a limiter's shared lock fails on an `/api` route — acquiring, releasing, or a
    conflict relayed by the component's in-memory store — and answers 503 problem+json with
    `Retry-After`, never a 500. No subject: the lock resource carries the limiter's key (an IP, a
    tried username), so neither the response nor the audit record names it, and the listener sits
    at priority 16, above Symfony's `logKernelException` (0), writing its own `error` line with
    the exception classes only — the raw message reaches no prod log at all. It catches any
    Lock failure under `/api`: a future non-limiter lock must revisit it; `BaseAccessController`
    logs the `guest-…`
    identifier, never the token; the `Security/User/Application` use cases and the housekeeping
    `PendingInvitationPurger` log after the successful action. **The refusals no other trace attributes**
    (issue #356, before it they only had the kernel's generic `Uncaught PHP Exception` line, with no IP
    nor path): `PasswordSetupService` logs `password-setup-token-rejected` (unknown token, no subject) and
    `password-setup-token-replayed` (a link **already used** comes back — possible leak of the link — with
    the account it activated; journal side only, the response is that of every unusable link, an unknown one
    included — one 404, issue #362 —, and a merely expired link is no event) right before throwing; `Infrastructure/Log/ThrottledRequestAuditListener`
    (`kernel.exception`, priority 0, above API Platform's -96 and `ApiProblemResponseListener`'s -98, which
    stop propagation) maps the three anonymous per-IP quota exceptions to `password-setup-throttled`,
    `contact-throttled`, `base-access-throttled`, no subject (the limiter's key is the IP). A listener rather
    than a call at the three throw sites on purpose: `Contact` would otherwise be the first context to
    depend on `Security` — the price is that this listener imports `Contact`'s quota exception. The
    per-account quotas are **not** there — the translator's (`ROLE_SUPER`) and the career assistant's
    (`ROLE_TRUSTED`), both traced with the account on `ai_usage` (see `.claude/rules/ai.md`). Since these events exist, the matching exceptions are back to `info` in
    `framework.exceptions`. Accepted limits, settled in the review of #356 — don't "fix" them without
    revisiting the trade-off: **`replayed` and `rejected` have legitimate sources** — the set-password page
    calls `validate` on every load, so a person reopening their own link after activation writes
    `replayed`, and a Messenger retry of the invitation regenerates the token, so the first link received
    gives `rejected`; the `path` (`…/validate` vs `…/password-setup`) is what tells a probe from a
    submission, read it before alerting. **Volume**: every anonymous 429 is now a prod line, bounded by the
    nginx zones (10/min/IP contact, 20/min/IP base-access), well below what `csrf-rejected` already allows.
    **Blind spots of the listener**: a 429 answered by nginx's `limit_req` never reaches PHP (the access log
    is its trace), and a quota exception wrapped in another one would go unseen (`instanceof` on the
    top-level throwable only). **The sort is a closed list, so a guard holds it open** (issue #361):
    `ThrottledRequestAuditCoverageTest` walks every `RetryAfterAware` of `src/` (through `DeclaredClasses`),
    hands each one to the real listener, and turns red unless it yields exactly one event of its own — a
    `…Throttled` method, never `loginThrottled` nor an unrelated one such as `rateLimiterUnavailable` — or
    sits in a justified list: `PER_ACCOUNT` (the translator and the assistant, both on `ai_usage`, pinned by
    their own tests) or `TRACED_UPSTREAM` (issue #399: `LoginRateLimitExceededException`, whose
    `login-throttled` — with the tried identifier's fingerprint (#418) — `SecurityEventsSubscriber` writes on `LoginFailureEvent`
    before the exception exists; sorting it again would log the refusal twice). The guard checks that the
    listener ignores every justified entry. A new anonymous quota therefore gets its `…Throttled` interface
    method, its `match` arm and its event in the same change — never a justified entry to make the suite
    pass. The guard calls the listener directly: it proves the sort, not that the
    exception reaches priority 0 unwrapped — `SecurityAuditLogTest` covers the real wiring, extend it too. **Replay and expiry must cost the same**: `findOneByTokenHash` loads the
    account by explicit join, so the `replayed` path, which logs it, pays no extra query behind the
    shared 404 — keep the join. **Consumption is atomic**: `PasswordSetupService::complete()` hashes, then
    `PasswordSetupTokenRepository::claim()` (`UPDATE … WHERE used_at IS NULL AND expires_at > :usedAt`, one
    row or none), and only the winner writes the account; the loser of a concurrent submission is a
    `replayed` (only when the token is `AlreadyUsed`: `claim()` returns a `TokenClaim`, a token gone meanwhile
    is a `rejected`, issue #458). **So is expiry** (issue #447): the claim instant is read **after** the hash, which takes
    time, and the same `UPDATE` refuses a token expired by then (strict bound, like `isUsable()`); that
    loser is not a replay — no event, a merely expired link never is one —, the 404 is unchanged. Never go back to
    deciding on `isUsable()` alone, which reads the loaded state. Functional tests read the records through
    `tests/Support/ReadsSecurityAuditLog.php` (a
    Monolog `test` handler on the channel, `when@test`, found among `monolog.logger.security_audit`'s
    handlers — that logger is public in every env, so phpstan-symfony's dev dump knows it); the kernel
    reboots between requests, so the handler holds the *last* request's records. `Psr\Log\Test\TestLogger`
    no longer ships with psr/log 3, unit tests use Monolog's `TestHandler`. `path` is logged **verbatim**
    (canonical form), with no redaction, and that is only correct because **no route carries a secret in
    its path** any more (audit A7 — the set-password token moved into the body, see `.claude/rules/security-user.md`).
    A route that ever needed one would be the bug, not a reason to add a redaction back here. A use case logs only
    an *effective* action: `CpgUserRoleAdministrator`'s idempotent no-op writes nothing, a refused
    action writes nothing (the unit tests pin `never()` on every error path).
