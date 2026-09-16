#!/usr/bin/env bash
#
# github-settings.sh
# ---------------------------------------------------------------------------
# Applique les réglages GitHub du flux de release (spec 0006, D9) et les réglages
# de sécurité du dépôt (3e audit du 2026-09-16, constats A2 et A14, décision D3),
# de façon IDEMPOTENTE, avec `gh` : à rejouer après tout changement, ou sur un fork.
#
#   a. merge des PR par commit de merge seulement (squash et rebase
#      désactivés : deploy-prod retrouve la release par HEAD^2, qui n'existe
#      pas autrement) ; delete_branch_on_merge reste à false (c'est
#      finalize-release qui supprime la branche, après la prod) ;
#   b. ruleset `main` : PR obligatoire en commit de merge, checks requis
#      `smoke-test-preprod` + `audit-preprod` (sur le SHA de tête de la PR,
#      c'est-à-dire le run de la branche release/*), pas de suppression, pas
#      de force-push ;
#   c. ruleset `develop` : PR obligatoire, checks requis = la phase 1 ;
#   d. environnements `production` (branche `main`) et `preprod` (branches
#      `release/*`) : aucun reviewer requis (le merge est le stop humain), et
#      une politique de branche de déploiement — sans elle, n'importe quelle
#      branche du dépôt pouvait lire les secrets de l'environnement (constat
#      A4) ; les secrets eux-mêmes ne sont pas touchés par cet appel ;
#   e. (rien à faire : delete_branch_on_merge, voir a) ;
#   f. ruleset tags `v*` : création, mise à jour, suppression, force-push
#      interdits — seul le job pose un tag ;
#   g. vérifie, sans la créer, la deploy key `release-bot` (écriture) : la
#      paire se génère hors dépôt, voir le README en bas de ce fichier ; le
#      secret RELEASE_DEPLOY_KEY est vérifié à l'étape k ;
#   h. alertes de vulnérabilité Dependabot **actives** (elles étaient
#      désactivées, donc les quatre écosystèmes suivis par `dependabot.yml` ne
#      remontaient rien, constat A2) et correctifs de sécurité automatiques
#      **désactivés** : leurs PR visent la branche par défaut `main`, qui
#      n'accepte qu'une PR validée par `smoke-test-preprod`/`audit-preprod`,
#      donc elles ne seraient jamais mergeables (voir l'étape h) ;
#   i. Private Vulnerability Reporting : le canal de signalement privé annoncé
#      par SECURITY.md, qui n'expose rien avant qu'un correctif existe ;
#   j. épinglage SHA imposé aux actions (`sha_pinning_required`) : le dépôt
#      épingle déjà à la main, GitHub refuse désormais un tag ou une branche.
#      `allowed_actions` reste `all` (question Q8 tranchée : c'est l'épinglage
#      qui protège, pas une liste blanche d'actions mutables).
#   k. secrets d'environnement : vérification de présence seulement. L'API ne
#      relit pas la valeur d'un secret, donc le script ne peut pas les
#      déplacer — il signale ce qui manque dans l'environnement et ce qui
#      traîne encore au niveau dépôt ; les commandes sont dans le README en
#      bas de ce fichier.
#
# Le seul acteur de bypass des trois rulesets est le type « Deploy keys »
# (actor_type DeployKey) : un dépôt personnel refuse l'application
# github-actions (vérifié le 2026-09-16, spec §10).
#
# Usage :  tools/github-settings.sh [--repo owner/name]   (défaut : le dépôt courant)
# ---------------------------------------------------------------------------
set -euo pipefail

repo=""
while [ $# -gt 0 ]; do
  case "$1" in
    --repo) repo="${2:?}"; shift ;;
    -h|--help) sed -n '2,51p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "github-settings.sh : option inconnue « $1 »" >&2; exit 2 ;;
  esac
  shift
done
[ -n "$repo" ] || repo="$(gh repo view --json nameWithOwner --jq .nameWithOwner)"
api="repos/$repo"

step() { printf '\n== %s\n' "$*"; }
ok()   { printf '   ok   %s\n' "$*"; }
warn() { printf '   !!   %s\n' "$*"; }

# ---- a. méthodes de merge ---------------------------------------------------
step "a. Méthodes de merge du dépôt $repo"
gh api -X PATCH "$api" \
  -F allow_merge_commit=true -F allow_squash_merge=false -F allow_rebase_merge=false \
  -F delete_branch_on_merge=false >/dev/null
gh api "$api" --jq '"   merge_commit=\(.allow_merge_commit) squash=\(.allow_squash_merge) rebase=\(.allow_rebase_merge) delete_branch_on_merge=\(.delete_branch_on_merge)"'

# ---- rulesets ---------------------------------------------------------------
# upsert_ruleset <nom> <json>  — crée ou remplace le ruleset de ce nom.
upsert_ruleset() {
  local name="$1" json="$2" id
  id="$(gh api "$api/rulesets" --jq ".[] | select(.name==\"$name\") | .id" | head -1)"
  if [ -n "$id" ]; then
    gh api -X PUT "$api/rulesets/$id" --input - <<<"$json" >/dev/null
    ok "ruleset « $name » mis à jour (id $id)"
  else
    id="$(gh api -X POST "$api/rulesets" --input - <<<"$json" --jq .id)"
    ok "ruleset « $name » créé (id $id)"
  fi
  gh api "$api/rulesets/$id" --jq '"   enforcement=\(.enforcement) bypass=\([.bypass_actors[].actor_type] | join(",")) rules=\([.rules[].type] | join(","))"'
}

bypass='[{"actor_type":"DeployKey","bypass_mode":"always"}]'
pr_rule='{"type":"pull_request","parameters":{"required_approving_review_count":0,"dismiss_stale_reviews_on_push":false,"require_code_owner_review":false,"require_last_push_approval":false,"required_review_thread_resolution":false,"allowed_merge_methods":["merge"]}}'
# checks_rule <contexte>…  — les checks GitHub Actions portent l'id de l'app 15368.
checks_rule() {
  local ctx="" c
  for c in "$@"; do ctx="$ctx{\"context\":\"$c\",\"integration_id\":15368},"; done
  printf '{"type":"required_status_checks","parameters":{"strict_required_status_checks_policy":false,"do_not_enforce_on_create":false,"required_status_checks":[%s]}}' "${ctx%,}"
}

step "b. Ruleset main — PR en commit de merge, préprod verte requise, ni suppression ni force-push"
upsert_ruleset "main — release par PR" "$(cat <<JSON
{"name":"main — release par PR","target":"branch","enforcement":"active",
 "bypass_actors":$bypass,
 "conditions":{"ref_name":{"include":["refs/heads/main"],"exclude":[]}},
 "rules":[$pr_rule,$(checks_rule smoke-test-preprod audit-preprod),{"type":"deletion"},{"type":"non_fast_forward"}]}
JSON
)"

step "c. Ruleset develop — PR obligatoire, phase 1 verte requise"
upsert_ruleset "develop — PR et phase 1" "$(cat <<JSON
{"name":"develop — PR et phase 1","target":"branch","enforcement":"active",
 "bypass_actors":$bypass,
 "conditions":{"ref_name":{"include":["refs/heads/develop"],"exclude":[]}},
 "rules":[$pr_rule,$(checks_rule test-backend test-frontend sast-backend phpstan-backend rector-backend lsp-check-backend tools-tests),{"type":"deletion"},{"type":"non_fast_forward"}]}
JSON
)"

step "f. Ruleset tags v* — posés par finalize-release seulement"
upsert_ruleset "tags v* — posés par la pipeline" "$(cat <<JSON
{"name":"tags v* — posés par la pipeline","target":"tag","enforcement":"active",
 "bypass_actors":$bypass,
 "conditions":{"ref_name":{"include":["refs/tags/v*"],"exclude":[]}},
 "rules":[{"type":"creation"},{"type":"update"},{"type":"deletion"},{"type":"non_fast_forward"}]}
JSON
)"

# ---- d. environnements et politiques de branche de déploiement ----------------
# env_branch_policy <environnement> <motif de branche>
# Idempotent : le PUT (re)pose les règles de protection — aucun reviewer, le
# merge de la PR de release est le stop humain (spec 0006, D6/D7) — et bascule
# l'environnement sur `custom_branch_policies` ; le motif n'est créé que s'il
# n'est pas déjà listé (l'API n'a pas d'upsert et accepterait un doublon).
# Le PUT crée l'environnement s'il n'existe pas ; il ne touche pas ses secrets,
# qui sont une autre ressource.
env_branch_policy() {
  local env="$1" pattern="$2"
  gh api -X PUT "$api/environments/$env" --input - >/dev/null <<'JSON'
{"wait_timer":0,"prevent_self_review":false,"reviewers":[],
 "deployment_branch_policy":{"protected_branches":false,"custom_branch_policies":true}}
JSON
  if gh api "$api/environments/$env/deployment-branch-policies" \
       --jq '.branch_policies[].name' | grep -qxF "$pattern"; then
    ok "environnement $env : politique de branche « $pattern » déjà en place"
  else
    gh api -X POST "$api/environments/$env/deployment-branch-policies" \
      -f name="$pattern" -f type=branch >/dev/null
    ok "environnement $env : politique de branche « $pattern » créée"
  fi
  printf '   %s : règles de protection = ' "$env"
  gh api "$api/environments/$env" --jq '[.protection_rules[].type] | join(",") | if . == "" then "aucune" else . end'
  printf '   %s : branches de déploiement autorisées = ' "$env"
  gh api "$api/environments/$env/deployment-branch-policies" --jq '[.branch_policies[].name] | join(",")'
}

step "d. Environnements — aucun reviewer requis, et d'où leurs secrets sont lisibles"
# deployment_branch_policy valait null : tout `environment: production` déclaré
# sur n'importe quelle branche servait KUBE_CONFIG_PROD (constat A4). La
# politique borne production à `main` et preprod aux branches `release/*`,
# c'est-à-dire exactement les deux branches d'où la pipeline déploie.
env_branch_policy production main
env_branch_policy preprod 'release/*'

# ---- g. deploy key et secret, vérifiés seulement ------------------------------
step "g. Deploy key release-bot (vérification ; le secret est vu à l'étape k)"
if gh api "$api/keys" --jq '.[] | select(.title=="release-bot" and .read_only==false) | .id' | grep -q .; then
  ok "deploy key « release-bot » en écriture présente"
else
  warn "deploy key « release-bot » absente ou en lecture seule — voir le README ci-dessous"
fi
# Le secret RELEASE_DEPLOY_KEY, lui, est vérifié à l'étape k : il appartient
# désormais à l'environnement `production`, pas au dépôt.

# ---- h. alertes Dependabot et correctifs de sécurité automatiques -------------
step "h. Dependabot — alertes de vulnérabilité et correctifs de sécurité automatiques"
# Ces deux ressources n'ont pas de corps : le PUT répond 204, et le GET de
# `vulnerability-alerts` répond 204 si c'est actif, 404 sinon — d'où la
# relecture sur le code de retour et non sur un champ JSON. Rejouer le PUT sur
# un réglage déjà actif est un no-op côté GitHub.
gh api -X PUT "$api/vulnerability-alerts" >/dev/null
if gh api "$api/vulnerability-alerts" >/dev/null 2>&1; then
  ok "alertes de vulnérabilité actives (GET -> 204)"
else
  warn "alertes de vulnérabilité toujours inactives (GET -> 404) — droits du jeton ?"
fi
# Les correctifs de sécurité automatiques, eux, sont DÉSACTIVÉS (option 1
# retenue le 2026-09-16) : leurs PR sont ouvertes sur la branche par défaut,
# `main`, qui n'accepte qu'une PR validée par `smoke-test-preprod` et
# `audit-preprod` — des checks qu'une branche `dependabot/**` ne produit pas.
# Elles seraient donc ingérables à vie, et hors du flux de release (spec 0006).
# Ce qui reste : les alertes ci-dessus préviennent, les mises à jour groupées
# hebdomadaires de `dependabot.yml` visent `develop` (`target-branch`), et
# `composer audit` + `npm audit` bloquent la CI. Le DELETE répond 204 ; la
# lecture, elle, renvoie du JSON.
gh api -X DELETE "$api/automated-security-fixes" >/dev/null
gh api "$api/automated-security-fixes" --jq '"   correctifs automatiques : enabled=\(.enabled) paused=\(.paused) (enabled=false attendu)"'

# ---- i. Private Vulnerability Reporting --------------------------------------
step "i. Private Vulnerability Reporting — canal principal annoncé par SECURITY.md"
gh api -X PUT "$api/private-vulnerability-reporting" >/dev/null
gh api "$api/private-vulnerability-reporting" --jq '"   enabled=\(.enabled)"'

# ---- j. épinglage SHA imposé aux actions --------------------------------------
step "j. Actions — épinglage SHA imposé, allowed_actions inchangé"
# Le PUT remplace l'objet entier : on relit d'abord `enabled` et
# `allowed_actions` pour les renvoyer tels quels, sinon activer l'épinglage
# rouvrirait ou restreindrait silencieusement l'usage des actions.
actions_enabled="$(gh api "$api/actions/permissions" --jq .enabled)"
actions_allowed="$(gh api "$api/actions/permissions" --jq .allowed_actions)"
# `allowed_actions` est absent quand les actions sont désactivées : on retombe
# alors sur la valeur du dépôt (Q8) plutôt que d'envoyer un littéral « null ».
if [ "$actions_allowed" = "null" ] || [ -z "$actions_allowed" ]; then
  actions_allowed="all"
fi
gh api -X PUT "$api/actions/permissions" \
  -F enabled="$actions_enabled" -f allowed_actions="$actions_allowed" \
  -F sha_pinning_required=true >/dev/null
gh api "$api/actions/permissions" --jq '"   enabled=\(.enabled) allowed_actions=\(.allowed_actions) sha_pinning_required=\(.sha_pinning_required)"'

# ---- k. secrets d'environnement (vérification seulement) ----------------------
# env_secret <environnement> <nom>
# L'API ne relit jamais la valeur d'un secret : ce script ne peut donc pas les
# déplacer, seulement dire où ils sont. Deux vérifications, pas une : un secret
# posé au niveau environnement pendant que l'homonyme subsiste au niveau dépôt
# ne protège rien — `secrets.X` continue de se résoudre sur le dépôt pour tout
# job qui ne déclare pas l'environnement.
env_secret() {
  local env="$1" name="$2"
  if gh secret list --repo "$repo" --env "$env" | awk '{print $1}' | grep -qxF "$name"; then
    ok "$name : présent dans l'environnement $env"
  else
    warn "$name : absent de l'environnement $env — « gh secret set $name --env $env » (README ci-dessous)"
  fi
  if gh secret list --repo "$repo" | awk '{print $1}' | grep -qxF "$name"; then
    warn "$name : existe encore au niveau dépôt — « gh secret delete $name » une fois le run suivant vert"
  else
    ok "$name : absent du niveau dépôt"
  fi
}

step "k. Secrets d'environnement — présence seulement (les valeurs ne sont pas lisibles)"
env_secret preprod KUBE_CONFIG_PREPROD
env_secret preprod PREPROD_BASIC_AUTH
env_secret production KUBE_CONFIG_PROD
env_secret production RELEASE_DEPLOY_KEY

printf '\nTerminé.\n'

# ---------------------------------------------------------------------------
# README — la paire release-bot (une fois, hors dépôt, jamais commitée)
#
#   key="$(mktemp -d)/release_bot"
#   ssh-keygen -t ed25519 -N '' -C release-bot -f "$key"
#   gh repo deploy-key add "$key.pub" --title release-bot --allow-write
#   gh secret set RELEASE_DEPLOY_KEY < "$key"
#   shred -u "$key" "$key.pub"
#
# Rotation : supprimer l'ancienne clé (`gh repo deploy-key delete <id>`),
# rejouer les cinq lignes. La clé donne l'écriture sur ce seul dépôt et
# contourne ses rulesets : même niveau de garde que les kubeconfigs.
#
# README — déplacer les secrets vers leur environnement (étape k, à la main)
#
# L'API ne relit pas la valeur d'un secret : chaque valeur est recollée depuis
# sa source d'origine (kubeconfig du déployeur, ligne htpasswd au format
# `utilisateur:mot_de_passe`, clé privée `release_bot`). Dans cet ordre, et
# entre deux releases — déplacer un secret pendant un run le casserait :
#
#   1. poser la copie au niveau environnement (le dépôt garde la sienne, donc
#      rien ne casse tant que l'étape 3 n'est pas faite) :
#
#        gh secret set KUBE_CONFIG_PREPROD --env preprod   < chemin/du/kubeconfig-preprod
#        gh secret set PREPROD_BASIC_AUTH  --env preprod   --body '<identifiant>:<mot-de-passe>'
#        gh secret set KUBE_CONFIG_PROD    --env production < chemin/du/kubeconfig-prod
#        gh secret set RELEASE_DEPLOY_KEY  --env production < chemin/de/la/cle/release_bot
#
#   2. rejouer `tools/github-settings.sh` : l'étape k doit afficher « présent
#      dans l'environnement » pour les quatre, et l'avertissement « existe
#      encore au niveau dépôt » pour les quatre aussi (c'est attendu ici) ;
#
#   3. seulement après un run de release complet **vert** avec les secrets
#      d'environnement (deploy-preprod, smoke-test-preprod, audit-preprod, puis
#      deploy-prod et finalize-release), supprimer les copies du dépôt :
#
#        gh secret delete KUBE_CONFIG_PREPROD
#        gh secret delete PREPROD_BASIC_AUTH
#        gh secret delete KUBE_CONFIG_PROD
#        gh secret delete RELEASE_DEPLOY_KEY
#
#   4. rejouer le script une dernière fois : l'étape k doit être entièrement
#      verte (« absent du niveau dépôt » pour les quatre).
# ---------------------------------------------------------------------------
