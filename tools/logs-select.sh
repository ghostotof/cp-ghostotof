#!/usr/bin/env bash
#
# logs-select.sh
# ---------------------------------------------------------------------------
# Filtre les journaux du conteneur php-fpm (une ligne JSON Monolog par
# enregistrement) selon un prédicat jq, sans perdre un enregistrement coupé
# (issue #651). Seule copie de cette commande : monolog.yaml, les règles de
# `.claude/rules/` et k8s/README.md y renvoient, au lieu de la recopier.
#
# Usage :  kubectl -n <espace> logs deploy/backend -c php-fpm [...] \
#            | tools/logs-select.sh '<prédicat jq>'
#
# Exemples de prédicats :
#   '.channel == "security_audit"'                  événements d'audit (SecurityAuditLogger)
#   '.channel == "security"'                        canal `security` du framework Symfony
#   '.channel | IN("security", "security_audit")'   les deux à la fois (IN : jq 1.6+)
#   '.context.outcome == "protocol-error"'          issue d'un appel MCP (McpCallOutcome)
#
# Chaque ligne lue sur stdin (`jq -R`, lignes brutes) est traitée ainsi :
#   - elle se décode en objet JSON : rendue si le prédicat est vrai, en JSON
#     compact (une ligne) ;
#   - elle ne se décode pas, ou se décode en autre chose qu'un objet (`42`,
#     `"x"`, `null`, `[1]`) : rendue sous la forme {"unparsed":"<ligne>"}
#     SEULEMENT si elle commence par `{`, sinon écartée.
#
# Pourquoi cette asymétrie. Le descripteur 2 du conteneur ne porte pas que du
# Monolog : lignes d'accès du pool FPM (une par requête, sondes comprises),
# NOTICE et WARNING du maître, erreurs du moteur PHP. Toutes les afficher
# noierait la sortie, et aucune n'est un enregistrement. Mais une ligne plus
# longue que `log_limit` de php-fpm est coupée en morceaux : le premier, qui
# commence par `{`, est le début d'un enregistrement (d'audit, peut-être) que
# l'opérateur doit voir, alors qu'un `fromjson? // empty` le ferait disparaître
# sans bruit. Les morceaux suivants ne commencent pas par `{` et sont écartés
# avec le bruit : le début suffit à signaler la coupure.
#
# Le prédicat est un FILTRE jq, inséré tel quel dans le programme (pas un
# `--arg`, qui ne serait qu'une chaîne) : il s'exécute avec toute la puissance
# de jq. C'est un filtre de confiance, écrit par l'opérateur — jamais une
# donnée venue de l'extérieur (journal, ticket, saisie, variable d'un tiers).
# Un prédicat qui lève à l'exécution sur une ligne (indexer un champ absent
# d'un type inattendu) arrête jq : code 5, message de jq sur stderr.
#
# Sortie : 0 si tout s'est passé ; 2 si les arguments sont invalides (usage
# sur stderr) ; sinon le code de jq (3 : prédicat qui ne compile pas, 5 :
# erreur à l'exécution), après un message sur stderr.
# ---------------------------------------------------------------------------
set -euo pipefail

if [ "$#" -ne 1 ] || [ -z "$1" ]; then
  cat >&2 <<USAGE
Usage : kubectl logs ... | $0 '<prédicat jq>'
  ex. : $0 '.channel == "security_audit"'
        $0 '.channel | IN("security", "security_audit")'
        $0 '.context.outcome == "protocol-error"'
USAGE
  exit 2
fi

# Le prédicat est la seule partie variable du programme ; la ligne brute est
# gardée dans $__line pour que le prédicat de l'opérateur ne la masque pas.
predicate="$1"
program=". as \$__line
  | (try fromjson catch null) as \$__record
  | if (\$__record | type) == \"object\" then
      \$__record | select(${predicate})
    elif (\$__line | startswith(\"{\")) then
      {unparsed: \$__line}
    else
      empty
    end"

code=0
jq -cR "$program" || code=$?
if [ "$code" -ne 0 ]; then
  echo "logs-select.sh : jq a échoué (code $code) ; si le message ci-dessus parle de syntaxe, le prédicat n'est pas un filtre jq valide : $predicate" >&2
  exit "$code"
fi
