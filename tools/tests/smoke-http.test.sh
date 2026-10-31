#!/usr/bin/env bash
#
# smoke-http.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/lib/smoke-http.sh (smoke_write_auth_config, smoke_cleanup,
# smoke_header, smoke_headers, smoke_excerpt, smoke_fetch) : un faux `curl` rejoue une réponse canned et note
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

# --- À la source, SMOKE_AUTH_DIR et SMOKE_AUTH_CONFIG sont vides : une valeur
# héritée de l'environnement n'est jamais crue (C7-6), sinon `smoke_cleanup` ou
# le `trap` d'un script supprimerait un fichier qui n'est pas le sien.
inherited="$(SMOKE_AUTH_DIR=/herite/dossier SMOKE_AUTH_CONFIG=/herite/fichier \
  bash -c 'source "$1"; printf "[%s][%s]" "${SMOKE_AUTH_DIR-absente}" "${SMOKE_AUTH_CONFIG-absente}"' _ "$LIB")"
if [ "$inherited" = "[][]" ]; then
  pass "sourcer vide SMOKE_AUTH_DIR et SMOKE_AUTH_CONFIG hérités de l'environnement"
else
  fail "variables héritées" "obtenu : $inherited"
fi
# Et `smoke_cleanup` n'y touche pas : un dossier témoin hérité survit.
mkdir "$TMP/herite"
rc=0
SMOKE_AUTH_DIR="$TMP/herite" bash -c 'source "$1"; smoke_cleanup' _ "$LIB" || rc=$?
if [ "$rc" -eq 0 ] && [ -d "$TMP/herite" ]; then
  pass "smoke_cleanup ne supprime pas un dossier hérité de l'environnement"
else
  fail "dossier hérité supprimé" "rc=$rc ; dossier : $([ -d "$TMP/herite" ] && echo présent || echo disparu)"
fi

# --- smoke_write_auth_config : le fichier -K, échappé au format de curl, en 600,
# dans un dossier privé en 700 sous TMPDIR, écrit dans le shell courant (la
# valeur ne passe en argument d'aucune commande) ; rien sur stdout.
# Lue par smoke_write_auth_config, par indirection (${!nom}).
# shellcheck disable=SC2034
AUDIT_BASIC_AUTH='u:p"a\b'
rc=0
smoke_write_auth_config AUDIT_BASIC_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
config="$SMOKE_AUTH_CONFIG"
if [ "$rc" -eq 0 ] && [ "$(cat "$config")" = 'user = "u:p\"a\\b"' ] && [ "$(stat -c %a "$config")" = "600" ] \
   && [ ! -s "$TMP/stdout" ] && [ ! -s "$TMP/stderr" ]; then
  pass "smoke_write_auth_config : user = \"u:p\\\"a\\\\b\", mode 600, rien imprimé"
else
  fail "smoke_write_auth_config" "rc=$rc ; config : $config ; contenu : $(cat -A "$config" 2>&1) ; mode : $(stat -c %a "$config" 2>&1) ; stdout : $(cat -A "$TMP/stdout")"
fi
if [ -n "$SMOKE_AUTH_DIR" ] && [ "$(dirname "$config")" = "$SMOKE_AUTH_DIR" ] && [ "$(dirname "$SMOKE_AUTH_DIR")" = "$TMPDIR" ] \
   && [ "$(stat -c %a "$SMOKE_AUTH_DIR")" = "700" ]; then
  pass "le fichier -K vit dans un dossier privé (700) sous TMPDIR"
else
  fail "dossier privé" "dossier : ${SMOKE_AUTH_DIR:-vide} ; config : $config ; mode : $(stat -c %a "${SMOKE_AUTH_DIR:-/nonexistent}" 2>&1)"
fi
dir="$SMOKE_AUTH_DIR"
rc=0
smoke_cleanup || rc=$?
if [ "$rc" -eq 0 ] && [ ! -e "$dir" ] && [ -z "$SMOKE_AUTH_DIR" ] && [ -z "$SMOKE_AUTH_CONFIG" ] && [ -z "$(ls -A "$TMP/tmpdir")" ]; then
  pass "smoke_cleanup retire le dossier, vide les deux variables, retourne 0"
else
  fail "smoke_cleanup" "rc=$rc ; dossier : $dir ; variables : [$SMOKE_AUTH_DIR][$SMOKE_AUTH_CONFIG] ; TMPDIR : $(ls -A "$TMP/tmpdir")"
fi
# Sans rien à retirer, un second appel retourne encore 0.
rc=0; smoke_cleanup || rc=$?
if [ "$rc" -eq 0 ]; then pass "smoke_cleanup sans dossier → 0"; else fail "smoke_cleanup à vide" "rc=$rc"; fi

# --- Décision 6 de #748 : un second smoke_write_auth_config remplace le premier.
# smoke_cleanup est appelé en tête : le dossier du premier appel est retiré avant
# que le second naisse, donc un seul dossier existe (celui du second) et aucun
# fichier d'identifiant ne reste derrière.
# Lue par smoke_write_auth_config, par indirection (${!nom}).
# shellcheck disable=SC2034
UNE_AUTH='first-user:first-pass'
smoke_write_auth_config UNE_AUTH
first_dir="$SMOKE_AUTH_DIR"
# shellcheck disable=SC2034
UNE_AUTH='second-user:second-pass'
smoke_write_auth_config UNE_AUTH
second_dir="$SMOKE_AUTH_DIR"
if [ -n "$first_dir" ] && [ -n "$second_dir" ] && [ "$first_dir" != "$second_dir" ] \
   && [ ! -e "$first_dir" ] && [ -d "$second_dir" ] && [ "$(ls -A "$TMP/tmpdir" | wc -l)" -eq 1 ] \
   && [ "$SMOKE_AUTH_CONFIG" = "$second_dir/curl-auth.conf" ] \
   && [ "$(cat "$SMOKE_AUTH_CONFIG")" = 'user = "second-user:second-pass"' ]; then
  pass "un second smoke_write_auth_config remplace le premier : un seul dossier, celui du second"
else
  fail "second appel de smoke_write_auth_config" "premier : $first_dir ($([ -e "$first_dir" ] && echo survit || echo retiré)) ; second : $second_dir ; TMPDIR : $(ls -A "$TMP/tmpdir" | tr '\n' ' ')"
fi
smoke_cleanup
unset UNE_AUTH
# Un dossier hérité de l'environnement (jamais créé par la bibliothèque) n'est
# supprimé ni par le second appel ni par smoke_cleanup : la source vide
# SMOKE_AUTH_DIR, donc l'appel de tête ne vise rien d'hérité.
mkdir "$TMP/herite2"
rc=0
SMOKE_AUTH_DIR="$TMP/herite2" UNE_AUTH='u:p' bash -c '
  source "$1"
  smoke_write_auth_config UNE_AUTH && smoke_write_auth_config UNE_AUTH && smoke_cleanup' _ "$LIB" || rc=$?
if [ "$rc" -eq 0 ] && [ -d "$TMP/herite2" ] && [ -z "$(ls -A "$TMP/tmpdir")" ]; then
  pass "un dossier hérité de l'environnement survit à deux smoke_write_auth_config et à smoke_cleanup"
else
  fail "dossier hérité et second appel" "rc=$rc ; dossier hérité : $([ -d "$TMP/herite2" ] && echo présent || echo disparu) ; TMPDIR : $(ls -A "$TMP/tmpdir" | tr '\n' ' ')"
fi

# --- Un retour à la ligne ou un CR ouvrirait une seconde directive : retour 2,
# message sur stderr, rien sur stdout, aucun fichier, la valeur jamais citée.
for newline_value in $'u:p\nurl = "x"' $'u:p\rurl = "x"'; do
  # Lue par smoke_write_auth_config, par indirection (${!nom}).
  # shellcheck disable=SC2034
  AUDIT_BASIC_AUTH="$newline_value"
  rc=0
  smoke_write_auth_config AUDIT_BASIC_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  if [ "$rc" -eq 2 ] && grep -q 'AUDIT_BASIC_AUTH contient un retour à la ligne' "$TMP/stderr" \
     && [ ! -s "$TMP/stdout" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] && [ -z "$SMOKE_AUTH_CONFIG" ] \
     && ! grep -qF -e 'u:p' -e 'url = ' "$TMP/stdout" "$TMP/stderr"; then
    pass "smoke_write_auth_config : retour à la ligne ou CR → retour 2, aucun fichier, valeur jamais citée"
  else
    fail "smoke_write_auth_config : retour à la ligne" "rc=$rc ; stderr : $(cat -A "$TMP/stderr") ; fichiers : $(ls -A "$TMP/tmpdir")"
  fi
done

# --- C6-9 : le refus nomme la variable reçue, pas une variable écrite en dur.
# shellcheck disable=SC2034
AUTRE_VAR=$'a\nb'
rc=0
smoke_write_auth_config AUTRE_VAR > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 2 ] && grep -q '^AUTRE_VAR contient un retour à la ligne' "$TMP/stderr" \
   && ! grep -q 'AUDIT_BASIC_AUTH' "$TMP/stderr" && [ -z "$(ls -A "$TMP/tmpdir")" ]; then
  pass "smoke_write_auth_config : le refus nomme la variable reçue (AUTRE_VAR), aucun fichier"
else
  fail "smoke_write_auth_config : nom de la variable" "rc=$rc ; stderr : $(cat -A "$TMP/stderr")"
fi
unset AUDIT_BASIC_AUTH AUTRE_VAR

# --- C7-7 : le nom est un identifiant, jamais une expression. `${!nom}` évalue
# un indice : un nom `x[$(commande)]` exécuterait la commande. Retour 2, aucune
# commande exécutée, aucun fichier. CR8-5 : un nom qui commence par `_smoke_` (les locales
# de la bibliothèque) est refusé aussi. C9-5 : de même `SMOKE_AUTH_` (SMOKE_AUTH_DIR et
# SMOKE_AUTH_CONFIG, que le `smoke_cleanup` de tête remet à vide avant la lecture : le
# retour serait 0 et l'appelant croirait avoir posé une Basic Auth).
hostile='x[$(touch "$TMP/pwned")]'
for bad_name in "$hostile" '1abc' 'a-b' '' '_smoke_x' '_smoke_name' SMOKE_AUTH_CONFIG SMOKE_AUTH_DIR SMOKE_AUTH_X; do
  SMOKE_CURL_OPTS=(-sS)
  rc=0
  smoke_write_auth_config "$bad_name" > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  if [ "$rc" -eq 2 ] && [ ! -e "$TMP/pwned" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] && [ "${#SMOKE_CURL_OPTS[@]}" -eq 1 ] \
     && grep -q "^smoke_write_auth_config : nom de variable invalide" "$TMP/stderr"; then
    pass "smoke_write_auth_config : nom invalide (${bad_name:-vide}) → retour 2, aucune commande exécutée"
  else
    fail "smoke_write_auth_config : nom invalide" "nom : ${bad_name:-vide} ; rc=$rc ; pwned : $([ -e "$TMP/pwned" ] && echo créé || echo non) ; stderr : $(cat -A "$TMP/stderr")"
  fi
  rm -f "$TMP/pwned"
done

# --- St7-1 : aucune fonction ne fait sortir l'appelant. Sans argument, le retour
# est 2 et la ligne suivante de l'appelant s'exécute (`${1:?}` faisait sortir
# tout shell non interactif).
after="$( set +e; smoke_write_auth_config 2>/dev/null; echo "suite rc=$?" )"
if [ "$after" = "suite rc=2" ]; then
  pass "smoke_write_auth_config sans argument → retour 2, l'appelant continue"
else
  fail "smoke_write_auth_config sans argument" "obtenu : « $after »"
fi

# --- C8-1 : la bibliothèque juge le vide. Une variable vide ou absente : retour 0,
# aucun dossier, SMOKE_AUTH_CONFIG vide. `set -u` est actif : une variable absente
# ne doit pas faire échouer `${!nom}`.
unset UNE_AUTH
rc=0
smoke_write_auth_config UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 0 ] && [ -z "$SMOKE_AUTH_CONFIG" ] && [ -z "$SMOKE_AUTH_DIR" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] \
   && [ ! -s "$TMP/stdout" ] && [ ! -s "$TMP/stderr" ]; then
  pass "smoke_write_auth_config : variable absente → retour 0, aucun dossier, SMOKE_AUTH_CONFIG vide"
else
  fail "smoke_write_auth_config (absente)" "rc=$rc ; config : [$SMOKE_AUTH_CONFIG] ; fichiers : $(ls -A "$TMP/tmpdir")"
fi
UNE_AUTH=""
rc=0
smoke_write_auth_config UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 0 ] && [ -z "$SMOKE_AUTH_CONFIG" ] && [ -z "$SMOKE_AUTH_DIR" ] && [ -z "$(ls -A "$TMP/tmpdir")" ]; then
  pass "smoke_write_auth_config : variable vide → retour 0, aucun dossier, SMOKE_AUTH_CONFIG vide"
else
  fail "smoke_write_auth_config (vide)" "rc=$rc ; config : [$SMOKE_AUTH_CONFIG] ; fichiers : $(ls -A "$TMP/tmpdir")"
fi
# Une variable vide après une posée : le premier fichier est retiré (smoke_cleanup en tête).
UNE_AUTH='u:p'
smoke_write_auth_config UNE_AUTH
UNE_AUTH=""
smoke_write_auth_config UNE_AUTH
if [ -z "$SMOKE_AUTH_CONFIG" ] && [ -z "$(ls -A "$TMP/tmpdir")" ]; then
  pass "smoke_write_auth_config : une variable vide après une posée retire le premier dossier"
else
  fail "vide après posée" "config : [$SMOKE_AUTH_CONFIG] ; fichiers : $(ls -A "$TMP/tmpdir")"
fi

# --- CR8-5 : une variable qui porte le nom d'une locale de l'ancienne version
# (`name`, `value`, `rc`, `traced`, `saved_umask`, `empty`) est lue pour ce qu'elle
# est, jamais masquée par une locale de la fonction.
for shadow in name value rc traced saved_umask empty; do
  printf -v "$shadow" '%s' 'u:p'
  rc_call=0
  smoke_write_auth_config "$shadow" || rc_call=$?
  if [ "$rc_call" -eq 0 ] && [ "$(cat "$SMOKE_AUTH_CONFIG" 2>/dev/null)" = 'user = "u:p"' ]; then
    pass "smoke_write_auth_config $shadow : le fichier porte la valeur de la variable $shadow"
  else
    fail "smoke_write_auth_config $shadow" "rc=$rc_call ; contenu : $(cat -A "$SMOKE_AUTH_CONFIG" 2>&1)"
  fi
  smoke_cleanup
  unset "$shadow"
done

# --- Un dossier qui ne se crée pas (TMPDIR absent) : retour 2, rien sur stdout,
# aucune variable posée.
rc=0
UNE_AUTH='u:p'
TMPDIR="$TMP/absent" smoke_write_auth_config UNE_AUTH > "$TMP/stdout" 2> /dev/null || rc=$?
if [ "$rc" -eq 2 ] && [ ! -s "$TMP/stdout" ] && [ -z "$SMOKE_AUTH_CONFIG" ] && [ -z "$SMOKE_AUTH_DIR" ]; then
  pass "smoke_write_auth_config : dossier impossible à créer → retour 2, rien posé"
else
  fail "smoke_write_auth_config (TMPDIR absent)" "rc=$rc ; dossier : [$SMOKE_AUTH_DIR]"
fi

# --- C7-9 : sous `set -x`, la valeur n'apparaît pas dans la trace (ni en
# argument d'une fonction, ni dans une commande), et l'état de xtrace est rétabli.
# La trace est lue dans un sous-shell : le dossier y est retiré avant la sortie.
# Lue par smoke_write_auth_config, par indirection (${!nom}).
# shellcheck disable=SC2034
UNE_AUTH='traced-user:traced-pass-value'
(
  set -x
  smoke_write_auth_config UNE_AUTH
  case "$-" in *x*) echo rétabli > "$TMP/xtrace-state" ;; esac
  cat "$SMOKE_AUTH_CONFIG" > "$TMP/xtrace-content"
  smoke_cleanup
  set +x
) > /dev/null 2> "$TMP/trace"
if ! grep -qF -e 'traced-pass-value' -e 'traced-user' "$TMP/trace" && [ -s "$TMP/trace" ] \
   && [ "$(cat "$TMP/xtrace-content" 2>/dev/null)" = 'user = "traced-user:traced-pass-value"' ]; then
  pass "sous set -x, la valeur n'apparaît pas dans la trace, le fichier est écrit"
else
  fail "valeur tracée" "trace : $(grep -F -e 'traced-' "$TMP/trace" | head -3) ; contenu : $(cat "$TMP/xtrace-content" 2>&1)"
fi
if [ "$(cat "$TMP/xtrace-state" 2>/dev/null)" = "rétabli" ]; then
  pass "sous set -x, xtrace est rétabli après l'appel"
else
  fail "xtrace rétabli" "état : $(cat "$TMP/xtrace-state" 2>&1)"
fi
# Sans set -x au départ, la fonction n'en allume pas.
(
  smoke_write_auth_config UNE_AUTH
  case "$-" in *x*) echo allumé > "$TMP/xtrace-on" ;; esac
  smoke_cleanup
)
if [ ! -e "$TMP/xtrace-on" ]; then
  pass "sans set -x au départ, la fonction n'allume pas xtrace"
else
  fail "xtrace allumé" "la fonction a activé xtrace"
fi
unset UNE_AUTH

# --- smoke_header <fichier> <nom> : nom insensible à la casse, CR retiré,
# dernière occurrence ; un en-tête absent donne une valeur vide.
printf 'HTTP/2 429\r\nretry-after: 12\r\nContent-Type: text/plain\r\nRETRY-AFTER: 30\r\n\r\n' > "$TMP/headers"
if [ "$(smoke_header "$TMP/headers" Retry-After)" = "30" ] \
   && [ "$(smoke_header "$TMP/headers" content-type)" = "text/plain" ] \
   && [ -z "$(smoke_header "$TMP/headers" X-Absent)" ]; then
  pass "smoke_header : nom sans casse, CR retiré, dernière occurrence, absent → vide"
else
  fail "smoke_header" "retry-after : $(smoke_header "$TMP/headers" Retry-After | cat -A) ; content-type : $(smoke_header "$TMP/headers" content-type | cat -A)"
fi

# --- smoke_headers <fichier> <nom> (St7-3) : toutes les valeurs, une par ligne,
# dans l'ordre, nom sans casse, CR retiré ; smoke_header en est la dernière ligne.
printf 'HTTP/2 401\r\nset-cookie: BEARER=a; Max-Age=0\r\nContent-Type: text/plain\r\nSet-Cookie: XSRF-TOKEN=b; Max-Age=0\r\n\r\n' > "$TMP/cookies"
rc=0
cookies="$(smoke_headers "$TMP/cookies" Set-Cookie)" || rc=$?
if [ "$rc" -eq 0 ] && [ "$cookies" = $'BEARER=a; Max-Age=0\nXSRF-TOKEN=b; Max-Age=0' ] \
   && [ "$(smoke_header "$TMP/cookies" SET-COOKIE)" = 'XSRF-TOKEN=b; Max-Age=0' ] \
   && [ -z "$(smoke_headers "$TMP/cookies" X-Absent)" ]; then
  pass "smoke_headers : les deux Set-Cookie, sans casse, CR retiré, absent → vide ; smoke_header = la dernière"
else
  fail "smoke_headers" "rc=$rc ; obtenu : $(printf '%s' "$cookies" | cat -A)"
fi

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

SMOKE_CURL="$TMP/curl"
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

# --- St8-12 : smoke_fetch lit lui-même la Basic Auth. Posée, curl reçoit `-K <fichier>`
# après SMOKE_CURL_OPTS ; absente, aucun -K ; deux smoke_write_auth_config
# successifs : un seul -K, celui du second fichier, qui existe encore.
smoke_cleanup
: > "$FAKE_ARGS"
FAKE_STATUS=200 smoke_fetch "GET /a" https://example.invalid/a
if ! grep -q -- ' -K ' "$FAKE_ARGS"; then
  pass "smoke_fetch : sans Basic Auth, aucun -K"
else
  fail "smoke_fetch sans auth" "$(cat "$FAKE_ARGS")"
fi
UNE_AUTH='first:one'
smoke_write_auth_config UNE_AUTH
first_conf="$SMOKE_AUTH_CONFIG"
: > "$FAKE_ARGS"
FAKE_STATUS=200 smoke_fetch "GET /b" https://example.invalid/b
if [ "$(cat "$FAKE_ARGS")" = "-sS --max-time 15 -K $first_conf -w \\n%{http_code} https://example.invalid/b" ]; then
  pass "smoke_fetch : Basic Auth posée → -K <fichier> après les options de l'appelant"
else
  fail "smoke_fetch avec auth" "$(cat -A "$FAKE_ARGS")"
fi
# shellcheck disable=SC2034
UNE_AUTH='second:two'
smoke_write_auth_config UNE_AUTH
: > "$FAKE_ARGS"
FAKE_STATUS=200 smoke_fetch "GET /c" https://example.invalid/c
if [ "$(grep -o -- ' -K ' "$FAKE_ARGS" | wc -l)" -eq 1 ] && grep -qF -- "-K $SMOKE_AUTH_CONFIG " "$FAKE_ARGS" \
   && [ -f "$SMOKE_AUTH_CONFIG" ] && [ ! -e "$first_conf" ] && [[ "${SMOKE_CURL_OPTS[*]}" != *-K* ]]; then
  pass "deux smoke_write_auth_config : curl reçoit un seul -K, celui du second fichier, qui existe ; jamais de -K dans SMOKE_CURL_OPTS"
else
  fail "second smoke_write_auth_config puis smoke_fetch" "$(cat "$FAKE_ARGS") ; options : ${SMOKE_CURL_OPTS[*]}"
fi
smoke_cleanup
unset UNE_AUTH

# --- C9-4 : une Basic Auth écrite dont le fichier a disparu ne part pas sans elle :
# smoke_fetch rend 2, dit « fichier de la Basic Auth absent — rien de vérifié » et
# n'appelle pas curl (un appel sans -K lirait le 401 de la préprod comme un résultat).
# Lue par smoke_write_auth_config, par indirection (${!nom}).
# shellcheck disable=SC2034
UNE_AUTH='gone:file'
smoke_write_auth_config UNE_AUTH
rm -f "$SMOKE_AUTH_CONFIG"
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /gone" https://example.invalid/gone > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 2 ] && grep -q '^GET /gone : fichier de la Basic Auth absent — rien de vérifié$' "$TMP/stderr" \
   && [ ! -s "$FAKE_ARGS" ] && [ ! -s "$TMP/stdout" ]; then
  pass "smoke_fetch : fichier -K disparu → retour 2, « fichier de la Basic Auth absent », curl jamais appelé"
else
  fail "smoke_fetch fichier -K absent" "rc=$rc ; stderr : $(cat -A "$TMP/stderr") ; appels : $(cat "$FAKE_ARGS")"
fi
smoke_cleanup
unset UNE_AUTH

# --- C9-4 : les appelants de smoke_fetch rendent son code (1 ou 2), jamais un `exit 1`
# qui ferait d'un fichier de Basic Auth absent un échec de réseau.
# Un appel s'étend parfois sur plusieurs lignes (`\` en fin de ligne) : on les joint d'abord.
TOOLS_DIR="$(dirname "$(dirname "$LIB")")"
joined_calls() {
  sed -e ':a' -e '/\\$/{N;s/\\\n//;ba' -e '}' "$1" | grep 'smoke_fetch' || true
}
for script in "$TOOLS_DIR"/*.sh; do joined_calls "$script" | sed "s|^|$(basename "$script"): |"; done | grep -F '|| exit 1' > "$TMP/exit1" || true
if [ ! -s "$TMP/exit1" ]; then
  pass "aucun appel de smoke_fetch n'est suivi de « || exit 1 »"
else
  fail "smoke_fetch || exit 1" "$(cat "$TMP/exit1")"
fi
for caller in smoke-dead-session-cookie smoke-login-throttling smoke-mcp; do
  if joined_calls "$TOOLS_DIR/$caller.sh" | grep -qF '|| exit "$?"'; then
    pass "$caller.sh : smoke_fetch … || exit \"\$?\""
  else
    fail "$caller.sh" "aucun smoke_fetch suivi de || exit \"\$?\""
  fi
done

# --- Sans SMOKE_CURL, c'est `curl` du PATH : le faux est placé en tête.
mkdir "$TMP/bin"; cp "$TMP/curl" "$TMP/bin/curl"
unset SMOKE_CURL
rc=0
PATH="$TMP/bin:$PATH" smoke_fetch "GET /y" https://example.invalid/y || rc=$?
if [ "$rc" -eq 0 ] && [ "$SMOKE_STATUS" = "200" ]; then
  pass "smoke_fetch : SMOKE_CURL absent → curl du PATH"
else
  fail "smoke_fetch sans SMOKE_CURL" "rc=$rc ; statut : $SMOKE_STATUS"
fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "Tous les cas passent."
