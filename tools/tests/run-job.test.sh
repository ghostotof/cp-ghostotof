#!/usr/bin/env bash
#
# run-job.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/run-job.sh (issue #488) : un faux `kubectl`
# (RUN_JOB_KUBECTL) note chacun de ses appels et le manifeste qu'on lui
# applique ; un faux tools/wait-rollout.sh (RUN_JOB_WAIT) réussit ou échoue
# selon FAKE_WAIT_EXIT.
#
# Ce qui est pincé :
#   - le nom du Job vient du `metadata.name` du manifeste, commentaire de fin
#     de ligne ôté ; un nom hors DNS-1123 (`--all`), un second objet ou un
#     objet qui n'est pas un Job sont refusés ;
#   - seuls l'image et les placeholders du manifeste passés en NOM=valeur
#     sont substitués ; une valeur vide ou porteuse de YAML (guillemet, fin de
#     ligne) est refusée sans être citée ; le reste du manifeste passe tel
#     quel ; un `${…}` resté sans valeur arrête tout ;
#   - un outil requis absent (jq pour wait-rollout.sh) arrête tout ;
#   - tous ces refus sortent en 2 AVANT le moindre appel à kubectl
#     (fail-closed) ; un delete ou un apply refusé sort en 3, sans attente ;
#     un Job qui n'aboutit pas sort en 1 — trois causes que l'appelant
#     distingue (smoke-messenger-probe.sh) ;
#   - le Job précédent est supprimé (`job/NOM`) avant l'apply ; l'attente vise
#     le Job avec le délai demandé ; ses 200 dernières lignes de journaux, de
#     tous ses conteneurs, sortent sur stdout en cas de succès, sur stderr
#     après un `::error::` en cas d'échec ;
#   - pipeline.yml ne réécrit pas la séquence en ligne.
#
# Usage :  tools/tests/run-job.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$TOOLS/run-job.sh"
K8S_BASE="$TOOLS/../k8s/base"
PIPELINE="$TOOLS/../.github/workflows/pipeline.yml"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

cat > "$TMP/kubectl" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_DIR/args"
case " $* " in
  *" delete "*) exit "${FAKE_DELETE_EXIT:-0}" ;;
  *" apply -f - "*) cat > "$FAKE_DIR/applied.yaml"; exit "${FAKE_APPLY_EXIT:-0}" ;;
  *" logs "*) echo "[php-fpm] journal du Job" ;;
esac
FAKE
cat > "$TMP/wait" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_DIR/wait-args"
exit "${FAKE_WAIT_EXIT:-0}"
FAKE
chmod +x "$TMP/kubectl" "$TMP/wait"

# Manifeste de travail : un `name:` de label précède le vrai `metadata.name`,
# qui porte un commentaire de fin de ligne ; un `name:` de conteneur le suit ;
# une variable shell sans accolades et une référence Kubernetes `$(…)` doivent
# traverser la substitution intactes.
cat > "$TMP/demo-job.yaml" <<'YAML'
# Un commentaire qui cite ${BACKEND_IMAGE}, comme les vrais manifestes.
apiVersion: batch/v1
kind: Job
metadata:
  labels:
    app.kubernetes.io/name: pas-ce-nom
  name: demo-job  # le nom que delete, wait et logs doivent viser
spec:
  template:
    spec:
      containers:
        - name: php-fpm
          image: ${BACKEND_IMAGE}
          command: ["sh", "-c", "echo $HOME $(POD_NAME)"]
          env:
            - name: NEW_ACCOUNT_USERNAME
              value: '${CPG_USERNAME}'
YAML

# manifest <nom de fichier> <contenu> — un manifeste de cas limite.
manifest() { printf '%s\n' "$2" > "$TMP/$1"; printf '%s' "$TMP/$1"; }

# run <dossier> [arguments du script…] — les variables d'environnement
# préfixées à l'appel (FAKE_WAIT_EXIT…) traversent jusqu'aux faux binaires.
# Le code de sortie est rangé dans <dossier>/status.
run() {
  local dir="$1"; shift
  mkdir -p "$dir"
  : > "$dir/args"; : > "$dir/wait-args"
  local status=0
  FAKE_DIR="$dir" RUN_JOB_KUBECTL="$TMP/kubectl" RUN_JOB_WAIT="$TMP/wait" \
    "$SCRIPT" "$@" > "$dir/out" 2> "$dir/err" || status=$?
  echo "$status" > "$dir/status"
}

# refused <libellé> <dossier> <motif attendu sur stderr> — sorti en 2, en
# disant pourquoi, sans le moindre appel à kubectl.
refused() {
  local label="$1" dir="$2" pattern="$3"
  if [ "$(cat "$dir/status")" = 2 ] && grep -q -- "$pattern" "$dir/err" && [ ! -s "$dir/args" ]; then
    pass "$label : refusé en 2, avant tout appel à kubectl"
  else
    fail "$label : refusé en 2, avant tout appel à kubectl" "sortie $(cat "$dir/status") ; $(cat "$dir/err" "$dir/args")"
  fi
}

IMAGE=ghcr.io/example/backend:1.2.3-abcdef0-preprod

echo "run-job.sh"

# --- Le Job se termine ------------------------------------------------------
ok="$TMP/ok"
BACKEND_IMAGE=ghcr.io/example/backend:0.0.0-mauvaise run "$ok" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 CPG_USERNAME=compte-de-test
if [ "$(cat "$ok/status")" = 0 ]; then
  pass "un Job réussi sort en 0"
else
  fail "un Job réussi sort en 0" "$(cat "$ok/err")"
fi

delete_line=$(grep -n -x -- '-n preprod delete job/demo-job --ignore-not-found' "$ok/args" | cut -d: -f1 || true)
apply_line=$(grep -n -x -- '-n preprod apply -f -' "$ok/args" | cut -d: -f1 || true)
if [ -n "$delete_line" ] && [ -n "$apply_line" ] && [ "$delete_line" -lt "$apply_line" ]; then
  pass "le Job précédent, nommé d'après metadata.name sans son commentaire, est supprimé avant l'apply"
else
  fail "le Job précédent, nommé d'après metadata.name sans son commentaire, est supprimé avant l'apply" "$(cat "$ok/args")"
fi

if grep -qx "          image: $IMAGE" "$ok/applied.yaml" 2>/dev/null; then
  pass "l'image passée en argument est substituée, pas celle de l'environnement"
else
  fail "l'image passée en argument est substituée, pas celle de l'environnement" "$(grep -sn 'image:' "$ok/applied.yaml")"
fi

if grep -qx "              value: 'compte-de-test'" "$ok/applied.yaml" 2>/dev/null; then
  pass "une variable nommée en argument est substituée"
else
  fail "une variable nommée en argument est substituée" "$(grep -sn 'value:' "$ok/applied.yaml")"
fi

if grep -qF 'echo $HOME $(POD_NAME)' "$ok/applied.yaml" 2>/dev/null; then
  pass "les autres \$ du manifeste passent intacts"
else
  fail "les autres \$ du manifeste passent intacts" "$(grep -sn 'command:' "$ok/applied.yaml")"
fi

if grep -qx 'preprod job/demo-job --timeout 240' "$ok/wait-args"; then
  pass "l'attente vise le Job, avec le délai demandé"
else
  fail "l'attente vise le Job, avec le délai demandé" "$(cat "$ok/wait-args")"
fi

if grep -qx -- '-n preprod logs job/demo-job --all-containers --prefix --tail=200' "$ok/args" \
    && grep -q 'journal du Job' "$ok/out"; then
  pass "un Job réussi imprime ses 200 dernières lignes, tous conteneurs, sur stdout"
else
  fail "un Job réussi imprime ses 200 dernières lignes, tous conteneurs, sur stdout" "$(cat "$ok/args" "$ok/out")"
fi

# --- Les vrais manifestes : le nom lu est celui du Job ----------------------
for pair in migrate-job.yaml:backend-migrate seed-job.yaml:backend-seed \
            messenger-probe-job.yaml:backend-messenger-probe; do
  manifest_file="${pair%%:*}"; job="${pair#*:}"
  real="$TMP/real-$job"
  run "$real" prod "$K8S_BASE/$manifest_file" "$IMAGE" 300
  if [ "$(cat "$real/status")" = 0 ] \
      && grep -qx -- "-n prod delete job/$job --ignore-not-found" "$real/args" \
      && grep -qx "prod job/$job --timeout 300" "$real/wait-args" \
      && ! grep -q '\${' "$real/applied.yaml"; then
    pass "$manifest_file : Job $job, plus aucun placeholder"
  else
    fail "$manifest_file : Job $job, plus aucun placeholder" "$(cat "$real/err" "$real/args" "$real/wait-args")"
  fi
done

# Issue #521 : les Jobs de maintenance de Messenger, l'Id de `failed` en paramètre.
stop="$TMP/real-stop-workers"
run "$stop" prod "$K8S_BASE/messenger-stop-workers-job.yaml" "$IMAGE" 300
if [ "$(cat "$stop/status")" = 0 ] \
    && grep -qx -- '-n prod delete job/backend-messenger-stop-workers --ignore-not-found' "$stop/args" \
    && ! grep -q '\${' "$stop/applied.yaml"; then
  pass "messenger-stop-workers-job.yaml : Job backend-messenger-stop-workers, plus aucun placeholder"
else
  fail "messenger-stop-workers-job.yaml : Job backend-messenger-stop-workers, plus aucun placeholder" "$(cat "$stop/err" "$stop/args")"
fi

for action in retry remove; do
  failed="$TMP/real-failed-$action"
  run "$failed" preprod "$K8S_BASE/messenger-failed-$action-job.yaml" "$IMAGE" 300 FAILED_MESSAGE_ID=42
  if [ "$(cat "$failed/status")" = 0 ] \
      && grep -qx -- "-n preprod delete job/backend-messenger-failed-$action --ignore-not-found" "$failed/args" \
      && grep -qx "              value: '42'" "$failed/applied.yaml" \
      && ! grep -q '\${' "$failed/applied.yaml"; then
    pass "messenger-failed-$action-job.yaml : l'Id substitué, plus aucun placeholder"
  else
    fail "messenger-failed-$action-job.yaml : l'Id substitué, plus aucun placeholder" "$(cat "$failed/err" "$failed/args")"
  fi
  run "$TMP/missing-$action" preprod "$K8S_BASE/messenger-failed-$action-job.yaml" "$IMAGE" 300
  refused "messenger-failed-$action-job.yaml sans Id" "$TMP/missing-$action" 'FAILED_MESSAGE_ID'
done

user="$TMP/real-create-user"
run "$user" preprod "$K8S_BASE/create-user-job.yaml" "$IMAGE" 180 CPG_USERNAME=compte-de-test
if [ "$(cat "$user/status")" = 0 ] \
    && grep -qx -- '-n preprod delete job/backend-create-user --ignore-not-found' "$user/args" \
    && ! grep -q '\${' "$user/applied.yaml"; then
  pass "create-user-job.yaml : Job backend-create-user, plus aucun placeholder"
else
  fail "create-user-job.yaml : Job backend-create-user, plus aucun placeholder" "$(cat "$user/err" "$user/args")"
fi

# --- Fail-closed : refus en 2, rien n'atteint le cluster --------------------
# Un placeholder sans valeur partirait littéral dans la spec du pod
# (`InvalidImageName`, ou un nom de compte « ${CPG_USERNAME} »).
run "$TMP/missing" preprod "$TMP/demo-job.yaml" "$IMAGE" 240
refused "un placeholder sans valeur, nommé" "$TMP/missing" 'CPG_USERNAME'

# envsubst ne traite pas la forme à défaut : elle partirait telle quelle.
with_default="$(manifest default.yaml 'kind: Job
metadata:
  name: demo-job
spec:
  image: ${BACKEND_IMAGE:-backend}')"
run "$TMP/default" preprod "$with_default" "$IMAGE" 240
refused "un \${NOM:-défaut} restant" "$TMP/default" 'BACKEND_IMAGE:-backend'

for bad in 'cpg_username=x' 'CPG_USERNAME' '1VAR=x'; do
  run "$TMP/badvar-$bad" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 "$bad"
  refused "« $bad », pas de la forme NOM=valeur" "$TMP/badvar-$bad" 'NOM=valeur'
done

# Seuls les placeholders du manifeste se passent : PATH changerait le
# binaire d'envsubst, BACKEND_IMAGE doublerait l'argument image.
for name in PATH BACKEND_IMAGE AUTRE; do
  run "$TMP/notplaceholder-$name" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 CPG_USERNAME=x "$name=/tmp/x"
  refused "$name, qui n'est pas un placeholder à passer" "$TMP/notplaceholder-$name" "$name"
done

# Une valeur est insérée telle quelle dans le YAML : vide, elle contourne la
# garde des placeholders ; un guillemet ou une fin de ligne y injectent des
# clés. Le refus ne la cite jamais (un collage malheureux).
for case_name in vide guillemet fin-de-ligne espace; do
  case "$case_name" in
    vide)         value='' ;;
    guillemet)    value="sentinelle'x" ;;
    fin-de-ligne) value=$'sentinelle\n          image: evil' ;;
    espace)       value='sentinelle x' ;;
  esac
  run "$TMP/value-$case_name" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 "CPG_USERNAME=$value"
  refused "une valeur $case_name" "$TMP/value-$case_name" 'CPG_USERNAME'
  if grep -q sentinelle "$TMP/value-$case_name/err"; then
    fail "le refus d'une valeur $case_name ne la cite pas" "$(cat "$TMP/value-$case_name/err")"
  fi
done

noname="$(manifest noname.yaml 'kind: Job
metadata:
  labels:
    a: b
spec: {}')"
run "$TMP/noname" preprod "$noname" "$IMAGE" 240
refused "un manifeste sans metadata.name" "$TMP/noname" 'metadata.name'

# Un nom hors DNS-1123 serait lu comme une option : `delete job --all`.
option_name="$(manifest option.yaml 'kind: Job
metadata:
  name: --all')"
run "$TMP/option" preprod "$option_name" "$IMAGE" 240
refused "un nom de Job hors DNS-1123 (« --all »)" "$TMP/option" 'DNS-1123'

# Deux objets : le nom lu ne serait pas forcément celui du Job appliqué.
two_docs="$(manifest two.yaml 'kind: ConfigMap
metadata:
  name: demo-job
---
kind: Job
metadata:
  name: autre')"
run "$TMP/two" preprod "$two_docs" "$IMAGE" 240
refused "un manifeste à deux objets" "$TMP/two" 'un seul objet'

not_a_job="$(manifest cm.yaml 'kind: ConfigMap
metadata:
  name: demo-job')"
run "$TMP/notjob" preprod "$not_a_job" "$IMAGE" 240
refused "un objet qui n'est pas un Job" "$TMP/notjob" 'kind: Job'

run "$TMP/usage" preprod "$TMP/demo-job.yaml" "$IMAGE"
refused "un argument manquant" "$TMP/usage" 'usage'

# wait-rollout.sh exige jq : absent, il échouerait APRÈS l'apply, sur un Job
# qui tourne (une création de compte annoncée en échec, recommencée).
nojq="$TMP/nojq-bin"
mkdir -p "$nojq"
for tool in bash env awk grep sed sort tr envsubst mktemp rm dirname cat; do
  ln -s "$(command -v "$tool")" "$nojq/$tool"
done
mkdir -p "$TMP/nojq"; : > "$TMP/nojq/args"; : > "$TMP/nojq/wait-args"
status=0
PATH="$nojq" FAKE_DIR="$TMP/nojq" RUN_JOB_KUBECTL="$TMP/kubectl" RUN_JOB_WAIT="$TMP/wait" \
  "$nojq/bash" "$SCRIPT" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 CPG_USERNAME=x \
  > "$TMP/nojq/out" 2> "$TMP/nojq/err" || status=$?
echo "$status" > "$TMP/nojq/status"
refused "jq absent" "$TMP/nojq" 'jq'

# --- Le cluster refuse : sortie 3, sans attente -----------------------------
for verb in delete apply; do
  upper=$(printf '%s' "$verb" | tr '[:lower:]' '[:upper:]')
  dir="$TMP/refused-$verb"
  mkdir -p "$dir"; : > "$dir/args"; : > "$dir/wait-args"
  status=0
  env "FAKE_${upper}_EXIT=1" FAKE_DIR="$dir" RUN_JOB_KUBECTL="$TMP/kubectl" RUN_JOB_WAIT="$TMP/wait" \
    "$SCRIPT" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 CPG_USERNAME=x > "$dir/out" 2> "$dir/err" || status=$?
  if [ "$status" = 3 ] && grep -q "::error::.*$verb" "$dir/err" && [ ! -s "$dir/wait-args" ]; then
    pass "un $verb refusé sort en 3, en le disant, sans attendre"
  else
    fail "un $verb refusé sort en 3, en le disant, sans attendre" "sortie $status ; $(cat "$dir/err" "$dir/wait-args")"
  fi
done

# --- Le Job échoue : sortie 1 -----------------------------------------------
ko="$TMP/ko"
FAKE_WAIT_EXIT=1 run "$ko" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 CPG_USERNAME=x
if [ "$(cat "$ko/status")" = 1 ]; then
  pass "un Job en échec sort en 1"
else
  fail "un Job en échec sort en 1" "sortie $(cat "$ko/status")"
fi
if grep -q '::error::.*demo-job' "$ko/err" \
    && grep -qx -- '-n preprod logs job/demo-job --all-containers --prefix --tail=200' "$ko/args" \
    && grep -q 'journal du Job' "$ko/err"; then
  pass "l'échec nomme le Job et imprime ses 200 dernières lignes, tous conteneurs, sur stderr"
else
  fail "l'échec nomme le Job et imprime ses 200 dernières lignes, tous conteneurs, sur stderr" "$(cat "$ko/err" "$ko/args")"
fi

if grep -qi 'secret' "$ok/args" "$ko/args"; then
  fail "aucun Secret n'est lu" "$(grep -i secret "$ok/args" "$ko/args")"
else
  pass "aucun Secret n'est lu"
fi

# --- Une seule implémentation -----------------------------------------------
# Hors commentaires, le pipeline ne supprime ni ne rend aucun Job lui-même :
# une correction de la séquence doit atteindre tous les Jobs (issue #488).
inline="$(grep -nE 'delete job|envsubst' "$PIPELINE" | grep -vE '^[0-9]+:[[:space:]]*#' || true)"
if [ -z "$inline" ]; then
  pass "pipeline.yml ne réécrit pas la séquence en ligne"
else
  fail "pipeline.yml ne réécrit pas la séquence en ligne" "$inline"
fi

if [ "$failures" -ne 0 ]; then
  echo "$failures échec(s)."
  exit 1
fi
echo "Tout est vert."
