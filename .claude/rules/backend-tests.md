---
paths:
  - "backend/tests/**"
  - "backend/phpunit.dist.xml"
  - "backend/symfony.lock"
  - ".github/workflows/pipeline.yml"
  - "docker/php/**"
---

# Backend — tests

> Moved verbatim from `.claude/rules/backend-outillage.md` (#651), sections « Exception traces under PHPUnit (#420) », « Concurrency tests with child processes (#403) », « Reading a repository file from a test (#629) » and « Freezing the clock for a `Retry-After` (#743) ». The index of every rule is at the top of `.claude/CLAUDE.md`.

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
`RevocationEndpointConcurrencyTest`, `RevocationEndpointPurgeDeadlockTest`, `McpConnectionPurgeLockOrderTest`,
`OwnMcpConnectionRevocationConcurrencyTest`, `AccountWideRevocationDeadlockTest`,
`McpClientRemovalDeadlockTest`)
goes through **one harness, written once**. `tests/Support/ChildPhpProcess::inTestEnvironment()` builds the
child's `Process`: its command line (above) and a copy of the test's `$_SERVER` (`phpunit.dist.xml`'s `<server>`
values) — a variable not to pass on is excluded there, for every child at once —, overlaid by each `<env force="true">`
of the loaded configuration (`@internal` `Registry`) with its `$_ENV` value, as `<env force>` never rewrites
`$_SERVER` (the dev container's `APP_PUBLIC_API_BASE_URL`, #647). An `<env>` without `force` is not overlaid:
PHPUnit keeps the shell's value there (a local `phpunit.xml`). Every `<server force>` of `phpunit.dist.xml` has
an `<env force>` of the same value (#748), Symfony reading `$_ENV` first: `ForcedTestValuesWinOverTheShellTest`
reruns `ForcedTestValuesProbeTest` (names read from the file) under a shell exporting them, by fingerprint; any
other `<server>` or `<env>` name is in the probe's allow-list, with its exact form.
`ChildPhpProcess::SETUP_FAILED` (2) is the exit code of a child that could not set itself up.
`tests/Support/ProcessBarrier` is the file barrier, both sides: the test `hold()`s it (exclusive `flock`),
the child calls `awaitInChild($path, ?$sessionPid)` once everything slow is done (writes `ready [<pid>]`,
then blocks on a shared lock), the test waits with `waitUntilReady()` — which then sleeps
`SETTLE_MICROSECONDS` so that every child has reached its `flock()` — or `sessionPidOf()` (the PostgreSQL
session it then watches in `pg_stat_activity`), and `release()`s; an unreferenced barrier releases itself.
Children reach private services through `tests/Support/TestServiceLocator` (`limiter.<name>` included; it
replaced `RateLimiterFactoryLocator`). `tests/Shared/ChildProcessHarnessTest.php` exercises the protocol on
real processes and fails, in `tests/`, on any `flock()` outside `ProcessBarrier`, any `foreach ($_SERVER`
outside `ChildPhpProcess`, and any `new Process(ChildPhpProcess::command(` outside its `OWN_ENVIRONMENT`
allow-list (the Dotenv test isolating a pod's environment, the probe's shell rerun). Not seen: `proc_open()`
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
measured. **The heap is built by the test, never inherited** (#650, R4: it failed 4 runs in 14 of `tests/Mcp` when the
earlier tests had fragmented `mcp_connection` and the rewritten version of the lower row landed before the higher):
`TestDatabase::assertSafeToWipe()` then `TRUNCATE mcp_connection CASCADE` first (a new heap: slots 1, 2, 3, the rewritten version at 4; the guard — see « A test that empties a table… » below — because `CASCADE` empties everything that references the table, on a connection opened outside the kernel), then `ANALYZE` (with
empty statistics the planner chooses an index scan, which reads the ids' order), then a **second precondition** that
says what the first one means: the search for the pair's others **without** an `ORDER BY` returns the higher before
the lower. Mutation (`ORDER BY` out of `revokeWhere()`): red, `55P03`, this test alone. 20 runs of `tests/Mcp`: 20 green. **Only the heap order is contradicted**: the rewrite is a HOT update (no indexed column changes), the index
entry still points at the root of its HOT chain, so an index scan would read the ids' order — the test discriminates
under today's plan, a sequential scan of a few rows, only, and a plan by index would leave it green without the
`ORDER BY`. Mutation, the `ORDER BY c.id` out of `revokeWhere()`'s sub-query — measured, `55P03` on the `NOWAIT`, the
other three tests of the class green (measured again under `FOR NO KEY UPDATE`, #726 T6).

**The person's revocation crossing the two other writers of a connection is pinned too** (#650, task 4:
`OwnMcpConnectionRevocationConcurrencyTest`; child `Fixtures/revoke-own-mcp-connection.php`, which calls the real
`OwnMcpConnectionsInterface::revoke()` and prints `done` or `not-found`, then `audit <n>` — the `mcp-connection-revoked`
lines of the child's own `security_audit` handler —, or `failed <SQLSTATE>`; its two arguments are row UUIDs, no
secret). Like the revocation endpoint's, the window is opened by a lock the test holds, in a session of its own
(`OpensProbeConnection`): the connection `FOR UPDATE` plus the write of the other worker, then
`SessionLockWait::untilBlocked()` (a child that finishes without waiting raced nothing), then the COMMIT. Two tests:
a refresh (it writes `last_used_at`) — the connection ends revoked `person`, the refresh's `last_used_at` intact, one
audit line —, and the purge's marking (it revokes `expired`) — the child answers `done` (the connection is the
caller's), writes and audits nothing, the cause stays `expired` and so does the instant. **Measured**: `SKIP LOCKED`
in the sub-query of `McpConnectionRepository::revokeWhere()` turns **both** red at the wait (the child no longer
blocks: it skips the held row, revokes nothing and answers `done`, `audit 0`); removing **both** `revoked_at IS NULL`
turns the second red (cause rewritten `person`, a second audit line) and leaves the first green. **Each of the two
conditions alone is redundant** for this crossing (either one removed, both tests stay green: PostgreSQL re-checks
the sub-query's quals on the locked row and the `UPDATE`'s on the target row), and so is the sub-query's `FOR NO KEY
UPDATE` (removed, green: the `UPDATE` waits on the row by itself) — its job is the id order, pinned by
`TokenEndpointConcurrencyTest::testASupersessionLocksTheOtherConnectionsInIdOrder`.

**A deadlock that is accepted is pinned too, and so is the retry that absorbs half of it** (#650, R4, F1; S3 of the
second review: `AccountWideRevocationDeadlockTest`; children `Fixtures/change-account-password.php`, which runs the real
`CpgUserAdministrator::changePassword()`, and `present-at-token-endpoint.php`; neither prints a secret, the code passes on
the child's stdin and the password is drawn in the child and never written). The test holds C1 `FOR UPDATE` in a session
of its own; the revocation starts and blocks on C1 after taking the account; the exchange starts, takes C2 and blocks on
C1 **behind** the revocation in the queue; C1 is released and the revocation, first in the queue, takes it and wants C2 —
held by the exchange, which waits for C1: `40P01`. Each child starts once the other is **blocked on a lock**
(`SessionLockWait::untilBlocked()`). **The victim is chosen, not left to the timers** (measured: 9 runs in 12 aborted the
exchange, 3 the revocation): the child meant to lose starts with `deadlock_timeout = 100ms`, the other keeps the default
second, so it is the first to look for the cycle. One test per victim. **Since S3 the revocation victim is retried once**
(`DeadlockRetryInterface`, `.claude/rules/security-user.md`): the revocation child no longer fails, it prints
`retried <n>` (the deadlocks the single retry absorbed, read on a probe of every channel), and the test proves the cycle
happened by that line; the exchange victim keeps its `failed 40P01` (league, out of scope, not retried). The final state
of each is asserted — revocation victim: the exchange won the cycle (C1 `superseded`, already inactive, C2
`password-changed`), the password and `credentials_version` changed, the version advanced **once**, **one** audit line of
connection (C2's) and **one** `password-changed`; exchange victim: no token for C2, its code still exchangeable
(`code_exchanged_at` null), the revocation succeeded first time (C1 and C2 `password-changed`, two audit lines, no retry).
Twelve runs in a row green. **Needs a role that may `SET deadlock_timeout`** (superuser, or the `SET` privilege on the
parameter) — true of the dev database and of CI. Mutations: `SKIP LOCKED` in `revokeWhere()` (red at the wait, no cycle),
`ORDER BY c.id DESC` (red: the cycle is gone). The limit itself: `.claude/rules/mcp-oauth-revocation-personne.md`, accepted
limit 4.

`McpClientRemovalDeadlockTest` (#652, T13) pins the same way the retry of `McpClientAdministrator::remove()`: same harness, child
`Fixtures/remove-mcp-client.php` (which assembles the administrator as `McpClientAdministratorTest` does, the container wires it
nowhere yet). `remove()` locks the client then C1, C2 by id; the exchange locks C2 then wants C1 — one case per victim. Proof
that the test bites: with a `DeadlockRetryInterface` that catches nothing, the victim case fails on `failed 40P01`.

**A test that empties a table checks the database first** (#650, F-D of the second review): `tests/Support/TestDatabase::
assertSafeToWipe(Connection)` reads `current_database()` **on the server** (not an environment variable the connection
might not have followed) and requires it to end exactly in `_test` plus ParaTest's `TEST_TOKEN` when there is one (something
must precede the suffix: `_test` alone is no derived name), else the test fails naming the database, **having emptied
nothing**. **Mandatory before any `TRUNCATE`, or `DELETE FROM <table>` without `WHERE`, on a connection opened outside the
kernel** (`OpensProbeConnection`, `DriverManager`): such a connection copies parameters by hand, and a wrong copy, another
test's database or a forgotten `APP_ENV` would point it at data that is not disposable. It is applied to `SessionBeforePasswordChangeTest` and
to the cleanups of `tests/Mcp` (sixteen files, `TokenEndpointConcurrencyTest` — its `TRUNCATE … CASCADE` — and the
`DrivesTheOAuthFlow` trait among them: the repository and snapshot tests, the controllers, the commands, the league
adapters, `McpEndpointTest`…) — also where the connection is the kernel's; `TestDatabaseGuardTest` pins the
recogniser (with and without a token, a development name refused) and that the kernel's connection is on the test database.
**Decision of the author: not generalised** to the ~110 other files of `tests/` that run a `DELETE FROM` without `WHERE`: they
all go through the **kernel's connection**, whose database name `doctrine.yaml` already suffixes (`dbname_suffix: '_test'`).
Extend it the day a test opens a connection otherwise. **A migration test whose column a later migration dropped recreates
the earlier state inside its own rolled-back transaction** (`Version20261028160000Test` adds `credentials_changed_at` back, as
`Version20261028200000::down()` does, then replays its own `down()`/`up()`): the migrated test schema no longer has the column.

Every
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
`symfony.lock` carries an entry for `symfony/phpunit-bridge` (recipe 7.3) on purpose, and `phpunit.dist.xml` does **not** carry its
`SymfonyExtension`: without the entry, any `composer update` or `composer require` replays the recipe, which adds the extension with
`clock-mock-namespaces=App` and `dns-mock-namespaces=App`. ClockMock would then mock `App\*` and falsify `FailedLoginTimingEqualizer`'s
duration (measured: the update rewrites `phpunit.dist.xml` without the entry, and leaves it alone with it). If a bridge update ever
brings a newer recipe, refuse its `phpunit.dist.xml` hunk.

Under the frozen `.999` instant a `Retry-After` is an **exact** value, asserted as such: a one-hour `sliding_window` closes
3600 s later (12:00:00.999 -> 13:00:00.999), symfony/rate-limiter >= 8.1.10 rounds the deadline up to 13:00:01, and
`RetryAfterListener` rounds the remaining 3600.001 s **up** to whole seconds (in integer microseconds, issue #743: truncating
two timestamps announced up to a second too early, 899 for 899.989 s left), so `AnswerControllerTest` reads `3601`
(`WholeClockFreezeTest` derives the same deadline). A login refusal reads `900`: its deadline is `now + 15 min` exactly.
That second above the real delay is the rounding of an integer-second header; announcing less would make the client retry too
early. Never relax it to `<= 3601`.
