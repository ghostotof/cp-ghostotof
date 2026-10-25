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
- [x] Suivi : neutralisation du rôle du prompt contournée — invisibles, crochets, borne avant NFKC (#662)
- [x] Suivi : journaliser la révision sans session 2026-07-28 (#664)
- [x] Suivi de l'audit de #675 : flux longs futurs, ligne d'appel mal attribuée, 4xx du routeur (#678)
- [x] Suivi : la complétion de l'argument `locale` du prompt rend une liste vide (#693) — livré en v0.29.0

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

- [x] Tâche 7 — Recherche : découverte OAuth derrière l'Ingress et autorisation différée (#646) —
      bloquée par : #640 — **2026-10-22 : deux notes sous `docs/research/` (sources et expérience Claude
      Code) ; recommandations validées par l'auteur le même jour**, ADR 0006 D3 amendé, questions ouvertes de
      la spec tranchées, tâches 8, 9, 12 et 14 ajustées dans leurs issues ; PR #692 fusionnée dans la mère (3eae019e)
- [x] Tâche 8 — Émission d'une connexion MCP, client pré-enregistré (#647) — bloquée par : #646 —
      **close le 2026-10-24** : PR #706 fusionnée dans la mère (f47f6cb2), plan `tasks/plan-t8-emission-connexion.md` ;
      `league/oauth2-server` 9.4.1 sans le bundle (décision de l'auteur du 2026-10-23, ADR 0006 D3 amendé),
      serveur d'autorisation, consentement (authentification de moins de 10 min exigée pour accorder),
      métadonnées et leur Ingress, zone nginx `oauth` ; règle `.claude/rules/mcp-oauth.md`. `claude mcp login`
      à froid prouvé contre la stack de dev le 2026-10-24 ; reports rangés sous les tâches 9 à 14
- [ ] Tâche 9 — Ouverture du corpus nominatif par la connexion MCP (#648) — bloquée par : #647
      — reports de la tâche 8 : registre RGPD §8 (le fournisseur d'IA du client reçoit les jetons) ;
      `bearer_methods_supported` annoncé avant l'authentificateur ; au point de jeton league lit
      `client_id` dans `Authorization: Basic`, la révocation non
- [ ] Tâche 10 — Durée de vie des jetons, rotation et purge (#649) — bloquée par : #647 — reports de
      la tâche 8, **dont deux importants, à livrer avant la release de la phase 2** : (1) un nouvel
      accord n'éteint pas l'accord précédent du même couple compte/client (avec #650) ; (2) point de
      jeton hors transaction, league révoque le code en dernier (rejeu concurrent → deux paires) —
      `revokeByHash` rendant le nombre de lignes. Aussi : `RevokedOnce` contre `McpConnection::revokedAt`,
      course révocation/rafraîchissement en 500, refus OAuth non journalisés, `is*Revoked` lit la carte
      d'identité Doctrine, deux horloges (league `time()` / `ClockInterface`). Reports de la tâche 9 :
      `lastUsedAt` écrit au rafraîchissement (jamais par appel) ; durées 30 j / 90 j de
      `McpConnection::IDLE_LIFETIME` / `MAX_LIFETIME` passées en configuration ; bornes au passage
      d'heure Europe/Paris, non testées (`McpAccessTokenRefusalTest` est à décalage fixe) ;
      **à livrer avant la release de la phase 2** (relecture de sécurité de la tâche 9) : le
      rafraîchissement (`ConnectionOfToken`, `RefreshTokenRepository::isRefreshTokenRevoked()`) ne juge
      ni `McpConnection::isExpiredAt()` ni le palier du compte — une connexion expirée ou un compte
      déchu obtient des jetons neufs, refusés ensuite : boucle refus/rafraîchissement, lignes de jetons
      accumulées ; le refus d'un jeton **connu** (révoqué, expiré, audience, connexion, scope, palier)
      n'écrit aucune ligne `security_audit` (événement `mcp-access-token-refused` par UUID, jamais pour
      `Malformed` ni `Unknown`)
- [ ] Tâche 11 — « Mes connexions MCP » (#650) — bloquée par : #648 — reports de la tâche 8 :
      connexions accumulées visibles et révocables ; révoquer la connexion, jamais les jetons un à un.
      Reports de la tâche 9 : une connexion MCP survit au changement de mot de passe et de rôles du
      compte (révoquer les connexions du compte, ou comparer `grantedAt` à une date de changement des
      identifiants)
- [ ] Tâche 12 — Enregistrement des clients par CIMD (#651) — bloquée par : #647 — reports de la
      tâche 8 : `client_id_metadata_document_supported: true` déjà annoncé ; domaine du client sur la page.
      Reports de la tâche 9 : borner la longueur du `client_id` CIMD écrit dans l'audit
      `nominative-corpus-read` (ou y écrire l'UUID interne du client)
- [ ] Tâche 13 — Backoffice MCP (#652) — bloquée par : #647 — reports de la tâche 8 : audit de
      l'enregistrement d'un client ; bornes de `register()` et des redirections (longueur, `parse_str`) ;
      borne du `client_id` (255 à la commande, 512 en base) ; second `findOneByClientId()` du
      consentement et course de suppression (FK en 500) ; regex de l'index violé dupliquée
- [ ] Tâche 14 — Livrer la phase 2 : préprod, page, registre (#653) — bloquée par : #648, #646 —
      reports de la tâche 8 : **Job k8s pour `app:mcp:client:register`** (jamais `kubectl exec`, sans
      lui rien n'est testable en préprod) ; Basic Auth préprod encore sur `/api/oauth` et `/api/mcp` ;
      `PrivacyPolicyPage.vue` à aligner sur le registre §3.4 ; CORS absent sur les deux documents ;
      documents servis aussi en HTTP clair (pas de `ssl-redirect` sur l'objet dédié) ; 10 req/min par
      pod sur `/token` face aux IP de sortie partagées de claude.ai et ChatGPT. Reports de la tâche 9 :
      vérifier en préprod ce que ChatGPT lit de `securitySchemes` (premier niveau ou `_meta`) et la
      forme liste de `_meta["mcp/www_authenticate"]` ; le 401 `invalid_token` sur toute requête au
      jeton refusé, face aux vrais clients ; mesurer la taille réelle en file du corpus (la taille
      seule, jamais le texte) contre `QUEUED_ANSWER_MAX_BYTES` ; CORS n'expose pas `WWW-Authenticate` ;
      la page publique de confidentialité dit encore « Aucun transfert de données hors de l'Union
      européenne » (`frontend/src/infrastructure/portfolio/content/fr.ts` et `en.ts`), contredite par
      le registre §8 à la sortie de la phase 2 ; un `pdftotext` et une ligne d'audit par appel de
      l'outil nominatif, sans quota par connexion — et le corpus rendu en entier avant d'être refusé
      pour sa taille (le refus coûte une lecture), à traiter avec ce quota ; contrôler au smoke
      préprod le `WWW-Authenticate` réel par la recherche de mentions D8

### Clôture

- [ ] Merge de clôture vers `develop` : archive de la spec et de `tasks/` sous `.claude/specs/archive/`

## Suivis (hors spec)

- Ressources MCP (#654) — bloqué par : #653
- Palier de confiance depuis l'AI Assistant de PhpStorm, tickets JetBrains LLM-25012 et IJPL-226161
  (#655) — veille

## Nés pendant la spec, hors de son périmètre (sur `develop`, pas sur la mère)

- [x] Veille : format et longueur des chaînes d'endoflife.date (#658) — livré en v0.29.0
- [x] Sécurité : aucune règle `access_control` ni route propre à un environnement (#659) — livré en v0.29.0
- [x] Sécurité : multipart refusé au backend, `file_uploads` coupé (#686, suivi CVE-2026-109618) — livré en v0.29.0
- [ ] Veille : borner le lien de documentation d'endoflife.date, hôte et longueur (#695) — suivi de #658, sévérité basse
- [ ] Sécurité : aucun service de sécurité redéfini pour un seul environnement (#698) — suivi de #659,
      sévérité nulle aujourd'hui, moyenne si le cas apparaissait
- [ ] Dépendances : API Platform hors du mélange 4.3/4.4, puis évaluer la 5.0 (#701) — dépréciation
      écrite en prod à chaque `POST /api/contact`
- [ ] Sécurité : le refus multipart de nginx passe avant `limit_req`, `limit_conn` et la borne de corps
      (#702) — suivi de #686, relevé par la relecture de l'étape 07 de #647
- [ ] Sécurité : épingler `BEARER.secure` à `true` en prod, l'exemption de parité n'a pas de filet (#703) — suivi de #659
- [ ] Veille : un cycle refusé laisse un cycle plus grossier prendre sa place (#704) — suivi de #658
- [ ] Tests : caches de parité orphelins, coût des trois conteneurs, traces cosmétiques (#705) — suivi de #659
