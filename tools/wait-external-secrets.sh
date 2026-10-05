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
# tours, chaque appel au cluster limité à 10 s (`--request-timeout`) : au
# pire ~timeout + 10 s par tour, jamais le `timeout-minutes` du job. Si la
# lecture échoue aux derniers tours, l'erreur le dit : le verdict décrit
# alors l'état du dernier tour lu, pas l'état courant.
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
REQUEST_TIMEOUT="10s"

namespace=""; names=(); timeout=120; interval=5

usage_error() { echo "wait-external-secrets.sh : $*" >&2; echo "usage : $0 <namespace> <externalsecret>… [--timeout 120] [--interval 5]" >&2; exit 2; }

while [ $# -gt 0 ]; do
  case "$1" in
    --timeout|--interval)
      [ $# -ge 2 ] || usage_error "$1 attend une valeur"
      if [ "$1" = "--timeout" ]; then timeout="$2"; else interval="$2"; fi
      shift ;;
    # L'en-tête de commentaires, jusqu'à la première ligne de code.
    -h|--help) sed -n '2,/^[^#]/{/^#/p}' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
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
# Échoue (retour 1) si kubectl échoue OU si sa réponse n'est pas le JSON
# attendu (page d'erreur d'un proxy, sortie 0) : jq est joué dans une
# substitution dont on teste le code, jamais dans `< <(…)` où son échec
# passerait inaperçu et laisserait les états vides.
read_states() {
  local json parsed line n s o r m k
  json="$("$kubectl_bin" --request-timeout="$REQUEST_TIMEOUT" -n "$namespace" get externalsecrets -o json)" || return 1
  parsed="$(jq -r --arg annotation "$OPTIONAL_ANNOTATION" "$JQ_STATE" --args "${names[@]}" <<<"$json" 2>/dev/null)" || return 1
  while IFS= read -r line; do
    IFS=$'\x1f' read -r n s o r m k <<<"$line"
    state[$n]="$s"; optional[$n]="$o"; reason[$n]="$r"; message[$n]="$m"; keys[$n]="$k"
  done <<<"$parsed"
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
forced=0
last_read=0    # dernier tour dont la lecture a réussi (0 = aucun)
last_round=0   # dernier tour joué
for ((round = 1; round <= rounds; round++)); do
  last_round=$round
  if read_states; then
    last_read=$round
  else
    echo "::warning::lecture des ExternalSecret de $namespace impossible au tour $round/$rounds, nouvel essai"
  fi
  blocking_names="$(blocking)"
  if [ -z "$blocking_names" ]; then break; fi

  if [ "$forced" -eq 0 ] && [ "$last_read" -gt 0 ]; then
    forced=1
    stamp="$(date +%s)"
    for n in $blocking_names; do
      if [ "${state[$n]:-absent}" = "absent" ]; then continue; fi
      "$kubectl_bin" --request-timeout="$REQUEST_TIMEOUT" -n "$namespace" annotate externalsecret "$n" "force-sync=$stamp" --overwrite >/dev/null \
        || echo "::warning::force-sync impossible sur l'ExternalSecret $n (RBAC ?) ; attente du prochain essai d'ESO"
    done
  fi
  echo "en attente (tour $round/$rounds) : $blocking_names"
  if [ "$round" -lt "$rounds" ]; then "$sleep_bin" "$interval"; fi
done

# Les facultatifs non prêts : signalés, jamais bloquants — et absents du
# bilan, qui ne liste que ce qui est réellement synchronisé.
synced=()
for n in "${names[@]}"; do
  if [ "${state[$n]:-absent}" = "ready" ]; then
    synced+=("$n")
  elif [ "${optional[$n]:-false}" = "true" ]; then
    echo "::warning::ExternalSecret facultatif $n non synchronisé (${reason[$n]:-état inconnu} : ${message[$n]:-}) — sans effet sur le déploiement"
  fi
done

if [ -z "$blocking_names" ]; then
  echo "ExternalSecret synchronisés : ${synced[*]}"
  exit 0
fi

if [ "$last_read" -eq 0 ]; then
  echo "::error::impossible de lire les ExternalSecret du namespace $namespace (kubectl get externalsecrets, réponse absente ou illisible) : rien n'est migré ni déployé"
  exit 1
fi
staleness=""
if [ "$last_read" -lt "$last_round" ]; then
  staleness=" (dernière lecture réussie au tour $last_read/$rounds, lectures suivantes impossibles : l'état peut avoir changé depuis)"
fi
for n in $blocking_names; do
  case "${state[$n]:-absent}" in
    absent) detail="${message[$n]:-introuvable}" ;;
    stale)  detail="${reason[$n]:-} pour la génération précédente : ESO n'a pas encore synchronisé la spec appliquée par cette release" ;;
    *)      detail="${reason[$n]:-} : ${message[$n]:-}" ;;
  esac
  echo "::error::ExternalSecret $n non synchronisé après ${timeout} s$staleness — $detail. Clés distantes référencées : ${keys[$n]:-aucune}. Vérifier qu'elles existent dans Scaleway Secret Manager (k8s/README.md) ; rien n'est migré ni déployé, la release précédente reste en service."
done
exit 1
