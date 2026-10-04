# Page Assistant (spec 0005, tâche 6, #265) : plan d'implémentation

> **Pour les agents d'exécution :** sous-skill requise, `superpowers:subagent-driven-development`.
> Les étapes utilisent des cases à cocher (`- [ ]`). Cycle rouge/vert : `superpowers:test-driven-development`.
> Le test doit échouer **pour la bonne raison** avant toute implémentation.

**Objectif :** donner au palier nominatif (`ROLE_TRUSTED`) une page `/(fr|en)/assistant` qui pose une
question à `POST /api/assistant/answers` et affiche la réponse en flux.

**Architecture :** une tranche `assistant` en couches propres, comme les autres contenus servis par
l'API. Un domaine sans Vue (message, erreur, fenêtre glissante pure), un repository HTTP qui lit le
flux SSE avec `fetch` + `ReadableStream`, un composable à machine d'états (`idle|streaming|error`),
puis la page, la route et le lien d'en-tête. Le repository est injecté par `InjectionKey` depuis
`main.ts`.

**Stack :** Vue 3, TypeScript, Vue Router 4, vue-i18n, Bootstrap 5, Vitest + `@vue/test-utils` + axe-core.

**Spec :** `.claude/specs/0005-career-assistant.md`, §4 M6 (critères) et §5 « Frontend » (structure).
Ticket : #265. **Quand l'issue et la spec divergent, la spec fait foi** : l'issue dit « 12 derniers
messages », la spec amendée et le backend disent **11**.

Branche : `feature/spec-0005-t6-page-assistant`, tirée de `feature/spec-0005-career-assistant` ; PR
vers cette branche mère (`--base` explicite).

## Contraintes globales

- Commandes **dans le conteneur uniquement** : `make front-test`, `make front-lint`, `make front-build`.
  Jamais `npm` sur l'hôte. Un test seul : `docker compose exec frontend npx vitest run <chemin>`.
- Tests sous `tests/`, en miroir de `src/`, import relatif vers `../../../src/...` (pas d'alias).
- **Jamais de `v-html`.** Le texte du modèle passe par `presentation/ui/RichText.vue`.
- Chaînes d'interface sous `assistant.*`, `nav.assistant`, `seo.assistant.*`, en **fr et en**. Aucune
  `no-raw-text`.
- Bornes du backend (`Conversation`, `ConversationMessage`) recopiées **à l'identique** :
  11 messages au plus, alternance stricte, premier et dernier message de la personne, total ≤ 16 000
  caractères, question ≤ 1 000, réponse ≤ 4 000. **Caractères = points de code** (`mb_strlen` côté
  PHP), c'est-à-dire `[...texte].length` en JS, jamais `texte.length` (unités UTF-16).
- Format du flux (`AnswerController`) : `text/event-stream`, événements `delta` `{"text": "…"}`, puis un
  seul `done` `{promptTokens, completionTokens, durationMs}` **ou** `error` `{reason}`.
- Erreurs HTTP : problem+json avec `type` `/errors/<slug>` : 401/403 (pas de corps garanti), 413
  `/errors/request-too-large`, 422 `/errors/invalid-conversation`, 429 `/errors/rate-limited` (avec
  `Retry-After` en secondes quand c'est Symfony, **sans** quand c'est la zone nginx), 503
  `/errors/assistant-unavailable`.
- Requête : `POST {apiUrl}/api/assistant/answers`, `credentials: 'include'`, `Content-Type:
  application/json`, `Accept: text/event-stream`, `X-XSRF-TOKEN` lu par `readCsrfToken()`
  (`infrastructure/auth/csrfCookie.ts`). Corps : `{ "locale": "fr"|"en", "messages": [{ "role":
  "user"|"assistant", "content": "…" }] }`.
- Garde (décision du 2026-10-02) : la garde partagée **n'est pas modifiée**. Anonyme ou palier de base
  sans compte → `login` avec `redirect`. Compte sans `ROLE_TRUSTED` → `forbidden`.
- Commentaires en français, comme le code existant.

## Points d'attention de la revue

Entrées que la spec implique sans qu'un test de tâche ne les couvre spontanément. Chaque ligne a son
test dans la tâche indiquée.

1. **Un tour précédent sans réponse** (erreur avant le premier fragment) laisse deux questions qui se
   suivent. Envoyées telles quelles, l'alternance casse et le backend répond 422. La fenêtre écarte
   la question orpheline (tâche 2).
2. **Un fragment SSE coupé entre deux lectures du flux**, ou un `\r\n` au lieu de `\n`. Le parseur
   met en tampon et accepte les deux (tâche 1).
3. **Un flux qui se termine sans `done` ni `error`** (coupure réseau, #318) ne doit pas laisser le
   message « en cours » à vie. Il devient une erreur `network`, et le message partiel est marqué
   incomplet (tâches 1 et 3).
4. **Un super-admin** dont le compte ne porte pas `ROLE_TRUSTED` en clair. La route déclare
   `roles: [ROLE_TRUSTED, ROLE_SUPER]`, la garde passant si **un** rôle correspond (tâche 4).
5. **Un émoji ou un caractère hors BMP** à la frontière des 4 000 caractères. La troncature se fait
   en points de code, sans laisser de demi-paire (tâche 2). Le compteur de la saisie compte aussi en
   points de code (tâche 3), donc pas de `maxlength` HTML, qui compte en UTF-16.

---

### Tâche 1 : domaine et repository HTTP en flux

**Fichiers :**
- Créer : `src/domain/assistant/entities/AssistantMessage.ts`
- Créer : `src/domain/assistant/errors/AssistantError.ts`
- Créer : `src/domain/assistant/repositories/AssistantRepository.ts`
- Créer : `src/infrastructure/assistant/HttpAssistantRepository.ts`
- Créer : `src/infrastructure/assistant/serverEvents.ts` (parseur SSE incrémental, pur)
- Tests : `tests/infrastructure/assistant/serverEvents.spec.ts`, `tests/infrastructure/assistant/HttpAssistantRepository.spec.ts`

**Interfaces produites :**

```ts
// domain/assistant/entities/AssistantMessage.ts
export type AssistantRole = 'user' | 'assistant'
export type AssistantMessageStatus = 'complete' | 'streaming' | 'incomplete'
/** Ce que la page affiche. */
export interface AssistantMessage { role: AssistantRole; content: string; status: AssistantMessageStatus }
/** Ce qui part sur le fil, la forme attendue par AnswerRequest. */
export interface ConversationTurn { role: AssistantRole; content: string }

// domain/assistant/errors/AssistantError.ts
export type AssistantErrorReason =
  | 'unauthenticated' | 'forbidden' | 'validation' | 'too-large'
  | 'rate-limited' | 'unavailable' | 'network' | 'unknown'
export class AssistantError extends Error {
  constructor(readonly reason: AssistantErrorReason, readonly retryAfterSeconds: number | null = null)
}

// domain/assistant/repositories/AssistantRepository.ts
import type { Locale } from '../../portfolio/entities/Locale'
export interface AssistantRepository {
  /** Résout sur `done`. Rejette une AssistantError, ou l'AbortError du signal tel quel. */
  answer(locale: Locale, messages: readonly ConversationTurn[], onDelta: (text: string) => void, signal?: AbortSignal): Promise<void>
}

// infrastructure/assistant/serverEvents.ts
export interface ServerEvent { type: string; data: string }
/** Accumule des morceaux de texte et rend les événements complets (séparés par une ligne vide). */
export class ServerEventParser { push(chunk: string): ServerEvent[] }
```

Règles du parseur : normaliser `\r\n` et `\r` en `\n` ; un événement se termine par une ligne vide ;
`event:` donne le type (défaut `message`) ; plusieurs lignes `data:` se joignent par `\n` ; une espace
unique après `:` est retirée ; les lignes qui commencent par `:` (commentaires) et `id:`/`retry:` sont
ignorées ; un reste sans ligne vide finale reste en tampon.

Correspondance des statuts dans le repository : 401 → `unauthenticated`, 403 → `forbidden`, 413 →
`too-large`, 422 → `validation`, 429 → `rate-limited` avec `retryAfterSeconds` (entier ≥ 0 lu dans
`Retry-After`, sinon date HTTP convertie en secondes depuis maintenant, sinon `null`), 503 →
`unavailable`, tout autre statut non 2xx → `unknown`. `fetch` qui rejette un `TypeError` → `network`.
Un `AbortError` (signal) est relancé **tel quel**. Pendant le flux : `delta` → `onDelta(JSON.parse(data).text)` ;
`done` → résout ; `error` → rejette `unavailable` ; une lecture qui échoue ou un flux qui se termine
sans `done` ni `error` → rejette `network`. Un JSON illisible dans `data` → `unknown`.

- [ ] **Étape 1 : tests rouges du parseur** (`serverEvents.spec.ts`) : un événement complet ; deux
  événements dans un même morceau ; un événement coupé sur trois `push` (y compris au milieu de
  `data:`) ; `\r\n` ; `data` multiligne ; commentaire `: ping` ignoré ; reste sans ligne vide non émis.
- [ ] **Étape 2 :** lancer, constater l'échec (module absent).
- [ ] **Étape 3 :** écrire `ServerEventParser`, relancer, vert.
- [ ] **Étape 4 : tests rouges du repository** (`HttpAssistantRepository.spec.ts`, `fetch` remplacé
  par `vi.stubGlobal`, corps construit avec `new ReadableStream` et `TextEncoder`, découpé en morceaux
  arbitraires). Cas : requête (URL, méthode, `credentials`, en-têtes dont `X-XSRF-TOKEN` lu du cookie,
  corps JSON exact) ; trois `delta` puis `done` → `onDelta` appelé trois fois dans l'ordre, promesse
  résolue ; `error` → `AssistantError('unavailable')` après les deltas déjà livrés ; flux fermé sans
  événement final → `network` ; chaque statut de la table ci-dessus ; 429 avec `Retry-After: 120` →
  `retryAfterSeconds === 120` ; 429 sans en-tête → `null` ; `fetch` qui rejette `TypeError` →
  `network` ; signal abandonné → l'`AbortError` d'origine, pas une `AssistantError` ; un caractère
  multi-octets (`é`, un émoji) coupé entre deux morceaux d'octets → texte intact (`TextDecoder` en
  `stream: true`).
- [ ] **Étape 5 :** lancer, constater l'échec, implémenter, vert.
- [ ] **Étape 6 :** commit `feat(assistant): domaine et repository HTTP en flux (#265)`, fichiers nommés.

### Tâche 2 : fenêtre glissante

**Fichiers :**
- Créer : `src/domain/assistant/services/conversationWindow.ts`
- Test : `tests/domain/assistant/services/conversationWindow.spec.ts`

**Consomme :** `AssistantMessage`, `ConversationTurn` (tâche 1).

**Produit :**

```ts
export const MAX_WINDOW_MESSAGES = 11
export const MAX_CONVERSATION_LENGTH = 16000
export const MAX_QUESTION_LENGTH = 1000
export const MAX_ANSWER_LENGTH = 4000
export function codePointLength(text: string): number
export function truncateToCodePoints(text: string, max: number): string
/** Historique affiché + nouvelle question → ce qui part au backend, toujours valide pour Conversation. */
export function buildConversationWindow(history: readonly AssistantMessage[], question: string): ConversationTurn[]
```

Algorithme de `buildConversationWindow` : parcourir l'historique et ne retenir que les **échanges**,
c'est-à-dire un message `user` suivi immédiatement d'un message `assistant` dont le contenu, une fois
trimé, n'est pas vide (statut `complete` ou `incomplete`, jamais `streaming`). Une question sans
réponse exploitable est écartée. Chaque réponse est tronquée à 4 000 points de code. Garder les
(11 − 1) / 2 = 5 derniers échanges, ajouter la question (trimée), puis retirer l'échange **le plus
ancien** tant que le total en points de code dépasse 16 000. Le résultat commence toujours par `user`,
alterne, finit par la question, et son compte est impair.

- [ ] **Étape 1 : tests rouges.** Historique vide → `[question]`. Sept échanges → les cinq derniers +
  la question (11 éléments, le premier est `user`). Une question orpheline au milieu (suivie d'une
  autre question) → écartée, l'alternance tient. Une réponse vide ou en `streaming` → l'échange est
  écarté. Une réponse `incomplete` non vide → gardée. Une réponse de 4 500 caractères → 4 000.
  `truncateToCodePoints('a'.repeat(3999) + '😀😀', 4000)` → se termine par **un** émoji entier, aucune
  demi-paire (`/[\uD800-\uDBFF]$/` ne correspond pas). Total > 16 000 → les plus anciens tombent jusqu'à
  passer dessous, la question restant toujours. `codePointLength('😀') === 1`. Test de propriété
  simple : pour 200 historiques tirés au hasard (graine fixe), le résultat respecte toutes les
  bornes de `Conversation`.
- [ ] **Étape 2 :** lancer, constater l'échec.
- [ ] **Étape 3 :** implémenter, vert.
- [ ] **Étape 4 :** commit `feat(assistant): fenêtre glissante conforme aux bornes D6 (#265)`.

### Tâche 3 : `markSessionExpired` et composable `useAssistant`

**Fichiers :**
- Modifier : `src/application/auth/useAuth.ts`, nouvelle fonction exportée à côté de `markBaseAccessExpired`
- Créer : `src/application/assistant/useAssistant.ts`
- Tests : `tests/application/auth/useAuth.spec.ts` (étendre), `tests/application/assistant/useAssistant.spec.ts`

**Consomme :** tâches 1 et 2.

**Produit :**

```ts
// application/auth/useAuth.ts
/** Un appel du palier nominatif a reçu 401/403 : le jeton ne vaut plus ce palier. Repasse anonyme. */
export function markSessionExpired(): void   // applySession(ANONYMOUS_SESSION)

// application/assistant/useAssistant.ts
export const ASSISTANT_REPOSITORY: InjectionKey<AssistantRepository>
export type AssistantState = 'idle' | 'streaming' | 'error'
export interface UseAssistantResult {
  messages: Readonly<Ref<readonly AssistantMessage[]>>
  state: Readonly<Ref<AssistantState>>
  error: Readonly<Ref<AssistantError | null>>
  draft: Ref<string>
  draftLength: ComputedRef<number>        // points de code de draft
  canSend: ComputedRef<boolean>           // pas en streaming, draft trimé non vide, draftLength ≤ 1000
  send: (locale: Locale) => Promise<void>
  reset: () => void
}
export function useAssistant(): UseAssistantResult
```

Comportement de `send` : ne fait rien si `!canSend`. Calcule la fenêtre **avant** de modifier
l'historique, ajoute la question (`complete`) puis une réponse vide (`streaming`), vide `draft`, efface
`error`, passe en `streaming`, appelle le repository avec un `AbortController` neuf. Chaque delta est
ajouté au contenu de la réponse. Sur `done` : réponse `complete`, état `idle`. Sur `AssistantError` :
une réponse non vide passe `incomplete` et reste visible ; une réponse vide est retirée (la question
reste affichée) et, si `draft` est encore vide, la question y est remise pour un nouvel essai. Puis
`error` est posé et l'état passe à `error`. Sur `unauthenticated`/`forbidden`, appeler aussi
`markSessionExpired()`. Un `AbortError` est ignoré sans erreur. `reset` abandonne l'appel en cours,
vide `messages` et `error`, repasse `idle`, sans toucher `draft`. `onScopeDispose` abandonne l'appel
en cours.

- [ ] **Étape 1 : test rouge de `markSessionExpired`** : session nominative → après l'appel, `tier`
  vaut `anonymous` et `user` vaut `null`. Lancer, échec, implémenter, vert.
- [ ] **Étape 2 : tests rouges du composable** (repository simulé, composable monté dans un composant
  sonde avec `provide`). Flux nominal : la question puis la réponse sont dans `messages`, la réponse
  grandit à chaque delta, `state` passe `streaming` puis `idle`. Fenêtre envoyée = celle de
  `buildConversationWindow` (vérifier avec une orpheline dans l'historique). `canSend` faux si vide,
  si blanc, si 1 001 points de code, pendant `streaming` ; vrai à exactement 1 000 émojis. Erreur
  après deux deltas → réponse `incomplete` gardée, `error.reason === 'unavailable'`. Erreur avant tout
  delta → réponse retirée, question gardée, `draft` restauré. 401 → `markSessionExpired` effectif
  (le palier redevient anonyme). `reset` pendant un flux → `signal.aborted`, `messages` vide, aucune
  erreur posée. Démontage pendant un flux → `signal.aborted`. Deux `send` de suite pendant un flux →
  un seul appel au repository.
- [ ] **Étape 3 :** lancer, échec, implémenter, vert.
- [ ] **Étape 4 :** commit `feat(assistant): composable à machine d'états et fin de session nominative (#265)`.

### Tâche 4 : page, route, i18n et composition root

**Fichiers :**
- Créer : `src/presentation/pages/AssistantPage.vue`
- Modifier : `src/presentation/router/index.ts`, enfant de `/:locale` : `path: 'assistant'`,
  `name: 'assistant'`, chargement paresseux, `meta: { requiresAuth: true, roles: [ROLE_TRUSTED,
  ROLE_SUPER], noindex: true, titleKey: 'seo.assistant.title', descriptionKey: 'seo.assistant.description' }`
- Modifier : `src/main.ts`, `app.provide(ASSISTANT_REPOSITORY, new HttpAssistantRepository(apiUrl))`
- Modifier : `src/infrastructure/i18n/locales/fr.json` et `en.json` (`assistant.*`, `nav.assistant`, `seo.assistant.*`)
- Tests : `tests/presentation/pages/AssistantPage.spec.ts`, `tests/presentation/router/adminGuard.spec.ts` (étendre)

**Consomme :** `useAssistant`, `ASSISTANT_REPOSITORY` (tâche 3), `useCvDownload`, `RichText`, `BaseTextarea`.

Contenu de la page, de haut en bas :
- `<h1>` `assistant.title` et un paragraphe d'introduction.
- Bandeau **permanent** (ni `role="alert"` ni `role="status"`) : « L'assistant peut se tromper, les
  documents font foi », suivi des trois contenus : `RouterLink` vers `/{locale}/case-studies` et
  `/{locale}/anonymous-cv`, et un bouton qui télécharge le CV (`useCvDownload().downloadCv`).
- Transcript : un conteneur `role="log"` `aria-live="polite"` avec `aria-label`. Chaque message
  porte un intitulé (« Vous » / « Assistant ») et son texte rendu par `<RichText :text>`. Un message
  `incomplete` porte la mention `assistant.incomplete`. Message d'accueil quand c'est vide.
- **Un seul** `role="status"`, qui annonce `assistant.answering` pendant `streaming` et reste vide
  sinon.
- Erreur : `role="alert"` avec un message par raison. `rate-limited` avec `retryAfterSeconds` →
  `assistant.errors.rateLimitedIn` `{ minutes }` (arrondi supérieur, minimum 1), sinon
  `assistant.errors.rateLimited` (qui doit se lire sans durée). `too-large` → « conversation trop
  longue, commencez-en une nouvelle ». `unauthenticated`/`forbidden` → message + lien vers `login`
  avec `redirect=/{locale}/assistant`. `unavailable`, `network`, `unknown`, `validation` → messages
  dédiés (`validation` reste générique, il ne doit pas arriver).
- Formulaire `@submit.prevent` : `BaseTextarea` (`id="assistant-question"`, ses attributs vont au
  `<textarea>` grâce à `inheritAttrs: false`) avec `aria-describedby` vers le compteur
  `assistant.counter` `{ count, max }` (classe `text-danger` au-delà de 1 000). Entrée sans Maj et
  hors composition IME (`event.isComposing`) → `preventDefault` + envoi ; Maj+Entrée → saut de ligne.
  Bouton « Envoyer » `type="submit"` `:disabled="!canSend"` `:aria-busy="'streaming' === state"`.
  Bouton « Nouvelle conversation » `type="button"` → `reset`.

- [ ] **Étape 1 : tests rouges de la garde** (`adminGuard.spec.ts`) : anonyme → `login` avec
  `redirect=/fr/assistant` ; palier de base sans compte → `login` (décision du 2026-10-02) ; compte
  `ROLE_USER` seul → `forbidden` ; `ROLE_TRUSTED` → passe ; `ROLE_SUPER` sans `ROLE_TRUSTED` → passe ;
  `waitForAuthCheck()` respecté (même schéma que les tests admin existants).
- [ ] **Étape 2 : tests rouges de la page** (repository simulé injecté, `createAppI18n()`, routeur
  local) : envoi par le bouton et par Entrée ; Maj+Entrée n'envoie pas ; Entrée pendant une
  composition IME n'envoie pas ; texte rendu fragment par fragment ; `role="log"` présent ; **un
  seul** `role="status"` dans le DOM ; « Envoyer » désactivé si vide et pendant un flux, avec
  `aria-busy="true"` ; chaque raison d'erreur affiche son message dans `role="alert"`, et la
  conversation reste affichée ; 429 avec 90 s → « 2 minutes » ; 429 sans durée → message sans
  chiffre ; « Nouvelle conversation » vide le transcript ; une réponse contenant `<img src=x
  onerror=alert(1)>` est rendue en **texte** (aucun élément `img` dans le DOM) ; audit axe sans
  violation (`expectNoAccessibilityViolation`), conversation affichée.
- [ ] **Étape 3 :** lancer, échec, implémenter page + route + i18n + `main.ts`, vert. Puis
  `make front-lint` (aucune `no-raw-text`, clés fr/en symétriques).
- [ ] **Étape 4 :** commit `feat(assistant): page Assistant, route réservée au palier nominatif (#265)`.

### Tâche 5 : lien « Assistant » dans l'en-tête

**Fichiers :**
- Modifier : `src/presentation/layout/AppHeader.vue`, zone connectée (branche `v-else` de `tier`, où
  vit le bouton CV), sur le **même modèle que le bouton CV** : un `RouterLink` texte
  `d-none d-sm-inline-flex` et un `RouterLink` icône `d-sm-none` avec `aria-label`, icône
  `~icons/lucide/message-circle`, cible `${homeLink}/assistant`. Visible pour `'trusted' === tier`, donc
  aussi pour un super-admin, contrairement au bouton CV.
- Test : `tests/presentation/layout/AppHeader.spec.ts` (étendre)

- [ ] **Étape 1 : tests rouges** : anonyme → aucun lien vers `/fr/assistant` ; palier de base → aucun ;
  nominatif → lien texte et lien icône (avec `aria-label`) ; super-admin → présents ; après
  `markSessionExpired()` → disparus.
- [ ] **Étape 2 :** lancer, échec, implémenter, vert.
- [ ] **Étape 3 :** commit `feat(assistant): lien Assistant dans l'en-tête du palier nominatif (#265)`.

### Tâche 6 : vérification de bout en bout et documentation

**Fichiers :** `tasks/todo.md` (cocher la tâche 6), `.claude/CLAUDE.md` (une ligne : tranche
`assistant` dans « API-backed content » et état de la tâche 6 dans le paragraphe `Ai/`).

- [ ] **Étape 1 :** `make front-test`, `make front-lint`, `make front-build` : les trois sont verts,
  sortie relue.
- [ ] **Étape 2 : test en navigateur réel sur la stack dev.** Le propriétaire se connecte **lui-même**
  dans l'onglet avec un compte `ROLE_TRUSTED`, l'agent pilote ensuite. Poser une question dont la
  réponse se vérifie dans le CV sans identité, constater l'affichage progressif, faire une capture.
  Il faut que le `.env.local` du backend porte `SCALEWAY_AI_API_KEY` et `SCALEWAY_AI_PROJECT_ID`.
- [ ] **Étape 3 :** documentation et commit `docs(assistant): page Assistant livrée (#265)`.

## Hors de cette tâche

- Les suivis de revue backend #318 à #323. La tâche 1 traite déjà côté client un flux sans
  événement final.
- La garde partagée, qui n'est pas modifiée (décision du 2026-10-02).
