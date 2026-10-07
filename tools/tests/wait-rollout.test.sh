#!/usr/bin/env bash
#
# wait-rollout.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/wait-rollout.sh (issue #353). Un faux `kubectl`
# (WAIT_ROLLOUT_KUBECTL) joue deux rôles, et note chacun de ses appels :
#   - les tranches (`rollout status` / `wait --for=condition=complete`)
#     rejouent $FAKE_DIR/slice-<n> (n = numéro de l'appel, plafonné au
#     dernier fichier présent) : `ready` sort en 0, `timeout` avance l'horloge
#     de la durée de tranche puis sort en 1, `error` sort en 1 sans avancer ;
#   - `get … -o json` sert $FAKE_DIR/state-<n>.json, même règle de rang ;
#     __FAIL__ et __HTML__ rejouent une API muette et une page de proxy.
# Une horloge factice ($FAKE_DIR/clock, WAIT_ROLLOUT_CLOCK) n'avance que par
# les tranches et le faux `sleep` : les délais de persistance (15 s, 150 s)
# se vérifient à la seconde près, et la suite tourne en une seconde.
#
# Les fixtures reproduisent la forme des objets de l'API : un Deployment
# porte `deployment.kubernetes.io/revision` et `status.observedGeneration` ;
# ses ReplicaSet la même annotation et un ownerReference vers son uid ; un
# pod un ownerReference vers son ReplicaSet ou son Job, et ses statuts de
# conteneurs `state.waiting.{reason,message}`.
#
# Ce qui est pincé : rollout terminé = sortie 0 dès la première tranche ;
# CreateContainerConfigError persistant = sortie 1 avant 30 s, en nommant
# pod, conteneur, raison et message ; transitoire = pas d'échec ; pods d'une
# révision antérieure ou d'un ancien Job ignorés ; génération non observée
# ignorée ; ErrImagePull/ImagePullBackOff comptés ensemble sur 150 s ;
# InvalidImageName immédiat ; initContainers inspectés ; pod en suppression
# ignoré ; Job Failed=True immédiat ; délai borné ; tranche en erreur
# complétée par une pause ; trois erreurs kubectl d'affilée (pas des
# expirations) = sortie 1, une expiration remet le compte à zéro ; message
# mis sur une ligne ; aucun Secret lu.
#
# Usage :  tools/tests/wait-rollout.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/wait-rollout.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# Ce qu'un `kubectl get secret` rendrait : ne doit apparaître dans AUCUNE
# sortie du script (critère « aucune valeur de secret dans les journaux »).
SENTINEL="VALEUR-DE-SECRET-NE-DOIT-JAMAIS-FUITER"

cat > "$TMP/kubectl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_DIR/args"
nth() { # nth <préfixe> <numéro d'appel> : le fichier de ce rang, ou le dernier présent
  local n="$2"
  while [ "$n" -gt 1 ] && [ ! -f "$FAKE_DIR/$1-$n" ]; do n=$((n - 1)); done
  echo "$FAKE_DIR/$1-$n"
}
case " $* " in
  *" rollout status "*|*" wait --for=condition=complete "*)
    n=$(grep -c -E ' rollout status | wait --for=condition=complete ' "$FAKE_DIR/args")
    slice="$(sed -n 's/.*--timeout=\([0-9]*\)s.*/\1/p' <<<"$*")"
    case "$(cat "$(nth slice "$n")")" in
      ready)   echo "deployment \"x\" successfully rolled out"; exit 0 ;;
      timeout) echo "Waiting for deployment \"x\" rollout to finish: 0 of 1 updated replicas are available..."
               echo $(( $(cat "$FAKE_DIR/clock") + slice )) > "$FAKE_DIR/clock"
               echo "error: timed out waiting for the condition" >&2; exit 1 ;;
      error)   echo "Error from server (InternalError): etcdserver: request timed out" >&2; exit 1 ;;
      # Tranche expirée avant la synchronisation initiale du cache (API
      # lente) : client-go/tools/watch/until.go, UntilWithSync.
      sync-timeout) echo $(( $(cat "$FAKE_DIR/clock") + slice )) > "$FAKE_DIR/clock"
               echo "error: UntilWithSync: unable to sync caches: context deadline exceeded" >&2; exit 1 ;;
      forbidden) echo "Error from server (Forbidden): deployments.apps \"backend\" is forbidden: User \"system:serviceaccount:preprod:github-actions-deployer\" cannot get resource \"deployments\"" >&2; exit 1 ;;
    esac ;;
  *" get "*" -o json "*)
    case " $* " in *" secret"*) printf '{"data":{"K":"%s"}}\n' "$SENTINEL"; exit 0 ;; esac
    n=$(grep -c ' get ' "$FAKE_DIR/args")
    f="$(nth state "$n")"
    case "$(cat "$f")" in
      __FAIL__) echo "Unable to connect to the server: i/o timeout" >&2; exit 1 ;;
      __HTML__) echo "<html><body>502 Bad Gateway</body></html>"; exit 0 ;;
    esac
    cat "$f" ;;
  *" secret "*|*" secrets "*) printf '{"data":{"K":"%s"}}\n' "$SENTINEL" ;;
  *) echo "faux kubectl : appel inattendu « $* »" >&2; exit 99 ;;
esac
EOF
cat > "$TMP/sleep" <<'EOF'
#!/usr/bin/env bash
printf 'sleep %s\n' "$1" >> "$FAKE_DIR/args"
echo $(( $(cat "$FAKE_DIR/clock") + $1 )) > "$FAKE_DIR/clock"
EOF
cat > "$TMP/clock" <<'EOF'
#!/usr/bin/env bash
cat "$FAKE_DIR/clock"
EOF
chmod +x "$TMP/kubectl" "$TMP/sleep" "$TMP/clock"

# --- fixtures ---------------------------------------------------------------
# deploy <nom> <uid> <generation> <observedGeneration> <revision>
deploy() {
  printf '{"kind":"Deployment","metadata":{"name":"%s","uid":"%s","generation":%s,"annotations":{"deployment.kubernetes.io/revision":"%s"}},"status":{"observedGeneration":%s}}' \
    "$1" "$2" "$3" "$5" "$4"
}
# rs <nom> <uid> <uid du Deployment> <revision>
rs() {
  printf '{"kind":"ReplicaSet","metadata":{"name":"%s","uid":"%s","annotations":{"deployment.kubernetes.io/revision":"%s"},"ownerReferences":[{"kind":"Deployment","uid":"%s"}]}}' \
    "$1" "$2" "$4" "$3"
}
# job <nom> <uid> [Failed]
job() {
  local cond='[]'
  [ "${3:-}" = "Failed" ] && cond='[{"type":"Failed","status":"True","reason":"BackoffLimitExceeded","message":"Job has reached the specified backoff limit"}]'
  printf '{"kind":"Job","metadata":{"name":"%s","uid":"%s"},"status":{"conditions":%s}}' "$1" "$2" "$cond"
}
# pod <nom> <uid du propriétaire> <conteneur> <raison|running> [message] [init] [deleting]
pod() {
  local state='{"running":{"startedAt":"2026-10-06T12:00:00Z"}}' statuses key='containerStatuses' meta=''
  if [ "$4" != "running" ]; then
    state="$(jq -cn --arg r "$4" --arg m "${5:-}" '{waiting: {reason: $r, message: $m}}')"
  fi
  [ "${6:-}" = "init" ] && key='initContainerStatuses'
  [ "${7:-}" = "deleting" ] && meta=',"deletionTimestamp":"2026-10-06T12:00:00Z"'
  statuses="$(jq -cn --arg c "$3" --argjson s "$state" '[{name: $c, state: $s}]')"
  printf '{"kind":"Pod","metadata":{"name":"%s","ownerReferences":[{"uid":"%s"}]%s},"status":{"%s":%s}}' \
    "$1" "$2" "$meta" "$key" "$statuses"
}
# state <n> <objet>…  — la liste servie au n-ième `get`.
state() {
  local n="$1"; shift
  local IFS=,
  printf '{"apiVersion":"v1","kind":"List","items":[%s]}\n' "$*" > "$FAKE_DIR/state-$n"
}
slices() { local i=1 s; for s in "$@"; do echo "$s" > "$FAKE_DIR/slice-$i"; i=$((i + 1)); done; }

run() {
  set +e
  WAIT_ROLLOUT_KUBECTL="$TMP/kubectl" WAIT_ROLLOUT_SLEEP="$TMP/sleep" WAIT_ROLLOUT_CLOCK="$TMP/clock" \
    SENTINEL="$SENTINEL" "$SCRIPT" "$@" > "$FAKE_DIR/out" 2>&1
  rc=$?
  set -e
}
new_case() { FAKE_DIR="$TMP/$1"; mkdir -p "$FAKE_DIR"; : > "$FAKE_DIR/args"; echo 0 > "$FAKE_DIR/clock"; export FAKE_DIR; }
clock() { cat "$FAKE_DIR/clock"; }
slice_calls() { grep -c -E ' rollout status | wait --for=condition=complete ' "$FAKE_DIR/args" || true; }

MSG_KEY="couldn't find key ANTHROPIC_API_KEY in Secret preprod/backend-secrets"
BACKEND="$(deploy backend d-1 7 7 12)"
NEW_RS="$(rs backend-7f9 rs-12 d-1 12)"
OLD_RS="$(rs backend-5c4 rs-11 d-1 11)"

echo "wait-rollout.sh"

# --- 1. rollout terminé dès la première tranche ------------------------------
new_case nominal
slices ready
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ] && [ "$(slice_calls)" -eq 1 ] && ! grep -q ' get ' "$FAKE_DIR/args"; then
  pass "rollout terminé : sortie 0 à la première tranche, sans lecture des pods"
else fail "rollout terminé : sortie 0 à la première tranche, sans lecture des pods" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi
if grep -q -- '-n preprod rollout status deployment/backend --timeout=5s' "$FAKE_DIR/args"; then
  pass "tranche = rollout status --timeout=<interval>s dans le namespace"
else fail "tranche = rollout status --timeout=<interval>s dans le namespace" "$(cat "$FAKE_DIR/args")"; fi

# --- 2. clé absente : CreateContainerConfigError persistant -----------------
new_case config-error
slices timeout
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm CreateContainerConfigError "$MSG_KEY")"
run preprod deployment/backend --timeout 180 --interval 5
out="$(cat "$FAKE_DIR/out")"
if [ "$rc" -eq 1 ] && [ "$(clock)" -ge 15 ] && [ "$(clock)" -lt 30 ]; then
  pass "CreateContainerConfigError persistant : sortie 1 entre 15 et 30 s (horloge $(clock) s), pas 180"
else fail "CreateContainerConfigError persistant : sortie 1 entre 15 et 30 s, pas 180" "rc=$rc, horloge=$(clock) ; $out"; fi
if grep -q '^::error::deployment/backend bloqué dans preprod : pod backend-7f9-abc, conteneur php-fpm : CreateContainerConfigError observé depuis [0-9]* s — couldn.t find key ANTHROPIC_API_KEY in Secret preprod/backend-secrets$' "$FAKE_DIR/out"; then
  pass "l'erreur nomme pod, conteneur, raison et message de Kubernetes"
else fail "l'erreur nomme pod, conteneur, raison et message de Kubernetes" "$out"; fi
unbounded="$(grep -v -e '--request-timeout=' -e '^sleep ' "$FAKE_DIR/args" || true)"
if [ -z "$unbounded" ]; then pass "chaque appel kubectl porte --request-timeout"
else fail "chaque appel kubectl porte --request-timeout" "$unbounded"; fi
if grep -q -- '-n preprod get deployments,replicasets,pods -o json' "$FAKE_DIR/args"; then
  pass "inspection : une lecture deployments,replicasets,pods du namespace"
else fail "inspection : une lecture deployments,replicasets,pods du namespace" "$(cat "$FAKE_DIR/args")"; fi

# --- 3. transitoire : apparaît puis disparaît --------------------------------
new_case config-transient
slices timeout timeout ready
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm CreateContainerConfigError "$MSG_KEY")"
state 2 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm running)"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ] && ! grep -q '^::error::' "$FAKE_DIR/out"; then pass "CreateContainerConfigError transitoire (< 15 s) : sortie 0"
else fail "CreateContainerConfigError transitoire (< 15 s) : sortie 0" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 4. pods d'une révision antérieure restée bloquée : ignorés --------------
# L'ancien ReplicaSet est listé EN PREMIER : sans le filtre de révision,
# `[0]` le prendrait et son pod bloqué ferait échouer à tort.
new_case old-revision
slices timeout timeout timeout timeout timeout ready
state 1 "$BACKEND" "$OLD_RS" "$NEW_RS" \
  "$(pod backend-5c4-old rs-11 php-fpm CreateContainerConfigError "$MSG_KEY")" \
  "$(pod backend-7f9-abc rs-12 php-fpm running)"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ]; then pass "pod bloqué d'une révision antérieure : ignoré, sortie 0"
else fail "pod bloqué d'une révision antérieure : ignoré, sortie 0" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 5. génération pas encore observée par le contrôleur : rien n'est jugé ---
# Juste après `apply -k`, la révision annotée est encore l'ancienne : ses pods
# ne disent rien du rollout en cours.
new_case unobserved
slices timeout timeout timeout timeout timeout ready
state 1 "$(deploy backend d-1 8 7 11)" "$OLD_RS" "$(pod backend-5c4-old rs-11 php-fpm CreateContainerConfigError "$MSG_KEY")"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ]; then pass "génération non observée : pods de l'ancienne révision ignorés"
else fail "génération non observée : pods de l'ancienne révision ignorés" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 6. échec de pull : ErrImagePull et ImagePullBackOff comptés ensemble ----
new_case pull-error
slices timeout
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm ErrImagePull 'failed to pull image')"
state 2 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm ImagePullBackOff 'Back-off pulling image')"
state 3 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm ErrImagePull 'failed to pull image')"
state 4 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm ImagePullBackOff 'Back-off pulling image')"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 1 ] && [ "$(clock)" -ge 150 ] && [ "$(clock)" -lt 165 ] && grep -q '^::error::.*ImagePullBackOff observé depuis' "$FAKE_DIR/out"; then
  pass "pull en échec alterné : sortie 1 après 150 s (horloge $(clock) s), pas avant"
else fail "pull en échec alterné : sortie 1 après 150 s, pas avant" "rc=$rc, horloge=$(clock) ; $(cat "$FAKE_DIR/out")"; fi

# Un registre indisponible une centaine de secondes (après `apply -k` en
# prod, sans rollback) ne doit pas faire échouer un rollout qui aurait
# convergé seul : arbitrage du 2026-10-07, 150 s plutôt que 60.
new_case pull-outage-100s
# shellcheck disable=SC2046  # découpage voulu : 21 « timeout » puis « ready »
slices $(printf 'timeout %.0s' $(seq 21)) ready
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm ErrImagePull 'failed to pull image')"
state 2 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm ImagePullBackOff 'Back-off pulling image')"
state 21 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm running)"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ]; then pass "pull en échec 100 s puis rétabli : sortie 0"
else fail "pull en échec 100 s puis rétabli : sortie 0" "rc=$rc, horloge=$(clock) ; $(cat "$FAKE_DIR/out")"; fi

new_case pull-transient
slices timeout timeout timeout timeout timeout timeout timeout timeout ready
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm ErrImagePull 'failed to pull image')"
state 5 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm ContainerCreating '')"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ]; then pass "pull qui aboutit après 20 s : sortie 0"
else fail "pull qui aboutit après 20 s : sortie 0" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 7. nom d'image invalide : immédiat --------------------------------------
new_case invalid-image
slices timeout
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm InvalidImageName 'Failed to apply default image tag')"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 1 ] && [ "$(slice_calls)" -eq 1 ]; then pass "InvalidImageName : sortie 1 dès la première inspection"
else fail "InvalidImageName : sortie 1 dès la première inspection" "rc=$rc, tranches=$(slice_calls) ; $(cat "$FAKE_DIR/out")"; fi

# --- 8. initContainer bloqué -------------------------------------------------
new_case init-container
slices timeout
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 seed-cache-system CreateContainerConfigError "$MSG_KEY" init)"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 1 ] && grep -q '^::error::.*initContainer seed-cache-system : CreateContainerConfigError' "$FAKE_DIR/out"; then
  pass "initContainer en CreateContainerConfigError : sortie 1, nommé comme initContainer"
else fail "initContainer en CreateContainerConfigError : sortie 1, nommé comme initContainer" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 9. pod en cours de suppression : ignoré ---------------------------------
new_case deleting
slices timeout timeout timeout timeout timeout ready
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm CreateContainerConfigError "$MSG_KEY" '' deleting)"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ]; then pass "pod en cours de suppression : ignoré"
else fail "pod en cours de suppression : ignoré" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 10. Job : CreateContainerConfigError avant ses 300 s --------------------
new_case job-config
slices timeout
state 1 "$(job backend-migrate j-2)" \
  "$(pod backend-migrate-old j-1 migrate CreateContainerConfigError 'secret "old" not found')" \
  "$(pod backend-migrate-xyz j-2 migrate CreateContainerConfigError "$MSG_KEY")"
run preprod job/backend-migrate --timeout 300 --interval 5
out="$(cat "$FAKE_DIR/out")"
if [ "$rc" -eq 1 ] && [ "$(clock)" -lt 30 ] && grep -q '^::error::job/backend-migrate bloqué dans preprod : pod backend-migrate-xyz, conteneur migrate : CreateContainerConfigError' "$FAKE_DIR/out"; then
  pass "Job en CreateContainerConfigError : sortie 1 avant 30 s, pas 300"
else fail "Job en CreateContainerConfigError : sortie 1 avant 30 s, pas 300" "rc=$rc, horloge=$(clock) ; $out"; fi
if ! grep -q 'backend-migrate-old' "$FAKE_DIR/out"; then pass "Job : le pod de l'ancien Job (autre uid) n'est pas mis en cause"
else fail "Job : le pod de l'ancien Job (autre uid) n'est pas mis en cause" "$out"; fi
if grep -q -- '-n preprod wait --for=condition=complete job/backend-migrate --timeout=5s' "$FAKE_DIR/args" \
   && grep -q -- '-n preprod get jobs,pods -o json' "$FAKE_DIR/args"; then
  pass "Job : tranche wait --for=condition=complete, lecture jobs,pods"
else fail "Job : tranche wait --for=condition=complete, lecture jobs,pods" "$(cat "$FAKE_DIR/args")"; fi

# --- 11. Job en échec (backoffLimit atteint) : immédiat ----------------------
new_case job-failed
slices timeout
state 1 "$(job backend-migrate j-2 Failed)"
run preprod job/backend-migrate --timeout 300 --interval 5
if [ "$rc" -eq 1 ] && [ "$(slice_calls)" -eq 1 ] && grep -q '^::error::job/backend-migrate en échec dans preprod (BackoffLimitExceeded : Job has reached the specified backoff limit)' "$FAKE_DIR/out"; then
  pass "Job Failed=True : sortie 1 dès la première inspection, raison citée"
else fail "Job Failed=True : sortie 1 dès la première inspection, raison citée" "rc=$rc, tranches=$(slice_calls) ; $(cat "$FAKE_DIR/out")"; fi

new_case job-done
slices timeout ready
state 1 "$(job backend-migrate j-2)" "$(pod backend-migrate-xyz j-2 migrate running)"
run preprod job/backend-migrate --timeout 300 --interval 5
if [ "$rc" -eq 0 ]; then pass "Job terminé à la deuxième tranche : sortie 0"
else fail "Job terminé à la deuxième tranche : sortie 0" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 12. délai dépassé sans raison fatale ------------------------------------
new_case timeout
slices timeout
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm ImagePullBackOff 'Back-off pulling image')"
state 2 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm ContainerCreating '')"
run preprod deployment/backend --timeout 20 --interval 5
if [ "$rc" -eq 1 ] && [ "$(slice_calls)" -le 5 ] && grep -q '^::error::deployment/backend pas prêt dans preprod après 20 s (error: timed out waiting for the condition)' "$FAKE_DIR/out"; then
  pass "délai dépassé : sortie 1, au plus timeout/interval + 1 tranches"
else fail "délai dépassé : sortie 1, au plus timeout/interval + 1 tranches" "rc=$rc, tranches=$(slice_calls) ; $(cat "$FAKE_DIR/out")"; fi

# --- 13. tranche en erreur immédiate : complétée par une pause ---------------
new_case slice-error
slices error error ready
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm running)"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ] && [ "$(grep -c '^sleep 5$' "$FAKE_DIR/args")" -eq 2 ]; then
  pass "tranche revenue en erreur : pause de l'intervalle avant la suivante"
else fail "tranche revenue en erreur : pause de l'intervalle avant la suivante" "rc=$rc ; $(cat "$FAKE_DIR/args")"; fi

# --- 13bis. erreur persistante autre qu'une expiration : échec anticipé -----
# `rollout status`/`wait` sortaient aussitôt sur Forbidden, NotFound ou
# ProgressDeadlineExceeded ; découpés en tranches, ils ne doivent pas
# devenir une attente silencieuse jusqu'au délai (relecture /code-review).
new_case slice-forbidden
slices forbidden
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm running)"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 1 ] && [ "$(slice_calls)" -eq 3 ] \
   && grep -q '^::error::deployment/backend : 3 erreurs kubectl consécutives dans preprod — Error from server (Forbidden): deployments.apps "backend" is forbidden' "$FAKE_DIR/out"; then
  pass "erreur kubectl persistante (Forbidden) : sortie 1 à la 3e tranche, erreur citée"
else fail "erreur kubectl persistante (Forbidden) : sortie 1 à la 3e tranche, erreur citée" "rc=$rc, tranches=$(slice_calls) ; $(cat "$FAKE_DIR/out")"; fi

# Une expiration de tranche remet le compte à zéro : deux erreurs, une
# tranche normale, deux erreurs, puis prêt — jamais trois d'affilée.
new_case slice-errors-interleaved
slices error error timeout error error ready
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm running)"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ]; then pass "erreurs kubectl non consécutives : l'attente aboutit"
else fail "erreurs kubectl non consécutives : l'attente aboutit" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# Une API lente fait expirer la tranche avant même la synchronisation du
# cache : c'est une expiration, pas une erreur à compter.
new_case slice-sync-timeout
slices sync-timeout sync-timeout sync-timeout sync-timeout ready
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm running)"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ]; then pass "tranches expirées avant la synchronisation du cache : l'attente aboutit"
else fail "tranches expirées avant la synchronisation du cache : l'attente aboutit" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 14. lecture des pods impossible -----------------------------------------
new_case unreachable
slices timeout timeout ready
printf '__FAIL__' > "$FAKE_DIR/state-1"
printf '__HTML__' > "$FAKE_DIR/state-2"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 0 ] && [ "$(grep -c '^::warning::lecture des pods' "$FAKE_DIR/out")" -eq 2 ] && ! grep -q 'unbound variable' "$FAKE_DIR/out"; then
  pass "lecture impossible (API muette, page de proxy) : ::warning::, l'attente continue"
else fail "lecture impossible (API muette, page de proxy) : ::warning::, l'attente continue" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 15. message sur plusieurs lignes : une seule ligne d'annotation ---------
new_case multiline
slices timeout
state 1 "$BACKEND" "$NEW_RS" "$(pod backend-7f9-abc rs-12 php-fpm InvalidImageName $'ligne 1\n::error::forgée')"
run preprod deployment/backend --timeout 180 --interval 5
if [ "$rc" -eq 1 ] && ! grep -q '^::error::forgée' "$FAKE_DIR/out" && grep -q 'ligne 1 ::error::forgée$' "$FAKE_DIR/out"; then
  pass "message multiligne : rendu sur une ligne, aucune commande de workflow forgée"
else fail "message multiligne : rendu sur une ligne, aucune commande de workflow forgée" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 16. aucune valeur de secret, dans aucun cas ------------------------------
leaks="$(grep -rl "$SENTINEL" "$TMP"/*/out 2>/dev/null || true)"
secret_reads="$(grep -h -E ' (get|describe) ([a-z,]*secrets?( |,|$)|secrets?/)' "$TMP"/*/args 2>/dev/null || true)"
if [ -z "$leaks" ] && [ -z "$secret_reads" ]; then pass "aucun Secret lu, aucune valeur dans la sortie"
else fail "aucun Secret lu, aucune valeur dans la sortie" "fuites : ${leaks:-aucune} ; lectures : ${secret_reads:-aucune}"; fi

# --- 17. arguments invalides : sortie 2, rien n'est appelé --------------------
new_case usage
for args in "preprod" "" "preprod statefulset/x" "preprod deployment/backend extra" \
            "preprod deployment/backend --timeout abc" "preprod deployment/backend --interval 0" \
            "preprod deployment/backend --timeout"; do
  # shellcheck disable=SC2086  # découpage voulu
  run $args
  if [ "$rc" -eq 2 ]; then pass "usage « ${args:-(vide)} » : sortie 2"
  else fail "usage « ${args:-(vide)} » : sortie 2" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi
done
if [ ! -s "$FAKE_DIR/args" ]; then pass "arguments invalides : kubectl jamais appelé"
else fail "arguments invalides : kubectl jamais appelé" "$(cat "$FAKE_DIR/args")"; fi
run --help
if [ "$rc" -eq 0 ] && grep -q '^Usage' "$FAKE_DIR/out" && ! grep -q 'set -euo' "$FAKE_DIR/out"; then pass "--help : l'en-tête seul, sans le code"
else fail "--help : l'en-tête seul, sans le code" "rc=$rc ; $(tail -3 "$FAKE_DIR/out")"; fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "tous les cas passent"
