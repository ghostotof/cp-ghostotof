#!/usr/bin/env bash
#
# logs-select.sh
# ---------------------------------------------------------------------------
# Filtre les journaux du conteneur php-fpm (une ligne JSON Monolog par
# enregistrement) selon un prédicat jq, sans perdre une ligne qui n'est pas un
# enregistrement (issue #651). Seule copie de cette commande : monolog.yaml, les
# règles de `.claude/rules/` et k8s/README.md y renvoient, au lieu de la recopier.
#
# Usage (depuis la racine du dépôt) :
#   kubectl -n <espace> logs deploy/backend -c php-fpm [...] \
#     | tools/logs-select.sh '<prédicat jq>'
#
# Exemples de prédicats :
#   '.channel == "security_audit"'                  événements d'audit (SecurityAuditLogger)
#   '.channel == "security"'                        canal `security` du framework Symfony
#   '.channel | IN("security", "security_audit")'   les deux à la fois (IN : jq 1.6+)
#   '.channel == "mcp_usage" and .context.outcome == "protocol-error"'
#                                                   issue d'un appel MCP (McpCallOutcome) ;
#                                                   borner le canal : ai_usage a aussi un
#                                                   champ `outcome`
#
# Chaque ligne lue sur stdin (`jq -R`, lignes brutes) est traitée ainsi :
#   1. un préfixe de `kubectl logs` est retiré pour la classification : un
#      horodatage RFC 3339 (`--timestamps`) et/ou `[pod/<nom>/<conteneur>] `
#      (`--prefix`), dans un ordre ou l'autre (kubectl met le préfixe d'abord) ;
#   2. elle se décode en objet JSON : rendue si le prédicat est vrai, en JSON
#      compact (une ligne), sans le préfixe ;
#   3. c'est du bruit connu : écartée (voir plus bas) ;
#   4. TOUT LE RESTE est rendu sous la forme {"unparsed":"<ligne>"}, ligne
#      complète telle que lue, préfixe kubectl compris (le pod et l'heure y sont
#      une information, et rien n'est perdu).
#
# Pourquoi une liste de bruit et non une liste de lignes à garder. Le
# descripteur 2 du conteneur ne porte pas que du Monolog, et ce qu'il porte en
# plus peut être précisément ce qu'on cherche : la trace de dernier recours de
# FailSafeAudit (error_log() de PHP, `[date Zone] message`), une erreur fatale
# PHP, un WARNING du maître (« execution timed out »), les morceaux d'un
# enregistrement plus long que `log_limit` de php-fpm (coupé : le premier
# commence par `{`, les suivants par n'importe quoi, y compris `{`), du JSON
# qui n'est pas un objet. Une liste de lignes à garder les perdrait en silence ;
# on n'écarte donc que ce qui est connu et sans intérêt :
#   - la ligne d'accès du pool FPM, une par requête sondes comprises, au format
#     par défaut de php-fpm `%R - %u %t "%m %r" %s` :
#     `203.0.113.7 -  16/Nov/2026:10:00:05 +0000 "GET /index.php" 200` ;
#   - les NOTICE du maître, dont la date n'a pas de nom de zone :
#     `[16-Nov-2026 10:00:00] NOTICE: fpm is running, pid 1` ;
#   - la ligne vide (elle n'est pas un enregistrement et n'a rien à dire).
# Si le pool change son `access.format`, ses lignes d'accès sortent en
# `unparsed` : bruyant, jamais silencieux.
#
# Le prédicat est un FILTRE jq, inséré tel quel dans le programme (pas un
# `--arg`, qui ne serait qu'une chaîne) : il s'exécute avec toute la puissance
# de jq. C'est un filtre de confiance, écrit par l'opérateur — jamais une
# donnée venue de l'extérieur (journal, ticket, saisie, variable d'un tiers).
# Il est enveloppé dans `((\n<prédicat>\n))` : un `#` (commentaire jq) en fin
# de prédicat ne mange pas la parenthèse fermante. Il est évalué par
# `select(any((<prédicat>); .))` : un prédicat qui produit plusieurs valeurs
# (virgule de premier niveau, `a, b`) ne rend l'enregistrement qu'une fois.
#
# Un prédicat qui lève une erreur sur une ligne (indexer `.context.outcome` quand
# `context` est une liste `[]`, cas réel de Monolog) n'arrête pas le flux : la
# ligne est écartée, un message court (numéro de ligne d'entrée et message de
# jq, jamais le contenu de la ligne) va sur stderr, et le script se termine par
# le code 4. Garder `.context.outcome? == ...` ou borner le canal d'abord évite
# l'erreur. Les messages exigent jq 1.7 (sous 1.6 ils sont mis entre guillemets).
#
# Sortie : 0 si tout s'est passé ; 2 si les arguments sont invalides (usage
# sur stderr) ; 3 si le prédicat ne compile pas (message de jq) ; 4 si le
# prédicat a levé une erreur sur au moins une ligne (le reste du flux est rendu).
# ---------------------------------------------------------------------------
set -euo pipefail

if [ "$#" -ne 1 ] || [ -z "$1" ]; then
  cat >&2 <<USAGE
Usage : kubectl logs ... | $0 '<prédicat jq>'
  ex. : $0 '.channel == "security_audit"'
        $0 '.channel | IN("security", "security_audit")'
        $0 '.channel == "mcp_usage" and .context.outcome == "protocol-error"'
USAGE
  exit 2
fi

# Le prédicat est la seule partie variable du programme.
predicate="$1"
program='
def prefix: "^(\\[pod/[^\\]]*\\] |[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+(Z|[+-][0-9]{2}:[0-9]{2}) )+";
def access: "^\\S+ - \\S* [0-9]{2}/[A-Za-z]{3}/[0-9]{4}:[0-9]{2}:[0-9]{2}:[0-9]{2} [+-][0-9]{4} \"[A-Z]+ [^\"]*\" [0-9]{3}$";
def notice: "^\\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2}\\] NOTICE: ";

# Résultat pour une ligne : {o: [sorties], e: message d erreur ou null}.
def handle($n):
  . as $raw
  | sub(prefix; "") as $line
  | (try ($line | fromjson) catch null) as $record
  | if ($record | type) == "object" then
      try {o: [$record | select(any((
'"${predicate}"'
      ); .))], e: null}
      catch {o: [], e: ("ligne \($n) : prédicat en erreur : \(.)")}
    elif ($line == "") or ($line | test(access)) or ($line | test(notice)) then
      {o: [], e: null}
    else
      {o: [{unparsed: $raw}], e: null}
    end;

foreach (inputs, null) as $in ({n: 0, errors: 0, r: null};
    if $in == null then .r = null
    else .n += 1 | . as $s | .r = ($in | handle($s.n)) | .errors += (if .r.e == null then 0 else 1 end)
    end;
    if $in == null then
      (if .errors > 0 then "logs-select.sh : \(.errors) ligne(s) en erreur de prédicat (code 4)\n" | halt_error(4) else empty end)
    else
      .r.o[], (.r.e | select(. != null) | (. + "\n") | stderr | empty)
    end)'

code=0
jq -cnR "$program" || code=$?
if [ "$code" -eq 3 ]; then
  echo "logs-select.sh : le prédicat n'est pas un filtre jq valide : $predicate" >&2
fi
exit "$code"
