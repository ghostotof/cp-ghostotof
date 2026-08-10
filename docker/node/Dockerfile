# syntax=docker/dockerfile:1
# =============================================================================
# Frontend — image déployable
# -----------------------------------------------------------------------------
#  Cibles (--target) :
#    production  nginx servant le bundle Vite compilé et minifié.
#    preprod     production + fichiers .map, pour des stacks lisibles.
#
#  Le principe appliqué au backend est conservé : `preprod` est construit
#  FROM production. Les fichiers JS/CSS servis sont donc BIT À BIT IDENTIQUES
#  entre les deux images — seules s'ajoutent les source maps, qui ne modifient
#  pas le bundle exécuté. On valide bien l'artefact que l'on déploiera.
#
#  Contexte de build : racine du projet (cf. .dockerignore).
# =============================================================================

ARG NODE_TAG
ARG NGINX_TAG

# =============================================================================
# Stage : deps — installation des dépendances npm
# Isolé pour le cache : tant que package-lock.json ne change pas, cette couche
# n'est pas rejouée, même si tout le code source est modifié.
# =============================================================================
FROM node:${NODE_TAG} AS deps

WORKDIR /app

# `npm ci` (et non `npm install`) : installation strictement conforme au
# lockfile, reproductible, et qui échoue si le lock est désynchronisé.
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

# =============================================================================
# Stage : build — compilation Vite
# =============================================================================
FROM node:${NODE_TAG} AS build

WORKDIR /app

# Pas de VITE_API_URL ici : l'URL de l'API est injectée au RUNTIME (variable
# d'env API_URL du conteneur, voir docker-entrypoint.sh), pas au build. Le
# bundle produit est donc identique quel que soit l'environnement cible —
# c'est ce qui permet de le construire une seule fois et de le promouvoir de
# la préprod vers la prod, comme pour le backend.
COPY --from=deps /app/node_modules ./node_modules
COPY frontend/ ./

# Les source maps sont générées SYSTÉMATIQUEMENT, y compris pour la production.
# Elles ne changent pas le bundle : ce sont des fichiers séparés. On les isole
# ensuite pour ne les embarquer que dans l'image de préprod.
RUN set -eux; \
    npm run build -- --sourcemap; \
    mkdir -p /out/app /out/maps; \
    cp -a dist/. /out/app/; \
    # Déplacement (et non copie) des .map hors du répertoire servi en prod.
    find /out/app -name '*.map' -exec sh -c \
        'mkdir -p "/out/maps/$(dirname "${1#/out/app/}")"; mv "$1" "/out/maps/${1#/out/app/}"' _ {} \;

# =============================================================================
# Stage : production — nginx servant les fichiers statiques
# =============================================================================
FROM nginx:${NGINX_TAG} AS production

# Port non privilégié : permet de tourner en utilisateur non-root, donc
# `runAsNonRoot: true` côté Kubernetes.
EXPOSE 8080

COPY docker/node/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /out/app /usr/share/nginx/html

# gettext fournit envsubst, utilisé par docker-entrypoint.sh pour générer
# config.js à partir de la variable d'env API_URL au démarrage du conteneur.
RUN apk add --no-cache gettext

COPY docker/node/config.template.js /usr/share/nginx/config.template.js
COPY docker/node/docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

# nginx a besoin d'écrire son PID et ses caches temporaires. On les redirige
# vers /tmp afin que le reste du système de fichiers puisse rester en lecture
# seule (readOnlyRootFilesystem).
RUN set -eux; \
    sed -i 's|^pid .*|pid /tmp/nginx.pid;|' /etc/nginx/nginx.conf; \
    chown -R nginx:nginx /usr/share/nginx/html /var/cache/nginx

USER nginx

HEALTHCHECK --interval=15s --timeout=3s --start-period=5s --retries=3 \
    CMD ["wget", "-qO-", "http://127.0.0.1:8080/healthz"]

ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]

# =============================================================================
# Stage : preprod — production + source maps
# =============================================================================
FROM production AS preprod

USER root
# Seul ajout : les .map, qui rendent les stacks d'erreur lisibles dans les
# outils de suivi (Sentry, console navigateur) sans toucher au code exécuté.
COPY --from=build --chown=nginx:nginx /out/maps /usr/share/nginx/html
USER nginx
