#!/usr/bin/env bash
#
# smoke-messenger-probe.sh
# ---------------------------------------------------------------------------
# Smoke préprod du trajet API → worker (issue #470), lancé par
# `smoke-test-preprod` après le déploiement : lance le Job
# k8s/base/messenger-probe-job.yaml avec l'image de la release par
# tools/run-job.sh (issue #488), et sort en 1 s'il échoue — ce qui fait
# échouer le smoke, donc déclenche `rollback-preprod`.
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
# Env :    SMOKE_PROBE_KUBECTL, SMOKE_PROBE_WAIT  binaires de substitution,
#          transmis à run-job.sh (tests hors ligne,
#          tools/tests/smoke-messenger-probe.test.sh)
# ---------------------------------------------------------------------------
set -euo pipefail

NAMESPACE="${1:?usage : $0 <namespace> <image backend>}"
IMAGE="${2:?usage : $0 <namespace> <image backend>}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
KUBECTL="${SMOKE_PROBE_KUBECTL:-kubectl}"
WAIT="${SMOKE_PROBE_WAIT:-$ROOT/tools/wait-rollout.sh}"

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

# Suppression du Job précédent, apply, attente et journaux (après un succès
# comme après un échec) : tools/run-job.sh (issue #488), avec les mêmes
# substituts que ce script. 300 s : le budget de la sonde, pulls compris. Un
# témoin non consommé fait sortir la commande en 1 au bout de 90 s, Job
# `Failed=True` que tools/wait-rollout.sh voit aussitôt ; un pod bloqué, lui,
# tourne encore à l'expiration de l'attente — l'activeDeadlineSeconds du Job
# est au-dessus (issue #535) —, et run-job.sh imprime ses journaux.
status=0
RUN_JOB_KUBECTL="$KUBECTL" RUN_JOB_WAIT="$WAIT" \
  "$ROOT/tools/run-job.sh" "$NAMESPACE" "$ROOT/k8s/base/messenger-probe-job.yaml" "$IMAGE" 300 || status=$?
# Seul le code 1 dit que le Job a tourné sans aboutir : le diagnostic du
# témoin ne vaut que pour lui. Un refus avant le cluster (2) ou du cluster (3)
# n'a rien à voir avec le worker, et run-job.sh l'a déjà nommé.
if [ "$status" -eq 1 ]; then
  echo "::error::Le worker n'a pas consommé le message témoin (issue #470) : écart de clé de MAC entre backend et worker, worker incapable de décoder, ou worker arrêté. Les journaux du Job sont ci-dessus." >&2
fi
exit "$status"
