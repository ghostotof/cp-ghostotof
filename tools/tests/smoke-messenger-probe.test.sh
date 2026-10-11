#!/usr/bin/env bash
#
# smoke-messenger-probe.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/smoke-messenger-probe.sh (issue #470) : un faux
# `kubectl` (SMOKE_PROBE_KUBECTL) note chacun de ses appels et le manifeste
# qu'on lui applique ; un faux tools/wait-rollout.sh (SMOKE_PROBE_WAIT) réussit
# ou échoue selon FAKE_WAIT_EXIT.
#
# Ce qui est pincé : le Job précédent est supprimé avant l'apply ; le
# manifeste appliqué porte l'image demandée et plus aucun placeholder ;
# l'attente vise le Job, avec un délai au-dessus de son activeDeadlineSeconds ;
# un Job en échec fait sortir en 1 en imprimant ses journaux ; aucun Secret
# n'est lu.
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
  *" apply -f - "*) cat > "$FAKE_DIR/applied.yaml" ;;
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

run() {
  local dir="$1"; shift
  mkdir -p "$dir"
  FAKE_DIR="$dir" SMOKE_PROBE_KUBECTL="$TMP/kubectl" SMOKE_PROBE_WAIT="$TMP/wait" \
    "$SCRIPT" preprod ghcr.io/example/backend:1.2.3-abcdef0-preprod > "$dir/out" 2>&1
}

echo "smoke-messenger-probe.sh"

# --- Le témoin est consommé -------------------------------------------------
ok="$TMP/ok"
if run "$ok"; then pass "un Job réussi sort en 0"; else fail "un Job réussi sort en 0" "$(cat "$ok/out")"; fi

delete_line=$(grep -n 'delete job backend-messenger-probe --ignore-not-found' "$ok/args" | cut -d: -f1 || true)
apply_line=$(grep -n 'apply -f -' "$ok/args" | cut -d: -f1 || true)
if [ -n "$delete_line" ] && [ -n "$apply_line" ] && [ "$delete_line" -lt "$apply_line" ]; then
  pass "le Job précédent est supprimé avant l'apply"
else
  fail "le Job précédent est supprimé avant l'apply" "$(cat "$ok/args")"
fi

if grep -q 'image: ghcr.io/example/backend:1.2.3-abcdef0-preprod' "$ok/applied.yaml" && ! grep -q '\${' "$ok/applied.yaml"; then
  pass "le manifeste appliqué porte l'image et plus aucun placeholder"
else
  fail "le manifeste appliqué porte l'image et plus aucun placeholder" "$(grep -n 'image:\|\${' "$ok/applied.yaml")"
fi

if grep -qx 'preprod job/backend-messenger-probe --timeout 300' "$ok/wait-args"; then
  pass "l'attente vise le Job, au-delà de son activeDeadlineSeconds"
else
  fail "l'attente vise le Job, au-delà de son activeDeadlineSeconds" "$(cat "$ok/wait-args")"
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
for d in backend worker; do
  stale="$TMP/stale-$d"
  upper=$(printf '%s' "$d" | tr '[:lower:]' '[:upper:]')
  if env "FAKE_${upper}_IMAGES=ghcr.io/example/backend:1.2.2-0000000-preprod nginx:1.29" FAKE_DIR="$stale" SMOKE_PROBE_KUBECTL="$TMP/kubectl" SMOKE_PROBE_WAIT="$TMP/wait" \
      bash -c 'mkdir -p "$FAKE_DIR"; "$0" preprod ghcr.io/example/backend:1.2.3-abcdef0-preprod > "$FAKE_DIR/out" 2>&1' "$SCRIPT"; then
    fail "$d sur une autre image fait échouer le smoke" "sorti en 0"
  elif grep -q "deployment/$d" "$stale/out" && ! grep -q 'apply -f -' "$stale/args"; then
    pass "$d sur une autre image fait échouer le smoke, avant tout Job"
  else
    fail "$d sur une autre image fait échouer le smoke, avant tout Job" "$(cat "$stale/out")"
  fi
done

# --- Le témoin n'est pas consommé ------------------------------------------
ko="$TMP/ko"
if FAKE_WAIT_EXIT=1 run "$ko"; then
  fail "un Job en échec sort en 1" "sorti en 0"
else
  pass "un Job en échec sort en 1"
fi
if grep -q "n'a pas consommé le message témoin" "$ko/out" && grep -q 'logs job/backend-messenger-probe' "$ko/args"; then
  pass "l'échec imprime les journaux du Job"
else
  fail "l'échec imprime les journaux du Job" "$(cat "$ko/out")"
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
