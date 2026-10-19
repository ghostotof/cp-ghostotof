# SPEC 0007 — Serveur MCP : le portfolio interrogé par l'assistant de la personne qui le consulte

> Statut : **rédigée** le 2026-10-19, à découper en tickets (`/mattpocock-skills:to-tickets`).
> Issue : #379. Décisions : [ADR 0006](../../docs/adr/0006-serveur-mcp.md), qui amende l'ADR 0003
> (D1) et l'ADR 0004 (D1, D3). Vocabulaire : [`CONTEXT.md`](../../CONTEXT.md), section « Accès MCP ».
> Branche de la spec : `feature/379-mcp-paliers`.
>
> Deux phases, livrables séparément : **phase 1**, paliers anonyme et de base, sans authentification ;
> **phase 2**, palier de confiance par une connexion MCP (OAuth 2.1).
>
> ⚠️ **Ce fichier vit dans un dépôt public** (`.claude/` est versionné, hors `CLAUDE.local.md`).
> Aucun secret, aucune adresse réelle, aucun nom de domaine de production, aucun nom de personne :
> le domaine du site s'écrit `<site>`. Voir le journal en fin de fichier.

---

## Problem Statement

Une personne qui s'intéresse au profil de l'auteur du site — recruteur, responsable technique, pair —
travaille de plus en plus avec son propre assistant IA : Claude, ChatGPT, un assistant d'IDE. Pour
qu'il réponde sur ce profil, elle doit aujourd'hui parcourir les pages, copier des extraits, les
coller dans la conversation, et recommencer dans l'autre langue ou pour une autre question.
L'assistant de carrière du site ne l'aide pas : il est réservé au palier de confiance, tourne sur le
modèle choisi par le site, et ne s'intègre pas à l'outil où elle travaille.

Du côté de l'auteur, le portfolio veut montrer la maîtrise d'un protocole devenu standard (MCP) sans
rien céder sur ses invariants : rien de nominatif sans `ROLE_TRUSTED`, aucun appel de modèle depuis le
site, aucun état sur le pod, et une trace de chaque lecture du CV nominatif.

## Solution

Le site expose un **serveur MCP** sur `https://<site>/api/mcp` (transport Streamable HTTP, POST et
GET). La personne l'ajoute à son **client MCP** comme n'importe quel serveur distant ; son assistant
peut alors interroger le portfolio avec les jetons de la personne, et le site n'appelle aucun modèle.

- **Sans authentification (phase 1)**, le serveur MCP sert, en lecture seule, exactement ce que les
  routes des paliers anonyme et de base servent déjà : À propos, Qualité, Parcours technique,
  Contributions, Incidents, Veille technique, CV sans identité, études de cas, plus l'adresse du
  formulaire de contact. Un prompt aide à présenter l'auteur pour un poste donné. Une page publique
  bilingue « Brancher votre assistant » explique comment l'ajouter à chaque client courant.
- **Au palier de confiance (phase 2)**, la personne accorde à son client MCP une **connexion MCP** par
  OAuth 2.1 : son client la redirige vers le site, elle s'y connecte, consent, et le client reçoit
  l'accès au **corpus nominatif** — le texte même de l'assistant de carrière — et un lien vers la
  page du site où se télécharge le CV. Elle voit et révoque ses connexions depuis « Mes connexions
  MCP » ; l'administrateur voit toutes les connexions au backoffice. Chaque lecture nominative est
  inscrite au journal d'audit.

## User Stories

### Phase 1 — personne du palier anonyme ou de base, depuis son client MCP

1. En tant que personne qui évalue un profil, je veux ajouter l'URL du serveur MCP du site à mon
   client MCP sans créer de compte, afin d'interroger le portfolio depuis l'outil où je travaille.
2. En tant que personne utilisant Claude Code, je veux une ligne de commande prête à copier pour
   ajouter le serveur, afin de ne pas chercher la syntaxe.
3. En tant que personne utilisant Claude Desktop ou claude.ai, je veux savoir où ajouter un
   connecteur distant et quelle URL saisir, afin de brancher le serveur sans essai-erreur.
4. En tant que personne utilisant ChatGPT, je veux les étapes pour ajouter le serveur comme
   connecteur, afin de l'utiliser dans mes conversations.
5. En tant que personne utilisant Cursor, je veux le fragment de configuration JSON à coller, afin de
   brancher le serveur dans mon éditeur.
6. En tant que personne utilisant l'AI Assistant de PhpStorm, je veux la configuration qui fonctionne
   avec lui, afin de brancher le serveur depuis l'IDE.
7. En tant que personne de passage, je veux lire sur la page « Brancher votre assistant » ce que le
   serveur sert et ne sert pas, afin de savoir à quoi m'attendre avant de l'ajouter.
8. En tant que personne de passage, je veux que la page existe en français et en anglais, afin de la
   lire dans ma langue.
9. En tant que personne qui demande « quelles sont les compétences de l'auteur ? », je veux que mon
   assistant obtienne le Parcours technique du site, afin que sa réponse soit fondée sur la source et
   non inventée.
10. En tant que personne qui s'intéresse à la façon de travailler de l'auteur, je veux que mon
    assistant lise les principes et traits de qualité, afin de comprendre ses exigences.
11. En tant que personne qui veut une vue d'ensemble, je veux que mon assistant lise la page À
    propos, afin de résumer le site et son auteur sans l'identifier.
12. En tant que personne qui évalue l'activité open source, je veux que mon assistant liste les
    contributions techniques, afin d'en juger la nature.
13. En tant que personne qui évalue la rigueur en production, je veux que mon assistant liste les
    incidents et les invariants qui en sont sortis, afin d'apprécier la façon de traiter une panne.
14. En tant que personne curieuse de la pile technique, je veux que mon assistant lise la veille
    technique, afin de savoir sur quoi le site tourne et s'il est à jour.
15. En tant que personne qui veut aller plus loin, je veux que mon assistant lise le CV sans identité,
    afin de connaître les domaines de compétence, leur ancienneté et leurs réalisations.
16. En tant que personne qui veut des exemples concrets, je veux que mon assistant lise les études de
    cas, afin de juger les compromis techniques sur des problèmes réels.
17. En tant que personne qui souhaite prendre contact, je veux que mon assistant me donne l'adresse du
    formulaire de contact, afin d'écrire à l'auteur sans quitter ma conversation pour la chercher.
18. En tant que personne qui recrute pour un poste précis, je veux un prompt « présenter l'auteur pour
    ce poste », afin que mon assistant parcoure les bons outils et me rende une présentation ciblée.
19. En tant que personne anglophone, je veux demander les contenus en anglais par un paramètre de
    langue, afin que mon assistant travaille sur la version linguistique anglaise.
20. En tant que personne qui ne précise pas de langue, je veux recevoir la version française par
    défaut, afin d'obtenir une réponse sans erreur.
21. En tant que personne dont le client ne passe au modèle que le bloc texte, je veux que ce bloc
    contienne le même JSON que le contenu structuré, afin que mon assistant voie les mêmes données.
22. En tant que personne dont le client exploite `structuredContent`, je veux un `outputSchema` déclaré
    pour chaque outil, afin que le client valide et présente la sortie.
23. En tant que personne dont un contenu est vide côté site, je veux une liste vide plutôt qu'une
    erreur, afin que mon assistant dise qu'il n'y a rien plutôt qu'il échoue.
24. En tant que personne dont le client ouvre un flux GET sur l'URL, je veux une réponse valide et non
    un 405, afin que l'AI Assistant de PhpStorm et les clients similaires se connectent.
25. En tant que personne qui liste les outils, je veux voir l'outil du corpus nominatif annoncé comme
    exigeant une connexion MCP, afin de savoir qu'il existe et comment l'ouvrir.
26. En tant que personne au palier anonyme, je veux que l'appel de l'outil nominatif me réponde
    clairement qu'une connexion MCP est requise, afin de ne pas croire à une panne.

### Phase 2 — personne du palier de confiance, connexion MCP

27. En tant que personne du palier de confiance, je veux que mon client MCP me redirige vers le site
    pour autoriser l'accès, afin de ne jamais saisir mon mot de passe dans un outil tiers.
28. En tant que personne du palier de confiance non connectée au site, je veux passer par la page de
    connexion puis revenir à l'autorisation, afin de ne pas recommencer la procédure.
29. En tant que personne du palier de confiance, je veux voir sur la page de consentement quel client
    demande l'accès, avec ce qui est vérifié (le domaine de son identifiant) distingué de ce qu'il
    déclare (son nom), afin de ne pas autoriser un client qui se fait passer pour un autre.
30. En tant que personne du palier de confiance, je veux voir sur la page de consentement ce que la
    connexion ouvrira — le corpus nominatif, en lecture — et vers quel fournisseur il partira, afin de
    consentir en connaissance de cause.
31. En tant que personne du palier de confiance, je veux pouvoir refuser, afin que le client ne reçoive
    rien.
32. En tant que personne du palier de confiance, je veux que mon assistant lise le corpus nominatif
    une fois la connexion accordée, afin qu'il réponde sur le parcours complet.
33. En tant que personne du palier de confiance, je veux recevoir avec le corpus un lien vers la page
    du site où se télécharge le CV, afin d'obtenir le document lui-même.
34. En tant que personne du palier de confiance, je veux lister mes connexions MCP (client, date
    d'octroi, dernier usage, expiration), afin de savoir quels outils lisent mon accès.
35. En tant que personne du palier de confiance, je veux révoquer une connexion MCP, afin qu'elle
    n'ouvre plus rien dès la requête suivante.
36. En tant que personne du palier de confiance, je veux qu'une connexion inutilisée expire d'elle-même
    après 30 jours et au plus tard 90 jours après son octroi, afin de ne pas laisser d'accès dormant.
37. En tant que personne dont le client se reconnecte, je veux que le renouvellement du jeton soit
    transparent tant que la connexion est valide, afin de ne pas consentir à chaque session.
38. En tant que personne utilisant Claude (Code, Desktop, claude.ai), je veux que le client déclenche
    l'autorisation au premier appel de l'outil nominatif, afin de ne pas configurer OAuth à la main.
39. En tant que personne utilisant ChatGPT, je veux que le connecteur propose l'autorisation à l'appel
    de l'outil nominatif, afin d'ouvrir la connexion dans l'interface de ChatGPT.
40. En tant que personne utilisant PhpStorm, je veux que la page « Brancher votre assistant » me dise
    que le palier de confiance passe par Claude Code dans le terminal de l'IDE, afin de ne pas chercher
    une option qui n'existe pas encore.

### Administrateur et auteur

41. En tant qu'administrateur, je veux voir au backoffice toutes les connexions MCP (compte, client,
    octroi, dernier usage, état), afin de savoir à qui le corpus nominatif est ouvert.
42. En tant qu'administrateur, je veux enregistrer au backoffice un client MCP pré-enregistré
    (identifiant, nom, URI de redirection), afin d'ouvrir le palier de confiance à un client qui ne
    publie pas de document de métadonnées.
43. En tant qu'administrateur, je veux que retirer le palier de confiance à un compte coupe ses
    connexions MCP à la requête suivante, afin qu'une décision d'accès ait un effet immédiat.
44. En tant qu'auteur, je veux que chaque lecture du corpus nominatif soit inscrite au journal d'audit
    (compte, connexion MCP, client, horodatage, outil), afin de savoir à qui mon CV a été ouvert.
45. En tant qu'auteur, je veux que l'octroi et la révocation d'une connexion MCP soient inscrits au
    journal d'audit, afin de reconstituer la vie d'un accès.
46. En tant qu'auteur, je veux qu'aucune description d'outil, aucun prompt ni le champ `instructions`
    ne me nomme, afin que rien de nominatif ne sorte au palier anonyme.
47. En tant qu'auteur, je veux que le serveur ne serve jamais un contenu ou un champ qu'aucune route du
    même palier ne sert, afin que le serveur MCP n'ouvre pas une fuite à côté de l'API.
48. En tant qu'auteur, je veux que la suite échoue si j'ajoute une route de contenu sans décider de
    l'exposer ou de l'exclure du serveur MCP, afin que l'oubli soit impossible.
49. En tant qu'auteur, je veux un journal des appels d'outils avec l'IP tronquée, afin de mesurer
    l'usage et repérer un abus sans conserver de donnée personnelle complète.
50. En tant qu'auteur, je veux que le registre RGPD décrive les nouveaux traitements, afin que la
    politique de confidentialité reste vraie.

### Exploitation

51. En tant qu'exploitant, je veux une zone nginx qui limite les appels MCP par IP, afin qu'un
    aspirateur ou un client en boucle ne sature pas le backend.
52. En tant qu'exploitant, je veux une zone nginx du niveau de `login` sur les points OAuth, afin de
    freiner le bourrage de codes et de jetons.
53. En tant qu'exploitant, je veux que les sessions MCP vivent dans un pool de cache DBAL, afin que
    deux réplicas sur disque en lecture seule servent le même client.
54. En tant qu'exploitant, je veux que les jetons expirés soient purgés par une tâche planifiée, afin
    que les tables OAuth ne grossissent pas sans fin.
55. En tant qu'exploitant, je veux qu'un appel authentifié ne coûte qu'une requête SQL
    d'authentification, afin que le serveur tienne la charge sans cache d'identité.
56. En tant qu'exploitant, je veux que le serveur MCP n'accepte que l'hôte du site (`allowed_hosts`
    dérivé de l'URL publique déjà réglée par environnement), afin de fermer le rebond DNS.
57. En tant qu'exploitant de la préprod, je veux que Basic Auth soit levée sur les seuls chemins MCP et
    OAuth en phase 2, afin qu'un client MCP puisse s'y authentifier par Bearer.

## Implementation Decisions

### Socle (phase 1)

- **Dépendances.** `symfony/mcp-bundle` et `mcp/sdk`, **pinnés en version exacte** (ADR 0006 D7) ;
  pas d'`api-platform/mcp`. La version retenue est vérifiée au ticket d'installation ; la ligne 0.9 de
  `mcp/sdk` a cassé l'API publique, le bundle utilisé doit lui être compatible.
- **Contexte borné.** Un nouveau contexte `Mcp` (Application, Infrastructure, Presentation) porte le
  serveur, les outils, le prompt et son journal. Il ne contient aucune règle métier de contenu : il lit
  les contextes `Portfolio/*` par leurs providers publics, comme `CorpusRenderer` le fait déjà.
  Les connexions MCP et le serveur d'autorisation (phase 2) vivent dans `Security`, à côté des comptes
  et du journal d'audit, parce qu'ils décident d'une autorisation, pas d'un contenu.
- **Endpoint.** `/api/mcp`, transport Streamable HTTP, POST et GET. Il reste sous `/api` (ADR 0006,
  alternatives écartées) : l'Ingress n'envoie au backend que `/api`. Le SDK répond 405 au GET (il
  n'ouvre pas de flux, ce que la spécification MCP admet) ; une enveloppe du contrôleur du bundle y
  répond par un flux SSE vide (`retry: 300000`) aussitôt fermé — jamais un flux tenu ouvert, qui
  retiendrait un worker FPM par client (précision de la tâche 1, 2026-10-19).
- **Firewall dédié** `mcp`, motif `^/api/mcp$`, déclaré **avant** le firewall `api`, sans état, **sans
  aucun authentificateur à cookie** — en phase 1 sans authentificateur du tout, en phase 2 avec le
  seul authentificateur Bearer de la connexion MCP. `CsrfCookieRequestSubscriber` reçoit une exclusion
  pour ce seul préfixe, sûre précisément parce que le firewall n'accepte jamais de cookie (ADR 0006
  D4).
- **Exposition.** `/api/mcp` entre dans `PUBLIC_PATHS` avec sa justification écrite (canal de lecture,
  ne sert rien qu'une route du même palier ne sert, cf. ADR 0006 D1–D2).
- **Hôte.** `allowed_hosts` du serveur = l'hôte du site, dérivé d'`APP_FRONTEND_BASE_URL`
  (`%env(key:host:url:…)%`), déjà posée par overlay : même hôte que l'API derrière l'Ingress, aucune
  variable ni ConfigMap nouvelle ; `localhost` en dev et en test (précision de la tâche 1).
- **Sessions MCP.** Store `cache` du bundle sur un **pool dédié** adossé à l'adaptateur DBAL existant
  (table `cache_items`), durée de vie d'une heure. La purge des entrées expirées est déjà assurée par
  `cache:pool:prune` dans le CronJob de purge existant : aucune tâche nouvelle.
- **`instructions` du serveur** : décrivent le site comme « le portfolio de l'auteur du site », les
  paliers, et invitent à appeler les outils plutôt qu'à deviner. Elles ne nomment pas l'auteur
  (ADR 0006 D8).

### Catalogue des outils (phase 1)

Chaque outil est un service `#[McpTool]`, en lecture seule, annoté comme tel (`readOnlyHint`).
Ceux dont la route prend une langue acceptent un paramètre `locale` facultatif, énuméré `fr` | `en`,
`fr` par défaut ; les autres n'en déclarent pas.

| Outil | Route adossée | Palier | Langue |
|---|---|---|---|
| `get_about` | `GET /api/about/{locale}` | anonyme | oui |
| `get_quality` | `GET /api/quality/{locale}` | anonyme | oui |
| `get_technical_background` | `GET /api/experience/technologies` | anonyme | non |
| `list_contributions` | `GET /api/contributions/{locale}` | anonyme | oui |
| `list_incidents` | `GET /api/incidents/{locale}` | anonyme | oui |
| `get_tech_watch` | `GET /api/watch` | anonyme | non |
| `get_contact_info` | page publique `/{locale}/contact` (exception listée) | anonyme | oui |
| `get_anonymous_cv` | `GET /api/anonymous-cv/{locale}` | base | oui |
| `list_case_studies` | `GET /api/case-studies/{locale}` | base | oui |
| `get_nominative_corpus` | corpus nominatif (exception listée), phase 2 | confiance | oui |

- **Un seul lecteur de payload.** Un module de l'Infrastructure `Mcp` reçoit une opération API
  Platform (classe de ressource + opération) et ses variables d'URI, appelle le provider de cette
  opération et normalise le résultat **avec le contexte de normalisation de cette même opération**, au
  format `json`. Tous les outils adossés à une route passent par lui ; c'est ce qui garantit l'égalité
  avec la route. Un outil ne touche jamais un repository ni une classe de backoffice.
- **Forme de la sortie.** `structuredContent` est un objet JSON (la spécification MCP l'exige) :
  - route qui rend un objet → `structuredContent` = cet objet, à l'identique ;
  - route qui rend une collection → `structuredContent` = `{"items": <le tableau rendu par la route>}`.
  Le bloc texte porte ce même objet sérialisé. L'`outputSchema` décrit cet objet. C'est la seule
  transformation admise, et elle est précisée dans l'ADR 0006 (D1).
- **Langue invalide** (hors énumération) : erreur de paramètres JSON-RPC `-32602`, rendue par le SDK
  avant l'outil — le schéma d'entrée énumère les langues, et le SDK le valide. Une erreur d'outil
  exigerait de retirer cette énumération, que le modèle lit pour choisir (précision de la tâche 1).
- **Contenu absent dans la langue** (la route répond 404, ex. aucun réglage « À propos ») : erreur
  d'outil (`isError: true`) au message littéral, jamais une erreur interne `-32603`.
- **Contenu vide** : liste vide, comme la route.
- **Veille sans manifeste** : la sortie de la route telle quelle (volet vulnérabilités « non
  analysé ») ; ce n'est pas une erreur.
- **`get_contact_info`** rend `{"contactPageUrl": "https://<site>/<locale>/contact"}` construit depuis
  `APP_FRONTEND_BASE_URL`, comme `allowed_hosts` ; il ne rend ni adresse e-mail ni rien d'autre.
- **`get_nominative_corpus`** est **toujours listé**, sa description dit qu'il exige une connexion MCP
  (ADR 0006 D3). En phase 1, son appel répond l'erreur d'outil « connexion MCP requise » ; en phase 2,
  le mécanisme décrit plus bas.

### Prompt (phase 1)

- `present_for_role`, arguments `role` (texte libre, requis, borné en longueur) et `locale`
  facultative. Il rend un message utilisateur qui demande au modèle de présenter l'auteur du site pour
  ce poste, en s'appuyant **uniquement** sur les outils du serveur, en citant l'outil d'où vient chaque
  affirmation, et en disant ce qu'il ne sait pas. Il ne nomme pas l'auteur et ne contient aucun
  contenu du site : il oriente vers les outils.
- Le texte de `role` est inséré comme donnée délimitée, pas comme instruction ; sa longueur est bornée
  (refus au-delà), parce qu'un prompt construit côté serveur ne doit pas devenir un relais d'injection.

### Test d'exposition symétrique (phase 1)

- Construit comme `ApiRouteExposureTest` et `CorpusSourcesTest` : il lit **le routeur réel et le
  registre d'outils réel** du conteneur, pas une déclaration.
- Sens 1 : chaque route de contenu de `PUBLIC_PATHS ∪ BASE_TIER_PATHS` (les routes GET qui servent un
  contenu, hors infrastructure API Platform) a un outil, ou figure dans une liste d'exclusions
  justifiées par écrit (`/api/contact` : écriture ; `/api/logout`, `/api/login_check`,
  `/api/account/*` : parcours, aucun contenu).
- Sens 2 : chaque outil déclare sa route adossée (route du même palier), ou figure dans une **liste
  fermée d'exceptions justifiées** : `get_contact_info` (page publique du SPA, palier anonyme) et
  `get_nominative_corpus` (corpus nominatif, dont `CorpusSourcesTest` fixe déjà les sources).
- Un outil adossé à une route du palier de base ou de confiance ne peut jamais être déclaré au palier
  anonyme : le palier d'un outil est celui de sa route, tel que `access_control` le fixe.

### Journal des appels (phase 1)

- Canal Monolog `mcp`, distinct de `security_audit` et d'`ai_usage`. Une ligne par requête :
  horodatage, méthode JSON-RPC, outil ou prompt, issue (succès, erreur d'outil, erreur de protocole),
  durée, IP tronquée (IPv4 en /24, IPv6 en /48), et le `clientInfo` déclaré au `initialize`, noté comme
  déclaré. Jamais les arguments en clair (le `role` du prompt est du texte libre), jamais un jeton.
- Le registre RGPD (`docs/rgpd/registre-traitements.md`) reçoit ce traitement, et la politique de
  confidentialité du site est relue en conséquence.

### nginx (phase 1, puis phase 2)

- Zone `mcp` : de l'ordre de 60 requêtes par minute et par IP, avec rafale, sur `location` exacte de
  `/api/mcp`, dans **les deux** configurations miroirs (dev et k8s) ; le script de contrôle des zones
  du backend la connaît.
- Phase 2 : zone `oauth`, du niveau de `login`, sur les points OAuth publics (jeton, révocation,
  autorisation). La limite est par pod (environ le double en effectif), assumé par l'ADR 0006 D6.

### Frontend (phase 1)

- Page publique bilingue **« Brancher votre assistant »**, sous `/{locale}/…` (le segment est fixé au
  ticket, avec ses traductions), liée depuis le pied de page. Elle affiche l'URL du serveur
  (`https://<site>/api/mcp`, construite depuis l'origine courante), ce que le serveur sert par palier
  et ce qu'il ne sert jamais, et la configuration pour Claude Code, Claude Desktop et claude.ai,
  ChatGPT, Cursor et PhpStorm. Les blocs de configuration ont un bouton de copie accessible.
- SEO : titre et description par langue, indexable (la page est publique et décrit un service).
- La page ne nomme pas l'auteur au-delà de ce que le reste du site public affiche déjà.

### Connexion MCP et serveur d'autorisation (phase 2)

- **Serveur d'autorisation interne** sur `league/oauth2-server-bundle` v2 (version exacte vérifiée au
  ticket), complété : métadonnées de serveur d'autorisation (RFC 8414), métadonnées de ressource
  protégée (RFC 9728), audience (RFC 8707, le paramètre `resource` doit désigner l'URL canonique de
  `/api/mcp`, sinon refus), **PKCE S256 obligatoire**, un seul scope `nominative:read`, clients publics.
- **Enregistrement des clients** : CIMD (l'identifiant du client est l'URL de son document de
  métadonnées), autorisé seulement pour une **liste de domaines** de configuration, le document étant
  récupéré par le client HTTP sortant du site avec résolution de l'hôte vérifiée (pas d'adresse
  privée, de boucle locale ni de lien local, après résolution DNS), délai et taille bornés. Plus des
  clients **pré-enregistrés** au backoffice. **Pas de DCR.**
- **Modèle.** Une **connexion MCP** relie un compte, un client, un scope, une date d'octroi, une date de
  dernier usage, une date de révocation éventuelle ; ses jetons d'accès et de rafraîchissement s'y
  rattachent. Clés UUID v7, horodatages `datetimetz_immutable`, comme le reste du schéma. Les jetons
  ne sont stockés qu'en empreinte, jamais en clair.
- **Durées** (configuration) : jeton d'accès 1 h ; jeton de rafraîchissement 30 jours, **rotation à
  chaque usage** et refus de la réutilisation d'un jeton déjà tourné (qui révoque la connexion) ;
  connexion expirée après 30 jours sans usage et au plus tard 90 jours après son octroi.
- **Parcours d'autorisation.** Le point d'autorisation valide la requête (client, redirection exacte,
  PKCE, `resource`, scope) puis renvoie le navigateur vers la page de consentement du frontend. La page
  exige une session du site au palier de confiance (sinon passage par la connexion, puis retour). Le
  consentement est un POST authentifié par le cookie du site, sous garde CSRF, réservé à
  `ROLE_TRUSTED` ; il rend l'URL de redirection portant le code. Un refus rend l'erreur
  `access_denied` au client.
- **Page de consentement** : le domaine de l'identifiant du client (vérifié) en évidence, le nom
  déclaré marqué comme tel, ce qui sera ouvert (le corpus nominatif, en lecture), et le fait que le
  corpus partira vers le fournisseur d'IA de ce client (ADR 0006 D5).
- **Authentificateur** : sur le firewall `mcp`, lit `Authorization: Bearer`, valide le jeton puis le
  **relit en base** avec la connexion, le compte et ses rôles en **une seule requête** ; refuse un
  jeton révoqué, expiré, d'une connexion révoquée ou expirée, d'un compte sans `ROLE_TRUSTED`, ou dont
  l'audience n'est pas `/api/mcp`. Un appel sans en-tête reste anonyme (phase 1 inchangée). Aucune
  écriture par appel : le dernier usage se met à jour au rafraîchissement.
- **Outil nominatif** : lit l'identité **par `Security` uniquement** (jamais `_meta.oauth`, jamais les
  middlewares d'autorisation du SDK, ADR 0006 D4). Avec `ROLE_TRUSTED`, il rend
  `{"corpus": <texte de CorpusRenderer pour la langue>, "cvPageUrl": "https://<site>/<locale>/assistant"}`
  — la page de l'assistant porte aujourd'hui le bouton de téléchargement du CV ; si le ticket en juge
  une autre plus juste, il le dit. Jamais le PDF.
- **Sans connexion**, l'appel de l'outil nominatif déclenche l'autorisation différée : un **401 avec
  `WWW-Authenticate`** (portant `resource_metadata`), renvoyé avant le SDK, pour les clients Claude ;
  des **`securitySchemes`** déclarés sur l'outil et l'erreur portant `_meta["mcp/www_authenticate"]`
  pour ChatGPT (ADR 0006 D3). Voir la question ouverte n°2.
- **Journal d'audit** (`SecurityAuditLoggerInterface`, canal `security_audit`) : trois événements
  nouveaux — connexion MCP accordée, connexion MCP révoquée (par la personne, par expiration, par
  réutilisation d'un jeton tourné), corpus nominatif lu (compte, connexion, client, outil ;
  l'horodatage est celui de la ligne). Le compte y est désigné comme les événements existants le
  désignent (même rédaction).
- **« Mes connexions MCP »** : page frontend réservée au palier de confiance, liste et révocation ;
  API correspondante sous `ROLE_TRUSTED`, qui ne rend que les connexions du compte appelant.
- **Backoffice** : liste de toutes les connexions MCP, et gestion des clients pré-enregistrés, sous
  `ROLE_SUPER` (`/api/backoffice/…`, donc jamais publique par construction).
- **Exposition** : les points OAuth publics (métadonnées, autorisation, jeton, révocation) entrent
  dans `PUBLIC_PATHS` avec leur justification ; le consentement et « Mes connexions MCP » n'entrent
  dans aucune liste (protégés par défaut, `ROLE_TRUSTED`) ; un jeton d'accès de base reçoit 403 sur
  eux. Les points jeton et révocation, appelés hors navigateur, sont exclus de la garde CSRF et
  n'acceptent pas de cookie.
- **Purge** : les jetons et codes expirés, et les connexions expirées depuis plus de la durée de
  conservation du journal, sont purgés par une étape ajoutée au CronJob de purge existant (même
  cadence que `cache:pool:prune`, un pod de moins qu'un CronJob dédié ; l'ADR dit « un CronJob », ce
  choix en est une mise en œuvre).
- **Préprod** : l'Ingress de préprod lève Basic Auth sur les seuls chemins MCP et OAuth (ADR 0006,
  conséquences), à revoir avec #339.
- **Page « Brancher votre assistant »** complétée : comment ouvrir une connexion MCP depuis chaque
  client, et la mention PhpStorm (palier de confiance par Claude Code dans le terminal de l'IDE).

## Testing Decisions

- **Un bon test** observe le comportement par le seam le plus haut : il envoie du JSON-RPC à
  `/api/mcp` par le `KernelBrowser` et lit la réponse HTTP, sans connaître les classes d'outils. Il ne
  teste pas le SDK lui-même (sérialisation JSON-RPC, négociation de version), seulement ce que le site
  en fait.
- **Seams retenus** (validés le 2026-10-19) :
  1. **HTTP `/api/mcp`** par `KernelBrowser` — seam principal ;
  2. **test d'exposition symétrique**, sur le routeur et le registre réels du conteneur ;
  3. **HTTP des points OAuth** (phase 2), même `KernelBrowser` ;
  4. **Vitest au niveau de la page** pour les pages frontend ;
  5. **nginx** : le script de contrôle des zones, et le smoke de préprod.
- **Phase 1, backend** :
  - handshake : `initialize`, `tools/list`, `prompts/list`, `resources/list` (vide) répondent ;
    `GET /api/mcp` ne répond pas 405 ;
  - **égalité route / outil** : pour chaque outil adossé, dans le même test, `tools/call` et le `GET`
    de la route (avec un jeton d'accès de base pour les routes du palier de base) ; `structuredContent`
    (ou son `items`) est égal au corps de la route, et le bloc texte le décode à l'identique ; dans
    les deux langues quand la route en prend une ;
  - langue invalide → `-32602` ; contenu absent dans la langue → erreur d'outil ; contenu vide →
    liste vide ;
  - aucun cookie n'est lu : une requête portant un cookie `BEARER` valide de `ROLE_TRUSTED` et sans
    en-tête XSRF n'ouvre pas l'outil nominatif et n'est pas refusée par la garde CSRF ;
  - `get_nominative_corpus` est listé, et son appel anonyme ne rend rien du corpus ;
  - **D8** : aucune description d'outil, aucun argument, aucun texte de prompt, ni `instructions` ne
    contient le nom de l'auteur ni un fragment du CV nominatif (le nom est lu depuis la configuration de
    test, jamais écrit dans le test, comme les secrets de test) ;
  - `allowed_hosts` : un `Host` étranger est refusé ;
  - le journal `mcp` porte une IP tronquée en IPv4 et en IPv6, et jamais l'argument `role` ;
  - la route `/api/mcp` est dans `PUBLIC_PATHS` et `ApiRouteExposureTest` reste vert.
- **Phase 1, exposition symétrique** : les deux sens, plus un test qui ajoute en mémoire une route
  fictive pour vérifier que l'oubli fait bien échouer (comme les autres tests d'allow-list).
- **Phase 2, backend** :
  - parcours complet : métadonnées, autorisation, consentement, code, jeton, `tools/call` nominatif ;
  - refus : PKCE absent ou faux, `resource` étranger, redirection non enregistrée, client CIMD hors
    liste, document CIMD sur une adresse privée, jeton d'accès de base sur le consentement (403) ;
  - **révocation** : connexion révoquée, puis compte privé de `ROLE_TRUSTED`, puis connexion expirée →
    la requête suivante n'ouvre plus le corpus ;
  - rotation : un jeton de rafraîchissement réutilisé révoque la connexion ;
  - **compte de requêtes** : un `tools/call` authentifié coûte exactement une requête de plus que le
    même appel anonyme, et aucune écriture hors de la table des sessions MCP ;
  - 401 + `WWW-Authenticate` sur l'appel anonyme de l'outil nominatif, avec `resource_metadata` ;
  - journal d'audit : octroi, révocation, lecture nominative, avec les champs attendus ;
  - « Mes connexions MCP » ne rend que les connexions du compte appelant.
- **Frontend** (Vitest, au niveau de la page, comme les pages existantes) : page « Brancher votre
  assistant » dans les deux langues, URL construite depuis l'origine, copie accessible ; en phase 2,
  page de consentement (vérifié / déclaré, refus), « Mes connexions MCP » (liste, révocation, état
  vide), vue backoffice. Accessibilité selon la règle frontend du dépôt.
- **nginx** : `tools/check-backend-nginx-rate-limits.sh` connaît les zones `mcp` puis `oauth` dans les
  deux configurations ; le smoke de préprod vérifie qu'au-delà du seuil la zone `mcp` répond 429.
- **Prior art** : `ApiRouteExposureTest` et `RouterScopeTest` (allow-lists justifiées sur le routeur
  réel), `CorpusSourcesTest` (sources pincées sur le service construit), `CorpusRendererTest` et ses
  instantanés, les tests de `BaseAccessController` (jeton d'accès de base), `AiUsageLogChannelTest`
  (canal Monolog dédié), `tools/smoke-login-throttling.sh` (smoke de seuil en préprod).

## Out of Scope

- **Les ressources MCP** (CV sans identité en Markdown, corpus nominatif comme ressource,
  `resource_link` dans les outils) : issue de suivi, juste après la phase 2.
- **Le palier de confiance depuis l'AI Assistant natif de PhpStorm** : attend OAuth et CIMD côté
  JetBrains (LLM-25012, IJPL-226161) ; issue de suivi qui surveille ces tickets.
- **Toute écriture** par le serveur MCP (y compris l'envoi du formulaire de contact) et **tout appel
  à un modèle** depuis le serveur.
- **Un jeton personnel en en-tête**, à côté d'OAuth ou à sa place ; **DCR**.
- **Le PDF du CV** servi par le serveur MCP, sous quelque forme que ce soit.
- **La révocation d'une connexion par l'administrateur** depuis le backoffice : il retire le palier de
  confiance au compte, ce qui coupe toutes ses connexions (le glossaire ne prévoit que ces deux voies).
- **Un e-mail à l'auteur** à chaque connexion MCP (ADR 0006, alternatives écartées).
- **Un limiteur de débit Symfony** partagé entre réplicas pour MCP (ADR 0006 D6).
- **`/mcp` hors de `/api`** et la révision du protocole sans session seule (ADR 0006).
- **L'accès VPN à la préprod** (#339, futur ADR 0007), qui pourra rendre Basic Auth inutile.

## Further Notes

### Questions ouvertes (à trancher par un ticket de recherche en tête de phase 2)

1. **Découverte des métadonnées OAuth.** RFC 8414 et RFC 9728 placent leurs documents sous
   `/.well-known/…` **à la racine du domaine** ; or l'Ingress n'envoie au backend que `/api`, et le
   SPA répond `200` en HTML à tout autre chemin — un client qui essaie d'abord la racine peut échouer
   au lieu de passer à la variante suivante. Pistes : `resource_metadata` dans `WWW-Authenticate` et un
   émetteur à chemin (`https://<site>/api…`) en comptant sur l'ordre de découverte de la spécification
   MCP ; une règle d'Ingress exacte pour les deux chemins `.well-known` (avec entrée `NON_API_PATHS`,
   ce que l'ADR a jugé coûteux pour `/mcp`) ; un `404` servi par le frontend sur `/.well-known/`. À
   vérifier contre Claude et ChatGPT réels, en préprod.
2. **Les deux mécanismes d'autorisation différée** (401 pour Claude, `_meta["mcp/www_authenticate"]`
   pour ChatGPT) ne peuvent pas répondre en même temps à un même appel : le 401 précède le résultat
   JSON-RPC. Établir comment chaque client se comporte devant l'autre réponse, et si un critère fiable
   (non déclaratif) les départage ; à défaut, le choix et son coût sont écrits dans l'ADR.

### Précisions apportées à l'ADR 0006 (D1)

- `structuredContent` d'une route de collection est enveloppé dans `{"items": …}` — la spécification
  MCP exige un objet ; c'est la seule transformation admise.
- Deux outils ne sont pas adossés à une route : `get_contact_info` (page publique) et
  `get_nominative_corpus` (corpus nominatif), dans une liste fermée et justifiée du test symétrique.
  Choix validé le 2026-10-19.

### Découpage en tâches (issues, 2026-10-19)

Quatorze tâches, sous-issues de #379, label `spec-0007`, blocages en liens natifs ; suivi cochable
dans `tasks/todo.md`.

| Phase | Tâches | Issues |
|---|---|---|
| 1 | socle et `get_about` ; catalogue et test symétrique ; prompt et garde D8 ; journal `mcp` et RGPD ; zone nginx ; page « Brancher votre assistant » | #640 → #645 |
| 2 | recherche (questions ouvertes) ; émission d'une connexion MCP ; corpus nominatif ; durée de vie des jetons ; « Mes connexions MCP » ; CIMD ; backoffice ; livraison | #646 → #653 |
| suivis | ressources MCP ; PhpStorm (tickets JetBrains) | #654, #655 |

**`develop` reçoit la feature deux fois**, par exception à la règle « une fois » de `CLAUDE.md`
(décision du 2026-10-19) : après la tâche 6, pour que la phase 1 parte en release seule, puis au merge
de clôture, qui archive la spec et `tasks/`. La vérification des vrais clients suit l'ADR : Claude Code
en préprod (en-tête Basic) dès la phase 1, claude.ai et ChatGPT en prod ; la préprod ne lève Basic Auth
sur les chemins MCP et OAuth qu'à la tâche 14.

### Divers

- La numérotation : cette spec prend 0007 ; l'ADR de l'accès VPN (#339) prendra 0007 dans
  `docs/adr/` — les deux séries sont indépendantes.
- Les issues de tâches se créent avec le plan, une par tâche, label `spec-0007` ; les deux issues de
  suivi (ressources MCP, tickets JetBrains) se créent avec elles.
- Phase 1 se livre seule : rien de la phase 2 ne doit être nécessaire pour qu'elle passe en prod.

---

## Journal des validations

- **2026-10-19 — rédaction** (`/mattpocock-skills:to-spec #379`), à partir de l'ADR 0006, de
  `CONTEXT.md` et du code de `develop` (2174e626). Seams et exception `get_contact_info` validés par
  l'auteur le même jour.
- **2026-10-19 — audit de sensibilité** (dépôt public) : aucun secret, aucune adresse e-mail, aucune
  IP, aucun chemin `/home/…`, aucun nom de domaine de production (le domaine s'écrit `<site>`), aucun
  nom de personne. Les identifiants de tickets JetBrains sont publics. Ce qui renseigne un attaquant
  sans être un secret : les zones de débit (ordre de grandeur seulement, déjà dans l'ADR), le fait que
  la préprod lève Basic Auth sur les chemins MCP et OAuth en phase 2 (déjà dans l'ADR), les
  durées des jetons (déjà dans l'ADR). Rien de nouveau par rapport à l'ADR publiée sur la même
  branche.
- **2026-10-19 — tâche 1 (#640)** : trois précisions, nées de l'implémentation et reportées plus haut —
  la langue invalide est un `-32602` du SDK (le schéma d'entrée énumère les langues) ; `allowed_hosts`
  dérive d'`APP_FRONTEND_BASE_URL` au lieu d'une variable dédiée ; le GET répond par un flux SSE vide
  au lieu du 405 du SDK. Règle d'implémentation : `.claude/rules/mcp.md`.
