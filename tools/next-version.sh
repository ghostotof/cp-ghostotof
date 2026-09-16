#!/usr/bin/env bash
#
# next-version.sh
# ---------------------------------------------------------------------------
# Calcule la prochaine version du projet à partir de Conventional Commits.
# C'est la SEULE implémentation de ce calcul (spec 0006, D2) : la pipeline
# l'exécute pour tenir le nom de branche `release/<version>` honnête, et
# l'humain l'exécute pour nommer sa branche.
#
# Règle : depuis le dernier tag `v*` joignable depuis la base (origin/main),
# sur les commits base..HEAD hors commits de merge :
#   - `type!:` ou `BREAKING CHANGE:` / `BREAKING-CHANGE:` dans le corps → majeur
#     (ramené à un mineur tant que le projet est en 0.x : le passage à 1.0.0
#     est un geste délibéré, pas la conséquence d'un `!`) ;
#   - `feat` → mineur ;
#   - tout autre type conventionnel → correctif ;
#   - un commit hors convention est ignoré avec un avertissement (il ne
#     compte ni pour ni contre) ;
#   - aucun commit conventionnel → échec explicite (« rien à livrer »).
#
# Usage :  tools/next-version.sh [--no-fetch] [--base <ref>] [--head <ref>]
#                                [--repo <dir>]
#   --no-fetch  ne pas rafraîchir la base et les tags depuis origin
#               (par défaut : `git fetch origin main --tags`, pour ne jamais
#               calculer depuis un tag périmé en local)
#   --base      référence de la base (défaut : origin/main)
#   --head      référence de la tête (défaut : HEAD)
#   --repo      dépôt à inspecter (défaut : le répertoire courant) — utilisé
#               par le test, qui travaille sur un dépôt temporaire
#
# Sortie : la version nue (`0.14.0`) sur stdout ; diagnostics sur stderr.
# ---------------------------------------------------------------------------
set -euo pipefail

fetch=1
base="origin/main"
head="HEAD"
repo="."

while [ $# -gt 0 ]; do
  case "$1" in
    --no-fetch) fetch=0 ;;
    --base) base="${2:?--base attend une référence}"; shift ;;
    --head) head="${2:?--head attend une référence}"; shift ;;
    --repo) repo="${2:?--repo attend un répertoire}"; shift ;;
    -h|--help) sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "next-version.sh : option inconnue « $1 »" >&2; exit 2 ;;
  esac
  shift
done

g() { git -C "$repo" "$@"; }

if [ "$fetch" -eq 1 ]; then
  # origin/main et ses tags doivent être à jour, sinon le dernier tag lu est
  # celui de la dernière fois qu'on a fetché — et la version calculée est
  # peut-être déjà sortie.
  g fetch --quiet origin main --tags
fi

# Dernier tag v* joignable depuis la base : c'est la version en prod. Un tag
# posé ailleurs (sur une branche de travail) n'est pas une base.
if ! last_tag="$(g describe --tags --abbrev=0 --match 'v*' "$base" 2>/dev/null)"; then
  echo "next-version.sh : aucun tag v* joignable depuis $base" >&2
  exit 1
fi

if ! [[ "$last_tag" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
  echo "next-version.sh : le tag $last_tag n'est pas de la forme vX.Y.Z" >&2
  exit 1
fi
major="${BASH_REMATCH[1]}"; minor="${BASH_REMATCH[2]}"; patch="${BASH_REMATCH[3]}"

# Niveau : 0 = rien, 1 = correctif, 2 = mineur, 3 = majeur.
level=0
conventional=0

# Un enregistrement par commit : sha, sujet, corps, séparés par des marqueurs
# que git ne produit jamais dans un message (\x1e entre commits, \x1f entre
# champs). --no-merges : un commit de merge n'apporte rien par lui-même, ce
# sont les commits qu'il amène qui comptent — et ils sont dans la liste.
while IFS=$'\x1f' read -r -d $'\x1e' sha subject body; do
  [ -n "$sha" ] || continue
  if [[ "$subject" =~ ^([a-z]+)(\([^\)]*\))?(!)?:\  ]]; then
    conventional=$((conventional + 1))
    type="${BASH_REMATCH[1]}"
    bang="${BASH_REMATCH[3]}"
    commit_level=1
    if [ "$type" = "feat" ]; then commit_level=2; fi
    if [ -n "$bang" ] || grep -qE '^BREAKING[ -]CHANGE:' <<<"$body"; then commit_level=3; fi
    if [ "$commit_level" -gt "$level" ]; then level=$commit_level; fi
  else
    echo "next-version.sh : commit hors convention ignoré : ${sha:0:7} $subject" >&2
  fi
done < <(g log --no-merges --format=$'%H\x1f%s\x1f%b\x1e' "$base..$head")

if [ "$conventional" -eq 0 ]; then
  echo "next-version.sh : rien à livrer — aucun commit conventionnel entre $last_tag ($base) et $head" >&2
  exit 1
fi

# semver pour les versions initiales : en 0.x un majeur est un mineur.
if [ "$level" -eq 3 ] && [ "$major" -eq 0 ]; then level=2; fi

case "$level" in
  3) major=$((major + 1)); minor=0; patch=0 ;;
  2) minor=$((minor + 1)); patch=0 ;;
  1) patch=$((patch + 1)) ;;
esac

echo "$major.$minor.$patch"
