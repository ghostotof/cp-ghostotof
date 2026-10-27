---
paths:
  - "backend/**"
  - ".github/workflows/**"
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
`InviteUsernameConcurrencyTest`, `AccountAdministrationConcurrencyTest`, `TokenEndpointConcurrencyTest`,
`RevocationEndpointConcurrencyTest`, `RevocationEndpointPurgeDeadlockTest`, `McpConnectionPurgeLockOrderTest`)
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
**That wait is written once too, in `tests/Support/SessionLockWait`** (lot C of the #649 reviews): built
`beside($connection, $child, $childSessionPid, $timeoutSeconds)` (its own probe connection, closed when the wait
returns, failure included), it offers `untilDoneOrBlocked()` — `FlushInterleaving`'s — and `untilBlocked()`, for
the lock-order tests whose operation goes through **no ORM flush** (the revocation endpoint, the purge: native SQL
all the way, so `FlushInterleaving` cannot interleave them): the window is opened by a row the test holds `FOR
UPDATE`, the child **must** block on it, and a child that finishes without waiting fails the wait at once — it
raced nothing (`RevocationEndpointPurgeDeadlockTest`, `McpConnectionPurgeLockOrderTest`). Both failure messages
quote the child's command line and output: a child reads any secret on stdin and writes none.
`tests/Shared/FlushInterleavingTest.php` exercises the interleaving on a real child (blocked on an advisory lock
the test holds, finished, neither); `ChildProcessHarnessTest` also refuses, in `tests/`, any class constant
`::preFlush` / `::onFlush` / `::postFlush` (whatever the class is called: `Events`, an alias, inside an
attribute) outside `FlushInterleaving` and `FlushInterleavingTest`, and any string literal naming
`pg_stat_activity` outside its **two owners**: `SessionLockWait` and the guard itself (it names the view to look
for it) — broad on purpose: a test with another use joins its pattern's owners with its reason.
Not seen: a listener armed on the event's name written as a string (`'preFlush'`), a view name built by
concatenation. The wait returns once the child has finished **whatever its exit code**: each caller checks
it, and names the refusal it expects or denounces.
**Proving a concurrency test still fails without its fix** takes the right mutation, measured in #510
before and after the extraction: remove the **lock and keep its transaction** — `FOR UPDATE` out of
`PostgresAccountRowLock` (#435 and #458 red on their token counts), the `pg_advisory_xact_lock` call out
of `PostgresUsernameAllocationLock` (#364: the parent's invitation fails on the username taken meanwhile)
or `PostgresAdvisoryOrderScopeLock` (#389) — since #512, the statement inside the adapter's `acquire`
closure, the transaction being `PostgresLockedTransaction`'s (measured again after the extraction) — and
`forUpdate: false` in `McpConnectionRepository::lockForUpdate()` (#649, task 5: the lock out, the transaction
and the re-read under `HINT_REFRESH` kept), which turns both tests of `TokenEndpointConcurrencyTest` red on
the child's `issued 200`, then on the connection left active and on the token rows (three access tokens and
three refresh tokens after two simultaneous refreshes of one token, two and two after two exchanges of one
code, instead of two and two, one and one); every sequential test of `Mcp/Connection` stays green under it.
That test's child (`tests/Mcp/Connection/Fixtures/present-at-token-endpoint.php`) presents the form body to
`TokenEndpointInterface` in PSR-7 and **reads it on its standard input** (`Process::setInput()`), never as an
argument: the command line is copied into the harness's failure messages, and the body carries the refresh
token or the code; it prints the refusal's JSON (league's, no token) and only the **status** of an issued
answer. `RevocationEndpointConcurrencyTest` (#649, task 6) is a **lock-order** test, not a lock-presence one: its
child (`Fixtures/revoke-at-revocation-endpoint.php`, same stdin rule, prints `revoked`, the refusal's JSON or
`failed <SQLSTATE>` — never the driver's message) revokes a refresh token while the parent rotates it, released at
the parent's first flush with the connection locked. Its mutation is the **order**: the token rows revoked before
the connection, without `lockForUpdate()` — measured, a deadlock, the child aborted in `40P01`, the parent served
`200` and the connection left active. **The purge's two orders are lock-order tests as well**
(`McpConnectionPurgeLockOrderTest`, lot C of the reviews; child `Fixtures/purge-mcp-connections.php`, which runs the
real `McpConnectionPurgerInterface` with the configured retention, no stdin — the purge reads no secret — and prints
`purged <connections> <codes> <access> <refresh>` or `failed <SQLSTATE>`): the test holds the other side's first row
`FOR UPDATE`, waits for the purge's session to block (`SessionLockWait::untilBlocked()`), then asks for the second row.
Mutation for « connections before tokens »: the `'connections' =>` line of `McpConnectionPurger::purge()` moved after
the token `deleteDead()`s — measured, the child `failed 40P01` on the old revoked connection the test held, the test
holding it then asking for its access token as a cascading account deletion does. Mutation for « A before R »: the
`'accessTokens' =>` and `'refreshTokens' =>` lines swapped — measured, the child `failed 40P01` with the test holding
the connection then its access token (the revocation endpoint's order) and asking for the refresh token. Each mutation
reddens its own test only. A third test pins how long the candidates stay locked (#726: the marking, then the deletions,
in two transactions): holding another connection's expired access token, it asks for a live candidate `FOR UPDATE NOWAIT`
once the purge blocks in its deletions; mutation, the two `run()`s merged back into one — measured, the parent refused
in `55P03`, the two order tests green. Its precondition (`assertALiveCandidate()`: pre-selected, not yet expired) reads
the purge's bounds through `ConnectionLifetimes::preselectionBoundsAt()` (#726, where the purge reads them too), never a
copied margin, so a change of the margin cannot leave the `NOWAIT` free for nothing. **The supersession's order is one too** (#726, final review):
`TokenEndpointConcurrencyTest::testASupersessionLocksTheOtherConnectionsInIdOrder` holds the lower of two other active
connections of the pair `FOR UPDATE`, waits for the exchanging child (`present-at-token-endpoint.php`) to block
(`SessionLockWait::untilBlocked()`), then asks for the higher one `FOR UPDATE NOWAIT`. The heap order must
contradict the ids, or the test proves nothing: the lower one's row is rewritten (a no-op `UPDATE`, its live version
moves after the other in the heap), checked by `ctid` before anything — without that rewrite the unfixed `UPDATE` passes,
measured. **Only the heap order is contradicted**: the rewrite is a HOT update (no indexed column changes), the index
entry still points at the root of its HOT chain, so an index scan would read the ids' order — the test discriminates
under today's plan, a sequential scan of a few rows, only, and a plan by index would leave it green without the
`ORDER BY`. Mutation, the `ORDER BY c.id` out of `revokeWhere()`'s sub-query — measured, `55P03` on the `NOWAIT`, the
other three tests of the class green (measured again under `FOR NO KEY UPDATE`, #726 T6). Every
test that waits on a held lock to prove the bound carries a `statement_timeout = '3s'` safety net, so
that a mutation removing the bound (`boundTheWait()`) fails it in `57014` instead of hanging the suite — a
child session that waits (a console session, so no FPM `lock_timeout`) sets it on itself before the barrier,
as that fixture does. Removing `whileLocked()` from the invitation handler entirely
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
