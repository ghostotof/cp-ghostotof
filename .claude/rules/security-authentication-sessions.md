---
paths:
  - "backend/src/Security/Authentication/Infrastructure/Http/AuthCookieFactory.php"
  - "backend/src/Security/Authentication/Infrastructure/Http/UnknownAuthCookieException.php"
  - "backend/src/Security/Authentication/Infrastructure/Jwt/**"
  - "backend/src/Security/Authentication/Infrastructure/Security/SessionBeforePasswordChangeRefusalListener.php"
  - "backend/src/Security/Authentication/Infrastructure/Security/SessionPredatesPasswordChangeException.php"
  - "backend/src/Shared/Domain/Security/CredentialsVersionClaim.php"
  - "backend/src/Mcp/Connection/Infrastructure/Security/JwtAuthenticationInstant.php"
  - "backend/config/packages/lexik_jwt_authentication.yaml"
  - "backend/tests/Security/Authentication/AuthCookie*Test.php"
  - "backend/tests/Security/Authentication/BearerCookieSecurePerEnvironmentTest.php"
  - "backend/tests/Security/Authentication/SessionBeforePasswordChangeTest.php"
  - "backend/tests/Security/Authentication/Infrastructure/Http/AuthCookieFactoryTest.php"
  - "backend/tests/Security/Authentication/Infrastructure/Jwt/**"
  - "backend/tests/Security/Authentication/Infrastructure/Security/SessionBeforePasswordChange*Test.php"
  - "backend/tests/Support/CookieConstructions.php"
  - "backend/tests/Support/ReadsResponseCookies.php"
---

# Backend — `Security/Authentication`: cookies and sessions

> Split from `.claude/rules/security-authentication.md` (#650, S5; procedure of #605), which keeps the index of the parts. This part: the `BEARER` and `XSRF-TOKEN` cookies, the JWT's claims, and the refusal of a session opened before the last password change.

- `Infrastructure/Jwt/LoginSuccessSubscriber.php` (attaches the `XSRF-TOKEN` cookie, shapes the
  `login_check` response body — includes `roles` alongside `username`, needed by the frontend to gate `/admin`
  without waiting for a full `checkAuth()` round-trip) and `CookieLogoutListener.php` (expires both cookies on
  `/api/logout`).
- **`Infrastructure/Http/AuthCookieFactory.php` is the only place that builds a `BEARER` or `XSRF-TOKEN`
  cookie** (issue #87): names (`AuthCookieFactory::BEARER` / `::XSRF_TOKEN`, also what
  `CsrfCookieRequestSubscriber` reads), `Path=/`, no `Domain`, `SameSite=Lax`, `HttpOnly` on `BEARER`
  only, `Secure` everywhere but `dev` (issue #714 — `test` included, see below; `dev` is served in http,
  where Chrome and Firefox accept a `Secure` cookie on `localhost`, a secure context, but not every browser,
  and none on another address). `bearer()` / `xsrf()` take an optional expiry (session cookie without),
  `expired()` mirrors the issued attributes with a past date — a cleared cookie whose attributes differ
  from the set one is *not* removed by the browser, which is the drift the factory exists to prevent. The
  three sites (`LoginSuccessSubscriber`, `BaseAccessController`, `CookieLogoutListener`) go through it;
  the one exception is the login `BEARER`, still set by Lexik from `lexik_jwt_authentication.yaml`
  (`set_cookies` + `when@dev`), and `tests/Security/Authentication/AuthCookieAttributesTest.php` pins
  that config to the factory **in `test`** by comparing the real `Set-Cookie` of login, base-access
  and logout per name, one cookie per name and response (a second `BEARER` on another `Path`, set first,
  was masked — measured). **`test` carries `Secure` on purpose** (issue #714): that test then reads it on
  the real chain — a redefined `lexik_jwt_authentication.cookie_provider.BEARER` turns it red (measured),
  and so, by construction, do a decorator of the success handler, a `kernel.response` listener, a bundle's
  `prepend()` or a compiler pass dropping it, as long as they act in `test` too; a guard of the declared
  value sees none of them. Functional clients stay in http and lose nothing: BrowserKit's
  `Cookie::fromString()` drops the `secure` flag of a cookie received on a non-`https` URI, so the jar
  sends it back (measured; the issue had assumed every test would need HTTPS) — which also means a test
  reads `Secure` on `getResponse()->headers->getCookies()`, never in the jar. Never make `test` insecure
  again to « match » `dev`.
- **No other cookie, anywhere** (issue #714). Any new auth cookie goes through the factory, never
  `Cookie::create()` or `clearCookie()` inline, and two guards hold it. **By tokens**,
  `AuthCookiesComeFromTheFactoryTest` (census `tests/Support/CookieConstructions`) makes the factory the
  only file of `src/` that builds a cookie: it refuses elsewhere `new`, any static call or any `extends`
  (named or anonymous class) of HttpFoundation's `Cookie` or of Lexik's `JWTCookieProvider`,
  `->clearCookie()`, `->createCookie()`, the security attribute rewrites
  `->with{Secure,HttpOnly,SameSite,Partitioned,Domain}()` — on the factory's own cookie too —,
  `setcookie()` / `setrawcookie()` and a string starting with the `Set-Cookie` header, normalized as
  `HeaderBag` normalizes a name (`set_cookie` counts) and unescaped between double quotes, even to read or
  remove it (`ResponseHeaderBag::getCookies()` / `removeCookie()` do that); the factory's exemption must
  stay needed. Not `withPath()` / `withRaw()`: the first is also a PSR-7 URI method (league), and neither
  drops a protection. A `with…()` homonym of another class is refused by name: rename it, no allow-list.
  Not seen, accepted: a dynamic call, the header composed (`'Set-'.'Cookie'`, `sprintf()`) or written with
  `\u{…}`, a grouped import or `use function` (both refused in `src/` by `ImportsStaySortedTest`), the
  vendor and the configuration. **At run time**, `ApiRouteExposureTest` (invariant 7, trait
  `tests/Support/ReadsResponseCookies`) requires every response it produces — after each of its requests,
  and on each `PUBLIC_PATHS` route called anonymously, `/api/mcp` and its PSR-7 bridge included — to set
  only `BEARER` and `XSRF-TOKEN`, with the factory's exact attributes: it sees the vendor, the
  configuration and dynamic calls (measured with the Lexik provider redefined and a composed header the
  census misses), on those responses only.
- `__Host-` prefixing is a separate, preprod-tested step (it renames what Lexik and the frontend read by
  name). That `secure` is the **only** Lexik key allowed to differ per environment (issue #659,
  `SecurityConfigEnvironmentParityTest`, see `.claude/rules/backoffice-api.md`, « Authorization »), and
  `BearerCookieSecurePerEnvironmentTest` pins its declared value in every environment to the factory's
  (`true` in `prod`, issue #703): the functional tests only see `test`'s cookies, and none loads `prod`.
  What acts in `prod` alone through the container — a redefined or decorated `cookie_provider.BEARER`, a
  `kernel.response` or `lexik_jwt_authentication.on_authentication_success` listener, a `prepend()`, a
  compiler pass, a PHP config file branching on `APP_ENV` — is `SecurityServicesEnvironmentParityTest`'s
  (issue #698): it compiles each environment and requires the provider's compiled `secure` to be the
  factory's in each. Still their blind spot: code that decides on the environment at run time, and code
  conditioned on the request being `https` (`isSecure()`, the scheme), since functional clients stay in http.
- **A JWT's `iat` is the instant the password was typed, and something relies on it** (#647): granting an
  MCP connection requires an authentication under ten minutes old, read from the `iat` of the session's JWT
  (`Mcp/Connection/Infrastructure/Security/JwtAuthenticationInstant::authenticatedAt()`,
  `.claude/rules/mcp-oauth.md`). That holds because the site issues a JWT at login only — no refresh endpoint, no
  sliding renewal. **A JWT refresh added some day must carry the original authentication instant in a claim of its
  own, and the original `cv` claim** (below), and the readers must read them: a stolen cookie renewed in a loop
  would otherwise pass for a fresh login, and outlive a password change. The `cv` of the refreshed token is the one
  **of the token being refreshed**, never a re-read of the account's current version, which would absolve a stolen
  token.
- **A session opened before the last password change is refused by the whole `api` firewall** (#650, R1b, the
  author's decision of 2026-10-28; ADR 0003 amended), **judged on the credentials version the JWT carries, not on a
  date** (S1 of the second review, decision of 2026-10-28). Three pieces, one source of truth each:
  - **`cpg_user.credentials_version`** (`INT NOT NULL DEFAULT 0`, `Version20261028180000`, fingerprint n° 39 of
    `verify.sql`) is advanced by `CpgUser::replacePassword($hash)` under the account lock, never by
    `upgradePassword()` (`.claude/rules/security-user.md`).
  - **The `cv` claim** (`Shared/Domain/Security/CredentialsVersionClaim::NAME`, in `Shared` so the firewall and the
    consent cite one spelling without one context importing the other) is set by
    `Infrastructure/Jwt/CredentialsVersionClaimListener` (Lexik's `JWT_CREATED`) from the `CpgUser` of the event —
    the instance whose hash was just verified, **never re-read**: the bcrypt takes ~270 ms and may precede the COMMIT
    of a password change while the token creation follows it, so a re-read would stick the new version on a token
    proved by the old password. Always set, even at 0. The base access passes through the same event and is
    untouched: no account, no version. No other code issues a JWT.
  - **The predicate** is `CpgUser::claimsAStaleCredentialsVersion(?int)`: **equality** (`($claim ?? 0) !== version`),
    so a claim *newer* than the account (a restored database) is refused too. **An absent claim is version 0**: a
    token issued before the deployment is served while the password never changed (an open session does not drop
    at the deployment) and refused once it has. A present claim that is not an integer is refused (fails closed).
  - **`Infrastructure/Security/SessionBeforePasswordChangeRefusalListener`** applies it: a `CheckPassportEvent`
    listener **registered on `security.event_dispatcher.api` only**, priority **128** (below `UserCheckerListener` at
    256, which loads the account and refuses a disabled one first; above `CheckCredentialsListener` at 0). It throws
    `SessionPredatesPasswordChangeException`, an `AuthenticationException` (Lexik's common 401, `log_level: info` in
    `framework.yaml`). No query, no second signature check (the passport carries the payload and the reloaded
    account). **Absent from `login`** (a password, no session), **`mcp`** (opaque DB-checked token, already revoked by
    the change) and **`oauth`** (no JWT passport): registered globally it would be copied onto each
    (`SessionBeforePasswordChangeRefusalListenerRegistrationTest`). **Rejected** (reasons in the docblock):
    `JWTAuthenticatedEvent`, a `UserChecker` (never sees the payload), a decorated `JWTAuthenticator`, a `jti` list
    (a read per request, ADR 0003), the comparison of `iat` with a date. The base tier is out of it: its
    `BaseAccessUser` is rebuilt from the payload (the test is on the user's class, never on a claim). **A second
    authenticator** added to this firewall must put the version in the passport's `payload` attribute, or not serve
    an account whose password changed.
  - **The `iat` no longer enters this verdict.** The previous form compared it with `credentials_changed_at`: a
    login that read the old hash before the change's COMMIT but signed a second later passed (F-A), two pods' clocks
    refused a legitimate sign-in (F-B), and « the same second » was refused in both directions (`<=`). All gone: a
    person signing in right after a change is served, and tests no longer move a date back in SQL. A future `iat`
    (a pod's clock ahead) is refused by Lexik itself before this rule.
  - **`StaleSessionCookiesClearingListener`** (`Infrastructure/Jwt`, Lexik's `JWT_INVALID`) makes that refusal — that
    one only — **expire `BEARER` and `XSRF-TOKEN`** through `AuthCookieFactory::expired()`. The firewall authenticates
    as soon as a cookie is present, **public routes included**: without it a refused cookie, alive up to an hour more,
    would answer 401 to every `/api` request, the About page and the contact form too. The next request is
    anonymous; the frontend reads that 401 as a finished session (a banner mid-page in the backoffice, the sign-in
    at the next navigation — `.claude/rules/frontend-backoffice.md`, « Route protection »).
  - **Every refusal is audited** (`session-refused-after-credentials-change`,
    `.claude/rules/security-authentication-audit.md`), before the exception is thrown.
  - **The consent keeps its own check** as defence in depth, unreachable by HTTP since R1b, the author's decision:
    it compares the same version through `AuthenticationInstantInterface::credentialsVersion()` (the `cv` of the
    session's token, re-read and re-verified by Lexik) and reads no token at all for an account at version 0
    (`.claude/rules/mcp-oauth.md`, « Consent »).
  `SessionBeforePasswordChangeTest` (HTTP: the real `PUT` then the cookie replayed, a version older or newer, the
  `iat` playing no part, a token without claim before and after a change, **the race** — an instance read before
  the change, a token created after it: refused, red under the old rule —, the base tier, cookies cleared then a
  public route served), `…RefusalListenerTest`, `…RegistrationTest`, `CredentialsVersionClaimListenerTest`.
