#!/usr/bin/env bash
#
# smoke-http.sh
# ---------------------------------------------------------------------------
# Bibliothèque sourçable : ce que les scripts de fumée et d'audit qui appellent
# `curl` contre un déploiement réel écrivaient chacun de leur côté (#748, R57) :
# la Basic Auth d'un appel (un fichier `-K` privé, jamais `-u`), les refus qui la protègent,
# la lecture d'un en-tête de réponse et l'appel de curl lui-même.
#
# Sept fonctions publiques :
#   smoke_use_auth_config <nom>            la seule entrée de la Basic Auth : écrit le
#                                          fichier de configuration `-K` de la variable,
#                                          dans un dossier privé (SMOKE_AUTH_CONFIG),
#                                          puis refuse (2) une variable définie mais
#                                          vide ; smoke_fetch lit le fichier ;
#   smoke_cleanup                          retire ce dossier et les fichiers de
#                                          smoke_data_file ; à poser dans le
#                                          `trap` de chaque appelant ;
#   smoke_data_file                        crée un fichier de corps de requête, le seul
#                                          que `--data-binary @` puisse lire avec la
#                                          Basic Auth ; son chemin est SMOKE_AUTH_DATA_FILE ;
#   smoke_header <fichier> <nom>           la valeur d'un en-tête de réponse (la
#                                          dernière s'il y en a plusieurs) ;
#   smoke_headers <fichier> <nom>          toutes ses valeurs, une par ligne ;
#   smoke_excerpt <texte>                  un extrait sans caractère de contrôle ;
#   smoke_fetch <libellé> <url> [args…]    un appel curl qui rend statut et corps ;
#                                          ses refus sont dits dans sa docblock.
#
# Le binaire est `SMOKE_CURL` (`curl` s'il est vide ; les tests hors ligne y
# mettent un faux) : un seul nom, lu ici seulement.
#
# Convention des fonctions privées (Q6 de la quinzième relecture) : bash n'a pas de valeur de retour, et un `$(…)` coûterait un
# sous-shell, qui perdrait ce que la fonction pose. Une fonction privée rend donc son code de retour et, quand elle a plus à dire,
# pose une globale `_smoke_*` que l'appelant lit juste après (`_smoke_arity`, `_smoke_refused_rank`, `_smoke_refused_reason`) ;
# chacune est déclarée à la source, juste au-dessus de la fonction qui la pose. Une fonction qui juge ou écrit la valeur de la
# Basic Auth la lit dans la variable `_smoke_value` de son appelant (la portée dynamique de bash) : la valeur ne passe jamais en
# argument, donc ni dans `ps` ni dans une trace. Celle qui l'écrit, `_smoke_write_config_file`, l'échappe **en place** : c'est
# l'appelant qui la vide ensuite.
#
# La Basic Auth d'un appelant se lit dans une variable que ses scripts nomment AUDIT_BASIC_AUTH :
# de l'audit, des smokes et de cette bibliothèque, ce fichier est le seul à la décrire (l'audit et les trois
# smokes ne l'écrivent qu'en deux formes, voir tools/tests/smoke-http.test.sh ; la règle
# .claude/rules/outillage-smoke.md la nomme aussi). Valeurs refusées, sortie 2, jamais citées :
#   - une valeur avec un retour à la ligne ou un CR (elle ouvrirait une directive du fichier `-K`) ;
#   - une valeur non vide sans « : » (des blancs compris) ;
#   - un utilisateur ou un mot de passe vide, ou fait seulement de blancs (`:`, `:p`, `u:`, ` :p`, `u: `) ;
#   - une variable définie mais vide (le secret de préprod manquant) : « secret de Basic Auth vide ».
# Une variable non définie n'envoie aucune Basic Auth (la prod, un smoke lancé à la main). Dès qu'elle est lue,
# la variable est retirée du shell (`unset`, D3 de la quatorzième relecture) : aucun vidage du shell, aucun test de définition
# ni aucun processus lancé ensuite (mktemp, curl, dig, openssl) ne la voit. Un drapeau privé, `_smoke_auth_defined`,
# garde « elle était définie » pour le contrôle du vide. Un retrait qui échoue (variable en lecture seule, ou globale masquée
# par une locale de l'appelant) est un refus : sortie 2, rien d'écrit, mais la variable reste alors visible : d'un vidage du shell
# quand elle est en lecture seule (Sp16-23), de la globale exportée aux processus fils quand une locale la masquait. Cela
# n'efface pas non plus la copie d'environnement que le processus a reçue à son lancement, lisible sous /proc, qui la porte encore.
# Aucune ligne de l'audit, des smokes ni de cette bibliothèque ne lit cette copie (R148b, garde de tools/tests/smoke-http.test.sh).
#
# Pas de `set -e` ici, ni d'appel réseau à la source : une bibliothèque sourcée
# ne change pas les options du shell qui la source. Aucune fonction ne fait
# sortir l'appelant ; c'est lui qui décide (`|| exit 2`, `|| exit "$?"`).
#
# Limite écrite (St8-12, St16-27) : sourcer la bibliothèque deux fois remet SMOKE_AUTH_DIR, SMOKE_AUTH_CONFIG,
# SMOKE_AUTH_DATA_FILES, SMOKE_AUTH_DATA_FILE et le drapeau privé à vide ; le dossier qu'un premier _smoke_write_config_file a
# créé et les fichiers qu'un premier smoke_data_file a créés ne sont alors plus retirés par le `trap`. Aucun script ne le fait
# (chacun source une fois, en tête) ; un marqueur pour ce cas serait une astuce de shell de plus, et le reste est un dossier
# 0700 ou des fichiers 600 de l'utilisateur sous TMPDIR.
# ---------------------------------------------------------------------------

# Le dossier privé de la Basic Auth et le fichier qu'il porte. Vides à la source,
# quoi que l'environnement ait contenu : une valeur héritée n'est jamais crue, ou
# le `trap` de l'appelant supprimerait un fichier qui n'est pas le sien (C7-6).
# Posées par _smoke_write_config_file seule, lues par les `trap` via smoke_cleanup.
SMOKE_AUTH_DIR=""
SMOKE_AUTH_CONFIG=""
# Les fichiers que smoke_data_file a créés (un tableau : un chemin peut porter un blanc) et le dernier d'entre eux. Leur préfixe
# `SMOKE_AUTH_` est ce que `smoke_cleanup` vide, et ce que _smoke_is_variable_name refuse comme nom de la Basic Auth (St16-26).
# Vides à la source, pour la même raison que le dossier : une valeur héritée n'est jamais crue, ou `smoke_cleanup` retirerait un
# fichier qui n'est pas le sien, et `--data-binary @<ce chemin>` serait admis avec la Basic Auth. Posés par smoke_data_file seule,
# vidés par smoke_cleanup.
SMOKE_AUTH_DATA_FILES=()
SMOKE_AUTH_DATA_FILE=""
# « La variable de la Basic Auth était définie » (1) ou non (0), le seul souvenir que garde la bibliothèque de la
# variable qu'elle a retirée du shell. Posé par _smoke_write_auth_config avant le retrait, lu par
# _smoke_require_auth_config seule. À 0 à la source, quoi que l'environnement ait contenu. Le préfixe `_smoke_` est
# refusé comme nom de variable (_smoke_is_variable_name) : aucun appelant ne peut passer ce nom.
_smoke_auth_defined=0

# smoke_cleanup
#
# Retire le dossier que _smoke_write_config_file a créé et les fichiers que smoke_data_file a créés (jamais un autre : les
# variables sont vides à la source), puis remet les variables à vide, le drapeau `_smoke_auth_defined` à 0 : un second appel ne
# réutilise rien du premier, pas même un chemin de fichier de corps, qui n'est plus admis. Retour 0 : c'est la dernière commande
# d'un `trap`, elle ne change pas le code de sortie. _smoke_write_auth_config l'appelle aussi en tête (voir plus bas), donc un
# second smoke_use_auth_config retire aussi les fichiers de corps du premier : un smoke les crée après son appel.
smoke_cleanup() {
  if [ -n "$SMOKE_AUTH_DIR" ]; then
    rm -rf -- "$SMOKE_AUTH_DIR"
  fi
  if [ "${#SMOKE_AUTH_DATA_FILES[@]}" -gt 0 ]; then
    rm -f -- "${SMOKE_AUTH_DATA_FILES[@]}"
  fi
  SMOKE_AUTH_DIR=""
  SMOKE_AUTH_CONFIG=""
  SMOKE_AUTH_DATA_FILES=()
  SMOKE_AUTH_DATA_FILE=""
  _smoke_auth_defined=0
  return 0
}

# smoke_data_file
#
# Crée un fichier vide (`mktemp`, sous TMPDIR, en 600), le retient dans SMOKE_AUTH_DATA_FILES et pose son chemin dans
# SMOKE_AUTH_DATA_FILE (D2 de la quinzième relecture, Q4). C'est le seul fichier que `--data-binary @<chemin>` peut lire avec la Basic Auth
# (smoke_fetch compare le chemin entier, rien d'autre ne passe : ni le fichier `-K`, ni un autre fichier du même dossier). Le chemin
# n'est **pas imprimé** : `"@$(smoke_data_file)"` lancerait la fonction dans un sous-shell, qui perdrait l'enregistrement, et le
# corps serait refusé. À appeler **après** smoke_use_auth_config (un smoke ne lance aucun processus avant elle, D2 de la quatorzième
# relecture, et son appel de tête retire les fichiers d'un appel précédent). smoke_cleanup les retire. Avec ou sans Basic Auth : un
# smoke ne sait pas s'il en porte une. Retour 0 ; 2 si `mktemp` échoue (rien de retenu, SMOKE_AUTH_DATA_FILE vide), le motif sur stderr.
# Limite : smoke_fetch juge le nom du fichier, pas le fichier (voir _smoke_is_data_file, Sp16-25).
smoke_data_file() {
  local _smoke_file
  SMOKE_AUTH_DATA_FILE=""
  if ! _smoke_file="$(mktemp)"; then
    echo "smoke_data_file : mktemp a échoué, aucun fichier créé" >&2
    return 2
  fi
  SMOKE_AUTH_DATA_FILES+=("$_smoke_file")
  # Lue par l'appelant, pas par la bibliothèque.
  # shellcheck disable=SC2034
  SMOKE_AUTH_DATA_FILE="$_smoke_file"
}

# _smoke_is_variable_name <nom>
#
# Vrai pour un nom de variable que les fonctions de la Basic Auth peuvent lire par indirection :
# un identifiant, sans le préfixe `_smoke_` ni `SMOKE_AUTH_` (les raisons sont plus bas).
_smoke_is_variable_name() {
  [[ "$1" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "$1" != _smoke_* && "$1" != SMOKE_AUTH_* ]]
}

# _smoke_judge_value
#
# Juge la valeur de la Basic Auth que _smoke_write_auth_config vient de lire, sur le modèle de _smoke_judge_args. Elle lit `_smoke_name`
# et `_smoke_value`, les variables de la fonction qui l'appelle (portée dynamique, voir l'en-tête) : la valeur ne passe en argument
# d'aucune fonction. Rend 0 pour une valeur admise (vide comprise : c'est à _smoke_require_auth_config de juger ce vide) ; sinon 2,
# valeur refusée, le motif dit sur stderr en nommant la variable (#748 C6-9), jamais la valeur, qui est un secret. Trois refus :
#   - un retour à la ligne ou un CR : il ouvrirait une seconde directive dans le fichier `-K` (`url = …`, `output = …`), car
#     l'échappement n'agit que sur `"` et `\` ;
#   - une valeur non vide sans « : » (des blancs compris) : curl coupe au premier « : », ce n'est pas un couple
#     utilisateur:mot_de_passe ;
#   - un utilisateur ou un mot de passe vide, ou fait seulement de blancs (`:`, `:p`, `u:`, ` :p`, `u: `, C12-9).
# Les trois sont refusés avant tout dossier ; le code 2 est celui d'un défaut d'environnement.
_smoke_judge_value() {
  if [[ "$_smoke_value" == *$'\n'* || "$_smoke_value" == *$'\r'* ]]; then
    echo "$_smoke_name contient un retour à la ligne : refusé, il ouvrirait une directive dans le fichier de configuration curl" >&2
    return 2
  fi
  if [ -z "$_smoke_value" ]; then
    return 0
  fi
  if [[ "$_smoke_value" != *:* ]]; then
    echo "$_smoke_name : Basic Auth sans « : » (utilisateur:mot_de_passe attendu), refusée" >&2
    return 2
  fi
  if [[ "${_smoke_value%%:*}" =~ ^[[:space:]]*$ || "${_smoke_value#*:}" =~ ^[[:space:]]*$ ]]; then
    echo "$_smoke_name : Basic Auth sans utilisateur ou sans mot de passe (utilisateur:mot_de_passe attendu), refusée" >&2
    return 2
  fi
  return 0
}

# _smoke_write_config_file
#
# Écrit le fichier de configuration curl (`-K`) qui porte la Basic Auth : un dossier privé (`mktemp -d`, 0700) sous TMPDIR, un fichier
# en 600 (`umask 077`), et une ligne `user = "…"`, les guillemets et antislashs échappés au format attendu par curl, donc n'importe
# quel mot de passe convient. SMOKE_AUTH_DIR et SMOKE_AUTH_CONFIG reçoivent les deux chemins, rien n'est imprimé. Jamais `-u` : les
# arguments d'un processus se lisent dans `ps`, seul le chemin du fichier apparaît dans ceux de curl. Elle lit `_smoke_value`, la
# variable de la fonction qui l'appelle, l'échappe en place et n'a pas de variable de ce nom à elle : la valeur ne passe en argument
# de rien, donc ni dans `ps` ni dans la trace d'un `set -x`. Un arrêt entre la création du dossier et l'affectation que lit le `trap`
# peut laisser un dossier privé **vide**, jamais la valeur : elle n'est écrite qu'une fois le chemin connu du `trap` (C7-8).
# Retour 0 ; 2 si le dossier ou le fichier ne s'écrit pas (le dossier est alors retiré, rien n'est resté).
#
# Une fois le fichier écrit (une Basic Auth pleine), elle retire aussi du shell les variables que curl lit et qui changeraient
# où part l'identifiant ou ce qu'on en voit : `http_proxy`, `HTTPS_PROXY`, `https_proxy`, `ALL_PROXY`, `all_proxy`, `NO_PROXY`,
# `no_proxy`, `CURL_CA_BUNDLE`, `CURL_SSL_BACKEND`, `QLOGDIR`, `SSL_CERT_DIR`, `SSL_CERT_FILE`, `SSLKEYLOGFILE` (un proxy qui
# inspecte le TLS, un lot d'autorités, un journal de clés TLS ou QUIC laissé par une session de débogage, le choix du moteur TLS ;
# R169, Q8). `CURL_HOME`, `XDG_CONFIG_HOME`, `HOME`, `APPDATA` et `USERPROFILE` ne servent qu'au `.curlrc`, que curl ne lit pas
# sous `-q` en tête de chaque appel ; `COLUMNS` règle la largeur de la barre de progression, `SHELL` ne sert que sous VMS. Les
# variantes en minuscules hors proxy (`curl_ca_bundle`, `ssl_cert_dir`, `ssl_cert_file`, `sslkeylogfile`) ne sont pas lues par
# curl 8.5.0 : mesuré le 2026-11-01 dans l'image du runner (`--network none`), `curl_ca_bundle`, `ssl_cert_file` et `ssl_cert_dir` ne
# changent ni `CURLOPT_CAINFO` ni `CURLOPT_CAPATH`, et `sslkeylogfile` ne crée aucun fichier là où `SSLKEYLOGFILE` en crée un ; elles
# ne sont donc pas retirées. Sans Basic Auth, rien n'est retiré.
#
# Limite écrite (R183, C15-4) : la bibliothèque retire ce qu'un poste ou un runner de bonne foi laisse traîner, une fois, à l'appel.
# Un environnement que contrôle un attaquant (`OPENSSL_CONF`, `LD_PRELOAD`, `PATH`, `SMOKE_CURL`, ou `SSLKEYLOGFILE` exporté de
# nouveau après l'appel) est hors du modèle : il verrait l'identifiant par le processus lui-même, sans passer par curl. Pour un
# proxy exporté après l'appel, smoke_fetch pose `--noproxy '*'` avec le `-K`.
_smoke_write_config_file() {
  local _smoke_umask _smoke_rc=0
  _smoke_umask="$(umask)"
  umask 077
  SMOKE_AUTH_DIR="$(mktemp -d)" || _smoke_rc=2
  if [ "$_smoke_rc" -eq 0 ]; then
    SMOKE_AUTH_CONFIG="$SMOKE_AUTH_DIR/curl-auth.conf"
    _smoke_value="${_smoke_value//\\/\\\\}"
    _smoke_value="${_smoke_value//\"/\\\"}"
    printf 'user = "%s"\n' "$_smoke_value" > "$SMOKE_AUTH_CONFIG" || { smoke_cleanup; _smoke_rc=2; }
  fi
  if [ "$_smoke_rc" -eq 0 ]; then
    unset -v http_proxy HTTPS_PROXY https_proxy ALL_PROXY all_proxy NO_PROXY no_proxy \
      CURL_CA_BUNDLE CURL_SSL_BACKEND QLOGDIR SSL_CERT_DIR SSL_CERT_FILE SSLKEYLOGFILE
  fi
  umask "$_smoke_umask"
  return "$_smoke_rc"
}

# _smoke_write_auth_config <nom d'une variable valant "utilisateur:mot_de_passe">
#
# Privée. Lit la Basic Auth dans la variable que son nom désigne, la retire du shell, la juge (_smoke_judge_value) et, si elle est
# pleine et valide, écrit le fichier `-K` (_smoke_write_config_file) : SMOKE_AUTH_DIR et SMOKE_AUTH_CONFIG reçoivent les deux
# chemins, rien n'est imprimé, et smoke_fetch passe `-K` à chaque appel tant que SMOKE_AUTH_CONFIG est posé (jamais rangé dans
# SMOKE_CURL_OPTS, donc jamais périmé). Retour 0 ; 2 pour un nom invalide, un retrait de la variable qui échoue, une valeur refusée,
# un dossier ou un fichier qui ne s'écrit pas.
#
# Une variable vide ou absente : retour 0, aucun dossier, SMOKE_AUTH_CONFIG vide. C'est à l'appelant de juger ce vide :
# _smoke_require_auth_config l'arrête (sortie 2) quand la variable est définie, une variable absente n'envoie aucune Basic Auth
# (la prod, un smoke lancé à la main). La valeur n'est lue que sous `set +x` (l'état de xtrace relevé avant, rétabli après), dans
# le shell courant : elle ne passe en argument d'aucune fonction ni d'aucun processus, donc ni dans `ps` ni dans la trace.
#
# Le nom est un identifiant (`[A-Za-z_][A-Za-z0-9_]*`), jamais une expression : l'indirection évalue un indice, `x[$(commande)]`
# exécuterait la commande (C7-7). Il ne commence pas par `_smoke_` (CR8-5) : les locales de la fonction portent ce préfixe, et
# l'indirection lit d'abord une locale du même nom — un nom comme `value` ou `rc` donnerait une Basic Auth fausse et silencieuse.
# Il ne commence pas non plus par `SMOKE_AUTH_` (C9-5) : SMOKE_AUTH_DIR, SMOKE_AUTH_CONFIG, SMOKE_AUTH_DATA_FILES et
# SMOKE_AUTH_DATA_FILE sont remises à vide par l'appel de tête avant la lecture, la valeur lue serait vide et aucune Basic Auth ne
# serait posée (« secret de Basic Auth vide », sortie 2, pour smoke_use_auth_config).
#
# Elle appelle smoke_cleanup **en tête**, avant tout, y compris la validation du nom (décision 6 de #748) : un second appel remplace
# le premier, il ne laisse ni dossier ni fichier d'identifiant derrière lui, et il n'existe jamais qu'un dossier. Ce que l'appel de
# tête retire est ce que la bibliothèque a créé elle-même : les variables sont vides à la source, un dossier hérité de
# l'environnement n'est jamais visé.
#
# Limite écrite (décision de l'auteur, 2026-10-31) : cet appel de tête lance `rm -rf`, un processus fils, avant la lecture et donc
# avant le retrait de la variable. Un second appel le lance avec la variable du second nom, si elle est encore exportée : `rm` la
# reçoit. C'est un fils du même utilisateur, qui ne journalise rien, le temps du nettoyage ; changer l'ordre compliquerait le chemin
# d'échec du nom pour un risque nul.
#
# Dès la lecture, avant toute validation et tout `mktemp`, la variable est retirée du shell (`unset -v`, D3 de la quatorzième
# relecture ; n'en retirer que l'exportation la laisserait lisible par un vidage du shell), pour toute valeur lue, refusée comprise :
# quand le retrait réussit, aucun vidage du shell, ni le `mktemp -d` de _smoke_write_config_file, curl, dig ou openssl ne la voient.
# Le drapeau privé `_smoke_auth_defined`, posé juste avant la lecture, garde « elle était définie » : c'est lui que lit
# _smoke_require_auth_config.
#
# Le retrait est vérifié (R182, Q1 de la quinzième relecture) : `unset` d'une variable en lecture seule échoue sans arrêter la
# fonction, et celui d'une locale de l'appelant laisse voir la globale exportée qu'elle masquait. Si la variable est encore définie
# juste après, le retrait n'a pas eu lieu : la valeur est vidée, xtrace rétabli, et l'appel rend 2 avant toute validation et tout
# `mktemp`, en nommant la variable (jamais la valeur) : « la variable ne peut pas être retirée du shell ». Aucun fichier n'est
# écrit ; l'appelant sort sur ce 2. Ce refus ne cache rien pour autant (Sp16-23) : la variable refusée est toujours là, en lecture
# seule elle reste visible d'un vidage du shell, et la globale exportée que masquait une locale reste visible des processus fils.
#
# Limite écrite (C16-3) : sous `shopt -s localvar_unset`, l'`unset` d'une locale de l'appelant la laisse définie et vide : `[[ -v ]]`
# répond faux, le retrait passe pour réussi, et la globale exportée qu'elle masque reste visible des processus fils (mesuré le
# 2026-11-01, bash 5.2.37 : `export V=g`, une locale `V` dans l'appelant, l'`unset` dans la fonction appelée, le fils lit `g`). Aucun
# des quatre scripts ne pose cette option, qui est un réglage du shell de l'appelant ; la bibliothèque ne la teste pas.
#
# Limite écrite (R182, Q2, CR15-3) : un nom invalide rend 2 avant la lecture, donc avant tout retrait, et la variable reste exportée.
# Aucun script ne l'atteint (la première garde de tools/tests/smoke-http.test.sh impose l'appel littéral du nom de la Basic Auth), et
# le `trap` des quatre scripts ne lance aucun processus tant que rien n'est créé : `smoke_cleanup` n'a alors aucun dossier à retirer,
# donc aucun `rm` (cas de tools/tests/audit-prod.test.sh). Retirer ici une variable nommée en dur donnerait à la bibliothèque le nom
# de ses appelants, ce que sa règle refuse.
#
# Le `trap` de l'appelant se pose **avant** l'appel ; puis `smoke_use_auth_config` l'enchaîne avec _smoke_require_auth_config.
# Celui des trois smokes : `trap '[ -z "$headers_file" ] || rm -f -- "$headers_file"; smoke_cleanup' EXIT` ; celui de l'audit :
# `trap 'smoke_cleanup' EXIT`.
_smoke_write_auth_config() {
  local _smoke_name="${1:-}" _smoke_value _smoke_traced=0 _smoke_rc=0
  smoke_cleanup
  if ! _smoke_is_variable_name "$_smoke_name"; then
    echo "_smoke_write_auth_config : nom de variable invalide" >&2
    return 2
  fi
  case "$-" in *x*) _smoke_traced=1 ;; esac
  set +x
  if [[ -v $_smoke_name ]]; then _smoke_auth_defined=1; fi
  _smoke_value="${!_smoke_name:-}"
  unset -v -- "$_smoke_name"
  if [[ -v $_smoke_name ]]; then
    _smoke_rc=2
    echo "$_smoke_name : la variable ne peut pas être retirée du shell (lecture seule ou globale masquée), rien d'écrit" >&2
  else
    _smoke_judge_value || _smoke_rc=$?
    if [ "$_smoke_rc" -eq 0 ] && [ -n "$_smoke_value" ]; then
      _smoke_write_config_file || _smoke_rc=$?
    fi
  fi
  _smoke_value=""
  if [ "$_smoke_traced" -eq 1 ]; then set -x; fi
  return "$_smoke_rc"
}

# _smoke_require_auth_config <nom d'une variable valant "utilisateur:mot_de_passe">
#
# Privée. Juge ce que _smoke_write_auth_config vient de poser (SMOKE_AUTH_CONFIG, ou rien). Une variable **définie** mais vide
# (le secret de préprod manquant) ne pose rien, et un script qui court alors sans Basic Auth lirait chaque 401 de la préprod comme
# un résultat.
# Retour 2 et « <nom> : secret de Basic Auth vide, rien de vérifié » sur stderr ; 0 sinon,
# y compris pour une variable non définie (la prod n'a pas de Basic Auth). Le vide se juge sur
# SMOKE_AUTH_CONFIG et la définition sur le drapeau `_smoke_auth_defined` : la variable n'existe plus
# ici (_smoke_write_auth_config l'a retirée), et sa valeur n'est jamais développée, donc jamais dans la
# trace d'un `set -x`. Même validation du nom que _smoke_write_auth_config.
_smoke_require_auth_config() {
  local _smoke_name="${1:-}"
  if ! _smoke_is_variable_name "$_smoke_name"; then
    echo "_smoke_require_auth_config : nom de variable invalide" >&2
    return 2
  fi
  if [ "$_smoke_auth_defined" -eq 1 ] && [ -z "$SMOKE_AUTH_CONFIG" ]; then
    echo "$_smoke_name : secret de Basic Auth vide, rien de vérifié" >&2
    return 2
  fi
  return 0
}

# smoke_use_auth_config <nom d'une variable valant "utilisateur:mot_de_passe">
#
# La seule fonction de la Basic Auth que l'appelant écrit : elle pose (_smoke_write_auth_config) puis
# exige (_smoke_require_auth_config), dans cet ordre, sans que l'appelant ait à s'en souvenir (St11-4).
# Retour 0 : variable non définie (aucune Basic Auth), ou pleine et écrite (SMOKE_AUTH_CONFIG posé).
# Retour 2, la cause dite sur stderr : nom invalide, valeur refusée, retrait de la variable impossible, dossier impossible, ou
# variable définie mais vide. Aucune fonction ne fait sortir l'appelant : `smoke_use_auth_config NOM || exit 2`.
smoke_use_auth_config() {
  _smoke_write_auth_config "${1:-}" || return "$?"
  _smoke_require_auth_config "${1:-}"
}

# smoke_headers <fichier d'en-têtes> <nom>
#
# Toutes les valeurs de l'en-tête dans la réponse que curl a écrite dans le fichier
# (`-D`), une par ligne, dans l'ordre : nom insensible à la casse (HTTP/2 l'écrit en
# minuscules), CR retiré, rien si absent. C'est la lecture des en-têtes multiples
# (`Set-Cookie`) ; smoke_header en prend la dernière.
smoke_headers() {
  tr -d '\r' < "$1" | awk -v name="$2" '
    tolower(substr($0, 1, length(name) + 1)) == tolower(name) ":" { sub(/^[^:]*:[[:space:]]*/, ""); print }'
}

# smoke_header <fichier d'en-têtes> <nom>
#
# La valeur d'un en-tête de la réponse que curl a écrite dans le fichier (`-D`) :
# la dernière occurrence de smoke_headers (la dernière réponse d'une redirection),
# vide si absent.
smoke_header() {
  smoke_headers "$1" "$2" | tail -n 1
}

# smoke_excerpt <texte>
#
# Les 160 premiers caractères du texte, tout caractère de contrôle changé en
# espace : ce texte part dans le journal de la CI, où un `\r` ouvrirait une ligne
# `::commande::` que le runner exécuterait.
smoke_excerpt() {
  printf '%s' "$1" | tr '\000-\037\177' '[ *]' | cut -c1-160
}

# _smoke_option_arity <argument de curl>
#
# La liste d'autorisation des arguments que smoke_fetch admet **avec la Basic Auth** (D1 de la quatorzième relecture), faite
# d'un `case` : aucune variable que l'appelant puisse modifier, et une seconde `source` de la bibliothèque la laisse intacte.
# Rend 0 et pose `_smoke_arity` (le nombre de valeurs que l'option prend) pour un argument de la liste, 1 pour tout autre :
#   - `-sS`                                                        0 valeur ;
#   - `--connect-timeout`, `--max-time`, `-D`, `-X`, `-H`, `-d`,
#     `--data-binary`, `-b`                                        1 valeur (certaines sont jugées : voir _smoke_value_touches_file).
# Ce sont les options que les trois smokes passent réellement (`SMOKE_CURL_OPTS` : `-sS`, deux délais, `-D` ; `"$@"` : `-X`,
# `-H`, `-d`, `--data-binary`, `-b`), relevées par `grep` des appels. La correspondance est **exacte** : ni préfixe (curl 8.5.0
# prend `--proto-r` pour `--proto-redir`), ni casse ignorée, ni `--option=valeur`, ni grappe autre que `-sS` (`-s`, `-sSv`),
# ni valeur collée (`-HAccept: x`). Une liste d'autorisation refuse d'emblée ce qu'une liste de refus n'a pas prévu : `-v`,
# `--verbose`, `--trace`, `--trace-ascii`, `--libcurl` (qui imprime l'identifiant, mesuré), une seconde URL, `-x`, `--proxy`,
# `--cacert`, `--proto-default`, une option future.
#
# Pour ajouter une option : la procédure est écrite une seule fois, dans .claude/rules/outillage-smoke.md (St17-24, St18-5).
_smoke_arity=0
_smoke_option_arity() {
  case "${1-}" in
    -sS) _smoke_arity=0 ;;
    --connect-timeout | --max-time | -D | -X | -H | -d | --data-binary | -b) _smoke_arity=1 ;;
    *) return 1 ;;
  esac
}

# _smoke_is_data_file <chemin>
#
# Vrai si le chemin est, **en entier**, un de ceux que smoke_data_file a retenus : une égalité de chaîne, aucun calcul de chemin
# (`..`, lien, préfixe de dossier), donc ni le fichier `-K` ni un autre fichier du même dossier ne passent.
# Limite écrite (Sp16-25) : le jugement porte sur le nom, pas sur le fichier. Un lien qui remplacerait un de ces fichiers entre sa
# création et l'appel serait lu par curl comme le fichier de corps, sans être vu, pas même par le `-f` de _smoke_value_touches_file,
# qui suit les liens (CR19-9) ; seul l'utilisateur du smoke peut le poser, sous TMPDIR, ce qui sort du modèle (comme un `PATH` ou un
# `LD_PRELOAD` qu'il contrôlerait, voir _smoke_write_config_file).
_smoke_is_data_file() {
  local _smoke_known
  for _smoke_known in "${SMOKE_AUTH_DATA_FILES[@]}"; do
    if [[ "$1" == "$_smoke_known" ]]; then
      return 0
    fi
  done
  return 1
}

# _smoke_writes_on_call_file <valeur de -D>
#
# Vrai si `-D <valeur>` peut faire écrire curl sur un fichier dont l'appel dépend : le fichier `-K`, le dossier de la Basic Auth ou un
# fichier de ce dossier, un fichier de smoke_data_file (C16-4, C17-4, CR17-7, C18-5), ou, par précaution, tout chemin à composant `..`.
# Curl écrase le fichier visé avec les en-têtes de la réponse (curl 8.5.0, mesuré le 2026-11-03 : à 0 octet quand la connexion est
# refusée ; aux en-têtes après un succès, mesuré sur le curl 8.14.1 de l'hôte seul) : le `-K` écrasé serait relu au prochain appel
# comme une configuration vide, donc sans Basic Auth, et un corps écrasé partirait en `--data-binary @…` avec les en-têtes de réponse,
# `Set-Cookie` compris. La raison rendue est `writes`, dont le texte est écrit dans _smoke_say_refused_arg seule. Les jugements :
#   - `-` est la sortie standard : faux ;
#   - une valeur dont un composant est `..` : vrai, sans autre calcul. `-ef` ne suit `..` qu'à travers des composants existants,
#     `<dossier>/absent/../curl-auth.conf` lui échappe (curl sort alors en 23, le `-K` intact, mesuré, mais le jugement ne s'en remet
#     pas à curl) ; `a..b` et `...` ne sont pas un composant `..` ;
#   - contre le fichier `-K`, le dossier lui-même, puis chaque fichier de smoke_data_file : l'égalité de chaîne avec le chemin retenu,
#     qui voit encore un fichier retiré (C18-5 : `-ef` exige qu'il existe, et curl le recréerait), puis `-ef` de bash (même
#     périphérique, même inode : il suit les liens, sans processus ni calcul de chemin) ;
#   - `-ef` du dossier de la valeur (`${1%/*}`, `/` pour un fichier de la racine, `.` pour un nom sans `/`) contre celui de la Basic Auth.
# Limites : un lien qui pointe vers un fichier du dossier qui n'existe pas encore n'est pas vu (le test lit l'inode du lien), ni un
# fichier retiré écrit autrement que son chemin retenu ; `<fichier>/` et `<dossier>/neuf/` passent (C18-6 : aucun fichier n'est là pour
# `-ef`), curl 8.5.0 sort alors en 23 sans rien écrire (mesuré le 2026-11-03) ; une valeur relative est jugée depuis le dossier courant,
# que curl lit aussi, puisque smoke_fetch n'en change pas entre le jugement et l'appel (CR17-10) : un `cd` entre les deux la casserait.
_smoke_writes_on_call_file() {
  local _smoke_dir _smoke_known
  if [[ "$1" == - ]]; then
    return 1
  fi
  if [[ "/$1/" == */../* ]]; then
    return 0
  fi
  for _smoke_known in "$SMOKE_AUTH_CONFIG" "$SMOKE_AUTH_DIR" "${SMOKE_AUTH_DATA_FILES[@]}"; do
    if [[ "$1" == "$_smoke_known" || "$1" -ef "$_smoke_known" ]]; then
      return 0
    fi
  done
  case "$1" in
    */*) _smoke_dir="${1%/*}"; [ -n "$_smoke_dir" ] || _smoke_dir=/ ;;
    *) _smoke_dir=. ;;
  esac
  [[ "$_smoke_dir" -ef "$SMOKE_AUTH_DIR" ]]
}

# _smoke_value_touches_file <option> <valeur>
#
# Vrai si la valeur de l'option fait lire ou écrire un fichier local à curl, ce qu'une Basic Auth ne tolère pas (D2 de la quinzième
# relecture et C16-1, C16-4 : le contenu d'un fichier de l'appelant partirait chez l'hôte, ou le fichier `-K` qui porte l'identifiant
# serait lu ou écrasé). Elle pose alors `_smoke_refused_reason` à `reads` ou `writes` :
#   - `-H` et `-d` : une valeur qui commence par `@` (`-H @f` lit des en-têtes, `-d @f` un corps, `@-` l'entrée standard) ;
#   - `--data-binary` : `@<chemin>`, sauf le chemin d'un fichier de smoke_data_file (_smoke_is_data_file) qui existe encore (`-f`,
#     C18-5 : un corps retiré n'est plus un fichier que le smoke tient) ; sans `@`, la valeur est le corps lui-même ;
#   - `-b` : une valeur qui commence par `@` (curl 8.5.0 en lit alors un fichier de cookies, `=` ou non : `-b '@a=b'` lit « a=b »,
#     mesuré), ou sans « = » (un fichier de cookies aussi, `-b ''` comprise) ; `-b 'nom=valeur'` est un cookie ;
#   - `-D` : une valeur sur laquelle curl peut écrire un fichier de l'appel (_smoke_writes_on_call_file), raison `writes`.
# Un blanc avant le `@` n'y change rien pour `-H` et `-d` : mesuré sur curl 8.5.0 (`--libcurl -`), ` @f` est un en-tête ou un corps,
# jamais un fichier. Pour `-b`, ` @a=b` est un cookie, mais ` @f` sans « = » est le fichier de cookies « ␣@f » (mesuré) : le refus du
# « = » manquant le couvre. Les autres options à une valeur (`--connect-timeout`, `--max-time`, `-X`) n'en touchent aucun : faux.
# La valeur n'est jamais imprimée.
_smoke_refused_reason=option
_smoke_value_touches_file() {
  case "$1" in
    -H | -d) [[ "$2" == @* ]] && _smoke_refused_reason=reads ;;
    --data-binary) [[ "$2" == @* ]] && ! { _smoke_is_data_file "${2#@}" && [[ -f "${2#@}" ]]; } && _smoke_refused_reason=reads ;;
    -b) [[ "$2" == @* || "$2" != *=* ]] && _smoke_refused_reason=reads ;;
    -D) _smoke_writes_on_call_file "$2" && _smoke_refused_reason=writes ;;
    *) return 1 ;;
  esac
}

# _smoke_judge_pending_value <option> <valeur> <rang>
#
# Juge la valeur qu'une option admise à une valeur vient de consommer, par _smoke_value_touches_file. Rend 0 si elle est admise ; sinon 1,
# et `_smoke_refused_rank` reçoit le rang de la valeur (`_smoke_refused_reason` est posé par _smoke_value_touches_file). Jamais le texte.
_smoke_judge_pending_value() {
  if _smoke_value_touches_file "$1" "$2"; then
    _smoke_refused_rank="$3"
    return 1
  fi
}

# _smoke_judge_args <élément…>
#
# Juge une liste d'arguments de curl par _smoke_option_arity. Une option admise à une valeur consomme l'élément suivant ; la valeur
# n'est jugée que pour une qui lit ou écrit un fichier local (_smoke_value_touches_file : les autres, un en-tête, un corps, un cookie,
# passent telles quelles, `-H -v` envoie l'en-tête « -v ») ; une option à une valeur sans élément suivant est refusée (sinon elle
# prendrait le `-w` de smoke_fetch pour valeur) ; tout autre élément — une seconde URL, `--`, `-v`, une option inconnue — est
# refusé. Rend 0 si tout est admis ; sinon 1, `_smoke_refused_rank` reçoit le rang (de 1 à n) du premier élément refusé et
# `_smoke_refused_reason` vaut `reads` ou `writes` quand c'est une valeur qui lit ou écrit un fichier (le rang est alors celui de la
# valeur), `option` sinon. Jamais le texte de l'élément : un argument collé à une option courte porterait un secret.
_smoke_refused_rank=0
_smoke_judge_args() {
  local _smoke_rank=0 _smoke_pending=0 _smoke_option="" _smoke_item
  _smoke_refused_rank=0
  _smoke_refused_reason=option
  for _smoke_item in "$@"; do
    _smoke_rank=$((_smoke_rank + 1))
    if [ "$_smoke_pending" -eq 1 ]; then
      _smoke_pending=0
      _smoke_judge_pending_value "$_smoke_option" "$_smoke_item" "$_smoke_rank" || return 1
      continue
    fi
    if ! _smoke_option_arity "$_smoke_item"; then
      _smoke_refused_rank="$_smoke_rank"
      return 1
    fi
    _smoke_pending="$_smoke_arity"
    _smoke_option="$_smoke_item"
  done
  if [ "$_smoke_pending" -eq 1 ]; then
    _smoke_refused_rank="$_smoke_rank"
    return 1
  fi
  return 0
}

# _smoke_say_refused_arg <libellé> <d'où vient l'élément>
#
# Dit sur stderr le refus que _smoke_judge_args vient de rendre, selon `_smoke_refused_reason` : « <libellé> : <d'où> n° <rang> hors
# des options admises avec la Basic Auth — rien de vérifié » pour une option hors de la liste (`option`), « … n° <rang> : une valeur
# qui lit un fichier local est refusée avec la Basic Auth — rien de vérifié » pour la valeur d'une option admise qui en lit un
# (`reads`), et pour une valeur de `-D` que _smoke_writes_on_call_file refuse (`writes`), le texte de la ligne `writes` du `case`
# ci-dessous, le seul endroit de la bibliothèque qui l'écrit (St18-21). Le rang, jamais le texte de la valeur.
_smoke_say_refused_arg() {
  local _smoke_what
  case "$_smoke_refused_reason" in
    reads) _smoke_what="n° $_smoke_refused_rank : une valeur qui lit un fichier local est refusée avec la Basic Auth" ;;
    writes)
      _smoke_what="n° $_smoke_refused_rank : une valeur qui peut écrire sur un fichier de la Basic Auth ou de son corps est refusée" ;;
    *) _smoke_what="n° $_smoke_refused_rank hors des options admises avec la Basic Auth" ;;
  esac
  echo "$1 : $2 $_smoke_what — rien de vérifié" >&2
}

# _smoke_judge_auth_call <libellé> <url> [arguments curl…]
#
# Juge un appel qui porte la Basic Auth (SMOKE_AUTH_CONFIG posé), dans l'ordre : l'URL est en `https://`, chaque élément de
# SMOKE_CURL_OPTS puis chaque argument sont admis (_smoke_judge_args), le fichier `-K` existe. Rend 0 si tout passe ; sinon 2, le refus
# dit sur stderr (_smoke_say_refused_arg pour une option ou une valeur) sans jamais citer la valeur de la Basic Auth ni l'argument
# refusé. Les messages sont décrits dans la docblock de smoke_fetch, qui appelle cette fonction.
_smoke_judge_auth_call() {
  local _smoke_label="$1" _smoke_url="$2"
  shift 2
  if [[ "$_smoke_url" != https://* ]]; then
    echo "$_smoke_label : Basic Auth refusée sur une URL non https:// — rien de vérifié" >&2
    return 2
  fi
  if ! _smoke_judge_args "${SMOKE_CURL_OPTS[@]}"; then
    _smoke_say_refused_arg "$_smoke_label" "SMOKE_CURL_OPTS, élément"
    return 2
  fi
  if ! _smoke_judge_args "$@"; then
    _smoke_say_refused_arg "$_smoke_label" "argument"
    return 2
  fi
  if [ ! -f "$SMOKE_AUTH_CONFIG" ]; then
    echo "$_smoke_label : fichier de la Basic Auth absent — rien de vérifié" >&2
    return 2
  fi
}

# smoke_fetch <libellé> <url> [arguments curl…]
#
# Lance `"$SMOKE_CURL" -q "${SMOKE_CURL_OPTS[@]}" [-K "$SMOKE_AUTH_CONFIG" --proto =https --proto-redir =https --noproxy '*'] "$@"
# -w '\n%{http_code}' "$url"` et pose :
#   SMOKE_STATUS   le code HTTP (la dernière ligne de la sortie) ;
#   SMOKE_BODY     le corps (le reste).
# L'appelant déclare SMOKE_CURL_OPTS (`-sS`, les délais, `-D <fichier>`…) ; SMOKE_CURL
# vaut `curl` s'il est vide. La Basic Auth n'y figure jamais : smoke_fetch lit
# SMOKE_AUTH_CONFIG à chaque appel (St8-12), donc aucun `-K` périmé après un second
# smoke_use_auth_config.
#
# `-q` est le **premier** argument de chaque appel, avec ou sans Basic Auth : curl ne lit alors aucun `.curlrc` (sous
# `CURL_HOME`, `XDG_CONFIG_HOME` ou `HOME`), qui pourrait porter `location-trusted` ou un proxy. Il ne vaut qu'en tête : curl 8.5.0
# (celui du runner) lit le fichier quand `-q` est en second argument (mesuré le 2026-11-01).
#
# Rend 1 (aucune réponse) ou 2 (un refus avant l'appel, ou le fichier de la Basic Auth absent), après l'avoir
# dit sur stderr, sans jamais faire sortir l'appelant. Dans tous les refus, curl n'est pas appelé et la valeur
# de la Basic Auth comme l'argument refusé ne sont jamais cités. Tous les refus qui suivent le premier sont jugés par
# _smoke_judge_auth_call, dans cet ordre : l'URL, SMOKE_CURL_OPTS, `"$@"`, le fichier `-K` :
#   - un `curl` qui n'obtient aucune réponse (réseau, DNS, délai) sort avec son code
#     (6, 7, 28…), hors du contrat 0/1/2 des scripts, si on le laisse à `set -e`, et smoke_fetch rend 1 :
#     « <libellé> : curl a échoué (code <n> : réseau, DNS ou délai) — rien de vérifié » ;
#   - SMOKE_AUTH_CONFIG posé et `url` qui ne commence pas par `https://` : « <libellé> : Basic
#     Auth refusée sur une URL non https:// — rien de vérifié ». curl envoie `Authorization:
#     Basic` dès la première requête, quel que soit le schéma : l'identifiant partirait en clair
#     (C11-1) ;
#   - SMOKE_AUTH_CONFIG posé et un argument de `"$@"` hors de la liste d'autorisation (_smoke_option_arity ; une seconde URL
#     en fait partie : la seule URL est celle que cette fonction juge) : « <libellé> : argument n° <rang> hors des options
#     admises avec la Basic Auth — rien de vérifié » (le rang dans `"$@"`, de 1 à n, jamais le texte) ;
#   - SMOKE_AUTH_CONFIG posé et la valeur d'une option admise qui lit un fichier local (_smoke_value_touches_file : `-H @f`, `-d @f`,
#     `--data-binary @<autre qu'un fichier présent de smoke_data_file>`, `-b @f` ou `-b` sans « = ») : « <libellé> : argument n° <rang> :
#     une valeur qui lit un fichier local est refusée avec la Basic Auth — rien de vérifié » (le rang de la valeur, jamais son texte) ;
#   - SMOKE_AUTH_CONFIG posé et une valeur de `-D` que _smoke_writes_on_call_file refuse : « <libellé> : argument n° <rang> : <texte de
#     la raison `writes`, voir _smoke_say_refused_arg> — rien de vérifié » ;
#   - SMOKE_AUTH_CONFIG posé et un élément de SMOKE_CURL_OPTS hors de la même liste, ou valeur qui lit ou écrit un fichier : « <libellé> :
#     SMOKE_CURL_OPTS, élément n° <rang> hors des options admises avec la Basic Auth — rien de vérifié » (ou « … : une valeur qui
#     lit un fichier local est refusée … », ou le texte de `writes`). Il part avec le `-K` comme `"$@"` ;
#   - SMOKE_AUTH_CONFIG posé mais son fichier disparu : « <libellé> : fichier de la
#     Basic Auth absent — rien de vérifié » (sans `-K`, curl lirait le 401 de la préprod comme un résultat).
# Sans Basic Auth, rien de tout cela n'est jugé (la prod garde sa liberté : sans identifiant, rien ne fuit).
# L'appel qui part avec la Basic Auth porte `--proto =https`, `--proto-redir =https` et `--noproxy '*'` après le `-K` : `--proto` borne
# aussi la première requête, donc une URL sans schéma (`hôte/chemin`, que curl devine en `http://`) est refusée par curl lui-même ;
# `--proto-redir` borne les redirections ; `--noproxy '*'` (mesuré sur curl 8.5.0 : `CURLOPT_NOPROXY, "*"`) écarte tout proxy, y
# compris celui d'une variable que l'appelant exporte après smoke_use_auth_config (C15-4). Aucune option de l'appelant ne peut les
# redonner : la liste ne les admet pas.
# L'appelant : `smoke_fetch … || exit "$?"`.
smoke_fetch() {
  local _smoke_label="$1" _smoke_url="$2" _smoke_response _smoke_rc _smoke_auth=()
  shift 2
  if [ -n "$SMOKE_AUTH_CONFIG" ]; then
    _smoke_judge_auth_call "$_smoke_label" "$_smoke_url" "$@" || return "$?"
    _smoke_auth=(-K "$SMOKE_AUTH_CONFIG" --proto "=https" --proto-redir "=https" --noproxy '*')
  fi
  _smoke_response="$("${SMOKE_CURL:-curl}" -q "${SMOKE_CURL_OPTS[@]}" "${_smoke_auth[@]}" "$@" -w '\n%{http_code}' "$_smoke_url")" || {
    _smoke_rc=$?
    echo "$_smoke_label : curl a échoué (code $_smoke_rc : réseau, DNS ou délai) — rien de vérifié" >&2
    return 1
  }
  # Lues par l'appelant, pas par la bibliothèque.
  # shellcheck disable=SC2034
  SMOKE_STATUS="${_smoke_response##*$'\n'}"
  # shellcheck disable=SC2034
  SMOKE_BODY="${_smoke_response%$'\n'*}"
}
