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
# et la liste des appels du faux `curl`, puis passe ces fichiers au cas, **par des variables
# nommées** posées devant l'appel (jamais six paramètres de position, St12-14) :
#   CASE_LABEL    le libellé du cas ;      CASE_RC     le code de sortie du script ;
#   CASE_ERR      le fichier de stderr ;   CASE_OUT    le fichier de stdout ;
#   CASE_CALLS    le fichier des appels du faux curl ;
#   CASE_TMPDIR   (facultatif) le dossier TMPDIR du script, qui doit rester vide ;
#   CASE_VALUE    la valeur de Basic Auth, qui ne doit apparaître nulle part.
# Exemple :  CASE_LABEL="…" CASE_RC="$rc" CASE_ERR="$TMP/err" CASE_OUT="$TMP/out" CASE_CALLS="$TMP/calls" check_empty_basic_auth
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

# check_empty_basic_auth  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_TMPDIR facultatif)
#
# Une Basic Auth définie mais vide (le secret de préprod manquant) : sortie 2, « secret de
# Basic Auth vide » en tête du stderr, aucun appel, rien sur stdout et, si le dossier est
# donné, rien dans TMPDIR. Sans ce refus, chaque 401 de la préprod se lirait comme un résultat.
# Un échec montre le stderr, les premiers appels et le contenu de TMPDIR.
check_empty_basic_auth() {
  local tmpdir="${CASE_TMPDIR:-}"
  if [ "${CASE_RC:?}" -eq 2 ] && grep -q '^AUDIT_BASIC_AUTH : secret de Basic Auth vide' "${CASE_ERR:?}" \
     && [ ! -s "${CASE_CALLS:?}" ] && [ ! -s "${CASE_OUT:?}" ] && { [ -z "$tmpdir" ] || [ -z "$(ls -A "$tmpdir")" ]; }; then
    pass "${CASE_LABEL:?}"
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
  if [ "${CASE_RC:?}" -eq 2 ] && grep -q -- "$1" "${CASE_ERR:?}" \
     && [ ! -s "${CASE_CALLS:?}" ] && [ ! -s "${CASE_OUT:?}" ] \
     && ! grep -qF -- "${CASE_VALUE:?}" "$CASE_ERR" "$CASE_OUT" "$CASE_CALLS"; then
    pass "${CASE_LABEL:?}"
  else
    fail "$CASE_LABEL" "rc=$CASE_RC ; err : $(grep -vF -- "$CASE_VALUE" "$CASE_ERR" | cat -A) ; appels : $(head -3 "$CASE_CALLS")"
  fi
}

# check_plain_http_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE)
#
# Une Basic Auth et une base en `http://` : sortie 2 avant tout appel de curl (qui enverrait
# `Authorization: Basic` en clair), « non https:// » en queue du message, la valeur nulle part.
check_plain_http_refused() {
  _check_value_refused ' : Basic Auth refusée sur une URL non https:// — rien de vérifié$'
}

# check_redirect_args_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE)
#
# Une Basic Auth et, dans les arguments de smoke_fetch, une option de redirection ou une URL qui n'est
# pas en `https://` : sortie 2 avant tout appel de curl, « option de redirection ou URL non https:// avec
# la Basic Auth — rien de vérifié » en queue du message, la valeur nulle part.
check_redirect_args_refused() {
  _check_value_refused ' : option de redirection ou URL non https:// avec la Basic Auth — rien de vérifié$'
}

# check_protocol_args_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE)
#
# Une Basic Auth et, dans les arguments de smoke_fetch, `--proto` ou `--proto-redir` (seuls ou en `--nom=valeur`) :
# ils l'emporteraient sur le `--proto-redir =https` posé avant eux. Sortie 2 avant tout appel de curl, « option de
# protocole (--proto, --proto-redir) refusée avec la Basic Auth — rien de vérifié » en queue du message, la valeur nulle part.
check_protocol_args_refused() {
  _check_value_refused ' : option de protocole (--proto, --proto-redir) refusée avec la Basic Auth — rien de vérifié$'
}
