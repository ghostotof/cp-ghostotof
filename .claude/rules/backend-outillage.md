---
paths:
  - "backend/**"
  - ".github/workflows/**"
  - ".github/dependabot.yml"
  - "docker/php/**"
---

# Backend — quality tooling and logging

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Project state ». The index of every rule is at the top of `.claude/CLAUDE.md`.

## Project state

This repository started as a freshly generated project skeleton (single "Init" commit). Real backend code now
exists — the `Security` bounded context (`User` + `Authentication`), six `Portfolio` bounded contexts
(`Experience`, `Quality`, `About`, `Contribution`, `Incident`, `Watch`, see `.claude/rules/backend-architecture.md` and the context rules) and an
`Ai` context whose two sub-contexts are delivered — `Translation` (spec 0002, ADR 0004, v0.10.0/v0.10.1) and `Assistant` (spec 0005, v0.19.0), see `.claude/rules/ai.md`; every entity has a UUID v7 key (spec 0003, v0.11.0) and every ordered content is
reordered by drag-and-drop with an explicit FR/EN link in the database (spec 0004, v0.12.0, see
`.claude/rules/portfolio-ordre.md`) — and follows a DDD structure under
`src/<BoundedContext>/` — the generic `ApiResource/`, `Controller/`, `Entity/`, `Repository/` directories left
over from the skeleton have been deleted (they were empty placeholders, no code ever lived there); don't
recreate them, new code always goes under its bounded context. PHPUnit is configured (`phpunit.dist.xml`,
`tests/`, mirrors the `src/` bounded-context tree) and runnable via `php bin/phpunit` inside `make sh`;
its traces carry no call arguments (see the last section, issue #420).
**PHPStan** is configured (`backend/phpstan.dist.neon`, level `max` + `phpstan-strict-rules` +
`phpstan-symfony`/`-doctrine`/`-phpunit`/`-deprecation-rules` via `extension-installer`): run it with
`composer phpstan` inside `make sh` (warms the dev container first), CI job `phpstan-backend` (blocking,
`build-images` needs it). **No baseline** — level `max` + strict-rules is green across `src/` AND `tests/`, keep
it that way. Two scoped `ignoreErrors` in `phpstan.dist.neon` cover functional-test JSON payloads (a decoded
response body is `mixed`; the PHPUnit assertions are the shape check, not PHPStan) — `offsetAccess`/`foreach`
and `argument.type … mixed given`, `path: tests/` only. `src/Kernel.php` is analysed since #675 (it registers
the MCP capabilities compiler pass, `declare(strict_types=1)` added): the `getAllowedEnvs()` false-positive
`method.unused` that excluded it no longer occurs (measured 2026-10-21). Rector still skips it (Flex skeleton). The `$uriVariables` mixed-access pattern is solved by the
`App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables` trait (`uriVariableUuid`/`uriVariableString`/
`uriVariableLocale`) — reuse it in new Providers/Processors rather than casting `mixed`. Functional tests build request bodies via
`App\Tests\Support\HttpJson::jsonBody()` (not raw `json_encode`, which is `string|false`).
`tests/object-manager.php` boots the kernel for `phpstan-doctrine`; `tests/bootstrap.php` is
`excludePaths`-excluded (Flex-managed). **Rector** is configured (`backend/rector.php`, `withPhpSets()` +
prepared sets deadCode/codeQuality/typeDeclarations/earlyReturn/instanceOf): `composer rector` shows diffs,
`composer rector:fix` applies, CI job `rector-backend` runs `--dry-run` (blocking, `build-images` needs it).
`rector/rector-symfony`/`-doctrine`/`-phpunit` are deliberately omitted (their deps conflict with `symfony/*
8.1.*`). Some cosmetic rules are skipped in `rector.php` (`SortAttributeNamedArgs`, `NewMethodCallWithoutParentheses`,
`FlipTypeControlToUseExclusiveType`, `ClassPropertyAssignToConstructorPromotion` — entities keep explicit
properties) — extend that skip list rather than fighting a rule inline. One skip is **not** cosmetic:
`ArrowFunctionDelegatingCallToFirstClassCallableRector` puts Rector and PHPStan in head-on disagreement (Rector
demands the rewrite, PHPStan rejects it), so it can never be satisfied — leave it skipped. Psalm
*is* installed (`psalm/phar`, `backend/psalm.xml`) but **only** for taint analysis (`composer psalm`, CI job
`sast-backend`, audit point M4) — `errorLevel="8"`, it is not and must not become a second type-checker
alongside PHPStan; don't reach for Psalm annotations or raise its level. **Symfony Language Tools**
(issue #90) is the fourth check: `symfony lsp:check` (`make back-lsp`, part of `make back-quality`, CI job
`lsp-check-backend`) boots the kernel in `dev` (`backend/.symfony-lsp.json`, `releaseMetadata: false` so
it makes no call to symfony.com) and validates what PHPStan cannot see: service ids, route names,
Messenger transports, firewalls, constraint options, bundle config keys, Twig paths. It needs no database
(a refused connection is fine — a *DNS* failure once segfaulted the checker, don't point it at an
unresolvable host). It requires Symfony CLI ≥ 5.20.0 (`.env`, `versions.lock`). **Language Tools is
pinned too** (`SYMFONY_LANGUAGE_TOOLS_VERSION`, issue #343), though the CLI has no option for it: left
alone, it asks the **GitHub API** (`releases/latest`, anonymous, a rate limit shared by every runner —
hence the 403s *before any analysis*) for the latest stable once its cache is over 24 h old.
`docker/php/install-symfony-language-tools.sh` installs the declared version into the CLI's cache
(`symfony lsp:cache-dir`, under the CLI's own `flock` on `install.lock`, checksum checked against the
release's `SHA256SUMS`) and writes `state.json` with `checkedAt` = now, so the CLI uses it **without
any network call**; both the CI job and `make back-lsp` run it first, then `--verify` after the
analysis. It relies on the CLI's cache layout (`local/externaltool/manager.go`): on a
`SYMFONY_CLI_VERSION` bump that breaks it, `--verify` turns red (exit 4) instead of the CLI silently
going back to the GitHub API. Exit codes 2 (download) / 3 (integrity) / 1 (configuration) drive the
job summary (`.github/scripts/summary-language-tools.sh`): only a 2 says "re-run", a 3 says
"don't", none reads as a code defect. The script lives in `docker/php/` because that directory is
mounted in the dev container and `tools/` is not. The job is blocking only on the `--fail-on` list
of low-false-positive codes and is *not* in `build-images`' `needs` yet (re-evaluate mid-October).
No baseline: the repo is clean apart from
six `config.unknown_key` **warnings** on `ai.yaml` (`ai.agent.{translator,career_assistant}.model.name/options`, three per agent), keys that
Symfony accepts because the bundle declares `model` as a `variableNode` (its only rule: a string, or an array
with `name`) — Language Tools cannot know the keys under it, a false positive by construction, left visible
rather than baselined. **Monolog** is installed (`symfony/monolog-bundle`, audit 2026-09-16 constat A5 —
without it, HttpKernel's fallback logger emitted nothing below `warning` and no security event left a
trace at all). `backend/config/packages/monolog.yaml`: in `when@prod` (which covers preprod and prod,
same `APP_ENV`) every handler is a `stream` to `php://stderr` with `monolog.formatter.json`, because
Kubernetes only collects a container's stdout/stderr — `kubectl logs … | jq` is the whole tooling. The
`security` (Symfony's own) and `security_audit` (ours, declared in `monolog.channels`) channels get
their own handler at a hard-coded `info`, never `fingers_crossed`: a run of failed logins with no error
after it is exactly the trace worth keeping, and buffering would discard it (`SecurityAuditLogChannelTest`
pins both, and their exclusion from `main` and `console` — the environment parity guards do not compare
Monolog handlers, which rightly differ in `test`; audit of #698). Everything else goes to
the `main` handler at `%env(default:app.log_level:LOG_LEVEL)%` (`LOG_LEVEL=warning` in `backend/.env`,
`debug` in the preprod image), which excludes those two channels so an event is emitted **once**. No
channel names an account by its login identifier (issue #363): the `security` channel goes through
`SecurityChannelAccountRedactor` (see `.claude/rules/security-authentication.md`), and
`AccountIdentifierReachesNoLogTest` reads every channel at every level, preprod's `debug` included. A
third channel follows the same rule, `ai_usage` (spec 0005): the career assistant's and, since issue
#356, the translator's token usage, duration and quota refusals, never any content, on its own `info`
handler — as an `info` of the default channel it never
left a production pod (`AiUsageLogChannelTest` pins the prod config). Since #643 two more, both kept out of
`main` and `console` (`McpLogChannelsTest`): `mcp`, the MCP SDK's own channel, capped at `warning` and bounded
by `SdkChannelRedactor`, and `mcp_usage`, the MCP call log, on an `info` handler (see `.claude/rules/mcp-journaux.md`). The
`default:` processor takes a *parameter name*, hence `app.log_level` in `services.yaml` — `default:warning:`
would look for a parameter called `warning` and fail at compile time. No duplication with
`error_log = /proc/self/fd/2` (`php.prod.ini`) either: Monolog writes to fd 2 itself, `error_log` only
ever receives the engine's own errors. The events are emitted by `SecurityAuditLogger` (see Phase 3 of
the remediation plan). The
frontend has moved past the default scaffold: it follows a layered clean architecture (see `.claude/rules/frontend.md`) and has
Vitest configured with `npm test`. A `ROLE_SUPER`-gated backoffice (`/admin` on the frontend, `/api/backoffice/*`
on the backend) lets an authenticated super-admin manage all of the above content plus user accounts — see
`.claude/rules/backoffice-api.md` and `.claude/rules/frontend-backoffice.md`.

## Target PHP version (issue #429)

`composer.json` requires **`"php": ">=8.5"`**, the runtime of every image, of CI (`setup-php`
`php-version: '8.5'`) and of the dev container — decided on 2026-10-11, rather than keeping `>=8.4` and
forbidding 8.5 functions through the analysers. The lower bound used to allow an install nobody tested or
deployed, and let an 8.5 function (`array_first`, during #419) through on a transitive polyfill alone,
since PHPStan, Rector (`withPhpSets()` without argument) and Psalm all read their target version from
that constraint — none sets its own, keep it that way so the one constraint drives all three. The
polyfill of the runtime's version is listed in `replace` like the older ones (`symfony/polyfill-php85`,
removed from the lock); `polyfill-php86` stays, its functions are not native yet. Moving to 8.5 let
Rector's `ArrayFirstLastRector` rewrite four `$a[array_key_last($a)]` / `array_values($a)[0]` into
`array_last()` / `array_first()`. No `config.platform.php`: Composer only ever runs in the container.
`tests/Shared/PhpConstraintMatchesRuntimeTest.php` fails when the constraint is not exactly
`>=<runtime major.minor>` or that version's polyfill is not replaced — so a runtime bump turns it red
until both move in the same change.

## Exception traces under PHPUnit (issue #420)

`phpunit.dist.xml` sets `<ini name="zend.exception_ignore_args" value="1"/>`, as `php.prod.ini` does in
the deployed images: a trace carries no call arguments. `#[SensitiveParameter]` (#411, #414, see
`.claude/rules/backend-conventions.md`) only covers `src/`; the vendor keeps its arguments
(`Cookie::create($value)`, `JWTManager::parse($token)`, `LcobucciJWTEncoder::decode($token)`…), and the
risk is a test trace pasted in a ticket or an assistant session. Facts measured before deciding
(2026-10-10): **CI already ran with it On** — `setup-php` loads `php.ini-production` by default
(`ini-file: production`) — while no ini file of the dev container sets it (no `php.ini`, `php.dev.ini`
as `conf.d/zz-app.ini` is silent: PHP's built-in Off); the directive is `PHP_INI_ALL`, so PHPUnit's
`ini_set()` applies it. **PHPUnit's report loses nothing**: it prints a trace as `file:line`, and its
exporter skips an exception's `trace`. What loses the arguments is a trace rendered by a test or by
Symfony (`getTraceAsString()`, a `FlattenException` in Messenger's `ErrorDetailsStamp` or in the debug
`ProblemNormalizer` body of a non-API-Platform route — API Platform's own debug body already drops them).
No test asserted on trace arguments, and the #411/#414 guards read the attribute by reflection, never
a trace, so none became vacuous. **A `php` process a test starts** does not get PHPUnit's `<ini>`: it is
built by `tests/Support/ChildPhpProcess::command()`, which passes the parent's value with `-d` (its
stderr, trace included, ends up in the failure message) — the concurrency tests, through
`ChildPhpProcess::inTestEnvironment()` (see the next section), and `CreateCpgUserCommandTest`'s
pseudo-terminal run. **To debug with arguments**, write a local
`phpunit.xml` (gitignored, read instead of the dist file) that sets it **explicitly** to 0 —
`bin/phpunit -d …=0` does not work, the XML `<ini>` is applied after the CLI one. The two runtime
tests then **skip**, naming the cause, rather than fail; a local `phpunit.xml` that merely omits it (an
old copy of the dist) fails them. `ExceptionArgumentsHiddenInTestsTest` pins the dist entry and the
shape of the child's command (the runtime checks alone would stay green in CI), the effect on
`getTrace()`, on a `FlattenException` and in a child, and that no test names `PHP_BINARY` outside the
helper. **Not covered**: `debug_backtrace()` (the directive only acts on exceptions — measured), the
frame of a `require`, which keeps the included path, a child started otherwise (a literal `'php'`,
`PhpExecutableFinder`, `bin/console` by its shebang — a token heuristic cannot tell a literal `'php'`
command from test data, so the guard does not try), and dev over HTTP (`APP_DEBUG` error bodies, Off
there) — which is why the #411/#414 guards stay. Setting it in `php.dev.ini` would cover every child at
once, at the cost of dev CLI traces: not done, an open question of #420.

## Concurrency tests with child processes (issue #403)

A test that puts real processes in a race (`RateLimiterConcurrencyTest`, `ContributionOrderConcurrencyTest`,
`SendAccountInvitationConcurrencyTest`, `PasswordSetupActivationConcurrencyTest`, `ReinviteConcurrencyTest`,
`InviteUsernameConcurrencyTest`)
goes through **one harness, written once**. `tests/Support/ChildPhpProcess::inTestEnvironment()` builds the
child's `Process`: its command line (above) and a copy of the test's `$_SERVER`, where `phpunit.dist.xml`'s
`<server force="true">` values live (a real `ANTHROPIC_API_KEY` exported in the shell must not reach the
child) — a variable not to pass on is excluded there, for every child at once. Process also inherits `$_ENV`
on its own, which holds the forced values too under PHPUnit (measured 2026-10-12): the copy is what does not
depend on that. `ChildPhpProcess::SETUP_FAILED` (2) is the exit code of a child that could not set itself up.
`tests/Support/ProcessBarrier` is the file barrier, both sides: the test `hold()`s it (exclusive `flock`),
the child calls `awaitInChild($path, ?$sessionPid)` once everything slow is done (writes `ready [<pid>]`,
then blocks on a shared lock), the test waits with `waitUntilReady()` — which then sleeps
`SETTLE_MICROSECONDS` so that every child has reached its `flock()` — or `sessionPidOf()` (the PostgreSQL
session it then watches in `pg_stat_activity`), and `release()`s; an unreferenced barrier releases itself.
Children reach private services through `tests/Support/TestServiceLocator` (`limiter.<name>` included; it
replaced `RateLimiterFactoryLocator`). `tests/Shared/ChildProcessHarnessTest.php` exercises the protocol on
real processes and fails, in `tests/`, on any `flock()` outside `ProcessBarrier`, any `foreach ($_SERVER`
outside `ChildPhpProcess`, and any `new Process(ChildPhpProcess::command(` outside its `OWN_ENVIRONMENT`
allow-list (the Dotenv test that isolates a pod's environment on purpose). Not seen: `proc_open()`
(`CreateCpgUserCommandTest`'s pseudo-terminal child gets the inherited environment only).
**The interleaving point is written once too** (issue #510): `tests/Support/FlushInterleaving`,
`releaseBeforeNextFlush()` (`preFlush`: everything read, nothing written — #364, #389, #458) or
`releaseAfterNextFlush()` (`postFlush`: written inside the operation's transaction, not committed — #435),
given the EntityManager, the barrier, the started child and the timeout. It waits for the child's
`ready <pid>`, arms a one-shot listener that removes itself on the first flush, releases the child, then
returns as soon as the child has **finished** or **its** session waits on any `Lock` in `pg_stat_activity`
(read from a separate probe connection, outside any transaction), and fails the test past the timeout.
`tests/Shared/FlushInterleavingTest.php` exercises it on a real child (blocked on an advisory lock the test
holds, finished, neither); `ChildProcessHarnessTest` also refuses, in `tests/`, any class constant
`::preFlush` / `::onFlush` / `::postFlush` (whatever the class is called: `Events`, an alias, inside an
attribute) and any string literal naming `pg_stat_activity` outside it (plus that test, and the guard
naming the view) — broad on purpose: a test with another use joins its pattern's owners with its reason.
Not seen: a listener armed on the event's name written as a string (`'preFlush'`), a view name built by
concatenation. The wait returns once the child has finished **whatever its exit code**: each caller checks
it, and names the refusal it expects or denounces.
**Proving a concurrency test still fails without its fix** takes the right mutation, measured in #510
before and after the extraction: remove the **lock and keep its transaction** — `FOR UPDATE` out of
`PostgresAccountRowLock` (#435 and #458 red on their token counts), the `pg_advisory_xact_lock` call out
of `PostgresUsernameAllocationLock` (#364: the parent's invitation fails on the username taken meanwhile)
or `PostgresAdvisoryOrderScopeLock` (#389) — since #512, the statement inside the adapter's `acquire`
closure, the transaction being `PostgresLockedTransaction`'s (measured again after the extraction). Every
test that waits on a held lock to prove the bound carries a `statement_timeout = '3s'` safety net, so
that a mutation removing the bound (`boundTheWait()`) fails it in `57014` instead of hanging the suite. Removing `whileLocked()` from the invitation handler entirely
proves nothing for #435: its flush then commits on its own before the `postFlush`, the child purges a
committed token and the count is right. Removing the purge of unused tokens from the activation leaves
#458's test green: with the lock, the handler waits and drops the message (« constaté sous verrou »); the
purge is pinned sequentially, on the real `_test` database, by
`AccountPasswordSetupResourceTest::testActivationPurgesTheOtherUsableLinksOfTheAccount` (issue #519: a
second usable token saved through the repository, activation by the first; measured, only the table count turns
red: the leftover link is refused anyway by the « account already active » guard), and by
`PasswordSetupServiceTest`'s mock. Removing it from `CpgUserAdministrator::changePassword()` (#460) turns
`testEveryUnusableLinkGetsTheSameResponse` red on its « révoqué par le backoffice » link (204 instead of
the common 404 — the account is still pending, nothing else refuses the link), measured in #519. No
concurrency harness for either: the purge is no race.

## Reading a repository file from a test (issue #629)

A test reads a file of `src/`, `config/`, the backend or the repository through
**`tests/Support/RepositoryFiles`**: `backend('src')` and `repository('k8s/README.md')` are written in that
class only, from its own location, and `read($absolutePath)` fails the test naming the path and the cause
— missing, not a regular file (a directory passes `assertFileIsReadable`), unreadable —, never returning
`''` the way `(string) file_get_contents()` did, the symptom of #615. Every reader of a repository file goes
through it: `KubernetesManifests`, `PhpImageBuild`, `MigrationSources`, `Runbook`, `EnvironmentConfigKernel`,
the token censuses of `tests/Support` (`DeclaredClasses`, `MisqualifiedNativeCalls`…) and the tests that walk
`src/`. A file a test wrote itself (a sandbox, a temporary directory) is not a repository file. Never write
`\dirname(__DIR__, n)` or `__DIR__.'/../..'` to reach out of `tests/`: such a path depends on the test's depth
and silently reads elsewhere when the test moves. `tests/Shared/DirPathsStayInTestsTest.php`
(`tests/Support/PathsLeavingTests`, by tokens) resolves every path built from `__DIR__` lexically from the
file's directory — `dirname(<__DIR__ and its concatenation>[, n])`, qualified or not, `n` in any base,
positional or named, trailing comma allowed, or a bare `__DIR__`, each followed by its concatenated pieces
(literals, `DIRECTORY_SEPARATOR`, the start of an interpolated string) — and fails on one that leaves `tests/`
or that it cannot resolve. **It judges where a path leads, not how it gets there**: a sibling fixture, and the
child scripts' `require \dirname(__DIR__, n).'/bootstrap.php'` (which load the autoloader, so cannot use
`RepositoryFiles`), stay in `tests/` and pass without an exemption — the issue meant to exempt them; a moved
one fails loudly on its `require`. A path that climbs out and comes back into `tests/` passing is the same
accepted limit. Its `EXEMPT` list holds pairs (file, resolved path) for the files that run before the
autoloader (`tests/bootstrap.php`, `tests/object-manager.php`, the child script `await-barrier.php`) and
`RepositoryFiles` itself, each pair still required to be written; a new path in an exempt file is refused.
Not seen: a nested call on the result (`\dirname(\dirname(__DIR__))`, `realpath()`), a path carried by a
variable, one composed by `sprintf()` or `implode()`, a constant other than `DIRECTORY_SEPARATOR`, `__FILE__`.

## API Platform: one version for the whole family, and deprecations seen (issue #701)

Every `api-platform/*` package of the lock is at **the same version** (13 of them, 4.4.6 on 2026-10-28), and
`composer.json` requires the two it names, `api-platform/symfony` and `api-platform/doctrine-orm`, as **`^4.4.6`**,
a caret. Before #701 the bundle was pinned `4.3.19` exactly while the components (`serializer`, `state`,
`metadata`…) had climbed to `4.4.0` through their own loose constraints: the bundle wired `ItemNormalizer` to
denormalize and the serializer deprecated it, so every `POST /api/contact` wrote a deprecation in production. The
caret is deliberate and is **not** the exact pin of the 0.x packages (`mcp/*`, `symfony/ai-*`, ADR 0004 D1, see
`.claude/rules/mcp.md`): API Platform follows semver, a minor does not break, and an exact pin on the bundle alone is
precisely what let the family drift apart. When you bump one, bump them all (`composer update 'api-platform/*'`).
`.github/dependabot.yml` carries an `ignore` of the **major** of `api-platform/*`: without it, since 5.0 came out
(2026-09-16) the `backend-minor-patch` group rejected the two packages as « the latest version is a major » and
never looked at the 4.x releases. **Remove that `ignore` when the 5.0 migration is done** (#735).
**`ignoreIndirectDeprecations="true"` in `phpunit.dist.xml` stays** (it spares the noise of the rest of the vendor),
and that is what hid this one, vendor calling vendor. The targeted net is `tests/Support/ApiPlatformDeprecations`, an
error handler put on for one action: it keeps an `E_USER_DEPRECATED` when its message starts with `Since api-platform/`,
or when **the stack crosses a file of `vendor/api-platform/` before the first file of the project** (going up from the
deprecated call; `trigger_error()` and `deprecation-contracts` are only the relay; the project is `src/` and `tests/`
anchored on the backend root, whatever the path above it; any other file, compiled-container glue included, decides
nothing and the climb goes on). Nothing between API Platform and the call is assumed: a lazy `var-exporter` proxy, a
decorator, Composer's autoloader (a deprecation raised when a class file loads) do not hide it. **The loud false
positive is accepted, on purpose**: the sub-request of API Platform's `ErrorListener` (`kernel->handle()`) goes through
Symfony's kernel, and an internal Symfony deprecation raised there is charged to API Platform, which has nothing to
migrate. For a guard a false positive is loud and costs one line, a false negative is silent and costs a release: the
finer criterion tried before (« the first caller outside the package that raises it decides ») went blind on exactly
those layers, and on a clone placed under a `vendor/` directory, and was dropped. A call made by the project itself,
with no API Platform file above it before the project's, stays with the handler in place (PHPUnit counts it). Every
other error goes back to that handler, which is restored, and the failure cites the message, the deprecated method,
where it was raised and the API Platform call that led to it. What it watches: `ApiPlatformRaisesNoDeprecationTest`
(five representative requests, an authenticated backoffice `PUT`, the MCP route reader, which calls provider and
serializer without HTTP), and `ApiPlatformStartupRaisesNoDeprecationTest` (kernel boot, in-memory compilation of the
`test` container through `EnvironmentServicesKernel`, cold metadata: the pools `api_platform.cache.metadata.*`
cleared, then every resource's collection and every operation through `OperationMetadataFactoryInterface::create()`,
**all of which must resolve** — a `null` built nothing and counted as one would blind the guard; the factory has no
index, so the calls are quadratic, measured at 0.1 s for 112 operations). `DeprecatedReferencesOfApiPlatform`, a
read-only compiler pass run at the end of `TYPE_BEFORE_OPTIMIZATION`, reports an API Platform service (`api_platform.*`,
hidden `.api_platform.*` included, an `ApiPlatform\` class, or a `ChildDefinition` whose parent chain says so)
referencing a **deprecated alias**: Symfony raises that from its own pass, with no API Platform frame, and exactly as
`ResolveReferencesToAliasesPass` does — nothing when the referrer is itself deprecated, each link of an alias chain
judged by its own referrer — and `found()` starts over at each compilation. It does **not** report a deprecated
*service*: Symfony raises that one when the service is instantiated (`ContainerBuilder::createService()`, the factory
`PhpDumper` writes), never at compilation, and the requests above see it with its stack.
`ApiPlatformPackagesShareOneVersionTest` reads `composer.lock`: the family is what the roots pull through the
`require` graph (`ApiPlatformLockFamily`), the roots being the `api-platform/*` packages `composer.json` requires in
`require` and `require-dev` — read from the file, never listed in the test, so a component added tomorrow is judged
the day it is added —, not every package with the prefix (an `api-platform/*` tool outside the monorepo has its own
versions, and nothing in the lock tells it apart by `source.url` or `homepage`); it fails citing
`composer update <those packages>`. The collector, the lock family and the pass each have their own test
(`ApiPlatformDeprecationCollectorTest`, `ApiPlatformPackagesShareOneVersionTest`, `DeprecatedReferencesOfApiPlatformTest`).
**Two traps measured while building it.** `@` is deliberately not looked at: `trigger_deprecation()` calls
`@trigger_error()`, so every such deprecation arrives « suppressed » (and PHPUnit lowers `error_reporting()` during a
test anyway); `phpunit.dist.xml` has `ignoreSuppressionOfDeprecations`, which the collector reproduces, and honouring
`@` makes it blind. And the handler that hands an error back to PHPUnit is bound to `PHPUnit\Runner\ErrorHandler`'s
scope (`asPhpunitReadsIt()`), so that its frame is dropped from the stack PHPUnit classifies: otherwise a raw
`trigger_error(E_USER_DEPRECATED)` from vendor code called by vendor code was classified « unknown » and counted
(`ApiPlatformDeprecationHandoverTest`, which runs a child PHPUnit on `Fixtures/RawIndirectDeprecationProbe`). **That
binding relies on a `@internal` PHPUnit class, and its fallback is silent**: if `PHPUnit\Runner\ErrorHandler` is renamed
or not loaded, `during()` falls back on the plain closure without a word. The noise is the handover test's, which
asserts the class is there before its child PHPUnit would count the deprecation. It saw the `ItemNormalizer`
deprecation on the lock of `develop` (red), and not on 4.4.6. **Not covered**: an `E_DEPRECATED` of the PHP engine in
API Platform's code; a route absent from the list (adding one costs a line); a deprecation born at the first use of a
service (a normalizer at its first normalization, a deprecated service an API Platform service references) outside
those requests; a reference to a deprecated alias added by autowiring during the optimization.
`ApiPlatformProblemDetailsTest` pins the `type`, `title` and status
of the problem+json errors (400, 404, 405, 409, 415, 422, 429) the same way, read identical on both locks.

## Freezing the clock for a `Retry-After` (issue #743)

The project's listeners (`RetryAfterListener`, `LoginThrottlingRefusalListener`) read `Psr\Clock\ClockInterface`, which
`mockTime()` freezes. Symfony's limiter (`microtime(true)` in `Symfony\Component\RateLimiter\Policy`) and
`LoginThrottlingListener` (`time()`) read the **real** time and accept no clock: a test freezing only the first left the
`Retry-After` at the mercy of the current fraction of second (a whole minute for a login, whose delay is counted in
minutes). A test that asserts a `Retry-After` therefore uses `tests/Support/FreezesTheWholeClock`
(`freezeTheWholeClock()`): `mockTime()` and `ClockMock::withClockMock()` (`symfony/phpunit-bridge`, dev) on the **same**
instant, `.999` on purpose (the window opens and the refusal falls a thousandth before the next second), released by an
`#[After]`. Freeze **after** the login of a functional test: the JWT must stay valid at the real time its check reads.

`ClockMock::register()` is called by `tests/bootstrap.php`, not by the bridge's `SymfonyExtension`
(`clock-mock-namespaces`): PHP caches the function resolved at each call site, so a registration after a first
`microtime()` of the namespace has no effect, and the extension also enables `DebugClassLoader` and removes the
deprecations' deduplication for the whole suite. **Only the namespaces whose calls compute a deadline**
(`RateLimiter\Policy`, `Security\Http\EventListener`): `ClockMock` also replaces `sleep()`, `usleep()` and `hrtime()`, and
the locks, the caches and `FailedLoginTimingEqualizer` wait and measure for real. `WholeClockFreezeTest` pins both lists
(a mocked namespace that must be, an unmocked one that must stay so) and the effect on the real components. A new
component whose deadline a test asserts and that reads the time in another namespace adds its line there, with its test.

Under the frozen `.999` instant a `Retry-After` is an **exact** value, asserted as such: a one-hour `sliding_window` closes
3600 s later (13:00:00.999), symfony/rate-limiter >= 8.1.10 rounds the deadline up to 13:00:01, and
`RetryAfterListener` subtracts whole-second timestamps, so `AnswerControllerTest` reads `3601` (and
`WholeClockFreezeTest` the same deadline). That second above the real 3600.000 s is the rounding of an integer-second header;
announcing less would make the client retry too early. Never relax it to `<= 3601`.
