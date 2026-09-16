#!/usr/bin/env bash
#
# rotate-deployer-token.sh
# ---------------------------------------------------------------------------
# Régénère le jeton du ServiceAccount `github-actions-deployer` d'un
# namespace et le pose, sous forme de kubeconfig autonome, comme secret
# d'ENVIRONNEMENT GitHub Actions (`KUBE_CONFIG_PREPROD` sur `preprod`,
# `KUBE_CONFIG_PROD` sur `production`).
#
# 3e audit de sécurité (2026-09-16), constat A3, décision D4 : le kubeconfig
# du pipeline reposait sur un Secret `kubernetes.io/service-account-token`,
# c'est-à-dire un jeton qui n'expire JAMAIS — une fuite du secret GitHub
# valait un accès permanent au namespace, jusqu'à ce que quelqu'un s'en
# aperçoive et supprime le Secret. Ce script le remplace par un jeton LIÉ
# (TokenRequest API, `kubectl create token`), à durée limitée : 90 jours par
# défaut (question Q4 : rotation trimestrielle). Une fuite se périme d'elle-
# même ; la rotation est une commande, pas une procédure.
#
# Ce que le jeton permet n'a pas changé, et il faut le dire tel quel : le
# `Role` du déployeur (`jobs create` + `pods/log` + `externalsecrets
# create/update` + `deployments update`) LIT tout secret du namespace par
# construction — un Job qui affiche son environnement suffit. Le retrait de
# `pods/exec` (audit C8) empêche un shell interactif, pas cette lecture. La
# durée limitée borne le temps d'exposition ; elle ne réduit pas le
# périmètre. Voir k8s/README.md §4.
#
# Étapes :
#   1. endpoint et CA du cluster lus dans le kubeconfig COURANT (`kubectl
#      config view --raw --minify` sur le contexte) — jamais l'identité de
#      l'administrateur, qui ne quitte pas ce poste ;
#   2. `kubectl create token github-actions-deployer -n <ns> --duration <d>` ;
#   3. contrôle de la durée réellement accordée : l'API tronque EN SILENCE à
#      `--service-account-max-token-expiration` du control-plane (inconnu sur
#      Kapsule, managé) — le champ `exp` du JWT émis est décodé (payload
#      base64, aucune vérification de signature, aucun secret manipulé) et
#      comparé à la demande ; un écart est un avertissement, pas un échec ;
#   4. assemblage d'un kubeconfig autonome (cluster + CA + user token +
#      contexte courant avec le namespace) dans un fichier temporaire en 600,
#      supprimé à la sortie quoi qu'il arrive ;
#   5. le jeton est ESSAYÉ avant d'être publié : `auth can-i create jobs`
#      doit répondre `yes` (sinon le déploiement échouerait) et `auth can-i
#      create pods/exec` doit répondre `no` (sinon le RBAC n'est pas celui
#      du dépôt — on ne publie pas un privilège inattendu) ;
#   6. `gh secret set <NOM> --env <env>` en lisant le fichier sur stdin ;
#   7. affichage de la date d'expiration et de la prochaine rotation.
#
# Le jeton n'apparaît JAMAIS dans la sortie ni dans les arguments d'un
# processus (`ps` les lit) : il passe par fichier et par stdin.
#
# Première rotation : l'ancien Secret durable reste valide tant qu'il existe,
# donc aucune interruption — après un déploiement vert avec le nouveau jeton,
# le supprimer : `kubectl -n <ns> delete secret github-actions-deployer-token`.
# Rotations suivantes : relancer ce script avant la date annoncée ; l'ancien
# jeton lié expire seul, rien à supprimer.
#
# Usage :  tools/rotate-deployer-token.sh <preprod|prod>
#            [--duration 2160h]     durée demandée (format Go : 2160h, 90m…)
#            [--context <ctx>]      contexte kubectl (défaut : cp-ghostotof-<env>)
#            [--repo owner/name]    dépôt GitHub (défaut : le dépôt courant)
#            [--dry-run]            tout sauf `create token` (jeton factice,
#                                   `can-i` sauté) et `gh secret set`
# Env :    ROTATE_KUBECTL, ROTATE_GH  binaires de substitution (tests hors ligne)
# ---------------------------------------------------------------------------
set -euo pipefail

SA_NAME="github-actions-deployer"
DEFAULT_DURATION="2160h"   # 90 jours (Q4)
ROTATION_LEAD_DAYS=7       # marge conseillée avant l'expiration

env_name=""; duration="$DEFAULT_DURATION"; context=""; gh_repo=""; dry_run=0

usage() { sed -n '2,63p' "$0" | sed 's/^# \{0,1\}//'; }
die()   { echo "rotate-deployer-token.sh : $*" >&2; exit 1; }
say()   { printf '%s\n' "$*"; }
warn()  { printf 'AVERTISSEMENT : %s\n' "$*" >&2; }

while [ $# -gt 0 ]; do
  case "$1" in
    --duration) duration="${2:?--duration attend une durée (ex. 2160h)}"; shift ;;
    --context) context="${2:?--context attend un nom de contexte kubectl}"; shift ;;
    --repo) gh_repo="${2:?--repo attend owner/name}"; shift ;;
    --dry-run) dry_run=1 ;;
    -h|--help) usage; exit 0 ;;
    -*) echo "rotate-deployer-token.sh : option inconnue « $1 »" >&2; exit 2 ;;
    *)
      if [ -n "$env_name" ]; then echo "rotate-deployer-token.sh : un seul environnement attendu" >&2; exit 2; fi
      env_name="$1" ;;
  esac
  shift
done

# ---- environnement → namespace, environnement GitHub, nom du secret ----------
case "$env_name" in
  preprod) namespace="preprod"; gh_env="preprod";    secret_name="KUBE_CONFIG_PREPROD" ;;
  prod)    namespace="prod";    gh_env="production"; secret_name="KUBE_CONFIG_PROD" ;;
  "") echo "usage : $0 <preprod|prod> [--duration 2160h] [--context <ctx>] [--repo owner/name] [--dry-run]" >&2; exit 2 ;;
  *)  echo "rotate-deployer-token.sh : environnement inconnu « $env_name » (attendu : preprod ou prod)" >&2; exit 2 ;;
esac
context="${context:-cp-ghostotof-$env_name}"

kubectl_bin="${ROTATE_KUBECTL:-kubectl}"
gh_bin="${ROTATE_GH:-gh}"
gh_args=()
if [ -n "$gh_repo" ]; then gh_args=(--repo "$gh_repo"); fi
for tool in jq base64 date; do command -v "$tool" >/dev/null || die "outil requis absent : $tool"; done

# ---- durée demandée, en secondes (format Go : suite de <n>h|m|s) -------------
duration_to_seconds() {
  local rest="$1" total=0
  [[ "$rest" =~ ^([0-9]+[hms])+$ ]] || return 1
  while [[ "$rest" =~ ^([0-9]+)([hms])(.*)$ ]]; do
    case "${BASH_REMATCH[2]}" in
      h) total=$((total + BASH_REMATCH[1] * 3600)) ;;
      m) total=$((total + BASH_REMATCH[1] * 60)) ;;
      s) total=$((total + BASH_REMATCH[1])) ;;
    esac
    rest="${BASH_REMATCH[3]}"
  done
  echo "$total"
}
requested_seconds="$(duration_to_seconds "$duration")" || die "durée invalide « $duration » (attendu : 2160h, 90m, 1h30m…)"
[ "$requested_seconds" -ge 600 ] || die "durée trop courte « $duration » : l'API exige au moins 10 minutes"
default_seconds="$(duration_to_seconds "$DEFAULT_DURATION")"
if [ "$requested_seconds" -gt "$default_seconds" ]; then
  warn "durée demandée ($duration) au-delà de la rotation trimestrielle décidée (Q4 : $DEFAULT_DURATION) — l'exposition en cas de fuite s'allonge d'autant"
fi

# ---- fichiers temporaires : 700 sur le répertoire, 600 sur chaque fichier ----
umask 077
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
token_file="$tmp/token"
kubeconfig="$tmp/kubeconfig"

say "Rotation du jeton du déployeur — environnement $env_name (namespace $namespace, contexte $context)"
[ "$dry_run" -eq 1 ] && say "MODE --dry-run : aucun jeton réel, aucune écriture GitHub."

# ---- 1. endpoint et CA du cluster, lus dans le kubeconfig courant ------------
# `--minify` ne garde que le contexte demandé ; on n'extrait que le cluster,
# jamais la section `users` (l'identité admin reste sur ce poste).
"$kubectl_bin" config view --raw --minify --context "$context" -o json > "$tmp/current.json" \
  || die "contexte « $context » illisible (kubectl config view)"
server="$(jq -r '.clusters[0].cluster.server // empty' "$tmp/current.json")"
[ -n "$server" ] || die "aucun endpoint de cluster dans le contexte « $context »"
if [ "$(jq -r '.clusters[0].cluster["insecure-skip-tls-verify"] // false' "$tmp/current.json")" = "true" ]; then
  die "le contexte « $context » saute la vérification TLS : on ne publie pas un kubeconfig sans CA"
fi
ca_data="$(jq -r '.clusters[0].cluster["certificate-authority-data"] // empty' "$tmp/current.json")"
if [ -z "$ca_data" ]; then
  ca_file="$(jq -r '.clusters[0].cluster["certificate-authority"] // empty' "$tmp/current.json")"
  [ -n "$ca_file" ] && [ -r "$ca_file" ] || die "aucune CA (ni certificate-authority-data ni fichier lisible) dans le contexte « $context »"
  ca_data="$(base64 -w0 < "$ca_file")"
fi
say "Cluster : $server"

# ---- 2. le jeton lié -----------------------------------------------------------
now="$(date +%s)"
if [ "$dry_run" -eq 1 ]; then
  # Jeton factice de la forme d'un JWT, pour exercer le décodage de `exp`
  # ci-dessous sans rien demander au cluster. Il n'authentifie rien.
  payload="$(printf '{"exp":%d,"iat":%d,"sub":"dry-run"}' "$((now + requested_seconds))" "$now" | base64 -w0 | tr '+/' '-_' | tr -d '=')"
  printf 'dry-run.%s.dry-run' "$payload" > "$token_file"
  say "Jeton : FACTICE (--dry-run), kubectl create token non appelé."
else
  "$kubectl_bin" --context "$context" -n "$namespace" create token "$SA_NAME" --duration "$duration" > "$token_file" \
    || die "kubectl create token a échoué (ServiceAccount $SA_NAME absent du namespace $namespace ? bootstrap RBAC, k8s/README.md §4)"
  [ -s "$token_file" ] || die "kubectl create token n'a rien renvoyé"
  say "Jeton lié émis pour $SA_NAME (namespace $namespace), durée demandée $duration."
fi

# ---- 3. durée réellement accordée : `exp` du JWT --------------------------------
# Payload = 2e segment, base64url sans padding. Décodage local, pas de
# vérification de signature (inutile ici) et le jeton ne quitte pas le fichier.
jwt_exp() {
  local seg
  seg="$(cut -d. -f2 "$1" | tr '_-' '/+')"
  case $(( ${#seg} % 4 )) in 2) seg="$seg==" ;; 3) seg="$seg=" ;; esac
  printf '%s' "$seg" | base64 -d 2>/dev/null | jq -r '.exp // empty' 2>/dev/null
}
expires_at="$(jwt_exp "$token_file")"
[[ "$expires_at" =~ ^[0-9]+$ ]] || die "impossible de lire l'expiration (« exp ») du jeton émis"
granted_seconds=$((expires_at - now))
# Tolérance d'une minute : l'horloge locale et celle du control-plane ne sont
# pas synchronisées à la seconde.
if [ $((requested_seconds - granted_seconds)) -gt 60 ]; then
  warn "durée TRONQUÉE par le cluster : $duration demandée, $((granted_seconds / 3600)) h accordées (maximum du control-plane, --service-account-max-token-expiration). Poser la rotation sur la durée accordée, pas sur celle demandée."
fi

# ---- 4. kubeconfig autonome -----------------------------------------------------
# Le jeton est lu par le shell (builtin, aucun processus) et écrit par un
# heredoc : il ne transite par aucune ligne de commande.
token="$(<"$token_file")"
cat > "$kubeconfig" <<EOF
apiVersion: v1
kind: Config
clusters:
  - name: $context
    cluster:
      server: $server
      certificate-authority-data: $ca_data
users:
  - name: $SA_NAME
    user:
      token: $token
contexts:
  - name: $context
    context:
      cluster: $context
      user: $SA_NAME
      namespace: $namespace
current-context: $context
EOF
unset token
chmod 600 "$kubeconfig"

# ---- 5. essai du jeton avant publication ---------------------------------------
can_i() {
  # `kubectl auth can-i` sort en 1 sur « no » : on lit la réponse, pas le code.
  "$kubectl_bin" --kubeconfig "$kubeconfig" -n "$namespace" auth can-i "$@" 2>/dev/null || true
}
if [ "$dry_run" -eq 1 ]; then
  say "Vérification auth can-i SAUTÉE (--dry-run : un jeton factice n'authentifie rien)."
else
  jobs_answer="$(can_i create jobs)"
  [ "$jobs_answer" = "yes" ] || die "le jeton ne peut pas créer de Job dans $namespace (réponse « ${jobs_answer:-vide} ») : le déploiement échouerait, rien n'est publié — RBAC à rejouer (k8s/README.md §4) ?"
  exec_answer="$(can_i create pods/exec)"
  [ "$exec_answer" = "no" ] || die "le jeton peut ouvrir un shell (pods/exec → « ${exec_answer:-vide} ») : privilège inattendu, le Role du cluster n'est pas celui du dépôt (audit C8), rien n'est publié"
  say "Contrôle du jeton : create jobs → yes, create pods/exec → no."
fi

# ---- 6. publication comme secret d'environnement -------------------------------
if [ "$dry_run" -eq 1 ]; then
  say "Publication SAUTÉE (--dry-run) : gh secret set $secret_name --env $gh_env < <kubeconfig>"
else
  "$gh_bin" secret set "$secret_name" --env "$gh_env" "${gh_args[@]}" < "$kubeconfig" \
    || die "gh secret set $secret_name --env $gh_env a échoué : le jeton émis reste valide jusqu'à son expiration mais n'est publié nulle part — relancer"
  say "Secret d'environnement $secret_name posé sur « $gh_env »."
fi

# ---- 7. échéances ----------------------------------------------------------------
expires_human="$(date -u -d "@$expires_at" '+%Y-%m-%d %H:%M UTC')"
rotate_by="$(date -u -d "@$((expires_at - ROTATION_LEAD_DAYS * 86400))" '+%Y-%m-%d')"
say "Expiration du jeton : $expires_human ($((granted_seconds / 86400)) jours)."
say "Prochaine rotation : avant le $rotate_by — relancer « $0 $env_name »."
if [ "$dry_run" -eq 0 ]; then
  say "Si le Secret durable existe encore (première rotation) : après un déploiement vert avec ce jeton,"
  say "  kubectl --context $context -n $namespace delete secret github-actions-deployer-token"
fi
