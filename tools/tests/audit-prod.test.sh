#!/usr/bin/env bash
#
# audit-prod.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/audit-prod.sh (issue #750) : où part la Basic Auth
# de la préprod. Un faux `curl` note les arguments de chaque appel (une ligne
# par appel, l'URL en dernier argument, comme le script la passe) ; `dig` et
# `openssl` sont de faux binaires muets. Aucun réseau, aucune vraie requête :
# le verdict de l'audit (ses échecs bloquants) n'est pas l'objet ici, seuls le
# sont les arguments des appels.
#
# curl envoie `Authorization: Basic` dès la première requête, quel que soit le
# schéma : un appel http:// qui porte `-K <fichier d'identifiants>` met
# l'identifiant en clair sur le réseau.
#
# Usage :  tools/tests/audit-prod.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/audit-prod.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

DOMAIN="audit.example.invalid"

# Des binaires factices en tête du PATH : le script les appelle par leur nom.
mkdir -p "$TMP/bin"
cat > "$TMP/bin/curl" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_CURL_LOG"
EOF
for fake in dig openssl; do
  printf '#!/usr/bin/env bash\nexit 1\n' > "$TMP/bin/$fake"
done
chmod +x "$TMP/bin/curl" "$TMP/bin/dig" "$TMP/bin/openssl"

# Un identifiant propre à ce run, jamais un secret littéral.
BASIC_AUTH="u$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n'):p$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"

# run_audit <nom> [avec-auth] : lance l'audit, laisse les appels dans $TMP/<nom>.log.
run_audit() {
  local name="$1" with_auth="${2:-}"
  : > "$TMP/$name.log"
  if [ -n "$with_auth" ]; then
    FAKE_CURL_LOG="$TMP/$name.log" PATH="$TMP/bin:$PATH" AUDIT_BASIC_AUTH="$BASIC_AUTH" \
      bash "$SCRIPT" "$DOMAIN" > "$TMP/$name.out" 2> "$TMP/$name.err" || true
  else
    FAKE_CURL_LOG="$TMP/$name.log" PATH="$TMP/bin:$PATH" \
      bash "$SCRIPT" "$DOMAIN" > "$TMP/$name.out" 2> "$TMP/$name.err" || true
  fi
}

# calls_to <nom> <préfixe d'URL> : les appels dont l'URL (dernier argument)
# commence par le préfixe.
calls_to() {
  local line
  while IFS= read -r line; do
    case "${line##* }" in "$2"*) printf '%s\n' "$line" ;; esac
  done < "$TMP/$1.log"
}

echo "audit-prod.sh — Basic Auth de la préprod (AUDIT_BASIC_AUTH fourni)"
run_audit avec auth

if [ ! -s "$TMP/avec.log" ]; then
  fail "l'audit a appelé curl" "aucun appel journalisé : $(head -c 300 "$TMP/avec.err")"
fi

http_calls="$(calls_to avec 'http://')"
if [ -z "$http_calls" ]; then
  fail "des sondes http:// ont été émises" "aucun appel http:// : le test ne prouverait rien"
else
  with_k="$(grep -E -- '(^| )-K( |$)' <<< "$http_calls" || true)"
  if [ -z "$with_k" ]; then
    pass "aucun appel http:// ne porte -K"
  else
    fail "aucun appel http:// ne porte -K" "la Basic Auth part en clair : ${with_k//$'\n'/ | }"
  fi
fi

www_https_calls="$(calls_to avec "https://www.${DOMAIN}")"
if [ -z "$www_https_calls" ]; then
  fail "la sonde https://www. a été émise" "aucun appel"
elif grep -qE -- '(^| )-K( |$)' <<< "$www_https_calls"; then
  fail "la sonde de redirection https://www. part sans -K" "$www_https_calls"
else
  pass "la sonde de redirection https://www. part sans -K"
fi

apex_calls="$(calls_to avec "https://${DOMAIN}")"
if [ -z "$apex_calls" ]; then
  fail "des appels https:// vers l'apex ont été émis" "aucun appel"
elif [ "$(grep -cvE -- '(^| )-K( |$)' <<< "$apex_calls" || true)" -eq 0 ]; then
  pass "chaque appel https:// vers l'apex porte -K (la préprod reste authentifiée)"
else
  fail "chaque appel https:// vers l'apex porte -K" "$(grep -vE -- '(^| )-K( |$)' <<< "$apex_calls")"
fi

if grep -qF -- "$BASIC_AUTH" "$TMP/avec.log"; then
  fail "l'identifiant n'apparaît dans aucun argument" "visible dans le journal des appels"
else
  pass "l'identifiant n'apparaît dans aucun argument (fichier -K uniquement)"
fi

echo "audit-prod.sh — sans AUDIT_BASIC_AUTH (prod, non protégée)"
run_audit sans

if [ ! -s "$TMP/sans.log" ]; then
  fail "l'audit a appelé curl" "aucun appel journalisé : $(head -c 300 "$TMP/sans.err")"
elif grep -qE -- '(^| )-K( |$)' "$TMP/sans.log"; then
  fail "aucun appel ne porte -K" "$(grep -E -- '(^| )-K( |$)' "$TMP/sans.log")"
else
  pass "aucun appel ne porte -K"
fi

echo
if [ "$failures" -gt 0 ]; then
  echo "$failures échec(s)."
  exit 1
fi
echo "Tous les tests passent."
