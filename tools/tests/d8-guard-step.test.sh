#!/usr/bin/env bash
#
# d8-guard-step.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne (issue #663, point 4) : l'étape de `test-backend` qui lance
# la garde D8 du serveur MCP (AuthorNeverNamedTest, `--group d8`) reçoit le
# vrai nom de l'auteur pour **tout** acteur, et lui seul le reçoit.
#
# Un push de Dependabot retombait sur le nom fictif de phpunit.dist.xml, faute
# de lire les secrets du dépôt : une montée de `mcp/sdk` ou de
# `symfony/mcp-bundle`, qui écrivent ce que le serveur publie, n'était jamais
# contrôlée contre le vrai nom dans son propre run. Le même secret est posé
# dans les secrets Dependabot (.claude/rules/mcp.md, garde D8) ; absent, la
# garde échoue en le disant.
#
# Deux règles, lues sur les lignes hors commentaires de pipeline.yml :
#  - l'étape `--group d8` déclare `MCP_D8_AUTHOR_NAME: ${{ secrets.… }}` et
#    son script ne retombe sur rien (ni `unset`, ni test d'acteur) ;
#  - aucune autre ligne ne nomme la variable : ni le job, ni la suite
#    complète, ni une autre étape ne la reçoivent.
#
# Usage :  tools/tests/d8-guard-step.test.sh [pipeline.yml]
#          (un autre fichier pour éprouver le refus, cf. la fin du fichier)
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SECRET_LINE='MCP_D8_AUTHOR_NAME: ${{ secrets.MCP_D8_AUTHOR_NAME }}'

# L'étape (au sens des `- ` d'une liste `steps:`, six espaces d'indentation)
# dont le script lance `bin/phpunit --group d8`, commentaires retirés.
d8_step() {
  awk '
    /^      - / { if (found) { print block; exit } block = ""; found = 0 }
    /^[[:space:]]*#/ { next }
    { block = block $0 "\n" }
    /bin\/phpunit --group d8/ { found = 1 }
    END { if (found) printf "%s", block }
  ' "$1"
}

# 0 si les deux règles tiennent ; 1 sinon, en disant laquelle.
check() {
  local pipeline="$1" step mentions
  step="$(d8_step "$pipeline")"
  if [ -z "$step" ]; then
    echo '       aucune étape ne lance « bin/phpunit --group d8 »'
    return 1
  fi
  if ! printf '%s\n' "$step" | grep -qF "$SECRET_LINE"; then
    echo "       l'étape d8 ne reçoit pas le secret MCP_D8_AUTHOR_NAME"
    return 1
  fi
  if printf '%s\n' "$step" | grep -qiE 'unset|GITHUB_ACTOR|dependabot'; then
    echo "       l'étape d8 retombe sur le nom fictif pour un acteur"
    return 1
  fi
  mentions="$(grep -vE '^[[:space:]]*#' "$pipeline" | grep -c 'MCP_D8_AUTHOR_NAME' || true)"
  if [ "$mentions" -ne 1 ]; then
    echo "       MCP_D8_AUTHOR_NAME nommée sur $mentions lignes hors commentaires (attendu : 1, l'env de l'étape d8)"
    return 1
  fi
}

failures=0
pass() { printf '  ok   %s\n' "$1"; }
fail() { printf '  FAIL %s\n' "$1"; failures=$((failures + 1)); }

if [ "$#" -eq 1 ]; then
  check "$1"
  exit $?
fi

if check "$ROOT/.github/workflows/pipeline.yml"; then
  pass "la garde D8 reçoit le vrai nom pour tout acteur, et elle seule"
else
  fail "l'étape de la garde D8 (ci-dessus)"
fi

# Le refus, éprouvé sur des copies.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
pipeline="$ROOT/.github/workflows/pipeline.yml"

# 1. Un repli selon l'acteur, sans même nommer la variable (seule la règle
#    de l'étape le voit, pas le compte des mentions).
sed 's|run: php bin/phpunit --group d8|run: [ "$GITHUB_ACTOR" = x ] \|\| php bin/phpunit --group d8|' \
  "$pipeline" > "$TMP/fallback.yml"
if cmp -s "$pipeline" "$TMP/fallback.yml"; then fail "la mutation du repli n'a rien changé"; fi
if check "$TMP/fallback.yml" > /dev/null; then fail 'un repli selon l'"'"'acteur passe'; else pass 'un repli selon l'"'"'acteur est refusé'; fi

# 2. Le secret retiré de l'étape.
grep -vF "$SECRET_LINE" "$pipeline" > "$TMP/no-secret.yml"
if cmp -s "$pipeline" "$TMP/no-secret.yml"; then fail "la mutation du secret n'a rien changé"; fi
if check "$TMP/no-secret.yml" > /dev/null; then fail "une étape d8 sans le secret passe"; else pass "une étape d8 sans le secret est refusée"; fi

# 3. Le nom donné aussi à la suite complète.
sed 's|^\([[:space:]]*\)RABBITMQ_INTEGRATION_DSN:|\1MCP_D8_AUTHOR_NAME: x\n&|' "$pipeline" > "$TMP/leak.yml"
if cmp -s "$pipeline" "$TMP/leak.yml"; then fail "la mutation de la fuite n'a rien changé"; fi
if check "$TMP/leak.yml" > /dev/null; then fail 'le nom donné à la suite complète passe'; else pass 'le nom donné à la suite complète est refusé'; fi

[ "$failures" -eq 0 ] || { printf '%d échec(s)\n' "$failures"; exit 1; }
