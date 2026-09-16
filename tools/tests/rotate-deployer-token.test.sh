#!/usr/bin/env bash
#
# rotate-deployer-token.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de tools/rotate-deployer-token.sh : un faux `kubectl`
# (ROTATE_KUBECTL) et un faux `gh` (ROTATE_GH) notent leurs arguments et
# rejouent des réponses pilotées par des variables d'environnement — le
# contexte, le jeton (un JWT de forme valide, signature factice), les deux
# réponses `auth can-i`. Aucun cluster, aucun réseau.
#
# Ce qui est pincé : l'environnement inconnu sort en 2 ; un jeton qui ne
# peut pas créer de Job, ou qui PEUT ouvrir un shell, n'est jamais publié ;
# au nominal `gh secret set KUBE_CONFIG_PREPROD --env preprod` est appelé
# avec le kubeconfig sur stdin ; le jeton n'apparaît dans AUCUN argument de
# processus ni sur stdout/stderr ; le fichier temporaire est supprimé ;
# `--dry-run` ne publie rien ; une durée tronquée par le cluster est signalée.
#
# Usage :  tools/tests/rotate-deployer-token.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/rotate-deployer-token.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

SIGNATURE="FAKESIGNATURE-ne-doit-jamais-fuiter"

# jwt <exp>  — un JWT de forme valide (header.payload.signature), payload
# base64url sans padding, signature factice reconnaissable.
jwt() {
  local header payload
  header="$(printf '{"alg":"RS256","kid":"test"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')"
  payload="$(printf '{"exp":%d,"sub":"system:serviceaccount:preprod:github-actions-deployer"}' "$1" | base64 -w0 | tr '+/' '-_' | tr -d '=')"
  printf '%s.%s.%s' "$header" "$payload" "$SIGNATURE"
}

# Le faux kubectl : note chaque appel (arguments, une ligne par appel) dans
# $FAKE_ARGS ; `config view` rend un kubeconfig minimal ; `create token`
# imprime $FAKE_TOKEN ; `auth can-i` répond selon FAKE_CAN_I_JOBS /
# FAKE_CAN_I_EXEC (et sort en 1 sur « no », comme le vrai) ; le chemin passé
# à --kubeconfig est noté dans $FAKE_KUBECONFIG_SEEN pour vérifier sa
# suppression une fois le script terminé.
cat > "$TMP/kubectl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf 'kubectl %s\n' "$*" >> "$FAKE_ARGS"
args=("$@")
case "${args[*]}" in
  *"config view"*)
    printf '{"clusters":[{"name":"c","cluster":{"server":"https://cluster.example.invalid:6443","certificate-authority-data":"Q0EtRkFDVElDRQ=="}}],"users":[{"name":"admin","user":{"token":"ADMIN-TOKEN-NE-DOIT-PAS-ETRE-COPIE"}}],"contexts":[{"name":"ctx","context":{"cluster":"c","user":"admin","namespace":"preprod"}}],"current-context":"ctx"}\n'
    ;;
  *"create token"*)
    printf '%s' "$FAKE_TOKEN"
    ;;
  *"auth can-i"*)
    for ((i = 0; i < ${#args[@]}; i++)); do
      [ "${args[$i]}" = "--kubeconfig" ] && printf '%s\n' "${args[$((i + 1))]}" >> "$FAKE_KUBECONFIG_SEEN"
    done
    case "${args[*]}" in
      *"create jobs"*) answer="${FAKE_CAN_I_JOBS:-yes}" ;;
      *"create pods/exec"*) answer="${FAKE_CAN_I_EXEC:-no}" ;;
      *) answer="no" ;;
    esac
    printf '%s\n' "$answer"
    [ "$answer" = "yes" ]
    ;;
  *) echo "faux kubectl : appel inattendu : $*" >&2; exit 99 ;;
esac
EOF
chmod +x "$TMP/kubectl"

# Le faux gh : note ses arguments et copie stdin dans $FAKE_GH_STDIN.
cat > "$TMP/gh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf 'gh %s\n' "$*" >> "$FAKE_ARGS"
cat > "$FAKE_GH_STDIN"
EOF
chmod +x "$TMP/gh"

# run <nom> <args du script…>  → $rc, sorties dans $TMP/out|err, journal
# des appels dans $TMP/<nom>.args ; FAKE_TOKEN / FAKE_CAN_I_* posés par
# l'appelant.
run() {
  local name="$1"; shift
  : > "$TMP/$name.args"; : > "$TMP/$name.stdin"; : > "$TMP/$name.kubeconfigs"
  set +e
  FAKE_ARGS="$TMP/$name.args" FAKE_GH_STDIN="$TMP/$name.stdin" FAKE_KUBECONFIG_SEEN="$TMP/$name.kubeconfigs" \
    FAKE_TOKEN="${FAKE_TOKEN:-}" FAKE_CAN_I_JOBS="${FAKE_CAN_I_JOBS:-yes}" FAKE_CAN_I_EXEC="${FAKE_CAN_I_EXEC:-no}" \
    ROTATE_KUBECTL="$TMP/kubectl" ROTATE_GH="$TMP/gh" \
    "$SCRIPT" "$@" >"$TMP/out" 2>"$TMP/err"
  rc=$?
  set -e
}

now="$(date +%s)"
ninety_days=$((2160 * 3600))

echo "rotate-deployer-token.sh"

# --- Environnement inconnu → code 2, aucun appel ------------------------------
FAKE_TOKEN="$(jwt $((now + ninety_days)))"
run unknown staging
if [ "$rc" -eq 2 ] && grep -q 'environnement inconnu' "$TMP/err" && [ ! -s "$TMP/unknown.args" ]; then
  pass "environnement inconnu → code 2, ni kubectl ni gh appelés"
else
  fail "environnement inconnu" "rc=$rc ; err : $(cat "$TMP/err") ; appels : $(cat "$TMP/unknown.args")"
fi

# --- Sans argument → code 2 ----------------------------------------------------
run noarg
if [ "$rc" -eq 2 ]; then pass "sans environnement → code 2"; else fail "sans environnement" "rc=$rc"; fi

# --- Durée invalide → échec avant tout appel ----------------------------------
run badduration preprod --duration 90j
if [ "$rc" -ne 0 ] && grep -q 'durée invalide' "$TMP/err" && [ ! -s "$TMP/badduration.args" ]; then
  pass "durée invalide → échec, aucun appel"
else
  fail "durée invalide" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- Cas nominal (preprod) ------------------------------------------------------
FAKE_TOKEN="$(jwt $((now + ninety_days)))"
run nominal preprod
if [ "$rc" -eq 0 ]; then pass "nominal → code 0"; else fail "nominal → code 0" "rc=$rc ; err : $(cat "$TMP/err")"; fi

if grep -q '^gh secret set KUBE_CONFIG_PREPROD --env preprod$' "$TMP/nominal.args"; then
  pass "nominal → gh secret set KUBE_CONFIG_PREPROD --env preprod"
else
  fail "nominal → gh secret set" "appels : $(cat "$TMP/nominal.args")"
fi

if grep -q 'create token github-actions-deployer --duration 2160h' "$TMP/nominal.args" \
   && grep -q -- '-n preprod create token' "$TMP/nominal.args" \
   && grep -q -- '--context cp-ghostotof-preprod' "$TMP/nominal.args"; then
  pass "nominal → kubectl create token sur le SA, le namespace et le contexte attendus, 2160h par défaut"
else
  fail "nominal → kubectl create token" "appels : $(cat "$TMP/nominal.args")"
fi

if grep -q 'auth can-i create jobs' "$TMP/nominal.args" && grep -q 'auth can-i create pods/exec' "$TMP/nominal.args"; then
  pass "nominal → les deux auth can-i sont joués avant la publication"
else
  fail "nominal → auth can-i" "appels : $(cat "$TMP/nominal.args")"
fi

# L'ordre : can-i AVANT gh secret set.
can_i_line="$(grep -n 'auth can-i create jobs' "$TMP/nominal.args" | head -1 | cut -d: -f1)"
gh_line="$(grep -n '^gh secret set' "$TMP/nominal.args" | head -1 | cut -d: -f1)"
if [ -n "$can_i_line" ] && [ -n "$gh_line" ] && [ "$can_i_line" -lt "$gh_line" ]; then
  pass "nominal → le jeton est essayé avant d'être publié"
else
  fail "nominal → ordre can-i / publication" "can-i ligne $can_i_line, gh ligne $gh_line"
fi

# Le jeton passe par stdin (il est DANS le kubeconfig publié)…
if grep -q "token: .*$SIGNATURE" "$TMP/nominal.stdin" \
   && grep -q 'server: https://cluster.example.invalid:6443' "$TMP/nominal.stdin" \
   && grep -q 'certificate-authority-data: Q0EtRkFDVElDRQ==' "$TMP/nominal.stdin" \
   && grep -q 'namespace: preprod' "$TMP/nominal.stdin"; then
  pass "nominal → le kubeconfig publié porte le jeton, le endpoint, la CA et le namespace"
else
  fail "nominal → contenu du kubeconfig" "$(cat "$TMP/nominal.stdin")"
fi

# … et jamais ailleurs : ni dans un argument de processus, ni sur les sorties.
if ! grep -q "$SIGNATURE" "$TMP/nominal.args" "$TMP/out" "$TMP/err"; then
  pass "nominal → le jeton n'apparaît dans aucun argument ni sur stdout/stderr"
else
  fail "nominal → fuite du jeton" "$(grep -l "$SIGNATURE" "$TMP/nominal.args" "$TMP/out" "$TMP/err")"
fi

# L'identité admin du kubeconfig courant n'est pas recopiée.
if ! grep -q 'ADMIN-TOKEN' "$TMP/nominal.stdin"; then
  pass "nominal → l'identité admin du kubeconfig courant n'est pas recopiée"
else
  fail "nominal → identité admin recopiée" "$(cat "$TMP/nominal.stdin")"
fi

# Le fichier temporaire vu par `auth can-i` a disparu.
seen="$(head -1 "$TMP/nominal.kubeconfigs")"
if [ -n "$seen" ] && [ ! -e "$seen" ] && [ ! -d "$(dirname "$seen")" ]; then
  pass "nominal → le kubeconfig temporaire est supprimé à la sortie"
else
  fail "nominal → fichier temporaire" "vu : « $seen », existe : $([ -e "$seen" ] && echo oui || echo non)"
fi

if grep -q 'Expiration du jeton' "$TMP/out" && grep -q 'Prochaine rotation' "$TMP/out" && grep -q '90 jours' "$TMP/out"; then
  pass "nominal → expiration (90 jours) et prochaine rotation affichées"
else
  fail "nominal → échéances" "$(cat "$TMP/out")"
fi

if ! grep -q 'TRONQUÉE' "$TMP/err"; then
  pass "nominal → pas d'avertissement de troncature quand la durée accordée = demandée"
else
  fail "nominal → troncature signalée à tort" "$(cat "$TMP/err")"
fi

# --- prod → KUBE_CONFIG_PROD sur l'environnement `production` -----------------
run prod prod
if [ "$rc" -eq 0 ] && grep -q '^gh secret set KUBE_CONFIG_PROD --env production$' "$TMP/prod.args" \
   && grep -q -- '-n prod create token' "$TMP/prod.args" && grep -q -- '--context cp-ghostotof-prod' "$TMP/prod.args"; then
  pass "prod → KUBE_CONFIG_PROD sur « production », namespace et contexte prod"
else
  fail "prod" "rc=$rc ; appels : $(cat "$TMP/prod.args")"
fi

# --- --context et --repo sont transmis ----------------------------------------
run opts preprod --context autre-ctx --repo owner/name
if [ "$rc" -eq 0 ] && grep -q -- '--context autre-ctx' "$TMP/opts.args" && grep -q '^gh secret set KUBE_CONFIG_PREPROD --env preprod --repo owner/name$' "$TMP/opts.args"; then
  pass "--context et --repo transmis à kubectl et gh"
else
  fail "--context / --repo" "rc=$rc ; appels : $(cat "$TMP/opts.args")"
fi

# --- can-i create jobs ≠ yes → échec, rien publié ------------------------------
FAKE_CAN_I_JOBS=no run nojobs preprod
if [ "$rc" -ne 0 ] && ! grep -q '^gh secret set' "$TMP/nojobs.args" && grep -q 'ne peut pas créer de Job' "$TMP/err"; then
  pass "can-i create jobs = no → échec explicite, aucun gh secret set"
else
  fail "can-i create jobs = no" "rc=$rc ; appels : $(cat "$TMP/nojobs.args") ; err : $(cat "$TMP/err")"
fi

# --- can-i create pods/exec = yes → échec (privilège inattendu), rien publié ---
FAKE_CAN_I_EXEC=yes run exec preprod
if [ "$rc" -ne 0 ] && ! grep -q '^gh secret set' "$TMP/exec.args" && grep -q 'privilège inattendu' "$TMP/err"; then
  pass "can-i create pods/exec = yes → échec (privilège inattendu), aucun gh secret set"
else
  fail "can-i create pods/exec = yes" "rc=$rc ; appels : $(cat "$TMP/exec.args") ; err : $(cat "$TMP/err")"
fi

# --- Durée tronquée par le cluster → avertissement, publication maintenue ------
FAKE_TOKEN="$(jwt $((now + 24 * 3600)))"
run truncated preprod
if [ "$rc" -eq 0 ] && grep -q 'TRONQUÉE' "$TMP/err" && grep -q '24 h accordées' "$TMP/err" && grep -q '^gh secret set' "$TMP/truncated.args"; then
  pass "exp du JWT < durée demandée → avertissement de troncature (24 h), publication maintenue"
else
  fail "troncature" "rc=$rc ; err : $(cat "$TMP/err") ; appels : $(cat "$TMP/truncated.args")"
fi

# --- Durée au-delà de Q4 → avertissement --------------------------------------
FAKE_TOKEN="$(jwt $((now + 8760 * 3600)))"
run long preprod --duration 8760h
if [ "$rc" -eq 0 ] && grep -q 'au-delà de la rotation trimestrielle' "$TMP/err"; then
  pass "--duration 8760h → avertissement Q4"
else
  fail "--duration au-delà de Q4" "rc=$rc ; err : $(cat "$TMP/err")"
fi

# --- --dry-run : ni create token, ni can-i, ni gh secret set -------------------
FAKE_TOKEN="$(jwt $((now + ninety_days)))"
run dry preprod --dry-run
if [ "$rc" -eq 0 ] && ! grep -q 'create token' "$TMP/dry.args" && ! grep -q 'auth can-i' "$TMP/dry.args" \
   && ! grep -q '^gh ' "$TMP/dry.args" && grep -q 'config view' "$TMP/dry.args"; then
  pass "--dry-run → config view seulement : pas de create token, pas de can-i, pas de gh"
else
  fail "--dry-run" "rc=$rc ; appels : $(cat "$TMP/dry.args") ; err : $(cat "$TMP/err")"
fi
if grep -q 'FACTICE' "$TMP/out" && grep -q 'SAUTÉE' "$TMP/out" && grep -q 'Expiration du jeton' "$TMP/out"; then
  pass "--dry-run → annonce le jeton factice, les étapes sautées et l'expiration calculée"
else
  fail "--dry-run → annonces" "$(cat "$TMP/out")"
fi

# --- Contexte illisible → échec avant create token ----------------------------
# Le faux kubectl ne sait pas répondre à un `config view` en échec : on
# simule avec un binaire qui échoue toujours.
printf '#!/usr/bin/env bash\nexit 1\n' > "$TMP/kubectl-broken"; chmod +x "$TMP/kubectl-broken"
set +e
ROTATE_KUBECTL="$TMP/kubectl-broken" ROTATE_GH="$TMP/gh" FAKE_ARGS="$TMP/broken.args" FAKE_GH_STDIN="$TMP/broken.stdin" \
  "$SCRIPT" preprod >"$TMP/out" 2>"$TMP/err"
rc=$?
set -e
if [ "$rc" -ne 0 ] && grep -q 'illisible' "$TMP/err"; then
  pass "contexte illisible → échec nommant le contexte"
else
  fail "contexte illisible" "rc=$rc ; err : $(cat "$TMP/err")"
fi

echo
if [ "$failures" -eq 0 ]; then echo "OK"; else echo "$failures échec(s)"; exit 1; fi
