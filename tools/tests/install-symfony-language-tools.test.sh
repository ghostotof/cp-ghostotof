#!/usr/bin/env bash
#
# install-symfony-language-tools.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de docker/php/install-symfony-language-tools.sh (#343) : les
# « releases » sont de fausses archives servies en `file://` depuis un dossier
# temporaire, le cache visé est temporaire lui aussi. Aucun appel réseau,
# aucune écriture dans le dépôt courant, aucun CLI Symfony requis.
#
# Usage :  tools/tests/install-symfony-language-tools.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/docker/php/install-symfony-language-tools.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

case "$(uname -m)" in
  x86_64)  ARCH=x64 ;;
  aarch64) ARCH=arm64 ;;
  *) echo "Architecture non supportée par ce test : $(uname -m)" >&2; exit 1 ;;
esac

# make_release <dossier> <version> [version annoncée par le binaire]
# Fabrique, sous <dossier>/v<version>/, l'archive de la plateforme courante et
# son SHA256SUMS, comme une release de symfony/language-tools. Le faux
# `symfony-lsp` ne fait qu'annoncer sa version, ce que le script vérifie.
make_release() {
  local base="$1" version="$2" reported="${3:-$2}"
  local name="symfony-lsp-v${version}-linux-${ARCH}"
  local work="$TMP/build-$RANDOM"
  mkdir -p "$work/$name" "$base/v$version"
  printf '#!/bin/sh\necho "Symfony Language Tools %s"\n' "$reported" > "$work/$name/symfony-lsp"
  chmod +x "$work/$name/symfony-lsp"
  tar -czf "$base/v$version/$name.tar.gz" -C "$work" "$name"
  (cd "$base/v$version" && sha256sum "$name.tar.gz" > SHA256SUMS)
}

# run <cache> <base> [args…] : lance le script, sortie dans $TMP/out, code
# de sortie dans $status (jamais d'arrêt sur échec, c'est ce qu'on teste).
run() {
  local cache="$1" base="$2"; shift 2
  status=0
  SYMFONY_LANGUAGE_TOOLS_CACHE_DIR="$cache" \
  SYMFONY_LANGUAGE_TOOLS_BASE_URL="file://$base" \
    "$SCRIPT" "$@" > "$TMP/out" 2>&1 || status=$?
}

state_version()    { sed -n 's/.*"version":"\([^"]*\)".*/\1/p' "$1/state.json"; }
state_checked_at() { sed -n 's/.*"checkedAt":\([0-9]*\).*/\1/p' "$1/state.json"; }

echo "install-symfony-language-tools.sh"

# 1. Cas nominal : la version demandée est installée là où le CLI la cherche,
#    et state.json la déclare vérifiée à l'instant — le CLI n'interrogera donc
#    pas l'API GitHub pendant 24 h.
releases="$TMP/releases"; make_release "$releases" 0.23.0
cache="$TMP/cache-nominal"
run "$cache" "$releases" 0.23.0
now=$(date +%s)
if [ "$status" -ne 0 ]; then
  fail "installe la version demandée" "code $status : $(cat "$TMP/out")"
elif [ "$("$cache/versions/0.23.0/symfony-lsp" --version)" != "Symfony Language Tools 0.23.0" ]; then
  fail "installe la version demandée" "binaire absent ou mauvaise version dans versions/0.23.0"
elif [ "$(state_version "$cache")" != "0.23.0" ]; then
  fail "installe la version demandée" "state.json : $(cat "$cache/state.json")"
elif [ $((now - $(state_checked_at "$cache"))) -gt 60 ]; then
  fail "installe la version demandée" "checkedAt n'est pas l'instant présent : $(cat "$cache/state.json")"
else
  pass "installe la version demandée et la déclare vérifiée maintenant"
fi

# 2. Déjà installée : aucun téléchargement (la base de releases est vide), mais
#    checkedAt est rafraîchi — sinon, 24 h plus tard, le CLI irait chercher
#    « la dernière stable » et dériverait de la version épinglée.
cache="$TMP/cache-present"
run "$cache" "$releases" 0.23.0
mkdir -p "$cache"
printf '{"version":"0.23.0","checkedAt":1}' > "$cache/state.json"
run "$cache" "$TMP/empty" 0.23.0
if [ "$status" -ne 0 ]; then
  fail "réutilise une installation présente sans réseau" "code $status : $(cat "$TMP/out")"
elif [ "$(state_checked_at "$cache")" -le 1 ]; then
  fail "réutilise une installation présente sans réseau" "checkedAt non rafraîchi : $(cat "$cache/state.json")"
else
  pass "réutilise une installation présente sans réseau et rafraîchit checkedAt"
fi

# 3. Le CLI a migré vers une autre version (lancé à la main hors `make`) : le
#    script remet la version épinglée active.
printf '{"version":"9.9.9","checkedAt":%s}' "$(date +%s)" > "$cache/state.json"
run "$cache" "$TMP/empty" 0.23.0
if [ "$status" -eq 0 ] && [ "$(state_version "$cache")" = "0.23.0" ]; then
  pass "réactive la version épinglée si le CLI en a choisi une autre"
else
  fail "réactive la version épinglée si le CLI en a choisi une autre" "code $status, state.json : $(cat "$cache/state.json")"
fi

# 4. Somme de contrôle fausse : refus, rien d'installé, rien d'activé.
tampered="$TMP/tampered"; make_release "$tampered" 0.23.0
printf '%064d  symfony-lsp-v0.23.0-linux-%s.tar.gz\n' 0 "$ARCH" > "$tampered/v0.23.0/SHA256SUMS"
cache="$TMP/cache-tampered"
run "$cache" "$tampered" 0.23.0
if [ "$status" -eq 0 ]; then
  fail "refuse une archive dont la somme ne correspond pas" "le script a réussi"
elif [ -e "$cache/versions/0.23.0" ] || [ -e "$cache/state.json" ]; then
  fail "refuse une archive dont la somme ne correspond pas" "une installation ou un state.json a été laissé"
elif ! grep -qi 'somme' "$TMP/out"; then
  fail "refuse une archive dont la somme ne correspond pas" "message sans mention de la somme : $(cat "$TMP/out")"
else
  pass "refuse une archive dont la somme ne correspond pas"
fi

# 5. Binaire qui annonce une autre version que celle demandée : refus.
liar="$TMP/liar"; make_release "$liar" 0.23.0 0.22.0
cache="$TMP/cache-liar"
run "$cache" "$liar" 0.23.0
if [ "$status" -ne 0 ] && [ ! -e "$cache/versions/0.23.0" ] && [ ! -e "$cache/state.json" ] \
   && grep -q '0.22.0' "$TMP/out"; then
  pass "refuse un binaire qui annonce une autre version"
else
  fail "refuse un binaire qui annonce une autre version" "code $status, sortie : $(cat "$TMP/out")"
fi

# 6. Installation présente mais corrompue (mauvaise version annoncée) :
#    remplacée par une installation saine.
cache="$TMP/cache-corrupt"
mkdir -p "$cache/versions/0.23.0"
printf '#!/bin/sh\necho "Symfony Language Tools 0.1.0"\n' > "$cache/versions/0.23.0/symfony-lsp"
chmod +x "$cache/versions/0.23.0/symfony-lsp"
run "$cache" "$releases" 0.23.0
if [ "$status" -eq 0 ] && [ "$("$cache/versions/0.23.0/symfony-lsp" --version)" = "Symfony Language Tools 0.23.0" ]; then
  pass "remplace une installation corrompue"
else
  fail "remplace une installation corrompue" "code $status, sortie : $(cat "$TMP/out")"
fi

# 7. Téléchargement impossible : échec explicite, nommé comme tel — c'est ce
#    qui permet au job CI de ne pas le confondre avec un diagnostic.
cache="$TMP/cache-offline"
run "$cache" "$TMP/empty" 0.23.0
if [ "$status" -ne 0 ] && grep -qi 'téléchargement impossible' "$TMP/out"; then
  pass "signale un téléchargement impossible comme tel"
else
  fail "signale un téléchargement impossible comme tel" "code $status, sortie : $(cat "$TMP/out")"
fi

# 8. Sans argument, la version vient du .env à la racine du dépôt, le même
#    qui porte SYMFONY_CLI_VERSION : une seule déclaration pour la CI et le dev.
repo="$TMP/repo"
mkdir -p "$repo/docker/php"
cp "$SCRIPT" "$repo/docker/php/"
printf 'SYMFONY_CLI_VERSION=5.20.0\nSYMFONY_LANGUAGE_TOOLS_VERSION=0.23.0\n' > "$repo/.env"
cache="$TMP/cache-dotenv"
status=0
SYMFONY_LANGUAGE_TOOLS_CACHE_DIR="$cache" SYMFONY_LANGUAGE_TOOLS_BASE_URL="file://$releases" \
  "$repo/docker/php/install-symfony-language-tools.sh" > "$TMP/out" 2>&1 || status=$?
if [ "$status" -eq 0 ] && [ "$(state_version "$cache")" = "0.23.0" ]; then
  pass "lit la version dans le .env du dépôt"
else
  fail "lit la version dans le .env du dépôt" "code $status, sortie : $(cat "$TMP/out")"
fi

# 9. Ni argument ni déclaration dans .env : refus, pas de « dernière version ».
printf 'SYMFONY_CLI_VERSION=5.20.0\n' > "$repo/.env"
status=0
SYMFONY_LANGUAGE_TOOLS_CACHE_DIR="$TMP/cache-none" SYMFONY_LANGUAGE_TOOLS_BASE_URL="file://$releases" \
  "$repo/docker/php/install-symfony-language-tools.sh" > "$TMP/out" 2>&1 || status=$?
if [ "$status" -ne 0 ] && [ ! -e "$TMP/cache-none/state.json" ] \
   && grep -q 'SYMFONY_LANGUAGE_TOOLS_VERSION' "$TMP/out"; then
  pass "refuse de tourner sans version déclarée"
else
  fail "refuse de tourner sans version déclarée" "code $status, sortie : $(cat "$TMP/out")"
fi

if [ "$failures" -gt 0 ]; then
  printf '\n%d échec(s)\n' "$failures"
  exit 1
fi
printf '\nTous les tests passent.\n'
