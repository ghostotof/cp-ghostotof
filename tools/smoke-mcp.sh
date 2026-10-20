#!/usr/bin/env bash
#
# smoke-mcp.sh
# ---------------------------------------------------------------------------
# Exerce le serveur MCP contre un déploiement RÉEL (spec 0007, #644).
#
# Quatre preuves que la CI ne peut pas apporter, sur le sidecar déployé :
#
# 1. Un `initialize` sur l'hôte public aboutit. Le SDK refuse en 403 un hôte
#    absent d'`allowed_hosts`, dérivé d'`APP_FRONTEND_BASE_URL` (mcp.yaml) :
#    une URL mal posée dans un overlay couperait tout client MCP, et rien
#    d'autre ne le verrait. La session ouverte est aussitôt fermée (DELETE),
#    et ce DELETE doit aboutir : sinon la ligne reste une heure en base.
# 2. Un corps de 16 Kio + 1 octet est refusé en 413, annoncé par
#    Content-Length puis envoyé en chunked : la borne de la location et son
#    `fastcgi_pass` propre, sans lequel un corps chunked passait (relectures
#    de #644). Sous un identifiant de session factice, pour n'entamer ni la
#    zone des créations de session, ni rien en base : le 413 précède PHP.
# 3. La clé de la zone `mcpsession` (#672) : des `ping` sous
#    `Mcp-Session-Id: 0`, que le SDK lit comme une absence de session et
#    refuse en 400 sans rien écrire, sont comptés comme des créations — 429
#    dans SESSION_MAX_REQUESTS, quand la zone `mcp` (rafale 20) en admettrait
#    davantage. La zone vidée, un `tools/list` sans session de la révision
#    2026-07-28 n'est pas refusé en 429 : il est exempté, le SDK le sert sans
#    session. Un jeton rechargé entre-temps (10 r/min) laisserait passer ce
#    dernier même compté : le garde des confs (check-backend-nginx-rate-limits.sh)
#    en est la preuve exacte, celle-ci dit que le pod porte la bonne conf.
# 4. Au-delà du seuil, la zone nginx `mcp` répond 429 (problem+json
#    `/errors/rate-limited`). Les requêtes de saturation sont des GET en SSE
#    sans session : ils démarrent Symfony mais n'écrivent rien en base. La
#    zone admet 60 r/min avec une rafale de 20, par pod : la préprod (un pod)
#    refuse vers la 14e requête (les étapes précédentes ont pris leurs jetons
#    — mesuré en dev le 2026-10-20 ; vers la 20e avant l'étape 3), et `publicapi` (rafale de 200) n'en refuserait aucune
#    avant MAX_REQUESTS. Un 429 dans la borne vient donc de la zone `mcp`,
#    pas du filet général.
#
# Appelé par le job `smoke-test-preprod` : son échec bloque la PR vers `main`.
#
# Usage :  tools/smoke-mcp.sh <url-de-base>
#          ex. tools/smoke-mcp.sh https://preprod.cp-ghostotof.com
# Env :    AUDIT_BASIC_AUTH  "utilisateur:mot_de_passe" (préprod), optionnel.
#                            Jamais via `-u` : les arguments d'un processus se
#                            lisent dans `ps` ; le couple passe par un fichier
#                            de configuration curl en 600 (comme audit-prod.sh).
#          SMOKE_CURL        binaire curl de substitution (tests hors ligne).
# ---------------------------------------------------------------------------
set -euo pipefail

base="${1:-}"
if [ -z "$base" ]; then
  echo "usage : $0 <url-de-base>" >&2
  exit 2
fi
base="${base%/}"
endpoint="$base/api/mcp"

curl_bin="${SMOKE_CURL:-curl}"
# Bien au-delà de la rafale d'un pod (20) et du taux rechargé pendant la
# boucle, bien en deçà de la rafale de `publicapi` (200).
MAX_REQUESTS=120
# Le nom que le serveur déclare (mcp.yaml) : un 200 d'un autre service
# derrière l'Ingress ne prouverait rien.
server_name='"name":"cp-ghostotof"'
# La borne de corps de la location `= /api/mcp` (backend-nginx.conf).
BODY_LIMIT=16384
# Un identifiant bien formé que le serveur ne connaît pas.
FAKE_SESSION='00000000-0000-0000-0000-000000000000'
# Étape 3 : la zone `mcpsession` admet 1 + sa rafale (5) par pod, moins le
# jeton de l'initialize ; la borne laisse une marge de rechargement et reste
# en deçà de ce que la zone `mcp` admet encore à ce stade.
SESSION_MAX_REQUESTS=12
MODERN_VERSION='2026-07-28'

headers_file="$(mktemp)"
body_file="$(mktemp)"
auth_config=""
trap 'rm -f "$headers_file" "$body_file" ${auth_config:+"$auth_config"}' EXIT

curl_opts=(-sS --connect-timeout 5 --max-time 15 -D "$headers_file")
if [ -n "${AUDIT_BASIC_AUTH:-}" ]; then
  auth_config="$(mktemp)"
  printf 'user = "%s"\n' "$(printf '%s' "$AUDIT_BASIC_AUTH" | sed 's/\\/\\\\/g; s/"/\\"/g')" > "$auth_config"
  curl_opts+=(-K "$auth_config")
fi

# Valeur d'un en-tête de la dernière réponse : nom insensible à la casse
# (HTTP/2 l'écrit en minuscules), CR retiré, dernière occurrence.
header() {
  tr -d '\r' < "$headers_file" | awk -v name="$1" '
    tolower(substr($0, 1, length(name) + 1)) == tolower(name) ":" { sub(/^[^:]*:[[:space:]]*/, ""); value = $0 }
    END { print value }'
}

# request <étape> <méthode> <en-têtes curl…> : remplit `status` et `body`.
# Dernière ligne de la sortie = code HTTP, le reste = corps. Un curl en échec
# (délai, connexion coupée) arrête le smoke en nommant l'étape.
request() {
  local step="$1" method="$2" response code
  shift 2
  if response="$("$curl_bin" "${curl_opts[@]}" -X "$method" "$@" -w '\n%{http_code}' "$endpoint")"; then
    :
  else
    code=$?
    echo "$step : curl a échoué (code $code) sur $method $endpoint" >&2
    exit 1
  fi
  status="${response##*$'\n'}"
  body="${response%$'\n'*}"
  # Le corps part dans le journal de la CI : tout caractère de contrôle devient
  # une espace, un `\r` y ouvrirait une ligne `::commande::` du runner.
  excerpt="$(printf '%s' "$body" | tr '\000-\037\177' '[ *]' | cut -c1-160)"
}

# --- 1. initialize sur l'hôte réel.
request initialize POST \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke-mcp","version":"1.0.0"}}}'

if [ "$status" = "403" ]; then
  echo "initialize : 403 — le SDK refuse l'hôte : allowed_hosts (dérivé d'APP_FRONTEND_BASE_URL, mcp.yaml) ne porte pas celui de $base (corps : $excerpt)" >&2
  exit 1
fi
if [ "$status" != "200" ]; then
  echo "initialize : $status reçu, 200 attendu (corps : $excerpt)" >&2
  exit 1
fi
session_id="$(header Mcp-Session-Id)"
if [ -z "$session_id" ]; then
  echo "initialize : 200 sans en-tête Mcp-Session-Id, le handshake n'a pas abouti (corps : $excerpt)" >&2
  exit 1
fi
if [[ "$body" != *"$server_name"* ]]; then
  echo "initialize : 200 sans le serverInfo du portfolio (${server_name}) — un autre service a répondu ? (corps : $excerpt)" >&2
  exit 1
fi
echo "initialize : session ouverte sur l'hôte réel (allowed_hosts accepte $base)"

# Fermer la session : sa ligne de `cache_items` disparaît sans attendre la purge.
request "fin de session" DELETE -H "Mcp-Session-Id: $session_id"
if [ "$status" != "200" ] && [ "$status" != "204" ]; then
  echo "fin de session : $status, 200 ou 204 attendu — la session reste une heure en base (corps : $excerpt)" >&2
  exit 1
fi
echo "fin de session : $status"

# --- 2. Borne de corps de la location.
head -c "$((BODY_LIMIT + 1))" /dev/zero | tr '\0' 'a' > "$body_file"
for framing in content-length chunked; do
  framing_opts=()
  [ "$framing" = chunked ] && framing_opts=(-H 'Transfer-Encoding: chunked')
  request "borne de corps ($framing)" POST -H 'Content-Type: application/json' -H "Mcp-Session-Id: $FAKE_SESSION" \
    "${framing_opts[@]}" --data-binary "@$body_file"
  if [ "$status" != "413" ]; then
    hint="client_max_body_size 16k de la location = /api/mcp"
    [ "$framing" = chunked ] && hint="fastcgi_pass propre à la location = /api/mcp (sans lui, un corps chunked est lu sous le 1 Mo du bloc server)"
    echo "corps de $((BODY_LIMIT + 1)) octets ($framing) : $status, 413 attendu — $hint (corps : $excerpt)" >&2
    exit 1
  fi
  echo "corps de $((BODY_LIMIT + 1)) octets refusé ($framing) : 413"
done

# --- 3. Clé de la zone des créations de session.
session_counted=""
for attempt in $(seq 1 "$SESSION_MAX_REQUESTS"); do
  request "sessions, requête $attempt" POST -H 'Content-Type: application/json' \
    -H 'Accept: application/json, text/event-stream' -H 'Mcp-Session-Id: 0' \
    -d '{"jsonrpc":"2.0","id":1,"method":"ping"}'
  if [ "$status" = "429" ]; then
    session_counted=$((attempt - 1))
    break
  fi
  if [ "$status" != "400" ]; then
    echo "ping sous « Mcp-Session-Id: 0 », requête $attempt : $status, 400 (session requise) ou 429 attendu (corps : $excerpt)" >&2
    exit 1
  fi
done
if [ -z "$session_counted" ]; then
  echo "ÉCHEC : aucun 429 après $SESSION_MAX_REQUESTS POST « Mcp-Session-Id: 0 » — la zone mcpsession ne compte pas « 0 » comme une absence de session (map \$mcp_without_session, #672)." >&2
  exit 1
fi
echo "« Mcp-Session-Id: 0 » compté par la zone mcpsession : 429 après $session_counted POST"

request "révision $MODERN_VERSION" POST -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' -H "MCP-Protocol-Version: $MODERN_VERSION" -H 'Mcp-Method: tools/list' \
  -d "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/list\",\"params\":{\"_meta\":{\"io.modelcontextprotocol/protocolVersion\":\"$MODERN_VERSION\",\"io.modelcontextprotocol/clientCapabilities\":{}}}}"
if [ "$status" = "429" ]; then
  echo "révision $MODERN_VERSION sans session : 429 — la zone mcpsession la compte comme une création (map \$mcp_new_session, #672) (corps : $excerpt)" >&2
  exit 1
fi
echo "révision $MODERN_VERSION sans session non comptée : $status"

# --- 4. Saturation de la zone mcp.
for attempt in $(seq 1 "$MAX_REQUESTS"); do
  request "saturation, requête $attempt" GET -H 'Accept: text/event-stream'

  if [ "$status" = "429" ]; then
    content_type="$(header Content-Type)"
    if [[ "$content_type" != application/problem+json* ]]; then
      echo "requête $attempt : 429 en « $content_type », application/problem+json attendu — l'error_page @rate_limited ne s'applique pas (corps : $excerpt)" >&2
      exit 1
    fi
    if [[ "$body" != *'"type":"/errors/rate-limited"'* ]]; then
      echo "requête $attempt : 429 sans le type /errors/rate-limited (corps : $excerpt)" >&2
      exit 1
    fi
    echo "OK : 429 de la zone mcp à la requête $attempt (problem+json /errors/rate-limited)."
    exit 0
  fi

  if [ "$status" != "200" ]; then
    echo "requête $attempt : $status reçu, 200 ou 429 attendu (corps : $excerpt)" >&2
    exit 1
  fi
done

echo "ÉCHEC : aucun 429 après $MAX_REQUESTS GET /api/mcp — la zone nginx mcp manque au sidecar déployé (#644) ; seul le filet publicapi (rafale de 200) s'applique." >&2
exit 1
