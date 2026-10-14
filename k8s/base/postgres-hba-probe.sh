#!/bin/sh
#
# postgres-hba-probe.sh
# ---------------------------------------------------------------------------
# Issue #596 — le pg_hba.conf vu du réseau, depuis un pod du namespace. Joué
# par le Job k8s/base/postgres-hba-probe-job.yaml (copie à l'identique dans
# son `command`, PostgresHbaProbeJobManifestTest) dans `smoke-test-preprod`,
# et dans les deux sens contre l'image épinglée par tools/check-postgres-hba.sh.
#
# Tente une connexion TCP sous le superutilisateur bootstrap, sans mot de
# passe. Le pg_hba.conf du dépôt la refuse par sa règle `reject`, AVANT toute
# authentification (#593) : seul ce refus-là fait sortir en 0. Tout le reste
# sort en 1 en le nommant — une connexion obtenue, une demande de mot de passe
# (le pg_hba.conf de l'image dans PGDATA, qui admet le superutilisateur en
# SCRAM : ce que ramènerait un `rollout undo` de `postgres` ou un overlay qui
# perd `-c hba_file`), un serveur injoignable.
#
# Aucun mot de passe en jeu : `-w` (psql n'en demande jamais), PGPASSFILE
# vers un chemin qui ne peut pas exister (libpq ignore alors le fichier sans
# rien dire ; `/dev/null` lui fait écrire un avertissement), et un PGPASSWORD
# posé est refusé. Ce que la sonde imprime part dans les journaux du
# pipeline : le nom du superutilisateur et celui de la base, tenus dans un
# Secret, y sont masqués.
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

# Remplace chaque occurrence littérale des deux noms (lus dans ENVIRON : ni
# expression régulière, ni séquence d'échappement interprétée).
redact() {
  awk '
    function hide(s, name, mark,    out, i) {
      out = ""
      while ((i = index(s, name)) > 0) {
        out = out substr(s, 1, i - 1) mark
        s = substr(s, i + length(name))
      }
      return out s
    }
    { print hide(hide($0, ENVIRON["PGUSER"], "<superutilisateur>"), ENVIRON["PGDATABASE"], "<base>") }
  '
}

if out="$(psql -X -A -t -w -c 'SELECT 1' 2>&1)"; then
  echo "#596 : ÉCHEC, le superutilisateur est ADMIS par TCP sur $PGHOST : le serveur ne lit pas le pg_hba.conf du dépôt." >&2
  exit 1
fi
out="$(printf '%s\n' "$out" | redact)"
case "$out" in
  *'pg_hba.conf rejects connection'*)
    echo "#596 : refusé par pg_hba.conf, comme attendu — $out"
    exit 0
    ;;
  *'no password supplied'*)
    echo "#596 : ÉCHEC, $PGHOST demande un mot de passe au superutilisateur au lieu de le refuser : il lit le pg_hba.conf de PGDATA, pas celui du dépôt — $out" >&2
    ;;
  *)
    echo "#596 : ÉCHEC, refus « pg_hba.conf rejects connection » attendu de $PGHOST, obtenu — $out" >&2
    ;;
esac
exit 1
