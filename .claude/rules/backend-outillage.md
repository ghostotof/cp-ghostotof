---
paths:
  - "backend/**"
  - ".github/workflows/**"
  - ".github/dependabot.yml"
  - "docker/php/**"
---

# Backend — quality tooling and logging

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Project state ». The index of every rule is at the top of `.claude/CLAUDE.md`.

## Where the rest went (#651)

This rule outgrew the 50 000-byte ceiling and was split by sub-domain; what concerns writing a backend test moved
**verbatim** to `.claude/rules/backend-tests.md`:

- « Exception traces under PHPUnit (#420) », « Concurrency tests with child processes (#403) », « Reading a
  repository file from a test (#629) », « Freezing the clock for a `Retry-After` (#743) » → `backend-tests.md`.

This file keeps the quality tooling, Monolog and `tools/logs-select.sh`, the target PHP version, and API Platform's
version and deprecation guard.

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
its traces carry no call arguments (see `.claude/rules/backend-tests.md`, issue #420).
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
`SecurityChannelAccountRedactor` (see `.claude/rules/security-authentication-audit.md`), and
`AccountIdentifierReachesNoLogTest` reads every channel at every level, preprod's `debug` included. A
third channel follows the same rule, `ai_usage` (spec 0005): the career assistant's and, since issue
#356, the translator's token usage, duration and quota refusals, never any content, on its own `info`
handler — as an `info` of the default channel it never
left a production pod (`AiUsageLogChannelTest` pins the prod config). Since #643 two more, both kept out of
`main` and `console` (`McpLogChannelsTest`): `mcp`, the MCP SDK's own channel, capped at `warning` and bounded
by `SdkChannelRedactor`, and `mcp_usage`, the MCP call log, on an `info` handler (see `.claude/rules/mcp-journaux.md`).
`cache` stays in `main` on purpose (`!cache` would silence every pool's failures): `SessionKeyCacheChannelRedactor`, a
processor of that channel, withholds the MCP session id a key of the sessions pool carries (#731, same rule). The
`default:` processor takes a *parameter name*, hence `app.log_level` in `services.yaml` — `default:warning:`
would look for a parameter called `warning` and fail at compile time. No duplication with
`error_log = /proc/self/fd/2` (`php.prod.ini`) either: Monolog writes to fd 2 itself, and `error_log` receives the
engine's own errors plus one plain-text line of our own, the last resort of `Mcp/Connection/Application/FailSafeAudit`
(the main logger failed while reporting a failed audit write: a constant message and the logger failure's class).
No Monolog record is ever taken up by `error_log` — that line is written by the `error_log()` function, not by a
handler. File descriptor 2 also carries the lines of the FPM master itself (`NOTICE: fpm is running`, `ready to
handle connections`, `exiting, bye-bye!`, and the `WARNING`s it raises — its `[global] error_log` of `www.prod.conf`
is that same descriptor) and the pool's access-log lines (`access.log` of the upstream image's `php-fpm.d/docker.conf`,
read in dev; the production image inherits the same file), none of them JSON. `tools/logs-select.sh` (#651) is the only copy of the command that reads them:
`kubectl logs deploy/backend -c php-fpm | tools/logs-select.sh '.channel == "security_audit"'` (audit events leave on
`security_audit`; `security` is the framework's own channel, `.channel | IN("security", "security_audit")` reads both).
It drops only the known noise (the pool's access line **in 2xx/3xx/4xx except 403**: every production 401/404 would drown
the events looked for, and those 401s, 404s and other 4xx are **lost** to this script — read the raw logs to find them;
the **403 stays visible** as `unparsed`, because only `backofficeAccessDenied`, `csrfRejected` and the MCP refusals have an
audit record, a base-tier token trying `GET /api/cv` leaves none, and a 403 that is already audited comes out twice, which is
accepted; a 5xx access line stays too, it carries the status PHP set — a 502/504 is written by nginx, not FPM, and a killed
worker leaves a master `WARNING`, not an access line —, the master's **routine** `NOTICE`s — start-up and `child N started` —,
the empty line, a line reduced to a kubectl prefix) and renders everything
else as `{"unparsed": "<whole line>"}`: a 403 or 5xx access line, a line of blanks only (not empty: not known noise), any other `NOTICE` (`Terminating`, `exiting, bye-bye!`, `child N exited`), a `WARNING`, the last-resort trace of
`FailSafeAudit`, a fatal error, the pieces of a line cut by `log_limit` (a `fromjson? // empty` would have dropped them
silently). It strips the prefixes of `kubectl logs --prefix`/`--timestamps` (and the leading blanks of the line) before decoding, wraps the predicate in
`((\n…\n))` and evaluates it with `any`; exit codes: 2 invalid usage, 3 a predicate that does not compile, 4 at least
one line on which the predicate raised (the line is dropped, stderr carries only its number and a fixed label, never a
value of the record; the stream goes on, each label comes **as it happens** and stays visible after a Ctrl-C or a `| head`,
a final total line closes a complete run; 4 wins over 5, never over a signal), 5 any other non-zero exit of jq
(a fixed label, never jq's text), 128 and more a signal passed through unchanged and silent (141 for `| head`).
jq's stderr goes through an awk allow-list filter that relays only the fixed labels (and counts them: no temporary file,
nothing grows during a `-f`), plus the compile error of code 3 (it quotes only the program); the rest is never relayed. Its predicate is a trusted jq filter, never
external data, its reasons are in its header (the only copy), and `tools/tests/logs-select.test.sh` covers it. The
`app:mcp:purge` job (CronJob `messenger-purge-cronjob.yaml`, container `purge`) writes its records through the same
handlers to its container's stderr, but its stdout carries the three chained console commands' own output (`[OK] …`),
which also comes out as `unparsed`. Read the **Job's pods**, not `deploy/backend -c php-fpm`: `kubectl logs -l
job-name=<name> -c purge --prefix --tail=-1 | tools/logs-select.sh …` (with a selector `kubectl logs` shows only the
last 10 lines per pod unless `--tail=-1` is given; `restartPolicy: OnFailure` re-runs the container **in the
same pod**, a replaced pod — lost node, eviction — is another pod of the Job: the selector reads them all, up to
`backoffLimit` + 1). The previous attempt of a restarted container is read on a **named** pod, `kubectl logs
pod/<pod> -c purge --previous` (the last restart only), the pod being found with `kubectl get pods -l job-name=<name>`
(RESTARTS column): `--previous` with `-l` fails as a whole as soon as one pod of the Job never restarted.
A line longer than php-fpm's `log_limit`
is cut into pieces that are no longer JSON: `www.prod.conf` and `www.dev.conf` set `log_limit = 8192` in `[global]`
(php-fpm's own default is 1024; the upstream image's `docker.conf` already sets 8192, repeated so as not to depend on
it; the dev file only takes effect at the next `make build`, it is copied into the image). The events are emitted by `SecurityAuditLogger` (see Phase 3 of
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
never looked at the 4.x releases. **Remove that `ignore` when the 5.0 migration is done** (#735). The same block
ignores **every** update of `symfony/ai-*`, `symfony/mcp-*` and `mcp/*` (#731, the pin guard's three patterns), not
only their major: they are 0.x and pinned exact (ADR 0004 D1, ADR 0006 D7), a bump is read in the upstream changelogs,
and a breaking 0.x release counts as a « minor » that `backend-minor-patch` would batch. It should remove version updates
only, not security PRs: GitHub's « Dependabot options reference » (<https://docs.github.com/en/code-security/dependabot/working-with-dependabot/dependabot-options-reference>) marks `ignore` as acting on security
updates too « except where `target-branch` is used » (section `ignore`, `#ignore--`), and its `target-branch` section
(`#target-branch-`) says a block's options then « no longer apply to security updates », which always target the
default branch. This block carries `target-branch: develop`, so its `ignore` filters no security PR, whether those stay
off (today, `tools/github-settings.sh`, step h) or are turned back on. Read, not measured: check it on the repository before turning security PRs back on. The alerts stay on, and
`composer audit --no-dev` already blocks CI (`test-backend`). New versions are read by hand: `composer outdated` takes one argument, a
name or a pattern (`'symfony/ai-*'`, measured), hence `composer outdated --direct | grep -E '^(symfony/(ai|mcp)-|mcp/)'`
for the three families in one command; that watch, and re-evaluating the `ignore` at the Symfony 8.2 bump, are
tracked in #764.
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
`composer update <those packages>`. It and the 0.x pin guard (`SymfonyAiAndMcpPinsMatchTheLockTest`, rule in
`tests/Support/ExactlyPinnedFamilies`) read both files through `tests/Support/ComposerFiles` (#731: `packages` then
`packages-dev`, the version as the lock writes it, `v` included), each keeping its tolerance (`MalformedLockEntry`):
this one ignores a malformed entry, the pin guard fails on it (`ComposerFilesTest`). The collector, the lock family and the pass each have their own test
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
