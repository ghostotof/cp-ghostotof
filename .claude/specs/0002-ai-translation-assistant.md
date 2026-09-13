# SPEC — Assistant de traduction FR/EN du backoffice (`Ai/Translation`, Symfony AI)

> Statut : **en relecture** (rédigée le 2026-09-14, design validé en session le même jour).
> Phase 1 de l'intégration de Symfony AI au projet ; la phase 2 (serveur MCP réservé à
> `ROLE_TRUSTED`) fera l'objet d'une spec distincte, mais ce document en prépare le socle (§2, D1 et D8).
>
> ⚠️ **Ce fichier vit dans un dépôt public** (`.claude/` est versionné, hors `CLAUDE.local.md`).
> Toute spec déposée ici est publiée au prochain push : aucun secret, aucune adresse réelle, aucun
> nom de domaine de production. Voir le journal d'audit en §10.

---

## 1. Objectif

Le site est bilingue (objectif n°12) et chaque contenu administrable existe en **deux lignes
distinctes**, une par `locale`, sans lien entre elles : une étude de cas, un post-mortem ou une
section du CV anonyme se saisit deux fois, en français puis en anglais. L'assistant supprime la
deuxième saisie à blanc : depuis une entrée chargée dans un formulaire du backoffice, un bouton
**« Proposer la version EN »** (ou FR) demande à un modèle de langage la traduction de tous les
champs de prose, puis pré-remplit un **nouveau** formulaire dans la locale cible. L'humain relit,
corrige et enregistre par le bouton habituel. **Rien n'est persisté sans ce clic.**

### Le vrai sujet de démonstration

Comme pour le radar de veille (spec 0001, ADR 0002), le thème est un prétexte assumé. Ce qui est mis
en scène, c'est **l'intégration d'un bundle expérimental et d'un fournisseur d'IA sans en devenir
l'otage** : bundle pinné à une version exacte et masqué derrière une interface applicative, appel
sortant confiné au backoffice (jamais dans un chemin de rendu public), coût borné par construction
(quota par compte, plafond de jetons, timeout), sortie structurée validée côté serveur, tests hors
ligne, et humain dans la boucle. Le socle posé ici (contexte `src/Ai/`, configuration de plateforme,
règles de l'ADR 0004) est celui sur lequel la phase 2 s'appuiera.

### Public visé

Le seul utilisateur est le compte `ROLE_SUPER` qui administre le contenu. Aucun visiteur, aucun
compte du palier de base ou nominatif n'y a accès, ni directement ni par effet de bord.

### Hors périmètre (v1)

- Toute exposition publique ou au palier `ROLE_USER`/`ROLE_TRUSTED` d'une fonctionnalité IA
  (chat, RAG, MCP) : phase 2, spec séparée.
- Traduction automatique à l'enregistrement, ou synchronisation FR↔EN après coup : l'assistant
  produit un brouillon, jamais une mise à jour silencieuse.
- Lien persistant entre une entrée FR et son pendant EN (`translationGroup`) : non nécessaire au
  brouillon, à reconsidérer si un besoin de « resynchroniser » apparaît.
- Autres fournisseurs (Ollama en cluster, Mistral…) : le bundle les permet par configuration, mais
  aucun n'est câblé ni testé en v1.
- La page admin des études de cas, qui n'existe pas encore côté frontend (routes admin actuelles :
  technologies, about, quality, contributions, incidents, anonymous-cv, watch, users) : la créer est
  une tâche à part ; l'assistant s'y branchera ensuite.
- L'ajout de `symfony lsp:check` à la CI (issue #90) : sans rapport, une PR par tâche.

## 2. Décisions structurantes

- **D1 — Un contexte borné `src/Ai/`**, avec un sous-contexte `Translation` (phase 1) et, plus tard,
  `Mcp` (phase 2). La configuration de plateforme (`config/packages/ai.yaml`, clé d'API) est
  déclarée une fois et partagée. Rien d'IA ne s'enfouit dans `Portfolio/*`.
- **D2 — Un endpoint générique, agnostique du contenu** : `POST /api/backoffice/translations` reçoit
  un dictionnaire `nom → texte` et le renvoie traduit. Le backend ignore ce qu'il traduit ; le
  frontend choisit les champs de prose de chaque page et recopie les autres (`version`,
  `occurredAt`, `position`…). Alternative écartée : un endpoint par ressource
  (`POST /api/backoffice/incidents/{id}/translation`), qui typerait mieux mais dupliquerait la logique
  six fois et ne pourrait pas traduire une saisie non encore enregistrée.
- **D3 — Fournisseur Anthropic, modèle `claude-sonnet-5`**, via le bridge du bundle. Vérifié dans le
  code de la v0.13 : le catalogue Anthropic connaît ce modèle avec la capacité `OUTPUT_STRUCTURED`.
  Deux conséquences de ce modèle, vérifiées dans la documentation de l'API : **aucun paramètre
  d'échantillonnage** (`temperature`, `top_p`, `top_k` sont refusés en 400) et la réflexion
  adaptative est active par défaut — on ne configure ni l'un ni l'autre.
- **D4 — Appel synchrone avec timeout**, depuis le processeur API Platform. Réservé `ROLE_SUPER`,
  hors de tout chemin de rendu public : la règle de l'ADR 0002 est respectée. Un traitement
  asynchrone (Messenger + polling) a été écarté : il ajouterait une entité de suivi et un endpoint
  de statut pour un usage occasionnel.
- **D5 — Sortie structurée, validée deux fois.** Le schéma JSON est construit à partir des noms de
  champs reçus (`additionalProperties: false`, tous requis) et imposé au modèle via
  `response_format` ; la réponse est ensuite vérifiée côté serveur (chaque clé présente, chaîne non
  vide). Toute déviation est une `TranslationUnavailableException` (503), jamais une réponse
  partielle.
- **D6 — Coût borné par construction** : quota `translation_assistant` en fenêtre glissante de
  **30 appels par heure et par compte** (clé : le `username`, l'appelant est authentifié),
  `max_output_tokens: 4096`, timeout client HTTP **40 s**. Les trois ensemble bornent la dépense
  même sur une boucle infinie côté UI.
- **D7 — Humain dans la boucle.** L'endpoint ne lit ni n'écrit en base ; le frontend bascule le
  formulaire en création avec le brouillon, et signale son origine par une bannière. Le bouton
  d'enregistrement habituel reste le seul chemin vers la persistance.
- **D8 — Une ADR 0004 « Assistance IA »** consigne ces règles et celles qui préparent la phase 2
  (voir §5, « Ce qui est touché en dehors du contexte »).
- **D9 — Bundle pinné en version exacte** (`symfony/ai-bundle`, `symfony/ai-anthropic-platform` et
  `symfony/ai-agent`, tous trois `0.13.0`, pas de `^`), le projet n'ayant aucune promesse de
  compatibilité ascendante. En 0.13 le bridge Anthropic et le composant Agent sont des paquets
  séparés : le bundle seul ne suffit pas. Les recettes Flex viennent du dépôt officiel
  `symfony/recipes` (pas de contrib), elles s'appliquent malgré `allow-contrib: false` ; le fichier
  `ai_anthropic_platform.yaml` qu'elles génèrent est fusionné dans `ai.yaml`.

### Contrats externes (vérifiés le 2026-09-14, pas de mémoire)

- **Symfony AI** — v0.13.0 (30 août 2026). `symfony/ai-bundle` exige `symfony/framework-bundle`
  `^7.3|^8.0`, PHP `>= 8.2` ; compatible avec le `8.1.*` du projet. Entrée idiomatique : un agent
  déclaré en YAML (`ai.agent.<nom>` : `platform`, `model`, `prompt.file`, `tools: false`,
  options de modèle), appelé via `AgentInterface::call(MessageBag, options)`. Sortie structurée par
  l'option `response_format` (schéma JSON), traitée par le `StructuredOutputProcessor` de l'agent.
  Tests : `Symfony\AI\Platform\Test\InMemoryPlatform` et `MockPlatformFactory` ; assertions
  fonctionnelles via `Symfony\AI\AiBundle\Test\AiAssertionsTrait` (`assertPlatformCallCount`,
  `assertModelCalled`). Jeu de jetons dans `$result->getMetadata()->get('token_usage')`.
- **API Anthropic** — `claude-sonnet-5` : 1M de contexte, tarif public 2 $ / 10 $ par million de
  jetons en entrée / sortie. Un post-mortem de cinq champs représente de l'ordre de 1 500 jetons en
  sortie, soit un coût unitaire de l'ordre du centime. Le plafond de 30 appels/heure borne la
  dépense horaire à quelques dizaines de centimes dans le pire cas.
- **Environnement d'exécution** — `max_execution_time = 30` en prod (`docker/php/php.prod.ini`) ;
  sous Linux cette limite ne compte pas l'attente réseau, mais nginx (`fastcgi_read_timeout`, 60 s
  par défaut) et l'ingress (60 s) coupent en temps mural. Le timeout client de 40 s reste sous ces
  deux plafonds ; la tâche de déploiement vérifie qu'aucune annotation d'ingress ne les abaisse.
- **Egress** — aucune NetworkPolicy d'egress n'existe dans `k8s/base/network-policies.yaml` ; rien à
  ouvrir. En revanche l'invariant de `CLAUDE.md` (« le CronJob `watch-refresh` est le seul objet qui
  fait des appels sortants vers des tiers ») devient faux : le Deployment `backend` en fait aussi,
  depuis le backoffice uniquement. `CLAUDE.md` est mis à jour en conséquence.

## 3. Carte des capacités (ordre de construction)

| # | Capacité | Dépend de | Livrable |
|---|---|---|---|
| M1 | Socle : bundle installé et pinné, `ai.yaml` (plateforme + agent `translator` + client HTTP dédié), prompt système, variable `ANTHROPIC_API_KEY` (`.env` vide, `phpunit.dist.xml` factice, `.env.local` dev), ADR 0004, mise à jour de `CLAUDE.md` | — | `composer phpstan`/`rector` verts, kernel bootable en test sans clé réelle |
| M2 | Traducteur : VO `TranslationRequest`/`TranslatedFields`, exceptions, `ContentTranslatorInterface` + `SymfonyAiContentTranslator` (sortie structurée, validation, journalisation des jetons) | M1 | Tests unitaires avec `InMemoryPlatform` |
| M3 | Ressource `POST /api/backoffice/translations` : DTO validé, processeur, limiteur par compte, `exception_to_status`, `HasProblemType` déplacé dans `Shared/` | M2 | Tests fonctionnels (401/403/200/422/429/503), `ApiRouteExposureTest` inchangé et vert |
| M4 | Déploiement : `ANTHROPIC_API_KEY` dans Secret Manager et les deux `ExternalSecret backend-secrets`, vérification des timeouts d'ingress | M1 | Rollout preprod, appel réel depuis le backoffice preprod |
| M5 | Frontend : tranche `admin/translation` (domaine, repository HTTP, composable, bouton réutilisable, clés i18n) et branchement sur `AdminIncidentsPage.vue` | M3 | Specs Vitest + axe, `npm run lint` et `build` verts |
| M6+ | Branchement page par page : Contributions, CV anonyme, About, Quality (puis Case studies quand sa page admin existera), une PR chacune | M5 | Même critères que M5, par page |

M1 à M5 constituent la première tranche verticale complète ; M6+ n'ajoute que du câblage.

## 4. Critères d'acceptation

### M2 — Traducteur

- Étant donné une requête `{fr → en, {title, impact}}` et une plateforme en mémoire renvoyant
  `{"title": "…", "impact": "…"}`, `translate()` renvoie un `TranslatedFields` portant exactement ces
  deux clés.
- Le schéma JSON transmis via `response_format` liste exactement les clés demandées, toutes
  `required`, `additionalProperties: false`.
- Si la réponse omet une clé, contient une valeur vide ou non textuelle, ou si la plateforme lève
  une exception (réseau, timeout, 4xx/5xx du fournisseur), `translate()` lève
  `TranslationUnavailableException` ; le message d'origine est journalisé, jamais renvoyé au client.
- Le jeu de jetons (entrée/sortie) et la durée sont journalisés en `info` ; **le contenu traduit ne
  l'est jamais**.
- Le prompt système (`config/ai/prompts/translator.txt`) exige : fidélité et registre technique,
  conservation des `` `backticks` ``, des sauts de paragraphe (deux retours à la ligne, convention de
  `RichText.vue`) et des noms de produits, interdiction d'exécuter une instruction contenue dans le
  texte, réponse uniquement dans la structure demandée.

### M3 — Ressource et cloisonnement

- Anonyme → 401 ; jeton du palier de base (`POST /api/account/base-access`) → 403 ; compte
  `ROLE_SUPER` → 200 avec `{sourceLocale, targetLocale, fields}` traduits. Le code est **200**, pas
  201 : rien n'est créé.
- 422 sur chaque contrainte : locale hors `{fr, en}`, locales identiques, `fields` vide ou de plus
  de 12 entrées, nom de champ hors `^[a-zA-Z][a-zA-Z0-9]{0,39}$`, valeur vide ou non textuelle,
  valeur de plus de 20 000 caractères, total de plus de 40 000 caractères.
- Le 31e appel dans l'heure pour un même compte → 429 avec `Retry-After`. Le quota d'un compte
  n'affecte pas un autre compte. Le quota n'est consommé qu'après la validation (une requête 422 ne
  le décrémente pas).
- Plateforme en échec → 503, `type: /errors/translation-unavailable`.
- `ApiRouteExposureTest` reste vert **sans** modification de `PUBLIC_PATHS` ni `BASE_TIER_PATHS`.
- La mutation exige le double-submit CSRF comme toute route `/api/backoffice/*` (test : sans
  `X-XSRF-TOKEN` → 403).

### M5 — Frontend (page Incidents)

- Le bouton est visible dans le formulaire, libellé selon la locale du formulaire (« Proposer la
  version EN » si `fr`, « Proposer la version FR » si `en`), désactivé si aucun champ de prose n'est
  rempli ou pendant un appel (`aria-busy`, spinner).
- Au succès : `editingId` passe à `null`, `form.locale` prend la locale cible, `title`, `impact`,
  `rootCause`, `resolution`, `invariant` sont remplacés, `version`, `occurredAt`, `position` sont
  conservés, une bannière `role="status"` indique l'origine IA du brouillon. L'en-tête du formulaire
  affiche « Créer ».
- Aucune requête `POST/PUT /api/backoffice/incidents` n'est émise par l'assistant lui-même.
- Erreurs : 429 → message « quota atteint, réessayez plus tard », 503 → « assistant indisponible »,
  autres → message générique ; toutes en `role="alert"`, le formulaire reste intact.
- Le passage axe (`expectNoAccessibilityViolation`) reste vert avec le bouton et la bannière rendus.
- `npm run lint` : les nouvelles chaînes passent par `admin.translation.*` (fr et en), aucune
  `no-raw-text`.

## 5. Structure du projet

### Backend — `backend/src/Ai/Translation/`

```
Domain/
  ValueObject/TranslationRequest.php        # sourceLocale, targetLocale (Locale), fields (array<string,string>)
  ValueObject/TranslatedFields.php
  Exception/TranslationUnavailableException.php   # 503, ProblemExceptionInterface + HasProblemType
  Exception/TranslationRateLimitExceededException.php  # 429, porte le Retry-After
Application/
  ContentTranslatorInterface.php
  TranslationRateLimiterInterface.php
Infrastructure/
  SymfonyAi/SymfonyAiContentTranslator.php  # seule classe qui importe le bundle
  RateLimiter/SymfonyTranslationRateLimiter.php   # limiter.translation_assistant, clé = username
  ApiPlatform/BackofficeTranslationProcessor.php  # validation → quota → traduction
  Http/TranslationRateLimitRetryAfterListener.php # Retry-After sur le 429 (calqué sur Contact)
Presentation/
  ApiResource/BackofficeTranslationResource.php   # Post /backoffice/translations, status 200
```

`Locale` est réutilisé depuis `Portfolio/Shared/Domain/ValueObject/` — déjà consommé hors de
`Portfolio/` (`Security/User`, `Shared/`), c'est la source unique des locales supportées. Le DTO
valide par `#[Assert\Choice(['fr','en'])]` et convertit par `Locale::from()` (valeur déjà bornée,
cf. la règle audit I3).

Configuration :

```
config/packages/ai.yaml        # platform.anthropic (api_key, http_client: ai.http_client), agent.translator
config/ai/prompts/translator.txt
config/packages/rate_limiter.yaml   # + translation_assistant (sliding_window, 30 / 1 hour)
config/packages/framework.yaml      # + http_client.scoped_clients.ai.http_client (timeout 40, max_redirects 0)
config/packages/api_platform.yaml   # + 2 entrées exception_to_status
config/bundles.php                  # + AiBundle (all)
```

### Frontend — `frontend/src/`

```
domain/admin/translation/entities/TranslationDraft.ts
domain/admin/translation/repositories/AdminTranslationRepository.ts   # translate(sourceLocale, targetLocale, fields)
domain/admin/translation/errors/AdminTranslationError.ts             # 'validation' | 'rate-limited' | 'unavailable' | 'unknown'
infrastructure/admin/translation/HttpAdminTranslationRepository.ts   # sur BackofficeHttpClient
application/admin/translation/useAdminTranslation.ts                 # translate(), isTranslating, errorReason
presentation/ui/admin/TranslateEntryButton.vue                        # bouton + spinner + aria-busy, émet 'translate'
presentation/pages/admin/AdminIncidentsPage.vue                      # branchement (liste des champs de prose)
infrastructure/i18n/locales/{fr,en}.json                             # admin.translation.*
main.ts                                                              # provide(ADMIN_TRANSLATION_REPOSITORY, …)
```

Tests miroir sous `tests/` (`tests/infrastructure/admin/translation/…`, `tests/application/admin/translation/…`,
`tests/presentation/ui/admin/TranslateEntryButton.spec.ts`, extension de
`tests/presentation/pages/admin/AdminIncidentsPage.spec.ts`).

### Ce qui est touché en dehors du contexte

- **`Security/User/Domain/Exception/HasProblemType` → `Shared/Domain/Exception/HasProblemType`.** Le
  trait est générique (un slug + un statut pour `ProblemExceptionInterface`) ; un troisième contexte
  en a besoin, il quitte `Security/User`. Les deux exceptions existantes changent de `use`, rien
  d'autre. Amélioration ciblée, faite dans M3, pas un refactor d'opportunité.
- **`docs/adr/0004-assistance-ia.md`**, sept décisions : bundle pinné et isolé derrière une
  interface ; aucun appel sortant dans un chemin de rendu public (extension de l'ADR 0002) ; seul le
  contenu **rédigé en backoffice et destiné à la publication** peut être envoyé à un fournisseur,
  jamais `cpg_user` (e-mails, hachés) ni le CV nominatif (le PDF de `app.cv_file_path`) ; une
  suggestion n'est jamais persistée sans action humaine ; plafonds de coût (quota par compte, jetons,
  timeout) ; tests hors ligne, aucun test ne sort sur le réseau ; **phase 2** réservée à
  `ROLE_TRUSTED`, sous `src/Ai/Mcp/`, dont les outils passent par les providers existants et jamais
  par les repositories, à préciser par amendement.
- **`CLAUDE.md`** : nouveau paragraphe « `Ai/` » sous « Backend architecture », mention du contexte
  dans « Project state », correction de l'invariant d'egress (§2), ajout de `ANTHROPIC_API_KEY` à la
  liste des variables servies hors dépôt.
- **`k8s/overlays/{preprod,prod}/external-secrets.yaml`** : une entrée `ANTHROPIC_API_KEY` dans
  l'`ExternalSecret backend-secrets`. **`k8s/README.md`** : la clé à créer dans Secret Manager.
  Aucun manifest de `k8s/base/` ne change.
- **`docker/php/init-symfony.sh`** : ligne `ANTHROPIC_API_KEY=` dans le `.env.local` généré pour un
  clone frais ; un checkout existant l'ajoute à la main (même remarque que pour `CONTACT_*`).

## 6. Commandes

```bash
# Backend (dans make sh)
composer require symfony/ai-bundle:0.13.0      # pin exact, pas de ^
php bin/console debug:router | grep translations   # une seule route, POST /api/backoffice/translations
php bin/console ai:agent:call translator       # essai interactif en dev (nécessite ANTHROPIC_API_KEY dans .env.local)
php bin/phpunit tests/Ai
composer phpstan && composer rector && composer psalm

# Frontend
make front-test && make front-lint && make front-build
```

## 7. Style de code

- Les conventions du projet s'appliquent sans exception : `declare(strict_types=1)`, `readonly`,
  Value Objects, exceptions métier explicites, interfaces pour les services consommés par un
  processeur, PHPStan `max` sans baseline, Rector vert.
- `SymfonyAiContentTranslator` est **la seule classe** à importer `Symfony\AI\*`. Le domaine et
  l'application ne connaissent ni `MessageBag`, ni `AgentInterface`, ni `Platform`. C'est la couche
  anti-corruption qui rend un changement de version, ou de fournisseur, local.
- Si PHPStan bute sur des types du bundle (0.x, `mixed` probable sur `asObject()`), l'`ignoreErrors`
  est scopé à `src/Ai/Translation/Infrastructure/SymfonyAi/` et justifié en commentaire, comme les
  deux entrées existantes pour `tests/`. Aucune annotation Psalm (règle du projet : Psalm ne fait
  que du taint).
- Le prompt vit dans un fichier texte, pas dans une chaîne PHP : il se relit et se diffe.
- Frontend : `Base*` pour les champs, `TranslateEntryButton` pour le bouton ; jamais de `v-html` sur
  un texte revenu du modèle (il repasse par les champs de formulaire, puis par `RichText.vue` à
  l'affichage).

## 8. Stratégie de test

- **Unitaires (`tests/Ai/Translation/`)** : `SymfonyAiContentTranslatorTest` avec
  `InMemoryPlatform`/`MockPlatformFactory` — nominal, schéma transmis, clé manquante, valeur vide,
  exception du fournisseur, journalisation des jetons (logger de test). `SymfonyTranslationRateLimiterTest`
  calqué sur celui de Contact. Tests des VO (locales identiques refusées, dictionnaire vide refusé).
- **Fonctionnels (`tests/Ai/Translation/Presentation/ApiResource/BackofficeTranslationResourceTest.php`)** :
  la plateforme est remplacée dans le conteneur de test (`$client->getContainer()->set(...)`, comme
  `http_client` dans `WatchResourceTest`) par une plateforme en mémoire, succès ou échec selon le
  cas ; à défaut, le client `ai.http_client` remplacé par un `MockHttpClient` renvoyant une réponse
  au format Anthropic. Cas : 401, 403 palier de base, 403 sans CSRF, 200, chaque 422, 429 au 31e
  appel, 503. `AiAssertionsTrait::assertModelCalled('claude-sonnet-5')` sur le cas nominal.
- **Aucun test ne sort sur le réseau.** `phpunit.dist.xml` porte une clé factice ; un test qui
  atteindrait l'API réelle échouerait en 401, ce qui est le filet de sécurité voulu.
- **Frontend** : repository (mapping statut → raison), composable (états), bouton (libellé par
  locale, désactivé, `aria-busy`), page Incidents (bascule en création, champs conservés, bannière,
  erreurs, aucune écriture), axe.
- **Régression de cloisonnement** : `ApiRouteExposureTest` inchangé doit rester vert — c'est lui qui
  garantit que la route n'est ni publique ni ouverte au palier de base.

## 9. Limites

### Toujours

- Vérifier l'exposition avec `debug:router` après avoir déclaré la ressource (une seule route, pas de
  route d'item synthétisée : le DTO n'a pas d'identifiant, `#[ApiProperty(identifier: false)]` si
  API Platform en invente un).
- Journaliser jetons et durée, jamais le contenu.
- Passer par `Locale::from()` sur des valeurs déjà bornées par `Assert\Choice`, `Locale::fromString()`
  n'a pas sa place ici (rien ne vient d'une URL).

### Demander avant

- Brancher l'assistant sur un contenu qui ne serait pas destiné à la publication ou porterait une
  donnée nominative (amendement de l'ADR 0004 requis).
- Élever le quota, le plafond de jetons ou le timeout.
- Ajouter un second fournisseur ou un second agent.
- Introduire un `ignoreErrors` PHPStan hors du périmètre défini en §7.

### Jamais

- Un appel à la plateforme depuis un provider public, un CronJob de rendu ou un handler Messenger
  déclenché par un visiteur.
- Persister une traduction sans action explicite de l'humain.
- Assouplir `ApiRouteExposureTest` pour faire passer la route.
- Envoyer `cpg_user`, un token, ou le PDF du CV nominatif à un fournisseur.
- Un `^` sur la version du bundle tant qu'il est en 0.x.

## 10. Journal des validations

**2026-09-14** — Design validé en session (approche A : endpoint générique ; fournisseur Anthropic
`claude-sonnet-5` ; appel synchrone ; traduction de l'entrée entière ; première page Incidents, puis
chaque page par étape). Exigence ajoutée par Christophe : préparer la phase 2, un serveur MCP réservé
à `ROLE_TRUSTED` — prise en compte par D1 (contexte `src/Ai/` commun) et D8 (ADR 0004, décision de
phase 2).

Corrections apportées à la rédaction : `claude-sonnet-5` refuse `temperature`, le réglage prévu à
l'oral est retiré ; `HasProblemType` quitte `Security/User` pour `Shared/` plutôt que d'être
dupliqué.

**2026-09-14** — Découpage validé (`tasks/plan.md`, issues #91–#103), page admin des études de cas
ouverte en #104. Corrections à l'installation (Task 1) : les recettes Flex **s'appliquent** (dépôt
officiel `symfony/recipes`, pas contrib — la spec disait l'inverse) ; le bridge Anthropic et le
composant Agent sont des paquets séparés, ajoutés et pinnés ; l'option de jetons s'appelle
`max_tokens` (nom Anthropic, fusionné tel quel dans la requête par le bridge, défaut 1000), pas
`max_output_tokens`. D9 et §5 mis à jour.

**Audit de sensibilité avant publication** : aucun e-mail, aucune adresse IP, aucun chemin
utilisateur, aucun nom de domaine, aucun secret ni credential (la clé d'API n'apparaît que comme nom
de variable, `ANTHROPIC_API_KEY`, jamais comme valeur). Points relevés, non bloquants et assumés : le
document décrit le cloisonnement `ROLE_SUPER`, les plafonds de coût et le risque d'injection de
prompt accepté, au même niveau de détail que `.claude/CLAUDE.md` et les ADR, déjà publics ; il
mentionne que le CV nominatif est servi depuis un fichier PDF, information déjà présente dans
`services.yaml`.

Prochaine étape : découpage en tâches sur la branche `feature/ai-translation-assistant`, une PR par
tâche empilée (M1 → M5), M6+ ensuite page par page.
