#!/usr/bin/env bash
#
# check-release-gates.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/check-release-gates.sh (issue #600) : des paires
# pipeline.yml / github-settings.sh construites en dur dans un répertoire
# jetable, puis les fichiers réels du dépôt, qui doivent passer.
#
# Usage :  tools/tests/check-release-gates.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/check-release-gates.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# expect <label> <code attendu> <motif attendu dans la sortie, ou ''> [<pipeline> <settings>]
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

# settings <fichier> <checks de main> [<checks de develop>]
settings() {
  printf '#!/usr/bin/env bash\nMAIN_REQUIRED_CHECKS=(%s)\nDEVELOP_REQUIRED_CHECKS=(%s)\n' \
    "$2" "${3:-tools-tests}" >"$1"
}

echo "check-release-gates.sh"

# --- Cas nominal : la sonde dans son propre job, hors des needs du rollback --
cat >"$TMP/ok.yml" <<'YAML'
name: Pipeline
on:
  push:
jobs:
  tools-tests:
    runs-on: ubuntu-latest
    steps:
      - run: tools/tests/run.sh
  deploy-preprod:
    needs: [tools-tests]
    steps:
      - run: echo deploy
  smoke-test-preprod:
    needs: [deploy-preprod]
    steps:
      # La sonde postgres-hba-probe-job.yaml n'est plus ici (#600).
      - run: echo smoke
  audit-preprod:
    needs: deploy-preprod
    steps:
      - run: echo audit
  postgres-hba-preprod:
    needs:
      - deploy-preprod
    steps:
      - name: Sonde
        run: |
          tools/run-job.sh preprod k8s/base/postgres-hba-probe-job.yaml sans-objet 120
  rollback-preprod:
    needs: [deploy-preprod, smoke-test-preprod, audit-preprod]
    if: failure()
    steps:
      - run: echo undo
YAML
settings "$TMP/ok.sh" "smoke-test-preprod audit-preprod postgres-hba-preprod"
expect "sonde dans son job, tout requis : succès" 0 "" "$TMP/ok.yml" "$TMP/ok.sh"

# --- La sonde dans un job dont l'échec déclenche le rollback (état d'avant #600)
sed -e '/^  postgres-hba-preprod:/,/^  rollback-preprod:/{/^  rollback-preprod:/!d}' \
    -e 's|^      - run: echo smoke$|      - run: tools/run-job.sh preprod k8s/base/postgres-hba-probe-job.yaml sans-objet 120|' \
    "$TMP/ok.yml" >"$TMP/in-smoke.yml"
settings "$TMP/in-smoke.sh" "smoke-test-preprod audit-preprod"
expect "sonde dans smoke-test-preprod : échec" 1 \
  "job smoke-test-preprod lance k8s/base/postgres-hba-probe-job.yaml et précède rollback-preprod" \
  "$TMP/in-smoke.yml" "$TMP/in-smoke.sh"

# --- Le job de la sonde ajouté directement aux needs du rollback --------------
sed 's|needs: \[deploy-preprod, smoke-test-preprod, audit-preprod\]|needs: [deploy-preprod, smoke-test-preprod, audit-preprod, postgres-hba-preprod]|' \
  "$TMP/ok.yml" >"$TMP/direct.yml"
expect "job de la sonde dans les needs du rollback : échec" 1 \
  "job postgres-hba-preprod lance k8s/base/postgres-hba-probe-job.yaml et précède rollback-preprod" \
  "$TMP/direct.yml" "$TMP/ok.sh"

# --- … ou seulement par transitivité (un job requis qui attend la sonde) ------
sed 's|^    needs: \[deploy-preprod\]$|    needs: [deploy-preprod, postgres-hba-preprod]|' \
  "$TMP/ok.yml" >"$TMP/transitive.yml"
expect "sonde ancêtre indirect du rollback : échec" 1 \
  "job postgres-hba-preprod lance k8s/base/postgres-hba-probe-job.yaml et précède rollback-preprod" \
  "$TMP/transitive.yml" "$TMP/ok.sh"

# --- La sonde lancée par aucun job (un commentaire ne compte pas) -------------
sed 's|tools/run-job.sh preprod k8s/base/postgres-hba-probe-job.yaml sans-objet 120|echo plus de sonde|' \
  "$TMP/ok.yml" >"$TMP/none.yml"
expect "sonde lancée par aucun job : échec" 1 \
  "k8s/base/postgres-hba-probe-job.yaml n'est lancé par aucun job" \
  "$TMP/none.yml" "$TMP/ok.sh"

# --- Un check requis qui n'est pas un job (renommage) --------------------------
settings "$TMP/renamed-main.sh" "smoke-test-preprod audit-preprod pg-hba-preprod postgres-hba-preprod"
expect "check requis de main sans job : échec" 1 \
  "check requis de main « pg-hba-preprod » : aucun job de ce nom" \
  "$TMP/ok.yml" "$TMP/renamed-main.sh"
settings "$TMP/renamed-dev.sh" "smoke-test-preprod audit-preprod postgres-hba-preprod" "tools-tests lint"
expect "check requis de develop sans job : échec" 1 \
  "check requis de develop « lint » : aucun job de ce nom" \
  "$TMP/ok.yml" "$TMP/renamed-dev.sh"

# --- Un job bloquant de la phase préprod qui n'est pas requis -----------------
settings "$TMP/missing.sh" "smoke-test-preprod audit-preprod"
expect "job après deploy-preprod non requis : échec" 1 \
  "job postgres-hba-preprod (après deploy-preprod) n'est pas un check requis de main" \
  "$TMP/ok.yml" "$TMP/missing.sh"
if grep -q "job rollback-preprod" "$TMP/out"; then
  fail "rollback-preprod n'a pas à être requis" "$(cat "$TMP/out")"
else
  pass "rollback-preprod n'a pas à être requis"
fi

# --- Erreurs de lecture : code 2, jamais un faux vert --------------------------
printf '#!/usr/bin/env bash\nDEVELOP_REQUIRED_CHECKS=(tools-tests)\n' >"$TMP/no-main.sh"
expect "MAIN_REQUIRED_CHECKS absent : code 2" 2 "MAIN_REQUIRED_CHECKS" "$TMP/ok.yml" "$TMP/no-main.sh"
settings "$TMP/empty-main.sh" ""
expect "MAIN_REQUIRED_CHECKS vide : code 2" 2 "MAIN_REQUIRED_CHECKS" "$TMP/ok.yml" "$TMP/empty-main.sh"
sed '/^  rollback-preprod:/,$d' "$TMP/ok.yml" >"$TMP/no-rollback.yml"
expect "job rollback-preprod absent : code 2" 2 "rollback-preprod" "$TMP/no-rollback.yml" "$TMP/ok.sh"
expect "fichier introuvable : code 2" 2 "Fichier introuvable" "$TMP/absent.yml" "$TMP/ok.sh"
expect "un seul argument : code 2" 2 "Usage" "$TMP/ok.yml"

# --- Les fichiers réels du dépôt ----------------------------------------------
expect "pipeline et rulesets du dépôt cohérents" 0 ""

if [ "$failures" -gt 0 ]; then
  printf '\n%d échec(s)\n' "$failures"
  exit 1
fi
printf '\nTous les cas passent.\n'
