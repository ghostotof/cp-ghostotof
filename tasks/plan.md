# Échec anticipé des rollouts — plan d'implémentation (issue #353)

> **Pour l'agent qui exécute :** sous-skill requise : `superpowers:subagent-driven-development` ou
> `superpowers:executing-plans` (dans ce dépôt, `/cpg-dev` étape 05 : `agent-skills:build` +
> `superpowers:test-driven-development`). Les étapes se cochent (`- [ ]`) ici et dans `tasks/todo.md`.

**Objectif :** qu'un pod bloqué au démarrage (`CreateContainerConfigError`, échec de pull, nom d'image
invalide) ou un Job en échec fasse échouer `deploy-preprod`/`deploy-prod` en quelques secondes, en
nommant le pod, le conteneur, la raison et le message de Kubernetes, au lieu d'attendre l'expiration
d'un `rollout status` (180 s) ou d'un `wait` de Job (300 s) sans cause dans les journaux.

**Architecture :** un script `tools/wait-rollout.sh <ns> deployment/NOM|job/NOM --timeout N`, testé
hors ligne sur le modèle de `tools/wait-external-secrets.sh` (#325). Il découpe l'attente kubectl
habituelle en tranches de 5 s (`rollout status --timeout=5s` / `wait --for=condition=complete
--timeout=5s`) : « terminé » garde la sémantique de kubectl. Entre deux tranches, une lecture JSON
des pods de la révision en cours (conteneurs et initContainers) applique un délai de persistance par
raison. Les sept attentes des étapes `Deploy` passent par ce script.

**Pile :** bash 5, jq, kubectl (runner `ubuntu-latest`), GitHub Actions ; shellcheck et actionlint en CI
(job `tools-tests`).

**Spec :** l'issue GitHub #353 (« Pipeline : échouer tôt sur CreateContainerConfigError pendant le
rollout, quelle qu'en soit la cause ») et les arbitrages de Christophe du 2026-10-06 :
- **Architecture** : script à tranches (plutôt qu'une surveillance parallèle ou un statut recalculé
  en jq).
- **Transitoires** : délai par raison. `InvalidImageName` immédiat, `CreateContainerConfigError`
  15 s, `ErrImagePull`/`ImagePullBackOff` 60 s comptés ensemble. Les autres raisons sont ignorées.
- **Périmètre élargi** : un Job `Failed=True` échoue aussitôt, et les `initContainerStatuses` sont
  inspectés. `CrashLoopBackOff` reste **hors périmètre**.
- **Plan écrit** (ce fichier).

## Contraintes globales

- Aucune valeur de secret dans les journaux : le script ne lit **jamais** un Secret, seulement
  `deployments`, `replicasets`, `jobs` et `pods` (statuts).
- Aucun verbe RBAC ajouté : le Role `github-actions-deployer` a déjà `get`/`list`/`watch` sur
  `deployments`, `replicasets`, `jobs`, `pods` (`k8s/base/github-actions-rbac/role.yaml`). Le RBAC
  est un bootstrap manuel, et la pipeline ne le rejoue pas : n'y rien changer.
- Le fail-closed reste inchangé. Avant `kubectl apply -k .`, un échec laisse la release précédente en
  service. Après, le comportement actuel s'applique : pas de rollback automatique en prod, et
  `rollback-preprod` ne tourne que si `deploy-preprod` a réussi.
- Le message d'erreur reste sur une seule ligne : il passe par une annotation `::error::`, et un
  saut de ligne permettrait de forger une commande de workflow.
- Chaque appel au cluster est borné par `--request-timeout`. Chaque job garde un `timeout-minutes`
  au-dessus de la somme de ses attentes internes (#285, `tools/check-workflow-timeouts.sh`).
- `run:` des workflows = `bash -e` sans `pipefail` : jamais `cmd | tee`.
- Jamais `[skip ci]` ni équivalent dans un message de commit.
- Les commits portent les lignes d'attribution de la session et nomment leurs fichiers, sans
  `git add -A`.
- Les tests et outils passent par le dépôt et ses conteneurs. Le banc bash tourne sur l'hôte comme en
  CI (`tools/tests/*.test.sh`). shellcheck n'est pas installé sur l'hôte : le lancer par l'image
  Docker en lui passant le fichier sur stdin (cf. Tâche 1).

## Points d'attention en revue

Cinq classes d'entrées que l'issue implique et qui mordraient en premier. Chacune est épinglée par
un test de la tâche qui la porte.
1. **Les pods d'un rollout précédent resté bloqué** (une release ratée, puis une release correctrice).
   Ils ne doivent pas faire échouer la nouvelle : seul le ReplicaSet de la révision courante compte.
   Tâche 1, cas 4, où l'ancien ReplicaSet est listé en premier.
2. **Juste après `apply -k`**, le contrôleur n'a pas encore observé la nouvelle génération : la
   révision annotée est encore l'ancienne. On ne juge rien tant que `observedGeneration <
   generation`. Tâche 1, cas 5.
3. **Le kubelet alterne `ErrImagePull` et `ImagePullBackOff`** : un compteur par raison
   repartirait de zéro à chaque bascule et n'échouerait jamais. Les deux raisons partagent un même
   compteur. Tâche 1, cas 6.
4. **Un message Kubernetes sur plusieurs lignes** pourrait forger une commande `::error::` ou
   `::set-output`. Tâche 1, cas 15.
5. **La forme réelle des objets et des messages** (items d'une `List` multi-types, texte du kubelet,
   sortie de `rollout status`) peut s'écarter des fixtures. Tâche 3, expérience en préprod.

---

### Tâche 1 : `tools/wait-rollout.sh` et son banc hors ligne

**Fichiers :**
- Créer : `tools/tests/wait-rollout.test.sh`
- Créer : `tools/wait-rollout.sh`

**Interfaces :**
- Consomme : rien des autres tâches.
- Produit : `tools/wait-rollout.sh <namespace> <deployment/NOM|job/NOM> [--timeout N] [--interval N]`,
  qui sort en 0 si la cible est prête, en 1 sur une raison fatale persistante, un Job en échec ou un
  délai dépassé, et en 2 sur une erreur d'usage. Les messages d'échec sont des lignes `::error::…`.
  Les binaires se substituent par `WAIT_ROLLOUT_KUBECTL`, `WAIT_ROLLOUT_SLEEP` et
  `WAIT_ROLLOUT_CLOCK`.

- [ ] **Étape 1 : écrire le banc qui échoue**

Créer `tools/tests/wait-rollout.test.sh` avec exactement ce contenu, puis le rendre exécutable
(`chmod +x`) :

```bash
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
# les tranches et le faux `sleep` : les délais de persistance (15 s, 60 s)
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
# ignorée ; ErrImagePull/ImagePullBackOff comptés ensemble sur 60 s ;
# InvalidImageName immédiat ; initContainers inspectés ; pod en suppression
# ignoré ; Job Failed=True immédiat ; délai borné ; tranche en erreur
# complétée par une pause ; message mis sur une ligne ; aucun Secret lu.
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

MSG_KEY="couldn't find key CV_PDF_PATH in Secret preprod/cv-pdf"
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
if grep -q '^::error::deployment/backend bloqué dans preprod : pod backend-7f9-abc, conteneur php-fpm : CreateContainerConfigError depuis [0-9]* s — couldn.t find key CV_PDF_PATH in Secret preprod/cv-pdf$' "$FAKE_DIR/out"; then
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
if [ "$rc" -eq 1 ] && [ "$(clock)" -ge 60 ] && [ "$(clock)" -lt 75 ] && grep -q '^::error::.*ImagePullBackOff depuis' "$FAKE_DIR/out"; then
  pass "pull en échec alterné : sortie 1 après 60 s (horloge $(clock) s), pas avant"
else fail "pull en échec alterné : sortie 1 après 60 s, pas avant" "rc=$rc, horloge=$(clock) ; $(cat "$FAKE_DIR/out")"; fi

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
```

- [ ] **Étape 2 : le lancer et le voir échouer pour la bonne raison**

Run : `tools/tests/wait-rollout.test.sh`
Attendu : la plupart des cas en `FAIL`, avec `rc=127` (le script n'existe pas encore). Le résumé final
annonce `N échec(s)` et la sortie vaut 1. Deux cas passent déjà, « aucun Secret lu » et
« arguments invalides : kubectl jamais appelé » : ils sont vrais sans script, et c'est normal.

- [ ] **Étape 3 : écrire le script**

Créer `tools/wait-rollout.sh` avec exactement ce contenu, puis `chmod +x` :

```bash
#!/usr/bin/env bash
#
# wait-rollout.sh
# ---------------------------------------------------------------------------
# Attend la fin du rollout d'un Deployment ou d'un Job, et échoue DÈS qu'un
# de ses nouveaux conteneurs est bloqué pour une raison qui ne se résoudra
# pas seule (issue #353). Remplace, dans `deploy-preprod`/`deploy-prod`, les
# `kubectl rollout status` et `kubectl wait --for=condition=complete`.
#
# Pourquoi : un Secret créé à la main absent (`cv-pdf`), une coquille dans
# un `secretKeyRef`/`configMapKeyRef`, une clé de Secret Manager absente du
# `data` de son ExternalSecret… laissent le pod en `CreateContainerConfigError`.
# `rollout status` n'en dit rien et expire après 180 s (300 s pour le Job de
# migration), sans cause dans les journaux du job. tools/wait-external-
# secrets.sh (issue #325) ne couvre que les ExternalSecret non synchronisés.
#
# Méthode : des tranches courtes de l'attente kubectl habituelle
# (`rollout status --timeout=<interval>s` ou `wait --for=condition=complete
# --timeout=<interval>s`), dont la sémantique « terminé » reste celle de
# kubectl ; entre deux tranches, une lecture des pods de la révision en cours.
#   Deployment : les pods du ReplicaSet dont l'annotation
#     `deployment.kubernetes.io/revision` est celle du Deployment, une fois
#     `status.observedGeneration` à jour — jamais ceux d'un rollout précédent
#     resté bloqué, qui donneraient un faux positif.
#   Job : les pods dont un ownerReference porte l'uid du Job — jamais ceux de
#     l'ancien `backend-migrate`, supprimé juste avant mais pas encore
#     ramassé. Un Job en condition `Failed=True` (backoffLimit atteint)
#     échoue aussitôt, au lieu d'attendre la fin du délai.
# Les conteneurs ET les initContainers sont inspectés, pods en cours de
# suppression exclus.
#
# Raisons fatales et persistance exigée avant d'échouer :
#   InvalidImageName             immédiat (ne se corrige jamais seul) ;
#   CreateContainerConfigError   15 s (le kubelet réessaie ; un Secret en
#                                cours d'écriture peut arriver) ;
#   ErrImagePull/ImagePullBackOff 60 s, comptés ensemble (le kubelet alterne
#                                les deux ; un registre momentanément
#                                indisponible aboutit à +10 s, +30 s).
# Toute autre raison (`ContainerCreating`, `PodInitializing`,
# `CrashLoopBackOff`…) est ignorée : l'attente continue jusqu'au délai.
#
# Aucune valeur de secret : seuls les statuts des pods sont lus, jamais un
# Secret. `state.waiting.message` vient du kubelet et cite des NOMS
# (« couldn't find key FOO in Secret preprod/backend-secrets »).
#
# Bornes : au plus timeout/interval + 1 tranches, et arrêt dès que l'horloge
# dépasse le délai ; chaque tranche dure au plus interval s (+ 10 s de
# requête), chaque lecture des pods au plus 10 s. Dépassement maximal :
# ~interval + 20 s. Une tranche qui revient avant son terme (erreur de
# l'API) est complétée par une pause, pour ne pas marteler l'API.
#
# Usage :  tools/wait-rollout.sh <namespace> <deployment/NOM|job/NOM>
#            [--timeout 180]   secondes d'attente au total (entier ≥ 0)
#            [--interval 5]    secondes par tranche (entier ≥ 1)
# Sortie : 0 prêt ; 1 raison fatale persistante, Job en échec ou délai
#          dépassé ; 2 usage.
# Env :    WAIT_ROLLOUT_KUBECTL, WAIT_ROLLOUT_SLEEP, WAIT_ROLLOUT_CLOCK
#          binaires de substitution (tests hors ligne ; l'horloge imprime
#          un horodatage en secondes)
# ---------------------------------------------------------------------------
set -euo pipefail

REQUEST_TIMEOUT="10s"
# Délai de persistance (s) par classe de raison fatale.
declare -A GRACE=([image-name]=0 [config]=15 [pull]=60)

namespace=""; target=""; timeout=180; interval=5

usage_error() { echo "wait-rollout.sh : $*" >&2; echo "usage : $0 <namespace> <deployment/NOM|job/NOM> [--timeout 180] [--interval 5]" >&2; exit 2; }

while [ $# -gt 0 ]; do
  case "$1" in
    --timeout|--interval)
      [ $# -ge 2 ] || usage_error "$1 attend une valeur"
      if [ "$1" = "--timeout" ]; then timeout="$2"; else interval="$2"; fi
      shift ;;
    # L'en-tête de commentaires, jusqu'à la première ligne de code.
    -h|--help) sed -n '2,/^[^#]/{/^#/p}' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*) usage_error "option inconnue « $1 »" ;;
    *) if [ -z "$namespace" ]; then namespace="$1"
       elif [ -z "$target" ]; then target="$1"
       else usage_error "argument en trop « $1 »"; fi ;;
  esac
  shift
done

[ -n "$namespace" ] || usage_error "namespace manquant"
[ -n "$target" ] || usage_error "cible manquante (deployment/NOM ou job/NOM)"
[[ "$target" =~ ^(deployment|job)/[a-z0-9]([-a-z0-9.]*[a-z0-9])?$ ]] || usage_error "cible « $target » : deployment/NOM ou job/NOM attendu"
[[ "$timeout" =~ ^[0-9]+$ ]] || usage_error "--timeout attend un entier ≥ 0 (reçu « $timeout »)"
[[ "$interval" =~ ^[0-9]+$ ]] && [ "$interval" -ge 1 ] || usage_error "--interval attend un entier ≥ 1 (reçu « $interval »)"
command -v jq >/dev/null || { echo "wait-rollout.sh : outil requis absent : jq" >&2; exit 1; }

kind="${target%%/*}"; name="${target#*/}"
kubectl_bin="${WAIT_ROLLOUT_KUBECTL:-kubectl}"
sleep_bin="${WAIT_ROLLOUT_SLEEP:-sleep}"
clock_bin="${WAIT_ROLLOUT_CLOCK:-}"
now() { if [ -n "$clock_bin" ]; then "$clock_bin"; else date +%s; fi; }

if [ "$kind" = "deployment" ]; then resources="deployments,replicasets,pods"; else resources="jobs,pods"; fi

# Une ligne par fait, champs séparés par US (\x1f, cf. wait-external-
# secrets.sh : `read` fusionnerait des tabulations consécutives) :
#   STATE <état> [raison] [message]   état : observed | unobserved | absent
#                                     | no-replicaset | failed (Job) ;
#   WAIT <pod> <conteneur> <init:true|false> <raison> <message>
#     un par conteneur des pods de la révision en cours dont la raison
#     d'attente est fatale.
# shellcheck disable=SC2016  # $… ci-dessous sont des variables jq
JQ_INSPECT='
  def oneline: (. // "") | gsub("[\r\n]+"; " ");
  def waits($items; $owner):
    ["InvalidImageName", "CreateContainerConfigError", "ErrImagePull", "ImagePullBackOff"] as $fatal
    | $items[]
    | select(.kind == "Pod" and .metadata.deletionTimestamp == null)
    | select(any(.metadata.ownerReferences[]?; .uid == $owner))
    | .metadata.name as $pod
    | ((.status.initContainerStatuses // [] | map(. + {init: true}))
       + (.status.containerStatuses // [] | map(. + {init: false})))[]
    | select((.state.waiting.reason // "") | IN($fatal[]))
    | ["WAIT", $pod, .name, (.init | tostring), .state.waiting.reason, (.state.waiting.message | oneline)]
    | join("\u001f");
  (.items // []) as $items
  | if $kind == "deployment" then
      ([$items[] | select(.kind == "Deployment" and .metadata.name == $name)][0]) as $d
      | if $d == null then "STATE\u001fabsent"
        elif ($d.status.observedGeneration // 0) < $d.metadata.generation then "STATE\u001funobserved"
        else
          ($d.metadata.annotations["deployment.kubernetes.io/revision"] // "") as $rev
          | ([$items[] | select(.kind == "ReplicaSet")
              | select(any(.metadata.ownerReferences[]?; .uid == $d.metadata.uid))
              | select((.metadata.annotations // {})["deployment.kubernetes.io/revision"] == $rev)][0]) as $rs
          | if $rs == null then "STATE\u001fno-replicaset"
            else "STATE\u001fobserved", waits($items; $rs.metadata.uid) end
        end
    else
      ([$items[] | select(.kind == "Job" and .metadata.name == $name)][0]) as $j
      | if $j == null then "STATE\u001fabsent"
        else
          ([$j.status.conditions // [] | .[] | select(.type == "Failed" and .status == "True")][0]) as $f
          | if $f != null then ["STATE", "failed", ($f.reason // "sans raison"), ($f.message | oneline)] | join("\u001f")
            else "STATE\u001fobserved", waits($items; $j.metadata.uid) end
        end
    end'

class_of() {
  case "$1" in
    InvalidImageName) echo image-name ;;
    CreateContainerConfigError) echo config ;;
    *) echo pull ;;
  esac
}

declare -A first_seen=()   # « pod|conteneur|classe » -> première observation
pending=()                 # raisons fatales observées, sous leur seuil
fatal=()                   # raisons fatales qui ont dépassé leur seuil
job_failed=""

# Lit l'état une fois, met à jour first_seen, remplit pending/fatal/job_failed.
# Échoue (retour 1) si kubectl échoue ou si sa réponse n'est pas le JSON
# attendu ; jq est joué dans une substitution dont on teste le code.
inspect() {
  local json parsed line tag a b c d e t cls key age where
  json="$("$kubectl_bin" --request-timeout="$REQUEST_TIMEOUT" -n "$namespace" get "$resources" -o json)" || return 1
  parsed="$(jq -r --arg kind "$kind" --arg name "$name" "$JQ_INSPECT" <<<"$json" 2>/dev/null)" || return 1
  [ -n "$parsed" ] || return 1
  t="$(now)"
  pending=(); fatal=()
  declare -A seen=()
  while IFS= read -r line; do
    IFS=$'\x1f' read -r tag a b c d e <<<"$line"
    case "$tag" in
      STATE) if [ "$a" = "failed" ]; then job_failed="$b : $c"; fi ;;
      WAIT)
        cls="$(class_of "$d")"; key="$a|$b|$cls"
        seen[$key]=1
        [ -n "${first_seen[$key]:-}" ] || first_seen[$key]="$t"
        age=$((t - first_seen[$key]))
        if [ "$c" = "true" ]; then where="initContainer $b"; else where="conteneur $b"; fi
        if [ "$age" -ge "${GRACE[$cls]}" ]; then
          fatal+=("pod $a, $where : $d depuis ${age} s — $e")
        else
          pending+=("pod $a, $where : $d depuis ${age} s (seuil ${GRACE[$cls]} s) — $e")
        fi ;;
    esac
  done <<<"$parsed"
  # Une raison qui a disparu (conteneur démarré, pod remplacé) repart de zéro.
  for key in "${!first_seen[@]}"; do
    [ -n "${seen[$key]:-}" ] || unset "first_seen[$key]"
  done
  return 0
}

slice() {
  if [ "$kind" = "deployment" ]; then
    "$kubectl_bin" --request-timeout="$((interval + 10))s" -n "$namespace" rollout status "deployment/$name" --timeout="${interval}s"
  else
    "$kubectl_bin" --request-timeout="$((interval + 10))s" -n "$namespace" wait --for=condition=complete "job/$name" --timeout="${interval}s"
  fi
}

err_file="$(mktemp)"
trap 'rm -f "$err_file"' EXIT

echo "Attente de $target dans $namespace (au plus ${timeout} s, échec anticipé sur raison fatale)"
start="$(now)"
deadline=$((start + timeout))
max_rounds=$((timeout / interval + 1))
last_printed=""
inspect_ok=1
for ((round = 1; round <= max_rounds; round++)); do
  slice_start="$(now)"
  if slice_out="$(slice 2>"$err_file")"; then
    printf '%s\n' "$slice_out"
    exit 0
  fi
  # La progression de kubectl, sans répéter une ligne inchangée.
  if [ -n "$slice_out" ] && [ "$slice_out" != "$last_printed" ]; then
    printf '%s\n' "$slice_out"; last_printed="$slice_out"
  fi

  if inspect; then
    inspect_ok=1
  else
    inspect_ok=0
    echo "::warning::lecture des pods de $target impossible au tour $round/$max_rounds, nouvel essai"
  fi
  if [ -n "$job_failed" ]; then
    echo "::error::$target en échec dans $namespace ($job_failed) : le Job ne réessaiera plus."
    exit 1
  fi
  if [ "${#fatal[@]}" -gt 0 ]; then
    for f in "${fatal[@]}"; do
      echo "::error::$target bloqué dans $namespace : $f"
    done
    echo "::error::$target ne démarrera pas sans intervention : échec sans attendre la fin des ${timeout} s."
    exit 1
  fi

  t="$(now)"
  if [ "$t" -ge "$deadline" ]; then break; fi
  # Tranche revenue avant son terme (erreur de l'API, objet introuvable) :
  # on complète l'intervalle plutôt que d'enchaîner les appels.
  if [ $((t - slice_start)) -lt "$interval" ]; then "$sleep_bin" $((interval - (t - slice_start))); fi
done

last_error="$(tr '\n' ' ' < "$err_file" | sed 's/ *$//')"
echo "::error::$target pas prêt dans $namespace après ${timeout} s${last_error:+ ($last_error)}."
if [ "$inspect_ok" -eq 0 ]; then
  echo "::error::$target : la dernière lecture des pods a échoué, leur état n'est pas connu."
elif [ "${#pending[@]}" -gt 0 ]; then
  for p in "${pending[@]}"; do echo "::error::$target, au dernier tour : $p"; done
fi
exit 1
```

- [ ] **Étape 4 : le banc passe**

Run : `tools/tests/wait-rollout.test.sh`
Attendu : 33 lignes `ok`, puis `tous les cas passent`, sortie 0.

- [ ] **Étape 5 : vérifier que le banc mord, par mutations**

Un banc vert du premier coup ne prouve rien. Appliquer chaque mutation ci-dessous, constater au moins
un `FAIL`, puis restaurer le fichier :

```bash
cp tools/wait-rollout.sh /tmp/wr.orig
mut() { sed -i "$1" tools/wait-rollout.sh; echo "== $2"; tools/tests/wait-rollout.test.sh | grep -E 'FAIL|[0-9] échec' | head -3; cp /tmp/wr.orig tools/wait-rollout.sh; }
mut 's/\[config\]=15/[config]=0/' "délai de config à 0"
mut 's/map(. + {init: true}))/map(. + {init: true}) | [])/' "initContainers ignorés"
mut 's/| select((.metadata.annotations \/\/ {})\["deployment.kubernetes.io\/revision"\] == $rev)//' "sans filtre de révision"
mut 's/elif (\$d.status.observedGeneration \/\/ 0) < \$d.metadata.generation then "STATE\\u001funobserved"/elif false then 1/' "sans garde de génération"
mut 's/select(any(.metadata.ownerReferences\[\]?; .uid == \$owner))//' "sans filtre de propriétaire"
mut 's/select(.kind == "Pod" and .metadata.deletionTimestamp == null)/select(.kind == "Pod")/' "pods en suppression inclus"
mut 's/if \[ \$((t - slice_start)) -lt "\$interval" \]; then .*$/:/' "sans pause après une tranche en erreur"
rm /tmp/wr.orig
git diff --exit-code tools/wait-rollout.sh   # le script est intact
```

Attendu : chaque mutation produit au moins une ligne `FAIL`, et le `git diff` final ne montre rien.
Ces sept mutations ont été jouées sur l'ébauche du 2026-10-06, et toutes ont été détectées.

- [ ] **Étape 6 : shellcheck au niveau de la CI**

shellcheck n'est pas installé sur l'hôte, et Docker Desktop ne monte que les chemins partagés : on
passe donc le fichier sur stdin.

```bash
for f in tools/wait-rollout.sh tools/tests/wait-rollout.test.sh; do
  timeout 110 docker run --rm -i koalaman/shellcheck:stable --severity=warning - < "$f" && echo "OK $f"
done
```

Attendu : `OK` pour chacun. La CI lance `shellcheck -x --severity=warning tools/*.sh tools/tests/*.sh`,
et les deux fichiers y entrent par glob, sans modification de la pipeline.

- [ ] **Étape 7 : commit**

```bash
git add tools/wait-rollout.sh tools/tests/wait-rollout.test.sh
git commit -F - <<'MSG'
feat(deploy): échouer tôt sur un conteneur qui ne démarrera pas (#353)

Un Secret absent, une coquille dans un secretKeyRef ou une clé hors du
data d'un ExternalSecret laissaient le pod en CreateContainerConfigError :
`rollout status` expirait après 180 s (300 s pour la migration), sans
cause dans les journaux.

tools/wait-rollout.sh découpe l'attente kubectl en tranches de 5 s et
inspecte entre deux tranches les pods de la révision en cours (conteneurs
et initContainers). Il échoue sur InvalidImageName aussitôt, sur
CreateContainerConfigError persistant 15 s, sur ErrImagePull/ImagePullBackOff
persistant 60 s, et sur un Job Failed=True, en nommant pod, conteneur,
raison et message du kubelet. Aucun Secret n'est lu.

Banc hors ligne : tools/tests/wait-rollout.test.sh (horloge factice).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01XZiu7kTd8hNbTVqphpjfRZ
MSG
```

---

### Tâche 2 : brancher le script dans `deploy-preprod` et `deploy-prod`, et documenter

**Fichiers :**
- Modifier : `.github/workflows/pipeline.yml`. Dans le job `tools-tests`, la liste des bancs. Dans
  `deploy-preprod` (l. 599 et ~710-747) et `deploy-prod` (l. 885 et ~1037-1057), le commentaire
  `timeout-minutes` et les sept attentes de chaque étape `Deploy` : migration, backend, frontend,
  postgres, rabbitmq, worker, et seed pour la préprod.
- Modifier : `.claude/rules/deploiement.md`. Ajouter une puce après celle de #325 (ExternalSecret)
  et une précision dans celle de #175.

**Interfaces :**
- Consomme : `tools/wait-rollout.sh <ns> <deployment/NOM|job/NOM> --timeout N` (Tâche 1). Sortie 0
  si prêt, 1 sinon.
- Produit : rien de consommé par une autre tâche.

- [ ] **Étape 1 : le constat qui doit changer (RED)**

```bash
grep -n -E 'kubectl -n (preprod|prod) (rollout status|wait --for=condition=complete)' .github/workflows/pipeline.yml
```

Attendu avant modification : 13 lignes. On en compte 7 dans `deploy-preprod` (migrate, backend,
frontend, postgres, rabbitmq, worker, seed) et 6 dans `deploy-prod`. S'y ajoutent les 3
`rollout status` de `smoke-test-preprod` (l. 801-803), qui ne sont pas des attentes de déploiement et
**restent tels quels** : 16 en tout. Après modification, il ne doit rester que ces 3 lignes de
`smoke-test-preprod`.

- [ ] **Étape 2 : `deploy-preprod`**

Dans l'étape `Deploy` de `deploy-preprod` :

a) Remplacer

```yaml
          kubectl -n preprod wait --for=condition=complete --timeout=300s job/backend-migrate \
            || { kubectl -n preprod logs job/backend-migrate --tail=200; exit 1; }
```

par

```yaml
          #    Attente par tools/wait-rollout.sh (issue #353), pas `kubectl wait` :
          #    même sémantique « terminé », mais un conteneur qui ne démarrera
          #    pas (CreateContainerConfigError, échec de pull) ou un Job en
          #    `Failed` font échouer en quelques secondes, en nommant le pod, le
          #    conteneur et la raison, au lieu d'attendre les 300 s. `|| true`
          #    sur les logs : un conteneur jamais démarré n'en a pas.
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" preprod job/backend-migrate --timeout 300 \
            || { kubectl -n preprod logs job/backend-migrate --tail=200 || true; exit 1; }
```

b) Remplacer

```yaml
          kubectl -n preprod rollout status deployment/backend --timeout=180s
          kubectl -n preprod rollout status deployment/frontend --timeout=180s
```

par

```yaml
          #    Même script pour les Deployments (issue #353) : seuls les pods de
          #    la révision en cours sont jugés, jamais ceux d'un rollout
          #    précédent resté bloqué.
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" preprod deployment/backend --timeout 180
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" preprod deployment/frontend --timeout 180
```

c) Remplacer

```yaml
          kubectl -n preprod rollout status deployment/postgres --timeout=300s
          kubectl -n preprod rollout status deployment/rabbitmq --timeout=300s
          kubectl -n preprod rollout status deployment/worker --timeout=180s
```

par

```yaml
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" preprod deployment/postgres --timeout 300
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" preprod deployment/rabbitmq --timeout 300
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" preprod deployment/worker --timeout 180
```

d) Remplacer

```yaml
          kubectl -n preprod wait --for=condition=complete --timeout=180s job/backend-seed \
            || { kubectl -n preprod logs job/backend-seed --tail=200; exit 1; }
```

par

```yaml
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" preprod job/backend-seed --timeout 180 \
            || { kubectl -n preprod logs job/backend-seed --tail=200 || true; exit 1; }
```

e) Ligne 599, remplacer le commentaire de `timeout-minutes: 45` par :

```yaml
    timeout-minutes: 45 # attentes kubectl cumulées ≤ 36 min (secrets ESO, fenêtre de maintenance, migration, rollouts, seed ; chaque wait-rollout.sh peut dépasser son délai de ~25 s)
```

Calcul : 120 (ESO) + 240 (fenêtre) + 300 + 180 + 180 + 300 + 300 + 180 + 180 = 1 980 s, soit 33 min.
On ajoute 7 × 25 s de dépassement possible (une tranche de 5 s, sa requête bornée à 15 s, puis une
lecture bornée à 10 s), soit ~3 min : ≤ 36 min, sous les 45.

- [ ] **Étape 3 : `deploy-prod`**

Mêmes remplacements dans l'étape `Deploy` de `deploy-prod`, avec `prod` à la place de `preprod`.
La production n'a pas de seed. On remplace la migration (avec le même bloc de commentaire qu'en
2a), puis backend, frontend, postgres, rabbitmq et worker :

```yaml
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" prod job/backend-migrate --timeout 300 \
            || { kubectl -n prod logs job/backend-migrate --tail=200 || true; exit 1; }
```

```yaml
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" prod deployment/backend --timeout 180
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" prod deployment/frontend --timeout 180
```

```yaml
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" prod deployment/postgres --timeout 300
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" prod deployment/rabbitmq --timeout 300
          "$GITHUB_WORKSPACE/tools/wait-rollout.sh" prod deployment/worker --timeout 180
```

Ligne 885 :

```yaml
    timeout-minutes: 45 # attentes kubectl cumulées ≤ 33 min (secrets ESO, fenêtre de maintenance, migration, rollouts ; chaque wait-rollout.sh peut dépasser son délai de ~25 s)
```

Calcul : 120 + 240 + 300 + 180 + 180 + 300 + 300 + 180 = 1 800 s, soit 30 min. On ajoute 6 × 25 s,
soit ≤ 33 min.

- [ ] **Étape 4 : `tools-tests`**

Dans le job `tools-tests`, ajouter après `tools/tests/wait-external-secrets.test.sh` :

```yaml
          tools/tests/wait-rollout.test.sh
```

- [ ] **Étape 5 : règle `deploiement.md`**

Lire d'abord `.claude/rules/deploiement.md` avec `Read`. Ajouter, juste après la puce « **Every
ExternalSecret of the release is synced before anything is migrated or rolled out** (issue #325) »,
la puce suivante :

```markdown
- **Every deploy wait fails early on a container that will not start** (issue #353). The `Deploy`
  steps never call `kubectl rollout status` or `kubectl wait --for=condition=complete` directly:
  `tools/wait-rollout.sh <ns> deployment/NAME|job/NAME --timeout N` cuts kubectl's own wait into
  5 s slices — so "done" keeps kubectl's meaning — and between slices reads the pods of the
  *current* revision only: for a Deployment, the ReplicaSet whose `deployment.kubernetes.io/revision`
  matches, once `status.observedGeneration` has caught up (pods of an earlier stuck rollout would be
  a false positive); for a Job, the pods owned by its uid (the previous `backend-migrate`, just
  deleted, may still be around). Containers **and** initContainers. It fails naming pod, container,
  reason and the kubelet's message — which names the missing key or Secret, never a value — on
  `InvalidImageName` at once, `CreateContainerConfigError` persisting 15 s, `ErrImagePull` and
  `ImagePullBackOff` persisting 60 s **together** (the kubelet alternates them); a Job with
  `Failed=True` fails at once instead of waiting out its timeout. `CrashLoopBackOff` is deliberately
  not fatal. It never reads a Secret and needs nothing beyond the deployer Role's `get/list/watch`.
  Each wait may overrun its timeout by ~25 s, counted in `timeout-minutes`. Fail-closed is
  unchanged: a failure before `apply -k` leaves the previous release serving; after it, no automatic
  rollback in prod, and `rollback-preprod` still runs only on a successful deploy. The 60 s
  `rollout status` checks of `smoke-test-preprod` are not deploy waits and stay. Offline test:
  `tools/tests/wait-rollout.test.sh`.
```

Dans la puce de #175, remplacer « then `kubectl apply -k .` + `rollout status`. » par « then
`kubectl apply -k .` + the rollout waits (`tools/wait-rollout.sh`, see below). » et « (300 s
timeout) » par « (300 s timeout, `tools/wait-rollout.sh`) ».

- [ ] **Étape 6 : vérifications (GREEN)**

```bash
grep -n -E 'kubectl -n (preprod|prod) (rollout status|wait --for=condition=complete)' .github/workflows/pipeline.yml
#  -> seulement les 3 lignes de smoke-test-preprod (l. ~801-803 + décalage)
grep -c 'tools/wait-rollout.sh' .github/workflows/pipeline.yml
#  -> 14 : 13 appels + la ligne de tools-tests
tools/check-workflow-timeouts.sh && echo TIMEOUTS-OK
tools/check-claude-rules.sh && echo RULES-OK
tools/tests/wait-rollout.test.sh | tail -1
timeout 110 docker run --rm -i rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -color - < .github/workflows/pipeline.yml && echo ACTIONLINT-OK
```

Attendu : 3 lignes, puis 14, `TIMEOUTS-OK`, `RULES-OK`, `tous les cas passent` et `ACTIONLINT-OK`.
Le nombre 14 ne compte que les lignes contenant la chaîne : deux tests ne seraient pas plus probants.
Si l'image actionlint n'accepte pas `-` sur stdin, copier le workflow dans un répertoire partagé avec
Docker, par exemple sous le dépôt, sans l'indexer, et lancer `actionlint` dessus.

- [ ] **Étape 7 : commit**

```bash
git add .github/workflows/pipeline.yml .claude/rules/deploiement.md
git commit -F - <<'MSG'
ci(deploy): attendre les rollouts par wait-rollout.sh (#353)

Les 13 attentes des étapes Deploy (migration, rollouts, seed en préprod)
passent par tools/wait-rollout.sh : un conteneur bloqué au démarrage ou
un Job en échec font échouer le job en quelques secondes au lieu de
180/300 s. Les `rollout status` de smoke-test-preprod, contrôles d'état
après coup, restent inchangés. timeout-minutes inchangé (45), son
commentaire recalculé avec le dépassement possible de chaque attente.
Banc ajouté à tools-tests ; règle deploiement.md complétée.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01XZiu7kTd8hNbTVqphpjfRZ
MSG
```

---

### Tâche 3 : expérience en préprod (forme réelle des objets et des messages)

Les fixtures de la Tâche 1 supposent trois choses. D'abord, `kubectl get deployments,replicasets,pods
-o json` rend une `List` dont chaque item porte `kind`. Ensuite, `state.waiting.message` d'un
`CreateContainerConfigError` nomme la clé ou le Secret manquant. Enfin, `rollout status --timeout=5s`
sort en 1 sur une tranche expirée. Les deux premières ne se vérifient que sur un vrai cluster.
**Christophe la joue dans un terminal séparé** : le kubeconfig de la préprod est un identifiant, et il
ne passe pas dans une session d'agent.

**Fichiers :** aucun, sauf si l'expérience contredit une fixture. On corrige alors la fixture et le
script, en TDD, dans un commit de la Tâche 1 bis.

- [ ] **Étape 1 : un Deployment jetable qui référence une clé absente**

```bash
NS=preprod
# Une seule révision, dont le conteneur référence une clé d'un Secret qui
# n'existe pas (pas `kubectl set env --from=secret/…` : il lit le Secret
# côté client et échouerait avant de créer quoi que ce soit).
kubectl -n "$NS" apply -f - <<'EOF'
apiVersion: apps/v1
kind: Deployment
metadata: {name: wr353-probe}
spec:
  replicas: 1
  selector: {matchLabels: {app: wr353-probe}}
  template:
    metadata: {labels: {app: wr353-probe}}
    spec:
      automountServiceAccountToken: false
      containers:
        - name: busybox
          image: busybox:1.37
          command: [sleep, "3600"]
          env:
            - name: PROBE
              valueFrom: {secretKeyRef: {name: wr353-absent, key: K}}
EOF
tools/wait-rollout.sh "$NS" deployment/wr353-probe --timeout 120; echo "rc=$?"
```

Attendu : `rc=1` en 20 à 25 s environ, avec une ligne `::error::deployment/wr353-probe bloqué dans
preprod : pod wr353-probe-…, conteneur busybox : CreateContainerConfigError depuis 1x s — secret
"wr353-absent" not found` ou équivalent. Noter le message exact.

> PSA `enforce: baseline` du namespace : busybox sans `securityContext` passe en `baseline`, et
> `warn: restricted` affiche un avertissement sans bloquer.
> Le Role déployeur n'a pas `delete` sur `deployments` : l'expérience se joue avec un compte
> administrateur du cluster, qui sert aussi au nettoyage.

- [ ] **Étape 2 : nettoyage**

```bash
kubectl -n "$NS" delete deployment wr353-probe
```

- [ ] **Étape 3 : consigner**

Coller dans le commentaire de clôture de l'issue #353 le message exact relevé, sans valeur. S'il
diffère de la forme attendue (une `List` sans `kind` par item, par exemple), ouvrir la Tâche 1 bis
avant la clôture de la branche.

---

## Suites et hors périmètre

- `CrashLoopBackOff` n'est pas une raison fatale, par arbitrage du 2026-10-06 : les redémarrages
  `Recreate` de postgres et rabbitmq ainsi que le worker en attente de RabbitMQ donneraient des faux
  positifs. À rouvrir dans une issue dédiée si le besoin se présente.
- `smoke-test-preprod` garde ses trois `rollout status --timeout=60s` : ce sont des contrôles d'état
  après le déploiement, pas des attentes de rollout.
- Le premier vrai déclenchement viendra de la release qui embarque cette branche : lire le journal de
  `deploy-preprod` (une ligne `Attente de … (au plus … s, échec anticipé sur raison fatale)` par
  attente).
