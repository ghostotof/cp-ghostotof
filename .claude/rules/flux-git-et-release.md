---
paths:
  - "RELEASE_NOTES.md"
  - "tools/next-version.sh"
  - ".claude/specs/**"
---

# Git flow, release by hand, task plans and spec archiving

> Moved verbatim from `.claude/CLAUDE.md` (2026-11-10, lighter per-session base), sections « Goals › 10 »,
> « Deployment invariants » (the release bullets: what holds from the shell) and « Agent skills › Task plans
> (`tasks/`) and spec archiving ». `CLAUDE.md` keeps a summary of each, and says to read this file before
> cutting a branch other than `feature/*` or `fix/*` from `develop`, before a release and before closing a
> spec. The pipeline's internals are in `.claude/rules/release.md`. The index of every rule is at the top of
> `.claude/CLAUDE.md`.

## Git flow (Goal #10)

10. The modifications must follow the project's git flow (spec 0006, archived under
    `.claude/specs/archive/2026-09-16-spec-0006-flux-de-release/`): `main` is exactly what runs in
    production and only advances by the merge of a `release/<X.Y.Z>` branch; `develop` is the
    integration branch; `feature/*`, `fix/*` and `hotfix/*` are cut from an up-to-date `develop`
    and return to it by PR (a hotfix differs only in that its release is cut right away — if
    `develop` then carries unfinished features, the release branch is cut from `main` with the
    hotfix cherry-picked, the one exception to "a release starts from `develop`"); a `release/*`
    branch is cut from `develop`, named after the version `tools/next-version.sh` computes, and
    ends in `main`. Never push to `main` or `develop` directly and never tag by hand: GitHub
    rulesets refuse both, and the pipeline does the tagging (see "Release from the shell" below).
    A spec's tasks stack on the spec's own feature branch, `develop` receives the feature once
    (see "Task plans and spec archiving" below).

## Release from the shell

- **A release is a branch, the merge is the stop, the tag is a consequence** (spec 0006). The pipeline's
  internals — its three phases, immutable images, `deploy-prod`'s guards, `finalize-release`'s six steps,
  the `release-bot` deploy key, the rules for editing a workflow — live in `.claude/rules/release.md`. What
  holds from the shell:
  - **The version is computed, never chosen** — `tools/next-version.sh`. The branch is named
    `release/<that version>`, `RELEASE_NOTES.md` at the repo root starts with `# v<that version> — <title>`
    (em dash, strict), and `release-version` fails naming the three values if they disagree: rename the
    branch, don't touch the CI. `git fetch --tags` first, or the script may answer a version already shipped.
  - **`main` only moves by the merge-commit of the release PR** (squash and rebase are disabled repo-wide),
    once `smoke-test-preprod`, `audit-preprod` and `postgres-hba-preprod` are green on its head. Every push
    on `release/*` redeploys the single preprod: one release at a time.
  - **Never write `[skip ci]`, `[ci skip]`, `[no ci]`, `[skip actions]` or `[actions skip]` in a commit or
    merge message** — GitHub silently skips the push's run (it happened to the very commit that introduced
    `finalize-release`). Only `finalize-release`'s copy commit carries it.
  - **Never a `main` → `develop` PR** (issue #293): when `finalize-release`'s summary asks for a sync, run its
    `fix/sync-main-vX.Y.Z` commands as given.
  - **What to do by hand, in order**: `git switch develop && git pull && git fetch --tags`;
    `git switch -c release/$(tools/next-version.sh)`; write `RELEASE_NOTES.md`; open the PR to
    `main` as a draft; iterate until the run is green (a `fix/*` PR targets the release branch);
    mark ready, merge. Nothing else — no `git tag`, no approval click, no `develop` sync unless
    the summary asks for it (then run its `fix/sync-main-…` commands as given). Everything is
    testable offline: `tools/tests/*.test.sh` (run by the
    `tools-tests` job on temporary git repositories, shellcheck at `warning`+, actionlint pinned).

## Task plans (`tasks/`) and spec archiving

The planning skill writes `tasks/plan.md` and `tasks/todo.md` at the repo root. **They live on the
feature branch, for the duration of the feature, and never reach `develop`** (rule set on
2026-09-15): `tasks/` does not exist on `develop` or `main`, and a PR that still carries it is not
ready to merge. Workflow:

1. During the feature, commit `tasks/` on the feature branch as needed (checkpoints, ticked tasks).
   **Every task of a spec lands on the feature branch that carries the spec, never directly on
   `develop`** (rule set on 2026-09-16): a task gets its own branch, stacked on the previous
   task's, and its PR targets the previous task's branch (the first one targets the spec's
   branch); when a task merges, the next PR is retargeted **before** its base is deleted (see
   the stacked-PR trap in memory). `develop` receives the feature **once**, through the PR the
   spec's branch opened at the start, which becomes the closing PR of step 2.
2. In the merge into `develop` that **fully closes** the feature, build the archive folder
   `.claude/specs/archive/<YYYY-MM-DD>-<slug>/` (closing date, one folder per feature — see the
   `README.md` there for the existing ones) with, **since 2026-09-16** (issue #192):
   - the spec itself, `git mv`ed from `.claude/specs/<NNNN>-<slug>.md` (so `.claude/specs/` only
     lists specs still open — a "livrée" status line is no longer what tells them apart), and
   - the **whole `tasks/` directory**, `git mv`ed as-is to a `tasks/` subfolder (not just
     `plan.md`/`todo.md`: whatever else the feature parked there, checkpoints, notes, goes with it),
   which removes `tasks/` from the tree in that same PR. Update the README's table there, and fix
   the links that pointed at the spec's old path (`CLAUDE.md`, ADRs, other specs — grep for it).
3. A plan whose feature is still open is not archived; a feature that spans several PRs keeps
   `tasks/` on its branches until the last one. A feature with no spec (an audit remediation, an
   ADR-only change) archives `tasks/` alone.

The archived files are frozen: they describe the plan as it stood at closing, ticked boxes,
checkpoints and open questions included. The five plans written before the rule existed were
extracted from git history and archived in one go (PR #189), as flat `plan.md`/`todo.md` files —
that flat layout is left as is, not migrated — and their specs (0001 to 0004) joined them on
2026-09-26, so `.claude/specs/` really does list only the open specs (spec 0001's plan came from a
since-deleted `.claude/plans/`, the location that predated `tasks/`). A "livrée" status line inside
a spec is never what tells open from closed; its location is.
