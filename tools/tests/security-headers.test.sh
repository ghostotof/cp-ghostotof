#!/usr/bin/env bash
#
# security-headers.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/lib/security-headers.sh (SECURITY_HEADERS +
# check_security_headers) : des blobs d'en-têtes HTTP construits en dur avec
# `printf`, aucun appel réseau, aucune écriture dans le dépôt courant.
#
# Usage :  tools/tests/security-headers.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

LIB="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/security-headers.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# shellcheck source=tools/lib/security-headers.sh
source "$LIB"

# Les 7 en-têtes attendus, dans l'ordre de SECURITY_HEADERS, avec une valeur
# plausible chacun.
FULL_HEADERS=(
  "Strict-Transport-Security: max-age=63072000; includeSubDomains; preload"
  "X-Content-Type-Options: nosniff"
  "Referrer-Policy: strict-origin-when-cross-origin"
  "Permissions-Policy: geolocation=()"
  "X-Frame-Options: DENY"
  "Cross-Origin-Opener-Policy: same-origin"
  "Content-Security-Policy: default-src 'self'"
)

# run <label> <blob> : appelle check_security_headers dans le shell courant
# (jamais via `$(...)`, qui perdrait les mises à jour de FAILURES dans une
# sous-shell) et laisse la sortie, débarrassée des séquences ANSI, dans
# $TMP/out.
run() {
  check_security_headers "$1" "$2" > "$TMP/raw"
  sed 's/\x1b\[[0-9;]*m//g' "$TMP/raw" > "$TMP/out"
}

# blob_full [status] : une seule réponse portant les 7 en-têtes.
blob_full() {
  printf '%s\n' "${1:-HTTP/2 200}"
  local h
  for h in "${FULL_HEADERS[@]}"; do printf '%s\n' "$h"; done
}

# blob_missing <index> : les 7 en-têtes sauf celui d'index $1 (base 0).
blob_missing() {
  local skip="$1" i=0 h
  printf 'HTTP/2 200\n'
  for h in "${FULL_HEADERS[@]}"; do
    [ "$i" -eq "$skip" ] || printf '%s\n' "$h"
    i=$((i + 1))
  done
}

echo "security-headers.sh"

# --- Cas nominal : les 7 en-têtes présents -----------------------------------
FAILURES=0
run "/ nominal" "$(blob_full)"
if [ "$FAILURES" -eq 0 ] && [ "$(grep -c '^    OK      ' "$TMP/out")" -eq 7 ] && ! grep -q ABSENT "$TMP/out"; then
  pass "7 en-têtes présents → FAILURES=0, 7 OK, aucune ABSENT"
else
  fail "cas nominal" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi

# --- Un en-tête absent, à tour de rôle ---------------------------------------
i=0
for h in "${SECURITY_HEADERS[@]}"; do
  FAILURES=0
  run "manque $h" "$(blob_missing "$i")"
  if [ "$FAILURES" -eq 1 ] \
     && [ "$(grep -c ABSENT "$TMP/out")" -eq 1 ] \
     && grep -q "^    ABSENT  ${h}   <-- à corriger !\$" "$TMP/out"; then
    pass "en-tête absent : $h → FAILURES+1, ABSENT nomme exactement $h"
  else
    fail "en-tête absent : $h" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
  fi
  i=$((i + 1))
done

# --- Insensibilité à la casse -------------------------------------------------
# FULL_HEADERS est déjà construit avec des noms en casse mixte
# (Content-Security-Policy:, X-Frame-Options:...) : le cas nominal ci-dessus
# le prouve déjà, mais on l'isole ici pour le nommer explicitement, avec une
# casse différente sur chaque en-tête (bas de casse pour l'un, tout majuscule
# pour un autre).
FAILURES=0
run "casse mêlée" "$(printf 'HTTP/2 200\nstrict-transport-security: max-age=1\nX-CONTENT-TYPE-OPTIONS: nosniff\nReferrer-Policy: no-referrer\npermissions-policy: geolocation=()\nX-Frame-Options: DENY\nCROSS-ORIGIN-OPENER-POLICY: same-origin\nContent-Security-Policy: default-src '"'"'self'"'"'\n')"
if [ "$FAILURES" -eq 0 ] && ! grep -q ABSENT "$TMP/out"; then
  pass "noms d'en-têtes insensibles à la casse (Content-Security-Policy: compte)"
else
  fail "insensibilité à la casse" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi

# --- Chaîne de redirections : seule la réponse finale compte -----------------
# 301 complet puis 200 nu → les 7 en-têtes du 301 ne doivent pas compter.
FAILURES=0
run "redirection : 301 complet puis 200 nu" "$(
  printf 'HTTP/1.1 301 Moved Permanently\n'
  for h in "${FULL_HEADERS[@]}"; do printf '%s\n' "$h"; done
  printf '\n'
  printf 'HTTP/2 200\n'
)"
if [ "$FAILURES" -eq 7 ] && [ "$(grep -c ABSENT "$TMP/out")" -eq 7 ]; then
  pass "301 (7 en-têtes) puis 200 nu → 7 ABSENT (seule la réponse finale compte)"
else
  fail "redirection 301 complet -> 200 nu" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi

# --- Inverse : 301 nu puis 200 complet → 0 ABSENT ----------------------------
FAILURES=0
run "redirection : 301 nu puis 200 complet" "$(
  printf 'HTTP/1.1 301 Moved Permanently\n'
  printf '\n'
  printf 'HTTP/2 200\n'
  for h in "${FULL_HEADERS[@]}"; do printf '%s\n' "$h"; done
)"
if [ "$FAILURES" -eq 0 ] && ! grep -q ABSENT "$TMP/out"; then
  pass "301 nu puis 200 (7 en-têtes) → 0 ABSENT"
else
  fail "redirection 301 nu -> 200 complet" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi

# --- Code HTTP : priorité à la pseudo-ligne x-audit-http-code ----------------
# Ligne de statut à 301 (traversée par -L), pseudo-ligne à 200 (fetch_headers) :
# le code affiché doit être celui de la pseudo-ligne, jamais celui du statut.
FAILURES=0
run "code via x-audit-http-code" "$(
  printf 'HTTP/1.1 301 Moved Permanently\n'
  for h in "${FULL_HEADERS[@]}"; do printf '%s\n' "$h"; done
  printf '\n'
  printf 'x-audit-http-code: 200\n'
)"
if grep -q '(HTTP 200)' "$TMP/out"; then
  pass "code HTTP affiché = celui de x-audit-http-code (200), pas celui de la ligne de statut (301)"
else
  fail "code via x-audit-http-code" "sortie : $(cat "$TMP/out")"
fi

# --- Code HTTP : à défaut, la ligne de statut --------------------------------
FAILURES=0
run "code via ligne de statut" "$(blob_full 'HTTP/2 200')"
if grep -q '(HTTP 200)' "$TMP/out"; then
  pass "sans x-audit-http-code, le code affiché vient de la ligne de statut"
else
  fail "code via ligne de statut" "sortie : $(cat "$TMP/out")"
fi

# --- Report-Only résiduel, sans le CSP enforce --------------------------------
# Comportement existant, épinglé tel quel (pas de correctif ici) : la CSP
# enforce est ABSENTE (elle n'est jamais littéralement présente puisque
# `content-security-policy-report-only:` ne satisfait pas l'ancrage
# `^content-security-policy:`), ET la mise en garde Report-Only s'ajoute par
# dessus : FAILURES est donc incrémenté deux fois pour ce seul en-tête.
FAILURES=0
run "CSP report-only seul, sans enforce" "$(
  printf 'HTTP/2 200\n'
  printf 'Strict-Transport-Security: max-age=1\n'
  printf 'X-Content-Type-Options: nosniff\n'
  printf 'Referrer-Policy: no-referrer\n'
  printf 'Permissions-Policy: geolocation=()\n'
  printf 'X-Frame-Options: DENY\n'
  printf 'Cross-Origin-Opener-Policy: same-origin\n'
  printf "Content-Security-Policy-Report-Only: default-src 'self'\n"
)"
if [ "$FAILURES" -eq 2 ] \
   && grep -q "^    ABSENT  content-security-policy   <-- à corriger !\$" "$TMP/out" \
   && grep -q 'Report-Only SEUL  content-security-policy' "$TMP/out"; then
  pass "Content-Security-Policy-Report-Only seul (sans enforce) : ABSENT + avertissement Report-Only, FAILURES+2"
else
  fail "report-only seul" "FAILURES=$FAILURES ; sortie : $(cat "$TMP/out")"
fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "Tous les cas passent."
