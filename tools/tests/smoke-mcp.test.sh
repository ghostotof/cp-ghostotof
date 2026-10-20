#!/usr/bin/env bash
#
# smoke-mcp.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/smoke-mcp.sh : un faux `curl` (SMOKE_CURL) rejoue
# des réponses canned, une par appel, au format que le script attend (corps,
# puis le code HTTP sur la dernière ligne ; en-têtes dans le fichier passé par
# `-D`), et note ses arguments pour vérifier les requêtes et la Basic Auth.
#
# Usage :  tools/tests/smoke-mcp.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/smoke-mcp.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# Le faux curl : lit la n-ième ligne de $FAKE_RESPONSES (format
# `code|en-têtes|corps`, en-têtes séparés par `;`, champ vide = aucun), écrit
# les en-têtes dans le fichier de `-D`, imprime le corps comme le vrai avec
# `-w '\n%{http_code}'`, et ajoute ses arguments à $FAKE_ARGS. Le corps passe
# par `printf '%b'` : `\r` ou `\033` y deviennent les vrais caractères.
cat > "$TMP/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_ARGS"
headers_file=""
while [ "$#" -gt 0 ]; do
  if [ "$1" = "-D" ]; then headers_file="$2"; shift; fi
  shift
done
n=$(wc -l < "$FAKE_CALLS" 2>/dev/null || echo 0)
n=$((n + 1))
echo "$n" >> "$FAKE_CALLS"
line="$(sed -n "${n}p" "$FAKE_RESPONSES")"
code="${line%%|*}"
rest="${line#*|}"
headers="${rest%%|*}"
body="${rest#*|}"
if [ -n "$headers_file" ]; then
  {
    printf 'HTTP/2 %s\r\n' "$code"
    if [ -n "$headers" ]; then
      IFS=';' read -ra list <<< "$headers"
      for header in "${list[@]}"; do printf '%s\r\n' "$header"; done
    fi
    printf '\r\n'
  } > "$headers_file"
fi
printf '%b\n%s' "$body" "$code"
EOF
chmod +x "$TMP/curl"

# run <nom> <réponses…>  → code retour dans $rc, sorties dans $TMP/out|err
run() {
  local name="$1"; shift
  : > "$TMP/$name.responses"
  for r in "$@"; do printf '%s\n' "$r" >> "$TMP/$name.responses"; done
  : > "$TMP/$name.args"; : > "$TMP/$name.calls"
  set +e
  FAKE_RESPONSES="$TMP/$name.responses" FAKE_ARGS="$TMP/$name.args" FAKE_CALLS="$TMP/$name.calls" \
    SMOKE_CURL="$TMP/curl" "$SCRIPT" https://example.invalid >"$TMP/out" 2>"$TMP/err"
  rc=$?
  set -e
}

# repeat <n> <réponse> : la réponse n fois, une par ligne de "$@" à passer à run.
repeat() { local _; for _ in $(seq 1 "$1"); do printf '%s\n' "$2"; done; }

session_id='0199c7a2-5f3e-7b1a-9c4d-2e8f6a1b3c5d'
# L'`initialize` tel que le transport le rend (en SSE ici : le script lit les deux formes).
init="200|content-type: text/event-stream;mcp-session-id: $session_id|event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"protocolVersion\":\"2025-06-18\",\"serverInfo\":{\"name\":\"cp-ghostotof\",\"version\":\"0.1.0\"}}}"
deleted='204||'
stream='200|content-type: text/event-stream|retry: 300000'
# Le 429 de la zone nginx : problem+json `/errors/rate-limited`, sans Retry-After.
zone429='429|content-type: application/problem+json|{"type":"/errors/rate-limited","title":"rate-limited","status":429,"detail":"Trop de requêtes. Réessayez dans un instant."}'

mapfile -t twenty_streams < <(repeat 20 "$stream")
mapfile -t all_streams < <(repeat 120 "$stream")

# --- Cas nominal : initialize, fin de session, 20 GET admis puis le 429.
run nominal "$init" "$deleted" "${twenty_streams[@]}" "$zone429"
if [ "$rc" -eq 0 ] && grep -q 'session ouverte' "$TMP/out" && grep -q '429 de la zone mcp à la requête 21' "$TMP/out"; then
  pass "initialize, DELETE, 20 × 200 puis 429 → succès"
else
  fail "cas nominal" "rc=$rc ; out : $(cat "$TMP/out") ; err : $(cat "$TMP/err")"
fi
if [ "$(wc -l < "$TMP/nominal.calls")" -eq 23 ]; then pass "s'arrête au premier 429 (23 appels)"; else fail "nombre d'appels" "$(wc -l < "$TMP/nominal.calls")"; fi

# --- L'initialize en JSON plutôt qu'en SSE : même verdict.
run json "200|content-type: application/json;Mcp-Session-Id: $session_id|{\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"serverInfo\":{\"name\":\"cp-ghostotof\"}}}" "$deleted" "$zone429"
if [ "$rc" -eq 0 ]; then pass "initialize en JSON, en-tête en casse mixte → succès"; else fail "initialize JSON" "rc=$rc ; err : $(cat "$TMP/err")"; fi

# --- Hôte refusé par le SDK : allowed_hosts ne porte pas l'hôte du déploiement.
run host '403|content-type: application/json|{"jsonrpc":"2.0","error":{"code":-32000,"message":"Forbidden"}}'
if [ "$rc" -eq 1 ] && grep -q 'allowed_hosts' "$TMP/err" && [ "$(wc -l < "$TMP/host.calls")" -eq 1 ]; then
  pass "initialize en 403 → échec nommant allowed_hosts"
else
  fail "hôte refusé" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- 200 sans identifiant de session : le handshake n'a pas abouti.
run nosession '200|content-type: application/json|{"jsonrpc":"2.0","id":1,"result":{"serverInfo":{"name":"cp-ghostotof"}}}'
if [ "$rc" -eq 1 ] && grep -q 'Mcp-Session-Id' "$TMP/err"; then pass "initialize sans Mcp-Session-Id → échec"; else fail "sans session" "rc=$rc ; err : $(cat "$TMP/err")"; fi

# --- 200 d'un autre serveur (une page, un autre service derrière l'Ingress).
run other "200|content-type: application/json;mcp-session-id: $session_id|{\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"serverInfo\":{\"name\":\"autre\"}}}"
if [ "$rc" -eq 1 ] && grep -q 'serverInfo' "$TMP/err"; then pass "initialize d'un autre serveur → échec"; else fail "autre serveur" "rc=$rc ; err : $(cat "$TMP/err")"; fi

# --- Jamais de 429 : la zone mcp manque (publicapi en voudrait plus de 200).
run never "$init" "$deleted" "${all_streams[@]}"
if [ "$rc" -eq 1 ] && grep -q 'aucun 429' "$TMP/err" && [ "$(wc -l < "$TMP/never.calls")" -eq 122 ]; then
  pass "120 GET sans 429 → échec (zone mcp absente)"
else
  fail "jamais freiné" "rc=$rc ; appels=$(wc -l < "$TMP/never.calls") ; err : $(cat "$TMP/err")"
fi

# --- Un 429 en HTML : l'`error_page` de la zone n'est pas en place.
run html "$init" "$deleted" '429|content-type: text/html|<html>429 Too Many Requests</html>'
if [ "$rc" -eq 1 ] && grep -q 'problem+json' "$TMP/err"; then pass "429 HTML → échec"; else fail "429 HTML" "rc=$rc ; err : $(cat "$TMP/err")"; fi

# --- Un 429 problem+json d'un autre type → échec.
run untyped "$init" "$deleted" '429|content-type: application/problem+json|{"type":"/errors/429","status":429}'
if [ "$rc" -eq 1 ] && grep -q '/errors/rate-limited' "$TMP/err"; then pass "429 sans type rate-limited → échec"; else fail "429 non typé" "rc=$rc ; err : $(cat "$TMP/err")"; fi

# --- Un statut inattendu pendant la saturation → échec immédiat.
run status "$init" "$deleted" "$stream" '502|content-type: text/html|<html>502</html>'
if [ "$rc" -eq 1 ] && grep -q '502 reçu' "$TMP/err" && [ "$(wc -l < "$TMP/status.calls")" -eq 4 ]; then
  pass "502 pendant la saturation → échec immédiat"
else
  fail "statut inattendu" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Le corps affiché dans le journal de la CI est débarrassé des caractères
# de contrôle : un `\r` y ouvrirait une ligne `::commande::` du runner.
run control '502|content-type: text/html|<html>\r::error::forge\033[31m</html>'
if [ "$rc" -eq 1 ] && ! grep -q $'\r' "$TMP/err" && ! grep -q $'\033' "$TMP/err" && grep -q '::error::forge' "$TMP/err"; then
  pass "caractères de contrôle du corps neutralisés dans l'extrait"
else
  fail "caractères de contrôle" "rc=$rc ; err : $(cat -A "$TMP/err")"
fi

# --- Les requêtes : initialize en POST JSON, DELETE de la session, GET en SSE.
first="$(sed -n 1p "$TMP/nominal.args")"
second="$(sed -n 2p "$TMP/nominal.args")"
third="$(sed -n 3p "$TMP/nominal.args")"
if [[ "$first" == *"-X POST"* && "$first" == *"Content-Type: application/json"* && "$first" == *"Accept: application/json, text/event-stream"* \
   && "$first" == *'"method":"initialize"'* && "$first" == *'"protocolVersion":"2025-06-18"'* && "$first" == *"https://example.invalid/api/mcp"* ]]; then
  pass "initialize : POST JSON, Accept des deux formes, révision 2025-06-18"
else
  fail "requête initialize" "$first"
fi
if [[ "$second" == *"-X DELETE"* && "$second" == *"Mcp-Session-Id: $session_id"* ]]; then pass "fin de session : DELETE avec son identifiant"; else fail "requête DELETE" "$second"; fi
if [[ "$third" == *"-X GET"* && "$third" == *"Accept: text/event-stream"* && "$third" != *"Mcp-Session-Id"* ]]; then
  pass "saturation : GET en SSE, sans session (rien n'est écrit en base)"
else
  fail "requête GET" "$third"
fi

# --- Basic Auth : via un fichier -K, jamais -u ; absente sinon.
AUDIT_BASIC_AUTH='smoke:p"a\ss' run auth "$init" "$deleted" "$zone429"
if grep -q -- ' -K ' "$TMP/auth.args" && ! grep -q -- ' -u ' "$TMP/auth.args"; then
  pass "AUDIT_BASIC_AUTH → -K <fichier>, jamais -u"
else
  fail "basic auth" "args : $(cat "$TMP/auth.args")"
fi
if ! grep -q -- ' -K ' "$TMP/nominal.args"; then pass "sans AUDIT_BASIC_AUTH → pas de -K"; else fail "sans auth" "args : $(head -1 "$TMP/nominal.args")"; fi

# --- Sans URL : usage, code 2.
set +e; "$SCRIPT" >/dev/null 2>"$TMP/err"; rc=$?; set -e
if [ "$rc" -eq 2 ] && grep -q '^usage' "$TMP/err"; then pass "sans argument → usage, code 2"; else fail "usage" "rc=$rc"; fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "Tous les cas passent."
