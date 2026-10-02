#!/usr/bin/env bash
#
# git-config-isolation.test.sh
# ---------------------------------------------------------------------------
# Les suites de tools/tests/ ne dépendent pas de la configuration git du poste
# (issue #304, règle de #26 : la machine hôte n'influence pas le résultat).
#
# Rejoue chaque autre suite avec un HOME dont le .gitconfig est hostile : il
# signe commits et étiquettes avec un programme gpg qui échoue toujours, et
# change la branche par défaut de `git init`. Une suite qui hérite de cette
# configuration échoue ; une suite isolée (GIT_CONFIG_GLOBAL=/dev/null,
# GIT_CONFIG_NOSYSTEM=1) passe. Les variables d'isolation sont retirées de
# l'environnement des suites rejouées : chacune doit les poser elle-même.
#
# Usage :  tools/tests/git-config-isolation.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF="$(basename "${BASH_SOURCE[0]}")"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# /bin/false comme programme gpg : la signature échoue aussitôt, au lieu
# d'attendre une phrase de passe comme le ferait un vrai agent.
mkdir -p "$TMP/home"
cat > "$TMP/home/.gitconfig" <<'EOF'
[commit]
	gpgsign = true
[tag]
	gpgSign = true
[gpg]
	program = /bin/false
[init]
	defaultBranch = branche-hostile
EOF

for suite in "$HERE"/*.test.sh; do
  name="$(basename "$suite")"
  [ "$name" = "$SELF" ] && continue
  if env -u GIT_CONFIG_GLOBAL -u GIT_CONFIG_NOSYSTEM HOME="$TMP/home" \
       bash "$suite" > "$TMP/$name.out" 2>&1; then
    pass "$name passe sous une configuration git qui signe"
  else
    fail "$name échoue sous une configuration git qui signe" \
         "$(grep -m3 -E 'FAIL|gpg|erreur|error' "$TMP/$name.out" || tail -3 "$TMP/$name.out")"
  fi
done

echo
if [ "$failures" -eq 0 ]; then echo "OK"; else echo "$failures échec(s)"; exit 1; fi
