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
