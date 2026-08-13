# Raccourcis du projet. `make help` liste les cibles disponibles.
.DEFAULT_GOAL := help
DC := docker compose

.PHONY: help build up down restart logs sh sh-front init db-migrate consume audit build-prod build-preprod front-init build-front-prod build-front-preprod get-secret

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
	  awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Construit les images
	$(DC) build

up: ## Démarre la stack en arrière-plan
	$(DC) up -d

down: ## Arrête la stack (les volumes sont conservés)
	$(DC) down

restart: down up ## Redémarre la stack

logs: ## Suit les logs de tous les services
	$(DC) logs -f

sh: ## Ouvre un shell dans le backend, en tant qu'utilisateur dev
	$(DC) exec --user dev backend bash

init: ## (Re)joue l'initialisation du projet Symfony
	$(DC) run --rm --user dev backend init-symfony

db-migrate: ## Applique les migrations Doctrine
	$(DC) exec --user dev backend php bin/console doctrine:migrations:migrate --no-interaction

consume: ## Lance le worker Messenger (transport async)
	$(DC) exec --user dev backend php bin/console messenger:consume async -vv

audit: ## Audit boîte noire de la prod
	@./tools/audit-prod.sh $(or $(DOMAIN),cp-ghostotof.com)

# --- Images déployables ------------------------------------------------------
# Ces cibles n'utilisent PAS Docker Compose : elles produisent un artefact
# destiné à un registry, pas un conteneur local.
# Le tag par défaut reprend le SHA court du commit courant : chaque image est
# ainsi traçable jusqu'à la révision exacte du code qu'elle contient.
TAG ?= $(shell git rev-parse --short HEAD 2>/dev/null || echo dev)
# `make` ne charge pas .env comme le fait Docker Compose : il faut le lire explicitement,
# sinon COMPOSE_PROJECT_NAME est vide et IMAGE/FRONT_IMAGE deviennent des tags invalides
# (ex. "-backend") tant qu'on ne le passe pas soi-même en ligne de commande.
COMPOSE_PROJECT_NAME := $(shell grep '^COMPOSE_PROJECT_NAME=' .env | cut -d= -f2)
IMAGE ?= $(COMPOSE_PROJECT_NAME)-backend
FLAVOR := $(shell grep '^BACKEND_FLAVOR=' .env | cut -d= -f2)

build-prod: ## Construit l'image de production (TAG=... IMAGE=...)
	docker build \
	  --target production \
	  --build-arg PHP_TAG=$(shell grep '^PHP_TAG=' .env | cut -d= -f2) \
	  --build-arg COMPOSER_TAG=$(shell grep '^COMPOSER_TAG=' .env | cut -d= -f2) \
	  --build-arg AMQP_EXT_VERSION=$(shell grep '^AMQP_EXT_VERSION=' .env | cut -d= -f2) \
	  --build-arg BACKEND_FLAVOR=$(FLAVOR) \
	  -f docker/php/Dockerfile \
	  -t $(IMAGE):$(TAG) .

build-preprod: ## Construit l'image de préprod (= prod + outils de diagnostic)
	docker build \
	  --target preprod \
	  --build-arg PHP_TAG=$(shell grep '^PHP_TAG=' .env | cut -d= -f2) \
	  --build-arg COMPOSER_TAG=$(shell grep '^COMPOSER_TAG=' .env | cut -d= -f2) \
	  --build-arg AMQP_EXT_VERSION=$(shell grep '^AMQP_EXT_VERSION=' .env | cut -d= -f2) \
	  --build-arg XDEBUG_VERSION=$(shell grep '^XDEBUG_VERSION=' .env | cut -d= -f2) \
	  --build-arg BACKEND_FLAVOR=$(FLAVOR) \
	  -f docker/php/Dockerfile \
	  -t $(IMAGE):$(TAG)-preprod .

sh-front: ## Ouvre un shell dans le conteneur frontend
	$(DC) exec frontend sh

front-init: ## Crée le projet Vite en mode INTERACTIF (à lancer une seule fois)
	$(DC) run --rm frontend npm create vite@$(shell grep '^CREATE_VITE_VERSION=' .env | cut -d= -f2) .

# --- Images déployables du frontend ------------------------------------------
# L'URL de l'API n'est PLUS un --build-arg : elle est injectée au runtime via la
# variable d'env API_URL du conteneur (docker/node/docker-entrypoint.sh). L'image
# est donc identique quel que soit l'environnement cible, promue de la préprod
# vers la prod sans reconstruction. Voir frontend/src/infrastructure/config/getApiUrl.ts.
FRONT_IMAGE ?= $(COMPOSE_PROJECT_NAME)-frontend

build-front-prod: ## Construit l'image frontend de production (TAG=...)
	docker build \
	  --target production \
	  --build-arg NODE_TAG=$(shell grep '^NODE_TAG=' .env | cut -d= -f2) \
	  --build-arg NGINX_TAG=$(shell grep '^NGINX_TAG=' .env | cut -d= -f2) \
	  -f docker/node/Dockerfile \
	  -t $(FRONT_IMAGE):$(TAG) .

build-front-preprod: ## Construit l'image frontend de préprod (= prod + source maps)
	docker build \
	  --target preprod \
	  --build-arg NODE_TAG=$(shell grep '^NODE_TAG=' .env | cut -d= -f2) \
	  --build-arg NGINX_TAG=$(shell grep '^NGINX_TAG=' .env | cut -d= -f2) \
	  -f docker/node/Dockerfile \
	  -t $(FRONT_IMAGE):$(TAG)-preprod .

# --- Secrets Kubernetes (préprod/prod) ---------------------------------------
# Lit les Secrets déjà présents dans le cluster (remplis par External Secrets
# Operator depuis Scaleway Secret Manager, cf. k8s/README.md) — ne fait AUCUNE
# hypothèse sur le nommage des contextes kubectl : utilise le contexte
# actuellement actif sur le poste (kubectl config current-context), seul le
# namespace est forcé via -n. Un utilisateur Kubernetes sans les droits
# `get`/`list` sur `secrets` (ex. un futur groupe "dev" restreint) se fera
# simplement rejeter par l'API server (403 Forbidden) — aucune logique de
# permission n'est dupliquée ici, le RBAC du cluster reste la seule source de
# vérité.
ENV ?= preprod
SECRET ?=
# Uniquement les 4 secrets applicatifs gérés par ESO (cf. k8s/overlays/*/external-secrets.yaml) :
# cv-pdf est exclu (binaire, illisible en terminal) ainsi que les secrets
# bootstrap scaleway-eso-auth/gitlab-registry (pas des credentials applicatifs).
ESO_SECRETS := backend-secrets postgres-credentials rabbitmq-credentials jwt-keys

get-secret: ## Affiche les secrets applicatifs en clair (ENV=preprod|prod, défaut preprod ; SECRET=nom pour n'en cibler qu'un)
	@if [ "$(ENV)" != "preprod" ] && [ "$(ENV)" != "prod" ]; then \
	  echo "ENV doit valoir 'preprod' ou 'prod' (reçu : '$(ENV)')" >&2; \
	  exit 1; \
	fi
	@printf "Afficher en clair les secrets de l'environnement '$(ENV)' ? [y/N] "; \
	read confirm; \
	if [ "$$confirm" != "y" ] && [ "$$confirm" != "Y" ]; then \
	  echo "Annulé."; \
	  exit 1; \
	fi
	@for s in $(if $(SECRET),$(SECRET),$(ESO_SECRETS)); do \
	  echo "--- $$s ($(ENV)) ---"; \
	  kubectl -n $(ENV) get secret $$s -o go-template='{{range $$k, $$v := .data}}{{$$k}}={{$$v|base64decode}}{{"\n"}}{{end}}' \
	    || echo "  (introuvable ou accès refusé)"; \
	  echo; \
	done
