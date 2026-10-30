#!/usr/bin/env bash
#
# smoke-http.sh
# ---------------------------------------------------------------------------
# Bibliothèque sourçable : ce que les scripts de fumée et d'audit qui appellent
# `curl` contre un déploiement réel écrivaient chacun de leur côté (#748, R57).
# Sourcée par :
#   - tools/smoke-dead-session-cookie.sh, tools/smoke-login-throttling.sh,
#     tools/smoke-mcp.sh (smoke_add_basic_auth, smoke_cleanup, smoke_header,
#     smoke_excerpt, smoke_fetch) ;
#   - tools/audit-prod.sh (smoke_write_auth_config et smoke_cleanup, et
#     seulement avec AUDIT_BASIC_AUTH) ;
#   - tools/tests/smoke-http.test.sh (hors ligne, faux curl).
#
# Cinq fonctions publiques (plus smoke_cleanup, qui défait la première) :
#   smoke_write_auth_config <nom>          le fichier de configuration `-K` de la
#                                          Basic Auth d'une variable, dans un
#                                          dossier privé (SMOKE_AUTH_CONFIG) ;
#   smoke_cleanup                          retire ce dossier ; à poser dans le
#                                          `trap` de chaque appelant ;
#   smoke_add_basic_auth <nom>             la Basic Auth d'une variable ajoutée
#                                          à SMOKE_CURL_OPTS ;
#   smoke_header <fichier> <nom>           la valeur d'un en-tête de réponse ;
#   smoke_excerpt <texte>                  un extrait sans caractère de contrôle ;
#   smoke_fetch <libellé> <url> [args…]    un appel curl qui rend statut et corps
#                                          et dit un échec réseau.
#
# Le binaire est `SMOKE_CURL` (`curl` s'il est vide ; les tests hors ligne y
# mettent un faux) : un seul nom, lu ici seulement.
#
# Pas de `set -e` ici, ni d'appel réseau à la source : une bibliothèque sourcée
# ne change pas les options du shell qui la source. Aucune fonction ne fait
# sortir l'appelant ; c'est lui qui décide (`|| exit 2`, `|| exit 1`).
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
smoke_cleanup() {
  if [ -n "$SMOKE_AUTH_DIR" ]; then
    rm -rf -- "$SMOKE_AUTH_DIR"
  fi
  SMOKE_AUTH_DIR=""
  SMOKE_AUTH_CONFIG=""
  return 0
}

# smoke_write_auth_config <nom d'une variable valant "utilisateur:mot_de_passe">
#
# Écrit le fichier de configuration curl (`-K`) qui porte la Basic Auth, dans un
# dossier privé (`mktemp -d`, 0700) sous TMPDIR, fichier en 600 (`umask 077`) :
# SMOKE_AUTH_DIR et SMOKE_AUTH_CONFIG reçoivent les deux chemins, rien n'est
# imprimé. Jamais `-u` : les arguments d'un processus se lisent dans `ps`, seul le
# chemin du fichier apparaît dans ceux de curl. Les guillemets et antislashs sont
# échappés au format attendu par curl, donc n'importe quel mot de passe convient.
#
# Le nom est un identifiant (`[A-Za-z_][A-Za-z0-9_]*`), jamais une expression :
# `${!nom}` évalue un indice, `x[$(commande)]` exécuterait la commande (C7-7).
# La fonction lit elle-même la valeur (C7-9) et l'écrit **dans le shell courant**,
# sous `set +x` (l'état de xtrace relevé avant, rétabli après) : la valeur ne passe
# en argument d'aucune fonction ni d'aucun processus, donc ni dans `ps` ni dans la
# trace d'un `set -x` de la CI.
#
# Un retour à la ligne (ou un CR) ouvrirait une seconde directive dans ce fichier
# (`url = …`, `output = …`) : l'échappement n'agit que sur `"` et `\`. Refusé avant
# tout dossier, sans citer la valeur (c'est un secret), en nommant la variable reçue
# (#748 C6-9), retour 2 — celui d'un défaut d'environnement. Retour 2 aussi pour un
# nom invalide, un dossier ou un fichier qui ne s'écrit pas (le dossier est alors
# retiré).
#
# Les scripts de fumée passent par smoke_add_basic_auth ; audit-prod.sh, qui garde
# son propre `CURL_OPTS`, l'appelle directement, après avoir posé son
# `trap 'smoke_cleanup' EXIT`, et pour une valeur non vide seulement. Un arrêt entre
# la création du dossier et l'affectation que lit le `trap` peut laisser un dossier
# privé **vide**, jamais la valeur : elle n'est écrite qu'une fois le chemin connu
# du `trap` (C7-8).
smoke_write_auth_config() {
  local name="${1:-}" value saved_umask traced=0 rc=0
  if ! [[ "$name" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "smoke_write_auth_config : nom de variable invalide" >&2
    return 2
  fi
  case "$-" in *x*) traced=1 ;; esac
  set +x
  value="${!name:-}"
  if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    echo "$name contient un retour à la ligne : refusé, il ouvrirait une directive dans le fichier de configuration curl" >&2
    rc=2
  else
    saved_umask="$(umask)"
    umask 077
    SMOKE_AUTH_DIR="$(umask 077 && mktemp -d)" || rc=2
    if [ "$rc" -eq 0 ]; then
      SMOKE_AUTH_CONFIG="$SMOKE_AUTH_DIR/curl-auth.conf"
      value="${value//\\/\\\\}"
      value="${value//\"/\\\"}"
      printf 'user = "%s"\n' "$value" > "$SMOKE_AUTH_CONFIG" || { smoke_cleanup; rc=2; }
    fi
    umask "$saved_umask"
  fi
  value=""
  if [ "$traced" -eq 1 ]; then
    set -x
  fi
  return "$rc"
}

# smoke_add_basic_auth <nom d'une variable d'environnement>
#
# Si la variable est vide ou absente, ne fait rien. Sinon écrit le fichier `-K` de
# sa valeur (smoke_write_auth_config, qui valide le nom et nomme la variable dans
# son refus), qui le retient dans SMOKE_AUTH_CONFIG, et ajoute `-K <fichier>` à
# SMOKE_CURL_OPTS. Retour 2 pour un nom invalide ou si le fichier ne s'écrit pas
# (retour à la ligne, disque) : l'appelant décide, `smoke_add_basic_auth
# AUDIT_BASIC_AUTH || exit 2`.
#
# L'appelant pose son `trap` **avant** : `trap 'rm -f "$headers_file";
# smoke_cleanup' EXIT`, qui retire le dossier à la sortie — un arrêt entre la
# création du dossier et le `trap` n'en laisserait un derrière. La valeur n'est lue
# que sous `set +x`, comme dans smoke_write_auth_config.
smoke_add_basic_auth() {
  local name="${1:-}" traced=0 empty=0
  if ! [[ "$name" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "smoke_add_basic_auth : nom de variable invalide" >&2
    return 2
  fi
  case "$-" in *x*) traced=1 ;; esac
  set +x
  if [ -z "${!name:-}" ]; then
    empty=1
  fi
  if [ "$traced" -eq 1 ]; then
    set -x
  fi
  if [ "$empty" -eq 1 ]; then
    return 0
  fi
  smoke_write_auth_config "$name" || return 2
  SMOKE_CURL_OPTS+=(-K "$SMOKE_AUTH_CONFIG")
}

# smoke_header <fichier d'en-têtes> <nom>
#
# La valeur d'un en-tête de la réponse que curl a écrite dans le fichier (`-D`) :
# nom insensible à la casse (HTTP/2 l'écrit en minuscules), CR retiré, dernière
# occurrence (la dernière réponse d'une redirection), vide si absent.
smoke_header() {
  tr -d '\r' < "$1" | awk -v name="$2" '
    tolower(substr($0, 1, length(name) + 1)) == tolower(name) ":" { sub(/^[^:]*:[[:space:]]*/, ""); value = $0 }
    END { print value }'
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
# Lance `"$SMOKE_CURL" "${SMOKE_CURL_OPTS[@]}" "$@" -w '\n%{http_code}' "$url"`
# et pose :
#   SMOKE_STATUS   le code HTTP (la dernière ligne de la sortie) ;
#   SMOKE_BODY     le corps (le reste).
# L'appelant déclare SMOKE_CURL_OPTS (`-sS`, les délais, `-D <fichier>`, `-K
# <fichier>`…) ; SMOKE_CURL vaut `curl` s'il est vide.
#
# Un `curl` qui n'obtient aucune réponse (réseau, DNS, délai) sort avec son code
# (6, 7, 28…), hors du contrat 0/1/2 des scripts, si on le laisse à `set -e` : la
# fonction le dit sur stderr — « <libellé> : curl a échoué (code <n> : réseau, DNS
# ou délai) — rien de vérifié » — et rend 1. L'appelant : `smoke_fetch … || exit 1`.
smoke_fetch() {
  local label="$1" url="$2" response rc
  shift 2
  response="$("${SMOKE_CURL:-curl}" "${SMOKE_CURL_OPTS[@]}" "$@" -w '\n%{http_code}' "$url")" || {
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
