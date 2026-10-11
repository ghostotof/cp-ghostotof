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

if [ ! -f "$DEV" ]; then
  echo "$DEV absent : initialiser le projet d'abord (make init)." >&2
  exit 1
fi
if [ ! -f "$TEST" ]; then
  : > "$TEST"
fi

random_hex() { od -An -tx1 -N32 /dev/urandom | tr -d ' \n'; }

# Dernière valeur d'une variable (celle que Dotenv retient), vide si absente.
current() { sed -n "s/^$2=//p" "$1" | tail -n 1; }

# Remplace toutes les lignes de la variable par une seule, en place.
set_var() {
  local file="$1" name="$2" value="$3" kept
  kept="$(grep -v "^$name=" "$file" || true)"
  {
    if [ -n "$kept" ]; then printf '%s\n' "$kept"; fi
    printf '%s=%s\n' "$name" "$value"
  } > "$file.dev-env-secrets.tmp"
  cat "$file.dev-env-secrets.tmp" > "$file"
  rm -f "$file.dev-env-secrets.tmp"
}

for file in "$DEV" "$TEST"; do
  if [ -z "$(current "$file" APP_SECRET)" ]; then
    set_var "$file" APP_SECRET "$(random_hex)"
    echo "APP_SECRET aléatoire posé dans $file."
  fi
done
