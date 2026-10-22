# Spec 0007 — Serveur MCP : suivi des tâches

Spec : `.claude/specs/0007-mcp-server.md`. Branche mère : `feature/379-mcp-paliers` (issue #379, dont
chaque tâche est une sous-issue). Une branche par tâche, `feature/spec-0007-t<N>-<slug>`, tirée de la
mère à jour, PR vers la mère. Le détail et les critères de chaque tâche vivent dans son issue ; les
blocages sont des liens natifs GitHub.

**`develop` reçoit la feature deux fois** (décision du 2026-10-19) : après la tâche 6, pour que la
phase 1 parte en release seule ; puis au merge de clôture, qui archive la spec et ce dossier.

## Phase 1 — sans authentification (paliers anonyme et de base)

- [x] Tâche 1 — Socle, `/api/mcp` répond et `get_about` rend sa route (#640) — bloquée par : aucune
- [x] Tâche 2 — Catalogue complet et test d'exposition symétrique (#641) — bloquée par : #640
- [x] Tâche 3 — Prompt `present_for_role` et garde D8 (#642) — bloquée par : #641
- [x] Tâche 4 — Journal `mcp` (IP tronquée) et registre RGPD (#643) — bloquée par : #640
- [x] Tâche 5 — Zone nginx `mcp` et smoke 429 (#644) — bloquée par : #640
- [x] Tâche 6 — Page « Brancher votre assistant » (#645) — bloquée par : #641
- [x] Suivi des relectures de #643 sur les tâches 1 à 3 (#663)
- [x] Suivi : réponses non sollicitées classées autrement que par le SDK (#671) — **avant la sortie
      de la phase 1**
- [x] Suivi : zone `mcpsession` et borne des paramètres (#672) — `0` et l'en-tête vide comptés, la
      révision 2026-07-28 exemptée quelle que soit la décision de #664, `_meta` accepté hors borne
- [x] Suivi : reports de faible sévérité des relectures de #663 et #645 (#673)
- [x] Suivi : objet vide de `JsonToolResult` et décodages JSON multiples par POST (#681) — relecture de #673
- [x] Suivi : `subscriptions/listen` tient un worker php-fpm 30 s, borné par `mcpconn` depuis #672
      (#675) — après la décision de #664, avant la sortie de la phase 1
- [x] Suivi : neutralisation du corpus de l'assistant de parcours alignée sur celle du rôle (#667) —
      neutraliseur commun `TagWordNeutraliser`, sans nonce sur `<documents>` (décision du 2026-10-21)

### Livraison de la phase 1

- [x] **Fait le 2026-10-21** : PR #685, fusionnée dans `develop` (8a621ab4). **Piège pour la suite** :
      `develop` porte maintenant le commit qui supprime `tasks/` (376ce0bf). Si l'on fusionne
      `develop` dans la mère, ce commit y entre aussi : `plan-t1-socle.md` disparaît sans conflit, et
      `todo.md` entre en conflit modification/suppression. À chaque reprise de `develop` dans la mère,
      restaurer `tasks/` depuis le parent côté mère (`git checkout HEAD -- tasks` avant de conclure la fusion).
      Premier passage vers `develop` : `tasks/` n'y entre jamais. PR d'une branche
      `feature/379-mcp-phase-1` tirée de la mère, où `tasks/` est retiré par un seul commit
      (`git rm -r tasks`) ; la mère le garde. La spec, encore ouverte, entre dans `develop` sous
      `.claude/specs/`. Pas d'archivage : la feature reste ouverte. **Avant** : le secret
      `MCP_D8_AUTHOR_NAME` posé aussi dans les secrets Dependabot (`gh secret set MCP_D8_AUTHOR_NAME
      --app dependabot`, #663) — sinon, une fois `pipeline.yml` dans `develop`, chaque push de Dependabot échoue la garde D8 ;
      `tools/github-settings.sh`, étape `l`, le vérifie
- [x] **v0.28.0 en prod le 2026-10-21** (PR #688, merge e4320a09, images `0.28.0-7cc1b17`, avec PHP
      8.5.12, #687). Smoke préprod : `initialize` sur l'hôte réel, 413 (content-length et chunked),
      `mcpsession` en 429 après 6 POST, `mcp` en 429 problem+json à la 43e requête. Prod, au palier
      anonyme en `curl` : `initialize` 200, `tools/list` (10 outils), `get_about`, `present_for_role`,
      `get_nominative_corpus` refusé, `DELETE` 200.
- [ ] **Reste de la release, à faire par l'auteur** : Claude Code branché en préprod (en-tête Basic,
      secret hors session) ; claude.ai, ChatGPT, Cursor et PhpStorm en prod ; les 429 de la zone `mcp` la
      première semaine (jusqu'au 2026-10-28).
      - 2026-10-22 : l'auteur a branché un Claude Code (environnement non précisé) : outils servis, corpus
        nominatif refusé, comme attendu en phase 1.
      - 2026-10-22, 429 de la zone `mcp` relevés dans les journaux nginx des deux pods de prod depuis la
        release : aucun ; le seul trafic MCP est le smoke `curl` du 2026-10-21. `/stack` affiche PHP 8.5.12.
- [x] Release (détail d'origine) : aucune variable d'hôte à poser — `allowed_hosts` dérive d'`APP_FRONTEND_BASE_URL`
      (`mcp.yaml`), déjà posée par overlay ; l'`initialize` du smoke MCP sur l'hôte public le prouve
      (un 403 dirait qu'il manque) ; smoke 429 lu ; Claude Code branché en préprod (en-tête Basic) ;
      claude.ai, ChatGPT, Cursor, PhpStorm vérifiés en prod ; zone `mcp` (60 req/min par IP) mesurée
      contre les IP de sortie partagées de claude.ai et ChatGPT (#673, point 2) : relever les 429 de la
      zone dans les journaux nginx de prod la première semaine, et rouvrir son débit s'ils tombent sur
      des appels légitimes

## Phase 2 — connexion MCP (palier de confiance)

- [ ] Tâche 7 — Recherche : découverte OAuth derrière l'Ingress et autorisation différée (#646) —
      bloquée par : #640 — **2026-10-22 : deux notes sous `docs/research/` (sources et expérience Claude
      Code), recommandation écrite, décision de l'auteur attendue** avant l'amendement de l'ADR 0006 et de la
      spec, puis la relecture des tâches 8, 9 et 14
- [ ] Tâche 8 — Émission d'une connexion MCP, client pré-enregistré (#647) — bloquée par : #646 —
      plan détaillé recommandé (étape 04)
- [ ] Tâche 9 — Ouverture du corpus nominatif par la connexion MCP (#648) — bloquée par : #647
- [ ] Tâche 10 — Durée de vie des jetons, rotation et purge (#649) — bloquée par : #647
- [ ] Tâche 11 — « Mes connexions MCP » (#650) — bloquée par : #648
- [ ] Tâche 12 — Enregistrement des clients par CIMD (#651) — bloquée par : #647
- [ ] Tâche 13 — Backoffice MCP (#652) — bloquée par : #647
- [ ] Tâche 14 — Livrer la phase 2 : préprod, page, registre (#653) — bloquée par : #648, #646

### Clôture

- [ ] Merge de clôture vers `develop` : archive de la spec et de `tasks/` sous `.claude/specs/archive/`

## Suivis (hors spec)

- Ressources MCP (#654) — bloqué par : #653
- Palier de confiance depuis l'AI Assistant de PhpStorm, tickets JetBrains LLM-25012 et IJPL-226161
  (#655) — veille
