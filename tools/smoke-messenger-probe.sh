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
# Le Job prouve le trajet depuis l'image de la release, pas depuis les pods
# réels : le script exige d'abord que `backend` et `worker` tournent cette
# image (le déploiement a attendu leur rollout), sans quoi un worker neuf
# derrière une API ancienne passerait au vert (relecture de #470).
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

for deployment in backend worker; do
  images="$("$KUBECTL" -n "$NAMESPACE" get "deployment/$deployment" -o jsonpath='{.spec.template.spec.containers[*].image}')"
  case " $images " in
    *" $IMAGE "*) ;;
    *)
      echo "::error::deployment/$deployment ne tourne pas l'image de la release ($IMAGE) : « $images ». Le message témoin ne prouverait rien de ses pods." >&2
      exit 1
      ;;
  esac
done

# La spec d'un Job est immuable : celui du déploiement précédent part d'abord.
"$KUBECTL" -n "$NAMESPACE" delete job "$JOB" --ignore-not-found
# Liste explicite : envsubst n'efface aucune autre `$VARIABLE` du manifeste.
# shellcheck disable=SC2016 # le littéral est voulu : c'est la liste d'envsubst
BACKEND_IMAGE="$IMAGE" envsubst '${BACKEND_IMAGE}' < "$ROOT/k8s/base/messenger-probe-job.yaml" \
  | "$KUBECTL" -n "$NAMESPACE" apply -f -

# 300 s : au-delà des 240 s d'activeDeadlineSeconds du Job, que
# tools/wait-rollout.sh voit échouer aussitôt (Failed=True).
if ! "$WAIT" "$NAMESPACE" "job/$JOB" --timeout 300; then
  echo "::error::Le worker n'a pas consommé le message témoin (issue #470) : écart de clé de MAC entre backend et worker, worker incapable de décoder, ou worker arrêté. Journaux du Job :" >&2
  "$KUBECTL" -n "$NAMESPACE" logs "job/$JOB" --tail=50 >&2 || true
  exit 1
fi

"$KUBECTL" -n "$NAMESPACE" logs "job/$JOB" --tail=20 || true
