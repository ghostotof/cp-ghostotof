# Découverte des métadonnées OAuth du serveur MCP derrière l'Ingress (issue #646, question 1)

- **Issue** : #646 (spec 0007, tâche 7, phase 2 du serveur MCP, ADR 0006)
- **Date** : 2026-10-22
- **Statut** : **note de recherche, recommandation validée par l'auteur le 2026-10-22**, telle quelle. La
  décision est écrite dans l'ADR 0006 (D3, amendement du 2026-10-22) et dans la spec 0007.
- **Question** (spec 0007, « Questions ouvertes », n° 1) : RFC 8414 et RFC 9728 placent leurs documents sous
  `/.well-known/…` à la racine du domaine, alors que l'Ingress n'envoie au backend que `/api`. Où publier les
  métadonnées pour que Claude et ChatGPT trouvent le serveur d'autorisation ?

Conventions : chaque affirmation externe renvoie à une source numérotée `[n]` (section « Sources »), primaire
sauf mention **[secondaire]**. **[mesuré]** marque ce qui a été observé dans l'expérience de la section 3,
**[déduit]** ce qui est raisonné sans être lu ni mesuré. Chaque conclusion porte une **confiance** (élevée,
moyenne, faible). Le domaine de production s'écrit `<site>`.

---

## 0. Synthèse

| Constat | Confiance |
|---|---|
| La prémisse de la spec est à corriger : le frontend répond déjà **404** (pas 200) sur tout `/.well-known/…` absent, grâce à la location `^~ /.well-known/` de `docker/node/nginx.conf` (posée pour `security.txt`). Vérifié en prod le 2026-10-22. La piste « 404 servi par le frontend » est donc **déjà en place**, et elle est indispensable : un 200 HTML à ces adresses arrête la découverte. | élevée [mesuré] |
| Le pointeur `resource_metadata` du 401 peut désigner n'importe quelle URL : Claude le documente précisément pour les plateformes qui ne routent qu'un préfixe. | élevée [3] |
| Les métadonnées du **serveur d'autorisation**, elles, ne passent pas par un pointeur : le client les cherche à des URL dérivées de l'émetteur. Pour un émetteur `https://<site>/api/oauth`, la seule URL qui tombe sous `/api` est la variante OIDC « ajoutée en fin de chemin » (`/api/oauth/.well-known/openid-configuration`), que Claude Code et le SDK TypeScript valident comme un **document OIDC complet** (`jwks_uri`, `id_token_signing_alg_values_supported`…) : un document RFC 8414 y est refusé. | élevée [mesuré], [8] |
| Un client qui n'a pas encore reçu de 401 (`claude mcp login` à froid, ajout d'un connecteur) cherche à la racine, puis retombe sur le comportement de la révision 2025-03-26 : `/authorize`, `/token`, `/register` **sur l'origine**, servis ici par la SPA. | élevée [mesuré], [4] |
| Servir les deux documents aux URL canoniques `/.well-known/oauth-protected-resource/api/mcp` et `/.well-known/oauth-authorization-server/api/oauth` fait aboutir **tous** les cas mesurés, à froid compris, avec un document RFC 8414 pur. Ce sont aussi les seules formes que la documentation de ChatGPT décrit. | élevée pour Claude Code [mesuré], moyenne pour claude.ai et ChatGPT [3][10] |

**Recommandation** (section 5) : deux règles d'Ingress `Exact` vers le backend, dans un objet Ingress dédié
(le schéma de `cp-ghostotof-healthz`), émetteur `https://<site>/api/oauth`, `resource_metadata` présent dans
chaque 401 et pointant sur l'URL canonique, aucun document OIDC. À confirmer en préprod contre claude.ai et
ChatGPT (tâche 14), dans les journaux d'accès.

---

## 1. La situation du site

- L'Ingress (`k8s/base/ingress.yaml`) a deux règles `Prefix` : `/api` vers `backend`, `/` vers `frontend`.
- Le frontend (`docker/node/nginx.conf`) sert `^~ /.well-known/` par `try_files $uri =404` : un 404 HTML pour
  tout fichier absent. Les autres chemins inconnus reçoivent `index.html` en 200 (routage de la SPA).
- Sous `/api`, un chemin inconnu reçoit un 404 `application/problem+json` du backend.
- Mesuré en prod le 2026-10-22 : `/.well-known/oauth-protected-resource` → 404 `text/html` ;
  `/.well-known/oauth-authorization-server/api` → 404 `text/html` ; `/api/.well-known/openid-configuration` →
  404 `application/json` ; `/foo/bar` → 200 `text/html` ; `/.well-known/security.txt` → 200.
- Précédent dans le dépôt : `k8s/overlays/preprod/ingress-healthz.yaml` sort un chemin `Exact` de l'Ingress
  principal par un **objet Ingress distinct** (ingress-nginx applique les annotations à tout un objet, et un
  chemin `Exact` l'emporte sur le `Prefix` `/`). La phase 2 en demande un de toute façon : l'ADR 0006 lève
  Basic Auth en préprod sur les seuls chemins MCP et OAuth.

## 2. Ce que disent les spécifications

### 2.1 Métadonnées de la ressource protégée (RFC 9728)

- **MCP 2026-07-28**, révision en vigueur [1] : le serveur met en œuvre **l'un** des deux mécanismes, soit le
  pointeur `resource_metadata` dans le `WWW-Authenticate` de ses 401, soit un document well-known, « at the
  path of the server's MCP endpoint » (`/.well-known/oauth-protected-resource/<chemin>`) ou à la racine [2].
  Le client prend le pointeur s'il existe, sinon essaie le well-known avec chemin, puis la racine [2].
  Identique en 2025-11-25 [5]. En 2025-06-18 le pointeur était obligatoire [6]. Confiance élevée.
- **RFC 9728** §3 fait pourtant du document well-known (chemin inséré entre l'hôte et le chemin) un MUST pour
  toute ressource qui publie des métadonnées ; MCP n'en fait qu'une option. Un serveur qui ne sert le document
  qu'au bout du pointeur est conforme à MCP, pas à la lettre de la RFC [7]. Confiance élevée.
- RFC 9728 §5.1 ne contraint pas l'emplacement de l'URL `resource_metadata` [7]. §3.3 exige que `resource`
  soit **identique** à l'URL que le client a appelée : `https://<site>/api/mcp`, à l'octet près [7]. Confiance
  élevée.

### 2.2 Métadonnées du serveur d'autorisation (RFC 8414, OIDC Discovery)

- **MCP 2026-07-28** [2] : pour un émetteur **avec** chemin, le client essaie, dans cet ordre :
  1. `/.well-known/oauth-authorization-server/<chemin>` (RFC 8414, insertion) ;
  2. `/.well-known/openid-configuration/<chemin>` (OIDC, insertion) ;
  3. `/<chemin>/.well-known/openid-configuration` (OIDC Discovery 1.0, ajout en fin de chemin).

  Sans chemin : `/.well-known/oauth-authorization-server`, puis `/.well-known/openid-configuration`. **Aucun
  repli vers la racine** pour un émetteur à chemin. L'`issuer` du document doit être identique à l'émetteur qui
  a servi à construire l'URL, sinon le client n'utilise pas le document [2]. Confiance élevée.
- **RFC 8414** §3.3 : la même identité d'émetteur [9]. Un émetteur `https://<site>/api/oauth` ne peut donc pas
  être servi depuis `/.well-known/oauth-authorization-server` (racine), dont l'émetteur reconstruit serait
  `https://<site>`. Confiance élevée.
- **OIDC Discovery 1.0** [11] rend obligatoires `jwks_uri`, `response_types_supported`,
  `subject_types_supported` et `id_token_signing_alg_values_supported` (avec `RS256`). La variante 3 est
  explicitement celle d'OIDC : un client a le droit de valider son document comme un document OIDC. Confiance
  élevée.
- **MCP 2025-03-26** [4], que le serveur négocie encore : la base d'autorisation est l'URL MCP **sans son
  chemin**, et sur un 404 des métadonnées le client utilise `/authorize`, `/token`, `/register` à la racine.
  Chez nous, la SPA. Confiance élevée.
- La spécification ne dit pas ce que « not found » recouvre (404 seul, tout 4xx, 200 non JSON) [2]. Le SDK
  TypeScript officiel passe à l'URL suivante sur tout 4xx (et 502), et **échoue** sur un 200 non JSON ou un JSON
  qui ne passe pas son schéma [8]. Confiance élevée (code lu).

### 2.3 Ce que documentent les clients

- **Claude** (claude.ai, Desktop, mobile et Claude Code partagent la même infrastructure d'authentification [3]) :
  - « The `resource_metadata` URL doesn't have to be on the MCP server's origin. It can be any HTTPS location
    that serves the JSON document, which makes this the most reliable path on serverless or edge platforms that
    only route requests under a path prefix and can't serve `/.well-known/*` at the root. » [3] Confiance élevée.
  - Sans pointeur, il sonde `/.well-known/oauth-protected-resource/<chemin MCP>`, puis la racine [3].
  - Seule la **première** entrée de `authorization_servers` est lue [3].
  - CIMD n'est choisi que si les métadonnées publient `"client_id_metadata_document_supported": true` **et**
    `"none"` dans `token_endpoint_auth_methods_supported`, sinon Claude bascule sur DCR [3] — qui échouerait
    ici (pas de DCR, ADR 0006 D3). Confiance élevée.
  - Un 404 fait passer de RFC 8414 à OIDC [12]. Pour un émetteur à chemin, la documentation ne décrit pas
    l'algorithme ; une réponse d'Anthropic (claude-ai-mcp #82, mai 2026) dit que claude.ai essaie les variantes
    « path-aware », sans préciser si la variante 3 en fait partie **[secondaire]** [15]. Confiance moyenne.
  - Des issues ouvertes décrivent encore claude.ai qui, après l'échec d'une étape, construit `/authorize` sur
    l'origine (#716, #962, #1169) **[secondaire]** [15]. Confiance moyenne.
- **ChatGPT** [10] : un document à `/.well-known/oauth-protected-resource` (ou la même URL annoncée dans un 401) ;
  métadonnées de l'AS à `/.well-known/oauth-authorization-server` ou `/.well-known/openid-configuration`.
  **Exemples uniquement à la racine et sans chemin**, rien sur un émetteur à chemin. Il essaie toutes les entrées
  de `authorization_servers`, exige `S256` dans `code_challenge_methods_supported`, préfère CIMD
  (`client_id` stable `https://chatgpt.com/oauth/client.json` si le serveur respecte RFC 9207, c'est-à-dire
  publie `authorization_response_iss_parameter_supported: true` et renvoie `iss`). L'émetteur est comparé
  à l'octet près [10]. Confiance élevée sur le texte, faible sur le cas d'un émetteur à chemin.

## 3. Expérience avec Claude Code 2.1.303 (2026-10-22)

Protocole : un faux serveur MCP local (`docs/research/2026-10-22-mcp-oauth-labo/fake_mcp.py`) imite la
topologie du site : sous `/api` un 404 JSON, `/.well-known/*` racine en 404 HTML (le frontend), tout autre
chemin en 200 HTML (la SPA). Il journalise chaque requête. Un outil public et un outil protégé ; le protégé
répond, selon le scénario, un 401 avec `WWW-Authenticate: Bearer resource_metadata="<base>/api/.well-known/oauth-protected-resource", scope="nominative:read"`
ou un résultat d'erreur au format ChatGPT. Claude Code est lancé en `claude -p` (une session, le modèle
appelle les outils) et en `claude mcp login` (authentification seule). Aucune donnée du site, aucun secret.

| # | Scénario | Requêtes de découverte observées, dans l'ordre | Issue |
|---|---|---|---|
| 1 | Session ouverte sans jeton, **401 sur le `tools/call`** de l'outil protégé, AS à `/api/oauth` servi en RFC 8414 à la variante 3 | `GET /api/.well-known/oauth-protected-resource` (le pointeur) → `/.well-known/oauth-authorization-server/api/oauth` (404) → `/.well-known/openid-configuration/api/oauth` (404) → `/api/oauth/.well-known/openid-configuration` (200) | **Échec** : « malformed result that failed schema validation », `jwks_uri`, `subject_types_supported`, `id_token_signing_alg_values_supported` manquants |
| 2 | Même chose, document OIDC complet à la variante 3 | mêmes URL | Le modèle reçoit « needs you to sign in again (run /mcp to re-authenticate) » ; `claude mcp login` produit ensuite l'URL d'autorisation sur `/api/oauth/authorize` |
| 3 | `claude mcp login` **à froid** (aucun 401 reçu avant, crédits effacés), mêmes documents | `/.well-known/oauth-protected-resource/api/mcp` (404) → `/.well-known/oauth-protected-resource` (404) → `/.well-known/oauth-authorization-server` → `/.well-known/openid-configuration` → `/.well-known/oauth-authorization-server/api/mcp` → `/.well-known/openid-configuration/api/mcp` → `/api/mcp/.well-known/openid-configuration` (tous 404) → `POST /register` **à la racine** | **Échec** : repli 2025-03-26, DCR sur l'origine (chez nous, la SPA) |
| 4 | Racine `/.well-known/*` en **200 HTML** (ce que ferait la SPA sans la location du frontend) | `/.well-known/oauth-protected-resource/api/mcp` (200 HTML) → `/.well-known/oauth-authorization-server` (200 HTML) → `/.well-known/oauth-authorization-server/api/mcp` | **Échec**, découverte arrêtée, pas même le repli |
| 5 | `claude mcp login` à froid, documents servis aux **URL canoniques** : PRM à `/.well-known/oauth-protected-resource/api/mcp`, AS (**RFC 8414 pur**, sans champ OIDC) à `/.well-known/oauth-authorization-server/api/oauth` | ces deux URL, rien d'autre | **Réussite** : URL d'autorisation sur `/api/oauth/authorize` |

Observations complémentaires [mesuré] :

- Avec le vrai serveur (dev), Claude Code ouvre par un `server/discover` de la révision **2026-07-28** et la
  garde : aucun `initialize` (`mcp_usage` : `server/discover`, `tools/list`, `prompts/list`, `tools/call`).
  Rejoué sur le faux serveur en 2026-07-28 : même découverte, mêmes résultats.
- Le pointeur appris d'un 401 est mémorisé : un `claude mcp login` lancé après le scénario 1 part du pointeur ;
  après `claude mcp logout`, il repart de la racine (scénario 3).
- L'URL d'autorisation construite : `response_type=code`, `client_id=https://claude.ai/oauth/claude-code-client-metadata`
  (CIMD), `code_challenge_method=S256`, `redirect_uri=http://localhost:<port aléatoire>/callback`,
  `scope=nominative:read` (repris du défi), `resource=<base>/api/mcp`.
- Le document CIMD de Claude Code (lu le 2026-10-22) déclare `redirect_uris`
  `["http://localhost/callback", "http://127.0.0.1/callback"]`, **sans port**, `token_endpoint_auth_method`
  `none`, `grant_types` `authorization_code` et `refresh_token`. Le serveur doit donc comparer une redirection
  de boucle locale en ignorant le port (RFC 8252 §7.3), ce que la documentation de Claude dit aussi [3].

Limites : Claude Code seul. claude.ai et ChatGPT ne se testent que sur un serveur public, donc en préprod à la
tâche 14 (Basic Auth levée sur ces chemins) ; l'expérience ne dit rien de leur ordre réel.

## 4. Les trois pistes de la spec

### (C) 404 servi par le frontend sur `/.well-known/` — déjà en place, nécessaire, insuffisante

- Nécessaire : un 404 fait passer à l'URL suivante (Claude [12], SDK TS [8]) ; un 200 HTML arrête tout
  (scénario 4). **Ne jamais laisser la SPA répondre 200 sous `/.well-known/`.** Confiance élevée.
- Insuffisante seule : sans pointeur ni document, le client retombe sur `/authorize` à la racine (scénario 3).
  Confiance élevée.

### (A) Pointeur `resource_metadata` et émetteur à chemin sous `/api`, sans toucher à l'Ingress

- La moitié « ressource » marche : le pointeur est suivi (scénario 1), documenté par Claude [3]. Confiance
  élevée.
- La moitié « serveur d'autorisation » ne passe que par la variante 3, qui exige un **document OIDC complet**
  (scénarios 1 et 2, [8]). Il faudrait publier `jwks_uri`, `subject_types_supported` et
  `id_token_signing_alg_values_supported: ["RS256"]` pour un serveur qui n'émet aucun ID Token
  (`league/oauth2-server` n'en fait pas) : une **déclaration inexacte** dans un document public, et une clé JWKS
  à servir pour rien. **[déduit]** Elle resterait sans effet tant que le scope `openid` n'est jamais accordé,
  mais un client qui s'y fierait demanderait un ID Token jamais émis. Confiance élevée sur le constat.
- Elle échoue à froid (scénario 3) : l'ajout d'un connecteur ou un `claude mcp login` avant tout 401. claude.ai
  sonde la racine à l'ajout pour « détecter » l'authentification (claude-ai-mcp #925, #713) **[secondaire]** [15].
- Aucune source ne garantit que claude.ai ni ChatGPT essaient la variante 3 ; une issue suggère l'inverse pour
  claude.ai (#1103) **[secondaire]** [15]. Confiance faible.

### (B) Règles d'Ingress exactes vers le backend

- Les URL canoniques sont **les premières** que tout client conforme essaie, à chaud comme à froid ; un document
  RFC 8414 pur y suffit (scénario 5) ; ce sont les seules formes que décrivent les documentations de Claude et
  de ChatGPT [3][10]. Confiance élevée pour Claude Code, moyenne pour les autres.
- Le reste de `/.well-known/` (`security.txt`, le 404) n'est pas touché : `Exact` ne capte que ces deux URL.
- Coûts propres au dépôt **[déduit]** :
  - deux routes Symfony hors `/api` : deux entrées justifiées dans `RouterScopeTest::NON_API_PATHS`, avec leur
    firewall et leur règle `access_control` (le contrat de ce test) ; `McpExposureSymmetryTest` et
    `ApiRouteExposureTest` ne les voient pas (hors `/api`) ;
  - le sidecar nginx du backend les sert déjà par `location /` (`try_files … /index.php`), sous la zone
    `publicapi` ; aucune location `~ /\.` n'y refuse les chemins cachés (vérifié dans
    `k8s/base/backend-nginx.conf`) ; la zone `oauth` prévue par l'ADR peut les couvrir aussi ;
  - en dev, la conf `docker/nginx/default.conf` sert déjà tout chemin par le backend : rien à faire ;
  - un objet Ingress de plus, dans `k8s/base/` (les deux environnements), sans annotation `auth-*` : la préprod
    lève ainsi Basic Auth sur ces deux chemins par le même objet que la tâche 14 doit créer pour `/api/mcp` et
    les points OAuth.
- Variante écartée : une règle à réécriture (`nginx.ingress.kubernetes.io/rewrite-target`) qui garderait les
  routes Symfony sous `/api`. Elle évite `NON_API_PATHS`, mais lie le site à une annotation propre à
  ingress-nginx, et les chemins vus par Symfony ne seraient plus ceux que le client demande.
- Variante écartée : un émetteur sans chemin (`https://<site>`), dont le document irait à
  `/.well-known/oauth-authorization-server`. Il couvrirait les clients restés en 2025-03-26, mais ferait de
  l'origine entière l'émetteur, et `/authorize`, `/token` y tomberaient de toute façon sur la SPA.

## 5. Recommandation

1. **Émetteur** `https://<site>/api/oauth`, points d'autorisation, de jeton et de révocation sous `/api/oauth/…`
   (routes `/api` ordinaires, `PUBLIC_PATHS` justifiés).
2. **Deux routes publiques hors `/api`**, servies par le backend, entrées dans `NON_API_PATHS` :
   `/.well-known/oauth-protected-resource/api/mcp` (RFC 9728, `resource` = `https://<site>/api/mcp`,
   `authorization_servers` = `["https://<site>/api/oauth"]`, `scopes_supported` = `["nominative:read"]`) et
   `/.well-known/oauth-authorization-server/api/oauth` (RFC 8414 pur : `issuer`, `authorization_endpoint`,
   `token_endpoint`, `revocation_endpoint`, `response_types_supported: ["code"]`,
   `grant_types_supported`, `code_challenge_methods_supported: ["S256"]`,
   `token_endpoint_auth_methods_supported: ["none"]`, `client_id_metadata_document_supported: true`,
   `authorization_response_iss_parameter_supported: true`, `scopes_supported`, **pas** de
   `registration_endpoint`). Le contenu dérive d'`APP_FRONTEND_BASE_URL`, comme `allowed_hosts`.
3. **Un objet Ingress dédié** (`k8s/base/`), deux chemins `Exact` vers `backend`, sans annotation d'auth ; la
   tâche 14 y ajoute `/api/mcp` et `/api/oauth` pour la préprod.
4. **Le pointeur `resource_metadata`** de chaque 401 désigne l'URL canonique du point 2 (une seule adresse par
   document, aucun miroir sous `/api`).
5. **Pas de document OIDC** (`openid-configuration`), à aucune adresse.
6. **Le 404 du frontend sur `/.well-known/` est un invariant** : un test ou la règle de déploiement le dit
   (`tools/check-frontend-image-headers.sh` ou `audit-prod.sh` peuvent vérifier qu'un chemin absent y répond
   404).
7. **Vérification en préprod (tâche 14)** : relever dans les journaux d'accès les URL réellement demandées par
   claude.ai, Claude Code et ChatGPT à l'ajout du connecteur et au premier appel de l'outil protégé.

Confiance de l'ensemble : élevée pour Claude Code, moyenne pour claude.ai et ChatGPT tant que la préprod ne l'a
pas montré.

## Sources

Consultées le 2026-10-22.

1. MCP, versioning — https://modelcontextprotocol.io/specification/versioning (« The current protocol version
   is 2026-07-28 ») ; changelog 2026-07-28 — https://modelcontextprotocol.io/specification/2026-07-28/changelog
2. MCP 2026-07-28, Authorization Server Discovery —
   https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization/authorization-server-discovery
3. Claude, « Authentication » — https://claude.com/docs/connectors/building/authentication
4. MCP 2025-03-26, Authorization — https://modelcontextprotocol.io/specification/2025-03-26/basic/authorization
5. MCP 2025-11-25, Authorization — https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
6. MCP 2025-06-18, Authorization — https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization
7. RFC 9728, OAuth 2.0 Protected Resource Metadata — https://www.rfc-editor.org/rfc/rfc9728.txt
8. SDK TypeScript officiel, commit `c05225a636c679870b65f8ca4fe83a76ea1cf113` : `packages/client/src/client/auth.ts`
   (`buildDiscoveryUrls`, `discoverAuthorizationServerMetadata`, `discoverOAuthServerInfo`) et
   `packages/core/src/auth.ts` (`OAuthMetadataSchema`, `OpenIdProviderDiscoveryMetadataSchema`) —
   https://github.com/modelcontextprotocol/typescript-sdk/tree/c05225a636c679870b65f8ca4fe83a76ea1cf113
9. RFC 8414, OAuth 2.0 Authorization Server Metadata — https://www.rfc-editor.org/rfc/rfc8414.txt
10. OpenAI, Apps SDK, « Authentication » — https://developers.openai.com/apps-sdk/build/auth
11. OpenID Connect Discovery 1.0 — https://openid.net/specs/openid-connect-discovery-1_0.html
12. Claude, « Troubleshooting » — https://claude.com/docs/connectors/building/troubleshooting
13. Claude Code, MCP — https://code.claude.com/docs/en/mcp
14. Document CIMD de Claude Code — https://claude.ai/oauth/claude-code-client-metadata
15. **[secondaire]** Issues https://github.com/anthropics/claude-ai-mcp : #82, #713, #716, #738, #925, #962,
    #1103, #1169
