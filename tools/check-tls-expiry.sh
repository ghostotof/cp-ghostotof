#!/usr/bin/env bash
#
# check-tls-expiry.sh
# ---------------------------------------------------------------------------
# Contrôle des certificats TLS servis, lancé tous les jours par le workflow
# .github/workflows/tls-expiry.yml (issue #719). Le renouvellement Let's
# Encrypt est resté bloqué 17 jours sans signal (incident du 2026-10-26) :
# audit-prod.sh contrôlait bien le certificat, mais seulement au push d'une
# branche release/*, et seulement en préprod.
#
# Ne fait qu'une poignée de main TLS par hôte : aucune requête HTTP, donc
# aucune Basic Auth de préprod, aucun secret, aucun environnement GitHub.
#
# Usage :  tools/check-tls-expiry.sh [--min-days N] HÔTE...
#   HÔTE      nom vérifié dans le certificat, connexion sur HÔTE:443 ;
#             ou NOM@ADRESSE:PORT, pour vérifier NOM en se connectant
#             ailleurs (les tests hors ligne, un serveur local).
#   --min-days  jours de validité restante en deçà desquels l'hôte échoue
#             (défaut 25 : cert-manager renouvelle à 30 jours de
#             l'échéance, l'alerte tombe donc 5 jours après un premier
#             échec et laisse 25 jours pour réparer).
#
# Sortie : 0 si tous les hôtes passent, 1 si l'un échoue, 2 si les arguments
# sont invalides.
# ---------------------------------------------------------------------------
set -uo pipefail   # pas de -e : un hôte en échec n'empêche pas de contrôler les suivants

# shellcheck source=tools/lib/tls-certificate.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/tls-certificate.sh"

usage() {
  echo "Usage : $0 [--min-days N] HÔTE..." >&2
  exit 2
}

MIN_DAYS=25
if [ "${1:-}" = "--min-days" ]; then
  # Un entier strictement positif, sans zéro de tête ambigu : bash lirait
  # « 08 » en octal dans l'arithmétique.
  if [ "$#" -lt 2 ] || ! [[ "$2" =~ ^[1-9][0-9]*$ ]]; then
    echo "--min-days attend un entier strictement positif, reçu : « ${2:-} »" >&2
    exit 2
  fi
  MIN_DAYS="$2"
  shift 2
fi
[ "$#" -ge 1 ] || usage

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
  # SNI = le nom vérifié, quelle que soit l'adresse : c'est lui qui choisit le
  # certificat côté ingress. `timeout` borne un hôte qui accepte la connexion
  # sans jamais répondre ; `echo |` ferme stdin, s_client rend la main aussitôt.
  check_tls_certificate "$host" "$MIN_DAYS" \
    "$(echo | timeout 10 openssl s_client -connect "$connect" -servername "$host" 2> /dev/null)"
done

echo
if [ "$FAILURES" -gt 0 ]; then
  echo "Échecs : $FAILURES"
  exit 1
fi
echo "Tous les certificats passent (seuil $MIN_DAYS jours)."
exit 0
