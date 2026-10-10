#!/usr/bin/env bash
#
# smoke-login-throttling.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/smoke-login-throttling.sh : un faux `curl`
# (SMOKE_CURL) rejoue des réponses canned, une par appel, au format que le
# script attend (corps, puis le code HTTP sur la dernière ligne ; en-têtes
# dans le fichier passé par `-D`), et note ses arguments pour vérifier la
# Basic Auth.
#
# Usage :  tools/tests/smoke-login-throttling.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/smoke-login-throttling.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# Le faux curl : lit la n-ième ligne de $FAKE_RESPONSES (format
# `code|retry-after|corps`, retry-after vide = pas d'en-tête), l'imprime comme
# le vrai avec `-w '\n%{http_code}'`, écrit les en-têtes dans le fichier de
# `-D`, et ajoute ses arguments à $FAKE_ARGS.
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
retry_after="${rest%%|*}"
body="${rest#*|}"
if [ -n "$headers_file" ]; then
  {
    printf 'HTTP/2 %s\r\n' "$code"
    if [ -n "$retry_after" ]; then printf 'retry-after: %s\r\n' "$retry_after"; fi
    printf '\r\n'
  } > "$headers_file"
fi
printf '%s\n%s' "$body" "$code"
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

invalid='401||{"code":401,"message":"Invalid credentials."}'
# Le refus de l'application (issue #399) : 429 problem+json avec Retry-After.
# Corps relevé tel quel sur la stack de dev : JsonResponse échappe les `/`,
# que la zone nginx, elle, écrit nus.
toomany='429|900|{"type":"\/errors\/rate-limited","title":"rate-limited","status":429,"detail":"Trop de tentatives de connexion. Réessayez plus tard."}'
# Celui de la zone nginx `login` : même type, mais jamais de Retry-After.
nginx429='429||{"type":"/errors/rate-limited","title":"rate-limited","status":429,"detail":"Trop de requêtes. Réessayez dans un instant."}'
# L'ancien contrat, d'avant #399 : le 401 de Lexik.
legacy='401||{"code":401,"message":"Too many failed login attempts, please try again in 15 minutes."}'

# --- Cas nominal : 5 refus puis le throttling au 6e → succès.
run nominal "$invalid" "$invalid" "$invalid" "$invalid" "$invalid" "$toomany"
if [ "$rc" -eq 0 ] && grep -q 'freiné à la tentative 6' "$TMP/out" && grep -q 'Retry-After : 900 s' "$TMP/out"; then
  pass "5 × 401 puis 429 rate-limited au 6e → succès"
else
  fail "cas nominal" "rc=$rc ; out : $(cat "$TMP/out") ; err : $(cat "$TMP/err")"
fi
if [ "$(wc -l < "$TMP/nominal.calls")" -eq 6 ]; then pass "exactement 6 appels"; else fail "nombre d'appels" "$(wc -l < "$TMP/nominal.calls")"; fi

# --- Throttling plus tôt (compteur global par IP déjà entamé) : accepté.
run early "$invalid" "$toomany"
if [ "$rc" -eq 0 ] && [ "$(wc -l < "$TMP/early.calls")" -eq 2 ]; then
  pass "429 dès le 2e → succès, arrêt immédiat"
else
  fail "throttling précoce" "rc=$rc, appels=$(wc -l < "$TMP/early.calls")"
fi

# --- Jamais freiné : le défaut de l'audit A1 → échec explicite.
run never "$invalid" "$invalid" "$invalid" "$invalid" "$invalid" "$invalid"
if [ "$rc" -eq 1 ] && grep -q 'sans jamais atteindre le throttling' "$TMP/err"; then
  pass "6 × 401 sans 429 → échec (A1)"
else
  fail "jamais freiné" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Un statut autre que 401 ou 429 (200, 502…) → échec, sans continuer.
run status "$invalid" '502||<html>502 Bad Gateway</html>'
if [ "$rc" -eq 1 ] && grep -q '502 reçu, 401 ou 429 attendu' "$TMP/err" && [ "$(wc -l < "$TMP/status.calls")" -eq 2 ]; then
  pass "statut ni 401 ni 429 → échec immédiat"
else
  fail "statut inattendu" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Un 429 sans Retry-After vient de la zone nginx `login`, pas de
# login_throttling : il ne prouve rien sur l'état des limiteurs → échec.
run nginx "$invalid" "$nginx429"
if [ "$rc" -eq 1 ] && grep -q 'sans Retry-After' "$TMP/err" && grep -q 'nginx' "$TMP/err" && [ "$(wc -l < "$TMP/nginx.calls")" -eq 2 ]; then
  pass "429 sans Retry-After (nginx) → échec immédiat"
else
  fail "429 nginx" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Un 429 avec Retry-After mais sans le type attendu → échec.
run untyped "$invalid" '429|900|{"type":"/errors/429","status":429}'
if [ "$rc" -eq 1 ] && grep -q '/errors/rate-limited' "$TMP/err"; then
  pass "429 sans type rate-limited → échec"
else
  fail "429 non typé" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Un Retry-After nul dirait de réessayer tout de suite → échec.
run zero "$invalid" '429|0|{"type":"/errors/rate-limited","status":429}'
if [ "$rc" -eq 1 ] && grep -q 'Retry-After' "$TMP/err"; then
  pass "Retry-After: 0 → échec"
else
  fail "Retry-After nul" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- L'ancien 401 « Too many » : l'image déployée ne porte pas #399 → échec
# explicite, plutôt qu'un « jamais freiné » qui accuserait le stockage.
run legacy "$invalid" "$legacy"
if [ "$rc" -eq 1 ] && grep -q 'ancien contrat' "$TMP/err" && [ "$(wc -l < "$TMP/legacy.calls")" -eq 2 ]; then
  pass "ancien 401 « Too many » → échec explicite"
else
  fail "ancien contrat" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Basic Auth : via un fichier -K, jamais -u ; absente sinon.
AUDIT_BASIC_AUTH='smoke:p"a\ss' run auth "$toomany"
if grep -q -- ' -K ' "$TMP/auth.args" && ! grep -q -- ' -u ' "$TMP/auth.args"; then
  pass "AUDIT_BASIC_AUTH → -K <fichier>, jamais -u"
else
  fail "basic auth" "args : $(cat "$TMP/auth.args")"
fi
run noauth "$toomany"
if ! grep -q -- ' -K ' "$TMP/noauth.args"; then pass "sans AUDIT_BASIC_AUTH → pas de -K"; else fail "sans auth" "args : $(cat "$TMP/noauth.args")"; fi

# --- Le corps et les en-têtes attendus par le backend.
if grep -q -- ' -D ' "$TMP/nominal.args" && grep -q -- 'X-Requested-With: fetch' "$TMP/nominal.args" && grep -q -- 'Content-Type: application/json' "$TMP/nominal.args" \
   && grep -q -- 'https://example.invalid/api/login_check' "$TMP/nominal.args" && grep -q 'smoke-throttling-probe-' "$TMP/nominal.args"; then
  pass "POST /api/login_check, JSON, X-Requested-With, identifiant de sonde, en-têtes lus"
else
  fail "requête" "args : $(head -1 "$TMP/nominal.args")"
fi

# --- Sans URL : usage, code 2.
set +e; "$SCRIPT" >/dev/null 2>"$TMP/err"; rc=$?; set -e
if [ "$rc" -eq 2 ] && grep -q '^usage' "$TMP/err"; then pass "sans argument → usage, code 2"; else fail "usage" "rc=$rc"; fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "Tous les cas passent."
