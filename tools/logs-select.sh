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
#      (`--prefix`), dans un ordre ou l'autre (kubectl met le préfixe d'abord),
#      puis les blancs de tête (espaces, tabulations) : ` {"channel":…}` se décode
#      comme `{"channel":…}` ;
#   2. elle se décode en objet JSON : rendue si le prédicat est vrai, en JSON
#      compact (une ligne), sans le préfixe ni le blanc ;
#   3. c'est du bruit connu : écartée (voir plus bas) ; une ligne qui commence par
#      `{` (après ses blancs de tête) saute les étapes 1 et 3, car aucun préfixe
#      kubectl ne peut la précéder (il commence par `[pod/` ou par un chiffre) et
#      aucun bruit ne commence par `{` : cela garde le coût des lignes JSON proche
#      d'un simple `fromjson` ;
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
#   - la ligne d'accès du pool FPM EN 2xx, 3xx OU 4xx, une par requête sondes
#     comprises, au format par défaut de php-fpm `%R - %u %t "%m %r" %s` (le statut
#     est le dernier champ) : `203.0.113.7 -  16/Nov/2026:10:00:05 +0000 "GET /index.php" 200`.
#     Un refus 401/403 a son propre enregistrement d'audit JSON, et chaque 401/404
#     de production (`/api/me` anonyme, scanners, client MCP sans jeton) noierait
#     les événements cherchés. Une ligne d'accès en 5xx n'est PAS écartée, elle
#     sort en `unparsed` : un 502, un 504 ou un worker tué n'a pas d'autre trace ;
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
# comprises), et le script se termine par le code 4. Ces lignes fixes sont
# affichées À LA FIN du flux (après la dernière ligne lue, juste avant la sortie),
# non au fil de l'eau : le script capture le stderr de jq dans un fichier temporaire
# (`mktemp` sous `umask 077`, supprimé à la sortie, jamais dans le dépôt).
# Le texte d'erreur de jq n'est jamais repris : il cite des valeurs de
# l'enregistrement (`+ 1` sur une chaîne en donne le début, `tonumber` la chaîne
# entière, une erreur non rattrapée `jq: error (at <stdin>:N) : …` la valeur), alors
# que stderr, lui, finit dans un terminal, une session d'assistant ou un journal de
# CI. Seuls sont relayés, filtrés sur leur forme exacte, les libellés fixes
# ci-dessus ; ce que le prédicat écrit lui-même sur le stderr de jq (`stderr`,
# `debug`) ne l'est jamais. Pour le détail, rejouer le
# prédicat sur la seule ligne fautive, après avoir gardé le flux dans un fichier :
#   kubectl ... logs ... > flux.log
#   sed -n 'Np' flux.log | jq '<prédicat>'      (N : le numéro de la ligne ; retirer
#                                                 d'abord un préfixe kubectl éventuel)
# Garder `.context.outcome? == ...` ou borner le canal d'abord évite l'erreur.
#
# Sortie :
#   0  tout s'est passé ;
#   2  arguments invalides (usage sur stderr) : ce code n'est plus jamais celui de jq ;
#   3  le prédicat ne compile pas : l'erreur de compilation de jq est relayée telle
#      quelle, car jq compile avant de lire la moindre ligne et ne cite que le
#      programme, jamais une donnée (reconnue à son dernier mot, `jq: N compile
#      error`) ;
#   4  le prédicat a levé une erreur sur au moins une ligne (le reste du flux est rendu) ;
#   5  toute autre sortie non nulle de jq (hors signal) : erreur système ou
#      d'entrée-sortie (code 2 de jq), erreur non rattrapée (code 5), `halt_error`
#      du prédicat... Le script dit seulement `jq a échoué (code N)` sur stderr, sans
#      le texte de jq. Un prédicat qui appellerait `halt_error(3)` ou `halt_error(4)`
#      se confondrait avec les codes 3 et 4 : le prédicat est de confiance, ce cas
#      n'est pas défendu, mais son texte n'est pas relayé non plus ;
#   128 et plus  un signal tué jq, code rendu tel quel et sans message : 141 (SIGPIPE,
#      `| head -n 20` a fermé le tube), 130 (Ctrl-C), 143 (SIGTERM)...
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
def access: "^\\S+ - \\S* [0-9]{2}/[A-Za-z]{3}/[0-9]{4}:[0-9]{2}:[0-9]{2}:[0-9]{2} [+-][0-9]{4} \"[A-Z]+ [^\"]*\" [234][0-9]{2}$";
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

# Retire les blancs de tête (espace, tabulation) sans regex pour la ligne qui n en a pas.
def ltrim: if startswith(" ") or startswith("\t") then sub("^[ \\t]+"; "") else . end;

# Résultat pour une ligne : {o: [sorties], e: message d erreur ou null}.
def handle($n):
  . as $raw
  | ltrim as $t
  | ($t | startswith("{")) as $brace
  | (if $brace then $t elif ($raw | test(access)) then null else ($raw | sub(prefix; "") | ltrim) end) as $line
  | (if $brace or $line == null then $line elif ($line | noise) then null else $line end) as $kept
  | if $kept == null then {o: [], e: null}
    else
      (if $kept | startswith("{") then try ($kept | fromjson) catch null else null end) as $record
      | if ($record | type) == "object" then
          try {o: [$record | select(any((
'"${predicate}"'
          ); .))], e: null}
          catch {o: [], e: "\nlogs-select.sh : ligne \($n) : le prédicat a levé une erreur"}
        else
          {o: [{unparsed: $raw}], e: null}
        end
    end;

foreach (inputs, null) as $in ({n: 0, errors: 0, r: null};
    if $in == null then .r = null
    else .n += 1 | . as $s | .r = ($in | handle($s.n)) | .errors += (if .r.e == null then 0 else 1 end)
    end;
    if $in == null then
      (if .errors > 0 then "\nlogs-select.sh : \(.errors) ligne(s) en erreur de prédicat (code 4)\n" | halt_error(4) else empty end)
    else
      .r.o[], (.r.e | select(. != null) | (. + "\n") | stderr | empty)
    end)'

# Le stderr de jq va dans un fichier privé, jamais sur le terminal : une erreur non
# rattrapée y cite des valeurs de l'enregistrement.
errfile="$(umask 077; mktemp)"
trap 'rm -f "$errfile"' EXIT

code=0
jq -cnR "$program" 2>"$errfile" || code=$?
case "$code" in
  0) ;;
  # 3 : jq n'a pas compilé le programme. Son message ne cite que le programme (jq
  # compile avant de lire une ligne) et se termine par `jq: N compile error` : relayé.
  # Un halt_error(3) du prédicat n'a pas cette forme : libellé fixe.
  3) if tail -n 1 "$errfile" | grep -Eq '^jq: [0-9]+ compile errors?$'; then
       cat "$errfile" >&2
       echo "logs-select.sh : le prédicat n'est pas un filtre jq valide : $predicate" >&2
     else
       echo "logs-select.sh : jq a échoué (code 3)" >&2
     fi ;;
  # 4 : halt_error(4) du programme, fin de flux après au moins une erreur de prédicat.
  # Seuls les libellés fixes du script sont relayés (filtre sur leur forme exacte) : le
  # prédicat a pu écrire autre chose sur le stderr de jq.
  4) grep -E -- '^logs-select\.sh : (ligne [0-9]+ : le prédicat a levé une erreur|[0-9]+ ligne\(s\) en erreur de prédicat \(code 4\))$' "$errfile" >&2 || true ;;
  # Un signal (130, 141...) : code rendu tel quel, sans message.
  *)
    if [ "$code" -lt 128 ]; then
      # Toute autre sortie non nulle de jq (2 : erreur système ou d'entrée-sortie, 5 :
      # erreur non rattrapée...) devient 5 : le 2 du script veut dire « usage invalide »
      # et ne doit pas se confondre avec lui. Libellé fixe : jamais le texte de jq.
      echo "logs-select.sh : jq a échoué (code $code)" >&2
      code=5
    fi ;;
esac
exit "$code"
