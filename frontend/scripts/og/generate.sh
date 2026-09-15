#!/usr/bin/env bash
#
# Génère les cartes de partage depuis template.html — deux variantes du même
# gabarit, qui ne diffèrent que par le titre et les dimensions :
#
#   site    → frontend/public/og.png          1200x630   carte Open Graph du
#             site (og:image de frontend/index.html), titre = identité
#   github  → .github/social-preview.png      1280x640   prévisualisation
#             sociale du dépôt GitHub, titre = pseudonyme (le dépôt est sous
#             pseudonyme : licence, README, URL — la carte suit)
#
# Usage : npm run og:generate [-- site|github]   (depuis frontend/),
#         ou ./scripts/og/generate.sh [site|github] — sans argument : les deux.
#
# La variante GitHub n'est PAS servie par le site et GitHub ne la lit pas tout
# seul : il n'existe pas d'API pour la prévisualisation sociale, elle se
# téléverse à la main (Settings → General → Social preview). Elle est commitée
# pour être à portée de clic, et régénérée par le pipeline à chaque release
# (artefact `social-preview` du job build-images) pour rester alignée sur le
# gabarit.
#
# Pourquoi Chrome système plutôt que Puppeteer/Playwright : la génération est
# ponctuelle (branding), pas une dépendance de build. Ajouter Playwright en
# devDependency ferait entrer ~300 Mo de navigateur dans `npm ci` — donc dans
# CHAQUE job CI et chaque install de dev — pour un PNG régénéré trois fois par
# an. Chrome est déjà présent sur les runners ubuntu-latest.
#
# Rendu en 2x puis réduction à la taille cible : le suréchantillonnage lisse
# les dégradés et les `filter: blur()` du gabarit, et la taille finale
# correspond exactement aux balises og:image:width/height de
# frontend/index.html — une image de 2400x1260 déclarée 1200x630 est tolérée
# par les plateformes mais reste une métadonnée fausse.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
template="$script_dir/template.html"
repo_root="$(cd "$script_dir/../../.." && pwd)"

# --- Variantes ---------------------------------------------------------------
# Le gabarit porte trois espaces réservés : __WIDTH__, __HEIGHT__ (taille du
# canevas) et __HEADLINE__ (le <h1>, HTML autorisé pour l'accent de couleur).
variant_headline() {
  case "$1" in
    site)   printf '%s' 'Christophe <span class="acc">Piton</span>' ;;
    github) printf '%s' 'ghost<span class="acc">otof</span>' ;;
  esac
}
variant_size() {
  case "$1" in
    site)   printf '%s' '1200x630' ;;
    github) printf '%s' '1280x640' ;;
  esac
}
variant_output() {
  case "$1" in
    site)   printf '%s' "$script_dir/../../public/og.png" ;;
    github) printf '%s' "$repo_root/.github/social-preview.png" ;;
  esac
}

if (( $# == 0 )); then
  variants=(site github)
else
  variants=("$@")
  for v in "${variants[@]}"; do
    if [[ -z "$(variant_size "$v")" ]]; then
      echo "ERREUR : variante inconnue « $v » (attendu : site, github)." >&2
      exit 2
    fi
  done
fi

# --- Chrome ------------------------------------------------------------------
chrome="${CHROME_BIN:-}"
if [[ -z "$chrome" ]]; then
  for candidate in chromium chromium-browser google-chrome google-chrome-stable; do
    if command -v "$candidate" >/dev/null 2>&1; then
      chrome="$candidate"
      break
    fi
  done
fi

if [[ -z "$chrome" ]]; then
  echo "ERREUR : aucun binaire Chrome/Chromium trouvé." >&2
  echo "        Installez-en un, ou pointez CHROME_BIN sur l'exécutable." >&2
  exit 1
fi

# --- Redimensionnement -------------------------------------------------------
resize=""
for candidate in magick convert; do
  if command -v "$candidate" >/dev/null 2>&1; then
    resize="$candidate"
    break
  fi
done

if [[ -z "$resize" ]]; then
  echo "ERREUR : ImageMagick (magick/convert) est requis pour ramener le rendu 2x" >&2
  echo "        à la taille cible. Sur Debian/Ubuntu : apt-get install imagemagick" >&2
  exit 1
fi

# ImageMagick 7 regroupe tout sous `magick` (`magick identify ...`) ; la 6, encore
# livrée par Ubuntu, expose `convert` et `identify` comme deux binaires distincts
# et ne comprend pas `convert identify`. Choisir la forme d'après le binaire
# trouvé, plutôt que de supposer la 7 : c'est la 6 qui tourne sur les runners.
if [[ "$resize" == 'magick' ]]; then
  identify=(magick identify)
else
  identify=(identify)
fi

if ! command -v "${identify[0]}" >/dev/null 2>&1; then
  echo "ERREUR : la commande '${identify[0]}' est introuvable, impossible de vérifier le rendu." >&2
  exit 1
fi

# --- Rendu -------------------------------------------------------------------
# --screenshot n'accepte pas d'écrire hors du répertoire courant de façon
# fiable selon les versions : on passe par un fichier temporaire dédié.
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

render() {
  local variant="$1"
  local size width height headline output tmp_html tmp_png
  size="$(variant_size "$variant")"
  width="${size%x*}"
  height="${size#*x}"
  headline="$(variant_headline "$variant")"
  output="$(variant_output "$variant")"
  tmp_html="$tmp_dir/$variant.html"
  tmp_png="$tmp_dir/$variant-2x.png"

  # Substitution des espaces réservés dans une copie : le gabarit versionné
  # reste neutre, et Chrome lit une URL file:// ordinaire. `|` comme séparateur
  # sed, le titre contient des `/` (balises).
  sed -e "s|__WIDTH__|${width}|g" \
      -e "s|__HEIGHT__|${height}|g" \
      -e "s|__HEADLINE__|${headline}|g" \
      "$template" > "$tmp_html"

  echo "→ [$variant] rendu ${width}x${height}@2x via $chrome"
  "$chrome" \
    --headless \
    --disable-gpu \
    --hide-scrollbars \
    --no-sandbox \
    --force-device-scale-factor=2 \
    --window-size="${width},${height}" \
    --screenshot="$tmp_png" \
    "file://$tmp_html" \
    >/dev/null 2>&1

  if [[ ! -s "$tmp_png" ]]; then
    echo "ERREUR : [$variant] Chrome n'a produit aucune capture." >&2
    exit 1
  fi

  echo "→ [$variant] réduction en ${width}x${height} via $resize"
  mkdir -p "$(dirname "$output")"
  "$resize" "$tmp_png" -resize "${width}x${height}" -strip "$output"

  # --- Vérification ----------------------------------------------------------
  # Un rendu qui part en vrille (police manquante, gabarit cassé) donne souvent
  # une image quasi vide : on refuse de publier une carte visiblement dégénérée.
  local actual bytes
  actual="$("${identify[@]}" -format '%wx%h' "$output" 2>/dev/null || echo '?')"
  if [[ "$actual" != "${width}x${height}" ]]; then
    echo "ERREUR : [$variant] dimensions inattendues ($actual, attendu ${width}x${height})." >&2
    exit 1
  fi

  bytes="$(wc -c <"$output")"
  if (( bytes < 10000 )); then
    echo "ERREUR : [$variant] $(basename "$output") ne pèse que ${bytes} octets — rendu probablement vide." >&2
    exit 1
  fi

  echo "✓ [$variant] $output — $actual, $(( bytes / 1024 )) Ko"
}

for v in "${variants[@]}"; do
  render "$v"
done
