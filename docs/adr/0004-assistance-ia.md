# ADR 0004 — Assistance IA : un fournisseur de modèles sans en devenir l'otage (`Ai/`)

- Statut : **accepté** (2026-09-14) — phase 1 en cours (spec `.claude/specs/0002-ai-translation-assistant.md`,
  issues `spec-0002`) ; D7 (phase 2) à préciser par amendement avant d'écrire du code
- Date : 2026-09-14
- Portée : `src/Ai/` (nouveau contexte borné), `config/packages/ai.yaml`, `config/ai/prompts/`,
  `config/packages/framework.yaml` (client HTTP `ai.http_client`), `config/packages/rate_limiter.yaml`,
  `k8s/overlays/*/external-secrets.yaml` (`ANTHROPIC_API_KEY`), frontend `admin/translation` ;
  objectifs n°8 (sécurité), n°9 (rien d'identifiant sans `ROLE_TRUSTED`) et n°12 (bilingue) ;
  étend la règle « aucun appel sortant dans un chemin de rendu public » de l'ADR 0002

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
Deux sont retenues, dans l'ordre : la traduction (phase 1, cette ADR) puis le serveur MCP (phase 2,
D7). Les autres sont écartées en fin de document.

## Décisions

### D1 — Un contexte borné `Ai/`, une interface applicative, un bundle pinné

Tout ce qui touche à un modèle de langage vit sous `src/Ai/`, découpé en sous-contextes par usage
(`Translation` en phase 1, `Mcp` en phase 2), avec le découpage habituel
`Domain/Application/Infrastructure/Presentation`. Rien d'IA ne s'enfouit dans `Portfolio/*` ni dans
`Security/*` : ces contextes ignorent qu'un modèle existe.

La frontière est une interface applicative par usage (`ContentTranslatorInterface` pour la
traduction). **Une seule classe du projet importe `Symfony\AI\*`**, dans `Infrastructure/` du
sous-contexte concerné (`SymfonyAiContentTranslator`). Le domaine et l'application ne connaissent ni
`MessageBag`, ni `AgentInterface`, ni `Platform` : changer de version du bundle, ou de fournisseur,
est un changement local à cette classe et à `ai.yaml`.

Les paquets `symfony/ai-bundle`, `symfony/ai-anthropic-platform` et `symfony/ai-agent` sont
**pinnés en version exacte** (`0.13.0`, pas de `^`) tant qu'ils sont en 0.x. Une montée de version
est un acte délibéré, lu dans le changelog, pas un effet de bord d'un `composer update`.

### D2 — Aucun appel à un fournisseur dans un chemin de rendu public

Règle de l'ADR 0002, étendue : **aucun provider public, aucun handler Messenger déclenché par un
visiteur, aucun CronJob de rendu n'appelle un modèle**. Un appel n'est possible que depuis un
chemin réservé à un compte authentifié dont le rôle justifie la dépense — en phase 1, le backoffice
(`ROLE_SUPER`), de façon synchrone, avec un timeout.

Conséquence pour l'infrastructure : le CronJob `watch-refresh` n'est plus le seul objet du cluster
à faire des appels sortants vers des tiers ; le Deployment `backend` en fait aussi, depuis le
backoffice uniquement. Une NetworkPolicy d'egress, si elle est un jour ajoutée, doit ouvrir
`api.anthropic.com` pour le backend comme endoflife.date et OSV.dev pour le CronJob.

### D3 — Ce qui peut partir vers un fournisseur, et ce qui ne le peut jamais

Peut être envoyé à un modèle : **le contenu rédigé dans le backoffice et destiné à la
publication**, sur n'importe quel palier (public, `ROLE_USER`). Ce contenu est, par construction,
ce que son auteur a décidé de publier ; le confier à un fournisseur n'expose rien qui ne le soit
déjà ou ne s'apprête à l'être.

Ne peut jamais être envoyé, quel que soit le fournisseur, y compris un modèle hébergé dans le
cluster : les données de `cpg_user` (e-mails, hachés de mots de passe, jetons), le CV nominatif
(le PDF servi par `GET /api/cv`, palier `ROLE_TRUSTED`), les messages du formulaire de contact
(texte libre d'un anonyme, données personnelles du visiteur), et tout ce que l'objectif n°9
réserve au palier nominatif. Brancher l'assistant sur un contenu de cette liste exige un
amendement de cette ADR, pas une revue de code.

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

### D6 — Aucun test ne sort sur le réseau

Les tests unitaires utilisent la plateforme en mémoire du composant (`InMemoryPlatform`,
`MockPlatformFactory`) ; les tests fonctionnels remplacent la plateforme, ou son client HTTP, dans
le conteneur de test. `phpunit.dist.xml` force une clé factice : un test qui atteindrait l'API
réelle échouerait en 401, et c'est le filet voulu. Un test qui appelle réellement un fournisseur
est intermittent par construction, coûte de l'argent à chaque exécution de la CI, et publie du
contenu de test chez un tiers.

### D7 — Phase 2 : un serveur MCP en lecture seule, réservé à `ROLE_TRUSTED`

Le second usage retenu est un serveur [MCP](https://modelcontextprotocol.io) exposant le portfolio
en lecture seule, pour qu'une personne autorisée puisse l'interroger depuis son propre assistant.
Trois règles sont fixées dès maintenant, le reste (transport, authentification du transport avec le
JWT existant, liste des outils) sera précisé par amendement avant tout code :

- le palier d'accès est **`ROLE_TRUSTED`**, pas le palier de base : ce que le serveur expose inclut
  ce que ce palier voit, et rien de plus ;
- les outils **passent par les providers existants**, jamais par les repositories : la partition
  par rôle est celle de l'API, appliquée au même endroit, et `ApiRouteExposureTest` (ou un
  équivalent pour le transport MCP) la pince ;
- il vit sous `src/Ai/Mcp/` et partage `ai.yaml` ; il n'appelle aucun modèle lui-même (c'est
  l'assistant du visiteur qui raisonne), donc D5 ne s'applique pas, mais D2 et D3 s'appliquent
  entièrement.

### Ce qui n'est jamais négociable

- Un `^` sur la version d'un paquet `symfony/ai-*` tant qu'il est en 0.x.
- Un appel à un modèle depuis un chemin public, un handler déclenché par un visiteur ou un CronJob
  de rendu.
- Une suggestion persistée sans action explicite d'un humain.
- `cpg_user`, un jeton ou le CV nominatif envoyés à un fournisseur.
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
- **Un bouton par page admin localisée**, branché page par page (Incidents d'abord) ; la page admin
  des études de cas, qui manque, est une tâche à part (issue #104).

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
- **L'agent RAG « interrogez mon parcours »** au palier de base : la plus démonstrative des pistes,
  mais un appel synchrone à la demande d'un visiteur (contraire à D2 sans un quota serré), un
  pgvector à ajouter sur un Postgres à état, et une surface d'injection de prompt ouverte à tous.
  Pas retenue en l'état ; réexaminable après la phase 2.
- **Le triage du formulaire de contact** : asynchrone par construction, mais c'est l'entrée la plus
  exposée à l'injection (texte libre d'un anonyme) et elle enverrait des données personnelles de
  visiteurs à un fournisseur (contraire à D3), pour un gain faible sur le volume d'un portfolio.
- **La synthèse des vulnérabilités OSV** et **le relecteur éditorial** : compatibles avec ces règles,
  valeur modeste ; non planifiés.
