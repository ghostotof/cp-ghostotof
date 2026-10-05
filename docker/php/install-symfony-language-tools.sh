#!/usr/bin/env bash
#
# install-symfony-language-tools.sh
# ---------------------------------------------------------------------------
# Épingle la version des Symfony Language Tools qu'exécute `symfony lsp:check`
# (issue #343). Lancé avant chaque `lsp:check`, en CI comme dans le conteneur
# dev (`make back-lsp`), pour que les deux exécutent exactement la même.
#
# Pourquoi c'est nécessaire : le CLI Symfony (5.20.0) n'a aucune option de
# version. À chaque exécution, si son cache n'a pas été vérifié depuis 24 h,
# il interroge l'API GitHub (`api.github.com/repos/symfony/language-tools/
# releases/latest`, anonyme, 60 requêtes/h par IP partagées entre runners) et
# installe « la dernière stable ». D'où deux défauts : un 403 de limite de débit
# fait échouer le job avant toute analyse, et le résultat change avec les
# releases amont sans qu'une ligne du dépôt bouge.
#
# Ce que fait ce script, en s'appuyant sur le format du cache du CLI
# (`local/externaltool/manager.go`) :
#   1. sous le verrou du CLI (`flock` sur `install.lock`, celui que prend son
#      `Resolve()`), installe la version demandée dans
#      `<cache>/versions/<version>/`, téléchargée depuis l'URL versionnée de la
#      release (pas l'API, donc pas sa limite de débit), somme SHA-256 vérifiée
#      contre le `SHA256SUMS` de la même release — le contrôle que fait le CLI
#      lui-même — et version annoncée par le binaire contrôlée ;
#   2. écrit `<cache>/state.json` = {version, checkedAt: maintenant}. Le CLI
#      voit une installation valide vérifiée il y a moins de 24 h : il l'utilise
#      sans aucun appel réseau.
# Idempotent : une installation déjà présente et saine n'est pas retéléchargée,
# seul `checkedAt` est rafraîchi (sinon, 24 h plus tard, le CLI repartirait
# chercher la dernière stable).
#
# `--verify` se lance APRÈS `lsp:check` : il échoue si le CLI a activé une
# autre version que l'épinglée. C'est le signal qui dit qu'une montée de
# SYMFONY_CLI_VERSION (version minimale relevée, format de cache changé…) a
# rendu cet épinglage inopérant — sans lui, le CLI repartirait silencieusement
# sur l'API GitHub. Il ne télécharge ni ne modifie rien.
#
# Pourquoi ce fichier est sous docker/php/ et pas sous tools/ : c'est le seul
# des deux dossiers monté dans le conteneur dev (`/var/www/docker/php`, voir
# docker-compose.yml), où `make back-lsp` l'exécute. Il n'est copié dans
# aucune image.
#
# Usage : install-symfony-language-tools.sh [--verify] [version]
#   Sans version, elle est lue dans `SYMFONY_LANGUAGE_TOOLS_VERSION` du `.env`
#   à la racine du dépôt (deux niveaux au-dessus de ce script), à côté de
#   `SYMFONY_CLI_VERSION`.
#
# Codes de sortie, lus par le résumé du job CI
# (.github/scripts/summary-language-tools.sh) :
#   0  version épinglée installée et active
#   1  configuration (version absente ou invalide, plateforme, CLI absent,
#      verrou du CLI non obtenu)
#   2  téléchargement impossible — incident réseau, à relancer
#   3  intégrité refusée (somme ou version annoncée) — ne pas relancer
#      à l'aveugle
#   4  (--verify) la version active n'est pas l'épinglée
#
# Variables (surcharges destinées aux tests, tools/tests/install-symfony-language-tools.test.sh) :
#   SYMFONY_LANGUAGE_TOOLS_CACHE_DIR     défaut : `symfony lsp:cache-dir`
#   SYMFONY_LANGUAGE_TOOLS_BASE_URL      défaut : URL des releases GitHub
#   SYMFONY_LANGUAGE_TOOLS_LOCK_TIMEOUT  défaut : 120 (secondes)
#
# À revoir à chaque montée de SYMFONY_CLI_VERSION : ce script dépend du format
# du cache du CLI (dossier `versions/`, `state.json`, intervalle de 24 h,
# `install.lock`). `--verify` est le filet qui le signale.
# ---------------------------------------------------------------------------
set -euo pipefail

readonly EXIT_CONFIG=1 EXIT_DOWNLOAD=2 EXIT_INTEGRITY=3 EXIT_NOT_ACTIVE=4

# fail <code> <message> : sortie avec un code qui dit la nature de l'échec.
fail() {
  local code="$1"; shift
  printf 'install-symfony-language-tools: %s\n' "$*" >&2
  exit "$code"
}

verify=false
if [ "${1:-}" = --verify ]; then
  verify=true
  shift
fi

version="${1:-}"
if [ -z "$version" ]; then
  env_file="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/.env"
  [ -f "$env_file" ] && version=$(sed -n 's/^SYMFONY_LANGUAGE_TOOLS_VERSION=//p' "$env_file")
  [ -n "$version" ] || fail "$EXIT_CONFIG" "SYMFONY_LANGUAGE_TOOLS_VERSION absente de $env_file et aucune version passée en argument"
fi
# Le format du dossier de version du CLI : X.Y.Z, sans préfixe `v`.
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "$EXIT_CONFIG" "version invalide : « $version » (attendu : X.Y.Z)"

# Seules les archives Linux sont gérées : posée dans le cache d'un CLI macOS,
# l'archive Linux serait rejetée par lui, qui repartirait sur le réseau.
[ "$(uname -s)" = Linux ] || fail "$EXIT_CONFIG" "système non supporté : $(uname -s) (Linux uniquement : conteneur dev et CI)"
case "$(uname -m)" in
  x86_64)  arch=x64 ;;
  aarch64) arch=arm64 ;;
  *) fail "$EXIT_CONFIG" "architecture non supportée : $(uname -m)" ;;
esac

if [ -n "${SYMFONY_LANGUAGE_TOOLS_CACHE_DIR:-}" ]; then
  cache="$SYMFONY_LANGUAGE_TOOLS_CACHE_DIR"
else
  command -v symfony > /dev/null || fail "$EXIT_CONFIG" "CLI Symfony introuvable dans le PATH : impossible de connaître son cache (symfony lsp:cache-dir)"
  cache=$(symfony lsp:cache-dir)
fi
target="$cache/versions/$version"
expected="Symfony Language Tools $version"

# reports_version <exécutable> : vrai si le binaire annonce la version voulue —
# le contrôle même que le CLI applique avant d'utiliser une installation.
reports_version() { [ -x "$1" ] && [ "$("$1" --version 2>/dev/null)" = "$expected" ]; }

# --- Vérification après lsp:check -------------------------------------------
if [ "$verify" = true ]; then
  active=""
  [ -f "$cache/state.json" ] && active=$(sed -n 's/.*"version":"\([^"]*\)".*/\1/p' "$cache/state.json")
  if [ "$active" != "$version" ] || ! reports_version "$target/symfony-lsp"; then
    fail "$EXIT_NOT_ACTIVE" "le CLI a activé « ${active:-aucune version} » au lieu de la version épinglée $version : l'épinglage ne tient plus (montée de SYMFONY_CLI_VERSION ? voir l'en-tête de ce script)"
  fi
  echo "Symfony Language Tools $version épinglé et actif."
  exit 0
fi

# --- Installation ------------------------------------------------------------
mkdir -p "$cache/versions"

# Le verrou du CLI : tant qu'il installe ou active une version, on n'écrit
# rien. Attente bornée par une boucle `flock -n`, le `flock` de BusyBox
# (conteneur dev Alpine) n'ayant pas d'option de délai.
exec 9> "$cache/install.lock"
deadline=$(( $(date +%s) + ${SYMFONY_LANGUAGE_TOOLS_LOCK_TIMEOUT:-120} ))
until flock -n 9; do
  [ "$(date +%s)" -lt "$deadline" ] || fail "$EXIT_CONFIG" "verrou du CLI ($cache/install.lock) toujours tenu : une autre commande symfony installe Language Tools, relancer une fois terminée"
  sleep 1
done

if reports_version "$target/symfony-lsp"; then
  echo "Symfony Language Tools $version déjà installé."
else
  name="symfony-lsp-v${version}-linux-${arch}"
  base_url="${SYMFONY_LANGUAGE_TOOLS_BASE_URL:-https://github.com/symfony/language-tools/releases/download}"
  # Sous versions/.install-* : le préfixe que le nettoyage du CLI supprime
  # après 48 h, si un arrêt brutal empêche le `trap` de le faire.
  work=$(mktemp -d "$cache/versions/.install-XXXXXX")
  trap 'rm -rf "$work"' EXIT

  echo "Téléchargement de Symfony Language Tools $version ($name)…"
  curl -fsSL --retry 3 -o "$work/$name.tar.gz" "$base_url/v$version/$name.tar.gz" \
    && curl -fsSL --retry 3 -o "$work/SHA256SUMS" "$base_url/v$version/SHA256SUMS" \
    || fail "$EXIT_DOWNLOAD" "téléchargement impossible de Symfony Language Tools $version depuis $base_url — ce n'est pas un diagnostic du code"

  want=$(awk -v f="$name.tar.gz" '$2 == f || $2 == "*" f { print $1 }' "$work/SHA256SUMS")
  [ -n "$want" ] || fail "$EXIT_INTEGRITY" "$name.tar.gz absent du SHA256SUMS de la release $version"
  got=$(sha256sum "$work/$name.tar.gz" | cut -d' ' -f1)
  [ "$got" = "$want" ] || fail "$EXIT_INTEGRITY" "somme SHA-256 incorrecte pour $name.tar.gz (attendue $want, obtenue $got) : archive refusée"

  tar -xzf "$work/$name.tar.gz" -C "$work"
  if ! reports_version "$work/$name/symfony-lsp"; then
    fail "$EXIT_INTEGRITY" "l'archive $name annonce « $("$work/$name/symfony-lsp" --version 2>&1 || true) » au lieu de « $expected »"
  fi

  # Une installation présente mais corrompue est remplacée, jamais réparée.
  rm -rf "$target"
  mv "$work/$name" "$target"
  echo "Symfony Language Tools $version installé dans $target."
fi

# Activation : écriture atomique (fichier temporaire du même dossier puis
# renommage), comme le CLI le fait de son côté. Le préfixe `.state-` est
# celui qu'il nettoie.
state_tmp=$(mktemp "$cache/.state-XXXXXX")
printf '{"version":"%s","checkedAt":%s}' "$version" "$(date +%s)" > "$state_tmp"
chmod 0644 "$state_tmp"
mv "$state_tmp" "$cache/state.json"
