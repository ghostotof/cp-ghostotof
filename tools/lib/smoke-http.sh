#!/usr/bin/env bash
#
# smoke-http.sh
# ---------------------------------------------------------------------------
# Bibliothèque sourçable : ce que les scripts de fumée et d'audit qui appellent
# `curl` contre un déploiement réel écrivaient chacun de leur côté (#748, R57).
# Sourcée par :
#   - tools/smoke-dead-session-cookie.sh, tools/smoke-login-throttling.sh,
#     tools/smoke-mcp.sh (les trois fonctions) ;
#   - tools/audit-prod.sh (smoke_auth_config_file seule) ;
#   - tools/tests/smoke-http.test.sh (hors ligne, faux curl).
#
# Trois fonctions :
#   smoke_auth_config_file <valeur>      le fichier de configuration `-K` de la
#                                        Basic Auth ;
#   smoke_excerpt <texte>                un extrait sans caractère de contrôle ;
#   smoke_fetch <libellé> <url> [args…]  un appel curl qui rend statut et corps
#                                        et dit un échec réseau.
#
# Pas de `set -e` ici, ni d'appel réseau à la source : une bibliothèque sourcée
# ne change pas les options du shell qui la source. Aucune fonction ne fait
# sortir l'appelant ; c'est lui qui décide (`|| exit 2`, `|| exit 1`).
# ---------------------------------------------------------------------------

# smoke_auth_config_file <valeur "utilisateur:mot_de_passe">
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
# `\`. Refusé avant tout fichier, sans citer la valeur (c'est un secret), retour 2
# — celui d'un défaut d'environnement.
#
# L'appelant : `auth_config="$(smoke_auth_config_file "$AUDIT_BASIC_AUTH")" ||
# exit 2`, garde son `trap 'rm -f …' EXIT`, et n'appelle la fonction que pour
# une valeur non vide.
smoke_auth_config_file() {
  local value="${1:-}" file
  if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    echo "AUDIT_BASIC_AUTH contient un retour à la ligne : refusé, il ouvrirait une directive dans le fichier de configuration curl" >&2
    return 2
  fi
  file="$(umask 077 && mktemp)" || return 2
  if ! printf 'user = "%s"\n' "$(printf '%s' "$value" | sed 's/\\/\\\\/g; s/"/\\"/g')" > "$file"; then
    rm -f "$file"
    return 2
  fi
  printf '%s\n' "$file"
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
# Lance `"$SMOKE_CURL_BIN" "${SMOKE_CURL_OPTS[@]}" "$@" -w '\n%{http_code}' "$url"`
# et pose :
#   SMOKE_STATUS   le code HTTP (la dernière ligne de la sortie) ;
#   SMOKE_BODY     le corps (le reste).
# L'appelant déclare SMOKE_CURL_OPTS (`-sS`, les délais, `-D <fichier>`, `-K
# <fichier>`…) ; SMOKE_CURL_BIN vaut `curl` s'il est vide (les tests hors ligne y
# mettent un faux).
#
# Un `curl` qui n'obtient aucune réponse (réseau, DNS, délai) sort avec son code
# (6, 7, 28…), hors du contrat 0/1/2 des scripts, si on le laisse à `set -e` : la
# fonction le dit sur stderr — « <libellé> : curl a échoué (code <n> : réseau, DNS
# ou délai) — rien de vérifié » — et rend 1. L'appelant : `smoke_fetch … || exit 1`.
smoke_fetch() {
  local label="$1" url="$2" response rc
  shift 2
  response="$("${SMOKE_CURL_BIN:-curl}" "${SMOKE_CURL_OPTS[@]}" "$@" -w '\n%{http_code}' "$url")" || {
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
