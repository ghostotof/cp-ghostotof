#!/usr/bin/env bash
#
# tls-certificate.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/lib/tls-certificate.sh (check_tls_certificate) et
# de tools/check-tls-expiry.sh (issue #719). Les certificats sont autosignés,
# générés par openssl dans un répertoire temporaire ; le script complet est
# exercé contre un `openssl s_server` lancé sur 127.0.0.1. Aucun appel au
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

# make_cert <nom> <jours> <nom DNS> : certificat autosigné valable <jours>
# jours, le nom DNS en CN et en subjectAltName (ce que lit -checkhost).
make_cert() {
  openssl req -x509 -newkey rsa:2048 -nodes -days "$2" -subj "/CN=$3" \
    -addext "subjectAltName=DNS:$3" \
    -keyout "$TMP/$1.key" -out "$TMP/$1.pem" 2> /dev/null
}

# Un certificat déjà expiré : `-days` refuse les valeurs négatives, on passe
# par `openssl ca -startdate/-enddate`, autosigné (`-selfsign`). Pas
# `openssl x509 -not_after` : il date d'OpenSSL 3.4, le runner CI est en 3.0.
make_expired_cert() {
  local name="$1" host="$2"
  openssl req -newkey rsa:2048 -nodes -subj "/CN=$host" -addext "subjectAltName=DNS:$host" \
    -keyout "$TMP/$name.key" -out "$TMP/$name.csr" 2> /dev/null
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
default_md = sha256
policy = anything
copy_extensions = copy
[ anything ]
commonName = supplied
EOF
  openssl ca -batch -config "$TMP/ca/ca.cnf" -selfsign -keyfile "$TMP/$name.key" \
    -in "$TMP/$name.csr" -out "$TMP/$name.pem" \
    -startdate 20200101000000Z -enddate 20200201000000Z 2> /dev/null
}

# run <hôte> <seuil> <fichier> : appelle check_tls_certificate dans le shell
# courant (jamais via `$(...)`, qui perdrait FAILURES dans une sous-shell) et
# laisse la sortie, débarrassée des séquences ANSI, dans $TMP/out.
run() {
  check_tls_certificate "$1" "$2" "$(cat "$3" 2> /dev/null)" > "$TMP/raw"
  sed 's/\x1b\[[0-9;]*m//g' "$TMP/raw" > "$TMP/out"
}

make_cert sain 60 site.example
make_cert proche 20 site.example
make_cert autre 60 autre.example
make_expired_cert expire site.example
: > "$TMP/vide.pem"

echo "tls-certificate.sh"

if [ ! -f "$LIB" ]; then
  fail "bibliothèque présente" "$LIB introuvable"
  echo "Échecs : $failures"
  exit 1
fi
# shellcheck source=tools/lib/tls-certificate.sh
source "$LIB"

# --- Cas nominal : 60 jours, seuil 25 ----------------------------------------
FAILURES=0
run site.example 25 "$TMP/sain.pem"
if [ "$FAILURES" -eq 0 ] && grep -Eq '^  OK      site.example : valide encore (59|60) jours \(seuil 25, ' "$TMP/out" \
   && grep -q '^  OK      site.example : nom couvert par le certificat' "$TMP/out"; then
  pass "60 jours, seuil 25 → FAILURES=0, jours restants et nom affichés"
else
  fail "cas nominal" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi

# --- Le même certificat, sous le seuil ----------------------------------------
# 20 jours : sain pour audit-preprod (14), alerte pour le job planifié (25).
FAILURES=0
run site.example 14 "$TMP/proche.pem"
if [ "$FAILURES" -eq 0 ]; then
  pass "20 jours, seuil 14 → FAILURES=0"
else
  fail "20 jours, seuil 14" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi
FAILURES=0
run site.example 25 "$TMP/proche.pem"
if [ "$FAILURES" -eq 1 ] && grep -Eq '^  ALERTE  site.example : expire dans (19|20) jours, sous le seuil de 25 ' "$TMP/out"; then
  pass "20 jours, seuil 25 → FAILURES+1, ALERTE nomme les jours restants et le seuil"
else
  fail "20 jours, seuil 25" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi

# --- Certificat expiré ---------------------------------------------------------
FAILURES=0
run site.example 25 "$TMP/expire.pem"
if [ "$FAILURES" -eq 1 ] && grep -q '^  ALERTE  site.example : expiré depuis le ' "$TMP/out"; then
  pass "certificat expiré → FAILURES+1, ALERTE « expiré »"
else
  fail "certificat expiré" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi

# --- Certificat d'un autre nom (le « Fake Certificate » d'ingress-nginx) -------
# Valable longtemps, donc invisible à un contrôle d'échéance seul.
FAILURES=0
run site.example 25 "$TMP/autre.pem"
if [ "$FAILURES" -eq 1 ] && grep -q '^  ALERTE  site.example : nom absent du certificat servi' "$TMP/out" \
   && grep -q '^  OK      site.example : valide encore' "$TMP/out"; then
  pass "autre nom, 60 jours → FAILURES+1 sur le nom seul"
else
  fail "autre nom" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi

# --- Aucun certificat (poignée de main échouée) ---------------------------------
FAILURES=0
run site.example 25 "$TMP/vide.pem"
if [ "$FAILURES" -eq 1 ] && grep -q '^  ALERTE  site.example : aucun certificat lisible' "$TMP/out" \
   && [ "$(grep -c ALERTE "$TMP/out")" -eq 1 ]; then
  pass "aucun certificat → FAILURES+1, une seule ALERTE"
else
  fail "aucun certificat" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi

# --- La fonction cumule, ne remet jamais FAILURES à zéro -----------------------
FAILURES=3
run site.example 25 "$TMP/proche.pem"
if [ "$FAILURES" -eq 4 ]; then
  pass "FAILURES=3 avant un échec → 4 après"
else
  fail "cumul de FAILURES" "FAILURES=$FAILURES"
fi

echo "check-tls-expiry.sh"

# start_server <certificat> : sert <certificat> sur un port libre de
# 127.0.0.1, écrit le port dans $TMP/port.
start_server() {
  local port
  for port in $(shuf -i 20000-60000 -n 20); do
    openssl s_server -quiet -accept "127.0.0.1:$port" -cert "$TMP/$1.pem" -key "$TMP/$1.key" \
      -naccept 4 > /dev/null 2>&1 < /dev/null &
    SERVER_PIDS+=("$!")
    sleep 0.5
    if kill -0 "$!" 2> /dev/null; then
      echo "$port" > "$TMP/port"
      return 0
    fi
  done
  return 1
}

# script <args…> : code de sortie dans $TMP/code, sortie (sans ANSI) dans $TMP/out.
script() {
  set +e
  "$SCRIPT" "$@" > "$TMP/raw" 2>&1
  echo "$?" > "$TMP/code"
  set -e
  sed 's/\x1b\[[0-9;]*m//g' "$TMP/raw" > "$TMP/out"
}

# --- Arguments refusés avant tout réseau ---------------------------------------
script
if [ "$(cat "$TMP/code")" -eq 2 ] && grep -q 'Usage' "$TMP/out"; then
  pass "sans hôte → exit 2, usage affiché"
else
  fail "sans hôte" "code $(cat "$TMP/code") ; sortie : $(cat "$TMP/out")"
fi
for bad in 0 -3 abc 12j ''; do
  script --min-days "$bad" site.example
  if [ "$(cat "$TMP/code")" -eq 2 ] && grep -q -- '--min-days' "$TMP/out"; then
    pass "--min-days « $bad » → exit 2"
  else
    fail "--min-days « $bad »" "code $(cat "$TMP/code") ; sortie : $(cat "$TMP/out")"
  fi
done

# --- Contre un vrai serveur TLS local -------------------------------------------
# Le nom vérifié est celui de l'hôte, la connexion part vers 127.0.0.1 : le
# script accepte « nom@adresse:port » pour cela, sans quoi il faudrait un DNS.
if start_server sain; then
  PORT_SAIN="$(cat "$TMP/port")"
  script --min-days 25 "site.example@127.0.0.1:$PORT_SAIN"
  if [ "$(cat "$TMP/code")" -eq 0 ] && grep -Eq 'OK      site.example : valide encore (59|60) jours \(seuil 25, ' "$TMP/out"; then
    pass "serveur sain, seuil 25 → exit 0"
  else
    fail "serveur sain" "code $(cat "$TMP/code") ; sortie : $(cat "$TMP/out")"
  fi
else
  fail "serveur sain" "openssl s_server n'a pas démarré"
fi

if start_server proche; then
  PORT_PROCHE="$(cat "$TMP/port")"
  # Deux hôtes, un sain un proche : un seul échec, et les deux contrôlés.
  script --min-days 25 "site.example@127.0.0.1:$PORT_SAIN" "site.example@127.0.0.1:$PORT_PROCHE"
  if [ "$(cat "$TMP/code")" -eq 1 ] && [ "$(grep -c '^  OK      site.example : valide encore' "$TMP/out")" -eq 1 ] \
     && grep -Eq 'ALERTE  site.example : expire dans (19|20) jours' "$TMP/out" && grep -q '^Échecs : 1$' "$TMP/out"; then
    pass "un hôte sain + un proche → exit 1, les deux contrôlés, « Échecs : 1 »"
  else
    fail "sain + proche" "code $(cat "$TMP/code") ; sortie : $(cat "$TMP/out")"
  fi
  # Seuil par défaut : 25 jours (le job planifié n'en passe pas).
  script "site.example@127.0.0.1:$PORT_PROCHE"
  if [ "$(cat "$TMP/code")" -eq 1 ] && grep -q 'sous le seuil de 25' "$TMP/out"; then
    pass "seuil par défaut → 25 jours"
  else
    fail "seuil par défaut" "code $(cat "$TMP/code") ; sortie : $(cat "$TMP/out")"
  fi
else
  fail "serveur proche" "openssl s_server n'a pas démarré"
fi

# --- Port fermé : un échec franc, jamais un vert --------------------------------
script --min-days 25 "site.example@127.0.0.1:1"
if [ "$(cat "$TMP/code")" -eq 1 ] && grep -q 'aucun certificat lisible' "$TMP/out"; then
  pass "port fermé → exit 1, « aucun certificat lisible »"
else
  fail "port fermé" "code $(cat "$TMP/code") ; sortie : $(cat "$TMP/out")"
fi

if [ "$failures" -gt 0 ]; then
  echo "Échecs : $failures"
  exit 1
fi
echo "Tous les cas passent."
