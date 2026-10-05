#!/usr/bin/env bash
#
# check-claude-rules.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/check-claude-rules.sh (issue #346) : des dépôts git
# jetables construits en dur, puis le dépôt réel, qui doit passer.
#
# Usage :  tools/tests/check-claude-rules.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$TOOLS/check-claude-rules.sh"
# shellcheck source=tools/tests/lib/git-isolation.sh
. "$TOOLS/tests/lib/git-isolation.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# expect <label> <code attendu> <motif attendu dans la sortie, ou ''> <dépôt>
expect() {
  local label="$1" want_code="$2" want_text="$3" repo="$4"
  local code=0
  "$SCRIPT" "$repo" >"$TMP/out" 2>&1 || code=$?
  if [ "$code" -ne "$want_code" ]; then
    fail "$label" "code $code au lieu de $want_code : $(cat "$TMP/out")"
  elif [ -n "$want_text" ] && ! grep -qF -- "$want_text" "$TMP/out"; then
    fail "$label" "« $want_text » absent de : $(cat "$TMP/out")"
  else
    pass "$label"
  fi
}

# new_repo <nom> : dépôt avec quelques fichiers suivis et une racine courte.
new_repo() {
  local repo="$TMP/$1"
  mkdir -p "$repo/.claude/rules" "$repo/backend/src/Security/User" "$repo/k8s/base"
  git -C "$repo" init -q
  printf '<?php\n' >"$repo/backend/src/Security/User/CpgUser.php"
  printf 'kind: Kustomization\n' >"$repo/k8s/base/kustomization.yaml"
  printf '# CLAUDE.md\n' >"$repo/.claude/CLAUDE.md"
  printf '%s' "$repo"
}

# track <dépôt> : suit tout l'arbre (les globs sont évalués contre l'index).
track() { git -C "$1" add -A; }

echo "check-claude-rules.sh"

# --- Cas nominal ----------------------------------------------------------------
repo="$(new_repo nominal)"
cat >"$repo/.claude/rules/security.md" <<'MD'
---
paths:
  - "backend/src/Security/**"
  - 'k8s/**'
  - k8s/base/kustomization.yaml
---

# Security
MD
track "$repo"
expect "règles conformes : succès" 0 "conformes" "$repo"

# --- Sans paths: -------------------------------------------------------------------
repo="$(new_repo sans-paths)"
printf '# Pas de frontmatter\n' >"$repo/.claude/rules/global.md"
cat >"$repo/.claude/rules/vide.md" <<'MD'
---
description: rien
---
MD
track "$repo"
expect "règle sans frontmatter : échec" 1 ".claude/rules/global.md : aucun \`paths:\`" "$repo"
expect "frontmatter sans paths: : échec" 1 ".claude/rules/vide.md : aucun \`paths:\`" "$repo"

# --- Frontmatter non refermé ----------------------------------------------------
repo="$(new_repo non-ferme)"
printf -- '---\npaths:\n  - "k8s/**"\n\n# Oubli du séparateur\n' >"$repo/.claude/rules/ouvert.md"
track "$repo"
expect "frontmatter non refermé : échec" 1 "frontmatter non refermé" "$repo"

# --- Glob mort ----------------------------------------------------------------------
repo="$(new_repo glob-mort)"
printf -- '---\npaths:\n  - "frontend/**"\n  - "k8s/**"\n---\n' >"$repo/.claude/rules/mort.md"
track "$repo"
expect "glob sans fichier suivi : échec" 1 "« frontend/** » ne correspond à aucun fichier suivi" "$repo"
if grep -qF "k8s/**" "$TMP/out"; then
  fail "un glob vivant n'est pas signalé" "$(cat "$TMP/out")"
else
  pass "un glob vivant n'est pas signalé"
fi

# Un fichier présent sur disque mais non suivi ne compte pas : la règle
# viserait un chemin que le dépôt ne contient pas.
repo="$(new_repo non-suivi)"
mkdir -p "$repo/frontend"
printf -- '---\npaths:\n  - "frontend/**"\n---\n' >"$repo/.claude/rules/front.md"
git -C "$repo" add .claude
printf 'x\n' >"$repo/frontend/main.ts"
expect "fichier non suivi ignoré : échec" 1 "ne correspond à aucun fichier suivi" "$repo"

# `*` ne traverse pas `/` : backend/*/CpgUser.php ne voit pas Security/User/.
repo="$(new_repo etoile)"
printf -- '---\npaths:\n  - "backend/*/CpgUser.php"\n---\n' >"$repo/.claude/rules/etoile.md"
track "$repo"
expect "« * » limité à un segment : échec" 1 "ne correspond à aucun fichier suivi" "$repo"

# --- Accolades --------------------------------------------------------------------
repo="$(new_repo accolades)"
printf -- '---\npaths:\n  - "k8s/base/{kustomization,deploy}.yaml"\n---\n' >"$repo/.claude/rules/acc.md"
track "$repo"
expect "glob à accolades : échec" 1 "utilise des accolades" "$repo"

# --- Plafond de la racine ---------------------------------------------------------
repo="$(new_repo plafond)"
head -c 50000 /dev/zero | tr '\0' 'a' >"$repo/.claude/CLAUDE.md"
track "$repo"
expect "racine à 50 000 octets : succès" 0 "conformes" "$repo"
printf 'b' >>"$repo/.claude/CLAUDE.md"
expect "racine à 50 001 octets : échec" 1 "50001 octets, au-delà du plafond" "$repo"

# --- Erreur d'usage --------------------------------------------------------------
mkdir -p "$TMP/pas-un-depot"
expect "répertoire hors git : erreur d'usage" 2 "Pas un dépôt git" "$TMP/pas-un-depot"

# --- Le dépôt réel ------------------------------------------------------------------
expect "dépôt réel : conforme" 0 "conformes" "$(cd "$TOOLS/.." && pwd)"

if [ "$failures" -gt 0 ]; then
  printf '%d échec(s)\n' "$failures"
  exit 1
fi
echo "tout est vert"
