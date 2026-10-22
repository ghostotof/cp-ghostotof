# Autorisation différée de l'outil nominatif : Claude et ChatGPT (issue #646, question 2)

- **Issue** : #646 (spec 0007, tâche 7, phase 2 du serveur MCP, ADR 0006 D3)
- **Date** : 2026-10-22
- **Statut** : **note de recherche, recommandation validée par l'auteur le 2026-10-22**, telle quelle. La
  décision est écrite dans l'ADR 0006 (D3, amendement du 2026-10-22) et dans la spec 0007.
- **Question** (spec 0007, « Questions ouvertes », n° 2) : l'ADR 0006 D3 prévoit deux mécanismes pour
  déclencher la connexion MCP à l'appel de `get_nominative_corpus` sans jeton : un 401 HTTP avec
  `WWW-Authenticate` pour Claude, une erreur d'outil portant `_meta["mcp/www_authenticate"]` pour ChatGPT. Le
  401 précède le résultat JSON-RPC : les deux ne peuvent pas répondre au même appel. Comment chaque client
  réagit-il à l'autre réponse, et un critère fiable, non déclaratif, les départage-t-il ?

Mêmes conventions que la note sœur `2026-10-22-mcp-decouverte-oauth.md` : sources `[n]`, **[secondaire]**,
**[mesuré]** (expérience de sa section 3, même laboratoire), **[déduit]**, confiance par conclusion.

---

## 0. Synthèse

| Constat | Confiance |
|---|---|
| La spécification MCP (2025-11-25 et 2026-07-28) et l'extension officielle MCP Apps placent l'autorisation **à la frontière HTTP** : un 401 sur la requête, quelle qu'elle soit. Aucun schéma d'authentification par outil n'existe dans le cœur : SEP-1488 (`securitySchemes`) est un brouillon, SEP-1489 (`_meta["mcp/www_authenticate"]`) a été **fermée** le 2026-06-26 au profit du 403 `insufficient_scope`. | élevée [1][2][3][4] |
| **Claude** (claude.ai, Desktop, Claude Code) ne déclenche la connexion que sur un **401 HTTP** avant le SDK ; un 200 `isError` portant `_meta["mcp/www_authenticate"]` ne déclenche rien, le modèle n'en voit que le texte. Les outils publics restent servis après le 401. | élevée [5], [mesuré] |
| **ChatGPT** documente le contraire : `securitySchemes` sur chaque outil **et** un résultat d'erreur portant `_meta["mcp/www_authenticate"]` (avec `error` et `error_description`), « without both halves ChatGPT will not show the linking UI ». Son support déconseille de compter sur un 401 seul ; un témoignage d'octobre 2026 montre pourtant une carte « Update access » sur un 401. | élevée sur la doc [6], faible sur le 401 [secondaire] [9] |
| **Aucun critère fiable, non déclaratif et commun** ne distingue les deux clients par le contenu MCP. Les seuls critères non déclaratifs sont réseau ou TLS (certificat client mTLS d'OpenAI, plages d'IP publiées), propres à un fournisseur et coûteux. | élevée [6][7] |
| Mais ce choix **ne décide d'aucun accès** : les deux formes refusent sans jeton. Un critère déclaratif suffit donc à choisir la forme du refus, tant que l'accès reste décidé par le jeton seul. | élevée [déduit] |

**Recommandation** (section 4) : le 401 par défaut, l'erreur d'outil au format ChatGPT seulement quand la
requête porte les marqueurs `_meta["openai/…"]` que ChatGPT envoie, et `securitySchemes` déclarés sur les
outils. À vérifier en préprod contre les deux clients (tâche 14).

---

## 1. Ce que dit la spécification

- L'autorisation porte sur **chaque requête HTTP** : « authorization MUST be included in every HTTP request
  from client to server » (2026-07-28 ; 2025-11-25 ajoute « even if they are part of the same logical
  session ») [1]. Le client « MUST be able to parse `WWW-Authenticate` headers and respond appropriately to
  `HTTP 401 Unauthorized` responses » [1]. **[déduit]** Rien ne limite le 401 à l'ouverture : un 401 sur un
  `tools/call` précis est conforme. Confiance élevée.
- Le serveur **SHOULD** mettre un `scope` dans le défi, et le client le traite comme faisant autorité pour
  l'opération en cours [1]. Un jeton valide au scope insuffisant reçoit un **403** `insufficient_scope` ; le
  client redemande l'union des scopes [1]. **[déduit]** Ici, un seul scope (`nominative:read`) : le 403 n'arrive
  que si un jeton valide sans ce scope peut exister, ce que la tâche 8 peut exclure. Confiance élevée.
- 2026-07-28 permet de faire varier la liste d'outils selon l'autorisation [2]. **[déduit]** Masquer l'outil
  nominatif à l'appelant anonyme supprimerait l'appel qui déclenche la connexion ; ChatGPT fige en plus la liste
  d'outils après approbation [6]. L'ADR 0006 D3 (« toujours listé ») tient. Confiance moyenne.
- 2026-07-28 rend obligatoires les en-têtes `Mcp-Method` et `Mcp-Name` et le refus d'une discordance avec le
  corps [2]. Le 401 du site lit déjà l'outil sous ses deux formes, comme le journal (spec 0007, amendement du
  2026-10-20, #664). Confiance élevée.
- Pas de schéma d'authentification par outil dans le type `Tool` du cœur [2]. SEP-1488, « securitySchemes in
  Tool Metadata for Mixed-Auth Servers », rédigée par OpenAI : ouverte, brouillon. SEP-1489, « Tool Error
  Responses for Triggering OAuth Flows » : fermée le 2026-06-26, « Closing as #835 landed for step-up auth » [3].
- L'extension officielle **MCP Apps** décrit l'autorisation par outil : « If a protected tool is being called and
  no valid Bearer token is present, the server returns HTTP 401 with a WWW-Authenticate header pointing to its
  Protected Resource Metadata », et « authorization is enforced at the HTTP boundary (as required by the spec),
  not as a tool-level error » [4]. Confiance élevée.

## 2. Ce que font les clients

### 2.1 Claude

- Serveur mixte **pris en charge et documenté** (« lazy authentication ») : connexion, liste et outils publics
  sans compte, connexion demandée seulement à l'appel de l'outil qui l'exige [5]. Confiance élevée.
- « Claude starts sign-in only when the HTTP request itself fails with `401 Unauthorized` and a
  `WWW-Authenticate` header. A tool handler can't produce that response … The check has to run in your HTTP
  handler, on the parsed JSON-RPC body, before the request reaches the SDK. » [5] C'est la forme que la spec 0007
  a retenue (« renvoyé avant le SDK »).
- claude.ai affiche une carte **Connect** dans la conversation, puis rejoue l'appel avec le nouveau jeton [5].
- « A `200` with `isError: true` is an application-level tool failure. Claude passes the error text to the model
  as the tool result and moves on, and there is no auth prompt. » [5] Confiance élevée.
- Claude Code [8], [mesuré] : après un 401 sur l'outil protégé, le modèle reçoit `MCP server "<nom>" needs you to
  sign in again (run /mcp to re-authenticate)`. Pas de flux dans la conversation : la personne lance `/mcp` ou
  `claude mcp login`. Mesuré le 2026-10-22, en 2025-11-25 comme en 2026-07-28 : la découverte part aussitôt du
  pointeur `resource_metadata`, et **un outil public appelé après le 401 répond encore** (la session n'est pas
  coupée).
- Claude Code devant l'erreur au format ChatGPT [mesuré] : aucune requête de découverte, le modèle rapporte le
  texte de l'erreur. Le `_meta` est ignoré.
- Défauts ouverts **[secondaire]** [10] : la détection du mode à l'ajout d'un connecteur (#1013, #1064) ; le
  second a été réparé côté serveur en servant correctement `server/discover` (2026-07-28), ce que le site fait.
  Confiance moyenne.

### 2.2 ChatGPT

- « Triggering the tool-level OAuth flow requires both metadata (`securitySchemes` and the resource metadata
  document) **and** runtime errors that carry `_meta["mcp/www_authenticate"]`. Without both halves ChatGPT will
  not show the linking UI for that tool. » [6]
- `securitySchemes` par outil : `noauth` (appelable sans compte), `oauth2` (avec ses scopes) ; les deux à la
  fois signifient « anonyme, liaison facultative » [6]. La référence définit aussi `_meta["securitySchemes"]`,
  un « back-compat mirror for clients that only read `_meta` » [7].
- L'erreur : un résultat `isError: true` dont `_meta["mcp/www_authenticate"]` contient un défi RFC 7235 avec
  `error` et `error_description`, par exemple `Bearer resource_metadata="…", error="insufficient_scope",
  error_description="…"` [6][7]. Confiance élevée.
- Sur le 401, la documentation dit seulement qu'il « tells the client to run the OAuth flow again » [6], à propos
  d'un jeton refusé. **[secondaire]** [9] : un fil du forum (février-mars 2026) rapporte qu'un 401 en cours de
  conversation ne relance pas OAuth, et le support d'OpenAI répond « I would not rely on a bare HTTP 401
  WWW-Authenticate response alone » ; un autre (septembre 2026) rapporte un `-32603` ; un commentaire d'octobre
  2026 (claude-ai-mcp #1064) montre au contraire, sur un serveur à 401, une carte « Update access ». Confiance
  faible.
- La liste d'outils est un instantané figé à l'approbation, rafraîchi à la main [6][9]. **[déduit]** Les
  `securitySchemes` doivent donc être là dès la première publication du connecteur.
- Step-up défaillant chez ChatGPT (openai-apps-sdk-examples #244, ouverte) **[secondaire]** [9] : sans effet ici,
  un seul scope.

## 3. Départager les deux clients

| Critère | Non déclaratif ? | Couvre | Coût |
|---|---|---|---|
| Certificat client mTLS d'OpenAI (« ChatGPT presents an OpenAI-managed client certificate when connecting to MCP servers ») [6] | oui | ChatGPT seul | Terminaison TLS avec vérification facultative sur ingress-nginx (annotations `auth-tls-*`, objet dédié), résultat transmis au backend ; derrière le Load Balancer en PROXY protocol. Lourd, et lié à ingress-nginx. |
| Plages d'IP de sortie publiées (Anthropic `160.79.104.0/21`, OpenAI) [5][6] | oui, l'IP étant fiable ici (PROXY protocol, `real_ip`, ADR 0005) | les applications hébergées, **pas Claude Code** (poste de la personne) | Listes à tenir à jour, qui changent |
| Marqueurs `_meta["openai/…"]` (`openai/subject`, `openai/session`…) sur les appels d'outils [7] | **non** : n'importe qui peut les envoyer ; OpenAI : « servers should never rely on them for authorization decisions » | ChatGPT | nul |
| `clientInfo`, `User-Agent`, révision du protocole, capacités | non | — | — |

**Aucun critère fiable, non déclaratif et commun n'existe.** Confiance élevée.

**[déduit]** La question perd pourtant son enjeu de sécurité : la distinction ne choisit que la **forme** du
refus, jamais l'accès. Sans jeton valide, l'outil refuse dans les deux branches ; un client qui imite ChatGPT
reçoit un refus sous une autre forme, rien de plus. Un critère déclaratif suffit, à une condition : l'ouverture
du corpus reste décidée par l'authentificateur Bearer seul (ADR 0006 D4), jamais par le marqueur. Le coût d'une
erreur de classement est une connexion qui ne se propose pas d'elle-même : la personne voit le texte de
l'erreur, qui doit donc dire quoi faire. Confiance élevée sur le raisonnement.

## 4. Recommandation

1. **Le 401 par défaut**, avant le SDK, sur l'appel de `get_nominative_corpus` sans jeton (ou au jeton refusé),
   dans les deux ères : `WWW-Authenticate: Bearer resource_metadata="https://<site>/.well-known/oauth-protected-resource/api/mcp", scope="nominative:read"`,
   plus `error="invalid_token"` quand un jeton présenté est refusé (RFC 6750 §3.1 : pas de code d'erreur quand
   aucun jeton n'est présenté). C'est la forme de la spécification, de MCP Apps et de Claude.
2. **L'erreur d'outil au format ChatGPT** quand la requête porte au moins une clé `_meta` préfixée `openai/` :
   un 200 `isError: true`, `_meta["mcp/www_authenticate"]` = le même défi complété de `error="insufficient_scope"`
   et d'un `error_description`, un texte qui dit à la personne de connecter son compte. **[mesuré dans le
   code]** Le SDK 0.8.1 le permet sans contournement : `CallToolResult::error($content, $meta)` porte un
   `_meta`, et l'outil reçoit le `RequestContext`, donc le `_meta` de la requête. Le partage devient : le point
   avant le SDK rend le 401, **sauf** pour une requête marquée `openai/`, qu'il laisse passer ; l'outil, qui lit
   l'identité par `Security` seul, rend alors cette erreur.
3. **`securitySchemes` sur chaque outil** : `[{"type": "noauth"}]` pour les outils publics,
   `[{"type": "oauth2", "scopes": ["nominative:read"]}]` pour l'outil nominatif, et le miroir
   `_meta["securitySchemes"]`. **[mesuré dans le code]** Le SDK 0.8.1 ne connaît pas de champ `securitySchemes`
   au premier niveau d'un outil (`Mcp\Schema\Tool`), seulement `_meta` (`#[McpTool(meta: …)]`). Le miroir se
   déclare donc sans rien changer ; le champ de premier niveau demanderait de décorer la réponse de `tools/list`.
   Dans les deux cas, la garde D8 et les tests de catalogue lisent ces valeurs. À trancher à la tâche 9 selon ce
   que ChatGPT lit vraiment (préprod).
4. **Ni mTLS ni plages d'IP** : un coût d'infrastructure pour un critère qui ne décide d'aucun accès.
5. **L'accès reste au seul jeton** (ADR 0006 D4) : un test fixe que les marqueurs `openai/` ne changent que la
   forme du refus, jamais l'ouverture du corpus.
6. **Vérification en préprod (tâche 14)** : la carte Connect de claude.ai sur le 401, `/mcp` dans Claude Code,
   l'écran de liaison de ChatGPT sur l'erreur d'outil ; et ce que ChatGPT fait d'un 401 (si sa carte « Update
   access » se confirme, la branche ChatGPT peut disparaître).

Confiance de l'ensemble : élevée pour Claude, moyenne pour ChatGPT, dont la documentation et les témoignages
divergent.

## 5. Ce que la décision change aux tâches suivantes

- **Tâche 8 (#647)** : métadonnées aux deux routes hors `/api` de la note sœur (entrées `NON_API_PATHS`,
  firewall, `access_control`, objet Ingress dédié) ; RFC 9207 (`iss` dans la réponse d'autorisation,
  `authorization_response_iss_parameter_supported`) pour le `client_id` stable de ChatGPT ; redirection de
  boucle locale comparée sans le port (RFC 8252 §7.3), que Claude Code exige ; pas de `registration_endpoint`.
  Le client pré-enregistré de la piste traçante peut être Claude Code (`claude mcp add … --client-id`, et
  `--callback-port` si la redirection enregistrée porte un port).
- **Tâche 9 (#648)** : les deux formes du refus (section 4, points 1 et 2), dans les deux ères ;
  `securitySchemes` (point 3) ; le test du point 5.
- **Tâche 12 (#651)** : la liste de domaines CIMD contient au moins `claude.ai` (Claude Code et les applications
  hébergées) et `chatgpt.com` ; Claude ne choisit CIMD que si les deux valeurs de la note sœur sont publiées.
- **Tâche 14 (#653)** : l'objet Ingress de la préprod sans Basic Auth porte aussi les deux chemins
  `.well-known` ; la vérification relève les URL demandées et l'interface de connexion de chaque client.
- **Page « Brancher votre assistant »** (tâche 14) : dans Claude Code, après le premier refus, lancer `/mcp` puis
  « Authenticate » (ou `claude mcp login <nom>`).

## Sources

Consultées le 2026-10-22.

1. MCP 2026-07-28, Authorization — https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization ;
   2025-11-25 — https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
2. MCP 2026-07-28, Tools — https://modelcontextprotocol.io/specification/2026-07-28/server/tools ; Streamable
   HTTP — https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http
3. SEP-1488 — https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1488 ; SEP-1489 —
   https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1489
4. MCP Apps, Authorization — https://apps.extensions.modelcontextprotocol.io/api/documents/authorization.html
5. Claude, « Lazy authentication » — https://claude.com/docs/connectors/building/lazy-authentication ;
   « Authentication » — https://claude.com/docs/connectors/building/authentication
6. OpenAI, Apps SDK, « Authentication » — https://developers.openai.com/apps-sdk/build/auth ; « Connect and
   test » — https://developers.openai.com/apps-sdk/deploy/connect-chatgpt
7. OpenAI, Apps SDK, référence — https://developers.openai.com/apps-sdk/reference
8. Claude Code, MCP — https://code.claude.com/docs/en/mcp
9. **[secondaire]** Forum OpenAI : « ChatGPT does not re-trigger OAuth on 401 + WWW-Authenticate for MCP tool
   calls » (https://community.openai.com/t/1374168), fils 1400778 et 1370765 ; openai-apps-sdk-examples #183,
   #202, #244 (https://github.com/openai/openai-apps-sdk-examples)
10. **[secondaire]** Issues https://github.com/anthropics/claude-ai-mcp : #1013, #1064, #1139
