#!/usr/bin/env bash
#
# check-claude-rules.sh
# ---------------------------------------------------------------------------
# Garde-fou du découpage de .claude/CLAUDE.md en règles à portée de chemin
# (issue #346). Claude Code ne charge une règle .claude/rules/*.md que
# lorsqu'un fichier correspondant à l'un de ses `paths:` est lu ou écrit.
# Deux défaillances seraient donc silencieuses :
#
#   - une règle sans `paths:` est chargée à chaque session, ce qui annule le
#     découpage sans que rien ne le signale ;
#   - un glob qui ne correspond plus à rien (répertoire renommé ou supprimé)
#     ne charge plus jamais sa règle : l'invariant cesse de s'appliquer.
#
# S'y ajoute un plafond sur .claude/CLAUDE.md : sans lui, la racine
# regrossit section après section, ce qui a mené à #346.
#
# Les globs sont évalués par git lui-même (pathspec `:(glob)`, où `*` ne
# traverse pas `/` et `**` traverse les répertoires), contre les fichiers
# suivis. Les accolades `{a,b}` sont refusées : git ne les développe pas, le
# contrôle ne pourrait donc pas les vérifier. Il suffit d'écrire un glob par
# ligne.
#
# Usage :  tools/check-claude-rules.sh [racine du dépôt]
#          (sans argument : le dépôt qui contient ce script)
# Sortie : 0 si tout est conforme, 1 en listant chaque manque, 2 sur erreur
#          d'usage.
# ---------------------------------------------------------------------------
set -euo pipefail

# Plafond de .claude/CLAUDE.md, en octets (#346 : 50 000, la racine en
# faisait 35 193 après le découpage).
readonly MAX_ROOT_BYTES=50000

root="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
if ! git -C "$root" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  printf 'Pas un dépôt git : %s\n' "$root" >&2
  exit 2
fi

problems=()

claude_md="$root/.claude/CLAUDE.md"
if [ -f "$claude_md" ]; then
  size="$(wc -c <"$claude_md")"
  size="${size// /}"
  if [ "$size" -gt "$MAX_ROOT_BYTES" ]; then
    problems+=(".claude/CLAUDE.md : $size octets, au-delà du plafond de $MAX_ROOT_BYTES — déplacer l'ajout dans la règle de sa zone")
  fi
fi

shopt -s nullglob
for rule in "$root"/.claude/rules/*.md; do
  name=".claude/rules/$(basename "$rule")"

  # Les globs de `paths:`, un par ligne « - glob », guillemets retirés, lus
  # seulement dans un frontmatter qui ouvre le fichier et se referme.
  globs="$(awk '
    NR == 1 { if ($0 != "---") exit; in_fm = 1; next }
    in_fm && $0 == "---" { closed = 1; exit }
    in_fm && /^paths:[[:space:]]*$/ { in_paths = 1; next }
    in_fm && in_paths && /^[[:space:]]+-[[:space:]]+/ {
      g = $0
      sub(/^[[:space:]]+-[[:space:]]+/, "", g)
      sub(/[[:space:]]+$/, "", g)
      if (g ~ /^".*"$/ || g ~ /^\047.*\047$/) g = substr(g, 2, length(g) - 2)
      print g
      next
    }
    in_fm && /^[^[:space:]]/ { in_paths = 0 }
    END { if (!closed) print "\001frontmatter-non-fermé" }
  ' "$rule")"

  if [ -z "$globs" ] || [ "$globs" = $'\001frontmatter-non-fermé' ]; then
    problems+=("$name : aucun \`paths:\` dans un frontmatter en tête de fichier — la règle serait chargée à chaque session")
    continue
  fi
  if [[ "$globs" == *$'\001frontmatter-non-fermé'* ]]; then
    problems+=("$name : frontmatter non refermé par « --- »")
    continue
  fi

  while IFS= read -r glob; do
    if [[ "$glob" == *'{'* ]]; then
      problems+=("$name : « $glob » utilise des accolades, que ce contrôle ne sait pas vérifier — un glob par ligne")
    elif [ -z "$(git -C "$root" ls-files -- ":(glob)$glob" | head -n 1)" ]; then
      problems+=("$name : « $glob » ne correspond à aucun fichier suivi — la règle ne se chargerait jamais pour ce chemin")
    fi
  done <<<"$globs"
done

if [ "${#problems[@]}" -gt 0 ]; then
  printf '%s\n' "${problems[@]}"
  exit 1
fi
printf 'Règles .claude/ conformes.\n'
