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
# run_case (check_non_https_refused, check_args_refused, check_curl_opts_refused) et generated_basic_auth : les valeurs de
# Basic Auth de ce test sont générées, jamais écrites en clair.
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
  kept_dir="$([ -d "$TMP/herite2" ] && echo présent || echo disparu)"
  leftovers="$(ls -A "$TMP/tmpdir" | tr '\n' ' ')"
  fail "dossier hérité et second appel" "rc=$rc ; dossier hérité : $kept_dir ; TMPDIR : $leftovers"
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
for bad_name in "$hostile" '1abc' 'a-b' '' '_smoke_x' '_smoke_name' SMOKE_AUTH_CONFIG SMOKE_AUTH_DIR SMOKE_AUTH_DATA_FILE SMOKE_AUTH_X; do
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
# La variable est retirée du shell dès sa lecture (R168) : la valeur attendue est gardée à part.
full_auth="$(generated_basic_auth)"
UNE_AUTH="$full_auth"
rc=0
smoke_use_auth_config UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 0 ] && [ ! -s "$TMP/stdout" ] && [ ! -s "$TMP/stderr" ] && [ "$(cat "$SMOKE_AUTH_CONFIG")" = "user = \"$full_auth\"" ]; then
  pass "smoke_use_auth_config : variable pleine → retour 0, le fichier -K est écrit"
else
  fail "smoke_use_auth_config (pleine)" "rc=$rc ; stderr : $(cat -A "$TMP/stderr")"
fi
smoke_cleanup
# Un nom invalide est refusé comme par _smoke_write_auth_config : retour 2, aucune commande exécutée.
for bad_name in SMOKE_AUTH_DIR SMOKE_AUTH_DATA_FILE _smoke_x '1abc' '' "$hostile"; do
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
if [ "$rc" -eq 0 ] && [ -s "$TMP/trace" ] && ! values_found_in "${UNE_AUTH%%:*}" "${UNE_AUTH#*:}" -- "$TMP/trace"; then
  pass "smoke_use_auth_config sous set -x : la valeur n'apparaît pas dans la trace"
else
  fail "smoke_use_auth_config tracée" "rc=$rc ; $(lines_with_values "$TMP/trace" "${UNE_AUTH%%:*}" | head -3)"
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
  for entry in _smoke_write_auth_config smoke_use_auth_config; do
    # Chaque appel retire la variable (R168) : elle est reposée avant chacun.
    UNE_AUTH="$hollow"
    rc=0
    "$entry" UNE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
    if [ "$rc" -eq 2 ] && grep -q '^UNE_AUTH : Basic Auth sans utilisateur ou sans mot de passe' "$TMP/stderr" \
       && [ ! -s "$TMP/stdout" ] && [ -z "$(ls -A "$TMP/tmpdir")" ] && [ -z "$SMOKE_AUTH_CONFIG" ] \
       && ! values_found_in "$token" -- "$TMP/stderr"; then
      pass "$entry : valeur à côté vide (${#hollow} caractères) → retour 2, aucun dossier, valeur jamais citée"
    else
      fail "$entry : valeur à côté vide" \
        "rc=$rc ; stderr : $(lines_without_values "$TMP/stderr" "$token" | cat -A) ; fichiers : $(ls -A "$TMP/tmpdir")"
    fi
  done
  UNE_AUTH="$hollow"
  ( set -x; smoke_use_auth_config UNE_AUTH || true ) > /dev/null 2> "$TMP/trace"
  if grep -q 'sans utilisateur ou sans mot de passe' "$TMP/trace" && ! values_found_in "$token" -- "$TMP/trace"; then
    pass "le refus d'une valeur à côté vide (${#hollow} caractères) ne laisse pas la valeur dans la trace set -x"
  else
    fail "valeur à côté vide tracée" "$(lines_with_values "$TMP/trace" "$token" | head -3)"
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
  retry_after="$(smoke_header "$TMP/headers" Retry-After | cat -A)"
  content_type="$(smoke_header "$TMP/headers" content-type | cat -A)"
  fail "smoke_header" "retry-after : $retry_after ; content-type : $content_type"
fi

# --- smoke_headers <fichier> <nom> (St7-3) : toutes les valeurs, une par ligne,
# dans l'ordre, nom sans casse, CR retiré ; smoke_header en est la dernière ligne.
printf 'HTTP/2 401\r\nset-cookie: BEARER=a; Max-Age=0\r\nContent-Type: text/plain\r\nSet-Cookie: XSRF-TOKEN=b; Max-Age=0\r\n\r\n' \
  > "$TMP/cookies"
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
if [ "$(cat "$FAKE_ARGS")" = '-q -sS --max-time 15 -b BEARER=abc -w \n%{http_code} https://example.invalid/x' ]; then
  pass "smoke_fetch : -q, options, arguments de l'appelant, -w, puis l'URL"
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
expected_args="-q -sS --max-time 15 -K $first_conf --proto =https --proto-redir =https --noproxy *"
expected_args+=" -w \\n%{http_code} https://example.invalid/b"
if [ "$(cat "$FAKE_ARGS")" = "$expected_args" ]; then
  pass "smoke_fetch : Basic Auth posée → -q, les options de l'appelant, puis -K <fichier> --proto =https --proto-redir =https --noproxy *"
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
for non_https_url in http://example.invalid/x HTTP://example.invalid/x ftp://example.invalid/x example.invalid/x; do
  : > "$FAKE_ARGS"
  rc=0
  FAKE_STATUS=200 smoke_fetch "GET /http" "$non_https_url" > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  case_vars_set "$TMP/stderr" "$FAKE_ARGS" "$TMP/stdout" "$http_auth"
  run_case check_non_https_refused \
    "smoke_fetch : Basic Auth sur $non_https_url → retour 2, « non https:// », curl jamais appelé" "$rc" \
    "${CASE_VARS[@]}"
done
rc=0
( set -x; FAKE_STATUS=200 smoke_fetch "GET /http" http://example.invalid/x || true ) > /dev/null 2> "$TMP/trace" || rc=$?
if [ "$rc" -eq 0 ] && grep -q 'non https://' "$TMP/trace" && ! values_found_in "${http_auth%%:*}" "${http_auth#*:}" -- "$TMP/trace" \
   && [ ! -s "$FAKE_ARGS" ]; then
  pass "smoke_fetch : le refus d'une URL non https:// ne laisse pas la valeur dans la trace set -x"
else
  fail "refus http:// tracé" "rc=$rc ; $(lines_with_values "$TMP/trace" "${http_auth%%:*}" | head -3)"
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

# --- R169 (D1 de la quatorzième relecture) : avec la Basic Auth, `"$@"` et SMOKE_CURL_OPTS ne portent que des options de la
# liste d'autorisation (_smoke_option_arity : `-sS`, `--connect-timeout`, `--max-time`, `-D`, `-X`, `-H`, `-d`, `--data-binary`,
# `-b`), correspondance exacte, et une seule URL, celle que smoke_fetch juge. Tout autre argument est refusé avant l'appel,
# sortie 2, le **rang** de l'argument dans le message et jamais son texte : la valeur d'une option admise (un en-tête, un corps,
# un cookie) n'est jugée que si elle fait lire un fichier local (R183, plus bas). Le jugement n'a lieu qu'avec la Basic Auth.
# Voir `-v`, `--libcurl -`, `-x`, une seconde URL.
args_auth="$(generated_basic_auth)"
UNE_AUTH="$args_auth"
smoke_use_auth_config UNE_AUTH
# Les six assistants de refus qui suivent (R199, St16-31) tiennent chacun en une ligne : deux corps, l'un pour « $@ » (`_refuse_in_args`),
# l'autre pour SMOKE_CURL_OPTS (`_refuse_in_curl_opts`), paramétrés par le cas partagé de smoke-cases.sh qui juge le message, et par
# la nature de l'élément refusé. Une **option** n'est jamais vide, et son texte est cherché dans le stderr ; une **valeur** peut l'être
# (`-b ''`) : un texte vide se trouve dans tout texte, il n'est pas cherché.
# _refused_not_quoted <où> <option|valeur> <élément refusé> : le refus ne cite jamais l'élément refusé (un argument collé à une option
# courte porterait un secret).
_refused_not_quoted() {
  local where="$1" nature="$2" refused="$3"
  if [ "$nature" = valeur ] && [ -z "$refused" ]; then
    return 0
  fi
  if grep -qF -- "$refused" "$TMP/stderr"; then
    fail "$where : l'élément refusé n'est pas cité (« $refused »)" "stderr : $(cat -A "$TMP/stderr")"
  fi
}
# _refuse_in_args <cas> <option|valeur> <rang attendu> <élément…> : smoke_fetch avec le préfixe `-X POST` puis ces éléments ; le rang se
# compte depuis le premier argument de l'appel, préfixe compris, et l'élément refusé se retrouve par ce même préfixe (St15-37) ; le cas
# vérifie la sortie 2, le message « argument n° <rang> », curl jamais appelé, la valeur de la Basic Auth absente.
_refuse_in_args() {
  local check="$1" nature="$2" rank="$3" refused rc=0
  local prefix=(-X POST)
  shift 3
  refused="${*:$((rank - ${#prefix[@]})):1}"
  : > "$FAKE_ARGS"
  FAKE_STATUS=200 smoke_fetch "GET /args" https://example.invalid/x "${prefix[@]}" "$@" > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  case_vars_set "$TMP/stderr" "$FAKE_ARGS" "$TMP/stdout" "$args_auth"
  run_case "$check" \
    "smoke_fetch : « $* » avec la Basic Auth → retour 2, rang $rank, curl jamais appelé" "$rc" \
    "${CASE_VARS[@]}" CASE_RANK="$rank"
  _refused_not_quoted smoke_fetch "$nature" "$refused"
}
# refuse_args <rang> <élément…> : une option hors de la liste d'autorisation.
refuse_args() { _refuse_in_args check_args_refused option "$@"; }
# Les contournements nommés par la quatorzième relecture : la sortie de curl qui montre l'identifiant (`-v`, `--verbose`, `--trace`,
# `--trace-ascii`, `--libcurl`, mesuré : `--libcurl -` imprime CURLOPT_USERPWD), une grappe qui porte `v`, une seconde URL
# `https://`, `--`, le proxy, le résolveur, l'autorité de certification, le socket Unix, le protocole par défaut, une redirection,
# un fichier de configuration, une valeur collée, une forme longue ou une option future que la liste d'autorisation ne connaît pas.
refuse_args 3 -v
refuse_args 3 --verbose
refuse_args 3 --trace -
refuse_args 3 --trace-ascii -
refuse_args 3 --trace-time
refuse_args 3 --libcurl -
refuse_args 3 -svS
refuse_args 3 -sSv
refuse_args 3 -vsS
refuse_args 3 -s
refuse_args 3 -S
refuse_args 3 https://autre.example/
refuse_args 3 --
refuse_args 3 -x http://p
refuse_args 3 --proxy http://p
refuse_args 3 --preproxy http://p
refuse_args 3 --doh-url https://d
refuse_args 3 --cacert x
refuse_args 3 --capath x
refuse_args 3 --unix-socket /tmp/s
refuse_args 3 --proto-default https
refuse_args 3 --proto =all
refuse_args 3 --proto-redir =all
refuse_args 3 --resolve example.invalid:443:127.0.0.1
refuse_args 3 --connect-to a:1:b:2
refuse_args 3 --insecure
refuse_args 3 -k
refuse_args 3 -L
refuse_args 3 --location
refuse_args 3 --location-trusted
refuse_args 3 -K x
refuse_args 3 --config x
refuse_args 3 --url https://autre.example/
refuse_args 3 --next
refuse_args 3 --variable v=1
refuse_args 3 --expand-url x
refuse_args 3 'hôte/chemin'
refuse_args 3 'http://example.invalid/y'
refuse_args 3 '-HAccept: x'
refuse_args 3 '-XPOST'
refuse_args 3 '--max-time=15'
refuse_args 3 '--MAX-TIME' 15
refuse_args 3 '--max-t' 15
refuse_args 3 '--header' x
refuse_args 3 '--request' GET
refuse_args 3 --compressed
refuse_args 3 --progress-bar
refuse_args 3 --future-option-unknown
# Le rang suit la position : l'option refusée est la cinquième, la septième, la dernière.
refuse_args 5 -H 'A: b' -v
refuse_args 7 -H 'A: b' -d '{"k":"v"}' -x http://p
# Une option à une valeur sans élément suivant est refusée (sinon elle prendrait le `-w` de smoke_fetch pour valeur).
refuse_args 3 -H
refuse_args 3 -b
refuse_args 5 -H 'A: b' -d
# La valeur d'une option admise n'est pas jugée comme option : une valeur qui ressemble à une option ou à une URL passe, et l'option
# suivante, elle, l'est.
refuse_args 5 -H -v -v
refuse_args 5 -d https://autre.example/ -L
# Voisins permis : chaque option de la liste d'autorisation et sa valeur, dans l'appel noté, après `-q` et le `-K`.
accept_args() {
  local label="$1" expected="$2" rc=0
  shift 2
  : > "$FAKE_ARGS"
  FAKE_STATUS=200 smoke_fetch "GET /args" https://example.invalid/x "$@" > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  expected="-q -sS --max-time 15 -K $SMOKE_AUTH_CONFIG --proto =https --proto-redir =https --noproxy * ${expected:+$expected }"
  expected+="-w \\n%{http_code} https://example.invalid/x"
  if [ "$rc" -eq 0 ] && [ "$(cat "$FAKE_ARGS")" = "$expected" ]; then
    pass "smoke_fetch : $label passe avec la Basic Auth, l'appel porte l'option et sa valeur"
  else
    fail "smoke_fetch : $label" "rc=$rc ; $(cat -A "$FAKE_ARGS") ; stderr : $(cat -A "$TMP/stderr")"
  fi
}
accept_args "-X POST" "-X POST" -X POST
accept_args "-H 'Origin: https://…'" "-H Origin: https://x" -H 'Origin: https://x'
accept_args "-d d'un corps JSON qui cite http://" '-d {"url":"http://x"}' -d '{"url":"http://x"}'
accept_args "-b 'BEARER=…'" "-b BEARER=x" -b 'BEARER=x'
accept_args "-H -v (une valeur qui ressemble à une option est une valeur)" "-H -v" -H -v
accept_args "-sS, --connect-timeout et --max-time dans « \$@ »" "-sS --connect-timeout 5 --max-time 9" -sS --connect-timeout 5 --max-time 9
accept_args "-D fichier" "-D /tmp/h" -D /tmp/h
accept_args "toute la liste, dans cet ordre" '-sS -X POST -H A: b -d {"k":"v"} -b C=d --data-binary corps' \
  -sS -X POST -H 'A: b' -d '{"k":"v"}' -b 'C=d' --data-binary corps
accept_args "aucun argument" ""
rc=0
( set -x; FAKE_STATUS=200 smoke_fetch "GET /args" https://example.invalid/x -L || true ) > /dev/null 2> "$TMP/trace" || rc=$?
if [ "$rc" -eq 0 ] && grep -q 'argument n° 1 hors des options admises avec la Basic Auth' "$TMP/trace" \
   && ! values_found_in "${args_auth%%:*}" "${args_auth#*:}" -- "$TMP/trace"; then
  pass "smoke_fetch : le refus d'un argument ne laisse pas la valeur dans la trace set -x"
else
  fail "refus d'un argument tracé" "rc=$rc ; $(lines_with_values "$TMP/trace" "${args_auth%%:*}" | head -3)"
fi
# SMOKE_CURL_OPTS est jugé par la même liste d'autorisation, avec son propre message : « SMOKE_CURL_OPTS, élément n° <rang> ».
# L'élément refusé n'est jamais cité.
saved_curl_opts=("${SMOKE_CURL_OPTS[@]}")
# _refuse_in_curl_opts <cas> <option|valeur> <rang attendu> <élément…> : smoke_fetch sans argument, ces éléments dans SMOKE_CURL_OPTS
# (rétabli ensuite) ; le cas vérifie la sortie 2, le message « SMOKE_CURL_OPTS, élément n° <rang> », curl jamais appelé.
_refuse_in_curl_opts() {
  local check="$1" nature="$2" rank="$3" refused rc=0
  shift 3
  refused="${*:$rank:1}"
  SMOKE_CURL_OPTS=("$@")
  : > "$FAKE_ARGS"
  FAKE_STATUS=200 smoke_fetch "GET /opts" https://example.invalid/x > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
  SMOKE_CURL_OPTS=("${saved_curl_opts[@]}")
  case_vars_set "$TMP/stderr" "$FAKE_ARGS" "$TMP/stdout" "$args_auth"
  run_case "$check" \
    "smoke_fetch : « $* » dans SMOKE_CURL_OPTS avec la Basic Auth → retour 2, élément n° $rank, curl jamais appelé" "$rc" \
    "${CASE_VARS[@]}" CASE_RANK="$rank"
  _refused_not_quoted SMOKE_CURL_OPTS "$nature" "$refused"
}
refuse_curl_opts() { _refuse_in_curl_opts check_curl_opts_refused option "$@"; }
refuse_curl_opts 2 -sS --verbose
refuse_curl_opts 4 -sS --max-time 15 --location-trusted
refuse_curl_opts 4 -sS --max-time 15 -L
refuse_curl_opts 4 -sS --max-time 15 -v
refuse_curl_opts 4 -sS --max-time 15 --libcurl
refuse_curl_opts 4 -sS --max-time 15 https://autre.example/
refuse_curl_opts 4 -sS --max-time 15 --proxy
refuse_curl_opts 4 -sS --max-time 15 --future-option-unknown
refuse_curl_opts 1 -s
refuse_curl_opts 2 -sS --max-time
# Voisin permis : les trois smokes passent `-sS`, deux délais et `-D <fichier>`.
SMOKE_CURL_OPTS=(-sS --connect-timeout 5 --max-time 15 -D "$TMP/headers")
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /opts" https://example.invalid/x || rc=$?
SMOKE_CURL_OPTS=("${saved_curl_opts[@]}")
expected_args="-q -sS --connect-timeout 5 --max-time 15 -D $TMP/headers -K $SMOKE_AUTH_CONFIG"
expected_args+=" --proto =https --proto-redir =https --noproxy * -w \\n%{http_code} https://example.invalid/x"
if [ "$rc" -eq 0 ] && [ "$(cat "$FAKE_ARGS")" = "$expected_args" ]; then
  pass "smoke_fetch : SMOKE_CURL_OPTS des trois smokes (-sS, deux délais, -D <fichier>) passe avec la Basic Auth"
else
  fail "SMOKE_CURL_OPTS des smokes" "rc=$rc ; $(cat -A "$FAKE_ARGS")"
fi
# --- R183 (D2 de la quinzième relecture, Q3 et Q4) : la valeur de cinq options admises est jugée, jamais citée (R196 : `-b '@…'`
# et `-D`, plus bas). Avec la Basic Auth, une valeur qui fait lire un fichier local à curl est refusée (sortie 2, curl jamais appelé,
# le **rang de la valeur** dans le message) : `-H @f`, `-d @f` et `-b @f` (le `@` en tête), `--data-binary @<chemin>` sauf le chemin
# **exact** d'un fichier créé par `smoke_data_file`, `-b` sans « = » (curl lit alors un fichier de cookies). Le fichier `-K` lui-même
# ne passe jamais.
# `smoke_data_file` pose SMOKE_AUTH_DATA_FILE (jamais une impression : une substitution `$(…)` perdrait l'enregistrement du chemin).
# Le nombre de fichiers que retient la bibliothèque et le premier : sous `set -u` un tableau absent (la fonction manque) ferait
# sortir le test, qui doit au contraire échouer par un `fail` nommé.
data_files_count() { ( set +u; echo "${#SMOKE_AUTH_DATA_FILES[@]}" ); }
data_files_first() { ( set +u; echo "${SMOKE_AUTH_DATA_FILES[0]-}" ); }
rc=0
smoke_data_file || rc=$?
data_file="${SMOKE_AUTH_DATA_FILE-}"
if [ "$rc" -eq 0 ] && [ -n "$data_file" ] && [ -f "$data_file" ] && [[ "$data_file" == "$TMPDIR"/* ]] \
   && [ "$(data_files_count)" -eq 1 ] && [ "$(data_files_first)" = "$data_file" ]; then
  pass "smoke_data_file : crée un fichier sous TMPDIR, pose SMOKE_AUTH_DATA_FILE et le retient dans SMOKE_AUTH_DATA_FILES"
else
  fail "smoke_data_file" "rc=$rc ; fichier : ${data_file:-aucun} ; retenus : $(data_files_count)"
fi
# refuse_file_args <rang de la valeur> <élément…> : la valeur de l'option fait lire un fichier local à curl, jamais citée.
refuse_file_args() { _refuse_in_args check_args_file_refused valeur "$@"; }
refuse_file_args 4 -H @/etc/hostname
refuse_file_args 4 -H @-
refuse_file_args 4 -H @
refuse_file_args 4 -d @f
refuse_file_args 4 -d @-
refuse_file_args 4 --data-binary "@$SMOKE_AUTH_CONFIG"
refuse_file_args 4 --data-binary @/tmp/autre
refuse_file_args 4 --data-binary "@$TMP/hors-smoke-data-file"
refuse_file_args 4 --data-binary @-
refuse_file_args 4 --data-binary @
# La correspondance est celle du chemin entier : ni un suffixe, ni un détour par `..`, ni un autre fichier du même dossier.
refuse_file_args 4 --data-binary "@${data_file}x"
refuse_file_args 4 --data-binary "@$TMPDIR/../${TMPDIR##*/}/${data_file##*/}"
refuse_file_args 4 -b cookies.txt
refuse_file_args 4 -b ''
refuse_file_args 4 -b /tmp/cookies
refuse_file_args 4 -b -
# R196 (C16-1) : curl 8.5.0 lit un fichier de cookies dès que la valeur de `-b` commence par `@`, même si elle porte un « = »
# (`-b '@a=b'` : CURLOPT_COOKIEFILE « a=b », mesuré sur l'image épinglée). Le fichier `-K` lui-même, par `@`, ne passe pas non plus ;
# un blanc avant le `@` n'est pas lu comme tel (mesuré : `-b ' @f'` désigne le fichier « ␠@f », refusé faute de « = »).
refuse_file_args 4 -b '@a=b'
refuse_file_args 4 -b '@cookies'
refuse_file_args 4 -b "@$SMOKE_AUTH_CONFIG"
refuse_file_args 4 -b ' @f'
refuse_file_args 6 -H 'A: b' -b '@a=b'
# Le rang suit la position de la valeur.
refuse_file_args 6 -H 'A: b' -d @f
refuse_file_args 6 -H 'A: b' -b cookies.txt
refuse_file_args 8 -H 'A: b' -d '{"k":"v"}' --data-binary @/tmp/autre
# Voisins permis : le chemin exact de smoke_data_file, un corps ou un en-tête qui n'a pas le `@` en tête, un cookie « nom=valeur ».
accept_args "--data-binary @<fichier de smoke_data_file>" "--data-binary @$data_file" --data-binary "@$data_file"
accept_args "--data-binary d'un corps sans @" "--data-binary abc" --data-binary abc
accept_args "-d d'un corps JSON" '-d {"a":1}' -d '{"a":1}'
accept_args "-d vide" "-d " -d ''
accept_args "-H d'un en-tête dont le @ n'est pas en tête" "-H X: @y" -H 'X: @y'
accept_args "-H 'X: y'" "-H X: y" -H 'X: y'
accept_args "-b 'BEARER=x'" "-b BEARER=x" -b 'BEARER=x'
accept_args "-b d'un cookie dont la valeur porte un =" "-b a=b=c" -b 'a=b=c'
# R196 : un blanc avant le `@` n'est lu comme un fichier par aucune de ces trois options (mesuré sur curl 8.5.0, `--libcurl -` :
# CURLOPT_COOKIE « ␠a=b », CURLOPT_HTTPHEADER « ␠@f », CURLOPT_POSTFIELDS « ␠@f ») : admis, aucun refus sans mesure.
accept_args "-b ' @a=b' (le blanc en tête en fait un cookie)" "-b  @a=b" -b ' @a=b'
accept_args "-H ' @f' (le blanc en tête en fait un en-tête)" "-H  @f" -H ' @f'
accept_args "-d ' @f' (le blanc en tête en fait un corps)" "-d  @f" -d ' @f'
# `-D` : le fichier d'en-têtes d'un smoke, la sortie standard, un nom sans `/`, un fichier du dossier parent de celui de la Basic Auth.
accept_args "-D <fichier d'en-têtes>" "-D $TMP/headers" -D "$TMP/headers"
accept_args "-D - (la sortie standard)" "-D -" -D -
accept_args "-D headers.txt (un nom sans /)" "-D headers.txt" -D headers.txt
accept_args "-D <fichier du TMPDIR, dossier parent de celui de la Basic Auth>" "-D $TMPDIR/h" -D "$TMPDIR/h"
# Le même jugement pour SMOKE_CURL_OPTS, avec son propre message : « SMOKE_CURL_OPTS, élément n° <rang> : … ».
refuse_curl_opts_file() { _refuse_in_curl_opts check_curl_opts_file_refused valeur "$@"; }
refuse_curl_opts_file 3 -sS -H @f
refuse_curl_opts_file 3 -sS -b cookies.txt
refuse_curl_opts_file 3 -sS --data-binary @/tmp/autre
refuse_curl_opts_file 3 -sS -b '@a=b'
# --- R196 (C16-4, Q1 (A)) : `-D <fichier>` écrit dans ce fichier. Vers le fichier `-K` ou vers un fichier du dossier de la Basic Auth,
# curl l'écraserait (mesuré sur l'image épinglée : `-D <le -K>` le laisse à 0 octet, même quand le transfert échoue) et le
# prochain appel lirait un `-K` vide, donc sans Basic Auth. Le jugement est un test de bash, `-ef` (même périphérique, même inode),
# qui suit `..` et les liens sans processus : refus de sortie 2, curl jamais appelé, le rang de la valeur, jamais son texte, et le
# message propre « une valeur qui écrit dans le dossier de la Basic Auth ».
refuse_write_args() { _refuse_in_args check_args_write_refused valeur "$@"; }
refuse_write_curl_opts() { _refuse_in_curl_opts check_curl_opts_write_refused valeur "$@"; }
write_dir_name="${SMOKE_AUTH_DIR##*/}"
ln -s "$SMOKE_AUTH_CONFIG" "$TMP/lien-vers-le-k"
refuse_write_args 4 -D "$SMOKE_AUTH_CONFIG"
refuse_write_args 4 -D "$SMOKE_AUTH_DIR/x"
refuse_write_args 4 -D "$SMOKE_AUTH_DIR/../$write_dir_name/curl-auth.conf"
refuse_write_args 4 -D "$SMOKE_AUTH_DIR/../$write_dir_name/neuf"
refuse_write_args 4 -D "$TMP/lien-vers-le-k"
refuse_write_args 6 -H 'A: b' -D "$SMOKE_AUTH_CONFIG"
# Un nom sans `/`, jugé depuis le dossier courant : dans le dossier de la Basic Auth, il écrit chez elle.
cd "$SMOKE_AUTH_DIR"
refuse_write_args 4 -D curl-auth.conf
refuse_write_args 4 -D headers.txt
cd "$OLDPWD"
refuse_write_curl_opts 3 -sS -D "$SMOKE_AUTH_CONFIG"
refuse_write_curl_opts 3 -sS -D "$SMOKE_AUTH_DIR/x"
refuse_write_curl_opts 3 -sS -D "$SMOKE_AUTH_DIR/../$write_dir_name/curl-auth.conf"
rm -f "$TMP/lien-vers-le-k"
unset write_dir_name

# --- R196 (St16-25, Q3 (A)) : les trois listes d'options que la procédure d'admission d'une option dit de tenir ensemble — les
# lignes du `case` de `_smoke_option_arity`, la liste de sa docblock et la phrase « It admits <n> options » de la règle — sont
# égales, et le nombre que dit la phrase est celui de sa liste. Une option ajoutée à une seule des trois (ou le nombre non mis à
# jour) rougit en la nommant. Chaque liste se lit **dans sa forme écrite** : un `case` ne se lit pas comme une docblock.
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
rule_file="$repo_root/.claude/rules/outillage-smoke.md"
case_options="$(awk '
  /^_smoke_option_arity\(\) \{/ { on = 1; next }
  on && /^\}/ { on = 0 }
  on && /^[[:space:]]+-[^)]*\)/ {
    line = $0
    sub(/\).*/, "", line)
    n = split(line, parts, "|")
    for (i = 1; i <= n; i++) { gsub(/[[:space:]]/, "", parts[i]); print parts[i] }
  }' "$LIB" | sort)"
# Lue par indirection (`${!first_list}`, plus bas) : shellcheck ne le voit pas.
# shellcheck disable=SC2034
docblock_options="$(awk '
  /^# La liste d.autorisation/ { on = 1 }
  /^# Ce sont les options/ { on = 0 }
  on && /^#   / { print }' "$LIB" | grep -oE '`-[A-Za-z-]+`' | tr -d '`' | sort)"
rule_sentence="$(awk '/^It admits / { on = 1 } on { print } on && /\(one value each\)/ { on = 0 }' "$rule_file")"
# shellcheck disable=SC2034
rule_options="$(printf '%s\n' "$rule_sentence" | grep -oE '`-[A-Za-z-]+`' | tr -d '`' | sort)"
rule_count_word="$(printf '%s\n' "$rule_sentence" | sed -n 's/^It admits \([a-z]*\) options.*/\1/p')"
case "$rule_count_word" in
  one) rule_count=1 ;; two) rule_count=2 ;; three) rule_count=3 ;; four) rule_count=4 ;; five) rule_count=5 ;;
  six) rule_count=6 ;; seven) rule_count=7 ;; eight) rule_count=8 ;; nine) rule_count=9 ;; ten) rule_count=10 ;;
  eleven) rule_count=11 ;; twelve) rule_count=12 ;; *) rule_count=-1 ;;
esac
options_problems=""
for named in case_options docblock_options rule_options; do
  if [ -z "${!named}" ]; then options_problems+="la liste « $named » est introuvable ; "; fi
done
for pair in "case_options docblock_options" "case_options rule_options"; do
  read -r first_list second_list <<< "$pair"
  only_first="$(comm -23 <(printf '%s\n' "${!first_list}") <(printf '%s\n' "${!second_list}") | tr '\n' ' ')"
  only_second="$(comm -13 <(printf '%s\n' "${!first_list}") <(printf '%s\n' "${!second_list}") | tr '\n' ' ')"
  if [ -n "$only_first$only_second" ]; then
    options_problems+="$first_list seule : ${only_first:-aucune} ; $second_list seule : ${only_second:-aucune} ; "
  fi
done
case_count="$(printf '%s\n' "$case_options" | grep -c .)"
if [ "$rule_count" -ne "$case_count" ]; then
  options_problems+="la règle dit « It admits ${rule_count_word:-?} options » pour $case_count options du case ; "
fi
if [ -z "$options_problems" ]; then
  pass "les options du case de _smoke_option_arity, de sa docblock et de la règle sont les mêmes ($case_count), le nombre dit vrai"
else
  fail "options admises : le case, la docblock et la règle ne disent pas la même liste" "$options_problems"
fi
unset repo_root rule_file case_options docblock_options rule_sentence rule_options rule_count_word rule_count options_problems named pair \
  first_list second_list only_first only_second case_count
# La Basic Auth portée, l'appel part avec `--noproxy '*'` derrière `--proto-redir =https` (curl 8.5.0 : `CURLOPT_NOPROXY, "*"`,
# que `--libcurl -` imprime), et un proxy exporté **après** `smoke_use_auth_config` ne change rien à l'appel : l'option le couvre,
# la bibliothèque ne retire les variables qu'une fois. L'appel sans Basic Auth ne la porte pas (plus bas).
saved_https_proxy="${HTTPS_PROXY-}"
export HTTPS_PROXY="http://127.0.0.1:9"
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /proxy" https://example.invalid/x || rc=$?
if [ "$rc" -eq 0 ] && grep -qF -- ' --proto-redir =https --noproxy * -w ' "$FAKE_ARGS" && [ "$HTTPS_PROXY" = "http://127.0.0.1:9" ]; then
  pass "smoke_fetch : un proxy exporté après la Basic Auth ne compte pas, l'appel porte --noproxy * derrière --proto-redir =https"
else
  fail "--noproxy avec la Basic Auth" "rc=$rc ; $(cat -A "$FAKE_ARGS")"
fi
if [ -n "$saved_https_proxy" ]; then export HTTPS_PROXY="$saved_https_proxy"; else unset HTTPS_PROXY; fi
unset saved_https_proxy
# `-q` est le premier argument de chaque appel, avec la Basic Auth (au-dessus) comme sans (plus bas) : `.curlrc` (`CURL_HOME`,
# `XDG_CONFIG_HOME`, `HOME`) n'est lu que si `-q` n'est pas le premier argument (curl 8.5.0, mesuré le 2026-11-01 : un `.curlrc`
# portant `location-trusted` donne `CURLOPT_UNRESTRICTED_AUTH` sans `-q`, rien avec `-q` en premier, et le redonne en second).
smoke_cleanup
unset UNE_AUTH
# Sans Basic Auth, `-L` et une URL http:// partent comme avant, dans `"$@"` comme dans SMOKE_CURL_OPTS, sans `-K` ni `--proto`,
# et `-q` reste le premier argument.
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /local" https://example.invalid/x -L http://example.invalid/y -v || rc=$?
if [ "$rc" -eq 0 ] && [ -s "$FAKE_ARGS" ] && ! grep -q -e ' -K ' -e '--proto' "$FAKE_ARGS" \
   && [[ "$(cat "$FAKE_ARGS")" == '-q -sS '* ]]; then
  pass "smoke_fetch : sans Basic Auth, -L, -v et une URL http:// partent sans -K ni --proto, derrière -q"
else
  fail "smoke_fetch sans Basic Auth avec -L" "rc=$rc ; $(cat "$FAKE_ARGS")"
fi
SMOKE_CURL_OPTS=(-sS -L --max-time 15)
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /local" https://example.invalid/x || rc=$?
SMOKE_CURL_OPTS=("${saved_curl_opts[@]}")
if [ "$rc" -eq 0 ] && [ -s "$FAKE_ARGS" ] && ! grep -q -e ' -K ' -e '--proto' "$FAKE_ARGS"; then
  pass "smoke_fetch : sans Basic Auth, un -L dans SMOKE_CURL_OPTS n'est pas jugé"
else
  fail "SMOKE_CURL_OPTS sans Basic Auth" "rc=$rc ; $(cat "$FAKE_ARGS")"
fi
# Sans Basic Auth, une valeur `@f` n'est pas jugée (rien à protéger), le `-b` sans « = » non plus, et l'appel ne porte pas `--noproxy`.
: > "$FAKE_ARGS"
rc=0
FAKE_STATUS=200 smoke_fetch "GET /local" https://example.invalid/x -H @f -d @g -b cookies.txt --data-binary @h || rc=$?
if [ "$rc" -eq 0 ] && grep -qF -- ' -H @f -d @g -b cookies.txt --data-binary @h ' "$FAKE_ARGS" \
   && ! grep -qF -- '--noproxy' "$FAKE_ARGS"; then
  pass "smoke_fetch : sans Basic Auth, -H @f, -d @g, -b sans = et --data-binary @h partent, sans --noproxy"
else
  fail "valeurs @ sans Basic Auth" "rc=$rc ; $(cat "$FAKE_ARGS")"
fi

# --- R169 (Q8) : une fois la Basic Auth posée, les variables d'environnement que curl lit (un proxy qui inspecte le TLS, un journal
# de clés TLS ou QUIC, un lot d'autorités, le choix du moteur TLS) ne sont plus dans le shell : aucun appel de curl ne les reçoit.
# Sans Basic Auth, elles restent.
curl_variables=(http_proxy HTTPS_PROXY https_proxy ALL_PROXY all_proxy NO_PROXY no_proxy
  CURL_CA_BUNDLE CURL_SSL_BACKEND QLOGDIR SSL_CERT_DIR SSL_CERT_FILE SSLKEYLOGFILE)
for variable in "${curl_variables[@]}"; do
  printf -v "$variable" '%s' "valeur-de-test-$variable"
  export "${variable?}"
done
UNE_AUTH="$(generated_basic_auth)"
rc=0
smoke_use_auth_config UNE_AUTH || rc=$?
still_set=""
for variable in "${curl_variables[@]}"; do
  if [[ -v $variable ]] || bash -c '[[ -v $1 ]]' _ "$variable"; then still_set+="$variable "; fi
done
if [ "$rc" -eq 0 ] && [ -z "$still_set" ]; then
  pass "smoke_use_auth_config avec la Basic Auth retire du shell les ${#curl_variables[@]} variables d'environnement de curl"
else
  fail "variables de curl encore posées" "rc=$rc ; encore posées : ${still_set:-aucune}"
fi
smoke_cleanup
unset UNE_AUTH
for variable in "${curl_variables[@]}"; do
  printf -v "$variable" '%s' "valeur-de-test-$variable"
  export "${variable?}"
done
rc=0
smoke_use_auth_config UNE_AUTH || rc=$?
missing=""
for variable in "${curl_variables[@]}"; do
  if ! bash -c '[[ -v $1 ]]' _ "$variable"; then missing+="$variable "; fi
done
if [ "$rc" -eq 0 ] && [ -z "$missing" ]; then
  pass "smoke_use_auth_config sans Basic Auth laisse les variables d'environnement de curl"
else
  fail "variables de curl retirées sans Basic Auth" "rc=$rc ; retirées : ${missing:-aucune}"
fi
smoke_cleanup
for variable in "${curl_variables[@]}"; do unset "$variable"; done
# Sp15-24, St16-35 : la liste ci-dessus recopie celle de la bibliothèque (son `unset -v`, relu ici). Un nom ajouté d'un seul côté
# rougit : à la bibliothèque seule, ce cas ; au test seul, le premier cas plus haut (la variable resterait posée) et celui-ci. La
# lecture est bornée par le corps de `_smoke_write_config_file` (de sa première ligne à son `}`), continuations comprises, et exige
# **un seul** `unset -v` : un second, plus bas dans le corps, serait une liste que ce cas ne lirait pas.
lib_unset_report="$(awk '
  /^_smoke_write_config_file\(\) \{/ { in_body = 1; next }
  in_body && /^\}/ { in_body = 0 }
  in_body && /^[[:space:]]*unset -v [A-Za-z_]/ { statements++; on = 1 }
  in_body && on {
    line = $0
    more = (line ~ /\\$/)
    sub(/^[[:space:]]*unset -v /, "", line)
    sub(/[[:space:]]*\\$/, "", line)
    names = names " " line
    if (!more) on = 0
  }
  END { print statements + 0; print names }' "$LIB")"
lib_unset_count="${lib_unset_report%%$'\n'*}"
lib_unset_names="$(printf '%s\n' "${lib_unset_report#*$'\n'}" | tr -s ' ' '\n' | sed '/^$/d' | sort | tr '\n' ' ')"
test_unset_names="$(printf '%s\n' "${curl_variables[@]}" | sort | tr '\n' ' ')"
if [ "$lib_unset_count" = 1 ] && [ -n "$lib_unset_names" ] && [ "$lib_unset_names" = "$test_unset_names" ]; then
  pass "la liste des variables de curl du test est celle que la bibliothèque retire (${#curl_variables[@]} noms)"
else
  fail "liste des variables de curl" \
    "bibliothèque : ${lib_unset_names:-introuvable} (unset -v dans le corps : $lib_unset_count, un attendu) ; test : $test_unset_names"
fi
unset curl_variables variable still_set missing lib_unset_report lib_unset_count lib_unset_names test_unset_names

# --- R183 (Q4) : le cycle de vie de smoke_data_file. `smoke_cleanup` retire les fichiers et vide SMOKE_AUTH_DATA_FILES et
# SMOKE_AUTH_DATA_FILE : un chemin d'un premier appel, même recréé ensuite, n'est plus admis. Un second `smoke_use_auth_config` remplace
# le premier : il retire aussi ses fichiers. À la source, les deux variables sont vides, quoi que l'environnement ait contenu.
lifecycle_auth="$(generated_basic_auth)"
UNE_AUTH="$lifecycle_auth"
smoke_use_auth_config UNE_AUTH
rc=0
smoke_data_file || rc=$?
first_data="${SMOKE_AUTH_DATA_FILE-}"
smoke_data_file || rc=$?
second_data="${SMOKE_AUTH_DATA_FILE-}"
if [ "$rc" -eq 0 ] && [ -f "$first_data" ] && [ -f "$second_data" ] && [ "$first_data" != "$second_data" ] \
   && [ "$(data_files_count)" -eq 2 ]; then
  pass "smoke_data_file : deux appels, deux fichiers distincts, les deux retenus"
else
  fail "deux smoke_data_file" "rc=$rc ; $first_data | $second_data ; retenus : $(data_files_count)"
fi
for kept in "$first_data" "$second_data"; do
  : > "$FAKE_ARGS"
  rc=0
  FAKE_STATUS=200 smoke_fetch "GET /data" https://example.invalid/x --data-binary "@$kept" || rc=$?
  if [ "$rc" -eq 0 ] && [ -n "$kept" ] && grep -qF -- "--data-binary @$kept " "$FAKE_ARGS"; then
    pass "smoke_fetch : chacun des deux fichiers de smoke_data_file est admis"
  else
    fail "fichier de smoke_data_file admis" "rc=$rc ; $(cat -A "$FAKE_ARGS")"
  fi
done
smoke_cleanup
if [ -n "$first_data" ] && [ -n "$second_data" ] && [ ! -e "$first_data" ] && [ ! -e "$second_data" ] \
   && [ "$(data_files_count)" -eq 0 ] && [ -z "${SMOKE_AUTH_DATA_FILE-}" ]; then
  pass "smoke_cleanup retire les fichiers de smoke_data_file et vide SMOKE_AUTH_DATA_FILES et SMOKE_AUTH_DATA_FILE"
else
  fail "smoke_cleanup et les fichiers de données" \
    "restent : $(ls "$first_data" "$second_data" 2>/dev/null) ; retenus : $(data_files_count)" \
    "; SMOKE_AUTH_DATA_FILE : ${SMOKE_AUTH_DATA_FILE-absente}"
fi
# Le chemin d'un premier appel n'est plus admis après un nouveau `smoke_use_auth_config`, même si le fichier est recréé.
UNE_AUTH="$lifecycle_auth"
smoke_use_auth_config UNE_AUTH
if [ -n "$first_data" ]; then : > "$first_data"; fi
args_auth="$lifecycle_auth"
refuse_file_args 4 --data-binary "@$first_data"
if [ -n "$first_data" ]; then rm -f -- "$first_data"; fi
smoke_cleanup
# Un second smoke_use_auth_config remplace le premier : les fichiers de données du premier ne lui survivent pas.
UNE_AUTH="$lifecycle_auth"
smoke_use_auth_config UNE_AUTH
smoke_data_file || true
replaced_data="${SMOKE_AUTH_DATA_FILE-}"
UNE_AUTH="$lifecycle_auth"
smoke_use_auth_config UNE_AUTH
if [ -n "$replaced_data" ] && [ ! -e "$replaced_data" ] && [ "$(data_files_count)" -eq 0 ]; then
  pass "un second smoke_use_auth_config retire les fichiers de données du premier"
else
  fail "second smoke_use_auth_config et les fichiers de données" \
    "reste : $(ls "$replaced_data" 2>/dev/null) ; retenus : $(data_files_count)"
fi
smoke_cleanup
unset UNE_AUTH
# Sans Basic Auth, smoke_data_file crée aussi son fichier (le smoke ne sait pas s'il en a une), et le retire de même.
rc=0
smoke_data_file || rc=$?
plain_data="${SMOKE_AUTH_DATA_FILE-}"
if [ "$rc" -eq 0 ] && [ -f "$plain_data" ]; then
  smoke_cleanup
  if [ ! -e "$plain_data" ]; then
    pass "smoke_data_file sans Basic Auth : le fichier existe, smoke_cleanup le retire"
  else
    fail "fichier sans Basic Auth retiré" "reste : $plain_data"
  fi
else
  fail "smoke_data_file sans Basic Auth" "rc=$rc ; fichier : ${plain_data:-aucun}"
fi
# Un mktemp qui échoue (TMPDIR absent) : retour 2, rien de retenu, SMOKE_AUTH_DATA_FILE vide, le motif sur stderr.
rc=0
TMPDIR="$TMP/absent" smoke_data_file > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 2 ] && [ -z "${SMOKE_AUTH_DATA_FILE-}" ] && [ "$(data_files_count)" -eq 0 ] \
   && grep -q '^smoke_data_file : ' "$TMP/stderr"; then
  pass "smoke_data_file : mktemp en échec → retour 2, rien de retenu, le motif sur stderr"
else
  fail "smoke_data_file sans dossier" "rc=$rc ; SMOKE_AUTH_DATA_FILE : ${SMOKE_AUTH_DATA_FILE-absente} ; stderr : $(cat -A "$TMP/stderr")"
fi
# À la source, les deux variables sont vides, quoi que l'environnement ait contenu.
inherited_data="$(SMOKE_AUTH_DATA_FILES=/herite/fichier SMOKE_AUTH_DATA_FILE=/herite/fichier \
  bash -c 'source "$1"; printf "[%s][%s]" "${#SMOKE_AUTH_DATA_FILES[@]}" "${SMOKE_AUTH_DATA_FILE-absente}"' _ "$LIB")"
if [ "$inherited_data" = "[0][]" ]; then
  pass "sourcer vide SMOKE_AUTH_DATA_FILES et SMOKE_AUTH_DATA_FILE hérités de l'environnement"
else
  fail "fichiers de données hérités" "obtenu : $inherited_data"
fi
unset lifecycle_auth first_data second_data kept replaced_data plain_data inherited_data

# --- C12-10, D3 de la quatorzième relecture : la valeur lue n'a aucune raison de rester dans l'environnement des processus
# que l'appelant lance (curl, dig, openssl) : smoke_use_auth_config retire la variable du shell une fois le fichier écrit
# (la valeur n'est plus que dans le fichier -K), exportée ou non.
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
if [ "$rc" -eq 0 ] && ! [[ -v UNE_AUTH ]] && ! bash -c '[[ -v UNE_AUTH ]]' \
   && [ "$(cat "$SMOKE_AUTH_CONFIG")" = "user = \"$exported_auth\"" ]; then
  pass "smoke_use_auth_config retire la variable exportée : absente d'un processus fils, valeur dans le fichier -K"
else
  fail "variable exportée non retirée" \
    "rc=$rc ; shell : $([[ -v UNE_AUTH ]] && echo définie || echo absente) ;" \
    "fils : $(bash -c '[[ -v UNE_AUTH ]] && echo la_voit || echo ne_la_voit_pas')"
fi
smoke_cleanup
unset UNE_AUTH
UNE_AUTH="$(generated_basic_auth)"
smoke_use_auth_config UNE_AUTH
if ! [[ -v UNE_AUTH ]]; then
  pass "smoke_use_auth_config retire aussi une variable non exportée"
else
  fail "variable non exportée non retirée" "attributs : ${UNE_AUTH@a}"
fi
smoke_cleanup

# --- R168 (D3 de la quatorzième relecture) : dès la lecture, la variable n'existe plus dans le shell (`unset`, qui retire aussi
# son export) : ni `[[ -v ]]`, ni `declare -p`, ni `set`, ni un fils ne la voient, quelle que soit la valeur lue.
for kind in pleine vide refusee; do
  case "$kind" in
    pleine) UNE_AUTH="$(generated_basic_auth)"; expected=0 ;;
    vide) UNE_AUTH=""; expected=2 ;;
    refusee) UNE_AUTH="sansdeuxpoints"; expected=2 ;;
  esac
  export UNE_AUTH
  rc=0
  smoke_use_auth_config UNE_AUTH > /dev/null 2>&1 || rc=$?
  if [ "$rc" -eq "$expected" ] && ! [[ -v UNE_AUTH ]] && [ -z "$(declare -p UNE_AUTH 2>/dev/null || true)" ] \
     && [ -z "$(set | grep '^UNE_AUTH=' || true)" ] && ! bash -c '[[ -v UNE_AUTH ]]'; then
    pass "smoke_use_auth_config : valeur $kind → retour $expected, la variable n'est plus définie (ni -v, ni declare -p, ni set, ni fils)"
  else
    fail "variable encore définie ($kind)" "rc=$rc (attendu $expected) ; définie : $([[ -v UNE_AUTH ]] && echo oui || echo non)"
  fi
  smoke_cleanup
  unset UNE_AUTH
done

# --- R182 (Q1) : un retrait qui échoue est un refus. Une variable en lecture seule ne se retire pas (le retrait rend 1 et le
# fichier -K serait écrit quand même), et le retrait d'une locale de l'appelant laisse voir la globale exportée qu'elle masquait :
# dans les deux cas la variable reste définie, donc visible d'un fils. Retour 2 avant toute validation et tout `mktemp`, le message
# nomme la variable et jamais la valeur, aucun fichier -K, aucun dossier. Chaque cas court dans un `bash -c` à part : la variable
# en lecture seule ne se retirerait plus du shell du test. Les valeurs sont générées et passées par l'environnement du `bash -c`,
# jamais par ses arguments.
shadow_global="$(generated_basic_auth)"
shadow_local="$(generated_basic_auth)"
for kind in lecture-seule locale-masquante; do
  mkdir "$TMP/unset-tmpdir"
  rc=0
  case "$kind" in
    lecture-seule)
      GLOBAL_VALUE="$shadow_global" TMPDIR="$TMP/unset-tmpdir" bash -c '
        source "$1"
        export UNE_AUTH="$GLOBAL_VALUE"
        readonly UNE_AUTH
        rc=0
        _smoke_write_auth_config UNE_AUTH || rc=$?
        printf "rc=%s config=[%s]" "$rc" "$SMOKE_AUTH_CONFIG"' _ "$LIB" > "$TMP/unset.out" 2> "$TMP/unset.err" || rc=$?
      ;;
    locale-masquante)
      GLOBAL_VALUE="$shadow_global" LOCAL_VALUE="$shadow_local" TMPDIR="$TMP/unset-tmpdir" bash -c '
        source "$1"
        export UNE_AUTH="$GLOBAL_VALUE"
        caller() {
          local UNE_AUTH="$LOCAL_VALUE"
          rc=0
          _smoke_write_auth_config UNE_AUTH || rc=$?
          printf "rc=%s config=[%s]" "$rc" "$SMOKE_AUTH_CONFIG"
        }
        caller' _ "$LIB" > "$TMP/unset.out" 2> "$TMP/unset.err" || rc=$?
      ;;
  esac
  if [ "$(cat "$TMP/unset.out")" = "rc=2 config=[]" ] \
     && grep -q '^UNE_AUTH : la variable ne peut pas être retirée du shell' "$TMP/unset.err" \
     && ! values_found_in "$shadow_global" "$shadow_local" -- "$TMP/unset.out" "$TMP/unset.err" \
     && [ -z "$(ls -A "$TMP/unset-tmpdir")" ]; then
    pass "_smoke_write_auth_config : variable $kind, retrait impossible → retour 2, rien d'écrit, valeur jamais citée"
  else
    detail="sortie : $(cat -A "$TMP/unset.out") ; TMPDIR : $(ls -A "$TMP/unset-tmpdir" | tr '\n' ' ')"
    detail+=" ; stderr : $(lines_without_values "$TMP/unset.err" "$shadow_global" "$shadow_local" | cat -A)"
    fail "retrait impossible ($kind)" "$detail"
  fi
  rm -rf "$TMP/unset-tmpdir"
done
unset shadow_global shadow_local kind

# Le drapeau privé `_smoke_auth_defined` garde « définie » pour le contrôle du vide : lu par _smoke_require_auth_config
# (jamais `[[ -v ]]`, la variable n'existe plus), remis à 0 par smoke_cleanup et à la source.
SMOKE_AUTH_CONFIG=""
UNE_AUTH=""
_smoke_auth_defined=0
rc=0
_smoke_require_auth_config UNE_AUTH > /dev/null 2>&1 || rc=$?
if [ "$rc" -eq 0 ]; then
  pass "_smoke_require_auth_config : drapeau à 0 → retour 0, même si la variable du même nom est définie et vide"
else
  fail "_smoke_require_auth_config lit la variable" "rc=$rc"
fi
unset UNE_AUTH
_smoke_auth_defined=1
rc=0
_smoke_require_auth_config UNE_AUTH > /dev/null 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 2 ] && grep -q '^UNE_AUTH : secret de Basic Auth vide, rien de vérifié$' "$TMP/stderr"; then
  pass "_smoke_require_auth_config : drapeau à 1 et aucune configuration → retour 2, « secret de Basic Auth vide », variable absente"
else
  fail "_smoke_require_auth_config lit le drapeau" "rc=$rc ; stderr : $(cat -A "$TMP/stderr")"
fi
_smoke_auth_defined=1
smoke_cleanup
if [ "${_smoke_auth_defined-absent}" = 0 ]; then
  pass "smoke_cleanup remet le drapeau _smoke_auth_defined à 0"
else
  fail "smoke_cleanup et le drapeau" "drapeau : ${_smoke_auth_defined-absent}"
fi
sourced_flag="$(_smoke_auth_defined=1 bash -c 'source "$1"; printf "%s" "${_smoke_auth_defined-absent}"' _ "$LIB")"
if [ "$sourced_flag" = 0 ]; then
  pass "sourcer la bibliothèque remet le drapeau _smoke_auth_defined à 0, quoi que l'environnement ait contenu"
else
  fail "drapeau hérité" "obtenu : $sourced_flag"
fi
# Un second appel avec un autre nom ne réutilise pas le drapeau du premier : premier défini et vide, second non défini → 0.
UNE_AUTH=""
smoke_use_auth_config UNE_AUTH > /dev/null 2>&1 || true
unset AUTRE_AUTH
rc=0
smoke_use_auth_config AUTRE_AUTH > "$TMP/stdout" 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 0 ] && [ ! -s "$TMP/stderr" ] && [ "${_smoke_auth_defined-absent}" = 0 ]; then
  pass "smoke_use_auth_config : après une variable définie et vide, un second nom non défini → retour 0, drapeau à 0"
else
  fail "drapeau du premier appel réutilisé" "rc=$rc ; drapeau : ${_smoke_auth_defined-absent} ; stderr : $(cat -A "$TMP/stderr")"
fi
smoke_cleanup
unset UNE_AUTH AUTRE_AUTH sourced_flag expected kind
unset UNE_AUTH exported_auth args_auth

# --- St12-14 : smoke_write_auth_config (sans tiret bas) n'existe pas ; smoke_use_auth_config est la seule
# entrée, et le nom privé la porte. Aucun appelant du dépôt n'écrit le nom sans tiret bas.
if ! declare -F smoke_write_auth_config > /dev/null && declare -F _smoke_write_auth_config > /dev/null; then
  pass "la bibliothèque n'expose pas smoke_write_auth_config : seule _smoke_write_auth_config existe, privée"
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

# --- Garde de la sous-chaîne AUDIT_BASIC_AUTH (C11-3, C12-2) : le secret n'est lu que par la bibliothèque, sous `set +x`.
# Toute ligne de l'audit et des trois smokes qui contient la sous-chaîne AUDIT_BASIC_AUTH (même dans un mot plus long) est
# refusée, commentaires et lignes d'un heredoc compris, sauf deux formes exactes (blancs de tête quelconques, comparés une
# fois retirés) : le test de définition de l'audit et l'appel de la bibliothèque, qui figure une fois par script.
# Une ligne de commentaire n'est pas écartée : un heredoc dont une ligne commence par `#` est du texte
# que le shell lit, pas un commentaire. `${#…}` ou `printenv …` lisent la valeur sans que `bash -x`
# ne la montre autrement que par son effet : seule la lecture du source les voit.
allowed_use='smoke_use_auth_config AUDIT_BASIC_AUTH || exit 2'
allowed_defined='if [[ -v AUDIT_BASIC_AUTH ]]; then'
basic_auth_substring_strays() {
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
  strays+="$(basic_auth_substring_strays "$script" "$TOOLS_DIR/$script.sh")"$'\n'
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
  basic_auth_substring_strays smoke-mcp "$TMP/mutant-smoke.sh"
}
substring_cases=(
  $'cat <<\'EOT\'\n# valeur : $AUDIT_BASIC_AUTH\nEOT'
  $'cat <<\'EOT\'\n  # \tAUDIT_BASIC_AUTH\nEOT'
  '# la variable AUDIT_BASIC_AUTH est lue ici'
  '    # AUDIT_BASIC_AUTH'
  'echo "${#AUDIT_BASIC_AUTH}"'
  'smoke_use_auth_config AUDIT_BASIC_AUTH || exit 2'
)
substring_labels=(
  "un heredoc dont une ligne commence par # et nomme le mot"
  "un heredoc dont une ligne indentée commence par # et nomme le mot"
  "un commentaire qui nomme le mot"
  "un commentaire indenté qui nomme le mot"
  "une longueur lue"
  "un second appel de la bibliothèque"
)
for i in "${!substring_cases[@]}"; do
  if [ -n "$(mutant_smoke "${substring_cases[$i]}")" ]; then
    pass "garde de la sous-chaîne AUDIT_BASIC_AUTH : ${substring_labels[$i]} est refusé"
  else
    fail "garde de la sous-chaîne AUDIT_BASIC_AUTH : ${substring_labels[$i]}" "la garde ne voit rien"
  fi
done
# Voisins permis : l'appel réindenté (quatre blancs, une tabulation) compte encore pour une fois, sans reste.
for indent in '    ' $'\t'; do
  sed "s|^smoke_use_auth_config AUDIT_BASIC_AUTH|${indent}&|" "$TOOLS_DIR/smoke-mcp.sh" > "$TMP/mutant-smoke.sh"
  if [ -z "$(basic_auth_substring_strays smoke-mcp "$TMP/mutant-smoke.sh")" ] \
     && grep -qE '^[[:space:]]+smoke_use_auth_config' "$TMP/mutant-smoke.sh"; then
    pass "garde de la sous-chaîne AUDIT_BASIC_AUTH : l'appel indenté de ${#indent} caractère(s) est une forme permise, comptée une fois"
  else
    fail "garde de la sous-chaîne AUDIT_BASIC_AUTH : appel indenté" "$(basic_auth_substring_strays smoke-mcp "$TMP/mutant-smoke.sh")"
  fi
done

# --- Garde des vidages d'environnement (C12-2, D3 de la treizième relecture) : aucune ligne de l'audit, des trois
# smokes ni de la bibliothèque n'énumère l'environnement ni ne lit une variable par son nom. Refusés :
#   - `declare`, `typeset`, `local`, `export`, `readonly` **sans nom** (seuls, ou suivis d'options seules : `declare -x`,
#     `export -n`), qui listent ; avec une option `p` dans une grappe quelconque (`declare -g -p NOM`) ; `declare`, `typeset`,
#     `local` avec une option `n` dans une grappe quelconque (une référence de nom, `local -r -n ref=X`, qui lit X par son
#     nom) ; `export -n -- NOM` et `readonly LIMITE=3` passent ; le mot finit sur la fin de ligne, `;`, `&`, `|`, `)`, `#`, une
#     redirection, un antislash (continuation), un guillemet ou un accent grave collé (`bash -c 'declare'`, x=`export`, C16-2) ;
#   - `compgen` avec `-v`, `-e`, `-A variable` ou `-A export` ; `set` après n'importe quel mot (`if set`, `then set`, `! set`,
#     `time set`, `bash -c 'set'`, x=`set`, `\set`), seul ou suivi d'une redirection, d'un tube, de `;`, `&&`, `||`, d'un guillemet,
#     d'un accent grave ou d'un antislash (`set\`) ; `set -o` passe ;
#   - `eval`, `printenv`, `env` (la commande : `"${env}"` n'en est pas une), `${!…}` ;
#   - `ps` avec l'option BSD `e` en toute position (`ps e`, `ps eww`, `ps -p $$ e`, `bash -c 'ps e'`, un antislash final) ; `-e` POSIX
#     liste les processus et non leur environnement : `ps -ef` passe (voir ps_lists_environment) ;
#   - le mot `environ`, prose comprise (`/proc/<pid>/environ` écrit de toute façon : `cd /proc/self && cat environ`,
#     `"$p/environ"`) ; la prose française écrit « à peu près » ; `environment` passe.
# Chaque ligne est lue, commentaires et heredocs compris. Un motif ancré sur des limites de mot : `set -e`, `set +x` passent.
# **L'exemption est une ligne** : `  _smoke_value="${!_smoke_name:-}"`, comparée par égalité, dans le corps de
# _smoke_write_auth_config ; tout autre ajout dans ce corps est jugé comme ailleurs.
# **Limite écrite** : la garde vise les fautes de bonne foi (une trace de débogage oubliée). Une liste de motifs n'est jamais
# complète contre un contournement délibéré (`eval` d'un nom composé, un `bash -c` dont la chaîne est assemblée, un mot coupé par un
# guillemet ou un antislash : `dec""lare`) : celui-là
# est arrêté par la relecture du diff, pas par ce test.
# ps_lists_environment <ligne> : vrai si une commande `ps` de la ligne porte l'option BSD `e` (`ps e`, `ps eww`, `ps auxe`), en
# toute position (`ps -p $$ e`, `ps -o args= -p $$ ewww`). Les options à tiret ne comptent pas : `-e` POSIX liste les processus,
# pas leur environnement (`ps -ef` passe), et la valeur d'une option qui en prend une (`-o etime=`, `-p $$`) est sautée. La
# ligne est coupée aux séparateurs (`| ; & ( ) < > #`) : `ps -p $$ | grep -q e` ne juge pas `grep`. Un guillemet, un accent grave ou un
# antislash collé à un mot est retiré avant le jugement, et un antislash final (continuation) refuse la ligne.
ps_lists_environment() {
  local segment token skip=0
  local ps_word='(^|[^[:alnum:]_.-])ps([[:space:]]|$|\\)'
  local -a tokens
  while IFS= read -r segment; do
    if ! [[ "$segment" =~ $ps_word ]]; then continue; fi
    # `ps\` : la ligne suivante porte les options.
    if [[ "${BASH_REMATCH[0]}" == *'\' ]]; then return 0; fi
    segment="${segment#*"${BASH_REMATCH[0]}"}"
    # Un antislash final : la ligne suivante continue la commande, ses options ne se jugent pas ici.
    if [[ "$segment" == *'\' ]]; then return 0; fi
    read -r -a tokens <<< "$segment"
    skip=0
    for token in "${tokens[@]}"; do
      # Un guillemet ou un accent grave collé au mot ne change pas ce que `ps` lit (`bash -c 'ps e'`, x=`ps e`).
      token="${token//[\'\"\`\\]/}"
      if [ "$skip" -eq 1 ]; then skip=0; continue; fi
      case "$token" in
        -[opCuUgGtsNOqw]) skip=1 ;;
        -*) ;;
        *) if [[ "$token" =~ ^[[:alpha:]]*e[[:alpha:]]*$ ]]; then return 0; fi ;;
      esac
    done
  done <<< "${1//[|;&()<>#]/$'\n'}"
  return 1
}
env_dump_lines() {
  local file="$1" n=0 line in_reader=0
  local word_before='(^|[^[:alnum:]_.-])'
  # Après le mot : des grappes d'options en `-` ou en `+` (`declare +x`) et le séparateur `--`, qui ne nomment rien, puis la fin de
  # la commande : la fin de ligne, `;`, `&`, `|`, `)`, `#`, une redirection, ou un antislash (`declare\` : la ligne suivante
  # continue la commande, des options ou un nom qu'on ne juge pas ici). Un guillemet ou un accent grave **collé** au mot ou à la
  # dernière option le ferme (`bash -c 'declare'`, `x=`declare``, `declare -x'`) ; après un blanc, il ne ferme que s'il est suivi d'une fin
  # de mot (`bash -c 'declare -x '`) : `export -n -- "$n"` ouvre le nom, il passe.
  local list_opts='([[:space:]]+(-|\+)[[:alnum:]]+|[[:space:]]+--)*'
  local list_plain='[[:space:]]*($|[;&|)#\\]|[0-9]*[<>])'
  local list_glued='[`'"'"'"]'
  local list_spaced='[[:space:]]+[`'"'"'"]($|[[:space:];&|)#<>])'
  local list_end="${list_opts}(${list_plain}|${list_glued}|${list_spaced})"
  local pat_bare="${word_before}(declare|typeset|local|readonly|export)${list_end}"
  # Une option `p` ou `n` dans n'importe quelle grappe d'options (`declare -g -p NOM`, `local -r -n ref=NOM`), pas la première seule.
  local pat_listing='(^|[^[:alnum:]_])(declare|typeset|local|readonly|export)([[:space:]]+-[[:alpha:]]*)*[[:space:]]+-[[:alpha:]]*p'
  local pat_nameref='(^|[^[:alnum:]_])(declare|typeset|local)([[:space:]]+-[[:alpha:]]*)*[[:space:]]+-[[:alpha:]]*n'
  local pat_compgen="${word_before}compgen[[:space:]]+(-[[:alpha:]]*[ve]|-A[[:space:]]+(variable|export))"
  local pat_eval='(^|[^[:alnum:]_.-])eval([^[:alnum:]_-]|$)'
  local pat_printenv='(^|[^[:alnum:]_.-])printenv([^[:alnum:]_]|$)'
  local pat_env='(^|[^[:alnum:]_.${-])env([^[:alnum:]_]|$)'
  # `set` après n'importe quel mot (`if set`, `then set`, `! set`, `time set`, `do set`), seul ou suivi d'une redirection, d'un
  # tube, d'un `;`, d'un `&&` ou d'un `||` ; `set -o`, `set -e`, `set +x` n'énumèrent rien.
  # Un guillemet, un accent grave ou un antislash avant ou après le mot le rend visible dans `bash -c 'set'`, x=`set`, `\set`
  # (l'antislash coupe l'alias) et `set\` (continuation).
  local pat_set='(^|[[:space:];&|(`{!'"'"'"\\]|\$\()set[[:space:]]*($|[|>;&)#`'"'"'"\\]|[0-9]*[<>])'
  local pat_indirect='\$\{!'
  # `unset` ne vide pas la copie de l'environnement que le processus a reçue à son lancement ; /proc/<pid>/environ la montre encore (R148b).
  local pat_environ='(^|[^[:alnum:]_])environ([^[:alnum:]_]|$)'
  while IFS= read -r line || [ -n "$line" ]; do
    n=$((n + 1))
    # La ligne d'interpréteur n'est pas un appel d'`env`.
    if [ "$n" -eq 1 ] && [[ "$line" == '#!'* ]]; then continue; fi
    if [ "$line" = '_smoke_write_auth_config() {' ]; then in_reader=1; fi
    if [ "$in_reader" -eq 1 ]; then
      if [ "$line" = '}' ]; then in_reader=0; fi
      # La seule exemption : la lecture de la valeur par son nom, dans le corps du lecteur.
      if [ "$line" = '  _smoke_value="${!_smoke_name:-}"' ]; then continue; fi
    fi
    if [[ "$line" =~ $pat_bare || "$line" =~ $pat_listing || "$line" =~ $pat_nameref || "$line" =~ $pat_compgen \
          || "$line" =~ $pat_eval || "$line" =~ $pat_printenv || "$line" =~ $pat_env || "$line" =~ $pat_set \
          || "$line" =~ $pat_indirect || "$line" =~ $pat_environ ]] || ps_lists_environment "$line"; then
      printf '%s:%s\n' "$n" "$line"
    fi
  done < "$file"
}
mutant_dump() {
  cp "$TOOLS_DIR/smoke-mcp.sh" "$TMP/mutant-dump.sh"
  printf '%s\n' "$@" >> "$TMP/mutant-dump.sh"
  env_dump_lines "$TMP/mutant-dump.sh"
}
# C16-2 : les formes où le mot est entouré d'un guillemet, d'un accent grave ou d'un antislash, ou suivi d'une continuation,
# vident l'environnement comme le mot nu. Chacune est **mesurée** : exécutée par `bash -c` avec une variable factice exportée
# (valeur aléatoire), elle imprime la valeur ; la même liste est ensuite refusée par la garde. Le script mesuré est la forme suivie
# de deux fins de ligne puis d'un `echo "${x-}"` : les formes qui affectent `x` (accent grave) l'impriment, et la continuation
# (`declare\`) finit sur la ligne vide.
dump_printing=(
  "bash -c 'declare'"
  'bash -c "export"'
  "bash -c 'declare -x'"
  "bash -c 'typeset'"
  'x=`declare`'
  'x=`export`'
  'x=`set`'
  '\set'
  'declare\'
  # Les formes voisines du même mécanisme (relevé de R197) : un autre mot, l'antislash avant, la continuation après des options.
  '\declare'
  '\export'
  'declare -x \'
  'export\'
  'set\'
  'typeset\'
  '"declare"'
  "'export'"
  "declare''"
  'x=`typeset -x`'
  "sh -c 'export'"
  "bash -c 'set'"
  # L'option BSD `e` de `ps` lit l'environnement d'un processus : citée, entre accents graves, ou avant une continuation.
  "bash -c 'ps e -p \$\$'"
  'x=`ps e -p $$`'
  'ps e -p $$ \'
)
measured_value="valeur-factice-$RANDOM$RANDOM"
for line in "${dump_printing[@]}"; do
  if [ "$(
    export MEASURED_VARIABLE="$measured_value"
    bash -c "$line"$'\n\necho "${x-}"' 2>&1 | grep -cF -- "$measured_value" || true
  )" -ge 1 ]; then
    pass "garde des vidages : bash imprime la variable factice avec « $line »"
  else
    fail "garde des vidages : « $line » n'imprime pas la variable" "la mesure ne voit pas la valeur factice"
  fi
done
dump_forbidden=(
  "${dump_printing[@]}"
  # Les mêmes mots de déclaration, qui n'impriment pas la variable exportée mais énumèrent des noms ou des valeurs.
  "bash -c 'readonly'"
  "bash -c 'local'"
  'x=`local`'
  'x=`readonly`'
  'readonly\'
  'local\'
  'ps -p $$ \'
  "bash -c 'ps e'"
  'x=`ps e`'
  'ps\'
  "bash -c 'declare -x '"
  'bash -c "export "'
  "ps e'"
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
  # D3 : les listes nues, sans option `p`.
  'declare'
  'typeset'
  'local'
  'export'
  'readonly'
  'declare -x'
  'typeset -x'
  'export -n'
  'declare -a'
  'declare | grep SOME'
  'export; echo fin'
  'declare 2>/dev/null'
  'declare -x # liste des exportées'
  # D3 : une référence qui lit une variable par son nom.
  'declare -n ref=SOME_NAME'
  'local -n ref=SOME_NAME'
  'typeset -n ref=SOME_NAME'
  'declare -rn ref=SOME_NAME'
  # D3 : l'énumération des noms et l'évaluation.
  'compgen -v'
  'compgen -e'
  'compgen -ve'
  'compgen -A export'
  'compgen -A variable'
  'set 2>/dev/null'
  'set 2>&1 | grep SOME'
  'set <<< x'
  'eval "v=\$$n"'
  'eval echo x'
  'x=$(eval echo "\$$n")'
  # D3 : les processus et /proc écrit autrement.
  'ps e'
  'ps eww -p $$'
  'ps auxe'
  # R168 (D3 de la quatorzième relecture) : `set` après n'importe quel mot, une option `p` ou `n` dans une grappe
  # qui n'est pas la première, l'option BSD `e` de `ps` en toute position, la prose qui écrit le mot `environ`.
  'if set; then'
  'if set | grep X'
  'then set'
  'time set'
  '! set > f'
  'do set'
  '{ set; }'
  '( set )'
  'true && set || true'
  'declare -g -p NOM'
  'typeset -r -p NOM'
  'local -r -n ref=NOM'
  'ps -p $$ e'
  'ps -o args= -p $$ ewww'
  'echo $(ps -p $$ eww)'
  'ps -p $$ -o comm=; ps e'
  '# environ 3 secondes'
  'cd /proc/self && cat environ'
  'cat "/proc"/self/environ'
  'p=/proc/self; cat "$p/environ"'
  'cat environ'
  'xargs -0 -n1 < environ'
  # R184 (C15-1) : `--` et les options en `+` ne sont pas des noms, et un `set` entre guillemets (`bash -c 'set'`) vide l'environnement.
  'declare --'
  'declare +x'
  'export --'
  'declare -x --'
  'typeset +r'
  "bash -c 'set'"
  'bash -c "set"'
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
  # D3 : le faux positif `${env}` (le `{` précède `env`) et les déclarations qui n'énumèrent rien.
  'x="${env}"'
  'echo "${env}"'
  'local name="$1"'
  'local name'
  'export -n -- "$n"'
  'readonly LIMIT=3'
  'declare -a list=()'
  'declare -A table'
  'declare -x SOME_NAME=1'
  'echo environment'
  'set -e'
  'set +x'
  'compgen -d'
  'compgen -f -- "$prefix"'
  'ps -p $$ -o comm='
  'echo "declare keyword."'
  # R168 : `-e` POSIX liste les processus, pas leur environnement ; `set` suivi d'une option n'est pas un vidage.
  'ps -ef'
  'ps -p $$ -e'
  'ps -o etime= -p $$'
  'ps -o etime -p $$'
  'ps -p $$ | grep -q e'
  'if set -o | grep -q xtrace; then'
  'local -r name="$1"'
  'declare -g -r NOM=1'
  'echo "# à peu près trois secondes"'
  # R184 : un nom suit `--` ou une option en `+` ; un `set` entre guillemets suivi d'une option n'énumère rien.
  'export -n -- NOM'
  'declare +x NOM=1'
  "bash -c 'set -e'"
  'bash -c "set +x"'
  # R197 : un mot de déclaration entre guillemets suivi d'autre chose, un `set` ou un `declare` qui n'énumère rien, une chaîne de prose.
  "echo 'declare keyword'"
  'echo "set the table"'
  'x=`set -e`'
  'x=`declare -a list`'
  "bash -c 'export -n -- NOM'"
  "bash -c 'declare -a list=()'"
  "bash -c 'readonly LIMIT=3'"
  'echo `date`'
  'x=`ps -ef`'
  "bash -c 'ps -p \$\$ -o comm='"
  'ps -p "$$" -o comm='
  'ps -o args= -p $$'
  'local settings \'
)
for line in "${dump_allowed[@]}"; do
  if [ -z "$(mutant_dump "$line")" ]; then
    pass "garde des vidages : « $line » passe"
  else
    fail "garde des vidages : voisin refusé à tort" "$line : $(mutant_dump "$line")"
  fi
done
# Les cinq fichiers réels n'en portent aucun ; la bibliothèque lit la valeur par indirection par la seule ligne
# exemptée de _smoke_write_auth_config : une indirection ailleurs dans la bibliothèque est refusée.
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

# R148b : la lecture de /proc/<pid>/environ est refusée dans le corps du lecteur comme partout (D3 : l'exemption est une ligne).
sed 's|^  _smoke_value="${!_smoke_name:-}"$|&\n  : < /proc/self/environ|' "$LIB" > "$TMP/mutant-lib.sh"
if [ -n "$(env_dump_lines "$TMP/mutant-lib.sh")" ] && grep -q '^  : < /proc/self/environ$' "$TMP/mutant-lib.sh"; then
  pass "garde des vidages : /proc/self/environ lu dans le corps de _smoke_write_auth_config est refusé"
else
  fail "/proc/environ dans le lecteur" "la garde ne voit rien"
fi

# D3 : l'exemption du lecteur est sa seule ligne de lecture par nom. Ce qu'on ajoute dans le corps de
# _smoke_write_auth_config est jugé comme partout ailleurs ; la ligne de lecture hors du lecteur est refusée.
reader_read='  _smoke_value="${!_smoke_name:-}"'
reader_added=(
  'printenv'
  'declare -p _smoke_name'
  'declare'
  'compgen -v'
  'set 2>/dev/null'
  'eval "echo x"'
  'ps eww -p $$'
  'cat environ'
)
for line in "${reader_added[@]}"; do
  python3 -I - "$LIB" "$TMP/mutant-lib.sh" "$reader_read" "  $line" <<'PY'
import sys
src, dst, anchor, added = sys.argv[1:5]
lines = open(src).read().split('\n')
out = []
for text in lines:
    out.append(text)
    if text == anchor:
        out.append(added)
open(dst, 'w').write('\n'.join(out))
PY
  if grep -qxF -- "  $line" "$TMP/mutant-lib.sh" && [ -n "$(env_dump_lines "$TMP/mutant-lib.sh")" ]; then
    pass "garde des vidages : « $line » ajouté dans le corps de _smoke_write_auth_config est refusé"
  else
    fail "vidage dans le lecteur : $line" "la garde ne voit rien"
  fi
done
cp "$LIB" "$TMP/mutant-lib.sh"
printf '%s\n' 'other_reader() {' "$reader_read" '}' >> "$TMP/mutant-lib.sh"
if [ -n "$(env_dump_lines "$TMP/mutant-lib.sh")" ]; then
  pass "garde des vidages : la ligne de lecture par nom hors de _smoke_write_auth_config est refusée"
else
  fail "ligne de lecture hors du lecteur" "la garde ne voit rien"
fi

# --- C13-5 : la variable perd son export dès sa lecture, avant le premier processus fils (`mktemp -d`) :
# un faux `mktemp` qui écrit son environnement dans un fichier ne doit pas la voir. Une valeur refusée n'est plus exportée non
# plus : le genre `refusee` du bloc R168 (`UNE_AUTH="sansdeuxpoints"` exportée) le vérifie, avec davantage d'assertions.
real_mktemp="$(command -v mktemp)"
mkdir "$TMP/fake-mktemp-bin"
printf '%s\n' '#!/bin/sh' 'env > "$MKTEMP_ENV_OUT"' "exec $real_mktemp \"\$@\"" > "$TMP/fake-mktemp-bin/mktemp"
chmod +x "$TMP/fake-mktemp-bin/mktemp"
exported_auth="$(generated_basic_auth)"
UNE_AUTH="$exported_auth"
export UNE_AUTH
rc=0
MKTEMP_ENV_OUT="$TMP/mktemp-env.txt" PATH="$TMP/fake-mktemp-bin:$PATH" smoke_use_auth_config UNE_AUTH || rc=$?
if [ "$rc" -eq 0 ] && [ -s "$TMP/mktemp-env.txt" ] && ! grep -q '^UNE_AUTH=' "$TMP/mktemp-env.txt" \
   && [ "$(cat "$SMOKE_AUTH_CONFIG")" = "user = \"$exported_auth\"" ]; then
  pass "smoke_use_auth_config : le mktemp lancé pendant l'écriture ne reçoit pas la variable"
else
  fail "export retiré trop tard" "rc=$rc ; mktemp la voit : $(grep -c '^UNE_AUTH=' "$TMP/mktemp-env.txt" || true)"
fi
smoke_cleanup
unset UNE_AUTH exported_auth real_mktemp

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

# --- CR15-7 : un outil que `command -v` ne trouve pas est un `fail` nommé, jamais un faux qui lancerait `exec ""` : le témoin
# noterait alors un échec muet au lieu d'un environnement, et le diagnostic dirait « fichier vide » sans dire pourquoi. Le `fail`
# est capté par une fonction du sous-shell ; aucun faux n'est écrit pour l'outil absent, et un outil présent l'est quand même.
absent_tool="outil-absent-$RANDOM$RANDOM"
rc=0
absent_out="$(fail() { printf 'FAIL %s | %s\n' "$1" "$2"; }; write_env_noting "$TMP/absent-bin" "$absent_tool" sh 2>&1)" || rc=$?
if [ "$rc" -eq 0 ] && [[ "$absent_out" == *"$absent_tool introuvable"* ]] && [ ! -e "$TMP/absent-bin/$absent_tool" ] \
   && [ -x "$TMP/absent-bin/sh" ] && ! grep -q 'exec ""' "$TMP/absent-bin"/* 2>/dev/null; then
  pass "write_env_noting : un outil introuvable est un fail nommé, sans faux écrit ni exec vide"
else
  fail "write_env_noting : outil introuvable" "rc=$rc ; sortie : $absent_out ; dossier : $(ls -A "$TMP/absent-bin" 2>&1 | tr '\n' ' ')"
fi
unset absent_tool absent_out

# --- R199 (St16-44, Q11 (A)) : `values_found_in` cherche une valeur générée sans qu'elle passe en argument d'un processus : les
# valeurs vont dans un fichier en 600 du dossier du test que `grep -F -f` lit (`ps` montrerait un motif en argument). Trois cas : le
# verdict (trouvée, absente, plusieurs valeurs dont une seule est là), le refus d'une valeur vide (un motif vide correspond à toute
# ligne : la recherche répondrait « trouvée » à tort), et la preuve par un faux `grep` qui note ses arguments et le mode du fichier de
# motifs : jamais la valeur en argument, un `-f` en 600.
pattern_value="$(generated_basic_auth)"
printf 'rien à voir ici\n' > "$TMP/haystack-clean"
printf 'la valeur %s est ici\n' "$pattern_value" > "$TMP/haystack-leak"
rc_leak=0; rc_clean=0; rc_second=0
values_found_in "$pattern_value" -- "$TMP/haystack-clean" "$TMP/haystack-leak" || rc_leak=$?
values_found_in "$pattern_value" -- "$TMP/haystack-clean" || rc_clean=$?
values_found_in "absente-$RANDOM" "$pattern_value" -- "$TMP/haystack-leak" || rc_second=$?
if [ "$rc_leak" -eq 0 ] && [ "$rc_clean" -eq 1 ] && [ "$rc_second" -eq 0 ] && [ ! -e "$TMP/value-patterns" ]; then
  pass "values_found_in : trouvée (0), absente (1), plusieurs valeurs dont une seule présente (0), le fichier de motifs retiré"
else
  fail "values_found_in : verdicts" \
    "trouvée : $rc_leak ; absente : $rc_clean ; deux valeurs : $rc_second ; fichier : $(ls "$TMP/value-patterns" 2>&1)"
fi
rc=0
( values_found_in "" -- "$TMP/haystack-leak" ) > /dev/null 2> "$TMP/stderr" || rc=$?
if [ "$rc" -eq 2 ] && grep -q 'valeur vide' "$TMP/stderr"; then
  pass "values_found_in : une valeur vide est refusée (sortie 2), jamais un motif vide qui trouverait toute ligne"
else
  fail "values_found_in : valeur vide" "rc=$rc ; stderr : $(cat -A "$TMP/stderr")"
fi
mkdir "$TMP/grep-bin"
real_grep="$(command -v grep)"
cat > "$TMP/grep-bin/grep" <<'FAKE'
#!/bin/bash
printf '%s\n' "$@" >> "$FAKE_GREP_ARGS"
previous=""
for argument in "$@"; do
  if [ "$previous" = -f ]; then stat -c %a "$argument" >> "$FAKE_GREP_MODES"; fi
  previous="$argument"
done
exec "$REAL_GREP" "$@"
FAKE
chmod +x "$TMP/grep-bin/grep"
: > "$TMP/grep.args"; : > "$TMP/grep.modes"
rc=0
FAKE_GREP_ARGS="$TMP/grep.args" FAKE_GREP_MODES="$TMP/grep.modes" REAL_GREP="$real_grep" PATH="$TMP/grep-bin:$PATH" \
  values_found_in "$pattern_value" -- "$TMP/haystack-leak" || rc=$?
grep_seen="$(cat "$TMP/grep.args")"
if [ "$rc" -eq 0 ] && [[ "$grep_seen" == *$'\n-f\n'* && "$grep_seen" != *"$pattern_value"* ]] && [ "$(cat "$TMP/grep.modes")" = 600 ]; then
  pass "values_found_in : grep reçoit un fichier de motifs en 600 (-f), jamais la valeur en argument"
else
  fail "values_found_in : arguments de grep" "rc=$rc ; modes : $(cat "$TMP/grep.modes") ; arguments : ${grep_seen//$pattern_value/<valeur>}"
fi
unset pattern_value real_grep grep_seen rc_leak rc_clean rc_second

# --- R199 (St16-34) : `call_carrying <fichier> <rang> <fragment>…` retrouve un appel du faux curl par ce qu'il porte, plus par son
# numéro de ligne : le n-ième appel qui porte tous les fragments, rien quand il n'y en a pas assez.
printf '%s\n' 'a -X POST https://x/un' 'b -X GET https://x/deux' 'c -X GET https://x/trois' > "$TMP/calls-sample"
if [ "$(call_carrying "$TMP/calls-sample" 1 -X GET)" = 'b -X GET https://x/deux' ] \
   && [ "$(call_carrying "$TMP/calls-sample" 2 -X GET)" = 'c -X GET https://x/trois' ] \
   && [ "$(call_carrying "$TMP/calls-sample" 1 -X GET trois)" = 'c -X GET https://x/trois' ] \
   && [ -z "$(call_carrying "$TMP/calls-sample" 3 -X GET)" ] && [ -z "$(call_carrying "$TMP/calls-sample" 1 -X DELETE)" ]; then
  pass "call_carrying : le n-ième appel qui porte tous les fragments, rien quand aucun ne les porte"
else
  fail "call_carrying" \
    "premier GET : $(call_carrying "$TMP/calls-sample" 1 -X GET) ; troisième : $(call_carrying "$TMP/calls-sample" 3 -X GET)"
fi

# --- R199 (St16-33) : un cas qui ne lit aucun code de sortie reçoit `-` à la place du code, jamais un `0` factice : CASE_RC reste
# vide, et un cas qui exige le code (`check_finds_library_under_cdpath`) le dit par un `fail` nommé au lieu de juger sur un zéro.
check_probe_rc() { printf '[%s]' "${CASE_RC-absente}"; }
seen_rc="$(run_case check_probe_rc "sonde" -)"
refused_out="$(fail() { printf 'FAIL %s | %s\n' "$1" "$2"; }
  run_case check_finds_library_under_cdpath "sans code" - CASE_ERR=e CASE_CALLS=c CASE_EXPECTED_RC=0 2>&1)"
if [ "$seen_rc" = "[]" ] && [[ "$refused_out" == *"CASE_RC n'est pas posée"* ]]; then
  pass "run_case : « - » laisse CASE_RC vide, et un cas qui exige le code refuse (fail nommé), jamais un zéro factice"
else
  fail "run_case : pas de code de sortie" "CASE_RC : $seen_rc ; cas qui l'exige : $refused_out"
fi
unset seen_rc refused_out

# --- C14-7, CR15-1 : les valeurs que pose `run_case` n'atteignent jamais un fils, même quand l'appelant exporte déjà ces noms.
# Une locale de bash garde l'attribut d'export de la variable globale qu'elle masque : sans `local +x`, un processus lancé par un
# cas recevrait la valeur que l'appel de `run_case` vient de poser (une Basic Auth générée). `local +x` ne garantit que cela : le
# fils d'un nom exporté par l'appelant voit la valeur **de l'appelant** (`exporte`, que le cas ne cherche pas : c'est la sienne).
# L'appelant exporte tous les noms avec cette valeur ; chaque valeur donnée à `run_case`, le libellé compris (St15-26), porte un
# repère généré, que le fils lancé par le cas ne doit voir nulle part. Le libellé est le repère seul : le verdict écrit son propre
# texte, pour ne pas imprimer le repère.
case_marker="$(generated_basic_auth)"
check_case_children_inherit_nothing() {
  local seen
  # Le repère va dans un fichier de motifs que le fils lit : jamais en argument du `bash -c` ni de son `grep`.
  seen="$(write_value_patterns "$case_marker"
    bash -c 'env | grep -cF -f "$1" || true' _ "$TMP/value-patterns"
    rm -f -- "$TMP/value-patterns")"
  if [ "$seen" = "0" ]; then
    pass "run_case : un fils du cas ne reçoit aucune valeur posée par run_case, même sous un nom exporté par l'appelant"
  else
    fail "run_case : les valeurs posées par run_case atteignent un fils" "$seen variable(s) de l'environnement d'un fils portent le repère"
  fi
}
# Les noms sont ceux de CASE_NAMES (smoke-cases.sh) : un nom ajouté à `run_case` est exporté et marqué ici sans autre ligne.
case_assignments=()
export CASE_LABEL=exporte CASE_RC=exporte
for case_name in "${CASE_NAMES[@]}"; do
  export "$case_name=exporte"
  case_assignments+=("$case_name=$case_marker-${case_name#CASE_}")
done
run_case check_case_children_inherit_nothing "$case_marker-LABEL" "$case_marker-RC" "${case_assignments[@]}"
unset CASE_LABEL CASE_RC "${CASE_NAMES[@]}" case_marker case_assignments case_name

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "Tous les cas passent."
