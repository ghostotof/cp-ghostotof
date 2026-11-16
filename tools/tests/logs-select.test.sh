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
# ai_usage porte aussi un champ `outcome` : un prédicat sur `.context.outcome` seul le sélectionnerait.
AI_ERR='{"message":"ai call","context":{"outcome":"protocol-error"},"level":200,"level_name":"INFO","channel":"ai_usage","datetime":"2026-11-16T10:00:05+00:00","extra":{}}'
# Le bruit connu, seul écarté : ligne d'accès du pool FPM (format par défaut
# `%R - %u %t "%m %r" %s`, `%u` vide) et NOTICE du maître (date sans zone).
ACCESS='203.0.113.7 -  16/Nov/2026:10:00:05 +0000 "GET /index.php" 200'
NOTICE='[16-Nov-2026 10:00:00] NOTICE: fpm is running, pid 1'
# Tout le reste est rendu en unparsed : un enregistrement d'audit coupé par
# `log_limit` (son début, puis sa suite), du JSON non objet, la trace de dernier
# recours de FailSafeAudit (error_log() de PHP : la date porte un nom de zone),
# un WARNING du maître, un morceau suivant qui commence par « { ».
CUT_HEAD='{"channel":"security_au'
CUT_TAIL='lue":1}'
NESTED_TAIL='{"nested":1},"level":200,"channel":"security_audit"}'
TRACE='[16-Nov-2026 10:00:06 Europe/Paris] Audit failure could not be logged. Logger failure: X'
FATAL='[16-Nov-2026 10:00:07 Europe/Paris] PHP Fatal error:  Uncaught Error: boom in /app/src/X.php:1'
WARN="[16-Nov-2026 10:00:08] WARNING: [pool www] child 7, script '/app/public/index.php' (request: \"GET /index.php\") execution timed out (31.2 sec), terminating"

# Les lignes qui sortent en unparsed, dans l'ordre de l'entrée.
UNPARSED_LINES=("$CUT_HEAD" "$CUT_TAIL" "$NESTED_TAIL" '42' '"x"' 'null' '[1]' "$TRACE" "$FATAL" "$WARN")

{
  printf '%s\n' "$AUDIT" "$SECURITY" "$APP" "$MCP_ERR" "$MCP_OK" "$AI_ERR" "$ACCESS" "$NOTICE" \
    "${UNPARSED_LINES[@]}" ''
} >"$TMP/mixed.log"

# unparsed <ligne> : la forme que le script rend pour une ligne qui n'est pas un objet JSON.
unparsed() { jq -cn --arg l "$1" '{unparsed: $l}'; }
# all_unparsed : unparsed de chaque ligne de UNPARSED_LINES, séparés par un saut de ligne.
all_unparsed() { local l; for l in "${UNPARSED_LINES[@]}"; do unparsed "$l"; done; }
UNP="$(all_unparsed)"

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
# Chaque sortie : les enregistrements retenus, puis toutes les lignes unparsed
# (placées après les enregistrements dans l'entrée).
NL=$'\n'
expect_output "canal security_audit : l'audit seul, plus les lignes unparsed" \
  "${AUDIT}${NL}${UNP}" "$TMP/mixed.log" '.channel == "security_audit"'

expect_output "canal security du framework : lui seul, plus les lignes unparsed" \
  "${SECURITY}${NL}${UNP}" "$TMP/mixed.log" '.channel == "security"'

expect_output "les deux canaux à la fois (IN), dans l'ordre de l'entrée" \
  "${AUDIT}${NL}${SECURITY}${NL}${UNP}" "$TMP/mixed.log" \
  '.channel | IN("security", "security_audit")'

# Décision série 8 : l'exemple McpCallOutcome borne le canal, ai_usage a aussi un `outcome`.
expect_output "McpCallOutcome : mcp_usage + protocol-error seul (pas ai_usage), plus unparsed" \
  "${MCP_ERR}${NL}${UNP}" "$TMP/mixed.log" '.channel == "mcp_usage" and .context.outcome == "protocol-error"'

expect_output "prédicat toujours faux : seules les lignes unparsed restent" \
  "${UNP}" "$TMP/mixed.log" 'false'

expect_output "prédicat toujours vrai : tous les objets, plus les lignes unparsed" \
  "${AUDIT}${NL}${SECURITY}${NL}${APP}${NL}${MCP_ERR}${NL}${MCP_OK}${NL}${AI_ERR}${NL}${UNP}" \
  "$TMP/mixed.log" 'true'

# --- Ce qui est écarté : le bruit connu, rien d'autre ----------------------------
printf '%s\n' "$ACCESS" "$NOTICE" '' >"$TMP/noise.log"
expect_output "accès FPM, NOTICE du maître, ligne vide : seuls écartés" \
  "" "$TMP/noise.log" 'true'

# Un utilisateur Basic Auth renseigné (%u) et une IPv6 restent une ligne d'accès.
printf '%s\n' '2001:db8::1 - alice 16/Nov/2026:10:00:05 +0000 "POST /index.php" 204' >"$TMP/access2.log"
expect_output "accès FPM avec utilisateur et IPv6 : écarté" "" "$TMP/access2.log" 'true'

# Seuls les statuts 2xx et 3xx sont du bruit : un 4xx ou un 5xx de PHP est un signal (échec
# d'authentification, refus, erreur serveur) et sort en unparsed.
for status in 200 204 301 304; do
  printf '%s\n' "203.0.113.7 -  16/Nov/2026:10:00:05 +0000 \"GET /index.php\" $status" >"$TMP/one.log"
  expect_output "accès FPM en $status : écarté" "" "$TMP/one.log" 'true'
done
for status in 400 401 404 500 503; do
  line="203.0.113.7 -  16/Nov/2026:10:00:05 +0000 \"GET /index.php\" $status"
  printf '%s\n' "$line" >"$TMP/one.log"
  expect_output "accès FPM en $status : rendu en unparsed" "$(unparsed "$line")" "$TMP/one.log" 'true'
done

# NOTICE du maître : seules celles de routine (démarrage, enfants lancés) sont écartées.
for line in '[16-Nov-2026 10:00:00] NOTICE: fpm is running, pid 1' \
            '[16-Nov-2026 10:00:00] NOTICE: ready to handle connections' \
            '[16-Nov-2026 10:00:01] NOTICE: [pool www] child 7 started'; do
  printf '%s\n' "$line" >"$TMP/one.log"
  expect_output "NOTICE de routine écartée : ${line:23}" "" "$TMP/one.log" 'true'
done
# Tout autre NOTICE signale un arrêt, un rechargement ou une sortie d'enfant : rendue.
for line in '[16-Nov-2026 10:00:09] NOTICE: Terminating ...' \
            '[16-Nov-2026 10:00:09] NOTICE: exiting, bye-bye!' \
            '[16-Nov-2026 10:00:09] NOTICE: Finishing ...' \
            '[16-Nov-2026 10:00:09] NOTICE: Reloading in progress ...' \
            '[16-Nov-2026 10:00:09] NOTICE: [pool www] child 7 exited with code 0 after 12.3 seconds from start'; do
  printf '%s\n' "$line" >"$TMP/one.log"
  expect_output "NOTICE hors routine rendue en unparsed : ${line:23}" "$(unparsed "$line")" "$TMP/one.log" 'true'
done

# --- Ce qui est rendu : tout le reste -----------------------------------------
for line in "$TRACE" "$FATAL" "$WARN" "$CUT_TAIL" "$NESTED_TAIL" '42' '"x"' 'null' '[1]'; do
  printf '%s\n' "$line" >"$TMP/one.log"
  expect_output "rendue en unparsed : ${line:0:60}" "$(unparsed "$line")" "$TMP/one.log" 'false'
done

PFX_FAST='[pod/backend-6d9f7c8b5-x2x4q/php-fpm]'
# Un morceau suivant qui commence par « { » ne casse pas le flux : l'enregistrement
# qui le suit est traité normalement.
printf '%s\n' "$CUT_HEAD" "$NESTED_TAIL" "$AUDIT" >"$TMP/nested.log"
expect_output "coupure avant un objet imbriqué : unparsed, puis le flux continue" \
  "$(unparsed "$CUT_HEAD")${NL}$(unparsed "$NESTED_TAIL")${NL}${AUDIT}" "$TMP/nested.log" '.channel == "security_audit"'

# Chemin rapide (ligne qui commence par « { ») : sans préfixe, avec préfixe kubectl, et coupée.
# Une ligne coupée préfixée garde sa ligne complète en unparsed.
printf '%s\n' "$AUDIT" "$PFX_FAST $CUT_HEAD" "$CUT_HEAD" "$PFX_FAST $AUDIT" >"$TMP/fast.log"
expect_output "chemin rapide : JSON nu et préfixé rendus, ligne coupée (nue ou préfixée) en unparsed" \
  "${AUDIT}${NL}$(unparsed "$PFX_FAST $CUT_HEAD")${NL}$(unparsed "$CUT_HEAD")${NL}${AUDIT}" "$TMP/fast.log" 'true'

# Un objet suivi de texte n'est pas du JSON : unparsed.
printf '%s\n' '{"channel":"security_audit"} trailing' >"$TMP/trailing.log"
expect_output "objet suivi de texte : non décodable, rendu en unparsed" \
  "$(unparsed '{"channel":"security_audit"} trailing')" "$TMP/trailing.log" '.channel == "security_audit"'

# --- Préfixes de kubectl logs (--timestamps, --prefix) ---------------------------
TS='2026-11-16T10:00:45.123456789Z'
PFX='[pod/backend-6d9f7c8b5-x2x4q/php-fpm]'
{
  printf '%s %s\n' "$TS" "$AUDIT"                  # --timestamps
  printf '%s %s\n' "$PFX" "$SECURITY"              # --prefix
  printf '%s %s %s\n' "$PFX" "$TS" "$APP"          # --prefix + --timestamps (ordre de kubectl : préfixe d'abord)
  printf '%s %s %s\n' "$TS" "$PFX" "$MCP_ERR"      # l'autre ordre, toléré
  printf '%s %s\n' "$TS" "$ACCESS"                 # bruit horodaté : écarté
  printf '%s %s %s\n' "$PFX" "$TS" "$NOTICE"       # NOTICE préfixée : écartée
  printf '%s \n' "$TS"                             # ligne vide horodatée : écartée
  printf '%s %s %s\n' "$PFX" "$TS" "$WARN"         # WARNING préfixé : rendu, ligne complète
} >"$TMP/kubectl.log"
expect_output "préfixes kubectl : retirés avant décodage, unparsed garde la ligne complète" \
  "${AUDIT}${NL}${SECURITY}${NL}${APP}${NL}${MCP_ERR}${NL}$(unparsed "$PFX $TS $WARN")" "$TMP/kubectl.log" 'true'

# --- Prédicat robuste ---------------------------------------------------------
# Un `#` (commentaire jq) en fin de prédicat ne mange pas la parenthèse fermante.
expect_output "prédicat terminé par un commentaire jq" \
  "${APP}${NL}${UNP}" "$TMP/mixed.log" '.channel == "app" # que les routes'

# Virgule de premier niveau : chaque enregistrement n'est rendu qu'une fois.
expect_output "prédicat à virgule de premier niveau : pas de doublon" \
  "${APP}${NL}${UNP}" "$TMP/mixed.log" '.channel == "app", .channel == "app"'

# Un prédicat qui lève sur une ligne (`context` est une liste, cas réel de Monolog,
# ou une chaîne) : signalé sur stderr, le reste du flux est rendu, code 4 à la fin.
# stderr ne porte QUE le numéro de ligne et un libellé fixe : jamais un texte de jq,
# qui cite des valeurs de l'enregistrement.
printf '%s\n' "$AUDIT" '{"channel":"app","context":[]}' "$MCP_ERR" '{"channel":"app","context":"texte"}' "$APP" >"$TMP/odd.log"
code=0
"$SCRIPT" '.context.outcome == "protocol-error"' <"$TMP/odd.log" >"$TMP/out" 2>"$TMP/err" || code=$?
WANT_ERR="logs-select.sh : ligne 2 : le prédicat a levé une erreur
logs-select.sh : ligne 4 : le prédicat a levé une erreur
logs-select.sh : 2 ligne(s) en erreur de prédicat (code 4)"
if [ "$code" -eq 4 ] && [ "$(cat "$TMP/out")" = "$MCP_ERR" ] && [ "$(cat "$TMP/err")" = "$WANT_ERR" ]; then
  pass "erreur à l'exécution : libellé fixe par ligne sur stderr, flux continué, code 4"
else
  fail "erreur à l'exécution : libellé fixe par ligne sur stderr, flux continué, code 4" "code $code ; stderr : $(cat "$TMP/err") ; stdout : $(cat "$TMP/out")"
fi

# Aucune valeur de l'enregistrement ne fuit sur stderr, quelle que soit l'erreur de jq
# (`+ 1` cite la valeur tronquée, `tonumber` la cite en entier).
SECRET_REC='{"channel":"x","context":{"ip":"secretvalue123"}}'
for predicate in '.context.ip + 1' '.context.ip | tonumber' '.context.ip | error'; do
  printf '%s\n' "$SECRET_REC" >"$TMP/secret.log"
  code=0
  "$SCRIPT" "$predicate" <"$TMP/secret.log" >"$TMP/out" 2>"$TMP/err" || code=$?
  if [ "$code" -eq 4 ] && ! grep -q 'secretvalu' "$TMP/err" && grep -q 'ligne 1 : le prédicat a levé une erreur' "$TMP/err"; then
    pass "erreur de prédicat sans fuite de valeur : $predicate"
  else
    fail "erreur de prédicat sans fuite de valeur : $predicate" "code $code ; stderr : $(cat "$TMP/err")"
  fi
done

# Codes de sortie de jq : 3 (compilation) et 4 (erreurs de prédicat) gardent leur sens ;
# toute autre sortie non nulle de jq (2 = erreur système ou d'entrée-sortie, ici simulée
# par halt_error) devient 5, jamais 2 qui est « usage invalide » du script.
for jqcode in 1 2 9; do
  code=0
  "$SCRIPT" "\"x\" | halt_error($jqcode)" <"$TMP/mixed.log" >"$TMP/out" 2>"$TMP/err" || code=$?
  if [ "$code" -eq 5 ]; then
    pass "sortie $jqcode de jq : code 5 du script"
  else
    fail "sortie $jqcode de jq : code 5 du script" "code $code ; stderr : $(cat "$TMP/err")"
  fi
done

# Les lignes de bruit comptent dans la numérotation : l'erreur cite le numéro de ligne d'entrée.
printf '%s\n' "$ACCESS" "$NOTICE" '' '{"channel":"app","context":[]}' >"$TMP/numbered.log"
code=0
"$SCRIPT" '.context.outcome == "x"' <"$TMP/numbered.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 4 ] && grep -q 'ligne 4 : le prédicat a levé une erreur' "$TMP/err"; then
  pass "numérotation : le bruit écarté compte, l'erreur cite la ligne 4"
else
  fail "numérotation : le bruit écarté compte, l'erreur cite la ligne 4" "code $code ; stderr : $(cat "$TMP/err")"
fi

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
if [ "$code" -eq 3 ] && [ ! -s "$TMP/out" ] \
   && grep -q 'prédicat' "$TMP/err" && grep -q 'jq: error' "$TMP/err"; then
  pass "prédicat invalide : code 3, message lisible, rien sur stdout"
else
  fail "prédicat invalide : code 3, message lisible" "code $code ; stderr : $(cat "$TMP/err") ; stdout : $(cat "$TMP/out")"
fi

echo
if [ "$failures" -gt 0 ]; then
  echo "Échecs : $failures"
  exit 1
fi
echo "Tous les cas passent."
