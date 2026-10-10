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
  - `Infrastructure/Http/CsrfCookieRequestSubscriber.php` — double-submit-cookie CSRF check, a `kernel.request`
    listener at priority 20 (must run *above* the Security firewall's priority 8 — see the class docblock).
  - `Infrastructure/Http/LoginCsrfRequestListener.php` — **login-CSRF guard** (issue #76) on the two anonymous
    routes that *set* a `BEARER` cookie, `POST /api/login_check` and `POST /api/account/base-access`. Both are
    rightly outside the double-submit (an anonymous caller has no `XSRF-TOKEN` to echo), but a cross-site
    HTML form could submit them top-level: the victim's cookie isn't sent (`SameSite=Lax`), yet the
    response's `Set-Cookie` *is* accepted and **replaces** the trusted `BEARER` with the attacker's (or a
    15-minute guest one). The guard requires the `X-Requested-With` header, which a form cannot set and
    which makes a cross-site `fetch()` fail its CORS preflight. **Presence is the protection, not the
    value.** Same priority 20 as the CSRF subscriber: above the firewall (or `json_login` sets the cookie
    first) and above the rate limiters (15), so a forged submission never burns the victim's IP quota.
    `nelmio_cors.yaml` lists the header in `allow_headers`; the frontend sends it via
    `infrastructure/http/loginCsrfHeader.ts` on both calls. Functional tests therefore pass
    `'HTTP_X_REQUESTED_WITH' => 'fetch'` in `server:` on every login/base-access request.
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
    and an **account pending activation** (empty hash, `password_verify($p, '')` returns at once) cost
    nothing — the gap said which identifiers exist and which are pending invitations. On `LoginFailureEvent`,
    `login` firewall only, priority **-100** (after the throttling counter and the audit log, neither of
    which should wait on a hash), it pays a dummy `hash()` of a constant with `CpgUser`'s configured hasher
    in **those two cases only**: never for an active account (the real hash already happened — a second one
    would recreate the gap the other way), never for the throttling (its answer must stay cheap), never for
    a blank password (refused before any DB access, identically for everyone). `hash()` of a constant, not
    `verify()` against a frozen hash, so it follows `password_hashers: auto` if the algorithm or cost
    changes. The submitted password is never read. The response is untouched — the three 401s must stay
    byte-identical, which is the other half of non-enumeration.
    `FailedLoginTimingEqualizerTest` + `tests/Security/Authentication/LoginFailureTimingTest.php` pin it.
    Related fact worth knowing: `login_throttling` answers **401** with `Too many failed login attempts`,
    not 429 — it is Lexik's failure handler that shapes the response (`LoginThrottlingTest`,
    `tools/smoke-login-throttling.sh`).
  - **`Infrastructure/Log/SecurityAuditLogger.php` is the single entry point of the security audit log**
    (3rd audit, A5/D5, Monolog channel `security_audit`, `info`, JSON on stderr in prod — see
    `monolog.yaml`). Implements `Application/SecurityAuditLoggerInterface`, one method per event:
    `login-succeeded`, `login-failed`, `login-throttled`, `logged-out`, `base-access-issued`,
    `csrf-rejected`, `backoffice-access-denied`, `rate-limiter-unavailable`, `user-invited`, `user-reinvited`, `role-changed`
    (`superAdmin` bool), `password-changed`, `user-deleted`, `account-activated`, `password-setup-token-rejected`,
    `password-setup-token-replayed`, `password-setup-throttled`, `contact-throttled`, `base-access-throttled`, `user-purged`
    (`actor: system` — the one event whose actor is not read from the token storage; `record()` takes an
    explicit actor for CLI callers). Every record carries
    `event` (the stable kebab-case key to filter on), `actor` (identifier from the token storage, or
    `anonymous`), `ip`, `path` (canonical), plus `user` and — for an existing account — `userId` (RFC 4122).
    **Never a password, a token (JWT, XSRF, invitation), an e-mail, a request body or a serialized
    exception** in any context: an invited account is named by `username` + `userId`, not by its e-mail.
    The same rule now holds for the **messages** of the exceptions the kernel logs (issue #356):
    `EmailAlreadyUsedException` no longer quotes the address (`alreadyLinkedToAnAccount()`, the backoffice
    reads the 409 status, never the `detail`), and the two delivery exceptions
    (`ContactMessageDeliveryException`, `AccountInvitationDeliveryException`) **never chain the mail
    transport's exception**: its message copies the server's answer (SMTP line, Scaleway API body), which
    can quote the sender or the recipient, and the worker logs the whole `previous` chain.
    `Shared/Infrastructure/Mailer/MailerTransportFailure` keeps its class and a numeric code (HTTP status
    for the API transport, SMTP reply code otherwise), the e-mail counterpart of `ProviderFailure`; each
    context keeps its own delivery exception, whose factory is identical on purpose (`Domain/` never
    imports `Shared/Infrastructure`). The price: the SMTP enhanced status (`5.1.1`) and Scaleway's error
    text are gone from the logs. **An address that `Mime\Address` would refuse never gets past
    validation**: `framework.validation.email_validation_mode: strict` (`validator.yaml`), egulias'
    grammar, the one Mime uses — the default `html5` mode accepted `a..b@example.com`, and the worker then
    failed on a `RfcComplianceException` quoting it, logged on every retry. Don't override `mode:` on an
    `Assert\Email` whose value ends up in an e-mail.
    `SecurityAuditLoggerTest::testNoContextValueEverCarriesAPasswordATokenOrAnEmail` pins that with
    sentinel values run through every method; extend it when adding one. Who calls what:
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
    the account it activated; journal side only, the response keeps the 410 merged with « expired », and
    a merely expired link is no event) right before throwing; `Infrastructure/Log/ThrottledRequestAuditListener`
    (`kernel.exception`, priority 0, above API Platform's -96 and `ApiProblemResponseListener`'s -98, which
    stop propagation) maps the three anonymous per-IP quota exceptions to `password-setup-throttled`,
    `contact-throttled`, `base-access-throttled`, no subject (the limiter's key is the IP). A listener rather
    than a call at the three throw sites on purpose: `Contact` would otherwise be the first context to
    depend on `Security` — the price is that this listener imports `Contact`'s quota exception. The
    translator's quota is **not** there: it is a `ROLE_SUPER` account, traced on `ai_usage` (see
    `.claude/rules/ai.md`). Since these events exist, the matching exceptions are back to `info` in
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
    hands each one to the real listener, and turns red unless it yields exactly one event of its own or
    sits in `PER_ACCOUNT` with a justification (the translator and the assistant, both on `ai_usage`). A new
    anonymous quota therefore gets its interface method, its `match` arm and its event in the same change —
    never a `PER_ACCOUNT` entry to make the suite pass. **Replay and expiry must cost the same**: `findOneByTokenHash` loads the
    account by explicit join, so the `replayed` path, which logs it, pays no extra query behind the
    shared 410 — keep the join. **Consumption is atomic**: `PasswordSetupService::complete()` hashes, then
    `PasswordSetupTokenRepository::claim()` (`UPDATE … WHERE used_at IS NULL`, one row or none), and only
    the winner writes the account; the loser of a concurrent submission is a `replayed`. Never go back to
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
