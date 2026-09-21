#!/usr/bin/env bash
#
# audit-prod.sh
# ---------------------------------------------------------------------------
# Audit "boîte noire" rapide d'un site en prod, à lancer depuis ta machine
# (ou en CI). Ne fait QUE des requêtes en lecture (GET / HEAD) : aucun test
# intrusif, aucune injection, aucun bruteforce. À n'utiliser que sur TES
# domaines.
#
# Usage :  ./tools/audit-prod.sh cp-ghostotof.com
# ---------------------------------------------------------------------------

set -uo pipefail   # -u : variable non définie = erreur / -o pipefail : remonte l'erreur d'un pipe
                   # (pas de -e : on veut continuer même si un test échoue)

FAILURES=0   # compteur global, à déclarer en haut du script
DOMAIN="${1:-cp-ghostotof.com}"
BASE="https://${DOMAIN}"

# Timeout court sur chaque requête : un domaine qui ne répond pas ne doit pas
# faire tourner le job en boucle jusqu'au timeout du runner CI.
CURL_OPTS=(--connect-timeout 5 --max-time 15)

# Préprod exige une Basic Auth (point de sécurité 2026-09-12) : sans elle,
# chaque requête de cet audit recevrait 401 au lieu du vrai contenu, et les
# sections 6/7 ci-dessous interpréteraient ce 401 comme des chemins
# sensibles exposés — des dizaines de faux échecs bloquants. AUDIT_BASIC_AUTH
# ("utilisateur:mot_de_passe") est optionnelle : absente contre la vraie
# prod (non protégée), fournie par le job audit-preprod du pipeline via le
# secret GitHub PREPROD_BASIC_AUTH.
#
# Jamais via `-u` : les arguments d'un processus sont lisibles dans `ps` par
# tout utilisateur de la machine pendant la requête (revue de sécurité du
# 2026-09-13, #78 pt 6). Le couple passe par un fichier de configuration curl
# (`-K`), créé en 600 par mktemp et supprimé à la sortie ; seul son chemin
# apparaît dans les arguments. Les guillemets et antislashs sont échappés au
# format attendu par curl, donc n'importe quel mot de passe convient.
if [ -n "${AUDIT_BASIC_AUTH:-}" ]; then
  CURL_AUTH_CONFIG="$(mktemp)"
  trap 'rm -f "$CURL_AUTH_CONFIG"' EXIT
  printf 'user = "%s"\n' "$(printf '%s' "$AUDIT_BASIC_AUTH" | sed 's/\\/\\\\/g; s/"/\\"/g')" > "$CURL_AUTH_CONFIG"
  CURL_OPTS+=(-K "$CURL_AUTH_CONFIG")
fi
DIG_TIMEOUT=(+time=3 +tries=1)

# Petit helper d'affichage pour séparer visuellement les sections
section() { printf '\n\033[1;36m=== %s ===\033[0m\n' "$1"; }

# ---------------------------------------------------------------------------
# 1. DNS : où pointe réellement le domaine ?
#    Utile pour vérifier que l'apex et le www pointent bien où tu crois
#    (LoadBalancer Scaleway, ingress k8s, etc.)
# ---------------------------------------------------------------------------
section "DNS"
dig "${DIG_TIMEOUT[@]}" +short "$DOMAIN" A
dig "${DIG_TIMEOUT[@]}" +short "$DOMAIN" AAAA          # IPv6 : absent = pas grave, mais bon à savoir
dig "${DIG_TIMEOUT[@]}" +short "www.${DOMAIN}" CNAME A
dig "${DIG_TIMEOUT[@]}" +short "$DOMAIN" CAA           # CAA : restreint quelles AC peuvent émettre un cert
dig "${DIG_TIMEOUT[@]}" +short "$DOMAIN" TXT | head -5 # SPF / vérifications diverses

# ---------------------------------------------------------------------------
# 2. En-têtes HTTP : le cœur de l'audit.
#    -s silencieux, -S affiche les erreurs, -D - dump les headers sur stdout,
#    -o /dev/null jette le body, -L suit les redirections.
# ---------------------------------------------------------------------------
section "En-têtes HTTP (avec redirections)"
HEADERS_RAW="$(curl -sS "${CURL_OPTS[@]}" -D - -o /dev/null -L "$BASE")"
echo "$HEADERS_RAW"

# ---------------------------------------------------------------------------
# 3. Redirections : HTTP -> HTTPS et www -> apex (ou l'inverse).
#    Un site qui répond en 200 sur http:// sans redirect = point à corriger.
# ---------------------------------------------------------------------------
section "Redirections"
for url in "http://${DOMAIN}" "http://www.${DOMAIN}" "https://www.${DOMAIN}"; do
  # %{http_code} = code de la 1re réponse, %{redirect_url} = cible du Location
  printf '%-32s -> %s %s\n' "$url" \
    "$(curl -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' "$url")" \
    "$(curl -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{redirect_url}' "$url")"
done

# ---------------------------------------------------------------------------
# 4. En-têtes de sécurité : on liste ce qui est PRÉSENT, puis ce qui MANQUE.
#
#    Sur PLUSIEURS réponses, pas seulement la page d'accueil. C'est le constat
#    A16 (audit du 2026-09-16) : en nginx, un bloc `location` qui déclare son
#    propre add_header n'hérite plus d'AUCUN add_header du bloc server. Les
#    locations qui posent un Cache-Control (/assets/, /config.js) ou un
#    Content-Type (/healthz) sortaient donc sans CSP ni HSTS, pendant que `/`
#    les portait — un audit mené sur la seule page d'accueil ne pouvait pas le
#    voir, et c'est exactement ce que cette section corrige.
# ---------------------------------------------------------------------------

# Liste unique, attendue sur CHACUNE des réponses vérifiées ci-dessous.
# content-security-policy est en mode enforce (plus Report-Only) sur les deux
# nginx : c'est donc un gate CI comme les autres.
SECURITY_HEADERS=(strict-transport-security x-content-type-options
                  referrer-policy permissions-policy x-frame-options
                  cross-origin-opener-policy content-security-policy)

# En-têtes d'une URL, suivis d'une pseudo-ligne portant le code HTTP final.
# Le préfixe `x-audit-` ne peut entrer en collision avec aucun en-tête
# vérifié : les tests ci-dessous ancrent leurs grep sur `^<nom>:`.
fetch_headers() {
  curl -sS "${CURL_OPTS[@]}" -D - -o /dev/null -L -w 'x-audit-http-code: %{http_code}\n' "$1"
}

# $1 = libellé affiché, $2 = sortie brute de fetch_headers.
check_security_headers() {
  local label="$1" raw headers code h
  raw="$(tr 'A-Z' 'a-z' <<< "$2")"
  # Les en-têtes de la réponse finale seulement : avec -L, `curl -D -` empile
  # aussi ceux des redirections traversées, et une 308 de l'ingress porte, elle,
  # ses propres en-têtes — de quoi valider une réponse qui, elle, n'en a aucun.
  # (Pas d'intervalle `{3}` dans le motif awk : mawk, l'awk par défaut du
  # runner CI, ne les a pas toujours supportés.)
  headers="$(awk '/^http\/[0-9.]+ [0-9][0-9][0-9]/ { buf = "" } { buf = buf $0 "\n" } END { printf "%s", buf }' <<< "$raw")"
  # Code de la réponse finale : la pseudo-ligne de fetch_headers si elle est
  # là, sinon la ligne de statut — la fonction reste utilisable sur un dump
  # d'en-têtes obtenu autrement (c'est le cas pour `/`, dont le dump sert aussi
  # à la section « Fuites d'information »).
  code="$(sed -n 's/^x-audit-http-code: //p' <<< "$raw" | tail -1)"
  [ -n "$code" ] || code="$(sed -n 's|^http/[0-9.]* \([0-9][0-9][0-9]\).*|\1|p' <<< "$headers" | tail -1)"

  printf '  \033[1m%s\033[0m  (HTTP %s)\n' "$label" "${code:-?}"
  for h in "${SECURITY_HEADERS[@]}"; do
    if grep -q "^${h}:" <<< "$headers"; then
      printf '    \033[32mOK\033[0m      %s\n' "$h"
    else
      printf '    \033[31mABSENT\033[0m  %s   <-- à corriger !\n' "$h"
      FAILURES=$((FAILURES + 1))
    fi
  done

  # Un en-tête Content-Security-Policy-Report-Only résiduel (sans le CSP
  # enforce) signalerait un retour en arrière non intentionnel.
  if grep -q '^content-security-policy-report-only:' <<< "$headers" \
     && ! grep -q '^content-security-policy:' <<< "$headers"; then
    printf '    \033[31mReport-Only SEUL\033[0m  content-security-policy   <-- enforce attendu !\n'
    FAILURES=$((FAILURES + 1))
  fi
}

section "En-têtes de sécurité"
HEADERS="$(tr 'A-Z' 'a-z' <<< "$HEADERS_RAW")"
check_security_headers "/  (document SPA)" "$HEADERS_RAW"

# config.js : régénéré au démarrage du conteneur (docker/node/docker-entrypoint.sh),
# servi no-store. C'est ce Cache-Control qui coupait l'héritage des en-têtes.
check_security_headers "/config.js" "$(fetch_headers "${BASE}/config.js")"

# Sonde de vivacité : servie par un `return 200` qui pose son propre
# Content-Type — même cause, même effet.
check_security_headers "/healthz" "$(fetch_headers "${BASE}/healthz")"

# Un asset RÉEL, découvert dans la page d'accueil. Jamais un nom en dur : Vite
# place un hash de contenu dans chaque nom de fichier, un chemin figé ici
# cesserait de correspondre à quoi que ce soit dès le déploiement suivant et
# vérifierait alors les en-têtes d'un 404 en croyant vérifier ceux d'un asset.
ASSET_PATH="$(grep -oE '/assets/[A-Za-z0-9._/-]+\.(js|css)' <<< "$(curl -sS "${CURL_OPTS[@]}" -L "$BASE")" | head -1)"
if [ -n "$ASSET_PATH" ]; then
  check_security_headers "$ASSET_PATH" "$(fetch_headers "${BASE}${ASSET_PATH}")"
else
  # Échec bloquant, et non un saut silencieux : ne pas avoir trouvé d'asset
  # n'est pas la même chose que ne pas en avoir cherché. Une page d'accueil
  # sans référence /assets/… signale de toute façon un déploiement cassé.
  printf '  \033[31mINTROUVABLE\033[0m  aucun /assets/… référencé par la page daccueil   <-- à vérifier !\n'
  FAILURES=$((FAILURES + 1))
fi

# En-têtes qui en disent trop sur la stack (à masquer côté nginx/Symfony)
section "Fuites d'information (server_tokens, X-Powered-By...)"
grep -E '^(server|x-powered-by|x-aspnet|x-generator|x-debug-token)' <<< "$HEADERS" || echo "  rien de bavard, bien"

# ---------------------------------------------------------------------------
# 5. Certificat TLS : date d'expiration + chaîne.
#    Important si tu gères le renouvellement toi-même (cert-manager, Let's Encrypt).
# ---------------------------------------------------------------------------
section "Certificat TLS"
CERT="$(echo | timeout 10 openssl s_client -connect "${DOMAIN}:443" -servername "$DOMAIN" 2>/dev/null)"
openssl x509 -noout -subject -issuer -dates <<< "$CERT"

# cert-manager/Let's Encrypt renouvelle normalement ~30 jours avant expiration :
# moins de 14 jours de validité restante signale un renouvellement en échec.
CERT_MIN_VALIDITY_DAYS=14
if openssl x509 -noout -checkend "$((CERT_MIN_VALIDITY_DAYS * 86400))" <<< "$CERT" > /dev/null 2>&1; then
  printf '  \033[32mOK\033[0m      valide plus de %s jours\n' "$CERT_MIN_VALIDITY_DAYS"
else
  printf '  \033[31mALERTE\033[0m  expire dans moins de %s jours — renouvellement à vérifier !\n' "$CERT_MIN_VALIDITY_DAYS"
  FAILURES=$((FAILURES + 1))
fi

# ---------------------------------------------------------------------------
# 6. Fichiers publics attendus : leur absence est surtout un sujet SEO.
# ---------------------------------------------------------------------------
section "Fichiers publics"
for path in /robots.txt /sitemap.xml /favicon.ico /humans.txt; do
  printf '%-16s %s\n' "$path" "$(curl -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' "${BASE}${path}")"
done

# ---------------------------------------------------------------------------
# 6bis. security.txt (RFC 9116) : le canal de signalement publié (constat A14).
#
#    Contrairement à la section 6, celle-ci est BLOQUANTE. Un security.txt
#    absent laisse un chercheur ouvrir une issue publique sur une faille
#    exploitable ; un security.txt périmé est pire, puisqu'il donne un canal
#    que son lecteur croit valide. La RFC borne d'ailleurs la validité par un
#    champ obligatoire, Expires : le vérifier activement ici est ce qui
#    transforme « il faudra penser à le renouveler » en un gate de pipeline.
#
#    Le fichier est servi par la location `^~ /.well-known/` du nginx frontend
#    (docker/node/nginx.conf), qui fait `try_files $uri =404` : un fichier
#    manquant donne un vrai 404, jamais le fallback SPA. On peut donc se fier
#    au code HTTP sans la ruse de comparaison de taille de la section 7.
# ---------------------------------------------------------------------------
section "security.txt (RFC 9116)"
SECURITY_TXT_URL="${BASE}/.well-known/security.txt"
SECURITY_TXT_RAW="$(tr 'A-Z' 'a-z' <<< "$(fetch_headers "$SECURITY_TXT_URL")")"
SECURITY_TXT_CODE="$(sed -n 's/^x-audit-http-code: //p' <<< "$SECURITY_TXT_RAW" | tr -d '\r' | tail -1)"
# `tail -1` : avec -L, le dump empile les en-têtes des redirections traversées
# (une 308 de l'ingress porte son propre Content-Type) — seule la dernière
# réponse compte, même raison que dans check_security_headers.
SECURITY_TXT_TYPE="$(sed -n 's/^content-type:[[:space:]]*//p' <<< "$SECURITY_TXT_RAW" | tr -d '\r' | tail -1)"

if [ "$SECURITY_TXT_CODE" = "200" ]; then
  printf '  \033[32mOK\033[0m      HTTP 200 sur /.well-known/security.txt\n'
else
  printf '  \033[31mECHEC\033[0m   HTTP %s sur /.well-known/security.txt   <-- attendu 200 !\n' "${SECURITY_TXT_CODE:-?}"
  FAILURES=$((FAILURES + 1))
fi

# La RFC impose text/plain. Un application/octet-stream (type par défaut quand
# nginx ne reconnaît pas l'extension) ferait télécharger le fichier au lieu de
# l'afficher, et plusieurs outils d'analyse le refusent alors purement et
# simplement. Le paramètre charset éventuel est ignoré par ce test.
case "$SECURITY_TXT_TYPE" in
  text/plain*)
    printf '  \033[32mOK\033[0m      Content-Type: %s\n' "$SECURITY_TXT_TYPE" ;;
  *)
    printf '  \033[31mECHEC\033[0m   Content-Type: %s   <-- text/plain attendu !\n' "${SECURITY_TXT_TYPE:-absent}"
    FAILURES=$((FAILURES + 1)) ;;
esac

SECURITY_TXT_BODY="$(curl -sS "${CURL_OPTS[@]}" -L "$SECURITY_TXT_URL")"

# Champs obligatoires de la RFC 9116. Les noms de champs y sont
# insensibles à la casse, d'où le grep -i, ancré en début de ligne pour ne pas
# compter une occurrence citée dans un commentaire.
if grep -qiE '^contact:[[:space:]]*[^[:space:]]' <<< "$SECURITY_TXT_BODY"; then
  printf '  \033[32mOK\033[0m      champ Contact présent\n'
else
  printf '  \033[31mECHEC\033[0m   champ Contact absent   <-- obligatoire (RFC 9116) !\n'
  FAILURES=$((FAILURES + 1))
fi

SECURITY_TXT_EXPIRES="$(grep -iE '^expires:' <<< "$SECURITY_TXT_BODY" | head -1 \
  | sed -e 's/^[Ee][Xx][Pp][Ii][Rr][Ee][Ss]:[[:space:]]*//' -e 's/[[:space:]]*$//' | tr -d '\r')"
if [ -z "$SECURITY_TXT_EXPIRES" ]; then
  printf '  \033[31mECHEC\033[0m   champ Expires absent   <-- obligatoire (RFC 9116) !\n'
  FAILURES=$((FAILURES + 1))
elif ! SECURITY_TXT_EXPIRES_TS="$(date -u -d "$SECURITY_TXT_EXPIRES" +%s 2>/dev/null)"; then
  # Une date que `date` ne sait pas lire n'est pas une date valide au sens de
  # la RFC (horodatage ISO 8601) : échec franc plutôt que saut silencieux.
  printf '  \033[31mECHEC\033[0m   Expires illisible : %s   <-- horodatage ISO 8601 attendu !\n' "$SECURITY_TXT_EXPIRES"
  FAILURES=$((FAILURES + 1))
elif [ "$SECURITY_TXT_EXPIRES_TS" -le "$(date -u +%s)" ]; then
  printf '  \033[31mECHEC\033[0m   Expires dépassé : %s   <-- fichier à renouveler !\n' "$SECURITY_TXT_EXPIRES"
  FAILURES=$((FAILURES + 1))
else
  printf '  \033[32mOK\033[0m      Expires: %s (encore %s jours)\n' "$SECURITY_TXT_EXPIRES" \
    "$(( (SECURITY_TXT_EXPIRES_TS - $(date -u +%s)) / 86400 ))"
fi

# ---------------------------------------------------------------------------
# 7. Chemins sensibles Symfony/Docker : ceux-ci DOIVENT renvoyer 404 en prod.
#    Un 200 sur /.env ou /_profiler serait une fuite critique.
#
#    Piège SPA (Vue Router en mode history, pas de SSR) : le fallback
#    `try_files ... /index.html` renvoie 200 avec le shell générique de
#    l'app pour N'IMPORTE QUELLE URL inconnue — /.env y compris — sans que
#    le fichier existe ni ne soit lu. Un simple test de code HTTP donnerait
#    donc un faux positif sur chaque chemin de cette liste. On compare la
#    taille du corps à celle de la page d'accueil : taille identique = même
#    page SPA générique renvoyée pour tout, pas une fuite ; taille
#    différente sur un 200 = contenu réellement distinct à traiter en
#    priorité (ex. vrai fichier .env, page phpinfo()...).
# ---------------------------------------------------------------------------
section "Chemins sensibles (attendu : 404, ou le même fallback SPA que /)"
BASE_SIZE="$(curl -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{size_download}' -L "$BASE")"
for path in /.env /.env.local /.git/config /_profiler /app_dev.php /index.php/_profiler \
            /composer.json /composer.lock /vendor/ /docker-compose.yml /phpinfo.php; do
  read -r code size <<< "$(curl -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code} %{size_download}' "${BASE}${path}")"
  if [ "$code" = "404" ] || [ "$code" = "403" ]; then
    printf '  \033[32m%s\033[0m  %s\n' "$code" "$path"
  elif [ "$code" = "200" ] && [ "$size" = "$BASE_SIZE" ]; then
    printf '  \033[33m%s\033[0m  %s   (identique à /, fallback SPA généraliste — pas une fuite)\n' "$code" "$path"
  else
    printf '  \033[31m%s\033[0m  %s   <-- à vérifier !\n' "$code" "$path"
    FAILURES=$((FAILURES + 1))   # contenu distinct sur un 200 = échec bloquant
  fi
done

# ---------------------------------------------------------------------------
# 8. Contenu servi : combien d'octets de HTML réel avant exécution du JS ?
#    Pour une SPA Vue non SSR, on trouve souvent une div vide -> impact SEO.
# ---------------------------------------------------------------------------
section "Contenu HTML brut (avant JS)"
BODY="$(curl -sS "${CURL_OPTS[@]}" -L "$BASE")"
echo "Taille du HTML : $(wc -c <<< "$BODY") octets"
echo "Balises <script> : $(grep -o '<script' <<< "$BODY" | wc -l)"
echo "Texte visible approximatif :"
sed -e 's/<[^>]*>//g' <<< "$BODY" | tr -s '[:space:]' ' ' | cut -c1-300

# ---------------------------------------------------------------------------
# 9. Compression et cache des assets : perf.
# ---------------------------------------------------------------------------
section "Compression / cache"
curl -sS "${CURL_OPTS[@]}" -D - -o /dev/null -H 'Accept-Encoding: gzip, br' -L "$BASE" \
  | grep -iE '^(content-encoding|cache-control|etag|last-modified|vary)' || echo "  aucun en-tête de cache/compression"

echo -e "\nTerminé."

if [ "$FAILURES" -gt 0 ]; then
  echo "Échecs bloquants : $FAILURES"
  exit 1        # la CI/CD marque le job en rouge
fi
exit 0
