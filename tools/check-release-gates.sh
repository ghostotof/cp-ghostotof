#!/usr/bin/env bash
#
# check-release-gates.sh
# ---------------------------------------------------------------------------
# Vérifie que les jobs qui jugent une release en préprod et les checks requis
# des rulesets disent la même chose (issue #600). Trois règles :
#
#   1. une sonde qui accuse un workload que `rollback-preprod` ne ramène pas
#      (aujourd'hui : le pg_hba.conf de `postgres`, #596) tourne dans un job
#      qui n'est PAS un ancêtre de `rollback-preprod`, direct ou transitif.
#      `failure()` y est vrai dès qu'un ancêtre échoue : sonde dans
#      `smoke-test-preprod`, son échec ramenait backend, worker et frontend —
#      qui n'étaient pas en cause — et laissait `postgres` fautif. La sonde
#      doit aussi être lancée par un job, sans quoi la règle serait vide ;
#   2. chaque check requis de `main` et de `develop` (tools/github-settings.sh)
#      est un job du workflow : un job renommé laisserait sinon la PR en
#      attente d'un check qui ne viendra jamais ;
#   3. chaque job qui suit directement `deploy-preprod`, `rollback-preprod`
#      excepté, est un check requis de `main`. Un job rouge mais non requis
#      laisserait fusionner la PR de release, puis la garde 3 de `deploy-prod`
#      (un run `success` exigé) refuserait APRÈS l'avancée de `main`.
#
# Le YAML est lu par son indentation, comme check-workflow-timeouts.sh : jobs à
# 2 espaces sous `jobs:`, clés de job à 4, `needs` en liste en ligne
# (`[a, b]`), en scalaire ou en liste en bloc. Une ligne de commentaire ne
# lance rien. Les checks requis sont lus sur les deux lignes
# `MAIN_REQUIRED_CHECKS=(…)` et `DEVELOP_REQUIRED_CHECKS=(…)` du script de
# réglages : absentes, en double ou vides, c'est une erreur de lecture (2),
# jamais un faux vert.
#
# Usage :  tools/check-release-gates.sh [<pipeline.yml> <github-settings.sh>]
#          (sans argument : les fichiers du dépôt)
# Sortie : 0 si cohérent, 1 en listant chaque écart, 2 sur erreur d'usage ou
#          de lecture.
# ---------------------------------------------------------------------------
set -euo pipefail

ROLLBACK_JOB=rollback-preprod
DEPLOY_JOB=deploy-preprod
# Manifestes de sonde dont l'échec ne doit jamais ramener l'application : ils
# accusent un workload que ROLLBACK_JOB ne touche pas.
NO_ROLLBACK_PROBES=(k8s/base/postgres-hba-probe-job.yaml)

usage() { echo "Usage : $0 [<pipeline.yml> <github-settings.sh>]" >&2; exit 2; }
case "$#" in
  0)
    root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    set -- "$root/.github/workflows/pipeline.yml" "$root/tools/github-settings.sh"
    ;;
  2) ;;
  *) usage ;;
esac
pipeline="$1" settings="$2"
for file in "$pipeline" "$settings"; do
  if [ ! -f "$file" ]; then
    printf 'Fichier introuvable : %s\n' "$file" >&2
    exit 2
  fi
done

# ---- lecture du workflow ------------------------------------------------------
# Une ligne par fait : « JOB <nom> », « NEED <job> <besoin> », « RUN <job> <manifeste> ».
probes="$(IFS='|'; printf '%s' "${NO_ROLLBACK_PROBES[*]}")"
facts="$(awk -v probes="$probes" '
  BEGIN { n = split(probes, probe, "|") }
  function add_needs(list,   k, parts, item, i) {
    gsub(/[][]/, "", list)
    k = split(list, parts, ",")
    for (i = 1; i <= k; i++) {
      item = parts[i]; gsub(/^[[:space:]]+|[[:space:]]+$/, "", item)
      if (item != "") printf "NEED %s %s\n", job, item
    }
  }
  /^jobs:[[:space:]]*(#.*)?$/ { in_jobs = 1; next }
  /^[^[:space:]#]/ { in_jobs = 0; next }
  !in_jobs { next }
  /^  [A-Za-z0-9_-]+:[[:space:]]*(#.*)?$/ {
    job = $1; sub(/:$/, "", job); in_needs = 0
    printf "JOB %s\n", job
    next
  }
  /^    needs:/ {
    value = $0; sub(/^    needs:[[:space:]]*/, "", value); sub(/[[:space:]]*#.*$/, "", value)
    if (value == "") { in_needs = 1 } else { in_needs = 0; add_needs(value) }
    next
  }
  in_needs && /^      - / { value = $0; sub(/^      - /, "", value); sub(/[[:space:]]*#.*$/, "", value); add_needs(value); next }
  /^    [A-Za-z0-9_-]+:/ { in_needs = 0 }
  /^[[:space:]]*#/ { next }
  job != "" {
    for (p = 1; p <= n; p++) if (index($0, probe[p])) printf "RUN %s %s\n", job, probe[p]
  }
' "$pipeline")"

declare -A is_job=() needs_of=()
while read -r kind a b; do
  case "$kind" in
    JOB)  is_job["$a"]=1 ;;
    NEED) needs_of["$a"]="${needs_of[$a]:-} $b" ;;
  esac
done <<<"$facts"

if [ -z "${is_job[$ROLLBACK_JOB]:-}" ] || [ -z "${is_job[$DEPLOY_JOB]:-}" ]; then
  printf 'Jobs %s et %s introuvables dans %s : lecture impossible.\n' "$ROLLBACK_JOB" "$DEPLOY_JOB" "$pipeline" >&2
  exit 2
fi

# ---- lecture des checks requis --------------------------------------------------
# required_checks <variable> : les noms entre parenthèses de l'unique ligne
# `<variable>=(…)`.
required_checks() {
  local var="$1" lines
  lines="$(grep -E "^$var=\(" "$settings" || true)"
  if [ -z "$lines" ] || [ "$(printf '%s\n' "$lines" | wc -l)" -ne 1 ]; then
    printf '%s : une ligne %s=(…) attendue exactement.\n' "$settings" "$var" >&2
    return 2
  fi
  lines="${lines#*\(}"
  lines="${lines%%\)*}"
  if [ -z "${lines//[[:space:]]/}" ]; then
    printf '%s : %s est vide.\n' "$settings" "$var" >&2
    return 2
  fi
  printf '%s\n' "$lines"
}
main_checks="$(required_checks MAIN_REQUIRED_CHECKS)" || exit 2
develop_checks="$(required_checks DEVELOP_REQUIRED_CHECKS)" || exit 2

errors=()

# ---- règle 1 : les sondes hors des ancêtres du rollback ------------------------
declare -A ancestor=()
queue=("$ROLLBACK_JOB")
while [ "${#queue[@]}" -gt 0 ]; do
  current="${queue[0]}"
  queue=("${queue[@]:1}")
  for need in ${needs_of[$current]:-}; do
    if [ -z "${ancestor[$need]:-}" ]; then
      ancestor["$need"]=1
      queue+=("$need")
    fi
  done
done

for manifest in "${NO_ROLLBACK_PROBES[@]}"; do
  launchers="$(awk -v m="$manifest" '$1 == "RUN" && $3 == m { print $2 }' <<<"$facts" | sort -u)"
  if [ -z "$launchers" ]; then
    errors+=("$manifest n'est lancé par aucun job : la règle « sonde hors rollback » ne porterait sur rien")
    continue
  fi
  for job in $launchers; do
    if [ -n "${ancestor[$job]:-}" ]; then
      errors+=("job $job lance $manifest et précède $ROLLBACK_JOB : son échec ramènerait l'application, pas le workload en cause")
    fi
  done
done

# ---- règle 2 : chaque check requis est un job ---------------------------------
for check in $main_checks; do
  [ -n "${is_job[$check]:-}" ] || errors+=("check requis de main « $check » : aucun job de ce nom dans $pipeline")
done
for check in $develop_checks; do
  [ -n "${is_job[$check]:-}" ] || errors+=("check requis de develop « $check » : aucun job de ce nom dans $pipeline")
done

# ---- règle 3 : chaque job après deploy-preprod est requis sur main ------------
for job in "${!is_job[@]}"; do
  [ "$job" = "$ROLLBACK_JOB" ] && continue
  case " ${needs_of[$job]:-} " in
    *" $DEPLOY_JOB "*)
      case " $(printf '%s' "$main_checks" | tr -s '[:space:]' ' ') " in
        *" $job "*) ;;
        *) errors+=("job $job (après $DEPLOY_JOB) n'est pas un check requis de main : rouge, il laisserait pourtant fusionner la PR de release") ;;
      esac
      ;;
  esac
done

if [ "${#errors[@]}" -gt 0 ]; then
  printf '%s\n' "${errors[@]}" | sort
  exit 1
fi
printf 'Jobs de la préprod et checks requis cohérents.\n'
