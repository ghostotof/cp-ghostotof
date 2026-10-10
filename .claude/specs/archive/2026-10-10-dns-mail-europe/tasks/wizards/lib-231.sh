# shellcheck shell=bash
# shellcheck disable=SC2034  # les constantes servent aux scripts qui sourcent ce fichier
#
# Helpers partagés par les trois wizards de l'issue #231 (DNS + messagerie en
# France). Sourcé sous le marqueur STAGES, après la bibliothèque du gabarit :
# il peut donc utiliser say/step/note/warn/confirm.
#
# Constantes du chantier — toutes publiques (rien ici n'est un secret).

DOMAIN="cp-ghostotof.com"
TEM_SPF_INCLUDE="include:_spf.tem.scaleway.com"
OLD_NS=(dion.ns.cloudflare.com jade.ns.cloudflare.com)
NEW_NS=(ns0.dom.scw.cloud ns1.dom.scw.cloud)

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Le fichier d'état remplace le `.env` par défaut du gabarit : le `.env` de la
# racine du dépôt pilote les images Docker, on n'y écrit jamais depuis ici.
ENV_FILE="$HERE/dns-mail-europe.state"
SNAP_DIR="$HERE/snapshots"

# tem_domain champ — lit un champ du domaine Transactional Email de $DOMAIN
# (`id`, `project_id`…) au lieu de l'écrire en dur. Version archivée : les
# scripts exécutés pendant le chantier portaient l'id du domaine TEM et l'id du
# projet Scaleway (qui est aussi le sélecteur DKIM de TEM) en constantes ; ils
# sont retirés du dépôt public et relus ici à la demande. Appel paresseux, pour
# que need_tools vérifie scw avant le premier usage.
tem_domain() {
  scw tem domain list name="$DOMAIN" -o json \
    | python3 -c 'import json,sys; print(json.load(sys.stdin)[0][sys.argv[1]])' "$1"
}

# need_tools cmd... — arrête net si un outil manque.
need_tools() {
  local missing=()
  for t in "$@"; do command -v "$t" >/dev/null 2>&1 || missing+=("$t"); done
  if (( ${#missing[@]} )); then
    warn "outils manquants : ${missing[*]}"; exit 1
  fi
}

# run_scw "description" args... — affiche la commande scw, demande confirmation,
# l'exécute. Refus = étape sautée (pas d'arrêt), pour pouvoir reprendre à la main.
run_scw() {
  local desc="$1"; shift
  printf '\n  %s%s%s\n' "$BOLD" "$desc" "$RESET"
  printf '  %s$ scw %s%s\n' "$DIM" "$*" "$RESET"
  if confirm "Exécuter ?"; then
    if scw "$@" >/dev/null; then
      printf '  %s✓ fait%s\n' "$GREEN" "$RESET"
    else
      warn "la commande a échoué — corrige à la main puis continue"
      SKIPPED+=("scw $*")
    fi
  else
    note "sautée"
    SKIPPED+=("scw $*")
  fi
}

# show_zone [type] — la zone Scaleway telle qu'elle est maintenant. Pour un MX,
# `data` contient déjà la priorité ("10 mx1.example.").
show_zone() {
  printf '\n  %sZone Scaleway %s%s\n' "$DIM" "$DOMAIN" "$RESET"
  scw dns record list "$DOMAIN" ${1:+type="$1"} -o json | python3 -c '
import json, sys
for r in sorted(json.load(sys.stdin), key=lambda r: (r["name"], r["type"])):
    print("    %-50s %-5s %s" % (r["name"] or "@", r["type"], r["data"][:70]))
'
}

# record_exists TYPE DATA [NAME] — vrai si la zone Scaleway contient exactement
# cet enregistrement. DATA se compare sans les guillemets que l'API met autour
# d'un TXT ; pour un MX, DATA est "<priorité> <hôte>." comme l'API le stocke.
record_exists() {
  local type="$1" data="$2" name="${3:-}"
  scw dns record list "$DOMAIN" type="$type" -o json | python3 -c '
import json, sys
want_type, want_data, want_name = sys.argv[1:4]
for r in json.load(sys.stdin):
    if r["type"] == want_type and r["name"] == want_name and r["data"].strip("\"") == want_data:
        sys.exit(0)
sys.exit(1)' "$type" "$data" "$name"
}

# expect_record present|absent TYPE DATA [NAME] — vérifie l'état réel de la zone
# après une écriture. Indispensable : `scw dns record delete` sur un enregistrement
# qui ne correspond pas exactement répond OK sans rien supprimer (constaté sur un MX
# désigné sans sa priorité).
expect_record() {
  local want="$1"; shift
  local label="$1 ${3:+$3.}$DOMAIN → $2"
  if record_exists "$@"; then
    if [[ "$want" == present ]]; then printf '  %s✓ présent%s  %s\n' "$GREEN" "$RESET" "$label"
    else warn "TOUJOURS PRÉSENT : $label"; SKIPPED+=("supprimer à la main : $label"); fi
  else
    if [[ "$want" == absent ]]; then printf '  %s✓ absent%s   %s\n' "$GREEN" "$RESET" "$label"
    else warn "ABSENT : $label"; SKIPPED+=("ajouter à la main : $label"); fi
  fi
}

# ensure_record TYPE DATA NAME COMMENT [PRIORITY] — ajoute l'enregistrement s'il
# manque, ne fait rien s'il est déjà là (une reprise du wizard ne doit pas
# retenter un ajout). DATA est la forme *stockée* (pour MX/SRV : priorité comprise) ;
# PRIORITY est passé à part à `add`, et DATA_ADD est ce qu'on lui donne en data.
ensure_record() {
  local type="$1" data="$2" name="$3" comment="$4" priority="${5:-}" data_add="$2"
  [[ -n "$priority" ]] && data_add="${data#"$priority" }"
  if record_exists "$type" "$data" "$name"; then
    printf '  %s✓ déjà présent%s  %s %s → %s\n' "$GREEN" "$RESET" "$type" "${name:-@}" "$data"; return
  fi
  run_scw "Ajouter $type ${name:-@} → $data" dns record add "$DOMAIN" type="$type" \
    ${name:+name="$name"} data="$data_add" ${priority:+priority="$priority"} comment="$comment"
  expect_record present "$type" "$data" "$name"
}

# remove_record TYPE DATA NAME — supprime l'enregistrement s'il est là, ne fait
# rien sinon. DATA est la forme stockée (priorité comprise pour MX/SRV).
remove_record() {
  local type="$1" data="$2" name="${3:-}"
  if ! record_exists "$type" "$data" "$name"; then
    printf '  %s✓ déjà absent%s   %s %s → %s\n' "$GREEN" "$RESET" "$type" "${name:-@}" "$data"; return
  fi
  run_scw "Retirer $type ${name:-@} → $data" dns record delete "$DOMAIN" type="$type" \
    ${name:+name="$name"} data="$data"
  expect_record absent "$type" "$data" "$name"
}

# relative_name FQDN-ou-nom — « ovh-zimbra-x.cp-ghostotof.com » → « ovh-zimbra-x »,
# « ovh-zimbra-x » inchangé, « cp-ghostotof.com » → vide (apex). OVH affiche
# les noms en FQDN, la zone Scaleway les veut relatifs.
relative_name() {
  local n="${1%.}"
  n="${n%".$DOMAIN"}"; [[ "$n" == "$DOMAIN" ]] && n=""
  printf '%s' "$n"
}

# spf_mechanisms "v=spf1 include:x ~all" → "include:x" : ne garde que les
# mécanismes, sans le préfixe v=spf1 ni le qualificateur all final, pour
# pouvoir combiner le SPF d'OVH et celui de TEM en un seul enregistrement.
spf_mechanisms() {
  printf '%s' "$1" | tr -d '"' | tr -s ' ' '\n' \
    | grep -viE '^(v=spf1|[-~+?]?all)$' | tr '\n' ' ' | sed 's/^ *//; s/ *$//'
}

# remove_other_spf SPF — retire tout TXT v=spf1 de l'apex autre que SPF : deux
# SPF sur un même nom sont un permerror, et une saisie maladroite (l'enregistrement
# OVH complet collé dans le champ « include ») avait produit un doublon bancal.
remove_other_spf() {
  local keep="$1" other others=()
  # La liste est lue d'abord, la boucle vient après : un `while read < <(…)`
  # redirigerait l'entrée standard du corps de la boucle, et le [y/N] de
  # confirmation lirait la liste au lieu du clavier (réponse vide = sauté).
  mapfile -t others < <(scw dns record list "$DOMAIN" type=TXT -o json | python3 -c '
import json, sys
for r in json.load(sys.stdin):
    d = r["data"].strip("\"")
    if r["name"] == "" and d.lower().startswith("v=spf1"):
        print(d)')
  for other in "${others[@]}"; do
    [[ -n "$other" && "$other" != "$keep" ]] || continue
    warn "SPF parasite à l'apex : $other"
    remove_record TXT "$other" ""
  done
}

# fqdn_dot nom — garantit le point final d'une cible (CNAME, MX, SRV) : l'API
# le stocke toujours, et record_exists compare à l'exact.
fqdn_dot() { local n="$1"; [[ "$n" == *. ]] && printf '%s' "$n" || printf '%s.' "$n"; }

# snapshot_zones — sauvegarde datée de la zone servie (dig chez Cloudflare) et
# de la zone Scaleway (export brut + JSON). Le point de retour de tout le chantier.
snapshot_zones() {
  local stamp selector; stamp=$(date +%Y%m%dT%H%M%S)
  selector=$(tem_domain project_id)
  mkdir -p "$SNAP_DIR"
  {
    for t in NS SOA A AAAA MX TXT CAA; do
      printf '%s %s\n' "$DOMAIN" "$t"; dig +short "@${OLD_NS[0]}" "$DOMAIN" "$t" | sed 's/^/  /'
    done
    for n in www preprod _dmarc "$selector._domainkey"; do
      for t in A CNAME TXT; do
        printf '%s.%s %s\n' "$n" "$DOMAIN" "$t"; dig +short "@${OLD_NS[0]}" "$n.$DOMAIN" "$t" | sed 's/^/  /'
      done
    done
  } > "$SNAP_DIR/cloudflare-$stamp.txt"
  scw dns zone export "$DOMAIN" > "$SNAP_DIR/scaleway-$stamp.zone" 2>/dev/null || true
  scw dns record list "$DOMAIN" -o json > "$SNAP_DIR/scaleway-$stamp.json"
  note "snapshots écrits dans $SNAP_DIR (cloudflare-$stamp.txt, scaleway-$stamp.{zone,json})"
}

# _answers NS name type — réponses triées d'un serveur faisant autorité. Les
# TXT longs (DKIM) sont découpés en chaînes de 255 octets à des endroits qui
# diffèrent d'un serveur à l'autre : on recolle les morceaux avant de comparer.
_answers() { dig +short "@$1" "$2" "$3" 2>/dev/null | sed 's/" "//g' | sort || true; }

# compare_zones — enregistrement par enregistrement, ancien NS contre nouveau NS.
# Imprime chaque écart ; retourne le nombre d'écarts.
compare_zones() {
  local diffs=0 selector pairs
  selector=$(tem_domain project_id)
  pairs=(
    "$DOMAIN A" "$DOMAIN AAAA" "$DOMAIN MX" "$DOMAIN TXT" "$DOMAIN CAA"
    "www.$DOMAIN CNAME" "preprod.$DOMAIN A" "_dmarc.$DOMAIN TXT"
    "$selector._domainkey.$DOMAIN TXT"
  )
  local extra
  for extra in "${@}"; do pairs+=("$extra"); done
  printf '\n  %s%-58s %s%s\n' "$DIM" "enregistrement" "état" "$RESET"
  local p name type old new
  for p in "${pairs[@]}"; do
    name=${p% *}; type=${p#* }
    old=$(_answers "${OLD_NS[0]}" "$name" "$type")
    new=$(_answers "${NEW_NS[0]}" "$name" "$type")
    if [[ "$old" == "$new" ]]; then
      printf '  %s%-58s%s %s=%s\n' "$DIM" "$name $type" "$RESET" "$GREEN" "$RESET"
    else
      diffs=$((diffs + 1))
      printf '  %s%-58s%s %s≠%s\n' "$BOLD" "$name $type" "$RESET" "$YELLOW" "$RESET"
      printf '%s\n' "$old" | sed "s/^/      ${OLD_NS[0]%%.*}: /"
      printf '%s\n' "$new" | sed "s/^/      ${NEW_NS[0]%%.*}: /"
    fi
  done
  return "$diffs"
}

# registry_ns — la délégation vue du registre .com. Une délégation arrive dans la
# section AUTHORITY, que `dig +short` n'affiche pas : on la lit explicitement.
registry_ns() {
  dig +noall +authority "@a.gtld-servers.net" "$DOMAIN" NS 2>/dev/null \
    | awk '$4 == "NS" { print $5 }' | sort | tr '\n' ' '
}

# pipeline_running — vrai si un run du workflow Pipeline est en cours sur GitHub.
pipeline_running() {
  local n
  n=$(gh run list --workflow Pipeline --status in_progress --json databaseId --jq 'length' 2>/dev/null || echo "?")
  [[ "$n" != "0" ]]
}

# http_check URL — code HTTP, émetteur du certificat, nom résolu.
http_check() {
  local url="$1" code issuer
  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$url" 2>&1 || true)
  issuer=$(curl -sS -o /dev/null -w '%{certs}' --max-time 15 "$url" 2>/dev/null \
    | grep -m1 -oE 'Issuer:.*' || echo 'Issuer: ?')
  printf '    %-40s HTTP %s  %s\n' "$url" "$code" "$issuer"
}
