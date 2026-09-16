#!/usr/bin/env bash
#
# github-settings.sh
# ---------------------------------------------------------------------------
# Applique les réglages GitHub du flux de release (spec 0006, D9), de façon
# IDEMPOTENTE, avec `gh` : à rejouer après tout changement, ou sur un fork.
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
#   d. environnement `production` : reviewer requis retiré (le merge est le
#      stop humain), secrets conservés (ils ne sont pas touchés par cet appel) ;
#   e. (rien à faire : delete_branch_on_merge, voir a) ;
#   f. ruleset tags `v*` : création, mise à jour, suppression, force-push
#      interdits — seul le job pose un tag ;
#   g. vérifie, sans les créer, la deploy key `release-bot` (écriture) et le
#      secret RELEASE_DEPLOY_KEY : la paire se génère hors dépôt, voir le
#      README en bas de ce fichier.
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
    -h|--help) sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
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

# ---- d. environnement production --------------------------------------------
step "d. Environnement production — plus de reviewer requis (le merge est le stop)"
# PUT remplace les règles de protection ; les secrets de l'environnement sont
# une autre ressource et ne bougent pas. deployment_branch_policy était null.
gh api -X PUT "$api/environments/production" --input - <<<'{"wait_timer":0,"prevent_self_review":false,"reviewers":[],"deployment_branch_policy":null}' >/dev/null
gh api "$api/environments/production" --jq '"   règles de protection : \([.protection_rules[].type] | join(",")) (vide = aucune)"'

# ---- g. deploy key et secret, vérifiés seulement ------------------------------
step "g. Deploy key release-bot et secret RELEASE_DEPLOY_KEY (vérification)"
if gh api "$api/keys" --jq '.[] | select(.title=="release-bot" and .read_only==false) | .id' | grep -q .; then
  ok "deploy key « release-bot » en écriture présente"
else
  warn "deploy key « release-bot » absente ou en lecture seule — voir le README ci-dessous"
fi
if gh secret list --repo "$repo" | awk '{print $1}' | grep -qx RELEASE_DEPLOY_KEY; then
  ok "secret RELEASE_DEPLOY_KEY présent"
else
  warn "secret RELEASE_DEPLOY_KEY absent — finalize-release échouera explicitement"
fi

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
# ---------------------------------------------------------------------------
