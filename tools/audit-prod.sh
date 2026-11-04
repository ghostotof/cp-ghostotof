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
# Env :    la variable de la Basic Auth de préprod, optionnelle : voir tools/lib/smoke-http.sh (son nom, les
#          valeurs refusées, la sortie 2). Jamais via `-u` : les arguments d'un processus se lisent dans `ps` ;
#          le couple passe par un fichier de configuration curl en 600.
# ---------------------------------------------------------------------------

set -uo pipefail   # -u : variable non définie = erreur / -o pipefail : remonte l'erreur d'un pipe
                   # (pas de -e : on veut continuer même si un test échoue)

FAILURES=0   # compteur global, à déclarer en haut du script
# Vrai (1) quand l'audit porte la Basic Auth (préprod) : posé plus bas, après smoke_use_auth_config. Ce que dit l'indice du 401
# d'une sonde (section 3) lit ce drapeau, jamais SMOKE_AUTH_CONFIG, qu'une valeur héritée de l'environnement peut poser sans
# que l'audit porte quoi que ce soit (CR14-3).
audit_carries_basic_auth=0
DOMAIN="${1:-cp-ghostotof.com}"
BASE="https://${DOMAIN}"

# Chaque appel de curl commence par `-q` (R169, Q6) : curl ne lit alors aucun `.curlrc` (sous `CURL_HOME`, `XDG_CONFIG_HOME` ou
# `HOME`), qui pourrait porter `location-trusted` et faire suivre l'identifiant par une redirection. Il ne vaut qu'en premier
# argument (curl 8.5.0, mesuré) ; tools/tests/audit-prod.test.sh le lit sur chaque appel et sur le source, sondes anonymes comprises.
#
# Timeout court sur chaque requête : un domaine qui ne répond pas ne doit pas
# faire tourner le job en boucle jusqu'au timeout du runner CI.
CURL_OPTS=(--connect-timeout 5 --max-time 15)

# Les mêmes options, sans jamais la Basic Auth ci-dessous : pour les seuls appels
# qui doivent partir SANS elle, même en préprod. Deux lecteurs : les sondes de
# redirection (section 3, qui en dit la raison) et les métadonnées OAuth (section 6ter,
# qu'un client MCP lit sans savoir répondre à une Basic Auth).
CURL_OPTS_ANONYMOUS=("${CURL_OPTS[@]}")

# Préprod exige une Basic Auth (point de sécurité 2026-09-12) : sans elle,
# chaque requête de cet audit recevrait 401 au lieu du vrai contenu, et les
# sections 6/7 ci-dessous interpréteraient ce 401 comme des chemins
# sensibles exposés — des dizaines de faux échecs bloquants. La variable de la Basic Auth
# (« utilisateur:mot_de_passe », décrite dans tools/lib/smoke-http.sh) est optionnelle :
# absente contre la vraie prod (non protégée), fournie par le job audit-preprod du pipeline
# via le secret GitHub PREPROD_BASIC_AUTH.
#
# Jamais via `-u` : les arguments d'un processus sont lisibles dans `ps` par
# tout utilisateur de la machine pendant la requête (revue de sécurité du
# 2026-09-13, #78 pt 6). Le couple passe par un fichier de configuration curl
# (`-K`), créé en 600 dans un dossier privé que `smoke_cleanup` retire à la sortie ;
# seul son chemin apparaît dans les arguments. Les guillemets et antislashs sont
# échappés au format attendu par curl, donc n'importe quel mot de passe convient.
# Le fichier et son échappement sont dans tools/lib/smoke-http.sh, partagée avec les
# scripts de fumée.
#
# Trois cas, jugés sur le nom, jamais sur la valeur : `[[ -v … ]]` ne la développe pas,
# et développée ici, hors de tout `set +x`, un `bash -x` l'imprimerait (C8-1).
#   - non définie : l'audit de la vraie prod (non protégée). Il ne source pas la
#     bibliothèque et n'envoie aucune Basic Auth ;
#   - définie et vide : le secret de préprod manquant. `smoke_use_auth_config` sort 2
#     avant toute requête, faute de quoi chaque 401 de la préprod se lirait comme des
#     chemins exposés ;
#   - définie et pleine : le fichier `-K` est écrit une fois, ici, avant tout appel.
#     Un retour à la ligne (ou un CR) dans la valeur, l'absence de « : », un utilisateur
#     ou un mot de passe vide ou fait de blancs sont refusés par `smoke_use_auth_config` avant tout dossier
#     (sortie 2, celle d'un défaut d'environnement, comme une bibliothèque absente).
# Le `-K` va dans `CURL_OPTS` : l'audit n'appelle jamais `smoke_fetch`, ne réécrit pas
# le fichier, donc aucun `-K` n'y est périmé. `--proto =https` et `--proto-redir =https` l'accompagnent : un appel
# authentifié n'envoie sa première requête qu'à une URL en https (une URL sans schéma, que curl devine en
# `http://`, est refusée par curl lui-même) et ne suit une redirection (`-L`) que vers https, jamais vers http://
# ni un autre schéma, où l'identifiant partirait en clair ; sans `-K`, les trois options n'ont pas lieu d'être
# (les sondes de la section 3 visent des URL en `http://` exprès). `--noproxy '*'` les suit : un proxy d'environnement exporté
# après `smoke_use_auth_config` (la bibliothèque n'en retire qu'une fois) ne reçoit pas l'identifiant (R183, C15-4).
# Le `trap` précède la création du dossier : un arrêt entre `mktemp -d` et l'affectation que lit le `trap` peut laisser un dossier
# privé **vide**, jamais la valeur, qui n'est écrite qu'une fois le chemin connu du
# `trap` (#748, C7-8).
if [[ -v AUDIT_BASIC_AUTH ]]; then
  # Sortie 2 si la bibliothèque manque ou ne se lit pas (sans -e, un source en échec continuerait) ; stderr de bash visible.
  # Le dossier se calcule sans `dirname` (la variable est encore exportée ici, un fils la recevrait) : `cd` et `pwd` sont des
  # commandes internes de bash. `CDPATH=''` et `--` : ni un CDPATH hérité ni un dossier qui commence par `-` ne changent le
  # dossier trouvé ; un script à la racine (`_audit_dir` vide) vaut `/` (C14-11).
  _audit_dir="${BASH_SOURCE[0]}"
  case "$_audit_dir" in */*) _audit_dir="${_audit_dir%/*}" ;; *) _audit_dir=. ;; esac
  [ -n "$_audit_dir" ] || _audit_dir=/
  # shellcheck source=tools/lib/smoke-http.sh
  source "$(CDPATH='' cd -- "$_audit_dir" && pwd)/lib/smoke-http.sh" || {
    echo "bibliothèque introuvable ou invalide : tools/lib/smoke-http.sh" >&2
    exit 2
  }
  trap 'smoke_cleanup' EXIT
  smoke_use_auth_config AUDIT_BASIC_AUTH || exit 2
  CURL_OPTS+=(-K "$SMOKE_AUTH_CONFIG" --proto "=https" --proto-redir "=https" --noproxy '*')
  audit_carries_basic_auth=1
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
HEADERS_RAW="$(curl -q -sS "${CURL_OPTS[@]}" -D - -o /dev/null -L "$BASE")"
echo "$HEADERS_RAW"

# ---------------------------------------------------------------------------
# 3. Redirections : HTTP -> HTTPS et www -> apex (ou l'inverse).
#    Un site qui répond en 200 sur http:// sans redirect = point à corriger.
# ---------------------------------------------------------------------------
section "Redirections"
# Ces sondes partent sans Basic Auth (CURL_OPTS_ANONYMOUS) : curl envoie
# `Authorization: Basic` dès la première requête, quel que soit le schéma, donc
# l'identifiant de la préprod partirait en clair sur http://. Une redirection
# se juge sans authentification (#750). Conséquence : contre la préprod (l'audit porte la
# Basic Auth), l'hôte répond 401 à la sonde, ce que la ligne dit au lieu de passer pour une
# redirection cassée ; contre la vraie prod, un 401 s'imprime seul, sans l'indice.
for url in "http://${DOMAIN}" "http://www.${DOMAIN}" "https://www.${DOMAIN}"; do
  # Un seul appel par sonde : le code de la 1re réponse (%{http_code}) et la cible de son
  # Location (%{redirect_url}) viennent de la même réponse, jamais de deux requêtes
  # qui pourraient différer. La réponse est assignée, puis lue en deux champs.
  probe="$(curl -q -sS "${CURL_OPTS_ANONYMOUS[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' "$url")"
  read -r code target <<< "$probe"
  hint=""
  # `audit_carries_basic_auth` vaut 1 seulement si l'audit porte la Basic Auth (préprod) : l'indice du 401 en dépend.
  if [ "$code" = "401" ] && [ "$audit_carries_basic_auth" -eq 1 ]; then
    hint=" (l'hôte demande la Basic Auth : la sonde part sans elle, #750)"
  fi
  printf '%-32s -> %s %s%s\n' "$url" "$code" "$target" "$hint"
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

# Sortie 2 si la bibliothèque manque ou ne se lit pas (sans -e, un source en échec continuerait) ; stderr de bash visible.
# shellcheck source=tools/lib/security-headers.sh
source "$(CDPATH='' cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/security-headers.sh" || {
  echo "bibliothèque introuvable ou invalide : tools/lib/security-headers.sh" >&2
  exit 2
}
# Sortie 2 si la bibliothèque manque ou ne se lit pas (sans -e, un source en échec continuerait) ; stderr de bash visible.
# Section 5 ci-dessous : sans la sortie, la fonction manquerait, FAILURES ne
# compterait rien, et l'audit lancé hors du dépôt (sans tools/lib/) passerait au
# vert sans avoir rien vérifié.
# shellcheck source=tools/lib/tls-certificate.sh
source "$(CDPATH='' cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/tls-certificate.sh" || {
  echo "bibliothèque introuvable ou invalide : tools/lib/tls-certificate.sh" >&2
  exit 2
}

# En-têtes d'une URL, suivis d'une pseudo-ligne portant le code HTTP final.
# Le préfixe `x-audit-` ne peut entrer en collision avec aucun en-tête
# vérifié : les tests ci-dessous ancrent leurs grep sur `^<nom>:`.
fetch_headers() {
  curl -q -sS "${CURL_OPTS[@]}" -D - -o /dev/null -L -w 'x-audit-http-code: %{http_code}\n' "$1"
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
ASSET_PATH="$(grep -oE '/assets/[A-Za-z0-9._/-]+\.(js|css)' <<< "$(curl -q -sS "${CURL_OPTS[@]}" -L "$BASE")" | head -1)"
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
# 5. Certificat TLS : échéance, nom couvert, chaîne de confiance.
#    Le jugement vit dans tools/lib/tls-certificate.sh, partagé avec le
#    contrôle quotidien (tools/check-tls-expiry.sh, issue #719).
#
#    Ici un seuil fixe de 14 jours, plus bas que celui du contrôle quotidien,
#    et c'est voulu : cet audit est le gate d'audit-preprod, check requis de
#    `main`. Un seuil plus haut bloquerait une release quand le renouvellement
#    est en échec, celle du hotfix qui le répare comprise ; l'alerte précoce
#    est le rôle du contrôle quotidien. 14 reste sous le point de
#    renouvellement de cert-manager pour 90, 64 et 45 jours de vie (30, 21,
#    15) : un certificat sain ne l'atteint jamais.
# ---------------------------------------------------------------------------
section "Certificat TLS"
CERT="$(fetch_tls_certificate "$DOMAIN" "${DOMAIN}:443")"
openssl x509 -noout -subject -issuer <<< "$CERT"
check_tls_certificate "$DOMAIN" 14 "$CERT"

# ---------------------------------------------------------------------------
# 6. Fichiers publics attendus : leur absence est surtout un sujet SEO.
# ---------------------------------------------------------------------------
section "Fichiers publics"
for path in /robots.txt /sitemap.xml /favicon.ico /humans.txt; do
  printf '%-16s %s\n' "$path" "$(curl -q -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' "${BASE}${path}")"
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

SECURITY_TXT_BODY="$(curl -q -sS "${CURL_OPTS[@]}" -L "$SECURITY_TXT_URL")"

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
# date -d est GNU : le script tourne en CI (ubuntu-latest) et sur Linux du propriétaire.
# Sur macOS, -d veut dire autre chose ; gdate de GNU coreutils serait l'équivalent.
# Comportement accepté : hors périmètre (pas de détection d'OS ni de repli).
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
# 6ter. Métadonnées OAuth de la connexion MCP (#647) : BLOQUANT.
#
#    Le serveur MCP publie deux documents hors de /api, là où la norme les
#    place : la ressource protégée (RFC 9728) et le serveur d'autorisation
#    (RFC 8414). Servis par le backend, ils n'y arrivent que par l'Ingress
#    dédié (k8s/base/ingress-oauth-metadata.yaml) ; sans lui, le frontend
#    répond 404 et aucun client MCP ne trouve le serveur d'autorisation. Un
#    `issuer` ou une `resource` sur un autre hôte (APP_FRONTEND_BASE_URL ou
#    APP_PUBLIC_API_BASE_URL mal posée) ferait refuser les jetons par le
#    client, qui compare ces valeurs à l'octet près (RFC 8414 §3.3, RFC 9728
#    §3.3) : d'où l'égalité exacte avec `${BASE}/api/oauth` et
#    `${BASE}/api/mcp`, jamais un simple préfixe (une barre finale, un chemin
#    dérivé passeraient).
#
#    Lus SANS la Basic Auth de la préprod (CURL_OPTS_ANONYMOUS) : un client MCP
#    ne sait pas y répondre, et l'Ingress dédié n'en porte volontairement
#    aucune. Les lire avec elle masquerait précisément sa régression.
#    Sans -L : un document servi ailleurs après une redirection n'est pas
#    celui que le client attend à cette adresse.
#
#    Le JSON est lu par jq (préinstallé sur ubuntu-latest) : Symfony échappe
#    les barres (`https:\/\/…`), une lecture par grep comparerait n'importe
#    quoi. Absent, c'est un échec franc, jamais un saut.
#
#    Aucun document OpenID Connect n'est servi (le serveur n'émet pas de jeton
#    d'identité) : /.well-known/openid-configuration doit rester le 404 du
#    frontend. Celui-là passe par l'Ingress principal, donc avec la Basic
#    Auth en préprod (sans elle, ce serait un 401).
# ---------------------------------------------------------------------------
section "Métadonnées OAuth de la connexion MCP (RFC 9728, RFC 8414)"

# check_oauth_metadata <chemin> <champ> <valeur attendue> : 200,
# `application/json`, et le champ (`resource` ou `issuer`) vaut exactement la
# valeur attendue.
check_oauth_metadata() {
  local path="$1" field="$2" expected="$3" response meta body code content_type value
  # Code et type à la suite du corps, sur une dernière ligne à part.
  response="$(curl -q -sS "${CURL_OPTS_ANONYMOUS[@]}" -w '\n%{http_code} %{content_type}' "${BASE}${path}")"
  meta="${response##*$'\n'}"
  body="${response%$'\n'*}"
  read -r code content_type <<< "$meta"
  content_type="$(tr '[:upper:]' '[:lower:]' <<< "${content_type:-}")"

  if [ "$code" = "200" ]; then
    printf '  \033[32mOK\033[0m      HTTP 200 sur %s\n' "$path"
  else
    printf '  \033[31mECHEC\033[0m   HTTP %s sur %s   <-- attendu 200, sans Basic Auth !\n' "${code:-?}" "$path"
    FAILURES=$((FAILURES + 1))
    return
  fi

  # `application/json` seul ou suivi de paramètres : jamais un préfixe nu, qui
  # admettrait `application/json-patch+json`.
  case "$content_type" in
    application/json|application/json\;*)
      printf '  \033[32mOK\033[0m      Content-Type: %s\n' "$content_type" ;;
    *)
      printf '  \033[31mECHEC\033[0m   Content-Type: %s   <-- application/json attendu !\n' "${content_type:-absent}"
      FAILURES=$((FAILURES + 1)) ;;
  esac

  if ! command -v jq > /dev/null; then
    printf '  \033[31mECHEC\033[0m   jq absent : champ %s non vérifié   <-- installer jq !\n' "$field"
    FAILURES=$((FAILURES + 1))
    return
  fi
  if ! value="$(jq -er --arg field "$field" '.[$field] | strings' <<< "$body" 2> /dev/null)"; then
    printf '  \033[31mECHEC\033[0m   champ %s absent, non textuel ou JSON illisible   <-- à vérifier !\n' "$field"
    FAILURES=$((FAILURES + 1))
    return
  fi
  # Comparaison de chaînes, à l'octet près, comme le client.
  if [ "$value" = "$expected" ]; then
    printf '  \033[32mOK\033[0m      %s: %s\n' "$field" "$value"
  else
    printf '  \033[31mECHEC\033[0m   %s: %s   <-- attendu exactement %s !\n' "$field" "$value" "$expected"
    FAILURES=$((FAILURES + 1))
  fi
}

check_oauth_metadata /.well-known/oauth-protected-resource/api/mcp resource "${BASE}/api/mcp"
check_oauth_metadata /.well-known/oauth-authorization-server/api/oauth issuer "${BASE}/api/oauth"

OIDC_CODE="$(curl -q -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' "${BASE}/.well-known/openid-configuration")"
if [ "$OIDC_CODE" = "404" ]; then
  printf '  \033[32mOK\033[0m      HTTP 404 sur /.well-known/openid-configuration (aucun document OIDC)\n'
else
  printf '  \033[31mECHEC\033[0m   HTTP %s sur /.well-known/openid-configuration   <-- attendu 404 !\n' "${OIDC_CODE:-?}"
  FAILURES=$((FAILURES + 1))
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
BASE_SIZE="$(curl -q -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{size_download}' -L "$BASE")"
for path in /.env /.env.local /.git/config /_profiler /app_dev.php /index.php/_profiler \
            /composer.json /composer.lock /vendor/ /docker-compose.yml /phpinfo.php; do
  read -r code size <<< "$(curl -q -sS "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code} %{size_download}' "${BASE}${path}")"
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
BODY="$(curl -q -sS "${CURL_OPTS[@]}" -L "$BASE")"
echo "Taille du HTML : $(wc -c <<< "$BODY") octets"
echo "Balises <script> : $(grep -o '<script' <<< "$BODY" | wc -l)"
echo "Texte visible approximatif :"
sed -e 's/<[^>]*>//g' <<< "$BODY" | tr -s '[:space:]' ' ' | cut -c1-300

# ---------------------------------------------------------------------------
# 9. Compression et cache des assets : perf.
# ---------------------------------------------------------------------------
section "Compression / cache"
curl -q -sS "${CURL_OPTS[@]}" -D - -o /dev/null -H 'Accept-Encoding: gzip, br' -L "$BASE" \
  | grep -iE '^(content-encoding|cache-control|etag|last-modified|vary)' || echo "  aucun en-tête de cache/compression"

echo -e "\nTerminé."

if [ "$FAILURES" -gt 0 ]; then
  echo "Échecs bloquants : $FAILURES"
  exit 1        # la CI/CD marque le job en rouge
fi
exit 0
