---
paths:
  - "backend/src/Security/Authentication/Application/SecurityAuditLoggerInterface.php"
  - "backend/src/Security/Authentication/Infrastructure/Log/**"
  - "backend/src/Security/Authentication/Infrastructure/Http/RateLimiterLockFailureListener.php"
  - "backend/src/Shared/Infrastructure/Doctrine/DriverErrorRedactionMiddleware.php"
  - "backend/src/Shared/Infrastructure/Doctrine/RedactedDriverException.php"
  - "backend/src/Shared/Infrastructure/Mailer/MailerSendingFailure.php"
  - "backend/config/packages/monolog.yaml"
  - "backend/tests/Security/AccountIdentifierReachesNoLogTest.php"
  - "backend/tests/Security/Authentication/SecurityAuditLog*Test.php"
  - "backend/tests/Security/Authentication/RateLimiterLockFailureTest.php"
  - "backend/tests/Security/Authentication/Infrastructure/Log/**"
  - "backend/tests/Security/Authentication/Infrastructure/Http/RateLimiterLockFailureListenerTest.php"
  - "backend/tests/Shared/Infrastructure/Doctrine/DriverErrorRedactionMiddlewareTest.php"
  - "backend/tests/Shared/Infrastructure/Doctrine/RedactedDriverExceptionTest.php"
  - "backend/tests/Shared/Infrastructure/Mailer/MailerSendingFailureTest.php"
  - "backend/tests/Support/ReadsSecurityAuditLog.php"
---

# Backend — `Security/Authentication`: the security audit log

> Split from `.claude/rules/security-authentication.md` (#650, S5; procedure of #605), which keeps the index of the parts. This part: `SecurityAuditLogger` (every event, its context, what a record never carries) and the guards that hold it.

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
  explicit actor for CLI callers), `mcp-connection-granted` (#647, `ConsentService` after the COMMIT of the
  connection and its code: `userId`, `connectionId`, the client's public `clientId`, never the code),
  `nominative-corpus-read` (#648, `NominativeCorpusReader::recordServed()`, called by `GetNominativeCorpusTool` once the
  answer fits its bound, never on a refusal: `userId` — also the
  `actor`, the `mcp` firewall's `McpConnectionPrincipal` being an `AccountReference` —, `connectionId`, `clientId`,
  `tool`; never the token, its hash nor anything of the corpus), `mcp-connection-revoked` (#649,
  `McpConnectionRevoker` once the repository's revocation took effect — or, inside a transaction, its caller after
  the COMMIT: the revocation endpoint, `TokenEndpoint` for `superseded` (at the new code's first exchange, #726), and
  `McpConnectionPurger` for `expired` —, never for a connection already revoked:
  `userId`, `connectionId`, `clientId`, `cause` — `client`, `refresh-token-reuse`, `code-replay` through
  `mcpConnectionRevoked()`, actor read as usual — as are #650's three causes, `person` (the person revoking one of
  hers from « Mes connexions MCP », `OwnMcpConnections`), `password-changed` and `trusted-tier-withdrawn` (every
  connection of an account, through the `Shared` port, audited by its caller **after the COMMIT** that holds the
  password or the role; the actor of `password-changed` is the administrator on the backoffice `PUT` and `anonymous`
  when an invitation link *replaces* a password, `PasswordSetupService`; `trusted-tier-withdrawn`'s is the
  administrator demoting — `.claude/rules/mcp-oauth-revocation-personne.md`); `superseded` through `mcpConnectionSuperseded()`, actor read as usual,
  plus `supersededBy`, the RFC 4122 UUID of the connection that replaces it (#726, audit B3: nothing else ties the
  line, written at the exchange, to the grant that caused it); `expired` through `mcpConnectionExpired()`, actor `system`
  like `user-purged`, the purge being a scheduled command; never the token or code presented),
  `mcp-access-token-refused` (#649, `McpBearerAuthenticator` right before refusing a **known** access token on
  `/api/mcp` — a cause of `McpAccessToken::refusalAt()` or the trusted tier, never `malformed` nor `unknown`, which
  designate no connection: `userId`, `connectionId`, `clientId`, `cause` = the `BearerRefusal` value, actor
  `anonymous`; at most one line per connection and per hour in normal use, `.claude/rules/mcp-oauth-audits.md`;
  never the token nor its hash), `mcp-grant-refused` (#725, `TokenEndpoint` after the ROLLBACK, through
  `GrantRefusalAuditInterface`, for every refusal of a code or refresh token the server **decrypts** — never an
  unreadable one, an unknown client, a vanished connection, nor a compromise, which writes `mcp-connection-revoked`:
  `userId`, `connectionId`, `clientId`, `cause` = the `GrantRefusal` value, `grantType`, `presentingClientId` (the
  registered client that presented it, named in the body — the token endpoint refuses any other), actor `anonymous`;
  never for a 5xx, and an audit failure leaves league's refusal unchanged (`critical` on the main channel); bounded by
  the nginx `oauth` zone, `.claude/rules/mcp-oauth-audits.md`; never the grant, the PKCE verifier nor a hash).
  Every record carries `event` (the stable kebab-case key to filter on), `actor`, `ip`, `path` (canonical), plus the subject.
  **An account is designated by its `userId` (RFC 4122) only, as subject and as `actor`, never by its
  login identifier** (issue #363): an invited account's username is derived from the local part of its
  address (`UsernameGenerator`, ADR 0001 D2), usually a first and last name. `actor` is the UUID of the
  authenticated account, the base tier's random `guest-…` marker, `anonymous`, `system`, `console`, or
  `unidentified` for any other `UserInterface` (none exists). `user` now only ever carries that
  `guest-…` marker (`base-access-issued`, a base access's `logged-out`). Both go through
  `App\Shared\Domain\Security\AccountReference` (`CpgUser::accountId()`), the interface `Ai` reads too,
  so no context depends on `Security`. The seven MCP methods take one `Shared/Domain/Security/McpConnectionReference`
  (an `AccountReference` with `connectionId()` and the public `clientId()`, lot C of #649's reviews), which the
  connection, a `RevokedConnection` and the `mcp` firewall's `McpConnectionPrincipal` implement — never the three
  fields unpacked by the caller; scalars follow it (cause, tool, grant type, presenting client), or a `Uuid` for
  `supersededBy` — `Security` still depends on no class of `Mcp`: what `Security/User` needs from `Mcp/Connection` (revoking an
  account's connections, #650) goes through `Shared/Application/Security/AccountMcpConnectionsRevokerInterface`. To name an account during an incident:
  `SELECT username, email FROM cpg_user WHERE id = '<userId>'` — once deleted, its lines designate no one,
  which is the point (minimisation, RGPD register §6; not Goal #9, which is about what the API exposes).
  It runs through a human's read-only `kubectl exec` on the `postgres` pod (`k8s/README.md`, « Journal de
  sécurité »), **never a Job**: a Job would print the name and address into its pod's logs, readable by
  the CI token's `pods/log` — the exception and its bounds are in `.claude/rules/deploiement-pipeline.md` (issue #520).
  **Never a password, a token (JWT, XSRF, invitation), an e-mail, a login identifier, a request body or a
  serialized exception** in any context.
  **The identifier tried at login is never written as typed** (issue #418): a password typed in the
  username field would otherwise reach the production log. `login-failed` and `login-throttled` carry
  `userFingerprint` instead — `Infrastructure/Log/LoginIdentifierFingerprint`, an HMAC-SHA256 keyed by a
  key derived from `kernel.secret` for this use alone (issue #455, see `.claude/rules/backend-integrite.md`), case-folded like `login_throttling`'s counter (`mb_strtolower`), **6 hex chars
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
  now `Class(userId="…", roles="…")` (`user="guest-…"` for the base tier, roles only otherwise). The `mcp`
  firewall writes it on every authenticated request too (#648): its `McpConnectionUser` is an
  `AccountReference`, so the line names the connection's account by `userId`, never the connection's UUID
  alone nor a login identifier (`AccountIdentifierReachesNoLogTest`). Any
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
