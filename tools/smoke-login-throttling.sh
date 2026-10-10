#!/usr/bin/env bash
#
# smoke-login-throttling.sh
# ---------------------------------------------------------------------------
# Exerce l'anti-brute-force du login contre un déploiement RÉEL (ADR 0005).
#
# Audit du 2026-09-16, constat A1 : le `login_throttling` de Symfony (5 essais
# par quart d'heure et par couple IP+identifiant) stockait son état sur le
# système de fichiers du pod, en lecture seule en production. L'écriture
# échouait en silence et quatorze logins erronés d'affilée passaient sans
# jamais être freinés. La CI n'a rien vu : son disque est inscriptible. Seul
# un test contre un pod déployé voit ce défaut — c'est ce script, appelé par
# le job `smoke-test-preprod`, qui bloque la PR vers `main` s'il échoue.
#
# Six POST /api/login_check avec un identifiant inexistant et un mauvais mot
# de passe : les cinq premiers répondent 401, le sixième doit être le refus de
# login_throttling — un 429 `/errors/rate-limited` avec un `Retry-After` > 0
# (issue #399 ; tests/Security/Authentication/LoginThrottlingTest pince ce
# contrat côté PHPUnit). L'identifiant est unique par exécution, pour que le
# compteur local reparte de zéro ; le compteur global par IP (25) tolère
# quatre exécutions par quart d'heure depuis un même runner, et une cinquième
# ne ferait que produire le 429 plus tôt — ce que ce script accepte aussi.
#
# Le `Retry-After` est ce qui distingue ce refus de celui de la zone nginx
# `login` (10 r/m, burst 10) : nginx rend lui aussi un 429
# `/errors/rate-limited`, mais sans `Retry-After`. Un 429 de nginx ne prouve
# rien sur l'état des limiteurs de l'application, il fait donc échouer le test.
#
# Usage :  tools/smoke-login-throttling.sh <url-de-base>
#          ex. tools/smoke-login-throttling.sh https://preprod.cp-ghostotof.com
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

curl_bin="${SMOKE_CURL:-curl}"
attempts=6
username="smoke-throttling-probe-$(date +%s)-$$"

# Les en-têtes de chaque réponse, pour y lire Retry-After.
headers_file="$(mktemp)"
auth_config=""
trap 'rm -f "$headers_file" ${auth_config:+"$auth_config"}' EXIT

curl_opts=(-sS --connect-timeout 5 --max-time 15 -D "$headers_file")
if [ -n "${AUDIT_BASIC_AUTH:-}" ]; then
  auth_config="$(mktemp)"
  printf 'user = "%s"\n' "$(printf '%s' "$AUDIT_BASIC_AUTH" | sed 's/\\/\\\\/g; s/"/\\"/g')" > "$auth_config"
  curl_opts+=(-K "$auth_config")
fi

throttled_at=0
for attempt in $(seq 1 "$attempts"); do
  # Dernière ligne = code HTTP, le reste = corps.
  response="$("$curl_bin" "${curl_opts[@]}" \
    -X POST \
    -H 'Content-Type: application/json' \
    -H 'X-Requested-With: fetch' \
    -d "{\"username\":\"$username\",\"password\":\"wrong-password-smoke\"}" \
    -w '\n%{http_code}' \
    "$base/api/login_check")"
  status="${response##*$'\n'}"
  body="${response%$'\n'*}"

  # Le corps est toujours montré, tronqué : un 401 de la Basic Auth de
  # l'ingress (HTML nginx) et un 401 de l'application (JSON Lexik) se
  # ressemblent au code près, et c'est la première chose à savoir quand ce
  # test échoue.
  excerpt="$(printf '%s' "$body" | tr '\n' ' ' | cut -c1-160)"

  if [ "$status" = "429" ]; then
    # Insensible à la casse (HTTP/2 écrit les noms en minuscules), CR retiré.
    retry_after="$(tr -d '\r' < "$headers_file" | sed -n 's/^[Rr][Ee][Tt][Rr][Yy]-[Aa][Ff][Tt][Ee][Rr]:[[:space:]]*//p' | tail -n 1)"
    if [ -z "$retry_after" ]; then
      echo "tentative $attempt : 429 sans Retry-After — c'est la zone nginx \`login\`, pas le login_throttling de l'application, et il ne prouve rien sur l'état des limiteurs (corps : $excerpt)" >&2
      exit 1
    fi
    if ! [[ "$retry_after" =~ ^[0-9]+$ ]] || [ "$retry_after" -le 0 ]; then
      echo "tentative $attempt : 429 avec un Retry-After invalide (« $retry_after »), un nombre de secondes > 0 attendu (corps : $excerpt)" >&2
      exit 1
    fi
    # `\/` toléré : JsonResponse (Symfony) échappe les barres obliques, la
    # zone nginx non — les deux formes valent le même JSON.
    if ! printf '%s' "$body" | grep -q '"type":"\\\?/errors\\\?/rate-limited"'; then
      echo "tentative $attempt : 429 sans le type /errors/rate-limited (corps : $excerpt)" >&2
      exit 1
    fi
    throttled_at="$attempt"
    echo "tentative $attempt : throttling actif (429 /errors/rate-limited, Retry-After : $retry_after s)"
    break
  fi

  if [ "$status" != "401" ]; then
    echo "tentative $attempt : $status reçu, 401 ou 429 attendu (corps : $excerpt)" >&2
    exit 1
  fi

  # L'ancien contrat (avant #399) : l'image déployée ne porte pas le 429.
  # Le dire, plutôt que conclure plus bas à un stockage défaillant.
  if printf '%s' "$body" | grep -qi 'too many failed login attempts'; then
    echo "tentative $attempt : 401 « Too many failed login attempts », l'ancien contrat de Lexik — le throttling fonctionne, mais l'image déployée ne répond pas le 429 de l'issue #399 (corps : $excerpt)" >&2
    exit 1
  fi
  echo "tentative $attempt : 401, pas encore freinée (corps : $excerpt)"
done

if [ "$throttled_at" -eq 0 ]; then
  echo "ÉCHEC : $attempts logins erronés sans jamais atteindre le throttling — l'état des limiteurs n'est pas persisté (ADR 0005, audit 2026-09-16 A1). Si les corps ci-dessus ne sont pas le JSON de l'application, le 401 vient d'ailleurs (Basic Auth de l'ingress ?)." >&2
  exit 1
fi

echo "OK : anti-brute-force du login effectif (freiné à la tentative $throttled_at)."
