# ADR 0004 — Assistance IA : un fournisseur de modèles sans en devenir l'otage (`Ai/`)

- Statut : **accepté** (2026-09-14) — **phase 1 livrée** le même jour (spec
  `.claude/specs/0002-ai-translation-assistant.md`, issues `spec-0002` fermées, releases v0.10.0 puis
  v0.10.1 en production) ; **amendée le 2026-09-15** : la phase 2 n'est plus un serveur MCP mais un
  assistant conversationnel réservé à `ROLE_TRUSTED` (D7 réécrite, D1/D2/D3/D5 retouchées, voir
  l'encadré « Amendement du 2026-09-15 » sous D7) ; spec de phase 2 à écrire avant tout code
- Date : 2026-09-14, amendée le 2026-09-15
- Portée : `src/Ai/` (nouveau contexte borné), `config/packages/ai.yaml`, `config/ai/prompts/`,
  `config/packages/framework.yaml` (clients HTTP `ai.http_client` et, en phase 2, un second client
  dédié à Scaleway), `config/packages/rate_limiter.yaml`, `k8s/overlays/*/external-secrets.yaml`
  (`ANTHROPIC_API_KEY`, puis la clé Scaleway de la phase 2), frontend `admin/translation` puis la
  page de l'assistant ; objectifs n°8 (sécurité), n°9 (rien d'identifiant sans `ROLE_TRUSTED`) et
  n°12 (bilingue) ; étend la règle « aucun appel sortant dans un chemin de rendu public » de
  l'ADR 0002

## Contexte

Le site est bilingue et chaque contenu administrable existe en deux lignes, une par `locale`, sans
lien entre elles : tout se saisit deux fois. Un modèle de langage traduit ce genre de texte
technique correctement, et [Symfony AI](https://symfony.com/doc/current/ai/index.html) (v0.13.0,
30 août 2026) fournit l'intégration : plateformes interchangeables (Anthropic, OpenAI, Mistral,
Ollama…), agents déclarés en YAML, sortie structurée, panneau profiler, assertions de test.

C'est aussi, sur un portfolio, un sujet de démonstration en soi. Mais il arrive avec trois
propriétés qui appellent des règles avant le premier appel :

1. **Le bundle est en 0.x**, sans promesse de compatibilité ascendante : son API peut changer à
   chaque version mineure.
2. **Un appel à un fournisseur coûte de l'argent et du temps** (plusieurs secondes) et dépend d'un
   tiers dont la disponibilité n'est pas la nôtre — exactement ce que l'ADR 0002 a tenu hors du
   chemin de rendu public pour endoflife.date et OSV.dev.
3. **Ce qui part vers un fournisseur le quitte** : ses conditions, sa rétention et ses incidents ne
   sont pas les nôtres. L'objectif n°9 interdit d'exposer une donnée identifiante sans
   `ROLE_TRUSTED` ; un fournisseur tiers n'a pas ce rôle.

Le projet a par ailleurs déjà arbitré, à deux reprises, une intégration de tiers (ADR 0001 pour
l'e-mail, ADR 0002 pour la veille). Cette ADR reprend la même grammaire — couche anti-corruption,
appel hors rendu, coût borné, tests hors ligne — et l'étend à une classe de tiers nouvelle : ceux
dont la sortie est du texte libre produit par un modèle.

Six pistes ont été examinées (traduction en backoffice, serveur MCP, agent RAG « interrogez mon
parcours », synthèse des vulnérabilités OSV, triage du formulaire de contact, relecteur éditorial).
Deux sont retenues, dans l'ordre : la traduction (phase 1, cette ADR) puis — depuis l'amendement du
2026-09-15 — l'assistant « interrogez mon parcours », réservé au palier `ROLE_TRUSTED` (phase 2,
D7). Le serveur MCP, initialement retenu pour la phase 2, rejoint les pistes écartées en fin de
document, avec la raison du revirement.

## Décisions

### D1 — Un contexte borné `Ai/`, une interface applicative, un bundle pinné

Tout ce qui touche à un modèle de langage vit sous `src/Ai/`, découpé en sous-contextes par usage
(`Translation` en phase 1, `Assistant` en phase 2 — amendement du 2026-09-15, le sous-contexte
`Mcp` annoncé à l'origine ne verra pas le jour), avec le découpage habituel
`Domain/Application/Infrastructure/Presentation`. Rien d'IA ne s'enfouit dans `Portfolio/*` ni dans
`Security/*` : ces contextes ignorent qu'un modèle existe.

La frontière est une interface applicative par usage (`ContentTranslatorInterface` pour la
traduction, une interface équivalente pour l'assistant). **Une seule classe par sous-contexte importe
`Symfony\AI\*`**, dans son `Infrastructure/` (`SymfonyAiContentTranslator` en phase 1). Le domaine et
l'application ne connaissent ni `MessageBag`, ni `AgentInterface`, ni `Platform` : changer de version
du bundle, ou de fournisseur, est un changement local à cette classe et à `ai.yaml`. La phase 2 est
la première mise à l'épreuve de cette frontière : un second fournisseur (Scaleway, D7) derrière la
même abstraction, choisi pour une raison qui tient à la donnée envoyée (D3), sans qu'aucune classe
de la phase 1 ne change.

Les paquets `symfony/ai-bundle`, `symfony/ai-anthropic-platform` et `symfony/ai-agent` — et, en
phase 2, `symfony/ai-scaleway-platform` — sont **pinnés en version exacte** (`0.13.0`, pas de `^`)
tant qu'ils sont en 0.x. Une montée de version est un acte délibéré, lu dans le changelog, pas un
effet de bord d'un `composer update`.

### D2 — Aucun appel à un fournisseur dans un chemin de rendu public

Règle de l'ADR 0002, étendue : **aucun provider public, aucun handler Messenger déclenché par un
visiteur, aucun CronJob de rendu n'appelle un modèle**. Un appel n'est possible que depuis un
chemin réservé à un compte authentifié dont le rôle justifie la dépense — en phase 1, le backoffice
(`ROLE_SUPER`), de façon synchrone, avec un timeout.

**Amendement du 2026-09-15.** Le palier `ROLE_TRUSTED` remplit cette condition : il est accordé
nominativement, par un `ROLE_SUPER`, à une personne identifiée (ADR 0003 D1). Un appel à la
demande d'un compte de ce palier est donc admis (D7), aux mêmes conditions que le backoffice —
synchrone, avec timeout, sous quota par compte (D5). Ce qui ne change pas : **ni l'anonyme, ni le
palier de base (`ROLE_USER`, y compris le jeton sans compte de l'ADR 0003 D6) ne déclenchent
jamais un appel**. Le jeton de base est distribué à qui le demande ; un quota par compte n'y borne
rien, et c'est précisément l'objection qui avait écarté l'agent RAG au palier de base.

Conséquence pour l'infrastructure : le CronJob `watch-refresh` n'est plus le seul objet du cluster
à faire des appels sortants vers des tiers ; le Deployment `backend` en fait aussi, depuis le
backoffice (phase 1) et depuis la page de l'assistant (phase 2). Une NetworkPolicy d'egress, si
elle est un jour ajoutée, doit ouvrir `api.anthropic.com` et `api.scaleway.ai` pour le backend
comme endoflife.date et OSV.dev pour le CronJob.

### D3 — Ce qui peut partir vers un fournisseur, et ce qui ne le peut jamais

Peut être envoyé à un modèle : **le contenu rédigé dans le backoffice et destiné à la
publication**, sur n'importe quel palier (public, `ROLE_USER`). Ce contenu est, par construction,
ce que son auteur a décidé de publier ; le confier à un fournisseur n'expose rien qui ne le soit
déjà ou ne s'apprête à l'être.

Ne peut jamais être envoyé, quel que soit le fournisseur, y compris un modèle hébergé dans le
cluster : les données de `cpg_user` (e-mails, hachés de mots de passe, jetons), les messages du
formulaire de contact (texte libre d'un anonyme, données personnelles du visiteur), et tout ce que
l'objectif n°9 réserve au palier nominatif — **à une exception près, encadrée ci-dessous**.
Brancher un usage sur un contenu de cette liste exige un amendement de cette ADR, pas une revue de
code.

**Amendement du 2026-09-15 — le CV nominatif.** La rédaction initiale interdisait le CV nominatif
(le PDF servi par `GET /api/cv`, palier `ROLE_TRUSTED`) « quel que soit le fournisseur ». C'est
l'exception qu'introduit la phase 2, et elle est bornée par l'opérateur, pas par le fournisseur :
le CV nominatif peut alimenter **un modèle opéré par l'hébergeur du site, dans la même région que
lui, ou auto-hébergé sans sortie réseau** — et rien d'autre. L'argument : ce document vit déjà
chez Scaleway, dans Secret Manager et dans le cluster Kapsule de `fr-par` ; le confier à l'API
d'inférence du même opérateur, dans la même région, ne lui fait changer ni d'hébergeur ni de
juridiction. Il ne franchit en revanche jamais cette frontière : **pas vers Anthropic** (la
plateforme de la phase 1, dont c'est la seule chose qu'elle ne verra jamais), pas vers un autre
fournisseur, quelle que soit la qualité de son modèle. Un test fonctionnel pince la plateforme de
l'agent de l'assistant (D7) ; changer ce fournisseur est un nouvel amendement, pas un changement de
`ai.yaml`.

### D4 — L'humain reste dans la boucle : une suggestion n'est jamais persistée

Le modèle **propose**, il n'écrit pas. L'endpoint de traduction ne lit ni n'écrit en base ; le
frontend remplit un nouveau formulaire avec le brouillon, le signale comme tel, et le bouton
d'enregistrement habituel reste le seul chemin vers la persistance. Il n'y a pas de « traduction
automatique à l'enregistrement », pas de synchronisation FR↔EN silencieuse.

C'est aussi la réponse retenue au risque d'injection de prompt : le texte à traduire est rédigé
par le super-admin lui-même, le prompt système interdit d'exécuter une instruction contenue dans
le texte, la sortie structurée contraint la forme — et si malgré cela le modèle produit autre chose
que la traduction, c'est un brouillon qu'un humain relit avant de l'enregistrer. Le risque résiduel
est accepté et nommé ici.

### D5 — Le coût est borné par construction, pas par vigilance

Trois bornes indépendantes, toutes présentes avant le premier déploiement :

- un **quota par compte** (fenêtre glissante, 30 appels par heure, clé = `username`) — un bug de
  boucle côté UI ou un compte compromis ne peut pas dépenser sans limite ;
- un **plafond de jetons de sortie** (`max_tokens: 4096`, nom Anthropic) ;
- un **timeout client HTTP** (40 s, sous les 60 s de nginx et de l'ingress) sur un client dédié
  `ai.http_client` à `max_redirects: 0`, même règle que les clients de la veille.

Les jetons consommés et la durée de chaque appel sont journalisés en `info`, **jamais le
contenu**. `claude-sonnet-5` est le modèle des deux environnements : preprod doit reproduire prod,
y compris la qualité de la sortie structurée.

**Amendement du 2026-09-15.** L'assistant (D7) reçoit les mêmes trois bornes, avec un quota
distinct (`career_assistant`, même fenêtre glissante, clé = `username`), un plafond de sortie plus
bas (une réponse de conversation, pas une traduction de cinq champs) et **une quatrième borne qui
n'existait pas en phase 1 : l'entrée**. La conversation est renvoyée entière à chaque tour, donc
sa taille est plafonnée (nombre de tours et longueur des messages, valeurs fixées par la spec) —
sans quoi le coût d'une question croîtrait avec la patience de l'utilisateur. Ordre de grandeur,
au tarif public de Scaleway : une question coûte un millième d'euro ; le pire cas théorique de
trois comptes saturant leur quota en continu, moins d'une centaine d'euros par mois — c'est
précisément la borne, et une alerte de budget côté hébergeur la rend visible bien avant.

### D6 — Aucun test ne sort sur le réseau

Les tests unitaires utilisent la plateforme en mémoire du composant (`InMemoryPlatform`,
`MockPlatformFactory`) ; les tests fonctionnels remplacent la plateforme, ou son client HTTP, dans
le conteneur de test. `phpunit.dist.xml` force une clé factice : un test qui atteindrait l'API
réelle échouerait en 401, et c'est le filet voulu. Un test qui appelle réellement un fournisseur
est intermittent par construction, coûte de l'argent à chaque exécution de la CI, et publie du
contenu de test chez un tiers.

### D7 — Phase 2 : un assistant « interrogez mon parcours », réservé à `ROLE_TRUSTED`, sur un modèle opéré par l'hébergeur

> **Amendement du 2026-09-15.** La rédaction initiale de D7 prévoyait un serveur
> [MCP](https://modelcontextprotocol.io) en lecture seule, interrogé depuis l'assistant externe de
> la personne autorisée. Le besoin réel, clarifié avant tout code, est l'inverse : **un chatbot sur
> le site**, pour qu'une personne du palier nominatif pose ses questions sur le parcours sans
> assistant à installer. C'est l'agent RAG « interrogez mon parcours » écarté plus bas, mais les
> trois objections qui l'avaient écarté tombent au palier `ROLE_TRUSTED` : l'appelant est un compte
> nominatif, donc D2 et le quota de D5 s'appliquent ; le corpus tient dans le contexte du modèle,
> donc aucun `pgvector` ; et la surface d'injection n'est ouverte qu'à des personnes identifiées,
> qui ne peuvent rien en tirer qu'elles ne lisent déjà (voir l'invariant ci-dessous). Le serveur
> MCP rejoint les alternatives écartées. Ce qui suit remplace l'ancien texte.

Le second usage retenu est un assistant conversationnel, sur une page du site, qui répond aux
questions d'une personne du palier `ROLE_TRUSTED` à partir de ce que ce palier lit déjà. Les règles
fixées ici s'imposent à la spec de phase 2 ; ce qu'elles ne fixent pas (noms, endpoint exact,
plafonds chiffrés, forme de la page) est du ressort de la spec.

- **Le palier est `ROLE_TRUSTED`, et l'assistant ne sait rien que l'appelant ne puisse déjà
  lire.** C'est l'invariant central, et il porte toute la sécurité de la fonctionnalité : le corpus
  est assemblé **exclusivement** à partir de contenus servis par des routes de palier inférieur ou
  égal — le CV sans identité (`/api/anonymous-cv`), les études de cas (`/api/case-studies`) et le CV
  nominatif (`/api/cv`, sous forme de texte extrait du PDF). Une injection de prompt réussie ne
  révèle donc rien : le modèle ne détient aucune donnée que la page d'à côté ne montre pas. Jamais
  de contenu backoffice, jamais `cpg_user`, jamais un message de contact, jamais le détail des
  vulnérabilités (`ROLE_SUPER`). Un contenu de palier inférieur (page « À propos », incidents…)
  peut s'ajouter par la spec ; un contenu de palier supérieur exige un amendement.
- **Le corpus passe par les providers et présenteurs existants**, jamais par les repositories : la
  partition par rôle est celle de l'API, lue au même endroit. Le CV nominatif est la seule pièce
  sans provider (c'est un fichier) : un lecteur dédié en extrait le texte, mis en cache, **jamais
  persisté ailleurs** ni journalisé — le PDF reste hors dépôt et hors image, comme aujourd'hui. Un
  test pince la liste des sources du corpus, comme `ApiRouteExposureTest` pince les routes.
- **Le corpus est injecté dans le contexte, pas retrouvé.** Il représente quelques milliers de
  jetons : un index vectoriel, un `pgvector` sur un Postgres à état et une étape de récupération
  seraient de la complexité sans problème à résoudre. Corollaire : **aucun outil, aucun appel de
  fonction** — l'agent n'agit pas, il répond ; le modèle n'a donc pas besoin de savoir appeler des
  outils, ce qui élargit le choix et supprime un mode de panne en démonstration.
- **Le corpus est rendu par un composant unique, en un document texte déterministe.** Un seul
  rendu pour les trois sources, en Markdown à intertitres nommés (« Problème », « Résultat
  mesuré »…) plutôt qu'en JSON : une locale (celle de la page), les entrées triées par
  `position`, aucun champ technique (identifiants, groupe de traduction, locale), le texte du CV
  nominatif extrait **une fois** du PDF (cache sur la date du fichier) puis normalisé — sauts de
  ligne de mise en page, en-têtes répétés à chaque page et espacement recomposés en paragraphes
  et listes. Ce rendu est **assemblé à chaque requête** (quelques requêtes SQL, une milliseconde
  devant un appel de plusieurs secondes) et non précalculé : un corpus mis en cache et invalidé à
  chaque écriture aurait autant de chemins d'oubli qu'il y a de façons d'écrire ces contenus —
  processeurs du backoffice, endpoint d'ordre, seeds, migration, remplacement du PDF monté — et
  chaque oubli fait répondre l'assistant avec la version d'avant sans qu'aucun test ne rougisse.
  Le déterminisme a une raison précise : le corpus est placé **en préfixe du prompt système**,
  byte-identique d'un appel à l'autre, avant tout élément variable (date, prénom, question), pour
  être reconnu par le **cache de prompt** du fournisseur — la seule façon de ne pas payer ni
  attendre la lecture du corpus à chaque tour, puisqu'une API de complétion est sans état et
  qu'aucun « envoi unique par session » n'existe. Un test de rendu sur fixtures montre noir sur
  blanc ce qui part chez le fournisseur ; c'est aussi là que vit la liste pincée des sources.
  Le cache de prompt lui-même est un gain, pas une condition : la spec vérifie s'il existe pour
  le modèle retenu (au moment de l'amendement, la grille Scaleway n'affiche un tarif « cached »
  que pour certains modèles), et le choix du modèle peut en tenir compte.
- **Le modèle est opéré par l'hébergeur du site**, Scaleway Generative APIs en région `fr-par`, via
  `symfony/ai-scaleway-platform` (pinné, D1). C'est la condition de D3 amendée pour le CV
  nominatif, et c'est aussi le choix économique : pas de nœud à louer, un coût au millième d'euro
  la question, une quantité offerte qui couvre le développement. Le modèle par défaut est un
  modèle ouvert de taille moyenne (Mistral Small 3.2 au moment de l'amendement), **confirmé par un
  appel réel lors de la spec** — le nom du modèle est de la configuration, pas de la décision, tant
  qu'il reste chez cet opérateur.
- **Rien n'est persisté, rien n'est journalisé du contenu.** L'endpoint ne lit ni n'écrit en base ;
  le frontend renvoie la conversation entière à chaque tour, bornée (D5 amendée). Aucune table de
  conversations, aucun historique côté serveur : une question posée au portfolio n'a pas à
  survivre à l'onglet. Comme en phase 1, seuls les jetons et la durée sont journalisés.
- **La réponse est diffusée en flux.** Une réponse de quelques centaines de jetons prend cinq à dix
  secondes sur ce genre d'API ; sans streaming, c'est un spinner de dix secondes. L'appel reste
  synchrone, sous le timeout du client dédié (40 s, comme en phase 1) et sous les 60 s de nginx et
  de l'ingress — ce qui impose de désactiver le tampon de réponse sur ce chemin (conséquences).
- **L'indisponibilité est un état affiché, pas une panne de page.** Un fournisseur injoignable ou
  un timeout répond 503 ; la page le dit et renvoie vers les contenus, qui restent lisibles. Rien
  de ce que le palier voit ne dépend de l'assistant.
- **La règle de D4 tient, transposée** : le modèle propose une réponse, la page la présente comme
  telle (« peut se tromper, les documents font foi »), et rien de ce qu'il dit n'entre en base.

### Ce qui n'est jamais négociable

- Un `^` sur la version d'un paquet `symfony/ai-*` tant qu'il est en 0.x.
- Un appel à un modèle depuis un chemin public, un handler déclenché par un visiteur ou un CronJob
  de rendu — ni depuis le palier de base, jeton sans compte compris (amendement du 2026-09-15).
- Une suggestion persistée sans action explicite d'un humain.
- `cpg_user`, un jeton ou un message de contact envoyés à un fournisseur ; le CV nominatif envoyé
  à un fournisseur autre que l'opérateur du site ou un modèle auto-hébergé (D3 amendée).
- Une source du corpus de l'assistant servie par une route de palier supérieur à `ROLE_TRUSTED`,
  ou lue autrement que par un provider ou un présenteur existant (D7).
- Un test qui sort sur le réseau, ou un assouplissement d'`ApiRouteExposureTest` pour faire passer
  une route.
- Le contenu d'un appel dans les logs.

## Conséquences

- **Un contexte borné de plus** (`src/Ai/`), le premier dont le domaine dépend d'un modèle de
  langage — et le premier où une réponse de tiers est du texte libre, d'où la validation serveur de
  la sortie structurée (chaque clé présente, chaîne non vide, sinon 503) plutôt qu'une confiance
  dans la forme.
- **Une route backoffice de plus**, `POST /api/backoffice/translations`, couverte sans entrée
  d'allow-list par `ApiRouteExposureTest` : elle tombe sous `^/api/backoffice(/|$)`.
- **Un secret de plus** hors dépôt, `ANTHROPIC_API_KEY`, servi comme `CONTACT_*` : vide dans `.env`,
  factice et forcée dans `phpunit.dist.xml`, `.env.local` en dev, Secret Manager → `ExternalSecret
  backend-secrets` en préprod/prod. Les secrets Scaleway doivent exister **avant** le premier
  déploiement preprod, sinon le Deployment ne démarre pas.
- **Un limiteur de plus** (`translation_assistant`), clé par compte et non par IP : premier
  limiteur du projet sur une route authentifiée.
- **Un invariant de `CLAUDE.md` corrigé** : le CronJob de veille n'est plus le seul objet à appeler
  des tiers.
- **Un coût récurrent nouveau**, de l'ordre du centime par traduction, plafonné par D5.
- **Un bouton par page admin localisée**, branché page par page — Incidents (v0.10.0), puis
  Contributions, CV sans identité, Qualité et À propos (v0.10.1). Deux sémantiques ont été tranchées au
  branchement : sur une page « par entrée », le formulaire bascule en création dans la locale cible ;
  sur une page « à locale de page » (Qualité, À propos), c'est la page qui change de locale, et le
  singleton des réglages À propos reçoit son brouillon en différé, une fois la locale cible chargée.
  La page admin des études de cas, qui manque, est une tâche à part (issue #104) ; le bouton y
  arrivera avec elle.
- **Les tests unitaires passent par un faux agent** (`FakeAgent`, implémentation de test de
  `AgentInterface`) plutôt que par `InMemoryPlatform` : c'est l'agent que le traducteur reçoit, la
  plateforme n'est jamais vue de la classe. **Le remplacement dans les tests fonctionnels s'est fait
  au niveau du client HTTP**, pas de la plateforme (D6 admettait les deux) : le service concret derrière le client scoped,
  `ai.http_client.scoping.inner`, est remplacé par un `MockHttpClient` au format de l'API Messages,
  et le `KernelBrowser` doit être en `disableReboot()`, sinon le kernel reconstruit entre la
  connexion et l'appel perd la substitution et la requête part réellement — la clé factice de
  `phpunit.dist.xml` la fait alors échouer en 401, ce qui est le filet prévu.

**Conséquences propres à la phase 2** (amendement du 2026-09-15, à reprendre dans la spec) :

- **Une seconde plateforme dans `ai.yaml`** (`scaleway`), un second client HTTP scoped (même
  timeout de 40 s, `max_redirects: 0`), un second secret hors dépôt (clé d'API Scaleway, même
  circuit qu'`ANTHROPIC_API_KEY` : vide dans `.env`, factice et forcée dans `phpunit.dist.xml`,
  `.env.local` en dev, Secret Manager en préprod/prod), un second quota (`career_assistant`).
- **Une règle `access_control` de plus**, `ROLE_TRUSTED`, ancrée `(/|$)` comme les autres (issue
  #78), sur un préfixe qui n'est pas `^/api/cv` (sinon capturé par la règle voisine) ;
  `ApiRouteExposureTest` la couvre sans entrée d'allow-list : anonyme → 401/403, palier de base →
  403, et **elle ne doit jamais figurer dans `BASE_TIER_PATHS`**.
- **Le streaming traverse deux reverse proxies qui tamponnent par défaut** : le sidecar nginx
  (`fastcgi_buffering off`, ou l'en-tête `X-Accel-Buffering: no` sur la réponse) et ingress-nginx
  (annotation `proxy-buffering: "off"`), sur ce chemin seulement. `docker/nginx/default.conf` et
  `k8s/base/backend-nginx.conf` restent des miroirs ; la ConfigMap hachée (`configMapGenerator`)
  fait redémarrer le sidecar, comme documenté dans `CLAUDE.md`.
- **Un lecteur de PDF côté serveur**, pour le texte du CV nominatif : dépendance à choisir dans la
  spec, texte mis en cache (invalidé par la date du fichier), normalisé, jamais écrit en base. Le
  cache est local au pod (`cache.app`, système de fichiers) : avec deux réplicas, chacun extrait
  une fois, ce qui est acceptable — il n'y a rien à partager.
- **Un rendu de corpus testé sur fixtures** (D7) : le test montre le document exact envoyé au
  fournisseur et échoue si une source y entre ou en sort ; il remplace pour ce contexte
  l'allow-list justifiée qu'`ApiRouteExposureTest` tient pour les routes.
- **Une page frontend de plus**, derrière `requiresAuth` + `roles: [ROLE_TRUSTED]` dans le routeur
  (la garde existe déjà, elle sert au backoffice), avec un transcript accessible (`role="log"`, un
  seul `role="status"` pour l'état de l'appel) et l'audit axe-core comme les autres pages.
- **Un coût récurrent** de l'ordre du millième d'euro par question, borné par D5 amendée.

## Alternatives écartées

- **Un endpoint par ressource** (`POST /api/backoffice/incidents/{id}/translation`) : typage plus
  serré, mais la logique dupliquée pour chaque contexte, et impossible de traduire une saisie non
  encore enregistrée.
- **Un traitement asynchrone via Messenger** avec polling : robuste aux lenteurs, mais une entité de
  suivi, un endpoint de statut et un état de plus pour un usage occasionnel depuis un backoffice.
- **Ollama dans le cluster** : rien ne quitte l'infrastructure, mais un pod lourd à héberger, une
  qualité de traduction inférieure, et D3 rend le bénéfice de confidentialité théorique — le
  contenu envoyé est déjà destiné à la publication. Le bundle permet d'y passer par configuration si
  la question se repose.
- **L'agent RAG « interrogez mon parcours » au palier de base** : la plus démonstrative des pistes,
  mais un appel synchrone à la demande d'un visiteur (contraire à D2 sans un quota serré), un
  pgvector à ajouter sur un Postgres à état, et une surface d'injection de prompt ouverte à tous.
  *Amendement du 2026-09-15 : retenue au palier `ROLE_TRUSTED`, sans index vectoriel, comme phase 2
  (D7). Ce qui reste écarté, c'est le palier de base : un jeton distribué à qui le demande ne se
  borne pas par compte.*
- **Le serveur MCP en lecture seule** (rédaction initiale de D7, écartée le 2026-09-15). L'idée
  était d'exposer le portfolio à l'assistant externe de la personne autorisée. Deux raisons de
  l'écarter : le besoin réel est un chatbot sur le site, sans rien à installer ; et
  l'authentification d'un client MCP externe ne passe pas par le cookie `BEARER` — il aurait fallu
  soit un jeton personnel en base et un second firewall, soit un serveur OAuth 2.1 complet (RFC
  9728, PKCE, enregistrement dynamique) pour les connecteurs de claude.ai et Claude Desktop, une
  surface disproportionnée pour un portfolio. Réouvrable : `symfony/mcp-bundle` (0.13.0, même
  monorepo) existe, et le corpus assemblé en D7 serait exactement ce qu'un tel serveur exposerait ;
  l'authentification resterait à trancher.
- **Un modèle auto-hébergé (Ollama) pour l'assistant** : rien ne quitte le cluster, ce qui aurait
  satisfait D3 sans l'amender. Mais le cluster n'a aucun nœud capable de porter un modèle — les
  pods backend sont limités à 256 Mio — et le pool CPU le moins cher (8 Gio) coûte une vingtaine
  d'euros par mois pour un modèle 3B qui met une à trois minutes à lire un contexte de 8 000
  jetons, au-dessus des 60 s de l'ingress. Un GPU coûte plusieurs centaines d'euros par mois. À
  l'usage attendu, l'API de l'hébergeur est des centaines de fois moins chère et dix fois plus
  rapide. Le bridge Ollama de Symfony AI existe : un jour où le cluster aurait la capacité, ce
  serait un changement de configuration, couvert par D3 telle qu'amendée (« auto-hébergé sans
  sortie réseau »).
- **Le triage du formulaire de contact** : asynchrone par construction, mais c'est l'entrée la plus
  exposée à l'injection (texte libre d'un anonyme) et elle enverrait des données personnelles de
  visiteurs à un fournisseur (contraire à D3), pour un gain faible sur le volume d'un portfolio.
- **La synthèse des vulnérabilités OSV** et **le relecteur éditorial** : compatibles avec ces règles,
  valeur modeste ; non planifiés.
