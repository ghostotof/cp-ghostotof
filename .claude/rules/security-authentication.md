---
paths:
  - "backend/src/Security/**"
  - "backend/src/Shared/Infrastructure/Http/**"
  - "backend/config/packages/security.yaml"
  - "backend/config/packages/lexik_jwt_authentication.yaml"
  - "backend/config/packages/nelmio_cors.yaml"
  - "backend/tests/Security/**"
  - "frontend/src/infrastructure/http/**"
---

# Backend — `Security/Authentication`

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture › `Security/Authentication/` ». The index of every rule is at the top of `.claude/CLAUDE.md`. Split by sub-domain in #650 (S5, procedure of #605): the original kept the guards below and the index of where each part went.

## Where the rest of this area went

| Part | Holds | Read it before… |
|---|---|---|
| `security-authentication-sessions.md` | the login success and logout wiring, `AuthCookieFactory` and the « no other cookie » guards, `__Host-`, the JWT `iat` and `cv` claims, the refusal of a session opened before the last password change, the cookies cleared on that refusal | touching a `BEARER` / `XSRF-TOKEN` cookie, a JWT claim or its creation, `lexik_jwt_authentication.yaml`, `SessionBeforePasswordChangeRefusalListener` |
| `security-authentication-connexion.md` | `FailedLoginTimingEqualizer`, the overlong password refused before any account is loaded, `password_hashers`, `LoginFailureCause` | touching the `login` firewall's failures, `security.yaml`'s `password_hashers`, a new early refusal at login |
| `security-authentication-audit.md` | `SecurityAuditLogger`: every event and its context, what a record never carries, the guards on every log channel, the redactions (identifier, exception messages, driver errors) | adding or changing an audit event, a log context, a Monolog handler, an exception message |

What stays here: the CSRF guards (double-submit, login-CSRF), the base access, `CanonicalPath`, the firewall anchoring, « no session ».

- **`Security/Authentication/`** — login/logout/JWT/CSRF mechanics, deliberately kept out of `User/`: this is
  infrastructure wiring around Symfony Security + LexikJWTAuthenticationBundle, not a domain concept of its own.
  - **Tokens, JWTs and CSRF values are hidden from stack traces** (`#[SensitiveParameter]`, issue #414): the
    guard covers all of `src/`, so its rule lives in `.claude/rules/backend-conventions.md`.
  - `Infrastructure/Http/CsrfCookieRequestSubscriber.php` — double-submit-cookie CSRF check, a `kernel.request`
    listener at priority 20 (must run *above* the Security firewall's priority 8 — see the class docblock).
    Its `EXCLUDED_PATHS` are **exact** paths, each justified in the docblock. Two since #647,
    **`/api/oauth/token` and `/api/oauth/revoke`**: called by the MCP client, never a browser, so no
    `XSRF-TOKEN` to echo. Safe for the reason `/api/mcp`'s exclusion is (ADR 0006 D4): their `oauth`
    firewall has no authenticator and reads no cookie, so a cross-site request carrying a person's
    `BEARER` borrows no identity, and has nothing to make act — exchanging a code needs its PKCE verifier,
    revoking a token needs the token. `/api/mcp`'s `mcp` firewall **has** an authenticator since #648, the MCP
    connection's, and it reads the `Authorization: Bearer` header only, never a cookie: its exclusion stays safe
    for the same reason (`.claude/rules/mcp-oauth.md`, « The authenticator of `/api/mcp` »). `TokenControllerTest` and `RevocationControllerTest`
    (`testABearerCookieWithoutTheXsrfHeaderIsNeitherRefusedNorRead`) pin both halves. **The consent,
    `/api/oauth/consent`, stays under the guard**: it reads the person (`ROLE_TRUSTED`) and grants in their
    name (`ConsentControllerTest::testAnApprovalWithoutTheCsrfHeaderIsForbidden`). Never exclude a prefix
    such as `/api/oauth/`. See `.claude/rules/mcp-oauth.md`.
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
    `tests/Security/Authentication/LoginFirewallScopeTest.php` pins the behaviour. Both read `test`'s container;
    that every environment declares the same firewalls and rules is `SecurityConfigEnvironmentParityTest`'s job
    (issue #659, `.claude/rules/backoffice-api.md`, « Authorization »).
  - **No session, in any environment** (`framework.session: false`, audit A23). Nothing needs one — both
    firewalls are `stateless`, auth is a JWT in a cookie, the CSRF is a double-submit cookie, there is no
    Symfony form. The point is not tidiness: the default handler writes under `var/`, and on a
    `readOnlyRootFilesystem` pod that is exactly the silent failure ADR 0005 exists to forbid. Without a
    session the error is loud (`SessionNotFoundException`) and surfaces in test or dev instead. Re-enabling
    `framework.form` or a session-token CSRF would need one — `tests/Security/StatelessSessionTest.php`
    pins the invariant, including in `test`.
