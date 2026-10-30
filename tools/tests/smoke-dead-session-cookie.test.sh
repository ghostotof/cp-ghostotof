#!/usr/bin/env bash
#
# smoke-dead-session-cookie.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/smoke-dead-session-cookie.sh : un faux `curl`
# (SMOKE_CURL) rejoue des réponses canned, une par appel, au format que le
# script attend (corps, puis le code HTTP sur la dernière ligne ; en-têtes
# dans le fichier passé par `-D`), et note ses arguments pour vérifier la
# Basic Auth et le cookie envoyé.
#
# Usage :  tools/tests/smoke-dead-session-cookie.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/smoke-dead-session-cookie.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# Le faux curl : lit la n-ième ligne de $FAKE_RESPONSES (format
# `code|en-têtes|corps`, les en-têtes étant des lignes complètes séparées par
# `~`, champ vide = aucun en-tête), imprime le corps comme le vrai avec
# `-w '\n%{http_code}'`, écrit les en-têtes dans le fichier de `-D` et ajoute
# ses arguments à $FAKE_ARGS. Le corps passe par `printf '%b'` : `\r` ou
# `\033` y deviennent les vrais caractères de contrôle. Une ligne
# `curl-exit:<n>|…` fait écrire `curl: (<n>) faux échec` sur stderr et sortir
# en `<n>`, comme un vrai curl qui n'a pas de réponse (7 : connexion refusée,
# 6 : DNS, 28 : délai). Quand `-K <fichier>` est passé, le faux copie le
# fichier dans $FAKE_KCONFIG et son mode (`stat -c %a`) dans $FAKE_KMODE : le
# contenu exact de la configuration est ce qui sépare une Basic Auth d'une
# directive injectée.
cat > "$TMP/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_ARGS"
headers_file=""
while [ "$#" -gt 0 ]; do
  if [ "$1" = "-D" ]; then headers_file="$2"; shift; fi
  if [ "$1" = "-K" ]; then
    cp "$2" "$FAKE_KCONFIG"
    stat -c %a "$2" > "$FAKE_KMODE"
    shift
  fi
  shift
done
n=$(wc -l < "$FAKE_CALLS" 2>/dev/null || echo 0)
n=$((n + 1))
echo "$n" >> "$FAKE_CALLS"
line="$(sed -n "${n}p" "$FAKE_RESPONSES")"
if [[ "$line" == curl-exit:* ]]; then
  exit_code="${line#curl-exit:}"
  exit_code="${exit_code%%|*}"
  echo "curl: ($exit_code) faux échec" >&2
  exit "$exit_code"
fi
code="${line%%|*}"
rest="${line#*|}"
extra_headers="${rest%%|*}"
body="${rest#*|}"
if [ -n "$headers_file" ]; then
  {
    printf 'HTTP/2 %s\r\n' "$code"
    printf 'content-type: application/json\r\n'
    if [ -n "$extra_headers" ]; then
      IFS='~' read -r -a lines <<< "$extra_headers"
      for h in "${lines[@]}"; do printf '%s\r\n' "$h"; done
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
    FAKE_KCONFIG="$TMP/$name.kconfig" FAKE_KMODE="$TMP/$name.kmode" \
    SMOKE_CURL="$TMP/curl" "$SCRIPT" https://example.invalid >"$TMP/out" 2>"$TMP/err"
  rc=$?
  set -e
}

# Les trois réponses de la pile corrigée (#748, R35).
witness_ok='200||{"incidents":[]}'
public_ok='200||{"incidents":[]}'
# Le refus de /api/me : 401 et les deux cookies expirés, tels que
# AuthCookieFactory::expired() les écrit (en minuscules en HTTP/2).
me_cleared='401|set-cookie: BEARER=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/; secure; httponly; samesite=lax~set-cookie: XSRF-TOKEN=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/; secure; samesite=lax|{"code":401,"message":"Invalid JWT Token"}'

# --- Cas nominal : témoin 200, lecture publique 200 sans cookie posé,
# /api/me 401 qui efface le cookie → succès.
run nominal "$witness_ok" "$public_ok" "$me_cleared"
if [ "$rc" -eq 0 ] && grep -q '^OK' "$TMP/out"; then
  pass "pile corrigée (200, 200 sans Set-Cookie, 401 qui efface) → succès"
else
  fail "cas nominal" "rc=$rc ; out : $(cat "$TMP/out") ; err : $(cat "$TMP/err")"
fi
if [ "$(wc -l < "$TMP/nominal.calls")" -eq 3 ]; then pass "exactement 3 appels"; else fail "nombre d'appels" "$(wc -l < "$TMP/nominal.calls")"; fi

# --- Les URL et le cookie : le témoin n'en porte pas, les deux autres appels
# portent le même BEARER aléatoire (hexadécimal, 32 caractères).
a1="$(sed -n 1p "$TMP/nominal.args")"; a2="$(sed -n 2p "$TMP/nominal.args")"; a3="$(sed -n 3p "$TMP/nominal.args")"
cookie2="$(printf '%s' "$a2" | grep -oE 'BEARER=[0-9a-f]{32}' || true)"
cookie3="$(printf '%s' "$a3" | grep -oE 'BEARER=[0-9a-f]{32}' || true)"
if [[ "$a1" == *'https://example.invalid/api/contributions/fr'* ]] && [[ "$a1" != *' -b '* ]] && [[ "$a1" != *BEARER* ]] \
   && [[ "$a2" == *'https://example.invalid/api/contributions/fr'* ]] && [ -n "$cookie2" ] \
   && [[ "$a3" == *'https://example.invalid/api/me'* ]] && [ "$cookie2" = "$cookie3" ]; then
  pass "témoin sans cookie ; lecture publique et /api/me avec le même BEARER aléatoire"
else
  fail "requêtes" "$(cat "$TMP/nominal.args")"
fi

# --- Le cookie change d'une exécution à l'autre (un cookie fixe serait un
# motif connu de l'attaquant et pourrait tomber sur une session réelle).
run again "$witness_ok" "$public_ok" "$me_cleared"
other="$(sed -n 2p "$TMP/again.args" | grep -oE 'BEARER=[0-9a-f]{32}' || true)"
if [ -n "$other" ] && [ "$other" != "$cookie2" ]; then pass "le cookie est tiré au hasard à chaque exécution"; else fail "cookie aléatoire" "$cookie2 / $other"; fi

# --- Variantes de casse et de forme de l'effacement : en-tête en casse
# HTTP/1, `Max-Age=0` seul, `expires` passé seul → succès.
run case1 "$witness_ok" "$public_ok" '401|Set-Cookie: bearer=deleted; Max-Age=0; path=/~Set-Cookie: xsrf-token=deleted; Max-Age=0; path=/|{}'
if [ "$rc" -eq 0 ]; then pass "Set-Cookie / bearer / Max-Age=0 en casse libre → succès"; else fail "casse" "rc=$rc ; err : $(cat "$TMP/err")"; fi
run case2 "$witness_ok" "$public_ok" '401|set-cookie: BEARER=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/~set-cookie: XSRF-TOKEN=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/|{}'
if [ "$rc" -eq 0 ]; then pass "expires passé sans Max-Age → succès"; else fail "expires" "rc=$rc ; err : $(cat "$TMP/err")"; fi

# --- C1 côté serveur : la lecture publique refuse le cookie mort → échec.
run public401 "$witness_ok" '401||{"code":401,"message":"Invalid JWT Token"}'
if [ "$rc" -eq 1 ] && grep -q 'lecture publique refusée pour un cookie mort' "$TMP/err" && [ "$(wc -l < "$TMP/public401.calls")" -eq 2 ]; then
  pass "lecture publique en 401 avec cookie → échec nommé, arrêt immédiat"
else
  fail "lecture publique refusée" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- La lecture publique est servie mais efface (ou pose) un BEARER : la
# course CR3-2 s'étendrait à chaque lecture publique → échec.
run publicset "$witness_ok" '200|set-cookie: BEARER=deleted; Max-Age=0; path=/|{}'
if [ "$rc" -eq 1 ] && grep -q 'Set-Cookie BEARER' "$TMP/err" && grep -q 'lecture publique' "$TMP/err"; then
  pass "lecture publique 200 mais Set-Cookie BEARER → échec"
else
  fail "Set-Cookie sur lecture publique" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- /api/me doit refuser : un 200 voudrait dire que le cookie est accepté.
run me200 "$witness_ok" "$public_ok" '200||{"username":"x"}'
if [ "$rc" -eq 1 ] && grep -q '/api/me' "$TMP/err" && grep -q '401 attendu' "$TMP/err"; then
  pass "/api/me en 200 avec un cookie mort → échec"
else
  fail "/api/me 200" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- /api/me en 401 sans effacer le cookie : le cookie mort resterait.
run mebare "$witness_ok" "$public_ok" '401||{"code":401}'
if [ "$rc" -eq 1 ] && grep -q "n'efface pas" "$TMP/err"; then
  pass "/api/me en 401 sans Set-Cookie → échec (cookie mort laissé)"
else
  fail "/api/me sans effacement" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- /api/me pose un BEARER non expiré : ce n'est pas un effacement.
run melive "$witness_ok" "$public_ok" '401|set-cookie: BEARER=abc; path=/; secure|{}'
if [ "$rc" -eq 1 ] && grep -q "n'efface pas" "$TMP/err"; then
  pass "/api/me pose un BEARER non expiré → échec"
else
  fail "/api/me BEARER vivant" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Le témoin en 401 : le 401 vient de la Basic Auth de l'ingress, pas de
# l'application — le dire, sans aller plus loin.
run basic401 '401||<html>401 Authorization Required</html>'
if [ "$rc" -eq 1 ] && grep -q 'Basic Auth' "$TMP/err" && [ "$(wc -l < "$TMP/basic401.calls")" -eq 1 ]; then
  pass "témoin en 401 → échec « Basic Auth », arrêt immédiat"
else
  fail "témoin 401" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Le témoin en 502 (ou tout autre statut) → échec.
run witness502 '502||<html>502 Bad Gateway</html>'
if [ "$rc" -eq 1 ] && grep -q '502 reçu, 200 attendu' "$TMP/err" && [ "$(wc -l < "$TMP/witness502.calls")" -eq 1 ]; then
  pass "témoin en 502 → échec immédiat"
else
  fail "témoin 502" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Le corps affiché dans le journal de la CI est débarrassé des caractères
# de contrôle : un `\r` y ouvrirait une ligne `::commande::` du runner.
run control '502||<html>\r::error::forge\033[31m</html>'
if [ "$rc" -eq 1 ] && ! grep -q $'\r' "$TMP/err" && ! grep -q $'\033' "$TMP/err" && grep -q '::error::forge' "$TMP/err"; then
  pass "caractères de contrôle du corps neutralisés dans l'extrait"
else
  fail "caractères de contrôle" "rc=$rc ; err : $(cat -A "$TMP/err")"
fi

# --- Basic Auth : via un fichier -K, jamais -u, jamais dans les arguments ;
# absente sinon.
AUDIT_BASIC_AUTH='smoke:p"a\ss' run auth "$witness_ok" "$public_ok" "$me_cleared"
if [ "$rc" -eq 0 ] && [ "$(grep -c -- ' -K ' "$TMP/auth.args")" -eq 3 ] && ! grep -q -- ' -u ' "$TMP/auth.args"; then
  pass "AUDIT_BASIC_AUTH → -K <fichier> sur les trois appels, jamais -u"
else
  fail "basic auth" "rc=$rc ; args : $(cat "$TMP/auth.args")"
fi
if ! grep -qF -e 'smoke:' -e 'p"a' "$TMP/auth.args"; then
  pass "la Basic Auth n'apparaît jamais dans les arguments de curl"
else
  fail "secret dans les arguments" "args : $(cat "$TMP/auth.args")"
fi
run noauth "$witness_ok" "$public_ok" "$me_cleared"
if ! grep -q -- ' -K ' "$TMP/noauth.args"; then pass "sans AUDIT_BASIC_AUTH → pas de -K"; else fail "sans auth" "args : $(cat "$TMP/noauth.args")"; fi

# --- CR5-10 : un curl en échec (réseau, DNS, délai) sort du contrat 0/1/2 si
# le script laisse `set -e` rendre le code de curl. Le script doit sortir 1 et
# dire que RIEN n'a été vérifié, à chaque étape.
run net1 'curl-exit:7|'
if [ "$rc" -eq 1 ] && grep -q 'curl a échoué (code 7' "$TMP/err" && grep -q 'rien de vérifié' "$TMP/err"; then
  pass "curl en échec (7) à l'étape 1 → sortie 1, « curl a échoué (code 7) … rien de vérifié »"
else
  fail "curl en échec à l'étape 1" "rc=$rc ; err : $(cat "$TMP/err")"
fi
run net2 "$witness_ok" 'curl-exit:28|'
if [ "$rc" -eq 1 ] && grep -q 'curl a échoué (code 28' "$TMP/err" && grep -q 'rien de vérifié' "$TMP/err" && [ "$(wc -l < "$TMP/net2.calls")" -eq 2 ]; then
  pass "curl en échec (28) à l'étape 2 → sortie 1, arrêt immédiat"
else
  fail "curl en échec à l'étape 2" "rc=$rc ; err : $(cat "$TMP/err")"
fi
run net3 "$witness_ok" "$public_ok" 'curl-exit:6|'
if [ "$rc" -eq 1 ] && grep -q 'curl a échoué (code 6' "$TMP/err" && grep -q '/api/me' "$TMP/err"; then
  pass "curl en échec (6) à l'étape 3 → sortie 1, la requête est nommée"
else
  fail "curl en échec à l'étape 3" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- C5-6 : XSRF-TOKEN est vérifié comme BEARER. Étape 2 : une route publique
# n'en pose aucun.
run publicxsrf "$witness_ok" '200|set-cookie: XSRF-TOKEN=abc; path=/; secure; samesite=lax|{}'
if [ "$rc" -eq 1 ] && grep -q 'Set-Cookie XSRF-TOKEN' "$TMP/err" && grep -q 'lecture publique' "$TMP/err"; then
  pass "lecture publique 200 mais Set-Cookie XSRF-TOKEN → échec"
else
  fail "XSRF-TOKEN sur lecture publique" "rc=$rc ; err : $(cat "$TMP/err")"
fi
# Étape 3 : /api/me expire les DEUX cookies ; un seul BEARER expiré ne suffit pas.
run mexsrf "$witness_ok" "$public_ok" '401|set-cookie: BEARER=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/|{}'
if [ "$rc" -eq 1 ] && grep -q "n'efface pas" "$TMP/err" && grep -q 'aucun Set-Cookie XSRF-TOKEN expiré' "$TMP/err"; then
  pass "/api/me n'expire que BEARER → échec, le message nomme XSRF-TOKEN"
else
  fail "/api/me sans XSRF-TOKEN expiré" "rc=$rc ; err : $(cat "$TMP/err")"
fi
run mebearer "$witness_ok" "$public_ok" '401|set-cookie: XSRF-TOKEN=deleted; Max-Age=0; path=/|{}'
if [ "$rc" -eq 1 ] && grep -q "n'efface pas" "$TMP/err" && grep -q 'aucun Set-Cookie BEARER expiré' "$TMP/err"; then
  pass "/api/me n'expire que XSRF-TOKEN → échec, le message nomme BEARER"
else
  fail "/api/me sans BEARER expiré" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- C5-8 : le CONTENU du fichier -K, pas seulement sa présence. Les guillemets
# et antislashs sont échappés au format de curl, le fichier est en 600.
AUDIT_BASIC_AUTH='u:p"a\b' run kfile "$witness_ok" "$public_ok" "$me_cleared"
if [ "$rc" -eq 0 ] && [ "$(cat "$TMP/kfile.kconfig")" = 'user = "u:p\"a\\b"' ]; then
  pass "le fichier -K vaut exactement user = \"u:p\\\"a\\\\b\""
else
  fail "contenu du fichier -K" "rc=$rc ; contenu : $(cat -A "$TMP/kfile.kconfig" 2>&1)"
fi
if [ "$(cat "$TMP/kfile.kmode" 2>/dev/null)" = "600" ]; then
  pass "le fichier -K est en 600 quand curl le lit"
else
  fail "mode du fichier -K" "mode : $(cat "$TMP/kfile.kmode" 2>&1)"
fi

# --- C5-7 : un retour à la ligne dans la Basic Auth ouvrirait une seconde
# directive dans le fichier -K (`url = …`) : refus, sortie 2 (erreur
# d'environnement), aucun appel, la valeur n'est jamais citée.
for newline_value in $'u:p\nurl = "x"' $'u:p\rurl = "x"'; do
  AUDIT_BASIC_AUTH="$newline_value" run newline "$witness_ok" "$public_ok" "$me_cleared"
  if [ "$rc" -eq 2 ] && grep -q 'AUDIT_BASIC_AUTH contient un retour à la ligne' "$TMP/err" \
     && [ ! -s "$TMP/newline.calls" ] && [ ! -e "$TMP/newline.kconfig" ] \
     && ! grep -qF 'url = ' "$TMP/out" "$TMP/err"; then
    pass "AUDIT_BASIC_AUTH avec un retour à la ligne → sortie 2, aucun appel, valeur jamais citée"
  else
    fail "retour à la ligne dans AUDIT_BASIC_AUTH" "rc=$rc ; err : $(cat -A "$TMP/err") ; appels : $(cat "$TMP/newline.calls")"
  fi
done

# --- C6-7 : une bibliothèque présente mais invalide (erreur de syntaxe) ne se dit
# pas « introuvable » en cachant la vraie erreur : le script sort 2, le message
# nomme « introuvable ou invalide » ET l'erreur de bash reste sur stderr.
mkdir -p "$TMP/badlib/tools/lib"
cp "$SCRIPT" "$TMP/badlib/tools/"
printf 'if then\n' > "$TMP/badlib/tools/lib/smoke-http.sh"
# LC_ALL=C : bash dit « syntax error » en anglais, quelle que soit la langue du poste.
set +e; LC_ALL=C "$TMP/badlib/tools/smoke-dead-session-cookie.sh" https://example.invalid >/dev/null 2>"$TMP/err"; rc=$?; set -e
if [ "$rc" -eq 2 ] && grep -q 'introuvable ou invalide : tools/lib/smoke-http.sh' "$TMP/err" && grep -q 'syntax error' "$TMP/err"; then
  pass "bibliothèque invalide → sortie 2, « introuvable ou invalide » et l'erreur de syntaxe de bash visible"
else
  fail "bibliothèque invalide" "rc=$rc ; err : $(cat "$TMP/err")"
fi
rm -rf "$TMP/badlib/tools/lib"
set +e; "$TMP/badlib/tools/smoke-dead-session-cookie.sh" https://example.invalid >/dev/null 2>"$TMP/err"; rc=$?; set -e
if [ "$rc" -eq 2 ] && grep -q 'introuvable ou invalide : tools/lib/smoke-http.sh' "$TMP/err"; then
  pass "bibliothèque absente → sortie 2, même message"
else
  fail "bibliothèque absente" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Sans URL : usage, code 2.
set +e; "$SCRIPT" >/dev/null 2>"$TMP/err"; rc=$?; set -e
if [ "$rc" -eq 2 ] && grep -q '^usage' "$TMP/err"; then pass "sans argument → usage, code 2"; else fail "usage" "rc=$rc"; fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "Tous les cas passent."
