# SPEC — Assistant « interrogez mon parcours » (`Ai/Assistant`, Symfony AI, Scaleway)

> Statut : **validée** le 2026-09-26 (rédigée le 2026-09-15 ; voir le journal en §10).
> Phase 2 de l'intégration de Symfony AI, cadrée par l'ADR 0004 telle qu'amendée le 2026-09-15 (D7
> réécrite, D2/D3/D5 retouchées). Elle succède à la spec 0002 (traduction, livrée) et en réutilise le
> socle : contexte `src/Ai/`, `ai.yaml`, client HTTP dédié, quota par compte, tests hors ligne.
>
> ⚠️ **Ce fichier vit dans un dépôt public** (`.claude/` est versionné, hors `CLAUDE.local.md`).
> Aucun secret, aucune adresse réelle, aucun nom de domaine de production, aucun nom de personne.
> Voir le journal d'audit en §10.

---

## 1. Objectif

Une personne du palier nominatif (`ROLE_TRUSTED`, ADR 0003) lit déjà trois contenus : le CV sans
identité, les études de cas, et le CV nominatif au format PDF. L'assistant lui permet de **poser des
questions en langage naturel sur ce parcours**, depuis une page du site, et d'obtenir des réponses
fondées sur ces trois contenus et rien d'autre — en français ou en anglais, dans la langue de la
question.

### Le vrai sujet de démonstration

Comme pour la traduction (spec 0002), le thème est un prétexte. Ce qui est mis en scène :

- **un second fournisseur derrière la même abstraction** — Anthropic pour la traduction, Scaleway
  pour l'assistant, sans qu'une classe de la phase 1 ne change (ADR 0004 D1) ;
- **un choix de fournisseur dicté par la donnée qui circule** : le CV nominatif ne quitte pas
  l'hébergeur du site (D3 amendée) ;
- **la sécurité par construction** : l'assistant ne sait rien que l'appelant ne lise déjà, donc une
  injection de prompt réussie ne révèle rien (D7) ;
- **un coût borné** : quota par compte, plafond de sortie, borne sur l'entrée, timeout (D5 amendée) ;
- **une réponse en flux** avec Symfony AI et `EventStreamResponse`, à travers deux reverse proxies.

### Public visé

Un compte `ROLE_TRUSTED` (invité nominativement par un `ROLE_SUPER`, ADR 0001/0003), ou un
`ROLE_SUPER` (qui hérite du rôle). Ni l'anonyme, ni le palier de base (`ROLE_USER`, jeton sans
compte compris) n'atteignent l'assistant — ni directement, ni par effet de bord.

### Hors périmètre (v1)

- Toute exposition au palier de base ou anonyme (ADR 0004 D2 amendée).
- Des outils ou appels de fonction, un index vectoriel, une récupération (D7 : le corpus est injecté).
- Un historique de conversations côté serveur, ou toute persistance (D7).
- Un résumé automatique de l'historique par le modèle (un appel de plus par tour).
- D'autres sources que les trois de D7 — un contenu de palier inférieur (À propos, incidents,
  contributions) peut s'ajouter par amendement de cette spec, un contenu de palier supérieur exige
  un amendement de l'ADR.
- Un modèle auto-hébergé (Ollama) : alternative écartée de l'ADR, réouvrable par configuration.
- Le serveur MCP : écarté par l'amendement de l'ADR.
- L'ajout de la clé Scaleway au bloc `when@prod` ou à une NetworkPolicy d'egress : aucune n'existe.

## 2. Décisions structurantes

- **D1 — Sous-contexte `src/Ai/Assistant/`**, même découpage que `Ai/Translation/`. Une interface
  applicative (`CareerAssistantInterface`) ; **une seule classe importe `Symfony\AI\*`**
  (`Infrastructure/SymfonyAi/SymfonyAiCareerAssistant`). Le corpus et sa mise en forme sont
  applicatifs et ne connaissent pas le bundle.
- **D2 — Plateforme Scaleway, déclarée comme service, pas dans `ai.yaml`.** Vérifié dans
  `symfony/ai-bundle` 0.13.0 (`AiBundle.php`, bloc `'scaleway' === $type`) : contrairement au bloc
  Anthropic, qui injecte `$platform['http_client']`, le bloc Scaleway référence **le client
  `http_client` par défaut en dur** — l'option `http_client` est ignorée. Le client dédié exigé par
  l'ADR (timeout 40 s, `max_redirects: 0`) ne peut donc pas passer par la configuration du bundle.
  La plateforme est déclarée dans `services.yaml` par la factory du bridge
  (`Symfony\AI\Platform\Bridge\Scaleway\Factory::createPlatform`) avec le client scoped
  `ai.scaleway.http_client` en argument ; l'agent `career_assistant` de `ai.yaml` la référence par
  son id de service (`platform: 'app.ai.platform.scaleway'`). Un commentaire dans `services.yaml`
  cite la ligne du bundle ; le jour où une version corrige la limitation, la déclaration revient
  dans `ai.yaml`. *À signaler en amont (issue sur `symfony/ai`), hors périmètre de la spec.*
- **D3 — Modèle `mistral-small-3.2-24b-instruct-2506`** (identifiant du catalogue du bridge,
  capacités `OUTPUT_STREAMING` et `OUTPUT_STRUCTURED` déclarées), **confirmé par un appel réel en
  M1** sur la quantité offerte. Un modèle de repli est nommé au même moment (`gemma-3-27b-it` ou
  `gpt-oss-120b`) si la qualité ou la disponibilité déçoit. Le nom du modèle est de la configuration :
  changer de modèle **chez Scaleway** ne demande ni amendement ni spec ; changer d'opérateur, si.
  Aucun paramètre d'échantillonnage n'est fixé en v1 ; `max_tokens` l'est (D6).
- **D4 — Endpoint `POST /api/assistant/answers`**, un contrôleur Symfony (pas une ressource API
  Platform) : la réponse est un `EventStreamResponse`, ce qu'un processeur API Platform ne produit
  pas. Corps `{locale, messages: [{role, content}…]}`, validé par le Validator sur un DTO ; réponse
  `text/event-stream`, événements `delta` (fragment de texte), `done` (jetons et durée), `error`
  (raison). `access_control` `{ path: ^/api/assistant(/|$), roles: ROLE_TRUSTED }`, **placée après
  `^/api/backoffice` et avant les règles `ROLE_USER`**, sur un préfixe qui n'est pas `^/api/cv`.
  Double-submit CSRF exigé comme toute mutation `/api` (aucune entrée dans `EXCLUDED_PATHS`).
- **D5 — Corpus rendu par un composant unique** (`Application/Corpus/CorpusRenderer`), en Markdown
  déterministe : une locale (celle de la requête), sections dans un ordre fixe (CV nominatif, CV
  sans identité, études de cas), entrées triées par `position`, intertitres nommés dans la langue
  du corpus, **aucun champ technique** (id, groupe de traduction, locale). Les sources sont lues
  **par les providers publics existants** (`AnonymousCvProvider`, `CaseStudyProvider`, appelés
  avec une opération `GetCollection` et `['locale' => …]`), jamais par les repositories ; le CV
  nominatif par un lecteur dédié (D7). Le rendu est **assemblé à chaque requête**, jamais
  précalculé (raisons dans l'ADR : chemins d'oubli d'une invalidation).
- **D6 — Coût borné, quatre bornes** (ADR D5 amendée) :
  - quota `career_assistant`, fenêtre glissante, **30 appels par heure et par compte** (clé :
    `username`), consommé **après** validation et **avant** l'appel ;
  - `max_tokens: 1024` en sortie ;
  - **entrée bornée par le serveur** : au plus **12 messages** (6 échanges) dans `messages`, au
    plus **1 000 caractères** par message utilisateur, **4 000** par message assistant (une réponse
    renvoyée), premier message de rôle `user`, alternance stricte `user`/`assistant`, dernier
    message `user` — sinon 422 ;
  - timeout **40 s** sur le client dédié, sous les 60 s de nginx et de l'ingress.
- **D7 — Le CV nominatif entre comme texte extrait du PDF**, par `smalot/pdfparser` (pur PHP, pas de
  binaire à ajouter à l'image ; `ext-iconv` et `ext-zlib` sont dans l'image `php:alpine`), puis
  **normalisé** : espaces et tabulations compressés, lignes recollées en paragraphes (une ligne
  vide = un paragraphe), en-têtes ou pieds répétés d'une page à l'autre supprimés, caractères de
  contrôle retirés. **Aucun cache : l'extraction est refaite à chaque requête**, et le texte
  n'existe qu'en mémoire le temps de la requête — **jamais persisté, jamais journalisé**.
  `cache.app` est sur Doctrine DBAL depuis l'ADR 0005 : y ranger le texte écrirait le CV nominatif
  complet dans la table `cache_items`, donc dans la base et ses sauvegardes. Le coût de
  l'extraction (quelques millisecondes attendues, **mesuré en M2**) est borné par le quota D6 ; si
  la mesure le rendait gênant, un cache se rediscute par amendement, jamais sur un stockage
  persistant. Fichier absent → le corpus omet la
  section et le prompt le dit (« le CV détaillé n'est pas disponible ») ; ce n'est pas une erreur.
- **D8 — Prompt système en deux parties** : un préambule fixe (`config/ai/prompts/career_assistant.txt`,
  rôle, règles, refus hors sujet, langue de réponse = langue de la question, ne rien inventer, citer
  la section d'où vient l'information) suivi du **corpus rendu**, puis la conversation. Le corpus est
  ainsi un **préfixe byte-identique** d'un appel à l'autre pour une locale donnée — condition du
  cache de prompt côté fournisseur, dont l'existence pour ce modèle est **vérifiée en M1** (gain
  attendu, pas condition).
- **D9 — Streaming de bout en bout** : `Execution::asStream()` du bundle (option `stream: true`) →
  `EventStreamResponse` → `fetch` + `ReadableStream` côté Vue (pas `EventSource`, qui ne pose ni
  méthode POST ni en-tête `X-XSRF-TOKEN`). Tampons coupés sur ce chemin seulement : `fastcgi_buffering
  off` dans une `location ^~ /api/assistant/` des deux fichiers nginx miroirs, et l'en-tête
  `X-Accel-Buffering: no` posé par le contrôleur, que l'ingress-nginx honore sans annotation globale.
- **D10 — Rien n'est persisté, rien du contenu n'est journalisé.** Ni question, ni réponse, ni
  corpus dans les logs ; jetons, durée, nombre de messages, statut de fin (`done`/`error`) en `info`.
- **D11 — Paquets pinnés** : `symfony/ai-scaleway-platform: 0.13.0` (exact) et `smalot/pdfparser`
  en contrainte `^2.12` (paquet stable, hors règle 0.x).

### Contrats externes (vérifiés le 2026-09-15)

- **`symfony/ai-scaleway-platform` 0.13.0** (30 août 2026, même monorepo que les paquets pinnés).
  `Factory::createPlatform(apiKey, httpClient, modelCatalog, contract, eventDispatcher, name,
  modelRouter, baseUrl = 'https://api.scaleway.ai')` ; enveloppe le client fourni dans un
  `EventSourceHttpClient` (streaming SSE) ; endpoint `POST {baseUrl}/v1/chat/completions`, en-tête
  `Authorization: Bearer <clé>`. Catalogue : `mistral-small-3.2-24b-instruct-2506`, `gemma-3-27b-it`,
  `llama-3.3-70b-instruct`, `gpt-oss-120b`, `qwen3-235b-a22b-instruct-2507`…, tous avec
  `OUTPUT_STREAMING`, `TOOL_CALLING`, `OUTPUT_STRUCTURED`.
- **`symfony/ai-bundle` 0.13.0** : la plateforme Scaleway configurée en YAML ignore `http_client`
  (D2). L'agent lit `platform:` comme un id de service, `model.name`, `model.options`,
  `prompt.file`, `tools: false`. `Agent::call()` avec `['stream' => true]` renvoie une `Execution`
  dont `asStream()` est un générateur de fragments ; les métadonnées (`token_usage`) sont lues
  après consommation du flux.
- **Symfony 8.1** : `Symfony\Component\HttpFoundation\EventStreamResponse` + `ServerEvent`
  (présents dans `vendor/`), le contrôleur renvoie un générateur d'événements.
- **Scaleway Generative APIs** (tarif public au 2026-09-15, région `fr-par`) : Mistral Small 3.2 à
  0,15 € / 0,35 € par million de jetons entrée / sortie ; 1 M de jetons offerts (**mensuel ou
  unique : non vérifié**, à lire dans la console en M1) ; pas de frais fixes. Ordre de grandeur :
  8 000 jetons en entrée + 300 en sortie ≈ 0,0013 € la question ; pire cas D6 (trois comptes à
  30/h en continu) < 100 €/mois.
- **`smalot/pdfparser`** v2.12.5 (2026-04-17), PHP ≥ 7.1, pur PHP, dépend d'`ext-iconv`, `ext-zlib`,
  `symfony/polyfill-mbstring`. Maintenance limitée annoncée : compatibilité PHP 8.4 **à vérifier en
  M1** par l'installation et un test d'extraction sur un PDF de fixture ; repli `spatie/pdf-to-text`
  (binaire `poppler-utils` à ajouter à l'image, plus précis) si l'extraction déçoit.
- **Environnement d'exécution** : `max_execution_time = 30` en prod — sous Linux cette limite ne
  compte pas l'attente réseau, et le contrôleur n'a rien à calculer pendant le flux ; nginx et
  l'ingress coupent à 60 s ; le timeout client de 40 s reste dessous. Le limiteur de débit Symfony
  s'appuie sur `cache.app`, sur Doctrine DBAL depuis l'ADR 0005 (v0.14.1) : le compteur est
  **commun à tous les pods**, le quota effectif est bien de 30/h par compte quel que soit le
  nombre de réplicas (le pire cas ci-dessus, ≈ 85 €/mois, est calculé sur cette base).

## 3. Carte des capacités (ordre de construction)

| # | Capacité | Dépend de | Livrable |
|---|---|---|---|
| M1 | Socle : `symfony/ai-scaleway-platform` pinné, `smalot/pdfparser`, client scoped `ai.scaleway.http_client`, plateforme déclarée en service (D2), agent `career_assistant` dans `ai.yaml`, prompt système, `SCALEWAY_AI_API_KEY` (`.env` vide, `phpunit.dist.xml` factice, `.env.local` dev, `init-symfony.sh`), **appel réel** `ai:agent:call career_assistant` en dev, vérification du cache de prompt, CLAUDE.md | — | `composer phpstan`/`rector`/`psalm`/`lsp:check` verts, kernel bootable en test sans clé réelle |
| M2 | Corpus : `PdfTextExtractor` + normalisation (sans cache, mesure de la durée), `CorpusRenderer` (trois sources, Markdown déterministe), `CorpusSourcesTest` pinçant la liste des sources, test de rendu sur fixtures | M1 | Tests unitaires, un PDF de fixture (contenu fictif, aucune donnée réelle) |
| M3 | Assistant : VO `Conversation`/`ConversationMessage` (bornes D6), `CareerAssistantInterface` + `SymfonyAiCareerAssistant` (flux, jetons, journalisation), exceptions, limiteur `career_assistant` + `Retry-After` | M2 | Tests unitaires avec `FakeAgent` (en flux) |
| M4 | Endpoint `POST /api/assistant/answers` : contrôleur, DTO validé, `EventStreamResponse`, `access_control`, `exception_to_status`, nginx `location` (deux fichiers), `X-Accel-Buffering` | M3 | Tests fonctionnels (401/403/403 CSRF/422/429/503/200 en flux), `ApiRouteExposureTest` inchangé et vert, `AccessControlAnchoringTest` vert |
| M5 | Déploiement : clé Scaleway dans Secret Manager et les deux `ExternalSecret backend-secrets`, `k8s/README.md`, ConfigMap nginx (hash → rollout du sidecar), procédure de vérification (`nginx -T`, une question réelle en flux) **exécutée pendant la release de la spec** | M4 | Overlays et README à jour, clé publiée avant la release ; en release : rollout préprod, une question réelle depuis un compte de test `ROLE_TRUSTED`, flux visible |
| M6 | Frontend : tranche `assistant` (domaine, repository HTTP en flux, composable à machine d'états, page `/(fr|en)/assistant` derrière `requiresAuth` + `roles: [ROLE_TRUSTED]`, lien dans la zone connectée de l'en-tête, clés i18n, axe) | M4 | Specs Vitest + axe, `make front-lint`/`front-build` verts, test navigateur réel sur la stack dev |

M1 à M6 forment la tranche verticale complète ; il n'y a pas de M7.

### Découpage en tâches (issues, 2026-09-26)

Les jalons découpent par couche ; les tâches les regroupent en tranches verticales. Chaque tâche
a sa branche, tirée de la branche mère `feature/spec-0005-career-assistant`, et sa PR vers elle.

| Tâche | Issue | Jalons couverts | Bloquée par |
|---|---|---|---|
| 1 — Socle Scaleway et appel réel en dev | #260 | M1 | — |
| 2 — Une question en flux de bout en bout | #261 | M2 (sans PDF), M3 et M4 (sans bornes ni quota) | #260 |
| 3 — Coût borné : bornes D6 et quota | #262 | M3 et M4 (bornes, quota, 422/429) | #261 |
| 4 — CV nominatif dans le corpus | #263 | M2 (PDF) | #261 |
| 5 — Préparation du déploiement | #264 | M5 | #261 |
| 6 — Page Assistant | #265 | M6 | #261, #262 |

## 4. Critères d'acceptation

### M2 — Corpus

- Étant donné deux sections de CV sans identité et deux études de cas en `fr` (fixtures) et un PDF
  de fixture, `CorpusRenderer::render(Locale::FR)` renvoie **exactement** le document Markdown
  attendu (snapshot versionné dans `tests/`), avec les intertitres français (« CV détaillé », « CV
  sans identité », « Études de cas », « Problème », « Solution », « Compromis », « Résultat mesuré »,
  « Compétences », « Années d'expérience », « Réalisations ») ; idem en `en` avec les intertitres
  anglais. Deux appels successifs rendent la même chaîne octet pour octet.
- Le document ne contient aucun UUID, aucun `translationGroup`, aucune mention de locale, aucune
  clé JSON.
- Les entrées suivent l'ordre `position` croissant quel que soit l'ordre de retour des fixtures.
- Le texte extrait d'un PDF de fixture à deux pages avec en-tête répété apparaît une seule fois par
  paragraphe, sans l'en-tête dupliqué, sans retour à la ligne intra-paragraphe.
- Fichier PDF absent → le document contient la phrase de substitution et aucune exception.
- `CorpusSourcesTest` : la liste des sources du rendu est exactement `{AnonymousCvProvider,
  CaseStudyProvider, PdfTextExtractor}` — ajouter une source fait échouer le test tant que la
  liste attendue (avec justification écrite, comme `PUBLIC_PATHS`) n'est pas mise à jour ; aucune
  classe `Backoffice*` ni aucun `*Repository` n'y est admis (assertion sur les noms).
- Le texte extrait n'est mis dans aucun cache : remplacer le PDF de fixture entre deux appels
  change le rendu dès l'appel suivant ; la durée d'une extraction est mesurée et notée au journal.
- Aucun log ne contient un fragment du corpus (logger de test, assertion négative).

### M3 — Assistant

- `answer(Conversation, Locale)` renvoie un itérable de fragments ; concaténés, ils forment la
  réponse du faux agent. Les jetons et la durée sont journalisés en `info` **après** la fin du flux ;
  jamais le contenu.
- Le message système transmis à l'agent commence par le préambule du fichier de prompt et se
  poursuit par le corpus rendu ; la conversation suit dans l'ordre reçu.
- Bornes D6 côté VO : 13 messages → `InvalidConversationException` ; message utilisateur de 1 001
  caractères → idem ; premier message `assistant` → idem ; deux `user` consécutifs → idem ; dernier
  message `assistant` → idem ; contenu vide ou espace seul → idem.
- Exception du fournisseur avant le premier fragment → `AssistantUnavailableException` (503) ;
  exception **pendant** le flux → le générateur émet un événement `error` et se termine, le
  contrôleur ne peut plus changer le statut HTTP (déjà 200), le frontend affiche l'erreur.
- Le quota est consommé une fois par appel, après validation ; le 31e appel dans l'heure lève
  `AssistantRateLimitExceededException` avec le délai de reprise.

### M4 — Endpoint et cloisonnement

- Anonyme → 401 ; jeton du palier de base → 403 ; compte `ROLE_TRUSTED` sans `X-XSRF-TOKEN` → 403 ;
  compte `ROLE_TRUSTED` avec CSRF → 200, `Content-Type: text/event-stream`, `X-Accel-Buffering: no`,
  `Cache-Control: no-store`, suite d'événements `delta` puis un `done` portant `promptTokens`,
  `completionTokens`, `durationMs` ; `ROLE_SUPER` → 200 également (hiérarchie).
- 422 sur chaque borne D6 et sur `locale` hors `Locale::values()` ; le quota n'est pas consommé sur
  un 422.
- 429 avec `Retry-After` au 31e appel du même compte dans l'heure ; un autre compte n'est pas
  affecté.
- Client HTTP simulé en échec → 503, `type: /errors/assistant-unavailable`.
- `ApiRouteExposureTest` vert **sans** modification de `PUBLIC_PATHS` ni de `BASE_TIER_PATHS` ;
  `AccessControlAnchoringTest` vert ; `debug:router | grep assistant` liste exactement une route.
- Le corps de requête est refusé au-delà de 64 Ko (`client_max_body_size` nginx reste à 1 Mo ; la
  borne applicative vient des longueurs D6).
- Tests fonctionnels : `ai.scaleway.http_client.scoping.inner` remplacé par un `MockHttpClient`
  renvoyant un flux SSE au format OpenAI-compatible (`data: {"choices":[{"delta":{"content":"…"}}]}`
  … `data: [DONE]`), avec `$client->disableReboot()` (leçon de la spec 0002). La clé factice de
  `phpunit.dist.xml` fait échouer en 401 tout appel qui partirait réellement.

### M6 — Frontend

- Route `/(fr|en)/assistant`, `meta: { requiresAuth: true, roles: [ROLE_TRUSTED], noindex: true,
  titleKey, descriptionKey }` : anonyme → redirigé vers `login` avec `redirect` ; palier de base →
  page « Interdit » (garde existante, `waitForAuthCheck()` respecté).
- Lien « Assistant » dans la zone connectée de l'en-tête, visible pour `'trusted' === tier`
  uniquement (pas pour le palier de base, pas pour l'anonyme), sur le même modèle que le bouton de
  téléchargement du CV, en desktop et en mobile.
- Page : transcript (`role="log"`, `aria-live="polite"`), un seul `role="status"` pour l'état de
  l'appel (« réponse en cours… »), zone de saisie (`BaseTextarea`, compteur, limite 1 000
  caractères, Entrée envoie, Maj+Entrée saute une ligne), bouton « Envoyer » désactivé pendant un
  appel (`aria-busy`) ou si vide, bouton « Nouvelle conversation », bandeau permanent « L'assistant
  peut se tromper, les documents font foi » avec liens vers les trois contenus.
- Fenêtre glissante côté client : l'affichage garde tout, la requête n'envoie que les 12 derniers
  messages ; un message assistant tronqué à 4 000 caractères avant envoi ; l'utilisateur ne
  rencontre jamais le 422 en usage normal.
- Flux : le texte s'affiche fragment par fragment ; à `done`, le message est figé ; à `error`, un
  `role="alert"` explicite et le message partiel reste visible, marqué incomplet.
- Erreurs HTTP : 401/403 → `markBaseAccessExpired()`-équivalent pour le palier nominatif (l'en-tête
  cesse d'afficher l'état connecté) et invitation à se reconnecter ; 429 → « quota atteint,
  réessayez dans N minutes » (depuis `Retry-After`) ; 503 → « assistant indisponible » ; réseau →
  générique. Toutes en `role="alert"`, la conversation reste intacte.
- **Jamais de `v-html`** : le texte du modèle est rendu par `RichText.vue` (paragraphes, `backticks`).
- Audit axe vert ; `make front-lint` : chaînes sous `assistant.*` (fr et en), aucune `no-raw-text`.
- Test navigateur réel sur la stack dev (règle : le propriétaire du site se connecte lui-même dans l'onglet, l'agent pilote ensuite),
  une question dont la réponse est vérifiable dans le CV sans identité, capture d'écran dans la PR.

## 5. Structure du projet

### Backend — `backend/src/Ai/Assistant/`

```
Domain/
  ValueObject/Conversation.php               # list<ConversationMessage>, bornes D6, alternance
  ValueObject/ConversationMessage.php        # role (enum Role: user|assistant), content
  ValueObject/Role.php
  Exception/InvalidConversationException.php       # 422
  Exception/AssistantUnavailableException.php      # 503, ProblemExceptionInterface + HasProblemType
  Exception/AssistantRateLimitExceededException.php # 429, porte le Retry-After
Application/
  CareerAssistantInterface.php               # answer(Conversation, Locale): iterable<string>
  AssistantRateLimiterInterface.php
  Corpus/CorpusRendererInterface.php         # render(Locale): string
  Corpus/CorpusRenderer.php                  # providers publics + PdfTextExtractor → Markdown
  Corpus/PdfTextExtractorInterface.php       # extract(): ?string (null si fichier absent)
Infrastructure/
  SymfonyAi/SymfonyAiCareerAssistant.php     # seule classe qui importe le bundle ; stream: true
  Pdf/SmalotPdfTextExtractor.php             # smalot/pdfparser + normalisation, sans cache (D7)
  RateLimiter/SymfonyAssistantRateLimiter.php  # limiter.career_assistant, clé = username
  Http/AssistantRateLimitRetryAfterListener.php
Presentation/
  Controller/AnswerController.php            # POST /api/assistant/answers → EventStreamResponse
  Dto/AnswerRequest.php                      # locale (Assert\Choice(Locale::values())), messages
```

`Locale` vient de `Portfolio/Shared/Domain/ValueObject/` (déjà consommé hors `Portfolio/`). Le DTO
est validé par le Validator (`#[MapRequestPayload]`), puis converti en `Conversation` ; une
violation de borne D6 détectée par le VO est une `InvalidConversationException` → 422.

Configuration :

```
config/packages/ai.yaml         # + agent.career_assistant (platform: 'app.ai.platform.scaleway',
                                #   model.name, model.options.max_tokens: 1024, prompt.file, tools: false)
config/services.yaml            # + app.ai.platform.scaleway (factory du bridge, client scoped) — D2
config/ai/prompts/career_assistant.txt
config/packages/framework.yaml  # + http_client.scoped_clients.ai.scaleway.http_client
                                #   (base_uri https://api.scaleway.ai, timeout 40, max_redirects 0)
config/packages/rate_limiter.yaml   # + career_assistant (sliding_window, 30 / 1 hour)
config/packages/security.yaml   # + { path: ^/api/assistant(/|$), roles: ROLE_TRUSTED }
config/packages/api_platform.yaml   # + 3 entrées exception_to_status (422/429/503)
docker/nginx/default.conf + k8s/base/backend-nginx.conf   # + location ^~ /api/assistant/ (fastcgi_buffering off)
```

### Frontend — `frontend/src/`

```
domain/assistant/entities/AssistantMessage.ts          # role, content, status ('complete'|'streaming'|'incomplete')
domain/assistant/repositories/AssistantRepository.ts   # answer(locale, messages, onDelta, signal): Promise<AnswerOutcome>
domain/assistant/errors/AssistantError.ts              # 'unauthenticated'|'forbidden'|'validation'|'rate-limited'|'unavailable'|'unknown'
infrastructure/assistant/HttpAssistantRepository.ts    # fetch POST + ReadableStream, parse SSE, X-XSRF-TOKEN
application/assistant/useAssistant.ts                  # machine d'états idle|streaming|error, fenêtre glissante, abort
presentation/pages/AssistantPage.vue
presentation/router/index.ts                           # + route assistant (requiresAuth, roles, noindex)
presentation/layout/AppHeader.vue                      # + lien Assistant (tier trusted)
infrastructure/i18n/locales/{fr,en}.json               # assistant.*, nav.assistant, seo.assistant.*
main.ts                                                # provide(ASSISTANT_REPOSITORY, …)
```

Tests miroir sous `tests/` (`tests/infrastructure/assistant/HttpAssistantRepository.spec.ts` avec un
`ReadableStream` simulé, `tests/application/assistant/useAssistant.spec.ts`,
`tests/presentation/pages/AssistantPage.spec.ts` avec axe, extension de
`tests/presentation/layout/AppHeader.spec.ts` et `tests/presentation/router/adminGuard.spec.ts`).

### Ce qui est touché en dehors du contexte

- **`config/packages/security.yaml`** : une règle `access_control` (D4). Aucun firewall nouveau.
- **`tests/Security/ApiRouteExposureTest.php`** : **aucune modification** — c'est le critère.
- **`docker/nginx/default.conf`** et **`k8s/base/backend-nginx.conf`** : une `location` chacun,
  miroirs. La ConfigMap hachée fait redémarrer le sidecar (`CLAUDE.md`, issue #18) ; vérifier avec
  `nginx -T | grep assistant` après le déploiement.
- **`k8s/overlays/{preprod,prod}/external-secrets.yaml`** : `SCALEWAY_AI_API_KEY` dans
  `backend-secrets` ; **`k8s/README.md`** : la clé à créer (une clé d'API IAM dédiée, application
  distincte de celle d'ESO, permission Generative APIs seule — moindre privilège comme pour le
  mailer). **Le secret doit exister avant le premier déploiement préprod**, sinon le Deployment ne
  démarre pas (leçon de la phase 1).
- **`docker/php/init-symfony.sh`** : ligne `SCALEWAY_AI_API_KEY=` dans le `.env.local` généré ; un
  checkout existant l'ajoute à la main.
- **`phpunit.dist.xml`** : `SCALEWAY_AI_API_KEY` factice forcée.
- **`CLAUDE.md`** : le paragraphe `Ai/` (phase 2 livrée, D2 de cette spec sur le client HTTP), la
  liste des variables servies hors dépôt, la liste des routes de palier et la note sur les tampons
  nginx/ingress.
- **`docs/adr/0004-assistance-ia.md`** : à la livraison, statut « phase 2 livrée » + écarts.

## 6. Commandes

```bash
# Backend (dans make sh)
composer require symfony/ai-scaleway-platform:0.13.0     # pin exact
composer require smalot/pdfparser:^2.12
php bin/console debug:container app.ai.platform.scaleway  # la plateforme prend bien le client scoped
php bin/console ai:agent:call career_assistant            # appel réel en dev (clé dans .env.local) — M1
php bin/console debug:router | grep assistant             # une seule route
php bin/phpunit tests/Ai/Assistant
composer phpstan && composer rector && composer psalm && symfony lsp:check

# Frontend
make front-test && make front-lint && make front-build

# Préprod, après rollout
kubectl exec deploy/backend -c nginx -n preprod -- nginx -T | grep -A3 'assistant'
```

## 7. Style de code

- Conventions du projet sans exception : `declare(strict_types=1)`, `readonly`, Value Objects,
  exceptions métier explicites, interface pour tout service consommé par le contrôleur, PHPStan
  `max` sans baseline, Rector et `lsp:check` verts.
- `SymfonyAiCareerAssistant` est **la seule classe** à importer `Symfony\AI\*`. `CorpusRenderer` et
  `SmalotPdfTextExtractor` n'en voient rien.
- Le contrôleur reste léger : validation par le Validator, conversion en VO, appel de l'interface,
  construction de l'`EventStreamResponse`. Aucune logique de corpus ni de quota dedans.
- Un `ignoreErrors` PHPStan, s'il faut, est scopé à `src/Ai/Assistant/Infrastructure/` et justifié ;
  aucune annotation Psalm.
- Le prompt vit dans un fichier texte ; les intertitres du corpus dans un tableau PHP par locale,
  dans `CorpusRenderer` (ce sont des libellés de rendu pour le modèle, pas des chaînes d'interface).
- Frontend : `RichText.vue` pour tout texte revenu du modèle ; `Base*` pour la saisie ; le
  repository parse le SSE et ne connaît pas Vue ; le composable ne connaît pas `fetch`.

## 8. Stratégie de test

- **Unitaires (`tests/Ai/Assistant/`)** : VO `Conversation` (chaque borne D6, alternance) ;
  `CorpusRendererTest` sur fixtures + snapshot ; `SmalotPdfTextExtractorTest` sur un PDF de fixture
  généré pour l'occasion (texte fictif, versionné, quelques Ko) et sur un fichier absent ;
  `CorpusSourcesTest` (liste pincée) ; `SymfonyAiCareerAssistantTest` avec un `FakeAgent` en flux
  (réutiliser ou étendre `tests/Ai/Translation/Support/FakeAgent.php` → le déplacer dans
  `tests/Ai/Support/` s'il sert aux deux) ; `SymfonyAssistantRateLimiterTest` calqué sur celui de la
  traduction.
- **Fonctionnels (`tests/Ai/Assistant/Presentation/Controller/AnswerControllerTest.php`)** : les
  cas de §4 M4, avec `MockHttpClient` sur `ai.scaleway.http_client.scoping.inner` et
  `disableReboot()`. La lecture du flux côté test passe par `$client->getResponse()->getContent()`
  après `ob_start()`/`sendContent()` si `KernelBrowser` ne capture pas le contenu diffusé — à
  vérifier en M4, et noter l'écart dans le journal.
- **Régression de cloisonnement** : `ApiRouteExposureTest` inchangé (401 anonyme, 403 palier de
  base sur la nouvelle route, aucune entrée d'allow-list) ; `AccessControlAnchoringTest` ;
  `ItemRouteRequirementTest` sans objet (pas de `{id}`).
- **Aucun test ne sort sur le réseau** : clé factice forcée dans `phpunit.dist.xml`.
- **Frontend** : repository (parse SSE fragment par fragment, statuts → raisons, abort) ; composable
  (états, fenêtre glissante, message incomplet sur `error`) ; page (transcript, `role="log"`,
  alerte, désactivation pendant l'appel, axe) ; en-tête (lien visible pour `trusted` seulement) ;
  garde de routeur (anonyme → login, base → forbidden, trusted → page).

## 9. Limites

### Toujours

- Vérifier `debug:router` après avoir déclaré la route (une seule, `POST`).
- Consommer le quota après validation, avant l'appel ; journaliser jetons et durée, jamais le
  contenu, jamais un fragment du corpus.
- `Locale::from()` sur la valeur bornée par `Assert\Choice`, pas `fromString()` (rien ne vient d'une
  URL).
- Garder les deux fichiers nginx miroirs.

### Demander avant

- Ajouter une source au corpus, même de palier inférieur (amendement de cette spec, mise à jour de
  `CorpusSourcesTest` avec justification).
- Élever une borne D6, le `max_tokens`, le timeout.
- Changer d'opérateur de modèle (amendement de l'ADR, D3) — changer de modèle chez Scaleway ne
  demande rien.
- Ajouter un outil à l'agent.
- Introduire un `ignoreErrors` PHPStan hors du périmètre §7.

### Jamais

- Un appel à la plateforme depuis un chemin public, le palier de base, un CronJob ou un handler
  Messenger déclenché par un visiteur.
- Le CV nominatif, ou le corpus, envoyé à Anthropic ou à tout fournisseur autre que Scaleway.
- Une source lue par un repository ou une classe `Backoffice*`.
- Persister une conversation, une réponse ou le texte extrait, `cache.app` compris.
- Assouplir `ApiRouteExposureTest` ; ajouter la route à `BASE_TIER_PATHS`.
- `v-html` sur un texte du modèle.
- Un `^` sur un paquet `symfony/ai-*`.
- Un nom de personne, une adresse ou un extrait du CV réel dans un test, une fixture ou ce fichier.

## 10. Journal des validations

**2026-09-15** — Rédaction, après l'amendement de l'ADR 0004 (PR #187). Décisions prises en session
avant la rédaction : Scaleway plutôt qu'Ollama (coût et temps de réponse chiffrés dans l'ADR), MCP
abandonné, CV nominatif inclus via extraction du PDF, corpus injecté et assemblé à chaque requête,
fenêtre glissante sur l'historique. Points découverts à la rédaction et fixés ici : le bundle 0.13.0
ignore `http_client` pour Scaleway (D2), `EventStreamResponse` est disponible (D9), le limiteur de
débit est local au pod (contrats externes).

**Audit de sensibilité avant publication** : aucun e-mail, aucune adresse IP, aucun nom de domaine,
aucun secret (la clé n'apparaît que comme nom de variable, `SCALEWAY_AI_API_KEY`), aucun nom de
personne. Le document dit que le CV nominatif est un PDF servi depuis un fichier hors dépôt et qu'il
est envoyé à l'API d'inférence de l'hébergeur : information déjà publique dans `services.yaml`,
`resources/README.md` et l'ADR 0004 amendée. Les fixtures de test seront fictives par construction
(§9, « Jamais »).

**À valider en session** : le nom de la route (`/api/assistant/answers`), les chiffres D6, le
libellé et l'emplacement du lien dans l'en-tête, le modèle de repli, et l'ordre des milestones
(M5 déploiement avant M6 frontend, pour qu'une question réelle passe en préprod avant la page).

**2026-09-26** — Validation en session. Les cinq points ci-dessus sont validés tels que rédigés
(route, chiffres D6, lien « Assistant » dans la zone connectée pour le palier nominatif seulement,
modèle de repli tranché en M1 après l'appel réel, M5 avant M6). Deux faits avaient dérivé depuis
la rédaction et sont corrigés : le limiteur de débit n'est plus local au pod (`cache.app` sur
Doctrine DBAL, ADR 0005), le quota de 30/h est donc global ; et, pour la même raison, le cache du
texte extrait prévu en D7 aurait écrit le CV nominatif dans `cache_items` — **D7 est amendée :
aucun cache**, extraction à chaque requête (options écartées : accepter la table, ou un pool APCu
qui ajoute une extension à l'image pour un gain faible).

**2026-09-26 (découpage)** — L'ordre « M5 avant M6 » validé plus haut ne peut pas tenir tel
qu'écrit : la préprod ne se déploie que depuis une branche `release/*` coupée de `develop`
(spec 0006), et `develop` ne reçoit cette spec qu'une fois, par la branche mère. M5 est donc
réduit à la préparation du déploiement ; la question réelle en préprod devient une vérification
de la release de la spec, avant le merge dans `main`. M5 et M6 ne dépendent plus l'un de l'autre.
Découpage en six tâches verticales publié (#260 à #265, dépendances natives GitHub).

**2026-09-26 (tâche 1, #260)** — Trois arbitrages pris à la relecture. (1) **Règle 5 du prompt
assouplie** par rapport au texte de l'issue (« aucune instruction exécutée depuis le corpus ou la
conversation ») : le visiteur peut orienter la *forme* d'une réponse (plus courte, en liste), jamais
les règles ; le texte strict faisait courir le risque qu'une demande ordinaire soit refusée. Le corpus
reste de la donnée, sans exception. (2) **`PlatformInterface` s'autowire sur `ai.platform.anthropic`**
(constat antérieur à la phase 2, vérifié par `debug:autowiring`) : un service qui l'injecterait par
type enverrait le CV nominatif chez Anthropic, contre D3. Le garde-fou (injection explicite de
`ai.agent.career_assistant`, test qui le vérifie) est ajouté aux critères de #261. (3)
`smalot/pdfparser` est installé en tâche 4 avec son test sur fixture, comme l'issue le permettait.
Nom de la variable confirmé : `SCALEWAY_AI_API_KEY` (celui de la recette, `SCALEWAY_API_KEY`, se
confondrait avec les clés IAM du mailer). Faits lus le même jour dans la documentation
Scaleway (FAQ Generative APIs, « Supported models ») : **Free Tier de 1 000 000 de jetons** en Serverless
(« up to 1,000,000 tokens »), déduit sur chaque facture (« Offer deducted - Generative APIs Free
Tier ») — la page ne dit pas « par mois » en toutes lettres, **à confirmer sur la première facture** ;
unité de facturation minimale 1 000 jetons ; aucun budget ne repose sur cette gratuité (pire cas D6
calculé sans). **Cache de prompt automatique** en Serverless, isolé par projet, taux annoncé de 50 à
90 % pour un usage conversationnel (non garanti), jetons en cache facturés à prix réduit : le préfixe
byte-identique de D8 en bénéficie sans configuration. **Modèle** : `mistral-small-3.2-24b-instruct-2506`
confirmé dans la console (0,15 € / 0,35 € par M de jetons, contexte 128k, température par défaut
0,15 — la spec n'en fixe aucune, D3), absent de la liste des modèles en fin de vie et cible de
redirection de trois modèles retirés ; sortie maximale 32k en Serverless, au-dessus des 1 024 de D6 ;
pas de modèle de repli nécessaire à ce stade. **Appel réel réussi** après un correctif : le premier
essai répondait `Error "unknown": "Unknown error"`. Un appel direct a montré un **403 FORBIDDEN** —
sans projet dans le chemin, `api.scaleway.ai/v1` vise le projet par défaut de l'organisation, où la
politique de l'application IAM ne donne aucun droit. Le projet entre donc dans le `baseUrl` de la
plateforme (`SCALEWAY_AI_PROJECT_ID`, même circuit que la clé ; à câbler en préprod/prod avec #264).
Second constat : le bridge 0.13.0 ne lit pas le format d'erreur de Scaleway
(`{"status","error","message"}`) et réduit tout échec à « unknown » — la tâche 2 doit journaliser le
statut HTTP d'un échec, faute de quoi le diagnostic redevient aveugle ; à signaler en amont avec D2.

**2026-09-26 (tâche 1, désignation)** — L'assistant parle du titulaire au masculin (« il ») et par son
**prénom tel qu'il figure dans les documents** (règle 2 du préambule). Le prénom n'est pas écrit dans le
prompt : le fichier est versionné dans un dépôt public, pseudonyme de bout en bout (objectif n°9). Il
n'arrive qu'avec le CV nominatif (tâche 4, `ROLE_TRUSTED`) ; d'ici là, « il » seul. Le texte extrait
du PDF doit donc conserver le prénom — à vérifier par le test de fixture de #263.
