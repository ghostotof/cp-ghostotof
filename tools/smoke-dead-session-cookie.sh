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
# Env :    AUDIT_BASIC_AUTH  "utilisateur:mot_de_passe" (préprod), optionnel.
#                            Jamais via `-u` : les arguments d'un processus se
#                            lisent dans `ps` ; le couple passe par un fichier
#                            de configuration curl en 600 (tools/lib/
#                            smoke-http.sh). Un retour à la ligne y est refusé
#                            (sortie 2).
#          SMOKE_CURL        binaire curl de substitution (tests hors ligne).
# Sortie : 0 conforme, 1 écart (ou curl sans réponse), 2 usage ou environnement
#          faux.
# ---------------------------------------------------------------------------
set -euo pipefail

base="${1:-}"
if [ -z "$base" ]; then
  echo "usage : $0 <url-de-base>" >&2
  exit 2
fi
base="${base%/}"

# Le stderr du `source` reste visible : une erreur de syntaxe de la bibliothèque
# s'y lit, au lieu de se cacher sous « introuvable » (#748, C6-7).
# shellcheck source=tools/lib/smoke-http.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/smoke-http.sh" || {
  echo "bibliothèque introuvable ou invalide : tools/lib/smoke-http.sh" >&2
  exit 2
}

SMOKE_CURL_BIN="${SMOKE_CURL:-curl}"
public_path="/api/contributions/fr"
protected_path="/api/me"

# Une valeur différente à chaque exécution : jamais un motif connu, jamais la
# session d'un compte (32 hexadécimaux ne se signent pas).
dead_cookie="BEARER=$(openssl rand -hex 16)"

# Les en-têtes de chaque réponse, pour y lire les Set-Cookie.
headers_file="$(mktemp)"
auth_config=""
trap 'rm -f "$headers_file" ${auth_config:+"$auth_config"}' EXIT

# La Basic Auth passe par un fichier de configuration curl en 600, jamais par un
# argument ; un retour à la ligne y est refusé (sortie 2). Voir la bibliothèque.
SMOKE_CURL_OPTS=(-sS --connect-timeout 5 --max-time 15 -D "$headers_file")
if [ -n "${AUDIT_BASIC_AUTH:-}" ]; then
  auth_config="$(smoke_auth_config_file "$AUDIT_BASIC_AUTH")" || exit 2
  SMOKE_CURL_OPTS+=(-K "$auth_config")
fi

# Les valeurs de tous les en-têtes Set-Cookie de la dernière réponse : nom
# insensible à la casse (HTTP/2 l'écrit en minuscules), CR retiré.
set_cookies() {
  tr -d '\r' < "$headers_file" | awk '
    tolower(substr($0, 1, 11)) == "set-cookie:" { sub(/^[^:]*:[[:space:]]*/, ""); print }'
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
  # Un curl sans réponse sort 1 en le disant (contrat 0/1/2, voir la bibliothèque).
  smoke_fetch "GET $path" "$base$path" "$@" || exit 1
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
  echo "témoin : GET $public_path sans cookie a répondu 401 — c'est la Basic Auth de l'ingress (AUDIT_BASIC_AUTH absent ou faux ?) ou une route devenue protégée, pas le cookie mort (corps : $(smoke_excerpt "$body"))" >&2
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
