#!/usr/bin/env bash
#
# check-rabbitmq-conf.sh
# ---------------------------------------------------------------------------
# Garde CI de l'issue #477 : k8s/base/rabbitmq.conf est le premier fichier du
# dépôt capable d'empêcher RabbitMQ de démarrer. Une clé inconnue ou une faute
# de frappe, et la validation de RabbitMQ refuse le nœud. En `Recreate`,
# l'ancien broker est déjà arrêté, `rollback-preprod` ne ramène pas
# `rabbitmq`, et la préprod reste sans broker (contact et invitations en 500,
# worker bloqué) jusqu'à une intervention à la main.
#
# Le script démarre l'image épinglée par le manifeste, avec le fichier monté
# comme en préprod/prod (/etc/rabbitmq/conf.d/20-cp-ghostotof.conf, à côté du
# 10-defaults.conf de l'image), attend que le nœud tourne, puis exige que
# `max_message_size` vaille la dernière valeur du fichier : celle que RabbitMQ
# retient. Il vérifie aussi que docker-compose.yml monte ce même fichier, sans
# quoi le dev retomberait en silence sur le plafond par défaut (16 Mio).
#
# Ce que ce script ne couvre PAS : un broker qui démarre sur un PVC déjà
# initialisé (cookie Erlang, données) — seul un vrai rollout en préprod le
# prouve (.claude/rules/deploiement.md, incident v0.5.0).
#
# Usage :  tools/check-rabbitmq-conf.sh rabbitmq:<tag>
#          (make rabbitmq-conf, qui lit le tag dans k8s/base/rabbitmq.yaml)
# ---------------------------------------------------------------------------
set -euo pipefail

IMAGE="${1:?usage : $0 rabbitmq:<tag>}"
case "$IMAGE" in
  rabbitmq:?*) ;;
  *) echo "Image « $IMAGE » refusée : tag introuvable dans k8s/base/rabbitmq.yaml ?" >&2; exit 2 ;;
esac

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF="$ROOT/k8s/base/rabbitmq.conf"
MOUNT="/etc/rabbitmq/conf.d/20-cp-ghostotof.conf"
COMPOSE_MOUNT="./k8s/base/rabbitmq.conf:$MOUNT:ro"

expected="$(sed -n 's/^max_message_size[[:space:]]*=[[:space:]]*\([0-9][0-9]*\)[[:space:]]*$/\1/p' "$CONF" | tail -n 1)"
if [ -z "$expected" ]; then
  echo "Aucune ligne max_message_size dans $CONF." >&2
  exit 1
fi

if ! grep -qF -- "- $COMPOSE_MOUNT" "$ROOT/docker-compose.yml"; then
  echo "docker-compose.yml ne monte pas $COMPOSE_MOUNT : le broker de dev garderait le plafond par défaut." >&2
  exit 1
fi

name="check-rabbitmq-conf-$$"
trap 'docker rm -f "$name" >/dev/null 2>&1 || true' EXIT
docker run -d --name "$name" -v "$CONF:$MOUNT:ro" "$IMAGE" >/dev/null

# Jusqu'à 2 min : le premier démarrage de l'Erlang VM est lent sur un runner.
# Le CLI tourne sous l'utilisateur `rabbitmq`, jamais en root (défaut de
# `docker exec`) : lancé avant le serveur, il crée .erlang.cookie, et un cookie
# créé par root est illisible pour le nœud, qui s'arrête sur `eacces`.
for _ in $(seq 1 60); do
  if [ "$(docker inspect -f '{{.State.Running}}' "$name")" != "true" ]; then
    echo "RabbitMQ s'est arrêté au démarrage avec $CONF (configuration refusée ?) :" >&2
    docker logs --tail 40 "$name" >&2 || true
    exit 1
  fi
  if docker exec -u rabbitmq "$name" rabbitmq-diagnostics -q check_running >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

actual="$(docker exec -u rabbitmq "$name" rabbitmqctl eval 'application:get_env(rabbit, max_message_size).' 2>/dev/null | tail -n 1 || true)"
if [ "$actual" != "{ok,$expected}" ]; then
  echo "max_message_size : « $actual » lu dans le nœud, « {ok,$expected} » attendu de $CONF." >&2
  docker logs --tail 40 "$name" >&2 || true
  exit 1
fi

echo "RabbitMQ ($IMAGE) démarre avec $CONF : max_message_size = $expected ; docker-compose.yml monte le même fichier."
