#!/usr/bin/env bash
#
# install-symfony-language-tools.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de docker/php/install-symfony-language-tools.sh (#343) : les
# « releases » sont de fausses archives servies en `file://` depuis un dossier
# temporaire, chaque cas a son propre cache temporaire. Aucun appel réseau,
# aucune écriture dans le dépôt courant, aucun CLI Symfony requis.
#
# Codes de sortie attendus du script : 1 configuration, 2 téléchargement
# impossible, 3 intégrité refusée, 4 version épinglée non active (--verify).
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

# Volontairement recopié du script plutôt que partagé : le test ne doit pas
# dépendre du code qu'il vérifie.
case "$(uname -m)" in
  x86_64)  ARCH=x64 ;;
  aarch64) ARCH=arm64 ;;
  *) echo "Architecture non supportée par ce test : $(uname -m)" >&2; exit 1 ;;
esac

# Chaque appel au faux `symfony-lsp` est consigné ici (son propre chemin), ce
# qui permet de vérifier où le script extrait l'archive.
INVOCATIONS="$TMP/invocations"

# make_release <dossier> <version> [version annoncée par le binaire]
# Fabrique, sous <dossier>/v<version>/, l'archive de la plateforme courante et
# son SHA256SUMS, comme une release de symfony/language-tools.
make_release() {
  local base="$1" version="$2" reported="${3:-$2}"
  local name="symfony-lsp-v${version}-linux-${ARCH}"
  local work
  work=$(mktemp -d "$TMP/build-XXXXXX")
  mkdir -p "$work/$name" "$base/v$version"
  printf '#!/bin/sh\necho "$0" >> "%s"\necho "Symfony Language Tools %s"\n' "$INVOCATIONS" "$reported" \
    > "$work/$name/symfony-lsp"
  chmod +x "$work/$name/symfony-lsp"
  tar -czf "$base/v$version/$name.tar.gz" -C "$work" "$name"
  (cd "$base/v$version" && sha256sum "$name.tar.gz" > SHA256SUMS)
}

# new_cache <nom> : chemin d'un cache vierge propre au cas.
new_cache() { printf '%s/cache-%s' "$TMP" "$1"; }

# run <script> <cache> <base> [args…] : lance le script avec ce cache et ces
# releases ; sortie dans $TMP/out, code de sortie dans $status. N'arrête jamais
# le test sur un échec, puisque c'est ce qu'on vérifie.
status=0
run() {
  local script="$1" cache="$2" base="$3"; shift 3
  status=0
  SYMFONY_LANGUAGE_TOOLS_CACHE_DIR="$cache" \
  SYMFONY_LANGUAGE_TOOLS_BASE_URL="file://$base" \
    "$script" "$@" > "$TMP/out" 2>&1 || status=$?
}

state_version()    { sed -n 's/.*"version":"\([^"]*\)".*/\1/p' "$1/state.json"; }
state_checked_at() { sed -n 's/.*"checkedAt":\([0-9]*\).*/\1/p' "$1/state.json"; }
output()           { cat "$TMP/out"; }

releases="$TMP/releases"; make_release "$releases" 0.23.0

echo "install-symfony-language-tools.sh"

# 1. Cas nominal : la version demandée est installée là où le CLI la cherche,
#    et state.json la déclare vérifiée à l'instant — le CLI n'interrogera donc
#    pas l'API GitHub pendant 24 h.
cache=$(new_cache nominal)
run "$SCRIPT" "$cache" "$releases" 0.23.0
now=$(date +%s)
if [ "$status" -ne 0 ]; then
  fail "installe la version demandée" "code $status : $(output)"
elif [ "$("$cache/versions/0.23.0/symfony-lsp" --version)" != "Symfony Language Tools 0.23.0" ]; then
  fail "installe la version demandée" "binaire absent ou mauvaise version dans versions/0.23.0"
elif [ "$(state_version "$cache")" != "0.23.0" ]; then
  fail "installe la version demandée" "state.json : $(cat "$cache/state.json")"
elif [ $((now - $(state_checked_at "$cache"))) -gt 60 ]; then
  fail "installe la version demandée" "checkedAt n'est pas l'instant présent : $(cat "$cache/state.json")"
else
  pass "installe la version demandée et la déclare vérifiée maintenant"
fi

# 2. L'archive est extraite sous versions/.install-*, le préfixe que le
#    nettoyage du CLI ramasse après 48 h : un script tué net (job annulé) ne
#    laisse pas de déchet permanent à la racine du cache.
first_call=$(head -n 1 "$INVOCATIONS")
case "$first_call" in
  "$cache/versions/.install-"*) pass "extrait l'archive sous versions/.install-*" ;;
  *) fail "extrait l'archive sous versions/.install-*" "binaire vérifié depuis : $first_call" ;;
esac

# 3. Déjà installée : aucun téléchargement (la base de releases est vide), mais
#    checkedAt est rafraîchi — sinon, 24 h plus tard, le CLI irait chercher
#    « la dernière stable » et dériverait de la version épinglée.
cache=$(new_cache present)
run "$SCRIPT" "$cache" "$releases" 0.23.0
printf '{"version":"0.23.0","checkedAt":1}' > "$cache/state.json"
run "$SCRIPT" "$cache" "$TMP/empty" 0.23.0
if [ "$status" -ne 0 ]; then
  fail "réutilise une installation présente sans réseau" "code $status : $(output)"
elif [ "$(state_checked_at "$cache")" -le 1 ]; then
  fail "réutilise une installation présente sans réseau" "checkedAt non rafraîchi : $(cat "$cache/state.json")"
else
  pass "réutilise une installation présente sans réseau et rafraîchit checkedAt"
fi

# 4. Le CLI a migré vers une autre version (lancé à la main hors `make`) : le
#    script remet la version épinglée active.
cache=$(new_cache drifted)
run "$SCRIPT" "$cache" "$releases" 0.23.0
printf '{"version":"9.9.9","checkedAt":%s}' "$(date +%s)" > "$cache/state.json"
run "$SCRIPT" "$cache" "$TMP/empty" 0.23.0
if [ "$status" -eq 0 ] && [ "$(state_version "$cache")" = "0.23.0" ]; then
  pass "réactive la version épinglée si le CLI en a choisi une autre"
else
  fail "réactive la version épinglée si le CLI en a choisi une autre" "code $status, state.json : $(cat "$cache/state.json")"
fi

# 5. Somme de contrôle fausse : refus d'intégrité (code 3), rien d'installé,
#    rien d'activé. Ce n'est pas un incident réseau à relancer.
tampered="$TMP/tampered"; make_release "$tampered" 0.23.0
printf '%064d  symfony-lsp-v0.23.0-linux-%s.tar.gz\n' 0 "$ARCH" > "$tampered/v0.23.0/SHA256SUMS"
cache=$(new_cache tampered)
run "$SCRIPT" "$cache" "$tampered" 0.23.0
if [ "$status" -ne 3 ]; then
  fail "refuse une archive dont la somme ne correspond pas (code 3)" "code $status : $(output)"
elif [ -e "$cache/versions/0.23.0" ] || [ -e "$cache/state.json" ]; then
  fail "refuse une archive dont la somme ne correspond pas (code 3)" "une installation ou un state.json a été laissé"
elif ! grep -qi 'somme' "$TMP/out"; then
  fail "refuse une archive dont la somme ne correspond pas (code 3)" "message sans mention de la somme : $(output)"
else
  pass "refuse une archive dont la somme ne correspond pas (code 3)"
fi

# 6. Binaire qui annonce une autre version que celle demandée : refus
#    d'intégrité (code 3).
liar="$TMP/liar"; make_release "$liar" 0.23.0 0.22.0
cache=$(new_cache liar)
run "$SCRIPT" "$cache" "$liar" 0.23.0
if [ "$status" -eq 3 ] && [ ! -e "$cache/versions/0.23.0" ] && [ ! -e "$cache/state.json" ] \
   && grep -q '0.22.0' "$TMP/out"; then
  pass "refuse un binaire qui annonce une autre version (code 3)"
else
  fail "refuse un binaire qui annonce une autre version (code 3)" "code $status : $(output)"
fi

# 7. Installation présente mais corrompue (mauvaise version annoncée) :
#    remplacée par une installation saine.
cache=$(new_cache corrupt)
mkdir -p "$cache/versions/0.23.0"
printf '#!/bin/sh\necho "Symfony Language Tools 0.1.0"\n' > "$cache/versions/0.23.0/symfony-lsp"
chmod +x "$cache/versions/0.23.0/symfony-lsp"
run "$SCRIPT" "$cache" "$releases" 0.23.0
if [ "$status" -eq 0 ] && [ "$("$cache/versions/0.23.0/symfony-lsp" --version)" = "Symfony Language Tools 0.23.0" ]; then
  pass "remplace une installation corrompue"
else
  fail "remplace une installation corrompue" "code $status : $(output)"
fi

# 8. Téléchargement impossible : code 2, nommé comme tel — c'est ce qui
#    permet au job CI de ne pas le confondre avec un diagnostic ni avec un
#    refus d'intégrité.
cache=$(new_cache offline)
run "$SCRIPT" "$cache" "$TMP/empty" 0.23.0
if [ "$status" -eq 2 ] && grep -qi 'téléchargement impossible' "$TMP/out"; then
  pass "signale un téléchargement impossible comme tel (code 2)"
else
  fail "signale un téléchargement impossible comme tel (code 2)" "code $status : $(output)"
fi

# 9. Le CLI tient son verrou d'installation (flock sur install.lock) : le
#    script l'attend, puis abandonne au-delà du délai plutôt que de modifier
#    le cache en même temps que lui.
cache=$(new_cache locked)
mkdir -p "$cache"
flock "$cache/install.lock" sleep 5 &
holder=$!
sleep 0.3
status=0
SYMFONY_LANGUAGE_TOOLS_LOCK_TIMEOUT=1 SYMFONY_LANGUAGE_TOOLS_CACHE_DIR="$cache" \
SYMFONY_LANGUAGE_TOOLS_BASE_URL="file://$releases" \
  "$SCRIPT" 0.23.0 > "$TMP/out" 2>&1 || status=$?
kill "$holder" 2>/dev/null || true
wait "$holder" 2>/dev/null || true
if [ "$status" -eq 1 ] && grep -qi 'verrou' "$TMP/out" && [ ! -e "$cache/state.json" ]; then
  pass "n'écrit pas dans le cache tant que le CLI tient son verrou"
else
  fail "n'écrit pas dans le cache tant que le CLI tient son verrou" "code $status : $(output)"
fi

# 10. Sans argument, la version vient du .env à la racine du dépôt, le même
#     qui porte SYMFONY_CLI_VERSION : une seule déclaration pour la CI et le dev.
repo="$TMP/repo"
mkdir -p "$repo/docker/php"
cp "$SCRIPT" "$repo/docker/php/"
printf 'SYMFONY_CLI_VERSION=5.20.0\nSYMFONY_LANGUAGE_TOOLS_VERSION=0.23.0\n' > "$repo/.env"
cache=$(new_cache dotenv)
run "$repo/docker/php/install-symfony-language-tools.sh" "$cache" "$releases"
if [ "$status" -eq 0 ] && [ "$(state_version "$cache")" = "0.23.0" ]; then
  pass "lit la version dans le .env du dépôt"
else
  fail "lit la version dans le .env du dépôt" "code $status : $(output)"
fi

# 11. Ni argument ni déclaration dans .env : refus de configuration (code 1),
#     jamais de repli sur « la dernière version ».
printf 'SYMFONY_CLI_VERSION=5.20.0\n' > "$repo/.env"
cache=$(new_cache none)
run "$repo/docker/php/install-symfony-language-tools.sh" "$cache" "$releases"
if [ "$status" -eq 1 ] && [ ! -e "$cache/state.json" ] \
   && grep -q 'SYMFONY_LANGUAGE_TOOLS_VERSION' "$TMP/out"; then
  pass "refuse de tourner sans version déclarée (code 1)"
else
  fail "refuse de tourner sans version déclarée (code 1)" "code $status : $(output)"
fi

# 12. Hors Linux (un poste macOS) : refus explicite. L'archive Linux posée
#     dans le cache d'un CLI macOS serait rejetée par lui, qui repartirait
#     sur le réseau — l'épinglage serait vain sans que rien ne le dise.
shim="$TMP/shim-darwin"; mkdir -p "$shim"
printf '#!/bin/sh\n[ "$1" = -s ] && { echo Darwin; exit 0; }\nexec %s "$@"\n' "$(command -v uname)" > "$shim/uname"
chmod +x "$shim/uname"
cache=$(new_cache darwin)
status=0
PATH="$shim:$PATH" SYMFONY_LANGUAGE_TOOLS_CACHE_DIR="$cache" SYMFONY_LANGUAGE_TOOLS_BASE_URL="file://$releases" \
  "$SCRIPT" 0.23.0 > "$TMP/out" 2>&1 || status=$?
if [ "$status" -eq 1 ] && grep -q 'Darwin' "$TMP/out" && [ ! -e "$cache/state.json" ]; then
  pass "refuse de tourner hors Linux (code 1)"
else
  fail "refuse de tourner hors Linux (code 1)" "code $status : $(output)"
fi

# 13. Sans surcharge du cache et sans CLI Symfony dans le PATH : refus de
#     configuration (code 1) qui nomme le CLI, pas un « command not found »
#     brut (127) que le résumé CI prendrait pour un téléchargement raté.
bare="$TMP/bare-path"; mkdir -p "$bare"
for tool in bash dirname sed uname mkdir; do ln -s "$(command -v "$tool")" "$bare/$tool"; done
status=0
PATH="$bare" "$SCRIPT" 0.23.0 > "$TMP/out" 2>&1 || status=$?
if [ "$status" -eq 1 ] && grep -q 'CLI Symfony' "$TMP/out"; then
  pass "refuse de tourner sans CLI Symfony quand le cache n'est pas imposé (code 1)"
else
  fail "refuse de tourner sans CLI Symfony quand le cache n'est pas imposé (code 1)" "code $status : $(output)"
fi

echo "install-symfony-language-tools.sh --verify"

# 14. --verify après une installation : la version épinglée est active.
cache=$(new_cache verify-ok)
run "$SCRIPT" "$cache" "$releases" 0.23.0
run "$SCRIPT" "$cache" "$TMP/empty" --verify 0.23.0
if [ "$status" -eq 0 ]; then
  pass "--verify confirme la version épinglée active"
else
  fail "--verify confirme la version épinglée active" "code $status : $(output)"
fi

# 15. --verify quand le CLI a activé une autre version (montée du CLI qui
#     rejette l'épinglage, par exemple) : code 4, qui nomme les deux versions.
printf '{"version":"0.24.0","checkedAt":%s}' "$(date +%s)" > "$cache/state.json"
run "$SCRIPT" "$cache" "$TMP/empty" --verify 0.23.0
if [ "$status" -eq 4 ] && grep -q '0.24.0' "$TMP/out" && grep -q '0.23.0' "$TMP/out"; then
  pass "--verify détecte que le CLI a activé une autre version (code 4)"
else
  fail "--verify détecte que le CLI a activé une autre version (code 4)" "code $status : $(output)"
fi

# 16. --verify sans state.json : rien n'est actif, code 4 aussi.
cache=$(new_cache verify-empty)
mkdir -p "$cache"
run "$SCRIPT" "$cache" "$TMP/empty" --verify 0.23.0
if [ "$status" -eq 4 ]; then
  pass "--verify échoue quand aucune version n'est active (code 4)"
else
  fail "--verify échoue quand aucune version n'est active (code 4)" "code $status : $(output)"
fi

# 17. --verify ne télécharge ni ne modifie rien : il constate.
before=$(cat "$TMP/cache-verify-ok/state.json")
run "$SCRIPT" "$TMP/cache-verify-ok" "$TMP/empty" --verify 0.23.0
if [ "$(cat "$TMP/cache-verify-ok/state.json")" = "$before" ]; then
  pass "--verify ne modifie pas state.json"
else
  fail "--verify ne modifie pas state.json" "$(cat "$TMP/cache-verify-ok/state.json")"
fi

if [ "$failures" -gt 0 ]; then
  printf '\n%d échec(s)\n' "$failures"
  exit 1
fi
printf '\nTous les tests passent.\n'
