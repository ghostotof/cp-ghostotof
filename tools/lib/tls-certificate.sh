#!/usr/bin/env bash
#
# tls-certificate.sh
# ---------------------------------------------------------------------------
# Bibliothèque sourçable, sans appel réseau : la fonction pure qui juge un
# certificat TLS déjà en main (issue #719). Sourcée par :
#   - tools/audit-prod.sh (section « Certificat TLS », seuil 14 jours, au
#     moment d'une release) ;
#   - tools/check-tls-expiry.sh (job planifié tls-expiry.yml, seuil 25 jours,
#     tous les jours) ;
#   - tools/tests/tls-certificate.test.sh (hors ligne, certificats autosignés).
#
# Contrat : l'appelant déclare et initialise la variable globale FAILURES
# (compteur d'échecs) avant d'appeler check_tls_certificate ; la fonction se
# contente de l'incrémenter, jamais de la déclarer ni de la remettre à zéro.
# Pas de `set -e` ici : une bibliothèque sourcée ne doit pas changer les
# options du shell qui la source.
#
# `date -d` est GNU : comme audit-prod.sh, le script tourne en CI
# (ubuntu-latest) et sur Linux. Hors périmètre : macOS (`gdate`).
# ---------------------------------------------------------------------------

# check_tls_certificate <hôte> <seuil en jours> <certificat PEM>
#
# Le PEM peut être la sortie brute de `openssl s_client` : `openssl x509` y
# lit le premier certificat, celui du serveur. Deux jugements, chacun compté :
#   - l'échéance : moins de <seuil> jours de validité restante, ou déjà
#     expiré, est un échec ;
#   - le nom : un certificat qui ne couvre pas <hôte> est un échec. C'est ce
#     que sert ingress-nginx quand le Secret TLS manque (son « Fake
#     Certificate », valable un an) : un contrôle d'échéance seul le dirait sain.
# Un certificat illisible (poignée de main échouée, port fermé) compte pour un
# seul échec, jamais pour un saut silencieux.
check_tls_certificate() {
  local host="$1" min_days="$2" pem="$3" not_after not_after_ts now days

  if ! not_after="$(openssl x509 -noout -enddate <<< "$pem" 2> /dev/null)"; then
    printf '  \033[31mALERTE\033[0m  %s : aucun certificat lisible (poignée de main TLS échouée ?)\n' "$host"
    FAILURES=$((FAILURES + 1))
    return
  fi
  not_after="${not_after#notAfter=}"
  not_after_ts="$(date -u -d "$not_after" +%s)"
  now="$(date -u +%s)"
  # Jours entiers restants, arrondis vers le bas : « 19 » veut dire que le
  # vingtième jour ne sera pas entier.
  days=$(( (not_after_ts - now) / 86400 ))

  if [ "$not_after_ts" -le "$now" ]; then
    printf '  \033[31mALERTE\033[0m  %s : expiré depuis le %s   <-- renouvellement en échec !\n' "$host" "$not_after"
    FAILURES=$((FAILURES + 1))
  elif [ "$days" -lt "$min_days" ]; then
    # cert-manager renouvelle à 30 jours de l'échéance : sous le seuil, le
    # renouvellement a échoué au moins une fois (incident du 2026-10-26).
    printf '  \033[31mALERTE\033[0m  %s : expire dans %s jours, sous le seuil de %s (%s)   <-- renouvellement à vérifier !\n' \
      "$host" "$days" "$min_days" "$not_after"
    FAILURES=$((FAILURES + 1))
  else
    printf '  \033[32mOK\033[0m      %s : valide encore %s jours (seuil %s, %s)\n' "$host" "$days" "$min_days" "$not_after"
  fi

  # -checkhost lit le subjectAltName (et le CN à défaut) ; sa sortie dit
  # « does match » ou « does NOT match », son code de sortie reste 0 dans les
  # deux cas : on juge donc la sortie.
  if openssl x509 -noout -checkhost "$host" <<< "$pem" 2> /dev/null | grep -q ' does match certificate$'; then
    printf '  \033[32mOK\033[0m      %s : nom couvert par le certificat\n' "$host"
  else
    printf '  \033[31mALERTE\033[0m  %s : nom absent du certificat servi (%s)   <-- Secret TLS manquant ?\n' \
      "$host" "$(openssl x509 -noout -subject <<< "$pem" 2> /dev/null)"
    FAILURES=$((FAILURES + 1))
  fi
}
