---
paths:
  - "backend/src/Security/Authentication/Infrastructure/Http/AuthCookieFactory.php"
  - "backend/src/Security/Authentication/Infrastructure/Http/UnknownAuthCookieException.php"
  - "backend/src/Security/Authentication/Infrastructure/Jwt/**"
  - "backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php"
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
  - "backend/tests/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSession*Test.php"
  - "backend/tests/Security/Authentication/Infrastructure/Security/SessionBeforePasswordChange*Test.php"
  - "backend/tests/Support/CookieConstructions.php"
  - "backend/tests/Support/ReadsResponseCookies.php"
  - "backend/tests/Support/PutsTheBearerCookie.php"
  - "backend/tests/Support/RefusedBearerCookie.php"
  - "tools/smoke-dead-session-cookie.sh"
  - "tools/tests/smoke-dead-session-cookie.test.sh"
  - "tools/lib/smoke-http.sh"
  - "tools/tests/smoke-http.test.sh"
---

# Backend — `Security/Authentication`: cookies and sessions

> Split from `.claude/rules/security-authentication.md` (#650, S5; procedure of #605), which keeps the index of the parts. This part: the `BEARER` and `XSRF-TOKEN` cookies, the JWT's claims, and the refusal of a session opened before the last password change.

- `Infrastructure/Jwt/LoginSuccessSubscriber.php` (attaches the `XSRF-TOKEN` cookie, shapes the
  `login_check` response body — includes `roles` alongside `username`, needed by the frontend to gate `/admin`
  without waiting for a full `checkAuth()` round-trip) and `CookieLogoutListener.php` (expires both cookies on
  `/api/logout`). **`BEARER` and `XSRF-TOKEN` are session cookies, at login as at the base access** (#748, F1, the
  author's decision of 2026-10-29, replacing R1's persistent `XSRF-TOKEN`): `set_cookies.BEARER.lifetime: 0` (Lexik
  gives no `Expires`: `JWTCookieProvider` answers `0` for a zero lifetime) and `xsrf($token)` without expiry, in
  `LoginSuccessSubscriber` (which no longer reads `token_ttl`) and `BaseAccessController`. Why: closing the browser
  without session restore then ends the session, read and write, and the cookies always die together (a session
  `XSRF-TOKEN` thrown away while a persistent `BEARER` survived made every write a 403 read « session ended », in a
  loop — #748 CR3). The JWT keeps `exp` = `token_ttl` (3600) or 900 s at the base access: **the token, not the cookie,
  bounds the session**, so a copied JWT stays valid until its `exp` (ADR 0003, unchanged). A browser that restores its
  session restores the cookies too, accepted. A session opened before the deployment keeps its persistent cookies, one
  hour at most. `AuthCookieAttributesTest` (`testTheLoginCookiesAreSessionCookies`,
  `testTheBaseAccessCookiesAreSessionCookies`: `getExpiresTime()` is `0` on the response) and
  `BaseAccessControllerTest` (the body's `expiresAt` equals the JWT's `exp`) pin it.
- **`Infrastructure/Http/AuthCookieFactory.php` is the only place that builds a `BEARER` or `XSRF-TOKEN`
  cookie** (issue #87): names (`AuthCookieFactory::BEARER` / `::XSRF_TOKEN`, also what
  `CsrfCookieRequestSubscriber` reads), `Path=/`, no `Domain`, `SameSite=Lax`, `HttpOnly` on `BEARER`
  only, `Secure` everywhere but `dev` (issue #714 — `test` included, see below; `dev` is served in http,
  where Chrome and Firefox accept a `Secure` cookie on `localhost`, a secure context, but not every browser,
  and none on another address). `bearer()` / `xsrf()` take **one parameter, no expiry** (#748, St3-3: a session cookie only, so
  none persistent can be asked for; `AuthCookieFactoryTest::testNeitherCookieTakesAnExpiry`),
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
- **A session opened before the last password change is refused by every route of the `api` firewall that requires a role; a public route serves the request as anonymous, the refusal still audited** (#650, R1b, the
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
    (a pod's clock ahead by more than `clock_skew`, below) is refused by Lexik itself before this rule.
  - **`StaleSessionCookiesClearingListener`** (`Infrastructure/Jwt`, Lexik's `JWT_INVALID` **and** `JWT_EXPIRED`, #748
    F1) makes **every** refusal of the `BEARER` cookie — expired, invalid (signature, payload, account gone), opened
    before a password change (#650) — **expire `BEARER` and `XSRF-TOKEN`** through `AuthCookieFactory::expired()`: with
    session cookies a refused one would stay until the browser closes. The next request is anonymous.
    `JWT_NOT_FOUND` (no cookie) clears nothing. **Accepted limit**: a login's expiry is not exposed to the
    frontend, so the first authenticated request after its `exp` takes the 401 (a read: 401; a write: 401, then the CSRF's
    403 without token), read as an ended session (`.claude/rules/frontend-session.md`; the backoffice banner, the sign-in
    at the next navigation — `.claude/rules/frontend-backoffice.md`, « Route protection »). Pinned by
    `RefusedSessionCookiesAreClearedTest` (cleared on `/api/me` with the factory's attributes, the public route served
    afterwards, a served session clearing nothing) and, for the password change, `SessionBeforePasswordChangeTest`.
  - **`Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener`** (#748 R35, the author's decision of
    2026-10-29, replacing R23's `credentials: 'omit'` and R24's replay): **a route with no `access_control` rule serves
    the request as anonymous and touches nothing**. A `LoginFailureEvent`
    listener on `security.event_dispatcher.api` only, last priority (-1024), that sets the response to `null` when the
    `AccessMap` asks for nothing — `requiresAccess()` is `AccessListener::supports()`'s predicate, compared with it on
    every route by a test, no second list of public routes (Lexik still builds and discards its 401). A protected
    route keeps its 401 and the clearing; the first one reached clears the dead cookie, and the SPA reads one at each
    load (`GET /api/me`). **Invariant n° 8 of `ApiRouteExposureTest`**: on every route and method — writes carrying a valid CSRF pair,
    or the double-submit's 403 would answer first —, a refused `BEARER` (invalid, expired) gets the anonymous status, a 401
    of a route that requires a role (`requiresAccess()`) expires both cookies with the factory's exact attributes, and a
    `PUBLIC_PATHS` route sets no cookie the anonymous call does not; the premise (every authorization of `/api` is an
    `access_control` rule) is held by `AuthorizationLivesInAccessControlTest` and `ApiPlatformOperationsCarryNoSecurityTest`
    (C5-12, R60, R61), the only witnesses of a `#[IsGranted]` or a `security:` put on a route without a rule, which the
    invariant no longer judges. The first one's detector (`tests/Support/AuthorizationSites`, by tokens, proven on code
    extracts) also refuses a hand-thrown `AccessDenied(Http)Exception` / `UnauthorizedHttpException`, a static
    `::isGranted(`, `AccessDecisionManagerInterface` and an `is_granted(` string; each exemption (the MCP reader, the two
    CSRF guards) carries its reason and the exact number of sites it covers. The second reads the **compiled** API
    Platform metadata — `security`, `securityPostDenormalize`, `securityPostValidation` of every resource and operation
    (39 classes, 112 operations on 2026-10-30) — so it also sees what the tokens cannot: a `security:` declared in YAML or
    XML, or merged by `api_platform.defaults` (measured: a `defaults.security` in a temporary config file turns it red
    and leaves the first green).
    Rejected: `lazy: true` (the JWT authenticator's `supports()` stays `true`), a `JWTAuthenticator` subclass, a
    firewall without authenticator for public routes. Pinned by `PublicRouteIgnoresRefusedSession{,Registration}Test`,
    `AuthorizationLivesInAccessControlTest`, `ApiPlatformOperationsCarryNoSecurityTest` and, on the real stack, `tools/smoke-dead-session-cookie.sh`
    (`smoke-test-preprod`, behind the Basic Auth the test container lacks): a random `BEARER` on a public read gets 200
    and no `Set-Cookie` for `BEARER` nor `XSRF-TOKEN`; on `/api/me` a 401 that expires both. A `curl` that gets no
    answer exits 1 with a message, a Basic Auth holding a line break is refused (exit 2), and that plumbing is shared
    in `tools/lib/smoke-http.sh`; offline tests `tools/tests/smoke-dead-session-cookie.test.sh` and
    `tools/tests/smoke-http.test.sh`.
    **Accepted limit (C5-1, the author's decision of 2026-10-30)**: a closed session (the password changed since) kept by a
    legitimate tab writes one `session-refused-after-credentials-change` line per public request until that tab's next
    `GET /api/me` — every load — clears the cookie; bounded by the nginx `publicapi` zone and the page's life, and an
    attacker gains nothing (served as anonymous). Not de-duplicated, for the reason in `security-authentication-audit.md`.
    **Accepted (C5-2, the author's decision of 2026-10-30)**: since the SPA sends its cookies to public reads (R37), a public
    read by a signed-in person is authenticated — the JWT verified and one `SELECT` of the account (the `cpg_users` provider
    reloads it) —, the price of serving a public route whatever the cookie (decision 1 of the fourth review); the response
    stays the anonymous one.
  - **`lexik_jwt_authentication.clock_skew: 5`** (#748, A3-9, the author's decision of 2026-10-29): a pod's clock a
    second ahead made an `iat` in the future a 401 which, since F1, clears the session. Read in Lexik 3.2
    (`LoadedJWS`, `LcobucciJWSProvider::verify()`): it loosens `iat` **and `exp`** (an expired token is served up to
    5 s more — hence no more than 5), and `nbf` (not set here); `cv` reads no date; the MCP consent reads `iat`, a
    future one up to 5 s giving a negative age, « recent », harmless. `JwtClockSkewTest` pins the value and both bounds.
  - **Accepted limit (CR3-2)**: the clearing targets a cookie *name*, not the value sent. A late 401 of an
    authenticated request still in flight with a dead cookie clears the fresh cookies another tab just received (a
    `Set-Cookie` is never conditional); window of one round trip, repaired by the sign-in that follows (read: 401,
    write: 403 without CSRF token, both « session ended »). Conditioning on the JWT's age was rejected: the race is on
    the order of arrival. ADR 0003, 2026-10-29 amendment.
  - **Every refusal is audited** (`session-refused-after-credentials-change`,
    `.claude/rules/security-authentication-audit.md`), before the exception is thrown.
  - **The consent keeps its own check** as defence in depth, unreachable by HTTP since R1b, the author's decision:
    it compares the same version through `AuthenticationInstantInterface::credentialsVersion()` (the `cv` of the
    session's token, re-read and re-verified by Lexik) and reads no token at all for an account at version 0
    (`.claude/rules/mcp-oauth.md`, « Consent »).
  `SessionBeforePasswordChangeTest` (HTTP: the real `PUT` then the cookie replayed, a version older or newer, the
  `iat` playing no part, a token without claim before and after a change, **the race** — an instance read before
  the change, a token created after it: refused, red under the old rule —, the base tier, a closed session served as anonymous on a public route and cleared by
  the first protected one), `…RefusalListenerTest`, `…RegistrationTest`, `CredentialsVersionClaimListenerTest`.
