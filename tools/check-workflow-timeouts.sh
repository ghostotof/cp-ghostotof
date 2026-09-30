#!/usr/bin/env bash
#
# check-workflow-timeouts.sh
# ---------------------------------------------------------------------------
# Vérifie que chaque job d'un workflow GitHub Actions déclare `timeout-minutes`,
# et que chaque étape qui appelle `apt-get` déclare le sien (issue #285).
#
# Sans `timeout-minutes`, GitHub laisse un job tourner 360 minutes. Le
# 2026-09-30, un miroir apt figé a bloqué `build-images` de la release v0.18.3
# plus de 30 minutes, sans échec ni message : la release entière attendait.
# Un job borné échoue vite et se relance ; une étape apt bornée échoue avant
# que le job tout entier n'atteigne sa propre limite.
#
# Le contrôle lit le YAML par son indentation, sans dépendance (ni yq ni
# PyYAML, dont la présence sur le runner n'est pas garantie). Il suppose la
# mise en forme des workflows du dépôt : jobs à 2 espaces sous `jobs:`, clés
# de job à 4, étapes `- ` à 6, clés d'étape à 8. Un workflow mis en forme
# autrement produirait un faux négatif possible, pas un faux positif : le test
# associé vérifie donc aussi qu'il détecte bien les manques sur un cas connu.
#
# Usage :  tools/check-workflow-timeouts.sh <workflow.yml>...
#          (sans argument : .github/workflows/*.yml du dépôt)
# Sortie : 0 si tout est borné, 1 en listant chaque manque, 2 sur erreur d'usage.
# ---------------------------------------------------------------------------
set -euo pipefail

if [ "$#" -eq 0 ]; then
  root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  set -- "$root"/.github/workflows/*.yml
fi

for file in "$@"; do
  if [ ! -f "$file" ]; then
    printf 'Fichier introuvable : %s\n' "$file" >&2
    exit 2
  fi
done

# Une ligne par manque : « <fichier>: job <nom> » ou « <fichier>: job <nom>,
# étape <nom> (apt-get) ».
missing="$(awk '
  function flush_step() {
    if (in_step && step_apt && !step_timeout) {
      printf "%s: job %s, étape « %s » (apt-get) sans timeout-minutes\n", file, job, step_name
    }
    in_step = 0; step_apt = 0; step_timeout = 0; step_name = "(sans nom)"
  }
  function flush_job() {
    flush_step()
    if (job != "" && !job_timeout) {
      printf "%s: job %s sans timeout-minutes\n", file, job
    }
    job = ""; job_timeout = 0
  }
  # flush_job() avant de changer `file` : le manque appartient au fichier précédent.
  FNR == 1 { if (in_jobs) flush_job(); in_jobs = 0; job = ""; file = FILENAME }
  /^jobs:[[:space:]]*(#.*)?$/ { in_jobs = 1; next }
  # Toute autre clé de premier niveau clôt la section jobs.
  /^[^[:space:]#]/ { if (in_jobs) flush_job(); in_jobs = 0; next }
  !in_jobs { next }
  /^  [A-Za-z0-9_-]+:[[:space:]]*(#.*)?$/ {
    flush_job()
    job = $1; sub(/:$/, "", job)
    next
  }
  /^    timeout-minutes:/ { job_timeout = 1; next }
  # Une autre clé de job (needs, env…) après les étapes clôt la dernière étape.
  /^    [A-Za-z0-9_-]+:/ { flush_step(); next }
  /^      - / {
    flush_step()
    in_step = 1
    if (match($0, /^      - name:[[:space:]]*/)) step_name = substr($0, RLENGTH + 1)
  }
  in_step && /^        name:/ { step_name = $0; sub(/^        name:[[:space:]]*/, "", step_name) }
  in_step && /^        timeout-minutes:/ { step_timeout = 1 }
  in_step && /apt-get[[:space:]]/ { step_apt = 1 }
  END { if (in_jobs) flush_job() }
' "$@")"

if [ -n "$missing" ]; then
  printf '%s\n' "$missing"
  exit 1
fi
printf 'Tous les jobs et toutes les étapes apt-get déclarent timeout-minutes.\n'
