#!/usr/bin/env bash
#
# summary-language-tools.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de .github/scripts/summary-language-tools.sh (#343) : le
# résumé du job lsp-check-backend quand l'installation des Symfony Language
# Tools a échoué. Ce qui compte ici, c'est que chaque code de sortie de
# docker/php/install-symfony-language-tools.sh soit présenté pour ce qu'il est :
# un incident réseau se relance, un refus d'intégrité ne se relance pas à
# l'aveugle, et aucun des deux n'est un défaut du code.
#
# Usage :  tools/tests/summary-language-tools.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/.github/scripts/summary-language-tools.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# summarize <code> : lance le script ; annotations (stdout) dans $TMP/stdout,
# carte de synthèse dans $TMP/summary, code de sortie dans $status.
status=0
summarize() {
  : > "$TMP/summary"
  status=0
  GITHUB_STEP_SUMMARY="$TMP/summary" "$SCRIPT" "$@" > "$TMP/stdout" 2>&1 || status=$?
}

echo "summary-language-tools.sh"

# 1. Code 2 : téléchargement impossible — relancer, le code n'est pas en cause.
summarize 2
if [ "$status" -eq 0 ] \
   && grep -q '^::error title=.*::.*[Tt]éléchargement impossible' "$TMP/stdout" \
   && grep -q 'Relancer le job' "$TMP/summary" \
   && grep -q "le code n'est pas en cause" "$TMP/summary"; then
  pass "code 2 : téléchargement impossible, à relancer"
else
  fail "code 2 : téléchargement impossible, à relancer" "stdout : $(cat "$TMP/stdout") | résumé : $(cat "$TMP/summary")"
fi

# 2. Code 3 : intégrité refusée — surtout pas « relancer ».
summarize 3
if [ "$status" -eq 0 ] \
   && grep -q '^::error title=.*::.*[Ii]ntégrité' "$TMP/stdout" \
   && grep -qi 'ne pas relancer' "$TMP/summary" \
   && ! grep -q 'Relancer le job' "$TMP/summary"; then
  pass "code 3 : intégrité refusée, ne pas relancer à l'aveugle"
else
  fail "code 3 : intégrité refusée, ne pas relancer à l'aveugle" "stdout : $(cat "$TMP/stdout") | résumé : $(cat "$TMP/summary")"
fi

# 3. Code 1 (et tout autre code) : configuration — à corriger dans le dépôt
#    ou le workflow, pas à relancer.
for code in 1 127; do
  summarize "$code"
  if [ "$status" -eq 0 ] \
     && grep -q '^::error title=.*::.*[Cc]onfiguration' "$TMP/stdout" \
     && grep -q "code $code" "$TMP/summary" \
     && ! grep -q 'Relancer le job' "$TMP/summary"; then
    pass "code $code : erreur de configuration, avec son code"
  else
    fail "code $code : erreur de configuration, avec son code" "stdout : $(cat "$TMP/stdout") | résumé : $(cat "$TMP/summary")"
  fi
done

# 4. Dans tous les cas, la carte dit qu'aucun diagnostic n'a tourné : un
#    échec d'installation ne doit jamais se lire comme un défaut du code.
for code in 1 2 3; do
  summarize "$code"
  if grep -q "aucun diagnostic n'a tourné" "$TMP/summary"; then
    pass "code $code : précise qu'aucun diagnostic n'a tourné"
  else
    fail "code $code : précise qu'aucun diagnostic n'a tourné" "$(cat "$TMP/summary")"
  fi
done

# 5. Code 0 ou absent : appel erroné, le script le refuse plutôt que d'écrire
#    une carte d'échec pour une installation réussie.
for args in 0 ""; do
  # shellcheck disable=SC2086 # "" doit bien donner zéro argument
  summarize $args
  if [ "$status" -ne 0 ] && [ ! -s "$TMP/summary" ] && grep -q 'Usage' "$TMP/stdout"; then
    pass "refuse un code « ${args:-absent} »"
  else
    fail "refuse un code « ${args:-absent} »" "code $status, résumé : $(cat "$TMP/summary")"
  fi
done

if [ "$failures" -gt 0 ]; then
  printf '\n%d échec(s)\n' "$failures"
  exit 1
fi
printf '\nTous les tests passent.\n'
