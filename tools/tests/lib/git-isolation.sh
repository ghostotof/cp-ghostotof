# shellcheck shell=bash
#
# git-isolation.sh
# ---------------------------------------------------------------------------
# À sourcer en tête de toute suite de tools/tests/ qui crée des dépôts git
# (issue #304, règle de #26 : la machine hôte n'influence pas le résultat).
#
# Coupe la suite, et les scripts qu'elle lance (ils héritent de
# l'environnement), de tout ce que le poste ou l'appelant peut imposer à git :
#   - la configuration système et globale (signature des commits et des
#     étiquettes, hooks via core.hooksPath, branche par défaut…) ;
#   - la configuration passée par l'environnement (GIT_CONFIG_PARAMETERS,
#     GIT_CONFIG_COUNT/KEY_n/VALUE_n), prioritaire sur la précédente ;
#   - le dépôt imposé par l'environnement (GIT_DIR, GIT_WORK_TREE,
#     GIT_INDEX_FILE…), posé par exemple quand les suites tournent depuis un
#     hook git : sans ce retrait, elles écriraient dans le vrai dépôt.
# Seule l'identité reste à fournir, par `g()` ci-dessous.
#
# Vérifié par tools/tests/git-config-isolation.test.sh.
# ---------------------------------------------------------------------------

export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
unset GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT \
      GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY \
      GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_COMMON_DIR GIT_NAMESPACE GIT_CEILING_DIRECTORIES

# git avec une identité fixe, pour les dépôts que la suite construit.
g() { git -c user.name=test -c user.email=test@example.invalid "$@"; }
