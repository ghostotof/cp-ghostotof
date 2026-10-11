#!/usr/bin/env bash
#
# smoke-messenger-probe.sh
# ---------------------------------------------------------------------------
# Smoke préprod du trajet API → worker (issue #470), lancé par
# `smoke-test-preprod` après le déploiement : applique le Job
# k8s/base/messenger-probe-job.yaml avec l'image de la release, attend sa fin
# par tools/wait-rollout.sh, et sort en 1 s'il échoue — ce qui fait échouer le
# smoke, donc déclenche `rollback-preprod`.
#
# Le Job tourne `app:messenger:probe` avec les Secrets du backend : il signe
# un message témoin sous la clé de l'API, et attend que le worker l'ait
# traité. Un écart de clé de MAC entre `backend` et `worker`, ou un worker
# incapable de décoder, envoie le témoin dans `failed` : le Job échoue au bout
# de 90 s, et ses journaux disent quoi vérifier.
#
# Ce script ne lit aucun Secret : les variables restent dans le pod du Job.
# Aucun mail ne part : le témoin ne pose qu'une marque en cache.
#
# Usage :  tools/smoke-messenger-probe.sh <namespace> <image backend>
# Env :    SMOKE_PROBE_KUBECTL, SMOKE_PROBE_WAIT  binaires de substitution
#          (tests hors ligne, tools/tests/smoke-messenger-probe.test.sh)
# ---------------------------------------------------------------------------
set -euo pipefail

NAMESPACE="${1:?usage : $0 <namespace> <image backend>}"
IMAGE="${2:?usage : $0 <namespace> <image backend>}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
KUBECTL="${SMOKE_PROBE_KUBECTL:-kubectl}"
WAIT="${SMOKE_PROBE_WAIT:-$ROOT/tools/wait-rollout.sh}"
JOB=backend-messenger-probe

# La spec d'un Job est immuable : celui du déploiement précédent part d'abord.
"$KUBECTL" -n "$NAMESPACE" delete job "$JOB" --ignore-not-found
# Liste explicite : envsubst n'efface aucune autre `$VARIABLE` du manifeste.
# shellcheck disable=SC2016 # le littéral est voulu : c'est la liste d'envsubst
BACKEND_IMAGE="$IMAGE" envsubst '${BACKEND_IMAGE}' < "$ROOT/k8s/base/messenger-probe-job.yaml" \
  | "$KUBECTL" -n "$NAMESPACE" apply -f -

# 180 s : les 150 s d'activeDeadlineSeconds du Job, plus la marge du pull.
if ! "$WAIT" "$NAMESPACE" "job/$JOB" --timeout 180; then
  echo "::error::Le worker n'a pas consommé le message témoin (issue #470) : écart de clé de MAC entre backend et worker, worker incapable de décoder, ou worker arrêté. Journaux du Job :" >&2
  "$KUBECTL" -n "$NAMESPACE" logs "job/$JOB" --tail=50 >&2 || true
  exit 1
fi

"$KUBECTL" -n "$NAMESPACE" logs "job/$JOB" --tail=20 || true
