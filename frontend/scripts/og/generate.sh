#!/usr/bin/env bash
#
# Génère la carte Open Graph (frontend/public/og.png) depuis template.html.
#
# Usage : npm run og:generate   (depuis frontend/), ou ./scripts/og/generate.sh
#
# Pourquoi Chrome système plutôt que Puppeteer/Playwright : la génération est
# ponctuelle (branding), pas une dépendance de build. Ajouter Playwright en
# devDependency ferait entrer ~300 Mo de navigateur dans `npm ci` — donc dans
# CHAQUE job CI et chaque install de dev — pour un PNG régénéré trois fois par
# an. Chrome est déjà présent sur les runners ubuntu-latest.
#
# Rendu en 2x puis réduction à 1200x630 : le suréchantillonnage lisse les
# dégradés et les `filter: blur()` du gabarit, et la taille finale correspond
# exactement aux balises og:image:width/height de frontend/index.html — une
# image de 2400x1260 déclarée 1200x630 est tolérée par les plateformes mais
# reste une métadonnée fausse.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
template="$script_dir/template.html"
output="$script_dir/../../public/og.png"

readonly WIDTH=1200
readonly HEIGHT=630

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
  echo "        en ${WIDTH}x${HEIGHT}. Sur Debian/Ubuntu : apt-get install imagemagick" >&2
  exit 1
fi

# --- Rendu -------------------------------------------------------------------
# --screenshot n'accepte pas d'écrire hors du répertoire courant de façon
# fiable selon les versions : on passe par un fichier temporaire dédié.
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT
tmp_png="$tmp_dir/og-2x.png"

echo "→ Rendu ${WIDTH}x${HEIGHT}@2x via $chrome"
"$chrome" \
  --headless \
  --disable-gpu \
  --hide-scrollbars \
  --no-sandbox \
  --force-device-scale-factor=2 \
  --window-size="${WIDTH},${HEIGHT}" \
  --screenshot="$tmp_png" \
  "file://$template" \
  >/dev/null 2>&1

if [[ ! -s "$tmp_png" ]]; then
  echo "ERREUR : Chrome n'a produit aucune capture." >&2
  exit 1
fi

echo "→ Réduction en ${WIDTH}x${HEIGHT} via $resize"
mkdir -p "$(dirname "$output")"
"$resize" "$tmp_png" -resize "${WIDTH}x${HEIGHT}" -strip "$output"

# --- Vérification ------------------------------------------------------------
# Un rendu qui part en vrille (police manquante, gabarit cassé) donne souvent
# une image quasi vide : on refuse de publier une carte visiblement dégénérée.
actual="$("$resize" identify -format '%wx%h' "$output" 2>/dev/null || echo '?')"
if [[ "$actual" != "${WIDTH}x${HEIGHT}" ]]; then
  echo "ERREUR : dimensions inattendues ($actual, attendu ${WIDTH}x${HEIGHT})." >&2
  exit 1
fi

bytes="$(wc -c <"$output")"
if (( bytes < 10000 )); then
  echo "ERREUR : og.png ne pèse que ${bytes} octets — rendu probablement vide." >&2
  exit 1
fi

echo "✓ $output — $actual, $(( bytes / 1024 )) Ko"
