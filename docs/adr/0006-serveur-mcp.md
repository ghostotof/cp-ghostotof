# ADR 0006 — Serveur MCP : le portfolio interrogé par l'assistant de la personne qui le consulte

> **Vocabulaire.** Les termes sont ceux de [`CONTEXT.md`](../../CONTEXT.md), section « Accès MCP » :
> **serveur MCP**, **client MCP**, **connexion MCP**, **corpus nominatif**.

- Statut : **accepté** (2026-10-19), à mettre en œuvre — cadrage de l'issue #379, mise en œuvre par
  la spec 0007 (`.claude/specs/0007-mcp-server.md`), en deux phases (paliers anonyme et de base, puis
  connexion MCP)
- Date : 2026-10-19
- Portée : `config/packages/mcp.yaml` (nouveau), `config/packages/security.yaml` (firewall dédié),
  `CsrfCookieRequestSubscriber` (une exclusion), `tests/Security/ApiRouteExposureTest.php`
  (`PUBLIC_PATHS`), un test d'exposition MCP symétrique (nouveau), les deux configurations nginx
  (zones `mcp` et OAuth), l'Ingress de préprod (phase 2), le journal d'audit de
  `Security/Authentication`, le frontend (page « Brancher votre assistant », puis consentement et
  « Mes connexions MCP ») ; **amende** l'ADR 0003 (D1) et l'ADR 0004 (D1, D3, alternatives
  écartées) ; objectifs n°8 (sécurité), n°9 (rien de nominatif sans `ROLE_TRUSTED`), n°11 et n°12

## Contexte

L'ADR 0004 avait d'abord retenu un serveur [MCP](https://modelcontextprotocol.io) pour sa phase 2,
puis l'a écarté le 2026-09-15 au profit de l'assistant de carrière, en le déclarant réouvrable. Deux
raisons l'avaient écarté : le besoin du moment était un assistant sur le site, sans rien à
installer ; et l'authentification d'un client MCP externe ne passe pas par le cookie `BEARER`.

L'assistant de carrière existe désormais (v0.19.0). Le serveur MCP revient pour un besoin distinct :
qu'une personne branche **son propre** assistant — Claude, ChatGPT, un IDE — sur le portfolio et
l'interroge, avec ses jetons à elle. Le site n'appelle aucun modèle : c'est la différence qui le
sort de la lettre de l'ADR 0004, sans le sortir de son esprit.

Trois faits, établis pendant le cadrage (2026-10-19), ont pesé sur les décisions :

1. **Un jeton fixe dans un en-tête n'est pas portable.** Claude Code, Cursor et VS Code en
   envoient ; ChatGPT jamais ; claude.ai et Claude Desktop seulement en bêta limitée, documentée
   comme un identifiant d'organisation, pas de personne ; l'AI Assistant de PhpStorm n'a que cela, et
   pas OAuth (ticket JetBrains LLM-25012, ouvert). Pour identifier une personne depuis claude.ai ou
   ChatGPT, OAuth 2.1 est la seule voie commune.
2. **Aucune brique de l'écosystème Symfony ne fait le travail seule.** `mcp/sdk` n'est qu'un
   serveur de ressources (son propre ADR exclut d'émettre des jetons), et en 0.8.1 la métadonnée
   `_meta.oauth` qu'il propage peut être complétée par le client. `symfony/mcp-bundle` 0.14.1 n'a
   aucune intégration d'autorisation. `league/oauth2-server-bundle` v2.0.0 gère PKCE et la
   révocation, mais ni DCR, ni CIMD, ni l'audience RFC 8707.
3. **La plomberie existante dicte le chemin.** L'Ingress n'envoie au backend que `/api` (`/mcp`
   servirait l'`index.html` du SPA) ; tout POST sous `/api` passe la garde CSRF double-submit ;
   le pod est en lecture seule, à deux réplicas (ADR 0005), alors que le store de session par défaut
   du bundle est un fichier sous `var/cache`.

## Décisions

### D1 — Un canal de lecture, qui ne sert rien qu'une route du même palier ne sert déjà

Le serveur MCP est en lecture seule et **n'appelle aucun modèle**. Chaque outil appelle le provider
public de la route correspondante, comme `CorpusRenderer` le fait déjà, et renvoie exactement sa
sortie : `structuredContent` égal à ce que sert la route, un `outputSchema` déclaré, et ce même JSON
sérialisé dans le bloc texte (la révision 2026-07-28 de la spécification le recommande pour la
compatibilité, et beaucoup de clients ne passent que ce bloc au modèle).

L'invariant est celui de l'assistant de carrière (ADR 0004 D7), étendu à tous les paliers : **le
serveur MCP ne sert rien qu'une route du même palier ne sert déjà**. Un test d'exposition symétrique,
construit comme `ApiRouteExposureTest`, l'impose dans les deux sens : chaque route de contenu de
`PUBLIC_PATHS ∪ BASE_TIER_PATHS` a un outil ou une exclusion justifiée par écrit, et chaque outil
correspond à une route existante du même palier. Ajouter un contexte de contenu fait échouer la
suite tant qu'on n'a pas choisi de l'exposer ou de l'exclure ; retirer une route fait échouer l'outil
orphelin. La revue des payloads demandée par l'issue se réduit ainsi à une règle vérifiée.

Deux précisions, apportées par la spec 0007 le 2026-10-19 :

- **« Exactement sa sortie », pour une collection.** La spécification MCP veut un objet pour
  `structuredContent` ; une route qui rend un tableau voit donc ce tableau enveloppé dans
  `{"items": …}`. C'est la seule transformation admise.
- **Deux outils sans route adossée**, dans une liste fermée et justifiée du test symétrique : l'adresse
  de la page publique du formulaire de contact (la route `/api/contact` est une écriture), et le
  corpus nominatif, dont `CorpusSourcesTest` fixe déjà les sources.

Le catalogue, ses noms et son prompt sont du ressort de la spec. Deux outils de l'issue d'origine
n'existeront pas : `get_experience`, qui est de la chronologie professionnelle, et
`list_projects` / `get_project`, le domaine n'ayant pas de notion de projet.

### D2 — Sans authentification : palier anonyme et palier de base (amende l'ADR 0003, D1)

Sans jeton, le serveur MCP sert les contenus des paliers anonyme **et de base**. C'est contraire à
la lettre de l'ADR 0003 (D1), qui définit le palier de base comme « publiable, non indexable » et
fait reposer la discrétion sur l'exigence d'une requête authentifiée.

Ce que cette discrétion protège tient toujours : ne pas remonter dans une recherche. Un moteur
n'indexe pas les réponses à des POST JSON-RPC, et le GET du transport n'ouvre qu'un flux
d'événements. Ce qui change, c'est qu'« exige une requête authentifiée » devient « exige un appel de
protocole » : un aspirateur conçu pour MCP lira ces contenus, comme il pouvait déjà le faire en une
requête par l'accès de base (ADR 0003 D1 : « un script obtient le jeton aussi facilement qu'un
humain »). L'ADR 0003 est amendée en ce sens.

### D3 — Palier de confiance : une connexion MCP, par OAuth 2.1 et par lui seul

Une personne du palier de confiance accorde à son client MCP une **connexion MCP**, qui lui ouvre
le corpus nominatif. Le mécanisme est **OAuth 2.1**, avec un serveur d'autorisation interne bâti sur
`league/oauth2-server-bundle` v2, complété de ce qui lui manque : métadonnées RFC 8414 et RFC 9728,
audience RFC 8707, page de consentement.

- **Un seul chemin.** Pas de jeton personnel en en-tête à côté d'OAuth : deux chemins
  d'authentification, c'est deux fois la surface d'autorisation, et c'est l'argument exact par lequel
  l'ADR 0003 (D2) a refusé un second chemin. L'AI Assistant natif de PhpStorm reste donc hors du
  palier de confiance tant que JetBrains n'a pas livré OAuth ; Claude Code, dans le terminal de
  l'IDE, y donne accès.
- **Enregistrement des clients : CIMD**, avec une liste de domaines autorisés contre le SSRF, plus
  des clients **pré-enregistrés** au backoffice. **Pas de DCR** : la spécification le déprécie, c'est
  un point d'inscription ouvert à tous, et le nom qu'un client y déclare n'est vérifié par personne.
- **Un seul scope**, `nominative:read` : il n'existe qu'un objet protégé.
- **Claude et ChatGPT** sont couverts, chacun par son mécanisme d'authentification différée : un 401
  avec `WWW-Authenticate` sur le `tools/call` de l'outil protégé, renvoyé avant le SDK, pour Claude ;
  des `securitySchemes` par outil et l'erreur `_meta["mcp/www_authenticate"]` pour ChatGPT. L'outil
  nominatif est donc **toujours listé**, et sa description dit qu'il exige une connexion MCP.
- **Durées de départ**, de la configuration et non de la décision : jeton d'accès 1 h, jeton de
  rafraîchissement 30 jours avec rotation à chaque usage (la spécification l'exige des clients
  publics), connexion expirée après 30 jours sans usage et au plus tard 90 jours après son octroi.

### D4 — L'identité vient du firewall Symfony, vérifiée en base à chaque requête

Le jeton est validé par un authentificateur Symfony, sur un firewall dédié à `/api/mcp`, sans état,
**qui n'accepte jamais de cookie**. À chaque requête authentifiée, le jeton est relu en base et le
compte rechargé : une connexion révoquée, ou un compte qui perd le palier de confiance, cesse
d'ouvrir le corpus à la requête suivante. C'est ce que la connexion MCP promet (« effet immédiat »),
et c'est précisément ce que le JWT du site ne fait pas (ADR 0003, risque accepté du 2026-09-21).

- **Jamais `_meta.oauth`, jamais les middlewares d'autorisation du SDK.** L'outil lit l'identité par
  `Security`, rien d'autre. Cela écarte la métadonnée complétable par le client, l'URL de métadonnées
  construite depuis l'en-tête `Host` et le tout-ou-rien d'`AuthorizationMiddleware`, et la phase 2
  n'attend pas un bundle compatible avec `mcp/sdk` 0.9.
- **Pourquoi pas de cookie.** `/api/mcp` doit être exclu de la garde CSRF, puisqu'un client MCP ne
  porte pas de jeton XSRF. Cette exclusion n'est sûre que si le canal ne s'authentifie jamais par
  cookie : sinon une page tierce ferait lancer au navigateur d'une personne de confiance des requêtes
  MCP portant son `BEARER`.
- **Le coût est d'une requête SQL**, jointure jeton, compte et rôles, devant une réponse qui prend
  des secondes du côté du modèle ; les appels sans jeton n'en paient aucune. Un test compte les
  requêtes d'un `tools/call` authentifié, et aucune écriture hors session n'y est admise : le
  **dernier usage** d'une connexion se met à jour au rafraîchissement du jeton (au plus une fois par
  heure), pas à chaque appel. Une purge planifiée retire les jetons expirés.

### D5 — Le corpus nominatif part vers le fournisseur que la personne a choisi

Au palier de confiance, le serveur MCP sert le **corpus nominatif** — le texte que rend
`CorpusRenderer` pour l'assistant de carrière — et un **lien vers la page du site** où se télécharge
le CV, jamais le PDF lui-même. Le lien ne révèle rien à qui ne s'y est pas connecté.

Ce corpus quitte alors le site vers le fournisseur d'IA du client MCP : Anthropic, OpenAI ou un
autre. L'ADR 0004 (D3) interdit au **site** d'envoyer le CV nominatif ailleurs que chez son hébergeur.
Sa lettre ne couvre pas ce cas, où c'est la personne qui l'envoie, mais son esprit le refuse : la
décision est donc écrite plutôt que tue. Elle est acceptée parce qu'elle n'étend pas ce que la
personne peut faire : du palier de confiance, elle télécharge déjà le PDF et peut le confier à
l'assistant de son choix. Le serveur MCP raccourcit ce geste, il n'en crée pas un nouveau.

En contrepartie, **chaque lecture nominative est inscrite au journal d'audit** : compte, connexion
MCP, client, horodatage, outil. Savoir à qui le CV a été ouvert est la raison d'être de l'octroi
nominatif (ADR 0003 D1), et une lecture par un client MCP est moins visible qu'un téléchargement.

### D6 — Aucun état sur le pod, aucune écriture par appel

- **Session MCP** : store `cache` du bundle, sur un pool DBAL dédié (ADR 0005 D1). Le store par
  défaut, un fichier sous `var/cache`, ne fonctionne pas sur un disque en lecture seule à deux
  réplicas ; le store en mémoire n'est pas partagé.
- **Débit** : une zone nginx `mcp` (de l'ordre de 60 requêtes par minute et par IP, avec rafale) et
  une zone pour les points OAuth, du niveau de `login`. La limite est par pod, donc environ le double
  en effectif ; un limiteur Symfony serait partagé, mais il ferait une écriture et un verrou
  PostgreSQL à chaque appel, et un client MCP en fait plusieurs par question.

### D7 — `symfony/mcp-bundle` seul, pinné en version exacte (amende l'ADR 0004, D1)

Le serveur repose sur `symfony/mcp-bundle` et des services `#[McpTool]`, sans `api-platform/mcp`.
Celui-ci ne réexpose pas les opérations existantes : il faut déclarer des opérations MCP à part,
qui n'héritent pas du contexte de la `Get` sœur — le payload ne serait donc pas garanti identique, ce
qui défait D1 —, il installe `symfony/object-mapper` sur toutes les opérations, exige
`symfony/expression-language` et un format MCP configuré, et ses outils semblent échapper au filtre
`registry` du serveur.

`symfony/mcp-bundle` (0.14.1) et `mcp/sdk` (0.8.1) sont **pinnés en version exacte** tant qu'ils sont
en 0.x. La règle de l'ADR 0004 (D1), écrite pour `symfony/ai-*`, s'étend à `symfony/mcp-*` et à
`mcp/*` : même monorepo, même namespace `Symfony\AI\`, même absence de promesse de compatibilité —
`mcp/sdk` 0.9.0 est sortie avec une série de ruptures la veille de cette décision.

### D8 — Rien ne nomme l'auteur hors du corpus nominatif

Aucune description d'outil, aucun prompt, ni le champ `instructions` du serveur ne nomme l'auteur :
ils parlent de « l'auteur du site ». Ce que le serveur dit de lui-même est lu par n'importe quel
client, donc au palier anonyme ; seul le corpus nominatif, au palier de confiance, porte une identité.

### Ce qui n'est jamais négociable

- Un outil qui sert un contenu qu'aucune route du même palier ne sert, ou un champ que cette route
  ne sert pas.
- Un appel à un modèle depuis le serveur MCP.
- Un cookie accepté par le firewall de `/api/mcp`, ou une identité lue ailleurs que dans `Security`.
- Un second chemin d'authentification vers le corpus nominatif à côté d'OAuth.
- Une connexion MCP qui survit à sa révocation ou à la perte du palier de confiance.
- Une lecture nominative absente du journal d'audit.
- Le nom de l'auteur dans ce que le serveur publie de lui-même.
- Un `^` sur `symfony/mcp-*` ou `mcp/*` tant qu'ils sont en 0.x.

## Conséquences

- **Une route publique de plus**, `/api/mcp`, avec son entrée justifiée dans `PUBLIC_PATHS`, une
  exclusion dans `CsrfCookieRequestSubscriber`, et un test qui vérifie qu'elle répond en GET
  (l'AI Assistant de PhpStorm échoue en 405 sur les serveurs qui le refusent).
- **Un test d'exposition de plus** (D1), et un test qui compte les requêtes SQL (D4).
- **Un pool de cache de plus**, pour les sessions MCP, sur DBAL ; une ligne par `initialize`, avec
  une durée de vie d'une heure.
- **Deux zones nginx de plus**, dans les deux configurations miroirs.
- **Un serveur d'autorisation OAuth** dans le backend (phase 2) : tables de clients, de codes et de
  jetons, deux pages frontend (consentement, « Mes connexions MCP »), une vue backoffice de toutes
  les connexions, un CronJob de purge des jetons expirés, une zone nginx.
- **La préprod lève Basic Auth sur les seuls chemins MCP et OAuth**, pour la phase 2 : Basic Auth et
  Bearer se disputent l'en-tête `Authorization`, et aucun client ne ferait d'OAuth derrière. Ce qui y
  est servi sans jeton est publiable par construction, et le nominatif exige un compte de préprod ;
  mais du code non encore publié devient joignable sur ces chemins. À revoir quand l'accès VPN à la
  préprod (#339) aboutit.
- **Une page publique bilingue** « Brancher votre assistant » dès la phase 1 (URL, configuration
  pour Claude Code, Claude Desktop et claude.ai, ChatGPT, Cursor, PhpStorm), complétée en phase 2.
- **Les ressources MCP** (le CV sans identité en Markdown, le corpus nominatif comme ressource, un
  `resource_link` dans les outils) font l'objet d'une issue de suivi, juste après la phase 2.
- **Une issue de suivi** surveille les tickets JetBrains LLM-25012 (OAuth) et IJPL-226161 (CIMD),
  pour ouvrir le palier de confiance à l'AI Assistant de PhpStorm le jour où ils sont livrés.
- **Le registre RGPD** (`docs/rgpd/registre-traitements.md`) reçoit les traitements nouveaux : le
  journal des appels d'outils (IP tronquée), puis en phase 2 les connexions MCP et leurs lectures
  nominatives au journal d'audit.
- **La numérotation** : l'accès VPN à la préprod (#339) évoquait un « ADR 0006 » sans l'avoir écrit ;
  il prendra le numéro suivant.

## Alternatives écartées

- **Le palier anonyme seul.** Le plus conforme à l'ADR 0003 telle qu'écrite, mais le contenu exposé
  serait trop mince pour qui s'interroge sur un profil, et la discrétion du palier de base tient sans
  l'authentification (D2).
- **Un jeton personnel en en-tête**, seul ou à côté d'OAuth. Très simple, révocation triviale ; mais
  inutilisable depuis claude.ai et ChatGPT, et à côté d'OAuth un second chemin (D3).
- **DCR**, pour Junie ou Cursor : déprécié par la spécification, ouvert à tous, et l'intérêt pour
  Junie n'est que probable — aucune documentation ne le confirme.
- **`omouren/mcp-oauth-bundle`** : il couvre ce qui manque à league (RFC 8414/9728, CIMD, RFC 8707),
  mais il avait sept semaines, ne fonctionne pas avec league v2, et sa protection SSRF ne résout pas
  le nom d'hôte.
- **Un fournisseur d'identité externe** derrière le proxy OAuth du SDK : une infrastructure de plus,
  et le proxy a disparu de `mcp/sdk` 0.9.
- **Un JWT sans état pour la connexion MCP** : une requête SQL de moins par appel, mais plus de
  révocation immédiate.
- **`/mcp` hors de `/api`** : l'URL conventionnelle, mais une règle d'Ingress par environnement, une
  entrée `NON_API_PATHS` avec son propre firewall et son `access_control`, et le sidecar à adapter.
- **La seule révision sans session du protocole (2026-07-28)** : rien à stocker, mais les clients qui
  ne la parlent pas encore seraient refusés.
- **Le PDF du CV en ressource binaire** : un second format à garder synchrone avec le corpus, et un
  canal de plus pour un document qu'un modèle lit moins bien que le texte.
- **Un e-mail à l'auteur à chaque connexion MCP** : de la réactivité en cas d'abus, au prix du bruit ;
  le journal d'audit suffit en V1.
