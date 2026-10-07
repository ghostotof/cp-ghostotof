#!/usr/bin/env bash
#
# wait-rollout.sh
# ---------------------------------------------------------------------------
# Attend la fin du rollout d'un Deployment ou d'un Job, et échoue DÈS qu'un
# de ses nouveaux conteneurs est bloqué pour une raison qui ne se résoudra
# pas seule (issue #353). Remplace, dans `deploy-preprod`/`deploy-prod`, les
# `kubectl rollout status` et `kubectl wait --for=condition=complete`.
#
# Pourquoi : un Secret ou une clé absents d'une variable d'environnement
# (`secretKeyRef`/`configMapKeyRef` mal orthographié, clé de Secret Manager
# absente du `data` de son ExternalSecret, Secret créé à la main absent)
# laissent le pod en `CreateContainerConfigError`. `rollout status` n'en dit
# rien et expire après 180 s (300 s pour le Job de migration), sans cause
# dans les journaux du job. tools/wait-external-secrets.sh (issue #325) ne
# couvre que les ExternalSecret non synchronisés.
#
# Limite connue : un Secret ou un ConfigMap absent monté en VOLUME non
# optionnel (`jwt-keys`, `backend-nginx-conf`) ne produit pas cette raison.
# Le pod reste en `ContainerCreating` et la cause n'apparaît que dans un
# événement `FailedMount`, que le Role déployeur ne lit pas (pas de verbe sur
# `events`). Ce cas finit encore en délai dépassé. `cv-pdf`, monté
# `optional: true`, ne bloque jamais le pod.
#
# Méthode : des tranches courtes de l'attente kubectl habituelle
# (`rollout status --timeout=<interval>s` ou `wait --for=condition=complete
# --timeout=<interval>s`), dont la sémantique « terminé » reste celle de
# kubectl ; entre deux tranches, une lecture des pods de la révision en cours.
#   Deployment : les pods du ReplicaSet dont l'annotation
#     `deployment.kubernetes.io/revision` est celle du Deployment, une fois
#     `status.observedGeneration` à jour — jamais ceux d'un rollout précédent
#     resté bloqué, qui donneraient un faux positif.
#   Job : les pods dont un ownerReference porte l'uid du Job — jamais ceux de
#     l'ancien `backend-migrate`, supprimé juste avant mais pas encore
#     ramassé. Un Job en condition `Failed=True` (backoffLimit atteint)
#     échoue aussitôt, au lieu d'attendre la fin du délai.
# Les conteneurs ET les initContainers sont inspectés, pods en cours de
# suppression exclus.
#
# Raisons fatales et persistance exigée avant d'échouer :
#   InvalidImageName             immédiat (ne se corrige jamais seul) ;
#   CreateContainerConfigError   15 s (le kubelet réessaie ; un Secret en
#                                cours d'écriture peut arriver) ;
#   ErrImagePull/ImagePullBackOff 60 s, comptés ensemble (le kubelet alterne
#                                les deux ; un registre momentanément
#                                indisponible aboutit à +10 s, +30 s).
# Toute autre raison (`ContainerCreating`, `PodInitializing`,
# `CrashLoopBackOff`, `CreateContainerError`…) est ignorée : l'attente
# continue jusqu'au délai. La durée affichée est celle observée par le
# script, pas celle depuis laquelle le conteneur attend.
#
# Erreurs de kubectl : une tranche qui sort en erreur sans avoir expiré
# (Forbidden, NotFound, ProgressDeadlineExceeded…) est tolérée une ou deux
# fois ; trois d'affilée font échouer en citant l'erreur, comme l'aurait
# fait un `rollout status` d'un seul tenant.
#
# Aucune valeur de secret : seuls les statuts des pods sont lus, jamais un
# Secret. `state.waiting.message` vient du kubelet et cite des NOMS
# (« couldn't find key FOO in Secret preprod/backend-secrets »).
#
# Bornes : au plus timeout/interval + 1 tranches, et arrêt dès que l'horloge
# dépasse le délai ; chaque tranche dure au plus interval s (+ 10 s de
# requête), chaque lecture des pods au plus 10 s. Dépassement maximal :
# ~interval + 20 s. Une tranche qui revient avant son terme (erreur de
# l'API) est complétée par une pause, pour ne pas marteler l'API.
#
# Usage :  tools/wait-rollout.sh <namespace> <deployment/NOM|job/NOM>
#            [--timeout 180]   secondes d'attente au total (entier ≥ 0)
#            [--interval 5]    secondes par tranche (entier ≥ 1)
# Sortie : 0 prêt ; 1 raison fatale persistante, Job en échec, erreurs
#          kubectl répétées ou délai dépassé ; 2 usage.
# Env :    WAIT_ROLLOUT_KUBECTL, WAIT_ROLLOUT_SLEEP, WAIT_ROLLOUT_CLOCK
#          binaires de substitution (tests hors ligne ; l'horloge imprime
#          un horodatage en secondes)
# ---------------------------------------------------------------------------
set -euo pipefail

REQUEST_TIMEOUT="10s"
# Raisons d'attente fatales, chacune rangée dans une classe ; seule table
# à modifier pour en ajouter une (le jq en reçoit la liste). Les deux
# raisons de pull partagent une classe : le kubelet les alterne.
declare -A REASON_CLASS=(
  [InvalidImageName]=image-name
  [CreateContainerConfigError]=config
  [ErrImagePull]=pull
  [ImagePullBackOff]=pull
)
# Délai de persistance (s) par classe.
declare -A GRACE=([image-name]=0 [config]=15 [pull]=60)
# Ce que kubectl écrit quand une tranche expire sans erreur, en regex
# étendue : « timed out waiting for the condition » (wait.ErrorInterrupted,
# texte partagé par `rollout status` et `wait`) ou « context deadline
# exceeded » (tranche expirée avant la synchronisation initiale du cache,
# API lente : UntilWithSync de client-go). Toute autre sortie en erreur
# compte pour MAX_SLICE_ERRORS.
SLICE_EXPIRED="timed out waiting for the condition|context deadline exceeded"
MAX_SLICE_ERRORS=3

namespace=""; target=""; timeout=180; interval=5

usage_error() { echo "wait-rollout.sh : $*" >&2; echo "usage : $0 <namespace> <deployment/NOM|job/NOM> [--timeout 180] [--interval 5]" >&2; exit 2; }

while [ $# -gt 0 ]; do
  case "$1" in
    --timeout|--interval)
      [ $# -ge 2 ] || usage_error "$1 attend une valeur"
      if [ "$1" = "--timeout" ]; then timeout="$2"; else interval="$2"; fi
      shift ;;
    # L'en-tête de commentaires, jusqu'à la première ligne de code.
    -h|--help) sed -n '2,/^[^#]/{/^#/p}' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*) usage_error "option inconnue « $1 »" ;;
    *) if [ -z "$namespace" ]; then namespace="$1"
       elif [ -z "$target" ]; then target="$1"
       else usage_error "argument en trop « $1 »"; fi ;;
  esac
  shift
done

[ -n "$namespace" ] || usage_error "namespace manquant"
[ -n "$target" ] || usage_error "cible manquante (deployment/NOM ou job/NOM)"
[[ "$target" =~ ^(deployment|job)/[a-z0-9]([-a-z0-9.]*[a-z0-9])?$ ]] || usage_error "cible « $target » : deployment/NOM ou job/NOM attendu"
[[ "$timeout" =~ ^[0-9]+$ ]] || usage_error "--timeout attend un entier ≥ 0 (reçu « $timeout »)"
[[ "$interval" =~ ^[0-9]+$ ]] && [ "$interval" -ge 1 ] || usage_error "--interval attend un entier ≥ 1 (reçu « $interval »)"
command -v jq >/dev/null || { echo "wait-rollout.sh : outil requis absent : jq" >&2; exit 1; }

kind="${target%%/*}"; name="${target#*/}"
kubectl_bin="${WAIT_ROLLOUT_KUBECTL:-kubectl}"
sleep_bin="${WAIT_ROLLOUT_SLEEP:-sleep}"
clock_bin="${WAIT_ROLLOUT_CLOCK:-}"
now() { if [ -n "$clock_bin" ]; then "$clock_bin"; else date +%s; fi; }

if [ "$kind" = "deployment" ]; then resources="deployments,replicasets,pods"; else resources="jobs,pods"; fi

# Une ligne par fait, champs séparés par US (\x1f, cf. wait-external-
# secrets.sh : `read` fusionnerait des tabulations consécutives) :
#   STATE <état> [raison] [message]   état : observed | unobserved | absent
#                                     | no-replicaset | failed (Job) ;
#   WAIT <pod> <conteneur> <init:true|false> <raison> <message>
#     un par conteneur des pods de la révision en cours dont la raison
#     d'attente est dans REASON_CLASS.
# shellcheck disable=SC2016  # $… ci-dessous sont des variables jq
JQ_INSPECT='
  def oneline: (. // "") | gsub("[\r\n]+"; " ");
  def waits($items; $owner):
    $items[]
    | select(.kind == "Pod" and .metadata.deletionTimestamp == null)
    | select(any(.metadata.ownerReferences[]?; .uid == $owner))
    | .metadata.name as $pod
    | ((.status.initContainerStatuses // [] | map(. + {init: true}))
       + (.status.containerStatuses // [] | map(. + {init: false})))[]
    | select((.state.waiting.reason // "") | IN($fatal[]))
    | ["WAIT", $pod, .name, (.init | tostring), .state.waiting.reason, (.state.waiting.message | oneline)]
    | join("\u001f");
  (.items // []) as $items
  | if $kind == "deployment" then
      ([$items[] | select(.kind == "Deployment" and .metadata.name == $name)][0]) as $d
      | if $d == null then "STATE\u001fabsent"
        elif ($d.status.observedGeneration // 0) < $d.metadata.generation then "STATE\u001funobserved"
        else
          ($d.metadata.annotations["deployment.kubernetes.io/revision"] // "") as $rev
          | ([$items[] | select(.kind == "ReplicaSet")
              | select(any(.metadata.ownerReferences[]?; .uid == $d.metadata.uid))
              | select((.metadata.annotations // {})["deployment.kubernetes.io/revision"] == $rev)][0]) as $rs
          | if $rs == null then "STATE\u001fno-replicaset"
            else "STATE\u001fobserved", waits($items; $rs.metadata.uid) end
        end
    else
      ([$items[] | select(.kind == "Job" and .metadata.name == $name)][0]) as $j
      | if $j == null then "STATE\u001fabsent"
        else
          ([$j.status.conditions // [] | .[] | select(.type == "Failed" and .status == "True")][0]) as $f
          | if $f != null then ["STATE", "failed", ($f.reason // "sans raison"), ($f.message | oneline)] | join("\u001f")
            else "STATE\u001fobserved", waits($items; $j.metadata.uid) end
        end
    end'

# Les raisons fatales, en tableau JSON pour le jq (`--argjson fatal`).
fatal_reasons_json="$(printf '%s\n' "${!REASON_CLASS[@]}" | jq -R . | jq -cs .)"

declare -A first_seen=()   # « pod|conteneur|classe » -> première observation
pending=()                 # raisons fatales observées, sous leur seuil
fatal=()                   # raisons fatales qui ont dépassé leur seuil
job_failed=""

# Lit l'état une fois, met à jour first_seen, remplit pending/fatal/job_failed.
# Échoue (retour 1) si kubectl échoue ou si sa réponse n'est pas le JSON
# attendu ; jq est joué dans une substitution dont on teste le code.
inspect() {
  local json parsed line state state_reason state_message pod container is_init reason message t cls key age where
  json="$("$kubectl_bin" --request-timeout="$REQUEST_TIMEOUT" -n "$namespace" get "$resources" -o json)" || return 1
  parsed="$(jq -r --arg kind "$kind" --arg name "$name" --argjson fatal "$fatal_reasons_json" "$JQ_INSPECT" <<<"$json" 2>/dev/null)" || return 1
  [ -n "$parsed" ] || return 1
  t="$(now)"
  pending=(); fatal=()
  declare -A seen=()
  while IFS= read -r line; do
    case "$line" in
      STATE$'\x1f'*)
        IFS=$'\x1f' read -r _ state state_reason state_message <<<"$line"
        if [ "$state" = "failed" ]; then job_failed="$state_reason : $state_message"; fi ;;
      WAIT$'\x1f'*)
        IFS=$'\x1f' read -r _ pod container is_init reason message <<<"$line"
        cls="${REASON_CLASS[$reason]}"; key="$pod|$container|$cls"
        seen[$key]=1
        [ -n "${first_seen[$key]:-}" ] || first_seen[$key]="$t"
        # Âge depuis la première observation par CE script : le statut d'un
        # pod ne date pas l'entrée dans la raison d'attente.
        age=$((t - first_seen[$key]))
        if [ "$is_init" = "true" ]; then where="initContainer $container"; else where="conteneur $container"; fi
        if [ "$age" -ge "${GRACE[$cls]}" ]; then
          fatal+=("pod $pod, $where : $reason observé depuis ${age} s — $message")
        else
          pending+=("pod $pod, $where : $reason observé depuis ${age} s (seuil ${GRACE[$cls]} s) — $message")
        fi ;;
    esac
  done <<<"$parsed"
  # Une raison qui a disparu (conteneur démarré, pod remplacé) repart de zéro.
  for key in "${!first_seen[@]}"; do
    [ -n "${seen[$key]:-}" ] || unset "first_seen[$key]"
  done
  return 0
}

slice() {
  if [ "$kind" = "deployment" ]; then
    "$kubectl_bin" --request-timeout="$((interval + 10))s" -n "$namespace" rollout status "deployment/$name" --timeout="${interval}s"
  else
    "$kubectl_bin" --request-timeout="$((interval + 10))s" -n "$namespace" wait --for=condition=complete "job/$name" --timeout="${interval}s"
  fi
}

err_file="$(mktemp)"
trap 'rm -f "$err_file"' EXIT

echo "Attente de $target dans $namespace (au plus ${timeout} s, échec anticipé sur raison fatale)"
start="$(now)"
deadline=$((start + timeout))
max_rounds=$((timeout / interval + 1))
last_printed=""
inspect_ok=1
slice_errors=0   # tranches consécutives sorties en erreur sans avoir expiré
for ((round = 1; round <= max_rounds; round++)); do
  slice_start="$(now)"
  if slice_out="$(slice 2>"$err_file")"; then
    printf '%s\n' "$slice_out"
    exit 0
  fi
  # La progression de kubectl, sans répéter une ligne inchangée.
  if [ -n "$slice_out" ] && [ "$slice_out" != "$last_printed" ]; then
    printf '%s\n' "$slice_out"; last_printed="$slice_out"
  fi

  if inspect; then
    inspect_ok=1
  else
    inspect_ok=0
    echo "::warning::lecture des pods de $target impossible au tour $round/$max_rounds, nouvel essai"
  fi
  if [ -n "$job_failed" ]; then
    echo "::error::$target en échec dans $namespace ($job_failed) : le Job ne réessaiera plus."
    exit 1
  fi
  if [ "${#fatal[@]}" -gt 0 ]; then
    for f in "${fatal[@]}"; do
      echo "::error::$target bloqué dans $namespace : $f"
    done
    echo "::error::$target ne démarrera pas sans intervention : échec sans attendre la fin des ${timeout} s."
    exit 1
  fi
  # Une tranche qui n'a pas simplement expiré a rencontré une erreur :
  # Forbidden, NotFound, ProgressDeadlineExceeded… `rollout status`/`wait`
  # en un seul appel l'auraient rapportée aussitôt. Une erreur isolée de
  # l'API est tolérée ; MAX_SLICE_ERRORS d'affilée font échouer.
  if grep -q -E "$SLICE_EXPIRED" "$err_file"; then
    slice_errors=0
  else
    slice_errors=$((slice_errors + 1))
    if [ "$slice_errors" -ge "$MAX_SLICE_ERRORS" ]; then
      echo "::error::$target : $slice_errors erreurs kubectl consécutives dans $namespace — $(tr '\n' ' ' < "$err_file" | sed 's/ *$//')"
      exit 1
    fi
  fi

  t="$(now)"
  if [ "$t" -ge "$deadline" ]; then break; fi
  # Tranche revenue avant son terme (erreur de l'API, objet introuvable) :
  # on complète l'intervalle plutôt que d'enchaîner les appels.
  if [ $((t - slice_start)) -lt "$interval" ]; then "$sleep_bin" $((interval - (t - slice_start))); fi
done

last_error="$(tr '\n' ' ' < "$err_file" | sed 's/ *$//')"
echo "::error::$target pas prêt dans $namespace après ${timeout} s${last_error:+ ($last_error)}."
if [ "$inspect_ok" -eq 0 ]; then
  echo "::error::$target : la dernière lecture des pods a échoué, leur état n'est pas connu."
elif [ "${#pending[@]}" -gt 0 ]; then
  for p in "${pending[@]}"; do echo "::error::$target, au dernier tour : $p"; done
fi
exit 1
