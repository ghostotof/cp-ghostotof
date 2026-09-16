#!/usr/bin/env bash
#
# next-version.test.sh
# ---------------------------------------------------------------------------
# Test de tools/next-version.sh sur un dépôt git temporaire (spec 0006, §4 M1).
# Ne touche jamais au dépôt courant : chaque cas construit son propre dépôt
# dans un répertoire jetable, avec une branche `main` taguée et une branche
# de travail, puis appelle le script avec --no-fetch --base main.
#
# Usage :  tools/tests/next-version.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/next-version.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# git sans configuration globale : identité fixe, pas de hooks, pas de signature.
g() { git -c user.name=test -c user.email=test@example.invalid -c commit.gpgsign=false "$@"; }

# new_repo <nom> <tag-initial>
# Crée un dépôt avec un commit initial tagué sur main, puis une branche `work`.
new_repo() {
  local dir="$TMP/$1" tag="$2"
  mkdir -p "$dir"
  g -C "$dir" init -q -b main
  g -C "$dir" commit -q --allow-empty -m "chore: initial"
  if [ -n "$tag" ]; then g -C "$dir" tag -a "$tag" -m "$tag"; fi
  g -C "$dir" switch -q -c work
  echo "$dir"
}

# commit <dir> <message...>  — commit vide sur la branche courante
commit() { local dir="$1"; shift; g -C "$dir" commit -q --allow-empty -m "$@"; }

# expect_version <libellé> <dir> <attendu>
expect_version() {
  local label="$1" dir="$2" expected="$3" got
  if got="$("$SCRIPT" --no-fetch --base main --repo "$dir" 2>"$TMP/stderr")"; then
    if [ "$got" = "$expected" ]; then pass "$label"; else fail "$label" "attendu $expected, obtenu $got"; fi
  else
    fail "$label" "échec inattendu : $(cat "$TMP/stderr")"
  fi
}

# expect_failure <libellé> <dir> <motif attendu sur stderr>
expect_failure() {
  local label="$1" dir="$2" pattern="$3"
  if "$SCRIPT" --no-fetch --base main --repo "$dir" >"$TMP/stdout" 2>"$TMP/stderr"; then
    fail "$label" "succès inattendu : $(cat "$TMP/stdout")"
  elif grep -q -- "$pattern" "$TMP/stderr"; then
    pass "$label"
  else
    fail "$label" "stderr ne contient pas « $pattern » : $(cat "$TMP/stderr")"
  fi
}

echo "next-version.sh"

d="$(new_repo patch v0.13.2)"
commit "$d" "fix(order): un correctif"
commit "$d" "docs(readme): une doc"
commit "$d" "ci: un réglage"
expect_version "fix + docs + ci depuis v0.13.2 → 0.13.3" "$d" "0.13.3"

d="$(new_repo minor v0.13.2)"
commit "$d" "fix(order): un correctif"
commit "$d" "feat(og): une nouveauté"
expect_version "un feat parmi des fix → 0.14.0" "$d" "0.14.0"

d="$(new_repo major-0x-bang v0.13.2)"
commit "$d" "feat!: rupture"
expect_version "feat! en 0.x → majeur ramené à mineur, 0.14.0" "$d" "0.14.0"

d="$(new_repo major-0x-footer v0.13.2)"
commit "$d" "refactor(api): réécriture" -m "BREAKING CHANGE: le contrat change"
expect_version "BREAKING CHANGE en pied en 0.x → 0.14.0" "$d" "0.14.0"

d="$(new_repo major-1x v1.4.2)"
commit "$d" "fix(api)!: rupture"
expect_version "fix! en 1.x → 2.0.0" "$d" "2.0.0"

d="$(new_repo minor-1x v1.4.2)"
commit "$d" "feat(api): ajout"
expect_version "feat en 1.x → 1.5.0" "$d" "1.5.0"

d="$(new_repo nothing v0.13.2)"
expect_failure "aucun commit depuis le tag → échec « rien à livrer »" "$d" "rien à livrer"

d="$(new_repo merge-ignored v0.13.2)"
commit "$d" "fix(a): un"
g -C "$d" switch -q -c side
commit "$d" "feat(b): sur une branche"
g -C "$d" switch -q work
g -C "$d" merge -q --no-ff side -m "Merge branch 'side'"
# Le merge est ignoré, mais pas les commits qu'il apporte : feat(b) compte.
expect_version "commit de merge ignoré, ses commits comptent → 0.14.0" "$d" "0.14.0"

d="$(new_repo merge-only v0.13.2)"
g -C "$d" switch -q -c side
commit "$d" "fix(b): sur une branche"
g -C "$d" switch -q work
g -C "$d" merge -q --no-ff side -m "feat: ce merge ne compte pas"
expect_version "un sujet feat sur un merge ne compte pas → 0.13.3" "$d" "0.13.3"

d="$(new_repo unconventional v0.13.2)"
commit "$d" "fix(a): un"
commit "$d" "WIP truc"
expect_version "commit hors convention ignoré → 0.13.3" "$d" "0.13.3"
if grep -q "hors convention" "$TMP/stderr"; then pass "  … avec un avertissement sur stderr"; else fail "  … avec un avertissement sur stderr" "$(cat "$TMP/stderr")"; fi

d="$(new_repo only-unconventional v0.13.2)"
commit "$d" "WIP truc"
expect_failure "uniquement des commits hors convention → échec" "$d" "rien à livrer"

d="$(new_repo no-tag "")"
commit "$d" "feat: premier"
expect_failure "aucun tag v* sur main → échec explicite" "$d" "aucun tag"

d="$(new_repo tag-not-on-base v0.13.2)"
commit "$d" "feat: un"
g -C "$d" tag -a v9.9.9 -m "tag posé sur work, pas sur main"
expect_version "un tag sur la branche de travail n'est pas la base → 0.14.0" "$d" "0.14.0"

echo
if [ "$failures" -eq 0 ]; then echo "OK"; else echo "$failures échec(s)"; exit 1; fi
