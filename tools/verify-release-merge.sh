#!/usr/bin/env bash
#
# verify-release-merge.sh
# ---------------------------------------------------------------------------
# Gardes LOCALES du déploiement en production (spec 0006, D7), extraites du
# job pour être testables sur un dépôt temporaire. Le job de la pipeline ne
# fait que l'appeler, puis ajoute les deux vérifications distantes (les
# manifestes d'images sur GHCR, le run de release vert) qui ne se testent
# pas hors ligne.
#
# Sur `main`, HEAD doit être le commit de merge d'une branche de release :
#   1. HEAD a un second parent (sinon : push direct, ou commit de copie des
#      notes — dans les deux cas rien à déployer) ;
#   2. RELEASE_SHA = HEAD^2, le HEAD de la branche de release, c'est-à-dire
#      le commit dont les images ont été construites et validées en préprod ;
#   3. RELEASE_NOTES.md existe À CE COMMIT (pas dans l'arbre de travail) et
#      sa première ligne est `# vX.Y.Z — <titre>` ;
#   4. la version en est extraite ; image_tag = <version>-<sha court>.
#
# Sur `release/*` (job release-version, spec D2), le même fichier est lu sur
# HEAD lui-même : option --no-merge, avec --expect-branch pour confronter le
# nom de branche et --expect-version pour confronter le calcul de
# next-version.sh. Un désaccord nomme toujours les valeurs comparées.
#
# Usage :  tools/verify-release-merge.sh [--repo <dir>] [--ref <ref>]
#                                        [--no-merge]
#                                        [--expect-branch release/X.Y.Z]
#                                        [--expect-version X.Y.Z]
# Sortie (stdout, format clé=valeur pour $GITHUB_OUTPUT) :
#   version=0.14.0
#   tag=v0.14.0
#   release_sha=<40 hex>
#   release_short_sha=<7 hex>
#   image_tag=0.14.0-<7 hex>
# ---------------------------------------------------------------------------
set -euo pipefail

repo="."
ref="HEAD"
require_merge=1
expect_branch=""
expect_version=""

while [ $# -gt 0 ]; do
  case "$1" in
    --repo) repo="${2:?--repo attend un répertoire}"; shift ;;
    --ref) ref="${2:?--ref attend une référence}"; shift ;;
    --no-merge) require_merge=0 ;;
    --expect-branch) expect_branch="${2:?--expect-branch attend un nom de branche}"; shift ;;
    --expect-version) expect_version="${2:?--expect-version attend une version}"; shift ;;
    -h|--help) sed -n '2,36p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "verify-release-merge.sh : option inconnue « $1 »" >&2; exit 2 ;;
  esac
  shift
done

g() { git -C "$repo" "$@"; }
die() { echo "verify-release-merge.sh : $*" >&2; exit 1; }

head_sha="$(g rev-parse --verify "$ref^{commit}")" || die "référence introuvable : $ref"

if [ "$require_merge" -eq 1 ]; then
  # Un commit de merge a un second parent. `rev-parse --verify` échoue
  # proprement sinon — c'est la garde n° 1 : un push direct sur main, ou le
  # commit de copie des notes (`[skip ci]`, poussé par finalize-release),
  # n'ont rien à déployer.
  release_sha="$(g rev-parse --verify --quiet "$head_sha^2" 2>/dev/null)" \
    || die "${head_sha:0:7} n'est pas un commit de merge : rien à déployer (un push direct sur main, ou le commit de copie des notes de release)"
else
  release_sha="$head_sha"
fi
release_short="$(g rev-parse --short=7 "$release_sha")"

# Le fichier est lu AU COMMIT de release, jamais dans l'arbre de travail :
# le job tourne sur un checkout de main, où le fichier peut être différent
# (une release suivante, une modification locale).
notes="$(g show "$release_sha:RELEASE_NOTES.md" 2>/dev/null)" \
  || die "RELEASE_NOTES.md absent au commit de release $release_short"

first_line="$(printf '%s\n' "$notes" | head -n 1)"
# Forme stricte : `# vX.Y.Z — <titre>`, tiret cadratin, comme les annotations
# de tag des releases précédentes. La première ligne devient le titre de la
# release GitHub (sans le `#`), le reste son corps.
if ! [[ "$first_line" =~ ^\#\ v([0-9]+\.[0-9]+\.[0-9]+)\ —\ .+$ ]]; then
  die "première ligne de RELEASE_NOTES.md au commit $release_short : « $first_line » — attendu « # vX.Y.Z — <titre> »"
fi
version="${BASH_REMATCH[1]}"

if [ -n "$expect_branch" ] && [ "$expect_branch" != "release/$version" ]; then
  die "le nom de branche « $expect_branch » ne correspond pas au titre de RELEASE_NOTES.md (v$version) : attendu « release/$version »"
fi

if [ -n "$expect_version" ] && [ "$expect_version" != "$version" ]; then
  die "la version calculée ($expect_version) ne correspond pas au titre de RELEASE_NOTES.md (v$version)"
fi

printf 'version=%s\n' "$version"
printf 'tag=v%s\n' "$version"
printf 'release_sha=%s\n' "$release_sha"
printf 'release_short_sha=%s\n' "$release_short"
printf 'image_tag=%s-%s\n' "$version" "$release_short"
