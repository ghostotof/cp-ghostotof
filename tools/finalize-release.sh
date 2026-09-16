#!/usr/bin/env bash
#
# finalize-release.sh
# ---------------------------------------------------------------------------
# Les six étapes qui suivent une mise en production verte (spec 0006, D8),
# extraites du job finalize-release pour être testables sur un dépôt
# temporaire. Chaque étape est IDEMPOTENTE : un re-run après un succès
# complet ne change rien et reste vert ; un re-run après un échec reprend là
# où ça s'est arrêté. Aucune étape n'annule les précédentes.
#
#   1. tag annoté v<version> sur le commit de release, annotation =
#      RELEASE_NOTES.md lu À CE COMMIT, --cleanup=verbatim (les titres
#      Markdown commencent par `#`, que git supprimerait sinon) ;
#      déjà posé sur ce commit → rien ; posé ailleurs → échec ;
#   2. push du tag ;
#   3. release GitHub : titre = première ligne sans `#`, corps = le reste,
#      --verify-tag --latest ; déjà publiée → rien ;
#   4. copie RELEASE_NOTES.md → docs/releases/v<version>.md, commit sur main
#      avec `[skip ci]` (OBLIGATOIRE : les push de la deploy key déclenchent
#      les workflows) ; fichier identique déjà là → rien ; différent → échec ;
#   5. suppression de la branche release/<version> ; absente → rien ;
#   6. fast-forward de develop sur main (`git push main:develop`, refusé par
#      git si develop a divergé) ; refus → ARRÊT PROPRE, code 0, le résumé
#      demande une PR main → develop.
#
# Le dépôt courant doit être un checkout de `main` au commit de merge (ce que
# fait le job), avec un remote `origin` sur lequel les push passent (deploy
# key, cf. le job). Le script écrit une ligne de résumé par étape sur stdout,
# et dans --summary <fichier> si donné ($GITHUB_STEP_SUMMARY).
#
# Usage :  tools/finalize-release.sh --version X.Y.Z --release-sha <sha>
#            [--repo <dir>] [--remote origin] [--main main] [--develop develop]
#            [--notes-dir docs/releases] [--summary <fichier>]
#            [--skip-github-release]   (tests : pas de gh, pas de réseau)
# ---------------------------------------------------------------------------
set -euo pipefail

repo="."; remote="origin"; main="main"; develop="develop"; notes_dir="docs/releases"
version=""; release_sha=""; summary=""; github_release=1

while [ $# -gt 0 ]; do
  case "$1" in
    --repo) repo="${2:?}"; shift ;;
    --remote) remote="${2:?}"; shift ;;
    --main) main="${2:?}"; shift ;;
    --develop) develop="${2:?}"; shift ;;
    --notes-dir) notes_dir="${2:?}"; shift ;;
    --version) version="${2:?}"; shift ;;
    --release-sha) release_sha="${2:?}"; shift ;;
    --summary) summary="${2:?}"; shift ;;
    --skip-github-release) github_release=0 ;;
    -h|--help) sed -n '2,38p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "finalize-release.sh : option inconnue « $1 »" >&2; exit 2 ;;
  esac
  shift
done

[ -n "$version" ] || { echo "finalize-release.sh : --version manquant" >&2; exit 2; }
[ -n "$release_sha" ] || { echo "finalize-release.sh : --release-sha manquant" >&2; exit 2; }

# Identité fixe pour tout ce que le script écrit (tag annoté, commit de
# copie) : le runner n'a pas de user.name/user.email, et un `git tag -a`
# sans identité échoue avec « Committer identity unknown ».
g() { git -C "$repo" -c user.name="release-bot" -c user.email="release-bot@users.noreply.github.com" "$@"; }
die() { echo "finalize-release.sh : $*" >&2; exit 1; }
say() {
  echo "- $*"
  if [ -n "$summary" ]; then echo "- $*" >> "$summary"; fi
}

tag="v$version"
branch="release/$version"
release_sha="$(g rev-parse --verify "$release_sha^{commit}")" || die "commit de release introuvable : $release_sha"

# Les notes, lues au commit de release et jamais dans l'arbre de travail.
notes="$(g show "$release_sha:RELEASE_NOTES.md")" || die "RELEASE_NOTES.md absent au commit $release_sha"
first_line="$(printf '%s\n' "$notes" | head -n 1)"
[[ "$first_line" =~ ^\#\ v([0-9]+\.[0-9]+\.[0-9]+)\ —\ .+$ ]] || die "première ligne des notes inattendue : « $first_line »"
[ "${BASH_REMATCH[1]}" = "$version" ] || die "les notes disent v${BASH_REMATCH[1]}, la version demandée est $version"
title="$(printf '%s' "$first_line" | sed -E 's/^#+[[:space:]]*//')"
body="$(printf '%s\n' "$notes" | tail -n +2 | sed -e '/./,$!d')"

# --- 1. tag annoté ----------------------------------------------------------
if existing="$(g rev-parse --verify --quiet "refs/tags/$tag^{commit}")"; then
  if [ "$existing" = "$release_sha" ]; then
    say "tag \`$tag\` : déjà posé sur \`${release_sha:0:7}\`"
  else
    die "le tag $tag existe déjà sur ${existing:0:7}, pas sur le commit de release ${release_sha:0:7} — un tag n'est jamais déplacé"
  fi
else
  printf '%s\n' "$notes" | g tag -a "$tag" "$release_sha" --cleanup=verbatim -F -
  say "tag \`$tag\` : posé sur \`${release_sha:0:7}\`"
fi

# --- 2. push du tag -----------------------------------------------------------
# Refusé par git si le tag distant pointe ailleurs : jamais de --force ici.
g push --quiet "$remote" "refs/tags/$tag" || die "push du tag $tag refusé par $remote"
say "tag \`$tag\` : poussé"

# --- 3. release GitHub --------------------------------------------------------
if [ "$github_release" -eq 1 ]; then
  if gh release view "$tag" >/dev/null 2>&1; then
    say "release GitHub \`$tag\` : déjà publiée"
  else
    notes_file="$(mktemp)"
    printf '%s\n' "$body" > "$notes_file"
    gh release create "$tag" --verify-tag --latest --title "$title" --notes-file "$notes_file" >/dev/null
    rm -f "$notes_file"
    say "release GitHub \`$tag\` : publiée ($(gh release view "$tag" --json url --jq .url))"
  fi
else
  say "release GitHub \`$tag\` : ignorée (--skip-github-release)"
fi

# --- 4. copie des notes sur main ---------------------------------------------
target="$notes_dir/$tag.md"
if [ -f "$repo/$target" ]; then
  if [ "$(cat "$repo/$target")" = "$notes" ]; then
    say "\`$target\` : déjà présent et identique"
  else
    die "$target existe déjà avec un contenu différent des notes du commit de release — à arbitrer à la main"
  fi
else
  mkdir -p "$repo/$notes_dir"
  printf '%s\n' "$notes" > "$repo/$target"
  g add "$target"
  g commit --quiet -m "docs(releases): notes de la version $tag [skip ci]" -m "Copie de RELEASE_NOTES.md au commit de release ${release_sha:0:7}, par finalize-release."
  g push --quiet "$remote" "HEAD:refs/heads/$main" || die "push de la copie des notes sur $main refusé par $remote (main a bougé ? re-run après vérification)"
  say "\`$target\` : copié et poussé sur \`$main\`"
fi

# --- 5. suppression de la branche de release ---------------------------------
if g ls-remote --exit-code --heads "$remote" "refs/heads/$branch" >/dev/null 2>&1; then
  g push --quiet "$remote" --delete "refs/heads/$branch"
  say "branche \`$branch\` : supprimée"
else
  say "branche \`$branch\` : déjà absente"
fi

# --- 6. fast-forward de develop ----------------------------------------------
# `git push` sans --force refuse un non-fast-forward : c'est exactement la
# règle voulue. Le refus n'est pas une erreur du flux, c'est develop qui a
# avancé pendant la release — une PR main → develop, à la main.
g fetch --quiet "$remote" "$develop" "$main"
if [ "$(g rev-parse "$remote/$develop")" = "$(g rev-parse "$remote/$main")" ]; then
  say "\`$develop\` : déjà au niveau de \`$main\`"
elif g push --quiet "$remote" "$remote/$main:refs/heads/$develop" 2>/dev/null; then
  say "\`$develop\` : avancée en fast-forward sur \`$main\`"
else
  say "\`$develop\` : **fast-forward impossible** (develop a avancé pendant la release) — ouvrir une PR \`$main\` → \`$develop\` et la merger à la main"
fi
