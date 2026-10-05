#!/usr/bin/env bash
#
# Résumé du job lsp-check-backend quand l'installation des Symfony Language
# Tools a échoué (issue #343) : `lsp:check` n'a pas tourné, et la carte doit le
# dire plutôt que de laisser croire à un défaut du code.
#
# Usage : summary-language-tools.sh <code de sortie de install-symfony-language-tools.sh>
#   Écrit l'annotation `::error` sur la sortie standard (GitHub l'y lit) et la
#   carte de synthèse dans "$GITHUB_STEP_SUMMARY". Sort 0 : le statut du job
#   reste décidé par l'étape d'installation.
#
# Le code décide du conseil donné, d'après docker/php/install-symfony-language-tools.sh :
#   2  téléchargement impossible → incident réseau, relancer ;
#   3  intégrité refusée → ne PAS relancer à l'aveugle : la release amont ne
#      correspond pas à ce qu'elle annonce, c'est à examiner ;
#   autre  configuration (version, plateforme, CLI, verrou) → à corriger.

set -u

code="${1:-}"
if [ -z "$code" ] || [ "$code" = 0 ]; then
  echo "Usage : summary-language-tools.sh <code de sortie non nul de l'installation>" >&2
  exit 64
fi

case "$code" in
  2)
    title='Symfony Language Tools indisponibles'
    message="Téléchargement impossible : aucun diagnostic n'a tourné, le code n'est pas en cause. Relancer le job."
    advice="Incident réseau du côté de GitHub : le code n'est pas en cause. **Relancer le job.**"
    ;;
  3)
    title='Symfony Language Tools refusés (intégrité)'
    message="Intégrité refusée : l'archive téléchargée ne correspond pas à sa somme ou à sa version. Ne pas relancer à l'aveugle."
    advice="L'archive téléchargée ne correspond pas à sa somme SHA-256 ou n'annonce pas la version épinglée. **Ne pas relancer à l'aveugle** : examiner la release amont avant tout."
    ;;
  *)
    title='Symfony Language Tools non installés (configuration)'
    message="Configuration : installation refusée (code $code), voir l'étape d'installation."
    advice="Erreur de configuration (code $code) : version déclarée, plateforme, CLI Symfony ou verrou du cache. À corriger dans le dépôt ou le workflow, une relance n'y changera rien."
    ;;
esac

echo "::error title=${title}::${message}"
{
  printf '## ⚠️ Symfony Language Tools (lsp:check)\n\n'
  printf "Installation de Language Tools en échec (code %s) : **aucun diagnostic n'a tourné**.\n\n" "$code"
  printf '%s\n\n' "$advice"
  printf "Détail : étape « Installe les Symfony Language Tools ».\n\n"
} >> "${GITHUB_STEP_SUMMARY:?GITHUB_STEP_SUMMARY requis}"
