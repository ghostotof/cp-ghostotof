#!/usr/bin/env bash
#
# git-config-isolation.test.sh
# ---------------------------------------------------------------------------
# Les suites de tools/tests/ ne dépendent pas de l'environnement git du poste
# (issue #304, règle de #26 : la machine hôte n'influence pas le résultat).
#
# Rejoue chaque autre suite dans un environnement git hostile, qui couvre
# chaque canal par lequel le poste ou l'appelant peut atteindre git :
#   - configuration globale (GIT_CONFIG_GLOBAL, l'équivalent de ~/.gitconfig) :
#     signature des commits et des étiquettes, hook pre-commit qui refuse tout,
#     branche par défaut de `git init` changée ;
#   - configuration par l'environnement (GIT_CONFIG_PARAMETERS, que `git -c`
#     alimente, et GIT_CONFIG_COUNT/KEY_n/VALUE_n), prioritaire sur la
#     configuration globale : un isolement par GIT_CONFIG_GLOBAL seul ne les
#     neutralise pas ;
#   - dépôt imposé par l'environnement (GIT_DIR, GIT_INDEX_FILE), comme le
#     ferait un lancement depuis un hook git : sans neutralisation, les suites
#     écriraient dans ce dépôt au lieu de leurs dépôts temporaires.
# Une suite isolée (tools/tests/lib/git-isolation.sh) passe et laisse le dépôt
# leurre intact ; une suite qui oublie de s'isoler échoue.
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

# Hook qui refuse tout commit : une suite qui hérite de core.hooksPath échoue
# au premier `git commit`, signature désactivée ou non.
mkdir -p "$TMP/hooks"
printf '#!/bin/sh\necho "hook pre-commit du poste exécuté" >&2\nexit 1\n' > "$TMP/hooks/pre-commit"
chmod +x "$TMP/hooks/pre-commit"

# /bin/false comme programme gpg, partout : la signature échoue aussitôt au
# lieu d'attendre la phrase de passe d'un vrai agent.
cat > "$TMP/gitconfig" <<EOF
[commit]
	gpgsign = true
[tag]
	gpgSign = true
[gpg]
	program = /bin/false
[core]
	hooksPath = $TMP/hooks
[init]
	defaultBranch = branche-hostile
EOF

# Dépôt leurre, créé avant d'exporter quoi que ce soit : il ne doit recevoir
# aucun objet ni aucune référence pendant les suites.
git init --quiet --bare "$TMP/leurre.git"
leurre_avant="$(find "$TMP/leurre.git" -type f | sort | xargs cksum)"

for suite in "$HERE"/*.test.sh; do
  name="$(basename "$suite")"
  [ "$name" = "$SELF" ] && continue
  if env -u GIT_CONFIG_NOSYSTEM -u GIT_WORK_TREE \
       GIT_CONFIG_GLOBAL="$TMP/gitconfig" \
       GIT_CONFIG_PARAMETERS="'commit.gpgsign'='true' 'gpg.program'='/bin/false'" \
       GIT_CONFIG_COUNT=2 \
       GIT_CONFIG_KEY_0=tag.gpgSign GIT_CONFIG_VALUE_0=true \
       GIT_CONFIG_KEY_1=gpg.program GIT_CONFIG_VALUE_1=/bin/false \
       GIT_DIR="$TMP/leurre.git" GIT_INDEX_FILE="$TMP/leurre.index" \
       bash "$suite" > "$TMP/$name.out" 2>&1; then
    pass "$name passe sous un environnement git hostile"
  else
    fail "$name échoue sous un environnement git hostile" \
         "$(grep -m3 -E 'FAIL|gpg|hook|erreur|error|fatal' "$TMP/$name.out" || tail -3 "$TMP/$name.out")"
  fi
done

if [ "$(find "$TMP/leurre.git" -type f | sort | xargs cksum)" = "$leurre_avant" ] \
   && [ ! -e "$TMP/leurre.index" ]; then
  pass "le dépôt imposé par GIT_DIR n'a pas été touché"
else
  fail "le dépôt imposé par GIT_DIR a été modifié" "une suite écrit hors de ses dépôts temporaires"
fi

echo
if [ "$failures" -eq 0 ]; then echo "OK"; else echo "$failures échec(s)"; exit 1; fi
