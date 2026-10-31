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
# et la liste des appels du faux `curl`, puis passe ces fichiers au cas.
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

# check_empty_basic_auth <libellé> <rc> <fichier stderr> <fichier appels> <fichier stdout> [dossier TMPDIR]
#
# Une Basic Auth définie mais vide (le secret de préprod manquant) : sortie 2, « secret de
# Basic Auth vide » en tête du stderr, aucun appel, rien sur stdout et, si le dossier est
# donné, rien dans TMPDIR. Sans ce refus, chaque 401 de la préprod se lirait comme un résultat.
# Un échec montre le stderr, les premiers appels et le contenu de TMPDIR.
check_empty_basic_auth() {
  local label="$1" rc="$2" err="$3" calls="$4" out="$5" tmpdir="${6:-}"
  if [ "$rc" -eq 2 ] && grep -q '^AUDIT_BASIC_AUTH : secret de Basic Auth vide' "$err" \
     && [ ! -s "$calls" ] && [ ! -s "$out" ] && { [ -z "$tmpdir" ] || [ -z "$(ls -A "$tmpdir")" ]; }; then
    pass "$label"
  else
    fail "$label" "rc=$rc ; err : $(cat -A "$err") ; appels : $(head -3 "$calls") ; TMPDIR : ${tmpdir:+$(ls -A "$tmpdir")}"
  fi
}

# check_plain_http_refused <libellé> <rc> <fichier stderr> <fichier appels> <fichier stdout> <valeur>
#
# Une Basic Auth et une base en `http://` : sortie 2 avant tout appel de curl (qui enverrait
# `Authorization: Basic` en clair), « non https:// » en queue du message, et la valeur de la
# Basic Auth ni dans le stderr, ni dans le stdout, ni dans les appels.
check_plain_http_refused() {
  local label="$1" rc="$2" err="$3" calls="$4" out="$5" value="$6"
  if [ "$rc" -eq 2 ] && grep -q ' : Basic Auth refusée sur une URL non https:// — rien de vérifié$' "$err" \
     && [ ! -s "$calls" ] && [ ! -s "$out" ] && ! grep -qF -- "$value" "$err" "$out" "$calls"; then
    pass "$label"
  else
    fail "$label" "rc=$rc ; err : $(grep -vF -- "$value" "$err" | cat -A) ; appels : $(head -3 "$calls")"
  fi
}
