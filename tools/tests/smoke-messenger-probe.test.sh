#!/usr/bin/env bash
#
# smoke-messenger-probe.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/smoke-messenger-probe.sh (issue #470) : un faux
# `kubectl` (SMOKE_PROBE_KUBECTL) note chacun de ses appels ; un faux
# tools/wait-rollout.sh (SMOKE_PROBE_WAIT) réussit ou échoue selon
# FAKE_WAIT_EXIT. Le script les transmet à tools/run-job.sh, qui tourne pour
# de vrai : la séquence du Job (delete, rendu, apply, attente, journaux) est
# pincée par tools/tests/run-job.test.sh, pas ici (issue #488).
#
# Ce qui est pincé ici : le Job lancé est celui du témoin, attendu au-delà de
# son activeDeadlineSeconds ; `backend` et `worker` doivent tourner l'image de
# la release, sinon échec avant tout Job ; un Job en échec fait sortir en 1
# avec le diagnostic du témoin ; un échec qui n'est PAS celui du Job (le
# cluster refuse l'apply) ne l'impute pas au worker ; aucun Secret n'est lu.
#
# Usage :  tools/tests/smoke-messenger-probe.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/smoke-messenger-probe.sh"
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
  *" apply -f - "*) cat > /dev/null; exit "${FAKE_APPLY_EXIT:-0}" ;;
  *" get deployment/backend "*) echo "${FAKE_BACKEND_IMAGES:-ghcr.io/example/backend:1.2.3-abcdef0-preprod nginx:1.29}" ;;
  *" get deployment/worker "*) echo "${FAKE_WORKER_IMAGES:-ghcr.io/example/backend:1.2.3-abcdef0-preprod}" ;;
  *" logs "*) echo "[ERROR] Le worker n'a pas consommé le message témoin en 90 s." ;;
esac
FAKE
cat > "$TMP/wait" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_DIR/wait-args"
exit "${FAKE_WAIT_EXIT:-0}"
FAKE
chmod +x "$TMP/kubectl" "$TMP/wait"

# run <dossier> — les variables préfixées à l'appel (FAKE_…) traversent
# jusqu'aux faux binaires ; le code de sortie est rangé dans <dossier>/status.
run() {
  local dir="$1" status=0
  mkdir -p "$dir"
  : > "$dir/args"; : > "$dir/wait-args"
  FAKE_DIR="$dir" SMOKE_PROBE_KUBECTL="$TMP/kubectl" SMOKE_PROBE_WAIT="$TMP/wait" \
    "$SCRIPT" preprod ghcr.io/example/backend:1.2.3-abcdef0-preprod > "$dir/out" 2>&1 || status=$?
  echo "$status" > "$dir/status"
}

echo "smoke-messenger-probe.sh"

# --- Le témoin est consommé -------------------------------------------------
ok="$TMP/ok"
run "$ok"
if [ "$(cat "$ok/status")" = 0 ]; then pass "un Job réussi sort en 0"; else fail "un Job réussi sort en 0" "$(cat "$ok/out")"; fi

if grep -qx 'preprod job/backend-messenger-probe --timeout 300' "$ok/wait-args"; then
  pass "le Job du témoin est lancé, attendu 300 s"
else
  fail "le Job du témoin est lancé, attendu 300 s" "$(cat "$ok/wait-args")"
fi

deadline=$(sed -n 's/^  activeDeadlineSeconds: \([0-9]*\)$/\1/p' "$(dirname "$SCRIPT")/../k8s/base/messenger-probe-job.yaml")
if [ -n "$deadline" ] && [ "$deadline" -lt 300 ]; then
  pass "activeDeadlineSeconds ($deadline) reste sous l'attente du script"
else
  fail "activeDeadlineSeconds reste sous l'attente du script" "lu : « $deadline »"
fi

# --- Un Deployment ne tourne pas l'image de la release ---------------------
# Le Job prouve le trajet depuis l'image de la release ; il ne prouve rien des
# pods réels si `backend` ou `worker` en tournent une autre (relecture de #470).
stale_images='ghcr.io/example/backend:1.2.2-0000000-preprod nginx:1.29'
FAKE_BACKEND_IMAGES="$stale_images" run "$TMP/stale-backend"
FAKE_WORKER_IMAGES="$stale_images" run "$TMP/stale-worker"
for d in backend worker; do
  stale="$TMP/stale-$d"
  if [ "$(cat "$stale/status")" != 0 ] && grep -q "deployment/$d" "$stale/out" && ! grep -q 'apply -f -' "$stale/args"; then
    pass "$d sur une autre image fait échouer le smoke, avant tout Job"
  else
    fail "$d sur une autre image fait échouer le smoke, avant tout Job" "$(cat "$stale/out")"
  fi
done

# --- Le témoin n'est pas consommé ------------------------------------------
ko="$TMP/ko"
FAKE_WAIT_EXIT=1 run "$ko"
if [ "$(cat "$ko/status")" = 1 ] && grep -q "n'a pas consommé le message témoin (issue #470)" "$ko/out"; then
  pass "un Job en échec sort en 1, avec le diagnostic du témoin"
else
  fail "un Job en échec sort en 1, avec le diagnostic du témoin" "sortie $(cat "$ko/status") ; $(cat "$ko/out")"
fi

# --- L'échec n'est pas celui du Job ----------------------------------------
# Un apply refusé (RBAC, webhook d'admission) n'a rien à voir avec le worker :
# l'y imputer enverrait vers une rotation de clé inutile (relecture de #488).
refused="$TMP/refused"
FAKE_APPLY_EXIT=1 run "$refused"
if [ "$(cat "$refused/status")" != 0 ] && ! grep -q 'clé de MAC' "$refused/out" && grep -q 'apply' "$refused/out"; then
  pass "un apply refusé fait échouer sans accuser le worker"
else
  fail "un apply refusé fait échouer sans accuser le worker" "sortie $(cat "$refused/status") ; $(cat "$refused/out")"
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
