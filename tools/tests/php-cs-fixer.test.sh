#!/usr/bin/env bash
#
# php-cs-fixer.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de docker/php/php-cs-fixer.sh (issue #398), la correction
# ponctuelle de `make back-style-fix`. Le « phar » est un fichier quelconque
# servi en `file://` depuis un dossier temporaire, l'interpréteur PHP est un
# faux qui consigne chaque appel (dossier courant et arguments) : aucun appel
# réseau, aucun PHP requis, aucune écriture dans le dépôt courant.
#
# Ce qui est pincé :
#   - un phar dont la somme ne concorde pas n'est jamais exécuté (code 3),
#     qu'il vienne d'être téléchargé ou qu'il soit déjà là ;
#   - un phar présent et sain est réutilisé sans réseau ;
#   - les deux règles, telles que les gardes PHPUnit les reproduisent, sont
#     appliquées à `src/` puis à `tests/`, depuis le dossier du backend ;
#   - un échec du fixeur sur `src/` arrête tout (code 4) ;
#   - la version et la somme de `.env` sont celles que recopie `versions.lock`,
#     et la cible `back-style-fix` du Makefile lance bien ce script.
#
# Codes de sortie attendus du script : 1 configuration, 2 téléchargement
# impossible, 3 intégrité refusée, 4 échec du fixeur.
#
# Usage :  tools/tests/php-cs-fixer.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/docker/php/php-cs-fixer.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

VERSION=3.95.27
# Volontairement recopiées du script plutôt que partagées : le test ne doit
# pas dépendre du code qu'il vérifie. Ce sont les règles que miment
# UnsortedImports et MisqualifiedNativeCalls (backend/tests/Support/).
RULES='{"ordered_imports":true,"native_function_invocation":{"include":["@compiler_optimized"],"scope":"all","strict":true}}'

# Le faux interpréteur : consigne `dossier courant|arguments` dans $CALLS et
# échoue sur le dossier nommé par FAKE_PHP_FAIL_ON, s'il y en a un.
CALLS="$TMP/calls"
FAKE_PHP="$TMP/fake-php"
cat > "$FAKE_PHP" <<'EOF'
#!/bin/sh
printf '%s|%s\n' "$PWD" "$*" >> "$FAKE_PHP_CALLS"
last=""
for arg in "$@"; do last="$arg"; done
[ -n "${FAKE_PHP_FAIL_ON:-}" ] && [ "$last" = "$FAKE_PHP_FAIL_ON" ] && exit 9
exit 0
EOF
chmod +x "$FAKE_PHP"

# Une « release » : le phar sous <base>/v<version>/php-cs-fixer.phar.
releases="$TMP/releases"
mkdir -p "$releases/v$VERSION"
printf 'faux phar %s\n' "$VERSION" > "$releases/v$VERSION/php-cs-fixer.phar"
GOOD_SUM=$(sha256sum "$releases/v$VERSION/php-cs-fixer.phar" | cut -d' ' -f1)
BAD_SUM=$(printf 'autre chose' | sha256sum | cut -d' ' -f1)

# new_project <nom> [version] [somme] : un backend vierge (src/, tests/) et
# son `.env` racine, propres au cas. Affiche le dossier du backend.
new_project() {
  local dir="$TMP/project-$1" version="${2-$VERSION}" sum="${3-$GOOD_SUM}"
  mkdir -p "$dir/backend/src" "$dir/backend/tests"
  printf 'NODE_TAG=x\nPHP_CS_FIXER_VERSION=%s\nPHP_CS_FIXER_SHA256=%s\n' "$version" "$sum" > "$dir/.env"
  printf '%s/backend' "$dir"
}

# run <backend> <base des releases> : lance le script sur ce backend, avec le
# `.env` qui est à côté ; sortie dans $TMP/out, code de sortie dans $status.
status=0
run() {
  local project="$1" base="$2"
  status=0
  : > "$CALLS"
  PHP_CS_FIXER_PROJECT_DIR="$project" \
  PHP_CS_FIXER_ENV_FILE="$(dirname "$project")/.env" \
  PHP_CS_FIXER_BASE_URL="file://$base" \
  PHP_CS_FIXER_PHP="$FAKE_PHP" \
  FAKE_PHP_CALLS="$CALLS" \
    "$SCRIPT" > "$TMP/out" 2>&1 || status=$?
}

output() { cat "$TMP/out"; }
calls()  { cat "$CALLS"; }
phar()   { printf '%s/var/php-cs-fixer-%s.phar' "$1" "${2:-$VERSION}"; }

echo "php-cs-fixer.sh"

# 1. Cas nominal : le phar est téléchargé sous var/, à un nom qui porte la
#    version, puis les deux règles s'appliquent à src/ puis à tests/, depuis
#    le dossier du backend.
project=$(new_project nominal)
run "$project" "$releases"
expected="$project|$(phar "$project") fix --using-cache=no --allow-risky=yes --rules=$RULES src
$project|$(phar "$project") fix --using-cache=no --allow-risky=yes --rules=$RULES tests"
if [ "$status" -ne 0 ]; then
  fail "télécharge le phar puis corrige src/ et tests/" "code $status : $(output)"
elif [ ! -f "$(phar "$project")" ]; then
  fail "télécharge le phar puis corrige src/ et tests/" "phar absent de var/ : $(ls -A "$project/var" 2>&1)"
elif [ "$(calls)" != "$expected" ]; then
  fail "télécharge le phar puis corrige src/ et tests/" "appels : $(calls)"
else
  pass "télécharge le phar puis corrige src/ et tests/"
fi

# 2. Un phar déjà là et sain est réutilisé : la base de releases est vide,
#    tout téléchargement échouerait.
run "$project" "$TMP/empty"
if [ "$status" -eq 0 ] && [ "$(calls)" = "$expected" ]; then
  pass "réutilise un phar présent et sain sans réseau"
else
  fail "réutilise un phar présent et sain sans réseau" "code $status : $(output)"
fi

# 3. Somme fausse au téléchargement : code 3, rien n'est exécuté, et ni le
#    phar ni son fichier temporaire ne restent sous var/.
project=$(new_project bad-sum "$VERSION" "$BAD_SUM")
run "$project" "$releases"
if [ "$status" -ne 3 ]; then
  fail "refuse un phar téléchargé dont la somme diffère (code 3)" "code $status : $(output)"
elif [ -s "$CALLS" ]; then
  fail "refuse un phar téléchargé dont la somme diffère (code 3)" "phar exécuté : $(calls)"
elif [ -n "$(ls -A "$project/var" 2>/dev/null)" ]; then
  fail "refuse un phar téléchargé dont la somme diffère (code 3)" "reste sous var/ : $(ls -A "$project/var")"
elif ! grep -q "$BAD_SUM" "$TMP/out"; then
  fail "refuse un phar téléchargé dont la somme diffère (code 3)" "le message ne nomme pas la somme attendue : $(output)"
else
  pass "refuse un phar téléchargé dont la somme diffère (code 3), sans l'exécuter"
fi

# 4. Un phar présent mais altéré n'est jamais exécuté : il est remplacé par
#    un téléchargement vérifié.
project=$(new_project tampered)
mkdir -p "$project/var"
printf 'altéré\n' > "$(phar "$project")"
run "$project" "$releases"
if [ "$status" -eq 0 ] && cmp -s "$(phar "$project")" "$releases/v$VERSION/php-cs-fixer.phar"; then
  pass "remplace un phar présent mais altéré par un téléchargement vérifié"
else
  fail "remplace un phar présent mais altéré par un téléchargement vérifié" "code $status : $(output)"
fi

# 5. Phar présent mais altéré, et téléchargement impossible : code 2, et le
#    phar altéré n'est toujours pas exécuté.
printf 'altéré\n' > "$(phar "$project")"
run "$project" "$TMP/empty"
if [ "$status" -eq 2 ] && [ ! -s "$CALLS" ]; then
  pass "échoue en téléchargement (code 2) sans exécuter le phar altéré"
else
  fail "échoue en téléchargement (code 2) sans exécuter le phar altéré" "code $status, appels : $(calls) ; $(output)"
fi

# 6. Montée de version dont la somme n'a pas suivi : le phar de l'ancienne
#    version, sain, ne doit pas servir à sa place. Le nom versionné l'écarte,
#    et le nouveau téléchargement échoue à la vérification.
project=$(new_project bump)
run "$project" "$releases"
mkdir -p "$releases/v9.9.9"
printf 'faux phar 9.9.9\n' > "$releases/v9.9.9/php-cs-fixer.phar"
sed -i "s/^PHP_CS_FIXER_VERSION=.*/PHP_CS_FIXER_VERSION=9.9.9/" "$(dirname "$project")/.env"
run "$project" "$releases"
if [ "$status" -eq 3 ] && [ ! -s "$CALLS" ]; then
  pass "une version montée sans sa somme échoue (code 3) au lieu de garder l'ancien phar"
else
  fail "une version montée sans sa somme échoue (code 3) au lieu de garder l'ancien phar" "code $status, appels : $(calls) ; $(output)"
fi

# 7. Échec du fixeur sur src/ : code 4, tests/ n'est pas traité.
project=$(new_project fixer-fails)
FAKE_PHP_FAIL_ON=src run "$project" "$releases"
if [ "$status" -eq 4 ] && [ "$(wc -l < "$CALLS")" -eq 1 ]; then
  pass "un échec du fixeur sur src/ arrête tout (code 4)"
else
  fail "un échec du fixeur sur src/ arrête tout (code 4)" "code $status, appels : $(calls) ; $(output)"
fi

# 8. Configuration : version ou somme absente ou mal formée, code 1, rien
#    n'est téléchargé ni exécuté.
for case in "absente||$GOOD_SUM" "mal formée|v3.95|$GOOD_SUM" "somme absente|$VERSION|" "somme mal formée|$VERSION|${GOOD_SUM:0:63}"; do
  IFS='|' read -r label version sum <<< "$case"
  project=$(new_project "config-${label// /-}" "$version" "$sum")
  run "$project" "$releases"
  if [ "$status" -eq 1 ] && [ ! -s "$CALLS" ] && [ ! -e "$project/var" ]; then
    pass "refuse une configuration invalide (code 1) : $label"
  else
    fail "refuse une configuration invalide (code 1) : $label" "code $status : $(output)"
  fi
done

# 9. La cible du Makefile lance ce script dans le conteneur, en `dev`.
if grep -A2 '^back-style-fix:' "$ROOT/Makefile" | grep -q 'exec -u dev backend /var/www/docker/php/php-cs-fixer.sh'; then
  pass "make back-style-fix lance le script dans le conteneur"
else
  fail "make back-style-fix lance le script dans le conteneur" "cible absente ou différente dans le Makefile"
fi

# 10. versions.lock recopie la version et la somme de `.env` : une montée qui
#     n'en touche qu'un des deux fichiers fait échouer ce test.
env_value()  { sed -n "s/^$1=//p" "$ROOT/.env"; }
lock_value() { sed -n "s/^$1=//p" "$ROOT/versions.lock"; }
if [ -n "$(env_value PHP_CS_FIXER_VERSION)" ] \
  && [ "$(env_value PHP_CS_FIXER_VERSION)" = "$(lock_value php-cs-fixer)" ] \
  && [ -n "$(env_value PHP_CS_FIXER_SHA256)" ] \
  && [ "$(env_value PHP_CS_FIXER_SHA256)" = "$(lock_value php-cs-fixer-sha256)" ]; then
  pass "versions.lock recopie la version et la somme de .env"
else
  fail "versions.lock recopie la version et la somme de .env" \
    ".env : $(env_value PHP_CS_FIXER_VERSION) $(env_value PHP_CS_FIXER_SHA256) ; versions.lock : $(lock_value php-cs-fixer) $(lock_value php-cs-fixer-sha256)"
fi

if [ "$failures" -gt 0 ]; then
  printf '\n%d échec(s)\n' "$failures"
  exit 1
fi
printf '\nTous les tests passent.\n'
