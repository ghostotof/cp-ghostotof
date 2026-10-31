#!/usr/bin/env bash
#
# smoke-http.sh
# ---------------------------------------------------------------------------
# Bibliothèque sourçable : ce que les scripts de fumée et d'audit qui appellent
# `curl` contre un déploiement réel écrivaient chacun de leur côté (#748, R57).
# Sourcée par :
#   - tools/smoke-dead-session-cookie.sh, tools/smoke-login-throttling.sh,
#     tools/smoke-mcp.sh (smoke_write_auth_config, smoke_require_auth_config,
#     smoke_cleanup, smoke_header, smoke_headers, smoke_excerpt, smoke_fetch) ;
#   - tools/audit-prod.sh (smoke_write_auth_config, smoke_require_auth_config et
#     smoke_cleanup, et seulement si AUDIT_BASIC_AUTH est définie) ;
#   - tools/tests/smoke-http.test.sh (hors ligne, faux curl).
#
# Six fonctions publiques (plus smoke_cleanup, qui défait la première) :
#   smoke_write_auth_config <nom>          le fichier de configuration `-K` de la
#                                          Basic Auth d'une variable, dans un
#                                          dossier privé (SMOKE_AUTH_CONFIG) ;
#                                          smoke_fetch le lit ;
#   smoke_require_auth_config <nom>        refuse (2) une variable définie mais
#                                          vide, après la précédente ;
#   smoke_cleanup                          retire ce dossier ; à poser dans le
#                                          `trap` de chaque appelant ;
#   smoke_header <fichier> <nom>           la valeur d'un en-tête de réponse (la
#                                          dernière s'il y en a plusieurs) ;
#   smoke_headers <fichier> <nom>          toutes ses valeurs, une par ligne ;
#   smoke_excerpt <texte>                  un extrait sans caractère de contrôle ;
#   smoke_fetch <libellé> <url> [args…]    un appel curl qui rend statut et corps
#                                          et dit un échec réseau ou un
#                                          fichier de Basic Auth absent.
#
# Le binaire est `SMOKE_CURL` (`curl` s'il est vide ; les tests hors ligne y
# mettent un faux) : un seul nom, lu ici seulement.
#
# Pas de `set -e` ici, ni d'appel réseau à la source : une bibliothèque sourcée
# ne change pas les options du shell qui la source. Aucune fonction ne fait
# sortir l'appelant ; c'est lui qui décide (`|| exit 2`, `|| exit "$?"`).
#
# Limite écrite (St8-12) : sourcer la bibliothèque deux fois remet SMOKE_AUTH_DIR
# et SMOKE_AUTH_CONFIG à vide ; le dossier d'un premier smoke_write_auth_config
# n'est alors plus retiré par le `trap`. Aucun script ne le fait (chacun source une
# fois, en tête) ; un marqueur pour ce cas serait une astuce de shell de plus, et
# le reste est un dossier 0700 de l'utilisateur sous TMPDIR.
# ---------------------------------------------------------------------------

# Le dossier privé de la Basic Auth et le fichier qu'il porte. Vides à la source,
# quoi que l'environnement ait contenu : une valeur héritée n'est jamais crue, ou
# le `trap` de l'appelant supprimerait un fichier qui n'est pas le sien (C7-6).
# Posées par smoke_write_auth_config seule, lues par les `trap` via smoke_cleanup.
SMOKE_AUTH_DIR=""
SMOKE_AUTH_CONFIG=""

# smoke_cleanup
#
# Retire le dossier que smoke_write_auth_config a créé (jamais un autre : la
# variable est vide à la source) et remet les deux variables à vide. Retour 0 :
# c'est la dernière commande d'un `trap`, elle ne change pas le code de sortie.
# smoke_write_auth_config l'appelle aussi en tête (voir plus bas).
smoke_cleanup() {
  if [ -n "$SMOKE_AUTH_DIR" ]; then
    rm -rf -- "$SMOKE_AUTH_DIR"
  fi
  SMOKE_AUTH_DIR=""
  SMOKE_AUTH_CONFIG=""
  return 0
}

# _smoke_is_variable_name <nom>
#
# Vrai pour un nom de variable que les fonctions de la Basic Auth peuvent lire par `${!nom}` :
# un identifiant, sans le préfixe `_smoke_` ni `SMOKE_AUTH_` (les raisons sont plus bas).
_smoke_is_variable_name() {
  [[ "$1" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "$1" != _smoke_* && "$1" != SMOKE_AUTH_* ]]
}

# smoke_write_auth_config <nom d'une variable valant "utilisateur:mot_de_passe">
#
# Écrit le fichier de configuration curl (`-K`) qui porte la Basic Auth, dans un
# dossier privé (`mktemp -d`, 0700) sous TMPDIR, fichier en 600 (`umask 077`) :
# SMOKE_AUTH_DIR et SMOKE_AUTH_CONFIG reçoivent les deux chemins, rien n'est
# imprimé, et smoke_fetch passe `-K` à chaque appel tant que SMOKE_AUTH_CONFIG est
# posé (jamais rangé dans SMOKE_CURL_OPTS, donc jamais périmé). Jamais `-u` : les
# arguments d'un processus se lisent dans `ps`, seul le chemin du fichier apparaît
# dans ceux de curl. Les guillemets et antislashs sont échappés au format attendu
# par curl, donc n'importe quel mot de passe convient.
#
# Une variable vide ou absente : retour 0, aucun dossier, SMOKE_AUTH_CONFIG vide. C'est à
# l'appelant de juger ce vide : smoke_require_auth_config l'arrête (sortie 2) quand la
# variable est définie, une variable absente n'envoie aucune Basic Auth (la prod, un
# smoke local). La valeur n'est lue que sous `set +x`.
#
# Le nom est un identifiant (`[A-Za-z_][A-Za-z0-9_]*`), jamais une expression :
# `${!nom}` évalue un indice, `x[$(commande)]` exécuterait la commande (C7-7). Il ne
# commence pas par `_smoke_` (CR8-5) : les locales de la fonction portent ce
# préfixe, et `${!nom}` lit d'abord une locale du même nom — un nom comme `value`
# ou `rc` donnerait une Basic Auth fausse et silencieuse. Il ne commence pas non plus
# par `SMOKE_AUTH_` (C9-5) : SMOKE_AUTH_DIR et SMOKE_AUTH_CONFIG sont remises à vide
# par l'appel de tête avant la lecture, le retour serait 0 et aucune Basic Auth ne
# serait posée. La fonction lit elle-même la valeur (C7-9) et l'écrit **dans le shell
# courant**, sous `set +x` (l'état de xtrace relevé avant, rétabli après) : la valeur
# ne passe en argument d'aucune fonction ni d'aucun processus, donc ni dans `ps` ni
# dans la trace d'un `set -x`.
#
# Un retour à la ligne (ou un CR) ouvrirait une seconde directive dans ce fichier
# (`url = …`, `output = …`) : l'échappement n'agit que sur `"` et `\`. Une valeur non
# vide sans « : » (des blancs compris) n'est pas un couple utilisateur:mot_de_passe.
# Les deux sont refusées avant tout dossier, sans citer la valeur (c'est un secret),
# en nommant la variable reçue (#748 C6-9), retour 2 — celui d'un défaut
# d'environnement. Retour 2 aussi pour un nom invalide, un dossier ou un fichier qui
# ne s'écrit pas (le dossier est alors retiré).
#
# Elle appelle smoke_cleanup **en tête**, avant tout, y compris la validation du nom
# (décision 6 de #748) : un second appel remplace le premier, il ne laisse ni dossier
# ni fichier d'identifiant derrière lui, et il n'existe jamais qu'un dossier. Ce que
# l'appel de tête retire est ce que la bibliothèque a créé elle-même : les variables
# sont vides à la source, un dossier hérité de l'environnement n'est jamais visé.
#
# L'appelant pose son `trap` **avant** (`trap 'rm -f "$headers_file"; smoke_cleanup'
# EXIT`) puis `smoke_write_auth_config AUDIT_BASIC_AUTH || exit 2` et
# `smoke_require_auth_config AUDIT_BASIC_AUTH || exit 2`. Un arrêt entre la
# création du dossier et l'affectation que lit le `trap` peut laisser un dossier
# privé **vide**, jamais la valeur : elle n'est écrite qu'une fois le chemin connu
# du `trap` (C7-8).
smoke_write_auth_config() {
  local _smoke_name="${1:-}" _smoke_value _smoke_umask _smoke_traced=0 _smoke_rc=0
  smoke_cleanup
  if ! _smoke_is_variable_name "$_smoke_name"; then
    echo "smoke_write_auth_config : nom de variable invalide" >&2
    return 2
  fi
  case "$-" in *x*) _smoke_traced=1 ;; esac
  set +x
  _smoke_value="${!_smoke_name:-}"
  if [[ "$_smoke_value" == *$'\n'* || "$_smoke_value" == *$'\r'* ]]; then
    echo "$_smoke_name contient un retour à la ligne : refusé, il ouvrirait une directive dans le fichier de configuration curl" >&2
    _smoke_rc=2
  elif [ -n "$_smoke_value" ] && [[ "$_smoke_value" != *:* ]]; then
    echo "$_smoke_name : Basic Auth sans « : » (utilisateur:mot_de_passe attendu), refusée" >&2
    _smoke_rc=2
  elif [ -n "$_smoke_value" ]; then
    _smoke_umask="$(umask)"
    umask 077
    SMOKE_AUTH_DIR="$(mktemp -d)" || _smoke_rc=2
    if [ "$_smoke_rc" -eq 0 ]; then
      SMOKE_AUTH_CONFIG="$SMOKE_AUTH_DIR/curl-auth.conf"
      _smoke_value="${_smoke_value//\\/\\\\}"
      _smoke_value="${_smoke_value//\"/\\\"}"
      printf 'user = "%s"\n' "$_smoke_value" > "$SMOKE_AUTH_CONFIG" || { smoke_cleanup; _smoke_rc=2; }
    fi
    umask "$_smoke_umask"
  fi
  _smoke_value=""
  if [ "$_smoke_traced" -eq 1 ]; then
    set -x
  fi
  return "$_smoke_rc"
}

# smoke_require_auth_config <nom d'une variable valant "utilisateur:mot_de_passe">
#
# À appeler juste après smoke_write_auth_config, qui a posé SMOKE_AUTH_CONFIG ou non :
# une variable **définie** mais vide (le secret de préprod manquant) ne pose rien, et un
# script qui court alors sans Basic Auth lirait chaque 401 de la préprod comme un résultat.
# Retour 2 et « <nom> : secret de Basic Auth vide, rien de vérifié » sur stderr ; 0 sinon,
# y compris pour une variable non définie (la prod n'a pas de Basic Auth). Le vide se juge sur
# SMOKE_AUTH_CONFIG et la définition sur `[[ -v ]]` : la valeur n'est jamais développée,
# donc jamais dans la trace d'un `set -x`. Même validation du nom que
# smoke_write_auth_config. Aucune fonction ne fait sortir l'appelant : `|| exit 2`.
smoke_require_auth_config() {
  local _smoke_name="${1:-}"
  if ! _smoke_is_variable_name "$_smoke_name"; then
    echo "smoke_require_auth_config : nom de variable invalide" >&2
    return 2
  fi
  if [[ -v $_smoke_name ]] && [ -z "$SMOKE_AUTH_CONFIG" ]; then
    echo "$_smoke_name : secret de Basic Auth vide, rien de vérifié" >&2
    return 2
  fi
  return 0
}

# smoke_headers <fichier d'en-têtes> <nom>
#
# Toutes les valeurs de l'en-tête dans la réponse que curl a écrite dans le fichier
# (`-D`), une par ligne, dans l'ordre : nom insensible à la casse (HTTP/2 l'écrit en
# minuscules), CR retiré, rien si absent. C'est la lecture des en-têtes multiples
# (`Set-Cookie`) ; smoke_header en prend la dernière.
smoke_headers() {
  tr -d '\r' < "$1" | awk -v name="$2" '
    tolower(substr($0, 1, length(name) + 1)) == tolower(name) ":" { sub(/^[^:]*:[[:space:]]*/, ""); print }'
}

# smoke_header <fichier d'en-têtes> <nom>
#
# La valeur d'un en-tête de la réponse que curl a écrite dans le fichier (`-D`) :
# la dernière occurrence de smoke_headers (la dernière réponse d'une redirection),
# vide si absent.
smoke_header() {
  smoke_headers "$1" "$2" | tail -n 1
}

# smoke_excerpt <texte>
#
# Les 160 premiers caractères du texte, tout caractère de contrôle changé en
# espace : ce texte part dans le journal de la CI, où un `\r` ouvrirait une ligne
# `::commande::` que le runner exécuterait.
smoke_excerpt() {
  printf '%s' "$1" | tr '\000-\037\177' '[ *]' | cut -c1-160
}

# smoke_fetch <libellé> <url> [arguments curl…]
#
# Lance `"$SMOKE_CURL" "${SMOKE_CURL_OPTS[@]}" [-K "$SMOKE_AUTH_CONFIG"] "$@" -w
# '\n%{http_code}' "$url"` et pose :
#   SMOKE_STATUS   le code HTTP (la dernière ligne de la sortie) ;
#   SMOKE_BODY     le corps (le reste).
# L'appelant déclare SMOKE_CURL_OPTS (`-sS`, les délais, `-D <fichier>`…) ; SMOKE_CURL
# vaut `curl` s'il est vide. La Basic Auth n'y figure jamais : smoke_fetch lit
# SMOKE_AUTH_CONFIG à chaque appel (St8-12), donc aucun `-K` périmé après un second
# smoke_write_auth_config.
#
# Rend 1 (aucune réponse) ou 2 (fichier de la Basic Auth absent), après l'avoir dit sur
# stderr, sans jamais faire sortir l'appelant :
#   - un `curl` qui n'obtient aucune réponse (réseau, DNS, délai) sort avec son code
#     (6, 7, 28…), hors du contrat 0/1/2 des scripts, si on le laisse à `set -e` :
#     « <libellé> : curl a échoué (code <n> : réseau, DNS ou délai) — rien de vérifié » ;
#   - SMOKE_AUTH_CONFIG posé mais son fichier disparu : « <libellé> : fichier de la
#     Basic Auth absent — rien de vérifié », curl n'est pas appelé (sans `-K`, il
#     lirait le 401 de la préprod comme un résultat).
# L'appelant : `smoke_fetch … || exit "$?"`.
smoke_fetch() {
  local label="$1" url="$2" response rc _smoke_auth=()
  shift 2
  if [ -n "$SMOKE_AUTH_CONFIG" ]; then
    if [ ! -f "$SMOKE_AUTH_CONFIG" ]; then
      echo "$label : fichier de la Basic Auth absent — rien de vérifié" >&2
      return 2
    fi
    _smoke_auth=(-K "$SMOKE_AUTH_CONFIG")
  fi
  response="$("${SMOKE_CURL:-curl}" "${SMOKE_CURL_OPTS[@]}" "${_smoke_auth[@]}" "$@" -w '\n%{http_code}' "$url")" || {
    rc=$?
    echo "$label : curl a échoué (code $rc : réseau, DNS ou délai) — rien de vérifié" >&2
    return 1
  }
  # Lues par l'appelant, pas par la bibliothèque.
  # shellcheck disable=SC2034
  SMOKE_STATUS="${response##*$'\n'}"
  # shellcheck disable=SC2034
  SMOKE_BODY="${response%$'\n'*}"
}
