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
#   3. c'est du bruit connu : écartée (voir plus bas) ; une ligne qui commence par
#      `{` saute les étapes 1 et 3 (retrait du préfixe, bruit : aucun préfixe kubectl n'y est possible : il
#      commence par `[pod/` ou par un chiffre), ce qui garde le coût des lignes
#      JSON proche d'un simple `fromjson` ;
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
#   - la ligne d'accès du pool FPM EN 2xx OU 3xx, une par requête sondes comprises,
#     au format par défaut de php-fpm `%R - %u %t "%m %r" %s` (le statut est le
#     dernier champ) : `203.0.113.7 -  16/Nov/2026:10:00:05 +0000 "GET /index.php" 200`.
#     Une ligne d'accès en 4xx ou 5xx n'est PAS écartée, elle sort en `unparsed` :
#     un 401/403 (authentification, refus), un 404 ou un 5xx de PHP est un signal
#     qu'une sonde en 200 ne donne jamais, et un 4xx reste visible ;
#   - les NOTICE de routine du maître (date sans nom de zone) : `fpm is running, pid N`,
#     `ready to handle connections` et `[pool <nom>] child N started`. Tout autre
#     NOTICE sort en `unparsed` : `Terminating ...`, `Finishing ...`,
#     `exiting, bye-bye!`, `Reloading in progress ...` (arrêt ou rechargement du
#     maître) et `child N exited with code 0 ...` (un enfant qui sort, même
#     proprement, est un fait de cycle de vie et non de la routine de démarrage) ;
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
# ligne est écartée, une ligne FIXE va sur stderr, `logs-select.sh : ligne N : le
# prédicat a levé une erreur` (N : numéro de la ligne d'entrée, lignes de bruit
# comprises), et le script se termine par le code 4. Le texte d'erreur de jq n'est
# jamais repris : il cite des valeurs de l'enregistrement (`+ 1` sur une chaîne en
# donne le début, `tonumber` la chaîne entière), alors que stderr, lui, finit dans
# un terminal, une session d'assistant ou un journal de CI. Pour le détail, rejouer le
# prédicat sur la seule ligne fautive, après avoir gardé le flux dans un fichier :
#   kubectl ... logs ... > flux.log
#   sed -n 'Np' flux.log | jq '<prédicat>'      (N : le numéro de la ligne ; retirer
#                                                 d'abord un préfixe kubectl éventuel)
# Garder `.context.outcome? == ...` ou borner le canal d'abord évite l'erreur.
#
# Sortie :
#   0  tout s'est passé ;
#   2  arguments invalides (usage sur stderr) : ce code n'est plus jamais celui de jq ;
#   3  le prédicat ne compile pas (message de jq, qui ne cite pas de donnée) ;
#   4  le prédicat a levé une erreur sur au moins une ligne (le reste du flux est rendu) ;
#   5  toute autre sortie non nulle de jq : erreur système ou d'entrée-sortie (code 2
#      de jq), erreur non rattrapée (code 5), signal, `halt_error` du prédicat... Le
#      script le dit sur stderr (`jq a échoué (code N)`). Un prédicat qui appellerait
#      `halt_error(3)` ou `halt_error(4)` se confondrait avec les codes 3 et 4 : le
#      prédicat est de confiance, ce cas n'est pas défendu.
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
def access: "^\\S+ - \\S* [0-9]{2}/[A-Za-z]{3}/[0-9]{4}:[0-9]{2}:[0-9]{2}:[0-9]{2} [+-][0-9]{4} \"[A-Z]+ [^\"]*\" [23][0-9]{2}$";
def notice: "^\\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2}\\] NOTICE: (fpm is running, pid [0-9]+|ready to handle connections|(\\[pool [^\\]]*\\] )?child [0-9]+ started)$";

# Chemins de la ligne, du moins au plus coûteux (une recherche d expression régulière
# coûte plusieurs microsecondes, la compilation de la suivante comprise) :
#   1. elle commence par { : pas de préfixe kubectl possible (il commence par [pod/ ou
#      un chiffre), directement fromjson, ni sub ni test ;
#   2. ligne d accès sans préfixe (le plus fréquent après le JSON) : un seul test ;
#   3. le reste : retrait du préfixe, puis classification (bruit, objet, unparsed).
# fromjson et son exception rattrapée, coûteuse, ne servent que pour une ligne qui
# commence par { : seul un objet est un enregistrement, un JSON non objet sort en
# unparsed de toute façon.
# (Pas d apostrophe dans ces commentaires : le programme est entre apostrophes en bash.)
def noise: . == "" or test(access) or test(notice);

# Résultat pour une ligne : {o: [sorties], e: message d erreur ou null}.
def handle($n):
  . as $raw
  | startswith("{") as $brace
  | (if $brace then $raw elif test(access) then null else sub(prefix; "") end) as $line
  | (if $brace or $line == null then $line elif ($line | noise) then null else $line end) as $kept
  | if $kept == null then {o: [], e: null}
    else
      (if $kept | startswith("{") then try ($kept | fromjson) catch null else null end) as $record
      | if ($record | type) == "object" then
          try {o: [$record | select(any((
'"${predicate}"'
          ); .))], e: null}
          catch {o: [], e: "logs-select.sh : ligne \($n) : le prédicat a levé une erreur"}
        else
          {o: [{unparsed: $raw}], e: null}
        end
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
case "$code" in
  0) ;;
  # 3 : jq n'a pas compilé le programme ; 4 : halt_error(4) du programme, fin de flux
  # après au moins une erreur de prédicat. Les deux gardent leur sens.
  3) echo "logs-select.sh : le prédicat n'est pas un filtre jq valide : $predicate" >&2 ;;
  4) ;;
  # Toute autre sortie non nulle de jq (2 : erreur système ou d'entrée-sortie, 5 :
  # erreur non rattrapée, signal...) devient 5 : le 2 du script veut dire « usage
  # invalide » et ne doit pas se confondre avec lui.
  *) echo "logs-select.sh : jq a échoué (code $code)" >&2; code=5 ;;
esac
exit "$code"
