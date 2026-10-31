#!/usr/bin/env bash
#
# audit-prod.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de ce que tools/audit-prod.sh fait de ses bibliothèques et de
# sa Basic Auth (#748, R62 ; constats C6-7 et C6-8). Pas de test de ses
# vérifications : elles se mesurent contre un site réel (job audit-preprod).
#
# Aucun appel réseau : `dig`, `curl` et `openssl` sont des faux, placés en tête
# du PATH, qui notent leur appel dans $FAKE_CALLS puis échouent sans rien dire.
# Le script, copié dans $TMP/tools/ avec ses bibliothèques, court donc jusqu'au
# bout (sortie 1 : tous ses contrôles échouent) sans rien joindre.
#
# Usage :  tools/tests/audit-prod.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# check_empty_basic_auth, generated_basic_auth : les cas communs aux smokes et à l'audit.
# shellcheck source=tools/tests/lib/smoke-cases.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/smoke-cases.sh"

# Les faux : ils notent « <nom> <arguments> » et échouent (sortie 7 pour curl,
# comme une connexion refusée ; 1 pour les autres), sans sortie.
# Une exception pour curl : si FAKE_CURL_HTTP_CODE_FOR_WWW est posée, un appel
# `-w '%{http_code}'` sur une URL https://www. rend ce code sur stdout (sortie 0),
# la réponse d'un hôte qui exige la Basic Auth (401) ou non.
mkdir -p "$TMP/bin"
for fake in dig openssl; do
  cat > "$TMP/bin/$fake" <<FAKE
#!/usr/bin/env bash
printf '%s %s\n' "$fake" "\$*" >> "\$FAKE_CALLS"
exit 1
FAKE
  chmod +x "$TMP/bin/$fake"
done
cat > "$TMP/bin/curl" <<'FAKE'
#!/usr/bin/env bash
printf '%s %s\n' curl "$*" >> "$FAKE_CALLS"
if [ -n "${FAKE_CURL_HTTP_CODE_FOR_WWW:-}" ] && [[ "$*" == *'%{http_code}'* ]] && [[ "${!#}" == https://www.* ]]; then
  printf '%s' "$FAKE_CURL_HTTP_CODE_FOR_WWW"
  exit 0
fi
exit 7
FAKE
chmod +x "$TMP/bin/curl"

# copy_tools <nom> <invalide|réelle> : une copie de tools/ où smoke-http.sh est soit la
# vraie bibliothèque, soit un fichier à erreur de syntaxe.
copy_tools() {
  local dir="$TMP/$1"
  mkdir -p "$dir/tools/lib"
  cp "$TOOLS/audit-prod.sh" "$dir/tools/"
  cp "$TOOLS/lib/security-headers.sh" "$TOOLS/lib/tls-certificate.sh" "$dir/tools/lib/"
  if [ "$2" = invalide ]; then
    printf 'if then\n' > "$dir/tools/lib/smoke-http.sh"
  else
    cp "$TOOLS/lib/smoke-http.sh" "$dir/tools/lib/"
  fi
}

# run_audit <nom> [préfixe de commande…] : lance la copie $TMP/<nom>/tools/audit-prod.sh contre un domaine
# qui n'existe pas, derrière le préfixe s'il y en a un (`bash -x`) ; $rc, $TMP/<nom>.calls, $TMP/<nom>.out,
# $TMP/<nom>.err. LC_ALL=C : bash dit « syntax error » en anglais quelle que soit la langue du poste. TMPDIR
# est confiné pour voir ce que le script laisse derrière lui. run_traced <nom> : le même, sous `bash -x`.
run_audit() {
  local name="$1"
  shift
  : > "$TMP/$name.calls"
  mkdir -p "$TMP/$name.tmpdir"
  set +e
  LC_ALL=C FAKE_CALLS="$TMP/$name.calls" TMPDIR="$TMP/$name.tmpdir" PATH="$TMP/bin:$PATH" \
    "$@" "$TMP/$name/tools/audit-prod.sh" example.invalid >"$TMP/$name.out" 2>"$TMP/$name.err"
  rc=$?
  set -e
}
run_traced() { run_audit "$1" bash -x; }

# --- C6-7 : sans AUDIT_BASIC_AUTH, la bibliothèque de la Basic Auth n'est pas
# sourcée du tout : une smoke-http.sh invalide ne change rien à l'audit (il court
# jusqu'au bout, sortie 1 par ses propres échecs, jamais 2 ni l'erreur de bash).
copy_tools sansauth invalide
run_audit sansauth
if [ "$rc" -eq 1 ] && ! grep -q 'syntax error' "$TMP/sansauth.err" && ! grep -q 'bibliothèque' "$TMP/sansauth.err" \
   && [ -s "$TMP/sansauth.calls" ]; then
  pass "sans AUDIT_BASIC_AUTH, une smoke-http.sh invalide n'est pas sourcée : l'audit court (sortie 1)"
else
  fail "sans AUDIT_BASIC_AUTH" "rc=$rc ; err : $(cat "$TMP/sansauth.err")"
fi

# --- C6-7 : avec AUDIT_BASIC_AUTH, la même bibliothèque invalide arrête le script
# avant toute requête, en le disant (« introuvable ou invalide ») et avec l'erreur
# de bash.
copy_tools avecauth invalide
AUDIT_BASIC_AUTH='u:p' run_audit avecauth
if [ "$rc" -eq 2 ] && grep -q 'introuvable ou invalide : tools/lib/smoke-http.sh' "$TMP/avecauth.err" \
   && grep -q 'syntax error' "$TMP/avecauth.err" && [ ! -s "$TMP/avecauth.calls" ]; then
  pass "avec AUDIT_BASIC_AUTH, une smoke-http.sh invalide → sortie 2, « introuvable ou invalide », erreur de bash, aucune requête"
else
  fail "bibliothèque invalide avec AUDIT_BASIC_AUTH" "rc=$rc ; err : $(cat "$TMP/avecauth.err") ; appels : $(cat "$TMP/avecauth.calls")"
fi

# --- C6-7 : les deux autres bibliothèques disent aussi « invalide », l'erreur
# de bash visible.
for lib in security-headers tls-certificate; do
  copy_tools "lib-$lib" reelle
  printf 'if then\n' > "$TMP/lib-$lib/tools/lib/$lib.sh"
  run_audit "lib-$lib"
  if [ "$rc" -eq 2 ] && grep -q "introuvable ou invalide : tools/lib/$lib.sh" "$TMP/lib-$lib.err" \
     && grep -q 'syntax error' "$TMP/lib-$lib.err"; then
    pass "$lib.sh invalide → sortie 2, « introuvable ou invalide », erreur de bash"
  else
    fail "$lib.sh invalide" "rc=$rc ; err : $(cat "$TMP/lib-$lib.err")"
  fi
done

# --- Un retour à la ligne dans AUDIT_BASIC_AUTH : sortie 2 avant toute requête,
# valeur jamais citée.
copy_tools newline reelle
AUDIT_BASIC_AUTH=$'u:p\nurl = "x"' run_audit newline
if [ "$rc" -eq 2 ] && grep -q 'AUDIT_BASIC_AUTH contient un retour à la ligne' "$TMP/newline.err" \
   && [ ! -s "$TMP/newline.calls" ] && ! grep -qF 'url = ' "$TMP/newline.out" "$TMP/newline.err"; then
  pass "AUDIT_BASIC_AUTH avec un retour à la ligne → sortie 2, aucune requête, valeur jamais citée"
else
  fail "retour à la ligne" "rc=$rc ; err : $(cat -A "$TMP/newline.err")"
fi

# --- C6-8 : le fichier -K ne survit pas au script, et la Basic Auth n'apparaît
# jamais dans les arguments d'un appel.
copy_tools nominal reelle
AUDIT_BASIC_AUTH='u:p' run_audit nominal
if grep -q 'curl .*-K ' "$TMP/nominal.calls" && ! grep -qF 'u:p' "$TMP/nominal.calls"; then
  pass "Basic Auth : curl reçoit -K <fichier>, jamais la valeur"
else
  fail "Basic Auth en argument" "$(cat "$TMP/nominal.calls")"
fi
if [ -z "$(ls -A "$TMP/nominal.tmpdir")" ]; then
  pass "le fichier -K est supprimé à la sortie"
else
  fail "fichier -K laissé" "$(ls -A "$TMP/nominal.tmpdir")"
fi

# --- C7-8 : le fichier -K naît dans un dossier privé propre à la bibliothèque, sous
# TMPDIR (pas directement dedans), et ce dossier disparaît à la sortie : aucun
# répertoire de la bibliothèque n'est laissé dans TMPDIR.
k_path="$(grep -o -- '-K [^ ]*' "$TMP/nominal.calls" | head -1 | cut -d' ' -f2)"
if [ -n "$k_path" ] && [ "$(dirname "$(dirname "$k_path")")" = "$TMP/nominal.tmpdir" ] && [ -z "$(ls -A "$TMP/nominal.tmpdir")" ]; then
  pass "le fichier -K vivait dans un dossier privé sous TMPDIR, et aucun répertoire n'y reste après la sortie"
else
  fail "dossier privé de la Basic Auth" "chemin -K : ${k_path:-absent} ; TMPDIR : $(ls -A "$TMP/nominal.tmpdir")"
fi

# --- #750 : curl envoie `Authorization: Basic` dès la première requête, quel que soit le
# schéma. Un appel http:// qui porte `-K <fichier d'identifiants>` met l'identifiant de la
# préprod en clair sur le réseau ; les sondes de redirection (section 3) partent donc sans
# Basic Auth, et seuls les appels https:// vers l'apex la portent. Lu sur les appels du run
# `nominal` (curl d'abord, l'URL en dernier argument).
# url_calls <nom> <préfixe d'URL> : les appels curl dont l'URL commence par le préfixe.
url_calls() {
  local line
  while IFS= read -r line; do
    case "$line" in
      "curl "*) case "${line##* }" in "$2"*) printf '%s\n' "$line" ;; esac ;;
    esac
  done < "$TMP/$1.calls"
}
has_k() { grep -qE -- '(^| )-K( |$)'; }

http_calls="$(url_calls nominal 'http://')"
if [ -z "$http_calls" ]; then
  fail "aucun appel http:// ne porte -K" "aucun appel http:// noté : le cas ne prouverait rien"
elif has_k <<< "$http_calls"; then
  fail "aucun appel http:// ne porte -K" "la Basic Auth part en clair : ${http_calls//$'\n'/ | }"
else
  pass "aucun appel http:// ne porte -K"
fi

www_calls="$(url_calls nominal 'https://www.example.invalid')"
if [ -z "$www_calls" ]; then
  fail "la sonde de redirection https://www. part sans -K" "aucun appel https://www. noté"
elif has_k <<< "$www_calls"; then
  fail "la sonde de redirection https://www. part sans -K" "${www_calls//$'\n'/ | }"
else
  pass "la sonde de redirection https://www. part sans -K"
fi

# Les métadonnées OAuth de la section 6ter partent sans Basic Auth exprès (un client MCP les lit
# sans savoir y répondre) : elles sont écartées, tout autre appel vers l'apex porte `-K`.
apex_calls="$(url_calls nominal 'https://example.invalid')"
without_k=""
while IFS= read -r line; do
  case "${line##* }" in "https://example.invalid/.well-known/oauth-"*) continue ;; esac
  [ -n "$line" ] && ! has_k <<< "$line" && without_k+="$line | "
done <<< "$apex_calls"
if [ -z "$apex_calls" ]; then
  fail "chaque appel https:// vers l'apex porte -K (la préprod reste authentifiée)" "aucun appel https:// vers l'apex noté"
elif [ -n "$without_k" ]; then
  fail "chaque appel https:// vers l'apex porte -K (la préprod reste authentifiée)" "sans -K : $without_k"
else
  pass "chaque appel https:// vers l'apex porte -K (la préprod reste authentifiée)"
fi

# --- C11-2 : un appel qui porte `-K` ne suit une redirection que vers https (`--proto-redir =https`) : une
# redirection vers http:// ou un autre schéma ne recevrait jamais l'identifiant. Un appel sans `-K` (l'audit de la
# vraie prod, ou les sondes anonymes) n'a aucune raison de le porter : il ne le porte pas.
has_proto_redir() { grep -qE -- '(^| )--proto-redir =https( |$)'; }
# calls_of <nom> <avec|sans> : les appels curl d'un run qui portent (ou non) `-K`, un par ligne.
calls_of() {
  local line
  while IFS= read -r line; do
    case "$line" in "curl "*) ;; *) continue ;; esac
    if has_k <<< "$line"; then [ "$2" = avec ] && printf '%s\n' "$line"; else [ "$2" = sans ] && printf '%s\n' "$line"; fi
  done < "$TMP/$1.calls"
  return 0
}
# lacking <avec|sans> <nom> : les appels qui devraient porter --proto-redir et ne le portent pas (avec -K),
# ou qui le portent sans devoir (sans -K), bornés à deux pour un message lisible.
lacking() {
  local line count=0
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    if { [ "$1" = avec ] && ! has_proto_redir <<< "$line"; } || { [ "$1" = sans ] && has_proto_redir <<< "$line"; }; then
      count=$((count + 1))
      [ "$count" -le 2 ] && printf '%s | ' "${line:0:90}"
    fi
  done <<< "$(calls_of "$2" "$1")"
  return 0
}

with_k="$(calls_of nominal avec)"
bad="$(lacking avec nominal)"
if [ -z "$with_k" ]; then
  fail "chaque appel qui porte -K porte --proto-redir =https" "aucun appel avec -K noté : le cas ne prouverait rien"
elif [ -n "$bad" ]; then
  fail "chaque appel qui porte -K porte --proto-redir =https" "sans l'option : $bad"
else
  pass "chaque appel qui porte -K porte --proto-redir =https ($(wc -l <<< "$with_k") appels)"
fi

# Le témoin : sans Basic Auth (l'audit de la vraie prod) et pour les appels anonymes d'un run authentifié.
without_k="$(calls_of sansauth sans)"$'\n'"$(calls_of nominal sans)"
bad="$(lacking sans sansauth)$(lacking sans nominal)"
if [ -z "${without_k//$'\n'/}" ]; then
  fail "aucun appel sans -K ne porte --proto-redir" "aucun appel sans -K noté : le cas ne prouverait rien"
elif [ -n "$bad" ]; then
  fail "aucun appel sans -K ne porte --proto-redir" "avec l'option : $bad"
else
  pass "aucun appel sans -K ne porte --proto-redir (avec et sans Basic Auth)"
fi

# --- CR11-9 : une sonde de redirection qui reçoit 401 dit que l'hôte demande la Basic Auth (la sonde part sans
# elle exprès) au lieu de passer pour une redirection cassée. Un autre code ne porte pas ce message. La valeur
# de la Basic Auth (générée) n'apparaît nulle part.
copy_tools sonde401 reelle
probe_auth="$(generated_basic_auth)"
FAKE_CURL_HTTP_CODE_FOR_WWW=401 AUDIT_BASIC_AUTH="$probe_auth" run_audit sonde401
probe_line="$(grep -F 'https://www.example.invalid' "$TMP/sonde401.out" | head -1 || true)"
if [[ "$probe_line" == *' 401 '* && "$probe_line" == *"l'hôte demande la Basic Auth"* ]] \
   && ! grep -qF -- "$probe_auth" "$TMP/sonde401.out" "$TMP/sonde401.err" "$TMP/sonde401.calls"; then
  pass "une sonde https://www. en 401 dit que l'hôte demande la Basic Auth"
else
  fail "une sonde https://www. en 401 dit que l'hôte demande la Basic Auth" "ligne de la sonde : ${probe_line:-absente}"
fi

copy_tools sonde200 reelle
FAKE_CURL_HTTP_CODE_FOR_WWW=301 AUDIT_BASIC_AUTH="$(generated_basic_auth)" run_audit sonde200
probe_line="$(grep -F 'https://www.example.invalid' "$TMP/sonde200.out" | head -1 || true)"
if [[ "$probe_line" == *' 301 '* && "$probe_line" != *'Basic Auth'* ]]; then
  pass "une sonde https://www. en 301 ne porte pas ce message"
else
  fail "une sonde https://www. en 301 ne porte pas ce message" "ligne de la sonde : ${probe_line:-absente}"
fi

# --- C8-1 : sous `bash -x`, la valeur de AUDIT_BASIC_AUTH n'apparaît nulle part dans
# la trace (le test de présence ne la développe pas, la bibliothèque la lit sous
# `set +x`). Valeur fictive, sans le mot « secret ».
copy_tools traced reelle
AUDIT_BASIC_AUTH='trace-user:trace-pass-value' run_traced traced
if [ -s "$TMP/traced.err" ] && grep -q 'curl-auth.conf' "$TMP/traced.err" \
   && ! grep -qF -e 'trace-pass-value' -e 'trace-user' "$TMP/traced.err" "$TMP/traced.out"; then
  pass "bash -x : la valeur de AUDIT_BASIC_AUTH n'apparaît ni dans la trace ni dans la sortie"
else
  fail "valeur tracée" "$(grep -nF -e 'trace-' "$TMP/traced.err" | head -3)"
fi

# --- Décisions 3 de la neuvième série et 4 de la dixième (smoke_use_auth_config) : AUDIT_BASIC_AUTH
# posée mais vide (le secret de préprod manquant) arrête l'audit : sortie 2, « secret de Basic Auth
# vide » sur stderr, aucun appel à dig/curl/openssl, aucun dossier dans TMPDIR. Un audit sans Basic Auth
# lirait le 401 de la préprod comme des dizaines d'échecs.
copy_tools vide reelle
AUDIT_BASIC_AUTH='' run_audit vide
CASE_LABEL="AUDIT_BASIC_AUTH vide : sortie 2, « secret de Basic Auth vide », aucun appel, aucun dossier" \
  CASE_RC="$rc" \
  CASE_ERR="$TMP/vide.err" \
  CASE_CALLS="$TMP/vide.calls" \
  CASE_OUT="$TMP/vide.out" \
  CASE_TMPDIR="$TMP/vide.tmpdir" \
  check_empty_basic_auth

# Le même sous `bash -x` : la trace ne porte de la variable que son nom (le test de
# présence ne la développe pas, le vide est jugé sur le chemin du fichier).
copy_tools videtraced reelle
AUDIT_BASIC_AUTH='' run_traced videtraced
if [ "$rc" -eq 2 ] && grep -q 'secret de Basic Auth vide' "$TMP/videtraced.err" \
   && ! grep -qE 'AUDIT_BASIC_AUTH=|AUDIT_BASIC_AUTH:-' "$TMP/videtraced.err" "$TMP/videtraced.out"; then
  pass "AUDIT_BASIC_AUTH vide sous bash -x : sortie 2, rien de la variable que son nom dans la trace"
else
  fail "AUDIT_BASIC_AUTH vide tracée" "rc=$rc ; $(grep -n 'AUDIT_BASIC_AUTH' "$TMP/videtraced.err" | head -5)"
fi

# Une valeur sans « : » (un identifiant sans mot de passe, ou des blancs) : sortie 2 avant toute
# requête, « sans « : » », la valeur ni dans la sortie ni dans la trace.
copy_tools sansdeuxpoints reelle
AUDIT_BASIC_AUTH='identifiant-seul' run_traced sansdeuxpoints
if [ "$rc" -eq 2 ] && grep -q 'AUDIT_BASIC_AUTH : Basic Auth sans « : »' "$TMP/sansdeuxpoints.err" \
   && [ ! -s "$TMP/sansdeuxpoints.calls" ] && [ -z "$(ls -A "$TMP/sansdeuxpoints.tmpdir")" ] \
   && ! grep -qF 'identifiant-seul' "$TMP/sansdeuxpoints.out" "$TMP/sansdeuxpoints.err"; then
  pass "AUDIT_BASIC_AUTH sans « : » : sortie 2, aucun appel, aucun dossier, valeur ni citée ni tracée"
else
  fail "AUDIT_BASIC_AUTH sans « : »" "rc=$rc ; err : $(grep -v 'identifiant-seul' "$TMP/sansdeuxpoints.err" | head -3)"
fi

# --- C11-8 : un utilisateur vide (`:`, `:p`) ou un mot de passe vide (`u:`) n'est pas une Basic Auth : sortie 2
# avant toute requête, « sans utilisateur ou sans mot de passe », aucun dossier, la valeur ni dans la sortie
# ni dans la trace de `bash -x`. Valeurs générées.
token="g$RANDOM$RANDOM"
copy_tools creuse reelle
for hollow in ':' "${token}:" ":${token}"; do
  AUDIT_BASIC_AUTH="$hollow" run_traced creuse
  if [ "$rc" -eq 2 ] && grep -q 'AUDIT_BASIC_AUTH : Basic Auth sans utilisateur ou sans mot de passe' "$TMP/creuse.err" \
     && [ ! -s "$TMP/creuse.calls" ] && [ -z "$(ls -A "$TMP/creuse.tmpdir")" ] \
     && ! grep -qF "$token" "$TMP/creuse.out" "$TMP/creuse.err"; then
    pass "AUDIT_BASIC_AUTH à côté vide (${#hollow} caractères) : sortie 2, aucun appel, aucun dossier, valeur ni citée ni tracée"
  else
    fail "AUDIT_BASIC_AUTH à côté vide" \
      "rc=$rc ; err : $(grep -vF "$token" "$TMP/creuse.err" | head -3) ; TMPDIR : $(ls -A "$TMP/creuse.tmpdir")"
  fi
done

# --- C6-8 : le `trap` est posé AVANT la création du fichier : un arrêt entre les
# deux (signal, `exit` d'une expansion) laisserait sinon le fichier de la Basic
# Auth derrière. Lu sur le source : aucune exécution ne tombe dans cette fenêtre
# d'un instant, mais l'ordre des deux lignes est exactement ce qui la ferme.
trap_line="$(grep -n "^  trap '.*' EXIT" "$TOOLS/audit-prod.sh" | head -1 | cut -d: -f1)"
file_line="$(grep -n 'smoke_use_auth_config AUDIT_BASIC_AUTH' "$TOOLS/audit-prod.sh" | head -1 | cut -d: -f1)"
if [ -n "$trap_line" ] && [ -n "$file_line" ] && [ "$trap_line" -lt "$file_line" ]; then
  pass "le trap (ligne $trap_line) précède la création du fichier -K (ligne $file_line)"
else
  fail "ordre trap / fichier" "trap ligne ${trap_line:-absente}, fichier ligne ${file_line:-absente}"
fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "Tous les cas passent."
