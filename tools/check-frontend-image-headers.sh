#!/usr/bin/env bash
#
# check-frontend-image-headers.sh
# ---------------------------------------------------------------------------
# Garde CI contre la régression A16 (audit du 2026-09-16) : en nginx, un bloc
# `location` qui déclare son PROPRE add_header n'hérite plus d'AUCUN add_header
# du bloc `server` parent. C'est ainsi que /assets/, /config.js et /healthz du
# frontend ont été servis sans CSP ni HSTS pendant des mois, sans qu'aucun
# test rougisse — `tools/audit-prod.sh` ne l'attrapait qu'après déploiement.
#
# Ce script lance localement l'image nginx du frontend (aucun réseau externe,
# tout parle à 127.0.0.1) et vérifie les 7 en-têtes de sécurité (cf.
# tools/lib/security-headers.sh) SUR CHAQUE `location` de docker/node/nginx.conf,
# pas seulement sur `/`. Les 8 locations actuelles et leur chemin de test :
#   `= /healthz`                          -> /healthz
#   `/assets/`                            -> l'asset réel découvert + /assets/missing-file.js (404)
#   `= /index.html`                       -> /index.html, / et /route/inconnue (voir note)
#   `= /config.js`                        -> /config.js
#   `^~ /.well-known/`                    -> /.well-known/security.txt
#   `~ /\.`                               -> /.hidden (404)
#   `~* \.(env|ya?ml|...)$`               -> /anything.env (404)
#   `/`                                   -> /favicon.svg (fichier statique racine)
#
# Note sur `/` et `location /` : `/` et `/route/inconnue` ne sont JAMAIS servis
# par `location /` elle-même. Son `try_files $uri $uri/ /index.html;` fait un
# rewrite interne vers /index.html dès que rien ne correspond, et ce rewrite
# refait tout le location matching depuis le début — la réponse part donc
# réellement de `location = /index.html`. C'est le comportement voulu (ces deux
# chemins prouvent que le repli SPA atterrit bien sur le bon document), mais ça
# veut dire qu'aucun des deux n'exerce vraiment `location /` : un `add_header`
# ajouté SEULEMENT dans ce bloc (la régression A16 sous sa forme exacte) ne
# casserait ni `/` ni `/route/inconnue`. La seule réponse réellement servie par
# `location /` est un fichier statique présent à la racine du build et non
# repris par une autre location — `/favicon.svg` (frontend/public/,
# référencé par frontend/index.html) — c'est ce chemin qui couvre cette
# location.
#
# Règle à respecter : ajouter une `location` dans docker/node/nginx.conf, c'est
# ajouter son chemin dans la liste CHECKS ci-dessous — sinon elle n'est pas
# couverte par ce garde et pourrait régresser en silence comme en A16.
#
# Usage : tools/check-frontend-image-headers.sh <image[:tag]>
# ---------------------------------------------------------------------------
set -euo pipefail

IMAGE="${1:-}"
if [ -z "$IMAGE" ]; then
  echo "Usage : $0 <image[:tag]>" >&2
  exit 2
fi

FAILURES=0

# shellcheck source=tools/lib/security-headers.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/security-headers.sh"

CURL_OPTS=(--connect-timeout 2 --max-time 5)

# Port hôte alloué dynamiquement par Docker (jamais un port fixe, pour rester
# sans risque de collision en CI) ; API_URL est une valeur bidon, ce script ne
# teste que le nginx statique, jamais le backend.
CID="$(docker run -d --rm -e API_URL=http://api.invalid -p 127.0.0.1::8080 "$IMAGE")"
trap 'docker stop "$CID" > /dev/null 2>&1 || true' EXIT

# `|| true` : sous `set -o pipefail`, un `docker port` qui échoue (conteneur
# mort au démarrage) ferait échouer tout le pipe, et `set -e` couperait le
# script avant même le message d'erreur et le `docker logs` de secours
# juste en dessous — qui sont précisément ce qui doit s'afficher dans ce cas.
HOST_PORT="$(docker port "$CID" 8080/tcp | head -1 || true)"
if [ -z "$HOST_PORT" ]; then
  echo "Impossible de lire le port publié par le conteneur $CID." >&2
  docker logs "$CID" >&2 || true
  exit 1
fi
BASE="http://${HOST_PORT}"

# En-têtes d'une réponse, suivis d'une pseudo-ligne portant le code HTTP.
# Jamais -L : chaque réponse est jugée pour elle-même (une location qui
# répondrait par une redirection ne serait alors plus celle testée).
fetch_headers() {
  curl -sS "${CURL_OPTS[@]}" -D - -o /dev/null -w 'x-audit-http-code: %{http_code}\n' "$1"
}

# Attente bornée de la sonde de vivacité (~30 s) : un conteneur qui ne
# démarre pas doit échouer franchement, jamais faire tourner le job en boucle.
READY=0
LAST_CODE=""
for _ in $(seq 1 30); do
  if LAST_CODE="$(curl -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' "${BASE}/healthz" 2>/dev/null)" \
     && [ "$LAST_CODE" = "200" ]; then
    READY=1
    break
  fi
  sleep 1
done
if [ "$READY" -ne 1 ]; then
  printf 'Le conteneur ne répond pas 200 sur /healthz après 30s (dernier code : %s).\n' "${LAST_CODE:-aucun}" >&2
  echo '--- docker logs ---' >&2
  docker logs "$CID" >&2 || true
  exit 1
fi

# check_path <chemin> <code attendu> <libellé> : vérifie le code HTTP (preuve
# que c'est bien la location visée qui a répondu, pas une autre) PUIS les 7
# en-têtes de sécurité sur cette même réponse.
check_path() {
  local path="$1" expected="$2" label="$3" raw code
  raw="$(fetch_headers "${BASE}${path}")"
  code="$(sed -n 's/^x-audit-http-code: //p' <<< "$raw" | tail -1)"
  if [ "$code" != "$expected" ]; then
    printf '  \033[31mCODE\033[0m    %s : attendu %s, obtenu %s   <-- code inattendu, mauvaise location ?\n' \
      "$label" "$expected" "${code:-?}"
    FAILURES=$((FAILURES + 1))
  fi
  check_security_headers "$label" "$raw"
}

# Un asset réel, jamais un nom en dur (Vite hache le nom de chaque fichier) :
# découvert dans le HTML de `/`, comme dans tools/audit-prod.sh.
HOME_HTML="$(curl -sS "${CURL_OPTS[@]}" "${BASE}/")"
# `|| true` : même piège que HOST_PORT ci-dessus — un `grep` sans
# correspondance renvoie 1, ce qui sous `pipefail`/`set -e` tuerait le script
# avant même d'atteindre le `if [ -n "$ASSET_PATH" ]` et son message
# INTROUVABLE juste plus bas (le cas que ce garde a précisément pour rôle de
# signaler, pas de faire disparaître silencieusement).
ASSET_PATH="$(grep -oE '/assets/[A-Za-z0-9._/-]+\.(js|css)' <<< "$HOME_HTML" | head -1 || true)"

echo "Image testée : $IMAGE (conteneur $CID, ${BASE})"
echo

check_path "/" "200" "/  (SPA via index.html)"
check_path "/index.html" "200" "/index.html"
check_path "/config.js" "200" "/config.js"
check_path "/healthz" "200" "/healthz"
check_path "/.well-known/security.txt" "200" "/.well-known/security.txt"
# La seule réponse réellement servie par `location /` (voir la note en-tête) :
# un fichier statique de la racine du build, jamais rewrité vers index.html.
check_path "/favicon.svg" "200" "/favicon.svg (fichier statique racine — la vraie location /)"

if [ -n "$ASSET_PATH" ]; then
  check_path "$ASSET_PATH" "200" "$ASSET_PATH (asset réel)"
else
  # Échec bloquant, et non un saut silencieux : ne pas avoir trouvé d'asset
  # n'est pas la même chose que ne pas en avoir cherché (cf. audit-prod.sh).
  printf '  \033[31mINTROUVABLE\033[0m  aucun /assets/…  référencé par la page d'\''accueil   <-- à vérifier !\n'
  FAILURES=$((FAILURES + 1))
fi

check_path "/assets/missing-file.js" "404" "/assets/missing-file.js (404 try_files)"
check_path "/.hidden" "404" "/.hidden (fichier caché)"
check_path "/anything.env" "404" "/anything.env (extension interdite)"
check_path "/route/inconnue" "200" "/route/inconnue (repli SPA)"

echo
if [ "$FAILURES" -gt 0 ]; then
  echo "Échecs bloquants : $FAILURES"
  exit 1
fi
echo "Toutes les locations servent les 7 en-têtes de sécurité, avec le code attendu."
exit 0
