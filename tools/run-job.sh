#!/usr/bin/env bash
#
# run-job.sh
# ---------------------------------------------------------------------------
# Lance un Job Kubernetes tenu hors de kustomize (issue #488) — la migration,
# le seed, le message témoin, la création de compte — et attend sa fin. Une
# seule implémentation de la séquence, que le pipeline écrivait en ligne à
# chaque Job :
#
#   1. rendu du manifeste par envsubst, à liste EXPLICITE : `${BACKEND_IMAGE}`
#      et les variables nommées en argument, rien d'autre — envsubst sans
#      liste effacerait toute autre `$VARIABLE` du manifeste ;
#   2. refus si un `${…}` subsiste : il partirait littéral dans la spec du
#      pod. Rien n'a encore touché le cluster (fail-closed) ;
#   3. suppression du Job précédent, dont la spec est immuable ;
#   4. apply, puis attente par tools/wait-rollout.sh (issue #353), qui fait
#      échouer en quelques secondes un conteneur qui ne démarrera pas ou un
#      Job `Failed`, au lieu d'épuiser le délai ;
#   5. en cas d'échec : `::error::` nommant le Job, ses 200 dernières lignes
#      de journaux sur stderr (`|| true` : un conteneur jamais démarré n'en a
#      pas), sortie en 1.
#
# Le nom du Job est lu dans le `metadata.name` du manifeste : le nom supprimé,
# attendu et lu ne peut pas diverger de celui appliqué. En cas de succès,
# rien n'est imprimé des journaux : l'appelant décide s'il les veut.
#
# Ce script ne lit aucun Secret ; il n'en passe aucun non plus — une valeur
# en argument est visible dans `ps`. Les variables nommées ne servent qu'à
# des valeurs publiables (un nom de compte), jamais à un secret.
#
# Usage :  tools/run-job.sh <namespace> <manifeste> <image backend> <délai s> [NOM=valeur …]
# Exemple : tools/run-job.sh preprod k8s/base/migrate-job.yaml "$IMAGE" 300
# Env :    RUN_JOB_KUBECTL, RUN_JOB_WAIT  binaires de substitution
#          (tests hors ligne, tools/tests/run-job.test.sh)
# ---------------------------------------------------------------------------
set -euo pipefail

USAGE="usage : $0 <namespace> <manifeste> <image backend> <délai s> [NOM=valeur …]"
NAMESPACE="${1:?$USAGE}"
MANIFEST="${2:?$USAGE}"
IMAGE="${3:?$USAGE}"
TIMEOUT="${4:?$USAGE}"
shift 4

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
KUBECTL="${RUN_JOB_KUBECTL:-kubectl}"
WAIT="${RUN_JOB_WAIT:-$ROOT/tools/wait-rollout.sh}"

# Variables à substituer en plus de l'image : NOM=valeur, NOM en majuscules
# comme les placeholders des manifestes.
substitutions=()
# shellcheck disable=SC2016 # le littéral est voulu : c'est la liste d'envsubst
list='${BACKEND_IMAGE}'
for assignment in "$@"; do
  if [[ ! "$assignment" =~ ^[A-Z_][A-Z0-9_]*= ]]; then
    echo "::error::run-job.sh : « $assignment » n'est pas de la forme NOM=valeur (NOM en majuscules)." >&2
    exit 2
  fi
  substitutions+=("$assignment")
  list+=" \${${assignment%%=*}}"
done

# metadata.name de premier niveau : le bloc `metadata:` en colonne 0, sa clé
# `name:` à deux espaces — ni un label `…/name:`, ni le `- name:` d'un
# conteneur.
JOB="$(awk '
  /^metadata:/ { inside = 1; next }
  inside && /^[^ #]/ { exit }
  inside && /^  name:/ { sub(/^  name:[ \t]*/, ""); gsub(/["'\''[:space:]]/, ""); print; exit }
' "$MANIFEST")"
if [ -z "$JOB" ]; then
  echo "::error::run-job.sh : aucun metadata.name dans $MANIFEST." >&2
  exit 2
fi

rendered="$(mktemp)"
trap 'rm -f "$rendered"' EXIT
# L'image passée en argument l'emporte sur un BACKEND_IMAGE de l'environnement.
env "${substitutions[@]}" BACKEND_IMAGE="$IMAGE" envsubst "$list" < "$MANIFEST" > "$rendered"

leftover="$(grep -o '\${[A-Za-z_][A-Za-z0-9_]*}' "$rendered" | sort -u | tr '\n' ' ' || true)"
if [ -n "$leftover" ]; then
  echo "::error::run-job.sh : $MANIFEST garde des placeholders sans valeur : ${leftover% }. Les passer en NOM=valeur." >&2
  exit 2
fi

# La spec d'un Job est immuable : celui du lancement précédent part d'abord.
"$KUBECTL" -n "$NAMESPACE" delete job "$JOB" --ignore-not-found
"$KUBECTL" -n "$NAMESPACE" apply -f - < "$rendered"

if ! "$WAIT" "$NAMESPACE" "job/$JOB" --timeout "$TIMEOUT"; then
  echo "::error::Le Job $JOB n'a pas abouti (namespace $NAMESPACE, délai $TIMEOUT s). Ses 200 dernières lignes de journaux :" >&2
  "$KUBECTL" -n "$NAMESPACE" logs "job/$JOB" --tail=200 >&2 || true
  exit 1
fi
