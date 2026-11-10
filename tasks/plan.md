# #731, PR 2 — Montée MCP : `symfony/mcp-bundle` 0.15.0 et `mcp/sdk` 0.9.0 — plan d'implémentation

> **Pour les agents d'exécution :** SOUS-SKILL REQUISE : `superpowers:subagent-driven-development` (recommandée)
> ou `superpowers:executing-plans`, tâche par tâche. Les étapes sont des cases à cocher (`- [ ]`).

**Objectif :** monter ensemble `symfony/mcp-bundle` 0.14.1 → 0.15.0 et `mcp/sdk` 0.8.1 → 0.9.0, en versions
exactes (ADR 0006 D7), ce qui remet au vert `composer audit` de la branche mère (avis PKSA-s52d-n7sx-trsg /
GHSA-f4rf-6ghj-mhr6, `mcp/sdk` >=0.8.0,<0.9.0), et traduire les décisions de l'auteur du 2026-11-06 (note §7) en code,
tests et documentation.

**Architecture :** une première tâche monte les deux paquets et rend le noyau démarrable (R1, R6, R10), avec un test
de caractérisation de la nouvelle prémisse du SDK (la réponse ne transite plus par la session) vu rouge sur 0.8.1 avant
la montée. Chaque rupture suivante a sa tâche, son test rouge et sa documentation (R4 `Origin`, R5 journal, R7 503 au
GET, R3 session trop grosse, R2 retrait de la borne en file), puis une tâche de vérifications (R8, R9, R11, coût de la
session), une tâche de versions dans la documentation, et une tâche finale (exposition à l'avis, notes de release
client pour le corps de PR, vérification complète).

**Pile :** PHP 8.5, Symfony 8.1, `symfony/mcp-bundle` 0.15.0, `mcp/sdk` 0.9.0, PHPUnit, PHPStan max, Rector, Psalm,
Symfony Language Tools, Docker Compose (tout se lance dans le conteneur `backend`, utilisateur `dev`).

**Spec :** `docs/research/2026-11-06-montees-epinglees-731.md` (sections 3, 4, 5 et **7, décisions non négociables**) et
l'issue #731 (`gh api repos/ghostotof/cp-ghostotof/issues/731 --jq .body` : `gh issue view` échoue sur les Projects
classic). PR 1 (famille Symfony AI) déjà fusionnée dans la mère (#766). La PR 2 vise la branche mère
`feature/379-mcp-paliers`.

## Contraintes globales

Chaque tâche les inclut implicitement.

- Versions exactes, dans `require` : `"mcp/sdk": "0.9.0"`, `"symfony/mcp-bundle": "0.15.0"` ; aucune autre
  contrainte de `composer.json` ne change, hors ce que Composer impose par `--with-dependencies`.
- **Hors périmètre, décidé** : pas de `session.lock` (#762), pas de 1.0 (#763), les 6 `config.unknown_key` de
  `lsp:check` sur `ai.yaml` restent visibles (ni plus ni moins de 6 avertissements à la fin).
- **Ne jamais lire `.env.local`, `.env.test.local` ni aucun fichier de secrets** (fuite d'une clé par un `grep -r` le
  2026-10-12). Jamais de `grep -r` sur `backend/` sans `--exclude='.env*.local'` ; préférer `git grep`, qui ne voit que
  les fichiers suivis.
- **Les règles `.claude/rules/*.md` se chargent par l'outil `Read` sur un fichier de leur zone, jamais par
  `cat`/`grep`.** Chaque tâche dit quelles règles lire avant d'écrire.
- Tout se lance dans le conteneur, en `-u dev` (sinon le cache PHPStan casse) :
  - ciblé : `docker compose exec -T -u dev backend php bin/phpunit <chemin du test> --filter '<nom du test>'` ;
  - suite : `make back-test` ; qualité : `make back-quality` (PHPStan, Rector, Psalm, `lsp:check`) ;
  - Composer : `docker compose exec -T -u dev backend composer …`.
- **Bind-mount désynchronisé** : après une écriture sur l'hôte, si un test contredit le source, comparer
  `sha1sum backend/<fichier>` sur l'hôte et `docker compose exec -T backend sha1sum /var/www/backend/<fichier>` ;
  différents → `docker compose restart backend`, puis recomparer avant de croire un test. Le `vendor/` du conteneur
  n'est pas celui de l'hôte : après la montée, tout passe par Composer **dans** le conteneur. Après un changement de
  `Kernel.php` ou d'un fichier de `config/` que le cache ne voit pas : `docker compose exec -T -u dev backend rm -rf
  var/cache/test`. Base `_test` en retard : `docker compose exec -T -u dev backend php bin/console
  doctrine:migrations:migrate -n --env=test`.
- Secrets de test : jamais de constante littérale (`TestKeys::material()`, `TestCredentials`) — GitGuardian scanne les
  commits.
- Un commit par tâche, **fichiers nommés** (`git add <chemins>`, jamais `-A` ni `.`), message en français au format
  conventionnel du dépôt, suffixé ` (#731)`, terminé par :
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt
  ```
  Jamais `[skip ci]`, `[ci skip]`, `[no ci]`, `[skip actions]`, `[actions skip]`. Ne rien pousser.
- Régression : chaque test rouge est vu rouge **pour la raison annoncée** avant la correction.
- Conventions backend (`.claude/rules/backend-conventions.md`) : `use` triés (`make back-style-fix` après un
  `rector:fix`), noms courts importés, natives optimisées qualifiées (`\count`, `\sprintf`…), une classe cité en
  commentaire par `{@see \Nom\Complet}` sans `use` (`CommentedClassNamesExistTest` : une classe supprimée ne doit plus
  être citée nulle part), aucune exception générique nue dans `src/`.
- Dans le code (docblocks, commentaires), une affirmation **au présent** sur le SDK ou le bundle ne cite plus de numéro
  de version : elle renvoie à « la version épinglée (composer.json) », comme la PR 1 l'a fait pour Symfony AI ; une
  affirmation **historique** (« en 0.8.1, le SDK … ; depuis la 0.9 … ») garde ses versions. Les fixtures de version
  des tests de pins (`SymfonyAiAndMcpPinsMatchTheLockTest`, `ComposerFilesTest`) ne bougent pas : ce sont des données.
- Les quatre relectures par série (/code-review, audit sécu, Standards, Spec) sont l'affaire du fil principal, pas
  des tâches.

## Points d'attention de la relecture

Cinq conditions que la note implique sans qu'un test existant les exerce ; chacune a son test dans la tâche indiquée.

1. **`MCP_ALLOWED_ORIGIN` vide ou mal formée** (`''`, barre finale, chemin, `*`) : `allowed_origins: ['']` admettrait un
   en-tête `Origin:` vide, une barre finale refuserait le site. Attendu : chaque environnement porte exactement
   l'origine de son `APP_FRONTEND_BASE_URL`, bien formée (tâche 2, `McpAllowedOriginPerEnvironmentTest`).
2. **`Origin: null`** (iframe isolée, `file://`) et un `Origin` du même hôte mais d'un autre schéma ou port : 403
   « Forbidden: Invalid Origin header. », au POST comme au GET (tâche 2).
3. **Store injoignable sur un GET sans session** (en-tête absent, vide ou `0`) : servi 200 sans toucher au store — le
   503 ne vaut que pour un identifiant à contrôler (tâche 4).
4. **Ère sans session sans `clientInfo` déclaré** : `declaredClient` vaut `null`, pas un tableau de `null` ni `'dev'`
   (tâche 3).
5. **Identifiant d'une session trop grosse rejoué au GET** : 404 comme au POST, jamais un flux servi (tâche 5).

---

## Carte des fichiers

| Fichier | Tâche(s) | Responsabilité après la PR |
|---|---|---|
| `backend/composer.json`, `composer.lock`, `symfony.lock`, `config/reference.php` | 1 | pins 0.15.0 / 0.9.0, référence régénérée |
| `backend/src/Mcp/Infrastructure/Registry/SecuritySchemesRegistry.php` | 1 | délègue `publishesListChanges()`, `Page<Tool>` |
| `backend/src/Mcp/Infrastructure/Registry/ToolWithSecuritySchemes.php` | 1 | recopie `execution` |
| `backend/tests/Mcp/Infrastructure/Registry/SecuritySchemesRegistryTest.php`, `tests/Mcp/Support/ReadsPortfolioRegistry.php` | 1 | R10, R6, R1 |
| `backend/tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php` | 1, 6, 7 | prémisse « pas par la session », retrait de la borne, R8 |
| `backend/config/packages/mcp.yaml`, `backend/.env`, `backend/phpunit.dist.xml`, `k8s/overlays/{preprod,prod}/kustomization.yaml` | 2 | `allowed_origins` = `MCP_ALLOWED_ORIGIN` |
| `backend/tests/Mcp/Infrastructure/Kubernetes/McpAllowedOriginPerEnvironmentTest.php` (nouveau) | 2 | une origine juste par environnement |
| `backend/tests/Mcp/Presentation/McpEndpointGetTest.php`, `McpEndpointTest.php` | 2, 4, 5, 7 | `Origin`, 503, session trop grosse, coût |
| `backend/src/Mcp/Infrastructure/Log/McpCallLog.php`, `ModernExchange.php` | 3 | `declaredClient` depuis `RequestMeta` |
| `backend/tests/Mcp/Infrastructure/Log/McpCallLogTest.php` | 3 | R5 |
| `backend/src/Mcp/Presentation/Controller/McpEndpointController.php` | 4 | 503 au GET |
| `backend/tests/Mcp/Fixtures/UnreachableSimpleCache.php`, `UnreachableCacheException.php` (nouveaux) | 4 | un cache PSR-16 injoignable |
| `backend/src/Mcp/Infrastructure/Session/BoundedSessionStore.php`, `tests/Mcp/Infrastructure/Session/BoundedSessionStoreTest.php` | 4, 5, 6 | justification réécrite, séquence 0.9 |
| `backend/src/Mcp/Presentation/Tool/GetNominativeCorpusTool.php`, `QueuedAnswer.php` (supprimé), `src/Mcp/Application/NominativeCorpusReaderInterface.php`, `tests/Mcp/Presentation/Tool/RouteBackedToolsTest.php`, `tests/Mcp/McpExposureSymmetryTest.php` | 6 | R2 |
| `.claude/rules/mcp.md`, `mcp-journaux.md`, `mcp-prompt-d8.md` | 1–8 | vérité de la 0.9 |
| `docs/adr/0006-serveur-mcp.md`, `docs/adr/0004-assistance-ia.md` | 8 | D7 amendée, D4 confirmée |
| `tasks/pr-731-mcp-0-15.md` (nouveau) | 9 | brouillon du corps de PR : exposition, notes client |

---

### Tâche 1 : la montée, et ce qui rend le noyau démarrable (R1, R6, R10, prémisse R2)

**Modèle conseillé :** opus — **sensible** (session, réponse nominative).

**Règles à lire avant d'écrire (outil `Read`) :** `.claude/rules/mcp.md`, `.claude/rules/backend-outillage.md`,
`.claude/rules/backend-conventions.md`.

**Fichiers :**
- Modifier : `backend/composer.json`, `backend/composer.lock`, `backend/symfony.lock`, `backend/config/reference.php`
- Modifier : `backend/src/Mcp/Infrastructure/Registry/SecuritySchemesRegistry.php`
- Modifier : `backend/src/Mcp/Infrastructure/Registry/ToolWithSecuritySchemes.php`
- Modifier : `backend/tests/Mcp/Infrastructure/Registry/SecuritySchemesRegistryTest.php`
- Modifier : `backend/tests/Mcp/Support/ReadsPortfolioRegistry.php`
- Modifier : `backend/tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php`
- Modifier : `.claude/rules/mcp.md` (puce « What it is » : les pins)

**Interfaces :**
- Produit : `SecuritySchemesRegistry::publishesListChanges(): bool` (délègue à `$this->inner`) ;
  `SecuritySchemesRegistry::getTools(?int $limit = null, ?string $cursor = null): Page` typé `@return Page<Tool>` ;
  `ToolWithSecuritySchemes` recopie `execution: $tool->execution`.
- Produit : `GetNominativeCorpusToolTest::testTheAnswerNeverTransitsThroughTheSession()` (lu par la tâche 6).

- [ ] **Étape 1 : écrire le test de caractérisation de la prémisse 0.9, avant la montée**

Dans `GetNominativeCorpusToolTest`, `testTheAnswerNeverTransitsThroughTheSession()` :
`$held = $this->holdMcpConnection()`, `$this->useRenderer(new StubCorpusRenderer(self::CORPUS_SENTINEL))`,
`$sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken))`, puis relever
`$offset = \count(self::allChannelsLogRecordsOf('doctrine'))`, appeler l'outil
(`$this->toolResult($sessionId, ['locale' => 'fr'], bearer: self::bearer($held->accessToken))`), et ne lire que les
enregistrements `doctrine` d'après `$offset` (le conteneur n'est pas reconstruit : `useRenderer()` désactive le
reboot). Parmi eux, les écritures de session : message commençant par `Executing `, SQL contenant `cache_items`, et
paramètres (`print_r($record->context['params'] ?? null, true)`) contenant `'mcp-portfolio-'.$sessionId` **ou** la
liaison `'mcp-connection:'.$held->connectionId."\n"`. Assertions :

```php
self::assertNotSame([], $sessionWrites, 'Aucune écriture de session relevée : le test ne verrait rien.');
foreach ($sessionWrites as $params) {
    // Témoin positif : les paramètres journalisés portent bien la valeur écrite.
    self::assertStringContainsString('mcp-connection:'.$held->connectionId, $params);
    self::assertStringNotContainsString(self::CORPUS_SENTINEL, $params, 'La réponse a transité par la session.');
}
self::assertStringContainsString(self::CORPUS_SENTINEL, $this->rawMcpAnswer($this->client));
```

Docblock : la prémisse que la 0.9 pose (la réponse d'un `POST` reste au transport, la file de la session ne porte que
les messages initiés par le serveur, qu'il n'émet pas) et pourquoi elle compte (la borne de la réponse en file, retirée
par la tâche 6, reposait sur l'inverse).

- [ ] **Étape 2 : le voir rouge sur 0.8.1**

Run : `docker compose exec -T -u dev backend php bin/phpunit tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php --filter testTheAnswerNeverTransitsThroughTheSession`
Attendu : FAIL sur « La réponse a transité par la session. » (le SDK 0.8.1 met la réponse en file dans la session au
`save()`). Un échec sur le témoin positif ou sur « Aucune écriture » dit que la lecture des paramètres est fausse :
la corriger avant d'aller plus loin.

- [ ] **Étape 3 : monter les deux paquets**

Run : `docker compose exec -T -u dev backend composer require 'mcp/sdk:0.9.0' 'symfony/mcp-bundle:0.15.0' --with-dependencies`
Si Composer refuse la résolution, relancer avec `--with-all-dependencies` et noter dans le message de commit chaque
paquet monté en plus (la note §1 en attend jusqu'à quatre : `opis/json-schema`, `phpdocumentor/reflection-common`,
`phpdocumentor/type-resolver`, `webmozart/assert`). Puis `git status --short` : seuls `backend/composer.json`,
`backend/composer.lock` et `backend/symfony.lock` changent ; tout autre fichier touché par une recette Flex
(`phpunit.dist.xml` surtout, piège de `symfony/phpunit-bridge`, `backend-outillage.md`) est restauré par
`git checkout -- <fichier>`.

- [ ] **Étape 4 : constater que le noyau ne démarre plus**

Run : `docker compose exec -T -u dev backend php bin/console lint:container`
Attendu : erreur fatale, `SecuritySchemesRegistry` ne met pas en œuvre `RegistryInterface::publishesListChanges()`.

- [ ] **Étape 5 : écrire le test de délégation (R1), puis le corriger**

Dans `SecuritySchemesRegistryTest`, `testTheListChangesAnswerIsTheInnerRegistrys()` : un `RegistryInterface` bouchon
(`$this->createStub(RegistryInterface::class)`, `publishesListChanges` → `true`) décoré par `new
SecuritySchemesRegistry($stub)` ; `self::assertTrue($decorated->publishesListChanges())`. Puis ajouter
`publishesListChanges(): bool { return $this->inner->publishesListChanges(); }` au décorateur, et compléter son
docblock : la méthode déléguée (« A decorator forwards the answer of the one it wraps », contrat du SDK) ; l'écart
accepté des `*ListChangedEvent` au chargement, **relu dans `vendor/mcp/sdk/src/Server/Builder.php` et
`Capability/Registry.php`** de la 0.9 : gardé tel quel s'il tient, réécrit selon ce que fait la 0.9 sinon ; et
`AnnouncedCapabilitiesTest` reste le garde de l'annonce (aucun `listChanged`).

- [ ] **Étape 6 : R6, la recopie d'`execution`**

Run : `docker compose exec -T -u dev backend php bin/phpunit tests/Mcp/Infrastructure/Registry/SecuritySchemesRegistryTest.php`
Attendu : FAIL de `testTheCopyKnowsEveryParameterOfTheSdkTool` (le constructeur de `Tool` a gagné `execution`).
Corriger la liste attendue en `['name', 'title', 'inputSchema', 'description', 'annotations', 'icons', 'meta',
'outputSchema', 'execution']`, passer `execution: new ToolExecution(TaskSupport::Optional)` à l'outil de
`testEveryFieldOfTheToolSurvivesTheCopy()` (le relancer : rouge, le champ n'est pas recopié), puis recopier
`execution: $tool->execution` dans le constructeur privé de `ToolWithSecuritySchemes` et ajouter
`execution?: ToolExecution` à la forme de retour de son `jsonSerialize()`. Les deux tests verts.

- [ ] **Étape 7 : R10, les cinq erreurs PHPStan `Page<TItem>`**

Run : `docker compose exec -T -u dev backend composer phpstan`
Attendu : 5 erreurs — `SecuritySchemesRegistry::getTools()` (un `instanceof Tool` toujours vrai, puis
`Page<ToolWithSecuritySchemes>` rendu où `Page<Tool>` est attendu, `Page` étant invariant),
`SecuritySchemesRegistryTest::published()` et `::toolNames()` (type générique manquant de `Page`),
`ReadsPortfolioRegistry::registeredPrompts()` (`assertInstanceOf(Prompt::class)` toujours vrai). Corriger sans
`@phpstan-ignore` ni baseline : `getTools()` recopie chaque outil sans test de type et rend une `Page<Tool>` (docblock
`@return Page<Tool>`, tableau intermédiaire annoté `array<int|string, Tool>`) ; les deux aides du test prennent
`Page<Tool>` ; l'assertion toujours vraie de `registeredPrompts()` est retirée (celle sur le nom reste). Puis
`composer phpstan` : `[OK] No errors`.

- [ ] **Étape 8 : régénérer `config/reference.php` et relever la suite**

Run : `docker compose exec -T -u dev backend php bin/console cache:clear` (environnement `dev`, qui régénère
`config/reference.php`), puis `git diff --stat backend/config/reference.php` (attendu : `allowed_origins` et
`session.lock` sous `mcp.servers.*`, entre autres). Puis :
- `docker compose exec -T -u dev backend composer audit` → aucun avis ;
- `docker compose exec -T -u dev backend php bin/phpunit tests/Shared/SymfonyAiAndMcpPinsMatchTheLockTest.php` → vert ;
- `docker compose exec -T -u dev backend php bin/phpunit tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php --filter testTheAnswerNeverTransitsThroughTheSession` → **vert** (la prémisse 0.9) ;
- `docker compose exec -T -u dev backend php bin/phpunit tests/Mcp tests/Ai` → **exactement 5 échecs attendus**, tous
  traités par les tâches suivantes : `McpEndpointGetTest::testAGetFromThePageOfTheSiteIsServed` (R4, tâche 2),
  `McpCallLogTest::testAModernToolCallLeavesOneLineWithTheExpectedFields`, `::testAModernDeclaredClientIsBounded`,
  `::testADeclaredClientLosesItsControlAndFormatCharacters` (R5, tâche 3),
  `McpEndpointTest::testAnOversizedSessionIsNeitherKeptNorReusable` (R3, tâche 5). Tout autre échec est à comprendre
  avant de committer.

- [ ] **Étape 9 : la règle**

Dans `.claude/rules/mcp.md`, puce « What it is » : les pins deviennent `0.15.0` et `0.9.0` (le bundle 0.15.0 exige
`mcp/sdk ^0.9`), la phrase « so 0.9 is out until a bundle release moves » est retirée.

- [ ] **Étape 10 : commit**

```bash
git add backend/composer.json backend/composer.lock backend/symfony.lock backend/config/reference.php \
  backend/src/Mcp/Infrastructure/Registry/SecuritySchemesRegistry.php \
  backend/src/Mcp/Infrastructure/Registry/ToolWithSecuritySchemes.php \
  backend/tests/Mcp/Infrastructure/Registry/SecuritySchemesRegistryTest.php \
  backend/tests/Mcp/Support/ReadsPortfolioRegistry.php \
  backend/tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php .claude/rules/mcp.md
git commit -F - <<'EOF'
fix(deps): symfony/mcp-bundle 0.15.0 et mcp/sdk 0.9.0, toujours épinglés en version exacte (#731)

Les deux paquets montent ensemble (le bundle 0.15.0 exige mcp/sdk ^0.9),
en version exacte (ADR 0006 D7) : composer audit ne signale plus l'avis
PKSA-s52d-n7sx-trsg (GHSA-f4rf-6ghj-mhr6). SecuritySchemesRegistry délègue
publishesListChanges() (R1), ToolWithSecuritySchemes recopie execution
(R6), les cinq erreurs PHPStan Page<TItem> sont corrigées (R10) et
config/reference.php est régénéré. Un test épingle la prémisse nouvelle :
la réponse d'un POST ne transite plus par la session (vu rouge sur 0.8.1).
Cinq tests restent rouges, traités par les commits suivants (R3, R4, R5).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt
EOF
```

---

### Tâche 2 : R4, `allowed_origins` = l'origine du site, par environnement

**Modèle conseillé :** opus — **sensible** (`Origin`).

**Règles à lire avant d'écrire (outil `Read`) :** `.claude/rules/mcp.md` (puces « Host check » et « `GET /api/mcp` »),
`.claude/rules/deploiement.md` (lire un fichier de `k8s/overlays/` par `Read`), `.claude/rules/backoffice-api.md`
(« Authorization », gardes de parité #659/#698), `.claude/rules/backend-outillage.md` (`phpunit.dist.xml`,
`ForcedTestValuesWinOverTheShellTest`).

**Fichiers :**
- Modifier : `backend/config/packages/mcp.yaml` (`http.allowed_origins`)
- Modifier : `backend/.env` (bloc `###> app ###`), `backend/phpunit.dist.xml`
- Modifier : `k8s/overlays/preprod/kustomization.yaml`, `k8s/overlays/prod/kustomization.yaml` (littéraux de `backend-config`)
- Créer : `backend/tests/Mcp/Infrastructure/Kubernetes/McpAllowedOriginPerEnvironmentTest.php`
- Modifier : `backend/tests/Mcp/Presentation/McpEndpointGetTest.php`, `backend/tests/Mcp/Presentation/McpEndpointTest.php`
- Modifier : `backend/src/Mcp/Presentation/Controller/McpEndpointController.php` (docblock), `.claude/rules/mcp.md`

**Interfaces :**
- Produit : la variable d'environnement `MCP_ALLOWED_ORIGIN` (une origine `scheme://hôte[:port]`, minuscules, sans
  barre finale), lue par `mcp.yaml` : `allowed_origins: ['%env(MCP_ALLOWED_ORIGIN)%']`.
- Valeurs : `.env` (dev) `http://localhost:5173` ; `phpunit.dist.xml` (test) `http://localhost:5173`, en
  `<server … force="true"/>` **et** `<env … force="true"/>` de même valeur ; préprod
  `https://preprod.cp-ghostotof.com` ; prod `https://cp-ghostotof.com`.

- [ ] **Étape 1 : constater le rouge de départ**

Run : `docker compose exec -T -u dev backend php bin/phpunit tests/Mcp/Presentation/McpEndpointGetTest.php --filter testAGetFromThePageOfTheSiteIsServed`
Attendu : FAIL, 403 « Forbidden: Invalid Origin header. » (le SDK 0.9 compare l'`Origin`, schéma et port compris, à la
même origine ou à `allowedOrigins`, vide par défaut ; `http://localhost:5173` n'est pas `http://localhost`).

- [ ] **Étape 2 : écrire les tests d'origine, rouges ou verts pour la bonne raison**

`McpEndpointGetTest` — fournisseur `originsAgainstTheSite()` et `testTheGetAdmitsTheSiteOriginOnly(string $origin, int
$expectedStatus)` (client du kernel, `HTTP_ORIGIN` + `Accept: text/event-stream`) :

```php
yield 'la page du site' => ['http://localhost:5173', 200];
yield 'la même origine que l\'API' => ['http://localhost', 200];
yield 'autre schéma' => ['https://localhost:5173', 403];
yield 'autre port' => ['http://localhost:5174', 403];
yield 'MCP Inspector' => ['http://localhost:6274', 403];
yield 'origine opaque' => ['null', 403];
yield 'hôte étranger' => ['https://evil.example', 403];
```

Pour un 403, asserter aussi le corps `Forbidden: Invalid Origin header.`. Le docblock de
`testAGetFromThePageOfTheSiteIsServed` perd « port ignoré » : l'origine du site est admise par `allowed_origins`.
`McpEndpointTest` — le même fournisseur au POST (`testThePostAdmitsTheSiteOriginOnly`, via `postInitialize($client,
['HTTP_ORIGIN' => $origin])`, 200 ou 403). Run : rouges sur `la page du site` (403), verts ailleurs.

- [ ] **Étape 3 : écrire le garde par environnement**

`McpAllowedOriginPerEnvironmentTest` (lit les fichiers par `tests/Support/RepositoryFiles`, les overlays par
`KubernetesManifests::overlay('<nom>')->documents('kustomization.yaml')` puis le `configMapGenerator` nommé
`backend-config` et ses `literals`, `.env` par `Symfony\Component\Dotenv\Dotenv::parse()`, `phpunit.dist.xml` par
`DOMDocument`). Tests :
- `testMcpYamlReadsTheOriginFromTheEnvironment()` : `mcp.servers.portfolio.http.allowed_origins` de `mcp.yaml` vaut
  exactement `['%env(MCP_ALLOWED_ORIGIN)%']` (rien en dur) ;
- `testEachEnvironmentAllowsExactlyTheOriginOfItsSite()`, fournisseur `dev` (`.env`), `test` (`phpunit.dist.xml`,
  `<server>` et `<env>` forcés et égaux ; l'URL du site est celle de `.env`, que le test charge aussi), `preprod`,
  `prod` (les deux littéraux du même `backend-config`). Pour chacun : la variable est présente, elle vérifie
  `\A(https?)://[a-z0-9.-]+(:[0-9]{1,5})?\z` (ni vide, ni `*`, ni barre finale, ni chemin), et elle est égale à
  l'origine de `APP_FRONTEND_BASE_URL` du même environnement (`scheme://host`, plus `:port` s'il est écrit) ; préprod et
  prod en `https`.

Run : rouge (variable absente partout, `mcp.yaml` sans `allowed_origins`).

- [ ] **Étape 4 : poser la variable et la configuration**

`mcp.yaml`, sous `http:` après `allowed_hosts` : `allowed_origins: ['%env(MCP_ALLOWED_ORIGIN)%']`, avec un commentaire
(le SDK compare l'`Origin` schéma et port compris ; la même origine passe toujours ; l'origine du site est admise en
plus, une valeur par environnement, jamais en dur ici — en dev le site, sur Vite, n'est pas sur l'origine de l'API).
`.env` : `MCP_ALLOWED_ORIGIN=http://localhost:5173`, avec son commentaire (égale à l'origine
d'`APP_FRONTEND_BASE_URL`, ni vide ni `*` : une valeur vide admettrait un `Origin` vide). `phpunit.dist.xml` : les
deux entrées forcées, près d'`APP_PUBLIC_API_BASE_URL`, avec un commentaire. Overlays : un littéral
`MCP_ALLOWED_ORIGIN=…` après `APP_FRONTEND_BASE_URL`, commenté comme ses voisins (ConfigMap `backend-config` à nom
stable : appliquée par `apply -k` avant le rollout de l'image de la PR).

- [ ] **Étape 5 : vert, et les gardes de parité restent verts**

Run : les trois classes ciblées ci-dessus → vertes ; puis
`docker compose exec -T -u dev backend php bin/phpunit tests/Security/SecurityConfigEnvironmentParityTest.php tests/Security/RouteEnvironmentParityTest.php tests/Security/SecurityServicesEnvironmentParityTest.php tests/Shared/ForcedTestValuesWinOverTheShellTest.php tests/Shared/ForcedTestValuesProbeTest.php tests/Mcp/Infrastructure/Log/McpCallLogTest.php`
Attendu : vert, hors les trois tests R5 de `McpCallLogTest` (tâche 3) ; `testAForeignOriginIsRefused` et le test
d'`Origin` étranger de `McpCallLogTest` restent verts.

- [ ] **Étape 6 : documentation**

`.claude/rules/mcp.md` : la puce « Host check » devient « Host and Origin check » (`allowed_hosts` inchangé ;
`allowed_origins` = `MCP_ALLOWED_ORIGIN`, une valeur par environnement et où elle est posée, le garde
`McpAllowedOriginPerEnvironmentTest` ; le SDK compare l'`Origin` schéma et port compris ; la même origine passe
toujours, déduite de l'URI de la requête — en préprod et en prod elle l'est derrière l'Ingress par `trusted_proxies`,
et l'origine du site y est de toute façon admise par la variable ; MCP Inspector et toute page tierce sont refusés,
`Host` absent compris) ; dans la puce « `GET /api/mcp` », la limite acceptée « the `Origin` check ignores scheme and
port » est retirée. Docblock de `McpEndpointController` : « la liste `allowed_hosts` » devient « `allowed_hosts` et
`allowed_origins` ».

- [ ] **Étape 7 : commit**

```bash
git add backend/config/packages/mcp.yaml backend/.env backend/phpunit.dist.xml \
  k8s/overlays/preprod/kustomization.yaml k8s/overlays/prod/kustomization.yaml \
  backend/tests/Mcp/Infrastructure/Kubernetes/McpAllowedOriginPerEnvironmentTest.php \
  backend/tests/Mcp/Presentation/McpEndpointGetTest.php backend/tests/Mcp/Presentation/McpEndpointTest.php \
  backend/src/Mcp/Presentation/Controller/McpEndpointController.php .claude/rules/mcp.md
git commit -F - <<'EOF'
fix(mcp): l'origine du site admise par allowed_origins, une variable par environnement (#731)

Le SDK 0.9 compare l'Origin schéma et port compris : la page du site en
dev (Vite) était refusée en 403. allowed_origins lit MCP_ALLOWED_ORIGIN,
posée par environnement (.env, phpunit.dist.xml, ConfigMaps de préprod et
de prod), rien en dur dans mcp.yaml (décision du 2026-11-06). Un garde
exige dans chaque environnement l'origine exacte de son site.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt
EOF
```

---

### Tâche 3 : R5, `declaredClient` de l'ère sans session lu dans `RequestMeta`

**Modèle conseillé :** sonnet — non sensible (journal, aucune donnée nouvelle n'y entre).

**Règles à lire avant d'écrire (outil `Read`) :** `.claude/rules/mcp-journaux.md`, `.claude/rules/mcp.md`,
`.claude/rules/backend-conventions.md`.

**Fichiers :**
- Modifier : `backend/src/Mcp/Infrastructure/Log/McpCallLog.php`
- Modifier : `backend/src/Mcp/Infrastructure/Log/ModernExchange.php` (docblock)
- Modifier : `backend/tests/Mcp/Infrastructure/Log/McpCallLogTest.php`
- Modifier : `.claude/rules/mcp-journaux.md`

**Interfaces :**
- Consomme : `Mcp\Server\Stateless\RequestMeta` (clé de session `RequestMeta::class`, propriété `?Implementation
  $clientInfo`, dont `name` et `version` sont des `string` quand elle est servie : `Implementation::fromArray()` les
  exige, et `InboundMessageRefusalListener` refuse avant le SDK un `clientInfo` que `RequestMeta::fromParams()` ne
  sait pas lire).
- Produit : `McpCallLog::declaredClient(SessionInterface $session): ?array` lit `client_info` (ère à poignée de main),
  sinon `RequestMeta::class` (ère sans session), puis borne par `boundedClient()`, inchangé.

- [ ] **Étape 1 : constater les trois rouges, et la raison**

Run : `docker compose exec -T -u dev backend php bin/phpunit tests/Mcp/Infrastructure/Log/McpCallLogTest.php --filter 'testAModernToolCallLeavesOneLineWithTheExpectedFields|testAModernDeclaredClientIsBounded|testADeclaredClientLosesItsControlAndFormatCharacters'`
Attendu : 3 FAIL, `declaredClient` vaut `null` (la 0.9 émet `RequestEvent`/`ResponseEvent`/`ErrorEvent` pour l'ère
sans session, sur une `Session` en mémoire qui porte `RequestMeta::class`, pas `client_info` : la ligne est écrite par
`onResponse()`, `ModernExchange` n'est plus appelé).

- [ ] **Étape 2 : ajouter le cas limite**

`testAModernCallWithoutClientInfoDeclaresNoClient()` : `postModernMcp($client, 'tools/list')` sans `clientInfo` ;
`self::assertNull($this->onlyCallLine()->context['declaredClient'])`. Vert dès maintenant (rien n'est déclaré) : il
garde que la correction n'invente pas un client (`'app'`/`'dev'`, défauts d'`Implementation`).

- [ ] **Étape 3 : lire `RequestMeta`**

Dans `declaredClient()`, quand `client_info` n'est pas un tableau, lire `$session->get(RequestMeta::class)` : une
`RequestMeta` dont `clientInfo` n'est pas `null` donne `['name' => $clientInfo->name, 'version' =>
$clientInfo->version]`, sinon `null` ; le tout par `boundedClient()`. Les trois tests de l'étape 1 et celui de l'étape
2 verts ; toute la classe `McpCallLogTest`, `McpCallLogTerminateTest` et `ModernExchangeTest` verts.

- [ ] **Étape 4 : documentation**

Docblock de `McpCallLog` (paragraphe « La révision sans session ») et de `ModernExchange` : le SDK émet désormais les
événements de l'ère sans session ; la ligne d'un message servi s'écrit sur ces événements, `declaredClient` lu dans
la `RequestMeta` de la session en mémoire ; `ModernExchange` ne sert plus qu'aux échanges sans événement — relever
lesquels dans `vendor/mcp/sdk/src/Server/Stateless/StatelessProtocol.php` (où `RequestEvent` est émis) et dans les cas
de `McpCallLogTest` sous l'enveloppe moderne : au moins les refus avant le SDK (`InboundMessageRefusalListener`) et le
401 différé. **Nouveau sens de `durationMs`, décidé** : la durée du traitement du message dans les deux ères (de
`RequestEvent` à la réponse), plus celle de la requête HTTP entière ; pour une ligne écrite par `ModernExchange`, elle
reste celle de la requête HTTP. `.claude/rules/mcp-journaux.md`, puce « The call log » : les mêmes faits, en
remplaçant « `StatelessProtocol`, **`final`, built inside `Builder::build()`, with no event** » et « **`durationMs` is
the whole HTTP request's** … a duration statistic keeps the two eras apart » par la nouvelle vérité, et la mention de
la version du SDK contre laquelle la liste fermée de `SdkChannelRedactor` est écrite par « la version épinglée ».

- [ ] **Étape 5 : commit**

```bash
git add backend/src/Mcp/Infrastructure/Log/McpCallLog.php backend/src/Mcp/Infrastructure/Log/ModernExchange.php \
  backend/tests/Mcp/Infrastructure/Log/McpCallLogTest.php .claude/rules/mcp-journaux.md
git commit -F - <<'EOF'
fix(mcp): le client déclaré de l'ère sans session lu dans RequestMeta, durationMs mesure le message (#731)

Le SDK 0.9 émet ses événements pour la révision 2026-07-28 : la ligne
d'appel s'écrit sur eux, et le clientInfo déclaré se lit dans la
RequestMeta de la session en mémoire (décision du 2026-11-06).
durationMs mesure désormais le traitement du message dans les deux ères ;
ModernExchange ne sert plus qu'aux échanges sans événement.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt
EOF
```

---

### Tâche 4 : R7, 503 au GET quand le store de sessions est injoignable

**Modèle conseillé :** opus — **sensible** (session).

**Règles à lire avant d'écrire (outil `Read`) :** `.claude/rules/mcp.md` (puces « Sessions » et « `GET /api/mcp` »),
`.claude/rules/mcp-journaux.md` (canal `mcp`, `SdkChannelRedactor`), `.claude/rules/backend-conventions.md`.

**Fichiers :**
- Créer : `backend/tests/Mcp/Fixtures/UnreachableSimpleCache.php` (`Psr\SimpleCache\CacheInterface` dont chaque
  méthode lève `UnreachableCacheException`)
- Créer : `backend/tests/Mcp/Fixtures/UnreachableCacheException.php` (`extends RuntimeException implements
  Psr\SimpleCache\CacheException`, factory nommée `storeDown()`)
- Modifier : `backend/src/Mcp/Presentation/Controller/McpEndpointController.php`
- Modifier : `backend/tests/Mcp/Presentation/McpEndpointGetTest.php`
- Modifier : `backend/tests/Mcp/Infrastructure/Session/BoundedSessionStoreTest.php`
- Modifier : `backend/src/Mcp/Infrastructure/Session/BoundedSessionStore.php` (docblock), `.claude/rules/mcp.md`

**Interfaces :**
- Consomme : `Mcp\Exception\SessionStoreException`, que `Psr16SessionStore::exists()` lève en 0.9 quand le cache
  échoue ; le POST, servi par le SDK, en fait un 503 `-32603` « Session store is unavailable. »
  (`Protocol::resolveSession()`).
- Produit : `McpEndpointController::__construct(…, ServerAnswersInterface $serverAnswers, LoggerInterface $logger)` —
  dernier paramètre `#[Autowire(service: 'monolog.logger.mcp')]` ; au GET, `SessionStoreException` → 503,
  `application/json`, une erreur JSON-RPC `-32603` « Session store is unavailable. », et un `error` « Session store is
  unavailable. » sur le canal `mcp`, contexte `['exception' => $e]` sans l'identifiant de session.

- [ ] **Étape 1 : le décorateur laisse passer l'injoignable (le POST du SDK en dépend)**

`BoundedSessionStoreTest::testAnUnreachableStoreIsNotAnAbsentSession()` : le décorateur sur `new
Psr16SessionStore(new UnreachableSimpleCache())` ; `$this->expectException(SessionStoreException::class)` sur
`exists(Uuid::v4())`, et aucun `warning` « MCP session unreadable » écrit (l'exception passe avant). Run : vert dès
la 0.9 — il épingle que le décorateur ne transforme pas la panne en `false` (un 404 qui ferait se réinitialiser le
client).

- [ ] **Étape 2 : le test rouge du GET**

Dans `McpEndpointGetTest`, généraliser l'aide `controllerAllowing()` en `controllerWith(array $allowedHosts,
SessionStoreInterface $sessionStore, LoggerInterface $logger)` (les appels existants passent
`$container->get('mcp.server.portfolio.session.store')` et `new NullLogger()` de `psr/log`). Fournisseur `sessionHeadersOnAnUnreachableStore()` et
`testAGetOnAnUnreachableSessionStore(array $server, int $expectedStatus)` avec, comme store, `new
BoundedSessionStore(new Psr16SessionStore(new UnreachableSimpleCache()), new TokenStorage(), $logger)` et un
`Monolog\Logger` sur un `TestHandler` :

```php
yield 'un identifiant à contrôler' => [['HTTP_MCP_SESSION_ID' => Uuid::v4()->toRfc4122()], 503];
yield 'sans en-tête de session' => [[], 200];
yield 'en-tête vide, lu absent' => [['HTTP_MCP_SESSION_ID' => ''], 200];
yield 'en-tête « 0 », lu absent' => [['HTTP_MCP_SESSION_ID' => '0'], 200];
```

Pour le 503 : `Content-Type` `application/json`, `error.code` `-32603`, `error.message` `Session store is
unavailable.`, un seul enregistrement `error` de ce message, et ni la valeur de l'identifiant ni une clé
`session_id` dans son contexte. Run : FAIL sur « un identifiant à contrôler » (l'exception sort du contrôleur, le
noyau répond 500) ; les trois autres verts.

- [ ] **Étape 3 : attraper l'exception au GET**

Dans `eventStream()`, entourer le seul appel `$this->sessionStore->exists($sessionId)` d'un `try`/`catch
(SessionStoreException $e)` qui journalise puis rend une méthode privée `jsonRpcServerError(int $status, string
$message): ResponseInterface` (construite comme `jsonRpcError()`, avec `Error::forInternalError($message)` du SDK,
lu dans `vendor/mcp/sdk/src/Schema/JsonRpc/Error.php`). Ajouter le paramètre `$logger`. Run : le fournisseur entier
vert ; `McpEndpointGetTest`, `McpEndpointTest`, `BoundedSessionStoreTest` verts ;
`docker compose exec -T -u dev backend php bin/phpunit tests/Security/SecurityServicesEnvironmentParityTest.php` vert.

- [ ] **Étape 4 : documentation**

Docblock du contrôleur (le 503, « suivre le SDK » : la session existe peut-être, le client réessaie au lieu de se
réinitialiser — décision du 2026-11-06) et de `BoundedSessionStore` (`exists()` laisse passer la
`SessionStoreException` du store, que le SDK sert en 503 au POST, le contrôleur au GET ; la décision #673, session
présente mais illisible → 404, inchangée : `read()` avale toujours ses erreurs). `.claude/rules/mcp.md` : puce
« Sessions », la phrase « `Psr16SessionStore` swallows every exception and returns `false` » devient vraie pour
`read`/`write`/`destroy` seulement, `exists()` lève et devient un 503 ; puce « `GET /api/mcp` », le 503 parmi les
réponses du transport recopiées.

- [ ] **Étape 5 : commit**

```bash
git add backend/tests/Mcp/Fixtures/UnreachableSimpleCache.php backend/tests/Mcp/Fixtures/UnreachableCacheException.php \
  backend/src/Mcp/Presentation/Controller/McpEndpointController.php backend/tests/Mcp/Presentation/McpEndpointGetTest.php \
  backend/tests/Mcp/Infrastructure/Session/BoundedSessionStoreTest.php \
  backend/src/Mcp/Infrastructure/Session/BoundedSessionStore.php .claude/rules/mcp.md
git commit -F - <<'EOF'
fix(mcp): le GET répond 503 quand le store de sessions est injoignable, comme le POST du SDK (#731)

En 0.9 Psr16SessionStore::exists() lève SessionStoreException quand le
cache est injoignable, et le SDK répond 503 -32603 au POST. Le GET suit
(décision du 2026-11-06) : le client réessaie au lieu de se réinitialiser.
Le décorateur borné laisse passer l'exception, épinglé par un test.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt
EOF
```

---

### Tâche 5 : R3, l'identifiant d'une session trop grosse n'existe pas

**Modèle conseillé :** opus — **sensible** (session).

**Règles à lire avant d'écrire (outil `Read`) :** `.claude/rules/mcp.md` (puce « Sessions »), `.claude/rules/mcp-journaux.md`.

**Fichiers :**
- Modifier : `backend/tests/Mcp/Presentation/McpEndpointTest.php`
- Modifier : `backend/src/Mcp/Infrastructure/Session/BoundedSessionStore.php` (docblock), `.claude/rules/mcp.md`

**Interfaces :** aucune nouvelle ; le comportement suit le SDK (décision 7).

- [ ] **Étape 1 : constater le rouge, et la raison**

Run : `docker compose exec -T -u dev backend php bin/phpunit tests/Mcp/Presentation/McpEndpointTest.php --filter testAnOversizedSessionIsNeitherKeptNorReusable`
Attendu : FAIL sur `assertNull` de l'en-tête `Mcp-Session-Id` (en 0.9 la réponse est au transport avant le `save()`,
que la borne refuse : l'`initialize` répond, avec l'identifiant d'une session qui n'existe pas).

- [ ] **Étape 2 : réécrire le test selon la décision**

`testAnOversizedSessionIsNeitherKeptNorReusable()` garde son début (le témoin `storedMcpSessions()`, l'`initialize` au
`_meta` de 600 Kio) et asserte désormais :

```php
self::assertResponseIsSuccessful();                       // l'initialize est servi (le SDK)
$sessionId = $client->getResponse()->headers->get(self::MCP_SESSION_HEADER);
self::assertIsString($sessionId);                        // un identifiant est rendu…
self::assertSame(0, $this->storedRowsOf($sessionId));    // …d'une session absente de la base
self::assertLessThan(BoundedSessionStore::MAX_BYTES, $this->largestStoredMcpSession());

$this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
self::assertResponseStatusCodeSame(404);                 // pas réutilisable : le client se réinitialise
$client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_ACCEPT' => 'text/event-stream']);
self::assertResponseStatusCodeSame(404);                 // au GET non plus
```

`storedRowsOf(string $sessionId): int` : `SELECT count(*) FROM cache_items WHERE item_id LIKE ?` sur
`'%mcp-portfolio-'.$sessionId.'%'` (le motif des autres tests de la classe). Les enregistrements du canal `mcp` de
l'`initialize` sont relevés et épinglés tels que mesurés : attendu d'après le code, un seul `warning` « MCP session
refused: over the size bound. » (tailles seules) et aucun « Failed to save session » (`Session::save()` rend `false`
sans lever quand le store refuse l'écriture) ; si la mesure diffère, épingler la mesure et le signaler dans le rapport
de la tâche. Docblock : la décision 7 du 2026-11-06 (suivre le SDK) et ce qui protège toujours le disque (la borne).
Dans `testASessionThatOutgrowsTheBoundIsGoneForTheNextRequest()`, ajouter après le `tools/list` volumineux
`self::assertResponseIsSuccessful()` et la présence d'un `result` (en 0.9 la réponse arrive, la session est détruite).

- [ ] **Étape 3 : vert**

Run : `docker compose exec -T -u dev backend php bin/phpunit tests/Mcp/Presentation/McpEndpointTest.php tests/Mcp/Infrastructure/Session`
Attendu : vert. Si la session écrite ou relue par le SDK après le refus (la lecture de la file sortante,
`Protocol::consumeOutgoingMessages()` en 0.9) faisait réapparaître une ligne, `storedRowsOf()` le dirait : c'est ce
que la mémoire des identifiants détruits du décorateur empêche ; ne pas la retirer.

- [ ] **Étape 4 : documentation**

`BoundedSessionStore`, paragraphe « Et le reste pour la requête en cours » : la justification est réécrite d'après la
0.9 (relire dans `vendor/mcp/sdk/src/Server/Protocol.php` ce qui lit ou écrit la session après le `save()` : la file
des messages initiés par le serveur) ; la phrase « La borne doit laisser passer la plus grosse réponse légitime : le
SDK met aussi en file … la réponse d'un outil » est laissée à la tâche 6. `.claude/rules/mcp.md`, puce « Sessions » :
« the answer queued for that request is lost (it could not fit), the client sees no JSON-RPC answer, then a 404 »
devient : la réponse arrive, l'identifiant rendu (à l'`initialize`) n'existe pas, la requête suivante reçoit 404, au
POST comme au GET ; `testAnOversizedSessionIsNeitherKeptNorReusable` le prouve.

- [ ] **Étape 5 : commit**

```bash
git add backend/tests/Mcp/Presentation/McpEndpointTest.php \
  backend/src/Mcp/Infrastructure/Session/BoundedSessionStore.php .claude/rules/mcp.md
git commit -F - <<'EOF'
test(mcp): l'identifiant d'une session trop grosse n'existe pas, 404 à la requête suivante (#731)

En 0.9 la réponse est au transport avant la sauvegarde de la session :
l'initialize dont la session dépasse la borne est servi, avec l'identifiant
d'une session absente de la base. On suit le SDK (décision du 2026-11-06),
et le test prouve que cet identifiant n'est pas réutilisable (404 au POST
et au GET).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt
EOF
```

---

### Tâche 6 : R2, retrait de la borne en file, lien session ↔ connexion gardé et rejustifié

**Modèle conseillé :** opus — **sensible** (réponse nominative, session).

**Règles à lire avant d'écrire (outil `Read`) :** `.claude/rules/mcp.md` (puces « The catalogue » et « Sessions »),
`.claude/rules/mcp-journaux.md`, `.claude/rules/backend-conventions.md`, `.claude/rules/security-authentication-audit.md`
(la ligne `nominative-corpus-read`).

**Fichiers :**
- Supprimer : `backend/src/Mcp/Presentation/Tool/QueuedAnswer.php`
- Modifier : `backend/src/Mcp/Presentation/Tool/GetNominativeCorpusTool.php`
- Modifier : `backend/src/Mcp/Application/NominativeCorpusReaderInterface.php` (docblock)
- Modifier : `backend/tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php`
- Modifier : `backend/tests/Mcp/Presentation/Tool/RouteBackedToolsTest.php`
- Modifier : `backend/tests/Mcp/McpExposureSymmetryTest.php` (`TOOLS_WITHOUT_ROUTE`)
- Modifier : `backend/src/Mcp/Infrastructure/Session/BoundedSessionStore.php` (docblock)
- Modifier : `backend/tests/Mcp/Infrastructure/Session/BoundedSessionStoreTest.php`
- Modifier : `.claude/rules/mcp.md`, `.claude/rules/mcp-journaux.md`, `tasks/todo.md`

**Interfaces :**
- Consomme : `GetNominativeCorpusToolTest::testTheAnswerNeverTransitsThroughTheSession()` (tâche 1), la nouvelle
  prémisse.
- Produit : `GetNominativeCorpusTool::__construct(NominativeCorpusReaderInterface $corpusReader, BearerChallenge
  $challenge)` — plus de `LoggerInterface` ; plus de `QUEUED_ANSWER_MAX_BYTES` ni de `CORPUS_TOO_LARGE` ;
  `__invoke()` rend le corpus ouvert puis `recordServed()`, ou le refus sans connexion, inchangé.

Lecture retenue de la décision 3 : sans borne, le refus « corpus trop grand » n'a plus d'objet ; il part avec elle,
avec son test `testAnOversizedCorpusIsRefusedWithTheCvPageLink` et le `warning` qu'il écrivait. Cette lecture a été
remontée au fil principal avec le plan (2026-11-10) : l'auteur la confirme avant que cette tâche ne s'exécute.

- [ ] **Étape 1 : le test d'exposition dit le nouveau constructeur (rouge)**

`McpExposureSymmetryTest::TOOLS_WITHOUT_ROUTE['get_nominative_corpus']` : la justification perd « au-delà de sa
borne, le refus et un warning aux tailles seules (LoggerInterface) », la liste des types devient
`[NominativeCorpusReaderInterface::class, BearerChallenge::class]` (retirer l'import devenu inutile). Run :
`docker compose exec -T -u dev backend php bin/phpunit tests/Mcp/McpExposureSymmetryTest.php` → FAIL (l'outil prend
encore le logger).

- [ ] **Étape 2 : retirer la borne**

Supprimer `QueuedAnswer.php` (`git rm`) ; dans `GetNominativeCorpusTool`, retirer `QUEUED_ANSWER_MAX_BYTES`,
`CORPUS_TOO_LARGE`, la mesure, le `warning`, le paramètre `$logger` et les imports de `BoundedSessionStore` et
`LoggerInterface` ; réécrire le docblock (plus de mesure « telle que le SDK la mettra en file » ; deux issues : corpus
ouvert puis ligne d'audit, ou refus) et celui de `NominativeCorpusReaderInterface` (l'audit suit la construction du
résultat, plus une mesure). Dans `GetNominativeCorpusToolTest`, retirer
`testTheToolMeasuresWhatTheSdkQueues`, `testAQueuedAnswerAtTheBoundFitsInTheSession`,
`testAnOversizedCorpusIsRefusedWithTheCvPageLink` et les aides devenues inutiles (`longestCorpusUnder()`,
`queuedBytesOf()`, imports) ; dans `RouteBackedToolsTest`, `testTheAnswerQueuedInTheSessionStaysFarBelowTheSessionBound`
et ses constantes (`ANSWER_SHARE_OF_THE_SESSION_BOUND`, `QUEUED_PER_ROUTE_BYTE`, `ENVELOPE_BYTES`) si plus rien ne les
lit. Run : `McpExposureSymmetryTest`, `GetNominativeCorpusToolTest` (dont `testTheAnswerNeverTransitsThroughTheSession`
et `testEachReadIsAudited`), `RouteBackedToolsTest` verts ;
`git grep -n -E 'QueuedAnswer|QUEUED_ANSWER_MAX_BYTES|CORPUS_TOO_LARGE' -- backend` → vide.

- [ ] **Étape 3 : la séquence du store rejouée selon la 0.9**

`BoundedSessionStoreTest::testTheQueueHoldingTheAnswerIsNeverWrittenUnbound()` rejoue la séquence 0.8.1 (la réponse
mise en file puis vidée). La remplacer par `testEveryWriteOfABoundSessionCarriesItsBinding()`, qui rejoue la séquence
d'un `tools/call` de la 0.9 avec la `Session` du SDK — relue dans `vendor/mcp/sdk/src/Server/Protocol.php` et
`Session/Session.php` : `exists()` à la réception, lecture, `set()` du `_meta` de la requête, `save()` (qui rappelle
`exists()` puis relit avant d'écrire), puis la lecture de la file sortante — sous une connexion ; chaque écriture reçue
par le store intérieur commence par la liaison de la connexion. Le docblock de
`testTheBoundCountsTheSessionNotTheBinding()` perd sa mention de `QUEUED_ANSWER_MAX_BYTES`. Retirer la constante
`OUTGOING_QUEUE` si plus rien ne la lit. Run : `BoundedSessionStoreTest` vert.

- [ ] **Étape 4 : la justification du lien, réécrite (décision 4)**

Docblock de `BoundedSessionStore` : la phrase de la borne (« La borne doit laisser passer la plus grosse réponse
légitime… ») devient : la borne protège le disque de ce que le client fait recopier en session ; la réponse d'un outil
n'y passe plus (prémisse épinglée par `testTheAnswerNeverTransitsThroughTheSession`). Le paragraphe du lien session ↔
connexion perd sa raison principale (la vidange de **toute** la file vers un `POST` concurrent, vraie en 0.8.1) et
garde le lien **en défense en profondeur** : la session porte ce que la connexion a déclaré (`client_info`, le dernier
`_meta`, la version négociée) et la file des messages initiés par le serveur, qu'aucune autre requête ne doit lire ni
réécrire ; le jour où un outil émettrait une requête vers le client (élicitation, échantillonnage), la vidange
reprendrait sa raison d'origine. Les limites acceptées (la course du SDK sans verrou, #762) restent.

- [ ] **Étape 5 : règles et suivi**

`.claude/rules/mcp.md` : puce « The catalogue » — retirer « once the answer fits its bound », « a session over its
bound once the answer is queued », « **Its queued answer is bounded** (`QUEUED_ANSWER_MAX_BYTES`…) » et l'import assumé
de `BoundedSessionStore` par `Mcp/Presentation` ; « measures the answer » sort de la description de l'adaptateur ;
puce « Sessions » — retirer « **The bound must fit the largest legitimate answer** … » jusqu'à « 4 % of the bound; the
seeds give the same order (12 KB for `list_incidents`). » (mesures et tests retirés), remplacer par la prémisse 0.9 et
son test ; réécrire « **A session is bound to the first MCP connection that uses it** » selon l'étape 4 ;
`BoundedSessionStoreTest replays the SDK's sequence` suit l'étape 3. `.claude/rules/mcp-journaux.md` : retirer la
phrase « **The nominative tool's size refusal** … ». `tasks/todo.md`, reports de la tâche 14 (#653) : barrer
`~~mesurer la taille réelle en file du corpus … contre QUEUED_ANSWER_MAX_BYTES~~` et `~~le corpus rendu en entier
avant d'être refusé pour sa taille~~`, avec « sans objet depuis #731 (la réponse ne passe plus par la session) ».

- [ ] **Étape 6 : vérifier et committer**

Run : `docker compose exec -T -u dev backend php bin/phpunit tests/Mcp` (vert hors les tests encore rouges d'une tâche
non faite) et `docker compose exec -T -u dev backend composer phpstan` (`[OK]`).

```bash
git rm backend/src/Mcp/Presentation/Tool/QueuedAnswer.php
git add backend/src/Mcp/Presentation/Tool/GetNominativeCorpusTool.php \
  backend/src/Mcp/Application/NominativeCorpusReaderInterface.php \
  backend/tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php \
  backend/tests/Mcp/Presentation/Tool/RouteBackedToolsTest.php backend/tests/Mcp/McpExposureSymmetryTest.php \
  backend/src/Mcp/Infrastructure/Session/BoundedSessionStore.php \
  backend/tests/Mcp/Infrastructure/Session/BoundedSessionStoreTest.php \
  .claude/rules/mcp.md .claude/rules/mcp-journaux.md tasks/todo.md
git commit -F - <<'EOF'
refactor(mcp): la borne de la réponse en file retirée, la réponse ne passe plus par la session (#731)

QueuedAnswer, QUEUED_ANSWER_MAX_BYTES, le refus d'un corpus trop grand et
les tests devenus vacants sont retirés (décision du 2026-11-06) : la prémisse
est épinglée par testTheAnswerNeverTransitsThroughTheSession. Le lien
session ↔ connexion est gardé en défense en profondeur, sa justification
réécrite, et la séquence du store rejouée selon la 0.9.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt
EOF
```

---

### Tâche 7 : vérifications R8, R9, R11 et coût de la session, épinglés

**Modèle conseillé :** opus — **sensible** (R8 : la réponse nominative).

**Règles à lire avant d'écrire (outil `Read`) :** `.claude/rules/mcp.md`, `.claude/rules/mcp-journaux.md`,
`.claude/rules/mcp-prompt-d8.md`.

**Fichiers :**
- Modifier : `backend/tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php` (R8)
- Modifier : `backend/tests/Mcp/Presentation/McpEndpointTest.php` (coût)
- Modifier : `.claude/rules/mcp.md`, `.claude/rules/mcp-journaux.md`, `.claude/rules/mcp-prompt-d8.md`

**Interfaces :** consomme `Mcp\Capability\Discovery\SchemaValidator::validateAgainstJsonSchema(mixed $data,
array|object $schema): list<array{pointer: string, keyword: string, message: string}>` (0.9).

- [ ] **Étape 1 : R8, aucun message de validation ne cite une valeur du corpus**

Lu dans `SchemaValidator::formatValidationError()` 0.9 : les messages citent des pointeurs (des clés), des mots-clés,
des paramètres du schéma et des noms de type, jamais une valeur. Épingler pour l'outil nominatif :
`testAnOutputBreakingItsSchemaNamesNoValueOfTheCorpus()` — le schéma est celui que publie `tools/list`
(`listedMcpTool($this->client, $sessionId, 'get_nominative_corpus')['outputSchema']`), le validateur `new
SchemaValidator()`, et trois sorties non conformes portant `self::CORPUS_SENTINEL` :

```php
yield 'corpus d\'un autre type' => [['corpus' => [self::CORPUS_SENTINEL], 'cvPageUrl' => 'u']];
yield 'propriété en plus' => [['corpus' => self::CORPUS_SENTINEL, 'cvPageUrl' => 'u', 'extra' => self::CORPUS_SENTINEL]];
yield 'propriété manquante' => [['corpus' => self::CORPUS_SENTINEL]];
```

(converties en objet comme le fait `CallToolHandler::validateStructuredContent()` : `json_decode(json_encode(...),
false)`). Assertions : au moins une erreur ; aucun `pointer` ni `message` ne contient le témoin. Run : vert. Si un
message cite une valeur, **arrêter la tâche** et rendre le constat au fil principal : c'est alors une tâche à part
(consigne de l'auteur).

- [ ] **Étape 2 : R9, rien à changer**

Run : `docker compose exec -T -u dev backend php bin/phpunit --filter 'testTheStructuredContentConformsToTheDeclaredOutputSchema|testTheToolIsReadOnlyAndItsOutputConformsToItsClosedSchema|testItIsReadOnlyAndItsOutputConformsToItsClosedSchema|testAValidConnectionReadsTheCorpusInEachLanguage' tests/Mcp`
Attendu : vert (constructeur `(?Validator, ?LoggerInterface)` sans argument, inchangé pour les tests).

- [ ] **Étape 3 : R11, les nouveaux messages du canal `mcp`**

Run : `docker compose exec -T -u dev backend php bin/phpunit tests/Mcp/Infrastructure/Log`
Attendu : vert (`SdkChannelRecordsTest`, `SdkChannelRedactorTest`). « Session store is unavailable. » est épinglé côté
GET par la tâche 4 (sans identifiant) ; « Failed to save session » par la tâche 5 (mesuré). Relire dans
`vendor/mcp/sdk/src/Server/Protocol.php` que « Received message to process. » ne porte plus le message brut et que
les deux nouveaux `warning` (« Client request timed out. », « Received a client response for an unknown or timed out
request ID. ») n'ont pour contexte que des clés que `SdkChannelRedactor::WITHHELD_KEYS` retire ou des chaînes qu'il
borne ; un contexte non couvert serait un trou : l'ajouter à `WITHHELD_KEYS` avec un cas de `SdkChannelRedactorTest`
(rouge avant, vert après).

- [ ] **Étape 4 : le coût d'un `tools/call` sur la ligne de session, mesuré puis épinglé**

`McpEndpointTest::testAToolCallCostsAFixedNumberOfSessionStatements()` : session ouverte, puis un `tools/call` de
`get_about` ; compter, dans les enregistrements `doctrine` de cette requête seule (`$client->disableReboot()` et un
décalage, comme à la tâche 1), les `Executing ` dont le SQL touche `cache_items`, séparés en lectures (`SELECT`) et
écritures. Attendu d'après la lecture de la 0.9 : 3 lectures (`exists()` à la réception, `exists()` rappelé par
`save()`, la lecture de la file sortante ; les relectures servies par le cache de lecture du décorateur) et 1
écriture. Épingler les deux nombres **mesurés** (`assertSame`), et signaler dans le rapport tout écart à l'attendu.
Docblock : le compte est un coût par requête MCP, qu'une montée du SDK peut changer (le test le dira).

- [ ] **Étape 5 : documentation**

`.claude/rules/mcp.md`, puce « Sessions » : « Each MCP request costs three round trips on the session row (`has`,
`get`, `set`) and two writes per `tools/call` (save, then the queue drained) » devient le compte mesuré et son test ;
ADR 0006 D6 « aucune écriture par appel » : tenue pour l'authentification, une écriture de session par requête.
Nouvelle phrase (puce « A tool serves exactly what its route serves ») : depuis la 0.9, un `structuredContent` non
conforme à son `outputSchema` devient une erreur d'outil (`isError: true`, « Invalid structured output for tool… »)
et une ligne `error` du canal `mcp` portant `validation_errors` ; l'outil cesse alors de servir ce que sert la route
jusqu'à correction (ADR 0006 D1) ; les messages ne citent aucune valeur (le test de l'étape 1). `.claude/rules/mcp-prompt-d8.md`
(l'erreur `-32603` rendue « by the SDK 0.8.1 ») : vérifier que `PresentForRolePromptTest` est vert, puis écrire « by
the pinned SDK ». `.claude/rules/mcp-journaux.md` : la liste fermée de `SdkChannelRedactor` « written against SDK
0.8.1 » devient « against the pinned SDK », et les messages de l'étape 3.

- [ ] **Étape 6 : commit**

```bash
git add backend/tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php \
  backend/tests/Mcp/Presentation/McpEndpointTest.php \
  .claude/rules/mcp.md .claude/rules/mcp-journaux.md .claude/rules/mcp-prompt-d8.md
git commit -F - <<'EOF'
test(mcp): validation de sortie sans valeur du corpus, coût de la session mesuré et épinglé (#731)

R8 : les messages de validation du structuredContent ne citent aucune
valeur du corpus nominatif, épinglé. R9 et R11 vérifiés. Le nombre de
lectures et d'écritures de la ligne de session par tools/call est mesuré
et épinglé, et les règles disent ce que la 0.9 change.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt
EOF
```
(Ajouter `SdkChannelRedactor.php` et `SdkChannelRedactorTest.php` à la liste si l'étape 3 les a touchés.)

---

### Tâche 8 : les versions et les faits de la 0.9 dans l'ADR et le code

**Modèle conseillé :** sonnet — non sensible (documentation).

**Règles à lire avant d'écrire (outil `Read`) :** `.claude/rules/mcp.md`, `.claude/rules/backend-conventions.md`
(commentaires, `{@see}`), et `docs/agents/domain.md` (format des ADR).

**Fichiers :**
- Modifier : `docs/adr/0006-serveur-mcp.md`, `docs/adr/0004-assistance-ia.md`
- Modifier (docblocks et commentaires seulement) : `backend/src/Mcp/Infrastructure/DependencyInjection/TruthfulCapabilitiesPass.php`,
  `backend/src/Mcp/Infrastructure/Http/InboundMessageRefusalListener.php`, `backend/src/Mcp/Infrastructure/Log/SdkChannelRedactor.php`,
  `backend/src/Mcp/Presentation/Controller/McpEndpointController.php`, `backend/src/Mcp/Presentation/Prompt/PromptRole.php`,
  `backend/src/Mcp/Presentation/Prompt/PresentForRolePrompt.php`, `backend/src/Mcp/Connection/Application/InvalidChallengeDescriptionException.php`,
  `backend/tests/Mcp/NoAnswerIsAStreamTest.php`, `backend/tests/Mcp/Presentation/Tool/SecuritySchemesTest.php`,
  `backend/tests/Mcp/Infrastructure/Http/InboundMessageClassificationTest.php`, `backend/tests/Mcp/Infrastructure/Http/InboundMessageRefusalTest.php`,
  `backend/tests/Mcp/Infrastructure/Log/SdkChannelRecordsTest.php`, `backend/tests/Mcp/Presentation/Prompt/PresentForRolePromptTest.php`
- Modifier : `.claude/rules/mcp.md` (mentions restantes)

- [ ] **Étape 1 : inventaire**

Run : `git grep -n -E '0\.8\.1|0\.14\.1|0\.9\b|0\.15\b' -- backend/src backend/tests/Mcp .claude/rules docs/adr ':!backend/tests/Shared'`
Pour chaque occurrence : au présent → « la version épinglée (composer.json) » si le fait tient en 0.9 (le vérifier dans
`vendor/`), corrigé sinon ; historique → gardé. Faits à vérifier, au minimum : le lot de 100 messages
(`MessageFactory::DEFAULT_MAX_BATCH_SIZE`, toujours 100) et les révisions négociées (`InboundMessageRefusalListener`) ;
la déduction des capacités (`TruthfulCapabilitiesPass` : la 0.9 retire `listChanged` à l'`initialize` mais annonce
toujours `logging`, et le bundle 0.15 n'a toujours pas d'option de capacités du serveur — la passe reste nécessaire,
pour `logging`) ; le 405 du GET par le transport (`McpEndpointController`, toujours vrai en 0.9) ; `server/discover`
sans outil (`SecuritySchemesTest`) ; aucune extension activée par le bundle (`NoAnswerIsAStreamTest`, `ModernExchange`).

- [ ] **Étape 2 : l'ADR 0006**

En-tête « Statut » : ajouter « et le 2026-11-10 (D7 : montée en `symfony/mcp-bundle` 0.15.0 et `mcp/sdk` 0.9.0,
#731) ». D3 : « `Mcp\Schema\Tool` 0.8.1 ne connaît pas ce champ » reste historique, compléter « (ni la 0.9, qui ajoute
`execution`) ». D4 : « la phase 2 n'attend pas un bundle compatible avec `mcp/sdk` 0.9 » → confirmé à la montée
(rien de la phase 2 n'en dépend ; les middlewares d'autorisation refondus de la 0.9 restent inutilisés). D7 : nouvel
encadré *Amendé le 2026-11-10 (#731)*, sur le modèle de l'amendement de l'ADR 0004 D1 du 2026-11-06 : les deux paquets
en version exacte, la raison (avis GHSA-f4rf-6ghj-mhr6 sur `mcp/sdk` 0.8.x, et la note de recherche) et les décisions
du 2026-11-06 qui en découlent, une phrase chacune avec son renvoi à la règle : `allowed_origins` par variable
(`MCP_ALLOWED_ORIGIN`) ; 503 au GET ; session trop grosse servie, son identifiant inexistant ; borne en file retirée,
lien session ↔ connexion gardé en défense en profondeur ; journal de l'ère sans session sur les événements du SDK,
`durationMs` du message ; validation du `structuredContent` (effet sur D1) ; hors périmètre : verrou de session (#762),
1.0 (#763). La dernière phrase de l'amendement du 2026-11-06 (« Les versions ne changent pas ici … ») reste, elle date
de cet amendement. `docs/adr/0004-assistance-ia.md`, lignes qui citent `symfony/mcp-bundle` 0.14.1 et `mcp/sdk` 0.8.1 :
ajouter « (montés en 0.15.0 et 0.9.0 le 2026-11-10, ADR 0006 D7) ».

- [ ] **Étape 3 : vérifier**

Run : `docker compose exec -T -u dev backend php bin/phpunit tests/Shared/CommentedClassNamesExistTest.php tests/Shared/ImportsStaySortedTest.php`
et `bash tools/check-claude-rules.sh` (règle ou CLAUDE.md sous 50 000 octets ; `mcp.md` en est proche : si la tâche le
fait dépasser, le découper par sous-domaine selon la règle d'index de `.claude/CLAUDE.md`).
Attendu : vert.

- [ ] **Étape 4 : commit**

```bash
git add docs/adr/0006-serveur-mcp.md docs/adr/0004-assistance-ia.md .claude/rules/mcp.md
# puis un `git add` nommé de chaque fichier de la liste « Modifier (docblocks et commentaires
# seulement) » que l'étape 1 a changé, chemins copiés de cette liste (jamais -A ni .)
git commit -F - <<'EOF'
docs(mcp): l'ADR 0006 D7 amendée pour la montée 0.15/0.9, versions au présent renvoyées au pin (#731)

D7 amendée le 2026-11-10 (montée, avis, décisions du 2026-11-06), D4
confirmée, l'ADR 0004 renvoie aux nouvelles versions. Dans le code, une
affirmation au présent sur le SDK ou le bundle renvoie à la version
épinglée ; les faits historiques gardent leur version.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt
EOF
```

---

### Tâche 9 : exposition à l'avis, notes de release client, vérification complète

**Modèle conseillé :** opus — non sensible en écriture, mais un raisonnement de sécurité (l'exposition).

**Règles à lire avant d'écrire (outil `Read`) :** `.claude/rules/mcp.md`, `.claude/rules/mcp-oauth.md` (les points
OAuth du projet, servis par league, pas par le SDK), `.claude/rules/deploiement-nginx.md` (borne de corps de
`/api/mcp`).

**Fichiers :**
- Créer : `tasks/pr-731-mcp-0-15.md` (brouillon du corps de la PR, en français ; **pas** `RELEASE_NOTES.md`, réservé
  aux branches `release/*`)

- [ ] **Étape 1 : l'exposition à GHSA-f4rf-6ghj-mhr6**

L'avis : `OAuthProxyMiddleware` (`POST /token`) et `ClientRegistrationMiddleware` (`POST /register`) du SDK lisent leur
corps sans borne, avant la borne de 4 MiB du transport ; condition : l'un d'eux câblé avant le transport. Vérifier, et
citer chaque lecture :
- le bundle 0.14.1 n'en référence aucun : `gh api 'repos/symfony/ai/contents/src/mcp-bundle/src/Http/MiddlewareFactory.php?ref=v0.14.1' --jq .content | base64 -d`
  (il ne remplace que `DnsRebindingProtectionMiddleware` dans `StreamableHttpTransport::defaultMiddleware()`) et une
  recherche des deux noms dans l'arbre du tag (`gh api 'repos/symfony/ai/git/trees/v0.14.1?recursive=1'`, puis le
  contenu de `src/mcp-bundle/config/services.php` et `src/McpBundle.php` à ce tag) ;
- `defaultMiddleware()` du SDK 0.8.1 ne les contient pas : `gh api 'repos/modelcontextprotocol/php-sdk/contents/src/Server/Transport/StreamableHttpTransport.php?ref=v0.8.1' --jq .content | base64 -d` ;
- le projet n'en câble aucun : `git log -p --all -S OAuthProxyMiddleware -- backend/src backend/config` et
  `git log -p --all -S ClientRegistrationMiddleware -- backend/src backend/config` vides ; `McpEndpointController`
  n'utilise que `MiddlewareFactory::create()` ou `defaultMiddleware()` puis `handshakeMiddleware()` ;
- les points OAuth du projet (`/api/oauth/*`) sont servis par `league/oauth2-server`, hors SDK (ADR 0006 D3), et nginx
  borne le corps de `/api/mcp` à 16 Kio (`deploiement-nginx.md`).
Conclusion attendue (vérifiée sur la 0.14.1 le jour du plan) : avis présent dans le verrou, **non exploitable** ici ;
la montée lève l'alerte de `composer audit`.

- [ ] **Étape 2 : le brouillon du corps de PR**

`tasks/pr-731-mcp-0-15.md`, sections : **Résumé** (la montée, `Closes`-sans-effet : #731 se ferme à la main après la
fusion vers la mère) ; **Décision par paquet** (montés, et pourquoi : note §1, §7) ; **Exposition à l'avis** (étape 1,
chaque source citée) ; **Ce qui change pour un client MCP** (note §5, mis à jour de ce que la PR a décidé : 1 `Origin`
schéma et port compris, l'origine du site admise par `MCP_ALLOWED_ORIGIN`, MCP Inspector et pages tierces en 403 ;
2 CORS : `Mcp-Method`, `Mcp-Name`, `Mcp-Param-*` autorisés, `WWW-Authenticate` exposé ; 3 store injoignable : 503
`-32603` au POST **et au GET** ; 4 session trop grosse : réponse servie, identifiant inexistant, 404 ensuite ; 5 sortie
non conforme : erreur d'outil ; 6 `Mcp-Param-*` sans argument miroir : `-32020` ; 7 `completion/complete` incomplet :
refusé ; 8 schéma d'entrée mal formé refusé côté serveur ; 9 inchangé) ; **Notes de déploiement** (nouvelle variable
`MCP_ALLOWED_ORIGIN` posée par les overlays dans `backend-config`, appliquée par `apply -k` avant le rollout ; aucune
migration ; à vérifier en préprod que claude.ai, ChatGPT et Claude Code n'envoient pas d'`Origin` refusé, et lire les
403 « Invalid Origin header » la première semaine) ; **Hors périmètre** (#762, #763, les 6 `config.unknown_key`) ;
**Vérifications** (étape 3, sorties résumées).

- [ ] **Étape 3 : vérification complète**

Run, dans l'ordre :
- `docker compose exec -T -u dev backend composer audit` → aucun avis ;
- `make back-test` → vert ;
- `make back-quality` → PHPStan `[OK]`, Rector sans diff, Psalm sans erreur, `lsp:check` : **exactement 6**
  avertissements `config.unknown_key`, tous sur `ai.yaml`, aucun bloquant, et `config/reference.php` inchangé par le
  passage (sinon le régénérer et le committer avec la tâche 1 par un commit de correction) ;
- `bash tools/check-claude-rules.sh` → vert ;
- `git grep -n -E 'QueuedAnswer|QUEUED_ANSWER_MAX_BYTES' -- . ':!tasks/' ':!docs/research/' ':!.claude/specs/archive/'` → vide.

- [ ] **Étape 4 : commit**

```bash
git add tasks/pr-731-mcp-0-15.md
git commit -F - <<'EOF'
docs(plan): brouillon du corps de la PR 2 de #731, exposition à l'avis et notes client (#731)

L'exposition à GHSA-f4rf-6ghj-mhr6 vérifiée (aucun des deux middlewares
OAuth du SDK câblé, ni par le bundle 0.14.1 ni par le projet) et ce qui
change pour un client MCP, pour le corps de la PR (pas RELEASE_NOTES.md,
réservé aux branches release/*).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt
EOF
```
