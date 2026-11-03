# shellcheck shell=bash
#
# smoke-cases.sh
# ---------------------------------------------------------------------------
# À sourcer par les tests hors ligne de l'audit et des trois smokes : les cas que
# chacun refaisait mot pour mot (#748, R133). Ils attendent du test qui les source
# `pass <message>` et `fail <titre> <détail>`, et lui rendent leur verdict par
# ces deux fonctions. Seuls ceux qui reçoivent CASE_RUNNER lancent le script testé, par lui (« Le lanceur », plus bas).
#
# Un test lance le script (`run`), garde le code de sortie, le stderr, le stdout
# et la liste des appels du faux `curl`, puis les passe au cas par `run_case`, **un appel** :
#   run_case <cas> <libellé> <code de sortie|-> [<NOM>=<valeur>]…      (<NOM> : un des noms de CASE_NAMES, plus bas)
# Un cas qui ne lit aucun code de sortie de l'appel reçoit `-` (check_root_script_dir_is_slash, et chaque cas à CASE_RUNNER, qui lit
# le code que le lanceur rend) : CASE_RC reste vide, jamais un `0` factice (St16-33) ; un cas qui exige le code (CASE_RC dans sa liste
# de `_case_incomplete`) refuse alors par un `fail` nommé.
# `run_case` pose en **locales** les variables que lit le cas : les valeurs qu'il pose n'atteignent jamais un processus lancé
# par le cas (CASE_VALUE est une Basic Auth générée, qu'aucun fils n'a à recevoir, St13-42). Elles sont déclarées `local +x` :
# une locale de bash garde l'attribut d'export de la variable globale qu'elle masque, de sorte qu'un nom déjà exporté par
# l'appelant porterait la valeur du cas jusqu'aux processus qu'il lance (C14-7). `+x` n'en garantit pas plus : un fils voit,
# sous un nom que l'appelant a exporté, la valeur de l'appelant, la locale n'étant pas exportée (CR15-1, mesuré sur le bash 5.2.37
# de l'hôte ; celui du runner, 5.2.21, ne se lance pas hors ligne) :
#   CASE_LABEL    le libellé du cas ;      CASE_RC     le code de sortie du script ;
#   CASE_ERR      le fichier de stderr ;   CASE_OUT    le fichier de stdout ;
#   CASE_CALLS    le fichier des appels du faux curl ;
#   CASE_TMPDIR   (facultatif) le dossier TMPDIR du script, qui doit rester vide ;
#   CASE_VALUE    la valeur de Basic Auth, qui ne doit apparaître nulle part ;
#   CASE_RANK     (facultatif) le rang de l'argument refusé, que dit le message des deux cas de refus d'argument ;
#   CASE_ENV_FILE le fichier où les faux outils de write_env_noting ont noté leur environnement, que lit le cas des fils ;
#   CASE_EXPECTED_RC (facultatif) le code de sortie que le cas exige du script (CASE_RC est celui qu'il a rendu) ;
#   CASE_SCRIPT   (facultatif) le chemin du script dont le cas relit le bloc d'amorçage ;
#   CASE_ARGS     (facultatif) le fichier des arguments du faux curl, que lisent les cas qui y cherchent `-K` ou une valeur ;
#   CASE_RUNNER   le nom de la fonction du test qui lance le script (« Le lanceur », plus bas).
# Exemple :  case_vars_set "$TMP/err" "$TMP/calls" "$TMP/out"
#            run_case check_empty_basic_auth "AUDIT_BASIC_AUTH vide" "$rc" "${CASE_VARS[@]}"
# Une variable que le cas exige et que l'appel ne pose pas est un `fail` nommé, jamais une sortie du test (St13-44).
#
# Les cas ne reçoivent jamais une valeur de Basic Auth sans que ce soit pour
# vérifier qu'elle n'apparaît nulle part : les valeurs des tests sont générées
# (generated_basic_auth), jamais écrites en clair, et jamais passées en argument d'un processus (values_found_in, plus bas).
# Les tests qui sourcent ce fichier posent $TMP, leur dossier temporaire, que lisent values_found_in et lines_without_values.
#
# Le lanceur (CASE_RUNNER, Sp18-4, St18-23) : `$CASE_RUNNER <nom>` lance le script avec les réponses de son cas nominal, range ses
# sorties sous ce nom comme le `run` du test (les fichiers que l'appel donne en CASE_CALLS, CASE_ERR… sont ceux de ce nom) et **rend**
# le code de sortie du script : le cas lit ce code, jamais une variable globale. Le cas pose devant l'appel l'environnement qu'il juge
# (AUDIT_BASIC_AUTH, FAKE_TOOL_ENV, PATH, CDPATH, RUN_SCRIPT) ; le lanceur lance `${RUN_SCRIPT:-<son script>}`. Les cas l'appellent
# sous `||` : un code non nul ne fait pas sortir le test (`set -e`). Trois cas le prennent, chacun sous son nom de lancement :
# check_no_child_sees_basic_auth (`witness`), check_finds_library_under_cdpath (`cdpath`), check_hollow_basic_auth_values (`creuse`).
# Un test leur donne le plus souvent la même fonction (`run_nominal`).
# ---------------------------------------------------------------------------

# generated_basic_auth
#
# Imprime un couple « utilisateur:mot_de_passe » différent à chaque appel, qu'aucun
# fichier du dépôt ne porte et qu'aucun message ne contient par hasard.
generated_basic_auth() {
  printf 'u%s%s:p%s%s' "$RANDOM" "$RANDOM" "$RANDOM" "$RANDOM"
}

# write_value_patterns <valeur>…
#
# Écrit les valeurs dans $TMP/value-patterns, un motif par ligne non vide, un fichier en 600 que `grep -F -f` lit : une Basic Auth
# générée, ou un fragment, ne passe jamais en argument d'un processus, où `ps` la montrerait (St16-44, Q11 (A) de la seizième
# relecture). `<(printf …)` est écarté (St17-27) : son chemin /dev/fd/N ne vit que le temps de la commande qui porte la substitution,
# et le fichier sert à plusieurs commandes de suite (celui des fils de smoke-http.test.sh : écrit, lu par un `bash -c`, retiré). Le
# fichier naît dans $TMP et non dans TMPDIR, que des tests exigent vide ; l'appelant le retire (`rm -f -- "$TMP/value-patterns"`)
# quand il l'a lu. Une valeur qui porte des sauts de ligne donne un motif par ligne qui n'est pas faite de blancs seuls : une ligne
# vide serait un motif qui correspond à toute ligne (CR17-5 : `$'\nabc'`, `$'abc\n'` et `$'a\n\nb'` en écrivaient une), une ligne de
# blancs (` `, une tabulation) à toute ligne qui en porte un (C18-4). Une valeur vide, ou faite de blancs seuls (sauts de ligne, espaces,
# tabulations), n'a aucune ligne à écrire : elle est refusée et la fonction fait sortir le shell qui l'exécute (code 2), car une
# recherche « elle n'est nulle part » serait fausse, une recherche « elle y est » vraie, quoi que le fichier contienne. Ce n'est pas
# un `fail` : la fonction s'appelle souvent dans un `$( )`, où le compteur d'échecs du test serait perdu. Les trois fonctions qui
# suivent l'appellent ; un test peut aussi donner le chemin à un processus fils qui doit lire les motifs.
write_value_patterns() {
  local value rest line
  for value in "$@"; do
    if [ -z "${value//[[:space:]]/}" ]; then
      echo "write_value_patterns : une valeur vide ou faite de blancs seuls (sauts de ligne, espaces, tabulations) est refusée" >&2
      exit 2
    fi
  done
  rm -f -- "${TMP:?write_value_patterns : TMP absente}/value-patterns"
  (
    umask 077
    for value in "$@"; do
      rest="$value"
      while [ -n "$rest" ]; do
        line="${rest%%$'\n'*}"
        if [[ "$rest" == *$'\n'* ]]; then rest="${rest#*$'\n'}"; else rest=""; fi
        if [ -n "${line//[[:space:]]/}" ]; then printf '%s\n' "$line"; fi
      done
    done > "$TMP/value-patterns"
  )
}

# values_found_in <valeur>… -- <fichier>…
#
# Rend 0 si une des valeurs figure dans un des fichiers, 1 sinon ; un code de grep supérieur à 1 (un fichier absent) ou un appel
# incomplet fait sortir le test en 2, jamais un « absente » faux : sous `! values_found_in …`, une erreur de grep passerait pour un vert.
# Les valeurs vont dans un fichier de motifs (write_value_patterns), retiré après la recherche. Une valeur à plusieurs lignes donne
# un motif par ligne : elle est « trouvée » dès qu'une de ses lignes l'est (C18-8). C'est sûr pour une recherche d'absence
# (`! values_found_in`, où un fragment trouvé suffit à dire la fuite) ; aucune recherche de présence n'en passe une, hors les cas de
# smoke-http.test.sh qui éprouvent cette fonction même.
values_found_in() {
  local values=() found=0
  while [ "$#" -gt 0 ] && [ "$1" != -- ]; do
    values+=("$1")
    shift
  done
  if [ "${#values[@]}" -eq 0 ] || [ "$#" -lt 2 ]; then
    echo "values_found_in : usage : <valeur>… -- <fichier>…" >&2
    exit 2
  fi
  shift
  write_value_patterns "${values[@]}"
  grep -qF -f "$TMP/value-patterns" -- "$@" || found=$?
  rm -f -- "$TMP/value-patterns"
  if [ "$found" -gt 1 ]; then
    echo "values_found_in : grep a échoué (code $found) sur : $*" >&2
    exit 2
  fi
  return "$found"
}

# _grep_value_lines <-F|-vF> <fichier> <valeur>…
#
# Les lignes du fichier qui portent (-F) ou ne portent pas (-vF) une des valeurs, par le fichier de motifs de write_value_patterns :
# jamais une valeur en argument. Sert aux messages d'échec, à lire dans un `$( )` (une valeur vide y fait sortir le sous-shell).
_grep_value_lines() {
  local mode="$1" file="$2"
  shift 2
  write_value_patterns "$@"
  grep "$mode" -f "$TMP/value-patterns" -- "$file" || true
  rm -f -- "$TMP/value-patterns"
}

# lines_with_values <fichier> <valeur>…  /  lines_without_values <fichier> <valeur>…
#
# Les lignes qui portent une des valeurs (la fuite qu'un échec montre) ou qui n'en portent aucune (le stderr d'un échec, montré sans
# la valeur qu'il ne devrait pas contenir).
lines_with_values() { _grep_value_lines -F "$@"; }
lines_without_values() { _grep_value_lines -vF "$@"; }

# call_carrying <fichier des appels> <rang> <fragment>…
#
# Imprime le n-ième appel noté (une ligne par appel du faux curl) qui porte tous les fragments (R199, St16-34) : un appel se retrouve
# par ce qu'il porte, plus par son numéro de ligne, qu'un appel ajouté ou retiré déplace. Rien quand moins de <rang> appels les portent :
# le verdict du test le dit alors par son détail vide, jamais par une sortie du test (`set -e`). Les fragments se comparent tels quels.
call_carrying() {
  local file="$1" rank="$2" seen=0 line fragment carries
  shift 2
  while IFS= read -r line || [ -n "$line" ]; do
    carries=1
    for fragment in "$@"; do
      if [[ "$line" != *"$fragment"* ]]; then
        carries=0
        break
      fi
    done
    if [ "$carries" -eq 1 ]; then
      seen=$((seen + 1))
      if [ "$seen" -eq "$rank" ]; then
        printf '%s\n' "$line"
        return 0
      fi
    fi
  done < "$file"
}

# write_env_noting <dossier> <outil>…
#
# Écrit dans <dossier>, pour chaque outil nommé, un faux qui ajoute son environnement au fichier que nomme $FAKE_TOOL_ENV,
# sous une ligne `== <outil>`, puis lance le vrai (son chemin, entre guillemets). Un test place ce dossier en tête du PATH
# d'un script lancé avec la Basic Auth : un processus lancé avant que la bibliothèque retire la variable la porterait, et le
# fichier le montrerait sous le nom de l'outil (C13-5, A de R165 ; un témoin commun des fils, D2 de la quatorzième relecture).
# Un outil que `command -v` ne trouve pas est un `fail` nommé et reste sans faux (CR15-7) : un faux qui lancerait `exec ""` noterait
# un échec muet, et le témoin dirait « fichier vide » sans dire pourquoi. Le test continue (rendre 1 le ferait sortir sous `set -e`).
write_env_noting() {
  local dir="$1" tool real
  shift
  mkdir -p "$dir"
  for tool in "$@"; do
    if ! real="$(command -v "$tool")"; then
      fail "write_env_noting : $tool introuvable" "aucun faux n'est écrit pour lui : le PATH du test ne le porte pas"
      continue
    fi
    printf '%s\n' '#!/bin/sh' "{ echo '== $tool'; env; } >> \"\$FAKE_TOOL_ENV\"" "exec \"$real\" \"\$@\"" > "$dir/$tool"
    chmod +x "$dir/$tool"
  done
}

# case_vars_set <fichier stderr> <fichier des appels> <fichier stdout> [<valeur de Basic Auth>]
#
# Pose le tableau CASE_VARS : les affectations CASE_ERR, CASE_CALLS, CASE_OUT (et CASE_VALUE si la valeur est donnée) que
# presque chaque appel de `run_case` aurait à répéter mot pour mot (St14-34). Un test l'écrit une fois, juste avant l'appel :
#   case_vars_set "$TMP/err" "$TMP/calls" "$TMP/out" "$basic_auth"
#   run_case check_non_https_refused "<libellé>" "$rc" "${CASE_VARS[@]}"
# Ce qu'un appel ajoute en plus (CASE_TMPDIR, CASE_RANK…) reste écrit à l'appel.
case_vars_set() {
  CASE_VARS=(CASE_ERR="$1" CASE_CALLS="$2" CASE_OUT="$3")
  if [ -n "${4:-}" ]; then
    CASE_VARS+=(CASE_VALUE="$4")
  fi
}

# Les noms de variable de cas qu'un appel de `run_case` peut donner (St15-35), écrits une fois : la déclaration des locales, le
# contrôle des affectations et le message d'une faute d'appel les lisent. CASE_LABEL et CASE_RC ne sont pas du nombre : ils
# viennent des deux paramètres qui suivent le cas. Un test qui boucle sur les noms (smoke-http.test.sh) lit ce tableau aussi.
CASE_NAMES=(CASE_ERR CASE_OUT CASE_CALLS CASE_TMPDIR CASE_VALUE CASE_RANK CASE_ENV_FILE CASE_EXPECTED_RC CASE_SCRIPT CASE_ARGS CASE_RUNNER)

# _case_assign <NOM=valeur>
#
# Rend 0 après avoir posé la variable de cas que nomme l'affectation (la locale de `run_case`, par la portée dynamique), 1 si le
# nom n'est pas dans CASE_NAMES.
_case_assign() {
  local _name
  for _name in "${CASE_NAMES[@]}"; do
    if [[ "$1" == "$_name="* ]]; then
      printf -v "$_name" '%s' "${1#*=}"
      return 0
    fi
  done
  return 1
}

# run_case <cas> <libellé> <code de sortie|-> [<NOM>=<valeur>]…
#
# Pose CASE_LABEL, CASE_RC et les variables de CASE_NAMES en locales (celles que l'appel ne donne pas restent vides),
# puis appelle le cas (`check_*`), qui les lit par la portée dynamique de bash. Une affectation qui ne nomme pas une
# de ces variables est une faute d'appel : un `fail`, le cas n'est pas lancé. Aucune n'est exportée (`local +x`, voir l'en-tête).
# `-` à la place du code de sortie : le cas n'en lit aucun, CASE_RC reste vide (un cas qui l'exige le dit, `_case_incomplete`).
run_case() {
  local +x _case="$1" CASE_LABEL="$2" CASE_RC="$3" _assignment _name _names
  if [ "$CASE_RC" = - ]; then
    CASE_RC=""
  fi
  for _name in "${CASE_NAMES[@]}"; do
    local +x "$_name="
  done
  shift 3
  for _assignment in "$@"; do
    if ! _case_assign "$_assignment"; then
      printf -v _names '%s, ' "${CASE_NAMES[@]}"
      fail "$CASE_LABEL" "run_case : « ${_assignment%%=*} » n'est pas une variable de cas (${_names%, })"
      return 0
    fi
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
  local calls
  _case_incomplete CASE_LABEL CASE_RC CASE_ERR CASE_CALLS CASE_OUT CASE_VALUE && return 0
  if [ "$CASE_RC" -eq 2 ] && grep -q -- "$1" "$CASE_ERR" \
     && [ ! -s "$CASE_CALLS" ] && [ ! -s "$CASE_OUT" ] \
     && ! values_found_in "$CASE_VALUE" -- "$CASE_ERR" "$CASE_OUT" "$CASE_CALLS"; then
    pass "$CASE_LABEL"
  else
    # Les appels se montrent sans la valeur, comme le stderr (C18-8) : un appel qui la porterait est la fuite que le cas cherche.
    calls="$(lines_without_values "$CASE_CALLS" "$CASE_VALUE" | head -3)"
    fail "$CASE_LABEL" "rc=$CASE_RC ; err : $(lines_without_values "$CASE_ERR" "$CASE_VALUE" | cat -A) ; appels : $calls"
  fi
}

# check_non_https_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE)
#
# Une Basic Auth et une base en `http://` : sortie 2 avant tout appel de curl (qui enverrait
# `Authorization: Basic` en clair), « non https:// » en queue du message, la valeur nulle part.
check_non_https_refused() {
  _check_value_refused ' : Basic Auth refusée sur une URL non https:// — rien de vérifié$'
}

# check_args_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE, CASE_RANK)
#
# Une Basic Auth et, dans les arguments de smoke_fetch, un argument hors de la liste d'autorisation : sortie 2 avant tout
# appel de curl, « argument n° <rang> hors des options admises avec la Basic Auth — rien de vérifié » en queue du message
# (le rang, jamais le texte de l'argument), la valeur nulle part.
check_args_refused() {
  _case_incomplete CASE_RANK && return 0
  _check_value_refused " : argument n° $CASE_RANK hors des options admises avec la Basic Auth — rien de vérifié\$"
}

# check_curl_opts_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE, CASE_RANK)
#
# Une Basic Auth et, dans SMOKE_CURL_OPTS, un élément hors de la liste d'autorisation : sortie 2 avant tout appel de curl,
# « SMOKE_CURL_OPTS, élément n° <rang> hors des options admises avec la Basic Auth — rien de vérifié » en queue du message,
# la valeur nulle part.
check_curl_opts_refused() {
  _case_incomplete CASE_RANK && return 0
  _check_value_refused " : SMOKE_CURL_OPTS, élément n° $CASE_RANK hors des options admises avec la Basic Auth — rien de vérifié\$"
}

# check_args_file_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE, CASE_RANK)
#
# Une Basic Auth et, dans les arguments de smoke_fetch, la valeur d'une option admise qui fait lire un fichier local à curl
# (`-H @f`, `-d @f`, `--data-binary @<autre que smoke_data_file>`, `-b` sans « = ») : sortie 2 avant tout appel de curl,
# « argument n° <rang> : une valeur qui lit un fichier local est refusée avec la Basic Auth — rien de vérifié » en queue du
# message (le rang de la valeur, jamais son texte), la valeur de la Basic Auth nulle part.
check_args_file_refused() {
  _case_incomplete CASE_RANK && return 0
  _check_value_refused " : argument n° $CASE_RANK : une valeur qui lit un fichier local est refusée avec la Basic Auth — rien de vérifié\$"
}

# check_curl_opts_file_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE, CASE_RANK)
#
# Le même refus pour un élément de SMOKE_CURL_OPTS : « SMOKE_CURL_OPTS, élément n° <rang> : une valeur qui lit un fichier local
# est refusée avec la Basic Auth — rien de vérifié » en queue du message.
check_curl_opts_file_refused() {
  _case_incomplete CASE_RANK && return 0
  _check_value_refused \
    " : SMOKE_CURL_OPTS, élément n° $CASE_RANK : une valeur qui lit un fichier local est refusée avec la Basic Auth — rien de vérifié\$"
}

# L'oracle du refus `writes` de la bibliothèque (_smoke_say_refused_arg), lu par les deux cas qui suivent (St18-22 : préfixé `CASE_`
# comme les variables des cas). Un seul texte pour toutes les valeurs de `-D` que _smoke_writes_on_call_file refuse (Q2 (A) de la
# dix-huitième relecture).
CASE_WRITE_REFUSAL="une valeur qui peut écrire sur un fichier de la Basic Auth ou de son corps est refusée"

# check_args_write_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE, CASE_RANK)
#
# Une Basic Auth et, dans les arguments de smoke_fetch, une valeur de `-D` refusée pour la raison `writes` (_smoke_writes_on_call_file,
# C16-4, R211, C18-5) : curl pourrait y écrire, et le `-K` écrasé serait relu au prochain appel comme une configuration vide. Sortie 2
# avant tout appel de curl, « argument n° <rang> : <CASE_WRITE_REFUSAL> — rien de vérifié » en queue du message (le rang de la valeur,
# jamais son texte), la valeur de la Basic Auth nulle part.
check_args_write_refused() {
  _case_incomplete CASE_RANK && return 0
  _check_value_refused " : argument n° $CASE_RANK : $CASE_WRITE_REFUSAL — rien de vérifié\$"
}

# check_curl_opts_write_refused  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_OUT, CASE_VALUE, CASE_RANK)
#
# Le même refus pour un élément de SMOKE_CURL_OPTS : « SMOKE_CURL_OPTS, élément n° <rang> : <CASE_WRITE_REFUSAL> — rien de vérifié »
# en queue du message.
check_curl_opts_write_refused() {
  _case_incomplete CASE_RANK && return 0
  _check_value_refused " : SMOKE_CURL_OPTS, élément n° $CASE_RANK : $CASE_WRITE_REFUSAL — rien de vérifié\$"
}

# check_http_base_served_without_basic_auth  (CASE_LABEL, CASE_RC, CASE_ERR, CASE_CALLS, CASE_ARGS)
#
# Sans Basic Auth, une base en `http://` est servie comme avant (un smoke local, R212, St17-23) : aucun appel ne porte de `-K`, le
# faux curl a été appelé, et le stderr ne dit pas « non https:// ». Le pendant de check_non_https_refused, qui exige le refus.
check_http_base_served_without_basic_auth() {
  _case_incomplete CASE_LABEL CASE_RC CASE_ERR CASE_CALLS CASE_ARGS && return 0
  if ! grep -q -- ' -K ' "$CASE_ARGS" && [ -s "$CASE_CALLS" ] && ! grep -q 'non https://' "$CASE_ERR"; then
    pass "$CASE_LABEL"
  else
    fail "$CASE_LABEL" "rc=$CASE_RC ; err : $(cat -A "$CASE_ERR") ; args : $(head -1 "$CASE_ARGS")"
  fi
}

# check_hollow_basic_auth_values  (CASE_LABEL, CASE_RUNNER, CASE_ERR, CASE_OUT, CASE_CALLS ; CASE_ARGS et CASE_TMPDIR facultatifs)
#
# Un utilisateur ou un mot de passe vide n'est pas une Basic Auth (C11-8, R212, St17-23) : pour chacune des trois valeurs creuses
# (`:`, `<repère>:` et `:<repère>`, le repère étant généré), sortie 2 avant tout appel de curl, « Basic Auth sans utilisateur ou sans
# mot de passe » en tête d'une ligne du stderr, rien sur stdout, rien dans TMPDIR si le dossier est donné, et le repère nulle part
# (stdout, stderr, et le fichier des arguments s'il est donné). Le cas lance le script par `AUDIT_BASIC_AUTH=<valeur> $CASE_RUNNER creuse`
# et juge le code que le lanceur rend. Un `pass` par valeur, le libellé suivi de la forme de la valeur (St18-33 : `<repère>:` et
# `:<repère>` ont la même longueur, un nombre de caractères ne les distinguait pas), jamais du repère.
check_hollow_basic_auth_values() {
  local token hollow form code detail calls tmpdir="${CASE_TMPDIR:-}" searched
  _case_incomplete CASE_LABEL CASE_RUNNER CASE_ERR CASE_OUT CASE_CALLS && return 0
  token="g$RANDOM$RANDOM"
  searched=("$CASE_OUT" "$CASE_ERR")
  if [ -n "${CASE_ARGS:-}" ]; then
    searched+=("$CASE_ARGS")
  fi
  for hollow in ':' "${token}:" ":${token}"; do
    case "$hollow" in
      :) form="« : » seul" ;;
      *:) form="utilisateur sans mot de passe" ;;
      *) form="mot de passe sans utilisateur" ;;
    esac
    code=0
    AUDIT_BASIC_AUTH="$hollow" "$CASE_RUNNER" creuse || code=$?
    if [ "$code" -eq 2 ] && grep -q '^AUDIT_BASIC_AUTH : Basic Auth sans utilisateur ou sans mot de passe' "$CASE_ERR" \
       && [ ! -s "$CASE_CALLS" ] && [ ! -s "$CASE_OUT" ] && { [ -z "$tmpdir" ] || [ -z "$(ls -A "$tmpdir")" ]; } \
       && ! values_found_in "$token" -- "${searched[@]}"; then
      pass "$CASE_LABEL ($form) → sortie 2, aucun appel${tmpdir:+, aucun dossier}, valeur jamais citée"
    else
      # Les appels se montrent sans le repère, comme le stderr (C18-8).
      calls="$(lines_without_values "$CASE_CALLS" "$token")"
      detail="code rendu par $CASE_RUNNER : $code ; err : $(lines_without_values "$CASE_ERR" "$token" | cat -A) ; appels : $calls"
      fail "$CASE_LABEL ($form)" "$detail${tmpdir:+ ; TMPDIR : $(ls -A "$tmpdir")}"
    fi
  done
}

# check_no_child_sees_basic_auth  (CASE_LABEL, CASE_RUNNER, CASE_EXPECTED_RC, CASE_ENV_FILE)
#
# Aucun processus que le script a lancé ne reçoit la variable de la Basic Auth (D2 de la quatorzième relecture). Le cas écrit les faux
# `dirname`, `mktemp`, `date`, `openssl` et `rm` de write_env_noting dans $TMP/witness-bin, vide CASE_ENV_FILE et lance le script par
# `$CASE_RUNNER witness` avec une Basic Auth générée, FAKE_TOOL_ENV=CASE_ENV_FILE et ce dossier en tête du PATH ; le fichier ne doit
# avoir aucune ligne `AUDIT_BASIC_AUTH=`. Trois conditions, dont les deux premières évitent un vert à vide : le fichier n'est pas vide
# (au moins un faux a tourné : un PATH qui ne mène à aucun faux ne prouverait rien), et le lanceur a rendu le code attendu (le script
# est allé jusqu'où le test le croit). Un échec nomme les outils dont l'environnement porte la variable. Le test peut relire le
# fichier après le cas (l'audit y cherche la ligne `== curl` de ses propres faux).
check_no_child_sees_basic_auth() {
  local carriers code=0
  _case_incomplete CASE_LABEL CASE_RUNNER CASE_EXPECTED_RC CASE_ENV_FILE && return 0
  write_env_noting "$TMP/witness-bin" dirname mktemp date openssl rm
  : > "$CASE_ENV_FILE"
  AUDIT_BASIC_AUTH="$(generated_basic_auth)" FAKE_TOOL_ENV="$CASE_ENV_FILE" PATH="$TMP/witness-bin:$PATH" \
    "$CASE_RUNNER" witness || code=$?
  if [ ! -s "$CASE_ENV_FILE" ]; then
    fail "$CASE_LABEL" "aucun faux outil n'a noté son environnement (fichier vide) : le témoin ne prouverait rien ; code=$code"
    return 0
  fi
  carriers="$(awk '/^== /{ tool = $2 } /^AUDIT_BASIC_AUTH=/{ seen[tool] = 1 } END { for (t in seen) print t }' \
    "$CASE_ENV_FILE" | sort | tr '\n' ' ')"
  if [ "$code" -eq "$CASE_EXPECTED_RC" ] && [ -z "$carriers" ]; then
    pass "$CASE_LABEL"
  else
    fail "$CASE_LABEL" "code=$code (attendu $CASE_EXPECTED_RC) ; outils dont l'environnement porte la variable : ${carriers:-aucun}"
  fi
}

# check_finds_library_under_cdpath  (CASE_LABEL, CASE_RUNNER, CASE_SCRIPT, CASE_EXPECTED_RC, CASE_ERR, CASE_CALLS)
#
# Le script lancé par un chemin relatif avec un CDPATH qui nomme un dossier de même nom (`$TMP/cdpath-decoy/tools`) : `cd tools`
# imprimerait alors le dossier que CDPATH trouve, et le `source` de la bibliothèque échouerait (C14-11). CASE_SCRIPT est le chemin
# absolu du script que le lanceur lance, sous un dossier `tools` : le cas crée le leurre, se place, dans un sous-shell, dans le dossier
# qui porte ce `tools`, et lance `CDPATH=<leurre> RUN_SCRIPT=tools/<nom du script> $CASE_RUNNER cdpath` avec une Basic Auth générée
# (l'audit ne lit sa bibliothèque qu'avec une Basic Auth ; un smoke la lit dans les deux cas). Le lanceur a rendu le code attendu
# (l'audit sort en 1 par ses propres échecs, un smoke en 0), aucune ligne « introuvable ou invalide » n'est sur le stderr, et le faux
# curl a été appelé (le script est allé jusqu'à ses requêtes). Un échec montre le stderr et les appels sans la valeur (C18-8).
check_finds_library_under_cdpath() {
  local value code=0 err calls
  _case_incomplete CASE_LABEL CASE_RUNNER CASE_SCRIPT CASE_EXPECTED_RC CASE_ERR CASE_CALLS && return 0
  if [[ "${CASE_SCRIPT%/*}" != /*/tools ]]; then
    fail "$CASE_LABEL" "CASE_SCRIPT n'est pas un chemin absolu sous un dossier tools : $CASE_SCRIPT"
    return 0
  fi
  mkdir -p "$TMP/cdpath-decoy/tools"
  value="$(generated_basic_auth)"
  ( cd -- "${CASE_SCRIPT%/tools/*}" && AUDIT_BASIC_AUTH="$value" CDPATH="$TMP/cdpath-decoy" RUN_SCRIPT="tools/${CASE_SCRIPT##*/}" \
      "$CASE_RUNNER" cdpath ) || code=$?
  if [ "$code" -eq "$CASE_EXPECTED_RC" ] && ! grep -q 'introuvable ou invalide' "$CASE_ERR" && [ -s "$CASE_CALLS" ]; then
    pass "$CASE_LABEL"
  else
    err="$(lines_without_values "$CASE_ERR" "$value")"
    calls="$(lines_without_values "$CASE_CALLS" "$value" | head -3)"
    fail "$CASE_LABEL" "code=$code (attendu $CASE_EXPECTED_RC) ; err : $err ; appels : $calls"
  fi
}

# check_root_script_dir_is_slash  (CASE_LABEL, CASE_SCRIPT ; le code de sortie est `-` : le cas n'en lit aucun, Sp15-22)
#
# Un script placé à la racine (`/script.sh`) a un dossier vide après `${BASH_SOURCE[0]%/*}` : le bloc d'amorçage lit alors `/`
# (C14-11, Sp15-22). Aucun test ne peut poser un script à la racine : le cas relit le bloc du script tel qu'il est écrit, de la ligne
# `<nom>="${BASH_SOURCE[0]}"` à la ligne `# shellcheck source=tools/lib/smoke-http.sh` qui la suit (ses deux repères, dans l'audit
# comme dans les trois smokes), remplace `${BASH_SOURCE[0]}` par `/script.sh` et l'exécute dans un shell à part : la variable qu'il
# pose doit valoir `/`. Un bloc introuvable entre ses repères, ou une variable qui vaut autre chose, est un `fail` nommé.
check_root_script_dir_is_slash() {
  local block name value needle="\${BASH_SOURCE[0]}"
  _case_incomplete CASE_LABEL CASE_SCRIPT && return 0
  block="$(awk '
    /^[[:space:]]*[A-Za-z_]+="\$\{BASH_SOURCE\[0\]\}"$/ { on = 1 }
    /^[[:space:]]*# shellcheck source=tools\/lib\/smoke-http\.sh$/ { on = 0 }
    on { print }' "$CASE_SCRIPT")"
  if [ -z "$block" ]; then
    fail "$CASE_LABEL" "le bloc d'amorçage de $CASE_SCRIPT est introuvable entre ses deux repères"
    return 0
  fi
  name="${block%%=*}"
  name="${name//[[:space:]]/}"
  value="$(bash -c "${block//"$needle"//script.sh}; printf '%s' \"\${$name}\"" 2>&1)"
  if [ "$value" = "/" ]; then
    pass "$CASE_LABEL"
  else
    fail "$CASE_LABEL" "un script à la racine lit « $value » dans $name, non « / » ; bloc relu : $(printf '%s' "$block" | tr '\n' ' ')"
  fi
}
