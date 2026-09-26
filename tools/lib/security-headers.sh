#!/usr/bin/env bash
#
# security-headers.sh
# ---------------------------------------------------------------------------
# Bibliothèque sourçable, sans appel réseau : la liste des en-têtes de
# sécurité attendus et la fonction pure qui les vérifie sur un dump d'en-têtes
# HTTP déjà en main. Sourcée par :
#   - tools/audit-prod.sh (audit boîte noire de préprod/prod, sections 4) ;
#   - le futur contrôle de l'image nginx du frontend (tâche suivante du même
#     lot, qui réutilise cette fonction sans réseau) ;
#   - tools/tests/security-headers.test.sh (hors ligne, en-têtes construits à
#     la main avec `printf`).
#
# Contrat : l'appelant déclare et initialise la variable globale FAILURES
# (compteur d'échecs) avant d'appeler check_security_headers ; la fonction se
# contente de l'incrémenter, jamais de la déclarer ni de la remettre à zéro.
# Pas de `set -e` ici : une bibliothèque sourcée ne doit pas changer les
# options du shell qui la source.
# ---------------------------------------------------------------------------

# Liste unique, attendue sur CHACUNE des réponses vérifiées par l'appelant.
# content-security-policy est en mode enforce (plus Report-Only) sur les deux
# nginx : c'est donc un gate CI comme les autres.
SECURITY_HEADERS=(strict-transport-security x-content-type-options
                  referrer-policy permissions-policy x-frame-options
                  cross-origin-opener-policy content-security-policy)

# $1 = libellé affiché, $2 = sortie brute de fetch_headers.
check_security_headers() {
  local label="$1" raw headers code h
  raw="$(tr 'A-Z' 'a-z' <<< "$2")"
  # Les en-têtes de la réponse finale seulement : avec -L, `curl -D -` empile
  # aussi ceux des redirections traversées, et une 308 de l'ingress porte, elle,
  # ses propres en-têtes — de quoi valider une réponse qui, elle, n'en a aucun.
  # (Pas d'intervalle `{3}` dans le motif awk : mawk, l'awk par défaut du
  # runner CI, ne les a pas toujours supportés.)
  headers="$(awk '/^http\/[0-9.]+ [0-9][0-9][0-9]/ { buf = "" } { buf = buf $0 "\n" } END { printf "%s", buf }' <<< "$raw")"
  # Code de la réponse finale : la pseudo-ligne de fetch_headers si elle est
  # là, sinon la ligne de statut — la fonction reste utilisable sur un dump
  # d'en-têtes obtenu autrement (c'est le cas pour `/`, dont le dump sert aussi
  # à la section « Fuites d'information »).
  code="$(sed -n 's/^x-audit-http-code: //p' <<< "$raw" | tail -1)"
  [ -n "$code" ] || code="$(sed -n 's|^http/[0-9.]* \([0-9][0-9][0-9]\).*|\1|p' <<< "$headers" | tail -1)"

  printf '  \033[1m%s\033[0m  (HTTP %s)\n' "$label" "${code:-?}"
  for h in "${SECURITY_HEADERS[@]}"; do
    if grep -q "^${h}:" <<< "$headers"; then
      printf '    \033[32mOK\033[0m      %s\n' "$h"
    else
      printf '    \033[31mABSENT\033[0m  %s   <-- à corriger !\n' "$h"
      FAILURES=$((FAILURES + 1))
    fi
  done

  # Un en-tête Content-Security-Policy-Report-Only résiduel (sans le CSP
  # enforce) signalerait un retour en arrière non intentionnel.
  if grep -q '^content-security-policy-report-only:' <<< "$headers" \
     && ! grep -q '^content-security-policy:' <<< "$headers"; then
    printf '    \033[31mReport-Only SEUL\033[0m  content-security-policy   <-- enforce attendu !\n'
    FAILURES=$((FAILURES + 1))
  fi
}
