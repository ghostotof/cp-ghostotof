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
# ---------------------------------------------------------------------------
section "En-têtes de sécurité"
HEADERS="$(tr 'A-Z' 'a-z' <<< "$HEADERS_RAW")"

# Bloquants : sans risque de casse fonctionnelle, doivent toujours être
# présents en prod. content-security-policy est volontairement exclu de
# cette liste — mal calibrée elle casse le site (scripts/appels API bloqués),
# elle mérite un déploiement progressif en Report-Only avant de devenir un
# gate CI ; en attendant elle reste seulement informative.
for h in strict-transport-security x-content-type-options \
         referrer-policy permissions-policy x-frame-options cross-origin-opener-policy; do
  if grep -q "^${h}:" <<< "$HEADERS"; then
    printf '  \033[32mOK\033[0m      %s\n' "$h"
  else
    printf '  \033[31mABSENT\033[0m  %s   <-- à corriger !\n' "$h"
    FAILURES=$((FAILURES + 1))
  fi
done

if grep -q '^content-security-policy:' <<< "$HEADERS"; then
  printf '  \033[32mOK\033[0m      content-security-policy\n'
else
  printf '  \033[33mABSENT\033[0m  content-security-policy   (informatif, non bloquant)\n'
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
# 7. Chemins sensibles Symfony/Docker : ceux-ci DOIVENT renvoyer 404 en prod.
#    Un 200 sur /.env ou /_profiler serait une fuite critique.
# ---------------------------------------------------------------------------
section "Chemins sensibles (attendu : 404 partout)"
for path in /.env /.env.local /.git/config /_profiler /app_dev.php /index.php/_profiler \
            /composer.json /composer.lock /vendor/ /docker-compose.yml /phpinfo.php; do
  code="$(curl -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' "${BASE}${path}")"
  if [ "$code" = "404" ] || [ "$code" = "403" ]; then
    printf '  \033[32m%s\033[0m  %s\n' "$code" "$path"
  else
    printf '  \033[31m%s\033[0m  %s   <-- à vérifier !\n' "$code" "$path"
    FAILURES=$((FAILURES + 1))   # un chemin sensible exposé = échec bloquant
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
