#!/usr/bin/env bash
#
# finalize-release.test.sh
# ---------------------------------------------------------------------------
# Test de tools/finalize-release.sh sur un dépôt git temporaire (spec 0006,
# D8) : un `origin` nu, un clone qui joue le rôle du checkout de main dans le
# job, une branche release/X.Y.Z mergée dans main. Sans gh ni réseau
# (--skip-github-release).
#
# Usage :  tools/tests/finalize-release.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/finalize-release.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }
check() { if eval "$2"; then pass "$1"; else fail "$1" "condition fausse : $2"; fi; }

g() { git -c user.name=test -c user.email=test@example.invalid -c commit.gpgsign=false "$@"; }

NOTES=$'# v0.14.0 — Titre de test\n\n## Contenu\n\n- une ligne'

# new_scenario <nom> → crée $TMP/<nom>/origin.git et $TMP/<nom>/work (checkout de main
# au commit de merge de release/0.14.0), écrit RELEASE_SHA et WORK dans des variables.
new_scenario() {
  local dir="$TMP/$1"
  mkdir -p "$dir"
  g init -q --bare "$dir/origin.git"
  g -C "$dir/origin.git" symbolic-ref HEAD refs/heads/main
  g clone -q "$dir/origin.git" "$dir/seed" 2>/dev/null
  local s="$dir/seed"
  g -C "$s" switch -q -c main 2>/dev/null || g -C "$s" checkout -q -b main
  g -C "$s" commit -q --allow-empty -m "chore: initial"
  g -C "$s" tag -a v0.13.2 -m "v0.13.2"
  g -C "$s" switch -q -c develop
  g -C "$s" commit -q --allow-empty -m "feat: une nouveauté"
  g -C "$s" switch -q -c release/0.14.0
  printf '%s\n' "$NOTES" > "$s/RELEASE_NOTES.md"
  g -C "$s" add RELEASE_NOTES.md && g -C "$s" commit -q -m "chore(release): notes 0.14.0"
  RELEASE_SHA="$(g -C "$s" rev-parse HEAD)"
  g -C "$s" switch -q main
  g -C "$s" merge -q --no-ff release/0.14.0 -m "Merge pull request #1 from x/release/0.14.0"
  g -C "$s" push -q origin main develop release/0.14.0 --tags
  # Le « checkout du job » : un clone frais, positionné sur main au commit de merge.
  g clone -q "$dir/origin.git" "$dir/work" 2>/dev/null
  WORK="$dir/work"
  g -C "$WORK" checkout -q main
  g -C "$WORK" fetch -q origin develop
}

run() { "$SCRIPT" --repo "$WORK" --version 0.14.0 --release-sha "$RELEASE_SHA" --skip-github-release "$@" >"$TMP/out" 2>"$TMP/err"; }

echo "finalize-release.sh"

# --- Cas nominal --------------------------------------------------------------
new_scenario nominal
if run --summary "$TMP/summary.md"; then
  O="$TMP/nominal/origin.git"
  check "tag v0.14.0 poussé sur le commit de release" "[ \"\$(g -C '$O' rev-parse v0.14.0^{commit})\" = '$RELEASE_SHA' ]"
  check "annotation du tag = notes, titres Markdown conservés (--cleanup=verbatim)" "[ \"\$(g -C '$O' tag -l --format='%(contents)' v0.14.0 | sed -e :a -e '/^\n*\$/{\$d;N;ba' -e '}')\" = \"\$NOTES\" ]"
  check "docs/releases/v0.14.0.md sur origin/main, identique aux notes" "[ \"\$(g -C '$O' show main:docs/releases/v0.14.0.md)\" = \"\$NOTES\" ]"
  check "le commit de copie porte [skip ci]" "g -C '$O' log -1 --format=%s main | grep -q '\[skip ci\]'"
  check "la branche release/0.14.0 est supprimée sur origin" "! g -C '$O' show-ref --verify --quiet refs/heads/release/0.14.0"
  check "develop == main sur origin (fast-forward)" "[ \"\$(g -C '$O' rev-parse develop)\" = \"\$(g -C '$O' rev-parse main)\" ]"
  check "le résumé compte six lignes" "[ \"\$(grep -c '^- ' '$TMP/summary.md')\" = 6 ]"
else
  fail "nominal" "échec inattendu : $(cat "$TMP/err")"
fi

# --- Idempotence : second run, rien ne change, code 0 --------------------------
O="$TMP/nominal/origin.git"
before="$(g -C "$O" for-each-ref --format='%(refname) %(objectname)' | sort)"
if run; then
  after="$(g -C "$O" for-each-ref --format='%(refname) %(objectname)' | sort)"
  if [ "$before" = "$after" ]; then pass "re-run : aucune référence n'a bougé sur origin"; else fail "re-run : aucune référence n'a bougé sur origin" "diff : $(diff <(echo "$before") <(echo "$after") || true)"; fi
  check "re-run : chaque étape dit « déjà »" "[ \"\$(grep -c 'déjà' '$TMP/out')\" -ge 4 ]"
else
  fail "re-run idempotent" "échec inattendu : $(cat "$TMP/err")"
fi

# --- develop a divergé : étapes 1–5 faites, étape 6 arrêt propre, code 0 -------
new_scenario diverged
g -C "$TMP/diverged/seed" switch -q develop
g -C "$TMP/diverged/seed" commit -q --allow-empty -m "feat: pendant la release"
g -C "$TMP/diverged/seed" push -q origin develop
DEV_BEFORE="$(g -C "$TMP/diverged/origin.git" rev-parse develop)"
if run; then
  O="$TMP/diverged/origin.git"
  check "divergé : tag posé quand même" "g -C '$O' show-ref --verify --quiet refs/tags/v0.14.0"
  check "divergé : notes copiées quand même" "g -C '$O' cat-file -e main:docs/releases/v0.14.0.md"
  check "divergé : branche supprimée quand même" "! g -C '$O' show-ref --verify --quiet refs/heads/release/0.14.0"
  check "divergé : develop intact" "[ \"\$(g -C '$O' rev-parse develop)\" = '$DEV_BEFORE' ]"
  check "divergé : le résumé demande une PR main → develop" "grep -q 'fast-forward impossible' '$TMP/out'"
else
  fail "develop divergé → code 0" "échec inattendu : $(cat "$TMP/err")"
fi

# --- Tag déjà posé ailleurs : échec à l'étape 1, rien d'autre --------------------
new_scenario wrongtag
g -C "$TMP/wrongtag/seed" tag -a v0.14.0 -m "ailleurs" 'v0.13.2^{commit}'
g -C "$TMP/wrongtag/seed" push -q origin v0.14.0
g -C "$WORK" fetch -q --tags origin
if run; then fail "tag ailleurs → échec" "succès inattendu"; else
  check "tag ailleurs : message explicite" "grep -q 'jamais déplacé' '$TMP/err'"
  check "tag ailleurs : la branche de release n'a pas été touchée" "g -C '$TMP/wrongtag/origin.git' show-ref --verify --quiet refs/heads/release/0.14.0"
fi

# --- Copie déjà présente mais différente : échec à l'étape 4 --------------------
new_scenario wrongcopy
mkdir -p "$TMP/wrongcopy/seed/docs/releases"
printf '# v0.14.0 — Autre chose\n' > "$TMP/wrongcopy/seed/docs/releases/v0.14.0.md"
g -C "$TMP/wrongcopy/seed" add docs && g -C "$TMP/wrongcopy/seed" commit -q -m "docs: copie divergente" && g -C "$TMP/wrongcopy/seed" push -q origin main
g -C "$WORK" pull -q --ff-only
if run; then fail "copie différente → échec" "succès inattendu"; else
  check "copie différente : message explicite" "grep -q 'contenu différent' '$TMP/err'"
  check "copie différente : le tag a bien été posé avant (étapes 1–3 faites)" "g -C '$TMP/wrongcopy/origin.git' show-ref --verify --quiet refs/tags/v0.14.0"
fi

# --- Version demandée ≠ notes : refus avant toute étape --------------------------
new_scenario badversion
if "$SCRIPT" --repo "$WORK" --version 0.15.0 --release-sha "$RELEASE_SHA" --skip-github-release >/dev/null 2>"$TMP/err"; then fail "version ≠ notes → échec" "succès inattendu"; else
  check "version ≠ notes : message explicite" "grep -q 'les notes disent v0.14.0' '$TMP/err'"
  check "version ≠ notes : aucun tag posé" "! g -C '$TMP/badversion/origin.git' show-ref --verify --quiet refs/tags/v0.15.0"
fi

echo
if [ "$failures" -eq 0 ]; then echo "OK"; else echo "$failures échec(s)"; exit 1; fi
