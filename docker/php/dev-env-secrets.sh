#!/usr/bin/env bash
#
# dev-env-secrets.sh
# ---------------------------------------------------------------------------
# Pose l'APP_SECRET local du backend (issue #481) : backend/.env le laisse
# vide, à raison (dépôt public), mais rien ne le fournissait en dev. Depuis
# #455, chaque usage dérive sa clé par SecretKeyDerivation, qui refuse un
# secret vide : en dev, le formulaire de contact répondait 500, comme toute
# écriture signée (CSRF, enveloppes Messenger, cache).
#
# Un APP_SECRET aléatoire (32 octets en hexadécimal) est posé, seulement là
# où il manque ou est vide, dans .env.local (dev) et dans .env.test.local
# (test, qui ne lit jamais .env.local), deux valeurs distinctes.
#
# JWT_PASSPHRASE n'est jamais touché : vide, il est légitime (la paire de clés
# locale est générée sans passphrase, cf. .env.test.local), et le traiter
# comme manquant cassait les tests JWT — constaté en écrivant ce script.
#
# Idempotent et muet quand il n'a rien à faire. N'imprime jamais une valeur.
# Réécrit les fichiers en place (même inode, mêmes droits) : un fichier
# remplacé désynchronise la vue du conteneur (cf. CLAUDE.md, bind-mount).
#
# Appelé par init-symfony.sh sur un checkout neuf ; sur un checkout existant :
# `make dev-secrets`.
#
# Usage :  docker/php/dev-env-secrets.sh <répertoire backend>
# ---------------------------------------------------------------------------
set -euo pipefail
# Le fichier temporaire de set_var porte le secret, et un .env.test.local créé
# ici aussi : lisibles par leur seul propriétaire.
umask 077

DIR="${1:?usage : $0 <répertoire backend>}"
DEV="$DIR/.env.local"
TEST="$DIR/.env.test.local"

for file in "$DEV" "$TEST"; do
  if [ ! -f "$file" ]; then
    # Pas de fichier incomplet : init-symfony.sh y écrit aussi DATABASE_URL.
    echo "$file absent : le générer d'abord (make init)." >&2
    exit 1
  fi
done

# Hors du dépôt, et retiré même sur une interruption : il porte le secret.
SCRATCH="$(mktemp)"
trap 'rm -f "$SCRATCH"' EXIT

random_hex() { od -An -tx1 -N32 /dev/urandom | tr -d ' \n'; }

# Valeur de la dernière ligne `[export ]NAME=…` (celle que Dotenv retient),
# lue comme Dotenv : espaces et une paire de guillemets autour retirés, si
# bien que `""`, `''` ou des espaces valent vide.
current() {
  local raw
  raw="$(sed -n "s/^\(export[[:space:]]\{1,\}\)\{0,1\}$2=//p" "$1" | tail -n 1)"
  raw="$(printf '%s' "$raw" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
  case "$raw" in
    \"*\") raw="${raw#\"}"; raw="${raw%\"}" ;;
    \'*\') raw="${raw#\'}"; raw="${raw%\'}" ;;
  esac
  printf '%s' "$raw"
}

# Remplace toutes les lignes de la variable, `export` compris, par une seule,
# en place : même inode, mêmes droits (cf. l'en-tête). Une écriture qui
# échouerait en route laisserait le fichier tronqué : c'est un fichier de dev,
# régénérable par make init, accepté.
set_var() {
  local file="$1" name="$2" value="$3"
  { grep -v "^\(export[[:space:]]\{1,\}\)\{0,1\}$name=" "$file" || true; printf '%s=%s\n' "$name" "$value"; } > "$SCRATCH"
  cat "$SCRATCH" > "$file"
}

for file in "$DEV" "$TEST"; do
  if [ -z "$(current "$file" APP_SECRET)" ]; then
    set_var "$file" APP_SECRET "$(random_hex)"
    echo "APP_SECRET aléatoire posé dans $file."
  fi
done
