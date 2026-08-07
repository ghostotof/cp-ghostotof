# Raccourcis du projet. `make help` liste les cibles disponibles.
.DEFAULT_GOAL := help
DC := docker compose

.PHONY: help build up down restart logs sh sh-front init db-migrate consume build-prod build-preprod front-init build-front-prod build-front-preprod

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

# --- Images déployables ------------------------------------------------------
# Ces cibles n'utilisent PAS Docker Compose : elles produisent un artefact
# destiné à un registry, pas un conteneur local.
# Le tag par défaut reprend le SHA court du commit courant : chaque image est
# ainsi traçable jusqu'à la révision exacte du code qu'elle contient.
TAG ?= $(shell git rev-parse --short HEAD 2>/dev/null || echo dev)
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
# API_URL est inliné dans le bundle par Vite AU BUILD : il doit donc être fourni
# ici, et non au démarrage du conteneur.
FRONT_IMAGE ?= $(COMPOSE_PROJECT_NAME)-frontend
API_URL ?= http://localhost:8080

build-front-prod: ## Construit l'image frontend de production (API_URL=... TAG=...)
	docker build \
	  --target production \
	  --build-arg NODE_TAG=$(shell grep '^NODE_TAG=' .env | cut -d= -f2) \
	  --build-arg NGINX_TAG=$(shell grep '^NGINX_TAG=' .env | cut -d= -f2) \
	  --build-arg VITE_API_URL=$(API_URL) \
	  -f docker/node/Dockerfile \
	  -t $(FRONT_IMAGE):$(TAG) .

build-front-preprod: ## Construit l'image frontend de préprod (= prod + source maps)
	docker build \
	  --target preprod \
	  --build-arg NODE_TAG=$(shell grep '^NODE_TAG=' .env | cut -d= -f2) \
	  --build-arg NGINX_TAG=$(shell grep '^NGINX_TAG=' .env | cut -d= -f2) \
	  --build-arg VITE_API_URL=$(API_URL) \
	  -f docker/node/Dockerfile \
	  -t $(FRONT_IMAGE):$(TAG)-preprod .
