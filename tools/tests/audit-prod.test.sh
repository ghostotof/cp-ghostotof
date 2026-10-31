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

# Les faux : ils notent « <nom> <arguments> » et échouent (sortie 7 pour curl,
# comme une connexion refusée ; 1 pour les autres), sans sortie.
mkdir -p "$TMP/bin"
for fake in dig curl openssl; do
  cat > "$TMP/bin/$fake" <<FAKE
#!/usr/bin/env bash
printf '%s %s\n' "$fake" "\$*" >> "\$FAKE_CALLS"
exit $([ "$fake" = curl ] && echo 7 || echo 1)
FAKE
  chmod +x "$TMP/bin/$fake"
done

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

# run <nom> : lance la copie $TMP/<nom>/tools/audit-prod.sh contre un domaine
# qui n'existe pas (LC_ALL=C : bash dit « syntax error » en anglais quelle que soit la langue du poste) ; $rc, $TMP/<nom>.calls, $TMP/<nom>.err. TMPDIR est confiné
# pour voir ce que le script laisse derrière lui.
run() {
  local name="$1"
  : > "$TMP/$name.calls"
  mkdir -p "$TMP/$name.tmpdir"
  set +e
  LC_ALL=C FAKE_CALLS="$TMP/$name.calls" TMPDIR="$TMP/$name.tmpdir" PATH="$TMP/bin:$PATH" \
    "$TMP/$name/tools/audit-prod.sh" example.invalid >"$TMP/$name.out" 2>"$TMP/$name.err"
  rc=$?
  set -e
}

# --- C6-7 : sans AUDIT_BASIC_AUTH, la bibliothèque de la Basic Auth n'est pas
# sourcée du tout : une smoke-http.sh invalide ne change rien à l'audit (il court
# jusqu'au bout, sortie 1 par ses propres échecs, jamais 2 ni l'erreur de bash).
copy_tools sansauth invalide
run sansauth
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
AUDIT_BASIC_AUTH='u:p' run avecauth
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
  run "lib-$lib"
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
AUDIT_BASIC_AUTH=$'u:p\nurl = "x"' run newline
if [ "$rc" -eq 2 ] && grep -q 'AUDIT_BASIC_AUTH contient un retour à la ligne' "$TMP/newline.err" \
   && [ ! -s "$TMP/newline.calls" ] && ! grep -qF 'url = ' "$TMP/newline.out" "$TMP/newline.err"; then
  pass "AUDIT_BASIC_AUTH avec un retour à la ligne → sortie 2, aucune requête, valeur jamais citée"
else
  fail "retour à la ligne" "rc=$rc ; err : $(cat -A "$TMP/newline.err")"
fi

# --- C6-8 : le fichier -K ne survit pas au script, et la Basic Auth n'apparaît
# jamais dans les arguments d'un appel.
copy_tools nominal reelle
AUDIT_BASIC_AUTH='u:p' run nominal
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

# --- C8-1 : sous `bash -x`, la valeur de AUDIT_BASIC_AUTH n'apparaît nulle part dans
# la trace (le test de présence ne la développe pas, la bibliothèque la lit sous
# `set +x`). Valeur fictive, sans le mot « secret ».
copy_tools traced reelle
mkdir -p "$TMP/traced.tmpdir"
: > "$TMP/traced.calls"
set +e
AUDIT_BASIC_AUTH='trace-user:trace-pass-value' LC_ALL=C FAKE_CALLS="$TMP/traced.calls" \
  TMPDIR="$TMP/traced.tmpdir" PATH="$TMP/bin:$PATH" \
  bash -x "$TMP/traced/tools/audit-prod.sh" example.invalid >"$TMP/traced.out" 2>"$TMP/traced.err"
set -e
if [ -s "$TMP/traced.err" ] && grep -q 'curl-auth.conf' "$TMP/traced.err" \
   && ! grep -qF -e 'trace-pass-value' -e 'trace-user' "$TMP/traced.err" "$TMP/traced.out"; then
  pass "bash -x : la valeur de AUDIT_BASIC_AUTH n'apparaît ni dans la trace ni dans la sortie"
else
  fail "valeur tracée" "$(grep -nF -e 'trace-' "$TMP/traced.err" | head -3)"
fi

# --- Décision 3 de la neuvième série (CR9-8, C9-6) : AUDIT_BASIC_AUTH posée mais vide
# (le secret de préprod manquant) arrête l'audit : sortie 2, « secret de Basic Auth
# vide » sur stderr, aucun appel à dig/curl/openssl, aucun dossier dans TMPDIR. Un audit
# qui courait sans Basic Auth lisait le 401 de la préprod comme des dizaines d'échecs.
copy_tools vide reelle
AUDIT_BASIC_AUTH='' run vide
if [ "$rc" -eq 2 ] && grep -q 'AUDIT_BASIC_AUTH : secret de Basic Auth vide' "$TMP/vide.err" \
   && [ ! -s "$TMP/vide.calls" ] && [ -z "$(ls -A "$TMP/vide.tmpdir")" ] && [ ! -s "$TMP/vide.out" ]; then
  pass "AUDIT_BASIC_AUTH vide : sortie 2, « secret de Basic Auth vide », aucun appel, aucun dossier"
else
  fail "AUDIT_BASIC_AUTH vide" "rc=$rc ; err : $(cat "$TMP/vide.err") ; appels : $(head -3 "$TMP/vide.calls") ; TMPDIR : $(ls -A "$TMP/vide.tmpdir")"
fi

# Le même sous `bash -x` : la trace ne porte de la variable que son nom (le test de
# présence ne la développe pas, le vide est jugé sur le chemin du fichier).
copy_tools videtraced reelle
mkdir -p "$TMP/videtraced.tmpdir"
: > "$TMP/videtraced.calls"
set +e
AUDIT_BASIC_AUTH='' LC_ALL=C FAKE_CALLS="$TMP/videtraced.calls" TMPDIR="$TMP/videtraced.tmpdir" PATH="$TMP/bin:$PATH" \
  bash -x "$TMP/videtraced/tools/audit-prod.sh" example.invalid >"$TMP/videtraced.out" 2>"$TMP/videtraced.err"
vide_rc=$?
set -e
if [ "$vide_rc" -eq 2 ] && grep -q 'secret de Basic Auth vide' "$TMP/videtraced.err" \
   && ! grep -qE 'AUDIT_BASIC_AUTH=|AUDIT_BASIC_AUTH:-' "$TMP/videtraced.err" "$TMP/videtraced.out"; then
  pass "AUDIT_BASIC_AUTH vide sous bash -x : sortie 2, rien de la variable que son nom dans la trace"
else
  fail "AUDIT_BASIC_AUTH vide tracée" "rc=$vide_rc ; $(grep -n 'AUDIT_BASIC_AUTH' "$TMP/videtraced.err" | head -5)"
fi

# --- C6-8 : le `trap` est posé AVANT la création du fichier : un arrêt entre les
# deux (signal, `exit` d'une expansion) laisserait sinon le fichier de la Basic
# Auth derrière. Lu sur le source : aucune exécution ne tombe dans cette fenêtre
# d'un instant, mais l'ordre des deux lignes est exactement ce qui la ferme.
trap_line="$(grep -n "^  trap '.*' EXIT" "$TOOLS/audit-prod.sh" | head -1 | cut -d: -f1)"
file_line="$(grep -n 'smoke_write_auth_config AUDIT_BASIC_AUTH' "$TOOLS/audit-prod.sh" | head -1 | cut -d: -f1)"
if [ -n "$trap_line" ] && [ -n "$file_line" ] && [ "$trap_line" -lt "$file_line" ]; then
  pass "le trap (ligne $trap_line) précède la création du fichier -K (ligne $file_line)"
else
  fail "ordre trap / fichier" "trap ligne ${trap_line:-absente}, fichier ligne ${file_line:-absente}"
fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "Tous les cas passent."
