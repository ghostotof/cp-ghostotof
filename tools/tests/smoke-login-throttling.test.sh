#!/usr/bin/env bash
#
# smoke-login-throttling.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/smoke-login-throttling.sh : un faux `curl`
# (SMOKE_CURL) rejoue des réponses canned, une par appel, au format que le
# script attend (corps, puis le code HTTP sur la dernière ligne), et note ses
# arguments pour vérifier la Basic Auth.
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

# Le faux curl : lit la n-ième ligne de $FAKE_RESPONSES (format `code|corps`),
# l'imprime comme le vrai avec `-w '\n%{http_code}'`, et ajoute ses arguments
# à $FAKE_ARGS.
cat > "$TMP/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_ARGS"
n=$(wc -l < "$FAKE_CALLS" 2>/dev/null || echo 0)
n=$((n + 1))
echo "$n" >> "$FAKE_CALLS"
line="$(sed -n "${n}p" "$FAKE_RESPONSES")"
code="${line%%|*}"
body="${line#*|}"
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

invalid='401|{"code":401,"message":"Invalid credentials."}'
toomany='401|{"code":401,"message":"Too many failed login attempts, please try again in 15 minutes."}'

# --- Cas nominal : 5 refus puis le throttling au 6e → succès.
run nominal "$invalid" "$invalid" "$invalid" "$invalid" "$invalid" "$toomany"
if [ "$rc" -eq 0 ] && grep -q 'freiné à la tentative 6' "$TMP/out"; then
  pass "5 × 401 puis « Too many » au 6e → succès"
else
  fail "cas nominal" "rc=$rc ; out : $(cat "$TMP/out") ; err : $(cat "$TMP/err")"
fi
if [ "$(wc -l < "$TMP/nominal.calls")" -eq 6 ]; then pass "exactement 6 appels"; else fail "nombre d'appels" "$(wc -l < "$TMP/nominal.calls")"; fi

# --- Throttling plus tôt (compteur global par IP déjà entamé) : accepté.
run early "$invalid" "$toomany"
if [ "$rc" -eq 0 ] && [ "$(wc -l < "$TMP/early.calls")" -eq 2 ]; then
  pass "« Too many » dès le 2e → succès, arrêt immédiat"
else
  fail "throttling précoce" "rc=$rc, appels=$(wc -l < "$TMP/early.calls")"
fi

# --- Jamais freiné : le défaut de l'audit A1 → échec explicite.
run never "$invalid" "$invalid" "$invalid" "$invalid" "$invalid" "$invalid"
if [ "$rc" -eq 1 ] && grep -q 'sans jamais atteindre le throttling' "$TMP/err"; then
  pass "6 × 401 sans « Too many » → échec (A1)"
else
  fail "jamais freiné" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Un statut autre que 401 (200, 429 nginx, 502…) → échec, sans continuer.
run status "$invalid" '429|<html>429 Too Many Requests</html>'
if [ "$rc" -eq 1 ] && grep -q '429 reçu, 401 attendu' "$TMP/err" && [ "$(wc -l < "$TMP/status.calls")" -eq 2 ]; then
  pass "statut ≠ 401 → échec immédiat"
else
  fail "statut inattendu" "rc=$rc ; err : $(cat "$TMP/err")"
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
if grep -q -- 'X-Requested-With: fetch' "$TMP/nominal.args" && grep -q -- 'Content-Type: application/json' "$TMP/nominal.args" \
   && grep -q -- 'https://example.invalid/api/login_check' "$TMP/nominal.args" && grep -q 'smoke-throttling-probe-' "$TMP/nominal.args"; then
  pass "POST /api/login_check, JSON, X-Requested-With, identifiant de sonde"
else
  fail "requête" "args : $(head -1 "$TMP/nominal.args")"
fi

# --- Sans URL : usage, code 2.
set +e; "$SCRIPT" >/dev/null 2>"$TMP/err"; rc=$?; set -e
if [ "$rc" -eq 2 ] && grep -q '^usage' "$TMP/err"; then pass "sans argument → usage, code 2"; else fail "usage" "rc=$rc"; fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "Tous les cas passent."
