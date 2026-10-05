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
#   1. installe la version demandée dans `<cache>/versions/<version>/`,
#      téléchargée depuis l'URL versionnée de la release (pas l'API, donc pas
#      sa limite de débit), somme SHA-256 vérifiée contre le `SHA256SUMS` de la
#      même release — le contrôle que fait le CLI lui-même ;
#   2. écrit `<cache>/state.json` = {version, checkedAt: maintenant}. Le CLI
#      voit une installation valide vérifiée il y a moins de 24 h : il l'utilise
#      sans aucun appel réseau.
# Idempotent : une installation déjà présente et saine n'est pas retéléchargée,
# seul `checkedAt` est rafraîchi (sinon, 24 h plus tard, le CLI repartirait
# chercher la dernière stable).
#
# Usage : install-symfony-language-tools.sh [version]
#   Sans argument, la version est lue dans `SYMFONY_LANGUAGE_TOOLS_VERSION` du
#   `.env` à la racine du dépôt (deux niveaux au-dessus de ce script), à côté
#   de `SYMFONY_CLI_VERSION`.
#
# Variables (surcharges destinées aux tests, tools/tests/install-symfony-language-tools.test.sh) :
#   SYMFONY_LANGUAGE_TOOLS_CACHE_DIR  défaut : `symfony lsp:cache-dir`
#   SYMFONY_LANGUAGE_TOOLS_BASE_URL   défaut : URL des releases GitHub
#
# À revoir à chaque montée de SYMFONY_CLI_VERSION : ce script dépend du format
# du cache du CLI (dossier `versions/`, `state.json`, intervalle de 24 h).
# ---------------------------------------------------------------------------
set -euo pipefail

die() { printf 'install-symfony-language-tools: %s\n' "$*" >&2; exit 1; }

version="${1:-}"
if [ -z "$version" ]; then
  env_file="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/.env"
  [ -f "$env_file" ] && version=$(sed -n 's/^SYMFONY_LANGUAGE_TOOLS_VERSION=//p' "$env_file")
  [ -n "$version" ] || die "SYMFONY_LANGUAGE_TOOLS_VERSION absente de $env_file et aucune version passée en argument"
fi
# Le format du dossier de version du CLI : X.Y.Z, sans préfixe `v`.
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "version invalide : « $version » (attendu : X.Y.Z)"

case "$(uname -m)" in
  x86_64)  arch=x64 ;;
  aarch64) arch=arm64 ;;
  *) die "architecture non supportée : $(uname -m)" ;;
esac

cache="${SYMFONY_LANGUAGE_TOOLS_CACHE_DIR:-$(symfony lsp:cache-dir)}"
base_url="${SYMFONY_LANGUAGE_TOOLS_BASE_URL:-https://github.com/symfony/language-tools/releases/download}"
name="symfony-lsp-v${version}-linux-${arch}"
target="$cache/versions/$version"
expected="Symfony Language Tools $version"

# reports_version <exécutable> : vrai si le binaire annonce la version voulue —
# le contrôle même que le CLI applique avant d'utiliser une installation.
reports_version() { [ -x "$1" ] && [ "$("$1" --version 2>/dev/null)" = "$expected" ]; }

mkdir -p "$cache/versions"

if reports_version "$target/symfony-lsp"; then
  echo "Symfony Language Tools $version déjà installé."
else
  work=$(mktemp -d "$cache/.pin-XXXXXX")
  trap 'rm -rf "$work"' EXIT

  echo "Téléchargement de Symfony Language Tools $version ($name)…"
  curl -fsSL --retry 3 -o "$work/$name.tar.gz" "$base_url/v$version/$name.tar.gz" \
    && curl -fsSL --retry 3 -o "$work/SHA256SUMS" "$base_url/v$version/SHA256SUMS" \
    || die "téléchargement impossible de Symfony Language Tools $version depuis $base_url — ce n'est pas un diagnostic du code"

  want=$(awk -v f="$name.tar.gz" '$2 == f || $2 == "*" f { print $1 }' "$work/SHA256SUMS")
  [ -n "$want" ] || die "$name.tar.gz absent du SHA256SUMS de la release $version"
  got=$(sha256sum "$work/$name.tar.gz" | cut -d' ' -f1)
  [ "$got" = "$want" ] || die "somme SHA-256 incorrecte pour $name.tar.gz (attendue $want, obtenue $got) : archive refusée"

  tar -xzf "$work/$name.tar.gz" -C "$work"
  if ! reports_version "$work/$name/symfony-lsp"; then
    die "l'archive $name annonce « $("$work/$name/symfony-lsp" --version 2>&1 || true) » au lieu de « $expected »"
  fi

  # Une installation présente mais corrompue est remplacée, jamais réparée.
  rm -rf "$target"
  mv "$work/$name" "$target"
  echo "Symfony Language Tools $version installé dans $target."
fi

# Activation : écriture atomique (fichier temporaire du même dossier puis
# renommage), comme le CLI le fait de son côté.
state_tmp=$(mktemp "$cache/.state-XXXXXX")
printf '{"version":"%s","checkedAt":%s}' "$version" "$(date +%s)" > "$state_tmp"
chmod 0644 "$state_tmp"
mv "$state_tmp" "$cache/state.json"
