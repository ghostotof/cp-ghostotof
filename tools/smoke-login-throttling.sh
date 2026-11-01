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
# Deux indices distinguent ce refus de celui de la zone nginx `login`
# (10 r/m, burst 10), qui rend lui aussi un 429 `/errors/rate-limited` :
# nginx ne pose pas de `Retry-After`, et son `detail` n'est pas celui de
# l'application. Un 429 de nginx ne prouve rien sur l'état des limiteurs de
# l'application, il fait donc échouer le test.
#
# Usage :  tools/smoke-login-throttling.sh <url-de-base>
#          ex. tools/smoke-login-throttling.sh https://preprod.cp-ghostotof.com
# Env :    la variable de la Basic Auth de préprod, optionnelle : voir tools/lib/smoke-http.sh (son nom, les
#          valeurs refusées, la sortie 2). Jamais via `-u` : les arguments d'un processus se lisent dans `ps` ;
#          le couple passe par un fichier de configuration curl en 600.
#          SMOKE_CURL        binaire curl de substitution (tests hors ligne).
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

# Ordre (D2 de la quatorzième relecture) : le `trap` (qui ne retire que le fichier déjà créé), puis la Basic Auth, puis
# seulement `date` et `mktemp` : la bibliothèque retire la variable du shell en la lisant, donc aucun fils ne la reçoit.
headers_file=""
trap '[ -z "$headers_file" ] || rm -f -- "$headers_file"; smoke_cleanup' EXIT

# Basic Auth : `smoke_use_auth_config` écrit le fichier `-K` et refuse (sortie 2) un secret vide ou creux ; `smoke_fetch` le lit
# et refuse, avec lui, une URL non https:// ou un argument hors de la liste d'options admises (tools/lib/smoke-http.sh).
smoke_use_auth_config AUDIT_BASIC_AUTH || exit 2

attempts=6
username="smoke-throttling-probe-$(date +%s)-$$"

# Les en-têtes de chaque réponse, pour y lire Retry-After.
headers_file="$(mktemp)"
SMOKE_CURL_OPTS=(-sS --connect-timeout 5 --max-time 15 -D "$headers_file")

# Le `detail` littéral de LoginRateLimitExceededException (backend) : le
# second discriminant face à la zone nginx, dont le 429 dit « Trop de
# requêtes ». ProblemDetailStaysStaticTest le garde constant côté PHP.
app_detail='"detail":"Trop de tentatives de connexion.'
# `\/` toléré : JsonResponse (Symfony) échappe les barres obliques, la zone
# nginx non — les deux formes valent le même JSON. ERE de bash, pas de grep :
# portable, et sans pipe que `pipefail` ferait échouer sur un SIGPIPE.
type_pattern='"type":"(\\)?/errors(\\)?/rate-limited"'

throttled_at=0
for attempt in $(seq 1 "$attempts"); do
  # Un curl sans réponse (sortie 1) ou un fichier de la Basic Auth absent (sortie 2) arrête le smoke en le disant.
  smoke_fetch "tentative $attempt" "$base/api/login_check" \
    -X POST \
    -H 'Content-Type: application/json' \
    -H 'X-Requested-With: fetch' \
    -d "{\"username\":\"$username\",\"password\":\"wrong-password-smoke\"}" || exit "$?"
  status="$SMOKE_STATUS"
  body="$SMOKE_BODY"

  # Le corps est toujours montré, tronqué : un 401 de la Basic Auth de
  # l'ingress (HTML nginx) et un 401 de l'application (JSON Lexik) se
  # ressemblent au code près, et c'est la première chose à savoir quand ce
  # test échoue. `smoke_excerpt` neutralise les caractères de contrôle (le texte
  # part dans le journal de la CI).
  excerpt="$(smoke_excerpt "$body")"

  if [ "$status" = "429" ]; then
    retry_after="$(smoke_header "$headers_file" Retry-After)"
    content_type="$(smoke_header "$headers_file" Content-Type)"
    if [ -z "$retry_after" ]; then
      echo "tentative $attempt : 429 sans Retry-After — c'est la zone nginx \`login\`, pas le login_throttling de l'application, et il ne prouve rien sur l'état des limiteurs (corps : $excerpt)" >&2
      exit 1
    fi
    if ! [[ "$retry_after" =~ ^[0-9]+$ ]] || [ "$retry_after" -le 0 ]; then
      echo "tentative $attempt : 429 avec un Retry-After invalide (« $(smoke_excerpt "$retry_after") »), un nombre de secondes > 0 attendu (corps : $excerpt)" >&2
      exit 1
    fi
    if [[ "$content_type" != application/problem+json* ]]; then
      echo "tentative $attempt : 429 en « $content_type », application/problem+json attendu (corps : $excerpt)" >&2
      exit 1
    fi
    if ! [[ "$body" =~ $type_pattern ]]; then
      echo "tentative $attempt : 429 sans le type /errors/rate-limited (corps : $excerpt)" >&2
      exit 1
    fi
    if [[ "$body" != *"$app_detail"* ]]; then
      echo "tentative $attempt : 429 avec Retry-After mais sans le detail de l'application — la zone nginx \`login\` relayée par un intermédiaire qui aurait ajouté l'en-tête ? Il ne prouve rien sur l'état des limiteurs (corps : $excerpt)" >&2
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
  if [[ "${body,,}" == *"too many failed login attempts"* ]]; then
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
