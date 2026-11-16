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

# Un utilisateur Basic Auth renseigné (%u) et une IPv6 restent une ligne d'accès, quelle que
# soit l'issue : en 2xx et 4xx elle est écartée, en 5xx elle sort en unparsed. Cela garde
# sous test la partie `\S+ - \S*` de l'expression pour les deux issues.
printf '%s\n' '2001:db8::1 - alice 16/Nov/2026:10:00:05 +0000 "POST /index.php" 204' >"$TMP/access2.log"
expect_output "accès FPM avec utilisateur et IPv6 en 204 : écarté" "" "$TMP/access2.log" 'true'
printf '%s\n' '2001:db8::1 - alice 16/Nov/2026:10:00:05 +0000 "POST /index.php" 401' >"$TMP/access2.log"
expect_output "accès FPM avec utilisateur et IPv6 en 401 : écarté" "" "$TMP/access2.log" 'true'
LINE_V6_5XX='2001:db8::1 - alice 16/Nov/2026:10:00:05 +0000 "POST /index.php" 502'
printf '%s\n' "$LINE_V6_5XX" >"$TMP/access2.log"
expect_output "accès FPM avec utilisateur et IPv6 en 502 : rendu en unparsed" \
  "$(unparsed "$LINE_V6_5XX")" "$TMP/access2.log" 'true'

# Les statuts 2xx, 3xx et 4xx SAUF 403 sont du bruit (chaque 401/404 de production noierait
# les événements cherchés ; ils sont perdus, les journaux bruts les gardent) ; le 403 sort en
# unparsed (un refus d'accès n'a pas toujours d'audit : un jeton de base sur /api/cv), comme
# les 5xx (un 5xx écrit par PHP n'a pas d'autre trace).
for status in 200 204 301 304 400 401 402 404 499; do
  printf '%s\n' "203.0.113.7 -  16/Nov/2026:10:00:05 +0000 \"GET /index.php\" $status" >"$TMP/one.log"
  expect_output "accès FPM en $status : écarté" "" "$TMP/one.log" 'true'
done
for status in 403 500 502 503 504; do
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

# Une ligne faite seulement d'espaces ou de tabulations n'est pas une ligne vide : unparsed.
for line in '   ' "$(printf '\t')" "$(printf ' \t ')"; do
  printf '%s\n' "$line" >"$TMP/one.log"
  expect_output "ligne de blancs seuls : rendue en unparsed" "$(unparsed "$line")" "$TMP/one.log" 'true'
done
# Préfixe kubectl suivi de blancs seuls : unparsed aussi, ligne complète (le préfixe seul reste écarté, voir plus bas).
printf '%s\n' '2026-11-16T10:00:45Z    ' >"$TMP/one.log"
expect_output "préfixe horodaté puis blancs seuls : rendu en unparsed" \
  "$(unparsed '2026-11-16T10:00:45Z    ')" "$TMP/one.log" 'true'

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

# Un blanc devant le JSON (espace, tabulation), seul ou après un préfixe kubectl, ne change
# pas le décodage : l'enregistrement est rendu sans le blanc (avant la série 9, fromjson le
# décodait).
printf '%s\n' " $AUDIT" "$(printf '\t')$SECURITY" "$PFX_FAST  $APP" "$PFX_FAST $(printf '\t')$MCP_ERR" >"$TMP/blank.log"
expect_output "JSON précédé d'un blanc (seul, ou après un préfixe) : décodé" \
  "${AUDIT}${NL}${SECURITY}${NL}${APP}${NL}${MCP_ERR}" "$TMP/blank.log" 'true'
# Un blanc devant une ligne qui n'est pas du JSON : toujours unparsed, ligne telle que lue.
printf '%s\n' " $CUT_HEAD" >"$TMP/blank.log"
expect_output "blanc devant un enregistrement coupé : unparsed, blanc compris" \
  "$(unparsed " $CUT_HEAD")" "$TMP/blank.log" 'true'

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
for jqcode in 1 2 4 9; do
  code=0
  "$SCRIPT" "\"x\" | halt_error($jqcode)" <"$TMP/mixed.log" >"$TMP/out" 2>"$TMP/err" || code=$?
  if [ "$code" -eq 5 ]; then
    pass "sortie $jqcode de jq : code 5 du script"
  else
    fail "sortie $jqcode de jq : code 5 du script" "code $code ; stderr : $(cat "$TMP/err")"
  fi
done

# Aucune valeur sur stderr, même pour une sortie que le `try` du script ne rattrape pas.
# `halt_error` simule une erreur de jq hors du `try` (code 7 ici) : jq écrit alors la valeur
# sur son stderr, que le script capture dans un fichier temporaire et ne recopie jamais.
code=0
printf '%s\n' "$SECRET_REC" >"$TMP/secret.log"
"$SCRIPT" '.context.ip | halt_error(7)' <"$TMP/secret.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 5 ] && [ "$(cat "$TMP/err")" = "logs-select.sh : jq a échoué (code 7)" ]; then
  pass "erreur hors du try : libellé fixe, aucune valeur sur stderr, code 5"
else
  fail "erreur hors du try : libellé fixe, aucune valeur sur stderr, code 5" "code $code ; stderr : $(cat "$TMP/err")"
fi

# Le stderr de jq lui-même (builtin `stderr`, `debug`) n'est jamais relayé : code 0, stderr vide.
code=0
"$SCRIPT" '(.context.ip | stderr) | true' <"$TMP/secret.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 0 ] && [ ! -s "$TMP/err" ] && [ "$(cat "$TMP/out")" = "$SECRET_REC" ]; then
  pass "stderr de jq jamais relayé : code 0, stderr vide"
else
  fail "stderr de jq jamais relayé : code 0, stderr vide" "code $code ; stderr : $(cat "$TMP/err")"
fi

# Avec des erreurs de prédicat (code 4), seuls les libellés fixes du script reviennent, même si
# le prédicat a écrit une valeur juste avant (sans saut de ligne) sur le stderr de jq.
code=0
"$SCRIPT" '(.context.ip | stderr) | error' <"$TMP/secret.log" >"$TMP/out" 2>"$TMP/err" || code=$?
WANT_ERR="logs-select.sh : ligne 1 : le prédicat a levé une erreur
logs-select.sh : 1 ligne(s) en erreur de prédicat (code 4)"
if [ "$code" -eq 4 ] && [ "$(cat "$TMP/err")" = "$WANT_ERR" ]; then
  pass "code 4 : libellés fixes seuls, une valeur écrite par le prédicat n'est pas relayée"
else
  fail "code 4 : libellés fixes seuls, une valeur écrite par le prédicat n'est pas relayée" "code $code ; stderr : $(cat "$TMP/err")"
fi

# Un `halt_error(3)` de prédicat n'est pas un échec de compilation (jq compile avant de lire) :
# 5, libellé fixe, jamais la valeur. Avec ou sans erreurs de prédicat avant lui.
code=0
"$SCRIPT" '.context.ip | halt_error(3)' <"$TMP/secret.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 5 ] && [ "$(cat "$TMP/err")" = "logs-select.sh : jq a échoué (code 3)" ]; then
  pass "halt_error(3) d'un prédicat : code 5, libellé fixe, aucune valeur sur stderr"
else
  fail "halt_error(3) d'un prédicat : code 5, libellé fixe, aucune valeur sur stderr" "code $code ; stderr : $(cat "$TMP/err")"
fi
printf '%s\n' '{"channel":"app","context":[]}' "$SECRET_REC" >"$TMP/h3.log"
code=0
"$SCRIPT" 'if (.context | type) == "array" then error("x") else (.context.ip | halt_error(3)) end' <"$TMP/h3.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 5 ] && ! grep -q 'secretvalu' "$TMP/err" && grep -q 'jq a échoué (code 3)' "$TMP/err"; then
  pass "erreur de prédicat puis halt_error(3) : code 5, aucune valeur sur stderr"
else
  fail "erreur de prédicat puis halt_error(3) : code 5, aucune valeur sur stderr" "code $code ; stderr : $(cat "$TMP/err")"
fi

# Signaux : un code de sortie >= 128 de jq est rendu tel quel, sans message.
for jqcode in 130 137 141; do
  code=0
  "$SCRIPT" "\"x\" | halt_error($jqcode)" <"$TMP/mixed.log" >"$TMP/out" 2>"$TMP/err" || code=$?
  if [ "$code" -eq "$jqcode" ] && [ ! -s "$TMP/err" ]; then
    pass "sortie $jqcode de jq : rendue telle quelle, stderr vide"
  else
    fail "sortie $jqcode de jq : rendue telle quelle, stderr vide" "code $code ; stderr : $(cat "$TMP/err")"
  fi
done

# SIGPIPE réel : `| head -n 1` ferme le tube pendant que jq écrit encore (141, stderr vide).
# Si SIGPIPE est ignoré à l'entrée (SigIgn, bit 13 : un runner ou un parent qui l'ignore), jq reçoit
# EPIPE au lieu du signal et sort autrement : le cas ne mesure plus le script, il est sauté.
sigign="$(sed -n 's/^SigIgn:[[:space:]]*//p' /proc/self/status 2>/dev/null || true)"
if [ -n "$sigign" ] && [ $((16#$sigign & 0x1000)) -ne 0 ]; then
  printf '  skip SIGPIPE (| head -n 1) : SIGPIPE est ignoré dans cet environnement\n'
else
  set +e
  yes "$AUDIT" | head -n 20000 | "$SCRIPT" 'true' 2>"$TMP/err" | head -n 1 >"$TMP/out"
  pipe_status=("${PIPESTATUS[@]}")
  set -e
  if [ "${pipe_status[2]}" -eq 141 ] && [ ! -s "$TMP/err" ] && [ "$(cat "$TMP/out")" = "$AUDIT" ]; then
    pass "SIGPIPE (| head -n 1) : le script sort en 141, stderr vide"
  else
    fail "SIGPIPE (| head -n 1) : le script sort en 141, stderr vide" "statuts ${pipe_status[*]} ; stderr : $(cat "$TMP/err")"
  fi
fi

# Les erreurs de prédicat sortent AU FIL DE L'EAU : le message est sur stderr avant la fin de l'entrée
# (flux lent simulé : la ligne fautive, puis 3 s de silence). On sonde stderr pendant le silence.
BAD='{"channel":"app","context":[]}'
{ printf '%s\n' "$AUDIT" "$BAD"; sleep 3; printf '%s\n' "$APP"; } \
  | "$SCRIPT" '.context.outcome == "x"' >"$TMP/out" 2>"$TMP/err" &
stream_pid=$!
seen=0
for _ in $(seq 20); do
  if grep -q 'ligne 2 : le prédicat a levé une erreur' "$TMP/err" 2>/dev/null; then seen=1; break; fi
  sleep 0.1
done
if [ "$seen" -eq 1 ] && kill -0 "$stream_pid" 2>/dev/null; then
  pass "erreur de prédicat : message sorti avant la fin de l'entrée"
else
  fail "erreur de prédicat : message sorti avant la fin de l'entrée" "vu=$seen ; stderr : $(cat "$TMP/err")"
fi
wait "$stream_pid" || true

# Après un Ctrl-C (SIGINT sur tout le groupe, ici sur le script seul), le message déjà sorti reste visible.
set +e
{ printf '%s\n' "$BAD"; sleep 3; } | timeout -s INT 1 "$SCRIPT" '.context.outcome == "x"' >"$TMP/out" 2>"$TMP/err"
sleep 0.3
set -e
if grep -q 'ligne 1 : le prédicat a levé une erreur' "$TMP/err"; then
  pass "erreur de prédicat visible après une interruption (SIGINT)"
else
  fail "erreur de prédicat visible après une interruption (SIGINT)" "stderr : $(cat "$TMP/err")"
fi

# `| head -n 1` après des erreurs de prédicat : 141 rendu tel quel, les messages déjà émis restent visibles.
set +e
{ printf '%s\n' "$BAD" "$BAD"; yes "$AUDIT" | head -n 20000; } | "$SCRIPT" '.context.outcome == "x" or (.channel == "security_audit")' 2>"$TMP/err" | head -n 1 >"$TMP/out"
pipe_status=("${PIPESTATUS[@]}")
set -e
if [ -n "$sigign" ] && [ $((16#$sigign & 0x1000)) -ne 0 ]; then
  printf '  skip | head après erreurs : SIGPIPE est ignoré dans cet environnement\n'
elif [ "${pipe_status[1]}" -eq 141 ] && grep -q 'ligne 1 : le prédicat a levé une erreur' "$TMP/err" \
   && grep -q 'ligne 2 : le prédicat a levé une erreur' "$TMP/err"; then
  pass "| head -n 1 après des erreurs : 141, messages visibles"
else
  fail "| head -n 1 après des erreurs : 141, messages visibles" "statuts ${pipe_status[*]} ; stderr : $(cat "$TMP/err")"
fi

jq -nr --arg l "$BAD" 'range(5000) | $l' >"$TMP/many.log"

# Un flux que jq a abandonné n'est pas lu jusqu'au bout : le code 4 promet le contraire, il
# reste 5 (une erreur de prédicat à la ligne 1, puis un halt_error(7) du prédicat à la ligne 2).
# Les libellés par ligne restent affichés, et le total (code 4) n'est pas annoncé.
printf '%s\n' "$BAD" "$AUDIT" >"$TMP/mask.log"
code=0
"$SCRIPT" 'if (.context | type) == "array" then error("x") else (1 | halt_error(7)) end' <"$TMP/mask.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 5 ] && grep -q 'ligne 1 : le prédicat a levé une erreur' "$TMP/err" && grep -q 'jq a échoué (code 7)' "$TMP/err"; then
  pass "erreur de prédicat puis flux abandonné par jq : code 5 (flux tronqué), libellés conservés"
else
  fail "erreur de prédicat puis flux abandonné par jq : code 5 (flux tronqué), libellés conservés" "code $code ; stderr : $(cat "$TMP/err")"
fi

# Un signal l'emporte toujours, même après des erreurs de prédicat : 130 rendu tel quel.
code=0
"$SCRIPT" 'if (.context | type) == "array" then error("x") else (1 | halt_error(130)) end' <"$TMP/mask.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 130 ] && grep -q 'ligne 1 : le prédicat a levé une erreur' "$TMP/err"; then
  pass "erreur de prédicat puis code 130 de jq : 130 rendu tel quel"
else
  fail "erreur de prédicat puis code 130 de jq : 130 rendu tel quel" "code $code ; stderr : $(cat "$TMP/err")"
fi

# Fuite : une valeur du journal qui imite la dernière ligne d'une erreur de compilation ne doit
# jamais être relayée. Le prédicat l'écrit sur le stderr de jq ; la valeur témoin est fictive.
printf '%s\n' '{"channel":"x","m":"witnessvalue987\njq: 1 compile error"}' >"$TMP/leak.log"
code=0
"$SCRIPT" '(.m + "\n" | stderr) | true' <"$TMP/leak.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 0 ] && [ "$(grep -c 'witnessvalue987' "$TMP/err")" -eq 0 ] && [ ! -s "$TMP/err" ]; then
  pass "valeur imitant « jq: N compile error » : jamais relayée, code 0, stderr vide"
else
  fail "valeur imitant « jq: N compile error » : jamais relayée, code 0, stderr vide" "code $code ; stderr : $(cat "$TMP/err")"
fi
# Même chose avec des erreurs de prédicat (code 4) : seuls les libellés fixes.
code=0
"$SCRIPT" '(.m + "\n" | stderr) | error' <"$TMP/leak.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 4 ] && [ "$(grep -c 'witnessvalue987' "$TMP/err")" -eq 0 ]; then
  pass "valeur imitant « jq: N compile error » avec erreur de prédicat : jamais relayée, code 4"
else
  fail "valeur imitant « jq: N compile error » avec erreur de prédicat : jamais relayée, code 4" "code $code ; stderr : $(cat "$TMP/err")"
fi

# Le filtre des erreurs (awk) échoue : libellé fixe et code non nul, les erreurs de prédicat ne
# disparaissent pas du code. Simulé par un faux `awk` en tête du PATH.
mkdir -p "$TMP/fakebin"
for awkcode in 7 143; do
  printf '#!/bin/sh\ncat >/dev/null\nexit %s\n' "$awkcode" >"$TMP/fakebin/awk"
  chmod +x "$TMP/fakebin/awk"
  want=5; [ "$awkcode" -ge 128 ] && want=$awkcode
  code=0
  PATH="$TMP/fakebin:$PATH" "$SCRIPT" '.context.outcome == "x"' <"$TMP/many.log" >"$TMP/out" 2>"$TMP/err" || code=$?
  if [ "$code" -eq "$want" ] && [ "$(cat "$TMP/err")" = "logs-select.sh : le filtre des erreurs a échoué (code $awkcode)" ]; then
    pass "filtre des erreurs sorti en $awkcode : libellé fixe, code $want"
  else
    fail "filtre des erreurs sorti en $awkcode : libellé fixe, code $want" "code $code ; stderr : $(cat "$TMP/err")"
  fi
done
rm -f "$TMP/fakebin/awk"

# Beaucoup d'erreurs : tout sort, rien n'est stocké (pas de fichier qui grossit) ; code 4.
code=0
"$SCRIPT" '.context.outcome == "x"' <"$TMP/many.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 4 ] && [ "$(grep -c 'le prédicat a levé une erreur$' "$TMP/err")" -eq 5000 ] \
   && tail -n 1 "$TMP/err" | grep -qx 'logs-select.sh : 5000 ligne(s) en erreur de prédicat (code 4)'; then
  pass "5000 erreurs de prédicat : 5000 libellés et le total, code 4"
else
  fail "5000 erreurs de prédicat : 5000 libellés et le total, code 4" "code $code ; $(wc -l <"$TMP/err") lignes de stderr"
fi

# Aucun fichier temporaire : un TMPDIR inutilisable ne gêne pas le script.
code=0
TMPDIR=/nonexistent "$SCRIPT" '.channel == "app"' <"$TMP/mixed.log" >"$TMP/out" 2>"$TMP/err" || code=$?
if [ "$code" -eq 0 ] && [ "$(head -n 1 "$TMP/out")" = "$APP" ]; then
  pass "TMPDIR inutilisable : sans effet (aucun fichier temporaire)"
else
  fail "TMPDIR inutilisable : sans effet (aucun fichier temporaire)" "code $code ; stderr : $(cat "$TMP/err")"
fi

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
