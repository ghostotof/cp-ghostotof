#!/usr/bin/env bash
#
# verify-release-merge.test.sh
# ---------------------------------------------------------------------------
# Test de tools/verify-release-merge.sh sur un dépôt git temporaire (spec
# 0006, D7). Chaque cas construit son dépôt : une branche `main`, une branche
# `release/X.Y.Z` avec ou sans RELEASE_NOTES.md, mergée ou non dans main.
#
# Usage :  tools/tests/verify-release-merge.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/verify-release-merge.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

g() { git -c user.name=test -c user.email=test@example.invalid -c commit.gpgsign=false "$@"; }

# new_repo <nom>  — dépôt avec un commit initial sur main
new_repo() {
  local dir="$TMP/$1"
  mkdir -p "$dir"
  g -C "$dir" init -q -b main
  g -C "$dir" commit -q --allow-empty -m "chore: initial"
  echo "$dir"
}

# release_branch <dir> <version> [<première ligne des notes>|"none"]
# Crée release/<version> depuis main avec un RELEASE_NOTES.md (sauf "none").
release_branch() {
  local dir="$1" version="$2" title="${3:-# v$2 — Titre de test}"
  g -C "$dir" switch -q -c "release/$version" main
  if [ "$title" != "none" ]; then
    printf '%s\n\n## Corps\n\n- une ligne\n' "$title" > "$dir/RELEASE_NOTES.md"
    g -C "$dir" add RELEASE_NOTES.md
  fi
  g -C "$dir" commit -q --allow-empty -m "fix: contenu de la release"
}

# merge_into_main <dir> <version>
merge_into_main() {
  g -C "$1" switch -q main
  g -C "$1" merge -q --no-ff "release/$2" -m "Merge pull request #1 from x/release/$2"
}

# run <dir> <args...>  → stdout dans $TMP/stdout, stderr dans $TMP/stderr, code retour
run() { local dir="$1"; shift; "$SCRIPT" --repo "$dir" "$@" >"$TMP/stdout" 2>"$TMP/stderr"; }

expect_ok_output() {
  local label="$1" key="$2" expected="$3"
  local got
  got="$(grep -E "^$key=" "$TMP/stdout" | cut -d= -f2- || true)"
  if [ "$got" = "$expected" ]; then pass "$label"; else fail "$label" "$key attendu « $expected », obtenu « $got » ; stdout : $(cat "$TMP/stdout")"; fi
}

expect_failure() {
  local label="$1" pattern="$2"
  if grep -q -- "$pattern" "$TMP/stderr"; then pass "$label"; else fail "$label" "stderr ne contient pas « $pattern » : $(cat "$TMP/stderr")"; fi
}

echo "verify-release-merge.sh"

# --- Cas nominal : merge d'une release dans main ----------------------------
d="$(new_repo nominal)"
release_branch "$d" 0.14.0
release_sha="$(g -C "$d" rev-parse "release/0.14.0")"
release_short="$(g -C "$d" rev-parse --short=7 "release/0.14.0")"
merge_into_main "$d" 0.14.0
if run "$d"; then
  expect_ok_output "nominal : version lue dans le titre" version 0.14.0
  expect_ok_output "nominal : release_sha = HEAD^2" release_sha "$release_sha"
  expect_ok_output "nominal : image_tag = <version>-<sha court>" image_tag "0.14.0-$release_short"
  expect_ok_output "nominal : tag = v<version>" tag v0.14.0
else
  fail "nominal" "échec inattendu : $(cat "$TMP/stderr")"
fi

# --- HEAD n'est pas un commit de merge ---------------------------------------
d="$(new_repo not-a-merge)"
release_branch "$d" 0.14.0
g -C "$d" switch -q main
g -C "$d" commit -q --allow-empty -m "docs: push direct"
if run "$d"; then fail "non-merge → échec" "succès inattendu"; else
  expect_failure "non-merge → échec nommant le SHA" "$(g -C "$d" rev-parse --short=7 HEAD)"
  expect_failure "non-merge → message explicite" "pas un commit de merge"
fi

# --- Merge sans RELEASE_NOTES.md ---------------------------------------------
d="$(new_repo no-notes)"
release_branch "$d" 0.14.0 none
merge_into_main "$d" 0.14.0
if run "$d"; then fail "sans notes → échec" "succès inattendu"; else
  expect_failure "sans notes → échec nommant le fichier" "RELEASE_NOTES.md"
fi

# --- Titre mal formé ----------------------------------------------------------
d="$(new_repo bad-title)"
release_branch "$d" 0.14.0 "Notes de la version 0.14.0"
merge_into_main "$d" 0.14.0
if run "$d"; then fail "titre mal formé → échec" "succès inattendu"; else
  expect_failure "titre mal formé → la ligne lue est citée" "Notes de la version 0.14.0"
  expect_failure "titre mal formé → la forme attendue est citée" "# vX.Y.Z — "
fi

# --- Tiret simple accepté à la place du tiret cadratin ? Non : strict --------
d="$(new_repo hyphen-title)"
release_branch "$d" 0.14.0 "# v0.14.0 - Titre"
merge_into_main "$d" 0.14.0
if run "$d"; then fail "tiret simple → échec (forme stricte)" "succès inattendu"; else
  pass "tiret simple → échec (forme stricte)"
fi

# --- Mode --no-merge : la référence est elle-même le commit de release -------
d="$(new_repo head-mode)"
release_branch "$d" 0.14.0
if run "$d" --no-merge; then
  expect_ok_output "--no-merge : release_sha = HEAD" release_sha "$(g -C "$d" rev-parse HEAD)"
  expect_ok_output "--no-merge : version" version 0.14.0
else
  fail "--no-merge" "échec inattendu : $(cat "$TMP/stderr")"
fi

# --- --expect-branch ---------------------------------------------------------
if run "$d" --no-merge --expect-branch release/0.14.0; then pass "--expect-branch cohérent → ok"; else fail "--expect-branch cohérent" "$(cat "$TMP/stderr")"; fi
if run "$d" --no-merge --expect-branch release/0.13.3; then fail "--expect-branch incohérent → échec" "succès inattendu"; else
  expect_failure "--expect-branch incohérent → les deux valeurs citées" "release/0.13.3"
  expect_failure "  … et la version du titre" "0.14.0"
fi

# --- --expect-version --------------------------------------------------------
if run "$d" --no-merge --expect-version 0.14.0; then pass "--expect-version cohérent → ok"; else fail "--expect-version cohérent" "$(cat "$TMP/stderr")"; fi
if run "$d" --no-merge --expect-version 0.13.3; then fail "--expect-version incohérent → échec" "succès inattendu"; else
  expect_failure "--expect-version incohérent → les deux valeurs citées" "0.13.3"
fi

# --- Le fichier est lu au commit de release, pas dans l'arbre de travail -----
d="$(new_repo from-commit)"
release_branch "$d" 0.14.0
merge_into_main "$d" 0.14.0
printf '# v9.9.9 — Modifié dans l arbre, non commité\n' > "$d/RELEASE_NOTES.md"
if run "$d"; then expect_ok_output "notes lues au commit, pas dans l'arbre" version 0.14.0; else fail "notes lues au commit" "$(cat "$TMP/stderr")"; fi

echo
if [ "$failures" -eq 0 ]; then echo "OK"; else echo "$failures échec(s)"; exit 1; fi
