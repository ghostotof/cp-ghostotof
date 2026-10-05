#!/usr/bin/env bash
#
# wait-external-secrets.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/wait-external-secrets.sh (issue #325) : un faux
# `kubectl` (WAIT_ES_KUBECTL) rejoue, un appel `get externalsecrets` après
# l'autre, des listes JSON préparées par le test (round-1.json, round-2.json…,
# la dernière étant resservie), et note chacun de ses appels. Un faux `sleep`
# (WAIT_ES_SLEEP) ne dort pas : la suite tourne en une seconde.
#
# Les fixtures reproduisent la forme réelle du statut d'ESO v2.9.0, relevée
# sur la préprod le 2026-10-05 : `status.syncedResourceVersion` vaut
# `<generation>-<hash>` et n'est posé qu'après une synchronisation RÉUSSIE ;
# un échec laisse la condition `Ready` à False, raison `SecretSyncedError`,
# message générique (« could not get secret data from provider »).
#
# Ce qui est pincé : un ES synchronisé sur sa génération courante passe ; un
# `Ready=True` hérité de la génération précédente ne passe PAS (le faux vert
# du cas même que l'issue vise : une release qui ajoute une clé) ; un échec
# persistant fait sortir en 1 en nommant l'ES, sa raison et les noms de ses
# clés distantes ; `force-sync` est posé une seule fois, et seulement sur
# les ES non prêts ; un ES annoté facultatif ne bloque pas ; un ES rétabli au
# second tour passe ; le nombre de tours est borné ; aucun Secret n'est lu.
#
# Usage :  tools/tests/wait-external-secrets.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/wait-external-secrets.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

# Valeur qu'un `kubectl get secret` rendrait : elle ne doit apparaître dans
# AUCUNE sortie du script (critère « aucune valeur de secret dans les
# journaux »). Le script n'a aucune raison de lire un Secret.
SENTINEL="VALEUR-DE-SECRET-NE-DOIT-JAMAIS-FUITER"

# Le faux kubectl : `get externalsecrets` sert $FAKE_DIR/round-<n>.json (n =
# numéro de l'appel, plafonné au dernier fichier présent) ; `get secret…`
# imprime la sentinelle ; tout appel est noté dans $FAKE_DIR/args.
cat > "$TMP/kubectl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_DIR/args"
case " $* " in
  *" get externalsecrets "*|*" get externalsecret "*)
    n=$(( $(grep -c ' get externalsecret' "$FAKE_DIR/args") ))
    while [ "$n" -gt 1 ] && [ ! -f "$FAKE_DIR/round-$n.json" ]; do n=$((n - 1)); done
    cat "$FAKE_DIR/round-$n.json"
    ;;
  *" secret "*|*" secrets "*)
    printf '{"data":{"APP_SECRET":"%s"}}\n' "$SENTINEL"
    ;;
  *" annotate "*)
    ;;
  *)
    echo "faux kubectl : appel inattendu « $* »" >&2; exit 99 ;;
esac
EOF
cat > "$TMP/sleep" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "$TMP/kubectl" "$TMP/sleep"

# es <nom> <generation> <syncedResourceVersion|""> <True|False> <reason> [optional]
# Un ExternalSecret complet, tel que `kubectl get externalsecrets -o json` le
# rend : deux clés distantes, et l'annotation de facultatif si demandé.
es() {
  local name="$1" gen="$2" srv="$3" ready="$4" reason="$5" optional="${6:-}"
  local message="secret synced" annotations='{}' srv_field=''
  [ "$ready" = "False" ] && message="could not get secret data from provider"
  [ -n "$optional" ] && annotations='{"cp-ghostotof.com/deploy-gate":"optional"}'
  [ -n "$srv" ] && srv_field=",\"syncedResourceVersion\":\"$srv\""
  cat <<JSON
{"apiVersion":"external-secrets.io/v1","kind":"ExternalSecret",
 "metadata":{"name":"$name","namespace":"preprod","generation":$gen,"annotations":$annotations},
 "spec":{"refreshInterval":"1h","secretStoreRef":{"kind":"SecretStore","name":"scaleway-secret-manager"},
         "target":{"name":"$name","creationPolicy":"Owner"},
         "data":[{"secretKey":"A","remoteRef":{"key":"name:preprod-$name-a"}},
                 {"secretKey":"B","remoteRef":{"key":"name:preprod-$name-b"}}]},
 "status":{"binding":{"name":"$name"},
           "conditions":[{"type":"Ready","status":"$ready","reason":"$reason","message":"$message","lastTransitionTime":"2026-10-05T12:00:00Z"}],
           "refreshTime":"2026-10-05T12:00:00Z"$srv_field}}
JSON
}

# round <n> <es-json>...  — la liste servie au n-ième `get externalsecrets`.
round() {
  local n="$1"; shift
  local IFS=,
  printf '{"apiVersion":"v1","kind":"List","items":[%s]}\n' "$*" > "$FAKE_DIR/round-$n.json"
}

# run <args>…  — lance le script ; sortie fusionnée dans $FAKE_DIR/out, code
# de sortie dans $rc.
run() {
  set +e
  WAIT_ES_KUBECTL="$TMP/kubectl" WAIT_ES_SLEEP="$TMP/sleep" SENTINEL="$SENTINEL" \
    "$SCRIPT" "$@" > "$FAKE_DIR/out" 2>&1
  rc=$?
  set -e
}

# Chaque cas repart d'un répertoire vierge.
new_case() { FAKE_DIR="$TMP/$1"; mkdir -p "$FAKE_DIR"; : > "$FAKE_DIR/args"; export FAKE_DIR; }
get_calls() { grep -c ' get externalsecret' "$FAKE_DIR/args" || true; }

echo "wait-external-secrets.sh"

# --- 1. nominal : tout est synchronisé sur sa génération courante -----------
new_case nominal
round 1 "$(es backend-secrets 3 3-0b84e255 True SecretSynced)" "$(es jwt-keys 1 1-aa11 True SecretSynced)"
run preprod backend-secrets jwt-keys --timeout 10 --interval 5
if [ "$rc" -eq 0 ] && [ "$(get_calls)" -eq 1 ]; then pass "ES synchronisés : sortie 0 dès le premier tour"
else fail "ES synchronisés : sortie 0 dès le premier tour" "rc=$rc, appels get=$(get_calls) ; $(cat "$FAKE_DIR/out")"; fi
if ! grep -q ' annotate ' "$FAKE_DIR/args"; then pass "aucun force-sync quand tout est prêt"
else fail "aucun force-sync quand tout est prêt" "$(grep ' annotate ' "$FAKE_DIR/args")"; fi

# --- 2. Ready=True hérité de la génération précédente : PAS prêt ------------
# La release vient de modifier la spec (generation 4) ; ESO n'a pas encore
# réconcilié, le statut décrit encore la génération 3. Il ne doit jamais
# passer pour synchronisé.
new_case stale
round 1 "$(es backend-secrets 4 3-0b84e255 True SecretSynced)"
run preprod backend-secrets --timeout 10 --interval 5
if [ "$rc" -eq 1 ] && grep -q 'backend-secrets' "$FAKE_DIR/out"; then pass "Ready=True d'une génération périmée : sortie 1 nommant l'ES"
else fail "Ready=True d'une génération périmée : sortie 1 nommant l'ES" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 3. clé absente de Secret Manager : échec persistant --------------------
new_case missing
round 1 "$(es backend-secrets 5 4-0b84e255 False SecretSyncedError)" "$(es jwt-keys 1 1-aa11 True SecretSynced)"
run preprod backend-secrets jwt-keys --timeout 10 --interval 5
out="$(cat "$FAKE_DIR/out")"
if [ "$rc" -eq 1 ]; then pass "SecretSyncedError persistant : sortie 1"
else fail "SecretSyncedError persistant : sortie 1" "rc=$rc ; $out"; fi
if grep -q '^::error::.*backend-secrets.*SecretSyncedError' "$FAKE_DIR/out"; then pass "annotation ::error:: nommant l'ES et la raison"
else fail "annotation ::error:: nommant l'ES et la raison" "$out"; fi
if grep -q 'name:preprod-backend-secrets-a' "$FAKE_DIR/out" && grep -q 'name:preprod-backend-secrets-b' "$FAKE_DIR/out"; then
  pass "les noms des clés distantes sont listés (ESO ne dit pas laquelle manque)"
else fail "les noms des clés distantes sont listés (ESO ne dit pas laquelle manque)" "$out"; fi
if ! grep -q '^::error::.*jwt-keys' "$FAKE_DIR/out"; then pass "un ES prêt n'est pas mis en cause"
else fail "un ES prêt n'est pas mis en cause" "$out"; fi
# Trois tours : t=0, t=5, t=10 — puis abandon.
if [ "$(get_calls)" -eq 3 ]; then pass "attente bornée : timeout/interval + 1 tours"
else fail "attente bornée : timeout/interval + 1 tours" "appels get=$(get_calls)"; fi
annotates="$(grep ' annotate ' "$FAKE_DIR/args" || true)"
if [ "$(printf '%s\n' "$annotates" | grep -c 'externalsecret backend-secrets force-sync=[0-9]* --overwrite')" -eq 1 ] \
   && [ "$(printf '%s\n' "$annotates" | grep -c .)" -eq 1 ]; then
  pass "force-sync posé une fois, sur le seul ES non prêt"
else fail "force-sync posé une fois, sur le seul ES non prêt" "annotate : ${annotates:-aucun}"; fi
if grep -q -- '-n preprod' <<<"$annotates"; then pass "force-sync dans le bon namespace"
else fail "force-sync dans le bon namespace" "annotate : ${annotates:-aucun}"; fi

# --- 4. facultatif en erreur : ne bloque pas --------------------------------
# Situation réelle de la préprod le 2026-10-05 : backend-xdebug-trigger en
# SecretSyncedError, jamais synchronisé (pas de syncedResourceVersion).
new_case optional
round 1 "$(es backend-secrets 3 3-0b84e255 True SecretSynced)" "$(es backend-xdebug-trigger 1 '' False SecretSyncedError optional)"
run preprod backend-secrets backend-xdebug-trigger --timeout 10 --interval 5
if [ "$rc" -eq 0 ]; then pass "ES facultatif en erreur : sortie 0"
else fail "ES facultatif en erreur : sortie 0" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi
if grep -q '^::warning::.*backend-xdebug-trigger' "$FAKE_DIR/out"; then pass "ES facultatif en erreur : signalé en ::warning::"
else fail "ES facultatif en erreur : signalé en ::warning::" "$(cat "$FAKE_DIR/out")"; fi

# --- 5. le même ES SANS l'annotation est obligatoire ------------------------
new_case optional-unmarked
round 1 "$(es backend-xdebug-trigger 1 '' False SecretSyncedError)"
run preprod backend-xdebug-trigger --timeout 10 --interval 5
if [ "$rc" -eq 1 ]; then pass "sans annotation, un ES en erreur bloque"
else fail "sans annotation, un ES en erreur bloque" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 6. rétabli au second tour (clé publiée, force-sync honoré) -------------
new_case recovers
round 1 "$(es backend-secrets 5 4-0b84e255 False SecretSyncedError)"
round 2 "$(es backend-secrets 5 5-1c95f366 True SecretSynced)"
run preprod backend-secrets --timeout 10 --interval 5
if [ "$rc" -eq 0 ] && [ "$(get_calls)" -eq 2 ]; then pass "ES rétabli au second tour : sortie 0"
else fail "ES rétabli au second tour : sortie 0" "rc=$rc, appels get=$(get_calls) ; $(cat "$FAKE_DIR/out")"; fi

# --- 7. ES introuvable dans le namespace ------------------------------------
new_case absent
round 1 "$(es jwt-keys 1 1-aa11 True SecretSynced)"
run preprod backend-secrets --timeout 0 --interval 5
if [ "$rc" -eq 1 ] && grep -q '^::error::.*backend-secrets' "$FAKE_DIR/out"; then pass "ES introuvable : sortie 1 nommant l'ES"
else fail "ES introuvable : sortie 1 nommant l'ES" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi

# --- 8. aucune valeur de secret, dans aucun cas ------------------------------
leaks="$(grep -rl "$SENTINEL" "$TMP"/*/out 2>/dev/null || true)"
secret_reads="$(grep -h -E ' (get|describe) secrets? ' "$TMP"/*/args 2>/dev/null || true)"
if [ -z "$leaks" ] && [ -z "$secret_reads" ]; then pass "aucun Secret lu, aucune valeur dans la sortie"
else fail "aucun Secret lu, aucune valeur dans la sortie" "fuites : ${leaks:-aucune} ; lectures : ${secret_reads:-aucune}"; fi

# --- 9. arguments invalides : sortie 2, rien n'est appelé --------------------
new_case usage
run preprod
if [ "$rc" -eq 2 ]; then pass "aucun ExternalSecret nommé : sortie 2"
else fail "aucun ExternalSecret nommé : sortie 2" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi
run
if [ "$rc" -eq 2 ]; then pass "aucun namespace : sortie 2"
else fail "aucun namespace : sortie 2" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi
run preprod backend-secrets --timeout abc
if [ "$rc" -eq 2 ]; then pass "--timeout non numérique : sortie 2"
else fail "--timeout non numérique : sortie 2" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi
run preprod backend-secrets --interval 0
if [ "$rc" -eq 2 ]; then pass "--interval nul : sortie 2"
else fail "--interval nul : sortie 2" "rc=$rc ; $(cat "$FAKE_DIR/out")"; fi
if [ ! -s "$FAKE_DIR/args" ]; then pass "arguments invalides : kubectl jamais appelé"
else fail "arguments invalides : kubectl jamais appelé" "$(cat "$FAKE_DIR/args")"; fi

echo
if [ "$failures" -gt 0 ]; then echo "$failures échec(s)"; exit 1; fi
echo "tous les cas passent"
