#!/bin/sh
# Génère config.js à partir de la variable d'env API_URL, fournie au runtime
# (Deployment Kubernetes), jamais au build. Voir config.template.js et
# frontend/src/infrastructure/config/getApiUrl.ts.
set -eu

: "${API_URL:?La variable d'environnement API_URL est requise}"

envsubst '${API_URL}' \
    < /usr/share/nginx/config.template.js \
    > /usr/share/nginx/html/config.js

exec "$@"
