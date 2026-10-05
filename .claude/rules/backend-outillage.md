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
`.claude/rules/portfolio-contenus.md`) — and follows a DDD structure under
`src/<BoundedContext>/` — the generic `ApiResource/`, `Controller/`, `Entity/`, `Repository/` directories left
over from the skeleton have been deleted (they were empty placeholders, no code ever lived there); don't
recreate them, new code always goes under its bounded context. PHPUnit is configured (`phpunit.dist.xml`,
`tests/`, mirrors the `src/` bounded-context tree) and runnable via `php bin/phpunit` inside `make sh`.
**PHPStan** is configured (`backend/phpstan.dist.neon`, level `max` + `phpstan-strict-rules` +
`phpstan-symfony`/`-doctrine`/`-phpunit`/`-deprecation-rules` via `extension-installer`): run it with
`composer phpstan` inside `make sh` (warms the dev container first), CI job `phpstan-backend` (blocking,
`build-images` needs it). **No baseline** — level `max` + strict-rules is green across `src/` AND `tests/`, keep
it that way. Two scoped `ignoreErrors` in `phpstan.dist.neon` cover functional-test JSON payloads (a decoded
response body is `mixed`; the PHPUnit assertions are the shape check, not PHPStan) — `offsetAccess`/`foreach`
and `argument.type … mixed given`, `path: tests/` only. `src/Kernel.php` is `excludePaths`-excluded
(`getAllowedEnvs()` false-positive `method.unused`). The `$uriVariables` mixed-access pattern is solved by the
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
after it is exactly the trace worth keeping, and buffering would discard it. Everything else goes to
the `main` handler at `%env(default:app.log_level:LOG_LEVEL)%` (`LOG_LEVEL=warning` in `backend/.env`,
`debug` in the preprod image), which excludes those two channels so an event is emitted **once**. A
third channel follows the same rule, `ai_usage` (spec 0005): the career assistant's token usage and
duration, never any content, on its own `info` handler — as an `info` of the default channel it never
left a production pod (`AiUsageLogChannelTest` pins the prod config). The
`default:` processor takes a *parameter name*, hence `app.log_level` in `services.yaml` — `default:warning:`
would look for a parameter called `warning` and fail at compile time. No duplication with
`error_log = /proc/self/fd/2` (`php.prod.ini`) either: Monolog writes to fd 2 itself, `error_log` only
ever receives the engine's own errors. The events are emitted by `SecurityAuditLogger` (see Phase 3 of
the remediation plan). The
frontend has moved past the default scaffold: it follows a layered clean architecture (see `.claude/rules/frontend.md`) and has
Vitest configured with `npm test`. A `ROLE_SUPER`-gated backoffice (`/admin` on the frontend, `/api/backoffice/*`
on the backend) lets an authenticated super-admin manage all of the above content plus user accounts — see
`.claude/rules/backoffice-api.md` and `.claude/rules/frontend-backoffice.md`.

