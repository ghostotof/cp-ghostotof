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
# de passe : chacun doit répondre 401, et le sixième doit porter le message
# « Too many failed login attempts » (c'est ce que le failure handler Lexik
# renvoie, en 401 et non 429 — tests/Security/Authentication/LoginThrottlingTest
# pince ce contrat côté PHPUnit). L'identifiant est unique par exécution, pour
# que le compteur local reparte de zéro ; le compteur global par IP (25)
# tolère quatre exécutions par quart d'heure depuis un même runner, et une
# cinquième ne ferait que produire « Too many » plus tôt — ce que ce script
# accepte aussi.
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

curl_opts=(-sS --connect-timeout 5 --max-time 15)
if [ -n "${AUDIT_BASIC_AUTH:-}" ]; then
  auth_config="$(mktemp)"
  trap 'rm -f "$auth_config"' EXIT
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

  if [ "$status" != "401" ]; then
    echo "tentative $attempt : $status reçu, 401 attendu (corps : ${body:0:200})" >&2
    exit 1
  fi

  if printf '%s' "$body" | grep -qi 'too many failed login attempts'; then
    throttled_at="$attempt"
    echo "tentative $attempt : throttling actif (« Too many failed login attempts »)"
    break
  fi
  echo "tentative $attempt : 401, pas encore freinée"
done

if [ "$throttled_at" -eq 0 ]; then
  echo "ÉCHEC : $attempts logins erronés sans jamais atteindre le throttling — l'état des limiteurs n'est pas persisté (ADR 0005, audit 2026-09-16 A1)." >&2
  exit 1
fi

echo "OK : anti-brute-force du login effectif (freiné à la tentative $throttled_at)."
