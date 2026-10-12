#!/usr/bin/env bash
#
# run-job.sh
# ---------------------------------------------------------------------------
# Lance un Job Kubernetes tenu hors de kustomize (issue #488) — la migration,
# le seed, le message témoin, la création de compte — et attend sa fin. Une
# seule implémentation de la séquence, que le pipeline écrivait en ligne à
# chaque Job :
#
#   1. contrôles, AVANT tout appel au cluster (fail-closed, sortie 2) :
#      - outils requis : bash >= 4 et jq pour tools/wait-rollout.sh, envsubst ;
#        absents, l'attente échouerait APRÈS l'apply, sur un Job qui tourne ;
#      - le manifeste décrit un seul objet, de `kind: Job`, dont le
#        `metadata.name` est un nom DNS-1123 (jamais une option : `--all`) ;
#      - chaque NOM=valeur nomme un placeholder `${NOM}` du manifeste, autre
#        que BACKEND_IMAGE (l'argument image), et sa valeur, non vide, tient
#        dans [A-Za-z0-9._@:/+-] : elle est insérée telle quelle dans le
#        YAML, où un guillemet ou une fin de ligne ajouteraient des clés ;
#      - rendu par envsubst à liste EXPLICITE (`${BACKEND_IMAGE}` et ces
#        noms : sans liste, envsubst effacerait toute autre `$VARIABLE`),
#        puis refus de tout `${…}` restant, qui partirait littéral dans la
#        spec du pod. Une variable shell d'un manifeste s'écrit donc `$VAR`,
#        sans accolades — commentaires compris ;
#   2. suppression du Job précédent (`job/NOM`), dont la spec est immuable,
#      puis apply — un refus du cluster sort en 3 ;
#   3. attente par tools/wait-rollout.sh (issue #353), qui fait échouer en
#      quelques secondes un conteneur qui ne démarrera pas ou un Job `Failed`
#      au lieu d'épuiser le délai — un échec sort en 1 ;
#   4. les 200 dernières lignes de journaux de chaque conteneur du Job
#      (initContainer compris, `--prefix` les nomme) : sur stdout après un
#      succès, sur stderr après un `::error::` en cas d'échec (`|| true` : un
#      conteneur jamais démarré n'en a pas).
#
# Trois codes d'échec, que l'appelant peut distinguer : 2 = refusé avant le
# cluster, 3 = le cluster a refusé le delete ou l'apply, 1 = le Job n'a pas
# abouti (smoke-messenger-probe.sh n'impute que celui-là au worker).
#
# Ce script ne lit aucun Secret, et n'en transmet aucun : une valeur passée
# en argument se lit dans `ps` et dans l'historique du shell. Un nom de
# compte n'est pas un secret, mais il est la moitié d'un identifiant
# (Goal #9 de .claude/CLAUDE.md) : le passer ici reste un geste d'opérateur,
# sur son poste, jamais une valeur écrite dans le dépôt.
#
# Usage :  tools/run-job.sh <namespace> <manifeste> <image backend> <délai s> [NOM=valeur …]
# Exemple : tools/run-job.sh preprod k8s/base/migrate-job.yaml "$IMAGE" 300
# Env :    RUN_JOB_KUBECTL, RUN_JOB_WAIT  binaires de substitution
#          (tests hors ligne, tools/tests/run-job.test.sh)
# ---------------------------------------------------------------------------
set -euo pipefail

USAGE="usage : $0 <namespace> <manifeste> <image backend> <délai s> [NOM=valeur …]"

# refuse <message> — refus avant tout appel au cluster.
refuse() {
  echo "::error::run-job.sh : $1" >&2
  exit 2
}

[ "$#" -ge 4 ] || refuse "$USAGE"
NAMESPACE="$1"
MANIFEST="$2"
IMAGE="$3"
TIMEOUT="$4"
shift 4

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
KUBECTL="${RUN_JOB_KUBECTL:-kubectl}"
WAIT="${RUN_JOB_WAIT:-$ROOT/tools/wait-rollout.sh}"

# --- Outils requis ----------------------------------------------------------
(( BASH_VERSINFO[0] >= 4 )) \
  || refuse "bash >= 4 requis (tools/wait-rollout.sh déclare des tableaux associatifs) ; celui-ci est $BASH_VERSION."
for tool in jq envsubst "$KUBECTL" "$WAIT"; do
  command -v "$tool" > /dev/null || refuse "outil requis absent : $tool."
done

# --- Le manifeste : un seul Job, nommé en DNS-1123 --------------------------
[ -r "$MANIFEST" ] || refuse "manifeste illisible : $MANIFEST."
kinds="$(grep -c '^kind:' "$MANIFEST" || true)"
[ "$kinds" = 1 ] || refuse "$MANIFEST doit décrire un seul objet ($kinds \`kind:\` lus)."
grep -qE '^kind:[[:space:]]*Job[[:space:]]*(#.*)?$' "$MANIFEST" \
  || refuse "$MANIFEST doit décrire un objet kind: Job."

# metadata.name de premier niveau : la clé `name:` à deux espaces du bloc
# `metadata:` en colonne 0 — ni un label `…/name:`, ni le `- name:` d'un
# conteneur —, commentaire de fin de ligne et guillemets ôtés.
JOB="$(awk '
  /^metadata:/ { inside = 1; next }
  inside && /^[^ #]/ { exit }
  inside && /^  name:/ {
    sub(/^  name:[ \t]*/, ""); sub(/[ \t]+#.*$/, ""); gsub(/["'\''[:space:]]/, "")
    print; exit
  }
' "$MANIFEST")"
[ -n "$JOB" ] || refuse "aucun metadata.name dans $MANIFEST."
# DNS-1123 : ce que Kubernetes accepte pour un Job, et jamais une option.
[[ "$JOB" =~ ^[a-z0-9]([-a-z0-9]*[a-z0-9])?$ && ${#JOB} -le 63 ]] \
  || refuse "le metadata.name « $JOB » de $MANIFEST n'est pas un nom DNS-1123."

# --- Les variables nommées --------------------------------------------------
# Les placeholders que le manifeste attend, BACKEND_IMAGE mis à part.
expected=" $(grep -o '\${[A-Za-z_][A-Za-z0-9_]*}' "$MANIFEST" | sed 's/^\${\(.*\)}$/\1/' \
  | grep -vx BACKEND_IMAGE | sort -u | tr '\n' ' ' || true)"
value_pattern='^[A-Za-z0-9._@:/+-]+$'
substitutions=()
# shellcheck disable=SC2016 # le littéral est voulu : c'est la liste d'envsubst
envsubst_vars='${BACKEND_IMAGE}'
for assignment in "$@"; do
  # Le nom seul est cité dans un refus, jamais la valeur (un collage malheureux).
  name="${assignment%%=*}"
  [[ "$assignment" =~ ^[A-Z_][A-Z0-9_]*= ]] \
    || refuse "« $name » n'est pas de la forme NOM=valeur (NOM en majuscules)."
  [[ "$expected" == *" $name "* ]] \
    || refuse "$name n'est pas un placeholder de $MANIFEST à passer en NOM=valeur (attendus :${expected% })."
  [[ "${assignment#*=}" =~ $value_pattern ]] \
    || refuse "valeur refusée pour $name : vide, ou hors [A-Za-z0-9._@:/+-] (elle est insérée telle quelle dans le YAML)."
  substitutions+=("$assignment")
  envsubst_vars+=" \${$name}"
done

# --- Rendu ------------------------------------------------------------------
rendered="$(mktemp)"
trap 'rm -f "$rendered"' EXIT
# L'image passée en argument l'emporte sur un BACKEND_IMAGE de l'environnement.
env ${substitutions[@]+"${substitutions[@]}"} BACKEND_IMAGE="$IMAGE" \
  envsubst "$envsubst_vars" < "$MANIFEST" > "$rendered"

leftover="$(grep -o '\${[^}]*}' "$rendered" | sort -u | tr '\n' ' ' || true)"
[ -z "$leftover" ] \
  || refuse "$MANIFEST garde des \${…} sans valeur : ${leftover% }. Un placeholder se passe en NOM=valeur, une variable shell s'écrit \$VAR."

# --- Cluster ----------------------------------------------------------------
# La spec d'un Job est immuable : celui du lancement précédent part d'abord.
if ! "$KUBECTL" -n "$NAMESPACE" delete "job/$JOB" --ignore-not-found; then
  echo "::error::run-job.sh : le cluster a refusé le delete du Job $JOB (namespace $NAMESPACE)." >&2
  exit 3
fi
if ! "$KUBECTL" -n "$NAMESPACE" apply -f - < "$rendered"; then
  echo "::error::run-job.sh : le cluster a refusé l'apply du Job $JOB (namespace $NAMESPACE)." >&2
  exit 3
fi

if ! "$WAIT" "$NAMESPACE" "job/$JOB" --timeout "$TIMEOUT"; then
  echo "::error::Le Job $JOB n'a pas abouti (namespace $NAMESPACE, délai $TIMEOUT s). Ses 200 dernières lignes de journaux, par conteneur :" >&2
  "$KUBECTL" -n "$NAMESPACE" logs "job/$JOB" --all-containers --prefix --tail=200 >&2 || true
  exit 1
fi

echo "Job $JOB terminé (namespace $NAMESPACE). Ses 200 dernières lignes de journaux, par conteneur :"
"$KUBECTL" -n "$NAMESPACE" logs "job/$JOB" --all-containers --prefix --tail=200 || true
