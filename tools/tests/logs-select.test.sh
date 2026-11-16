#!/usr/bin/env bash
#
# logs-select.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/logs-select.sh (issue #651) : une entrée mixte
# construite en dur (enregistrements Monolog de plusieurs canaux, lignes du
# pool et du maître FPM, morceaux d'un enregistrement coupé, JSON non objet,
# ligne vide), filtrée par les prédicats documentés dans l'en-tête du script.
# Les sorties sont comparées octet pour octet.
#
# Ne dépend que de bash et de jq (celui du runner GitHub, 1.7) ; aucune
# adresse réelle : les IP sont celles de la plage de documentation
# 203.0.113.0/24 (RFC 5737).
#
# Usage :  tools/tests/logs-select.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/logs-select.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# --- Entrée mixte ------------------------------------------------------------
# Enregistrements Monolog : le format du handler JSON (monolog.formatter.json),
# sur une ligne, dans l'ordre de ses clés (jq -c ne les réordonne pas).
AUDIT='{"message":"Security audit event","context":{"event":"login-failed","ip":"203.0.113.7"},"level":200,"level_name":"INFO","channel":"security_audit","datetime":"2026-11-16T10:00:00+00:00","extra":{}}'
SECURITY='{"message":"Authenticator successful!","context":{"user":"0192f1c0-0000-7000-8000-000000000000"},"level":200,"level_name":"INFO","channel":"security","datetime":"2026-11-16T10:00:01+00:00","extra":{}}'
APP='{"message":"Matched route \"home\".","context":{"route":"home"},"level":100,"level_name":"DEBUG","channel":"app","datetime":"2026-11-16T10:00:02+00:00","extra":{}}'
MCP_ERR='{"message":"mcp call","context":{"outcome":"protocol-error"},"level":200,"level_name":"INFO","channel":"mcp_usage","datetime":"2026-11-16T10:00:03+00:00","extra":{}}'
MCP_OK='{"message":"mcp call","context":{"outcome":"success"},"level":200,"level_name":"INFO","channel":"mcp_usage","datetime":"2026-11-16T10:00:04+00:00","extra":{}}'
# Ce que le descripteur 2 porte en plus : ligne d'accès du pool FPM, NOTICE du maître.
ACCESS='203.0.113.7 -  16/Nov/2026:10:00:05 +0000 "GET /index.php" 200'
NOTICE='[16-Nov-2026 10:00:00] NOTICE: fpm is running, pid 1'
# Un enregistrement d'audit coupé par la limite de log_limit : son début, puis sa suite.
CUT_HEAD='{"channel":"security_au'
CUT_TAIL='lue":1}'

{
  printf '%s\n' "$AUDIT" "$SECURITY" "$APP" "$MCP_ERR" "$MCP_OK" "$ACCESS" "$NOTICE" \
    "$CUT_HEAD" "$CUT_TAIL" '42' '"x"' 'null' '[1]' ''
} >"$TMP/mixed.log"

# unparsed <ligne> : la forme que le script rend pour un début d'enregistrement coupé.
unparsed() { jq -cn --arg l "$1" '{unparsed: $l}'; }

# expect_output <label> <sortie attendue> <entrée> <prédicat>
expect_output() {
  local label="$1" want="$2" input="$3" predicate="$4" code=0
  "$SCRIPT" "$predicate" <"$input" >"$TMP/out" 2>"$TMP/err" || code=$?
  if [ "$code" -ne 0 ]; then
    fail "$label" "code $code au lieu de 0 : $(cat "$TMP/err")"
  elif [ "$(cat "$TMP/out")" != "$want" ]; then
    fail "$label" "sortie inattendue. Attendu : $(printf '%q' "$want") ; obtenu : $(printf '%q' "$(cat "$TMP/out")")"
  else
    pass "$label"
  fi
}

echo "logs-select.sh"

# --- Les prédicats documentés ------------------------------------------------
NL=$'\n'
expect_output "canal security_audit : l'audit seul, plus le début coupé" \
  "${AUDIT}${NL}$(unparsed "$CUT_HEAD")" "$TMP/mixed.log" '.channel == "security_audit"'

expect_output "canal security du framework : lui seul, plus le début coupé" \
  "${SECURITY}${NL}$(unparsed "$CUT_HEAD")" "$TMP/mixed.log" '.channel == "security"'

expect_output "les deux canaux à la fois (IN), dans l'ordre de l'entrée" \
  "${AUDIT}${NL}${SECURITY}${NL}$(unparsed "$CUT_HEAD")" "$TMP/mixed.log" \
  '.channel | IN("security", "security_audit")'

expect_output "McpCallOutcome : l'issue protocol-error seule, plus le début coupé" \
  "${MCP_ERR}${NL}$(unparsed "$CUT_HEAD")" "$TMP/mixed.log" '.context.outcome == "protocol-error"'

expect_output "prédicat toujours faux : seul le début coupé reste visible" \
  "$(unparsed "$CUT_HEAD")" "$TMP/mixed.log" 'false'

expect_output "prédicat toujours vrai : tous les objets, plus le début coupé" \
  "${AUDIT}${NL}${SECURITY}${NL}${APP}${NL}${MCP_ERR}${NL}${MCP_OK}${NL}$(unparsed "$CUT_HEAD")" \
  "$TMP/mixed.log" 'true'

# --- Ce qui est écarté --------------------------------------------------------
# Aucune de ces lignes ne commence par « { » : jamais rendue, même avec un prédicat vrai.
printf '%s\n' "$ACCESS" "$NOTICE" "$CUT_TAIL" '42' '"x"' 'null' '[1]' '' >"$TMP/noise.log"
expect_output "accès FPM, NOTICE, suite, 42, \"x\", null, [1], ligne vide : tout écarté" \
  "" "$TMP/noise.log" 'true'

# Un JSON non objet commençant par « { » n'existe pas ; mais un objet suivi de bruit
# est du texte, pas du JSON : début d'enregistrement coupé, donc rendu en unparsed.
printf '%s\n' '{"channel":"security_audit"} trailing' >"$TMP/trailing.log"
expect_output "objet suivi de texte : non décodable, rendu en unparsed" \
  "$(unparsed '{"channel":"security_audit"} trailing')" "$TMP/trailing.log" '.channel == "security_audit"'

# --- Entrée vide --------------------------------------------------------------
: >"$TMP/empty.log"
expect_output "entrée vide : sortie vide, succès" "" "$TMP/empty.log" '.channel == "security_audit"'

# --- Arguments ----------------------------------------------------------------
code=0
"$SCRIPT" <"$TMP/mixed.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 2 ] && grep -q 'Usage' "$TMP/err" && [ ! -s "$TMP/out" ]; then
  pass "sans argument : usage sur stderr, code 2"
else
  fail "sans argument : usage sur stderr, code 2" "code $code ; stderr : $(cat "$TMP/err") ; stdout : $(cat "$TMP/out")"
fi

code=0
"$SCRIPT" '.a' '.b' <"$TMP/mixed.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 2 ] && grep -q 'Usage' "$TMP/err"; then
  pass "deux arguments : usage, code 2"
else
  fail "deux arguments : usage, code 2" "code $code ; stderr : $(cat "$TMP/err")"
fi

code=0
"$SCRIPT" '' <"$TMP/mixed.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 2 ] && grep -q 'Usage' "$TMP/err"; then
  pass "prédicat vide : usage, code 2"
else
  fail "prédicat vide : usage, code 2" "code $code ; stderr : $(cat "$TMP/err")"
fi

code=0
"$SCRIPT" '.channel ==' <"$TMP/mixed.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -ne 0 ] && [ "$code" -ne 2 ] && [ ! -s "$TMP/out" ] \
   && grep -q 'prédicat' "$TMP/err" && grep -q 'jq: error' "$TMP/err"; then
  pass "prédicat invalide : code non nul (hors 2), message lisible, rien sur stdout"
else
  fail "prédicat invalide : code non nul (hors 2), message lisible" "code $code ; stderr : $(cat "$TMP/err") ; stdout : $(cat "$TMP/out")"
fi

# Un prédicat qui lève à l'exécution (`.context` est une chaîne : l'indexer échoue)
# n'est pas avalé : jq s'arrête, code 5, message sur stderr.
printf '%s\n' '{"channel":"app","context":"texte"}' >"$TMP/odd.log"
code=0
"$SCRIPT" '.context.outcome == "x"' <"$TMP/odd.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 5 ] && grep -q 'jq: error' "$TMP/err"; then
  pass "erreur à l'exécution du prédicat : code 5, message de jq"
else
  fail "erreur à l'exécution du prédicat : code 5, message de jq" "code $code ; stderr : $(cat "$TMP/err")"
fi

echo
if [ "$failures" -gt 0 ]; then
  echo "Échecs : $failures"
  exit 1
fi
echo "Tous les cas passent."
