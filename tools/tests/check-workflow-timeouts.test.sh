#!/usr/bin/env bash
#
# check-workflow-timeouts.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/check-workflow-timeouts.sh (issue #285) : des
# workflows construits en dur dans un répertoire jetable, puis les workflows
# réels du dépôt, qui doivent passer.
#
# Usage :  tools/tests/check-workflow-timeouts.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/check-workflow-timeouts.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# expect <label> <code attendu> <motif attendu dans la sortie, ou ''> <fichiers>...
expect() {
  local label="$1" want_code="$2" want_text="$3"
  shift 3
  local code=0
  "$SCRIPT" "$@" >"$TMP/out" 2>&1 || code=$?
  if [ "$code" -ne "$want_code" ]; then
    fail "$label" "code $code au lieu de $want_code : $(cat "$TMP/out")"
  elif [ -n "$want_text" ] && ! grep -qF -- "$want_text" "$TMP/out"; then
    fail "$label" "« $want_text » absent de : $(cat "$TMP/out")"
  else
    pass "$label"
  fi
}

echo "check-workflow-timeouts.sh"

# --- Cas nominal : tout est borné -------------------------------------------
cat >"$TMP/ok.yml" <<'YAML'
name: ok
on:
  push:
    branches: [main]
jobs:
  build:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4
      - name: Dépendances
        timeout-minutes: 5
        run: |
          sudo apt-get update -qq
          sudo apt-get install -y -qq imagemagick
  deploy:
    runs-on: ubuntu-latest
    timeout-minutes: 45 # somme des attentes internes
    needs: build
    steps:
      - run: echo déploiement
YAML
expect "workflow entièrement borné : succès" 0 "" "$TMP/ok.yml"

# --- Job sans timeout --------------------------------------------------------
cat >"$TMP/job.yml" <<'YAML'
jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - run: echo lint
  test:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - run: echo test
YAML
expect "job sans timeout-minutes : échec" 1 "job lint sans timeout-minutes" "$TMP/job.yml"
if grep -q "job test" "$TMP/out"; then
  fail "un job borné n'est pas signalé" "$(cat "$TMP/out")"
else
  pass "un job borné n'est pas signalé"
fi

# --- Dernier job du fichier sans timeout (vidage en fin de fichier) ----------
cat >"$TMP/last.yml" <<'YAML'
jobs:
  first:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: echo un
  last:
    runs-on: ubuntu-latest
    steps:
      - run: echo deux
YAML
expect "dernier job du fichier sans timeout : échec" 1 "job last sans timeout-minutes" "$TMP/last.yml"

# --- Étape apt-get sans timeout, dans un job borné ---------------------------
cat >"$TMP/apt.yml" <<'YAML'
jobs:
  og:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    steps:
      - name: Dépendances de rendu
        run: |
          sudo apt-get update -qq
      - name: Rendu
        run: npm run og:generate
YAML
expect "étape apt-get sans timeout : échec" 1 "étape « Dépendances de rendu » (apt-get)" "$TMP/apt.yml"
if grep -qF "« Rendu »" "$TMP/out"; then
  fail "une étape sans apt-get n'est pas signalée" "$(cat "$TMP/out")"
else
  pass "une étape sans apt-get n'est pas signalée"
fi

# --- Le timeout du job ne vaut pas pour l'étape apt --------------------------
cat >"$TMP/inline.yml" <<'YAML'
jobs:
  og:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    steps:
      - run: sudo apt-get install -y jq
YAML
expect "étape apt-get en ligne, sans nom : échec" 1 "étape « (sans nom) » (apt-get)" "$TMP/inline.yml"

# --- Plusieurs fichiers : chaque manque est attribué au bon fichier ----------
expect "manque attribué à son fichier" 1 "$TMP/last.yml: job last" "$TMP/ok.yml" "$TMP/last.yml"
if grep -q "ok.yml" "$TMP/out"; then
  fail "aucun manque attribué au fichier complet" "$(cat "$TMP/out")"
else
  pass "aucun manque attribué au fichier complet"
fi

# --- Erreur d'usage -----------------------------------------------------------
expect "fichier introuvable : code 2" 2 "Fichier introuvable" "$TMP/absent.yml"

# --- Les workflows réels du dépôt ---------------------------------------------
expect "workflows du dépôt entièrement bornés" 0 ""

if [ "$failures" -gt 0 ]; then
  printf '\n%d échec(s)\n' "$failures"
  exit 1
fi
printf '\nTous les cas passent.\n'
