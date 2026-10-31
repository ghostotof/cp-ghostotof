# shellcheck shell=bash
#
# smoke-cases.sh
# ---------------------------------------------------------------------------
# À sourcer par les tests hors ligne de l'audit et des trois smokes : les cas que
# chacun refaisait mot pour mot (#748, R133). Ils attendent du test qui les source
# `pass <message>` et `fail <titre> <détail>`, et lui rendent leur verdict par
# ces deux fonctions ; aucun n'appelle le script testé.
#
# Un test lance le script (`run`), garde le code de sortie, le stderr, le stdout
# et la liste des appels du faux `curl`, puis les passe au cas par `run_case`, **un appel** :
#   run_case <cas> <libellé> <code de sortie> [CASE_ERR=<fichier>] [CASE_OUT=<fichier>] [CASE_CALLS=<fichier>] \
#     [CASE_TMPDIR=<dossier>] [CASE_VALUE=<valeur>]
# `run_case` pose en **locales** les variables que lit le cas (jamais exportées : CASE_VALUE est une Basic Auth
# générée, qu'aucun processus lancé par le cas n'a à recevoir, St13-42) :
#   CASE_LABEL    le libellé du cas ;      CASE_RC     le code de sortie du script ;
#   CASE_ERR      le fichier de stderr ;   CASE_OUT    le fichier de stdout ;
#   CASE_CALLS    le fichier des appels du faux curl ;
#   CASE_TMPDIR   (facultatif) le dossier TMPDIR du script, qui doit rester vide ;
#   CASE_VALUE    la valeur de Basic Auth, qui ne doit apparaître nulle part.
# Exemple :  run_case check_empty_basic_auth "AUDIT_BASIC_AUTH vide" "$rc" CASE_ERR="$TMP/err" CASE_OUT="$TMP/out" CASE_CALLS="$TMP/calls"
# Une variable que le cas exige et que l'appel ne pose pas est un `fail` nommé, jamais une sortie du test (St13-44).
#
# Les cas ne reçoivent jamais une valeur de Basic Auth sans que ce soit pour
# vérifier qu'elle n'apparaît nulle part : les valeurs des tests sont générées
# (generated_basic_auth), jamais écrites en clair.
# ---------------------------------------------------------------------------

# generated_basic_auth
#
# Imprime un couple « utilisateur:mot_de_passe » différent à chaque appel, qu'aucun
# fichier du dépôt ne porte et qu'aucun message ne contient par hasard.
generated_basic_auth() {
  printf 'u%s%s:p%s%s' "$RANDOM" "$RANDOM" "$RANDOM" "$RANDOM"
}

# write_env_noting_dirname <dossier>
#
# Écrit dans <dossier> un faux `dirname` qui ajoute son environnement au fichier que nomme $FAKE_DIRNAME_ENV, puis
# rend le vrai. Un test le place en tête du PATH d'un script lancé avec la Basic Auth : un `dirname` lancé avant que
# la bibliothèque retire l'export de la variable la porterait, et le fichier la montrerait (C13-5, A de R165).
write_env_noting_dirname() {
  local dir="$1" real_dirname
  real_dirname="$(command -v dirname)"
  mkdir -p "$dir"
  printf '%s\n' '#!/bin/sh' 'env >> "$FAKE_DIRNAME_ENV"' "exec $real_dirname \"\$@\"" > "$dir/dirname"
  chmod +x "$dir/dirname"
}

# run_case <cas> <libellé> <code de sortie> [CASE_ERR=… CASE_OUT=… CASE_CALLS=… CASE_TMPDIR=… CASE_VALUE=…]
#
# Pose CASE_LABEL, CASE_RC et les cinq autres variables en locales (celles que l'appel ne donne pas restent vides),
# puis appelle le cas (`check_*`), qui les lit par la portée dynamique de bash. Une affectation qui ne nomme pas une
# de ces cinq variables est une faute d'appel : un `fail`, le cas n'est pas lancé.
run_case() {
  local _case="$1" CASE_LABEL="$2" CASE_RC="$3" _assignment
  local CASE_ERR="" CASE_OUT="" CASE_CALLS="" CASE_TMPDIR="" CASE_VALUE=""
  shift 3
  for _assignment in "$@"; do
    case "$_assignment" in
      CASE_ERR=* | CASE_OUT=* | CASE_CALLS=* | CASE_TMPDIR=* | CASE_VALUE=*)
        printf -v "${_assignment%%=*}" '%s' "${_assignment#*=}"
        ;;
      *)
        fail "$CASE_LABEL" \
          "run_case : « ${_assignment%%=*} » n'est pas une variable de cas (CASE_ERR, CASE_OUT, CASE_CALLS, CASE_TMPDIR, CASE_VALUE)"
        return 0
        ;;
    esac
  done
  "$_case"
}

# _case_incomplete <nom de variable de cas…>
#
# Rend 0, après un `fail` qui nomme la première, si une des variables nommées est vide ; 1 si toutes sont posées.
# Un cas qui s'appuie dessus rend alors son verdict sans sortir du test (`set -e`, `${…:?}`).
_case_incomplete() {
  local _name
  for _name in "$@"; do
    if [ -z "${!_name:-}" ]; then
      fail "${CASE_LABEL:-cas sans libellé}" "$_name n'est pas posée : l'appel du cas est incomplet"
      return 0
    fi
  done
  return 1
}

# check_empty_basic_auth  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_TMPDIR facultatif)
#
# Une Basic Auth définie mais vide (le secret de préprod manquant) : sortie 2, « secret de
# Basic Auth vide » en tête du stderr, aucun appel, rien sur stdout et, si le dossier est
# donné, rien dans TMPDIR. Sans ce refus, chaque 401 de la préprod se lirait comme un résultat.
# Un échec montre le stderr, les premiers appels et le contenu de TMPDIR.
check_empty_basic_auth() {
  local tmpdir="${CASE_TMPDIR:-}"
  _case_incomplete CASE_LABEL CASE_RC CASE_ERR CASE_CALLS CASE_OUT && return 0
  if [ "$CASE_RC" -eq 2 ] && grep -q '^AUDIT_BASIC_AUTH : secret de Basic Auth vide' "$CASE_ERR" \
     && [ ! -s "$CASE_CALLS" ] && [ ! -s "$CASE_OUT" ] && { [ -z "$tmpdir" ] || [ -z "$(ls -A "$tmpdir")" ]; }; then
    pass "$CASE_LABEL"
  else
    fail "$CASE_LABEL" \
      "rc=$CASE_RC ; err : $(cat -A "$CASE_ERR") ; appels : $(head -3 "$CASE_CALLS") ; TMPDIR : ${tmpdir:+$(ls -A "$tmpdir")}"
  fi
}

# _check_value_refused <fin du message>  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE)
#
# Le corps des deux cas de refus qui suivent : sortie 2 avant tout appel de curl, le message qui finit par le
# motif donné, et la valeur de la Basic Auth ni dans le stderr, ni dans le stdout, ni dans les appels.
_check_value_refused() {
  _case_incomplete CASE_LABEL CASE_RC CASE_ERR CASE_CALLS CASE_OUT CASE_VALUE && return 0
  if [ "$CASE_RC" -eq 2 ] && grep -q -- "$1" "$CASE_ERR" \
     && [ ! -s "$CASE_CALLS" ] && [ ! -s "$CASE_OUT" ] \
     && ! grep -qF -- "$CASE_VALUE" "$CASE_ERR" "$CASE_OUT" "$CASE_CALLS"; then
    pass "$CASE_LABEL"
  else
    fail "$CASE_LABEL" "rc=$CASE_RC ; err : $(grep -vF -- "$CASE_VALUE" "$CASE_ERR" | cat -A) ; appels : $(head -3 "$CASE_CALLS")"
  fi
}

# check_non_https_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE)
#
# Une Basic Auth et une base en `http://` : sortie 2 avant tout appel de curl (qui enverrait
# `Authorization: Basic` en clair), « non https:// » en queue du message, la valeur nulle part.
check_non_https_refused() {
  _check_value_refused ' : Basic Auth refusée sur une URL non https:// — rien de vérifié$'
}

# check_args_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE)
#
# Une Basic Auth et, dans les arguments de smoke_fetch, une option ou une URL que la bibliothèque refuse
# (redirection, URL, configuration, protocole ou hôte) : sortie 2 avant tout appel de curl, « option ou URL
# refusée avec la Basic Auth (redirection, URL, configuration, protocole ou hôte) — rien de vérifié » en queue
# du message, la valeur nulle part.
check_args_refused() {
  _check_value_refused ' : option ou URL refusée avec la Basic Auth (redirection, URL, configuration, protocole ou hôte) — rien de vérifié$'
}

# check_curl_opts_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE)
#
# Une Basic Auth et, dans SMOKE_CURL_OPTS, une option que la bibliothèque refuse : sortie 2 avant tout appel de
# curl, « SMOKE_CURL_OPTS porte une option refusée avec la Basic Auth — rien de vérifié » en queue du message,
# la valeur nulle part.
check_curl_opts_refused() {
  _check_value_refused ' : SMOKE_CURL_OPTS porte une option refusée avec la Basic Auth — rien de vérifié$'
}
