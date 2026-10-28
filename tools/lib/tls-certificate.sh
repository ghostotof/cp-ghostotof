#!/usr/bin/env bash
#
# tls-certificate.sh
# ---------------------------------------------------------------------------
# Bibliothèque sourçable : la récupération et le jugement d'un certificat TLS
# servi (issue #719). Sourcée par :
#   - tools/check-tls-expiry.sh (workflow tls-expiry.yml, tous les jours,
#     seuil relatif à la durée de vie) ;
#   - tools/audit-prod.sh (section « Certificat TLS », gate d'audit-preprod,
#     seuil fixe de 14 jours) ;
#   - tools/tests/tls-certificate.test.sh (hors ligne, autorité de test).
# Pourquoi deux seuils, et l'incident qui les a fait naître :
# .claude/rules/deploiement.md, puce « The served certificates are checked
# every day ».
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

# Jours de répit après le point de renouvellement, en seuil relatif : passé ce
# délai, le renouvellement a échoué plusieurs fois (cert-manager réessaie
# avec un backoff d'une heure au plus) et il faut regarder.
TLS_RENEWAL_GRACE_DAYS=5

# fetch_tls_certificate <nom> <adresse:port>
#
# La sortie brute d'`openssl s_client` : la chaîne servie, puis la ligne
# « Verify return code: N (…) », le jugement de la chaîne contre le magasin de
# confiance par défaut (SSL_CERT_FILE le remplace). Le SNI est <nom>, quelle
# que soit l'adresse : c'est lui qui choisit le certificat côté ingress.
# `timeout` borne un hôte qui accepte la connexion sans jamais répondre ;
# `echo |` ferme stdin, s_client rend la main aussitôt. Rien en cas d'échec :
# check_tls_certificate le compte.
fetch_tls_certificate() {
  echo | timeout 10 openssl s_client -connect "$2" -servername "$1" 2> /dev/null
}

# check_tls_certificate <hôte> <seuil> <sortie de fetch_tls_certificate>
#
# <seuil> : un nombre de jours (fixe, audit-prod.sh), ou `auto` : relatif à
# la durée de vie du certificat. cert-manager renouvelle au tiers de la durée
# de vie restante, faute de `renewBefore` (aucun manifeste n'en pose) : à 30
# jours pour 90, 21 pour 64 (Let's Encrypt à partir du 2027-02-10), 15 pour
# 45 (2028). Le seuil est ce point moins TLS_RENEWAL_GRACE_DAYS : 25, 16, 10.
# Un seuil fixe donnerait de fausses alertes à chaque cycle dès que la durée
# de vie raccourcit.
#
# Trois jugements, chacun compté :
#   - l'échéance, sous le seuil ou dépassée ;
#   - le nom : un certificat qui ne couvre pas <hôte> est un échec. C'est ce
#     que sert ingress-nginx quand le Secret TLS manque (son « Fake
#     Certificate », valable un an) ;
#   - la chaîne, d'après la ligne « Verify return code » de s_client : un
#     certificat de l'AC de staging de Let's Encrypt, ou un intermédiaire
#     manquant, a le bon nom et la bonne échéance.
# Un certificat illisible (poignée de main échouée, port fermé), ou un seuil
# invalide, compte pour un seul échec, jamais pour un saut silencieux.
check_tls_certificate() {
  local host="$1" threshold="$2" served="$3" dates not_before not_after
  local start end now days lifetime_days renewal_days min_days context ok_detail verify code reason

  if ! dates="$(openssl x509 -noout -startdate -enddate <<< "$served" 2> /dev/null)"; then
    printf '  \033[31mALERTE\033[0m  %s : aucun certificat lisible (poignée de main TLS échouée ?)\n' "$host"
    FAILURES=$((FAILURES + 1))
    return
  fi
  not_before="$(sed -n 's/^notBefore=//p' <<< "$dates")"
  not_after="$(sed -n 's/^notAfter=//p' <<< "$dates")"
  start="$(date -u -d "$not_before" +%s)"
  end="$(date -u -d "$not_after" +%s)"
  now="$(date -u +%s)"
  # Jours entiers, arrondis vers le bas : « 19 » veut dire que le vingtième
  # jour ne sera pas entier.
  days=$(( (end - now) / 86400 ))

  # Un entier strictement positif, sans zéro de tête : bash lirait « 08 » en
  # octal dans l'arithmétique.
  if [ "$threshold" = "auto" ]; then
    # Arrondis au jour le plus proche, pas vers le bas : Let's Encrypt fixe
    # notAfter à notBefore + 90 jours − 1 seconde, qui donnerait 89 et 29.
    lifetime_days=$(( (end - start + 43200) / 86400 ))
    renewal_days=$(( ((end - start) / 3 + 43200) / 86400 ))
    min_days=$(( renewal_days - TLS_RENEWAL_GRACE_DAYS ))
    context="renouvellement attendu à $renewal_days jours, durée de vie $lifetime_days jours, $not_after"
    ok_detail="seuil $min_days : $context"
  elif [[ "$threshold" =~ ^[1-9][0-9]*$ ]]; then
    min_days="$threshold"
    context="$not_after"
    ok_detail="seuil $min_days, $context"
  else
    printf '  \033[31mALERTE\033[0m  %s : seuil invalide « %s » (un nombre de jours ou auto)\n' "$host" "$threshold"
    FAILURES=$((FAILURES + 1))
    return
  fi

  if [ "$end" -le "$now" ]; then
    printf '  \033[31mALERTE\033[0m  %s : expiré depuis le %s   <-- renouvellement en échec !\n' "$host" "$not_after"
    FAILURES=$((FAILURES + 1))
  elif [ "$days" -lt "$min_days" ]; then
    printf '  \033[31mALERTE\033[0m  %s : expire dans %s jours, sous le seuil de %s (%s)   <-- renouvellement à vérifier !\n' \
      "$host" "$days" "$min_days" "$context"
    FAILURES=$((FAILURES + 1))
  else
    printf '  \033[32mOK\033[0m      %s : valide encore %s jours (%s)\n' "$host" "$days" "$ok_detail"
  fi

  # -checkhost lit le subjectAltName (et le CN à défaut) ; sa sortie dit
  # « does match » ou « does NOT match », son code de sortie reste 0 dans les
  # deux cas : on juge donc la sortie.
  if openssl x509 -noout -checkhost "$host" <<< "$served" 2> /dev/null | grep -q ' does match certificate$'; then
    printf '  \033[32mOK\033[0m      %s : nom couvert par le certificat\n' "$host"
  else
    printf '  \033[31mALERTE\033[0m  %s : nom absent du certificat servi (%s)   <-- Secret TLS manquant ?\n' \
      "$host" "$(openssl x509 -noout -subject <<< "$served" 2> /dev/null)"
    FAILURES=$((FAILURES + 1))
  fi

  # La dernière ligne de vérification : s_client en écrit une par poignée de
  # main, la dernière juge la session conclue.
  verify="$(sed -n 's/^[[:space:]]*Verify return code: //p' <<< "$served" | tail -1)"
  if [ "$verify" = "0 (ok)" ]; then
    printf '  \033[32mOK\033[0m      %s : chaîne de confiance vérifiée\n' "$host"
  elif [ -z "$verify" ]; then
    printf '  \033[31mALERTE\033[0m  %s : chaîne de confiance non vérifiée (aucun résultat de s_client)\n' "$host"
    FAILURES=$((FAILURES + 1))
  else
    # « 18 (self-signed certificate) » → code 18, raison « self-signed certificate ».
    code="${verify%% *}"
    reason="${verify#* (}"
    printf '  \033[31mALERTE\033[0m  %s : chaîne de confiance refusée (%s : %s)   <-- émetteur à vérifier !\n' \
      "$host" "$code" "${reason%)}"
    FAILURES=$((FAILURES + 1))
  fi
}
