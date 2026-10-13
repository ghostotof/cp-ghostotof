#!/usr/bin/env bash
#
# check-postgres-hba.sh
# ---------------------------------------------------------------------------
# Garde CI de l'issue #593 : k8s/base/postgres-pg_hba.conf décide qui se
# connecte à PostgreSQL, et un fichier invalide empêche le serveur de démarrer
# (« could not load pg_hba.conf »). En `Recreate`, l'ancien pod est déjà
# arrêté, `rollback-preprod` ne ramène pas `postgres` : la préprod resterait
# sans base jusqu'à une intervention à la main. PostgresHbaTest lit la forme du
# fichier ; ce script mesure ce qu'en fait le serveur.
#
# Il démarre l'image épinglée par le manifeste sous l'uid du manifeste (70),
# le fichier monté au chemin que lit `-c hba_file=…` dans postgres.yaml, avec
# un superutilisateur bootstrap au nom quelconque (comme en préprod/prod, où
# il est dans un Secret), crée cpg_app, cpg_migrator et cpg_reader, puis exige :
#   - depuis un autre conteneur, par TCP : le superutilisateur et cpg_reader
#     refusés par une règle `reject`, cpg_app et cpg_migrator admis avec leur
#     mot de passe, refusés avec un mauvais ;
#   - depuis l'intérieur du conteneur : la sonde `pg_isready` du manifeste, le
#     superutilisateur sur le socket local, cpg_reader sur 127.0.0.1 (le SHOW
#     des keepalives du runbook).
#
# Les mots de passe sont générés à chaque run et n'entrent dans aucun argument
# de processus : fichiers d'environnement privés et entrée standard de psql.
#
# Ce que ce script ne couvre PAS : un serveur qui démarre sur un PVC déjà
# initialisé — seul un vrai rollout en préprod le prouve
# (.claude/rules/deploiement.md).
#
# Usage :  tools/check-postgres-hba.sh postgres:<tag>
#          (make postgres-hba, qui lit le tag dans k8s/base/postgres.yaml)
# ---------------------------------------------------------------------------
set -euo pipefail

IMAGE="${1:?usage : $0 postgres:<tag>}"
case "$IMAGE" in
  postgres:?*) ;;
  *) echo "Image « $IMAGE » refusée : tag introuvable dans k8s/base/postgres.yaml ?" >&2; exit 2 ;;
esac

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HBA="$ROOT/k8s/base/postgres-pg_hba.conf"
# Le chemin que lit le serveur, pris dans le manifeste : une seule source.
MOUNT="$(sed -n 's/^[[:space:]]*- hba_file=\([^[:space:]]*\)[[:space:]]*$/\1/p' "$ROOT/k8s/base/postgres.yaml")"
if [ -z "$MOUNT" ] || [ "$(printf '%s\n' "$MOUNT" | wc -l)" -ne 1 ]; then
  echo "k8s/base/postgres.yaml : un et un seul « - hba_file=<chemin> » attendu dans les args du serveur." >&2
  exit 2
fi

BOOTSTRAP=hba_bootstrap
DB=hba_check
server="check-postgres-hba-$$"
network="check-postgres-hba-$$"
umask 077
scratch="$(mktemp -d)"
cleanup() {
  docker rm -f "$server" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  rm -rf "$scratch"
}
trap cleanup EXIT

openssl rand -hex 24 > "$scratch/bootstrap"
openssl rand -hex 24 > "$scratch/app"
openssl rand -hex 24 > "$scratch/migrator"
{ echo "POSTGRES_USER=$BOOTSTRAP"; echo "POSTGRES_DB=$DB"; printf 'POSTGRES_PASSWORD='; cat "$scratch/bootstrap"; } > "$scratch/server.env"

docker network create "$network" >/dev/null
# Monté depuis le checkout (Docker Desktop ne partage pas /tmp) : l'uid 70 doit
# pouvoir le lire, comme la ConfigMap montée en 0644 en cluster.
docker run -d --name "$server" --network "$network" --user 70:70 \
  --env-file "$scratch/server.env" \
  -v "$HBA:$MOUNT:ro" \
  "$IMAGE" postgres -c "hba_file=$MOUNT" >/dev/null
rm -f "$scratch/server.env"

# Prêt quand le serveur définitif écoute en TCP : le serveur temporaire de
# l'initialisation n'écoute que le socket local. Jusqu'à 2 min.
ready=
for _ in $(seq 1 60); do
  if [ "$(docker inspect -f '{{.State.Running}}' "$server")" != "true" ]; then
    echo "PostgreSQL s'est arrêté au démarrage avec $HBA (fichier refusé ?) :" >&2
    docker logs --tail 40 "$server" >&2 || true
    exit 1
  fi
  if docker exec "$server" pg_isready -q -h 127.0.0.1 -U "$BOOTSTRAP" -d "$DB" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done
if [ -z "$ready" ]; then
  echo "PostgreSQL n'écoute pas en TCP au bout de 2 min :" >&2
  docker logs --tail 40 "$server" >&2 || true
  exit 1
fi

failures=0
fail() {
  echo "ÉCHEC : $*" >&2
  failures=$((failures + 1))
}

# Le fichier servi est bien celui du dépôt.
served="$(docker exec "$server" psql -X -A -t -U "$BOOTSTRAP" -d "$DB" -c 'SHOW hba_file' 2>&1 || true)"
[ "$served" = "$MOUNT" ] || fail "hba_file vaut « $served », « $MOUNT » attendu."

# Les trois rôles, par le socket local (requête et mots de passe sur stdin).
{
  printf "CREATE ROLE cpg_app LOGIN PASSWORD '%s';\n" "$(cat "$scratch/app")"
  printf "CREATE ROLE cpg_migrator LOGIN PASSWORD '%s';\n" "$(cat "$scratch/migrator")"
  printf 'CREATE ROLE cpg_reader LOGIN;\n'
} | docker exec -i "$server" psql -X -q -v ON_ERROR_STOP=1 -U "$BOOTSTRAP" -d "$DB" >/dev/null

# Une connexion TCP depuis un autre conteneur du réseau, comme un pod du
# namespace vers le Service `database`. Le mot de passe passe par un fichier
# d'environnement ; rien n'est affiché qu'un code et le message du serveur.
# $1 rôle, $2 fichier du mot de passe (vide : aucun).
remote() {
  local env="$scratch/client.env"
  : > "$env"
  if [ -n "$2" ]; then
    { printf 'PGPASSWORD='; cat "$2"; } > "$env"
  fi
  docker run --rm --network "$network" --env-file "$env" --entrypoint psql "$IMAGE" \
    -X -A -t -w -h "$server" -U "$1" -d "$DB" -c 'SELECT 1' 2>&1
}

# $1 rôle, $2 fichier du mot de passe
expect_admitted() {
  local out
  if ! out="$(remote "$1" "$2")" || [ "$out" != 1 ]; then
    fail "$1 par TCP : admis attendu, obtenu « $out »."
  fi
}

# $1 rôle, $2 fichier du mot de passe, $3 motif attendu dans le refus
expect_refused() {
  local out
  if out="$(remote "$1" "$2")"; then
    fail "$1 par TCP : refus attendu, connexion obtenue."
  elif ! grep -q -- "$3" <<<"$out"; then
    fail "$1 par TCP : refus « $3 » attendu, obtenu « $out »."
  fi
}

openssl rand -hex 24 > "$scratch/wrong"
expect_refused "$BOOTSTRAP" "$scratch/bootstrap" 'pg_hba.conf rejects connection'
expect_refused cpg_reader "" 'pg_hba.conf rejects connection'
expect_admitted cpg_app "$scratch/app"
expect_admitted cpg_migrator "$scratch/migrator"
expect_refused cpg_app "$scratch/wrong" 'password authentication failed'

# L'intérieur du pod.
docker exec "$server" sh -c 'pg_isready -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
  || fail "la sonde pg_isready du manifeste échoue."
[ "$(docker exec "$server" psql -X -A -t -U "$BOOTSTRAP" -d "$DB" -c 'SELECT 1' 2>&1)" = 1 ] \
  || fail "le superutilisateur ne passe plus par le socket local."
[ "$(docker exec "$server" psql -X -A -t -w -h 127.0.0.1 -U cpg_reader -d "$DB" -c 'SELECT 1' 2>&1)" = 1 ] \
  || fail "cpg_reader ne passe plus par 127.0.0.1 sans mot de passe."

if [ "$failures" -ne 0 ]; then
  echo "$failures contrôle(s) en échec avec $HBA ($IMAGE)." >&2
  exit 1
fi

echo "PostgreSQL ($IMAGE) démarre avec $HBA : superutilisateur et cpg_reader refusés par TCP, cpg_app et cpg_migrator admis par SCRAM, socket local et loopback inchangés."
