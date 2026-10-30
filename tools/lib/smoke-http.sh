#!/usr/bin/env bash
#
# smoke-http.sh
# ---------------------------------------------------------------------------
# Bibliothèque sourçable : ce que les scripts de fumée et d'audit qui appellent
# `curl` contre un déploiement réel écrivaient chacun de leur côté (#748, R57).
# Sourcée par :
#   - tools/smoke-dead-session-cookie.sh, tools/smoke-login-throttling.sh,
#     tools/smoke-mcp.sh (les cinq fonctions) ;
#   - tools/audit-prod.sh (smoke_auth_config_file seule, et seulement avec
#     AUDIT_BASIC_AUTH) ;
#   - tools/tests/smoke-http.test.sh (hors ligne, faux curl).
#
# Cinq fonctions :
#   smoke_auth_config_file <nom> <valeur>  le fichier de configuration `-K` de la
#                                          Basic Auth ;
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

# smoke_auth_config_file <nom de variable> <valeur "utilisateur:mot_de_passe">
#
# Écrit le fichier de configuration curl (`-K`) qui porte la Basic Auth, en 600
# dès l'origine (`umask 077` + `mktemp`, jamais lisible d'un tiers), et imprime
# son chemin. Jamais `-u` : les arguments d'un processus se lisent dans `ps`,
# seul le chemin du fichier apparaît dans ceux de curl. Les guillemets et
# antislashs sont échappés au format attendu par curl, donc n'importe quel mot
# de passe convient.
#
# Un retour à la ligne (ou un CR) ouvrirait une seconde directive dans ce
# fichier (`url = …`, `output = …`) : `printf 'user = "%s"'` n'échappe que `"` et
# `\`. Refusé avant tout fichier, sans citer la valeur (c'est un secret), en
# nommant la variable reçue (`<nom>`, #748 C6-9), retour 2 — celui d'un défaut
# d'environnement.
#
# Les scripts de fumée passent par smoke_add_basic_auth ; audit-prod.sh, qui garde
# son propre `CURL_OPTS`, l'appelle directement :
# `file="$(smoke_auth_config_file AUDIT_BASIC_AUTH "$AUDIT_BASIC_AUTH")" || exit 2`,
# après avoir posé son `trap 'rm -f …' EXIT`, et pour une valeur non vide seulement.
smoke_auth_config_file() {
  local name="${1:?nom de la variable}" value="${2:-}" file
  if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    echo "$name contient un retour à la ligne : refusé, il ouvrirait une directive dans le fichier de configuration curl" >&2
    return 2
  fi
  file="$(umask 077 && mktemp)" || return 2
  if ! printf 'user = "%s"\n' "$(printf '%s' "$value" | sed 's/\\/\\\\/g; s/"/\\"/g')" > "$file"; then
    rm -f "$file"
    return 2
  fi
  printf '%s\n' "$file"
}

# smoke_add_basic_auth <nom d'une variable d'environnement>
#
# Si la variable est vide ou absente, ne fait rien. Sinon écrit le fichier `-K` de
# sa valeur (smoke_auth_config_file, qui nomme la variable dans son refus), le
# retient dans SMOKE_AUTH_CONFIG et ajoute `-K <fichier>` à SMOKE_CURL_OPTS.
# Retour 2 si le fichier ne s'écrit pas (retour à la ligne, disque) : l'appelant
# décide, `smoke_add_basic_auth AUDIT_BASIC_AUTH || exit 2`.
#
# L'appelant pose son `trap` **avant** : `trap 'rm -f "$headers_file"
# ${SMOKE_AUTH_CONFIG:+"$SMOKE_AUTH_CONFIG"}' EXIT`, qui lit SMOKE_AUTH_CONFIG à la
# sortie — un arrêt entre la création du fichier et le `trap` n'en laisserait un
# derrière.
smoke_add_basic_auth() {
  local name="${1:?nom de la variable}"
  [ -n "${!name:-}" ] || return 0
  SMOKE_AUTH_CONFIG="$(smoke_auth_config_file "$name" "${!name}")" || return 2
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
