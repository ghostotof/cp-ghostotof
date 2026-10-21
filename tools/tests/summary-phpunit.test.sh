#!/usr/bin/env bash
#
# summary-phpunit.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de .github/scripts/summary-phpunit.php : la carte de
# synthèse de PHPUnit sur la page du run. Ce qui compte ici (#663, point 5),
# c'est qu'il **sorte toujours en 0** — rapport absent, illisible, vert ou
# rouge — et qu'il dise lequel : il raconte, il ne décide jamais du statut du
# job (ce sont les deux étapes PHPUnit qui échouent). L'étape « Résumé
# PHPUnit » de pipeline.yml l'appelle donc sans `|| status=1`, qui n'aurait
# jamais servi.
#
# Usage :  tools/tests/summary-phpunit.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/.github/scripts/summary-phpunit.php"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# summarize <rapport> : la carte dans $TMP/card, le code de sortie dans $status.
status=0
summarize() {
  status=0
  php "$SCRIPT" "$1" > "$TMP/card" 2>&1 || status=$?
}

# expect <cas> <motif de la carte>
expect() {
  if [ "$status" -eq 0 ] && grep -q -- "$2" "$TMP/card"; then
    pass "$1 : sortie 0, la carte le dit"
  else
    fail "$1" "sortie $status ; carte : $(head -3 "$TMP/card" | tr '\n' ' ')"
  fi
}

echo "summary-phpunit.php"

# 1. Rapport absent : PHPUnit tombé avant de l'écrire (base de test non prête).
summarize "$TMP/absent.xml"
expect 'rapport absent' 'Aucun rapport JUnit trouvé'

# 2. Rapport illisible.
printf 'pas du XML' > "$TMP/broken.xml"
summarize "$TMP/broken.xml"
expect 'rapport illisible' 'Rapport JUnit illisible'

# 3. Suite verte.
cat > "$TMP/green.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="CLI Arguments" tests="2" assertions="3" errors="0" failures="0" skipped="0" time="0.5">
    <testcase name="testA" class="App\Tests\ATest" assertions="2" time="0.2"/>
    <testcase name="testB" class="App\Tests\ATest" assertions="1" time="0.3"/>
  </testsuite>
</testsuites>
XML
summarize "$TMP/green.xml"
expect 'suite verte' '## ✅ PHPUnit'

# 4. Suite rouge : la carte nomme le cas, et le script sort quand même en 0.
cat > "$TMP/red.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="CLI Arguments" tests="2" assertions="2" errors="0" failures="1" skipped="0" time="0.5">
    <testcase name="testA" class="App\Tests\ATest" assertions="1" time="0.2"/>
    <testcase name="testB" class="App\Tests\ATest" assertions="1" time="0.3">
      <failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that false is true.</failure>
    </testcase>
  </testsuite>
</testsuites>
XML
summarize "$TMP/red.xml"
expect 'suite rouge' 'App\\Tests\\ATest::testB'

[ "$failures" -eq 0 ] || { printf '%d échec(s)\n' "$failures"; exit 1; }
