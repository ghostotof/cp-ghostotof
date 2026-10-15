---
paths:
  - ".github/workflows/**"
  - "RELEASE_NOTES.md"
  - "tools/next-version.sh"
  - "tools/verify-release-merge.sh"
  - "tools/finalize-release.sh"
  - "tools/check-release-gates.sh"
  - "tools/check-workflow-timeouts.sh"
  - "tools/github-settings.sh"
---

# Release flow and pipeline internals

> Moved verbatim from `.claude/CLAUDE.md` (#602), section « Deployment invariants ». `CLAUDE.md` keeps what
> holds from the shell (computed version, merge-commit, no `[skip ci]`, the steps by hand). The index of
> every rule is at the top of `.claude/CLAUDE.md`.

- **A release is a branch, the merge is the stop, the tag is a consequence** (spec 0006,
  2026-09-16, replacing the "tag = trigger" flow that cost v0.7.0 a stale image, v0.7.1 its
  Markdown headings and v0.11.0–v0.13.1 a literal `#` in their titles). The pipeline has three
  phases gated by the branch: quality on every push of `main`, `develop`, `feature/**`, `fix/**`,
  `hotfix/**`, `release/**`, `dependabot/**` (no `pull_request` event: the push's checks show on
  the PR, listening to both doubled every run); on `release/**` only, `release-version` →
  `build-images` → `deploy-preprod` → smoke tests + audit (→ `rollback-preprod`), and **the run
  stops there**; on `main` only, `deploy-prod` → `finalize-release` + `audit-prod`. Rules that
  hold, each with its guard:
  - **The version is computed, never chosen** — `tools/next-version.sh` (Conventional Commits
    since the last `v*` tag reachable from `origin/main`: `!`/`BREAKING CHANGE` → major, demoted
    to minor while in `0.x`; `feat` → minor; else patch; nothing conventional → "rien à livrer").
    The branch is named `release/<that version>`, `RELEASE_NOTES.md` at the repo root starts with
    `# v<that version> — <title>` (em dash, strict), and `release-version` fails naming the three
    values if they disagree: rename the branch, don't touch the CI. `git fetch --tags` first, or
    the script may answer a version already shipped.
  - **Images are `<version>-<7-char sha>` and `-preprod`, immutable.** `build-images` inspects the
    four manifests first: all present → green no-op (re-run), none → build + push, some → fail.
    That is what makes the old "reused tag serves a stale image" incident impossible;
    `k8s/base/migrate-job.yaml` keeps `imagePullPolicy: Always` anyway. `preprod` is still built
    `FROM production`.
  - **Every push on `release/*` redeploys preprod** (migration before rollout, seed, smoke tests,
    audit, rollback on failure, unchanged) and produces no pending approval. The preprod is
    unique, so one release at a time; a second `release/*` branch is warned about, not blocked.
  - **`main` only moves by the merge-commit of the release PR** (rulesets: PR required, merge
    method `merge` only — squash/rebase are disabled repo-wide because `deploy-prod` finds the
    release as `HEAD^2` —, `smoke-test-preprod` + `audit-preprod` + `postgres-hba-preprod` required
    on the head SHA, kept in step with `pipeline.yml` by `tools/check-release-gates.sh`, issue #600 —
    no deletion, no force-push). `deploy-prod` never builds: three guards **before** the kubeconfig
    is even written — HEAD is a merge and `HEAD^2`'s `RELEASE_NOTES.md` gives the version
    (`tools/verify-release-merge.sh`), the four images exist on GHCR, a `success` run of the
    workflow exists for `release/<version>` at that exact commit (`gh run list --commit` wants
    the **full** SHA; the run survives the branch's deletion). The `production` environment keeps
    its secrets but no longer requires a reviewer: the merge is the stop.
  - **`finalize-release` runs after a green prod**, six idempotent steps in
    `tools/finalize-release.sh` (a re-run changes nothing): annotated tag `vX.Y.Z` on `HEAD^2`
    with `RELEASE_NOTES.md` read *at that commit* as the annotation (`--cleanup=verbatim`, so the
    Markdown headings survive; the first line minus its `#` becomes the release title, the rest
    its body); push of the tag; GitHub Release; copy of the notes to `docs/releases/vX.Y.Z.md`
    committed on `main` with `[skip ci]`, the root file staying in place for the next release;
    deletion of `release/X.Y.Z`; `git push main:develop`, whose non-fast-forward refusal is the
    intended clean stop (green, the summary prints the commands to report `main` through a
    `fix/sync-main-vX.Y.Z` branch cut from `develop`, issue #293 — **never a `main` → `develop`
    PR**: its head is the `[skip ci]` copy commit, no required check ever runs on that SHA and the
    PR stays `BLOCKED` forever; the branch must keep a prefix the pipeline listens to; once that
    PR is merged, a re-run says "déjà reporté" instead of printing the commands again). Its pushes
    use the **`release-bot` deploy key** (secret `RELEASE_DEPLOY_KEY`, host key pinned from `gh api
    meta`, checkout with `persist-credentials: false`), the only bypass actor of the three
    rulesets (`main`, `develop`, tags `v*` — a personal repo refuses the `github-actions` app as
    a bypass actor). Deploy-key pushes **do** trigger workflows, unlike `GITHUB_TOKEN`'s, hence
    the `[skip ci]` on the copy commit. Rotation and the whole D9 setup: `tools/github-settings.sh`
    (idempotent) and its README.
  - **Never write `[skip ci]`, `[ci skip]`, `[no ci]`, `[skip actions]` or `[actions skip]` in a
    commit or merge message** — GitHub silently skips the push's run (it happened to the very
    commit that introduced `finalize-release`). Only the copy commit above carries it.
  - **Pipeline `run:` steps are `bash -e` without `pipefail`**: never `cmd | tee >> $GITHUB_OUTPUT`,
    a failing `cmd` goes unnoticed (write to a file, then append). The runner has no git
    identity: scripts that tag or commit pass `-c user.name`/`user.email`. Both learned in T5/T8.
  - **Every job declares `timeout-minutes`, and so does every step calling `apt-get`** (issue
    #285): GitHub's default is 360 minutes, and a frozen apt mirror held v0.18.3's `build-images`
    for over 30 minutes without failing. Size a job at 3–10× its usual duration, a deploy job
    above the sum of its internal `kubectl` waits — a timeout that cuts `deploy-prod` mid-apply
    is worse than a slow run. `tools/check-workflow-timeouts.sh` (run by `tools-tests`) turns
    red on a job or apt step added without one.
  - **What to do by hand, in order**: `git switch develop && git pull && git fetch --tags`;
    `git switch -c release/$(tools/next-version.sh)`; write `RELEASE_NOTES.md`; open the PR to
    `main` as a draft; iterate until the run is green (a `fix/*` PR targets the release branch);
    mark ready, merge. Nothing else — no `git tag`, no approval click, no `develop` sync unless
    the summary asks for it (then run its `fix/sync-main-…` commands as given). Everything is
    testable offline: `tools/tests/*.test.sh` (run by the
    `tools-tests` job on temporary git repositories, shellcheck at `warning`+, actionlint pinned).
