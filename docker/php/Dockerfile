# syntax=docker/dockerfile:1
# =============================================================================
# Image applicative PHP-FPM — multi-stage
# -----------------------------------------------------------------------------
#  Cibles disponibles (--target) :
#
#    dev         Poste de développement. Code monté en bind mount, symfony-cli,
#                UID/GID alignés sur l'hôte, opcache en revalidation immédiate.
#
#    production  Artefact déployable. Le code est COPIÉ dans l'image (aucun
#                montage), dépendances de dev exclues, opcache verrouillé +
#                preloading, exécution en utilisateur non-root fixe.
#
#    preprod     CONSTRUIT À PARTIR DE `production`. C'est volontaire : la
#                préprod doit valider l'artefact réellement déployé, pas un
#                artefact cousin. On n'ajoute qu'un delta d'observabilité
#                (Xdebug en mode profilage, logs verbeux). Le déclarer après
#                `production` dans le fichier est une contrainte Docker, pas
#                un ordre de pipeline : celui-ci reste dev → preprod → prod.
#
#  Le contexte de build est la RACINE DU PROJET (et non docker/php), car les
#  stages de déploiement doivent pouvoir copier ./backend. Voir .dockerignore.
# =============================================================================

ARG PHP_TAG
ARG COMPOSER_TAG

# -----------------------------------------------------------------------------
# Stage : composer_bin — binaire Composer issu de l'image officielle
# -----------------------------------------------------------------------------
FROM composer:${COMPOSER_TAG} AS composer_bin

# =============================================================================
# Stage : base — socle commun à TOUTES les cibles
# Contient PHP, ses extensions et les librairies runtime. Rien de spécifique
# à un environnement : c'est ce qui garantit que dev et prod partagent le même
# moteur PHP et les mêmes versions d'extensions.
# =============================================================================
FROM php:${PHP_TAG} AS base

ARG AMQP_EXT_VERSION

RUN set -eux; \
    apk add --no-cache \
        icu-libs libpq libzip rabbitmq-c tzdata \
        fcgi; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev rabbitmq-c-dev linux-headers; \
    # Extensions requises par Symfony / Doctrine / Messenger
    #   intl      : traductions, formats de dates et de nombres
    #   pdo_pgsql : driver PostgreSQL pour Doctrine
    #   zip       : requis par Composer pour les archives
    #   sockets   : dépendance de plusieurs transports Messenger
    docker-php-ext-install -j"$(nproc)" intl pdo_pgsql zip sockets; \
    # opcache : depuis PHP 8.5, il est intégré au binaire et n'est PLUS
    # construit comme extension partagée. `docker-php-ext-install opcache`
    # configure alors l'extension, ne compile rien, et échoue à l'installation
    # avec « cp: can't stat 'modules/*' ». On ne l'installe donc que s'il est
    # absent : le Dockerfile reste ainsi valable pour PHP 8.4 et antérieurs
    # comme pour 8.5+, sans avoir à figer une version de PHP.
    if php -m | grep -qi '^Zend OPcache$'; then \
        echo "opcache est intégré à cette version de PHP : installation ignorée"; \
    else \
        docker-php-ext-install -j"$(nproc)" opcache; \
    fi; \
    # amqp : transport RabbitMQ natif pour Messenger
    pecl install "amqp-${AMQP_EXT_VERSION}"; \
    docker-php-ext-enable amqp; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear

COPY --from=composer_bin /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/backend

# =============================================================================
# Stage : dev — environnement de développement local
# =============================================================================
FROM base AS dev

ARG UID=1000
ARG GID=1000
ARG SYMFONY_CLI_VERSION

# Outils confort, inutiles (et indésirables) dans une image déployée.
RUN apk add --no-cache bash git curl unzip

# --- symfony-cli --------------------------------------------------------------
# Binaire distribué par architecture : on détecte celle de l'hôte pour supporter
# aussi bien x86_64 que arm64 (Apple Silicon, Ampere…).
RUN set -eux; \
    case "$(uname -m)" in \
        x86_64)  ARCH=amd64 ;; \
        aarch64) ARCH=arm64 ;; \
        *) echo "Architecture non supportée : $(uname -m)" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/symfony-cli.tar.gz \
        "https://github.com/symfony-cli/symfony-cli/releases/download/v${SYMFONY_CLI_VERSION}/symfony-cli_linux_${ARCH}.tar.gz"; \
    tar -xzf /tmp/symfony-cli.tar.gz -C /usr/local/bin symfony; \
    chmod +x /usr/local/bin/symfony; \
    rm -f /tmp/symfony-cli.tar.gz; \
    symfony version

# -----------------------------------------------------------------------------
# Utilisateur `dev` aligné sur l'UID/GID de l'hôte
# -----------------------------------------------------------------------------
# Cœur de la gestion des droits : tout fichier écrit par le conteneur (vendor/,
# var/cache, code généré par le maker…) appartient à l'utilisateur hôte. Aucun
# `sudo chown` n'est nécessaire côté IDE.
# Les tests `getent` gèrent le cas où l'UID/GID existe déjà dans l'image de base.
RUN set -eux; \
    if ! getent group "${GID}" >/dev/null; then addgroup -g "${GID}" dev; fi; \
    GROUP_NAME="$(getent group "${GID}" | cut -d: -f1)"; \
    if ! getent passwd "${UID}" >/dev/null; then \
        adduser -u "${UID}" -G "${GROUP_NAME}" -s /bin/bash -D dev; \
    fi; \
    USER_NAME="$(getent passwd "${UID}" | cut -d: -f1)"; \
    mkdir -p /var/www/backend "/home/${USER_NAME}/.composer" "/home/${USER_NAME}/.symfony5"; \
    chown -R "${UID}:${GID}" /var/www "/home/${USER_NAME}"

COPY docker/php/php.dev.ini  /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.dev.conf /usr/local/etc/php-fpm.d/zz-www.conf

COPY docker/php/init-symfony.sh /usr/local/bin/init-symfony
RUN chmod +x /usr/local/bin/init-symfony

# Le master php-fpm tourne en root (nécessaire pour ouvrir le socket et basculer
# d'identité) ; les workers tournent en `dev` (voir www.dev.conf).
EXPOSE 9000
CMD ["php-fpm", "-F"]

# =============================================================================
# Stage : vendor — résolution des dépendances PHP de production
# Isolé pour tirer parti du cache Docker : tant que composer.json/lock ne
# changent pas, cette couche coûteuse n'est pas rejouée.
# =============================================================================
FROM base AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1

# On copie d'abord UNIQUEMENT les manifestes : modifier du code applicatif
# n'invalide donc pas le cache de `composer install`.
COPY backend/composer.json backend/composer.lock ./

# --no-dev            : exclut phpunit, maker-bundle, debug-pack…
# --optimize-autoloader + --classmap-authoritative : autoloader statique, sans
#                       accès disque à l'exécution (gain net en production).
# --no-scripts        : les scripts Symfony ont besoin du code source, qui
#                       n'est pas encore présent ; ils sont rejoués plus bas.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --classmap-authoritative

# =============================================================================
# Stage : production — artefact déployable
# =============================================================================
FROM base AS production

ARG BACKEND_FLAVOR=full

# UID fixe et arbitraire (hors plage système). Aucun alignement avec un hôte
# n'est nécessaire ici : il n'y a plus de bind mount. Un UID numérique explicite
# est par ailleurs requis par `runAsNonRoot` côté Kubernetes.
ARG APP_UID=10001
ARG APP_GID=10001

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

RUN set -eux; \
    addgroup -g "${APP_GID}" app; \
    adduser -u "${APP_UID}" -G app -s /sbin/nologin -D app

# Dépendances puis code source : l'ordre importe pour le cache de couches.
COPY --from=vendor --chown=${APP_UID}:${APP_GID} /var/www/backend/vendor ./vendor
COPY --chown=${APP_UID}:${APP_GID} backend/ ./

RUN set -eux; \
    # Les scripts sautés au stage `vendor` sont rejoués maintenant que le code
    # est présent (génération de .env.local.php, cache warmup…).
    composer dump-autoload --no-dev --classmap-authoritative; \
    composer run-script --no-dev post-install-cmd || true; \
    # Compilation des assets : uniquement en mode full-stack (AssetMapper).
    # En mode API, il n'y a aucun asset à compiler.
    if [ "${BACKEND_FLAVOR}" = "full" ]; then \
        php bin/console asset-map:compile --no-interaction; \
    fi; \
    # var/ doit rester inscriptible : c'est le SEUL chemin mutable de l'image.
    # Le reste peut tourner en système de fichiers read-only.
    mkdir -p var/cache var/log; \
    chown -R "${APP_UID}:${APP_GID}" var; \
    # Nettoyage : ni Composer ni les manifestes n'ont d'utilité à l'exécution.
    rm -f /usr/local/bin/composer

COPY docker/php/php.prod.ini  /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.prod.conf /usr/local/etc/php-fpm.d/zz-www.conf

# Sonde utilisée par les readiness/liveness probes Kubernetes.
COPY docker/php/healthcheck.sh /usr/local/bin/healthcheck
RUN chmod +x /usr/local/bin/healthcheck

# Exécution non-root de bout en bout : le master php-fpm lui-même tourne en
# `app`, ce qui permet un `runAsNonRoot: true` et un `readOnlyRootFilesystem`.
USER app

EXPOSE 9000
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
    CMD ["/usr/local/bin/healthcheck"]
CMD ["php-fpm", "-F"]

# =============================================================================
# Stage : preprod — production + delta d'observabilité
# -----------------------------------------------------------------------------
# `FROM production` est le point clé : les couches applicatives sont
# BIT À BIT IDENTIQUES à celles de l'image de production. Ce que l'on valide en
# préprod est donc bien l'artefact qui sera déployé, augmenté d'outils de
# diagnostic — et non une seconde compilation qui pourrait diverger.
# =============================================================================
FROM production AS preprod

ARG XDEBUG_VERSION

# L'installation d'extensions requiert root ; on repasse en `app` à la fin.
USER root

RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers; \
    pecl install "xdebug-${XDEBUG_VERSION}"; \
    docker-php-ext-enable xdebug; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear

# Xdebug en mode `profile`/`trace` uniquement, jamais `debug` : on mesure sans
# ouvrir de port d'attachement sur un environnement partagé.
# Le déclenchement est manuel (XDEBUG_TRIGGER), donc sans coût quand inutilisé.
COPY docker/php/xdebug.preprod.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

# APP_DEBUG reste à 0 : la préprod doit se comporter comme la production.
# Seule la verbosité des logs est augmentée pour faciliter le diagnostic.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    LOG_LEVEL=debug

RUN mkdir -p /var/www/backend/var/profiler \
 && chown -R app:app /var/www/backend/var

USER app
CMD ["php-fpm", "-F"]
