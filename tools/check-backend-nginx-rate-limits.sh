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
#   - qu'un 429 rendu par Symfony (quota applicatif, avec Retry-After)
#     traverse nginx sans être intercepté. C'est garanti par l'absence de
#     `fastcgi_intercept_errors` dans les deux confs, et vérifié à la main en
#     dev (cf. #347).
#
# Depuis #644, il vérifie aussi, sur les deux confs :
#   - que les déclarations de zones et les locations de MIRRORED_LOCATIONS
#     sont identiques d'une conf à l'autre (le sondage seul ne verrait pas
#     deux débits différents) ;
#   - les bornes de corps de BODY_LIMITS : 413 d'un octet au-delà, rien à la
#     borne.
# Depuis #672, ce que la clé de la zone `mcpsession` compte comme une
# création de session (check_session_key), et le 429 de chaque zone
# `limit_conn` de CONN_LIMITS (check_conn_limit) : faute de php-fpm, une
# connexion n'est retenue qu'en n'achevant pas son corps — nginx la compte dès
# ses en-têtes lus, et lit le corps avant de joindre l'amont.
# Depuis #686, le refus des corps multipart (check_content_types) :
# `multipart/form-data`, le parseur visé par CVE-2026-109618, refusé en 415
# problem+json avant PHP, sur toute méthode et toute location ; JSON,
# urlencoded (le point de jeton OAuth de la phase 2 MCP en a besoin) et une
# requête sans corps passent.
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

# <chemin> <méthode> <burst de la zone> <zone> [<en-tête>:<valeur>, sans espace]
ZONES=(
  "/api/contact POST 5 contact"
  "/api/account/password-setup POST 10 pwsetup"
  "/api/account/base-access POST 10 baseaccess"
  "/api/login_check POST 10 login"
  "/api/assistant/answers POST 5 assistant"
  # Serveur MCP (spec 0007, #644), location exacte, toutes méthodes. Deux
  # zones : `mcpsession` ne compte que les créations de session (POST sans
  # UUID bien formé dans Mcp-Session-Id, hors révision 2026-07-28 : #672, voir
  # check_session_key), `mcp` toutes les requêtes. La ligne POST de `mcp`
  # porte donc un identifiant (un UUID inconnu : nginx n'en vérifie que la
  # forme), sans quoi `mcpsession` refuserait la première. La ligne GET a une
  # rafale de 0 : elle suit la ligne POST, et son 429 dans les sondes ne vient
  # que d'une zone que le POST vient de vider — le même compteur. Avant la
  # ligne suivante, qui sature `publicapi`.
  "/api/mcp POST 5 mcpsession"
  "/api/mcp POST 20 mcp Mcp-Session-Id:00000000-0000-0000-0000-000000000000"
  "/api/mcp GET 0 mcp"
  "/api/watch GET 200 publicapi"
)
# Bornes de corps propres à une location : <chemin> <octets admis>. Un corps
# d'un octet de plus doit être refusé en 413 par nginx, avant PHP, annoncé par
# Content-Length comme envoyé en `Transfer-Encoding: chunked` ; un corps pile
# à la borne, non. Avec Content-Length, le 413 naît à la recherche de location
# (find-config), avant toute zone ; en chunked, à la lecture du corps, dans la
# location qui fait le `fastcgi_pass` — d'où une location qui le fait
# elle-même : avec `try_files`, le corps était lu sous `~ ^/index\.php`, qui
# n'a que le 1 Mo du bloc server (relectures de #644, mesuré).
BODY_LIMITS=(
  "/api/mcp 16384"
)
# Zones `limit_conn` : <chemin> <connexions admises> <zone>. Autant de
# connexions tenues ouvertes, puis la suivante doit recevoir le 429 de la zone,
# et passer de nouveau une fois elles fermées. Les requêtes tenues portent un
# identifiant de session bien formé : elles n'entament pas `mcpsession`.
CONN_LIMITS=(
  "/api/mcp 2 mcpconn"
  "/api/assistant/answers 1 assistantconn"
)
# Locations dont le bloc doit être identique, directive par directive, dans
# les deux confs (commentaires et blancs ignorés) : le miroir n'est garanti
# que là où il est vérifié.
MIRRORED_LOCATIONS=(
  "= /api/mcp"
  # Les corps des refus de nginx (#686) : la sonde ne lit que le statut et le
  # `type`, une divergence du `title` ou du `detail` ne se verrait pas.
  "@rate_limited"
  "@unsupported_media_type"
)
# Requêtes une à une après la rafale : une ou deux ont pu être rechargées par
# le taux de la zone pendant qu'on la vidait.
MAX_PROBES=10
# Clé de la zone `mcpsession` (#672) : tout POST dont Mcp-Session-Id n'est pas
# un UUID bien formé compte comme une création, sauf la révision sans session
# 2026-07-28, qui n'écrit rien en base. Nombre de requêtes admises avant son
# 429 : 1 + la rafale de sa ligne de ZONES, plus une marge pour un jeton
# rechargé (10 r/min, un toutes les 6 s). Bien en deçà des 21 de la zone `mcp`
# (rafale 20) : un 429 dans la borne ne peut venir que de `mcpsession`.
MCPSESSION_BURST=""
for zone in "${ZONES[@]}"; do
  read -r _ _ burst name _ <<< "$zone"
  if [ "$name" = mcpsession ]; then MCPSESSION_BURST="$burst"; fi
done
MCPSESSION_MAX_ADMITTED=$((MCPSESSION_BURST + 3))
# Un identifiant de session bien formé, inconnu du serveur.
WELL_FORMED_SESSION='00000000-0000-0000-0000-000000000000'
# Corps multipart refusés par nginx (#686) :
# <libellé>|<méthode>|<chemin>|<Content-Type, vide : aucun>|<415 ou « autre »>.
# Plusieurs en-têtes Content-Type se séparent par `^` : nginx les joint par
# « , », dans l'ordre, et PHP coupe au premier `,` — les deux retiennent le
# premier type (mesuré par l'audit de #686), ce que les deux cas de doublon
# figent. PHP analyse le multipart au démarrage d'un POST, et Symfony le fait
# analyser pour PUT, DELETE, PATCH et QUERY (`request_parse_body()` dans
# `Request::createFromGlobals()`), avant le routeur et le pare-feu : d'où des
# méthodes et des chemins variés, les locations qui font leur propre
# `fastcgi_pass` comprises. PHP compare le type en minuscules ; nginx refuse
# aussi la casse mêlée et un blanc en tête. Les témoins « autre » prouvent que
# le refus ne vise pas tout corps.
CONTENT_TYPE_CASES=(
  "multipart en POST|POST|/api/contact|multipart/form-data; boundary=x|415"
  "multipart en PUT sur une route inconnue|PUT|/api/inconnue|multipart/form-data; boundary=x|415"
  "multipart en casse mêlée, DELETE|DELETE|/api/backoffice/users/x|Multipart/Form-Data; boundary=x|415"
  $'multipart précédé d\'une tabulation|POST|/api/login_check|\tmultipart/form-data; boundary=x|415'
  "multipart sur /api/mcp|POST|/api/mcp|multipart/form-data; boundary=x|415"
  "multipart sur /api/assistant/answers|POST|/api/assistant/answers|multipart/form-data; boundary=x|415"
  "multipart en QUERY|QUERY|/api/inconnue|multipart/form-data; boundary=x|415"
  "multipart puis JSON, doublon|POST|/api/contact|multipart/form-data; boundary=x^application/json|415"
  "JSON puis multipart, doublon, témoin (PHP retient JSON)|POST|/api/contact|application/json^multipart/form-data; boundary=x|autre"
  "urlencoded, témoin (point de jeton OAuth)|POST|/api/login_check|application/x-www-form-urlencoded|autre"
  "JSON, témoin|POST|/api/contact|application/json|autre"
  "sans Content-Type ni corps, témoin|POST|/api/logout||autre"
)

# Couverture : chaque zone `limit_req` déclarée dans une des confs doit avoir
# sa ligne dans ZONES. Sans ce contrôle, une zone oubliée resterait verte sans
# avoir jamais été testée.
declare -A LISTED=()
for zone in "${ZONES[@]}"; do
  read -r _ _ _ name _ <<< "$zone"
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

# Même couverture pour les zones `limit_conn`, dans CONN_LIMITS (#672).
declare -A CONN_LISTED=()
for limit in "${CONN_LIMITS[@]}"; do
  read -r _ _ name <<< "$limit"
  CONN_LISTED[$name]=1
done
for target in "${CONFS[@]}"; do
  read -r conf _ <<< "$target"
  while read -r name; do
    if [ -z "${CONN_LISTED[$name]:-}" ]; then
      printf '  \033[31mNON TESTÉE\033[0m  zone limit_conn « %s » de %s absente de CONN_LIMITS   <-- ajouter sa ligne\n' "$name" "$conf"
      FAILURES=$((FAILURES + 1))
    fi
  done < <(grep -oE '^[[:space:]]*limit_conn[[:space:]]+[A-Za-z0-9_]+' "${ROOT}/${conf}" | awk '{ print $2 }' | sort -u)
done

# Miroir (#644) : tout le niveau `http` (real_ip, `map`, déclarations de
# zones), puis chaque location de MIRRORED_LOCATIONS, identiques dans les deux
# confs. Le sondage ci-dessous prouve qu'une zone répond, pas qu'elle a le
# même débit des deux côtés.
# http_level <conf> : les directives avant `server {`, commentaires et blancs
# retirés.
http_level() {
  awk '/^server[[:space:]]*\{/ { exit }
    { sub(/#.*/, ""); gsub(/^[[:space:]]+|[[:space:]]+$/, ""); gsub(/[[:space:]]+/, " ") }
    $0 != "" { print }' "$1"
}
# location_block <conf> <ouverture> : les directives du bloc `location
# <ouverture> {`, commentaires et blancs retirés ; rien si le bloc manque.
# L'amont de `fastcgi_pass` est la seule divergence admise (`backend:9000` en
# dev, `127.0.0.1:9000` dans le pod) : il est masqué.
location_block() {
  awk -v opening="location $2 {" '
    { line = $0; sub(/#.*/, "", line); gsub(/^[[:space:]]+|[[:space:]]+$/, "", line); gsub(/[[:space:]]+/, " ", line)
      sub(/^fastcgi_pass [^;]+;$/, "fastcgi_pass <amont>;", line) }
    !inside && line == opening { inside = 1; next }
    inside && line == "}" { exit }
    inside && line != "" { print line }
  ' "$1"
}
read -r FIRST_CONF _ <<< "${CONFS[0]}"
read -r SECOND_CONF _ <<< "${CONFS[1]}"
if ! diff <(http_level "${ROOT}/${FIRST_CONF}") <(http_level "${ROOT}/${SECOND_CONF}") > /dev/null; then
  printf '  \033[31mMIROIR\033[0m      niveau http (real_ip, map, zones) différent entre %s et %s   <-- aligner les deux\n' "$FIRST_CONF" "$SECOND_CONF"
  FAILURES=$((FAILURES + 1))
fi
for opening in "${MIRRORED_LOCATIONS[@]}"; do
  first="$(location_block "${ROOT}/${FIRST_CONF}" "$opening")"
  second="$(location_block "${ROOT}/${SECOND_CONF}" "$opening")"
  if [ -z "$first" ] || [ -z "$second" ]; then
    printf '  \033[31mMIROIR\033[0m      location %s absente de %s   <-- la déclarer dans les deux confs\n' \
      "$opening" "$([ -z "$first" ] && echo "$FIRST_CONF" || echo "$SECOND_CONF")"
    FAILURES=$((FAILURES + 1))
  elif [ "$first" != "$second" ]; then
    printf '  \033[31mMIROIR\033[0m      location %s différente entre %s et %s   <-- aligner les deux\n' "$opening" "$FIRST_CONF" "$SECOND_CONF"
    FAILURES=$((FAILURES + 1))
  fi
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
  local conf="$1" path="$2" method="$3" burst="$4" zone="$5" header="${6:-}" i raw code="" body_file
  local -a headers=()
  [ -n "$header" ] && headers=(-H "${header/:/: }")
  body_file="$(mktemp)"

  # Rafale vidée d'un coup : burst + 1 requêtes, 50 à la fois. Le `?n=[…]` ne
  # sert qu'à faire générer les URL par curl ; la zone compte par IP, et la
  # location se choisit sur le chemin seul. Les codes sont ignorés : seule
  # compte la réponse jugée ensuite. `|| true` : un échec ici se reverra plus bas.
  curl -s "${CURL_OPTS[@]}" --parallel --parallel-max 50 -X "$method" "${headers[@]}" \
    -o /dev/null "${BASE}${path}?n=[0-${burst}]" > /dev/null 2>&1 || true

  for i in $(seq 1 "$MAX_PROBES"); do
    # `|| true` : sous `set -e`, un seul curl en échec (délai dépassé)
    # arrêterait tout le script sans dire quelle zone était en cours.
    raw="$(curl -sS "${CURL_OPTS[@]}" -X "$method" "${headers[@]}" -D - -o "$body_file" \
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

# check_body_limit <conf> <chemin> <octets admis> : un corps d'un octet de
# trop est refusé en 413, un corps pile à la borne passe la borne (502 ici,
# faute de php-fpm — tout sauf 413), annoncé par Content-Length puis envoyé
# en chunked. Les sondes portent un identifiant de session factice : elles
# n'entament pas la zone des créations de session sondée ensuite.
check_body_limit() {
  local conf="$1" path="$2" limit="$3" size code expected ok framing
  local -a framing_opts
  for framing in content-length chunked; do
    framing_opts=()
    [ "$framing" = chunked ] && framing_opts=(-H 'Transfer-Encoding: chunked')
    for size in "$((limit + 1))" "$limit"; do
      head -c "$size" /dev/zero | tr '\0' 'a' > "$BODY_FILE"
      code="$(curl -s "${CURL_OPTS[@]}" -X POST -H 'Content-Type: application/json' \
        -H 'Mcp-Session-Id: 00000000-0000-0000-0000-000000000000' "${framing_opts[@]}" \
        --data-binary "@${BODY_FILE}" -o /dev/null -w '%{http_code}' "${BASE}${path}" 2>/dev/null || true)"
      if [ "$size" -gt "$limit" ]; then
        expected="413"
        [ "$code" = "413" ] && ok=1 || ok=0
      else
        expected="autre que 413"
        [ -n "$code" ] && [ "$code" != "000" ] && [ "$code" != "413" ] && ok=1 || ok=0
      fi
      if [ "$ok" = 1 ]; then
        printf '  \033[32mOK\033[0m      %s : corps de %s octets (%s) → %s\n' "$path" "$size" "$framing" "$code"
      else
        printf '  \033[31mKO\033[0m      %s : corps de %s octets (%s) → « %s », %s attendu (borne %s, %s)   <-- client_max_body_size, et fastcgi_pass, de la location ?\n' \
          "$path" "$size" "$framing" "${code:-aucun}" "$expected" "$limit" "$conf"
        FAILURES=$((FAILURES + 1))
      fi
    done
  done
}

# mcp_post <en-têtes curl…> : un POST sur /api/mcp, code HTTP sur la sortie.
mcp_post() {
  curl -s "${CURL_OPTS[@]}" -X POST "$@" -o /dev/null -w '%{http_code}' "${BASE}/api/mcp" 2>/dev/null || true
}

# check_conn_limit <conf> <chemin> <connexions admises> <zone> : ouvre autant
# de connexions que la zone en admet, chacune arrêtée au milieu de son corps
# (Content-Length annoncé, un octet envoyé), puis exige le 429 problem+json sur
# la suivante, et une réponse autre que 429 une fois les connexions fermées
# — sans quoi le 429 pourrait venir d'une zone `limit_req`.
check_conn_limit() {
  local conf="$1" path="$2" limit="$3" zone="$4" hostport host port i fd code after body_file
  local -a fds=()
  hostport="${BASE#http://}"
  host="${hostport%:*}"
  port="${hostport##*:}"
  body_file="$(mktemp)"
  for i in $(seq 1 "$limit"); do
    exec {fd}<>"/dev/tcp/${host}/${port}"
    printf 'POST %s HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nMcp-Session-Id: %s\r\nContent-Length: 100\r\n\r\n{' \
      "$path" "$WELL_FORMED_SESSION" >&"$fd"
    fds+=("$fd")
  done
  # Le temps que nginx lise les en-têtes et compte chaque connexion.
  sleep 1
  code="$(curl -s "${CURL_OPTS[@]}" -X POST -H "Mcp-Session-Id: ${WELL_FORMED_SESSION}" \
    -o "$body_file" -w '%{http_code}' "${BASE}${path}" 2>/dev/null || true)"
  for fd in "${fds[@]}"; do exec {fd}>&-; done
  sleep 1
  after="$(curl -s "${CURL_OPTS[@]}" -X POST -H "Mcp-Session-Id: ${WELL_FORMED_SESSION}" \
    -o /dev/null -w '%{http_code}' "${BASE}${path}" 2>/dev/null || true)"

  if [ "$code" = "429" ] && grep -q '"type":"/errors/rate-limited"' "$body_file" \
     && [ -n "$after" ] && [ "$after" != "000" ] && [ "$after" != "429" ]; then
    printf '  \033[32mOK\033[0m      %s (zone %s) : 429 problem+json au-delà de %s connexion(s), %s une fois fermées\n' "$path" "$zone" "$limit" "$after"
  else
    printf '  \033[31mKO\033[0m      %s (zone %s, %s) : %s connexion(s) tenue(s) → « %s », 429 rate-limited attendu ; fermées → « %s », autre que 429 attendu   <-- limit_conn %s %s dans la location ?\n' \
      "$path" "$zone" "$conf" "$limit" "${code:-aucun}" "${after:-aucun}" "$zone" "$limit"
    FAILURES=$((FAILURES + 1))
  fi
  rm -f "$body_file"
}

# check_session_key <conf> : sur des compteurs neufs (le conteneur que vient
# de lancer start_nginx, avant toute autre sonde), ce que la clé de
# `mcpsession` compte (#672). nginx et le SDK ne lisent pas l'en-tête de la
# même façon : PHP rogne les tabulations, supprime un en-tête portant un
# caractère de contrôle, puis teste la valeur PHP vraie ou fausse — d'où une
# liste d'admission côté nginx (seul un UUID bien formé vaut « avec session »)
# plutôt qu'une liste des formes vides, que `<TAB>0` contournait (relectures
# de #672, mesuré : sessions créées sans 429).
#   1. Des POST sous `Mcp-Session-Id: 0`, une à une : le 429 doit tomber
#      après la rafale (au moins MCPSESSION_BURST admis) et dans
#      MCPSESSION_MAX_ADMITTED — la zone `mcp` en admettrait 21.
#   2. La zone vidée, chaque cas de SESSION_KEY_CASES : 429 s'il est compté,
#      autre chose sinon. Les cas « non comptés » sont les témoins qui prouvent
#      que la zone ne compte pas tout.
SESSION_KEY_CASES=(
  "en-tête Mcp-Session-Id vide, compté|Mcp-Session-Id;|429"
  $'Mcp-Session-Id « <TAB>0 », compté (PHP rogne la tabulation)|Mcp-Session-Id: \t0|429'
  $'Mcp-Session-Id « x<0x01>y », compté (PHP supprime l\'en-tête)|Mcp-Session-Id: x\x01y|429'
  $'UUID suivi d\'une tabulation, compté|Mcp-Session-Id: '"${WELL_FORMED_SESSION}"$'\t|429'
  "UUID bien formé, non compté|Mcp-Session-Id: ${WELL_FORMED_SESSION}|autre que 429"
  "POST sans session de la révision 2026-07-28, non compté|MCP-Protocol-Version: 2026-07-28|autre que 429"
  "POST sans session de la révision 2025-06-18, compté|MCP-Protocol-Version: 2025-06-18|429"
)
check_session_key() {
  local conf="$1" i code="" admitted=""
  for i in $(seq 1 "$((MCPSESSION_MAX_ADMITTED + 1))"); do
    code="$(mcp_post -H 'Mcp-Session-Id: 0')"
    if [ "$code" = "429" ]; then admitted=$((i - 1)); break; fi
  done
  if [ -n "$admitted" ] && [ "$admitted" -ge "$MCPSESSION_BURST" ]; then
    printf '  \033[32mOK\033[0m      mcpsession : « Mcp-Session-Id: 0 » compté, 429 après %s POST\n' "$admitted"
  else
    printf '  \033[31mKO\033[0m      mcpsession : POST « Mcp-Session-Id: 0 » : %s, au moins %s admis puis 429 dans les %s attendus (dernier code « %s », %s)   <-- clé du map $mcp_new_session, rafale de mcpsession\n' \
      "${admitted:+429 après $admitted}${admitted:-aucun 429}" "$MCPSESSION_BURST" "$((MCPSESSION_MAX_ADMITTED + 1))" "${code:-aucun}" "$conf"
    FAILURES=$((FAILURES + 1))
    return
  fi

  local entry label header expected
  for entry in "${SESSION_KEY_CASES[@]}"; do
    IFS='|' read -r label header expected <<< "$entry"
    code="$(mcp_post -H "$header")"
    if { [ "$expected" = "429" ] && [ "$code" = "429" ]; } || { [ "$expected" != "429" ] && [ -n "$code" ] && [ "$code" != "000" ] && [ "$code" != "429" ]; }; then
      printf '  \033[32mOK\033[0m      mcpsession : %s → %s\n' "$label" "$code"
    else
      printf '  \033[31mKO\033[0m      mcpsession : %s → « %s », %s attendu (%s)   <-- clé du map $mcp_new_session\n' \
        "$label" "${code:-aucun}" "$expected" "$conf"
      FAILURES=$((FAILURES + 1))
    fi
  done
}

# check_content_types <conf> : chaque cas de CONTENT_TYPE_CASES. Un refus doit
# être le 415 problem+json `/errors/415` de nginx, avec les 7 en-têtes de
# sécurité (location nommée sans add_header, comme `@rate_limited`) ; un
# témoin, tout code sauf 415 — 502 ici, faute de php-fpm.
check_content_types() {
  local conf="$1" entry label method path content_type expected raw code body_file
  local type
  local -a ct_opts content_types
  body_file="$(mktemp)"
  for entry in "${CONTENT_TYPE_CASES[@]}"; do
    IFS='|' read -r label method path content_type expected <<< "$entry"
    ct_opts=()
    if [ -n "$content_type" ]; then
      IFS='^' read -r -a content_types <<< "$content_type"
      for type in "${content_types[@]}"; do ct_opts+=(-H "Content-Type: ${type}"); done
      ct_opts+=(--data-binary 'a=1')
    fi
    raw="$(curl -sS "${CURL_OPTS[@]}" -X "$method" "${ct_opts[@]}" -D - -o "$body_file" \
      -w 'x-audit-http-code: %{http_code}\n' "${BASE}${path}" 2>&1 || true)"
    code="$(sed -n 's/^x-audit-http-code: //p' <<< "$raw" | tail -1)"
    if [ "$expected" != 415 ]; then
      if [ -n "$code" ] && [ "$code" != "000" ] && [ "$code" != "415" ]; then
        printf '  \033[32mOK\033[0m      %s → %s\n' "$label" "$code"
      else
        printf '  \033[31mKO\033[0m      %s → « %s », autre que 415 attendu (%s)   <-- map $refused_content_type trop large ?\n' \
          "$label" "${code:-aucun}" "$conf"
        FAILURES=$((FAILURES + 1))
      fi
      continue
    fi
    if [ "$code" = "415" ] && grep -qi '^content-type: application/problem+json' <<< "$raw" \
       && grep -q '"type":"/errors/415"' "$body_file" && grep -q '"status":415' "$body_file"; then
      printf '  \033[32mOK\033[0m      %s → 415 problem+json\n' "$label"
      check_security_headers "$label (415)" "$raw"
    else
      printf '  \033[31mKO\033[0m      %s → « %s », 415 problem+json /errors/415 attendu (%s) ; corps : %s   <-- refus des corps de formulaire (#686) ?\n' \
        "$label" "${code:-aucun}" "$conf" "$(head -c 120 "$body_file" | tr '\n' ' ')"
      FAILURES=$((FAILURES + 1))
    fi
  done
  rm -f "$body_file"
}

BODY_FILE="$(mktemp)"
trap 'cleanup; rm -f "$BODY_FILE"' EXIT

# <conf relative au dépôt> <port écouté>
for target in "${CONFS[@]}"; do
  read -r conf port <<< "$target"
  echo "== ${conf} (image ${IMAGE})"
  start_nginx "${ROOT}/${conf}" "$port"
  # D'abord, sur des compteurs neufs : les sondes suivantes entament `mcp` et
  # `mcpsession`, ce qui brouillerait la borne de check_session_key, et un
  # 429 de `limit_req` masquerait celui de `limit_conn`. Elles s'accommodent
  # en retour d'une `mcpsession` déjà vide (leur 429 vient plus tôt) et d'une
  # `mcp` entamée d'une vingtaine de jetons sur 21, rechargés à un par seconde.
  for limit in "${CONN_LIMITS[@]}"; do
    # shellcheck disable=SC2086 # découpage voulu : trois champs par ligne
    check_conn_limit "$conf" $limit
  done
  check_session_key "$conf"
  for limit in "${BODY_LIMITS[@]}"; do
    # shellcheck disable=SC2086 # découpage voulu : deux champs par ligne
    check_body_limit "$conf" $limit
  done
  # Avant les zones : le témoin JSON sur /api/contact prend un jeton de
  # `contact`, que check_zone tolère (la rafale vidée, son 429 vient plus tôt).
  check_content_types "$conf"
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
echo "Toutes les zones limit_req et limit_conn des deux confs répondent 429 en problem+json, avec les 7 en-têtes de sécurité ; corps multipart refusés en 415 ; clé de mcpsession, zones, locations miroir et bornes de corps alignées."
