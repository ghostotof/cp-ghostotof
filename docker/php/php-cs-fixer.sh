#!/usr/bin/env bash
#
# php-cs-fixer.sh
# ---------------------------------------------------------------------------
# La correction ponctuelle de `make back-style-fix` (issue #398) : trie les
# `use` et qualifie les fonctions natives que le compilateur optimise, dans
# `backend/src/` puis `backend/tests/`. C'est ce que vérifient les deux gardes
# PHPUnit, `ImportsStaySortedTest` et `NativeCallsQualificationTest`, dont le
# message d'échec renvoie ici. `rector:fix` insère chaque nouvel import en tête
# du bloc : lancer la cible après lui.
#
# php-cs-fixer n'est volontairement pas une dépendance Composer (#391). Son
# phar est posé sous `backend/var/` (non versionné), à la version épinglée par
# PHP_CS_FIXER_VERSION, et n'est exécuté qu'après vérification de sa somme
# PHP_CS_FIXER_SHA256. Les deux valeurs vivent dans le `.env` à la racine du
# dépôt (deux niveaux au-dessus de ce script), recopiées dans `versions.lock` ;
# la release ne publie pas de somme, seulement une signature GPG, d'où une
# somme épinglée par nos soins (voir le commentaire de `.env`).
#
# Le phar porte la version dans son nom (`php-cs-fixer-<version>.phar`) : après
# une montée de PHP_CS_FIXER_VERSION dont la somme n'aurait pas suivi, l'ancien
# phar, pourtant sain, n'est pas réutilisé en silence ; le téléchargement de la
# nouvelle version échoue à la vérification. Le nom doit garder
# `php-cs-fixer` : en analyse parallèle, php-cs-fixer retrouve son propre
# exécutable par `argv[0]` quand il tourne depuis un phar, et échoue sinon
# (`Cannot determine Fixer executable`, constaté le 2026-10-12).
#
# Les deux règles sont celles que les gardes reproduisent, à cette version :
#   - `ordered_imports` (tri `alpha`), mimé par tests/Support/UnsortedImports ;
#   - `native_function_invocation` (`include: ['@compiler_optimized']`,
#     `scope: all`, `strict: true`, risquée), mimé par
#     tests/Support/MisqualifiedNativeCalls. `strict` retire aussi un `\`
#     superflu. Changer de version, c'est relire les deux recenseurs et
#     recopier `MisqualifiedNativeCalls::COMPILER_OPTIMIZED`.
# Un `fix` par dossier : php-cs-fixer n'accepte plusieurs chemins qu'avec un
# fichier de configuration.
#
# Pourquoi ce fichier est sous docker/php/ et pas sous tools/ : c'est le seul
# des deux dossiers monté dans le conteneur dev (`/var/www/docker/php`, voir
# docker-compose.yml), où `make back-style-fix` l'exécute. Il n'est copié dans
# aucune image.
#
# Usage : php-cs-fixer.sh
#
# Codes de sortie :
#   0  correction appliquée (ou rien à corriger)
#   1  configuration (version ou somme absente ou mal formée)
#   2  téléchargement impossible — incident réseau, à relancer
#   3  intégrité refusée (somme) — le phar n'a pas été exécuté ; ne pas
#      relancer à l'aveugle
#   4  php-cs-fixer a échoué
#
# Variables (surcharges destinées aux tests, tools/tests/php-cs-fixer.test.sh) :
#   PHP_CS_FIXER_ENV_FILE     défaut : `.env` à la racine du dépôt
#   PHP_CS_FIXER_PROJECT_DIR  défaut : `backend/` à la racine du dépôt
#   PHP_CS_FIXER_BASE_URL     défaut : URL des releases GitHub
#   PHP_CS_FIXER_PHP          défaut : php
# ---------------------------------------------------------------------------
set -euo pipefail

readonly EXIT_CONFIG=1 EXIT_DOWNLOAD=2 EXIT_INTEGRITY=3 EXIT_FIXER=4

# Les règles, en JSON pour `--rules`. Pas de caractère spécial au shell une
# fois entre guillemets.
readonly RULES='{"ordered_imports":true,"native_function_invocation":{"include":["@compiler_optimized"],"scope":"all","strict":true}}'

# fail <code> <message> : sortie avec un code qui dit la nature de l'échec.
fail() {
  local code="$1"; shift
  printf 'php-cs-fixer: %s\n' "$*" >&2
  exit "$code"
}

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
env_file="${PHP_CS_FIXER_ENV_FILE:-$root/.env}"
project="${PHP_CS_FIXER_PROJECT_DIR:-$root/backend}"

# --- Configuration -----------------------------------------------------------
[ -f "$env_file" ] || fail "$EXIT_CONFIG" "$env_file introuvable : il porte PHP_CS_FIXER_VERSION et PHP_CS_FIXER_SHA256"
version=$(sed -n 's/^PHP_CS_FIXER_VERSION=//p' "$env_file")
sum=$(sed -n 's/^PHP_CS_FIXER_SHA256=//p' "$env_file")
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
  || fail "$EXIT_CONFIG" "PHP_CS_FIXER_VERSION invalide dans $env_file : « $version » (attendu : X.Y.Z)"
[[ "$sum" =~ ^[0-9a-f]{64}$ ]] \
  || fail "$EXIT_CONFIG" "PHP_CS_FIXER_SHA256 invalide dans $env_file : « $sum » (attendu : 64 caractères hexadécimaux minuscules)"

phar="$project/var/php-cs-fixer-$version.phar"

# sha256_of <fichier> : la somme seule. `cut` plutôt qu'une option de
# `sha256sum` : celui du conteneur dev est celui de BusyBox.
sha256_of() { sha256sum "$1" | cut -d' ' -f1; }

# --- Phar vérifié --------------------------------------------------------------
if [ -f "$phar" ] && [ "$(sha256_of "$phar")" = "$sum" ]; then
  echo "php-cs-fixer $version déjà présent, somme vérifiée."
else
  # Un phar présent mais qui ne concorde pas n'est jamais exécuté : il est
  # remplacé par un téléchargement vérifié, jamais réparé.
  base_url="${PHP_CS_FIXER_BASE_URL:-https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download}"
  mkdir -p "$project/var"
  # Fichier temporaire dans le même dossier, pour un renommage atomique : le
  # chemin final ne désigne jamais un phar non vérifié.
  work=$(mktemp "$project/var/.php-cs-fixer-XXXXXX")
  trap 'rm -f "$work"' EXIT

  echo "Téléchargement de php-cs-fixer $version…"
  curl -fsSL --retry 3 -o "$work" "$base_url/v$version/php-cs-fixer.phar" \
    || fail "$EXIT_DOWNLOAD" "téléchargement impossible de php-cs-fixer $version depuis $base_url — ce n'est pas un défaut du code, relancer"

  got=$(sha256_of "$work")
  [ "$got" = "$sum" ] \
    || fail "$EXIT_INTEGRITY" "somme SHA-256 incorrecte pour php-cs-fixer $version (attendue $sum, obtenue $got) : phar refusé, rien n'a été exécuté. Une montée de PHP_CS_FIXER_VERSION demande de recalculer PHP_CS_FIXER_SHA256 sur un phar dont la signature GPG a été vérifiée (voir .env)"

  mv "$work" "$phar"
  echo "php-cs-fixer $version installé dans $phar."
fi

# --- Correction ----------------------------------------------------------------
cd "$project"
for directory in src tests; do
  "${PHP_CS_FIXER_PHP:-php}" "$phar" fix --using-cache=no --allow-risky=yes "--rules=$RULES" "$directory" \
    || fail "$EXIT_FIXER" "php-cs-fixer a échoué sur $directory/ (voir sa sortie ci-dessus)"
done
