#!/bin/sh
#
# postgres-hba-probe.sh
# ---------------------------------------------------------------------------
# Issue #596 — le pg_hba.conf vu du réseau, depuis un pod du namespace. Joué
# par le Job k8s/base/postgres-hba-probe-job.yaml (copie à l'identique dans
# son `command`, PostgresHbaProbeJobManifestTest) dans `postgres-hba-preprod`,
# et dans les deux sens contre l'image épinglée par tools/check-postgres-hba.sh.
#
# Tente une connexion TCP sous le superutilisateur bootstrap, sans mot de
# passe, sur la base de l'application puis sur `postgres`. Le pg_hba.conf du
# dépôt la refuse par sa règle `reject`, AVANT toute authentification (#593) :
# seul ce refus, sur les deux bases, fait sortir en 0 (dernière ligne
# « #596 : OK »). Une connexion obtenue, une demande de mot de passe (le
# fichier de l'image dans PGDATA, que ramènerait un `rollout undo` de
# `postgres`) ou un serveur resté injoignable sortent en 1, nommés.
#
# Issue #597 : tente aussi le rôle applicatif `cpg_app`, sans mot de passe, sur
# `postgres`. Le fichier du dépôt refuse les bases de l'image à tout rôle,
# avant toute authentification ; une ConfigMap de l'époque #593 (sans ce
# refus) lui demanderait un mot de passe — le superutilisateur, refusé partout
# par les deux fichiers, ne le verrait pas. Une base de l'application nommée
# `postgres` lui demanderait un mot de passe aussi : nommée de même.
#
# Aucun mot de passe en jeu (`-w`, PGPASSFILE sur un chemin impossible,
# PGPASSWORD refusé). La sortie part dans les journaux du pipeline : les noms
# du superutilisateur et de la base, tenus dans un Secret, y sont masqués.
#
# POSIX sh : tourne dans l'image postgres (alpine, BusyBox). Aucune variable
# entre accolades, commentaires compris : tools/run-job.sh passe le manifeste
# du Job dans envsubst et refuse celles qui restent (on écrit `$VAR`).
# Env :  PGHOST, PGUSER (le superutilisateur), PGDATABASE ; PGCONNECT_TIMEOUT
#        (10 s par défaut)
# ---------------------------------------------------------------------------
if [ -z "$PGHOST" ] || [ -z "$PGUSER" ] || [ -z "$PGDATABASE" ]; then
  echo "#596 : PGHOST, PGUSER et PGDATABASE sont requis." >&2
  exit 1
fi
if [ -n "$PGPASSWORD" ]; then
  echo "#596 : PGPASSWORD est posé ; la sonde ne lit aucun mot de passe." >&2
  exit 1
fi
PGPASSFILE=/dev/null/aucun-pgpass
[ -n "$PGCONNECT_TIMEOUT" ] || PGCONNECT_TIMEOUT=10
export PGPASSFILE PGCONNECT_TIMEOUT

# Le superutilisateur doit être refusé sur la base de l'application ET sur
# `postgres`, que l'image crée toujours : une règle qui le rouvrirait sur une
# autre base que celle de l'application passerait sinon. Depuis #597, le refus
# des bases de l'image précède toute règle qui accepte : seule une règle
# insérée avant lui rouvrirait `postgres`.
SECOND_DATABASE=postgres
# Le rôle applicatif, nom public (pas un Secret) : jamais masqué.
APP_ROLE=cpg_app
# Un serveur qui vient de redémarrer (Recreate, PVC neuf : le serveur
# d'initialisation n'écoute que le socket local) refuse ou laisse expirer la
# connexion un moment : quelques essais, rien d'autre n'est réessayé. Au pire
# 4 × PGCONNECT_TIMEOUT (10 s) + 3 × 5 s ≈ 55 s, sur une seule base (la seconde
# n'est pas tentée après un serveur resté injoignable) : sous l'attente de 120 s
# du pipeline, pull de l'image compris.
ATTEMPTS=4
PAUSE=5

# Masque, pour l'affichage seulement, chaque occurrence littérale des noms du
# superutilisateur et de la base (lus dans ENVIRON : ni expression régulière,
# ni séquence d'échappement), tronqués aussi à 63 octets comme le serveur les
# cite. Un seul passage, le nom le plus long d'abord, un seul marqueur : ni la
# partie propre d'un nom qui en contient un autre, ni l'égalité des deux ne
# transparaissent.
redact() {
  awk '
    BEGIN {
      n = 0
      add(ENVIRON["PGUSER"]); add(ENVIRON["PGDATABASE"])
      add(substr(ENVIRON["PGUSER"], 1, 63)); add(substr(ENVIRON["PGDATABASE"], 1, 63))
      # Tri par longueur décroissante (quelques éléments : tri par insertion).
      for (i = 2; i <= n; i++) {
        v = names[i]
        for (j = i - 1; j >= 1 && length(names[j]) < length(v); j--) names[j + 1] = names[j]
        names[j + 1] = v
      }
    }
    function add(name) { if (name != "") names[++n] = name }
    {
      line = $0; out = ""
      while (line != "") {
        hit = 0
        for (k = 1; k <= n; k++) {
          if (substr(line, 1, length(names[k])) == names[k]) { hit = k; break }
        }
        if (hit) { out = out "<masqué>"; line = substr(line, length(names[hit]) + 1) }
        else { out = out substr(line, 1, 1); line = substr(line, 2) }
      }
      print out
    }
  '
}

# attempt BASE [RÔLE] — une connexion TCP sans mot de passe, sous RÔLE ou,
# par défaut, sous le superutilisateur (PGUSER).
# Pose RAW (sortie brute de psql) et VERDICT : admitted, rejected, password,
# unreachable ou other. Les motifs sont cherchés dans la sortie BRUTE : le
# masquage, qui peut toucher un nom recoupant « connection », ne vient
# qu'après, à l'affichage.
attempt() {
  user="$PGUSER"
  [ -z "$2" ] || user="$2"
  if RAW="$(PGUSER="$user" psql -X -A -t -w -d "$1" -c 'SELECT 1' 2>&1)"; then
    VERDICT=admitted
    return
  fi
  case "$RAW" in
    *'pg_hba.conf rejects connection'*) VERDICT=rejected ;;
    *'no password supplied'*) VERDICT=password ;;
    *'Connection refused'*|*'timeout expired'*) VERDICT=unreachable ;;
    *) VERDICT=other ;;
  esac
}

# probe BASE LIBELLÉ [RÔLE] — 0 seulement si RÔLE (par défaut le
# superutilisateur) y est refusé par pg_hba.conf ; sinon 1, en le nommant.
probe() {
  who="le superutilisateur"
  [ -z "$3" ] || who="$3"
  try=1
  attempt "$1" "$3"
  while [ "$VERDICT" = unreachable ] && [ "$try" -lt "$ATTEMPTS" ]; do
    echo "#596 : $PGHOST injoignable ($2), nouvel essai dans $PAUSE s ($try/$ATTEMPTS)."
    sleep "$PAUSE"
    try=$((try + 1))
    attempt "$1" "$3"
  done
  shown="$(printf '%s\n' "$RAW" | redact)"
  case "$VERDICT" in
    rejected)
      echo "#596 : refusé par pg_hba.conf ($2) — $shown"
      return 0
      ;;
    admitted)
      echo "#596 : ÉCHEC ($2), $who est ADMIS par TCP sur $PGHOST : le serveur ne lit pas le pg_hba.conf du dépôt." >&2
      ;;
    password)
      echo "#596 : ÉCHEC ($2), $PGHOST demande un mot de passe à $who au lieu de le refuser : il ne lit pas le pg_hba.conf du dépôt (celui de PGDATA, ou une ConfigMap sans le refus des bases de l'image, #597 ?) — $shown" >&2
      ;;
    unreachable)
      echo "#596 : ÉCHEC ($2), $PGHOST injoignable après $ATTEMPTS essais — $shown" >&2
      ;;
    *)
      echo "#596 : ÉCHEC ($2), refus « pg_hba.conf rejects connection » attendu de $PGHOST, obtenu — $shown" >&2
      ;;
  esac
  return 1
}

status=0
probe "$PGDATABASE" "base de l'application" || status=1
if [ "$VERDICT" = unreachable ]; then
  echo "#596 : base $SECOND_DATABASE non tentée, le serveur est resté injoignable." >&2
else
  if [ "$PGDATABASE" != "$SECOND_DATABASE" ]; then
    probe "$SECOND_DATABASE" "base $SECOND_DATABASE" || status=1
  fi
  probe "$SECOND_DATABASE" "$APP_ROLE, base $SECOND_DATABASE, #597" "$APP_ROLE" || status=1
fi
if [ "$status" -eq 0 ]; then
  echo "#596 : OK, superutilisateur refusé par TCP sur les deux bases, $APP_ROLE sur $SECOND_DATABASE (#597)."
fi
exit "$status"
