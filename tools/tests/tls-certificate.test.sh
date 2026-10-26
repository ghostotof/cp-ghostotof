#!/usr/bin/env bash
#
# tls-certificate.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/lib/tls-certificate.sh (check_tls_certificate) et
# de tools/check-tls-expiry.sh (issue #719). Les certificats sont signés par
# une autorité de test générée par openssl dans un répertoire temporaire ; le
# script complet est exercé contre des `openssl s_server` lancés sur
# 127.0.0.1, l'autorité de test lui étant donnée par SSL_CERT_FILE (variable
# standard d'OpenSSL : le magasin de confiance par défaut). Aucun appel au
# réseau extérieur, aucune écriture dans le dépôt courant.
#
# Usage :  tools/tests/tls-certificate.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LIB="$ROOT/tools/lib/tls-certificate.sh"
SCRIPT="$ROOT/tools/check-tls-expiry.sh"
TMP="$(mktemp -d)"
SERVER_PIDS=()
cleanup() {
  local pid
  for pid in "${SERVER_PIDS[@]}"; do kill "$pid" 2> /dev/null || true; done
  rm -rf "$TMP"
}
trap cleanup EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

for required in "$LIB" "$SCRIPT"; do
  if [ ! -f "$required" ]; then
    echo "  FAIL $required introuvable"
    exit 1
  fi
done

# --- Autorité de test et certificats -------------------------------------------
# `openssl ca` plutôt que `x509 -req -days` : il fixe le début ET la fin de
# validité (-startdate/-enddate), ce qu'il faut pour juger un seuil relatif à
# la durée de vie, et pour un certificat déjà expiré (`-days` refuse les
# valeurs négatives ; `x509 -not_after` date d'OpenSSL 3.4, le runner CI est
# en 3.0).
mkdir -p "$TMP/ca/newcerts"
: > "$TMP/ca/index.txt"
echo 01 > "$TMP/ca/serial"
cat > "$TMP/ca/ca.cnf" <<EOF
[ ca ]
default_ca = test
[ test ]
dir = $TMP/ca
database = \$dir/index.txt
new_certs_dir = \$dir/newcerts
serial = \$dir/serial
unique_subject = no
default_md = sha256
policy = anything
copy_extensions = copy
x509_extensions = leaf
[ anything ]
commonName = supplied
[ leaf ]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
EOF
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=AC de test cp-ghostotof" \
  -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
  -keyout "$TMP/ca.key" -out "$TMP/ca.pem" 2> /dev/null

# when <décalage> : horodatage `openssl ca` à <décalage> de maintenant, au
# format de `date -d` (« -30 days », « 60 days -1 second »).
when() { date -u -d "$1" +%Y%m%d%H%M%SZ; }

# make_cert <nom> <début> <fin> <nom DNS> : certificat signé par l'autorité
# de test, le nom DNS en CN et en subjectAltName (ce que lit -checkhost),
# valable de <début> à <fin>, décalages de `when`.
make_cert() {
  openssl req -newkey rsa:2048 -nodes -subj "/CN=$4" -addext "subjectAltName=DNS:$4" \
    -keyout "$TMP/$1.key" -out "$TMP/$1.csr" 2> /dev/null
  openssl ca -batch -config "$TMP/ca/ca.cnf" -cert "$TMP/ca.pem" -keyfile "$TMP/ca.key" \
    -in "$TMP/$1.csr" -out "$TMP/$1.pem" -notext \
    -startdate "$(when "$2")" -enddate "$(when "$3")" 2> /dev/null
}

# Durée de vie 90 jours (celle de Let's Encrypt jusqu'au 2027-02-10) :
# renouvellement attendu à 30 jours, seuil relatif 30 − 5 = 25.
make_cert sain "-30 days" "60 days" site.example
make_cert proche "-70 days" "20 days" site.example
make_cert autre "-30 days" "60 days" autre.example
make_cert expire "-100 days" "-10 days" site.example
# Durée de vie 64 jours (Let's Encrypt à partir du 2027-02-10) :
# renouvellement attendu à 21,3 jours, seuil relatif 16. À 18 jours, un
# certificat sain ; un seuil fixe de 25 l'aurait dit en échec.
make_cert court "-46 days" "18 days" site.example
make_cert court_en_echec "-49 days" "15 days" site.example
# Comme Let's Encrypt : notAfter = notBefore + 90 jours − 1 seconde. Arrondie
# vers le bas, la durée de vie lue serait 89 jours et le seuil 24.
make_cert letsencrypt "-30 days" "60 days -1 second" site.example
# Autosigné, du bon nom et loin de l'échéance : seule la chaîne le trahit.
openssl req -x509 -newkey rsa:2048 -nodes -days 60 -subj "/CN=site.example" \
  -addext "subjectAltName=DNS:site.example" \
  -keyout "$TMP/autosigne.key" -out "$TMP/autosigne.pem" 2> /dev/null
: > "$TMP/vide.pem"

# run <hôte> <seuil> <fichier> [code de vérification] : appelle
# check_tls_certificate dans le shell courant (jamais via `$(...)`, qui
# perdrait FAILURES dans une sous-shell) sur le PEM suivi de la ligne de
# vérification qu'écrit `openssl s_client`, et laisse la sortie, débarrassée
# des séquences ANSI, dans $TMP/out. Le code par défaut, 0, isole les autres
# verdicts de celui de la chaîne.
run() {
  local input
  input="$(cat "$3" 2> /dev/null)"
  [ -z "$input" ] || input="$input"$'\n'"    Verify return code: ${4:-0} (${5:-ok})"
  check_tls_certificate "$1" "$2" "$input" > "$TMP/raw"
  sed 's/\x1b\[[0-9;]*m//g' "$TMP/raw" > "$TMP/out"
}

# expect <libellé> <FAILURES attendu> <motif ERE>... : chaque motif doit
# apparaître dans la sortie.
expect() {
  local label="$1" want="$2" pattern
  shift 2
  if [ "$FAILURES" -ne "$want" ]; then
    fail "$label" "FAILURES=$FAILURES, attendu $want ; sortie : $(cat "$TMP/out")"
    return
  fi
  for pattern in "$@"; do
    if ! grep -Eq "$pattern" "$TMP/out"; then
      fail "$label" "motif « $pattern » absent ; sortie : $(cat "$TMP/out")"
      return
    fi
  done
  pass "$label"
}

# shellcheck source=tools/lib/tls-certificate.sh
source "$LIB"

echo "tls-certificate.sh — seuil relatif à la durée de vie (auto)"

FAILURES=0
run site.example auto "$TMP/sain.pem"
expect "90 jours de vie, 60 restants → sain" 0 \
  '^  OK      site\.example : valide encore (59|60) jours \(seuil 25 : renouvellement attendu à 30 jours, durée de vie 90 jours' \
  '^  OK      site\.example : nom couvert par le certificat$' \
  '^  OK      site\.example : chaîne de confiance vérifiée$'

FAILURES=0
run site.example auto "$TMP/letsencrypt.pem"
expect "90 jours moins 1 seconde (Let's Encrypt) → lu 90 jours, seuil 25" 0 \
  '\(seuil 25 : renouvellement attendu à 30 jours, durée de vie 90 jours, '

FAILURES=0
run site.example auto "$TMP/proche.pem"
expect "90 jours de vie, 20 restants → renouvellement en échec depuis 10 jours" 1 \
  '^  ALERTE  site\.example : expire dans (19|20) jours, sous le seuil de 25 \(renouvellement attendu à 30 jours'

FAILURES=0
run site.example auto "$TMP/court.pem"
expect "64 jours de vie, 18 restants → sain (un seuil fixe de 25 l'aurait refusé)" 0 \
  '^  OK      site\.example : valide encore (17|18) jours \(seuil 16 : renouvellement attendu à 21 jours, durée de vie 64 jours'

FAILURES=0
run site.example auto "$TMP/court_en_echec.pem"
expect "64 jours de vie, 15 restants → alerte" 1 \
  '^  ALERTE  site\.example : expire dans (14|15) jours, sous le seuil de 16 '

echo "tls-certificate.sh — seuil fixe (audit-prod.sh)"

FAILURES=0
run site.example 14 "$TMP/proche.pem"
expect "20 jours restants, seuil 14 → sain" 0 \
  '^  OK      site\.example : valide encore (19|20) jours \(seuil 14, '

FAILURES=0
run site.example 25 "$TMP/proche.pem"
expect "20 jours restants, seuil 25 → alerte" 1 \
  '^  ALERTE  site\.example : expire dans (19|20) jours, sous le seuil de 25 \('

echo "tls-certificate.sh — autres verdicts"

FAILURES=0
run site.example auto "$TMP/expire.pem"
expect "certificat expiré → alerte « expiré »" 1 '^  ALERTE  site\.example : expiré depuis le '

# Le « Fake Certificate » d'ingress-nginx : valable longtemps, donc invisible
# à un contrôle d'échéance seul.
FAILURES=0
run site.example auto "$TMP/autre.pem"
expect "certificat d'un autre nom → alerte sur le nom seul" 1 \
  '^  ALERTE  site\.example : nom absent du certificat servi' '^  OK      site\.example : valide encore'

FAILURES=0
run site.example auto "$TMP/autosigne.pem" 18 "self-signed certificate"
expect "chaîne refusée par s_client → alerte qui cite sa raison" 1 \
  '^  ALERTE  site\.example : chaîne de confiance refusée \(18 : self-signed certificate\)' \
  '^  OK      site\.example : nom couvert par le certificat$'

# Un PEM seul, sans la ligne de s_client : rien ne dit que la chaîne a été
# vérifiée, donc échec, jamais un vert par défaut.
FAILURES=0
check_tls_certificate site.example auto "$(cat "$TMP/sain.pem")" > "$TMP/raw"
sed 's/\x1b\[[0-9;]*m//g' "$TMP/raw" > "$TMP/out"
expect "aucun résultat de vérification → alerte" 1 '^  ALERTE  site\.example : chaîne de confiance non vérifiée'

FAILURES=0
run site.example auto "$TMP/vide.pem"
expect "aucun certificat → une seule alerte" 1 '^  ALERTE  site\.example : aucun certificat lisible'
[ "$(grep -c ALERTE "$TMP/out")" -eq 1 ] || fail "aucun certificat → une seule alerte" "$(cat "$TMP/out")"

for bad in 0 -3 abc 12j '' 08; do
  FAILURES=0
  run site.example "$bad" "$TMP/sain.pem"
  expect "seuil « $bad » refusé → alerte, jamais un vert" 1 '^  ALERTE  site\.example : seuil invalide'
done

FAILURES=3
run site.example auto "$TMP/proche.pem"
if [ "$FAILURES" -eq 4 ]; then
  pass "FAILURES=3 avant un échec → 4 après (la fonction cumule)"
else
  fail "cumul de FAILURES" "FAILURES=$FAILURES"
fi

echo "check-tls-expiry.sh"

# start_server <certificat> : sert <certificat> sur un port libre de
# 127.0.0.1 et écrit le port dans $TMP/port, une fois qu'une poignée de main
# y aboutit (jamais un délai fixe). Un port pris fait sortir s_server : on en
# essaie un autre.
start_server() {
  local port pid
  for port in $(shuf -i 20000-60000 -n 20); do
    openssl s_server -quiet -accept "127.0.0.1:$port" -cert "$TMP/$1.pem" -key "$TMP/$1.key" \
      > /dev/null 2>&1 < /dev/null &
    pid="$!"
    SERVER_PIDS+=("$pid")
    for _ in $(seq 50); do
      kill -0 "$pid" 2> /dev/null || break
      if echo | timeout 2 openssl s_client -connect "127.0.0.1:$port" > /dev/null 2>&1; then
        echo "$port" > "$TMP/port"
        return 0
      fi
      sleep 0.1
    done
  done
  return 1
}

# script <args…> : lance le script avec l'autorité de test pour magasin de
# confiance ; code de sortie dans $TMP/code, sortie (sans ANSI) dans $TMP/out.
script() {
  set +e
  SSL_CERT_FILE="$TMP/ca.pem" "$SCRIPT" "$@" > "$TMP/raw" 2>&1
  echo "$?" > "$TMP/code"
  set -e
  sed 's/\x1b\[[0-9;]*m//g' "$TMP/raw" > "$TMP/out"
}

# expect_script <libellé> <code attendu> <motif ERE>...
expect_script() {
  local label="$1" want="$2" pattern
  shift 2
  if [ "$(cat "$TMP/code")" -ne "$want" ]; then
    fail "$label" "code $(cat "$TMP/code"), attendu $want ; sortie : $(cat "$TMP/out")"
    return
  fi
  for pattern in "$@"; do
    if ! grep -Eq "$pattern" "$TMP/out"; then
      fail "$label" "motif « $pattern » absent ; sortie : $(cat "$TMP/out")"
      return
    fi
  done
  pass "$label"
}

script
expect_script "sans hôte → exit 2, usage affiché" 2 'Usage'

# Le nom vérifié est celui de l'hôte, la connexion part vers 127.0.0.1 : le
# script accepte « nom@adresse:port » pour cela, sans quoi il faudrait un DNS.
declare -A PORTS=()
for name in sain proche court autosigne; do
  if start_server "$name"; then
    PORTS[$name]="$(cat "$TMP/port")"
  else
    fail "serveur $name" "openssl s_server n'a pas démarré"
  fi
done

if [ -n "${PORTS[sain]:-}" ]; then
  script "site.example@127.0.0.1:${PORTS[sain]}"
  expect_script "serveur sain, chaîne vérifiée par s_client → exit 0" 0 \
    'OK      site\.example : valide encore (59|60) jours \(seuil 25 ' \
    'OK      site\.example : chaîne de confiance vérifiée' \
    '^Tous les certificats passent\.$'
fi

if [ -n "${PORTS[court]:-}" ]; then
  script "site.example@127.0.0.1:${PORTS[court]}"
  expect_script "64 jours de vie, 18 restants → exit 0 (seuil relatif par défaut)" 0 'seuil 16 '
fi

if [ -n "${PORTS[sain]:-}" ] && [ -n "${PORTS[proche]:-}" ]; then
  # Deux hôtes, un sain un proche : un seul échec, les deux contrôlés, et une
  # annotation GitHub qui nomme l'hôte en échec.
  script "site.example@127.0.0.1:${PORTS[sain]}" "site.example@127.0.0.1:${PORTS[proche]}"
  expect_script "un hôte sain + un proche → exit 1, les deux contrôlés" 1 \
    'ALERTE  site\.example : expire dans (19|20) jours' '^Échecs : 1$' \
    '^::error title=Certificat TLS::site\.example en échec \(1 verdict'
  [ "$(grep -c '^::error' "$TMP/out")" -eq 1 ] || fail "une seule annotation" "$(cat "$TMP/out")"
fi

if [ -n "${PORTS[autosigne]:-}" ]; then
  # Sans l'autorité de test, s_client lit le vrai magasin : le serveur
  # autosigné y est refusé, comme le serait l'AC de staging de Let's Encrypt.
  script "site.example@127.0.0.1:${PORTS[autosigne]}"
  expect_script "serveur autosigné → exit 1, chaîne refusée" 1 'ALERTE  site\.example : chaîne de confiance refusée \(18 '
fi

# Port fermé : un échec franc, jamais un vert.
script "site.example@127.0.0.1:1"
expect_script "port fermé → exit 1, « aucun certificat lisible »" 1 'aucun certificat lisible'

# Bibliothèque introuvable : arrêt en 2, jamais un vert sans contrôle.
mkdir -p "$TMP/isole"
cp "$SCRIPT" "$TMP/isole/check-tls-expiry.sh"
set +e
"$TMP/isole/check-tls-expiry.sh" site.example > "$TMP/out" 2>&1
code="$?"
set -e
if [ "$code" -eq 2 ] && grep -q 'bibliothèque introuvable' "$TMP/out" && ! grep -q 'passent' "$TMP/out"; then
  pass "bibliothèque absente → exit 2, aucun hôte contrôlé"
else
  fail "bibliothèque absente" "code $code ; sortie : $(cat "$TMP/out")"
fi

if [ "$failures" -gt 0 ]; then
  echo "Échecs : $failures"
  exit 1
fi
echo "Tous les cas passent."
