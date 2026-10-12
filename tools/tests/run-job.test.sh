#!/usr/bin/env bash
#
# run-job.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/run-job.sh (issue #488) : un faux `kubectl`
# (RUN_JOB_KUBECTL) note chacun de ses appels et le manifeste qu'on lui
# applique ; un faux tools/wait-rollout.sh (RUN_JOB_WAIT) réussit ou échoue
# selon FAKE_WAIT_EXIT.
#
# Ce qui est pincé : le nom du Job vient du `metadata.name` du manifeste ; le
# Job précédent est supprimé avant l'apply ; seules l'image et les variables
# nommées sont substituées, le reste du manifeste passe tel quel ; un
# placeholder resté sans valeur arrête tout avant le moindre appel à kubectl
# (fail-closed) ; l'attente vise le Job avec le délai demandé ; un Job en
# échec fait sortir en 1 en imprimant ses 200 dernières lignes de journaux.
#
# Usage :  tools/tests/run-job.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$TOOLS/run-job.sh"
K8S_BASE="$TOOLS/../k8s/base"
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
  *" apply -f - "*) cat > "$FAKE_DIR/applied.yaml"; exit "${FAKE_APPLY_EXIT:-0}" ;;
  *" logs "*) echo "[ERROR] journal du Job en échec" ;;
esac
FAKE
cat > "$TMP/wait" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_DIR/wait-args"
exit "${FAKE_WAIT_EXIT:-0}"
FAKE
chmod +x "$TMP/kubectl" "$TMP/wait"

# Manifeste de travail : un `name:` de label et un de conteneur précèdent ou
# suivent le vrai `metadata.name`, une variable shell sans accolades et une
# référence Kubernetes `$(…)` doivent traverser la substitution intactes.
cat > "$TMP/demo-job.yaml" <<'YAML'
# Un commentaire qui cite ${BACKEND_IMAGE}, comme les vrais manifestes.
apiVersion: batch/v1
kind: Job
metadata:
  labels:
    app.kubernetes.io/name: pas-ce-nom
  name: demo-job
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

# run <dossier> [arguments du script…] — les variables d'environnement
# préfixées à l'appel (FAKE_WAIT_EXIT…) traversent jusqu'aux faux binaires.
run() {
  local dir="$1"; shift
  mkdir -p "$dir"
  : > "$dir/args"; : > "$dir/wait-args"
  FAKE_DIR="$dir" RUN_JOB_KUBECTL="$TMP/kubectl" RUN_JOB_WAIT="$TMP/wait" \
    "$SCRIPT" "$@" > "$dir/out" 2> "$dir/err"
}

IMAGE=ghcr.io/example/backend:1.2.3-abcdef0-preprod

echo "run-job.sh"

# --- Le Job se termine ------------------------------------------------------
ok="$TMP/ok"
if BACKEND_IMAGE=ghcr.io/example/backend:0.0.0-mauvaise run "$ok" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 CPG_USERNAME=compte-de-test; then
  pass "un Job réussi sort en 0"
else
  fail "un Job réussi sort en 0" "$(cat "$ok/err")"
fi

delete_line=$(grep -n -x -- '-n preprod delete job demo-job --ignore-not-found' "$ok/args" | cut -d: -f1 || true)
apply_line=$(grep -n -x -- '-n preprod apply -f -' "$ok/args" | cut -d: -f1 || true)
if [ -n "$delete_line" ] && [ -n "$apply_line" ] && [ "$delete_line" -lt "$apply_line" ]; then
  pass "le Job précédent, nommé d'après metadata.name, est supprimé avant l'apply"
else
  fail "le Job précédent, nommé d'après metadata.name, est supprimé avant l'apply" "$(cat "$ok/args")"
fi

if grep -qx "          image: $IMAGE" "$ok/applied.yaml"; then
  pass "l'image passée en argument est substituée, pas celle de l'environnement"
else
  fail "l'image passée en argument est substituée, pas celle de l'environnement" "$(grep -n 'image:' "$ok/applied.yaml")"
fi

if grep -qx "              value: 'compte-de-test'" "$ok/applied.yaml"; then
  pass "une variable nommée en argument est substituée"
else
  fail "une variable nommée en argument est substituée" "$(grep -n 'value:' "$ok/applied.yaml")"
fi

if grep -qF 'echo $HOME $(POD_NAME)' "$ok/applied.yaml"; then
  pass "les autres \$ du manifeste passent intacts"
else
  fail "les autres \$ du manifeste passent intacts" "$(grep -n 'command:' "$ok/applied.yaml")"
fi

if grep -qx 'preprod job/demo-job --timeout 240' "$ok/wait-args"; then
  pass "l'attente vise le Job, avec le délai demandé"
else
  fail "l'attente vise le Job, avec le délai demandé" "$(cat "$ok/wait-args")"
fi

if grep -q ' logs ' "$ok/args"; then
  fail "un Job réussi n'imprime pas de journaux (l'appelant décide)" "$(cat "$ok/args")"
else
  pass "un Job réussi n'imprime pas de journaux (l'appelant décide)"
fi

# --- Les vrais manifestes : le nom lu est celui du Job ----------------------
for pair in migrate-job.yaml:backend-migrate seed-job.yaml:backend-seed \
            messenger-probe-job.yaml:backend-messenger-probe; do
  manifest="${pair%%:*}"; job="${pair#*:}"
  real="$TMP/real-$job"
  if run "$real" prod "$K8S_BASE/$manifest" "$IMAGE" 300 \
      && grep -qx -- "-n prod delete job $job --ignore-not-found" "$real/args" \
      && grep -qx "prod job/$job --timeout 300" "$real/wait-args" \
      && ! grep -q '\${' "$real/applied.yaml"; then
    pass "$manifest : Job $job, plus aucun placeholder"
  else
    fail "$manifest : Job $job, plus aucun placeholder" "$(cat "$real/err" "$real/args" "$real/wait-args")"
  fi
done

user="$TMP/real-create-user"
if run "$user" preprod "$K8S_BASE/create-user-job.yaml" "$IMAGE" 180 CPG_USERNAME=compte-de-test \
    && grep -qx -- '-n preprod delete job backend-create-user --ignore-not-found' "$user/args" \
    && ! grep -q '\${' "$user/applied.yaml"; then
  pass "create-user-job.yaml : Job backend-create-user, plus aucun placeholder"
else
  fail "create-user-job.yaml : Job backend-create-user, plus aucun placeholder" "$(cat "$user/err" "$user/args")"
fi

# --- Fail-closed : rien n'atteint le cluster --------------------------------
# Un placeholder sans valeur partirait littéral dans la spec du pod
# (`InvalidImageName`, ou un nom de compte « ${CPG_USERNAME} »).
missing="$TMP/missing"
if run "$missing" preprod "$TMP/demo-job.yaml" "$IMAGE" 240; then
  fail "un placeholder sans valeur fait échouer" "sorti en 0"
elif grep -q 'CPG_USERNAME' "$missing/err" && [ ! -s "$missing/args" ]; then
  pass "un placeholder sans valeur fait échouer en le nommant, avant tout appel à kubectl"
else
  fail "un placeholder sans valeur fait échouer en le nommant, avant tout appel à kubectl" "$(cat "$missing/err" "$missing/args")"
fi

for bad in 'cpg_username=x' 'CPG_USERNAME' '1VAR=x'; do
  badvar="$TMP/badvar-$bad"
  if run "$badvar" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 "$bad"; then
    fail "« $bad » est refusé" "sorti en 0"
  elif grep -qF -- "$bad" "$badvar/err" && [ ! -s "$badvar/args" ]; then
    pass "« $bad » est refusé en le citant, avant tout appel à kubectl"
  else
    fail "« $bad » est refusé en le citant, avant tout appel à kubectl" "$(cat "$badvar/err" "$badvar/args")"
  fi
done

noname="$TMP/noname"
printf 'apiVersion: batch/v1\nkind: Job\nmetadata:\n  labels:\n    a: b\nspec: {}\n' > "$TMP/noname.yaml"
if run "$noname" preprod "$TMP/noname.yaml" "$IMAGE" 240; then
  fail "un manifeste sans metadata.name est refusé" "sorti en 0"
elif grep -q 'metadata.name' "$noname/err" && [ ! -s "$noname/args" ]; then
  pass "un manifeste sans metadata.name est refusé en le disant, avant tout appel à kubectl"
else
  fail "un manifeste sans metadata.name est refusé en le disant, avant tout appel à kubectl" "$(cat "$noname/err" "$noname/args")"
fi

usage="$TMP/usage"
if run "$usage" preprod "$TMP/demo-job.yaml" "$IMAGE"; then
  fail "un argument manquant est refusé" "sorti en 0"
elif grep -q 'usage' "$usage/err" && [ ! -s "$usage/args" ]; then
  pass "un argument manquant est refusé par l'usage, avant tout appel à kubectl"
else
  fail "un argument manquant est refusé par l'usage, avant tout appel à kubectl" "$(cat "$usage/err" "$usage/args")"
fi

# --- L'apply est refusé -----------------------------------------------------
refused="$TMP/refused"
if FAKE_APPLY_EXIT=1 run "$refused" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 CPG_USERNAME=x; then
  fail "un apply refusé fait échouer" "sorti en 0"
elif [ -s "$refused/args" ] && [ ! -s "$refused/wait-args" ]; then
  pass "un apply refusé fait échouer, sans attendre"
else
  fail "un apply refusé fait échouer, sans attendre" "$(cat "$refused/wait-args")"
fi

# --- Le Job échoue ----------------------------------------------------------
ko="$TMP/ko"
if FAKE_WAIT_EXIT=1 run "$ko" preprod "$TMP/demo-job.yaml" "$IMAGE" 240 CPG_USERNAME=x; then
  fail "un Job en échec sort en 1" "sorti en 0"
else
  pass "un Job en échec sort en 1"
fi
if grep -q '::error::.*demo-job' "$ko/err" \
    && grep -qx -- '-n preprod logs job/demo-job --tail=200' "$ko/args" \
    && grep -q 'journal du Job en échec' "$ko/err"; then
  pass "l'échec nomme le Job et imprime ses 200 dernières lignes sur stderr"
else
  fail "l'échec nomme le Job et imprime ses 200 dernières lignes sur stderr" "$(cat "$ko/err" "$ko/args")"
fi

if grep -qi 'secret' "$ok/args" "$ko/args"; then
  fail "aucun Secret n'est lu" "$(grep -i secret "$ok/args" "$ko/args")"
else
  pass "aucun Secret n'est lu"
fi

if [ "$failures" -ne 0 ]; then
  echo "$failures échec(s)."
  exit 1
fi
echo "Tout est vert."
