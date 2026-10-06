---
paths:
  - "backend/src/**/Presentation/**"
  - "backend/src/**/Infrastructure/ApiPlatform/**"
  - "backend/src/Shared/**"
  - "backend/config/packages/api_platform.yaml"
  - "backend/config/packages/framework.yaml"
  - "backend/config/packages/security.yaml"
  - "backend/tests/**"
---

# Backend — backoffice API (`ROLE_SUPER`) and API errors

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backoffice (`ROLE_SUPER`) ». The index of every rule is at the top of `.claude/CLAUDE.md`.

- **Authorization**: a single `access_control` entry in `config/packages/security.yaml`,
  `{ path: ^/api/backoffice(/|$), roles: ROLE_SUPER }`, which **must stay the first entry in the list** — Symfony
  applies only the first matching rule, so a later/looser rule (e.g. `^/api/me`) would never get a chance to
  override it, but a rule placed *before* it could accidentally widen backoffice access. **Every `path` is
  anchored with `(/|$)`** (issue #78, pt 2): a rule covers its route and its subtree, nothing else, so
  `/api/cv-export` is *not* `ROLE_TRUSTED` by accident and a future `/api/case-studies-drafts` is *not*
  `ROLE_USER` by accident. A sibling path therefore inherits no implicit protection — write its rule, or
  `ApiRouteExposureTest` flags it. `tests/Security/AccessControlAnchoringTest.php` pins the anchors against
  the compiled `AccessMap`; keep the pattern when adding a rule. The same test also pins the **firewall**
  patterns, which follow a different rule and for a stated reason — see `.claude/rules/security-authentication.md`.
- **API Platform pattern**, repeated identically across every backoffice resource
  (`BackofficeExperienceTechnologyResource`, `BackofficeQuality{Principle,Trait}Resource`,
  `BackofficeContributionResource`, `BackofficeIncidentResource`, `BackofficeWatchedProductResource`,
  `Backoffice{About}{Settings,SiteCard,MeCard}Resource`, `BackofficeUserResource`,
  `BackofficeUserPasswordResource`): a flat DTO (never the Doctrine entity itself) under
  `Presentation/ApiResource/`, backed by a `Provider` (`GetCollection`/`Get`) and a `Processor`
  (`Post`/`Put`/`Delete`) under `Infrastructure/ApiPlatform/`. Collection reads use a `?locale=` query filter
  (unlike the public `{locale}` path param — collections aren't per-locale routes). **`Put`/`Delete` operations
  need an explicit `provider:` set, not just `processor:`** — otherwise API Platform's default provider tries to
  resolve the DTO via Doctrine directly and 404s before ever reaching the processor. Since `v0.12.0` the
  localized collections (Quality, About cards) are read **without** the `?locale=` filter by the admin
  pages, which display every language in one grouped table (spec 0004 D8); the filter still exists.
  Each ordered context adds a one-operation `Backoffice<X>OrderResource` (`Put …/order`, `read: false`,
  `output: false`, 204) whose input uses the `CarriesOrderedKeys` trait — `keys()` returns the
  validated `list<string>` and is the only thing the Processor reads; mirror that rather than
  re-validating the array by hand (`debug:router | grep /order` must list exactly one route per context,
  none synthesised).
- **A read-only resource with no Doctrine identifier needs `#[ApiProperty(identifier: false)]`** on its
  `id` field — `BackofficeVulnerabilityResource` (`GetCollection /backoffice/watch/vulnerabilities`,
  read straight from the snapshot) is the case in point. Without it API Platform infers `id` as the
  identifier, synthesises an item operation to build IRIs, and publishes
  `/api/backoffice_vulnerabilities/{id}`: a second, undocumented route to the same data. Same trap as
  audit C6 on `BackofficeUserResource`, arrived at from the other end. Check `debug:router` after
  adding any resource.
- **`Security/User` backoffice resources** (`ROLE_SUPER`): `BackofficeUserResource` —
  `GetCollection` (list, `normalizationContext: skip_null_values=false` so `email` is always present),
  `Post /backoffice/users` (**invite** by `{email, locale}`, input DTO `BackofficeUserInviteInput`, → 201; direct
  username+password creation stays CLI-only), an explicit `Get /backoffice/users/{id}` and
  `Delete /backoffice/users/{id}`. The `Get` is declared **on purpose** (audit C6): without an item operation,
  API Platform silently synthesises one to build IRIs, published on its default template
  `/api/backoffice_users/{id}` — a second, undocumented path to the same data, which at the time only stayed
  protected by the accident that the then-unanchored `^/api/backoffice` matched `backoffice_users` by prefix.
  That accident is gone (the rule is now `^/api/backoffice(/|$)`, see "Authorization" above), so such a route
  would be served to anyone — `ApiRouteExposureTest` would turn red, but check `debug:router` first. Declaring
  the `Get` removes that route. Plus dedicated one-operation
  resources: `BackofficeUserPasswordResource` (`Put …/{id}/password`, `output: false`),
  `BackofficeUserRoleResource` (`Put …/{id}/roles` `{superAdmin}`, `output: false`),
  `BackofficeUserInvitationResource` (`Post …/{id}/invitation` `{locale}`, resend, `read: false`, → 202).
  The `Put`/`Post`-with-id ones each set an explicit `provider` (or `read: false` for the id-in-path `Post`) so
  API Platform doesn't 404 at the read stage on the non-Doctrine DTO.
- **Exceptions**: every new Domain `NotFoundException`/`AlreadyExistsException` needs an entry in
  `config/packages/api_platform.yaml`'s `exception_to_status` map (e.g. `ExperienceTechnologyNotFoundException`,
  `CpgUserNotFoundException`, `CannotDeleteOwnAccountException`) — otherwise API Platform surfaces an unmapped
  exception as a generic 500 instead of a meaningful 4xx. **And a `log_level` in `framework.exceptions`**
  (issue #348): `ErrorListener::logKernelException` (priority 0) logs every exception *before* API Platform
  (-96) renders it — API Platform does not log it "itself" — and without an entry a non-`HttpException`
  goes out `critical`: until #348 every backoffice 404/409 and every public password-setup 404/429 was a
  production alert an anonymous caller could produce at will. `info` for a client error, `warning` for a
  third-party outage (503) or for a signal nothing else traces (missing About settings for a valid
  locale). The password-setup unknown token and quota and the translation quota were in that last group
  until issue #356 gave them their own trace (`security_audit`, `ai_usage`) and moved them back to `info`:
  a client-triggerable 4xx stays `info` once something attributable traces it. That rule covers our domain exceptions
  only: the framework's own HTTP 4xx (router 404/405, validation 422, 403) stay at `error`.
  `tests/Shared/Infrastructure/Http/ExceptionLogLevelCoverageTest.php` pins four things: an entry for
  every exception rendered with a declared status — every `exception_to_status` key, every
  `exceptionToStatus` carried by an `#[ApiResource]` or one of its operations (merged by the vendor at
  render time, `ErrorListener::getOperationExceptionToStatus`), every `#[WithHttpStatus]` exception of
  `src/` (the kernel converts it but resolves the level on the original exception) and every
  `ProblemExceptionInterface` of `src/` (found by token parsing
  through `tests/Support/DeclaredClasses`, shared with `ProblemDetailStaysStaticTest`, never inferred from
  file paths); a level that matches the rendered status (4xx: info/notice/warning, 5xx:
  warning/error/critical); no entry that targets anything else — a broad `\DomainException` or an
  interface would hide real server faults and shadow the precise entries after it, since the kernel takes
  the first match —; and justified ways out only (`EXEMPT`: the three broad API Platform defaults below,
  which stay `critical`; `JUSTIFIED_ENTRIES`: the 415 of #320).
  **Both maps are read compiled, never parsed from YAML** (issue #357): `tests/Support/CompiledExceptionConfig`
  reads the `exception_listener` mapping, the `api_platform.exception_to_status` parameter and the
  resource metadata from the test container, so an entry from another config file or a `when@test`
  block counts as the kernel counts it; `ApiExceptionLogLevelTest` builds its listener from the same
  mapping. The flip side: the test container only sees `test`. **Never declare either map for a single
  environment** (`when@prod`, `config/packages/prod/`, `services_prod.yaml` — preprod and prod both run
  `APP_ENV=prod`): keep them common, `ExceptionLogLevelCoverageTest` turns red otherwise.
  **A request body the Serializer refuses never reaches the broad `Serializer\ExceptionInterface` entry**
  (issue #355): `Shared/Infrastructure/ApiPlatform/MalformedRequestBodyProvider` decorates
  `api_platform.state_provider.deserialize` and, **on an operation that deserializes only**, turns the
  Serializer's `UnexpectedValueException` family — the only one a client can trigger — into
  `MalformedRequestBodyException` (400, `info`): unreadable JSON, and valid JSON whose root is not an
  object (`123`, `null`, `"x"`), which is *not* collected as a 422. A server fault raised at the same step
  (`LogicException`, `MappingException`, an `UnsupportedFormatException` for a negotiated format with no
  encoder) passes through untouched and stays a `critical` 500 — never widen the `catch` to
  `Serializer\ExceptionInterface`. The broad entry keeps covering the output side (a non-encodable
  response, invalid UTF-8 in the database), a server fault that must stay `critical`: never give it a
  `log_level`, and never move the conversion to the JSON decoder, which also decodes internal data. The
  `UnsupportedFormatException`s of `var/log/test.log` are output-side too: `GET /api`, the Hydra
  entrypoint serialized as `jsonld` (not a declared format), disabled in prod (`enable_entrypoint: false`).
  One caveat: on a write, the decorator also sees the providers `DeserializeProvider` wraps (read of the
  existing item, our own Providers) — a Provider of `src/` that calls the Serializer must catch its own
  failures.
  When two exceptions share a status code but the frontend must tell them apart (e.g. the two `PUT …/roles` 409s: self-modification vs last-super-admin), make
  the exception `implements ApiPlatform\Metadata\Exception\ProblemExceptionInterface` and
  `use App\Shared\Domain\Exception\HasProblemType` (declare `problemType()` → a stable kebab slug +
  `problemStatus()`): API Platform then emits `type: /errors/<slug>` in the problem+json, which the client keys
  on instead of substring-matching the localized `detail`.
  **Two traps of that map, both paid for** (audit A15): declaring `exception_to_status` **replaces** API
  Platform's defaults instead of extending them, so the three it ships with are restored explicitly at the
  **end** of the list (`Serializer\ExceptionInterface: 400`, `ApiPlatform\Metadata\Exception\InvalidArgumentException: 400`,
  `Doctrine\ORM\OptimisticLockException: 409`) — without them, unparsable JSON or a wrongly-typed field
  answered **500 on every POST, public ones included**, i.e. an anonymous caller could manufacture 500s at
  will and drown real server errors in the logs. And resolution takes the **first matching entry**, with
  `is_a()` matching interfaces and parents too, so a broad entry must stay **below** the precise ones: add a
  new exception *above* those three restored defaults, never after. Since 2026-09-22 (issue #239)
  `defaults.collect_denormalization_errors: true` narrows what that 400 covers: a **wrongly-typed field**
  (`{"name":123}`) is collected instead of aborting the deserialization and comes out as a **422 with
  `violations` naming the field**, the same shape the admin forms already render for an `Assert`; only
  unreadable JSON and a root that is not an object stay a 400 (`MalformedRequestBodyException` since
  #355, see above). `MalformedRequestBodyTest` pins both boundaries. The API Platform metadata
  pool survives a change to that option — `rm -rf var/cache/test` before trusting a red test.
- **Errors under `/api` come out as JSON, never as Symfony's HTML page** (audit A15). Two families escaped
  API Platform's own error handling: the router's 404/405 (raised before API Platform exists for that
  request) and the 403s of our own `kernel.request` guards on non-API-Platform routes (`POST /api/logout`
  without the CSRF header, `POST /api/login_check` without `X-Requested-With`).
  `Shared/Infrastructure/Http/ApiJsonErrorFormatListener` sets `json` as the request format for any
  canonical path under `/api` (exactly `/api` or `/api/…`, never `/apix`), and
  Symfony's `ProblemNormalizer` then renders RFC 7807. Two things to leave alone: it is on **`kernel.exception`
  at priority -100**, *not* `kernel.request` — setting the format on every request breaks API Platform's
  content negotiation, and `GET /api/docs` without an `Accept` header answered **406** — and -100 sits
  between API Platform's own `ExceptionListener` (-96) and Symfony's `ErrorListener` (-128), so it never
  runs on errors API Platform already handled. `ApiJsonErrorFormatListenerTest` + `ApiErrorFormatTest` pin it.
- **A `ProblemExceptionInterface` thrown by a controller under `/api` gets its typed problem+json from one
  listener** (issue #322): `Shared/Infrastructure/Http/ApiProblemResponseListener`, `kernel.exception`
  **priority -98**, main request, canonical path under `/api`, renders `{type, title, status, detail}` as
  `application/problem+json` (status `500` if the exception has none). It replaced the assistant's and
  base-access's own listeners; never write a per-route copy again. **The priority is the rule, not a
  detail**: API Platform's `ExceptionListener` (-96) stops propagation on every route it owns, so what
  reaches -98 is non-API-Platform *by construction* — no `_api_respond` attribute is read — and it must
  stay above Symfony's rendering (-128). `ApiProblemResponseListenerPriorityTest` reads the real
  dispatcher and pins that bracket. Two consequences for a new exception rendered there: it needs a
  `log_level` in `framework.exceptions`, since `logKernelException` (0) now sees it — base-access's 429
  was not logged at all while its listener stopped propagation at 0, and would go out `critical` without
  its `info` entry (`ExceptionLogLevelCoverageTest` enforces it) —, and an `exception_to_status` entry for it is
  dead config. Never give such an exception a `status_code` in `framework.exceptions`: `logKernelException`
  would swap it for an `HttpException` before -98, and the `type` would silently disappear. One case
  where the propagation does reach -98 from an API Platform route: `kernel.terminate`, where the delegated
  renderer stands down (non-debug) — the listener stands down too. `BackofficeUserRoleResourceTest`
  asserts the 409 still carries API Platform's debug `trace`, which the shared listener never emits. Rejected:
  `api_platform.handle_symfony_errors: true` — global, unfiltered by path, rewrites the 404/405/403 bodies
  that already work, and hands an `html` negotiation back to Symfony before `ApiJsonErrorFormatListener`.
- **Every quota 429 gets its `Retry-After` from one listener** (issue #273):
  `Shared/Infrastructure/Http/RetryAfterListener` reads any exception implementing
  `Shared/Domain/Exception/RetryAfterAware` (a PHP 8.4 interface property, `$retryAfter { get; }`, met by
  the exceptions' promoted `public readonly`). It notes the deadline on the request at **`kernel.exception`
  priority 64** and sets the header at `kernel.response` — **on a 429 only** (a failed render that ends in a 500 must not carry it) — with an injected `ClockInterface`. 64 is not
  arbitrary: it must sit above every listener that *builds* the 429 and so stops propagation — API
  Platform (-96) and, on a controller, `ApiProblemResponseListener` (-98). A new quota exception implements the interface; never write a fifth
  per-context copy. `RateLimiterLockFailureListener` stays apart on purpose (fixed delay, priority 16).

