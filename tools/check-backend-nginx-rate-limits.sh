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
# Pour chaque zone, le script envoie des requêtes jusqu'au premier 429 (borné
# au-delà de la rafale), puis exige sur CETTE réponse :
#   - Content-Type application/problem+json ;
#   - `"type":"/errors/rate-limited"` et `"status":429` dans le corps ;
#   - les 7 en-têtes de sécurité (tools/lib/security-headers.sh) : la location
#     nommée qui produit le corps ne doit pas poser d'add_header propre, sinon
#     elle perd tous ceux du bloc server (piège A16).
#
# Ce que ce script ne peut PAS voir, faute de PHP : qu'un 429 rendu par
# Symfony (quota applicatif, avec Retry-After) traverse nginx sans être
# intercepté. C'est garanti par l'absence de `fastcgi_intercept_errors` dans
# les deux confs, et vérifié à la main en dev (cf. #347).
#
# Règle à respecter : ajouter une zone `limit_req` dans les confs, c'est
# ajouter une ligne à ZONES ci-dessous.
#
# Usage : tools/check-backend-nginx-rate-limits.sh <image nginx[:tag]>
# ---------------------------------------------------------------------------
set -euo pipefail

IMAGE="${1:-}"
if [ -z "$IMAGE" ]; then
  echo "Usage : $0 <image nginx[:tag]>" >&2
  exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAILURES=0

# shellcheck source=tools/lib/security-headers.sh
source "${ROOT}/tools/lib/security-headers.sh"

CURL_OPTS=(--connect-timeout 2 --max-time 5)

# <chemin> <méthode> <nombre max de requêtes avant d'exiger un 429> <zone>
# Le maximum laisse une marge au-dessus de `burst` + 1 : le taux remplit le
# seau pendant que les requêtes partent, quelques-unes de plus passent.
ZONES=(
  "/api/contact POST 20 contact"
  "/api/account/password-setup POST 30 pwsetup"
  "/api/account/base-access POST 30 baseaccess"
  "/api/login_check POST 30 login"
  "/api/assistant/answers POST 20 assistant"
  "/api/watch GET 400 publicapi"
)

# `root` (/var/www/backend/public) n'existe pas dans le conteneur, et c'est
# sans importance : le 429 naît avant tout accès au système de fichiers. Pas
# de dossier temporaire monté à la place : Docker Desktop ne partage pas /tmp.
CIDS=()
cleanup() {
  local cid
  for cid in "${CIDS[@]}"; do docker stop "$cid" > /dev/null 2>&1 || true; done
}
trap cleanup EXIT

# start_nginx <fichier de conf> <port écouté dans le conteneur> : lance le
# conteneur, attend qu'il réponde, et écrit son URL de base dans BASE.
start_nginx() {
  local conf="$1" port="$2" cid host_port code=""
  cid="$(docker run -d --rm --add-host backend:127.0.0.1 \
    -v "${conf}:/etc/nginx/conf.d/default.conf:ro" \
    -p "127.0.0.1::${port}" "$IMAGE")"
  CIDS+=("$cid")

  # `|| true` : sous pipefail, un `docker port` qui échoue (conteneur mort au
  # démarrage) couperait le script avant le `docker logs` qui l'explique.
  host_port="$(docker port "$cid" "${port}/tcp" | head -1 || true)"
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

# check_zone <chemin> <méthode> <max> <zone> : sature la zone, puis juge le
# premier 429 obtenu.
check_zone() {
  local path="$1" method="$2" max="$3" zone="$4" i raw code body_file
  body_file="$(mktemp)"
  for i in $(seq 1 "$max"); do
    raw="$(curl -sS "${CURL_OPTS[@]}" -X "$method" -D - -o "$body_file" \
      -w 'x-audit-http-code: %{http_code}\n' "${BASE}${path}")"
    code="$(sed -n 's/^x-audit-http-code: //p' <<< "$raw" | tail -1)"
    [ "$code" = "429" ] && break
  done

  if [ "$code" != "429" ]; then
    printf '  \033[31mPAS DE 429\033[0m  %s (zone %s) : aucun 429 en %s requêtes, zone absente ?\n' "$path" "$zone" "$max"
    FAILURES=$((FAILURES + 1))
    rm -f "$body_file"
    return
  fi

  printf '  %s (zone %s) : 429 à la requête %s\n' "$path" "$zone" "$i"
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
for target in "docker/nginx/default.conf 80" "k8s/base/backend-nginx.conf 8080"; do
  read -r conf port <<< "$target"
  echo "== ${conf} (image ${IMAGE})"
  start_nginx "${ROOT}/${conf}" "$port"
  for zone in "${ZONES[@]}"; do
    # shellcheck disable=SC2086 # découpage voulu : quatre champs par ligne
    check_zone $zone
  done
  echo
done

if [ "$FAILURES" -gt 0 ]; then
  echo "Échecs bloquants : $FAILURES"
  exit 1
fi
echo "Toutes les zones des deux confs répondent 429 en problem+json, avec les 7 en-têtes de sécurité."
