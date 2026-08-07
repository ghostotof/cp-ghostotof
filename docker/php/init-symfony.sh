#!/usr/bin/env bash
# =============================================================================
# Initialise le projet Symfony dans /var/www/backend (monté depuis l'hôte).
# Idempotent : si composer.json existe déjà, le script ne fait rien.
# =============================================================================
set -Eeuo pipefail

APP_DIR="/var/www/backend"
cd "$APP_DIR"

if [[ -f composer.json ]]; then
  echo "→ Projet Symfony déjà initialisé, rien à faire."
  exit 0
fi

: "${SYMFONY_VERSION:?SYMFONY_VERSION doit être défini}"
: "${BACKEND_FLAVOR:=full}"

echo "→ Création d'un projet Symfony ${SYMFONY_VERSION} (mode : ${BACKEND_FLAVOR})"

# `symfony new` exige un répertoire cible vide et le crée lui-même. On passe par
# un dossier temporaire puis on déplace le contenu, ce qui évite tout conflit
# avec le point de montage (et les éventuels fichiers déjà présents).
TMP_DIR="$(mktemp -d)"
rmdir "$TMP_DIR"

NEW_ARGS=( "$TMP_DIR" "--version=${SYMFONY_VERSION}" "--no-git" )
# --webapp installe le pack complet (Twig, AssetMapper, formulaires, sécurité…).
# Sans ce flag on obtient le squelette minimal, base idéale d'une API.
[[ "$BACKEND_FLAVOR" == "full" ]] && NEW_ARGS+=( "--webapp" )

symfony new "${NEW_ARGS[@]}"

# Déplacement du contenu, fichiers cachés compris (.env, .gitignore…).
shopt -s dotglob
mv "$TMP_DIR"/* "$APP_DIR"/
shopt -u dotglob
rmdir "$TMP_DIR"

if [[ "$BACKEND_FLAVOR" == "api" ]]; then
  echo "→ Installation d'API Platform ${API_PLATFORM_VERSION} + strict nécessaire (BDD, Messenger)"
  # Version exacte figée : on garde la maîtrise du numéro installé.
  composer require --no-interaction "api-platform/symfony:${API_PLATFORM_VERSION}"
  # api-platform/symfony n'embarque plus le pont Doctrine ORM (paquet séparé
  # depuis la 3.2). Il doit être requis dans la même commande que orm-pack :
  # sinon le cache:clear post-install de orm-pack tourne avec API Platform
  # déjà actif mais sans le pont, et casse sur « Doctrine support cannot be
  # enabled as the doctrine ORM component is not installed ».
  composer require --no-interaction symfony/orm-pack api-platform/doctrine-orm
  composer require --no-interaction symfony/messenger symfony/amqp-messenger
  composer require --no-interaction --dev symfony/maker-bundle
else
  echo "→ Ajout du support Messenger/AMQP au projet full-stack"
  composer require --no-interaction symfony/amqp-messenger
fi

# -----------------------------------------------------------------------------
# Configuration locale : .env.local n'est pas versionné et surcharge .env.
# On y pointe les DSN vers les noms de services Docker (`database`, `rabbitmq`),
# résolus par le DNS interne du réseau Compose.
# -----------------------------------------------------------------------------
cat > .env.local <<LOCALENV
# Généré automatiquement à l'initialisation du projet — non versionné.
APP_ENV=dev
DATABASE_URL="postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}?serverVersion=16&charset=utf8"
MESSENGER_TRANSPORT_DSN="amqp://${RABBITMQ_USER}:${RABBITMQ_PASSWORD}@rabbitmq:5672/%2f/messages"
LOCALENV

echo "✔ Projet Symfony initialisé."
