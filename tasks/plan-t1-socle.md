# Spec 0007 — Tâche 1 (#640) : socle, `/api/mcp` répond et `get_about` rend sa route — plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** un client MCP se branche sur `https://<site>/api/mcp`, le handshake aboutit en POST, le GET
ne répond pas 405, et `get_about` rend exactement ce que sert `GET /api/about/{locale}`.

**Architecture :** `symfony/mcp-bundle` déclare un serveur `portfolio` sur `/api/mcp` ; un firewall
dédié sans authentificateur l'isole du cookie `BEARER` ; les sessions vivent dans un pool DBAL
(ADR 0005). Un nouveau contexte `Mcp` porte un lecteur de payload unique qui appelle le provider
d'une opération API Platform et normalise avec le contexte de cette même opération ; les outils
passent par lui.

**Tech Stack :** PHP 8.5, Symfony 8.1, API Platform 4.3, `symfony/mcp-bundle` 0.14.1, `mcp/sdk`
0.8.1, `nyholm/psr7`, PHPUnit.

**Spec :** `.claude/specs/0007-mcp-server.md` (phase 1) · ADR 0006 · issue #640.

## Global Constraints

- `symfony/mcp-bundle` **`0.14.1`** et `mcp/sdk` **`0.8.1`**, versions exactes, sans `^` (ADR 0006 D7 ;
  le bundle 0.14.1 exige `mcp/sdk ^0.8.1`, la 0.9 est exclue de fait).
- Aucune lecture de `_meta.oauth`, aucun middleware d'autorisation du SDK (ADR 0006 D4).
- Rien de ce que le serveur publie ne nomme l'auteur : on écrit « the site's author » (D8).
- Règles du dépôt : `declare(strict_types=1)`, `use` triés (`make back-style-fix`), aucune exception
  SPL nue (une classe nommée par échec), aucune classe anonyme, regex terminées par `\z`.
- Commandes dans le conteneur : `make back-test`, `make back-quality` ; PHPStan/Rector en `-u dev`.
- Jamais lire `.env.local`.

## Review Focus

1. **Un champ `null` de la route** (`AboutCardResource::$iconKey`) : la route l'omet (`skip_null_values`
   d'API Platform) ; l'outil doit l'omettre aussi → test d'égalité avec une carte sans icône (tâche 3).
2. **Une langue sans contenu** (aucun `AboutSettings` en `en`) : la route répond 404 ; l'outil doit
   rendre une erreur d'outil explicite, pas une erreur interne -32603 → test (tâche 3).
3. **Une requête portant le cookie `BEARER` d'un compte de confiance** : ni refusée par la garde CSRF,
   ni authentifiée → test (tâche 1).
4. **Un en-tête `Host` ou `Origin` étranger** (rebond DNS) → 403 du SDK → test (tâche 1).
5. **Un GET sans session** (client qui ouvre le flux avant `initialize`) : pas de 405, pas de travail
   coûteux → test (tâche 2).

---

### Task 1 : le serveur répond au handshake, isolé du cookie et de la garde CSRF

**Files :**
- Modify : `backend/composer.json`, `backend/composer.lock`, `backend/config/bundles.php`, `backend/symfony.lock`
- Create : `backend/config/packages/mcp.yaml`, `backend/config/routes/mcp.yaml`
- Modify : `backend/config/packages/cache.yaml` (pool), `backend/config/packages/security.yaml` (firewall),
  `backend/config/services.yaml` (cache PSR-16 des sessions)
- Modify : `backend/src/Security/Authentication/Infrastructure/Http/CsrfCookieRequestSubscriber.php` (`EXCLUDED_PATHS`)
- Modify : `backend/tests/Security/ApiRouteExposureTest.php` (`PUBLIC_PATHS`), `backend/tests/Security/AccessControlAnchoringTest.php` (cas du firewall `mcp`)
- Create : `backend/tests/Mcp/Support/SpeaksMcp.php` (trait), `backend/tests/Mcp/Presentation/McpEndpointTest.php`

**Interfaces :**
- Produces : serveur `portfolio`, route `_mcp_endpoint_portfolio` sur `/api/mcp`, service contrôleur
  `mcp.server.portfolio.controller` (public, méthode `handle`) ; pool `cache.mcp_sessions` ; service
  `app.mcp.session_cache` (`Symfony\Component\Cache\Psr16Cache` sur ce pool) ; firewall `mcp`.
- Produces (tests) : trait `SpeaksMcp` — `initializeMcpSession(KernelBrowser $client): string` (rend le
  `Mcp-Session-Id`, envoie aussi `notifications/initialized`), `mcpCall(KernelBrowser $client, string $sessionId, string $method, array $params = []): array`
  (rend la réponse JSON-RPC décodée), en-têtes `Accept: application/json, text/event-stream`,
  `Content-Type: application/json`, `MCP-Protocol-Version: 2025-06-18`.

- [ ] **Step 1 : installer les dépendances.** Dans le conteneur : `composer require symfony/mcp-bundle:0.14.1 mcp/sdk:0.8.1 nyholm/psr7:^1.8`.
  `nyholm/psr7` fournit l'implémentation PSR-17 que `Http\Discovery\Psr17Factory` du bundle cherche ;
  ajouter `"php-http/discovery": false` à `config.allow-plugins` (aucune installation implicite).
  Vérifier `composer.json` : les deux premiers sans `^`. Si une recette Flex crée des fichiers, les
  remplacer par ceux des étapes suivantes.

- [ ] **Step 2 : écrire le test qui échoue** — `McpEndpointTest` :
  - `testInitializeAnswersWithTheServerIdentity` : `initialize` → 200, `result.serverInfo.name` =
    `cp-ghostotof`, `result.instructions` non vide, en-tête `Mcp-Session-Id` présent.
  - `testToolsListAnswersAfterTheHandshake` : `tools/list` → `result.tools` est un tableau.
  - `testAForeignHostIsRefused` : `initialize` avec `HTTP_HOST` = `evil.example` → 403.
  - `testAForeignOriginIsRefused` : `HTTP_ORIGIN` = `https://evil.example` → 403.
  - `testATrustedBearerCookieIsNeitherRefusedByTheCsrfGuardNorRead` : cookies `BEARER` (JWT valide d'un
    compte `ROLE_TRUSTED`, comme `ApiRouteExposureTest` en fabrique) et `XSRF-TOKEN`, **sans**
    `X-XSRF-TOKEN` → `initialize` 200 ; et `self::getContainer()->get('security.token_storage')->getToken()` est `null` après la requête.
  - `testPromptsAndResourcesListsAnswer` : `prompts/list` et `resources/list` → 200 et une réponse
    JSON-RPC (un `result`, ou l'erreur `-32601` si le SDK n'annonce pas la capacité faute d'élément : le
    noter dans la règle de la tâche 4).
  - `testSessionsLiveInTheDatabase` : le service `cache.mcp_sessions` est un `DoctrineDbalAdapter` (deux
    réplicas, disque en lecture seule : ADR 0005), et `mcp.server.portfolio.session.store` un
    `Mcp\Server\Session\Psr16SessionStore`.
  - `testTheMcpPackagesArePinnedExactly` : `composer.json` (lu par `RepositoryFiles::read()`) requiert
    `symfony/mcp-bundle` et `mcp/sdk` sans aucun opérateur (`^`, `~`, `*`, `>`, `|`).
  - `/api/mcp` dans `ApiRouteExposureTest::PUBLIC_PATHS` : la suite de sécurité reste verte.

- [ ] **Step 3 : lancer, constater l'échec** — `make back-test ARGS="--filter McpEndpointTest"` (ou
  `php bin/phpunit tests/Mcp` dans `make sh`) → 404 sur `/api/mcp`.

- [ ] **Step 4 : configurer.**
  - `mcp.yaml` : `mcp.servers.portfolio` — `name: cp-ghostotof`, `version: 0.1.0`,
    `description` et `instructions` en anglais, sans nom (« The portfolio of the site's author … call the
    tools rather than guess; each tool returns exactly what the matching page of the site serves »),
    `transports: { http: true, stdio: false }`, `http.path: /api/mcp`,
    `http.allowed_hosts: ['%env(key:host:url:APP_FRONTEND_BASE_URL)%']` (l'hôte du site, déjà posé par
    overlay ; `localhost` en dev et en test), `session: { store: cache, cache_pool: app.mcp.session_cache, ttl: 3600 }`,
    `registry: { tools: ['App\Mcp\'], prompts: ['App\Mcp\'] }`.
  - `routes/mcp.yaml` : `mcp: { resource: ., type: mcp }`.
  - `cache.yaml` : pool `cache.mcp_sessions`, `adapter: cache.adapter.doctrine_dbal`,
    `default_lifetime: 3600` (même table `cache_items`, purgée par `cache:pool:prune`, MAC par
    `AuthenticatedCacheMarshaller` comme tout pool DBAL).
  - `services.yaml` : `app.mcp.session_cache: { class: Symfony\Component\Cache\Psr16Cache, arguments: ['@cache.mcp_sessions'] }`.
  - `security.yaml` : firewall `mcp`, **avant** `api`, `pattern: ^/api/mcp$`, `stateless: true`, aucun
    authentificateur, aucun `provider` (si le conteneur en exige un : `provider: app_users`, et le dire
    dans le commit) ; commentaire : « jamais de cookie ici, ADR 0006 D4 ».
  - `CsrfCookieRequestSubscriber::EXCLUDED_PATHS` : `'/api/mcp'`, avec la raison en commentaire (le
    firewall n'accepte aucun cookie, donc une requête intersite n'emprunte aucune identité).
  - `ApiRouteExposureTest::PUBLIC_PATHS` : `'/api/mcp' => 'Serveur MCP (ADR 0006) : canal de lecture qui ne sert rien qu\'une route du même palier ne sert déjà ; le palier de confiance y exigera une connexion MCP (phase 2), jamais un cookie.'`
  - `AccessControlAnchoringTest` : le firewall `mcp` passe ses contrôles (fin `$`) ; ajouter les cas
    `'/api/mcp → mcp'` et `'/api/mcpx → api'` à l'appartenance de firewall.

- [ ] **Step 5 : relancer, constater le succès** ; puis toute la suite `tests/Security` et
  `tests/Shared` (gardes d'imports, de pools authentifiés `CacheItemsAreAuthenticatedTest`, de
  sessions `StatelessSessionTest`).

- [ ] **Step 6 : commit** — `feat(mcp): serveur MCP sur /api/mcp, isolé du cookie et de la garde CSRF (#640)`.

### Task 2 : le GET ne répond pas 405

Le transport du SDK 0.8.1 répond `405` au GET (il n'ouvre pas de flux SSE) ; c'est conforme à la
spécification MCP, mais l'AI Assistant de PhpStorm échoue sur un 405 (ADR 0006, conséquences).
Une **enveloppe** du contrôleur du bundle répond au GET par un flux SSE vide qui fixe un long délai de
reconnexion, et délègue tout le reste.

**Files :**
- Create : `backend/src/Mcp/Presentation/Controller/McpEndpointController.php`
- Test : `backend/tests/Mcp/Presentation/McpEndpointTest.php`

**Interfaces :**
- Produces : `final readonly class McpEndpointController`, `#[AsDecorator('mcp.server.portfolio.controller')]`,
  constructeur `(#[AutowireDecorated] McpController $inner)`, `public function handle(Request $request): Response`.
  Constante `RECONNECT_DELAY_MS = 300000`.

- [ ] **Step 1 : test qui échoue** — `testGetOpensAnEmptyEventStreamInsteadOf405` : `GET /api/mcp`
  (sans session) → 200, `Content-Type` commence par `text/event-stream`, corps exactement
  `"retry: 300000\n\n"`, en-tête `Cache-Control: no-store`. Et `testPostStillReachesTheServer` (un
  `initialize` après décoration répond toujours 200).
- [ ] **Step 2 : constater l'échec** (405).
- [ ] **Step 3 : implémenter** `handle()` : GET → `Response` (pas `StreamedResponse` : un FPM ne doit
  pas garder de flux ouvert) au corps ci-dessus ; sinon `$this->inner->handle($request)`.
- [ ] **Step 4 : constater le succès.**
- [ ] **Step 5 : commit** — `feat(mcp): le GET de /api/mcp ouvre un flux vide au lieu d'un 405 (#640)`.

### Task 3 : `get_about` rend exactement sa route

**Files :**
- Create : `backend/src/Mcp/Application/RoutePayloadReaderInterface.php`
- Create : `backend/src/Mcp/Infrastructure/ApiPlatform/ApiPlatformRoutePayloadReader.php`
- Create : `backend/src/Mcp/Infrastructure/ApiPlatform/RouteOperationNotFoundException.php`
- Create : `backend/src/Mcp/Presentation/Tool/RouteBackedToolResult.php`
- Create : `backend/src/Mcp/Presentation/Tool/GetAboutTool.php`
- Test : `backend/tests/Mcp/Presentation/Tool/GetAboutToolTest.php`

**Interfaces :**
- Produces : `interface RoutePayloadReaderInterface { /** @param class-string $resourceClass @param array<string, string> $uriVariables @return array<mixed> */ public function read(string $resourceClass, array $uriVariables = []): array; }`
- Produces : `ApiPlatformRoutePayloadReader` — trouve l'**unique** opération `HttpOperation` GET hors
  `/backoffice` de la ressource (`ResourceMetadataCollectionFactoryInterface`), sinon
  `RouteOperationNotFoundException::forResource(string $resourceClass)` (étend `LogicException`, défaut
  de câblage, 500) ; appelle `api_platform.state_provider.locator` (`#[Autowire(service: …)] ProviderInterface`)
  avec cette opération ; normalise par `serializer` au format `json` avec le contexte que
  `SerializerContextBuilder` construit pour une requête : `$operation->getNormalizationContext() ?? []`,
  puis `operation_name`, `operation`, `resource_class`, `skip_null_values` (true),
  `AbstractItemNormalizer::SKIP_NULL_TO_ONE_RELATIONS` (true), `iri_only` (false), `input`, `output`,
  `uri_variables`.
- Produces : `final class RouteBackedToolResult { public static function forObject(array $payload): CallToolResult; }`
  — `structuredContent` = `$payload`, un seul `TextContent` = `json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`.
  (La tâche 2 de la spec ajoutera `forCollection(array $items)`.)
- Produces : `GetAboutTool` — `#[McpTool(name: 'get_about', title: 'About', description: …, annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false), outputSchema: …)]`
  sur `__invoke(Locale $locale = Locale::FR): CallToolResult` (`Locale` = `App\Portfolio\Shared\Domain\ValueObject\Locale` ;
  le SDK en déduit l'énumération `fr`|`en` du schéma d'entrée). `AboutSettingsNotFoundException` →
  `Mcp\Exception\ToolCallException` au message littéral « No About content exists in this language. ».
  `outputSchema` écrit à la main : objet `{site: {eyebrow, cards[]}, me: {eyebrow, technicalSubtitle, technicalCards[], personalSubtitle, personalCards[], hobbiesSubtitle, hobbiesCards[]}}`,
  carte `{title, description, iconKey?}`, `required` sur les champs non nullables.

- [ ] **Step 1 : tests qui échouent** — `GetAboutToolTest` (fonctionnel, `SpeaksMcp`, fixtures comme
  `AboutContentResourceTest`, nettoyage des trois tables en `tearDown`) :
  - `testToolsListAnnouncesGetAboutAsReadOnlyWithALocaleEnumAndAnOutputSchema` : `inputSchema.properties.locale.enum` = `['fr', 'en']`, `annotations.readOnlyHint` = true, `outputSchema.type` = `object`.
  - `testGetAboutReturnsExactlyWhatTheRouteServes` (data provider `fr`, `en`) : contenu dans la langue,
    dont **une carte sans `iconKey`** ; `GET /api/about/{locale}` puis `tools/call get_about {locale}` ;
    `assertSame($routeBody, $result['structuredContent'])` et
    `assertSame($routeBody, json_decode($result['content'][0]['text'], true))` ; `isError` absent ou false.
  - `testTheStructuredContentConformsToTheDeclaredOutputSchema` : `(new SchemaValidator())->validateAgainstJsonSchema($structuredContent, $outputSchema)` vide.
  - `testWithoutLocaleTheFrenchVersionIsReturned` : sans argument → égal à `GET /api/about/fr`.
  - `testAnUnknownLocaleIsRefusedByTheInputSchema` : `{locale: 'de'}` → erreur JSON-RPC `-32602`.
  - `testALanguageWithoutContentIsAToolError` : aucun contenu `en` → `isError` true, texte = le message
    littéral, et aucune erreur `-32603`.
- [ ] **Step 2 : constater l'échec** (outil inconnu).
- [ ] **Step 3 : implémenter** l'interface, le lecteur, l'exception, `RouteBackedToolResult`, l'outil
  (alias `RoutePayloadReaderInterface` → `ApiPlatformRoutePayloadReader` si l'autowiring ne le fait pas).
- [ ] **Step 4 : constater le succès**, puis `make back-quality`.
- [ ] **Step 5 : commit** — `feat(mcp): get_about rend exactement GET /api/about/{locale} (#640)`.

### Task 4 : écrire ce qui a été décidé

**Files :**
- Create : `.claude/rules/mcp.md` (`paths:` `backend/src/Mcp/**`, `backend/tests/Mcp/**`, `backend/config/packages/mcp.yaml`)
- Modify : `.claude/CLAUDE.md` (ligne du tableau des règles), `.claude/specs/0007-mcp-server.md`

- [ ] **Step 1 : la règle** — l'invariant D1 et le lecteur unique (pourquoi le contexte de
  normalisation complet, `skip_null_values`), le firewall sans cookie et son exclusion CSRF, le GET en
  flux vide (et pourquoi pas un `StreamedResponse`), `allowed_hosts` dérivé d'`APP_FRONTEND_BASE_URL`,
  le pin exact, les sessions DBAL. Court : ce qu'un agent doit savoir avant de toucher `Mcp/`.
- [ ] **Step 2 : la spec** — trois corrections au journal daté : la langue invalide est un `-32602` du
  SDK (le schéma d'entrée énumère les langues, le SDK valide avant l'outil ; une erreur d'outil
  exigerait de retirer l'énumération que le modèle lit) ; `allowed_hosts` réutilise
  `APP_FRONTEND_BASE_URL` (pas de nouvelle variable ni de ConfigMap) ; le GET répond par un flux SSE
  vide (`retry: 300000`).
- [ ] **Step 3 : vérifier** — `tools/check-claude-rules.sh` vert.
- [ ] **Step 4 : commit** — `docs(rules): règle mcp et précisions de la spec 0007 (#640)`.

## Vérification finale

- [ ] `make back-test` et `make back-quality` verts.
- [ ] Manuel (dev) : `claude mcp add --transport http cpg http://localhost:8080/api/mcp`, puis une question
  qui fait appeler `get_about`.
