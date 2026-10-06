#!/usr/bin/env bash
#
# check-backend-nginx-rate-limits.sh
# ---------------------------------------------------------------------------
# Garde CI de l'issue #347 : un 429 émis par une zone `limit_req` du nginx
# backend sort en problem+json `/errors/rate-limited`, comme les erreurs que
# Symfony rend sous `/api` (règle « Errors under `/api` come out as JSON »,
# audit A15). Sans `error_page 429`, nginx sert sa page HTML par défaut, sans
# `type` : c'était le cas de toutes les zones sauf `assistant` jusqu'à #347.
#
# Les DEUX confs miroir sont testées, chacune dans un conteneur à part (donc
# avec des compteurs neufs) : docker/nginx/default.conf (dev) et
# k8s/base/backend-nginx.conf (sidecar de préprod/prod). Aucun php-fpm n'est
# lancé : un 429 de zone naît dans la phase preaccess, avant toute
# transmission FastCGI, et les requêtes admises répondent 502 sans que cela
# gêne le test. `--add-host backend:127.0.0.1` ne sert qu'à laisser démarrer
# la conf de dev, dont le `fastcgi_pass backend:9000` est résolu au chargement.
#
# Pour chaque zone, le script vide d'abord la rafale (`burst`) en requêtes
# parallèles, puis envoie quelques requêtes une à une jusqu'au premier 429, et
# exige sur CETTE réponse :
#   - Content-Type application/problem+json ;
#   - `"type":"/errors/rate-limited"` et `"status":429` dans le corps ;
#   - les 7 en-têtes de sécurité (tools/lib/security-headers.sh) : la location
#     nommée qui produit le corps ne doit pas poser d'add_header propre, sinon
#     elle perd tous ceux du bloc server (piège A16).
# Vider la rafale en parallèle, et non requête par requête, rend le test
# insensible à la lenteur du runner : en série, à 100 ms par requête, les
# 10 r/s de `publicapi` remplissaient le seau aussi vite qu'on le vidait.
#
# Ce que ce script ne couvre PAS :
#   - le 429 de `limit_conn` (zone `assistantconn`) : il faudrait un amont qui
#     retienne la connexion, et sans php-fpm elle est refusée aussitôt. Il
#     passe par le même `error_page` hérité du bloc server ;
#   - qu'un 429 rendu par Symfony (quota applicatif, avec Retry-After)
#     traverse nginx sans être intercepté. C'est garanti par l'absence de
#     `fastcgi_intercept_errors` dans les deux confs, et vérifié à la main en
#     dev (cf. #347).
#
# Règle à respecter : ajouter une zone `limit_req` dans les confs, c'est
# ajouter une ligne à ZONES ci-dessous — sinon le script échoue en nommant la
# zone oubliée.
#
# Usage : tools/check-backend-nginx-rate-limits.sh <image nginx:tag>
# ---------------------------------------------------------------------------
set -euo pipefail

IMAGE="${1:-}"
# Un tag vide (`nginx:`) est ce que produit la cible make si l'extraction du
# tag depuis le manifeste ne trouve rien : le refuser ici, avec un message,
# plutôt que de laisser `docker run` échouer sur « invalid reference format ».
if [ -z "$IMAGE" ] || [[ "$IMAGE" == *: ]]; then
  echo "Usage : $0 <image nginx:tag> (reçu : « ${IMAGE} »)" >&2
  exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFS=("docker/nginx/default.conf 80" "k8s/base/backend-nginx.conf 8080")
FAILURES=0

# shellcheck source=tools/lib/security-headers.sh
source "${ROOT}/tools/lib/security-headers.sh"

CURL_OPTS=(--connect-timeout 2 --max-time 5)

# <chemin> <méthode> <burst de la zone> <zone>
ZONES=(
  "/api/contact POST 5 contact"
  "/api/account/password-setup POST 10 pwsetup"
  "/api/account/base-access POST 10 baseaccess"
  "/api/login_check POST 10 login"
  "/api/assistant/answers POST 5 assistant"
  "/api/watch GET 200 publicapi"
)
# Requêtes une à une après la rafale : une ou deux ont pu être rechargées par
# le taux de la zone pendant qu'on la vidait.
MAX_PROBES=10

# Couverture : chaque zone `limit_req` déclarée dans une des confs doit avoir
# sa ligne dans ZONES. Sans ce contrôle, une zone oubliée resterait verte sans
# avoir jamais été testée.
declare -A LISTED=()
for zone in "${ZONES[@]}"; do
  read -r _ _ _ name <<< "$zone"
  LISTED[$name]=1
done
for target in "${CONFS[@]}"; do
  read -r conf _ <<< "$target"
  while read -r name; do
    if [ -z "${LISTED[$name]:-}" ]; then
      printf '  \033[31mNON TESTÉE\033[0m  zone limit_req « %s » de %s absente de ZONES   <-- ajouter sa ligne\n' "$name" "$conf"
      FAILURES=$((FAILURES + 1))
    fi
  done < <(grep -oE '^[[:space:]]*limit_req[[:space:]]+zone=[A-Za-z0-9_]+' "${ROOT}/${conf}" | sed 's/.*zone=//' | sort -u)
done

# `root` (/var/www/backend/public) n'existe pas dans le conteneur, et c'est
# sans importance : le 429 naît avant tout accès au système de fichiers. Pas
# de dossier temporaire monté à la place : Docker Desktop ne partage pas /tmp.
# Pas de `--rm` non plus : un nginx qui meurt au démarrage serait supprimé
# avec ses logs, ceux-là mêmes que le message d'erreur doit afficher.
CIDS=()
cleanup() {
  local cid
  for cid in "${CIDS[@]}"; do docker rm -f "$cid" > /dev/null 2>&1 || true; done
}
trap cleanup EXIT

# start_nginx <fichier de conf> <port écouté dans le conteneur> : lance le
# conteneur, attend qu'il réponde, et écrit son URL de base dans BASE.
start_nginx() {
  local conf="$1" port="$2" cid host_port code=""
  cid="$(docker run -d --add-host backend:127.0.0.1 \
    -v "${conf}:/etc/nginx/conf.d/default.conf:ro" \
    -p "127.0.0.1::${port}" "$IMAGE")"
  CIDS+=("$cid")

  # `|| true` : sous pipefail, un `docker port` qui échoue (conteneur mort au
  # démarrage) couperait le script avant le `docker logs` qui l'explique.
  host_port="$(docker port "$cid" "${port}/tcp" 2>/dev/null | head -1 || true)"
  if [ -z "$host_port" ]; then
    echo "Impossible de lire le port publié par le conteneur ($conf)." >&2
    docker logs "$cid" >&2 || true
    exit 1
  fi
  BASE="http://${host_port}"

  # Sonde : un `.php` hors contrôleur frontal tombe sur `location ~ \.php$`
  # (404), qu'aucune zone ne plafonne — attendre ne consomme aucun compteur.
  for _ in $(seq 1 30); do
    code="$(curl -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' "${BASE}/probe.php" 2>/dev/null || true)"
    [ "$code" = "404" ] && return 0
    sleep 1
  done
  printf 'nginx ne répond pas 404 sur /probe.php après 30 s (%s, dernier code : %s).\n' "$conf" "${code:-aucun}" >&2
  docker logs "$cid" >&2 || true
  exit 1
}

# check_zone <conf> <chemin> <méthode> <burst> <zone> : vide la rafale, puis
# juge le premier 429 obtenu.
check_zone() {
  local conf="$1" path="$2" method="$3" burst="$4" zone="$5" i raw code="" body_file
  body_file="$(mktemp)"

  # Rafale vidée d'un coup : burst + 1 requêtes, 50 à la fois. Le `?n=[…]` ne
  # sert qu'à faire générer les URL par curl ; la zone compte par IP, et la
  # location se choisit sur le chemin seul. Les codes sont ignorés : seule
  # compte la réponse jugée ensuite. `|| true` : un échec ici se reverra plus bas.
  curl -s "${CURL_OPTS[@]}" --parallel --parallel-max 50 -X "$method" \
    -o /dev/null "${BASE}${path}?n=[0-${burst}]" > /dev/null 2>&1 || true

  for i in $(seq 1 "$MAX_PROBES"); do
    # `|| true` : sous `set -e`, un seul curl en échec (délai dépassé)
    # arrêterait tout le script sans dire quelle zone était en cours.
    raw="$(curl -sS "${CURL_OPTS[@]}" -X "$method" -D - -o "$body_file" \
      -w 'x-audit-http-code: %{http_code}\n' "${BASE}${path}" 2>&1 || true)"
    code="$(sed -n 's/^x-audit-http-code: //p' <<< "$raw" | tail -1)"
    [ "$code" = "429" ] && break
  done

  if [ "$code" != "429" ]; then
    printf '  \033[31mPAS DE 429\033[0m  %s (zone %s, %s) : dernier code « %s » après la rafale et %s requêtes, zone absente ?\n' \
      "$path" "$zone" "$conf" "${code:-aucun}" "$MAX_PROBES"
    [ -n "$code" ] && [ "$code" != "000" ] || printf '    curl : %s\n' "$(head -1 <<< "$raw")"
    FAILURES=$((FAILURES + 1))
    rm -f "$body_file"
    return
  fi

  printf '  %s (zone %s) : 429 après la rafale de %s, à la requête %s\n' "$path" "$zone" "$burst" "$i"
  if grep -qi '^content-type: application/problem+json' <<< "$raw"; then
    printf '    \033[32mOK\033[0m      content-type problem+json\n'
  else
    printf '    \033[31mKO\033[0m      content-type : %s   <-- problem+json attendu\n' \
      "$(grep -i '^content-type:' <<< "$raw" | tr -d '\r' || echo absent)"
    FAILURES=$((FAILURES + 1))
  fi
  if grep -q '"type":"/errors/rate-limited"' "$body_file" && grep -q '"status":429' "$body_file"; then
    printf '    \033[32mOK\033[0m      corps /errors/rate-limited\n'
  else
    printf '    \033[31mKO\033[0m      corps : %s\n' "$(head -c 120 "$body_file" | tr '\n' ' ')"
    FAILURES=$((FAILURES + 1))
  fi
  check_security_headers "$path (429)" "$raw"
  rm -f "$body_file"
}

# <conf relative au dépôt> <port écouté>
for target in "${CONFS[@]}"; do
  read -r conf port <<< "$target"
  echo "== ${conf} (image ${IMAGE})"
  start_nginx "${ROOT}/${conf}" "$port"
  for zone in "${ZONES[@]}"; do
    # shellcheck disable=SC2086 # découpage voulu : quatre champs par ligne
    check_zone "$conf" $zone
  done
  echo
done

if [ "$FAILURES" -gt 0 ]; then
  echo "Échecs bloquants : $FAILURES"
  exit 1
fi
echo "Toutes les zones limit_req des deux confs répondent 429 en problem+json, avec les 7 en-têtes de sécurité."
