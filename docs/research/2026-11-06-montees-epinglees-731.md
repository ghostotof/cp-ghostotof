# Montées des paquets épinglés : Symfony AI 0.15, `symfony/mcp-bundle` 0.15, `mcp/sdk` 0.9 (issue #731)

- **Issue** : #731 (à traiter avant l'ouverture publique de la phase 2 de la spec 0007)
- **Date** : 2026-11-06
- **Statut** : **note de recherche, arbitrages rendus le 2026-11-06** (section 7). La famille AI est
  tranchée et livrée dans la première PR de #731 : les quatre paquets en `0.15.0` exacts, plus les trois
  `symfony/ai-*` jusqu'ici transitifs (`ai-platform`, `ai-generic-platform`, `ai-open-responses-platform`),
  désormais épinglés eux aussi, et un `ignore` Dependabot (ADR 0004, amendement du 2026-11-06). La famille
  MCP part dans une seconde PR, selon les décisions de la section 7.
- **Question** : pour chacun des six paquets épinglés en version exacte (ADR 0004 D1, ADR 0006 D7), quelle est
  la dernière version, qu'est-ce qui casse dans le projet, et faut-il monter ?

Conventions : chaque affirmation externe renvoie à une source numérotée `[n]` (section « Sources »), toutes
primaires (Packagist, dépôts des paquets à leurs tags, code). **[mesuré]** marque ce qui a été observé sur une
copie jetable du backend (section 6), **[lu]** ce qui est lu dans le code ou le changelog d'un paquet,
**[déduit]** ce qui est raisonné sans avoir été mesuré. Chaque constat porte une **confiance** (élevée, moyenne,
faible) et, pour une rupture, une **sévérité** estimée (bloquante, haute, moyenne, basse).

---

## 0. Synthèse

| Paquet | Épinglé | Dernière stable (2026-11-06) | Pré-version | Recommandation |
|---|---|---|---|---|
| `symfony/ai-bundle` | 0.13.0 | **0.15.0** (2026-10-22) | 1.0.0-BETA1 (2026-10-26) | monter en 0.15.0 |
| `symfony/ai-agent` | 0.13.0 | **0.15.0** | 1.0.0-BETA1 | monter en 0.15.0 |
| `symfony/ai-anthropic-platform` | 0.13.0 | **0.15.0** | 1.0.0-BETA1 | monter en 0.15.0 |
| `symfony/ai-scaleway-platform` | 0.13.0 | **0.15.0** | 1.0.0-BETA1 | monter en 0.15.0 |
| `symfony/mcp-bundle` | 0.14.1 | **0.15.0** (2026-10-22) | 1.0.0-BETA1 (même code) | monter en 0.15.0, **avec** `mcp/sdk` 0.9.0 |
| `mcp/sdk` | 0.8.1 | **0.9.0** (2026-10-18) | — | monter en 0.9.0, **avec** le bundle 0.15.0 |

Constats principaux :

| Constat | Confiance |
|---|---|
| La table de l'issue est toujours juste en versions **stables** ; une `1.0.0-BETA1` existe pour tout `symfony/ai-*` et le bundle MCP, hors de portée tant que `minimum-stability` vaut `stable`, et porteuse de ruptures lourdes côté agent (section 2.4). | élevée [1][2] |
| **Les deux familles sont indépendantes** : le bundle MCP 0.15 n'exige pas `symfony/ai-*` 0.15, ni l'inverse ; elles peuvent partir dans deux PR. Le bundle MCP et le SDK, eux, montent **ensemble** (`symfony/mcp-bundle` 0.15.0 exige `mcp/sdk ^0.9`, 0.14.1 exigeait `^0.8.1`). | élevée [1] |
| **Famille AI : aucune rupture mesurée.** Montée sur une copie, toute la suite `tests/Ai` passe, le lecteur `ProviderFailure` compris sur les vrais `ResultConverter` ; les formats de message qu'il lit sont inchangés. Seul fichier suivi qui bouge en plus des versions : `config/reference.php` (généré). | élevée [mesuré] |
| **Famille MCP : une rupture bloquante et six tests rouges**, tous attendus. `SecuritySchemesRegistry` n'implémente plus `RegistryInterface` (méthode `publishesListChanges()` ajoutée) : erreur fatale au démarrage. Le reste touche l'`Origin`, le journal d'appels de l'ère sans session, la recopie de `Tool` et le comportement d'une session trop grosse. | élevée [mesuré] |
| **Une prémisse de plusieurs gardes du projet tombe** : en 0.9 la réponse d'un `POST` ne transite plus par la file sortante de la session. La borne `QUEUED_ANSWER_MAX_BYTES`, sa mesure `QueuedAnswer` et une partie de la raison du lien session ↔ connexion reposaient dessus. Les tests restent verts, mais deviennent en partie vacants. | élevée [lu], moyenne sur l'ampleur [déduit] |
| Les 6 avertissements `config.unknown_key` de `lsp:check` **ne viennent pas d'un écart de version** : ils sont identiques avant et après la montée. | élevée [mesuré] |

---

## 1. Versions et contraintes croisées

Lu sur Packagist (métadonnées `p2`, minifiées, développées version par version) [1] et sur les tags des dépôts [2][3].

- **`symfony/ai-*`** : tags `v0.14.0` (2026-09-25), `v0.14.1` (2026-09-27), `v0.15.0` (2026-10-23),
  `v1.0.0-BETA1` (2026-10-26) dans le monorepo `symfony/ai` [2]. Chaque paquet 0.15.0 exige
  `symfony/ai-platform ^0.15` ; `symfony/ai-scaleway-platform` 0.15.0 exige en plus
  `symfony/ai-generic-platform ^0.15` et `symfony/ai-open-responses-platform ^0.15` (deux dépendances
  transitives qui montent avec, épinglées depuis en `0.15.0` exacts, ADR 0004). PHP `>=8.2`, Symfony `^7.3|^8.0` partout : compatible PHP 8.5 et Symfony 8.1.
  `symfony/ai-agent` 0.15.0 déclare un `conflict` avec `phpstan/phpstan 2.2.x-dev || 2.3.x-dev` (branches de
  développement seulement ; le projet a `2.3.5`, sans effet). `symfony/ai-platform` 0.15.0 n'exige plus
  `symfony/polyfill-php85` (BETA1 le réexige) ; le projet le met en `replace`, sans effet.
- **`symfony/mcp-bundle`** : 0.15.0 et 1.0.0-BETA1 exigent `mcp/sdk ^0.9` ; 0.14.0 et 0.14.1 exigeaient
  `^0.8.1` [1]. Le code du bundle est **identique** entre 0.15.0 et 1.0.0-BETA1
  (`git diff v0.15.0 v1.0.0-BETA1 -- src/mcp-bundle` vide) [2].
- **`mcp/sdk`** : 0.9.0 (2026-10-18), seule version après 0.8.1 [1][3]. Mêmes exigences qu'en 0.8.1, plus un
  `conflict` avec `phpstan/phpstan 2.3.0` (le projet a 2.3.5, sans effet). La branche `main` porte une trentaine
  de commits non publiés après 0.9.0 (section 4.5).
- **`composer outdated`** sur une copie fidèle au lock propose exactement `0.15.0` / `0.15.0` / `0.9.0`
  [mesuré]. Une résolution `composer require … -W` des six versions exactes réussit et monte aussi, par
  `-W`, `opis/json-schema` 2.6.0 → 2.6.1, `phpdocumentor/reflection-common` 2.2.0 → 2.2.1,
  `phpdocumentor/type-resolver` 2.0.0 → 2.1.0 et `webmozart/assert` 2.4.1 → 2.5.0 [mesuré]. Sans `-W`, ces
  quatre-là peuvent rester (non vérifié).

**Une montée de l'un force-t-elle l'autre ?** Oui au sein du MCP (bundle ↔ SDK, dans les deux sens : le SDK
0.9 seul est refusé par le bundle 0.14.1, et le bundle 0.15 seul exige le SDK 0.9). Non entre les familles :
aucun paquet `symfony/ai-*` n'exige `mcp/*` ni l'inverse (`symfony/ai-mcp-tool`, qui le ferait, n'est pas
installé) [1].

---

## 2. Famille AI : `symfony/ai-*` 0.13.0 → 0.15.0

### 2.1 Ce que le projet utilise

Trois classes de `src/` importent `Symfony\AI\*` : `Ai/Translation/Infrastructure/SymfonyAi/SymfonyAiContentTranslator`,
`Ai/Assistant/Infrastructure/SymfonyAi/SymfonyAiCareerAssistant` et le lecteur partagé
`Ai/Shared/Infrastructure/SymfonyAi/ProviderFailure` (ADR 0004 D1, amendé) ; s'y ajoutent
`Ai/Assistant/Infrastructure/Http/ReplayRefusingHttpClient` (décore `ai.scaleway.http_client`), la déclaration
de la plateforme Scaleway dans `config/services.yaml` (service `app.ai.platform.scaleway` : `Bridge\Scaleway\Factory::createPlatform`,
`ai.platform.model_catalog.scaleway`), `config/packages/ai.yaml`, et en test `FakeAgent` / `FakeStreamingAgent`
(implémentent `AgentInterface`, construisent `Execution`), `ProviderFailureTest` (les vrais
`Bridge\Anthropic\ResultConverter` et `Bridge\Scaleway\Llm\ResultConverter`), `ScalewayPlatformWiringTest`.

### 2.2 Ruptures qui touchent le projet

**Aucune mesurée.** Sur une copie montée en 0.15.0, `tests/Ai` passe entièrement, et la suite complète ne
révèle aucun échec lié à l'IA (section 6) [mesuré]. Détail des points vérifiés :

| Point | Constat | Source | Confiance |
|---|---|---|---|
| Messages lus par `ProviderFailure` (constantes `STATUS_PATTERN` et `ERROR_TYPE_PATTERN` de `ProviderFailure`) | Le haut de `Anthropic\ResultConverter::convert()` (401, 400, 429, ≥ 500, flux ≥ 400 « Unexpected response code », `API Error [type]`) est **identique** octet pour octet entre les deux tags ; `Scaleway\Llm\ResultConverter` est inchangé (`Error "%s": "%s".`, « Unexpected response code ») ; le chemin d'erreur du flux de `CompletionsConversionTrait` aussi. `ProviderFailureTest` vert. | `v0.13.0..v0.15.0 -- src/platform/src/Bridge/Anthropic/ResultConverter.php`, `…/Scaleway/Llm` [2] ; [mesuré] | élevée |
| Hiérarchie des exceptions | `ServerException` et `RateLimitExceededException` implémentent en plus `RetryableExceptionInterface` (ajout) ; aucune classe lue par `ProviderFailure` ne disparaît. Nouvelles `MaxOutputTokensException`, levée quand la limite de sortie coupe un **appel d'outil** (`tool_use`) — et, correction de la relecture, sur **tout** arrêt `max_tokens` d'un flux Anthropic, texte compris (`convertStream()`, à `message_stop`) —, et `MalformedToolCallException` (arguments d'outil illisibles). Sans effet ici : les deux agents ont `tools: false` et le traducteur, seul client Anthropic, n'est pas en flux. `ProviderFailure` les range depuis en `output-truncated` et `malformed-response` (#731). | `-- src/platform/src/Exception/`, CHANGELOG platform 0.15 [2] | élevée |
| `max_tokens` (`ai.yaml`, clés `ai.agent.translator.model.options.max_tokens` et `ai.agent.career_assistant.model.options.max_tokens`) | 0.15 ajoute l'option canonique `PlatformOptions::MAX_OUTPUT_TOKENS` ; un `max_tokens` explicite **gagne** (`??=`), Anthropic comme Generic Completions. Corps de requête inchangé ; le défaut de 1000 de `Claude` ne s'applique pas puisqu'on fixe `max_tokens`. | `-- src/platform/src/Bridge/Anthropic/ModelClient.php Claude.php …/Generic/Completions/ModelClient.php` [2] | élevée |
| Flux de l'assistant (`SymfonyAiCareerAssistant`, `asStream()`, `getMetadata()->get('token_usage')`) | `CompletionsConversionTrait::convertStream()` émet désormais l'usage **une fois, à la fin du flux** (avant la raison de fin) au lieu d'à l'arrivée du morceau ; l'usage final est le même. `AnswerControllerTest` (usage journalisé) vert. | `-- src/platform/src/Bridge/Generic/Completions/CompletionsConversionTrait.php` [2] ; [mesuré] | élevée |
| `AgentInterface`, `Execution` (faux agents de test) | `AgentInterface::call()` inchangé en 0.15 (il change en 1.0) ; `Execution::__construct()` prend un troisième argument **optionnel** (`Cancellation`). `FakeAgent`/`FakeStreamingAgent` compilent et passent. | `-- src/agent/src/Execution/Execution.php src/agent/src/Agent.php` [2] ; [mesuré] | élevée |
| Plateforme Scaleway déclarée hors `ai.yaml` (règle `ai.md`) | **Toujours nécessaire**, pour deux raisons. `AiBundle::processPlatformConfig()` 0.15.0, dans son bloc `'scaleway' === $type`, passe encore `new Reference('http_client')` en dur et ignore l'option `http_client` du type `scaleway`, exactement comme en 0.13.0. Et le nœud de configuration `scaleway` (`config/platform/scaleway.php`) n'a que `api_key` et `http_client` : aucune URL de base où porter le projet, la `Factory` partirait vers `https://api.scaleway.ai` sans lui (403). Deux sentinelles depuis, une par raison : `ScalewayHttpClientOptionSentinelTest` et `ScalewayPlatformOptionListSentinelTest` (la liste fermée des options du nœud). `Factory::createPlatform()` (arguments nommés de `services.yaml`) inchangé ; `ai.platform.model_catalog.scaleway` existe toujours. | `v0.15.0:src/ai-bundle/src/AiBundle.php`, `-- src/platform/src/Bridge/Scaleway/Factory.php` (vide) [2] | élevée |
| Arbre de configuration `ai.agent.*` (`platform`, `model`, `prompt.file`, `tools: false`) | Inchangé pour ce qu'utilise le projet ; ajouts seulement (`execution_strategy`, `mcp_server`…). Le compilateur passe. | `-- src/ai-bundle/config/options.php` [2] ; [mesuré] | élevée |

### 2.3 Ruptures sans effet ici (relevées pour mémoire)

Lues dans `UPGRADE.md` (0.13 → 0.14 → 0.15) et les CHANGELOG [2] : `TokenUsageInterface::getModel()` ajoutée
(BC break pour qui l'implémente — le projet ne fait que l'utiliser) ; `Test\InMemoryPlatform` supprimée (non
utilisée, le projet a ses `FakeAgent`) ; `convertStreamUsage()` prend un second argument (seulement pour qui
surcharge le trait) ; ruptures des bridges MiniMax, Replicate, HuggingFace, Perplexity, Albert, TypeSafe, des
stores (Postgres `hnsw`, SurrealDB, Elasticsearch…) ; plateforme `failover` taguée par son nom ;
`SimilaritySearch::getUsedDocuments()`. Rien de cela n'est installé ou appelé.

**Effets de bord sans rupture**, à connaître :

- Le bundle enregistre en 0.15 un alias `#[Target]` par plateforme configurée [2] (CHANGELOG ai-bundle 0.15).
  Le projet injecte ses agents par identifiant (`ai.agent.translator`, `ai.agent.career_assistant`), donc rien
  ne change ; un futur `PlatformInterface $anthropic` autowiré résoudrait désormais par nom. Confiance moyenne
  [déduit].
- Le bundle ajoute au profiler la frise des appels d'agents et de plateforme (0.14) — dev seulement. Basse.
- `config/reference.php` (suivi, généré par Symfony à la compilation) change : nouvelles plateformes
  (`edenai`, `fireworks`, `higgsfield`, `together`, `typesafe`, `venice`), options `bedrock`, `execution_strategy`,
  `mcp_server`… Il faut le committer avec la montée, sinon `lsp:check` s'arrête sur « The selected file
  "config/reference.php" changed during the diagnostics check » au premier passage [mesuré]. Sévérité basse,
  confiance élevée.

### 2.4 La 1.0.0-BETA1 (hors recommandation)

Publiée le 2026-10-26, c'est une pré-version (refusée par `minimum-stability: stable` sans `@beta`). Ses
ruptures **toucheraient** le projet [2] (`UPGRADE FROM 0.15 to 1.0`) : `AgentInterface::call()` rend
`ExecutionInterface` au lieu d'`Execution` (signature de `FakeAgent`, `FakeStreamingAgent`, et le typage
`Execution` de `SymfonyAiCareerAssistant::textFragments()`/`relay()`) ; `MessageBag` devient immuable et l'agent
ne modifie plus le sac qu'on lui passe ; le composant `Chat` est retiré. Confiance élevée sur l'existence de ces
ruptures, sévérité haute si l'on montait en 1.0 ; sans objet pour 0.15.

### 2.5 Recommandation AI

**Monter les quatre paquets en `0.15.0` exacts**, dans une PR à part du MCP. Confiance élevée. Coût : les
versions dans `composer.json`/`composer.lock`, `config/reference.php`, et les mentions de `0.13.0` dans
l'ADR 0004 (D1 et son amendement du 2026-10-19) et dans `.claude/rules/ai.md` (paragraphe de la plateforme
Scaleway : « the bridge reports… », « symfony/ai-bundle hard-codes… » ; la liste des pins). Dans le code, le
numéro de version n'est plus écrit (décision du 2026-11-06, livrée avec la montée) : le commentaire de
`config/services.yaml` (service `app.ai.platform.scaleway`) et les docblocks de `ProviderFailure`,
`ReplayRefusingHttpClient`, `ProviderFailureTest` et `ScalewayPlatformWiringTest` renvoient à « la version
épinglée (composer.json) », qu'une montée n'oblige plus à réécrire. Le commentaire « Don't move it back into
`ai.yaml` until a bundle version honours the option » reste vrai en 0.15.0, complété par la seconde raison
(pas d'URL de base).

---

## 3. Le bundle MCP 0.14.1 → 0.15.0

Diff du bundle seul [2] (`v0.14.1..v0.15.0 -- src/mcp-bundle`, 16 fichiers) et CHANGELOG 0.15 :

- **`servers.<name>.http.allowed_origins`** (nouveau) : passé à `MiddlewareFactory` puis à
  `DnsRebindingProtectionMiddleware` du SDK 0.9 (section 4.2). Défaut `[]` : seules les requêtes de **même
  origine** passent. Une validation refuse `allowed_origins` quand `allowed_hosts: false`.
- **`servers.<name>.session.lock`** (nouveau, désactivé par défaut) : enveloppe le SDK
  `Builder::setSessionLock()` — un `FileSessionLock` à côté du store `file`, ou un `SymfonyLockSessionLock` sur
  une `LockFactory` pour tout autre store (le projet est en store `cache` : il faudrait une `LockFactory`, donc
  `symfony/lock`, suggéré par le bundle).
- `McpPass` lit `CompletionProvider::$provider` seul (le SDK a retiré `providerClass`) ; le projet écrit
  `#[CompletionProvider(enum: Locale::class)]` (`PresentForRolePrompt`), sans effet.
- **Toujours pas d'option de capacités du serveur** (`capabilities` n'existe que sous `mcp.clients`) : la
  passe `TruthfulCapabilitiesPass` reste la seule façon d'écrire l'annonce (section 4.3).
- Identifiants de services inchangés : `mcp.server.portfolio.{builder,registry,controller,middleware_factory,session.store}`,
  `mcp.psr_http_factory`, `mcp.http_foundation_factory`, `mcp.psr17_factory` — le projet en décore ou injecte
  sept (`McpEndpointController`, `SecuritySchemesRegistry`, `BoundedSessionStore`, `McpCallLog`,
  `TruthfulCapabilitiesPass`, les contrôleurs OAuth de `Mcp/Connection`). Le compilateur passe [mesuré].
- `ServerConnectionInterface::getServerCapabilities()` : côté client MCP du bundle, non utilisé.

---

## 4. Le SDK `mcp/sdk` 0.8.1 → 0.9.0

Sources : `CHANGELOG.md` de 0.9.0 [3] (long, marqué « [BC Break] » par endroits), puis
`git diff v0.8.1 v0.9.0 -- <chemin>` sur chaque classe que le projet étend, décore, recopie ou appelle [3], puis
la suite de tests sur une copie (section 6).

### 4.1 Ruptures qui touchent le projet (classées par sévérité)

| # | Rupture | Côté projet | Source | Sévérité | Confiance |
|---|---|---|---|---|---|
| R1 | `RegistryInterface` gagne `publishesListChanges(): bool` (« a decorating registry forwards the answer of the one it wraps »). | `src/Mcp/Infrastructure/Registry/SecuritySchemesRegistry` n'implémente plus l'interface : **erreur fatale** au chargement de la classe, donc au démarrage du noyau (vu par `lsp:check` et PHPStan). Correctif : déléguer à `$this->inner`. | `-- src/Capability/RegistryInterface.php` [3] ; [mesuré] | **bloquante** | élevée |
| R2 | **La réponse d'un `POST` ne passe plus par la file sortante de la session** : le transport garde les réponses en mémoire (`StreamableHttpTransport::createJsonResponse()` sur `$this->responses`), la file ne porte plus que les requêtes et notifications initiées par le serveur. | Prémisses à reprendre : `GetNominativeCorpusTool::QUEUED_ANSWER_MAX_BYTES` (sa déclaration et son usage dans `__invoke()`) et `Presentation/Tool/QueuedAnswer` (« ce que le SDK met en file ») ; la raison principale du lien session ↔ connexion de `BoundedSessionStore` (docblock de la classe, passage sur `Protocol::consumeOutgoingMessages()` : le SDK « vide toute la file à tout `POST` » — vrai désormais seulement pour les messages initiés par le serveur, qu'il n'émet pas) ; `RouteBackedToolsTest::testTheAnswerQueuedInTheSessionStaysFarBelowTheSessionBound`, `GetNominativeCorpusToolTest::testTheToolMeasuresWhatTheSdkQueues` et `testAQueuedAnswerAtTheBoundFitsInTheSession` **restent verts** (ils mesurent la réponse HTTP réencodée, pas la ligne `cache_items`) mais ne mesurent plus ce que leur nom dit. Les règles `mcp.md` (« Sessions », « two writes per `tools/call` ») et le docblock de l'outil deviennent faux. Rien ne casse ; c'est de la dette de vérité. | CHANGELOG 0.9.0 « [BC Break] Fix lost responses… » ; `v0.9.0:src/Server/Transport/StreamableHttpTransport.php`, `createJsonResponse()` [3] ; tests verts [mesuré] | haute (documentaire et décisionnelle) | élevée sur le mécanisme, moyenne sur le nombre exact d'écritures par appel [déduit] |
| R3 | Une sauvegarde de session ratée n'empêche plus la réponse : en 0.8.1 la réponse était dans la session, donc perdue avec elle ; en 0.9 elle est déjà au transport (`Protocol` attrape l'échec de `save()` et journalise « Failed to save session »). | `McpEndpointTest::testAnOversizedSessionIsNeitherKeptNorReusable` (son assertion d'absence de `Mcp-Session-Id`) **rouge** : un `initialize` dont la session dépasse `MAX_BYTES` reçoit maintenant un `Mcp-Session-Id`… d'une session qui n'existe pas (la requête suivante aura un 404 et le client se réinitialisera). La borne protège toujours le disque ; le contrat client change (section 5). Même effet pour un `tools/call` dont la session déborde : la réponse arrive, la session est détruite. | `v0.9.0:src/Server/Protocol.php` (le `catch` autour de `save()`, « Failed to save session ») vs `v0.8.1` (réponse mise en file dans la session) [3] ; [mesuré] | moyenne | élevée |
| R4 | `DnsRebindingProtectionMiddleware` contrôle l'`Origin` **à part**, schéma et port compris, contre `allowedOrigins` (vide par défaut) ; la même origine (déduite de l'URI PSR-7 de la requête) passe toujours ; `Host` est toujours vérifié, absent compris. | `McpEndpointGetTest::testAGetFromThePageOfTheSiteIsServed` **rouge** : `Origin: http://localhost:5173` (le Vite de dev) sur l'API `localhost:8080` reçoit 403 « Invalid Origin header ». La limite acceptée de `mcp.md` (« the `Origin` check ignores scheme and port ») disparaît : c'est un durcissement. En prod le site et l'API partagent l'origine ; `trusted_proxies: private_ranges` + `x-forwarded-proto`/`-port` (`framework.yaml`, clés `trusted_proxies` et `trusted_headers`) devraient faire reconnaître `https://<site>` comme même origine derrière l'Ingress — non mesuré. Aucune page du frontend n'appelle `/api/mcp` (seul `mcpClientConfiguration.ts` en construit l'URL pour l'affichage). | `v0.9.0:src/Server/Transport/Http/Middleware/DnsRebindingProtectionMiddleware.php` ; `v0.14.1..v0.15.0 -- src/mcp-bundle/src/Http/MiddlewareFactory.php` [2][3] ; [mesuré] | moyenne (clients navigateur) | élevée sur le comportement, moyenne sur la prod [déduit] |
| R5 | L'ère sans session (`2026-07-28`) **émet maintenant `RequestEvent`, `ResponseEvent` et `ErrorEvent`** (`StatelessProtocol` reçoit le répartiteur du `Builder`), sur une `Session` en mémoire qui porte `RequestMeta::class`, `client_capabilities`, `protocol_version` — **pas `client_info`**. | `Infrastructure/Log/McpCallLog` écrit sa ligne par `onResponse()`/`onError()`, donc `writeModernCall()` (via `ModernExchange`) n'est plus appelé (garde `wroteALine`) : la ligne reste unique, mais `declaredClient` y vaut `null` (lu sous `client_info`, constante `SDK_CLIENT_INFO`, par `declaredClient()`) et **`durationMs` change de sens** (durée du traitement du message, plus celle de toute la requête HTTP que décrit `mcp-journaux.md`). Trois tests **rouges** : `McpCallLogTest::testAModernToolCallLeavesOneLineWithTheExpectedFields`, `testAModernDeclaredClientIsBounded`, `testADeclaredClientLosesItsControlAndFormatCharacters`. Piste : lire `RequestMeta::class` dans la session pour l'ère sans session ; `ModernExchange` ne sert plus qu'aux échanges sans événement (refus avant le SDK, 401 différé, refus de bord). | CHANGELOG 0.9.0 « Fix `RequestEvent`… not being dispatched for `2026-07-28` requests » ; `v0.9.0:src/Server/Stateless/StatelessProtocol.php` (répartition de `RequestEvent`, `ResponseEvent`, `ErrorEvent`) [3] ; [mesuré] | moyenne (journal dégradé, pas de fuite) | élevée |
| R6 | `Mcp\Schema\Tool` gagne le paramètre de constructeur `execution` (`?ToolExecution`). | `Infrastructure/Registry/ToolWithSecuritySchemes` (son constructeur) recopie chaque champ sauf celui-là : un outil qui le déclarerait le perdrait. `SecuritySchemesRegistryTest::testTheCopyKnowsEveryParameterOfTheSdkTool` **rouge**, comme prévu par sa conception (règle `mcp.md`). Aucun outil du projet ne le déclare : correctif = recopier `execution`. `Tool` 0.9 ne connaît toujours pas `securitySchemes` : le décorateur reste nécessaire (ADR 0006 D3). | `-- src/Schema/Tool.php` [3] ; [mesuré] | basse | élevée |
| R7 | `SessionStoreInterface::exists()` peut lever `SessionStoreException` ; `Psr16SessionStore::exists()` la lève quand le cache est injoignable (au lieu de `false`) ; `Protocol` répond alors **503** (« Session store is unavailable », JSON-RPC `-32603`) au lieu de 404. `Session::save()` appelle maintenant `exists()` avant d'écrire (session détruite entre-temps) et ne réécrit que les clés modifiées sur ce que le store contient. | `BoundedSessionStore::exists()` appelle `inner->read()` puis, sur échec, `inner->exists()` : base en panne → l'exception remonte. Au `POST`, le SDK en fait un 503 ; au `GET`, `McpEndpointController::eventStream()` ne l'attrape pas → probablement un 500 du noyau (non mesuré). La décision #673 (session présente mais illisible → 404) n'est pas touchée : `read()` de `Psr16SessionStore` avale toujours ses erreurs. Une lecture de plus par requête est probable : `save()` appelle `exists()`, que le décorateur sert en **lisant** la ligne, puis `load()` relit (servi ou non par le cache de lecture du décorateur selon l'ordre des appels — non mesuré) ; `testAnAuthenticatedCallCostsExactlyOneQueryMore` reste vert (comparaison relative), ce qui ne dit rien du coût absolu. | `-- src/Server/Session/Psr16SessionStore.php SessionStoreInterface.php Session.php`, `v0.9.0:src/Server/Protocol.php` (réponse « Session store is unavailable ») [3] ; tests verts [mesuré] | moyenne | élevée sur le SDK, moyenne sur le GET et le coût [déduit] |
| R8 | `CallToolHandler` **valide `structuredContent` contre l'`outputSchema`** ; un écart devient un `CallToolResult` `isError: true` (« Invalid structured output for tool… ») et une ligne `error` sur le canal `mcp` portant `validation_errors`. | Tous les outils déclarent un `outputSchema` fermé et les tests les comparent déjà au `SchemaValidator` : rien de rouge. Ce que ça change : un écart futur entre une route et le schéma de son outil, autrefois servi tel quel, devient une erreur d'outil — plus sûr, mais l'outil cesse de répondre « exactement ce que sert la route » (ADR 0006 D1) jusqu'à correction. Le message d'erreur cite pointeurs et messages du validateur, rendus au client et journalisés (`SdkChannelRedactor` borne à 256 caractères, ne retire pas `validation_errors`) — à relire pour l'outil nominatif (son schéma est fermé ; un message d'opis cite-t-il une valeur ? non vérifié). | CHANGELOG « [BC Break] Validate a tool result's `structuredContent` » ; `-- src/Server/Handler/Request/CallToolHandler.php` [3] | basse | élevée sur le mécanisme, faible sur le contenu des messages |
| R9 | `SchemaValidator` : constructeur `(?Validator $validator, ?LoggerInterface $logger)` (le logger passe deuxième) ; `validateAgainstJsonSchema()` ne valide plus un tableau vide comme objet. | Les tests l'instancient sans argument (`GetAboutToolTest::testTheStructuredContentConformsToTheDeclaredOutputSchema`, `RouteBackedToolsTest::testTheToolIsReadOnlyAndItsOutputConformsToItsClosedSchema`, `GetContactInfoToolTest::testItIsReadOnlyAndItsOutputConformsToItsClosedSchema`, `GetNominativeCorpusToolTest::testAValidConnectionReadsTheCorpusInEachLanguage`) : inchangé, verts. | `-- src/Capability/Discovery/SchemaValidator.php` [3] ; [mesuré] | basse | élevée |
| R10 | Typage générique : `Page<TItem>` ; PHPStan niveau max signale 5 erreurs sur la copie montée : `SecuritySchemesRegistry::getTools()` (`instanceof` toujours vrai, puis `Page<ToolWithSecuritySchemes>` rendu là où `Page<Tool>` est attendu, `Page` est invariant), `SecuritySchemesRegistryTest::published()` et `::toolNames()` (type générique manquant), `tests/Mcp/Support/ReadsPortfolioRegistry::registeredPrompts()` (`assertInstanceOf` toujours vrai). `make back-quality` serait rouge. | `-- src/Schema/Page.php` [3] ; PHPStan sur la copie [mesuré] | moyenne (CI bloquante) | élevée |
| R11 | Le canal `mcp` reçoit de nouveaux messages : « Session store is unavailable. » (`error`, contexte `session_id`, `exception`), « Failed to save session: %s » (`error`), « Client request timed out. », « Received a client response for an unknown or timed out request ID. » (`warning`), et `Protocol` ne met plus le message brut dans « Received message to process. » (`info`). | `SdkChannelRedactor` retire `session_id` et normalise l'exception : rien ne fuit à première lecture. « Failed to save session » sera désormais écrit en `error` à chaque session refusée par la borne (R3) — en plus du `warning` du décorateur ; en prod, au-dessus du plafond `warning` : une ligne par tentative d'un client abusif. `SdkChannelRecordsTest` vert. | `v0.9.0:src/Server/Protocol.php` (les messages cités) [3] ; [mesuré] | basse | moyenne |

### 4.2 Ce qui ne casse pas, vérifié

- **Capacités** : `InitializeHandler` retire désormais `listChanged` de toute annonce (`withoutListChanged()`), et la
  détection n'annonce `listChanged` qu'avec un bus **et** un registre qui publie. Mais elle annonce toujours
  `logging: true` : `TruthfulCapabilitiesPass` (`setCapabilities()` à arguments nommés, dans `process()`) reste
  nécessaire et compatible (le nouveau paramètre `tasks` a un défaut). `AnnouncedCapabilitiesTest` vert.
  `-- src/Server/Builder.php src/Schema/ServerCapabilities.php src/Server/Handler/Request/InitializeHandler.php`
  [3] ; confiance élevée.
- **Révisions de protocole** : les cas de `ProtocolVersion` sont les mêmes (seuls des garde-fous et
  `supportsSseResumption()` s'ajoutent) ; `MessageInterface::PROTOCOL_VERSION` passe à `2026-07-28` mais le
  serveur négocie par `handshakeVersions()`. `McpEndpointGetTest` (versions 2025-06-18, 2025-11-25 servies,
  2026-07-28 et inconnue en 400) vert. Confiance élevée.
- **Refus avant le SDK** (#644, #664, #675, #671) : `InboundMessageClassificationTest` (table contre le vrai
  `MessageFactory`), `InboundMessageRefusalTest`, `NoAnswerIsAStreamTest` (lit `REGISTERED_MESSAGES` et les
  constantes de `StatelessProtocol`, inchangées), `DeferredAuthorizationListenerTest` : verts. Confiance élevée.
- **Garde D8, prompt `present_for_role`** : `AuthorNeverNamedTest`, `PresentForRolePromptTest`, `PromptRoleTest`
  verts. Confiance élevée.
- **Phase 2 (OAuth, `Mcp/Connection`, `McpBearerAuthenticator`)** : elle n'utilise du SDK et du bundle que le
  pont PSR-7 (`mcp.psr_http_factory`, `mcp.http_foundation_factory`, `mcp.psr17_factory`), inchangé. Les
  middlewares d'autorisation du SDK (refondus en 0.9 : `OAuthProxyMiddleware`, `ClientRegistrationMiddleware`,
  `OAuthRequestMetaMiddleware`, `JwksProvider` supprimés ; `AuthorizationMiddleware` + `ScopePolicy`) ne sont
  pas utilisés (ADR 0006 D4 « jamais les middlewares d'autorisation du SDK »). Tous les tests `tests/Mcp/Connection`
  verts. Confiance élevée. Note : D4 disait que « la phase 2 n'attend pas un bundle compatible avec
  `mcp/sdk` 0.9 » — c'est confirmé, rien de la phase 2 n'en dépend.
- **Parité entre environnements** (#659, #698) et `ApiRouteExposureTest` : résultat de la suite complète en
  section 6.

### 4.3 Opportunités ouvertes par 0.9 / 0.15 (aucune n'est nécessaire)

- **Verrou de session** (`session.lock`) : fermerait « la course propre au SDK » acceptée dans `mcp.md`
  (Sessions, lien de connexion). Demande `symfony/lock` et une `LockFactory` sur PostgreSQL, à confronter à
  l'ADR 0005 et au verrou consultatif existant. Coût : un verrou par requête MCP.
- **Simplifications rendues possibles par R2** : la borne `QUEUED_ANSWER_MAX_BYTES` et la mesure `QueuedAnswer`
  n'ont plus de raison d'être dans leur forme ; le lien session ↔ connexion perd sa justification principale
  (il garde un intérêt : la session porte `clientInfo`, le dernier `_meta`). **Décidé** : le garder, en défense en
  profondeur (section 7, décision 4).

### 4.4 Ruptures du SDK sans effet ici

Côté **client** (tout le chapitre « Client » du CHANGELOG : ère moderne par défaut, `TimeoutException`,
`getServerCapabilities()`…) : le projet n'utilise pas le client. **Autorisation** : voir 4.2. **stdio** : désactivé
(`transports.stdio: false`). **Ids des requêtes vers le client en UUID**, `ClientGateway::log()` /
`ClientLogger`, élicitation, échantillonnage : aucun outil n'en émet. `ElicitationSchema::getDefaults()`,
`AbstractSchemaDefinition::getDefault()` : non utilisés. `#[CompletionProvider]` sans `providerClass` : le projet
écrit `enum:`. `#[Schema]` : `exclusiveMinimum`/`exclusiveMaximum` deviennent des booléens dans le type
phpdoc — le projet ne les utilise pas (non vérifié exhaustivement, aucune erreur PHPStan). `Psr6SessionStore`
et `PassthroughMiddleware` : ajouts. Source : CHANGELOG 0.9.0 [3].

### 4.5 Ce qui attend dans `main` du SDK, non publié

Après `v0.9.0`, `main` (au 2026-11-06) porte notamment : réponse 404 à un `DELETE` d'une session inconnue (#638,
le projet le documente aujourd'hui comme un 200 du SDK, `mcp.md` « Sessions »), fin immédiate d'un flux
`listen` qui ne peut rien porter (#618, refusé ici de toute façon), `server/discover` servi par un handler (#622),
« Fix result lists being sent as JSON objects » (#656), JSON compact des formateurs de résultats (#647),
`Session::save()` qui n'efface plus une session que le store n'a pas su lire (#637), liaison et rotation de
`requestState` (#625, #626), validation des noms d'outils (128 caractères, jeu de caractères, #639) [3]
(`git log v0.9.0..origin/main`). À relire à la montée suivante ; #637 et #638 touchent directement
`BoundedSessionStore`. Confiance élevée sur leur présence, faible sur leur date de publication.

---

## 5. Ce qui changerait pour un client MCP (notes de release)

Avec bundle 0.15.0 + SDK 0.9.0, sans autre changement du projet :

1. **`Origin`** : une requête portant un `Origin` d'une autre origine que l'API (schéma, hôte **et port**) est
   refusée en **403** `text/plain` « Forbidden: Invalid Origin header. » — POST, GET, DELETE. Avant, seul l'hôte
   comptait. Touche les clients qui tournent dans un navigateur (MCP Inspector sur `http://localhost:6274`, une
   page tierce). Les connecteurs hébergés (claude.ai, ChatGPT) et les clients natifs n'envoient en principe pas
   d'`Origin` — confiance moyenne, à vérifier en préprod. Un `Host` absent est refusé (403).
2. **CORS** : le préflight autorise `Mcp-Method`, `Mcp-Name`, `Mcp-Param-*` et **expose `WWW-Authenticate`**
   (utile au défi OAuth lu depuis un navigateur) [3].
3. **Store de sessions injoignable** : **503** JSON-RPC `-32603` « Session store is unavailable. » au lieu d'un
   404 — le client doit réessayer au lieu de se réinitialiser [3]. Le GET servi par le projet suit-il ? À
   trancher (R7).
4. **Session trop grosse** (borne de 512 KiB) : l'`initialize` répond avec un `Mcp-Session-Id` dont la session
   n'existe pas ; la requête suivante reçoit 404 et le client se réinitialise. Un `tools/call` qui fait déborder
   la session reçoit désormais sa réponse (avant : aucune réponse JSON-RPC, puis 404) [mesuré].
5. **Sortie d'outil non conforme à son `outputSchema`** : `isError: true` « Invalid structured output for tool
   '…' » au lieu de la valeur non conforme [3]. Ne devrait jamais se produire (tests de schéma par outil).
6. **En-têtes `Mcp-Param-*`** (SEP-2243, ère 2026-07-28) : un en-tête reconnu dont l'argument miroir manque au
   corps est refusé en `-32020` au lieu d'être accepté [3]. Aucun outil n'a d'argument miroir ici ; basse.
7. **`completion/complete`** sans `argument.name` ou `argument.value` : refusé au lieu d'une complétion vide [3].
8. **Schéma d'entrée d'un outil** : un `properties` non objet ou un `required` non liste est refusé à la
   construction — côté serveur seulement, les outils du projet passent [mesuré].
9. **Inchangé** : révisions négociées (2024-11-05 à 2025-11-25 par `initialize`, 2026-07-28 sans session),
   capacités annoncées (outils, prompts, complétions ; ni `listChanged` ni `logging`), refus de `listen`
   (`-32601`), lots, réponses client, 401 différé, `securitySchemes`, `Cache-Control: no-store` [mesuré].

---

## 6. Mesures

Toutes faites sur des **copies jetables** du backend (`git archive HEAD backend`, donc sans aucun fichier non
suivi ni secret), dans des conteneurs `--rm` de l'image `cp-ghostotof-backend:latest`, sous l'utilisateur `dev` ;
la base est un PostgreSQL 18.6 jetable sur un réseau dédié, jamais celui de la stack. Le dépôt et son `vendor/`
n'ont pas été touchés par ces mesures. Les mesures faites ensuite **dans la stack**, pour la PR AI (suite
complète, `make back-quality`, `lsp:check`), l'ont été après réalignement de son `vendor/` sur le verrou par
`composer install`, le 2026-11-06 : le `vendor/` d'un conteneur ne suit pas le dépôt (il n'est pas monté depuis
l'hôte), et un `vendor/` en retard aurait mesuré autre chose que le verrou.

**`lsp:check`, ligne de base** (copie fidèle au lock, Language Tools 0.23.0 épinglé) — **6 avertissements**,
0 bloquant :

```
config/packages/ai.yaml:26:17  config.unknown_key  ai.agent.translator.model.name
config/packages/ai.yaml:27:17  config.unknown_key  ai.agent.translator.model.options
config/packages/ai.yaml:34:21  config.unknown_key  ai.agent.translator.model.options.max_tokens
config/packages/ai.yaml:50:17  config.unknown_key  ai.agent.career_assistant.model.name
config/packages/ai.yaml:51:17  config.unknown_key  ai.agent.career_assistant.model.options
config/packages/ai.yaml:54:21  config.unknown_key  ai.agent.career_assistant.model.options.max_tokens
```

**`lsp:check` après montée** (avec le correctif R1 appliqué à la copie, sans quoi le noyau ne démarre pas) :
les **mêmes 6**, rien de plus.

**Diagnostic des 6 `config.unknown_key`** : `ai.agent.<nom>.model` est un `variableNode` dans
`src/ai-bundle/config/options.php` (nœud `agent.*.model`) à v0.13.0, v0.15.0 **et** v1.0.0-BETA1 [2] : Symfony
accepte n'importe quoi dessous (règle unique : une chaîne, ou un tableau avec `name`), et Language Tools ne
peut connaître aucune clé sous un nœud variable. Faux positif par construction, déjà documenté comme tel dans
`backend-outillage.md` ; **ni causé ni corrigé par la montée**. Confiance élevée. Seule façon de les faire
disparaître côté projet : écrire le modèle en chaîne avec ses options en paramètres de requête
(`'claude-sonnet-5?max_tokens=4096'`, forme acceptée par le même nœud) — décidé : non, ils restent visibles (section 7, décision 9).

**`composer outdated`** (copie fidèle au lock) :

```
mcp/sdk                       0.8.1   ~ 0.9.0
symfony/ai-agent              0.13.0  ~ 0.15.0
symfony/ai-anthropic-platform 0.13.0  ~ 0.15.0
symfony/ai-bundle             0.13.0  ~ 0.15.0
symfony/ai-scaleway-platform  0.13.0  ~ 0.15.0
symfony/mcp-bundle            0.14.1  ~ 0.15.0
```

**PHPUnit `tests/Ai` + `tests/Mcp`** (1810 tests) : copie au lock **1809 verts, 1 échec** d'environnement
(`McpEndpointPathTest::testTheFrontendPublishesThePath`, fichier du frontend non monté dans la copie) ; copie
montée + correctif R1 **1803 verts, 7 échecs** : le même échec d'environnement et les six de la section 4.1
(R3 ×1, R4 ×1, R5 ×3, R6 ×1). Aucun échec dans `tests/Ai`.

**PHPStan** (copie montée) : erreur fatale R1 d'abord ; une fois R1 corrigé, **5 erreurs** (R10).

**Suite complète** (`bin/phpunit`, 5802 tests) : copie au lock **2 échecs**, tous deux d'environnement (le fichier du frontend ci-dessus, et `DownloadCvControllerTest::testAuthenticatedRequestReturnsThePdfFixture`, dont le PDF n'est pas dans une copie `git archive`) ; copie montée + correctif R1 : ces 2 plus **les mêmes six** de la section 4.1, rien d'autre. Les gardes de parité entre environnements (`SecurityConfigEnvironmentParityTest`, `RouteEnvironmentParityTest`, `SecurityServicesEnvironmentParityTest`), `ApiRouteExposureTest`, `RouterScopeTest` et les gardes de dépréciation d'API Platform passent après montée.

**Sonde de la ligne de session** (test ajouté à la copie seulement, corpus fictif) : après un appel de
`get_nominative_corpus`, la ligne `cache_items` de la session fait 321 octets en 0.8.1 et 348 en 0.9.0, pour une
réponse HTTP de 2 654 octets — en 0.8.1 la réponse a donc été mise en file puis vidée dans la même requête, ce
que la ligne finale ne montre pas ; la sonde ne départage pas les deux versions sur ce point (la preuve de R2
est la lecture du code). La session 0.9 est 27 octets plus grosse au repos (nouvelles clés internes).

---

## 7. Questions et décisions de l'auteur (2026-11-06)

1. **Deux PR ou une ?** Les familles n'ont rien en commun (section 1). **Décidé : deux PR**, AI d'abord
   (livrée), MCP ensuite, séparée.
2. **`allowed_origins`** (R4) : laisser vide (même origine seulement), ou y mettre une origine ?
   **Décidé : l'origine du site, lue dans une variable d'environnement** (une valeur par environnement, rien
   en dur dans `mcp.yaml`).
3. **La borne de la réponse nominative** (R2) : garder `QUEUED_ANSWER_MAX_BYTES`, la remplacer, ou la
   retirer ? **Décidé : retirer `QueuedAnswer`, la borne et les trois tests devenus vacants**, remplacés par
   un test qui épingle que la réponse **ne transite pas par la session** (la prémisse nouvelle, au lieu de
   l'ancienne).
4. **Le lien session ↔ connexion** (R2) : le garder ou le retirer ? **Décidé : le garder, en défense en
   profondeur** (la session porte `clientInfo` et le dernier `_meta`) ; sa justification, en partie
   devenue fausse (R2), est à réécrire dans la PR MCP.
5. **Journal de l'ère sans session** (R5) : **décidé : lire `declaredClient` depuis `RequestMeta` dans la
   session**, et accepter le nouveau sens de `durationMs` (traitement du message, dans les deux ères), écrit
   dans `mcp-journaux.md`.
6. **Store injoignable** (R7) : **décidé : suivre le SDK, 503 au `GET` aussi** (le client réessaie au lieu
   de se réinitialiser).
7. **Session trop grosse** (R3) : **décidé : suivre le SDK** (l'`initialize` refusé rend un identifiant qui
   n'existe pas), avec un test qui prouve que cet identifiant n'est pas réutilisable.
8. **Verrou de session** du bundle 0.15 : **pas dans cette montée** ; issue de suivi #762.
9. **Les 6 `config.unknown_key`** : **laissés visibles** (choix actuel, faux positif documenté dans
   `backend-outillage.md`).
10. **1.0.0-BETA1** : **pas de 1.0 dans cette montée** ; la montée vers la 1.0 stable est suivie par l'issue
    #763 (elle casse `FakeAgent`, `FakeStreamingAgent` et le typage `Execution` de l'assistant).
11. **Dependabot** : **décidé : ignorer toute montée** de `symfony/ai-*`, `symfony/mcp-bundle` et `mcp/*`
    (`.github/dependabot.yml`) — montée délibérée lue dans les changelogs amont, et une 0.x qui casse compterait
    en « minor » pour le groupe `backend-minor-patch` ; contrepartie : plus aucune PR automatique pour eux,
    suivi à la main. À réévaluer à la montée Symfony 8.2 (issue #764).

---

## Sources

1. Packagist, métadonnées de `symfony/mcp-bundle`, `mcp/sdk`, `symfony/ai-bundle`, `symfony/ai-agent`,
   `symfony/ai-platform`, `symfony/ai-anthropic-platform`, `symfony/ai-scaleway-platform`,
   `symfony/ai-generic-platform`, `symfony/ai-open-responses-platform` —
   `https://repo.packagist.org/p2/<paquet>.json` (lu le 2026-11-06).
2. Monorepo `symfony/ai`, `https://github.com/symfony/ai`, tags `v0.13.0`, `v0.14.1`, `v0.15.0`, `v1.0.0-BETA1` :
   `UPGRADE.md`, `src/{platform,agent,ai-bundle,mcp-bundle}/CHANGELOG.md`, et les diffs cités par chemin.
3. `modelcontextprotocol/php-sdk`, `https://github.com/modelcontextprotocol/php-sdk`, tags `v0.8.1`, `v0.9.0` et
   `main` au 2026-11-06 : `CHANGELOG.md` (section 0.9.0) et les diffs cités par chemin.
