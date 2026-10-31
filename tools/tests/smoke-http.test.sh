#!/usr/bin/env bash
#
# smoke-http.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/lib/smoke-http.sh (smoke_use_auth_config, sa fonction privée _smoke_write_auth_config,
# smoke_cleanup, smoke_header, smoke_headers, smoke_excerpt, smoke_fetch) : un faux `curl` rejoue
# une réponse canned et note ses arguments, aucun appel réseau, aucune écriture dans le dépôt courant.
# Les scripts qui sourcent la bibliothèque ont chacun leur test (le contrat de
# leurs sorties) ; celui-ci épingle ce que la bibliothèque promet seule.
#
# Usage :  tools/tests/smoke-http.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

LIB="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/smoke-http.sh"
# generated_basic_auth : les valeurs de Basic Auth de ce test sont générées, jamais écrites en clair.
# shellcheck source=tools/tests/lib/smoke-cases.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/smoke-cases.sh"
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

# --- _smoke_write_auth_config : le fichier -K, échappé au format de curl, en 600,
# dans un dossier privé en 700 sous TMPDIR, écrit dans le shell courant (la
# valeur ne passe en argument d'aucune commande) ; rien sur stdout.
# Lue par _smoke_write_auth_config, par indirection (${!nom}).
# shellcheck disable=SC2034
AUDIT_BASIC_AUTH='u:p"a\b'
rc=0
_smoke_write_auth_config AUDIT_BASIC_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
config="$SMOKE_AUTH_CONFIG"
if [ "$rc" -eq 0 ] && [ "$(cat "$config")" = 'user = "u:p\"a\\b"' ] && [ "$(stat -c %a "$config")" = "600" ] \
   && [ ! -s "$TMP/stdout" ] && [ ! -s "$TMP/stderr" ]; then
  pass "_smoke_write_auth_config : user = \"u:p\\\"a\\\\b\", mode 600, rien imprimé"
else
  detail="rc=$rc ; config : $config ; contenu : $(cat -A "$config" 2>&1)"
  detail+=" ; mode : $(stat -c %a "$config" 2>&1) ; stdout : $(cat -A "$TMP/stdout")"
  fail "_smoke_write_auth_config" "$detail"
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

# --- Décision 6 de #748 : un second _smoke_write_auth_config remplace le premier.
# smoke_cleanup est appelé en tête : le dossier du premier appel est retiré avant
# que le second naisse, donc un seul dossier existe (celui du second) et aucun
# fichier d'identifiant ne reste derrière.
# Lue par _smoke_write_auth_config, par indirection (${!nom}).
# shellcheck disable=SC2034
UNE_AUTH='first-user:first-pass'
_smoke_write_auth_config UNE_AUTH
first_dir="$SMOKE_AUTH_DIR"
# shellcheck disable=SC2034
UNE_AUTH='second-user:second-pass'
_smoke_write_auth_config UNE_AUTH
second_dir="$SMOKE_AUTH_DIR"
if [ -n "$first_dir" ] && [ -n "$second_dir" ] && [ "$first_dir" != "$second_dir" ] \
   && [ ! -e "$first_dir" ] && [ -d "$second_dir" ] && [ "$(ls -A "$TMP/tmpdir" | wc -l)" -eq 1 ] \
   && [ "$SMOKE_AUTH_CONFIG" = "$second_dir/curl-auth.conf" ] \
   && [ "$(cat "$SMOKE_AUTH_CONFIG")" = 'user = "second-user:second-pass"' ]; then
  pass "un second _smoke_write_auth_config remplace le premier : un seul dossier, celui du second"
else
  detail="premier : $first_dir ($([ -e "$first_dir" ] && echo survit || echo retiré)) ; second : $second_dir"
  detail+=" ; TMPDIR : $(ls -A "$TMP/tmpdir" | tr '\n' ' ')"
  fail "second appel de _smoke_write_auth_config" "$detail"
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
  _smoke_write_auth_config UNE_AUTH && _smoke_write_auth_config UNE_AUTH && smoke_cleanup' _ "$LIB" || rc=$?
if [ "$rc" -eq 0 ] && [ -d "$TMP/herite2" ] && [ -z "$(ls -A "$TMP/tmpdir")" ]; then
  pass "un dossier hérité de l'environnement survit à deux _smoke_write_auth_config et à smoke_cleanup"
else
  fail "dossier hérité et second appel" "rc=$rc ; dossier hérité : $([ -d "$TMP/herite2" ] && echo présent || echo disparu) ; TMPDIR : $(ls -A "$TMP/tmpdir" | tr '\n' ' ')"
fi

# --- Un retour à la ligne ou un CR ouvrirait une seconde directive : retour 2,
# message sur stderr, rien sur stdout, aucun fichier, la valeur jamais citée.
for newline_value in $'u:p\nurl = "x"' $'u:p\rurl = "x"'; do
  # Lue par _smoke_write_auth_config, par indirection (${!nom}).
  # shellcheck disable=SC2034
  AUDIT_BASIC_AUTH="$newline_value"
  rc=0
  _smoke_write_auth_config AUDIT_BASIC_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  if [ "$rc" -eq 2 ] && grep -q 'AUDIT_BASIC_AUTH contient un retour à la ligne' "$TMP/stderr" \
     && [ ! -s "$TMP/stdout" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] && [ -z "$SMOKE_AUTH_CONFIG" ] \
     && ! grep -qF -e 'u:p' -e 'url = ' "$TMP/stdout" "$TMP/stderr"; then
    pass "_smoke_write_auth_config : retour à la ligne ou CR → retour 2, aucun fichier, valeur jamais citée"
  else
    fail "_smoke_write_auth_config : retour à la ligne" "rc=$rc ; stderr : $(cat -A "$TMP/stderr") ; fichiers : $(ls -A "$TMP/tmpdir")"
  fi
done

# --- C6-9 : le refus nomme la variable reçue, pas une variable écrite en dur.
# shellcheck disable=SC2034
AUTRE_VAR=$'a\nb'
rc=0
_smoke_write_auth_config AUTRE_VAR > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 2 ] && grep -q '^AUTRE_VAR contient un retour à la ligne' "$TMP/stderr" \
   && ! grep -q 'AUDIT_BASIC_AUTH' "$TMP/stderr" && [ -z "$(ls -A "$TMP/tmpdir")" ]; then
  pass "_smoke_write_auth_config : le refus nomme la variable reçue (AUTRE_VAR), aucun fichier"
else
  fail "_smoke_write_auth_config : nom de la variable" "rc=$rc ; stderr : $(cat -A "$TMP/stderr")"
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
  _smoke_write_auth_config "$bad_name" > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  if [ "$rc" -eq 2 ] && [ ! -e "$TMP/pwned" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] && [ "${#SMOKE_CURL_OPTS[@]}" -eq 1 ] \
     && grep -q "^_smoke_write_auth_config : nom de variable invalide" "$TMP/stderr"; then
    pass "_smoke_write_auth_config : nom invalide (${bad_name:-vide}) → retour 2, aucune commande exécutée"
  else
    fail "_smoke_write_auth_config : nom invalide" \
      "nom : ${bad_name:-vide} ; rc=$rc ; pwned : $([ -e "$TMP/pwned" ] && echo créé || echo non) ; stderr : $(cat -A "$TMP/stderr")"
  fi
  rm -f "$TMP/pwned"
done

# --- St7-1 : aucune fonction ne fait sortir l'appelant. Sans argument, le retour
# est 2 et la ligne suivante de l'appelant s'exécute (`${1:?}` faisait sortir
# tout shell non interactif).
after="$( set +e; _smoke_write_auth_config 2>/dev/null; echo "suite rc=$?" )"
if [ "$after" = "suite rc=2" ]; then
  pass "_smoke_write_auth_config sans argument → retour 2, l'appelant continue"
else
  fail "_smoke_write_auth_config sans argument" "obtenu : « $after »"
fi

# --- C8-1 : la bibliothèque juge le vide. Une variable vide ou absente : retour 0,
# aucun dossier, SMOKE_AUTH_CONFIG vide. `set -u` est actif : une variable absente
# ne doit pas faire échouer `${!nom}`.
unset UNE_AUTH
rc=0
_smoke_write_auth_config UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 0 ] && [ -z "$SMOKE_AUTH_CONFIG" ] && [ -z "$SMOKE_AUTH_DIR" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] \
   && [ ! -s "$TMP/stdout" ] && [ ! -s "$TMP/stderr" ]; then
  pass "_smoke_write_auth_config : variable absente → retour 0, aucun dossier, SMOKE_AUTH_CONFIG vide"
else
  fail "_smoke_write_auth_config (absente)" "rc=$rc ; config : [$SMOKE_AUTH_CONFIG] ; fichiers : $(ls -A "$TMP/tmpdir")"
fi
UNE_AUTH=""
rc=0
_smoke_write_auth_config UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 0 ] && [ -z "$SMOKE_AUTH_CONFIG" ] && [ -z "$SMOKE_AUTH_DIR" ] && [ -z "$(ls -A "$TMP/tmpdir")" ]; then
  pass "_smoke_write_auth_config : variable vide → retour 0, aucun dossier, SMOKE_AUTH_CONFIG vide"
else
  fail "_smoke_write_auth_config (vide)" "rc=$rc ; config : [$SMOKE_AUTH_CONFIG] ; fichiers : $(ls -A "$TMP/tmpdir")"
fi
# Une variable vide après une posée : le premier fichier est retiré (smoke_cleanup en tête).
UNE_AUTH='u:p'
_smoke_write_auth_config UNE_AUTH
UNE_AUTH=""
_smoke_write_auth_config UNE_AUTH
if [ -z "$SMOKE_AUTH_CONFIG" ] && [ -z "$(ls -A "$TMP/tmpdir")" ]; then
  pass "_smoke_write_auth_config : une variable vide après une posée retire le premier dossier"
else
  fail "vide après posée" "config : [$SMOKE_AUTH_CONFIG] ; fichiers : $(ls -A "$TMP/tmpdir")"
fi

# --- Décision 4 de la dixième série, choix 7 de la onzième : smoke_use_auth_config <nom> écrit puis
# exige, en une fonction. Définie et vide : retour 2, « secret de Basic Auth vide », aucun dossier ;
# non définie : 0, aucun dossier (l'audit de la prod, un smoke local) ; pleine : 0, le fichier est écrit.
UNE_AUTH=""
rc=0
smoke_use_auth_config UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 2 ] && grep -q '^UNE_AUTH : secret de Basic Auth vide' "$TMP/stderr" && [ ! -s "$TMP/stdout" ] \
   && [ -z "$(ls -A "$TMP/tmpdir")" ] && [ -z "$SMOKE_AUTH_CONFIG" ]; then
  pass "smoke_use_auth_config : variable définie et vide → retour 2, « secret de Basic Auth vide », aucun dossier"
else
  fail "smoke_use_auth_config (vide)" "rc=$rc ; stderr : $(cat -A "$TMP/stderr") ; fichiers : $(ls -A "$TMP/tmpdir")"
fi
unset UNE_AUTH
rc=0
smoke_use_auth_config UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 0 ] && [ ! -s "$TMP/stdout" ] && [ ! -s "$TMP/stderr" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] \
   && [ -z "$SMOKE_AUTH_CONFIG" ]; then
  pass "smoke_use_auth_config : variable non définie → retour 0, rien dit, aucun dossier"
else
  fail "smoke_use_auth_config (non définie)" "rc=$rc ; stderr : $(cat -A "$TMP/stderr") ; fichiers : $(ls -A "$TMP/tmpdir")"
fi
UNE_AUTH="$(generated_basic_auth)"
rc=0
smoke_use_auth_config UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 0 ] && [ ! -s "$TMP/stdout" ] && [ ! -s "$TMP/stderr" ] && [ "$(cat "$SMOKE_AUTH_CONFIG")" = "user = \"$UNE_AUTH\"" ]; then
  pass "smoke_use_auth_config : variable pleine → retour 0, le fichier -K est écrit"
else
  fail "smoke_use_auth_config (pleine)" "rc=$rc ; stderr : $(cat -A "$TMP/stderr")"
fi
smoke_cleanup
# Un nom invalide est refusé comme par _smoke_write_auth_config : retour 2, aucune commande exécutée.
for bad_name in SMOKE_AUTH_DIR _smoke_x '1abc' '' "$hostile"; do
  rc=0
  smoke_use_auth_config "$bad_name" > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  if [ "$rc" -eq 2 ] && [ ! -e "$TMP/pwned" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] \
     && grep -q ' : nom de variable invalide' "$TMP/stderr"; then
    pass "smoke_use_auth_config : nom invalide (${bad_name:-vide}) → retour 2, aucune commande exécutée"
  else
    fail "smoke_use_auth_config : nom invalide" "nom : ${bad_name:-vide} ; rc=$rc ; stderr : $(cat -A "$TMP/stderr")"
  fi
  rm -f "$TMP/pwned"
done
# Sous `set -x`, ni le vide, ni une valeur pleine ne laissent la valeur dans la trace.
UNE_AUTH="$(generated_basic_auth)"
rc=0
( set -x; smoke_use_auth_config UNE_AUTH; in_rc=$?; smoke_cleanup; exit "$in_rc" ) > /dev/null 2> "$TMP/trace" || rc=$?
if [ "$rc" -eq 0 ] && [ -s "$TMP/trace" ] && ! grep -qF -e "${UNE_AUTH%%:*}" -e "${UNE_AUTH#*:}" "$TMP/trace"; then
  pass "smoke_use_auth_config sous set -x : la valeur n'apparaît pas dans la trace"
else
  fail "smoke_use_auth_config tracée" "rc=$rc ; $(grep -F "${UNE_AUTH%%:*}" "$TMP/trace" | head -3)"
fi
unset UNE_AUTH

# --- Une valeur non vide sans « : » (ou faite de blancs) ne donne pas un identifiant : retour 2,
# « sans « : » » nommant la variable, aucun dossier, la valeur jamais citée, ni imprimée sous set -x.
for colonless in 'seulementutilisateur' '   '; do
  UNE_AUTH="$colonless"
  rc=0
  _smoke_write_auth_config UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  if [ "$rc" -eq 2 ] && grep -q '^UNE_AUTH : Basic Auth sans « : »' "$TMP/stderr" && [ ! -s "$TMP/stdout" ] \
     && [ -z "$(ls -A "$TMP/tmpdir")" ] && [ -z "$SMOKE_AUTH_CONFIG" ] && ! grep -qF 'seulementutilisateur' "$TMP/stderr"; then
    pass "_smoke_write_auth_config : valeur sans « : » (${#colonless} caractères) → retour 2, aucun dossier, valeur jamais citée"
  else
    fail "_smoke_write_auth_config : valeur sans « : »" "rc=$rc ; stderr : $(cat -A "$TMP/stderr") ; fichiers : $(ls -A "$TMP/tmpdir")"
  fi
done
UNE_AUTH='seulementutilisateur'
( set -x; _smoke_write_auth_config UNE_AUTH || true ) > /dev/null 2> "$TMP/trace"
if grep -q 'sans « : »' "$TMP/trace" && ! grep -qF 'seulementutilisateur' "$TMP/trace"; then
  pass "_smoke_write_auth_config : le refus d'une valeur sans « : » ne laisse pas la valeur dans la trace set -x"
else
  fail "valeur sans « : » tracée" "$(grep -F 'seulement' "$TMP/trace" | head -3)"
fi
unset UNE_AUTH

# --- C11-8 : un utilisateur vide (`:`, `:p`) ou un mot de passe vide (`u:`) n'est pas une Basic Auth :
# retour 2, « sans utilisateur ou sans mot de passe » nommant la variable, aucun dossier, la valeur
# jamais citée ni laissée dans la trace d'un `set -x`. smoke_use_auth_config refuse de même.
token="g$RANDOM$RANDOM"
# Un côté fait seulement de blancs (espaces, tabulation) est creux comme un côté vide (C12-9).
for hollow in ':' "${token}:" ":${token}" "::${token}" " :${token}" "${token}: " $'\t'":${token}" "${token}:"$'\t' "   :   "; do
  UNE_AUTH="$hollow"
  for entry in _smoke_write_auth_config smoke_use_auth_config; do
    rc=0
    "$entry" UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
    if [ "$rc" -eq 2 ] && grep -q '^UNE_AUTH : Basic Auth sans utilisateur ou sans mot de passe' "$TMP/stderr" \
       && [ ! -s "$TMP/stdout" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] && [ -z "$SMOKE_AUTH_CONFIG" ] \
       && ! grep -qF "$token" "$TMP/stderr"; then
      pass "$entry : valeur à côté vide (${#hollow} caractères) → retour 2, aucun dossier, valeur jamais citée"
    else
      fail "$entry : valeur à côté vide" "rc=$rc ; stderr : $(grep -vF "$token" "$TMP/stderr" | cat -A) ; fichiers : $(ls -A "$TMP/tmpdir")"
    fi
  done
  ( set -x; smoke_use_auth_config UNE_AUTH || true ) > /dev/null 2> "$TMP/trace"
  if grep -q 'sans utilisateur ou sans mot de passe' "$TMP/trace" && ! grep -qF "$token" "$TMP/trace"; then
    pass "le refus d'une valeur à côté vide (${#hollow} caractères) ne laisse pas la valeur dans la trace set -x"
  else
    fail "valeur à côté vide tracée" "$(grep -F "$token" "$TMP/trace" | head -3)"
  fi
done
unset UNE_AUTH token

# --- CR8-5 : une variable qui porte un nom courant (`name`, `value`, `rc`, `traced`,
# `saved_umask`, `empty`) est lue pour ce qu'elle est, jamais masquée par une locale
# de la fonction (les siennes portent le préfixe `_smoke_`).
for shadow in name value rc traced saved_umask empty; do
  printf -v "$shadow" '%s' 'u:p'
  rc_call=0
  _smoke_write_auth_config "$shadow" || rc_call=$?
  if [ "$rc_call" -eq 0 ] && [ "$(cat "$SMOKE_AUTH_CONFIG" 2>/dev/null)" = 'user = "u:p"' ]; then
    pass "_smoke_write_auth_config $shadow : le fichier porte la valeur de la variable $shadow"
  else
    fail "_smoke_write_auth_config $shadow" "rc=$rc_call ; contenu : $(cat -A "$SMOKE_AUTH_CONFIG" 2>&1)"
  fi
  smoke_cleanup
  unset "$shadow"
done

# --- Un dossier qui ne se crée pas (TMPDIR absent) : retour 2, rien sur stdout,
# aucune variable posée.
rc=0
UNE_AUTH='u:p'
TMPDIR="$TMP/absent" _smoke_write_auth_config UNE_AUTH > "$TMP/stdout" 2> /dev/null || rc=$?
if [ "$rc" -eq 2 ] && [ ! -s "$TMP/stdout" ] && [ -z "$SMOKE_AUTH_CONFIG" ] && [ -z "$SMOKE_AUTH_DIR" ]; then
  pass "_smoke_write_auth_config : dossier impossible à créer → retour 2, rien posé"
else
  fail "_smoke_write_auth_config (TMPDIR absent)" "rc=$rc ; dossier : [$SMOKE_AUTH_DIR]"
fi

# --- C7-9 : sous `set -x`, la valeur n'apparaît pas dans la trace (ni en
# argument d'une fonction, ni dans une commande), et l'état de xtrace est rétabli.
# La trace est lue dans un sous-shell : le dossier y est retiré avant la sortie.
# Lue par _smoke_write_auth_config, par indirection (${!nom}).
# shellcheck disable=SC2034
UNE_AUTH='traced-user:traced-pass-value'
(
  set -x
  _smoke_write_auth_config UNE_AUTH
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
  _smoke_write_auth_config UNE_AUTH
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
# après SMOKE_CURL_OPTS ; absente, aucun -K ; deux _smoke_write_auth_config
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
_smoke_write_auth_config UNE_AUTH
first_conf="$SMOKE_AUTH_CONFIG"
: > "$FAKE_ARGS"
FAKE_STATUS=200 smoke_fetch "GET /b" https://example.invalid/b
if [ "$(cat "$FAKE_ARGS")" = "-sS --max-time 15 -K $first_conf --proto-redir =https -w \\n%{http_code} https://example.invalid/b" ]; then
  pass "smoke_fetch : Basic Auth posée → -K <fichier> --proto-redir =https après les options de l'appelant"
else
  fail "smoke_fetch avec auth" "$(cat -A "$FAKE_ARGS")"
fi
# shellcheck disable=SC2034
UNE_AUTH='second:two'
_smoke_write_auth_config UNE_AUTH
: > "$FAKE_ARGS"
FAKE_STATUS=200 smoke_fetch "GET /c" https://example.invalid/c
if [ "$(grep -o -- ' -K ' "$FAKE_ARGS" | wc -l)" -eq 1 ] && grep -qF -- "-K $SMOKE_AUTH_CONFIG " "$FAKE_ARGS" \
   && [ -f "$SMOKE_AUTH_CONFIG" ] && [ ! -e "$first_conf" ] && [[ "${SMOKE_CURL_OPTS[*]}" != *-K* ]]; then
  pass "deux _smoke_write_auth_config : curl reçoit un seul -K, celui du second fichier, qui existe ; jamais de -K dans SMOKE_CURL_OPTS"
else
  fail "second _smoke_write_auth_config puis smoke_fetch" "$(cat "$FAKE_ARGS") ; options : ${SMOKE_CURL_OPTS[*]}"
fi
smoke_cleanup
unset UNE_AUTH

# --- C11-1 : curl envoie `Authorization: Basic` dès la première requête, quel que soit le schéma.
# Une Basic Auth posée et une URL qui ne commence pas par `https://` : smoke_fetch rend 2, dit
# « non https:// — rien de vérifié » sans citer la valeur, et n'appelle pas curl. Sur `https://`, curl
# reçoit le `-K`. La trace d'un `set -x` ne porte pas la valeur non plus.
http_auth="$(generated_basic_auth)"
UNE_AUTH="$http_auth"
smoke_use_auth_config UNE_AUTH
for plain_url in http://example.invalid/x HTTP://example.invalid/x ftp://example.invalid/x example.invalid/x; do
  : > "$FAKE_ARGS"
  rc=0
  FAKE_STATUS=200 smoke_fetch "GET /http" "$plain_url" > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  CASE_LABEL="smoke_fetch : Basic Auth sur $plain_url → retour 2, « non https:// », curl jamais appelé" \
    CASE_RC="$rc" \
    CASE_ERR="$TMP/stderr" \
    CASE_CALLS="$FAKE_ARGS" \
    CASE_OUT="$TMP/stdout" \
    CASE_VALUE="$http_auth" \
    check_plain_http_refused
done
rc=0
( set -x; FAKE_STATUS=200 smoke_fetch "GET /http" http://example.invalid/x || true ) > /dev/null 2> "$TMP/trace" || rc=$?
if [ "$rc" -eq 0 ] && grep -q 'non https://' "$TMP/trace" && ! grep -qF -e "${http_auth%%:*}" -e "${http_auth#*:}" "$TMP/trace" \
   && [ ! -s "$FAKE_ARGS" ]; then
  pass "smoke_fetch : le refus d'une URL non https:// ne laisse pas la valeur dans la trace set -x"
else
  fail "refus http:// tracé" "rc=$rc ; $(grep -F "${http_auth%%:*}" "$TMP/trace" | head -3)"
fi
: > "$FAKE_ARGS"
FAKE_STATUS=200 smoke_fetch "GET /https" https://example.invalid/x
if grep -qF -- " -K $SMOKE_AUTH_CONFIG " "$FAKE_ARGS" && [ "$SMOKE_STATUS" = "200" ]; then
  pass "smoke_fetch : Basic Auth sur https:// → curl reçoit le -K"
else
  fail "smoke_fetch https:// avec -K" "$(cat "$FAKE_ARGS")"
fi
# Sans Basic Auth, une URL http:// part comme avant (la préprod et la prod sont en https, un smoke local non).
smoke_cleanup
unset UNE_AUTH
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /local" http://example.invalid/x || rc=$?
if [ "$rc" -eq 0 ] && [ -s "$FAKE_ARGS" ] && ! grep -q -- ' -K ' "$FAKE_ARGS"; then
  pass "smoke_fetch : sans Basic Auth, une URL http:// part sans -K"
else
  fail "smoke_fetch http:// sans Basic Auth" "rc=$rc ; $(cat "$FAKE_ARGS")"
fi

# --- C12-13 : `"$@"` part avec le `-K`. Une option de redirection (`-L`, `--location`, `--location-trusted`,
# seule ou dans une grappe d'options courtes), `--url` ou une URL qui ne commence pas par `https://` y rendrait
# à la Basic Auth ce que le contrôle de l'URL principale lui refuse : retour 2 avant tout appel, la valeur jamais
# citée. L'appel qui part porte `--proto-redir =https` après le `-K`. Sans Basic Auth, rien de tout cela.
args_auth="$(generated_basic_auth)"
UNE_AUTH="$args_auth"
smoke_use_auth_config UNE_AUTH
refused_args=(
  "-L" "--location" "--location-trusted" "--url" "--url=https://example.invalid/y" "-sL" "-sSL" "-Lo"
  "http://example.invalid/y" "HTTP://example.invalid/y" "ftp://example.invalid/y" "gopher://example.invalid/y"
)
for refused in "${refused_args[@]}"; do
  : > "$FAKE_ARGS"
  rc=0
  FAKE_STATUS=200 smoke_fetch "GET /args" https://example.invalid/x -X POST "$refused" > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  CASE_LABEL="smoke_fetch : « $refused » dans les arguments avec la Basic Auth → retour 2, curl jamais appelé" \
    CASE_RC="$rc" \
    CASE_ERR="$TMP/stderr" \
    CASE_CALLS="$FAKE_ARGS" \
    CASE_OUT="$TMP/stdout" \
    CASE_VALUE="$args_auth" \
    check_redirect_args_refused
done
# Voisins permis : des arguments ordinaires, et une URL https:// en argument.
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /args" https://example.invalid/x -X POST -H 'Content-Type: application/json' \
  -d '{"k":"v"}' -b 'BEARER=abc' https://example.invalid/other || rc=$?
expected_args="-sS --max-time 15 -K $SMOKE_AUTH_CONFIG --proto-redir =https -X POST -H Content-Type: application/json"
expected_args+=" -d {\"k\":\"v\"} -b BEARER=abc https://example.invalid/other -w \\n%{http_code} https://example.invalid/x"
if [ "$rc" -eq 0 ] && [ "$(cat "$FAKE_ARGS")" = "$expected_args" ]; then
  pass "smoke_fetch : des arguments ordinaires et une URL https:// passent, l'appel porte -K <fichier> --proto-redir =https"
else
  fail "smoke_fetch arguments ordinaires" "rc=$rc ; $(cat -A "$FAKE_ARGS")"
fi
rc=0
( set -x; FAKE_STATUS=200 smoke_fetch "GET /args" https://example.invalid/x -L || true ) > /dev/null 2> "$TMP/trace" || rc=$?
if [ "$rc" -eq 0 ] && grep -q 'option de redirection ou URL non https://' "$TMP/trace" \
   && ! grep -qF -e "${args_auth%%:*}" -e "${args_auth#*:}" "$TMP/trace"; then
  pass "smoke_fetch : le refus d'un argument de redirection ne laisse pas la valeur dans la trace set -x"
else
  fail "refus d'un argument tracé" "rc=$rc ; $(grep -F "${args_auth%%:*}" "$TMP/trace" | head -3)"
fi
# R148b : `--proto` et `--proto-redir` (seuls ou en `--nom=valeur`) dans `"$@"` l'emporteraient sur le
# `--proto-redir =https` posé avant eux : refusés avec la Basic Auth, sortie 2, curl jamais appelé, valeur jamais citée.
for refused in "--proto" "--proto=https" "--proto-redir" "--proto-redir=all" "--proto-redir=http,https"; do
  : > "$FAKE_ARGS"
  rc=0
  FAKE_STATUS=200 smoke_fetch "GET /proto" https://example.invalid/x -X POST "$refused" > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  CASE_LABEL="smoke_fetch : « $refused » dans les arguments avec la Basic Auth → retour 2, curl jamais appelé" \
    CASE_RC="$rc" \
    CASE_ERR="$TMP/stderr" \
    CASE_CALLS="$FAKE_ARGS" \
    CASE_OUT="$TMP/stdout" \
    CASE_VALUE="$args_auth" \
    check_protocol_args_refused
done
# La forme à deux arguments (`--proto-redir`, puis sa valeur) est refusée dès le premier.
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /proto" https://example.invalid/x --proto-redir '=all' > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
CASE_LABEL="smoke_fetch : « --proto-redir =all » en deux arguments avec la Basic Auth → retour 2, curl jamais appelé" \
  CASE_RC="$rc" \
  CASE_ERR="$TMP/stderr" \
  CASE_CALLS="$FAKE_ARGS" \
  CASE_OUT="$TMP/stdout" \
  CASE_VALUE="$args_auth" \
  check_protocol_args_refused
# Voisins permis : des options qui commencent comme elles sans être elles (`--progress-bar`, `--proto-default`).
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /proto" https://example.invalid/x --progress-bar --proto-default https || rc=$?
expected_args="-sS --max-time 15 -K $SMOKE_AUTH_CONFIG --proto-redir =https --progress-bar --proto-default https"
expected_args+=" -w \\n%{http_code} https://example.invalid/x"
if [ "$rc" -eq 0 ] && [ "$(cat "$FAKE_ARGS")" = "$expected_args" ]; then
  pass "smoke_fetch : --progress-bar et --proto-default ne sont pas des options de protocole refusées, l'appel part"
else
  fail "voisins de --proto" "rc=$rc ; $(cat -A "$FAKE_ARGS")"
fi
smoke_cleanup
unset UNE_AUTH
# Sans Basic Auth, `-L` et une URL http:// partent comme avant, sans `-K` ni `--proto-redir`.
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /local" https://example.invalid/x -L http://example.invalid/y || rc=$?
if [ "$rc" -eq 0 ] && [ -s "$FAKE_ARGS" ] && ! grep -q -e ' -K ' -e '--proto-redir' "$FAKE_ARGS"; then
  pass "smoke_fetch : sans Basic Auth, -L et une URL http:// partent sans -K ni --proto-redir"
else
  fail "smoke_fetch sans Basic Auth avec -L" "rc=$rc ; $(cat "$FAKE_ARGS")"
fi

# --- C12-10 : la valeur lue n'a aucune raison de rester dans l'environnement des processus que
# l'appelant lance (curl, dig, openssl) : smoke_use_auth_config retire l'attribut d'export de la variable
# une fois le fichier écrit, la valeur restant lisible dans le shell. Rien n'y change si la variable n'était pas exportée.
exported_auth="$(generated_basic_auth)"
UNE_AUTH="$exported_auth"
export UNE_AUTH
if [[ "${UNE_AUTH@a}" == *x* ]] && bash -c '[[ -v UNE_AUTH ]]'; then
  pass "(témoin) la variable du test est exportée avant smoke_use_auth_config"
else
  fail "témoin d'export" "attributs : ${UNE_AUTH@a}"
fi
rc=0
smoke_use_auth_config UNE_AUTH || rc=$?
if [ "$rc" -eq 0 ] && [[ "${UNE_AUTH@a}" != *x* ]] && [ "$UNE_AUTH" = "$exported_auth" ] \
   && ! bash -c '[[ -v UNE_AUTH ]]' && [ "$(cat "$SMOKE_AUTH_CONFIG")" = "user = \"$exported_auth\"" ]; then
  pass "smoke_use_auth_config retire l'export de la variable : absente d'un processus fils, valeur inchangée dans le shell"
else
  fail "export non retiré" \
    "rc=$rc ; attributs : [${UNE_AUTH@a}] ; fils : $(bash -c '[[ -v UNE_AUTH ]] && echo la_voit || echo ne_la_voit_pas')"
fi
smoke_cleanup
unset UNE_AUTH
UNE_AUTH="$(generated_basic_auth)"
smoke_use_auth_config UNE_AUTH
if [[ "${UNE_AUTH@a}" != *x* ]]; then
  pass "smoke_use_auth_config sur une variable non exportée : elle ne l'est pas davantage"
else
  fail "variable non exportée" "attributs : ${UNE_AUTH@a}"
fi
smoke_cleanup
unset UNE_AUTH exported_auth args_auth

# --- St12-14 : smoke_write_auth_config n'est plus publique ; smoke_use_auth_config est la seule
# entrée, et le nom privé la porte. Aucun appelant du dépôt n'écrit l'ancien nom.
if ! declare -F smoke_write_auth_config > /dev/null && declare -F _smoke_write_auth_config > /dev/null; then
  pass "la bibliothèque n'expose plus _smoke_write_auth_config : seule _smoke_write_auth_config existe, privée"
else
  fail "smoke_write_auth_config publique" "$(declare -F | grep -F smoke_write_auth_config | tr '\n' ' ')"
fi

# --- C9-4 : une Basic Auth écrite dont le fichier a disparu ne part pas sans elle :
# smoke_fetch rend 2, dit « fichier de la Basic Auth absent — rien de vérifié » et
# n'appelle pas curl (un appel sans -K lirait le 401 de la préprod comme un résultat).
# Lue par _smoke_write_auth_config, par indirection (${!nom}).
# shellcheck disable=SC2034
UNE_AUTH='gone:file'
_smoke_write_auth_config UNE_AUTH
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
for script in "$TOOLS_DIR"/*.sh; do
  joined_calls "$script" | sed "s|^|$(basename "$script"): |"
done | grep -F '|| exit 1' > "$TMP/exit1" || true
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

# --- Garde du mot (C11-3, C12-2) : le secret n'est lu que par la bibliothèque, sous `set +x`. Toute ligne
# de l'audit et des trois smokes qui contient le mot AUDIT_BASIC_AUTH est refusée, commentaires et lignes
# d'un heredoc compris, sauf deux formes exactes (blancs de tête quelconques, comparés une fois retirés) :
# le test de définition de l'audit et l'appel de la bibliothèque, qui figure une fois par script.
# Une ligne de commentaire n'est pas écartée : un heredoc dont une ligne commence par `#` est du texte
# que le shell lit, pas un commentaire. `${#…}` ou `printenv …` lisent la valeur sans que `bash -x`
# ne la montre autrement que par son effet : seule la lecture du source les voit.
allowed_use='smoke_use_auth_config AUDIT_BASIC_AUTH || exit 2'
allowed_defined='if [[ -v AUDIT_BASIC_AUTH ]]; then'
basic_auth_word_strays() {
  local script="$1" file="$2" numbered code line uses=0
  while IFS= read -r numbered; do
    code="${numbered#*:}"
    code="${code#"${code%%[![:space:]]*}"}"
    if [ "$code" = "$allowed_use" ] || { [ "$script" = audit-prod ] && [ "$code" = "$allowed_defined" ]; }; then
      continue
    fi
    printf '%s\n' "$script.sh:$numbered"
  done < <(grep -nF -- 'AUDIT_BASIC_AUTH' "$file" || true)
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line#"${line%%[![:space:]]*}"}"
    if [ "$line" = "$allowed_use" ]; then uses=$((uses + 1)); fi
  done < "$file"
  if [ "$uses" -ne 1 ]; then
    printf '%s\n' "$script.sh : l'appel « $allowed_use » figure $uses fois, une attendue"
  fi
}
strays=""
for script in audit-prod smoke-dead-session-cookie smoke-login-throttling smoke-mcp; do
  strays+="$(basic_auth_word_strays "$script" "$TOOLS_DIR/$script.sh")"$'\n'
done
if [ -z "${strays//$'\n'/}" ]; then
  pass "AUDIT_BASIC_AUTH : seules les deux formes autorisées figurent dans l'audit et les smokes, l'appel une fois chacun"
else
  fail "AUDIT_BASIC_AUTH hors liste d'autorisation" "$strays"
fi

# La garde est lue sur des copies que l'on altère : chaque contournement nommé doit être vu, chaque
# voisin permis doit passer. `mutant_smoke` ajoute des lignes à la copie d'un smoke et rend ce que la garde dit.
mutant_smoke() {
  cp "$TOOLS_DIR/smoke-mcp.sh" "$TMP/mutant-smoke.sh"
  printf '%s\n' "$@" >> "$TMP/mutant-smoke.sh"
  basic_auth_word_strays smoke-mcp "$TMP/mutant-smoke.sh"
}
word_cases=(
  $'cat <<\'EOT\'\n# valeur : $AUDIT_BASIC_AUTH\nEOT'
  $'cat <<\'EOT\'\n  # \tAUDIT_BASIC_AUTH\nEOT'
  '# la variable AUDIT_BASIC_AUTH est lue ici'
  '    # AUDIT_BASIC_AUTH'
  'echo "${#AUDIT_BASIC_AUTH}"'
  'smoke_use_auth_config AUDIT_BASIC_AUTH || exit 2'
)
word_labels=(
  "un heredoc dont une ligne commence par # et nomme le mot"
  "un heredoc dont une ligne indentée commence par # et nomme le mot"
  "un commentaire qui nomme le mot"
  "un commentaire indenté qui nomme le mot"
  "une longueur lue"
  "un second appel de la bibliothèque"
)
for i in "${!word_cases[@]}"; do
  if [ -n "$(mutant_smoke "${word_cases[$i]}")" ]; then
    pass "garde du mot : ${word_labels[$i]} est refusé"
  else
    fail "garde du mot : ${word_labels[$i]}" "la garde ne voit rien"
  fi
done
# Voisins permis : l'appel réindenté (quatre blancs, une tabulation) compte encore pour une fois, sans reste.
for indent in '    ' $'\t'; do
  sed "s|^smoke_use_auth_config AUDIT_BASIC_AUTH|${indent}&|" "$TOOLS_DIR/smoke-mcp.sh" > "$TMP/mutant-smoke.sh"
  if [ -z "$(basic_auth_word_strays smoke-mcp "$TMP/mutant-smoke.sh")" ] \
     && grep -qE '^[[:space:]]+smoke_use_auth_config' "$TMP/mutant-smoke.sh"; then
    pass "garde du mot : l'appel indenté de ${#indent} caractère(s) est une forme permise, comptée une fois"
  else
    fail "garde du mot : appel indenté" "$(basic_auth_word_strays smoke-mcp "$TMP/mutant-smoke.sh")"
  fi
done

# --- Garde des vidages d'environnement (C12-2) : aucune ligne de l'audit, des trois smokes ni de la
# bibliothèque (hors le corps de _smoke_write_auth_config, seul lecteur de la valeur) n'énumère l'environnement
# ou ne lit une variable par son nom : `declare -p`, `printenv`, `env`, `set` seul ou redirigé, `export -p`,
# `${!…}`. Un motif ancré sur des limites de mot : `environment`, `set -e`, `set +x` passent.
env_dump_lines() {
  local file="$1" n=0 line in_reader=0
  local pat_listing='(^|[^[:alnum:]_])(declare|typeset|local|readonly|export)[[:space:]]+-[[:alpha:]]*p'
  local pat_printenv='(^|[^[:alnum:]_.-])printenv([^[:alnum:]_]|$)'
  local pat_env='(^|[^[:alnum:]_.$-])env([^[:alnum:]_]|$)'
  local pat_set='(^|[;&|(`{]|\$\()[[:space:]]*set[[:space:]]*($|[|>;&)])'
  local pat_indirect='\$\{!'
  # R148b : `export -n` ne retire la valeur que de l'environnement des fils ; /proc/<pid>/environ la montre encore.
  local pat_proc_environ='/proc/[^[:space:]]*environ([^[:alnum:]_]|$)'
  while IFS= read -r line || [ -n "$line" ]; do
    n=$((n + 1))
    # La ligne d'interpréteur n'est pas un appel d'`env`.
    if [ "$n" -eq 1 ] && [[ "$line" == '#!'* ]]; then continue; fi
    # Le corps de _smoke_write_auth_config, seul lecteur de la valeur, lit par indirection.
    # /proc/…/environ n'a aucune excuse, pas même dans le corps du lecteur : jugé avant son exemption.
    if [[ "$line" =~ $pat_proc_environ ]]; then
      printf '%s:%s\n' "$n" "$line"
      continue
    fi
    if [ "$line" = '_smoke_write_auth_config() {' ]; then in_reader=1; fi
    if [ "$in_reader" -eq 1 ]; then
      if [ "$line" = '}' ]; then in_reader=0; fi
      continue
    fi
    if [[ "$line" =~ $pat_listing || "$line" =~ $pat_printenv || "$line" =~ $pat_env \
          || "$line" =~ $pat_set || "$line" =~ $pat_indirect ]]; then
      printf '%s:%s\n' "$n" "$line"
    fi
  done < "$file"
}
mutant_dump() {
  cp "$TOOLS_DIR/smoke-mcp.sh" "$TMP/mutant-dump.sh"
  printf '%s\n' "$@" >> "$TMP/mutant-dump.sh"
  env_dump_lines "$TMP/mutant-dump.sh"
}
dump_forbidden=(
  'declare -p SOME_NAME'
  'declare -xp'
  'typeset -p SOME_NAME'
  'printenv'
  'printenv SOME_NAME'
  'env | grep SOME'
  'FOO=1 env'
  '/usr/bin/env true'
  'set | grep SOME'
  'set > "$TMP_FILE"'
  'set'
  'export -p'
  'readonly -p'
  'value="${!some_name}"'
  'cat /proc/self/environ'
  'tr "\0" "\n" < /proc/$$/environ'
  'strings "/proc/${pid}/environ"'
  'cat /proc/12345/environ'
  'cat /proc/self/../self/environ'
  '# lire /proc/self/environ, dit en commentaire'
  $'cat <<\'EOT\'\n/proc/self/environ\nEOT'
  $'cat <<\'EOT\'\n# declare -p SOME_NAME\nEOT'
  '# printenv, dit en commentaire'
)
for line in "${dump_forbidden[@]}"; do
  if [ -n "$(mutant_dump "$line")" ]; then
    pass "garde des vidages : « ${line//$'\n'/ ↵ } » est refusé"
  else
    fail "garde des vidages : $line" "la garde ne voit rien"
  fi
done
dump_allowed=(
  'set -euo pipefail'
  'set +x'
  'set -x'
  'environment=1'
  '# l'"'"'environnement hérité'
  'export -n -- SOME_NAME'
  'export SOME_NAME'
  'local settings offset'
  'setup_value="$offset"'
  'value="${#some_name}"'
  'value="${some_name:-}"'
  'cat /proc/self/status'
  'cat /proc/self/cmdline'
  'echo /proc/self/environment'
  'echo "a pour valeur $env_name"'
)
for line in "${dump_allowed[@]}"; do
  if [ -z "$(mutant_dump "$line")" ]; then
    pass "garde des vidages : « $line » passe"
  else
    fail "garde des vidages : voisin refusé à tort" "$line : $(mutant_dump "$line")"
  fi
done
# Les cinq fichiers réels n'en portent aucun ; la bibliothèque lit la valeur par indirection dans
# _smoke_write_auth_config seulement : la même ligne ailleurs dans la bibliothèque est refusée.
dump_found=""
for file in audit-prod.sh smoke-dead-session-cookie.sh smoke-login-throttling.sh smoke-mcp.sh lib/smoke-http.sh; do
  dump_found+="$(env_dump_lines "$TOOLS_DIR/$file" | sed "s|^|$file:|")"$'\n'
done
if [ -z "${dump_found//$'\n'/}" ]; then
  pass "aucun vidage d'environnement ni lecture par indirection dans l'audit, les smokes et la bibliothèque"
else
  fail "vidage d'environnement" "$dump_found"
fi
cp "$LIB" "$TMP/mutant-lib.sh"
printf '%s\n' 'other_read() { local _x="${!1}"; }' >> "$TMP/mutant-lib.sh"
if [ -n "$(env_dump_lines "$TMP/mutant-lib.sh")" ]; then
  pass "garde des vidages : une indirection hors de _smoke_write_auth_config est refusée dans la bibliothèque"
else
  fail "indirection hors de la fonction" "la garde ne voit rien"
fi

# R148b : la lecture de /proc/<pid>/environ est refusée aussi dans le corps du lecteur, exempté des autres motifs.
cp "$LIB" "$TMP/mutant-lib.sh"
sed 's|^  _smoke_value="${!_smoke_name:-}"$|&\n  : < /proc/self/environ|' "$LIB" > "$TMP/mutant-lib.sh"
if [ -n "$(env_dump_lines "$TMP/mutant-lib.sh")" ] && grep -q '^  : < /proc/self/environ$' "$TMP/mutant-lib.sh"; then
  pass "garde des vidages : /proc/self/environ lu dans le corps de _smoke_write_auth_config est refusé"
else
  fail "/proc/environ dans le lecteur" "la garde ne voit rien"
fi

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
