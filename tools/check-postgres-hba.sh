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
# Il démarre l'image épinglée par le manifeste sous l'uid du manifeste
# (`runAsUser`), le fichier monté au chemin que lit `-c hba_file=…` dans
# postgres.yaml, avec
# un superutilisateur bootstrap au nom quelconque (comme en préprod/prod, où
# il est dans un Secret), crée cpg_app, cpg_migrator et cpg_reader, puis exige :
#   - depuis un autre conteneur, par TCP : le superutilisateur et cpg_reader
#     refusés par une règle `reject`, une connexion de réplication aussi,
#     cpg_app et cpg_migrator admis avec leur mot de passe, refusés avec un
#     mauvais ; sur les bases que crée l'image (`postgres`, `template0`,
#     `template1`, lues dans pg_database, issue #597), refusés par pg_hba.conf
#     avant toute authentification, réplication logique comprise ;
#   - sur le loopback du conteneur, chemin d'un `kubectl port-forward` ou d'un
#     conteneur éphémère : le superutilisateur refusé, cpg_reader admis sans
#     mot de passe (le SHOW des keepalives du runbook) sur la base de
#     l'application seule, aucune base de l'image (#597) ;
#   - sur le socket local : le superutilisateur admis (entrypoint, procédure
#     des rôles). La sonde `pg_isready` du manifeste est rejouée pour dire que
#     le serveur répond ; elle n'authentifie personne, elle ne mesure donc
#     rien du fichier ;
#   - la sonde du smoke préprod (k8s/base/postgres-hba-probe.sh, issue #596),
#     jouée comme le Job (uid du manifeste, système de fichiers en lecture
#     seule, PGCONNECT_TIMEOUT), dans les deux sens : elle réussit contre ce
#     serveur, y compris sous des noms qui recoupent les messages qu'elle
#     reconnaît ; elle échoue en nommant la demande de mot de passe contre un
#     second serveur, démarré sans `-c hba_file`, qui lit le pg_hba.conf de
#     l'image dans PGDATA (ce que ramènerait un `rollout undo` de `postgres` ou
#     un overlay qui perd l'argument) ; elle échoue contre un troisième, dont
#     le fichier n'admet le superutilisateur que sur la base `postgres`, et
#     contre un quatrième, le fichier du dépôt sans le refus des bases de
#     l'image (#597), où seul cpg_app, sans mot de passe, la trahit ; elle
#     réessaie puis échoue sur un port fermé. Sa sortie, qui part dans les
#     journaux du pipeline, ne nomme jamais le superutilisateur ni la base.
#
# Les mots de passe sont générés à chaque run et n'entrent dans aucun argument
# de processus : fichiers d'environnement privés et entrée standard de psql.
#
# Ce que ce script ne couvre PAS : un serveur qui démarre sur un PVC déjà
# initialisé — seul un vrai rollout en préprod le prouve
# (.claude/rules/deploiement-postgres.md).
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
# La sonde du smoke préprod (#596). Vérifiée avant tout montage : un chemin
# source absent, Docker le crée en répertoire vide, et `sh` sur un répertoire
# sort en 0 — la sonde passerait sans avoir rien mesuré (constaté).
PROBE="$ROOT/k8s/base/postgres-hba-probe.sh"
if [ ! -f "$PROBE" ]; then
  echo "$PROBE introuvable : la sonde du smoke préprod (#596) n'aurait rien à jouer." >&2
  exit 2
fi
# Le chemin que lit le serveur, pris dans le manifeste : une seule source.
MOUNT="$(sed -n 's/^[[:space:]]*- hba_file=\([^[:space:]]*\)[[:space:]]*$/\1/p' "$ROOT/k8s/base/postgres.yaml")"
if [ -z "$MOUNT" ] || [ "$(printf '%s\n' "$MOUNT" | wc -l)" -ne 1 ]; then
  echo "k8s/base/postgres.yaml : un et un seul « - hba_file=<chemin> » attendu dans les args du serveur." >&2
  exit 2
fi
# L'uid du conteneur, pris dans le manifeste aussi : le fichier monté doit lui être lisible.
RUN_AS="$(sed -n 's/^[[:space:]]*runAsUser:[[:space:]]*\([0-9][0-9]*\)[[:space:]]*$/\1/p' "$ROOT/k8s/base/postgres.yaml")"
if [ -z "$RUN_AS" ] || [ "$(printf '%s\n' "$RUN_AS" | wc -l)" -ne 1 ]; then
  echo "k8s/base/postgres.yaml : un et un seul « runAsUser: <uid> » attendu." >&2
  exit 2
fi

BOOTSTRAP=hba_bootstrap
DB=hba_check
server="check-postgres-hba-$$"
# Sonde #596 : le même serveur sans `-c hba_file` (le fichier de l'image dans
# PGDATA), puis un fichier qui n'admet le superutilisateur que sur `postgres`.
pgdata_server="check-postgres-hba-pgdata-$$"
postgres_db_server="check-postgres-hba-postgresdb-$$"
# Sonde #597 : le fichier du dépôt sans le refus des bases de l'image (une
# ConfigMap de l'époque #593, que ramènerait un `rollout undo` de `postgres`).
no_597_server="check-postgres-hba-no597-$$"
network="check-postgres-hba-$$"
umask 077
scratch="$(mktemp -d)"
cleanup() {
  docker rm -f "$server" "$pgdata_server" "$postgres_db_server" "$no_597_server" >/dev/null 2>&1 || true
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
docker run -d --name "$server" --network "$network" --user "$RUN_AS:$RUN_AS" \
  --env-file "$scratch/server.env" \
  -v "$HBA:$MOUNT:ro" \
  "$IMAGE" postgres -c "hba_file=$MOUNT" >/dev/null
# Démarrés en même temps, pour ne pas attendre les initialisations l'une après l'autre.
docker run -d --name "$pgdata_server" --network "$network" --user "$RUN_AS:$RUN_AS" \
  --env-file "$scratch/server.env" \
  "$IMAGE" postgres >/dev/null
# Le fichier du dépôt, précédé d'une règle qui rouvre le superutilisateur sur
# la base `postgres` seulement. En tête, la seule place où elle n'est pas
# masquée : le refus des bases de l'image (#597) la précéderait sinon. Copié
# dans le conteneur (docker cp passe par l'API, sans partage de fichiers),
# lisible par l'uid du serveur.
{ echo 'host postgres all all scram-sha-256'; cat "$HBA"; } > "$scratch/postgres-db.conf"
chmod 644 "$scratch/postgres-db.conf"
docker create --name "$postgres_db_server" --network "$network" --user "$RUN_AS:$RUN_AS" \
  --env-file "$scratch/server.env" \
  "$IMAGE" postgres -c hba_file=/tmp/pg_hba.conf >/dev/null
docker cp "$scratch/postgres-db.conf" "$postgres_db_server:/tmp/pg_hba.conf" >/dev/null
docker start "$postgres_db_server" >/dev/null
grep -v '^host[[:space:]]\{1,\}postgres,template0,template1[[:space:]]' "$HBA" > "$scratch/no-597.conf"
cmp -s "$HBA" "$scratch/no-597.conf" \
  && { echo "Le refus des bases de l'image (#597) est introuvable dans $HBA." >&2; exit 2; }
chmod 644 "$scratch/no-597.conf"
docker create --name "$no_597_server" --network "$network" --user "$RUN_AS:$RUN_AS" \
  --env-file "$scratch/server.env" \
  "$IMAGE" postgres -c hba_file=/tmp/pg_hba.conf >/dev/null
docker cp "$scratch/no-597.conf" "$no_597_server:/tmp/pg_hba.conf" >/dev/null
docker start "$no_597_server" >/dev/null
rm -f "$scratch/server.env"

# Prêt quand le serveur définitif écoute en TCP : le serveur temporaire de
# l'initialisation n'écoute que le socket local. Jusqu'à 2 min.
# $1 conteneur, $2 ce qu'il lit (pour le message).
wait_listening() {
  for _ in $(seq 1 60); do
    if [ "$(docker inspect -f '{{.State.Running}}' "$1")" != "true" ]; then
      echo "PostgreSQL s'est arrêté au démarrage avec $2 (fichier refusé ?) :" >&2
      docker logs --tail 40 "$1" >&2 || true
      exit 1
    fi
    if docker exec "$1" pg_isready -q -h 127.0.0.1 -U "$BOOTSTRAP" -d "$DB" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  echo "PostgreSQL ($2) n'écoute pas en TCP au bout de 2 min :" >&2
  docker logs --tail 40 "$1" >&2 || true
  exit 1
}
wait_listening "$server" "$HBA"
wait_listening "$pgdata_server" "le pg_hba.conf de l'image"
wait_listening "$postgres_db_server" "un pg_hba.conf ouvert sur la base postgres"
wait_listening "$no_597_server" "le pg_hba.conf du dépôt sans le refus #597"

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
# $1 rôle, $2 fichier du mot de passe (vide : aucun), $3 base (défaut : $DB ;
# `replication` demande une connexion de réplication physique, `logical:<base>`
# une réplication logique sur <base>).
remote() {
  local env="$scratch/client.env" target="dbname=${3:-$DB}"
  : > "$env"
  if [ -n "$2" ]; then
    { printf 'PGPASSWORD='; cat "$2"; } > "$env"
  fi
  case "${3:-}" in
    replication)
      # Réplication physique : c'est elle que le mot-clé `replication` désigne.
      target="dbname=$DB replication=true" ;;
    logical:*)
      # Réplication logique : elle suit le nom de la base qu'elle nomme.
      target="dbname=${3#logical:} replication=database" ;;
  esac
  docker run --rm --network "$network" --env-file "$env" --entrypoint psql "$IMAGE" \
    -X -A -t -w -c 'SELECT 1' "host=$server user=$1 $target" 2>&1
}

# $1 rôle, $2 fichier du mot de passe
expect_admitted() {
  local out
  if ! out="$(remote "$1" "$2")" || [ "$out" != 1 ]; then
    fail "$1 par TCP : admis attendu, obtenu « $out »."
  fi
}

# $1 rôle, $2 fichier du mot de passe, $3 motif attendu dans le refus, $4 base
expect_refused() {
  local out
  if out="$(remote "$1" "$2" "${4:-}")"; then
    fail "$1 par TCP${4:+ ($4)} : refus attendu, connexion obtenue."
  elif ! grep -q -- "$3" <<<"$out"; then
    fail "$1 par TCP${4:+ ($4)} : refus « $3 » attendu, obtenu « $out »."
  fi
}

openssl rand -hex 24 > "$scratch/wrong"
expect_refused "$BOOTSTRAP" "$scratch/bootstrap" 'pg_hba.conf rejects connection'
expect_refused cpg_reader "" 'pg_hba.conf rejects connection'
expect_admitted cpg_app "$scratch/app"
expect_admitted cpg_migrator "$scratch/migrator"
expect_refused cpg_app "$scratch/wrong" 'password authentication failed'
expect_refused cpg_migrator "$scratch/wrong" 'password authentication failed'
expect_refused "$BOOTSTRAP" "$scratch/bootstrap" 'pg_hba.conf rejects replication connection' replication
# Issue #597 : cpg_app et cpg_migrator n'atteignent que la base de
# l'application, jamais celles que l'image crée (PUBLIC y garde CONNECT et
# TEMP). Le refus de pg_hba.conf précède l'authentification : le mot de passe
# fourni ne joue pas — sans la règle, SCRAM l'accepterait et le contrôle
# rougirait. Les bases sont lues dans le catalogue du serveur, et la liste doit
# être exactement celle-ci : une base de plus dans une image future échapperait
# à la règle, qui les nomme.
image_databases="$(docker exec "$server" psql -X -A -t -U "$BOOTSTRAP" -d "$DB" \
  -c "SELECT string_agg(datname, ' ' ORDER BY datname) FROM pg_database WHERE datname <> '$DB'" 2>&1)"
[ "$image_databases" = 'postgres template0 template1' ] \
  || fail "bases créées par l'image : « postgres template0 template1 » attendu, « $image_databases » lu."
for database in $image_databases; do
  # Les fichiers de mot de passe portent le nom du rôle sans `cpg_` (app, migrator).
  for role in cpg_app cpg_migrator; do
    expect_refused "$role" "$scratch/${role#cpg_}" 'pg_hba.conf rejects connection' "$database"
  done
done
# Une réplication LOGIQUE suit le nom de la base (`replication=database`) :
# refusée sur `postgres` par la même règle, sous le message d'une connexion
# ordinaire (seule la réplication physique a le sien).
expect_refused cpg_app "$scratch/app" 'pg_hba.conf rejects connection for .*database "postgres"' logical:postgres

# Le loopback : ce qu'un port-forward ou un conteneur éphémère atteint.
# $1 adresse ; refus du superutilisateur, même sans mot de passe à fournir.
loopback() {
  docker exec "$server" psql -X -A -t -w -h "$1" -U "$2" -d "${3:-$DB}" -c 'SELECT 1' 2>&1
}
for address in 127.0.0.1 ::1; do
  out="$(loopback "$address" "$BOOTSTRAP" || true)"
  grep -q 'pg_hba.conf rejects connection' <<<"$out" \
    || fail "superutilisateur sur $address (port-forward) : refus attendu, obtenu « $out »."
  [ "$(loopback "$address" cpg_reader)" = 1 ] \
    || fail "cpg_reader ne passe plus par $address sans mot de passe."
  # #597 : sur le loopback non plus, aucune base de l'image — ni pour le
  # lecteur, admis en `trust` sur la seule base de l'application.
  for database in $image_databases; do
    for role in cpg_reader cpg_app; do
      out="$(loopback "$address" "$role" "$database" || true)"
      grep -q 'pg_hba.conf rejects connection' <<<"$out" \
        || fail "$role sur $address, base $database : refus attendu, obtenu « $out »."
    done
  done
done

# La sonde du smoke préprod (#596), lancée comme le Job : uid du manifeste,
# système de fichiers en lecture seule, PGCONNECT_TIMEOUT du manifeste,
# variables libpq seulement, aucun mot de passe.
# $1 serveur, $2 rôle, $3 base, $4 port (défaut 5432).
probe() {
  docker run --rm --network "$network" --user "$RUN_AS:$RUN_AS" --read-only \
    -e PGHOST="$1" -e PGUSER="$2" -e PGDATABASE="$3" -e PGPORT="${4:-5432}" -e PGCONNECT_TIMEOUT=10 \
    -v "$PROBE:/postgres-hba-probe.sh:ro" \
    --entrypoint sh "$IMAGE" /postgres-hba-probe.sh 2>&1
}
# $1 libellé, $2 sortie de la sonde, $3… noms qui ne doivent pas y figurer.
probe_output_is_redacted() {
  local label="$1" output="$2" name
  shift 2
  for name in "$@"; do
    if grep -qF -e "$name" <<<"$output"; then
      fail "sonde #596 ($label) : sa sortie laisse voir « $name » : « $output »."
    fi
  done
}
# $1 libellé, $2 serveur, $3 rôle, $4 base — succès attendu, avec la marque
# que la sonde n'imprime qu'après les deux refus : un code 0 seul ne prouve
# pas qu'elle a tourné.
expect_probe_passes() {
  local out
  if ! out="$(probe "$2" "$3" "$4")" || ! grep -q '^#596 : OK' <<<"$out"; then
    fail "sonde #596 ($1) : succès attendu, obtenu « $out »."
  fi
  probe_output_is_redacted "$1" "$out" "$3" "$4"
  PROBE_OUTPUT="$out"
}
# $1 libellé, $2 marque attendue dans l'échec, $3 serveur, $4 port (défaut 5432).
expect_probe_fails() {
  local out
  if out="$(probe "$3" "$BOOTSTRAP" "$DB" "${4:-}")"; then
    fail "sonde #596 ($1) : échec attendu, succès obtenu « $out »."
  elif ! grep -q -- "$2" <<<"$out"; then
    fail "sonde #596 ($1) : échec « $2 » attendu, obtenu « $out »."
  fi
  probe_output_is_redacted "$1" "$out" "$BOOTSTRAP" "$DB"
  PROBE_OUTPUT="$out"
}

expect_probe_passes "pg_hba.conf du dépôt" "$server" "$BOOTSTRAP" "$DB"
# Des noms qui recoupent les messages que la sonde reconnaît (« connection »,
# « rejects »), l'un préfixe de l'autre : le refus reste reconnu, et ni l'un
# ni la partie propre de l'autre n'apparaissent. Le `reject` final refuse un
# rôle inexistant avant de le chercher.
expect_probe_passes "noms qui recoupent les motifs" "$server" conn conn_rejects
probe_output_is_redacted "noms qui recoupent les motifs" "$PROBE_OUTPUT" _rejects
expect_probe_fails "pg_hba.conf de l'image, superutilisateur admis en SCRAM" \
  'demande un mot de passe' "$pgdata_server"
expect_probe_fails "superutilisateur admis sur la base postgres seulement" \
  'base postgres.*demande un mot de passe' "$postgres_db_server"
# #597 : le superutilisateur y reste refusé partout ; seul cpg_app, tenté sans
# mot de passe sur `postgres`, révèle le refus manquant.
expect_probe_fails "pg_hba.conf sans le refus des bases de l'image" \
  'cpg_app, base postgres.*demande un mot de passe' "$no_597_server"
# Un port fermé : la sonde réessaie (un serveur qui vient de redémarrer
# n'écoute pas encore), puis échoue en le nommant.
expect_probe_fails "port fermé" 'injoignable' "$server" 5433
retries="$(grep -c 'nouvel essai' <<<"$PROBE_OUTPUT" || true)"
[ "$retries" -ge 1 ] || fail "sonde #596 (port fermé) : au moins un nouvel essai attendu, $retries lu."

# Le socket local, et la sonde du manifeste (le serveur répond ; elle
# n'authentifie pas : seul le SELECT mesure la règle `local`).
docker exec "$server" sh -c 'pg_isready -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
  || fail "la sonde pg_isready du manifeste échoue."
[ "$(docker exec "$server" psql -X -A -t -U "$BOOTSTRAP" -d "$DB" -c 'SELECT 1' 2>&1)" = 1 ] \
  || fail "le superutilisateur ne passe plus par le socket local."

if [ "$failures" -ne 0 ]; then
  echo "$failures contrôle(s) en échec avec $HBA ($IMAGE)." >&2
  exit 1
fi

echo "PostgreSQL ($IMAGE) démarre avec $HBA : superutilisateur, cpg_reader et réplication refusés par TCP, cpg_app et cpg_migrator admis par SCRAM, refusés sur les bases de l'image (#597), loopback à cpg_reader seul et hors des bases de l'image, socket local inchangé ; la sonde du smoke préprod (#596) passe ici (noms piégés compris) et échoue sur le fichier de l'image, sur la base postgres rouverte, sur un fichier sans le refus des bases de l'image (#597) et sur un port fermé."
