#!/usr/bin/env bash
#
# postgres-image-pins.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne (issue #611) : l'image PostgreSQL est la même partout où le
# dépôt l'épingle — `POSTGRES_TAG` de `.env` (dev), `versions.lock`, le
# serveur de préprod et de prod (`k8s/base/postgres.yaml`) et le service du
# job `test-backend` de la CI (`.github/workflows/pipeline.yml`).
#
# Depuis #611, verify.sql compare l'empreinte du schéma — le texte que le
# serveur imprime pour chaque définition (`pg_get_*def`) — à celle que
# DatabaseOwnerSettingsVerificationTest calcule sur la base de test : un
# contrat exact, qui ne vaut que si la CI tourne sur le serveur de la prod.
# La CI était restée en 18.4 quand le reste passait en 18.6, sans que rien
# ne le voie.
#
# Usage :  tools/tests/postgres-image-pins.test.sh [racine]
#          (racine : le dépôt par défaut ; un autre dossier pour éprouver
#          le refus, cf. les cas en fin de fichier)
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Les quatre étiquettes d'une racine, une par ligne, « source=étiquette ».
# Une source introuvable ou ambiguë donne une étiquette vide, donc un écart.
tags_of() {
  local root="$1"
  printf 'env=%s\n' "$(sed -n 's/^POSTGRES_TAG=\([^[:space:]#]*\).*$/\1/p' "$root/.env")"
  printf 'versions.lock=%s\n' "$(sed -n 's/^postgres=\([^[:space:]#]*\).*$/\1/p' "$root/versions.lock")"
  printf 'postgres.yaml=%s\n' "$(sed -n 's/^[[:space:]]*image:[[:space:]]*postgres:\([^[:space:]#]*\).*$/\1/p' "$root/k8s/base/postgres.yaml")"
  # Le seul service PostgreSQL de la pipeline : celui de test-backend.
  printf 'pipeline.yml=%s\n' "$(sed -n 's/^[[:space:]]*image:[[:space:]]*postgres:\([^[:space:]#]*\).*$/\1/p' "$root/.github/workflows/pipeline.yml")"
}

# 0 si les quatre étiquettes existent, sont uniques et égales ; 1 sinon,
# en nommant chaque source. Une épingle en double dans un fichier ajoute une
# ligne sans « source= » : le compte de lignes la refuse.
check() {
  local root="$1" tags
  tags="$(tags_of "$root")"
  if [ "$(printf '%s\n' "$tags" | wc -l)" -ne 4 ] \
    || [ "$(printf '%s\n' "$tags" | grep -c '=$' || true)" -ne 0 ] \
    || [ "$(printf '%s\n' "$tags" | cut -d= -f2- | sort -u | wc -l)" -ne 1 ]; then
    printf '%s\n' "$tags" | sed 's/^/       /'
    return 1
  fi
}

failures=0
pass() { printf '  ok   %s\n' "$1"; }
fail() { printf '  FAIL %s\n' "$1"; failures=$((failures + 1)); }

if [ "$#" -eq 1 ]; then
  check "$1"
  exit $?
fi

if check "$ROOT"; then pass 'le dépôt épingle la même image PostgreSQL partout'; else fail 'le dépôt épingle des images PostgreSQL différentes (ci-dessus)'; fi

# Le refus, éprouvé sur des copies : chaque source désaccordée tour à tour.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
for source in .env versions.lock k8s/base/postgres.yaml .github/workflows/pipeline.yml; do
  copy="$TMP/$(printf '%s' "$source" | tr '/.' '__')"
  mkdir -p "$copy/k8s/base" "$copy/.github/workflows"
  for file in .env versions.lock k8s/base/postgres.yaml .github/workflows/pipeline.yml; do
    cp "$ROOT/$file" "$copy/$file"
  done
  sed -i 's/\(POSTGRES_TAG=\|^postgres=\|image:[[:space:]]*postgres:\)[0-9][0-9.]*/\199.9/' "$copy/$source"
  if check "$copy" > /dev/null; then fail "une étiquette désaccordée dans $source passe"; else pass "une étiquette désaccordée dans $source est refusée"; fi
done

[ "$failures" -eq 0 ] || { printf '%d échec(s)\n' "$failures"; exit 1; }
