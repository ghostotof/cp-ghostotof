#!/usr/bin/env bash
#
# smoke-http.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/lib/smoke-http.sh (smoke_auth_config_file,
# smoke_excerpt, smoke_fetch) : un faux `curl` rejoue une réponse canned et note
# ses arguments, aucun appel réseau, aucune écriture dans le dépôt courant.
# Les scripts qui sourcent la bibliothèque ont chacun leur test (le contrat de
# leurs sorties) ; celui-ci épingle ce que la bibliothèque promet seule.
#
# Usage :  tools/tests/smoke-http.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

LIB="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/smoke-http.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# Les fichiers temporaires de la bibliothèque naissent dans un dossier à part :
# on y voit qu'un refus n'en laisse aucun.
mkdir "$TMP/tmpdir"
export TMPDIR="$TMP/tmpdir"

# --- Sourcer ne change aucune option du shell et n'appelle rien.
errexit_before="$(set +o | grep errexit)"
# shellcheck source=tools/lib/smoke-http.sh
source "$LIB"
if [ "$(set +o | grep errexit)" = "$errexit_before" ] && [ -z "$(ls -A "$TMP/tmpdir")" ]; then
  pass "sourcer la bibliothèque ne change pas errexit et ne crée aucun fichier"
else
  fail "source" "errexit : $(set +o | grep errexit) ; fichiers : $(ls -A "$TMP/tmpdir")"
fi

# --- smoke_auth_config_file : le fichier -K, échappé au format de curl, en 600.
config="$(smoke_auth_config_file 'u:p"a\b')"
if [ "$(cat "$config")" = 'user = "u:p\"a\\b"' ] && [ "$(stat -c %a "$config")" = "600" ]; then
  pass "smoke_auth_config_file : user = \"u:p\\\"a\\\\b\", mode 600, chemin imprimé"
else
  fail "smoke_auth_config_file" "chemin : $config ; contenu : $(cat -A "$config" 2>&1) ; mode : $(stat -c %a "$config" 2>&1)"
fi
rm -f "$config"

# --- Un retour à la ligne ou un CR ouvrirait une seconde directive : retour 2,
# message sur stderr, rien sur stdout, aucun fichier, la valeur jamais citée.
for newline_value in $'u:p\nurl = "x"' $'u:p\rurl = "x"'; do
  set +e
  smoke_auth_config_file "$newline_value" > "$TMP/stdout" 2> "$TMP/stderr"
  rc=$?
  set -e
  if [ "$rc" -eq 2 ] && grep -q 'AUDIT_BASIC_AUTH contient un retour à la ligne' "$TMP/stderr" \
     && [ ! -s "$TMP/stdout" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] \
     && ! grep -qF -e 'u:p' -e 'url = ' "$TMP/stdout" "$TMP/stderr"; then
    pass "smoke_auth_config_file : retour à la ligne ou CR → retour 2, aucun fichier, valeur jamais citée"
  else
    fail "smoke_auth_config_file : retour à la ligne" "rc=$rc ; stderr : $(cat -A "$TMP/stderr") ; fichiers : $(ls -A "$TMP/tmpdir")"
  fi
done

# --- smoke_excerpt : caractères de contrôle en espaces, 160 caractères.
excerpt="$(smoke_excerpt $'<html>\r::error::forge\033[31m</html>')"
if [[ "$excerpt" != *$'\r'* && "$excerpt" != *$'\033'* && "$excerpt" == *'::error::forge'* ]]; then
  pass "smoke_excerpt : les caractères de contrôle deviennent des espaces"
else
  fail "smoke_excerpt (contrôle)" "$(printf '%s' "$excerpt" | cat -A)"
fi
excerpt="$(smoke_excerpt "$(head -c 500 /dev/zero | tr '\0' 'a')")"
if [ "${#excerpt}" -eq 160 ]; then pass "smoke_excerpt : 160 caractères au plus"; else fail "smoke_excerpt (longueur)" "${#excerpt}"; fi

# --- smoke_fetch : le faux curl note ses arguments, rend un corps sur
# plusieurs lignes puis le code, ou échoue avec le code de $FAKE_CURL_EXIT.
cat > "$TMP/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_ARGS"
if [ -n "${FAKE_CURL_EXIT:-}" ]; then
  echo "curl: (${FAKE_CURL_EXIT}) faux échec" >&2
  exit "$FAKE_CURL_EXIT"
fi
printf 'ligne 1\nligne 2\n%s' "${FAKE_STATUS:-200}"
EOF
chmod +x "$TMP/curl"
export FAKE_ARGS="$TMP/args"
: > "$FAKE_ARGS"

SMOKE_CURL_BIN="$TMP/curl"
SMOKE_CURL_OPTS=(-sS --max-time 15)

SMOKE_STATUS=""; SMOKE_BODY=""
rc=0
FAKE_STATUS=404 smoke_fetch "GET /x" https://example.invalid/x -b "BEARER=abc" || rc=$?
if [ "$rc" -eq 0 ] && [ "$SMOKE_STATUS" = "404" ] && [ "$SMOKE_BODY" = $'ligne 1\nligne 2' ]; then
  pass "smoke_fetch : SMOKE_STATUS = dernière ligne, SMOKE_BODY = le reste (plusieurs lignes)"
else
  fail "smoke_fetch nominal" "rc=$rc ; statut : $SMOKE_STATUS ; corps : $SMOKE_BODY"
fi
# `-w` reçoit les deux caractères `\n` : c'est curl qui les interprète.
if [ "$(cat "$FAKE_ARGS")" = '-sS --max-time 15 -b BEARER=abc -w \n%{http_code} https://example.invalid/x' ]; then
  pass "smoke_fetch : options, arguments de l'appelant, -w, puis l'URL"
else
  fail "smoke_fetch arguments" "$(cat -A "$FAKE_ARGS")"
fi

# --- Un échec de curl : retour 1 (jamais le code de curl), le libellé, le code
# et « rien de vérifié » sur stderr ; rien sur stdout. Appelé sous `set -e`, la
# bibliothèque ne doit pas faire sortir l'appelant : c'est `|| exit 1` qui décide.
for curl_code in 6 7 28; do
  rc=0
  FAKE_CURL_EXIT="$curl_code" smoke_fetch "tentative 3" https://example.invalid/x > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  if [ "$rc" -eq 1 ] && grep -q "^tentative 3 : curl a échoué (code $curl_code : réseau, DNS ou délai) — rien de vérifié\$" "$TMP/stderr" \
     && [ ! -s "$TMP/stdout" ]; then
    pass "smoke_fetch : curl en échec ($curl_code) → retour 1, libellé, code, « rien de vérifié »"
  else
    fail "smoke_fetch curl $curl_code" "rc=$rc ; stderr : $(cat -A "$TMP/stderr")"
  fi
done

# --- Sans SMOKE_CURL_BIN, c'est `curl` du PATH : le faux est placé en tête.
mkdir "$TMP/bin"; cp "$TMP/curl" "$TMP/bin/curl"
unset SMOKE_CURL_BIN
rc=0
PATH="$TMP/bin:$PATH" smoke_fetch "GET /y" https://example.invalid/y || rc=$?
if [ "$rc" -eq 0 ] && [ "$SMOKE_STATUS" = "200" ]; then
  pass "smoke_fetch : SMOKE_CURL_BIN absent → curl du PATH"
else
  fail "smoke_fetch sans SMOKE_CURL_BIN" "rc=$rc ; statut : $SMOKE_STATUS"
fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "Tous les cas passent."
