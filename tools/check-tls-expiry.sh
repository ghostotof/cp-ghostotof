#!/usr/bin/env bash
#
# check-tls-expiry.sh
# ---------------------------------------------------------------------------
# Contrôle des certificats TLS servis, lancé tous les jours par le workflow
# .github/workflows/tls-expiry.yml (issue #719). Échéance sous un seuil
# relatif à la durée de vie, nom couvert, chaîne de confiance : le jugement et
# ses raisons sont dans tools/lib/tls-certificate.sh.
#
# Ne fait qu'une poignée de main TLS par hôte : aucune requête HTTP, donc
# aucune Basic Auth de préprod, aucun secret, aucun environnement GitHub.
#
# Usage :  tools/check-tls-expiry.sh HÔTE...
#   HÔTE  nom vérifié dans le certificat, connexion sur HÔTE:443 ;
#         ou NOM@ADRESSE:PORT, pour vérifier NOM en se connectant ailleurs
#         (les tests hors ligne, un serveur local).
#
# Sortie : 0 si tous les hôtes passent, 1 si l'un échoue (une annotation
# `::error::` par hôte en échec, lisible dans le résumé du run), 2 si les
# arguments sont invalides ou la bibliothèque introuvable.
# ---------------------------------------------------------------------------
set -uo pipefail   # pas de -e : un hôte en échec n'empêche pas de contrôler les suivants

# Sans -e, un `source` en échec ne s'arrête pas : chaque appel finirait en
# « command not found », FAILURES resterait à 0 et le run serait vert sans
# avoir rien contrôlé.
# shellcheck source=tools/lib/tls-certificate.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/tls-certificate.sh" 2> /dev/null || {
  echo "bibliothèque introuvable : tools/lib/tls-certificate.sh" >&2
  exit 2
}

if [ "$#" -lt 1 ]; then
  echo "Usage : $0 HÔTE..." >&2
  exit 2
fi

FAILURES=0
for target in "$@"; do
  if [[ "$target" == *@* ]]; then
    host="${target%%@*}"
    connect="${target#*@}"
  else
    host="$target"
    connect="${target}:443"
  fi
  printf '\n\033[1;36m=== %s ===\033[0m\n' "$host"
  before="$FAILURES"
  check_tls_certificate "$host" auto "$(fetch_tls_certificate "$host" "$connect")"
  if [ "$FAILURES" -gt "$before" ]; then
    echo "::error title=Certificat TLS::$host en échec ($((FAILURES - before)) verdict(s), voir le log du run)"
  fi
done

echo
if [ "$FAILURES" -gt 0 ]; then
  echo "Échecs : $FAILURES"
  exit 1
fi
echo "Tous les certificats passent."
exit 0
