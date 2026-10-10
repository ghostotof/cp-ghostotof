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
  (`Post`/`Put`/`Delete`) under `Infrastructure/ApiPlatform/`. A CRUD Processor **uses the
  `Shared/Infrastructure/ApiPlatform/DispatchesWriteOperations` trait** (issue #338,
  `@use DispatchesWriteOperations<TheResource>`) and declares only `create()`, `update(Uuid $id, …)` and
  `delete(Uuid $id)`. The trait picks one by operation, and refuses any other with
  `UnsupportedOperationException` before any action — never re-write the `instanceof` cascade.
  `DispatchesWriteOperationsTest` pins that choice. Each resource's functional test pins the wiring.
  Collection reads use a `?locale=` query filter
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
  render time, `ErrorListener::getOperationExceptionToStatus`), every exception the kernel converts
  itself — a `framework.exceptions` entry with a `status_code`, which wins, else a `#[WithHttpStatus]`
  of `src/`; either way the level is resolved on the original exception — and every concrete
  `ProblemExceptionInterface` of `src/` (found by token parsing
  through `tests/Support/DeclaredClasses`, shared with `ProblemDetailStaysStaticTest`, never inferred from
  file paths); a level that matches **every** status the exception can be rendered with (4xx:
  info/notice/warning, 5xx: warning/error/critical) — a cautious superset: in each table API Platform
  may consult (the global one, merged per operation) the first key matching by `is_a()`, plus
  `getStatus()` for a problem exception and the kernel's conversion; no entry that targets anything else — a broad `\DomainException` or an
  interface would hide real server faults and shadow the precise entries after it, since the kernel takes
  the first match —; and justified ways out only (`EXEMPT`: the two broad API Platform defaults below,
  which stay `critical`; `JUSTIFIED_ENTRIES`: the 415 of #320). **An `EXEMPT` entry is exempt from the
  `log_level` entry only, never from the status/level check** (issue #373): it is still judged at the
  kernel's default `critical`, hence at a 5xx; `testTheLevelCheckJudgesEveryExemptedEntry` locks that in.
  An `exception_to_status` key that is an interface or an abstract class (`Serializer\ExceptionInterface`)
  is judged too, through a PHPUnit stub handed to `resolveLogLevel` — before #373 it escaped the check,
  which is what really let #360 live. Not covered: an interface or abstract class given a `status_code` in
  `framework.exceptions` (its implementations are not in `src/`, so nothing lists it as rendered) — don't
  write one.
  **Both maps are read compiled, never parsed from YAML** (issue #357): `tests/Support/CompiledExceptionConfig`
  reads the `exception_listener` mapping, the `api_platform.exception_to_status` parameter and the
  resource metadata from the test container, so an entry from another config file or a `when@test`
  block counts as the kernel counts it; `ApiExceptionLogLevelTest` builds its listener from the same
  mapping; `CompiledExceptionConfigTest` proves it by compiling the app with one more config file
  (`ExtraConfigKernel`, through an `extra_config` option of `bootKernel()` that only that class's
  `createKernel()` understands — `KernelTestCase` silently ignores it anywhere else). The flip side: the test container only sees `test`. **Never declare either map for a single
  environment** — not `when@prod`, `config/packages/prod/`, `services_prod.*` (preprod and prod both run
  `APP_ENV=prod`, the guards would miss it), and not `when@test` either (the guards would turn green on
  a config production lacks): keep them common. `ExceptionMappingEnvironmentParityTest` enforces it by
  loading every environment of `Kernel::getAllowedEnvs()` through Symfony's own loaders without compiling
  (`tests/Support/EnvironmentConfigKernel`), each map normalized and merged by its extension's own
  configuration node — so every form the kernel knows (YAML or PHP, list form, dashed keys) is seen,
  and nothing it ignores. Keep both maps literal: a `%env()%` or `%parameter%` value reads the same
  everywhere but resolves per environment, and the same test refuses it. The level checked is the one
  the kernel resolves, read from `ErrorListener::resolveLogLevel` itself: the first matching entry by
  `instanceof`, else an inherited `#[WithLogLevel]` — honoured, though the project keeps levels in
  `framework.exceptions` so the domain does not depend on HttpKernel. Three vendor internals are read by
  reflection rather than reimplemented (the compiled mapping, `getInheritedAttribute`,
  `resolveLogLevel`); `ErrorListenerInternalsTest` pins their signature, so a Symfony upgrade that
  changes them fails there first. GraphQL operations are not covered:
  `testGraphQlStaysDisabled` turns red the day `api_platform.graphql.enabled` becomes true.
  **A request body the Serializer refuses never reaches the broad `Serializer\ExceptionInterface` entry**
  (issue #355): `Shared/Infrastructure/ApiPlatform/MalformedRequestBodyProvider` decorates
  `api_platform.state_provider.deserialize` and, **on an operation that deserializes only**, turns the
  Serializer's `UnexpectedValueException` family — the only one a client can trigger — into
  `MalformedRequestBodyException` (400, `info`): unreadable JSON, and valid JSON whose root is not an
  object (`123`, `null`, `"x"`), which is *not* collected as a 422 — plus, since #360, two precise classes
  of its `RuntimeException` family that only a body can cause and no operation triggers today:
  `ExtraAttributesException` (`allow_extra_attributes: false`) and `MissingConstructorArgumentsException`
  (`collect_denormalization_errors` off); with the broad entry at 500, a context change would otherwise hand
  anonymous callers a `critical` 500. Never `RuntimeException` itself. A server fault raised at the same step
  (`LogicException`, `MappingException`, an `UnsupportedFormatException` for a negotiated format with no
  encoder) passes through untouched and stays a `critical` 500 — never widen the `catch` to
  `Serializer\ExceptionInterface`. The broad entry keeps covering the output side only (a non-encodable
  response — a `NaN` in a `double precision` column; invalid UTF-8 never reaches the `UTF8` database —,
  a misconfigured Serializer, a negotiated format with no encoder): server faults, so it maps to **500**
  since issue #360, **not** API Platform's default 400 — never "restore" it, `ServerSideSerializerFailureTest`
  pins the 500 end to end. That column now refuses `NaN` through a `CHECK` (#372), so the test lifts the
  constraint (`LiftsExperienceYearsConstraint`) to write its row. It must stay `critical`: never give it a `log_level`, and never move the
  conversion to the JSON decoder, which also decodes internal data. The Hydra entrypoint (`GET /api`) is
  disabled in **every** environment (`enable_entrypoint: false`, #360): API Platform hard-codes its
  formats to `jsonld`/`jsonhal`/`jsonapi`/`html` (`entrypoint_formats`, a `json` added to `docs_formats`
  is dropped), none of which the project declares, so in dev/test it only ever answered an
  `UnsupportedFormatException`. `/api/docs` stays available in dev.
  One caveat: on a write, the decorator also sees the providers `DeserializeProvider` wraps (read of the
  existing item, our own Providers) — a Provider of `src/` that calls the Serializer must catch its own
  failures.
  When two exceptions share a status code but the frontend must tell them apart (e.g. the two `PUT …/roles` 409s: self-modification vs last-super-admin), make
  the exception `implements ApiPlatform\Metadata\Exception\ProblemExceptionInterface` and
  `use App\Shared\Domain\Exception\HasProblemType` (declare `problemType()` → a stable kebab slug +
  `problemStatus()`): API Platform then emits `type: /errors/<slug>` in the problem+json, which the client keys
  on instead of substring-matching the localized `detail`.
  **Two traps of that map, both paid for** (audit A15): declaring `exception_to_status` **replaces** API
  Platform's defaults instead of extending them, so two of the three it ships with sit explicitly at the
  **end** of the list (`Serializer\ExceptionInterface: 500`, `ApiPlatform\Metadata\Exception\InvalidArgumentException: 500`),
  both **deliberately not** at API Platform's 400; the third, `Doctrine\ORM\OptimisticLockException: 409`,
  was **removed** by issue #373 (no entity is versioned, and its only possible throw today, `notVersioned()`,
  is a programming error — a `critical` 500, what no entry gives; map the conflict back to a 409 with a
  `log_level` the day an `#[ORM\Version]` appears). The API Platform `InvalidArgumentException` (subclass
  `ItemNotFoundException`; `OperationNotFoundException` is **not** one, it extends PHP's) was reachable by a
  client: an `id` in the body of any PUT — a "standard" PUT populates no object in API Platform 4, so the key
  went to IRI resolution and came out 400 + `critical`. `defaults.denormalization_context.api_allow_update:
  false` now makes the Serializer refuse it (`MalformedRequestBodyException`, `info`), and
  `MalformedRequestBodyTest::testEveryUpdateOperationRefusesAnUpdateByIri` checks it holds on every compiled
  PUT/PATCH — a `denormalizationContext` declared on a resource or an operation is **merged** with that
  default (`OperationDefaultsTrait::addGlobalDefaults`), but an empty `[]` or an explicit
  `api_allow_update: true` neutralizes it. What is left for the broad entry is server faults (IRI
  generation, metadata), hence 500. Two client paths would reopen it: **pagination** (`?page=0`) the day a
  provider paginates, and a **`writableLink` relation**, for which `AbstractItemNormalizer::denormalizeRelation`
  sets `api_allow_update: true` again — give either case its own class first, never move the entry back
  to 400. When audit A15 restored them, unparsable JSON or a wrongly-typed field
  answered **500 on every POST, public ones included**, i.e. an anonymous caller could manufacture 500s at
  will and drown real server errors in the logs; the Serializer's default 400 was the cure then. #239 and
  #355 have since given every client case its own class, so what still reaches that entry is a server
  fault, mapped to **500** (issue #360, see above) — the client side is guarded by `MalformedRequestBodyTest`,
  not by this entry. The status/level check of `ExceptionLogLevelCoverageTest` covers `EXEMPT` entries
  and interface keys since issue #373 (see above): both blind spots had let #360 live.
  And resolution takes the **first matching entry**, with
  `is_a()` matching interfaces and parents too, so a broad entry must stay **below** the precise ones: add a
  new exception *above* those two restored defaults, never after. Since 2026-09-22 (issue #239)
  `defaults.collect_denormalization_errors: true` narrowed what the Serializer's 400 covered: a **wrongly-typed field**
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
  per-context copy. It must also be traced: `ThrottledRequestAuditCoverageTest` (issue #361) wants every
  `RetryAfterAware` either sorted by `ThrottledRequestAuditListener` or justified as a per-account quota
  (see `.claude/rules/security-authentication.md`). `RateLimiterLockFailureListener` stays apart on purpose (fixed delay, priority 16).
  **And every quota 429 carries `type: /errors/rate-limited`** (issue #369), the `type` of the nginx zones
  (`@rate_limited`): one cause, one value for a client to recognise. A quota exception therefore also
  implements `ProblemExceptionInterface` with `HasProblemType` (`rate-limited`, 429) — model:
  `BaseAccessRateLimitExceededException`. Without it, on an API Platform operation, the `type` falls back
  to `/errors/429`, derived from the status alone, which is what the contact, set-password and
  translation quotas answered until #369. `QuotaExceptionsAreRetryAfterAwareTest` walks every
  `RetryAfterAware` of `src/` (`DeclaredClasses`) and enforces it; each route's functional 429 test
  reads the `type`. The `exception_to_status` entries of the three API Platform quotas stay, at 429:
  that map is read first. **`login_throttling` is not one of them yet**: Lexik's failure handler answers
  **401** `Too many failed login attempts`, no problem+json. Decided on 2026-10-10 (#369): it moves to a
  429 `rate-limited` with `Retry-After`, in issue #399, since it touches the login page, the preprod
  smoke test (`tools/smoke-login-throttling.sh`) and the failure handler.

