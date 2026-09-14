#!/usr/bin/env bash
#
# Résumé de job GitHub Actions pour un outil qui parle le format `github`
# (PHPStan `--error-format=github`, Psalm `--output-format=github`), à partir
# du log de son étape (capturé par `tee`).
#
# Usage : summary-annotations.sh "<titre>" <log> [ligne-bonus-regex] >> "$GITHUB_STEP_SUMMARY"
#
# Ces outils émettent une commande de workflow par constat :
#   ::error file=src/X.php,line=12,col=0::Le message
# GitHub en fait des annotations dans l'onglet « Files changed », mais pas de
# carte de synthèse. Ce script compte ces lignes et les remet en table — le
# statut du job reste décidé par l'étape de l'outil (ce script sort toujours 0).
#
# Le 3e argument, facultatif, est une expression `grep -E` : la première ligne
# du log qui la satisfait est reprise telle quelle sous la table (ex. la
# couverture d'inférence de Psalm).

set -u

title="${1:?titre requis}"
log="${2:?chemin du log requis}"
bonus="${3:-}"

if [ ! -f "$log" ]; then
  printf '## %s\n\n⚠️ Aucun log trouvé (`%s`) : l'\''outil ne s'\''est pas exécuté — voir l'\''étape.\n\n' "$title" "$log"
  exit 0
fi

# Décodage des trois séquences que le format `github` encode dans le message.
decode() { sed -e 's/%0A/ /g' -e 's/%0D//g' -e 's/%25/%/g'; }

errors=$(grep -c '^::error' "$log" || true)
warnings=$(grep -c '^::warning' "$log" || true)

if [ "$errors" -eq 0 ]; then icon='✅'; else icon='❌'; fi

printf '## %s %s\n\n' "$icon" "$title"
printf '| Erreurs | Avertissements |\n|---:|---:|\n| %d | %d |\n\n' "$errors" "$warnings"

if [ -n "$bonus" ]; then
  line=$(grep -E -m1 "$bonus" "$log" || true)
  [ -n "$line" ] && printf '%s\n\n' "$line"
fi

if [ "$errors" -eq 0 ] && [ "$warnings" -eq 0 ]; then
  exit 0
fi

limit=40
printf '| Niveau | Fichier | Message |\n|---|---|---|\n'
grep -E '^::(error|warning)' "$log" | head -n "$limit" | decode | while IFS= read -r raw; do
  level=${raw#::}; level=${level%% *}
  props=${raw#*::}; props=${props%%::*}          # "error file=…,line=…,col=…" ou "error "
  message=${raw#*::*::}
  file=$(printf '%s' "$props" | sed -n 's/.*file=\([^,]*\).*/\1/p')
  lineno=$(printf '%s' "$props" | sed -n 's/.*line=\([0-9]*\).*/\1/p')
  where='—'
  [ -n "$file" ] && where="\`${file}${lineno:+:$lineno}\`"
  message=$(printf '%s' "$message" | sed 's/|/\\|/g')
  printf '| %s | %s | %s |\n' "$level" "$where" "$message"
done

total=$((errors + warnings))
if [ "$total" -gt "$limit" ]; then
  printf '\n… et %d autre(s), voir le log.\n' "$((total - limit))"
fi
printf '\n'
