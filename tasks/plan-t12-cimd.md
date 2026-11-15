# Spec 0007 — Tâche 12 (#651) : enregistrement des clients par CIMD — plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** un client OAuth qui s'identifie par l'URL de son document de métadonnées (CIMD), sur un domaine de la liste autorisée, obtient une connexion MCP sans pré-enregistrement — sans que la récupération de ce document ouvre une SSRF, et en montrant à la personne ce qui est vérifié (le domaine) et ce qui ne l'est pas (le nom).

**Architecture:** *l'autorisation juge le document ; le jeton, le rafraîchissement et la révocation jugent la ligne `mcp_client`*. Aux trois temps de l'autorisation (`GET /authorize`, `GET` et `POST /consent`), `AuthorizationRequestValidator` demande à une **admission CIMD** le client décrit par le document (cache en base, sinon récupération à adresse épinglée) et le passe à league par un attribut PSR-7 de la requête ; à l'approbation, le client est écrit ou rafraîchi dans `mcp_client` (`registration = 'cimd'`) dans la transaction de la connexion. Aucun appel sortant ne part de `/token` ni de `/revoke`. Une ligne pré-enregistrée gagne toujours, sans récupération.

**Tech Stack:** PHP 8.5, Symfony 8.1 (`symfony/http-client` 8.1.11 : `NoPrivateNetworkHttpClient`, options `resolve`, `max_redirects`, `timeout`, `max_duration`, lecture par `stream()`), league/oauth2-server 9.4.1, Doctrine ORM 3 / PostgreSQL, `symfony/rate-limiter`, `symfony/cache` (adaptateur `doctrine_dbal`), Vue 3 + TypeScript, vue-i18n, Vitest, axe-core.

**Spec:** `.claude/specs/0007-mcp-server.md` (l. 350-354, 627-628), `docs/adr/0006-serveur-mcp.md` (D3), ticket #651. Référence normative : draft-ietf-oauth-client-id-metadata-document-03 (§3, §4.1, §4.2, §6, §6.1, §6.2, §10.1, §10.6, §10.7, §10.11), lu en source primaire le 2026-11-13.

## Global Constraints

- **Branche** : `feature/spec-0007-t12-cimd` ; la PR vise `feature/379-mcp-paliers` (jamais `develop` ni `main`). Un commit par tâche, message en français `type(mcp): … (#651)`, sans `[skip ci]` ni variante. `git add` nomme les fichiers de la tâche, jamais `-A` ni `.`.
- **Commandes, toujours dans les conteneurs** : test ciblé backend `docker compose exec -T -u dev backend php bin/phpunit --filter <Classe>` (toujours `-u dev`, jamais root) ; test ciblé frontend `docker compose exec -T frontend npx vitest run <chemin>` ; vérifications de fin `make back-quality`, `make back-test`, `make front-test`, `make front-lint`, `make front-build`. Un test qui contredit le source : `docker compose restart backend` (ou `frontend`) avant toute autre hypothèse.
- **TDD** (`superpowers:test-driven-development`) : chaque tâche écrit ses tests, les voit échouer **pour la raison attendue** (classe absente, assertion fausse — pas une erreur de syntaxe ni de câblage), puis implémente.
- **Lire avant d'écrire** : avant de modifier un fichier d'une zone, lire un fichier de cette zone avec l'outil `Read` (les règles `.claude/rules/` ne se chargent que par lui) : `mcp-oauth.md`, `mcp-oauth-audits.md`, `security-authentication-audit.md`, `portfolio-watch.md` (patron des appels sortants), `backend-integrite.md` (pools de cache authentifiés), `deploiement-etat-hors-pod.md`, `backend-conventions.md`, `frontend.md`, `frontend-a11y.md`.
- **Aucun test ne touche le réseau ni le DNS** : `MockHttpClient` et un résolveur d'adresses en doublure, partout. Une requête DNS ou HTTP réelle dans la suite est un défaut de la tâche.
- **Secrets** : ne jamais lire `.env.local`, `.env.*.local`, `config/jwt/*` ; tout `grep -r` les exclut. Aucun secret écrit ; dans les tests, `TestKeys::material()` / `TestCredentials`, jamais une constante en clair. Ne jamais nommer l'auteur du site ni un compte réel.
- **Code** : `declare(strict_types=1)`, classes `final readonly` quand c'est possible, propriétés, paramètres et retours typés, une exception métier nommée par refus (constructeurs nommés, message qui ne recopie jamais une valeur reçue), `use` triés (`make back-style-fix`), PHPStan niveau maximal, Rector, Psalm, `lsp:check`. Tout horodatage en `datetimetz_immutable`.
- **Sécurité et routes identiques dans tous les environnements** : aucune déclaration sous `when@test`/`when@prod` pour un service de sécurité, une route ou un limiteur (`SecurityConfigEnvironmentParityTest`, `RouteEnvironmentParityTest`, `SecurityServicesEnvironmentParityTest`).
- **ADR 0005** : aucun état sur le pod — le cache du document et le limiteur vivent en base (`cache.adapter.doctrine_dbal`), valeurs authentifiées par `AuthenticatedCacheMarshaller`.
- **Valeurs fixées par ce plan** (constantes de code, pas de configuration) : taille maximale du document **5 120 octets** (5 Ko, draft §10.11) ; délai d'inactivité **2,0 s**, durée totale **3,0 s** ; durée de cache **entre 300 s et 3 600 s** (le `max-age` du serveur, borné ; 300 s à défaut) ; au plus **10** redirections déclarées par document ; quota de récupération **10 par 10 minutes et par adresse IP** ; liste des domaines autorisés : `claude.ai`, `chatgpt.com` (paramètre versionné `app.mcp.cimd.allowed_domains`).
- **Hors périmètre** : le backoffice des clients et des connexions (T13, #652 : lister, supprimer ou révoquer un client CIMD, audit de l'enregistrement d'un client, bornes de `register()`), la livraison (T14, #653 : Job k8s, essai en préprod avec les vrais domaines), le DCR (écarté par l'ADR 0006), l'affichage de `logo_uri` (le draft impose alors un préchargement), `private_key_jwt`, les hôtes IPv6 seuls.

## Review Focus

Entrées et modes d'échec que le ticket n'énonce pas et qui mordraient en premier ; chacun est épinglé par un test dans la tâche qui possède le code.

1. **Réponse DNS mixte** : un hôte autorisé rend une adresse publique *et* une adresse privée — la récupération est refusée, aucune requête ne part (Task 3, `testAHostWithOnePrivateAddressAmongPublicOnesIsRefused`).
2. **Corps qui dépasse la borne sans l'annoncer** (pas de `Content-Length`, envoi par morceaux) : la lecture s'arrête à la borne, le reste n'est jamais lu (Task 4, `testAnUnannouncedOversizedBodyStopsAtTheBound`).
3. **`client_id` du document presque égal à l'URL** (casse, barre finale) : refus, la comparaison est une égalité de chaînes, sans normalisation (Task 5, `testAClientIdThatDiffersOnlyByCaseOrTrailingSlashIsRefused`).
4. **Client pré-enregistré dont l'identifiant a la forme d'une URL d'un domaine autorisé** : la ligne gagne, aucune récupération (Task 9, `testAPreregisteredRowWinsWithoutAnyFetch`).
5. **Le document change entre l'affichage et l'approbation** (cache expiré, redirection retirée) : l'approbation est refusée, rien n'est écrit (Task 10, `testADocumentThatDroppedTheRedirectBetweenDisplayAndApprovalGrantsNothing`).

## Décisions et alternatives

Chaque décision ci-dessous qui touche le périmètre, l'ADR ou un contrat publié est reprise sous « Questions ouvertes » dans le compte rendu du plan ; le plan s'exécute avec l'option recommandée.

**D-A — Qui lit le document, qui lit la ligne.** L'autorisation juge le document ; `/token`, le rafraîchissement et `/revoke` jugent la ligne `mcp_client` écrite à l'approbation.
- *Avantages* : aucun appel sortant depuis un point de jeton (un rafraîchissement horaire ne dépend pas de la disponibilité de l'éditeur du client, et ces points ne deviennent pas un levier de récupération) ; `TokenEndpoint::isKnownClient()`, `LeagueRevocationEndpoint` et `ClientRepository` restent inchangés ; la clé étrangère `mcp_connection → mcp_client` tient.
- *Inconvénients* : un document retiré ou modifié par son éditeur n'arrête pas une connexion déjà accordée (elle vit jusqu'à son échéance, la révocation existe) ; la « re-récupération périodique » du draft (§6, SHOULD) a lieu à chaque nouvelle autorisation, pas en tâche de fond.
- *Écarté* : résoudre le client par le document à chaque point (appels sortants au point de jeton, `invalid_client` sur une panne de l'éditeur) ; écrire la ligne dès `GET /authorize` (écriture en base déclenchée par un anonyme).

**D-B — Passage du client à league : un attribut PSR-7, pas d'état partagé.** Le validateur pose le client découvert en attribut de la requête ; `LoopbackAwareAuthCodeGrant::getClientEntityOrFail()` le lit, et retombe sur les lignes sinon.
- *Avantages* : aucun service à état mutable, la requête porte ce qui la concerne ; un seul juge (`AuthorizationRequestValidator`) aux trois temps ; l'attribut n'est honoré que s'il est une instance de `McpClient` — un paramètre de route ou de requête, toujours une chaîne, ne peut pas le contrefaire.
- *Inconvénients* : une surcharge de plus d'une méthode `protected` de league (à relire à chaque montée de version, comme les trois autres du même grant).
- *Écarté* : un `ClientRepository` qui consulte le cache (ferait partir une récupération depuis `/token`) ; un service « client courant » à état (ordre d'appel implicite, fuite entre requêtes d'un même worker).

**D-C — Persistance : une colonne `mcp_client.registration`, écrite à l'approbation.** `preregistered` (défaut) ou `cimd` ; à l'approbation, `INSERT … ON CONFLICT (client_id) DO UPDATE … WHERE registration = 'cimd'`.
- *Avantages* : aucune ligne pour un client qu'aucune personne n'a approuvé ; nom et redirections rafraîchis à chaque nouvel accord ; deux premiers accords simultanés ne rendent pas de 500 ; une ligne pré-enregistrée n'est jamais écrasée ; T13 distingue les deux modes sans heuristique sur la forme de l'identifiant.
- *Inconvénients* : une migration ; le nom affiché dans « Mes connexions MCP » est celui du dernier accord, pas celui du jour de l'octroi.
- *Écarté* : ne rien persister (casse la clé étrangère et D-A) ; une table à part pour les clients CIMD (deux sources pour une même notion, jointures dans tous les dépôts de jetons).

**D-D — SSRF : cinq couches, l'adresse épinglée par nous.**
1. `CimdClientId` : `https`, hôte DNS en minuscules avec au moins un point (ni adresse littérale, ni entier décimal), **sans port** (le draft le permet ; le refuser borne la cible au port 443), chemin autre que `/`, sans segment `.` ni `..` ; l'hôte doit être **exactement** dans la liste (pas de sous-domaine implicite).
2. Résolution par un port `HostAddressResolverInterface` (nom **enraciné**, `claude.ai.`, pour que la liste de recherche du `resolv.conf` du pod ne s'applique pas ; enregistrements **A seuls**) ; aucune adresse → refus.
3. `PublicAddressPolicy` : **toutes** les adresses rendues doivent être publiques (`IpUtils::PRIVATE_SUBNETS` de Symfony **plus** le multicast `224.0.0.0/4` et `ff00::/8`, qu'il ne couvre pas) ; la politique juge aussi l'IPv6 et l'IPv4 mappée, bien que la résolution n'en rende pas.
4. Épinglage : la première adresse vérifiée part dans l'option `resolve` → `CURLOPT_RESOLVE` ; la connexion va à l'adresse vérifiée, pas à une seconde réponse DNS (rebinding).
5. `NoPrivateNetworkHttpClient` autour du client : il revérifie l'adresse épinglée et `primary_ip` à la connexion. Sans notre `resolve`, il ferait lui-même la requête DNS (une seule adresse vérifiée, et une vraie requête DNS dans les tests) : c'est pourquoi la résolution est à nous, derrière une interface.
- *Avantages* : chaque couche se teste sans réseau ; le rebinding est fermé par construction ; la défense en profondeur tient si l'une des couches régresse.
- *Inconvénients* : un hôte IPv6 seul est refusé (ni `claude.ai` ni `chatgpt.com`) ; le résolveur natif (`dns_get_record`) n'a pas de test unitaire, faute de pouvoir interroger un DNS — son essai réel est celui de la préprod (T14).
- *Écarté* : `NoPrivateNetworkHttpClient` seul (une seule adresse vérifiée, multicast non couvert, DNS réel en test) ; vérifier puis laisser curl résoudre (rebinding) ; un proxy de sortie filtrant (infrastructure hors périmètre, T14 au plus tôt).

**D-E — Lecture bornée.** `max_redirects: 0` et tout statut autre que 200 refusé (draft §4.1, §6) ; `Content-Type` de type `application/json` exigé ; `timeout` 2,0 s et `max_duration` 3,0 s ; taille bornée **en flux** (`stream()`, arrêt et `cancel()` au-delà de 5 120 octets, plus le refus immédiat d'un `Content-Length` supérieur).
- *Écarté* : `getContent()` puis `strlen()` (le corps entier est déjà en mémoire) ; l'option `on_progress` seule (elle borne, mais le refus y passe par une exception levée dans un rappel, moins lisible que la boucle de `stream()`).

**D-F — Cache et quota.** Pool dédié `cache.mcp_client_metadata` (base, valeurs authentifiées), clé `sha256(client_id)`, valeur = **le corps brut validé** (revalidé à chaque lecture : aucun objet désérialisé depuis le cache) ; jamais d'erreur ni de document invalide en cache (draft §6.2, MUST NOT) ; durée = `max-age` borné à [300 s ; 3 600 s]. Quota `mcp_client_metadata` : 10 récupérations par 10 minutes **et par adresse IP — une IPv6 comptée par son /64** (qui tient un /64 change d'adresse source à volonté et aurait un quota neuf à chaque essai), une IPv4 entière, une IPv4 portée par une IPv6 (mappée `::ffff:a.b.c.d`, NAT64 `64:ff9b::/96`, 6to4 `2002::/16`, compatible `::/96`, reconnue par l'extracteur partagé `Shared/Infrastructure/Network/EmbeddedIpv4`, aussi utilisé par `TruncatedIp`) comptée comme cette IPv4 ; une adresse absente ou illisible garde `unknown` ; pour ce limiteur seulement (classe `FetchQuotaKey`), consommé seulement sur un cache manqué, et jamais pour un hôte hors liste (jugé avant le cache et le quota). **Plafond global** `mcp_client_metadata_global` : 100 récupérations par 10 minutes pour tous les appelants ensemble (`sliding_window`, clé constante, aucune donnée personnelle), **regardé sans être consommé** (`consume(0)`, qui rend toujours « accepté » : le refus se lit sur les jetons restants) avant le quota de l'appelant, puis consommé après lui ; un refus du plafond ne coûte donc rien à l'appelant, un refus de l'appelant rien au plafond ; le refus lève la même `ClientMetadataFetchRateLimitExceededException`, avec le `retryAfter` du limiteur qui refuse. Il borne le nombre total de récupérations sortantes qu'une attaque distribuée sur beaucoup d'adresses peut faire partir. **Voie des clients connus** (décidée le 2026-11-14, codée en Task 7) : un `client_id` déjà enregistré en base comme client déclaré (`ClientRegistration::Cimd`, écrit par `saveDiscovered()` à l'approbation, Task 10) n'est pas refusé par le plafond tant que son compteur a de la place : il l'est par le quota de son appelant, et **le compteur de ce client** (`mcp_client_metadata_known_client`, 10 récupérations par 10 minutes, `sliding_window`, clé = empreinte `ClientIdFingerprint`, le SHA-256 du `client_id`) **ne refuse jamais seul** : regardé (`consume(0)`, lu sur les jetons restants), il fait, **plein, se replier la voie sur le chemin d'un client inconnu** (plafond regardé, appelant, plafond consommé, l'appelant n'étant consommé qu'une fois). Sinon l'appelant est consommé, puis le compteur ; si le compteur vient de se remplir (course), c'est le plafond qui est consommé, sans reprendre d'unité à l'appelant, et son refus porte son `retryAfter`. Mesure qui impose la voie : **10 sources suffisent à saturer le plafond** (10 /64 d'un même /48 × 10 récupérations de `client_id` aléatoires sur un domaine autorisé, chacune un 404 jamais gardé) ; sans cette voie, l'attaque bloquait tout client dont le document n'est pas en cache, Claude et ChatGPT compris, à chaque expiration de leur document. Raison du compteur (relecture de `19c6dea7`) : un `client_id` approuvé est **public** (l'URL de son document), donc un attaquant ne peut pas *rendre* un client connu, mais peut en *nommer* un ; sans compteur, chaque /64 neuf faisait partir 10 récupérations vers ce document en contournant le plafond, sans autre borne que le nombre de /64. Le compteur **décide qui paie, il ne borne pas le total** : les 10 premières récupérations d'un client dans la fenêtre, quels que soient les appelants, passent hors du plafond, les suivantes le paient ; le total des sorties vers les domaines autorisés est borné par le plafond commun (100) plus 10 par client approuvé, par fenêtre de 10 minutes ; l'ensemble de ses clés est borné par le nombre de clients approuvés. *Pourquoi il ne refuse jamais seul* (relecture de `91050866`, mesure : 10 /authorize simultanés depuis **une** adresse, à l'expiration du cache, remplissaient le compteur d'un client approuvé et faisaient refuser (`Throttled`, environ 6 minutes) chaque personne du palier de confiance qui le connectait ensuite ; et un client connu était plus refusé qu'un inconnu) : une arme contre le client, que la décision du 2026-11-15 supprime en repliant sur le plafond commun un compteur plein. *Empreinte* : une clé de longueur et d'alphabet fixes, qui n'écrit pas l'URL en clair dans `cache_items` ; **ce n'est pas un secret** (le `client_id` d'un client approuvé est une URL publique, recalculable), et aucune donnée personnelle n'est stockée. Une seule dérivation, `ClientIdFingerprint::of()`, partagée avec `CachedClientMetadataStore`.
- *Avantages* : le nombre d'entrées est borné par le nombre de documents **valides** des domaines autorisés (un tiers ne peut pas en fabriquer) ; un client déjà vu ne dépend plus du quota tant que son document est en cache, et, une fois la Task 7 livrée, un client enregistré passe un plafond saturé pour ses 10 premières récupérations de la fenêtre (au-delà, il repasse par le plafond) ; une adresse, ou un /64, ne fait pas de ce site un relais de requêtes vers les domaines autorisés ; le plafond borne le total sortant d'une attaque distribuée ; un attaquant ne peut pas rendre un `client_id` « connu » : il faudrait un document valide sur un domaine autorisé **et** l'approbation d'une personne du palier de confiance ; un client connu n'est jamais plus refusé qu'un inconnu, et épuiser son compteur ne suffit pas à le bloquer.
- *Inconvénients* : le plafond reste épuisable par 10 sources, et bloque alors le **premier** enregistrement d'un client jamais vu (limite acceptée) ; une lecture en base de plus sur chaque cache manqué (indexée, sur la contrainte d'unicité de `client_id`) ; trois prises de verrou et trois lectures de `cache_items` par récupération acceptée d'un client inconnu (coup d'œil au plafond, appelant, plafond), dont deux sur la clé `global` commune à tous les pods ; un client connu en coûte autant (coup d'œil à son compteur, appelant, son compteur), mais sur une clé propre à ce client, jamais sur la clé `global` ; un compteur plein ajoute le chemin d'un inconnu (le plafond regardé puis consommé : deux prises de verrou de plus sur `global`), et bloquer un client connu demande de saturer le plafond (10 sources, limite déjà acceptée) **et** son compteur (une source de plus suffit : dix `/authorize` simultanés à l'expiration de son document), limite acceptée par décision du 2026-11-15 ; la course entre le coup d'œil au compteur et sa consommation ne coûte une unité à l'appelant sans rien lui donner que si le plafond est plein aussi.
- *Alternatives écartées* : quota par adresse seul (ne borne pas une attaque distribuée, la zone nginx `oauth` non plus) ; plafond seul sans voie des clients connus (10 sources refusent le premier accord de tout le monde, version initiale de cette décision) ; plafond relevé à 600 par 10 minutes (60 /64 au lieu de 10, trivial pour qui tient un /48 : le seuil monte, le problème reste) ; cache négatif des 404 (l'attaquant change de chemin à chaque essai, et le draft §6.2 l'interdit pour une erreur).

**D-G — Refus : `invalid_client` sans redirection, 503/429 pour le transitoire.** Un refus définitif rend ce que league rend pour un client inconnu (`invalid_client`, 401, jamais de redirection vers le client — draft §6.1) ; un refus transitoire (DNS, délai, 5xx de l'éditeur) rend `temporarily_unavailable` en **503**, le quota dépassé en **429**. Chaque refus survenu après la liste de domaines est tracé par un événement d'audit `mcp-client-metadata-refused` (hôte et cause, jamais l'URL entière ni le corps). Un domaine hors liste n'est pas tracé : il ne déclenche aucune récupération et se confond, comme aujourd'hui, avec un client inconnu (le tracer donnerait à un anonyme une écriture d'audit par requête, bornée par nginx seul).

**D-H — Audit des connexions : l'identifiant public du client, inchangé.** `mcp-connection-granted`, `nominative-corpus-read` et les autres continuent d'écrire `clientId`, l'URL pour un client CIMD.
- *Avantages* : `McpClientId` borne déjà la valeur à 255 caractères d'un alphabet sans espace ni caractère de contrôle (`[A-Za-z0-9._:/-]`), donc ni ligne démesurée ni injection de journal ; une ligne d'audit reste lisible sans jointure, y compris après la suppression du client par T13 ; continuité avec les lignes déjà écrites.
- *Inconvénients* : jusqu'à 255 octets par ligne ; l'URL est une donnée choisie par un tiers (d'un domaine autorisé).
- *Écarté* : l'UUID interne (illisible sans la table, orphelin après suppression, rupture avec l'historique).

**D-I — Contrat de `GET /api/oauth/consent`.** `client.registration` gagne la valeur `cimd` ; un champ `client.domain` apparaît (`string` pour `cimd`, `null` pour `preregistered`). C'est un changement d'un contrat publié, lu par le seul frontend du site, livré dans la même tâche que lui (le frontend refuse aujourd'hui toute autre valeur que `preregistered`).

**D-J — L'annonce `client_id_metadata_document_supported: true`** (posée par T8, en avance sur le code) devient exacte avec cette tâche : elle reste, la phrase « acceptée en avance » de la règle `mcp-oauth.md` est retirée (Task 12).

**D-K — La liste de domaines est un paramètre versionné**, pas une variable d'environnement.
- *Avantages* : une décision de sécurité relue en PR, identique dans tous les environnements, aucune variable à poser en préprod ni en prod.
- *Inconvénients* : ajouter un domaine demande une release.

## Fichiers

| Fichier | Rôle | Tâche |
|---|---|---|
| `backend/src/Mcp/Connection/Domain/ValueObject/CimdClientId.php` (créé) | forme stricte d'un `client_id` CIMD, hôte | 1 |
| `backend/src/Mcp/Connection/Domain/ValueObject/AllowedClientDomains.php` (créé) | liste des domaines autorisés | 1 |
| `backend/src/Mcp/Connection/Domain/Exception/InvalidAllowedClientDomainException.php` (créé) | entrée de liste mal formée | 1 |
| `backend/config/services.yaml` (modifié) | paramètre `app.mcp.cimd.allowed_domains`, câblages | 1, 3, 6 |
| `backend/src/Mcp/Connection/Infrastructure/Cimd/PublicAddressPolicy.php` (créé) | une adresse est-elle publique | 2 |
| `backend/src/Mcp/Connection/Infrastructure/Cimd/HostAddressResolverInterface.php`, `NativeHostAddressResolver.php` (créés) | résolution A, nom enraciné | 3 |
| `backend/src/Mcp/Connection/Domain/ValueObject/ClientMetadataRefusal.php` (créé) | causes de refus, transitoire ou non | 3 |
| `backend/src/Mcp/Connection/Domain/Exception/ClientMetadataRefusedException.php` (créé) | refus d'un document | 3 |
| `backend/src/Mcp/Connection/Application/Cimd/ClientMetadataFetcherInterface.php`, `FetchedClientMetadata.php` (créés) | port de récupération | 3 |
| `backend/src/Mcp/Connection/Infrastructure/Cimd/HttpClientMetadataFetcher.php` (créé) | récupération épinglée et bornée | 3, 4 |
| `backend/src/Mcp/Connection/Domain/ValueObject/ClientMetadataDocument.php` (créé) | document validé | 5 |
| `backend/src/Mcp/Connection/Domain/Exception/InvalidClientMetadataDocumentException.php` (créé) | document invalide | 5 |
| `backend/src/Mcp/Connection/Application/Cimd/CimdClientResolver.php`, `CimdClientResolverInterface.php`, `ClientMetadataStoreInterface.php`, `ClientMetadataFetchQuotaInterface.php` (créés) | cache, quota, résolution | 6 |
| `backend/src/Mcp/Connection/Infrastructure/Cimd/CachedClientMetadataStore.php`, `SymfonyClientMetadataFetchQuota.php` (créés) | adaptateurs cache et limiteur | 6 |
| `backend/src/Mcp/Connection/Domain/Exception/ClientMetadataFetchRateLimitExceededException.php` (créé) | quota dépassé | 6 |
| `backend/config/packages/cache.yaml`, `rate_limiter.yaml` (modifiés) | pool `cache.mcp_client_metadata`, limiteur `mcp_client_metadata` | 6 |
| `backend/src/Mcp/Connection/Domain/ValueObject/ClientRegistration.php` (créé) | `preregistered` / `cimd` | 7 |
| `backend/src/Mcp/Connection/Domain/Entity/McpClient.php` (modifié) | colonne `registration`, `discovered()` | 7 |
| `backend/migrations/Version20261113120000.php` (créé) | colonne et contrainte | 7 |
| `backend/src/Mcp/Connection/Domain/Repository/McpClientRepositoryInterface.php`, `Infrastructure/Doctrine/McpClientRepository.php` (modifiés) | `saveDiscovered()` | 7 |
| `backend/src/Security/Authentication/Application/SecurityAuditLoggerInterface.php`, `Infrastructure/Log/SecurityAuditLogger.php` (modifiés) | événement `mcp-client-metadata-refused` | 8 |
| `backend/src/Mcp/Connection/Application/Cimd/CimdClientAdmission.php`, `CimdClientAdmissionInterface.php` (créés) | admission : coexistence, liste, document, audit | 9 |
| `backend/src/Mcp/Connection/Application/AuthorizationRequestValidator.php`, `AuthorizationRequestSummary.php` (modifiés) | appel de l'admission, attribut, résumé | 9 |
| `backend/src/Mcp/Connection/Infrastructure/League/LoopbackAwareAuthCodeGrant.php` (modifié) | `getClientEntityOrFail()` | 9 |
| `backend/src/Mcp/Connection/Application/OAuthServerMetadata.php` (modifié) | docblock de l'annonce | 9 |
| `backend/src/Mcp/Connection/Application/ConsentService.php`, `Presentation/Controller/ConsentController.php` (modifiés) | persistance à l'approbation, `registration` et `domain` | 10 |
| `frontend/src/domain/mcp/entities/ConsentRequest.ts`, `frontend/src/infrastructure/mcp/HttpOAuthConsentRepository.ts`, `frontend/src/presentation/pages/OAuthConsentPage.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (modifiés) | page de consentement | 11 |
| `.claude/rules/mcp-oauth-cimd.md` (créé), `.claude/rules/mcp-oauth.md`, `mcp.md`, `.claude/CLAUDE.md`, `docs/adr/0006-serveur-mcp.md`, `docs/rgpd/registre-traitements.md`, `tasks/todo.md` (modifiés) | règles, ADR, registre, notes de release | 12 |

Les tests de chaque tâche sont nommés dans la tâche ; tous vivent sous `backend/tests/Mcp/Connection/` (même arborescence que le source) ou `frontend/tests/`.

---

### Task 1 : `CimdClientId` et liste des domaines autorisés — **sensible** (identification du client, SSRF)

**Files:**
- Create: `backend/src/Mcp/Connection/Domain/ValueObject/CimdClientId.php`, `AllowedClientDomains.php`, `backend/src/Mcp/Connection/Domain/Exception/InvalidAllowedClientDomainException.php`
- Modify: `backend/config/services.yaml` (paramètre `app.mcp.cimd.allowed_domains: ['claude.ai', 'chatgpt.com']` à côté des `app.mcp.lifetime.*` ; service `AllowedClientDomains` par fabrique `fromList`, argument `'%app.mcp.cimd.allowed_domains%'`)
- Test: `backend/tests/Mcp/Connection/Domain/ValueObject/CimdClientIdTest.php`, `AllowedClientDomainsTest.php`, `backend/tests/Mcp/Connection/Infrastructure/Cimd/CimdWiringTest.php`

**Interfaces:**
- Consumes: `McpClientId::fromString(string): McpClientId` (alphabet `[A-Za-z0-9._:/-]`, 255 caractères au plus).
- Produces:
  - `CimdClientId::tryFrom(string $clientId): ?self` — `null` quand la chaîne n'a pas la forme CIMD (elle n'est alors qu'un identifiant de ligne) ; `value(): string` (la chaîne reçue, à l'octet près) ; `host(): string` ; `toMcpClientId(): McpClientId`.
  - `AllowedClientDomains::fromList(array $domains): self` (`@param list<string>`, lève `InvalidAllowedClientDomainException::malformed()` sur une entrée qui n'est pas un nom d'hôte en minuscules) ; `allows(CimdClientId $clientId): bool` (égalité exacte de l'hôte).

- [x] **Step 1 : tests rouges.** `CimdClientIdTest`, deux fournisseurs de données :
  - acceptés (avec l'hôte attendu) : `https://claude.ai/oauth/claude-code-client-metadata` → `claude.ai` ; `https://chatgpt.com/oauth/client.json` → `chatgpt.com` ; `https://a.example.test/x` → `a.example.test`. `value()` rend la chaîne reçue, `toMcpClientId()->equals(McpClientId::fromString($url))`.
  - refusés (`tryFrom()` rend `null`) : `claude-code` ; `http://claude.ai/x` ; `HTTPS://claude.ai/x` ; `https://Claude.ai/x` ; `https://claude.ai` ; `https://claude.ai/` ; `https://claude.ai:443/x` ; `https://claude.ai/a/../b` ; `https://claude.ai/./b` ; `https://claude.ai/a/..` ; `https://127.0.0.1/x` ; `https://2130706433/x` ; `https://localhost/x` ; `https://claude.ai./x` ; `https://-claude.ai/x` ; `https://claude..ai/x` ; `https://user@claude.ai/x` ; `https://claude.ai/x?y=1` ; `https://claude.ai/x#f` ; `https://claude.ai/%2e%2e/x` ; `https://[::1]/x` ; `"https://claude.ai/x "` ; `"https://claude.ai/x\n"` ; une URL de 256 caractères.
  - `AllowedClientDomainsTest` : `fromList(['claude.ai', 'chatgpt.com'])` autorise les deux hôtes ; refuse `evil.claude.ai`, `claude.ai.evil.test`, `notclaude.ai` ; `fromList([])` n'autorise rien (CIMD éteint) ; `fromList(['Claude.ai'])`, `fromList(['claude.ai/'])`, `fromList([''])` lèvent `InvalidAllowedClientDomainException`.
  - `CimdWiringTest` (`KernelTestCase`) : `getParameter('app.mcp.cimd.allowed_domains') === ['claude.ai', 'chatgpt.com']` et le service `AllowedClientDomains` autorise `https://claude.ai/oauth/claude-code-client-metadata`.
- [x] **Step 2 : vérifier l'échec** — `--filter 'CimdClientIdTest|AllowedClientDomainsTest|CimdWiringTest'` : classes et paramètre absents.
- [x] **Step 3 : implémenter.** `CimdClientId::tryFrom()` : d'abord `McpClientId::PATTERN` (borne et alphabet : ni `?`, `#`, `%`, `@`, espace), puis préfixe `https://` exact, puis l'hôte par `\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z` (le dernier label commence par une lettre : une adresse en notation pointée n'y passe pas), puis le chemin : non vide, autre que `/`, aucun segment égal à `.` ou `..`. Aucune normalisation : la valeur gardée est la chaîne reçue. Le docblock dit pourquoi le port est refusé (D-D) et cite le draft §3.
- [x] **Step 4 : vérifier** — les trois tests verts ; `make back-quality` vert.
- [x] **Step 5 : commit** — `feat(mcp): la forme d'un client_id CIMD et la liste des domaines autorisés (#651)`.

### Task 2 : politique des adresses publiques — **sensible** (SSRF)

**Files:**
- Create: `backend/src/Mcp/Connection/Infrastructure/Cimd/PublicAddressPolicy.php`
- Test: `backend/tests/Mcp/Connection/Infrastructure/Cimd/PublicAddressPolicyTest.php`

**Interfaces:**
- Consumes: `Symfony\Component\HttpFoundation\IpUtils::checkIp()`, `IpUtils::PRIVATE_SUBNETS`.
- Produces: `PublicAddressPolicy::isPublic(string $address): bool` (classe `final readonly`, sans dépendance ; `false` pour toute chaîne qui n'est pas une adresse IP).

- [x] **Step 1 : tests rouges.** Un fournisseur par verdict :
  - publiques : `1.1.1.1`, `160.79.104.10`, `2606:4700:4700::1111`.
  - non publiques : `127.0.0.1`, `10.0.0.1`, `172.16.0.1`, `192.168.1.1`, `169.254.169.254`, `100.64.0.1`, `0.0.0.0`, `192.0.2.1`, `198.18.0.1`, `224.0.0.1`, `239.255.255.250`, `240.0.0.1`, `255.255.255.255`, `::1`, `::`, `fe80::1`, `fc00::1`, `fd00::1`, `ff02::1`, `::ffff:127.0.0.1`, `::ffff:10.0.0.1`, `::ffff:1.1.1.1`, `64:ff9b::7f00:1`, `2002:7f00:1::`, `2001:db8::1`, `not-an-ip`, la chaîne vide, `"1.1.1.1 "`, `0x7f.1`, `017700000001`.
  - `testTheMulticastRangesAreAddedToSymfonysList` : `224.0.0.0/4` et `ff00::/8` ne figurent pas dans `IpUtils::PRIVATE_SUBNETS` (si une montée de Symfony les y ajoute, le test le dit et l'ajout local se retire).
- [x] **Step 2 : vérifier l'échec** — `--filter PublicAddressPolicyTest` : classe absente.
- [x] **Step 3 : implémenter** — `filter_var($address, FILTER_VALIDATE_IP)` d'abord (sinon `false`), puis `!IpUtils::checkIp($address, [...IpUtils::PRIVATE_SUBNETS, '224.0.0.0/4', 'ff00::/8'])`. Le docblock énumère les classes d'adresses et renvoie au draft §10.7 (RFC 6890).
- [x] **Step 4 : vérifier** — test vert, `make back-quality` vert.
- [x] **Step 5 : commit** — `feat(mcp): une adresse à usage spécial n'est jamais publique, multicast compris (#651)`.

### Task 3 : résolution vérifiée et adresse épinglée — **sensible** (SSRF, DNS)

**Files:**
- Create: `backend/src/Mcp/Connection/Infrastructure/Cimd/HostAddressResolverInterface.php`, `NativeHostAddressResolver.php`, `HttpClientMetadataFetcher.php` ; `backend/src/Mcp/Connection/Application/Cimd/ClientMetadataFetcherInterface.php`, `FetchedClientMetadata.php` ; `backend/src/Mcp/Connection/Domain/ValueObject/ClientMetadataRefusal.php` ; `backend/src/Mcp/Connection/Domain/Exception/ClientMetadataRefusedException.php`
- Modify: `backend/config/services.yaml` — service `app.mcp.cimd.http_client` (classe `Symfony\Component\HttpClient\NoPrivateNetworkHttpClient`, argument `'@http_client'`) ; `HttpClientMetadataFetcher` reçoit `$client: '@app.mcp.cimd.http_client'`
- Test: `backend/tests/Mcp/Connection/Infrastructure/Cimd/HttpClientMetadataFetcherTest.php`, `backend/tests/Mcp/Connection/Fixtures/FixedHostAddressResolver.php` (doublure : table hôte → adresses, compte ses appels), `CimdWiringTest.php` (complété)

**Interfaces:**
- Consumes: `CimdClientId` (Task 1), `PublicAddressPolicy::isPublic()` (Task 2).
- Produces:
  - `HostAddressResolverInterface::resolve(string $host): array` (`@return list<string>` : les adresses IPv4 de l'hôte, liste vide si aucune ou si la résolution échoue).
  - `enum ClientMetadataRefusal: string` — `DomainNotAllowed = 'domain-not-allowed'`, `Throttled = 'throttled'`, `Unresolvable = 'unresolvable'`, `AddressBlocked = 'address-blocked'`, `Unreachable = 'unreachable'`, `Redirected = 'redirected'`, `UnexpectedStatus = 'unexpected-status'`, `UpstreamError = 'upstream-error'`, `UnexpectedContentType = 'unexpected-content-type'`, `TooLarge = 'too-large'`, `InvalidDocument = 'invalid-document'` ; `isTransient(): bool` vrai pour `Throttled`, `Unresolvable`, `Unreachable`, `UpstreamError`.
  - `ClientMetadataRefusedException::because(ClientMetadataRefusal $refusal, ?Throwable $previous = null): self`, propriété publique `readonly ClientMetadataRefusal $refusal` ; le message ne porte que la valeur de l'énumération.
  - `ClientMetadataFetcherInterface::fetch(CimdClientId $clientId): FetchedClientMetadata` (`@throws ClientMetadataRefusedException`).
  - `FetchedClientMetadata::__construct(public string $body, public ?int $maxAge)`.
  - `HttpClientMetadataFetcher::__construct(HttpClientInterface $client, HostAddressResolverInterface $resolver, PublicAddressPolicy $policy, AllowedClientDomains $domains)` ; constantes publiques `MAX_BYTES = 5120`, `TIMEOUT = 2.0`, `MAX_DURATION = 3.0`, `MAX_CONNECT_DURATION = 1.0`.

- [x] **Step 1 : tests rouges.** Le client testé est `new NoPrivateNetworkHttpClient(new MockHttpClient($rappel))`, le rappel compte les requêtes et garde `$options`. Le document servi est celui de Claude Code (voir Task 5), en 200 `application/json`.
  - `testTheRequestGoesToTheVerifiedAddress` : résolveur `['claude.ai' => ['1.1.1.1']]` → une requête `GET` sur l'URL du `client_id`, `$options['resolve']['claude.ai'] === '1.1.1.1'`, `max_redirects === 0`, `timeout === 2.0`, `max_duration === 3.0`, `max_connect_duration === 1.0`, en-tête `Accept: application/json`, même `User-Agent` que `EndOfLifeDateClient` ; le résolveur a reçu `claude.ai`.
  - `testAHostThatDoesNotResolveIsRefusedAsTransient` : résolveur vide → `Unresolvable`, zéro requête.
  - `testAHostResolvingToASpecialAddressIsRefused` (fournisseur : `127.0.0.1`, `10.0.0.5`, `169.254.169.254`, `192.168.0.1`, `224.0.0.1`) → `AddressBlocked`, zéro requête.
  - `testAHostWithOnePrivateAddressAmongPublicOnesIsRefused` : `['1.1.1.1', '10.0.0.5']` → `AddressBlocked`, zéro requête *(Review Focus 1)*.
  - `testAConnectionThatLandsOnAPrivateAddressIsRefused` : résolveur public, `new MockResponse($corps, ['primary_ip' => '127.0.0.1', 'response_headers' => ['content-type' => 'application/json']])` → refus `Unreachable` (c'est `NoPrivateNetworkHttpClient` qui bloque, sur `primary_ip` : un filet, qui coupe la lecture sans empêcher l'émission du GET, non une couche qui suffirait seule).
  - `CimdWiringTest` : `app.mcp.cimd.http_client` est un `NoPrivateNetworkHttpClient` ; `ClientMetadataFetcherInterface` rend un `HttpClientMetadataFetcher` ; `HostAddressResolverInterface` rend un `NativeHostAddressResolver`.
- [x] **Step 2 : vérifier l'échec** — `--filter 'HttpClientMetadataFetcherTest|CimdWiringTest'` : classes absentes.
- [x] **Step 3 : implémenter.**
  - `NativeHostAddressResolver::resolve()` : `dns_get_record($host.'.', DNS_A)` (nom enraciné, enregistrements A seuls — D-D), l'avertissement d'une résolution manquée neutralisé par un `set_error_handler` local rétabli dans un `finally` ; rend les `ip` des enregistrements, liste vide sinon. Aucun test n'instancie cette classe pour résoudre (aucun DNS en test) : le docblock le dit et renvoie à l'essai de préprod de T14.
  - `HttpClientMetadataFetcher::fetch()`, pour cette tâche : résoudre `host()` ; liste vide → `Unresolvable` ; une adresse non publique → `AddressBlocked` ; requête `GET` avec `resolve => [host => première adresse]`, `max_redirects => 0`, `timeout`, `max_duration`, `buffer => false`, en-têtes `Accept` et `User-Agent` ; toute `TransportExceptionInterface` → `Unreachable` (sans lire le message : le blocage de `NoPrivateNetworkHttpClient` s'y range, limite écrite dans le docblock). Le corps est rendu tel quel, `maxAge` à `null` : la lecture bornée est la Task 4.
- [x] **Step 4 : vérifier** — tests verts, `make back-quality` vert.
- [x] **Step 5 : commit** — `feat(mcp): le document d'un client est demandé à une adresse résolue, vérifiée et épinglée (#651)`.

### Task 4 : lecture bornée — ni redirection, ni statut autre que 200, ni corps trop gros ou trop lent — **sensible** (SSRF)

**Files:**
- Modify: `backend/src/Mcp/Connection/Infrastructure/Cimd/HttpClientMetadataFetcher.php`
- Test: `backend/tests/Mcp/Connection/Infrastructure/Cimd/HttpClientMetadataFetcherTest.php`

**Interfaces:**
- Consumes: Task 3.
- Produces: `fetch()` complet — `FetchedClientMetadata::$body` d'au plus 5 120 octets, `$maxAge` lu de `Cache-Control`.

- [x] **Step 1 : tests rouges.**
  - `testAPublishedDocumentIsReturnedWithItsMaxAge` : `cache-control: public, max-age=300` → `maxAge === 300`, corps identique ; sans `Cache-Control`, avec `no-store`, avec `max-age=abc` → `null`.
  - `testARedirectionIsRefusedAndNeverFollowed` (301, 302, 303, 307, 308, avec `Location: http://169.254.169.254/`) → `Redirected`, **une** requête.
  - `testAnyStatusOtherThan200IsRefused` : 204, 206, 401, 404 → `UnexpectedStatus` ; 429, 500, 503 → `UpstreamError`.
  - `testAnUnexpectedContentTypeIsRefused` : `text/html`, en-tête absent, `application/jsonp` → `UnexpectedContentType` ; `application/json; charset=utf-8` et `Application/JSON` acceptés.
  - `testAnAnnouncedOversizedBodyIsRefusedBeforeReading` : `content-length: 5121`, corps servi par un générateur à drapeau → `TooLarge`, le générateur n'a rien produit.
  - `testAnUnannouncedOversizedBodyStopsAtTheBound` : générateur de 100 morceaux de 1 024 octets, sans `Content-Length`, qui compte ce qu'il produit → `TooLarge`, au plus 7 morceaux produits *(Review Focus 2)*.
  - `testABodyOfExactlyTheBoundIsAccepted` : 5 120 octets.
  - `testAStalledBodyIsRefusedAsTransient` : générateur `yield 'x'; yield '';` (une chaîne vide est un délai dépassé pour `MockResponse`) → `Unreachable`.
  - `testATransportFailureIsRefusedAsTransient` : `new MockResponse('', ['error' => 'refused'])` → `Unreachable`.
  - `testARefusalNeverCarriesWhatTheServerSent` : un corps et un `Location` marqués d'un jeton aléatoire ; le message de l'exception et celui de sa précédente ne le contiennent pas.
- [x] **Step 2 : vérifier l'échec** — les nouveaux tests échouent sur l'assertion (le corps est rendu sans contrôle), pas sur une classe absente.
- [x] **Step 3 : implémenter**, dans cet ordre : statut (3xx → `Redirected` ; 429 ou ≥ 500 → `UpstreamError` ; autre que 200 → `UnexpectedStatus`) ; `Content-Type` (partie avant `;`, rognée, en minuscules, égale à `application/json`) ; `Content-Length` numérique supérieur à `MAX_BYTES` → `TooLarge` ; puis boucle `foreach ($this->client->stream($response) as $chunk)` : `isTimeout()` → `cancel()` et `Unreachable` ; cumul de `getContent()` ; au-delà de `MAX_BYTES` → `cancel()` et `TooLarge`. `maxAge` : `max-age=(\d{1,9})` d'une directive entière de `Cache-Control`, `null` sinon. Tout refus passe par `ClientMetadataRefusedException::because()`.
- [x] **Step 4 : vérifier** — tests verts, `make back-quality` vert.
- [x] **Step 5 : commit** — `feat(mcp): la lecture du document d'un client est bornée en flux, sans redirection (#651)`.

### Task 5 : validation du document — **sensible** (identification du client)

**Files:**
- Create: `backend/src/Mcp/Connection/Domain/ValueObject/ClientMetadataDocument.php`, `backend/src/Mcp/Connection/Domain/Exception/InvalidClientMetadataDocumentException.php`
- Test: `backend/tests/Mcp/Connection/Domain/ValueObject/ClientMetadataDocumentTest.php`, `backend/tests/Mcp/Connection/Fixtures/ClaudeCodeMetadata.php` (constantes `CLIENT_ID` et `json(): string`, le document ci-dessous)

**Interfaces:**
- Consumes: `CimdClientId`, `McpClientName::fromString()`, `RedirectUri::fromString()`.
- Produces: `ClientMetadataDocument::fromJson(CimdClientId $expected, string $json): self` (`@throws InvalidClientMetadataDocumentException`) ; propriétés publiques `readonly CimdClientId $clientId`, `McpClientName $name`, `array $redirectUris` (`list<RedirectUri>`) ; constante `MAX_REDIRECT_URIS = 10`. L'exception a un constructeur nommé par règle (`notAnObject()`, `clientIdMismatch()`, `secretDeclared()`, `unsupportedAuthMethod()`, `unsupportedGrantTypes()`, `unsupportedResponseTypes()`, `invalidName()`, `invalidRedirectUris()`), dont le message nomme le champ, jamais sa valeur.

Le document de référence (celui que `https://claude.ai/oauth/claude-code-client-metadata` servait le 2026-11-13) :

```json
{"client_id":"https://claude.ai/oauth/claude-code-client-metadata","client_name":"Claude Code","client_uri":"https://claude.ai","redirect_uris":["http://localhost/callback","http://127.0.0.1/callback"],"grant_types":["authorization_code","refresh_token"],"response_types":["code"],"token_endpoint_auth_method":"none"}
```

- [x] **Step 1 : tests rouges.**
  - `testTheClaudeCodeDocumentIsAccepted` : nom `Claude Code`, deux redirections, chacune `isLoopback()`, la première `matches('http://localhost:49152/callback')`.
  - `testAClientIdThatDiffersOnlyByCaseOrTrailingSlashIsRefused` : `client_id` absent, non-chaîne, d'une autre URL, de la même URL avec une majuscule dans le chemin, avec une barre finale → `clientIdMismatch` *(Review Focus 3)*.
  - `testWhatIsNotAJsonObjectIsRefused` : texte non JSON, `[]`, `"x"`, `null`, un objet imbriqué sur plus de 8 niveaux.
  - `testADeclaredSecretIsRefused` : `client_secret` présent (même `null`), `client_secret_expires_at` présent.
  - `testOnlyAPublicClientIsAccepted` : `token_endpoint_auth_method` valant `client_secret_post`, `client_secret_basic`, `client_secret_jwt`, `private_key_jwt`, `x`, ou non-chaîne → refus, **absent compris** (RFC 7591 §2 : le défaut est `client_secret_basic`, un client confidentiel) ; seul `none`, déclaré, → accepté.
  - `testTheGrantAndResponseTypesMustIncludeTheCodeFlow` : `grant_types` présent sans `authorization_code` ou qui n'est pas une liste de chaînes → refus ; `response_types` présent sans `code` → refus ; les deux absents → accepté.
  - `testTheNameIsRequiredAndBounded` : `client_name` absent, non-chaîne, vide, de 101 caractères, portant un caractère de contrôle → refus.
  - `testTheRedirectUrisAreRequiredBoundedAndEachValid` : absent, liste vide, objet, membre non-chaîne, 11 membres, un membre que `RedirectUri` refuse (`http://example.test/cb`, `https://x.test/cb#f`, `javascript:alert(1)`) → refus du document entier.
  - `testUnknownFieldsAreIgnored` : `client_uri`, `logo_uri`, `scope`, `tos_uri`, un champ inconnu → accepté.
  - `testARefusalNeverCopiesAValue` : la valeur fautive porte un jeton aléatoire, absent du message.
- [x] **Step 2 : vérifier l'échec** — `--filter ClientMetadataDocumentTest` : classe absente.
- [x] **Step 3 : implémenter** — `json_decode($json, true, 8, JSON_THROW_ON_ERROR)` ; objet (tableau non-liste, ou vide refusé) ; `client_id` comparé par `===` à `$expected->value()` (draft §4.1 : égalité de chaînes, aucune normalisation) ; puis les règles dans l'ordre des tests. Le docblock cite le draft §4.1 et dit pourquoi un seul membre de `redirect_uris` invalide refuse tout le document (échec fermé : un document à moitié lu n'est pas le document publié).
- [x] **Step 4 : vérifier** — test vert, `make back-quality` vert.
- [x] **Step 5 : commit** — `feat(mcp): le document de métadonnées d'un client est validé avant d'être cru (#651)`.

### Task 6 : cache borné en base, quota de récupération, résolution — **sensible** (SSRF, amplification)

**Files:**
- Create: `backend/src/Mcp/Connection/Application/Cimd/CimdClientResolverInterface.php`, `CimdClientResolver.php`, `ClientMetadataStoreInterface.php`, `ClientMetadataFetchQuotaInterface.php` ; `backend/src/Mcp/Connection/Infrastructure/Cimd/CachedClientMetadataStore.php`, `SymfonyClientMetadataFetchQuota.php`, `FetchQuotaKey.php` ; `backend/src/Mcp/Connection/Domain/Exception/ClientMetadataFetchRateLimitExceededException.php`
- Modify: `backend/config/packages/cache.yaml` (pool `cache.mcp_client_metadata`, `adapter: cache.adapter.doctrine_dbal`, `default_lifetime: 3600`, commentaire sur le modèle de `cache.mcp_sessions`) ; `backend/config/packages/rate_limiter.yaml` (limiteur `mcp_client_metadata` : `policy: 'sliding_window'`, `limit: 10`, `interval: '10 minutes'`, même verrou que `base_access`, commentaire qui dit ce qu'il borne ; limiteur `mcp_client_metadata_global` : `sliding_window`, `limit: 100`, `interval: '10 minutes'`, clé constante, même verrou) ; `backend/tests/Security/RateLimiterStorageTest.php` (`yield 'mcp_client_metadata' => ['mcp_client_metadata'];` et `yield 'mcp_client_metadata_global' => ['mcp_client_metadata_global'];`) ; `backend/tests/Shared/Infrastructure/Cache/CacheItemsAreAuthenticatedTest.php` (`assertContains('cache.mcp_client_metadata', $databasePools)`) ; `backend/tests/Security/Authentication/Infrastructure/Log/ThrottledRequestAuditCoverageTest.php` (entrée `TRACED_UPSTREAM` pour la nouvelle exception : « tracé par `CimdClientAdmission`, événement `mcp-client-metadata-refused`, cause `throttled` » — le traçage lui-même arrive en Task 9)
- Test: `backend/tests/Mcp/Connection/Application/Cimd/CimdClientResolverTest.php` ; `backend/tests/Mcp/Connection/Infrastructure/Cimd/CachedClientMetadataStoreTest.php`, `SymfonyClientMetadataFetchQuotaTest.php` ; doublures `backend/tests/Mcp/Connection/Fixtures/InMemoryClientMetadataStore.php`, `CountingFetchQuota.php`, `ScriptedMetadataFetcher.php`

**Interfaces:**
- Consumes: `ClientMetadataFetcherInterface::fetch()`, `FetchedClientMetadata` (Tasks 3-4) ; `ClientMetadataDocument::fromJson()` (Task 5) ; `ClientMetadataRefusal`, `ClientMetadataRefusedException` (Task 3).
- Produces:
  - `ClientMetadataStoreInterface::find(CimdClientId $clientId): ?string` ; `save(CimdClientId $clientId, string $body, int $ttl): void`.
  - `ClientMetadataFetchQuotaInterface::consume(): void` (`@throws ClientMetadataFetchRateLimitExceededException`).
  - `CimdClientResolverInterface::resolve(CimdClientId $clientId): ClientMetadataDocument` (`@throws ClientMetadataRefusedException`).
  - `CimdClientResolver::__construct(ClientMetadataStoreInterface $store, ClientMetadataFetchQuotaInterface $quota, ClientMetadataFetcherInterface $fetcher, AllowedClientDomains $domains)` — `resolve()` juge la liste des domaines **en premier**, avant le magasin et le quota (un document gardé d'un domaine retiré ne sert plus ; un refus local ne coûte aucune unité de quota) ; constantes `MIN_TTL = 300`, `MAX_TTL = 3600`.
  - `CachedClientMetadataStore::__construct(#[Autowire(service: 'cache.mcp_client_metadata')] CacheItemPoolInterface $pool)` — clé `hash('sha256', $clientId->value())`.
  - `SymfonyClientMetadataFetchQuota::__construct(#[Autowire(service: 'limiter.mcp_client_metadata')] RateLimiterFactory $factory, #[Autowire(service: 'limiter.mcp_client_metadata_global')] RateLimiterFactory $globalFactory, RequestStack $requests)` — clé de l'appelant `FetchQuotaKey::forAddress(getMainRequest()?->getClientIp())` (IPv6 par son /64, IPv4 entière, IPv4 portée par une IPv6 — mappée, NAT64, 6to4, compatible — comme cette IPv4 via `EmbeddedIpv4`, `unknown` à défaut), sur le modèle de `SymfonyBaseAccessRateLimiter` ; le plafond global, de clé constante, regardé sans être consommé avant elle, puis consommé après elle (correction de la relecture, `bcec5225`) ; le refus de l'un ou l'autre lève `ClientMetadataFetchRateLimitExceededException` avec le `retryAfter` du limiteur qui refuse.

- [x] **Step 1 : tests rouges.**
  - `CimdClientResolverTest` (doublures en mémoire, aucun conteneur) :
    - `testACachedDocumentIsServedWithoutQuotaNorFetch` ;
    - `testAMissConsumesTheQuotaFetchesValidatesAndStores` (une unité de quota, une récupération, le corps brut rangé) ;
    - `testTheLifetimeFollowsMaxAgeWithinItsBounds` (fournisseur : `null` → 300 ; 60 → 300 ; 300 → 300 ; 1 800 → 1 800 ; 86 400 → 3 600) ;
    - `testAnInvalidDocumentIsRefusedAndNeverStored` → `InvalidDocument`, le magasin reste vide ;
    - `testAFetchRefusalIsNeverStored` (`Redirected`) : l'appel suivant récupère de nouveau ;
    - `testAnExhaustedQuotaRefusesWithoutFetching` → `Throttled`, zéro récupération ;
    - `testACachedBodyThatNoLongerValidatesIsFetchedAgain` ;
    - `testACachedDocumentOfADomainThatLeftTheListIsRefused` (cache amorcé, domaine retiré de la liste → `DomainNotAllowed`, zéro quota, zéro récupération) ;
    - `testADomainOutsideTheListConsumesNoQuota`.
  - `CachedClientMetadataStoreTest` (`KernelTestCase`) : `save()` puis `find()` rend le corps ; un autre `client_id` rend `null` ; aucune clé de `cache_items` ne contient `claude.ai` (la clé est une empreinte).
  - `SymfonyClientMetadataFetchQuotaTest` (`KernelTestCase`, `RequestStack` portant une requête d'adresse `198.51.100.7`, pool `cache.rate_limiter` vidé au départ) : dix `consume()` passent, le onzième lève `ClientMetadataFetchRateLimitExceededException` ; une requête d'une autre adresse passe ; deux IPv6 du même /64 (`2001:db8:1:2::1`, `2001:db8:1:2:ffff::9`, compressée ou développée) partagent le compteur, une IPv6 d'un autre /64 a le sien ; `::ffff:198.51.100.7`, `64:ff9b::198.51.100.7` et `2002:c633:6407::1` partagent le compteur de `198.51.100.7`, deux IPv4 distinctes ne partagent rien ; le plafond global refuse la 101e récupération de l'ensemble des appelants, avec le `retryAfter` du limiteur global, et un appelant déjà refusé par son propre quota ne consomme rien du plafond ; un appelant refusé par le plafond garde tout son quota, et quand les deux refuseraient, c'est le `retryAfter` du plafond qui est rendu. `ClientIdFingerprintTest` (unitaire) juge l'empreinte seule : valeur figée du SHA-256, longueur et alphabet fixes, deux `client_id` deux empreintes, l'URL illisible dans l'empreinte. `FetchQuotaKeyTest` (unitaire) juge la clé seule : formes d'écriture, frontière du /64, IPv4 portée (mappée, NAT64, 6to4, compatible), adresse absente ou illisible → `unknown`. `EmbeddedIpv4Test` juge l'extracteur partagé (frontières de chaque forme, longueurs ≠ 16).
  - Les trois tests transverses complétés (limiteur en base et sous verrou, pool authentifié, quota justifié).
- [x] **Step 2 : vérifier l'échec** — `--filter 'CimdClientResolverTest|CachedClientMetadataStoreTest|SymfonyClientMetadataFetchQuotaTest|RateLimiterStorageTest|CacheItemsAreAuthenticatedTest|ThrottledRequestAuditCoverageTest'` : classes, pool et limiteur absents.
- [x] **Step 3 : implémenter.** `resolve()` : lecture du magasin ; un corps présent qui valide est rendu ; sinon `consume()` (quota dépassé → `Throttled`), `fetch()`, `fromJson()` (`InvalidClientMetadataDocumentException` → `InvalidDocument`, l'exception d'origine en précédente), `save()` avec `max(MIN_TTL, min(MAX_TTL, $maxAge ?? MIN_TTL))`, puis le document. Rien n'est rangé sur un refus (draft §6.2). Le quota n'est jamais consommé dans une transaction : le validateur tourne avant celle de l'approbation (le docblock le rappelle).
- [x] **Step 4 : vérifier** — tests verts, `make back-quality` vert.
- [x] **Step 5 : commit** — `feat(mcp): le document d'un client est gardé en base un temps borné, sa récupération sous quota (#651)`.

### Task 7 : le mode d'enregistrement d'un client, en base ; la voie des clients connus

**Files:**
- Create: `backend/src/Mcp/Connection/Domain/ValueObject/ClientRegistration.php`, `backend/migrations/Version20261113120000.php`
- Modify: `backend/src/Mcp/Connection/Domain/Entity/McpClient.php`, `backend/src/Mcp/Connection/Domain/Repository/McpClientRepositoryInterface.php`, `backend/src/Mcp/Connection/Infrastructure/Doctrine/McpClientRepository.php`, `backend/tests/Mcp/Connection/Application/McpClientRepositoryFake.php` ; voie des clients connus (D-F) : `backend/src/Mcp/Connection/Application/Cimd/ClientMetadataFetchQuotaInterface.php`, `CimdClientResolver.php`, `backend/src/Mcp/Connection/Infrastructure/Cimd/SymfonyClientMetadataFetchQuota.php`, `CachedClientMetadataStore.php` (sa clé passe par `ClientIdFingerprint`), `backend/tests/Mcp/Connection/Support/CountingFetchQuota.php`, `GlanceThenRefuseCeiling.php`, `backend/config/packages/rate_limiter.yaml` (commentaire du plafond ; limiteur `mcp_client_metadata_known_client`), `backend/tests/Security/RateLimiterStorageTest.php` (`yield 'mcp_client_metadata_known_client'`)
- Test: `backend/tests/Mcp/Connection/Domain/Entity/McpClientTest.php` (complété), `backend/tests/Mcp/Connection/Infrastructure/Doctrine/McpClientRepositoryTest.php` (créé, `KernelTestCase`), `CimdClientResolverTest.php` et `SymfonyClientMetadataFetchQuotaTest.php` (complétés)

**Interfaces:**
- Consumes: `ClientMetadataDocument` (Task 5).
- Produces:
  - `enum ClientRegistration: string` — `Preregistered = 'preregistered'`, `Cimd = 'cimd'`.
  - `McpClient::discovered(ClientMetadataDocument $document, DateTimeImmutable $at): self` (mode `Cimd`, identifiant `$document->clientId->toMcpClientId()`) ; `McpClient::registration(): ClientRegistration` ; `register()` garde sa signature et pose `Preregistered`.
  - `McpClientRepositoryInterface::saveDiscovered(McpClient $discovered): ?McpClient` — écrit ou rafraîchit la ligne `cimd` de cet identifiant et rend la ligne gérée ; `null` si une ligne pré-enregistrée porte déjà l'identifiant (elle n'est pas touchée).
  - Colonne `mcp_client.registration VARCHAR(16) NOT NULL DEFAULT 'preregistered'`, contrainte `chk_mcp_client_registration CHECK (registration IN ('preregistered', 'cimd'))`.
  - Voie des clients connus (D-F) : `McpClientRepositoryInterface::isDiscovered(McpClientId $clientId): bool` — vrai seulement pour une ligne `cimd` (une ligne pré-enregistrée ne passe jamais par le résolveur : l'admission la sert d'abord, Task 9). `ClientMetadataFetchQuotaInterface` reçoit **deux méthodes nommées** plutôt qu'un drapeau : `consume()` (client inconnu : plafond regardé, appelant, plafond) et `consumeForKnownClient(CimdClientId $clientId)` (corrections des relectures de `19c6dea7` — « appelant seul » — puis de `91050866` — un compteur qui refusait seul). **Contrat** : (a) le compteur du client est regardé (`consume(0)`, lu sur les jetons restants) ; **plein, repli sur tout `consume()`** (plafond regardé, appelant, plafond consommé, l'appelant consommé une fois) ; (b) sinon l'appelant est consommé ; (c) le compteur est consommé ; refusé (course), le plafond est consommé directement, **sans seconde unité à l'appelant**, et s'il refuse aussi l'exception porte son `retryAfter`. Le compteur ne refuse donc jamais seul. L'empreinte de sa clé est `ClientIdFingerprint::of()`, nouvelle classe `final readonly` de `Infrastructure/Cimd`, valeur inchangée (SHA-256 du `client_id`), partagée avec `CachedClientMetadataStore`. *Pourquoi deux méthodes* : l'appel dit ce qu'il décide (`consume(true)` ne se lit pas au site d'appel), et chaque branche a son contrat et son test ; *inconvénient* : deux méthodes à doubler dans `CountingFetchQuota`, qui compte chacune à part. `CimdClientResolver` reçoit `McpClientRepositoryInterface` et, sur un cache manqué seulement (après la liste des domaines et le magasin), choisit la méthode selon `isDiscovered($clientId->toMcpClientId())` : aucune lecture en base pour un document en cache ni pour un domaine hors liste.

- [x] **Step 1 : tests rouges.**
  - Voie des clients connus : `CimdClientResolverTest::testAKnownClientMissNeverConsumesTheCeiling`, `testAnUnknownClientMissConsumesTheCeilingToo`, `testACachedDocumentNeverReadsTheRegistrations`, `testADomainOutsideTheListNeverReadsTheRegistrations` ; `SymfonyClientMetadataFetchQuotaTest::testAKnownClientPassesASaturatedCeiling` (plafond épuisé, appelant libre → accepté, plafond inchangé), `testAKnownClientIsStillBoundByItsCallerQuota` (appelant épuisé → refusé avec son `retryAfter`), `testAnUnknownClientIsRefusedByASaturatedCeiling` ; `McpClientRepositoryTest::testOnlyACimdRowIsDiscovered` (ligne `cimd` → vrai, ligne pré-enregistrée → faux, identifiant absent → faux).
  - `McpClientTest` : `testARegisteredClientIsPreregistered` ; `testADiscoveredClientCarriesItsDocument` (mode `Cimd`, nom `Claude Code`, `allowsRedirect('http://localhost:53124/callback')` vrai, `allowsRedirect('http://localhost:53124/other')` et `allowsRedirect('https://evil.test/callback')` faux).
  - `McpClientRepositoryTest` : `testADiscoveredClientIsInsertedOnce` ; `testASecondSaveRefreshesNameAndRedirectsAndKeepsIdAndDate` ; `testAPreregisteredRowIsNeverOverwritten` (`saveDiscovered()` rend `null`, nom et redirections de la ligne inchangés) ; `testTheColumnRefusesAnUnknownRegistration` (`UPDATE mcp_client SET registration = 'x'` lève) ; `testARowWrittenWithoutTheColumnIsPreregistered` (`INSERT` SQL sans la colonne).
- [x] **Step 2 : vérifier l'échec** — `--filter 'McpClientTest|McpClientRepositoryTest'` : méthodes et colonne absentes.
- [x] **Step 3 : implémenter.**
  - Colonne mappée `#[ORM\Column(type: 'string', length: 16, enumType: ClientRegistration::class, options: ['default' => 'preregistered'])]`.
  - Migration : docblock français qui dit pourquoi (les lignes existantes sont toutes pré-enregistrées, d'où le défaut ; la contrainte ferme les valeurs ; migration additive, aucun code `Mcp/Connection` n'est encore en production), `up()` : `ALTER TABLE mcp_client ADD registration VARCHAR(16) DEFAULT 'preregistered' NOT NULL` puis `ALTER TABLE mcp_client ADD CONSTRAINT chk_mcp_client_registration CHECK (registration IN ('preregistered', 'cimd'))` ; `down()` : l'inverse. `make db-migrate` puis `doctrine:schema:validate` dans le conteneur.
  - `saveDiscovered()` : une requête DBAL aux paramètres typés (`uuid`, `json`, `datetimetz_immutable`) :

    ```sql
    INSERT INTO mcp_client (id, client_id, name, redirect_uris, registered_at, registration)
    VALUES (:id, :clientId, :name, :redirectUris, :registeredAt, 'cimd')
    ON CONFLICT (client_id) DO UPDATE
       SET name = EXCLUDED.name, redirect_uris = EXCLUDED.redirect_uris
     WHERE mcp_client.registration = 'cimd'
    RETURNING id
    ```

    Aucune ligne rendue → `null`. Sinon `find()` de l'identifiant rendu, suivi d'un `refresh()` si l'entité était déjà gérée (ses champs viennent d'être réécrits en SQL). Lire `.claude/rules/mcp-oauth-purge.md` (ordre des verrous) avant d'écrire : l'appelant de la Task 10 exécute cette requête **avant** toute écriture de `mcp_connection` dans la même transaction.
  - `McpClientRepositoryFake::saveDiscovered()` : même contrat, en mémoire.
  - `isDiscovered()` : une requête `SELECT 1 … WHERE client_id = :id AND registration = 'cimd'` (index d'unicité de `client_id`). `consumeForKnownClient()` : voir le contrat ci-dessus ; tests de `SymfonyClientMetadataFetchQuotaTest` : `testTwoKnownClientsHaveTheirOwnCounters`, `testACallerRefusedByItsQuotaConsumesNothingOfTheKnownClientLimiter`, `testAKnownClientIsStillBoundByItsCallerQuota`, `testNoCacheItemHoldsTheClearClientId`, et, pour la décision du 2026-11-15, `testAKnownClientIsNeverRefusedWhileTheCeilingHasRoom`, `testAFullKnownClientCounterFallsBackOnTheCeiling`, `testAFullKnownClientCounterAndAFullCeilingRefuseWithTheCeilingsRetryAfter`, `testWhenTheCounterIsFullAndTheCallerExhaustedTheCallersQuotaRefuses`, `testOneAddressExhaustingAKnownClientCounterDoesNotBlockTheOthers` (le scénario d'attaque), `testAKnownClientCounterFilledBetweenTheGlanceAndTheConsumptionFallsBackOnTheCeilingWithOneCallerUnit`, `testWhenTheCounterFillsDuringTheRaceAndTheCeilingIsFullTheCallerLosesItsOneUnit` ; **retirés car contredisant le contrat** (le onzième appel est accepté par le plafond, le `retryAfter` n'est plus celui du compteur) : `testTenCallersShareOneKnownClientCounterAndTheEleventhIsRefused`, `testARefusalByTheKnownClientLimiterCostsTheCallerNothing`, `testWhenBothWouldRefuseTheRetryAfterIsTheKnownClientLimiters` ; `ClientIdFingerprintTest` (unitaire) ; `CimdClientResolverTest::testAKnownClientMissHandsItsClientIdToTheCounter`, `testAKnownClientRefusedByItsCounterIsThrottledWithoutFetching`. Docblocks (quota, résolveur) et commentaire de `rate_limiter.yaml` : la voie, sa raison (10 sources saturent le plafond) et pourquoi un attaquant ne peut pas rendre un client connu.
- [x] **Step 4 : vérifier** — tests verts ; `make back-test` vert en entier (schéma, `TimestampColumnsCarryTheirOffsetTest`, `DatabaseOwnerSettingsVerificationTest`) ; `make back-quality` vert.
- [x] **Step 5 : commit** — `feat(mcp): un client porte son mode d'enregistrement, pré-enregistré ou déclaré (#651)`.

### Task 8 : événement d'audit `mcp-client-metadata-refused` — **sensible** (audit)

**Files:**
- Modify: `backend/src/Security/Authentication/Application/SecurityAuditLoggerInterface.php`, `backend/src/Security/Authentication/Infrastructure/Log/SecurityAuditLogger.php`
- Test: `backend/tests/Security/Authentication/Infrastructure/Log/SecurityAuditLoggerTest.php`

**Interfaces:**
- Produces: `SecurityAuditLoggerInterface::mcpClientMetadataRefused(string $clientHost, string $cause): void` — événement `mcp-client-metadata-refused`, contexte propre `clientHost` et `cause`, plus le contexte commun de tout événement (dont l'adresse du demandeur) ; aucun compte (l'événement est déclenché par un anonyme, comme `csrf-rejected`, au même niveau de journal que lui). Deux chaînes, pas un type de `Mcp` : ce contexte ne dépend pas de `Mcp`.

- [ ] **Step 1 : test rouge** — `testAClientMetadataRefusalNamesTheHostAndTheCause` : `mcpClientMetadataRefused('claude.ai', 'address-blocked')` écrit un enregistrement `mcp-client-metadata-refused` dont le contexte porte `clientHost === 'claude.ai'` et `cause === 'address-blocked'`, et aucune clé `clientId`, `url`, `userId` ; mêmes assertions de forme que le test de `csrfRejected`.
- [ ] **Step 2 : vérifier l'échec** — `--filter SecurityAuditLoggerTest` : méthode absente.
- [ ] **Step 3 : implémenter** — lire d'abord `.claude/rules/security-authentication-audit.md` (ce qu'un enregistrement ne porte jamais) ; méthode et docblock sur le modèle de `csrfRejected()` ; le docblock dit que l'hôte vient de `CimdClientId::host()` (alphabet `[a-z0-9.-]`, déjà dans la liste autorisée) et que ni l'URL ni le corps reçu n'entrent au journal.
- [ ] **Step 4 : vérifier** — test vert ; `make back-test` vert (aucune autre implémentation de l'interface n'existe ; les simulacres PHPUnit suivent) ; `make back-quality` vert.
- [ ] **Step 5 : commit** — `feat(security): le refus du document d'un client MCP est tracé, hôte et cause (#651)`.

### Task 9 : admission au point d'autorisation, coexistence avec les clients pré-enregistrés — **sensible** (identification du client, SSRF, audit)

**Files:**
- Create: `backend/src/Mcp/Connection/Application/Cimd/CimdClientAdmissionInterface.php`, `CimdClientAdmission.php`
- Modify: `backend/src/Mcp/Connection/Application/AuthorizationRequestValidator.php`, `AuthorizationRequestSummary.php`, `OAuthServerMetadata.php` (docblock l. 30 : l'annonce est désormais exacte) ; `backend/src/Mcp/Connection/Infrastructure/League/LoopbackAwareAuthCodeGrant.php` ; `backend/tests/Mcp/Connection/Application/AuthorizationRequestValidatorTest.php` (le validateur reçoit une admission)
- Test: `backend/tests/Mcp/Connection/Application/Cimd/CimdClientAdmissionTest.php`, `backend/tests/Mcp/Connection/CimdAuthorizationTest.php` (fonctionnel, trait `DrivesTheOAuthFlow`)

**Interfaces:**
- Consumes: `CimdClientId::tryFrom()`, `AllowedClientDomains::allows()` (Task 1) ; `CimdClientResolverInterface::resolve()` (Task 6) ; `McpClient::discovered()`, `registration()`, `ClientRegistration` (Task 7) ; `mcpClientMetadataRefused()` (Task 8) ; `McpClientRepositoryInterface::findOneByClientId()` ; `OAuthClient::__construct(McpClient)`.
- Produces:
  - `CimdClientAdmissionInterface::REQUEST_ATTRIBUTE = 'mcp.cimd.discovered_client'` ; `admit(string $clientId): ?McpClient` (`@throws ClientMetadataRefusedException`) — `null` : league juge par les lignes ; un `McpClient` (non persisté, mode `Cimd`) : league juge par lui.
  - `CimdClientAdmission::__construct(McpClientRepositoryInterface $clients, AllowedClientDomains $allowed, CimdClientResolverInterface $resolver, SecurityAuditLoggerInterface $audit, ClockInterface $clock)`.
  - `AuthorizationRequestSummary` : paramètre de constructeur supplémentaire `public ?McpClient $discoveredClient = null` ; `registration(): ClientRegistration` (`Cimd` si `discoveredClient` est présent, `Preregistered` sinon) ; `verifiedDomain(): ?string` (l'hôte du `client_id` pour `Cimd`, `null` sinon).
  - `AuthorizationRequestValidator::__construct(AuthorizationServer $server, OAuthEndpoints $endpoints, CimdClientAdmissionInterface $admission)` ; `validate()` garde sa signature.

- [ ] **Step 1 : tests rouges.**
  - `CimdClientAdmissionTest` (doublures) :
    - `testAnIdentifierThatIsNotCimdShapedIsLeftToTheRows` (`claude-code` → `null`, résolveur intact) ;
    - `testAPreregisteredRowWinsWithoutAnyFetch` : une ligne pré-enregistrée d'identifiant `https://claude.ai/oauth/claude-code-client-metadata` → `null`, résolveur intact *(Review Focus 4)* ;
    - `testADomainOutsideTheListIsRefusedWithoutAnyFetchNorAudit` → `DomainNotAllowed` ;
    - `testAnAllowedDomainYieldsTheDiscoveredClient` (mode `Cimd`, nom du document) ;
    - `testADiscoveredRowIsJudgedAgainByItsDocument` (une ligne `cimd` existe : le résolveur est appelé, le client rendu vient du document) ;
    - `testADiscoveredRowWhoseDomainLeftTheListIsRefused` ;
    - `testARefusalAfterTheListIsAuditedWithHostAndCause` (fournisseur sur chaque cause que le résolveur peut lever) puis relancé tel quel.
  - `CimdAuthorizationTest` — après `setUpOAuthFlow()` : `$this->client->disableReboot()`, puis `self::getContainer()->set('app.mcp.cimd.http_client', new NoPrivateNetworkHttpClient(new MockHttpClient(…), PublicAddressPolicy::SUBNETS))` et `set(HostAddressResolverInterface::class, new FixedHostAddressResolver(['claude.ai' => ['1.1.1.1']]))` — le test remplace le client du récupérateur (non le `http_client` du framework) par un `MockHttpClient` entouré du même filet que `services.yaml`, ce qui garde la couche 5 en jeu sans réseau ; pools `cache.mcp_client_metadata` et `cache.rate_limiter` vidés. Demande type : `validParameters(['client_id' => ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => 'http://localhost:53124/callback'])`.
    - `testAnAllowedCimdClientReachesTheConsentPage` : 302 vers la page de consentement, comme pour un client pré-enregistré ;
    - `testADomainOutsideTheListIsAnUnknownClient` (`https://client.evil.test/metadata.json`) : 401 `invalid_client`, pas de `Location`, zéro requête sortante, zéro appel du résolveur ;
    - `testADocumentOnAPrivateAddressIsRefused` (résolveur → `10.0.0.5`) : 401 `invalid_client`, pas de `Location`, zéro requête sortante, un enregistrement d'audit `mcp-client-metadata-refused` (`clientHost: claude.ai`, `cause: address-blocked`) ;
    - `testARedirectingDocumentIsRefused` (302 vers `http://169.254.169.254/`) : 401, une seule requête sortante ;
    - `testAnOversizedDocumentIsRefused` (6 000 octets) : 401 ;
    - `testASlowDocumentIsTemporarilyUnavailable` (corps qui cale) : 503 `temporarily_unavailable`, pas de `Location` ;
    - `testAnExhaustedQuotaAnswers429` : onze `client_id` distincts de `claude.ai` servis en 404 depuis la même adresse → la onzième réponse est 429 `temporarily_unavailable`, et dix requêtes sortantes sont parties ;
    - `testARedirectUriTheDocumentDoesNotDeclareIsRefusedWithoutRedirection` (`redirect_uri=https://evil.test/cb`) : refus de league, pas de `Location` ;
    - `testARequestParameterNamedLikeTheAttributeChangesNothing` : un paramètre de requête `mcp.cimd.discovered_client=x` joint à un client inconnu rend la même réponse que sans lui ;
    - `testThePreregisteredFlowMakesNoOutboundCall` : le parcours de `CLIENT_ID` (`https://client.example.test/metadata.json`, pré-enregistré) passe avec un `MockHttpClient` qui échoue à la première requête.
- [ ] **Step 2 : vérifier l'échec** — `--filter 'CimdClientAdmissionTest|CimdAuthorizationTest'` : classes absentes ; puis, l'admission écrite seule, `CimdAuthorizationTest` échoue encore sur `invalid_client` (league ne connaît pas le client) — c'est l'échec attendu avant la surcharge du grant.
- [ ] **Step 3 : implémenter.**
  - `admit()`, dans cet ordre : `CimdClientId::tryFrom()` (`null` → `null`) ; ligne existante en mode `Preregistered` → `null` ; hôte hors liste → `ClientMetadataRefusedException::because(DomainNotAllowed)` (sans audit — D-G ; `CimdClientResolver::resolve()` et `HttpClientMetadataFetcher::fetch()` refusent aussi un hôte hors liste, le premier avant le cache et le quota, le second avant toute résolution : défense en profondeur) ; `resolve()` — un refus est tracé par `mcpClientMetadataRefused($id->host(), $e->refusal->value)` puis relancé, **sauf `DomainNotAllowed`**, relancé sans audit (D-G : jamais d'audit pour un hôte hors liste, même si le résolveur ou le récupérateur le refuse à son tour, en défense en profondeur) ; `McpClient::discovered($document, $this->clock->now())`.
  - **Note pour cette tâche : le `retryAfter` est perdu aujourd'hui.** `ClientMetadataFetchRateLimitExceededException` porte le `retryAfter` du limiteur qui refuse (celui de l'appelant ou le global), mais `CimdClientResolver` la convertit en `ClientMetadataRefusedException(Throttled)` sans le garder. Le 429 de cette tâche doit porter l'en-tête `Retry-After` : garder l'exception de quota en `previous` de `ClientMetadataRefusedException` et la relire ici, ou une autre voie choisie par la tâche ; un test le pin (`Retry-After` présent et égal à celui du limiteur).
  - `validate()` : après `wellFormedParameters()`, appeler `admit($parameters['client_id'])` ; un `ClientMetadataRefusedException` devient un `OAuthServerException` **sans `redirectUri`** — transitoire : `new OAuthServerException('The client metadata document is temporarily unavailable.', 0, 'temporarily_unavailable', 429 pour Throttled sinon 503, previous: $e)` ; définitif : `OAuthServerException::invalidClient($request)` ; un client rendu est posé par `$request->withAttribute(CimdClientAdmissionInterface::REQUEST_ATTRIBUTE, $client)` avant `validateAuthorizationRequest()` et passé au résumé (`discoveredClient`).
  - `LoopbackAwareAuthCodeGrant::getClientEntityOrFail(string $clientId, ServerRequestInterface $request): ClientEntityInterface` : si l'attribut est une instance de `McpClient` dont `getClientId()` vaut `$clientId`, rendre `new OAuthClient($client)` après le même contrôle `supportsGrantType()` que le parent ; sinon `parent::getClientEntityOrFail()`. Le docblock dit pourquoi le test de type suffit contre un attribut contrefait (le pont PSR-7 recopie les attributs de la requête Symfony, tous des chaînes de routage) et que l'échange du code, sans attribut, lit toujours la ligne.
- [ ] **Step 4 : vérifier** — tests verts ; `OAuthFlowTest`, `AuthorizationRequestValidatorTest`, `OAuthMetadataTest` verts sans modification d'assertion ; `make back-quality` vert.
- [ ] **Step 5 : commit** — `feat(mcp): un client déclaré d'un domaine autorisé est admis au point d'autorisation (#651)`.

### Task 10 : consentement — domaine vérifié, persistance à l'approbation, parcours complet de Claude Code — **sensible** (consentement, identification du client, audit)

**Files:**
- Modify: `backend/src/Mcp/Connection/Application/ConsentService.php`, `backend/src/Mcp/Connection/Presentation/Controller/ConsentController.php` (la constante `REGISTRATION` disparaît), `backend/tests/Mcp/Connection/Application/ConsentServiceTest.php`
- Test: `backend/tests/Mcp/Connection/CimdConsentFlowTest.php` (fonctionnel, mêmes doublures et même `disableReboot()` que `CimdAuthorizationTest`)

**Interfaces:**
- Consumes: `AuthorizationRequestSummary::$discoveredClient`, `registration()`, `verifiedDomain()` (Task 9) ; `McpClientRepositoryInterface::saveDiscovered()` (Task 7) ; `AuthorizationRequestRejectedException::clientNoLongerRegistered()`.
- Produces: réponse de `GET /api/oauth/consent` — `client: { id: string, name: string, registration: 'preregistered'|'cimd', domain: string|null }`, le reste inchangé (D-I).

- [ ] **Step 1 : tests rouges** (`CimdConsentFlowTest`) :
  - `testTheSummaryShowsTheVerifiedDomainAndTheDeclaredName` : `client.registration === 'cimd'`, `client.domain === 'claude.ai'`, `client.name === 'Claude Code'`, `client.id` l'URL, `redirectHost === 'localhost'` ;
  - `testAPreregisteredClientHasNoVerifiedDomain` : `registration === 'preregistered'`, `domain === null` (clé présente) ;
  - `testNoClientRowExistsBeforeApproval` : après `GET /authorize` et `GET /consent`, puis après un refus de la personne, aucune ligne `mcp_client` en mode `cimd` ;
  - `testClaudeCodeCompletesTheFlowOnARandomLoopbackPort` : approbation → redirection sur `http://localhost:53124/callback` avec `code`, `state`, `iss` → échange (même `redirect_uri`) : 200 et jetons → rafraîchissement : 200 ; une ligne `mcp_client` (`registration = 'cimd'`, nom `Claude Code`) ; **une seule** requête sortante sur tout le parcours (le cache sert les deux revalidations) ; le compteur de requêtes sortantes ne bouge pas pendant `/token` et le rafraîchissement ;
  - `testASecondApprovalRefreshesTheRowInsteadOfDuplicating` : second parcours, pool vidé, document au nom changé → toujours une ligne, au nouveau nom ;
  - `testADocumentThatDroppedTheRedirectBetweenDisplayAndApprovalGrantsNothing` : `GET /consent` réussi ; pool vidé ; le document ne déclare plus que `https://claude.ai/callback` ; l'approbation est refusée comme un refus de revalidation ; aucune connexion, aucune ligne de client *(Review Focus 5)* ;
  - `testTheGrantAuditCarriesThePublicClientId` : l'enregistrement `mcp-connection-granted` porte `clientId` égal à l'URL, de 255 caractères au plus (D-H) ; capteur d'audit : celui de `GrantRefusalAuditTest`.
  - `ConsentServiceTest` : `testAPreregisteredRowTakenWhileConsentingRefusesTheGrant` (`saveDiscovered()` rend `null` → `clientNoLongerRegistered`, rien n'est écrit).
- [ ] **Step 2 : vérifier l'échec** — `--filter 'CimdConsentFlowTest|ConsentServiceTest'` : `registration` vaut encore `preregistered`, et l'approbation lève `clientNoLongerRegistered` (aucune ligne).
- [ ] **Step 3 : implémenter.** `ConsentController` : `'registration' => $summary->registration()->value`, `'domain' => $summary->verifiedDomain()`. `ConsentService::grant()` : quand `$summary->discoveredClient` est présent, le client est `saveDiscovered($summary->discoveredClient) ?? throw AuthorizationRequestRejectedException::clientNoLongerRegistered()`, **dans** la transaction et avant `connections->add()` ; sinon le chemin actuel, inchangé. L'audit `mcp-connection-granted` reste après le COMMIT.
- [ ] **Step 4 : vérifier** — tests verts ; `make back-test` et `make back-quality` verts en entier.
- [ ] **Step 5 : commit** — `feat(mcp): l'approbation d'un client déclaré l'enregistre, le résumé montre son domaine vérifié (#651)`.

### Task 11 : page de consentement — domaine vérifié en évidence, nom marqué comme déclaré — **sensible** (consentement)

**Files:**
- Modify: `frontend/src/domain/mcp/entities/ConsentRequest.ts`, `frontend/src/infrastructure/mcp/HttpOAuthConsentRepository.ts`, `frontend/src/presentation/pages/OAuthConsentPage.vue`, `frontend/src/infrastructure/i18n/locales/fr.json`, `en.json`
- Test: `frontend/tests/infrastructure/mcp/HttpOAuthConsentRepository.spec.ts`, `frontend/tests/presentation/pages/OAuthConsentPage.spec.ts`

**Interfaces:**
- Consumes: le contrat de la Task 10.
- Produces: `export type ConsentClientRegistration = 'preregistered' | 'cimd'` ; `ConsentClient { id: string; name: string; registration: ConsentClientRegistration; domain: string | null }`.

Textes, sous `oauthConsent.client` (clés nouvelles ; les clés existantes ne changent pas) :

| Clé | Français | Anglais |
|---|---|---|
| `domainLabel` | Domaine vérifié | Verified domain |
| `domainNotice` | Ce site a lu la déclaration de ce client à cette adresse, sur ce domaine : c'est la seule information vérifiée. | This site read this client's declaration at this address, on this domain: it is the only verified information. |
| `declaredNameLabel` | Nom déclaré | Declared name |
| `declaredNameNotice` | Le nom que ce client se donne lui-même. Ce site ne l'a pas vérifié : fiez-vous au domaine. | The name this client gives itself. This site has not verified it: rely on the domain. |
| `registration.cimd` | Déclaré par le client, dans un document publié sur son domaine | Declared by the client, in a document published on its domain |

- [ ] **Step 1 : tests rouges.**
  - `HttpOAuthConsentRepository.spec.ts` : un résumé `registration: 'cimd', domain: 'claude.ai'` est accepté ; `cimd` avec `domain` `null`, absent, vide ou non-chaîne → `ConsentFailedError` ; `preregistered` avec `domain: null` accepté ; `preregistered` avec un domaine → `ConsentFailedError` ; une valeur inconnue de `registration` → `ConsentFailedError`.
  - `OAuthConsentPage.spec.ts`, fixture `client: { id: 'https://claude.ai/oauth/claude-code-client-metadata', name: 'Assistant Déclaré', registration: 'cimd', domain: 'claude.ai' }`, en français puis en anglais :
    - `[data-testid="consent-client-domain"]` contient `claude.ai`, sous le libellé « Domaine vérifié » / « Verified domain » ;
    - le domaine précède le nom dans l'ordre du document ;
    - le nom porte le libellé « Nom déclaré » / « Declared name » et sa mise en garde, et **pas** le texte `nameNotice` (« … l'administrateur du site a enregistré ce client ») ;
    - le mode affiché est `registration.cimd` ;
    - un nom `<b>x</b>` s'affiche en texte (aucun élément `b` dans la page) ;
    - avec la fixture pré-enregistrée (`domain: null`) : aucun `consent-client-domain`, libellés et mise en garde actuels inchangés ;
    - l'audit axe de la page passe dans les deux variantes.
- [ ] **Step 2 : vérifier l'échec** — `npx vitest run tests/infrastructure/mcp/HttpOAuthConsentRepository.spec.ts tests/presentation/pages/OAuthConsentPage.spec.ts` : le dépôt refuse `cimd`, la page n'a pas `consent-client-domain`.
- [ ] **Step 3 : implémenter.** `parseSummary()` : `registration` dans les deux valeurs, `domain` chaîne non vide si et seulement si `cimd`. Page, section « Le client », même `<dl>` : pour `cimd`, une première paire `domainLabel` / `<code data-testid="consent-client-domain">` (même mise en forme que `consent-redirect-host`, classe `text-break`) suivie de `domainNotice`, puis le nom sous `declaredNameLabel` avec `declaredNameNotice` ; pour `preregistered`, le rendu actuel. Le domaine et le nom restent en interpolation de texte (jamais `v-html`). Le sens ne passe par aucune couleur ni icône seule : c'est le libellé qui dit « vérifié » ou « déclaré » (`frontend-a11y.md`). Aucun `<h*>` ajouté, donc aucun saut de niveau.
- [ ] **Step 4 : vérifier** — `make front-test`, `make front-lint` (clés i18n toutes utilisées, présentes dans les deux langues), `make front-build` verts.
- [ ] **Step 5 : commit** — `feat(front): la page de consentement distingue le domaine vérifié du nom déclaré (#651)`.

### Task 12 : règles, ADR, registre, notes de release, vérifications de fin

**Files:**
- Create: `.claude/rules/mcp-oauth-cimd.md`
- Modify: `.claude/rules/mcp-oauth.md`, `.claude/rules/mcp.md` (index : neuf règles), `.claude/rules/security-authentication-audit.md` (nouvel événement), `.claude/CLAUDE.md` (ligne du tableau des règles), `docs/adr/0006-serveur-mcp.md`, `docs/rgpd/registre-traitements.md`, `CONTEXT.md`, `tasks/todo.md`

- [ ] **Step 1 : règle `mcp-oauth-cimd.md`.** En-tête `paths:` : `backend/src/Mcp/Connection/**/Cimd/**`, `backend/tests/Mcp/Connection/**/Cimd/**`, `backend/src/Mcp/Connection/Domain/ValueObject/CimdClientId.php`, `AllowedClientDomains.php`, `ClientMetadataDocument.php`, `ClientRegistration.php`, `backend/tests/Mcp/Connection/Cimd*Test.php`. Contenu, en anglais comme les autres règles : qui juge quoi (D-A) ; les cinq couches et pourquoi la résolution est à nous (D-D) ; la lecture bornée et ses valeurs (D-E) ; cache et quota (D-F) ; les refus et ce qui est tracé (D-G) ; l'attribut PSR-7 et son test de type (D-B) ; `saveDiscovered()` et la ligne pré-enregistrée qui gagne (D-C) ; « aucun test sur le réseau ni le DNS » et les doublures ; les limites acceptées ci-dessous. `mcp-oauth.md` : retirer « One announcement ahead of the code, accepted… » et remplacer « a CIMD client (#651) will declare its own… » par un renvoi à la nouvelle règle ; le fichier reste sous 50 000 octets.
- [ ] **Step 2 : vérifier les règles** — `bash tools/check-claude-rules.sh` vert (chaque règle a ses `paths:`, chaque glob trouve un fichier suivi, aucune ne dépasse 50 000 octets) ; les tests d'outillage (`tools/tests/*.test.sh`) verts.
- [ ] **Step 3 : ADR 0006, D3** — amendement daté du 2026-11-13 : l'autorisation juge le document, les points de jeton la ligne ; le client est écrit à l'approbation ; **un appel sortant synchrone part d'une route que tout anonyme peut appeler** (`GET /api/oauth/authorize`) — écart assumé au principe « aucun appel sortant sur un chemin de rendu public » des ADR 0002 et 0004, borné par la liste de domaines, le quota par adresse, le cache, la taille et le délai ; les limites acceptées. `CONTEXT.md`, sous « Client MCP » : « client pré-enregistré » et « client déclaré » (par son document de métadonnées, CIMD), une phrase chacun.
- [ ] **Step 4 : registre RGPD** — §2 : le limiteur `mcp_client_metadata`, clé = adresse IP (une IPv6 réduite à son /64), fenêtre de 10 minutes, le plafond `mcp_client_metadata_global`, clé constante, sans donnée personnelle, fenêtre de 10 minutes, et le compteur `mcp_client_metadata_known_client`, clé = empreinte SHA-256 du `client_id` (l'URL publique du document d'un client : l'empreinte n'est **pas un secret**, aucune donnée personnelle), fenêtre de 10 minutes, jamais refus à lui seul (un compteur plein se replie sur le plafond) ; §3.4 : le nom et les redirections d'un client déclaré viennent de son document public, et la requête qui le lit ne transmet rien de la personne (ni son adresse, ni son compte : seuls l'adresse du serveur et son `User-Agent`) ; §6 : l'événement `mcp-client-metadata-refused` (hôte, cause, adresse du demandeur) ; §8 : aucune donnée personnelle ne part vers les domaines autorisés ; date de révision en tête.
- [ ] **Step 5 : `tasks/todo.md`** — cocher la Tâche 12 ; notes de release : une migration additive (`Version20261113120000`, colonne à défaut, aucune contrainte d'ordre avec le déploiement) ; **aucune variable d'environnement nouvelle** ; un pool de cache et un limiteur de plus, dans la table `cache_items` existante (aucune migration, purgés par le CronJob `cache:pool:prune` existant) ; **sortie réseau** : les pods du backend doivent résoudre et joindre `claude.ai:443` et `chatgpt.com:443`. Reports inscrits sous T13 (#652) : lister, supprimer et révoquer un client déclaré, retirer un domaine de la liste (les connexions déjà accordées survivent au retrait), audit de l'enregistrement d'un client. Reports inscrits sous T14 (#653) : essai en préprod avec les vrais domaines (c'est le seul essai du résolveur natif), vérification de la sortie réseau et d'une éventuelle `NetworkPolicy`, lecture du document de ChatGPT (`https://chatgpt.com/oauth/client.json`) contre les règles de la Task 5, mesurer les délais de résolution du pod (`resolv.conf`) et décider d'un `dnsConfig` (limite acceptée du résolveur natif).
- [ ] **Step 6 : vérifications de fin, sorties lues** — `make back-quality`, `make back-test`, `make front-test`, `make front-lint`, `make front-build` : tous verts. `git status` propre hors des fichiers du plan ; `grep -rn 'mcp.cimd' backend/config` ne montre que le paramètre versionné.
- [ ] **Step 7 : commit** — `docs(mcp): règle CIMD, ADR 0006 amendé, registre et notes de release (#651)`. La PR vise `feature/379-mcp-paliers` ; l'issue #651 se ferme à la main après la fusion.

## Limites acceptées (à recopier dans la règle et dans l'ADR)

- **Un document retiré ou modifié n'arrête pas une connexion déjà accordée** : elle vit jusqu'à son échéance ou sa révocation (D-A). De même, retirer un domaine de la liste arrête les nouvelles autorisations, pas les connexions existantes.
- **Le quota est par adresse** (une IPv6 par son /64) : une attaque distribuée fait partir autant de récupérations qu'elle a d'adresses ou de /64, chacune vers un domaine de la liste, chacune d'au plus 3 secondes et 5 120 octets (D-F).
- **Plafond global** (`mcp_client_metadata_global`, 100 par 10 minutes) : **10 sources suffisent à l'épuiser** (10 IPv4, ou 10 /64 d'un même /48). Il refuse alors le **premier** enregistrement d'un client jamais vu ; une fois la Task 7 livrée, un client déjà enregistré passe par la voie des clients connus (D-F), borné par le quota de son appelant et par son propre compteur, qui plein le renvoie au plafond (avant elle, il est refusé comme les autres sur un cache manqué). Le plafond saturé refuse donc aussi un client connu dont le compteur est plein. Les connexions déjà accordées et les documents en cache ne sont pas touchés.
- **Le compteur d'un client connu est épuisable par quiconque le nomme** (`mcp_client_metadata_known_client`, 10 par 10 minutes) : son `client_id` est public, **une seule adresse** suffit (dix `/authorize` simultanés à l'expiration du cache). **Il ne bloque plus le client à lui seul** (décision du 2026-11-15, relecture de `91050866`) : plein, il renvoie la voie sur le plafond commun, qui accepte tant qu'il a de la place. Fermer la porte à un client connu demande de saturer le plafond (10 sources, limite du plafond ci-dessus) **et** son compteur (une source de plus, dix `/authorize` simultanés à l'expiration de son document), et un client connu n'est jamais plus refusé qu'un inconnu ; un client enregistré passe donc un plafond saturé pour ses 10 premières récupérations de la fenêtre, pas au-delà. Accepté : sans ce compteur, nommer un client approuvé ouvrait une voie sortante bornée par le seul nombre de /64 de l'attaquant ; avec lui, la voie qui contourne le plafond est bornée à 10 récupérations par client et par fenêtre : le compteur décide qui paie, il ne borne pas le total (plafond commun plus 10 par client approuvé, par fenêtre de 10 minutes). **Alternative écartée** : un verrou par client (une récupération à la fois), qui ne laisserait le compteur se remplir que pendant une vraie panne du domaine du client, au prix d'une concurrence entre processus.
- **Course entre le coup d'œil au compteur d'un client connu et sa consommation** : le compteur peut se remplir entre les deux ; le plafond prend alors l'appel sans reprendre d'unité à l'appelant, qui ne perd son unité sans rien obtenir que si le plafond est plein aussi. Fermer la fenêtre demanderait un verrou commun aux limiteurs, hors de proportion.
- **Course entre le coup d'œil au plafond et sa consommation** : le plafond peut se remplir entre les deux ; l'unité déjà prise au quota de l'appelant est alors perdue. La fenêtre est de quelques millisecondes, mais **sous saturation chaque essai peut y perdre une unité** (pas une fois au total) : un appelant qui insiste pendant qu'une attaque tient le plafond plein peut voir fondre son propre quota. Accepté : ce cas ne se produit que plafond déjà plein, où l'appelant est de toute façon refusé.
- **La clé constante `global` est un point chaud** : un verrou advisory et une ligne de `cache_items` communs à tous les pods, **pris deux fois** (coup d'œil, puis consommation) sur chaque cache manqué d'un client inconnu accepté par son quota ; une rafale s'y sérialise (≈ 1,5 ms par `consume()`, mesure d'ADR 0005, donc ≈ 3 ms par récupération sur cette seule clé).
- **Un appelant que son propre quota refuse touche quand même la clé `global`** : le coup d'œil (`consume(0)`) précède le quota de l'appelant, donc chaque essai d'un appelant déjà épuisé prend le verrou du plafond et lit sa ligne. Accepté : la zone nginx `oauth` borne déjà le débit par adresse avant PHP, et un attaquant gagne plus à prendre un /64 neuf (quota plein) qu'à insister sur un /64 épuisé. *Écarté* : un `consume(0)` sur l'appelant d'abord, qui coûterait une prise de verrou de plus à **chaque** récupération, légitime comprise, pour épargner seulement les essais déjà refusés.
- **Pas de cache négatif** : un document invalide ou un refus de récupération n'est jamais gardé (draft §6.2), donc chaque essai coûte une récupération et une unité de quota ; le quota par adresse et le plafond global bornent ce coût.
- **Pas de requête unique sur un cache manqué** : N requêtes simultanées pour le même `client_id` consomment N unités de quota et lancent N récupérations, aucun verrou ne les fusionne.
- **`no-store`, `no-cache` et `max-age=0` sont lus comme absents** : le document est quand même gardé 300 s, plancher décidé en D-F.
- **La liste des domaines est vérifiée à trois endroits** (l'admission, le résolveur, le récupérateur) : défense en profondeur assumée ; une évolution de la sémantique de la liste se fait aux trois.
- **Les autres limiteurs du site comptent encore une IPv6 par adresse entière** — suivi : #775 (applicatif et zones nginx).
- **`EmbeddedIpv4` ne reconnaît ni le préfixe NAT64 à usage local `64:ff9b:1::/48` (RFC 8215) ni les préfixes NAT64 propres à un réseau** : la place de l'IPv4 y dépend de la longueur choisie par l'opérateur. Des clients derrière un tel traducteur partagent un /64 au quota, et `TruncatedIp` les tronque en /48. Seul `64:ff9b::/96` est reconnu.
- **Teredo (`2001::/32`) n'est pas reconnu** : des clients d'un même serveur Teredo partagent un /64 ; le protocole est quasi éteint.
- **Le blocage de `NoPrivateNetworkHttpClient` se lit `unreachable`** au journal d'audit, pas `address-blocked` : son exception n'a pas de type propre, et son message n'est pas lu (Task 3).
- **Le résolveur natif n'a pas de test** dans la suite ; son essai est celui de la préprod (T14).
- **Le résolveur natif n'a aucun délai propre** (acceptée le 2026-11-14) : `dns_get_record()` suit `resolv.conf` (délai × tentatives × serveurs), hors des 3 s de `max_duration`. Un DNS lent d'un domaine autorisé retient un worker FPM ; la liste de domaines réduit le scénario à une panne du DNS de `claude.ai` ou `chatgpt.com`. À mesurer en préprod (T14), qui décidera d'un `dnsConfig` du pod.
- **Un document qui omet `token_endpoint_auth_method` est refusé** (RFC 7591 §2) ; si celui de ChatGPT l'omet, à rediscuter à l'essai de T14.
- **Un hôte IPv6 seul est refusé**, ainsi qu'un `client_id` portant un port.
- **Un document dont une seule redirection est irrecevable est refusé en entier** ; si celui de ChatGPT en déclare une que `RedirectUri` refuse, ChatGPT ne passera pas tant que la règle n'est pas rediscutée (essai de T14).
- **Le nom affiché dans « Mes connexions MCP » est celui du dernier accord** donné à ce client, par quiconque.

## Auto-relecture du plan

- **Couverture du ticket** : parcours complet d'un client CIMD d'un domaine autorisé, document de Claude Code et port aléatoire (Task 10) ; domaine hors liste (Tasks 1, 9) ; adresse privée après résolution (Tasks 2, 3, 9) ; redirection, document trop gros ou trop lent (Tasks 4, 9) ; page de consentement, vérifié et déclaré distingués, Vitest (Task 11) ; `make back-quality`, `make back-test`, `make front-test` (Task 12). Reports de la tâche : annonce des métadonnées (D-J, Tasks 9 et 12) ; domaine du client au consentement (D-I, Tasks 10 et 11) ; `client_id` dans l'audit (D-H, Task 10).
- **Cohérence des types** : `CimdClientId` (1) → `fetch()` (3-4) → `ClientMetadataDocument::fromJson()` (5) → `resolve()` (6) → `McpClient::discovered()` (7) → `admit()` et attribut (9) → `saveDiscovered()` (7, appelé en 10) ; `ClientMetadataRefusal` (3) est la seule source des causes, de l'audit (8, 9) au statut HTTP (9) ; `registration` / `domain` (10) sont lus par `parseSummary()` (11).
- **Review Focus** : les cinq lignes ont chacune leur test nommé, dans la tâche qui possède le code.
