#!/usr/bin/env bash
#
# wait-external-secrets.sh
# ---------------------------------------------------------------------------
# Attend que les ExternalSecret nommés soient synchronisés depuis Scaleway
# Secret Manager, et échoue en les nommant sinon (issue #325). Joué par
# `deploy-preprod`/`deploy-prod` juste après l'apply des seuls objets ESO de
# la release, AVANT le Job de migration et le rollout.
#
# Pourquoi : un ExternalSecret qui référence une clé absente laisse son
# Secret Kubernetes incomplet ; le Deployment ne démarre pas et la cause
# n'apparaît que dans les événements des pods (`CreateContainerConfigError`),
# après un rollout qui a expiré. C'est arrivé en phase 1 (clé Anthropic).
# Échouer ici est fail-closed : rien n'a encore été migré ni déployé, la
# release précédente reste en service.
#
# « Synchronisé » = condition `Ready` à True ET `status.syncedResourceVersion`
# qui commence par `<metadata.generation>-`. La seconde moitié n'est pas
# décorative : ESO ne pose `syncedResourceVersion` qu'après une
# synchronisation réussie, et tant qu'il n'a pas réconcilié une spec modifiée
# le statut décrit encore la génération précédente — `Ready=True` compris.
# Un `kubectl wait --for=condition=Ready` passerait donc au vert à tort
# précisément quand une release ajoute une clé (relevé sur ESO v2.9.0,
# préprod, 2026-10-05).
#
# Facultatif : un ExternalSecret annoté `cp-ghostotof.com/deploy-gate:
# optional` (backend-xdebug-trigger en préprod) ne bloque pas ; s'il n'est pas
# synchronisé, il est signalé en avertissement. L'attente s'arrête dès que
# tous les ES obligatoires sont prêts.
#
# force-sync : au premier tour, chaque ES obligatoire non prêt reçoit
# l'annotation `force-sync=<epoch>` (procédure documentée par ESO, verbe
# `patch` du Role déployeur). Sans elle, un ES en erreur depuis un déploiement
# précédent reste dans le backoff exponentiel du contrôleur (jusqu'à ~16 min) :
# « je publie la clé manquante, je relance le job » échouerait encore.
#
# Aucune valeur de secret : le script ne lit QUE les objets ExternalSecret
# (spec et statut, qui n'en contiennent pas), jamais un Secret. En cas
# d'échec il cite les NOMS des clés distantes (`remoteRef.key`, déjà publics
# dans le dépôt), parce que le message d'ESO est générique (« could not get
# secret data from provider ») et ne dit pas laquelle manque.
#
# Bornes : un appel `kubectl get externalsecrets` par tour, timeout/interval+1
# tours, soit ~timeout secondes plus la latence des appels.
#
# Usage :  tools/wait-external-secrets.sh <namespace> <externalsecret>…
#            [--timeout 120]   secondes d'attente au total (entier ≥ 0)
#            [--interval 5]    secondes entre deux tours (entier ≥ 1)
# Sortie : 0 tous les obligatoires prêts ; 1 au moins un ne l'est pas ;
#          2 usage.
# Env :    WAIT_ES_KUBECTL, WAIT_ES_SLEEP  binaires de substitution (tests
#          hors ligne)
# ---------------------------------------------------------------------------
set -euo pipefail

OPTIONAL_ANNOTATION="cp-ghostotof.com/deploy-gate"

namespace=""; names=(); timeout=120; interval=5

usage_error() { echo "wait-external-secrets.sh : $*" >&2; echo "usage : $0 <namespace> <externalsecret>… [--timeout 120] [--interval 5]" >&2; exit 2; }

while [ $# -gt 0 ]; do
  case "$1" in
    --timeout) timeout="${2:-}"; shift ;;
    --interval) interval="${2:-}"; shift ;;
    -h|--help) sed -n '2,54p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*) usage_error "option inconnue « $1 »" ;;
    *) if [ -z "$namespace" ]; then namespace="$1"; else names+=("$1"); fi ;;
  esac
  shift
done

[ -n "$namespace" ] || usage_error "namespace manquant"
[ "${#names[@]}" -gt 0 ] || usage_error "aucun ExternalSecret à attendre"
[[ "$timeout" =~ ^[0-9]+$ ]] || usage_error "--timeout attend un entier ≥ 0 (reçu « $timeout »)"
[[ "$interval" =~ ^[0-9]+$ ]] && [ "$interval" -ge 1 ] || usage_error "--interval attend un entier ≥ 1 (reçu « $interval »)"
command -v jq >/dev/null || { echo "wait-external-secrets.sh : outil requis absent : jq" >&2; exit 1; }

kubectl_bin="${WAIT_ES_KUBECTL:-kubectl}"
sleep_bin="${WAIT_ES_SLEEP:-sleep}"
rounds=$((timeout / interval + 1))

# Une ligne par ES demandé, champs séparés par US (\x1f, pas une tabulation :
# `read` fusionnerait des tabulations consécutives et décalerait un champ
# vide) : nom, état (ready | stale | pending | absent), facultatif
# (true|false), raison, message, clés distantes.
#   stale   = Ready=True mais statut d'une génération antérieure ;
#   pending = Ready absent ou False.
# shellcheck disable=SC2016  # $… ci-dessous sont des variables jq
JQ_STATE='
  (.items // [] | map({key: .metadata.name, value: .}) | from_entries) as $by
  | $ARGS.positional[] as $n
  | $by[$n] as $es
  | if $es == null then
      [$n, "absent", "false", "introuvable", "aucun ExternalSecret de ce nom dans le namespace", ""]
    else
      ([$es.status.conditions // [] | .[] | select(.type == "Ready")] | .[0]) as $c
      | ($es.metadata.generation | tostring) as $g
      | (($es.status.syncedResourceVersion // "") | startswith($g + "-")) as $current
      | [ $n,
          (if ($c.status // "") == "True" then (if $current then "ready" else "stale" end) else "pending" end),
          ((($es.metadata.annotations // {})[$annotation] == "optional") | tostring),
          ($c.reason // "sans condition Ready"),
          (($c.message // "") | gsub("[\r\n]+"; " ")),
          ([$es.spec.data[]?.remoteRef.key] + [$es.spec.dataFrom[]?.extract.key // empty] | join(", ")) ]
    end
  | join("\u001f")'

declare -A state optional reason message keys
read_states() {
  local json line n s o r m k
  json="$("$kubectl_bin" -n "$namespace" get externalsecrets -o json)" || return 1
  while IFS= read -r line; do
    IFS=$'\x1f' read -r n s o r m k <<<"$line"
    state[$n]="$s"; optional[$n]="$o"; reason[$n]="$r"; message[$n]="$m"; keys[$n]="$k"
  done < <(jq -r --arg annotation "$OPTIONAL_ANNOTATION" "$JQ_STATE" --args "${names[@]}" <<<"$json")
}

# Liste des ES obligatoires pas encore prêts (vide = on peut s'arrêter).
blocking() {
  local n out=()
  for n in "${names[@]}"; do
    if [ "${state[$n]:-absent}" != "ready" ] && [ "${optional[$n]:-false}" != "true" ]; then
      out+=("$n")
    fi
  done
  echo "${out[*]}"
}

echo "ExternalSecret à synchroniser dans $namespace : ${names[*]} (au plus ${timeout} s)"
forced=0; read_ok=0
for ((round = 1; round <= rounds; round++)); do
  if read_states; then
    read_ok=1
  else
    echo "::warning::lecture des ExternalSecret de $namespace impossible au tour $round/$rounds, nouvel essai"
  fi
  pending="$(blocking)"
  if [ -z "$pending" ]; then break; fi

  if [ "$forced" -eq 0 ] && [ "$read_ok" -eq 1 ]; then
    forced=1
    stamp="$(date +%s)"
    for n in $pending; do
      if [ "${state[$n]:-absent}" = "absent" ]; then continue; fi
      "$kubectl_bin" -n "$namespace" annotate externalsecret "$n" "force-sync=$stamp" --overwrite >/dev/null \
        || echo "::warning::force-sync impossible sur l'ExternalSecret $n (RBAC ?) ; attente du prochain essai d'ESO"
    done
  fi
  echo "en attente (tour $round/$rounds) : $pending"
  if [ "$round" -lt "$rounds" ]; then "$sleep_bin" "$interval"; fi
done

# Les facultatifs non prêts : signalés, jamais bloquants.
for n in "${names[@]}"; do
  if [ "${optional[$n]:-false}" = "true" ] && [ "${state[$n]:-absent}" != "ready" ]; then
    echo "::warning::ExternalSecret facultatif $n non synchronisé (${reason[$n]} : ${message[$n]}) — sans effet sur le déploiement"
  fi
done

if [ -z "$pending" ]; then
  echo "ExternalSecret synchronisés : ${names[*]}"
  exit 0
fi

if [ "$read_ok" -eq 0 ]; then
  echo "::error::impossible de lire les ExternalSecret du namespace $namespace (kubectl get externalsecrets) : rien n'est migré ni déployé"
  exit 1
fi
for n in $pending; do
  case "${state[$n]:-absent}" in
    absent) detail="${message[$n]}" ;;
    stale)  detail="ESO n'a pas encore synchronisé la spec appliquée par cette release (le statut Ready décrit la génération précédente)" ;;
    *)      detail="${reason[$n]} : ${message[$n]}" ;;
  esac
  echo "::error::ExternalSecret $n non synchronisé après ${timeout} s — $detail. Clés distantes référencées : ${keys[$n]:-aucune}. Vérifier qu'elles existent dans Scaleway Secret Manager (k8s/README.md) ; rien n'est migré ni déployé, la release précédente reste en service."
done
exit 1
