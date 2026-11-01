#!/usr/bin/env bash
#
# smoke-dead-session-cookie.sh
# ---------------------------------------------------------------------------
# Vérifie contre un déploiement RÉEL qu'un cookie de session mort ne ferme pas
# les lectures publiques (#748, R35 ; constat C1 de la quatrième relecture).
#
# Le défaut : le pare-feu `api` authentifie dès qu'un cookie `BEARER` est
# présent, quelle que soit la route ; un cookie refusé (expiré, invalide, ouvert
# avant un changement de mot de passe) rendait un 401 à toute lecture publique.
# Corrigé côté serveur par PublicRouteIgnoresRefusedSessionListener : une route
# que `access_control` laisse à l'anonyme est servie comme à l'anonyme, sans
# rien effacer ; la première route qui exige un rôle refuse et efface. Les
# tests PHPUnit voient le conteneur de test ; ce script voit la vraie pile
# (ingress, nginx, image de production) — le seul endroit où la préprod
# diffère : son Ingress porte la Basic Auth.
#
# Trois requêtes (un `curl` qui n'obtient aucune réponse — réseau, DNS, délai —
# sort 1 en le disant : rien n'a été vérifié) :
#   1. GET /api/contributions/fr SANS cookie → 200 (témoin : la route répond,
#      la Basic Auth passe). Un 401 ici est celui de l'ingress, pas de
#      l'application : le dire, sans aller plus loin.
#   2. le même GET avec `BEARER=<valeur aléatoire>` → 200 et AUCUN
#      `Set-Cookie` de `BEARER` ni de `XSRF-TOKEN` (une route publique
#      n'efface pas : la course d'un 401 tardif qui efface des cookies neufs
#      reste aux requêtes authentifiées).
#   3. GET /api/me avec le même cookie → 401 et un `Set-Cookie` expiré
#      (`Max-Age=0` ou `expires` passé) pour CHACUN de `BEARER` et
#      `XSRF-TOKEN` : la route protégée refuse et efface les deux cookies de
#      session (StaleSessionCookiesClearingListener), comme le SPA le provoque
#      à chaque chargement.
#
# Le cookie est une valeur aléatoire (`openssl rand -hex 16`) : ce n'est PAS un
# secret — il n'ouvre aucune session, le serveur le refuse —, il passe donc en
# argument `-b`. La Basic Auth, elle, en est un (voir plus bas).
#
# Usage :  tools/smoke-dead-session-cookie.sh <url-de-base>
#          ex. tools/smoke-dead-session-cookie.sh https://preprod.cp-ghostotof.com
# Env :    la variable de la Basic Auth de préprod, optionnelle : voir tools/lib/smoke-http.sh (son nom, les
#          valeurs refusées, la sortie 2). Jamais via `-u` : les arguments d'un processus se lisent dans `ps` ;
#          le couple passe par un fichier de configuration curl en 600.
#          SMOKE_CURL        binaire curl de substitution (tests hors ligne).
# Sortie : 0 conforme, 1 écart (ou curl sans réponse), 2 usage ou environnement
#          faux (dont le fichier de la Basic Auth disparu).
# ---------------------------------------------------------------------------
set -euo pipefail

base="${1:-}"
if [ -z "$base" ]; then
  echo "usage : $0 <url-de-base>" >&2
  exit 2
fi
base="${base%/}"

# Sortie 2 si la bibliothèque manque ou ne se lit pas (sans -e, un source en échec continuerait) ; stderr de bash visible.
# Le dossier se calcule sans `dirname` (la variable de la Basic Auth est encore exportée ici, un fils la recevrait) : `cd` et
# `pwd` sont des commandes internes de bash. `CDPATH=''` et `--` : ni un CDPATH hérité ni un dossier qui commence par `-` ne
# changent le dossier trouvé ; un script à la racine (`script_dir` vide) vaut `/` (C14-11).
script_dir="${BASH_SOURCE[0]}"
case "$script_dir" in */*) script_dir="${script_dir%/*}" ;; *) script_dir=. ;; esac
[ -n "$script_dir" ] || script_dir=/
# shellcheck source=tools/lib/smoke-http.sh
source "$(CDPATH='' cd -- "$script_dir" && pwd)/lib/smoke-http.sh" || {
  echo "bibliothèque introuvable ou invalide : tools/lib/smoke-http.sh" >&2
  exit 2
}

public_path="/api/contributions/fr"
protected_path="/api/me"

# Ordre (D2 de la quatorzième relecture) : le `trap` (qui ne retire que le fichier déjà créé), puis la Basic Auth, puis
# seulement `openssl` et `mktemp` : la bibliothèque retire la variable du shell en la lisant, donc aucun fils ne la reçoit.
headers_file=""
trap '[ -z "$headers_file" ] || rm -f -- "$headers_file"; smoke_cleanup' EXIT

# Basic Auth : `smoke_use_auth_config` écrit le fichier `-K` et refuse (sortie 2) un secret vide ou creux ; `smoke_fetch` le lit
# et refuse, avec lui, une URL non https:// ou un argument hors de la liste d'options admises (tools/lib/smoke-http.sh).
smoke_use_auth_config AUDIT_BASIC_AUTH || exit 2

# Une valeur différente à chaque exécution : jamais un motif connu, jamais la
# session d'un compte (32 hexadécimaux ne se signent pas).
dead_cookie="BEARER=$(openssl rand -hex 16)"

# Les en-têtes de chaque réponse, pour y lire les Set-Cookie.
headers_file="$(mktemp)"
SMOKE_CURL_OPTS=(-sS --connect-timeout 5 --max-time 15 -D "$headers_file")

# Les valeurs de tous les en-têtes Set-Cookie de la dernière réponse (la lecture
# des en-têtes multiples est celle de la bibliothèque).
set_cookies() {
  smoke_headers "$headers_file" Set-Cookie
}

# Les Set-Cookie du cookie nommé $1 (nom insensible à la casse).
set_cookies_named() {
  set_cookies | awk -v name="$1" 'tolower(substr($0, 1, length(name) + 1)) == tolower(name) "=" { print }'
}

# Les deux cookies de session que le serveur peut poser ou effacer.
session_cookies=(BEARER XSRF-TOKEN)

# Une requête : $status et $body en sortie. Les arguments en plus (le cookie)
# viennent avant l'URL.
status=""
body=""
fetch() {
  local path="$1"; shift
  # Sortie 1 (curl sans réponse) ou 2 (fichier de la Basic Auth absent), dite par la bibliothèque.
  smoke_fetch "GET $path" "$base$path" "$@" || exit "$?"
  status="$SMOKE_STATUS"
  body="$SMOKE_BODY"
}

# Un Set-Cookie efface-t-il le cookie ? `Max-Age=0` (ou négatif), ou un
# `expires` antérieur à maintenant.
expires_it() {
  local cookie="${1,,}" expires
  if [[ "$cookie" =~ max-age=(0|-[0-9]+)([[:space:]]*\;|[[:space:]]*$) ]]; then
    return 0
  fi
  if [[ "$cookie" =~ expires=([^\;]+) ]]; then
    expires="$(date -d "${BASH_REMATCH[1]}" +%s 2>/dev/null || true)"
    if [ -n "$expires" ] && [ "$expires" -lt "$(date +%s)" ]; then
      return 0
    fi
  fi
  return 1
}

# --- 1. Le témoin : la route publique répond sans cookie.
fetch "$public_path"
if [ "$status" = "401" ]; then
  echo "témoin : GET $public_path sans cookie a répondu 401 — c'est la Basic Auth de l'ingress" \
    "(secret de préprod absent ou faux ?) ou une route devenue protégée, pas le cookie mort" \
    "(corps : $(smoke_excerpt "$body"))" >&2
  exit 1
fi
if [ "$status" != "200" ]; then
  echo "témoin : GET $public_path sans cookie : $status reçu, 200 attendu (corps : $(smoke_excerpt "$body"))" >&2
  exit 1
fi
echo "témoin : GET $public_path sans cookie → 200"

# --- 2. La lecture publique avec un cookie mort : servie, rien posé.
fetch "$public_path" -b "$dead_cookie"
if [ "$status" != "200" ]; then
  echo "lecture publique refusée pour un cookie mort : GET $public_path avec un BEARER aléatoire a répondu $status, 200 attendu — le pare-feu refuse encore une session morte sur une route publique, #748 C1 (corps : $(smoke_excerpt "$body"))" >&2
  exit 1
fi
for name in "${session_cookies[@]}"; do
  leftover="$(set_cookies_named "$name")"
  if [ -n "$leftover" ]; then
    echo "lecture publique servie mais elle pose un Set-Cookie $name (« $(smoke_excerpt "$leftover") ») : une route publique ne doit rien effacer, la course d'un 401 tardif s'étendrait à chaque lecture" >&2
    exit 1
  fi
done
echo "lecture publique : GET $public_path avec un BEARER mort → 200, aucun Set-Cookie BEARER ni XSRF-TOKEN"

# --- 3. La route protégée avec le même cookie : refusée, cookie effacé.
fetch "$protected_path" -b "$dead_cookie"
if [ "$status" != "401" ]; then
  echo "GET $protected_path avec un BEARER mort : $status reçu, 401 attendu (corps : $(smoke_excerpt "$body"))" >&2
  exit 1
fi
# Les DEUX cookies de session doivent être expirés : un seul laisserait l'autre
# jusqu'à la fermeture du navigateur (un XSRF-TOKEN de session sans BEARER, ou
# l'inverse, est la boucle « session finie » de #748 CR3).
for name in "${session_cookies[@]}"; do
  cleared=0
  while IFS= read -r cookie; do
    if expires_it "$cookie"; then cleared=1; fi
  done < <(set_cookies_named "$name")
  if [ "$cleared" -ne 1 ]; then
    echo "GET $protected_path en 401 mais n'efface pas le cookie mort : aucun Set-Cookie $name expiré (Max-Age=0 ou expires passé), il resterait jusqu'à la fermeture du navigateur (Set-Cookie vus : « $(smoke_excerpt "$(set_cookies)") »)" >&2
    exit 1
  fi
done
echo "route protégée : GET $protected_path avec un BEARER mort → 401 et Set-Cookie BEARER et XSRF-TOKEN expirés"

echo "OK : un cookie de session mort est ignoré par les lectures publiques et effacé par la première route protégée."
