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
#   - la ligne d'accès du pool FPM EN 2xx, 3xx OU 4xx SAUF 403, une par requête
#     sondes comprises, au format par défaut de php-fpm `%R - %u %t "%m %r" %s` (le
#     statut est le dernier champ) :
#     `203.0.113.7 -  16/Nov/2026:10:00:05 +0000 "GET /index.php" 200`.
#     Chaque 401/404 de production (`/api/me` anonyme, scanners, client MCP sans
#     jeton) noierait les événements cherchés. CES 401, 404 ET AUTRES 4xx SONT
#     PERDUS pour ce script : pour les retrouver, lire les journaux bruts
#     (`kubectl logs` sans le filtre). Le 403 n'est PAS écarté, il sort en
#     `unparsed` : seuls `backofficeAccessDenied`, `csrfRejected` et les refus MCP
#     ont un enregistrement d'audit, un jeton de base qui tente `GET /api/cv` (403)
#     n'en laisse aucun. Un 403 déjà audité sort donc deux fois, par son audit et par
#     sa ligne d'accès : accepté. Une ligne d'accès en 5xx n'est PAS écartée non
#     plus : le statut de cette ligne est celui que PHP a posé, un 5xx applicatif
#     n'a pas d'autre trace. Un 502 ou un 504 est écrit par nginx, pas par FPM, et
#     un worker tué laisse un WARNING du maître FPM (rendu aussi en `unparsed`), pas
#     une ligne d'accès : ni l'un ni l'autre ne se lit ici par ce filtre d'accès ;
#   - les NOTICE de routine du maître (date sans nom de zone) : `fpm is running, pid N`,
#     `ready to handle connections` et `[pool <nom>] child N started`. Tout autre
#     NOTICE sort en `unparsed` : `Terminating ...`, `Finishing ...`,
#     `exiting, bye-bye!`, `Reloading in progress ...` (arrêt ou rechargement du
#     maître) et `child N exited with code 0 ...` (un enfant qui sort, même
#     proprement, est un fait de cycle de vie et non de la routine de démarrage) ;
#   - la ligne vide (elle n'est pas un enregistrement et n'a rien à dire), y compris
#     une ligne réduite à un préfixe kubectl. Une ligne faite seulement d'espaces ou
#     de tabulations n'est PAS vide : on n'écarte que ce qui est connu, elle sort en
#     `unparsed`.
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
# ligne est écartée et une ligne FIXE va sur stderr, `logs-select.sh : ligne N : le
# prédicat a levé une erreur` (N : numéro de la ligne d'entrée, lignes de bruit
# comprises). Ces lignes sortent AU FIL DE L'EAU, au moment de l'erreur : elles
# restent donc visibles après un Ctrl-C ou un `| head`. À la fin de l'entrée une
# dernière ligne donne le total, `N ligne(s) en erreur de prédicat (code 4)` (perdue
# si Ctrl-C tue aussi son filtre), et le script se termine par le code 4.
# Mécanisme : le libellé est construit par le programme jq (`stderr`) et le stderr de
# jq passe par un filtre awk, liste blanche sur la forme exacte des libellés ; il
# compte les erreurs et n'écrit aucun fichier (rien ne grossit pendant un `-f`).
# Le texte d'erreur de jq n'est jamais repris : il cite des valeurs de
# l'enregistrement (`+ 1` sur une chaîne en donne le début, `tonumber` la chaîne
# entière, une erreur non rattrapée `jq: error (at <stdin>:N) : …` la valeur), alors
# que stderr, lui, finit dans un terminal, une session d'assistant ou un journal de
# CI. Ce que le prédicat écrit lui-même sur le stderr de jq (`stderr`, `debug`) n'est
# jamais relayé. Pour le détail, rejouer le
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
#   4  le prédicat a levé une erreur sur au moins une ligne (le reste du flux est
#      rendu) ; il l'emporte sur le 5 ci-dessous, jamais sur un signal ;
#   5  toute autre sortie non nulle de jq (hors signal) : erreur système ou
#      d'entrée-sortie (code 2 de jq), erreur non rattrapée (code 5), `halt_error`
#      du prédicat... Le script dit seulement `jq a échoué (code N)` sur stderr, sans
#      le texte de jq. Un `halt_error(3)` du prédicat n'a pas la forme d'une erreur de
#      compilation : libellé fixe, code 3 (non relayé) ; un `halt_error(4)` est un 5 ;
#   128 et plus  un signal a tué jq, code rendu tel quel et sans message : 141 (SIGPIPE,
#      `| head -n 20` a fermé le tube), 130 (Ctrl-C), 143 (SIGTERM)... Les libellés
#      d'erreur déjà émis restent visibles.
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
def access: "^\\S+ - \\S* [0-9]{2}/[A-Za-z]{3}/[0-9]{4}:[0-9]{2}:[0-9]{2}:[0-9]{2} [+-][0-9]{4} \"[A-Z]+ [^\"]*\" (2[0-9]{2}|3[0-9]{2}|4(0[0-24-9]|[1-9][0-9]))$";
def notice: "^\\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2}\\] NOTICE: (fpm is running, pid [0-9]+|ready to handle connections|(\\[pool [^\\]]*\\] )?child [0-9]+ started)$";

# Chemins de la ligne, du moins au plus coûteux (une recherche d expression régulière
# coûte plusieurs microsecondes, la compilation de la suivante comprise) :
#   1. elle commence par { : pas de préfixe kubectl possible (il commence par [pod/ ou
#      un chiffre), directement fromjson, ni sub ni test ;
#   2. ligne d accès sans préfixe (le plus fréquent après le JSON) : un seul test ;
#   3. le reste : retrait du préfixe, puis classification (bruit, objet, unparsed). Une
#      ligne est vide seulement si le préfixe seul la compose : des blancs seuls ne le
#      sont pas.
# fromjson et son exception rattrapée, coûteuse, ne servent que pour une ligne qui
# commence par { : seul un objet est un enregistrement, un JSON non objet sort en
# unparsed de toute façon.
# (Pas d apostrophe dans ces commentaires : le programme est entre apostrophes en bash.)

# Retire les blancs de tête (espace, tabulation) sans regex pour la ligne qui n en a pas.
def ltrim: if startswith(" ") or startswith("\t") then sub("^[ \\t]+"; "") else . end;

# Résultat pour une ligne : {o: [sorties], e: message d erreur ou null}.
def handle($n):
  . as $raw
  | ltrim as $t
  | ($t | startswith("{")) as $brace
  | (if $brace or ($raw | test(access)) then null else ($raw | sub(prefix; "")) end) as $pre
  | (if $brace then $t elif $pre == null then null else ($pre | ltrim) end) as $line
  | (if $brace or $line == null then $line elif ($pre == "" or ($line | test(access)) or ($line | test(notice))) then null else $line end) as $kept
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

# Une valeur par ligne d entrée ; le libellé d erreur, précédé d un saut de ligne (le
# prédicat a pu écrire sans en terminer un), part sur stderr au moment où il se produit.
foreach inputs as $in (0; . + 1; . as $n | $in | handle($n)
    | .o[], (.e | select(. != null) | (. + "\n") | stderr | empty))'

# Filtre du stderr de jq, au fil de l'eau (awk, un `fflush` par libellé) :
#  - un libellé fixe d'erreur de prédicat est relayé aussitôt et compté ;
#  - tout autre ligne (valeur écrite par le prédicat, erreur non rattrapée de jq) est
#    gardée en mémoire bornée (200 lignes) et jamais relayée, sauf si la dernière est
#    `jq: N compile error(s)` : jq compile avant de lire, ses lignes ne citent que le
#    programme, elles sont alors relayées. Sortie awk : 3 (compilation), 4 (erreurs de
#    prédicat comptées, le total est écrit), sinon 0.
stderr_filter() {
  awk '
    /^logs-select\.sh : ligne [0-9]+ : le prédicat a levé une erreur$/ { print; fflush(); n++; next }
    { total++; last = $0; if (b < 200) buf[++b] = $0 }
    END {
      compile = (last ~ /^jq: [0-9]+ compile errors?$/)
      if (compile) {
        for (i = 1; i <= b; i++) print buf[i]
        if (total > b) print last
      }
      if (n > 0) printf "logs-select.sh : %d ligne(s) en erreur de prédicat (code 4)\n", n
      exit compile ? 3 : (n > 0 ? 4 : 0)
    }'
}

# Le filtre est lancé d abord, sur un descripteur dédié : son pid (`$!`) est lu avant que
# le prochain `||` ne le perde, et il voit la fin du flux quand ce descripteur est fermé.
exec 3> >(stderr_filter >&2)
filter_pid=$!
code=0
jq -cnR "$program" 2>&3 || code=$?
exec 3>&-
# Attendre le filtre : son statut dit s'il y a eu des erreurs de prédicat (4) ou une erreur
# de compilation relayée (3), et ses lignes finales doivent précéder les nôtres.
filter=0
wait "$filter_pid" || filter=$?

case "$code" in
  0) ;;
  # 3 : jq n'a pas compilé le programme (le filtre a relayé son message, qui ne cite que le
  # programme) ; un halt_error(3) du prédicat n'a pas cette forme : libellé fixe.
  3) if [ "$filter" -eq 3 ]; then
       echo "logs-select.sh : le prédicat n'est pas un filtre jq valide : $predicate" >&2
     else
       echo "logs-select.sh : jq a échoué (code 3)" >&2
     fi ;;
  # Un signal (130, 141...) : code rendu tel quel, sans message.
  *)
    if [ "$code" -lt 128 ]; then
      # Toute autre sortie non nulle de jq (2 : erreur système ou d'entrée-sortie, 5 :
      # erreur non rattrapée, 4 : halt_error du prédicat...) devient 5 : le 2 du script veut
      # dire « usage invalide ». Libellé fixe : jamais le texte de jq.
      echo "logs-select.sh : jq a échoué (code $code)" >&2
      code=5
    fi ;;
esac
# Des erreurs de prédicat ont eu lieu : 4, sauf si un signal a tué jq (>= 128, rendu tel quel).
if [ "$filter" -eq 4 ] && [ "$code" -lt 128 ] && [ "$code" -ne 3 ]; then
  code=4
fi
exit "$code"
