# #748 — Accessibilité de « Mes connexions MCP », du menu du compte et du bandeau de session finie : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** corriger les 21 constats de #748 (les sept majeurs, et chaque mineur ou cosmétique, ou le laisser avec une
raison écrite), avec le point 4 de #732 (un 403 lu à tort comme une session finie), sans perdre la saisie d'une page
d'administration.

**Architecture :** une règle unique, vérifiée dans le code, décide de ce qu'est une « session finie » côté frontend :
un 401, ou un 403 à une écriture **envoyée sans jeton CSRF** (`infrastructure/http/sessionEnded.ts`). Le
`BackofficeHttpClient` l'applique et le retient par réponse ; chaque dépôt d'administration la traduit en une raison
`session-ended` commune, que composables et pages taisent (le bandeau, devenu collant sous l'en-tête, est alors la
seule annonce). Le reste est local à chaque composant : page MCP (focus, annonces, tableau), menu du compte, bandeau,
en-tête, contrastes.

**Tech Stack :** Vue 3.5 + TypeScript + Vite, Vitest + `@vue/test-utils` + jsdom, axe-core (`tests/support/axe.ts`),
Bootstrap 5.3.8 (CSS seul, thème sombre `data-bs-theme="dark"`), vue-i18n, `unplugin-icons` (lucide).

**Spec :** ticket #748 (21 constats, critères d'acceptation) et le point 4 de #732 ; arbitrages de l'auteur du
2026-10-29 (constat 15 traité ici avec #732-4 ; constat 7 par les deux pistes ; tout le reste corrigé ou laissé avec
une raison). Règles : `.claude/rules/frontend.md`, `frontend-a11y.md`, `frontend-backoffice.md`,
`mcp-oauth-revocation-personne.md`, `security-authentication.md`, `security-authentication-sessions.md`.

## Décisions de l'auteur (2026-10-29), sur les questions du premier plan

1. **Constat 6, l'en-tête à 320 px : option (a)**, la marque tronquée (`text-truncate` sur son libellé, `min-width: 0`
   sur le lien, les actions `flex-shrink: 0`). Tâche 10.
2. **Le 403 est corrigé partout dans #748**, contre la recommandation du premier plan : la règle `endsTheSession`
   s'étend à la page de consentement OAuth (`HttpOAuthConsentRepository`, tâche 4b) et à l'assistant
   (`HttpAssistantRepository`/`useAssistant`, tâche 4c). Le point 4 de #732 est entièrement traité ici.
3. **Constat 2 : `text-danger-emphasis` sur les 67 occurrences**, validé. Tâche 1.

## Ce qui a été vérifié dans le code (base du constat 15)

- `CsrfCookieRequestSubscriber` (backend) écoute `kernel.request` en **priorité 20**, avant le pare-feu (8), et ne
  contrôle que `POST`, `PUT`, `PATCH`, `DELETE` sous `/api` : **une lecture n'est jamais refusée pour le CSRF**, et une
  écriture sans en-tête `X-XSRF-TOKEN` (ou sans cookie) reçoit un 403 avant toute authentification.
- `XSRF-TOKEN` n'est pas `HttpOnly` (`AuthCookieFactory` : `HttpOnly` sur `BEARER` seul) : le frontend le lit déjà
  (`infrastructure/auth/csrfCookie.ts`, `readCsrfToken()`), et n'envoie l'en-tête que s'il le trouve.
- Au login, `XSRF-TOKEN` est un **cookie de session** (`LoginSuccessSubscriber`, sans échéance) alors que `BEARER` vit
  3 600 s (`lexik_jwt_authentication.yaml`) : à l'expiration du JWT, le navigateur jette `BEARER` et garde
  `XSRF-TOKEN`, donc une écriture passe le CSRF et prend le **401** du pare-feu (le « cas principal » du ticket,
  déduit et maintenant confirmé par le code).
- `/api/logout` (`CookieLogoutListener`) et le refus d'une session antérieure au changement de mot de passe
  (`StaleSessionCookiesClearingListener`) **expirent les deux cookies** : après eux, une lecture prend un 401, une
  écriture part sans jeton et prend un **403 CSRF** (le constat 15).
- Conclusion : **un 403 à une écriture envoyée sans jeton CSRF** est une session finie (aucune paire de cookies) ; un
  403 à une écriture qui portait son jeton, ou à une lecture, est un refus réel (autorisation, signature CSRF
  invalide) et le reste. Le signal est « le client n'avait pas de jeton à envoyer », connu au moment de l'envoi — pas
  une relecture du cookie à la réception.

## Global Constraints

- **Lire avec l'outil `Read`** au moins un fichier de la zone avant d'y écrire (charge la règle path-scoped) ; jamais
  `cat`/`sed`/`grep` à la place. Lire la règle elle-même avant de la modifier.
- Tests **dans le conteneur uniquement** : ciblé `docker compose exec frontend npm test -- <chemin du spec>`, puis avant
  chaque commit `make front-test`, `make front-lint`, `make front-build`, tous verts. Jamais `npm` sur l'hôte.
- Après un `sed`/`perl -pi` ou un changement de dépendance sur l'hôte : `docker compose restart frontend` avant de
  croire un test (montage désynchronisé).
- TDD (`superpowers:test-driven-development`) : le test est écrit d'abord et **vu rouge pour la bonne raison** (l'échec
  dit le défaut, pas une faute de frappe) avant la correction. Un test existant n'est modifié que là où la tâche le
  dit, parce que le comportement change exprès.
- **Un commit par tâche**, fichiers nommés (`git add <fichiers>`, jamais `git add -A` ni `.`), message en français
  (`fix(frontend): … (#748)`), terminé par les lignes d'attribution que donne le fil principal.
- Toute chaîne nouvelle existe en **FR et EN** (`src/infrastructure/i18n/locales/{fr,en}.json`) ; un libellé lu par
  `t()` avec une clé construite passe par une table explicite ou un type fermé, jamais une clé brute affichée.
- Commentaires en français, qui disent le pourquoi (style du dépôt). Ne jamais lire `.env.local`, `.env.test.local`
  ni un fichier de secrets ; ne jamais nommer un compte réel. Pas de push, pas de PR.
- Contraste : jsdom ne le calcule pas (`tests/support/axe.ts` désactive `color-contrast`) ; il est épinglé par des
  gardes de source (classes, `style.css`) et mesuré au navigateur à la tâche 11.

## Review Focus

1. **L'en-tête change de hauteur pendant que le bandeau est collé** (menu mobile ouvert, alerte de l'en-tête) : le
   bandeau doit rester juste sous l'en-tête, jamais dessous. Test : tâche 8, `usePublishedHeight.spec.ts`, « un
   second relevé met la propriété à jour ».
2. **La page démontée pendant le délai d'annonce** (navigation dans les 250 ms qui suivent une révocation) : aucune
   écriture d'état après le démontage, minuterie annulée. Test : tâche 5, « le démontage annule l'annonce en
   attente ».
3. **Un cookie `XSRF-TOKEN` présent mais vide** (`XSRF-TOKEN=`) : le client n'envoie pas d'en-tête vide, le serveur
   refuse en 403 ; c'est une session finie, pas un refus. Test : tâche 3, `BackofficeHttpClient.spec.ts`, « cookie
   vide ».
4. **Un rechargement en échec « session finie » sur une liste déjà affichée** : la liste reste, aucune alerte de
   chargement. Test : tâche 2, `sessionEndedLoad.spec.ts`, « garde la liste déjà lue ».
5. **Échap pendant qu'un autre menu a le focus** (menu « Contenu » d'`AdminLayout`, groupe de l'en-tête) alors que le
   menu du compte est resté ouvert : le menu du compte se ferme sans voler le focus. Test : tâche 7, « Échap ailleurs
   ferme sans prendre le focus ».

## Fichiers

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `src/style.css` | surcharge `.btn-outline-danger`, classe `.link-muted`, `scroll-padding-top` | 1, 8 |
| `src/**/*.vue` (29 fichiers) | `text-danger` → `text-danger-emphasis` | 1 |
| `src/presentation/ui/LocaleSwitcher.vue`, `src/presentation/layout/AppFooter.vue` | contrastes secondaires | 1 |
| `src/domain/admin/shared/errors/sessionEnded.ts` (nouveau) | la raison `session-ended`, `isSessionEndedError()` | 2 |
| `src/domain/admin/*/errors/*.ts` (11 fichiers) | unions de raisons élargies | 2 |
| `src/presentation/ui/admin/visibleErrorReason.ts` (nouveau) | taire la raison `session-ended` à l'affichage | 2 |
| `src/application/admin/**/use*.ts` (13 composables + `useOrderDraft`, `useAdminTranslation`) | ne pas lever `hasError` sur une session finie | 2 |
| pages et `ui/admin` du backoffice (11 pages/sections + `OrderToolbar.vue`) | messages filtrés | 2 |
| `src/infrastructure/http/sessionEnded.ts` (nouveau) | `endsTheSession()` | 3 |
| `src/infrastructure/admin/shared/BackofficeHttpClient.ts` | réaction au 403 sans jeton, `endedTheSession()` | 3 |
| `src/infrastructure/admin/**/Http*Repository.ts` (15 dépôts) | raison `session-ended` | 3 |
| `src/infrastructure/mcp/HttpMcpConnectionsRepository.ts` | même règle, `SessionExpiredError` | 4 |
| `src/infrastructure/mcp/HttpOAuthConsentRepository.ts`, `src/domain/mcp/errors/ConsentForbiddenError.ts` (nouveau), `src/application/mcp/useOAuthConsent.ts`, `src/presentation/pages/OAuthConsentPage.vue` | même règle ; un refus réel est l'échec `forbidden` | 4b |
| `src/infrastructure/assistant/HttpAssistantRepository.ts`, `src/application/assistant/useAssistant.ts`, `src/presentation/pages/AssistantPage.vue` | même règle ; `forbidden` ne finit plus la session | 4c |
| `src/presentation/pages/McpConnectionsPage.vue` | boutons, focus, annonces, tableau | 4, 5, 6 |
| `src/presentation/layout/AccountMenu.vue` | Échap, sortie du focus, entrée courante | 7 |
| `src/presentation/layout/usePublishedHeight.ts` (nouveau) | publier une hauteur en propriété CSS | 8 |
| `src/presentation/ui/admin/SessionEndedBanner.vue` | collant, session rétablie, icône | 8 |
| `src/presentation/layout/AppHeader.vue` | hauteur publiée, connexion en session finie, 320 px | 8, 9, 10 |

---

### Task 1 : Contrastes du rouge et du gris (constats 1, 2, 17)

**Files :**
- Modify : `frontend/src/style.css`, `frontend/src/presentation/ui/LocaleSwitcher.vue:33`, `frontend/src/presentation/layout/AppFooter.vue` (4 liens `link-secondary`, version comprise), tous les `frontend/src/**/*.{vue,ts}` qui portent `text-danger` (67 occurrences, 29 fichiers ; jamais `text-danger-emphasis` ni `text-bg-danger`)
- Modify : `frontend/tests/presentation/pages/AssistantPage.spec.ts:230,235` (le compteur attend `text-danger-emphasis`)
- Create : `frontend/tests/presentation/styleSheet.spec.ts`, `frontend/tests/presentation/colorClasses.spec.ts`
- Modify : `.claude/rules/frontend-a11y.md` (puce « Text contrast »)

**Choix :** le rouge des messages passe à `text-danger-emphasis` (`#ea868f`, ~7,8:1 sur `#0b0a14`), précédent de
`ContactPage.vue`. **Écarté : redéfinir `.text-danger` dans `style.css`** — une classe utilitaire qui ne fait plus ce
que dit son nom ; **écarté : changer `--bs-danger-rgb`** — il colore aussi `text-bg-danger`, dont le texte blanc
tomberait sous 4,5:1. Le bouton « Révoquer » et les « Supprimer » gardent `btn-outline-danger`, dont `style.css`
surcharge texte et bordure à `var(--bs-danger-text-emphasis)` (~6,1:1 sur `#212529`) ; le survol reste le rouge plein
de Bootstrap, blanc sur `#dc3545` (4,53:1). **Écarté : `btn-danger` plein** — 4,53:1 seulement, et un pavé rouge par
ligne. Le gris : `text-secondary` et `link-secondary` utilisent `--bs-secondary-rgb` (`#6c757d`, 4,19:1) ; le
sélecteur passe à `text-body-secondary` (`#94a3b8`, ~7,7:1), le pied de page à une classe `.link-muted` (même
couleur, soulignement au survol). **Écarté : redéfinir `--bs-secondary-rgb`** — il est aussi le fond des badges
`text-bg-secondary` (texte blanc, ~2,6:1).

- [ ] **Step 1 : écrire les gardes, rouges**

`tests/presentation/colorClasses.spec.ts` lit les sources par `import.meta.glob('../../src/**/*.{vue,ts}', { query: '?raw', import: 'default', eager: true })` (typé par `vite/client`, sans `node:fs`) :
- `it('aucun texte en text-danger : #dc3545 fait 4,34:1 sur le fond, text-danger-emphasis le remplace')` — aucun fichier ne correspond à `/\btext-danger\b(?!-)/` ; le message d'échec liste les fichiers fautifs.
- `it('ni text-secondary ni link-secondary : #6c757d fait 4,19:1 sur le fond')` — aucun ne correspond à `/\b(?:text|link)-secondary\b/`.

`tests/presentation/styleSheet.spec.ts` importe `../../src/style.css?raw` :
- `it('btn-outline-danger : texte et bordure en danger-text-emphasis')` — le bloc `.btn-outline-danger { … }` contient `--bs-btn-color: var(--bs-danger-text-emphasis)` et `--bs-btn-border-color: var(--bs-danger-text-emphasis)`.
- `it('.link-muted prend la couleur secondaire du thème')` — un bloc `.link-muted` contient `var(--bs-secondary-color)`.

- [ ] **Step 2 : les voir rouges**

Run : `docker compose exec frontend npm test -- tests/presentation/colorClasses.spec.ts tests/presentation/styleSheet.spec.ts`
Expected : FAIL, les 29 fichiers `text-danger` listés, `LocaleSwitcher.vue` et `AppFooter.vue` listés, blocs CSS absents.

- [ ] **Step 3 : corriger**

Remplacement mécanique : `perl -pi -e 's/\btext-danger\b(?!-)/text-danger-emphasis/g'` sur les fichiers listés,
puis `docker compose restart frontend`. `style.css` : bloc `.btn-outline-danger` (les quatre propriétés
`--bs-btn-color`, `--bs-btn-border-color`, `--bs-btn-disabled-color`, `--bs-btn-disabled-border-color` à
`var(--bs-danger-text-emphasis)`) et bloc `.link-muted` (`color: var(--bs-secondary-color) !important`,
`text-decoration-color` assorti avec `--bs-link-underline-opacity`, `:hover`/`:focus-visible` en
`var(--bs-emphasis-color)`), commentés comme `.text-eyebrow`. `LocaleSwitcher.vue` : `text-body-secondary`.
`AppFooter.vue` : `link-secondary` → `link-muted` (garder les utilitaires `link-underline-opacity-*`).
`AssistantPage.spec.ts` : les deux assertions du compteur suivent la nouvelle classe.

- [ ] **Step 4 : règle**

`frontend-a11y.md`, puce « Text contrast » : le rouge de texte est `text-danger-emphasis`, jamais `text-danger`
(4,34:1) ; `btn-outline-danger` est surchargé dans `style.css` ; le gris est `text-body-secondary` ou `.link-muted`,
jamais `text-secondary`/`link-secondary` (4,19:1) ; les gardes `colorClasses.spec.ts` et `styleSheet.spec.ts`, et
pourquoi ni `--bs-danger-rgb` ni `--bs-secondary-rgb` ne sont redéfinis.

- [ ] **Step 5 : vérifier** — les deux specs ciblés PASS, puis `make front-test`, `make front-lint`, `make front-build` verts.

- [ ] **Step 6 : commit** — `git add` des fichiers modifiés et créés, nommés un à un ; `fix(frontend): contrastes AA du rouge et du gris (#748)`.

---

### Task 2 : La raison « session finie », tue par les composables et les pages (constat 7, première piste — socle)

**Files :**
- Create : `frontend/src/domain/admin/shared/errors/sessionEnded.ts`, `frontend/src/presentation/ui/admin/visibleErrorReason.ts`
- Modify : les 11 unions de raisons (`AdminAboutError`, `AdminAnonymousCvSectionError`, `AdminCaseStudyError`, `AdminContributionError`, `AdminIncidentError`, `AdminQualityError`, `AdminExperienceTechnologyError`, `AdminTranslationError`, `AdminUserError`, `AdminWatchedProductError`, `AdminOrderError`)
- Modify : les `load()` de `useAdminAboutMeCards`, `useAdminAboutSettings`, `useAdminAboutSiteCards`, `useAdminAnonymousCvSections`, `useAdminCaseStudies`, `useAdminContributions`, `useAdminIncidents`, `useAdminQualityPrinciples`, `useAdminQualityTraits`, `useAdminExperienceTechnologies`, `useAdminUsers`, `useAdminVulnerabilities`, `useAdminWatchedProducts`
- Modify : chaque `errorText`/`translationErrorText`/message par raison de `AdminCaseStudiesPage`, `AdminAnonymousCvPage`, `AdminContributionsPage`, `AdminIncidentsPage`, `AdminQualityPage` (×2), `AdminTechnologiesPage`, `AdminUsersPage`, `AdminWatchPage`, `AdminAboutSettingsForm`, `AdminAboutSiteCardsSection`, `AdminAboutMeCardsSection`, et `OrderToolbar.vue` (`errorReason`, y compris `cancelDisabled`)
- Test : `frontend/tests/domain/admin/shared/errors/sessionEnded.spec.ts`, `frontend/tests/presentation/ui/admin/visibleErrorReason.spec.ts`, `frontend/tests/application/admin/sessionEndedLoad.spec.ts`, `frontend/tests/presentation/pages/admin/errorReasonsHideSessionEnded.spec.ts`, un cas ajouté à `frontend/tests/presentation/pages/admin/AdminCaseStudiesPage.spec.ts`

**Interfaces :**
- Produces :
  - `SESSION_ENDED = 'session-ended'` (`as const`), `type SessionEndedReason = typeof SESSION_ENDED`, `isSessionEndedError(error: unknown): boolean` (vrai pour tout objet dont `reason === SESSION_ENDED`, quelle que soit sa classe) — `src/domain/admin/shared/errors/sessionEnded.ts` ;
  - chaque `Admin*ErrorReason` et `AdminOrderErrorReason` inclut `SessionEndedReason` ;
  - `visibleErrorReason<R extends string>(reason: R | null | undefined): Exclude<R, SessionEndedReason> | null` — `src/presentation/ui/admin/visibleErrorReason.ts`.

**Choix :** une raison commune ajoutée à chaque union plutôt qu'une classe d'erreur partagée : chaque composable
convertit déjà tout ce qui n'est pas *son* erreur en `unknown` (`error instanceof AdminXError ? error : …`), une classe
commune y redeviendrait « Une erreur est survenue ». Les mutations n'ont donc rien à changer (l'erreur du dépôt est
gardée telle quelle) ; seul `load()` doit ne pas lever `hasError`, et seules les pages doivent ne pas afficher la
raison. Cette tâche ne fait encore produire la raison par aucun dépôt (tâche 3) : chaque commit reste cohérent.

- [ ] **Step 1 : tests, rouges**

- `sessionEnded.spec.ts` : `isSessionEndedError(new AdminCaseStudyError('session-ended', 'x'))` et `(new AdminOrderError('session-ended', 'x'))` vrais ; faux pour `new AdminCaseStudyError('unknown', 'x')`, `new Error('x')`, `null`, `'session-ended'`.
- `visibleErrorReason.spec.ts` : `'session-ended'` → `null` ; `'unknown'` → `'unknown'` ; `null`/`undefined` → `null`.
- `sessionEndedLoad.spec.ts`, table `it.each` sur les 13 composables (nom, clé d'injection, fabrique) : chacun monté dans un composant hôte, le dépôt fourni est un `Proxy` dont toute méthode rejette l'erreur donnée.
  - `it('%s : un chargement refusé pour session finie ne lève pas hasError')` — rejet `{ reason: 'session-ended' }` de la classe du dépôt → `hasError === false`.
  - `it('%s : tout autre échec lève hasError')` — rejet `reason: 'unknown'` → `true` (témoin).
  - `it('%s : garde la liste déjà lue')` — premier `list()` résolu `[x]`, second rejeté session finie après `load()` → liste toujours `[x]`, `hasError === false` (Review Focus 4 ; pour `useAdminAboutSettings`, la valeur lue reste).
- `errorReasonsHideSessionEnded.spec.ts` : glob brut de `src/presentation/pages/admin/*.vue` et `src/presentation/ui/admin/*.vue` ; tout fichier qui contient `` errors.${ `` contient aussi `visibleErrorReason(`. Message d'échec : la liste des fichiers.
- `AdminCaseStudiesPage.spec.ts` : `it("une erreur « session finie » à l'enregistrement n'affiche aucune alerte de la page")` — dépôt dont `create` rejette `new AdminCaseStudyError('session-ended', 'x')`, soumission → aucun `[role="alert"]` dans la page, et aucun texte `admin.caseStudies.errors.session-ended` (clé brute).

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/domain/admin/shared tests/presentation/ui/admin/visibleErrorReason.spec.ts tests/application/admin/sessionEndedLoad.spec.ts tests/presentation/pages/admin`. Expected : modules introuvables, `hasError` vrai, 12 fichiers listés, une alerte avec la clé brute.

- [ ] **Step 3 : implémenter** — les deux modules ; `| SessionEndedReason` dans chaque union (docblock : produite par le dépôt sur un 401 ou un 403 sans jeton, tâche 3, jamais affichée) ; dans chaque `load()` : `catch (error) { if (requestGuard.isCurrent(token) && !isSessionEndedError(error)) hasError.value = true }` (forme adaptée au composable) ; chaque calcul de message passe la raison par `visibleErrorReason()` avant `t()` ; `OrderToolbar` calcule `shownReason = visibleErrorReason(props.errorReason)` et s'en sert pour l'alerte **et** `cancelDisabled` (une erreur tue n'est pas une erreur affichée).

- [ ] **Step 4 : verts** — même commande PASS, puis `make front-test`, `make front-lint`, `make front-build`.

- [ ] **Step 5 : commit** — `fix(frontend): une session finie ne double plus le bandeau d'une erreur de page (#748)`.

---

### Task 3 : Un 403 sans jeton CSRF finit la session du backoffice (constats 15 et 7)

**Files :**
- Create : `frontend/src/infrastructure/http/sessionEnded.ts`, `frontend/tests/infrastructure/http/sessionEnded.spec.ts`, `frontend/tests/infrastructure/admin/sessionEndedMapping.spec.ts`
- Modify : `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts`, les 15 `frontend/src/infrastructure/admin/**/Http*Repository.ts` (`toError`, `toOrderError`, et l'erreur en ligne de `HttpAdminVulnerabilityRepository`)
- Modify : `frontend/tests/infrastructure/admin/shared/BackofficeHttpClient.spec.ts`, `frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts`
- Modify : `.claude/rules/frontend-backoffice.md` (puce « A 401 mid-page… », phrase « The 403 keeps its meaning », `paths:` + `frontend/tests/infrastructure/admin/sessionEndedMapping.spec.ts`)

**Interfaces :**
- Consumes : `SESSION_ENDED`, `SessionEndedReason` (tâche 2).
- Produces :
  - `endsTheSession(status: number, sentWithoutCsrfToken: boolean): boolean` = `401 === status || (403 === status && sentWithoutCsrfToken)` — `src/infrastructure/http/sessionEnded.ts` ;
  - `BackofficeHttpClient#endedTheSession(response: Response): boolean` — vrai pour une réponse que le client a jugée fin de session à l'envoi (un `WeakSet<Response>` de module, rempli là où la réaction est appelée).
  - noms inchangés : `setBackofficeUnauthorizedHandler`, `BackofficeUnauthorizedHandler` (seule leur documentation change).

**Choix :** le signal est l'**absence de jeton à l'envoi** (`!readCsrfToken()`, une valeur vide comptant comme
absente, puisque le client n'envoie alors pas d'en-tête), retenu par le client avec la réponse. **Écarté : relire le
cookie à la réception** — un autre onglet peut l'avoir reposé entre-temps, et la question est « le serveur a-t-il
refusé faute de jeton ». **Écarté : lire le `detail` du 403** (« En-tête CSRF manquant ou invalide. ») — texte
localisable, pas un contrat. Une lecture n'est jamais concernée : le CSRF ne la contrôle pas.

- [ ] **Step 1 : tests, rouges**

- `sessionEnded.spec.ts`, `it.each` : `(401, false)`, `(401, true)`, `(403, true)` → vrai ; `(403, false)`, `(200, true)`, `(404, true)`, `(422, true)`, `(500, true)` → faux.
- `BackofficeHttpClient.spec.ts` (aides : poser `document.cookie = 'XSRF-TOKEN=jeton; path=/'`, l'effacer avec une date passée, dans `afterEach`) :
  - le `it.each` existant garde 403 **pour la lecture et pour une écriture avec cookie** (le poser dans ce test) ;
  - `it("une écriture en 403 partie sans jeton CSRF appelle la réaction")` — pas de cookie → réaction appelée une fois, `client.endedTheSession(result)` vrai ;
  - `it("cookie XSRF-TOKEN vide : l'écriture part sans en-tête, son 403 finit la session")` (Review Focus 3) — `document.cookie = 'XSRF-TOKEN=; path=/'`, l'appel `fetch` n'a pas d'en-tête `X-XSRF-TOKEN`, réaction appelée ;
  - `it("une lecture en 403 n'est jamais une fin de session")` — réaction non appelée, `endedTheSession` faux ;
  - `it('endedTheSession : vrai pour un 401, faux pour un 200')`.
- `sessionEndedMapping.spec.ts`, table des 15 dépôts (`{ name, create: (url) => repo, read?: (repo) => Promise, write?: (repo) => Promise }` : une lecture et une écriture par dépôt ; lecture seule pour `HttpAdminVulnerabilityRepository`, écriture seule pour `HttpAdminTranslationRepository` ; l'ordre (`reorder`) en plus pour les dépôts ordonnés) :
  - `it('%s : un 401 en lecture est la raison session-ended')`, `it('%s : un 403 à une écriture sans jeton est la raison session-ended')`, `it('%s : un 403 à une écriture avec jeton garde son mapping')` (raison ≠ `session-ended`), `it('%s : reorder en 401 est AdminOrderError session-ended')` ;
  - `it('la table couvre tous les dépôts du backoffice')` — les clés de `import.meta.glob('../../../src/infrastructure/admin/**/Http*Repository.ts')` égalent les noms de la table.
- `AdminLayout.sessionEnded.spec.ts` (le banc existant, `HttpAdminCaseStudyRepository` réel) :
  - `it("un 401 à l'enregistrement : le bandeau est la seule alerte")` — exactement un `[role="alert"]`, celui du bandeau ; aucun texte « Une erreur est survenue » ;
  - `it("déconnecté dans un autre onglet (403, plus de XSRF-TOKEN) : le bandeau, seul, et la saisie reste")` — le `fetch` répond 403 aux écritures, aucun cookie → bandeau, une seule alerte, saisie et route inchangées ;
  - `it("un 403 avec jeton : pas de bandeau, l'erreur de la page")` — cookie posé → pas de bandeau, l'alerte de la page présente, `authState.user` non nul.

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/infrastructure tests/presentation/layout/AdminLayout.sessionEnded.spec.ts`. Expected : module introuvable, réaction non appelée sur le 403 sans jeton, raisons `unknown`, deux alertes.

- [ ] **Step 3 : implémenter** — `sessionEnded.ts` (docblock : les quatre faits vérifiés ci-dessus, avec les classes backend qui les portent) ; `BackofficeHttpClient` : `get` passe `false`, `mutate` passe `!csrfToken`, la méthode privée devient `notifyIfSessionEnded(response, sentWithoutCsrfToken)`, la classe docblock dit la nouvelle règle ; chaque dépôt teste `this.client.endedTheSession(response)` **en premier** dans son traducteur d'erreur et rend `new AdminXError(SESSION_ENDED, 'Session ended')` (`AdminOrderError` pour l'ordre).

- [ ] **Step 4 : règle** — `frontend-backoffice.md` : la réaction suit « un 401, ou un 403 à une écriture envoyée sans jeton CSRF » ; les faits vérifiés en deux phrases (CSRF avant le pare-feu, écritures seules ; `XSRF-TOKEN` cookie de session au login, effacé avec `BEARER` à la déconnexion et au refus d'une session antérieure) ; les dépôts lisent ces réponses comme `session-ended`, que composables et pages taisent (tâche 2, `visibleErrorReason`) ; « un 403 à une lecture, ou à une écriture qui portait son jeton, garde son sens ». **Limites acceptées**, écrites : un `XSRF-TOKEN` présent mais dont la signature ne vérifie plus (rotation d'`APP_SECRET`) reste un refus, alors que se reconnecter le réparerait ; un navigateur qui jette le cookie de session `XSRF-TOKEN` en gardant `BEARER` (fermeture sans restauration) lit une session finie — se reconnecter est bien le remède. Liste de tests de la puce et `paths:` mises à jour.

- [ ] **Step 5 : verts** — même commande, puis `make front-test`, `make front-lint`, `make front-build`.

- [ ] **Step 6 : commit** — `fix(frontend): un 403 sans jeton CSRF est une session finie du backoffice (#748)`.

---

### Task 4 : « Mes connexions MCP » — 403 et session finie (point 4 de #732, constats 15 et 10)

**Files :**
- Modify : `frontend/src/infrastructure/mcp/HttpMcpConnectionsRepository.ts:59-102`, `frontend/src/application/mcp/useMcpConnections.ts` (docblocks 401/403 seulement), `frontend/src/presentation/pages/McpConnectionsPage.vue:301`
- Test : `frontend/tests/infrastructure/mcp/HttpMcpConnectionsRepository.spec.ts`, `frontend/tests/presentation/pages/McpConnectionsPage.spec.ts`
- Modify : `.claude/rules/frontend.md` (puce « A refused session reaches the person in words »), `.claude/rules/mcp-oauth-revocation-personne.md` (« Frontend », « Slice » ; limites acceptées)

**Interfaces :**
- Consumes : `endsTheSession(status, sentWithoutCsrfToken)` (tâche 3).

- [ ] **Step 1 : tests, rouges**

- Dépôt : le test existant du 403 change exprès. `it('list : un 403 est un échec de la requête, pas une session finie')` → `McpConnectionsRequestFailedError` ; `it('revoke : un 403 avec jeton CSRF est un échec, pas une session finie')` (cookie posé) ; `it("revoke : un 403 parti sans jeton CSRF est une session finie")` → `SessionExpiredError` ; les 401 restent `SessionExpiredError`.
- Page : `it('session finie à la révocation : tous les boutons « Révoquer » sont désactivés')` — deux connexions actives, la révocation rejette `SessionExpiredError` → chaque `tbody button` a `disabled`.

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/infrastructure/mcp/HttpMcpConnectionsRepository.spec.ts tests/presentation/pages/McpConnectionsPage.spec.ts`.

- [ ] **Step 3 : implémenter** — `send(url, init, sentWithoutCsrfToken)` ; `list()` passe `false`, `revoke()` `!csrfToken` ; `httpError` : `endsTheSession(...)` → `SessionExpiredError`, un autre 403 tombe dans le cas générique ; le commentaire « 403 couvre aussi le refus CSRF » remplacé par la règle. Page : `:disabled="sessionExpired || null !== revokingId"`.

- [ ] **Step 4 : règles** — `frontend.md` et `mcp-oauth-revocation-personne.md` : « 401, ou 403 à une révocation partie sans jeton CSRF » au lieu de « 401/403 », renvoi à `frontend-backoffice.md` pour les faits ; les boutons sont désactivés en session finie. Limite acceptée : un `GET` en 403 (compte qui a perdu `ROLE_TRUSTED` pendant que la page est ouverte) montre l'erreur générique et « Réessayer », qui n'y peut rien — rare, et le présenter comme une session finie ferait se reconnecter pour rien, le défaut que #732-4 nommait.

- [ ] **Step 5 : verts** — même commande, puis les trois `make front-*`.

- [ ] **Step 6 : commit** — `fix(frontend): mes connexions MCP ne lisent plus un 403 comme une session finie (#748, #732)`.

---

### Task 4b : Page de consentement OAuth — 403 et session finie (point 4 de #732, constat 15)

**Files :**
- Create : `frontend/src/domain/mcp/errors/ConsentForbiddenError.ts`
- Modify : `frontend/src/infrastructure/mcp/HttpOAuthConsentRepository.ts:68-160` (`describe`, `decide`, `send`, `httpError`), `frontend/src/application/mcp/useOAuthConsent.ts` (`OAuthConsentFailure`, `fail()`), `frontend/src/presentation/pages/OAuthConsentPage.vue:72-132` (`errorMessage`, lien), `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`oauthConsent.errors`)
- Test : `frontend/tests/infrastructure/mcp/HttpOAuthConsentRepository.spec.ts`, `frontend/tests/application/mcp/useOAuthConsent.spec.ts`, `frontend/tests/presentation/pages/OAuthConsentPage.spec.ts`
- Modify : `.claude/rules/mcp-oauth.md` (puce « The consent page », phrase « 401 **and 403** (a CSRF refusal included) read as an expired session … accepted »)

**Interfaces :**
- Consumes : `endsTheSession(status, sentWithoutCsrfToken)` (tâche 3).
- Produces : `class ConsentForbiddenError extends Error` (sans paramètre, comme `ConsentAccountChangedError`) ; `OAuthConsentFailure` gagne `'forbidden'`.

**Ce que le code fait aujourd'hui (vérifié) :** les deux appels, `GET` (résumé) et `POST` (décision), lisent tout 403
comme `SessionExpiredError` → `markSessionExpired()` (l'en-tête passe anonyme) et l'échec `session-expired`, avec un
lien « Se reconnecter » vers `login?redirect=<fullPath>` **sans** `reauth=1`. Côté serveur
(`ConsentControllerTest`, règle `mcp-oauth.md`) : le `GET` n'est jamais soumis au CSRF, son 403 est le refus
d'`access_control` (compte sans `ROLE_TRUSTED`, palier de base) ; le `POST` prend le 403 CSRF avant le pare-feu, ou le
refus d'`access_control`. Pour une personne encore connectée, la route `login` la renvoie à l'accueil sauf avec
`reauth=1` (`router/index.ts`, `beforeEnter` : `authState.user && !isReauthentication(to)`) : le lien « Se
reconnecter » actuel ne s'ouvre donc **pas** si la session vaut encore, ce que le 403 laisse supposer.

**Choix :** la règle commune — un 401, ou un 403 au `POST` parti sans jeton, reste `SessionExpiredError` (le
`REAUTHENTICATION_REQUIRED` typé garde sa priorité sur le 401) ; tout autre 403 (le `GET`, ou le `POST` qui portait
son jeton) est `ConsentForbiddenError`, lu comme l'échec **terminal** `forbidden` (la demande n'est plus montrée),
**sans** marquer la session expirée (elle vaut pour le reste du site). La page dit que ce compte ne peut pas
autoriser cette connexion et propose « Changer de compte » — le lien qui existe déjà (`switchAccountTarget` :
`login?redirect=<fullPath>&reauth=1`), seul chemin qui ouvre la connexion à une personne connectée. Il répare aussi le
cas rare d'un `XSRF-TOKEN` à la signature périmée (rotation d'`APP_SECRET`) : la connexion repose des cookies neufs.
**Écarté : ramener `forbidden` à `ready` pour réessayer** — renvoyer la même décision au même refus. **Écarté : un
lien « Se reconnecter » sans `reauth=1`** — la route le renvoie à l'accueil, la personne étant connectée. Le texte de
`sessionExpired` perd sa seconde moitié (« ou ne permet pas d'autoriser cette connexion »), qui est désormais
`forbidden`.

- [ ] **Step 1 : tests, rouges**
- Dépôt (cookie `XSRF-TOKEN` posé ou effacé par test, comme à la tâche 3 ; les tests existants du 403 changent exprès) :
  - `it('describe : un 403 est un refus, pas une session finie')` → `ConsentForbiddenError` ;
  - `it('decide : un 403 avec jeton CSRF est un refus')` → `ConsentForbiddenError` ;
  - `it('decide : un 403 parti sans jeton CSRF est une session finie')` → `SessionExpiredError` ;
  - les 401 (non typé → `SessionExpiredError`, typé `reauthentication-required` → `ReauthenticationRequiredError`) restent verts.
- Composable : `it("forbidden : terminal, la demande n'est plus montrée, la session n'est pas marquée expirée")` — `describe` rejette `ConsentForbiddenError` → `state === 'error'`, `failure === 'forbidden'`, `request === null`, `authState` inchangé ; même chose pour `decide` (pas de retour à `ready`).
- Page :
  - `it('refus du serveur : le message dit que ce compte ne peut pas autoriser, et propose de changer de compte')` — une alerte au texte `oauthConsent.errors.forbidden` (FR et EN), un lien dont `href` porte `redirect=<le chemin complet de la demande>` et `reauth=1`, aucun bouton Autoriser/Refuser ;
  - `it('refus du serveur : aucune violation d\'accessibilité détectable')` (minuteries réelles rendues avant axe, comme le spec le fait déjà).

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/infrastructure/mcp/HttpOAuthConsentRepository.spec.ts tests/application/mcp/useOAuthConsent.spec.ts tests/presentation/pages/OAuthConsentPage.spec.ts`. Expected : `SessionExpiredError` reçu au lieu de `ConsentForbiddenError`, `failure` vaut `session-expired`, clé i18n absente.

- [ ] **Step 3 : implémenter** — `send(url, init, unprocessable, sentWithoutCsrfToken)` ; `describe` passe `false`, `decide` `!csrfToken` ; dans `httpError`, le `case 401` garde la lecture du type puis `SessionExpiredError`, le `case 403` rend `endsTheSession(403, sentWithoutCsrfToken) ? new SessionExpiredError() : new ConsentForbiddenError()` (commentaire « 403 couvre aussi le refus CSRF » remplacé par la règle). `fail()` : `ConsentForbiddenError` → `state = 'error'`, `failure = 'forbidden'`. Page : `case 'forbidden'` dans `errorMessage` ; le lien « Changer de compte » (`switchAccountTarget`, son libellé existant) rendu dans l'alerte quand `'forbidden' === failure`. i18n : `oauthConsent.errors.forbidden` FR « Ce compte ne peut pas autoriser cette connexion : il n'a pas, ou plus, l'accès de confiance. Changez de compte pour décider. », EN « This account cannot authorise this connection: it does not, or no longer, have trusted access. Switch account to decide. » ; `sessionExpired` FR « Votre session a expiré. », EN « Your session has expired. ».

- [ ] **Step 4 : règle** — `mcp-oauth.md`, puce « The consent page » : remplacer « 401 **and 403** (a CSRF refusal included) read as an expired session … accepted, a re-login gives fresh cookies » par la règle (`endsTheSession`, renvoi à `frontend-backoffice.md` pour les faits), l'échec terminal `forbidden`, la session non marquée, le lien `reauth=1` et pourquoi (la route `login` renvoie une personne connectée à l'accueil sans lui). Limite acceptée, écrite : après un `forbidden`, la page de connexion ouverte par `reauth=1` affiche l'annonce `auth.reauthNotice` (« autoriser une connexion MCP demande de vous être connecté il y a moins de dix minutes ») — déjà le cas de « Changer de compte », exacte sur le fond (la personne revient à la demande), imprécise sur la raison.

- [ ] **Step 5 : verts** — même commande, puis les trois `make front-*`.

- [ ] **Step 6 : commit** — `fix(frontend): le consentement OAuth distingue un refus d'une session finie (#748, #732)`.

---

### Task 4c : Assistant — 403 et session finie (point 4 de #732, constat 15)

**Files :**
- Modify : `frontend/src/infrastructure/assistant/HttpAssistantRepository.ts:31-160` (`answer`, `httpError`, `reasonFor`), `frontend/src/domain/assistant/errors/AssistantError.ts` (docblock des raisons), `frontend/src/application/assistant/useAssistant.ts:127-129`, `frontend/src/presentation/pages/AssistantPage.vue:18` (`needsLogin`), `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`assistant.errors.forbidden`)
- Test : `frontend/tests/infrastructure/assistant/HttpAssistantRepository.spec.ts`, `frontend/tests/application/assistant/useAssistant.spec.ts`, `frontend/tests/presentation/pages/AssistantPage.spec.ts`
- Modify : `.claude/rules/frontend.md` (paragraphe de l'assistant : « a 401/403 calls `markSessionExpired()` »)

**Interfaces :**
- Consumes : `endsTheSession(status, sentWithoutCsrfToken)` (tâche 3).
- `AssistantErrorReason` inchangé : `unauthenticated` devient « session finie » (401, ou 403 sans jeton), `forbidden` « refus réel » (403 avec jeton).

**Ce que le code fait aujourd'hui (vérifié) :** un seul appel, `POST /api/assistant/answers` (soumis au CSRF ; la
règle `ai.md` le dit : un `POST` anonyme y prend 403, pas 401). Le dépôt lit 401 → `unauthenticated`, 403 →
`forbidden` ; `useAssistant` appelle `markSessionExpired()` sur les deux ; la page affiche pour les deux un lien
« Se reconnecter » vers `login?redirect=/<locale>/assistant`, sans `reauth=1`. Conséquence : une déconnexion dans un
autre onglet (403 CSRF sans cookie) se lit déjà comme une session finie, mais au prix d'un message (`forbidden`,
« Votre session ne permet plus d'utiliser l'assistant ») qui suppose un compte rétrogradé ; et un vrai refus (compte
qui a perdu `ROLE_TRUSTED`, ou `XSRF-TOKEN` à la signature périmée) passe l'en-tête anonyme alors que la session vaut.

**Choix :** le dépôt applique la règle commune — `endsTheSession(status, !csrfToken)` → `unauthenticated` ; un 403
qui portait son jeton → `forbidden`. `useAssistant` ne marque la session expirée que sur `unauthenticated` ; la page
ne propose « Se reconnecter » que pour lui (il s'ouvre : la session est finie, la route `login` accueille un
anonyme). `forbidden` dit le refus sans lien. **Écarté : relire `/api/me` (`checkAuth`) sur `forbidden`** pour
réaligner l'en-tête d'un compte rétrogradé — couple le composable de l'assistant au dépôt d'authentification pour un
cas rare que le prochain chargement complet corrige. **Écarté : un lien `reauth=1`** — l'annonce de la page de
connexion parle d'autoriser une connexion MCP, hors de propos ici.

- [ ] **Step 1 : tests, rouges** (les tests existants changent exprès : la table `[403, 'forbidden']` du dépôt, l'`it.each(['unauthenticated', 'forbidden'])` de `useAssistant.spec.ts:180`, ceux d'`AssistantPage.spec.ts:201` et `:293`)
- Dépôt : `it('un 403 parti sans jeton CSRF est une session finie (unauthenticated)')` (aucun cookie) ; `it('un 403 avec jeton CSRF est un refus (forbidden)')` (cookie posé) ; 401 reste `unauthenticated`.
- Composable : `it('unauthenticated : la session est marquée expirée')` ; `it("forbidden : la session n'est pas marquée expirée")` — `authState.user` inchangé.
- Page : `it('session finie : message et lien « Se reconnecter »')` ; `it("refus : le message dit le refus, sans lien de reconnexion")` — texte `assistant.errors.forbidden`, aucun lien vers `login`, en FR et en EN ; axe vert dans les deux cas.

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/infrastructure/assistant tests/application/assistant tests/presentation/pages/AssistantPage.spec.ts`.

- [ ] **Step 3 : implémenter** — `answer()` garde `csrfToken` et le passe à `httpError(response, !csrfToken)` ; `reasonFor(status, sentWithoutCsrfToken)` commence par `endsTheSession(...)` → `unauthenticated`, puis `403` → `forbidden` ; docblock d'`AssistantError` mis à jour ; `useAssistant` : `if ('unauthenticated' === failure.reason) markSessionExpired()` ; `needsLogin` sur `unauthenticated` seul. i18n `assistant.errors.forbidden` FR « Le serveur refuse l'assistant à ce compte : son accès de confiance a peut-être été retiré. », EN « The server refuses the assistant to this account: its trusted access may have been withdrawn. ».

- [ ] **Step 4 : règle** — `frontend.md`, paragraphe de l'assistant : « a 401, or a 403 sent without a CSRF token, calls `markSessionExpired()` ; a 403 that carried its token is a refusal (`forbidden`), the session stays » ; limite acceptée : l'en-tête d'un compte rétrogradé reste au palier de confiance jusqu'au prochain chargement (rejet de la relecture de `/api/me`, une ligne). `ai.md` ne décrit pas le frontend : rien à y écrire.

- [ ] **Step 5 : verts** — même commande, puis les trois `make front-*`.

- [ ] **Step 6 : commit** — `fix(frontend): l'assistant distingue un refus d'une session finie (#748, #732)`.

---

### Task 5 : « Mes connexions MCP » — focus et annonces (constats 3, 4, 8, 9, 11)

**Files :**
- Modify : `frontend/src/presentation/pages/McpConnectionsPage.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`mcpConnections.confirm`)
- Test : `frontend/tests/presentation/pages/McpConnectionsPage.spec.ts`
- Modify : `.claude/rules/frontend-a11y.md` (nouvelle puce), `.claude/rules/mcp-oauth-revocation-personne.md` (« The page »)

**Choix :**
- Constat 3 : **le focus d'abord, l'annonce ensuite** — le titre est focalisé, puis l'issue est écrite dans la région
  `role="status"` 250 ms plus tard (`ANNOUNCE_AFTER_FOCUS_MS`, constante de la page). **Écarté : `aria-describedby` du
  titre vers le message** — la lecture d'une description au focus dépend des réglages du lecteur d'écran (VoiceOver la
  lit après un délai, NVDA selon « annoncer les descriptions »), et la description resterait collée au titre à chaque
  focus suivant.
- Constat 4 : **au succès, le focus va au titre ; au nouvel échec, au nouveau bouton « Réessayer »** ; session finie :
  au titre (l'alerte et son lien sont annoncés). **Écarté : garder l'alerte et un bouton `disabled`/`aria-busy`** — au
  succès le bouton disparaît quand même et le focus retombe sur `<body>`, et un second échec ne change pas le texte de
  l'alerte, donc n'est pas annoncé.
- Constat 11 : `aria-busy` passe de la `<section>` au conteneur du tableau.

- [ ] **Step 1 : tests, rouges** (`vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })` dans les tests du délai ; si `flushPromises` s'en trouve bloqué, faire avancer avec `vi.advanceTimersByTimeAsync`)

- `it("après une révocation, le titre a le focus avant que l'issue soit annoncée")` — juste après `flushPromises()` : `document.activeElement` est le `<h1>`, la région `status` est vide ; après `vi.runAllTimers()` : elle contient « révoquée ».
- Le test existant « confirmation acceptée : … l'annonce dans la région role="status" » avance les minuteries avant son assertion (changement exprès) ; même chose pour « 404 : … déjà terminée ».
- `it("le démontage annule l'annonce en attente")` (Review Focus 2) — démonter avant `runAllTimers()` → aucune erreur, `vi.getTimerCount() === 0`.
- `it("échec de la révocation : le focus reste sur le bouton « Révoquer »")` (constat 8) — 500 → `document.activeElement` est le bouton cliqué.
- `it('« Réessayer » réussi : le focus passe au titre')` et `it('« Réessayer » en échec : le focus passe au nouveau « Réessayer »')` (constat 4).
- `it('la confirmation nomme le client et la date de l\'accord')` (constat 9) — `window.confirm` reçoit un texte contenant « Claude Code » et la date formatée de `grantedAt`, en FR et en EN.
- Le test existant « pendant le chargement : aria-busy… » change exprès : au premier chargement, aucun `aria-busy` sur la `<section>` ; `it("pendant la relecture qui suit une révocation, c'est le tableau qui est occupé, pas la région d'état")` — le conteneur `.table-responsive` porte `aria-busy="true"`, aucun ancêtre de `[role="status"]` ne le porte.

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/presentation/pages/McpConnectionsPage.spec.ts`.

- [ ] **Step 3 : implémenter** — dans la page : `announcedOutcome = ref<McpRevocationOutcome | null>(null)` lu par `statusMessage` à la place de `lastOutcome` ; `onRevoke` annule la minuterie en cours et vide `announcedOutcome`, puis après `revoke()` : si `lastOutcome` vaut `revoked` ou `already-gone`, `nextTick`, focus du titre, puis `setTimeout` qui copie `lastOutcome` ; sinon rien (le bouton existe encore) ; `onBeforeUnmount` annule. `onRetry()` (ref `retryButton`) : `await load()`, `nextTick`, puis focus selon le choix ci-dessus. `aria-busy="isLoading"` sur le `div.table-responsive`. i18n : `confirm` reçoit `{date}` — FR « Révoquer la connexion de « {client} », accordée le {date} ? Elle cessera de fonctionner dès la prochaine requête de l'assistant. », EN « Revoke the connection of "{client}", granted on {date}? It will stop working at the assistant's next request. » ; l'appel passe `date(connection.grantedAt)`.

- [ ] **Step 4 : règles** — `frontend-a11y.md`, nouvelle puce : quand un geste déplace le focus et annonce une issue, le focus d'abord, la région `status` écrite après un court délai (une annonce écrite au moment du déplacement peut être vidée par la lecture de l'élément focalisé) ; pourquoi pas `aria-describedby` ; jamais d'`aria-busy` sur un ancêtre d'une région vivante. `mcp-oauth-revocation-personne.md` : la confirmation nomme le client **et la date** ; le focus ne part au titre que sur `revoked`/`already-gone` ; « Réessayer » rend le focus. Non vérifié, à garder écrit : l'écoute avec un vrai lecteur d'écran (NVDA, VoiceOver).

- [ ] **Step 5 : verts** — puis les trois `make front-*`.

- [ ] **Step 6 : commit** — `fix(frontend): focus et annonces de « Mes connexions MCP » (#748)`.

---

### Task 6 : « Mes connexions MCP » — le tableau (constats 5, 18, 19)

**Files :**
- Modify : `frontend/src/presentation/pages/McpConnectionsPage.vue:221-319` (+ bloc `<style scoped>`)
- Test : `frontend/tests/presentation/pages/McpConnectionsPage.spec.ts`

**Choix :** constat 5, `position-relative` sur `.table-responsive` : le `visually-hidden` (`position: absolute`) de
l'en-tête « Actions » et des « Sans échéance » trouve son ancêtre positionné dans le conteneur défilant. **Écarté :
remplacer le `<span>` par un `aria-label` sur le `<th>`** — un en-tête sans texte visible ni contenu déclenche
`empty-table-header` d'axe et dépend du support d'`aria-label` sur une cellule. Constat 18 : `overflow-y: hidden` en
CSS scopé (un conteneur sans hauteur imposée n'a jamais à défiler verticalement). Constat 19 : le tableau référence la
mention par `aria-describedby`, **seulement quand elle est rendue** (une référence vers un id absent est une faute).

- [ ] **Step 1 : tests, rouges**
- `it('le conteneur défilant du tableau est positionné : rien de masqué n\'en sort à 320 px')` — `.table-responsive` a la classe `position-relative`.
- `it("historique coupé : le tableau est décrit par la mention")` — `table` a `aria-describedby` égal à l'`id` de `[data-testid="terminated-truncated"]` ; `it("historique entier : le tableau n'a pas d'aria-describedby")`.
- Le test axe « avec la mention d'un historique coupé » reste vert.

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/presentation/pages/McpConnectionsPage.spec.ts`.

- [ ] **Step 3 : implémenter** — classe, `id` fixe de la mention (`mcp-connections-truncated`), `:aria-describedby`, `<style scoped>` : `.table-responsive { overflow-y: hidden; }` commenté (barre parasite de 1 px). À vérifier au navigateur (tâche 11) : le contour de focus du dernier « Révoquer » n'est pas rogné.

- [ ] **Step 4 : verts** — puis les trois `make front-*`.

- [ ] **Step 5 : commit** — `fix(frontend): le tableau de « Mes connexions MCP » tient à 320 px (#748)`.

---

### Task 7 : Menu du compte (constats 12, 13, 20)

**Files :**
- Modify : `frontend/src/presentation/layout/AccountMenu.vue`, `frontend/src/presentation/layout/AppHeader.vue` (les quatre `RouterLink` du menu du compte mobile, pour l'entrée courante)
- Test : `frontend/tests/presentation/layout/AccountMenu.spec.ts`, `frontend/tests/presentation/layout/AppHeader.spec.ts`
- Modify : `.claude/rules/mcp-oauth-revocation-personne.md` (« Desktop account menu »)

**Choix :** constat 13, la piste du ticket — fermer sur `focusout` **quand `relatedTarget` est non nul et hors du
conteneur** ; Safari donne `relatedTarget` nul au clic d'un bouton, le menu reste alors ouvert (limite acceptée, déjà
écrite dans le docblock, à garder). **Écarté : fermer à tout `focusout`** — Safari fermerait la liste avant que le clic
n'arrive. Constat 20 : `exact-active-class="active"` sur les `RouterLink` (la classe `.dropdown-item.active` déjà
employée par `AdminLayout` et les groupes de l'en-tête ; `aria-current="page"` est posé par `RouterLink` lui-même).

- [ ] **Step 1 : tests, rouges**
- `it("Échap ailleurs ferme sans prendre le focus")` (constat 12, Review Focus 5) — menu ouvert, focus sur un bouton hors du composant (attaché au document), Échap → menu fermé, `document.activeElement` est ce bouton.
- Le test « Échap referme le menu et rend le focus au bouton » reste vert (focus dans le menu).
- `it('le focus qui part hors du menu le referme')` — `focusout` d'une entrée avec `relatedTarget` = un bouton extérieur → fermé, focus non déplacé.
- `it('un focusout sans relatedTarget (Safari) laisse le menu ouvert')` et `it('le focus qui passe d\'une entrée à l\'autre le laisse ouvert')`.
- `it("l'entrée de la page courante est distinguée visuellement")` — sur `/fr/mcp-connections`, le lien « Mes connexions MCP » a la classe `active` et `aria-current="page"`, les autres non. Même assertion dans `AppHeader.spec.ts` pour le menu mobile.

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/presentation/layout/AccountMenu.spec.ts tests/presentation/layout/AppHeader.spec.ts`.

- [ ] **Step 3 : implémenter** — `onDocumentKeydown` : retenir `container.value?.contains(document.activeElement)` avant `close()`, ne rendre le focus que dans ce cas ; `@focusout="onFocusOut"` sur le conteneur racine ; `exact-active-class="active"`. Docblock : la phrase « Pas de fermeture à la sortie du focus » devient la règle nouvelle et sa limite Safari.

- [ ] **Step 4 : règle** — « Desktop account menu » : fermeture aussi quand le focus part hors du menu (sauf `relatedTarget` nul, Safari) ; Échap ne rend le focus que s'il était dans le menu ; l'entrée courante porte `active`.

- [ ] **Step 5 : verts** — puis les trois `make front-*`.

- [ ] **Step 6 : commit** — `fix(frontend): le menu du compte ne vole plus le focus et suit sa sortie (#748)`.

---

### Task 8 : Bandeau de session finie collant, retour annoncé, icône du nouvel onglet (constats 7 seconde piste, 14, 21)

**Files :**
- Create : `frontend/src/presentation/layout/usePublishedHeight.ts`, `frontend/tests/presentation/layout/usePublishedHeight.spec.ts`
- Modify : `frontend/src/presentation/ui/admin/SessionEndedBanner.vue`, `frontend/src/presentation/layout/AppHeader.vue` (`ref` sur `<header>`), `frontend/src/style.css`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`admin.sessionEnded.restored`)
- Test : `frontend/tests/presentation/ui/admin/SessionEndedBanner.spec.ts`, `frontend/tests/presentation/styleSheet.spec.ts`
- Modify : `.claude/rules/frontend-backoffice.md` et `.claude/rules/frontend-a11y.md` (puces du bandeau), `paths:` de `frontend-backoffice.md` + le nouveau spec

**Interfaces :**
- Produces : `usePublishedHeight(target: Readonly<Ref<HTMLElement | null>>, property: \`--${string}\`): void` — tant que la cible existe, sa hauteur (`borderBoxSize`, arrondie au pixel supérieur, `px`) est posée sur `document.documentElement.style` sous `property` par un `ResizeObserver` ; retirée quand la cible disparaît (`v-if`) et au démontage ; sans `ResizeObserver` (jsdom), ne fait rien. Propriétés : `--app-header-height` (en-tête), `--admin-notice-height` (bandeau).

**Choix :** le bandeau reste dans `AdminLayout` au-dessus du `RouterView` (ordre de lecture et tests inchangés), en
`position: sticky; top: var(--app-header-height, 0px)`, fond opaque `var(--bs-body-bg)`, `z-index` sous celui de
l'en-tête (1020) pour que ses menus passent dessus. **Écarté : le téléporter dans l'en-tête** — une seule hauteur à
mesurer, mais le bandeau entrerait dans le repère `banner`, hors d'`AdminLayout` (que ses tests montent sans en-tête),
et `Teleport` exige une cible montée. **Écarté : une hauteur d'en-tête codée en dur** — elle change avec le menu
mobile et les alertes de l'en-tête (Review Focus 1). Pour que le focus ne passe pas sous l'en-tête et le bandeau
(2.4.11), `html { scroll-padding-top: calc(var(--app-header-height, 0px) + var(--admin-notice-height, 0px)) }` — qui
corrige au passage le masquage par l'en-tête collant sur tout le site. Constat 14 : une région `role="status"`
**présente dès le montage** du bandeau (une région insérée avec son texte peut ne pas être annoncée), qui reçoit
« Session rétablie, vous pouvez enregistrer. » quand `hasSessionEnded` passe de vrai à faux, masquée visuellement
quand elle est vide, vidée au changement de route suivant.

- [ ] **Step 1 : tests, rouges**
- `usePublishedHeight.spec.ts` (un faux `ResizeObserver` par `vi.stubGlobal`, qui garde son rappel) : `it('pose la hauteur relevée')` → `--x` vaut `48px` ; `it('un second relevé met la propriété à jour')` → `120px` (Review Focus 1) ; `it('retire la propriété quand la cible disparaît, et au démontage')` ; `it('sans ResizeObserver, ne fait rien')`.
- `SessionEndedBanner.spec.ts` :
  - `it('le bandeau est collant sous l\'en-tête')` — l'alerte porte la classe `session-ended-banner` (le CSS est mesuré à la tâche 11) ;
  - `it('une région role="status" existe dès le montage, vide')` ;
  - `it('au retour de la session, la région annonce « Session rétablie » et l\'alerte disparaît')` — `markSessionExpired()`, puis l'événement `focus` avec un `me` qui rend un compte → région : « Session rétablie, vous pouvez enregistrer. », aucun `[role="alert"]` ;
  - `it('le message de retour se vide à la navigation suivante')` ;
  - `it("le lien dit le nouvel onglet aussi aux yeux : une icône cachée aux lecteurs d'écran")` — le lien contient un `svg[aria-hidden="true"]`, son texte reste « Se reconnecter (s'ouvre dans un nouvel onglet) ».
  - le test axe existant reste vert, bandeau affiché et après retour.
- `styleSheet.spec.ts` : `it('le défilement réserve la place de l\'en-tête et du bandeau collants')` — `style.css` contient `scroll-padding-top` avec `--app-header-height` et `--admin-notice-height`.

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/presentation/layout/usePublishedHeight.spec.ts tests/presentation/ui/admin/SessionEndedBanner.spec.ts tests/presentation/styleSheet.spec.ts`.

- [ ] **Step 3 : implémenter** — la composable ; le bandeau : racine `<div>`, l'alerte (`v-if`, `ref`, classe `session-ended-banner`, `usePublishedHeight(…, '--admin-notice-height')`), la région `status` ; `watch(hasSessionEnded, (now, before) => …)` pose le message sur `true → false` ; `watch(() => route.fullPath, …)` le vide ; `IconExternalLink` (`~icons/lucide/external-link`, `aria-hidden="true"`, 14 px) dans le lien ; `<style scoped>` du bandeau (sticky, `top`, fond, `z-index: 1010`, `padding-block`, bordure basse). `AppHeader` : `ref="header"` et `usePublishedHeight(header, '--app-header-height')`. `style.css` : la règle `html` commentée. i18n FR « Session rétablie, vous pouvez enregistrer. », EN « Session restored, you can save again. ».

- [ ] **Step 4 : règles** — `frontend-backoffice.md` (puce « A 401 mid-page… ») : le bandeau est collant sous l'en-tête, hauteurs publiées par `usePublishedHeight`, `scroll-padding-top` ; le retour de la session est annoncé par une région présente dès le montage. `frontend-a11y.md` (puce du bandeau) : l'icône `external-link` en plus du texte caché ; « une région qui annonce un retour existe avant son message ».

- [ ] **Step 5 : verts** — puis les trois `make front-*`.

- [ ] **Step 6 : commit** — `fix(frontend): le bandeau de session finie reste visible et annonce le retour (#748)`.

---

### Task 9 : L'en-tête pendant une session finie (constat 16)

**Files :**
- Modify : `frontend/src/presentation/layout/AppHeader.vue` (bloc `'anonymous' === tier`)
- Test : `frontend/tests/presentation/layout/AppHeader.spec.ts`
- Modify : `.claude/rules/frontend-backoffice.md` (puce « A 401 mid-page… »)

**Choix :** quand `hasSessionEnded` est vrai **et** que la route a `meta.requiresAuth` (backoffice, MCP, assistant :
les pages qui ont fait finir la session), « Connexion » s'ouvre dans un nouvel onglet vers
`login?redirect=<route.fullPath>&expired=1`, `rel="noopener"`, texte caché `common.opensInNewTab` et icône
`external-link` — le même lien que le bandeau ; « Accès instantané » n'est pas proposé (il remplacerait le cookie et
mènerait aux études de cas, la page et sa saisie avec). **Écarté : masquer « Connexion »** — un bouton qui disparaît
déplace la barre et retire un chemin connu. Sur une route publique, rien ne change (`sessionEnded` y survit jusqu'à
une connexion, et un nouvel onglet y serait incompréhensible).

- [ ] **Step 1 : tests, rouges**
- `it("session finie sur une page protégée : « Connexion » ouvre un nouvel onglet qui ramène ici")` — `target="_blank"`, `rel="noopener"`, `href` = `/fr/login?redirect=/fr/admin/case-studies&expired=1`, texte contenant « (s'ouvre dans un nouvel onglet) », `svg[aria-hidden="true"]` dans le lien.
- `it("session finie sur une page protégée : pas d'« Accès instantané »")`.
- `it('session finie sur une page publique : la connexion habituelle')` et `it('session active : rien ne change')` (témoins).
- axe : `it('session finie : aucune violation d\'accessibilité détectable')`.

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/presentation/layout/AppHeader.spec.ts`.

- [ ] **Step 3 : implémenter** — `const sessionEndedHere = computed(() => hasSessionEnded.value && true === route.meta.requiresAuth)` ; le lien et les deux boutons d'accès conditionnés ; `SESSION_ENDED_QUERY`/`SESSION_ENDED_VALUE` de `presentation/router/sessionEnded.ts` (jamais la chaîne recopiée).

- [ ] **Step 4 : règle** — `frontend-backoffice.md` : l'en-tête suit le bandeau sur une page protégée en session finie.

- [ ] **Step 5 : verts** — puis les trois `make front-*`.

- [ ] **Step 6 : commit** — `fix(frontend): l'en-tête ne fait plus perdre la saisie d'une session finie (#748)`.

---

### Task 10 : L'en-tête à 320 px (constat 6)

**Files :**
- Modify : `frontend/src/presentation/layout/AppHeader.vue` (lien de marque, groupe de droite, `<style scoped>`)
- Test : `frontend/tests/presentation/layout/AppHeader.spec.ts`

**Choix (décision de l'auteur) :** la marque se tronque. Le bouton « Accès instantané » est déjà une icône seule sous
576 px, la piste du ticket « icône seule sous 360 px » ne gagne rien. Écartées : le libellé de marque masqué sous
360 px (tout ou rien, une borne de plus) ; `flex-wrap` de la ligne (l'en-tête collant gagne ~50 px à 320 px, ce qui
empire 2.4.11 avec le bandeau collant).

- [ ] **Step 1 : tests, rouges**
- `it('la marque cède la place aux actions : son libellé se tronque, les actions ne rétrécissent pas')` — le libellé de marque est un `<span class="text-truncate">`, le lien porte la classe `brand-link` (`min-width: 0`), le groupe de droite `flex-shrink-0`.
- `it('le nom accessible du lien d\'accueil reste la marque entière')` — `text()` du lien = `brandName`.

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/presentation/layout/AppHeader.spec.ts`.

- [ ] **Step 3 : implémenter** — `brand-link` (`min-width: 0`, scopé), libellé en `<span class="text-truncate">`, `flex-shrink-0` sur le groupe de droite ; commentaire : mesures du ticket (331 et 389 px pour 301), le nom accessible entier.

- [ ] **Step 4 : verts** — puis les trois `make front-*`.

- [ ] **Step 5 : commit** — `fix(frontend): l'en-tête tient à 320 px (#748)`.

---

## Corrections de relecture (2026-10-29)

Les tâches 1 à 10 sont faites (0838f0ed → 0040068c). Deux relectures du 2026-10-29 ont retenu les constats ci-dessous ;
**tous sont corrigés dans cette branche**, par les tâches R1 à R10, avant les tâches 11 et 12. Décisions de l'auteur
prises sur ces relectures : CR3 par les deux corrections (backend et frontend) ; CR5 par la resynchronisation de la
session et le lien « Changer de compte » partout ; CR7 par une revérification au retour sur toutes les pages
protégées ; le refactoring (CR9, Std1 à Std9) entier ici. Hors périmètre, traités par le fil principal : le préfixe
`__Host-` des cookies (S2), les constats portant sur la fusion 0e581b42 (`Retry-After` du backend…).

### Vérification de chaque constat dans le code (HEAD 0040068c)

Aucun constat n'est écarté. Un seul l'est en partie (CR7, première moitié, déjà en place).

- **CR1/C1 et D1, confirmés.** `SessionEndedBanner.vue` a pour racine un `<div>` qui ne contient que l'alerte collante
  et la région `status` (`visually-hidden`, donc `position: absolute`, hors du flux). Le bloc contenant du `sticky` est
  ce `<div>`, haut exactement de l'alerte : elle n'a aucune course et défile avec lui. Ce même `<div>`, toujours rendu,
  est un élément du flux de la `<section class="d-flex flex-column gap-4">` d'`AdminLayout` : un `gap-4` de plus, même
  vide.
- **CR2, confirmé.** `useAdminAboutSettings.load(locale)` ne vide pas `settings` quand la locale change ; un rejet
  `session-ended` ne lève plus `hasError` (tâche 2), donc `AdminAboutSettingsForm` (`v-else` du formulaire) montre les
  champs FR sous l'en-tête EN, et `save(props.locale)` enverrait le FR sur l'EN. **Seul composable à clé** : les douze
  autres `load()` ne prennent aucun paramètre (`grep "const load = async"` dans `src/application/admin`), aucun ne charge
  par identifiant.
- **CR4/D2, confirmé.** Une première lecture en `session-ended` laisse la liste à `[]` avec `hasError` faux : les douze
  états vides (`admin.*.empty`, `admin.watch.vulnerabilities.empty`) s'affichent comme si le contenu n'existait pas.
- **CR3/S1, confirmé.** Deux sites seulement posent `XSRF-TOKEN` : `LoginSuccessSubscriber.php:57-59` (sans échéance,
  cookie de session) et `BaseAccessController.php:69` (échéance du jeton, déjà). Aucun autre : la définition du mot de
  passe ne connecte pas, il n'existe aucun rafraîchissement de JWT (`security-authentication-sessions.md`, « a JWT's
  `iat` »), `CookieLogoutListener` et `StaleSessionCookiesClearingListener` ne font qu'expirer. `BEARER` reçoit
  `time() + 3600` de Lexik (`JWTCookieProvider::createCookie`, `set_cookies.BEARER.lifetime: 3600`), le JWT `token_ttl:
  3600` (paramètre `lexik_jwt_authentication.token_ttl`). Un navigateur fermé puis rouvert sans restauration jette le
  cookie de session et garde `BEARER` : chaque écriture part sans jeton, prend un 403, lu « session finie » ;
  `recheckSession` retrouve le compte au `focus` et annonce « Session rétablie » ; la boucle recommence.
- **CR5/S3/S4, confirmé, et `/api/me` relit bien les rôles en base.** Le fournisseur `cpg_users` (entité) recharge le
  `CpgUser` ; `JWTAuthenticator::createToken()` (Lexik) bâtit le jeton avec `$passport->getUser()->getRoles()` ;
  `access_control` exige `ROLE_TRUSTED` sur `^/api/me`. Un compte qui a perdu l'accès de confiance prend donc un 403
  sur `/api/me` (palier de base pour `HttpAuthRepository.me()`), un `XSRF-TOKEN` à la signature périmée un 200 (en-tête
  inchangé). Aujourd'hui : la page MCP lit un 403 comme l'échec générique avec « Réessayer » sans fin, l'assistant et le
  consentement laissent l'en-tête au palier de confiance.
- **CR6, confirmé.** `hasRestored` n'est remis à faux que par `watch(() => route.fullPath)`.
- **CR7, en partie déjà en place.** « Connexion » en nouvel onglet sur toute route `requiresAuth` : déjà vrai
  (`sessionEndedHere` lit `route.meta.requiresAuth`) ; seul un test par route manque (R8). La revérification au `focus`
  n'existe que dans le bandeau, donc dans le backoffice : à corriger (R8).
- **CR8, confirmé.** `AccountMenu.vue:85` : `container.contains(document.activeElement)` est faux quand
  `activeElement` est `body`.
- **CR10, confirmé.** `onRetry()` n'appelle pas `cancelAnnouncement()` : `announcedOutcome` garde « révoquée ».
- **S7, confirmé.** `markSessionExpired()` est idempotent : un second refus ne change rien au DOM du bandeau.
- **C2, confirmé pour le commentaire ; débordement probable.** Le ticket (constat 6) mesure le **groupe de droite
  seul** : 331 px au palier de confiance, 389 px en anonyme et en EN, pour 301 px utiles ; le commentaire
  d'`AppHeader.vue:243` inverse les deux. Le groupe étant `flex-shrink-0`, tronquer la marque ne peut pas absorber
  30 à 88 px qui ne sont pas les siens : si ces mesures tiennent encore, la ligne déborde (confiance moyenne, la mesure
  date d'avant les tâches 7 et 9 qui ont changé ce groupe). Mesure et repli : tâche 11.
- **CR9, Std1 à Std9, confirmés.** 25 traducteurs d'erreur testent `endedTheSession()` dans les 15 dépôts ; 13
  `load()` répètent `requestGuard.isCurrent(token) && !isSessionEndedError(error)` ; 23 appels de
  `visibleErrorReason(` dans les pages ; le lien de reconnexion est calculé dans `AppHeader.vue:174`,
  `SessionEndedBanner.vue:45`, `McpConnectionsPage.vue:66`, la locale par la route (en-tête) ou par i18n (les deux
  autres) ; le booléen `sentWithoutCsrfToken` traverse `send` → `httpError` dans les trois dépôts `fetch` ; le
  consentement juge le 403 dans son `case 403` (`endsTheSession(403, …)`), les autres avant le `switch` ;
  `common.opensInNewTab` dit « (nouvelle fenêtre) » en FR et « (opens in a new tab) » en EN.

### Choix de conception tranchés

1. **La session finie est une erreur typée levée par le transport, plus une raison par contexte** (CR9, Std1, Std2).
   `SessionEndedError` (`domain/auth/errors/`) remplace la raison `session-ended` des onze unions, le `WeakSet` de
   module et `SessionExpiredError` (MCP). `BackofficeHttpClient` la lève lui-même après la réaction ; aucun dépôt
   d'administration ne la traduit plus ; deux fonctions d'`application/admin/shared/` la taisent, une pour les
   chargements (`useAdminLoad`), une pour les mutations (`visibleMutationError`) ; les pages ne voient plus jamais cette
   raison et `visibleErrorReason` disparaît. *Avantages* : une règle écrite à un endroit par couche (transport, puis
   application), plus de clé de traduction impossible à garder, 25 + 23 sites supprimés. *Inconvénients* : 14 `catch`
   de mutation changent de forme, et une erreur de session sort de la hiérarchie de chaque contexte (voulu : ce n'est
   pas une erreur du contexte). *Écarté* : garder la raison et factoriser le filtre dans un `errorText(prefix,
   reason)` — la clé `session-ended` resterait dans onze unions et chaque nouveau dépôt devrait la produire. Pas de
   patron GoF : le signal est une exception typée, filtrée par deux fonctions partagées.
2. **Un seul envoi « qui juge la session »** (Std5, Std6, Std4) : `infrastructure/http/sessionAwareFetch.ts`
   pose les cookies, l'en-tête CSRF d'une écriture et rend `{ response, endedTheSession }`. Le booléen ne traverse
   plus rien ; chaque dépôt teste `endedTheSession` à une place fixe (après le seul cas typé qui le précède, le 401
   `reauthentication-required` du consentement). Le module remplace `infrastructure/http/sessionEnded.ts` (un seul
   `sessionEnded.ts` restera, celui du routeur). `frontend.md` interdit `BackofficeHttpClient` aux dépôts MCP, du
   consentement et de l'assistant : ils appellent le helper, pas ce client, qui l'appelle aussi. *Écarté* : une classe
   cliente commune aux quatre (la réaction globale du backoffice, `setBackofficeSessionEndedHandler`, n'est pas celle
   des trois autres, qui marquent la session dans leur composable).
3. **Pas d'`errorText(prefix, reason)`** (Std2, troisième volet) : la raison filtrée n'existant plus, chaque page
   revient à `t(\`admin.<contexte>.errors.${reason}\`)`, sa forme d'avant #748. La répétition est antérieure à #748 et
   ne porte aucune règle ; un helper serait une abstraction sans variation (YAGNI).
4. **Un lien de reconnexion, un composant** (Std3, Std7) : `signInAgainLocation()` (`presentation/router/
   sessionEnded.ts`), `switchAccountLocation()` (`presentation/router/reauthentication.ts`) et la langue par
   `routeLocale()` (route d'abord, repli i18n) ; `presentation/ui/SignInAgainLink.vue` porte le balisage du nouvel
   onglet (texte caché `common.opensInNewTab`, icône). Nouvel onglet dans le bandeau, l'en-tête, la page MCP et
   l'assistant (une saisie ou une page à garder ; la revérification au retour rétablit l'onglet d'origine). **Le
   consentement garde ses liens dans le même onglet**, par les mêmes constructeurs : c'est une étape d'un flux OAuth
   qui finit par `location.replace` vers le client, un second onglet laisserait la demande ouverte dans le premier.
   `common.opensInNewTab` FR devient « (s'ouvre dans un nouvel onglet) » (comme l'EN ; `target="_blank"` ouvre un
   onglet dans les navigateurs actuels) et `admin.sessionEnded.newTab` disparaît.
5. **Un refus réel** (CR5, S8) : `AccessForbiddenError` (`domain/auth/errors/`, remplace `ConsentForbiddenError`) pour
   la page MCP et le consentement, la raison `forbidden` pour l'assistant ; les trois composables relisent `/api/me`
   (`checkAuth()`, vérifié : rôles relus en base) et ne marquent jamais la session expirée ; un message neutre partagé
   (`common.accessRefused`, juste aussi pour une signature CSRF périmée) et « Changer de compte »
   (`switchAccountLocation`, même onglet, la connexion s'ouvrant à une personne connectée). L'annonce de la page de
   connexion ouverte par `reauth=1` (`auth.reauthNotice`) devient neutre : elle parlait d'une connexion MCP ; la raison
   des dix minutes reste dite par l'alerte du consentement, avant le lien.
6. **Une revérification partagée** (CR7, CR3b) : `useAuth().recheckEndedSession()`, branchée une fois sur le `focus`
   de la fenêtre par `presentation/layout/useSessionRecheckOnFocus.ts` dans `AppLayout` ; le bandeau perd son écouteur.
   Une session n'est rétablie que si le compte revient **et** qu'un jeton d'écriture existe
   (`AuthRepository.hasWriteToken()`, port de domaine que `HttpAuthRepository` lit dans le cookie : l'application ne lit
   pas un cookie). Chaque composable qui a vu la session finir relit au retour (`watch` de `authState.sessionEnded`).
7. **Le bandeau a deux racines** (CR1, D1) : l'alerte (`v-if`) et la région `status`, enfants directs de la `<section>`
   d'`AdminLayout`, qui contient la page : le collage a toute la hauteur de la page, et rien n'entre dans le flux tant
   que les deux sont vides. *Écarté* : `display: contents` sur le `<div>` (même effet, mais une règle CSS de plus à
   connaître, et des pertes de sémantique connues de `display: contents` dans Safari) ; déplacer la région dans
   `AdminLayout` (le comportement du bandeau coupé en deux fichiers).
8. **« Focus puis annonce » dans un composable** (Std8) : `presentation/ui/useFocusThenAnnounce.ts` tient la minuterie,
   l'annulation, le démontage ; la page perd quatre déclarations et le patron d'accessibilité de `frontend-a11y.md`
   pointe une implémentation unique. Elle simplifie réellement : `onRevoke` et `onRetry` n'ont plus à connaître la
   minuterie, et `onRetry` l'annule (CR10).
9. **Une règle de référence** (Std9) : `.claude/rules/frontend-session.md` (nouvelle) tient la règle, ses faits
   backend, les erreurs typées, le bandeau, l'en-tête, le lien, la revérification et les limites acceptées ; le
   docblock de `sessionAwareFetch.ts` en est le pendant dans le code. Les quatre règles et les docblocks qui la
   réexpliquaient y renvoient en une phrase. Elle allège `frontend-backoffice.md` (27 349 o) et `mcp-oauth.md`
   (39 764 o, la plus proche du plafond de 50 000).
10. **`XSRF-TOKEN` vit comme `BEARER`** (CR3a) : `LoginSuccessSubscriber` lui donne `time() + token_ttl`, lu par
    `#[Autowire(param: 'lexik_jwt_authentication.token_ttl')]`, comme `BaseAccessController` le fait avec son propre
    TTL. *Écarté* : un paramètre `app.session_lifetime` référencé par `token_ttl` et `set_cookies.BEARER.lifetime` (une
    seule source, mais trois fichiers de configuration touchés et les gardes de parité de Lexik à revoir pour un gain
    qu'un test fonctionnel donne déjà : il compare les deux `Expires` réels du login).

### Décisions de l'auteur sur le plan des corrections (2026-10-29)

1. C2 : si le premier repli ne suffit pas, le sélecteur de langue passe dans le menu mobile sous 360 px (tâche 11).
2. Consentement : les liens de reconnexion du corps de page restent dans le même onglet (flux OAuth), « Connexion » de
   l'en-tête en nouvel onglet ; la différence est écrite dans `mcp-oauth.md` (R6/R7).
3. Assistant : « Changer de compte » dans le même onglet, la conversation perdue est acceptée (R7).
4. Libellés : `common.opensInNewTab` FR devient « (s'ouvre dans un nouvel onglet) » (R6) et l'annonce `reauth=1`
   devient neutre (R7).

### Review Focus (corrections)

1. **Passer de FR à EN dans les réglages « À propos » pendant une session finie, puis se reconnecter et enregistrer** :
   le FR n'est jamais montré sous l'EN, rien n'est enregistrable avant la lecture de l'EN, qui arrive au retour. Tests :
   R4, `AdminAboutPage.spec.ts`.
2. **Une proposition de traduction en attente pendant que la lecture de la locale cible tombe en session finie** :
   elle ne se pose pas sur un formulaire non chargé, elle se pose au retour. Test : R4.
3. **Une session qui revient avec `BEARER` mais sans `XSRF-TOKEN`** (session ouverte avant le déploiement de R1) :
   la session reste finie, aucune annonce « rétablie ». Test : R8, `useAuth.spec.ts`.
4. **Un 403 à une écriture qui portait un `XSRF-TOKEN` à la signature périmée** (compte toujours de confiance) :
   `/api/me` répond 200, l'en-tête garde son palier, le message ne dit pas que le compte a perdu son accès. Tests : R7
   (texte neutre exact, `checkAuth` appelé sans changement de palier).
5. **Deux retours sur l'onglet rapprochés pendant une session finie** : une seule relecture de `/api/me` en vol, et
   avec le bandeau affiché, une seule par retour (pas de doublon bandeau + mécanisme partagé). Tests : R8.

### Contraintes propres aux tâches R

- Les **Global Constraints** ci-dessus valent pour chaque tâche R. En plus : **R1 est backend** — lire avec `Read` un
  fichier de `backend/src/Security/Authentication/Infrastructure/Jwt/` avant d'écrire ; test ciblé
  `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis `make back-test` et `make back-quality`, verts ;
  jamais de secret littéral dans un test (`TestCredentials`, `TestKeys::material()`).
- Exécution : un agent par tâche (sonnet, effort high), successifs, dans l'ordre R1 → R10 ;
  `superpowers:test-driven-development` ; un commit par tâche, fichiers nommés ; `git rm` pour une suppression.
- **Refactoring pur** (R2, R3, R5) : le filet est la suite existante, verte **avant** la première modification et
  **après** la dernière ; les seuls tests modifiés sont ceux que la tâche nomme (renommage, contrat qui change exprès),
  et les nouveaux modules ont leur test écrit d'abord et vu rouge (module introuvable).
- Une règle `.claude/rules` touchée reste sous 50 000 octets ; chaque `paths:` ajouté désigne un fichier suivi **dans le
  commit de la tâche** (`tools/check-claude-rules.sh` vert avant le commit) : une tâche n'ajoute à `paths:` que les
  fichiers qui existent à sa fin.
- Vitest 5 vide les imports CSS (même `?raw`) : un CSS se lit par `node:fs` ; un `.vue` ou `.ts` brut par
  `import.meta.glob(…, { query: '?raw', import: 'default', eager: true })`, comme `colorClasses.spec.ts`.
  `ResizeObserver` est absent de jsdom.

### Fichiers (tâches R)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `backend/src/Security/Authentication/Infrastructure/Jwt/LoginSuccessSubscriber.php` | échéance de `XSRF-TOKEN` | R1 |
| `frontend/src/infrastructure/http/sessionAwareFetch.ts` (nouveau, remplace `sessionEnded.ts`) | envoi avec cookies et jeton CSRF, verdict « session finie » | R2 |
| `frontend/src/domain/auth/errors/{SessionEndedError,AccessForbiddenError}.ts` (nouveaux) | les deux erreurs partagées | R2 |
| `.claude/rules/frontend-session.md` (nouvelle), `.claude/CLAUDE.md` (index) | la règle de référence | R2, puis R3 à R9 |
| `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts`, 15 `Http*Repository.ts` du backoffice | le client lève, les dépôts ne traduisent plus | R2, R3 |
| `frontend/src/application/admin/shared/{useAdminLoad,visibleMutationError}.ts` (nouveaux) | taire la session finie, à un endroit | R3, R4 |
| 13 composables d'administration, `useOrderDraft`, `useAdminTranslation`, 12 pages et sections, `OrderToolbar.vue` | branchés sur les deux fonctions ; état « non chargé » | R3, R4 |
| `frontend/src/presentation/ui/useFocusThenAnnounce.ts` (nouveau) | focus, puis annonce différée | R5 |
| `frontend/src/presentation/router/{routeLocale,sessionEnded,reauthentication}.ts`, `frontend/src/presentation/ui/SignInAgainLink.vue` (nouveau) | le lien de reconnexion et celui du changement de compte | R6 |
| `frontend/src/{infrastructure,application}/mcp/*`, `frontend/src/application/assistant/useAssistant.ts`, pages MCP, assistant, consentement | refus réel, retour de la session | R2, R6, R7, R8 |
| `frontend/src/application/auth/useAuth.ts`, `frontend/src/domain/auth/repositories/AuthRepository.ts`, `frontend/src/infrastructure/auth/HttpAuthRepository.ts`, `frontend/src/presentation/layout/{AppLayout.vue,useSessionRecheckOnFocus.ts}` | revérification partagée, compteur de fins | R8, R9 |
| `frontend/src/presentation/ui/admin/SessionEndedBanner.vue` | deux racines, retour effacé, réannonce | R6, R8, R9 |
| `frontend/src/presentation/layout/AccountMenu.vue` | Échap depuis `body` | R10 |

---

### Task R1 : `XSRF-TOKEN` meurt avec `BEARER` au login (CR3a)

**Files :**
- Modify : `backend/src/Security/Authentication/Infrastructure/Jwt/LoginSuccessSubscriber.php`, `backend/config/packages/lexik_jwt_authentication.yaml` (commentaire sur `set_cookies.BEARER.lifetime` seulement)
- Test : `backend/tests/Security/Authentication/AuthCookieAttributesTest.php`
- Modify : `.claude/rules/security-authentication-sessions.md` (puce `LoginSuccessSubscriber`, puce `AuthCookieFactory` : « `bearer()` / `xsrf()` take an optional expiry (session cookie without) »)

**Interfaces :**
- Produces : `LoginSuccessSubscriber::__construct(CsrfCookieTokenSigner $csrfCookieTokenSigner, AuthCookieFactory $authCookieFactory, #[Autowire(param: 'lexik_jwt_authentication.token_ttl')] int $sessionLifetimeSeconds)`.

- [ ] **Step 1 : tests, rouges** — dans `AuthCookieAttributesTest` (même façon de se connecter que le test existant, `TestCredentials`, en-tête `HTTP_X_REQUESTED_WITH`) :
  - `testTheLoginXsrfTokenExpiresWithItsBearer` — cookies lus sur `getResponse()->headers->getCookies()` (jamais dans le jar) : `assertGreaterThan(0, $xsrf->getExpiresTime())` et `assertEqualsWithDelta($bearer->getExpiresTime(), $xsrf->getExpiresTime(), 1.0)` ;
  - `testTheBaseAccessXsrfTokenExpiresWithItsBearer` — même assertion sur `POST /api/account/base-access` (témoin, vert d'emblée).
- [ ] **Step 2 : rouges** — `docker compose exec -u dev backend php bin/phpunit tests/Security/Authentication/AuthCookieAttributesTest.php`. Expected : le premier FAIL, `0` reçu pour l'échéance de `XSRF-TOKEN` ; le témoin PASS.
- [ ] **Step 3 : implémenter** — `xsrf($token, time() + $this->sessionLifetimeSeconds)` ; le commentaire « Cookie de session (pas d'échéance) … » remplacé par le pourquoi : un cookie de session jeté à la fermeture du navigateur pendant que `BEARER`, persistant, survit, faisait de chaque écriture un 403 sans jeton, lu « session finie » en boucle par le frontend (#748, CR3) ; l'accès de base faisait déjà mourir les deux ensemble. Import `Autowire` trié (`make back-style-fix` au besoin). YAML : une ligne au-dessus de `lifetime: 3600` — cette durée doit rester celle de `token_ttl`, que suit `XSRF-TOKEN` ; `AuthCookieAttributesTest` compare les deux échéances réelles.
- [ ] **Step 4 : règle** — `security-authentication-sessions.md` : au login, `XSRF-TOKEN` reçoit l'échéance du JWT (`token_ttl`), comme à l'accès de base ; plus aucun site n'émet de cookie de session ; pourquoi (la boucle ci-dessus) ; le test qui l'épingle. Session ouverte avant le déploiement : son `XSRF-TOKEN` reste un cookie de session, au plus une heure (la durée du JWT).
- [ ] **Step 5 : verts** — même commande PASS, puis `make back-test` et `make back-quality` verts.
- [ ] **Step 6 : commit** — `git add` des trois fichiers et de la règle ; `fix(backend): le XSRF-TOKEN du login expire avec le BEARER (#748)`.

---

### Task R2 : Un seul envoi qui juge la session, deux erreurs partagées, une règle de référence (Std4, Std5, Std6, Std9, S5 ; refactoring pur)

**Files :**
- Create : `frontend/src/infrastructure/http/sessionAwareFetch.ts`, `frontend/tests/infrastructure/http/sessionAwareFetch.spec.ts`, `frontend/src/domain/auth/errors/SessionEndedError.ts`, `frontend/src/domain/auth/errors/AccessForbiddenError.ts`, `.claude/rules/frontend-session.md`
- Delete (`git rm`) : `frontend/src/infrastructure/http/sessionEnded.ts`, `frontend/tests/infrastructure/http/sessionEnded.spec.ts`, `frontend/src/domain/mcp/errors/SessionExpiredError.ts`, `frontend/src/domain/mcp/errors/ConsentForbiddenError.ts`
- Modify : `BackofficeHttpClient.ts` (interne seulement), `infrastructure/mcp/HttpMcpConnectionsRepository.ts`, `infrastructure/mcp/HttpOAuthConsentRepository.ts`, `infrastructure/assistant/HttpAssistantRepository.ts`, `domain/mcp/repositories/{McpConnectionsRepository,OAuthConsentRepository}.ts` (docblocks), `application/mcp/{useMcpConnections,useOAuthConsent}.ts`, `presentation/pages/OAuthConsentPage.vue` (littéral d'échec), `application/assistant/useAssistant.ts` et `presentation/ui/admin/SessionEndedBanner.vue` (docblocks) ; leurs specs, pour les renommages seulement
- Modify : `.claude/CLAUDE.md` (une ligne de l'index), `.claude/rules/frontend-backoffice.md`, `frontend.md`, `mcp-oauth.md`, `mcp-oauth-revocation-personne.md` (renvois)

**Interfaces :**
- Produces (`src/infrastructure/http/sessionAwareFetch.ts`) :
  - `endsTheSession(status: number, sentWithoutCsrfToken: boolean): boolean` — inchangée, déplacée ;
  - `interface SessionAwareResponse { readonly response: Response; readonly endedTheSession: boolean }` ;
  - `sessionAwareFetch(url: string, init: Omit<RequestInit, 'credentials' | 'headers'> & { method: string; headers?: Readonly<Record<string, string>> }): Promise<SessionAwareResponse>` — `credentials: 'include'` ; pour une méthode autre que `GET`/`HEAD`, lit `readCsrfToken()` et ajoute `X-XSRF-TOKEN` s'il est non vide ; `endedTheSession = endsTheSession(status, écriture && !jeton)` ; un rejet de `fetch` (réseau, `AbortError`) remonte tel quel.
- Produces : `class SessionEndedError extends Error` (sans paramètre, `name = 'SessionEndedError'`), `class AccessForbiddenError extends Error` (idem) — `src/domain/auth/errors/`.
- `OAuthConsentFailure` : `'session-expired'` devient `'session-ended'` (seul littéral renommé).

- [ ] **Step 1 : filet** — `make front-test` vert sur la tête, noté.
- [ ] **Step 2 : test du nouveau module, rouge** — `sessionAwareFetch.spec.ts` (cookie posé ou effacé par test comme dans `BackofficeHttpClient.spec.ts`, `fetch` remplacé par `vi.fn`) :
  - le tableau `it.each` d'`endsTheSession`, repris tel quel de `sessionEnded.spec.ts` ;
  - `it("une lecture part avec les cookies et sans en-tête CSRF ; son 403 ne finit pas la session")` — `credentials === 'include'`, aucun `X-XSRF-TOKEN` même cookie posé, `endedTheSession === false` ;
  - `it("une écriture porte l'en-tête lu dans le cookie ; son 403 reste un refus")` ;
  - `it.each([['sans cookie', null], ['cookie vide', '']])("écriture %s : aucun en-tête, et son 403 finit la session")` ;
  - `it('un 401 finit la session, en lecture comme en écriture')` ;
  - `it('les en-têtes donnés sont gardés')` (`Content-Type`, `Accept` présents à côté du jeton) ;
  - `it("une panne réseau ou un abandon remonte tel quel")` — le même objet rejeté (`TypeError`, `DOMException` `AbortError`).
  Run : `docker compose exec frontend npm test -- tests/infrastructure/http/sessionAwareFetch.spec.ts`. Expected : FAIL, module introuvable.
- [ ] **Step 3 : implémenter le module et les deux erreurs** — le docblock de `sessionAwareFetch.ts` devient **l'explication de référence** : la règle, les faits backend **à jour de R1** (à l'expiration du JWT les deux cookies tombent ensemble : une lecture prend 401, une écriture part sans jeton et prend le 403 du CSRF ; déconnexion et refus d'une session antérieure expirent les deux ; CSRF avant le pare-feu, écritures seules), le verdict pris à l'envoi, et **S5** : entre la lecture du cookie et l'envoi, un autre onglet peut poser ou retirer `XSRF-TOKEN` ; le verdict reste juste pour la requête partie (elle a, ou non, porté un jeton) ; au pire un onglet qui vient de se reconnecter lit son 403 en vol comme une fin de session, et la revérification au retour la rétablit — limite acceptée. Il remplace le docblock de `sessionEnded.ts`.
- [ ] **Step 4 : brancher les quatre clients** — chaque dépôt juge en **un seul endroit** (`httpError(judged: SessionAwareResponse)` ou équivalent), dans cet ordre : consentement seul, 401 typé `reauthentication-required` ; puis `judged.endedTheSession` → `SessionEndedError` (l'assistant : raison `unauthenticated`) ; puis le `switch` sur le statut (le consentement : `403` → `AccessForbiddenError`). Plus aucun paramètre `sentWithoutCsrfToken`, plus de `false` littéral. La page MCP garde, dans cette tâche, son 403 en échec générique (R7 le change). `BackofficeHttpClient` : `get`/`mutate` passent par `sessionAwareFetch`, le reste de son contrat (`WeakSet`, `endedTheSession()`) ne change qu'en R3. Renommages : `SessionExpiredError` → `SessionEndedError`, `ConsentForbiddenError` → `AccessForbiddenError`, `'session-expired'` → `'session-ended'` (composable, page, specs).
- [ ] **Step 5 : règle de référence et renvois (Std9)** — `frontend-session.md` (frontmatter `paths:` : `frontend/src/infrastructure/http/**`, `frontend/src/application/auth/**`, `frontend/src/domain/auth/errors/**`, `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts`, `frontend/src/infrastructure/mcp/**`, `frontend/src/infrastructure/assistant/**`, `frontend/src/presentation/ui/admin/SessionEndedBanner.vue`, `frontend/src/presentation/router/sessionEnded.ts`, `frontend/src/presentation/router/reauthentication.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/tests/infrastructure/http/**`, `frontend/tests/presentation/ui/admin/SessionEndedBanner.spec.ts`, `frontend/tests/presentation/layout/usePublishedHeight.spec.ts`, `frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts`) reçoit **déplacée telle quelle** la puce « A 401, or a 403 to a write sent without a CSRF token, mid-page… » de `frontend-backoffice.md`, mise à jour pour `sessionAwareFetch`, les deux erreurs, les faits de R1 et S5 ; puis une section par client (backoffice, MCP, consentement, assistant), une phrase chacun. `frontend-backoffice.md` garde, à la place, deux phrases et le renvoi ; `frontend.md` (paragraphe de l'assistant, puce « A refused session reaches the person in words »), `mcp-oauth.md` (puce « The consent page », la phrase « A 401, or a 403 to the `POST` … ») et `mcp-oauth-revocation-personne.md` (« Slice ») disent leur cas en une phrase et renvoient à `frontend-session.md`. Docblocks : `BackofficeHttpClient`, les trois dépôts, `useMcpConnections`, `useOAuthConsent`, `useAssistant`, `SessionEndedBanner` — une ligne et `voir infrastructure/http/sessionAwareFetch.ts` (`grep -rn "sans jeton CSRF\|endsTheSession" frontend/src` pour n'en oublier aucun). `.claude/CLAUDE.md`, tableau d'index, après `frontend-a11y.md` : `` | `frontend-session.md` | an ended session and a refusal on the frontend: `sessionAwareFetch`, `SessionEndedError` / `AccessForbiddenError`, the banner, the header's link, the re-check on return | touching a call that sends the session cookie, a 401/403 mapping, the banner, a sign-in-again link | ``.
- [ ] **Step 6 : verts** — le spec ciblé PASS ; `make front-test` (même nombre de tests que le filet, plus les nouveaux, moins `sessionEnded.spec.ts` repris), `make front-lint`, `make front-build`, `tools/check-claude-rules.sh` verts.
- [ ] **Step 7 : commit** — fichiers nommés, suppressions par `git rm` ; `refactor(frontend): une seule règle de session finie pour tous les clients HTTP (#748)`.

---

### Task R3 : Le client du backoffice lève la session finie, deux fonctions la taisent (CR9, Std1, Std2, Std4)

**Files :**
- Create : `frontend/src/application/admin/shared/useAdminLoad.ts`, `frontend/src/application/admin/shared/visibleMutationError.ts`, `frontend/tests/application/admin/shared/useAdminLoad.spec.ts`, `frontend/tests/application/admin/shared/visibleMutationError.spec.ts`
- Delete (`git rm`) : `frontend/src/domain/admin/shared/errors/sessionEnded.ts`, `frontend/src/presentation/ui/admin/visibleErrorReason.ts`, `frontend/tests/domain/admin/shared/errors/sessionEnded.spec.ts`, `frontend/tests/presentation/ui/admin/visibleErrorReason.spec.ts`, `frontend/tests/presentation/pages/admin/errorReasonsHideSessionEnded.spec.ts`
- Modify : `BackofficeHttpClient.ts`, les 15 `src/infrastructure/admin/**/Http*Repository.ts`, les 11 unions `Admin*ErrorReason` et `AdminOrderErrorReason`, les 13 composables d'administration, `useOrderDraft.ts`, `useAdminTranslation.ts`, les 12 pages et sections et `OrderToolbar.vue` (tous les appels `visibleErrorReason(`), `src/main.ts`, `src/presentation/router/backofficeSessionExpiry.ts`
- Test : `BackofficeHttpClient.spec.ts`, `tests/infrastructure/admin/sessionEndedMapping.spec.ts`, `tests/application/admin/sessionEndedLoad.spec.ts`, `AdminCaseStudiesPage.spec.ts`, `tests/presentation/router/backofficeSessionExpiry.spec.ts`, `AdminLayout.sessionEnded.spec.ts`, `OrderToolbar.spec.ts`
- Modify : `.claude/rules/frontend-backoffice.md`, `.claude/rules/frontend-session.md`

**Interfaces :**
- Consumes : `sessionAwareFetch`, `SessionEndedError` (R2).
- Produces :
  - `BackofficeHttpClient#get(path: string): Promise<Response>` et `#mutate(method: string, path: string, body?: unknown): Promise<Response>` **rejettent `SessionEndedError`** après avoir appelé la réaction quand `endedTheSession` ; `endedTheSession()` et le `WeakSet` disparaissent ;
  - renommages (Std4) : `BackofficeSessionEndedHandler`, `setBackofficeSessionEndedHandler(handler: BackofficeSessionEndedHandler | null): void`, `onBackofficeSessionEnded(): void` (fichier `backofficeSessionExpiry.ts` gardé) ;
  - `interface AdminLoadState { isLoading: Ref<boolean>; hasError: Ref<boolean> }` et `useAdminLoad<T, K = void>(options: { read: (key: K) => Promise<T>; apply: (value: T) => void }): AdminLoadState & { load: (key: K) => Promise<void> }` — `isLoading` part à `true` ; garde des réponses périmées (`createStaleRequestGuard`) ; `SessionEndedError` ne lève pas `hasError` et ne touche pas la valeur posée ; tout autre rejet lève `hasError` ;
  - `visibleMutationError<E extends Error>(error: unknown, ownError: abstract new (...args: never[]) => E, fallback: () => E): E | null` — `null` pour `SessionEndedError`, l'erreur elle-même si elle est `ownError`, `fallback()` sinon ;
  - chaque `UseAdmin…Result` **étend `AdminLoadState`** (R4 y ajoute un champ sans retoucher les composables).

- [ ] **Step 1 : filet** — `make front-test` vert, noté.
- [ ] **Step 2 : tests, rouges**
  - `useAdminLoad.spec.ts` (hôte monté, comme `sessionEndedLoad.spec.ts`) : `it('pose la valeur lue et baisse isLoading')` ; `it('un échec lève hasError')` ; `it('une session finie ne lève pas hasError et garde la valeur déjà posée')` ; `it('une réponse plus ancienne que le dernier load est jetée')` ; `it('load(clé) passe la clé au lecteur')`.
  - `visibleMutationError.spec.ts` : `AdminCaseStudyError('validation', 'x')` → le même objet ; `new SessionEndedError()` → `null` ; `new Error('x')` → le résultat de `fallback`.
  - `BackofficeHttpClient.spec.ts` (changement exprès du contrat) : `it.each` 401 en lecture, 401 en écriture, 403 en écriture sans cookie, cookie vide → `rejects.toBeInstanceOf(SessionEndedError)` et réaction appelée **une** fois ; 403 en lecture, 403 en écriture avec jeton, 200/204/404/409/422/500 → réponse rendue, réaction non appelée ; les tests d'`endedTheSession` supprimés.
  - `sessionEndedMapping.spec.ts`, simplifié : la table des 15 dépôts reste ; un seul cas `it('%s : la session finie traverse le dépôt telle quelle')` (401 sur la lecture, ou l'écriture pour la traduction ; et `reorder` pour les ordonnés) → `rejects.toBeInstanceOf(SessionEndedError)` ; le test de complétude par glob reste. Les cas « 403 avec jeton garde son mapping » partent (le client les couvre).
  - `sessionEndedLoad.spec.ts` : les rejets deviennent `new SessionEndedError()` ; nouveau `it.each` sur les mutations (`{ name, mutate: (composable) => Promise<void>, error: (composable) => unknown }`, la première mutation des douze composables à `errorMessage`, `translate` d'`useAdminTranslation`, `save` d'`useOrderDraft` après un `move`) : `it('%s : une mutation refusée pour session finie ne pose aucune erreur')` → `null` ; témoin `unknown` → non nul.
  - `AdminCaseStudiesPage.spec.ts` : le cas « session finie à l'enregistrement » rejette `new SessionEndedError()` ; toujours aucune alerte de page.
  Run : `docker compose exec frontend npm test -- tests/application/admin tests/infrastructure/admin tests/presentation/pages/admin/AdminCaseStudiesPage.spec.ts`. Expected : modules introuvables, le client résout au lieu de rejeter, les mutations posent `unknown`.
- [ ] **Step 3 : implémenter** — le client (la réaction, puis `throw new SessionEndedError()`) ; les 25 branches `endedTheSession` et les imports `SESSION_ENDED` retirés des dépôts ; `| SessionEndedReason` retiré des unions et de leurs docblocks ; chaque `load()` devient `useAdminLoad({ read: () => repository.list(), apply: (value) => { liste.value = value } })` (`useAdminAboutSettings` : `read: (locale) => repository.get(locale)`) ; chaque `catch` de mutation : `errorMessage.value = visibleMutationError(error, AdminXError, () => new AdminXError('unknown', 'Unknown error'))` ; `useOrderDraft` et `useAdminTranslation` posent `?.reason ?? null` ; les pages et `OrderToolbar` lisent la raison directement (`visibleErrorReason(x)` → `x`, `shownReason` → `props.errorReason`, `cancelDisabled` compris). `useAdminVulnerabilities` gagne la garde des réponses périmées par le helper (sans effet visible).
- [ ] **Step 4 : règles** — `frontend-backoffice.md` : le client lève `SessionEndedError` ; aucun dépôt ne la traduit ; composables par `useAdminLoad` et `visibleMutationError`, jamais un `catch` qui la convertirait en `unknown` ; un nouveau composable d'administration passe par eux ; la liste des tests à jour (les trois gardes supprimées, pourquoi : la raison n'existe plus). `frontend-session.md` : la section du backoffice et les noms nouveaux. `paths:` de `frontend-backoffice.md` : `frontend/tests/application/admin/sessionEndedLoad.spec.ts` ajouté.
- [ ] **Step 5 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 6 : commit** — `refactor(frontend): la session finie levée par le client du backoffice, tue à un seul endroit (#748)`.

---

### Task R4 : Une liste non chargée n'est pas vide, une clé nouvelle vide l'ancienne, tout se relit au retour (CR2, CR4/D2)

**Files :**
- Modify : `frontend/src/application/admin/shared/useAdminLoad.ts`, `frontend/src/application/admin/about/useAdminAboutSettings.ts` (passe `clear`), `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue`, les 12 pages et sections à état vide (`AdminCaseStudiesPage`, `AdminAnonymousCvPage`, `AdminContributionsPage`, `AdminIncidentsPage`, `AdminQualityPage` ×2, `AdminTechnologiesPage`, `AdminUsersPage`, `AdminWatchPage` ×2, `AdminAboutSiteCardsSection`, `AdminAboutMeCardsSection`), `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`admin.notLoaded`)
- Create : `frontend/tests/presentation/pages/admin/emptyStateNeedsLoaded.spec.ts`
- Test : `useAdminLoad.spec.ts`, `sessionEndedLoad.spec.ts`, `AdminAboutPage.spec.ts`, `AdminCaseStudiesPage.spec.ts`, `AdminWatchPage.spec.ts`
- Modify : `.claude/rules/frontend-backoffice.md`

**Interfaces :**
- Produces :
  - `AdminLoadState.isLoaded: Ref<boolean>` — vrai quand la valeur de la clé courante a été posée ; faux avant la première lecture, après une première lecture en session finie, et dès qu'une clé nouvelle commence à se lire ; posé **après** `apply()` (les observateurs synchrones de la valeur ont déjà copié) ;
  - `options.clear?: () => void` — appelé quand `load(clé)` commence avec une clé différente (`Object.is`) de celle de la valeur détenue ;
  - après une lecture finie sur `SessionEndedError`, la première descente de `authState.sessionEnded` (vrai → faux) relance `load(même clé)` **une** fois (CR4, la relecture au retour).

- [ ] **Step 1 : tests, rouges**
  - `useAdminLoad.spec.ts` : `it("isLoaded : faux avant la première lecture, vrai après, faux encore après une première lecture en session finie")` ; `it("une clé nouvelle vide la valeur de l'ancienne avant de lire")` (`clear` appelé une fois, `isLoaded` faux pendant la lecture) ; `it("la même clé ne vide rien")` ; `it("au retour de la session, la lecture interrompue est relancée une fois, avec sa clé")` (`markSessionExpired()`, puis un compte appliqué par le `login` d'un composant sonde, comme `SessionEndedBanner.spec.ts`) ; `it("au retour, rien n'est relu si la dernière lecture a abouti")`.
  - `sessionEndedLoad.spec.ts` : `it('%s : une première lecture en session finie laisse isLoaded faux')`.
  - `AdminAboutPage.spec.ts` : `it("session finie au passage en EN : ni les champs FR ni « Enregistrer », la mention « non chargé »")` (FR lu, `get('en')` rejette `SessionEndedError` → aucun champ ne porte la valeur FR, texte `admin.notLoaded`, aucun bouton d'envoi des réglages) ; `it('au retour de la session, les réglages EN se lisent et remplissent le formulaire')` (Review Focus 1) ; `it("une proposition en attente ne se pose pas sur un formulaire non chargé, elle se pose au retour")` (Review Focus 2).
  - `AdminCaseStudiesPage.spec.ts` : `it("première lecture en session finie : ni « aucune étude de cas » ni tableau, la mention « non chargé »")` ; `it('au retour de la session, la liste se lit et s\'affiche')`. `AdminWatchPage.spec.ts` : le même premier cas pour les vulnérabilités (`admin.watch.vulnerabilities.empty` absent).
  - `emptyStateNeedsLoaded.spec.ts` : glob brut de `src/presentation/pages/admin/*.vue` ; tout fichier qui contient `.empty')` contient `isLoaded` au moins autant de fois ; message d'échec : la liste des fichiers.
  Run : `docker compose exec frontend npm test -- tests/application/admin tests/presentation/pages/admin`. Expected : `isLoaded` indéfini, la valeur FR affichée, l'état vide affiché, 11 fichiers listés.
- [ ] **Step 2 : implémenter** — le helper (clé détenue, clé en attente après une session finie, `watch(() => authState.sessionEnded, …)` dans le composable appelant, arrêté à son démontage) ; `useAdminAboutSettings` passe `clear: () => { settings.value = null }` ; dans chaque page, avant l'état vide : `v-else-if="!isLoaded"` → `<p class="text-body-secondary mb-0">{{ t('admin.notLoaded') }}</p>` (pas de région vivante : le bandeau annonce) et l'état vide conditionné à `isLoaded` ; `AdminAboutSettingsForm` : le formulaire n'est rendu que si `isLoaded`, et la proposition en attente se pose par `watch(isLoaded, …, { flush: 'sync' })` au lieu d'après `await load()`. i18n : FR « Contenu non chargé : votre session a pris fin. Il se chargera dès que vous serez reconnecté. », EN « Content not loaded: your session has ended. It will load as soon as you are signed in again. ».
- [ ] **Step 3 : règle** — `frontend-backoffice.md` : « non chargé » distinct de « vide », la relecture au retour, la clé qui vide l'ancienne valeur (CR2, et pourquoi : un formulaire d'une locale sous l'en-tête d'une autre, enregistré par-dessus) ; la garde `emptyStateNeedsLoaded.spec.ts`.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`.
- [ ] **Step 5 : commit** — `fix(frontend): une liste non chargée n'est plus dite vide, et se lit au retour de la session (#748)`.

---

### Task R5 : « Focus puis annonce » dans un composable ; « Réessayer » efface l'issue annoncée (Std8, CR10)

**Files :**
- Create : `frontend/src/presentation/ui/useFocusThenAnnounce.ts`, `frontend/tests/presentation/ui/useFocusThenAnnounce.spec.ts`
- Modify : `frontend/src/presentation/pages/McpConnectionsPage.vue`
- Test : `frontend/tests/presentation/pages/McpConnectionsPage.spec.ts`
- Modify : `.claude/rules/frontend-a11y.md` (puce « A gesture that moves the focus and announces an outcome »)

**Interfaces :**
- Produces : `ANNOUNCE_AFTER_FOCUS_MS = 250` ; `useFocusThenAnnounce<T>(delayMs: number = ANNOUNCE_AFTER_FOCUS_MS): { announced: Readonly<Ref<T | null>>; focusThenAnnounce: (target: HTMLElement | null | undefined, value: T) => void; cancel: () => void; isUnmounted: () => boolean }` — `focusThenAnnounce` annule l'annonce en cours, focalise la cible tout de suite, écrit `value` après le délai ; `cancel` annule la minuterie et vide `announced` ; au démontage : `cancel()` et `isUnmounted()` vrai.

- [ ] **Step 1 : filet** — `docker compose exec frontend npm test -- tests/presentation/pages/McpConnectionsPage.spec.ts` vert, noté.
- [ ] **Step 2 : tests, rouges** — `useFocusThenAnnounce.spec.ts` (minuteries simulées) : `it('focalise la cible tout de suite et annonce après le délai')` ; `it("cancel vide l'annonce et annule la minuterie")` (`vi.getTimerCount() === 0`) ; `it("le démontage annule, et isUnmounted devient vrai")` ; `it('un second appel remplace le premier')`. Page : `it("« Réessayer » efface l'issue d'une révocation déjà annoncée")` — révocation réussie, relecture qui suit en échec ; minuteries avancées : la région dit « révoquée » ; clic sur « Réessayer » → la région ne contient plus « révoquée », pendant et après la relecture (CR10). Expected : module introuvable ; « révoquée » encore lue.
- [ ] **Step 3 : implémenter** — le composable ; la page perd `announcedOutcome`, `announceTimer`, `unmounted`, `cancelAnnouncement` et son `onBeforeUnmount` ; `statusMessage` lit `announced` ; `onRevoke` appelle `cancel()` puis `focusThenAnnounce(heading.value, outcome)` ; `onRetry` commence par `cancel()` ; les deux sortent si `isUnmounted()` après leurs `await`.
- [ ] **Step 4 : règle** — `frontend-a11y.md` : le patron a une implémentation, `useFocusThenAnnounce` ; une page qui en a besoin l'appelle ; un geste qui relance une lecture vide l'annonce précédente.
- [ ] **Step 5 : verts** — specs ciblés PASS (le filet sans changement) ; `make front-test`, `make front-lint`, `make front-build`.
- [ ] **Step 6 : commit** — `refactor(frontend): « focus puis annonce » dans un composable, « Réessayer » efface l'issue annoncée (#748)`.

---

### Task R6 : Un seul lien de reconnexion ; aucune route protégée ne porte de secret (Std3, Std7, C2 commentaire, S6)

**Files :**
- Create : `frontend/src/presentation/router/routeLocale.ts`, `frontend/src/presentation/ui/SignInAgainLink.vue`, `frontend/tests/presentation/router/signInLocations.spec.ts`, `frontend/tests/presentation/ui/SignInAgainLink.spec.ts`, `frontend/tests/presentation/router/protectedRoutesCarryNoSecret.spec.ts`
- Modify : `frontend/src/presentation/router/sessionEnded.ts`, `frontend/src/presentation/router/reauthentication.ts`, `SessionEndedBanner.vue`, `AppHeader.vue` (le lien, `homeLink` par `routeLocale`, le commentaire de la marque), `McpConnectionsPage.vue`, `AssistantPage.vue`, `OAuthConsentPage.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`
- Test : `SessionEndedBanner.spec.ts`, `AppHeader.spec.ts`, `McpConnectionsPage.spec.ts`, `AssistantPage.spec.ts`, `OAuthConsentPage.spec.ts`, `AppFooter.spec.ts:110`, `BaseButton.spec.ts:122`
- Modify : `.claude/rules/frontend-session.md`, `.claude/rules/frontend-backoffice.md` (« Route protection »), `.claude/rules/frontend-a11y.md` (puce du bandeau)

**Interfaces :**
- Produces :
  - `routeLocale(route: { params: Readonly<Record<string, unknown>> }, fallback: Locale): Locale` — le paramètre `locale` de la route s'il est une locale prise en charge, sinon `fallback` ;
  - `signInAgainLocation(route: Pick<RouteLocationNormalizedLoaded, 'fullPath' | 'params'>, fallback: Locale): RouteLocationRaw` = `{ name: 'login', params: { locale }, query: { redirect: route.fullPath, [SESSION_ENDED_QUERY]: SESSION_ENDED_VALUE } }` ;
  - `switchAccountLocation(route, fallback): RouteLocationRaw` = même forme, `[REAUTH_QUERY]: REAUTH_VALUE` au lieu d'`expired` ;
  - `SignInAgainLink.vue` : aucune prop, l'emplacement par défaut est le libellé visible ; une seule racine `RouterLink` (les classes passées s'y posent), `target="_blank"`, `rel="noopener"`, `<span class="visually-hidden">{{ t('common.opensInNewTab') }}</span>`, `IconExternalLink` 14 px `aria-hidden="true"`.

- [ ] **Step 1 : tests, rouges**
  - `signInLocations.spec.ts` : `it('signInAgainLocation : la langue de la route, le chemin complet en retour, expired=1')` (`/en/admin/x?a=1#f` → `{ name: 'login', params: { locale: 'en' }, query: { redirect: '/en/admin/x?a=1#f', expired: '1' } }`) ; `it('sans langue dans la route, la langue de repli')` ; `it('switchAccountLocation : reauth=1')` ; `it("aucun autre fichier ne compose ces liens")` — glob brut de `src/**/*.{vue,ts}` : `SESSION_ENDED_QUERY` et `REAUTH_QUERY` n'apparaissent hors de leur module que dans `LoginPage.vue` (lecture) et dans les deux constructeurs.
  - `SignInAgainLink.spec.ts` : `href` `/fr/login?redirect=…&expired=1`, `target`, `rel`, texte « Se reconnecter (s'ouvre dans un nouvel onglet) » en FR et « … (opens in a new tab) » en EN, `svg[aria-hidden="true"]`, une classe passée posée sur le lien ; axe vert.
  - `McpConnectionsPage.spec.ts` : `it("session finie : le lien de reconnexion s'ouvre dans un nouvel onglet et ramène ici")`. `AssistantPage.spec.ts` : `it("session finie : « Se reconnecter » ouvre un nouvel onglet, expired=1, retour à l'assistant")` (le lien d'avant, même onglet sans `expired`, change exprès). `OAuthConsentPage.spec.ts` : le lien de session finie porte `expired=1`, sans `target` (même onglet, exprès).
  - `AppFooter.spec.ts` et `BaseButton.spec.ts` : « (s'ouvre dans un nouvel onglet) » au lieu de « (nouvelle fenêtre) ».
  - `protectedRoutesCarryNoSecret.spec.ts` (S6 ; lit `router.getRoutes()` du routeur de l'application, en lecture seule) : `REVIEWED_PROTECTED_ROUTES: Record<string, string>`, nom de route → pourquoi sa requête et son fragment ne portent aucun secret ; `it('chaque route protégée est revue')` → les noms des routes `meta.requiresAuth` égalent les clés (une route ajoutée sans revue fait échouer, et le message dit pourquoi : son `fullPath` part dans `redirect`, donc dans l'URL d'un nouvel onglet que le serveur du frontend journalise) ; `it("set-password, qui porte un secret dans son fragment, n'est pas protégée")`. Pour `oauth-consent` : `client_id`, `redirect_uri`, `state`, `code_challenge` ne sont pas des secrets (le défi PKCE est un condensat, `state` la valeur anti-CSRF du client) et la navigation qui a ouvert la page les a déjà envoyés au serveur du frontend.
  Expected : modules introuvables ; liens d'avant ; « (nouvelle fenêtre) ».
- [ ] **Step 2 : implémenter** — les modules ; le bandeau, l'en-tête, la page MCP et l'assistant rendent `<SignInAgainLink>` (classes du bouton gardées dans l'en-tête) ; le consentement construit ses deux liens par `signInAgainLocation`/`switchAccountLocation` dans `RouterLink` sans `target` ; i18n : `common.opensInNewTab` FR « (s'ouvre dans un nouvel onglet) », `admin.sessionEnded.newTab` retiré des deux fichiers ; `AppHeader` : commentaire de la marque corrigé (C2) — « À 320 px, le groupe de droite demandait 331 px au palier de confiance et 389 px en anonyme et en EN, pour 301 utiles (mesures de #748) : la marque se tronque la première ; si le groupe seul dépasse encore, voir la tâche 11 ».
- [ ] **Step 3 : règles** — `frontend-session.md` : le lien, ses constructeurs, le composant, les sites en nouvel onglet et l'exception du consentement (raison du choix 4) ; `frontend-backoffice.md`, « Route protection » : l'invariant « aucune route `requiresAuth` ne porte de secret dans sa requête ou son fragment » (S6), pourquoi, la garde ; `frontend-a11y.md` : la puce du bandeau renvoie à `SignInAgainLink`. `paths:` de `frontend-session.md` : les fichiers créés.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `refactor(frontend): un seul lien de reconnexion, et aucune route protégée ne porte de secret (#748)`.

---

### Task R7 : Un refus réel relit la session et propose de changer de compte (CR5, S3, S4, S8)

**Files :**
- Modify : `frontend/src/infrastructure/mcp/HttpMcpConnectionsRepository.ts` (403 → `AccessForbiddenError`), `frontend/src/application/mcp/useMcpConnections.ts`, `McpConnectionsPage.vue`, `frontend/src/application/assistant/useAssistant.ts`, `AssistantPage.vue`, `frontend/src/application/mcp/useOAuthConsent.ts`, `OAuthConsentPage.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`
- Test : `HttpMcpConnectionsRepository.spec.ts`, `useMcpConnections.spec.ts`, `McpConnectionsPage.spec.ts`, `useAssistant.spec.ts`, `AssistantPage.spec.ts`, `useOAuthConsent.spec.ts`, `OAuthConsentPage.spec.ts`, `LoginPage.spec.ts`
- Modify : `.claude/rules/frontend.md`, `mcp-oauth.md`, `mcp-oauth-revocation-personne.md`, `frontend-session.md`

**Interfaces :**
- Consumes : `AccessForbiddenError` (R2), `switchAccountLocation` (R6).
- Produces : `type McpRefusal = 'session-ended' | 'forbidden'` ; `UseMcpConnectionsResult.refusal: DeepReadonly<Ref<McpRefusal | null>>` **remplace `sessionExpired`**, remis à `null` au début de chaque `load()` et `revoke()`. Les trois composables appellent `useAuth().checkAuth()` une fois sur un refus (`AccessForbiddenError`, ou la raison `forbidden` de l'assistant), sans l'attendre pour poser leur état, un rejet étant avalé ; jamais `markSessionExpired()` sur un refus.

- [ ] **Step 1 : tests, rouges** (les tests qui fournissent `useAuth` reçoivent `AUTH_REPOSITORY` avec un `me` espionné)
  - Dépôt MCP (changement exprès) : `it('list : un 403 est AccessForbiddenError')`, `it('revoke : un 403 avec jeton est AccessForbiddenError')` ; le 403 sans jeton reste `SessionEndedError`.
  - `useMcpConnections.spec.ts` : `it("lecture refusée : refusal 'forbidden', /api/me relu une fois, la session n'est pas marquée finie")` ; `it("révocation refusée avec jeton : refusal 'forbidden', la liste reste")` ; `it("session finie : refusal 'session-ended'")`.
  - `McpConnectionsPage.spec.ts` : `it("refus : message neutre, « Changer de compte » vers login?reauth=1&redirect=<ce chemin>, aucun « Réessayer », tous les « Révoquer » désactivés")` en FR et EN, axe vert ; `it('refus à la révocation : le focus passe au titre')`.
  - `useAssistant.spec.ts` : `it("forbidden : /api/me relu une fois, la session n'est pas marquée finie")`. `AssistantPage.spec.ts` : `it("refus : message neutre et « Changer de compte » (reauth=1, retour à l'assistant)")`.
  - `useOAuthConsent.spec.ts` : `it("forbidden : /api/me relu une fois")`. `OAuthConsentPage.spec.ts` : le message de `forbidden` est `common.accessRefused`.
  - Texte exact (Review Focus 4, S8) dans les tests de page : FR « Le serveur refuse cette demande à votre session : votre compte n'a peut-être pas, ou plus, l'accès de confiance, ou la session doit être rouverte. Changez de compte, ou reconnectez-vous au même, pour continuer. », EN « The server refuses this request to your session: your account may not have, or may no longer have, trusted access, or the session needs to be reopened. Switch account, or sign in again to the same one, to continue. ».
  - `LoginPage.spec.ts` : l'annonce `reauth=1` est FR « Confirmez votre identité ou connectez-vous avec un autre compte : vous reviendrez ensuite à la page que vous quittiez. », EN « Confirm your identity or sign in with another account: you will then return to the page you left. ».
  Run : `docker compose exec frontend npm test -- tests/infrastructure/mcp tests/application/mcp tests/application/assistant tests/presentation/pages`. Expected : `McpConnectionsRequestFailedError` reçu, `refusal` indéfini, `me` jamais appelé, anciens textes.
- [ ] **Step 2 : implémenter** — la page MCP : `refusal === 'session-ended'` → alerte et `SignInAgainLink` ; `'forbidden'` → alerte `common.accessRefused` et `RouterLink :to="switchAccountLocation(…)"` libellé `common.switchAccount` ; sinon l'échec générique et « Réessayer » ; « Révoquer » désactivé si `null !== refusal` ; focus au titre sur tout refus. Assistant : `forbidden` → `checkAuth()`, la page montre le même message et le même lien. Consentement : `AccessForbiddenError` → `checkAuth()` en plus de l'échec terminal `forbidden`. i18n : `common.accessRefused` (texte ci-dessus), `common.switchAccount` (le libellé de `oauthConsent.account.switch` déplacé, la clé ancienne retirée et ses lecteurs mis à jour), `assistant.errors.forbidden` et `oauthConsent.errors.forbidden` retirés, `auth.reauthNotice` remplacé (textes ci-dessus).
- [ ] **Step 3 : règles** — `frontend.md` : les deux limites acceptées retirées (l'en-tête d'un compte rétrogradé ; le `GET` en 403 de la page MCP) et remplacées par la resynchronisation et le lien ; `mcp-oauth.md`, puce « The consent page » : le message neutre (S8), `/api/me` relu, la phrase « Accepted: the login page opened by `reauth=1` then shows `auth.reauthNotice` (« authorizing an MCP connection… ») » remplacée par l'annonce neutre et pourquoi ; `mcp-oauth-revocation-personne.md` (« Slice », « The page ») : `refusal` ; `frontend-session.md` : une section « Un refus réel », le fait vérifié (rôles relus en base : fournisseur `cpg_users`, `JWTAuthenticator::createToken`, `access_control ^/api/me`).
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh` (taille de `mcp-oauth.md`).
- [ ] **Step 5 : commit** — `fix(frontend): un refus réel relit la session et propose de changer de compte (#748, #732)`.

---

### Task R8 : La session finie se revérifie au retour, sur toutes les pages protégées, et seulement avec un jeton d'écriture (CR7, CR3b)

**Files :**
- Create : `frontend/src/presentation/layout/useSessionRecheckOnFocus.ts`, `frontend/tests/presentation/layout/useSessionRecheckOnFocus.spec.ts`
- Modify : `frontend/src/domain/auth/repositories/AuthRepository.ts`, `frontend/src/infrastructure/auth/HttpAuthRepository.ts`, `frontend/src/application/auth/useAuth.ts`, `frontend/src/presentation/layout/AppLayout.vue`, `SessionEndedBanner.vue` (écouteur retiré), `useMcpConnections.ts`, `useOAuthConsent.ts`, `useAssistant.ts`
- Test : `useAuth.spec.ts`, `HttpAuthRepository.spec.ts`, `AppLayout.spec.ts`, `AppHeader.spec.ts`, `SessionEndedBanner.spec.ts`, `useMcpConnections.spec.ts`, `useOAuthConsent.spec.ts`, `useAssistant.spec.ts`, et chaque faux `AuthRepository` des specs (14 fichiers, `grep -rln "me: vi.fn" frontend/tests`)
- Modify : `.claude/rules/frontend-session.md`

**Interfaces :**
- Produces :
  - `AuthRepository.hasWriteToken(): boolean` — vrai quand un cookie `XSRF-TOKEN` non vide est lisible (`HttpAuthRepository`, par `readCsrfToken()`) ;
  - `UseAuthResult.recheckEndedSession(): Promise<void>` — sans effet hors session finie ; une seule relecture en vol (un second appel pendant la première rend la même promesse) ; `me()` ; un compte retrouvé **et** `hasWriteToken()` → la session est appliquée (`sessionEnded` baisse) ; sinon l'état ne change pas ; un rejet de `me()` est avalé ;
  - `useSessionRecheckOnFocus(): void` — écoute `focus` sur `window` du montage au démontage et appelle `recheckEndedSession()` ; appelé une fois, dans `AppLayout.vue` ;
  - réactions au retour (`authState.sessionEnded` vrai → faux) : `useMcpConnections` relit si `refusal === 'session-ended'` ; `useOAuthConsent` appelle `reload()` si `failure === 'session-ended'` ; `useAssistant` remet `error` à `null` et `state` à `'idle'` si la raison était `unauthenticated` (le brouillon reste).

- [ ] **Step 1 : tests, rouges**
  - `useAuth.spec.ts` : `it("un compte retrouvé avec son jeton d'écriture rétablit la session")` ; `it("un compte retrouvé sans XSRF-TOKEN : la session reste finie")` (Review Focus 3, régression de CR3b — vu rouge contre le code actuel, qui la rétablit) ; `it("hors session finie, aucune relecture")` ; `it('deux appels rapprochés : une seule relecture en vol')` (Review Focus 5) ; `it('une panne réseau laisse la session finie, sans rejet')`.
  - `HttpAuthRepository.spec.ts` : `hasWriteToken` vrai avec `XSRF-TOKEN=x`, faux sans cookie et avec `XSRF-TOKEN=`.
  - `useSessionRecheckOnFocus.spec.ts` : `focus` → `recheckEndedSession` appelée ; plus rien après le démontage.
  - `AppLayout.spec.ts` : `it("un retour sur l'onglet, bandeau affiché : une seule relecture de /api/me")` (route d'administration, `AdminLayout` et le bandeau montés sous `AppLayout`, `markSessionExpired()`, un `focus` → `me` appelé une fois) ; `it("la revérification vaut hors du backoffice")` (route `mcp-connections`).
  - `AppHeader.spec.ts` (CR7, première moitié, déjà en place, à épingler) : `it.each(['mcp-connections', 'assistant', 'oauth-consent'])("%s : session finie, « Connexion » ouvre un nouvel onglet qui ramène ici")`.
  - Réactions : `useMcpConnections.spec.ts` `it('au retour de la session, la liste se relit')` ; `useOAuthConsent.spec.ts` `it('au retour de la session, la demande se relit')` ; `useAssistant.spec.ts` `it("au retour de la session, l'erreur de session s'efface et le brouillon reste")`.
  - `SessionEndedBanner.spec.ts` : les tests de relecture au `focus` partent vers `useAuth.spec.ts` ; ceux de « Session rétablie » déclenchent le retour par `recheckEndedSession()` ; `it("le bandeau n'écoute plus le focus lui-même")` (bandeau seul, `focus` → `me` non appelé).
  Expected : `hasWriteToken`/`recheckEndedSession` absents, session rétablie sans jeton, deux relectures avec le bandeau, aucune relecture hors backoffice.
- [ ] **Step 2 : implémenter** — chaque faux `AuthRepository` reçoit `hasWriteToken: () => true` (le comportement d'avant pour ces specs) ; le reste selon les interfaces ; `checkAuth()` (chargement initial) ne change pas.
- [ ] **Step 3 : règle** — `frontend-session.md` : la revérification partagée et son unique point d'installation, la condition du jeton d'écriture (pourquoi : un compte retrouvé sans jeton prend un 403 à la première écriture), les réactions au retour par page, l'en-tête en nouvel onglet sur toute route `requiresAuth` (épinglé par route). `paths:` : les fichiers créés.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): la session finie se revérifie au retour sur toutes les pages protégées (#748)`.

---

### Task R9 : Le bandeau colle vraiment, n'ajoute aucun espace, s'efface et se réannonce comme il faut (CR1/C1, D1, CR6, S7)

**Files :**
- Modify : `frontend/src/presentation/ui/admin/SessionEndedBanner.vue`, `frontend/src/application/auth/useAuth.ts` (`sessionEndedCount`)
- Test : `AdminLayout.sessionEnded.spec.ts`, `SessionEndedBanner.spec.ts`, `useAuth.spec.ts`
- Modify : `.claude/rules/frontend-session.md`, `.claude/rules/frontend-a11y.md` (puce « A live region is announced when it is *inserted* »)

**Interfaces :**
- Produces : `UseAuthResult.sessionEndedCount: ComputedRef<number>` — augmenté de un par chaque `markSessionExpired()`.

- [ ] **Step 1 : tests, rouges**
  - `AdminLayout.sessionEnded.spec.ts` (jsdom ne calcule pas le collage : le test prouve la structure qui le permet) : `it("le bandeau est un enfant direct de la section qui contient la page : il colle sur toute sa hauteur")` — `[data-testid="admin-session-ended"]`, `parentElement` = la `<section>` d'`AdminLayout`, qui contient aussi l'élément de la page de la route ; `it("session active : le bandeau n'ajoute à la section aucun élément dans le flux")` (D1) — parmi les enfants directs de la section, le seul venu du bandeau est `[data-testid="admin-session-restored"]`, de classe `visually-hidden`.
  - `SessionEndedBanner.spec.ts` (monté dans un composant hôte : deux racines) : `it("une session qui reprend fin efface « Session rétablie »")` (CR6 : fin, retour, message ; `markSessionExpired()` → région vide) ; `it("un nouveau refus pendant que le bandeau est affiché le réinsère, donc le réannonce")` (S7 : l'élément `[role="alert"]` d'après n'est pas celui d'avant) ; `it('plusieurs refus dans le même tick : une seule réinsertion')`.
  - `useAuth.spec.ts` : `it('chaque fin de session augmente sessionEndedCount')`.
  Expected : parent = le `<div>` racine, un `<div>` de plus dans la section, message gardé, même élément d'alerte.
- [ ] **Step 2 : implémenter** — gabarit à deux racines : l'alerte (`v-if`, `ref="banner"`, `:key="sessionEndedCount"`) et la région `status` ; `watch(hasSessionEnded, (isEnded, wasEnded) => { if (isEnded) hasRestored.value = false; else if (wasEnded) hasRestored.value = true })` ; vérifier que `usePublishedHeight` suit l'élément réinséré (sa `ref` change, l'observateur suit la cible). Docblock : pourquoi deux racines (le bloc contenant du `sticky`), choix 7.
- [ ] **Step 3 : règles** — `frontend-session.md` : deux racines et pourquoi, la réinsertion à chaque refus (S7), le message de retour effacé par une nouvelle fin ; `frontend-a11y.md` : réinsérer une alerte (changement de `key`) pour la réannoncer.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`.
- [ ] **Step 5 : commit** — `fix(frontend): le bandeau de session finie colle vraiment, sans espace vide, et se réannonce (#748)`.

---

### Task R10 : Échap rend le focus au bouton du menu depuis `body` (CR8)

**Files :**
- Modify : `frontend/src/presentation/layout/AccountMenu.vue:80-91`
- Test : `frontend/tests/presentation/layout/AccountMenu.spec.ts`
- Modify : `.claude/rules/mcp-oauth-revocation-personne.md` (« Desktop account menu »)

- [ ] **Step 1 : test, rouge** — `it("Échap, le focus sur body (Safari après un clic) : le focus revient au bouton")` — menu ouvert au clic, `(document.activeElement as HTMLElement).blur()` (l'élément actif devient `body`), Échap → `document.activeElement` est le bouton. Le test « Échap ailleurs ferme sans prendre le focus » (Review Focus 5 du plan d'origine) reste vert. Expected : FAIL, `body`.
- [ ] **Step 2 : implémenter** — `const active = document.activeElement` ; le focus est rendu si `active` est dans le conteneur, ou `null`, ou `document.body` (personne d'autre ne le détient).
- [ ] **Step 3 : règle** — « Escape returns the focus to the button only if it was inside the menu » devient « … inside the menu, or held by nobody (`body`, Safari after a click) ».
- [ ] **Step 4 : verts** — spec PASS ; `make front-test`, `make front-lint`, `make front-build`.
- [ ] **Step 5 : commit** — `fix(frontend): Échap rend le focus au bouton du menu du compte quand personne ne le tient (#748)`.

---

## Corrections de la deuxième relecture (2026-10-29)

R1 à R10 sont faites (a24369c9 → c6a7ba3b), R11 hors plan (be0b8d8d : `requestedLocale` dans
`useAdminAboutSettings`). Quatre relectures du diff 0040068c...be0b8d8d (`/code-review medium` A–J, audit de sécurité
F1–F13, axe Standards St*, axe Spec Sp*) ont retenu les constats ci-dessous ; **tous sont traités dans cette branche**,
par les tâches R12 à R22, avant les tâches 11 et 12 — sauf F12 (jeton CSRF lié ni à la session ni au temps), qui part
dans le futur ticket `__Host-` (tâche 12).

**Décisions de l'auteur (2026-10-29)**, non rediscutées ici :
1. **F1** : `BEARER` (`set_cookies.BEARER.lifetime: 0`) et `XSRF-TOKEN` deviennent des cookies de session, au login
   comme à l'accès de base ; le JWT garde `exp` = `token_ttl`. Le constat H est absorbé, le `time()` de
   `LoginSuccessSubscriber` aussi (plus d'échéance à calculer).
2. **F4** : un vrai 403 du backoffice est traité comme R7 (relecture de `/api/me`, `common.accessRefused`, « Changer de
   compte »).
3. **F3/D** : retenir le compte dont la session a fini ; « Session rétablie » en place seulement si le même compte
   revient ; sinon appliquer la session et rejouer la navigation.
4. Tout le reste est corrigé ici ; F12 seul est hors périmètre.

### Vérification de chaque constat dans le code (HEAD be0b8d8d)

- **A, confirmé.** `useMcpConnections.ts:171-178` relit au retour ; `load()` remet `refusal` à `null` mais pas
  `lastOutcome` ; `McpConnectionsPage.vue:267` affiche l'alerte d'échec sur `'failed' === lastOutcome && null === refusal`.
- **B = F2, confirmé.** `router/index.ts`, `beforeEnter` de `login` : `authState.user && !isReauthentication(to)` renvoie à
  l'accueil ; `signInAgainLocation` ne pose que `expired=1`.
- **C, confirmé.** `BackofficeHttpClient.react()` appelle la réaction sur tout verdict « session finie », sans savoir si la
  session a été rétablie depuis l'envoi. **F5, confirmé** : `applySession()` (via `checkAuth()`) abaisse `sessionEnded`
  dès qu'un compte revient, sans `hasWriteToken()` ; `recheckEndedSession()` ne compare rien à l'arrivée.
- **E, confirmé.** `SessionEndedBanner.vue:85-95` : la `key` est sur le `<p role="alert">` qui contient le lien.
- **Sp-c1, confirmé.** `markSessionExpired()` incrémente `sessionEndedCount` à chaque appel ; des 401 de lectures
  parallèles arrivés dans des ticks distincts réinsèrent l'alerte autant de fois.
- **F, confirmé.** `useMcpConnections.ts:108`, `useAssistant.ts:138`, `useOAuthConsent.ts:122` : `void checkAuth()` ; un
  `/api/me` en 401 applique `ANONYMOUS_SESSION` sans lever `sessionEnded` ; `load` et `revoke` en lancent chacun un.
- **G, confirmé.** `AccountMenu.vue` : `focusWasFree` vaut aussi quand l'élément focalisé ailleurs vient d'être retiré.
- **F7, confirmé.** `AppHeader.vue:172,368` : `sessionEndedHere` vaut sur `oauth-consent` (`requiresAuth`), « Connexion »
  y est `SignInAgainLink`, nouvel onglet.
- **F8, confirmé, latent.** `SignInAgainLink` pose toujours `redirect` ; aujourd'hui aucun site ne le rend hors route
  `requiresAuth` (bandeau, en-tête sous `sessionEndedHere`, pages MCP et assistant), d'où la sévérité info.
- **F10 (1) et (2), confirmés** ; **Sp-c3/St, confirmé** : `requestedLocale` double la clé détenue de `useAdminLoad`.
- **F11, confirmé.** `AdminAboutSettingsForm.vue:126`.
- **F13, confirmé.** `csrfCookie.ts:14` : `decodeURIComponent('%E0')` lève `URIError` ; `sessionAwareFetch` la laisse
  remonter avant tout envoi, chaque écriture échoue.
- **I, J, `SessionAwareInit`, `ApiProblemBody`, confirmés** (`reauthentication.ts:30-39` vs `sessionEnded.ts:35-44` ;
  `HttpAuthRepository.ts:97-101` et `sessionAwareFetch.ts:61-72` ; `HttpMcpConnectionsRepository.ts:64`,
  `HttpOAuthConsentRepository.ts:109` ; imports de `admin/shared` dans les deux dépôts MCP).
- **Documentation, confirmée** : `frontend-session.md:288` (« the re-read on `focus` »), `:38` (« Later tasks… »),
  `paths:` incomplets (tous les fichiers cités existent et sont suivis) ; docblocks de `useFocusThenAnnounce.ts`
  (`isUnmounted` omis), `reauthentication.ts`, `sessionEnded.ts`, `useMcpConnections.ts:170`, `useOAuthConsent.ts:199`,
  `backofficeSessionExpiry.ts`, `main.ts` ; `frontend-a11y.md:78` ; clé `oauthConsent.errors.sessionExpired`
  (`OAuthConsentPage.vue:75`) ; `AppHeader.vue:235` (« la tâche 11 du plan de #748 »).
- **Sp-b1, écarté après vérification** : `frontend-session.md` (« It is silent: `isChecking` is never touched »,
  l. 210-214) écrit déjà que seule la première vérification lève `isChecking`, que `waitForAuthCheck` attend celle-là,
  et pourquoi (le scintillement de l'en-tête) ; la phrase contraire est dans le plan figé (R8), pas dans une règle.
- **Sp-b2, écarté après vérification** : l'invariant est déjà écrit dans `frontend-session.md` (« A mutation resolves
  to `true` only when it succeeded », l. 114-117) et dans `frontend-backoffice.md` (puce « A 401, or a 403… » : « its
  mutations resolve to `true` only on success »).
- **Constat nouveau, tiré de la décision 1 (vérifié)** : `StaleSessionCookiesClearingListener` n'efface les cookies
  que pour le refus d'une session antérieure au changement de mot de passe ; son docblock le justifie par « un jeton
  expiré ne pose pas le problème — le cookie expire avec lui ». Avec des cookies de session, un `BEARER` expiré reste
  jusqu'à la fermeture du navigateur, et le pare-feu `api` authentifie dès qu'un cookie est présent, **route publique
  comprise** (`JWTAuthenticator::onAuthenticationFailure` rend un 401) : tout `/api`, « À propos » et le contact
  compris, répondrait 401 pendant toute la session du navigateur. R21 étend donc l'effacement à `JWT_EXPIRED` et à tout
  `JWT_INVALID`. Reste un 401 unique par cookie mort (question ouverte du rapport).
- **Backend, vérifié** : Lexik accepte `lifetime: 0` (`LexikJWTAuthenticationExtension` : `$attributes['lifetime'] ??
  …`, 0 n'est pas `null` ; `JWTCookieProvider::createCookie()` : `0 === $this->defaultLifetime ? 0`, donc
  `Expires` absent). `BaseAccessControllerTest.php:74` compare l'échéance du cookie à `expiresAt` : elle change de sens.

### Choix de conception tranchés

1. **Une génération de session** (C, F5, Sp-c1). `useAuth` tient un compteur de module, `sessionStamp()`, avancé à
   chaque fin acceptée et à chaque retour. Un appelant le lit à l'envoi et le rend avec le verdict :
   `markSessionExpired(sentAt)` et la relecture de `/api/me` ignorent ce qui est parti sous une génération périmée.
   *Avantages* : un seul nombre règle les deux sens (une fin d'avant le retour, un retour d'avant une fin) et, en
   prime, Sp-c1 (les lectures parallèles du chargement partent sous la même génération : la première fin compte, les
   suivantes ne réannoncent pas). *Écartés* : annuler toutes les requêtes en vol au retour (`AbortController` global :
   chaque client à rebrancher, et une écriture annulée a pu aboutir côté serveur) ; un horodatage (deux événements dans
   la même milliseconde).
2. **Une seule descente de `sessionEnded`**, interne à `useAuth` : un compte, un jeton d'écriture, une génération
   courante. `applySession()` n'abaisse plus rien ; la connexion dans l'onglet abaisse sans rien annoncer (la page de
   connexion navigue elle-même).
3. **Deux signaux de retour, pas un `watch` par page** : `onSessionRestored(cb)` (le même compte, rétabli en place) et
   `onAccountSwitched(cb)` (un autre compte), enregistrés dans un `Set` de module et retirés à `onScopeDispose`. Le
   rejeu est fait une fois, par `AppLayout` : `router.replace({ …, force: true })` (la garde juge le nouveau compte ;
   vue-router 5.4 a `force`, `index-*.d.ts:2294`) puis une `key` sur le composant de `RouterView` (sans elle, le même
   chemin garde la même instance : l'assistant garderait la conversation du premier compte). *Écarté* :
   `window.location.reload()` (perd l'état de l'application entière et refait le premier `checkAuth`).
4. **« Relire le palier après un refus », une fois, dédoublonnée** (F) : `rereadAfterRefusal()` dans `useAuth`, une
   promesse de module en vol. Un `/api/me` en 401 y marque la session finie ; la fonction le dit à l'appelant, qui
   passe son refus de `forbidden` à « session finie ».
5. **Le vrai 403 du backoffice suit la règle de R3, pas les pages une à une** (F4). `BackofficeHttpClient` lève
   `AccessForbiddenError` après une réaction enregistrée (relecture du palier, état « accès refusé ») ;
   `useAdminLoad` et `visibleMutationError` la taisent comme `SessionEndedError` ; **une seule alerte, dans
   `AdminLayout`** (`AccessRefusedNotice`, à côté du bandeau), avec `common.accessRefused` et « Changer de compte ».
   *Avantages* : 12 pages et 14 `catch` intacts, la saisie reste (la mutation rend `false`). *Écarté* : un message par
   page (26 sites, et une clé de plus dans chaque union de raisons). « Changer de compte » **dans le même onglet**,
   comme R7 (décision 2 : « traité comme R7 ») — voir la question ouverte du rapport. `admin.notLoaded` devient neutre
   (il disait « votre session a pris fin », faux pour un refus).
6. **Réannoncer sans détruire le lien** (E) : l'alerte reste le même élément, seul son message (un `<span>`) porte la
   `key`. Une `role="alert"` est `aria-atomic` : un changement de contenu se lit en entier.
7. **Échap et le focus** (G) : le menu retient, par `focusin` sur le document tant qu'il est ouvert, si le dernier focus
   est parti **hors** du menu ; `body` ne vaut « personne » que si rien n'a pris le focus ailleurs depuis l'ouverture.
8. **La clé courante a une source** (Sp-c3/St, F10) : `useAdminLoad` expose `write(key, value)` (une valeur écrite
   périme les lectures en vol de la même clé) et `isCurrentKey(key)` (réactive) ; `requestedLocale` disparaît.
9. **Même onglet sur une étape de flux** (F7) : `meta.signInInSameTab` sur la route `oauth-consent`, lu par
   `SignInAgainLink` ; le consentement rend aussi son lien du corps par ce composant. Un seul constructeur, une règle
   par route plutôt qu'une prop à chaque site.
10. **`XSRF-TOKEN` indécodable = absent** (F13), comme un cookie vide : jamais une valeur émise par le serveur (jeton
    signé, sans caractère à encoder). *Écarté* : la valeur brute (le serveur, qui décode le cookie, la refuserait :
    même impasse, en 403 « refus réel »).
11. **Tout cookie de session refusé est effacé** (F1) : `StaleSessionCookiesClearingListener` écoute `JWT_EXPIRED` et
    tout `JWT_INVALID`, pas seulement le refus d'avant changement de mot de passe.
12. **`ApiProblemBody` et `hasProblemType` vont dans `infrastructure/http/problemJson.ts`**, module neutre ;
    `orderProblems.ts` garde les deux slugs de l'ordre et ne réexporte rien.

### Review Focus (deuxième relecture)

1. **Une écriture part, la session est rétablie dans un autre onglet, puis son 401 arrive** : la session reste rétablie,
   l'écriture échoue comme un échec ordinaire (réessayable). Test : R14.
2. **Trois lectures en 401 arrivées dans trois ticks au chargement d'une page d'administration** : une seule annonce du
   bandeau. Test : R14, `AdminWatchPage.spec.ts`.
3. **Un autre compte revient pendant que l'assistant montre une conversation** : la page est remontée, rien du premier
   compte n'est affiché. Test : R18.
4. **Un cookie `BEARER` expiré (cookies de session) sur une route publique** : un 401 qui efface les deux cookies, puis
   la requête suivante est anonyme et servie. Test : R21.
5. **`XSRF-TOKEN=%E0`** : aucune exception, l'écriture part sans en-tête (« session finie »). Test : R12.

### Contraintes propres aux tâches R12 à R22

- Les **Global Constraints** et les « Contraintes propres aux tâches R » ci-dessus valent toutes. Exécution : un agent
  par tâche (sonnet, effort high), successifs dans l'ordre R12 → R22, `superpowers:test-driven-development`, un commit
  par tâche, fichiers nommés, `git rm` pour une suppression.
- **Refactoring pur** (R12 hors F13, R13 hors A, R16 hors B/F7/F8) : `make front-test` vert avant la première
  modification, noté, et après la dernière ; les tests changés sont ceux que la tâche nomme.
- **R21 est backend** : lire avec `Read` un fichier de `backend/src/Security/Authentication/Infrastructure/Jwt/` avant
  d'écrire ; test ciblé `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis `make back-test` et
  `make back-quality` ; jamais de secret littéral (`TestCredentials`, `TestKeys::material()`), un JWT de test se signe
  par l'encodeur du conteneur (`JWTEncoderInterface`), jamais par une clé écrite dans le test.
- Avant chaque commit : `make front-test`, `make front-lint`, `make front-build`, et `tools/check-claude-rules.sh` dès
  qu'une règle bouge (`mcp-oauth.md` ~41 Ko et `mcp-oauth-revocation-personne.md` ~40 Ko : une phrase remplacée, jamais
  un paragraphe ajouté).
- axe-core sous minuteries réelles ; `ResizeObserver` absent de jsdom ; un CSS se lit par `node:fs`.

### Fichiers (tâches R12 à R22)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `frontend/src/infrastructure/auth/csrfCookie.ts` | `readCsrfToken` robuste, `hasCsrfToken` | R12 |
| `frontend/src/infrastructure/http/{sessionAwareFetch,problemJson}.ts` | `SessionAwareInit` ; corps problem+json neutre | R12 |
| `frontend/src/application/auth/useAuth.ts` | `onSessionRestored`, génération, descente unique, `rereadAfterRefusal`, `onAccountSwitched` | R13, R14, R15, R18 |
| `frontend/src/application/admin/shared/{useAdminLoad,visibleMutationError,backofficeAccessRefusal}.ts` | retour, refus réel, clé courante | R13, R17, R20 |
| `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/main.ts` | génération à l'envoi, refus réel | R14, R17 |
| `frontend/src/application/{mcp/useMcpConnections,mcp/useOAuthConsent,assistant/useAssistant}.ts` | retour, génération, relecture du palier | R13, R14, R15 |
| `frontend/src/presentation/router/{loginLocation,sessionEnded,reauthentication,index}.ts`, `frontend/src/presentation/ui/SignInAgainLink.vue` | un constructeur, `expired=1` ouvre la connexion, même onglet sur le consentement | R16 |
| `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue` (nouveau), `frontend/src/presentation/layout/AdminLayout.vue` | l'alerte du refus réel | R17 |
| `frontend/src/presentation/layout/AppLayout.vue` | rejeu de la navigation, remontage | R18 |
| `frontend/src/presentation/ui/admin/SessionEndedBanner.vue`, `frontend/src/presentation/layout/AccountMenu.vue` | focus | R13, R18, R19 |
| `frontend/src/application/admin/about/useAdminAboutSettings.ts`, `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue` | F10, F11 | R20 |
| `backend/config/packages/lexik_jwt_authentication.yaml`, `backend/src/Security/Authentication/Infrastructure/Jwt/{LoginSuccessSubscriber,StaleSessionCookiesClearingListener}.php`, `backend/src/Security/User/Presentation/Controller/BaseAccessController.php` | cookies de session, effacement de tout cookie refusé | R21 |

---

### Décisions de l'auteur sur ce plan (2026-10-29)

1. Premier 401 après l'expiration du JWT : **accès de base → déconnexion anticipée côté client** (tâche R21b) ; login →
   limite acceptée, écrite dans la règle et la note de release.
2. F4 : « Changer de compte » du backoffice dans le **même onglet** (comme R7).
3. Amendement d'ADR 0003 **dans R21** (Step 3b), pas à la clôture.

### Task R12 : Le cookie CSRF lu une fois et sans exception ; deux types partagés (J, F13, `SessionAwareInit`, `ApiProblemBody`)

**Files :**
- Create : `frontend/src/infrastructure/http/problemJson.ts`, `frontend/tests/infrastructure/http/problemJson.spec.ts`, `frontend/tests/infrastructure/auth/csrfCookie.spec.ts`
- Modify : `frontend/src/infrastructure/auth/csrfCookie.ts`, `frontend/src/infrastructure/auth/HttpAuthRepository.ts`, `frontend/src/infrastructure/http/sessionAwareFetch.ts`, `frontend/src/infrastructure/mcp/{HttpMcpConnectionsRepository,HttpOAuthConsentRepository}.ts`, `frontend/src/infrastructure/admin/shared/{BackofficeHttpClient,orderProblems}.ts`, `frontend/src/infrastructure/admin/users/HttpAdminUserRepository.ts`, les 9 dépôts qui importent `hasProblemType` de `orderProblems` (`grep -rln "hasProblemType" frontend/src/infrastructure/admin`)
- Test : `frontend/tests/infrastructure/http/sessionAwareFetch.spec.ts`
- Modify : `.claude/rules/frontend-session.md` (`paths:` complétés, une phrase sur `hasCsrfToken`), `.claude/rules/frontend-backoffice.md` (`orderProblems.ts` : le prédicat vit dans `problemJson.ts`)

**Interfaces :**
- Produces (`csrfCookie.ts`) : `readCsrfToken(): string | null` — `null` aussi quand la valeur ne se décode pas (`URIError` attrapée) ; `hasCsrfToken(): boolean` — vrai pour une valeur lue non vide.
- Produces (`sessionAwareFetch.ts`) : `export type SessionAwareInit = Omit<RequestInit, 'credentials' | 'headers'> & { method: string; headers?: Readonly<Record<string, string>> }`, deuxième paramètre de `sessionAwareFetch`.
- Produces (`problemJson.ts`) : `interface ApiProblemBody` (déplacée telle quelle) et `hasProblemType(body: ApiProblemBody, slug: string): boolean` (déplacée telle quelle).

- [ ] **Step 1 : filet** — `make front-test` vert, nombre de tests noté.
- [ ] **Step 2 : tests, rouges**
  - `csrfCookie.spec.ts` : `it('lit la valeur décodée')` ; `it('XSRF-TOKEN=%E0 : aucune exception, absent')` → `readCsrfToken()` vaut `null` (Review Focus 5) ; `it.each([['absent', null], ['vide', ''], ['indécodable', '%E0']])('hasCsrfToken : %s → faux')` ; `it('hasCsrfToken : une valeur → vrai')`.
  - `sessionAwareFetch.spec.ts` : `it("écriture avec XSRF-TOKEN=%E0 : part sans en-tête, et son 403 finit la session")`.
  - `problemJson.spec.ts` : les cas de `hasProblemType` (slug en fin de `type`, `type` absent, autre slug).
  Run : `docker compose exec frontend npm test -- tests/infrastructure/auth/csrfCookie.spec.ts tests/infrastructure/http`. Expected : `URIError`, `hasCsrfToken` et `problemJson` introuvables.
- [ ] **Step 3 : implémenter** — `readCsrfToken` dans un `try`/`catch (URIError)` ; `hasCsrfToken` ; `HttpAuthRepository.hasWriteToken()` rend `hasCsrfToken()` ; `sessionAwareFetch` décide « avec jeton » par `hasCsrfToken()` puis lit la valeur (un seul critère pour les deux sites, J) et type son `init` par `SessionAwareInit` ; les deux dépôts MCP typent leur `send` par `SessionAwareInit` et importent `problemJson.ts` ; `BackofficeHttpClient` importe le type sans le réexporter : ses lecteurs (`HttpAdminUserRepository`, `orderProblems.ts`, les 9 dépôts) importent `problemJson.ts`. Docblock de `csrfCookie.ts` : pourquoi indécodable vaut absent (choix 10).
- [ ] **Step 4 : règles** — `frontend-session.md`, `paths:` : ajouter `frontend/src/infrastructure/auth/**`, `frontend/tests/infrastructure/auth/**`, `frontend/src/domain/auth/repositories/**`, `frontend/src/application/mcp/**`, `frontend/src/application/assistant/**`, `frontend/tests/application/auth/**`, `frontend/tests/support/signInAgain.ts`, `frontend/src/presentation/layout/usePublishedHeight.ts` ; une phrase : « with a token » est `hasCsrfToken()`, la seule définition (vide ou indécodable = absent). `frontend-backoffice.md`, puce `orderProblems.ts` : `hasProblemType` vit dans `infrastructure/http/problemJson.ts`, partagé avec les dépôts MCP.
- [ ] **Step 5 : verts** — specs ciblés PASS ; `make front-test` (le filet plus les nouveaux), `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 6 : commit** — `fix(frontend): le cookie CSRF se lit sans exception, à un seul endroit ; types partagés hors du backoffice (#748)`.

---

### Task R13 : Un signal de retour, `onSessionRestored` ; plus d'alerte d'échec que personne n'a déclenchée (refactoring, A)

**Files :**
- Modify : `frontend/src/application/auth/useAuth.ts`, `frontend/src/application/admin/shared/useAdminLoad.ts`, `frontend/src/application/mcp/{useMcpConnections,useOAuthConsent}.ts`, `frontend/src/application/assistant/useAssistant.ts`, `frontend/src/presentation/ui/admin/SessionEndedBanner.vue`
- Test : `frontend/tests/application/auth/useAuth.spec.ts`, `frontend/tests/presentation/pages/McpConnectionsPage.spec.ts`
- Modify : `.claude/rules/frontend-session.md` (« The re-check on return », puce « Nobody but `useAuth` restores »), `.claude/rules/frontend-backoffice.md` (« The return is the one signal that exists »)

**Interfaces :**
- Produces : `UseAuthResult.onSessionRestored(callback: () => void): void` — appelé dans un scope (setup ou `effectScope`), retiré à `onScopeDispose` ; `callback` appelé une fois, de façon synchrone, à chaque descente de `sessionEnded` (vrai → faux). R18 le restreint au même compte.

- [ ] **Step 1 : filet** — `make front-test` vert, noté.
- [ ] **Step 2 : tests, rouges**
  - `useAuth.spec.ts` : `it("onSessionRestored : appelé une fois au retour, jamais à la fin")` ; `it("onSessionRestored : plus appelé après la fin du scope")` (`effectScope().stop()`).
  - `McpConnectionsPage.spec.ts` (A) : `it("révocation refusée pour session finie, puis retour : aucune alerte « révocation échouée »")` — `revoke` rejette `SessionEndedError`, retour par `signInAgain()`, liste relue → aucun `[role="alert"]` ne contient le texte d'échec de révocation.
  Expected : `onSessionRestored` absent ; l'alerte d'échec présente.
- [ ] **Step 3 : implémenter** — dans `useAuth`, un `Set` de rappels de module, vidé de chaque rappel à `onScopeDispose` ; la descente les appelle. Les cinq `watch(() => authState.sessionEnded, …)` / `watch(hasSessionEnded, …)` côté retour deviennent `onSessionRestored(…)` : `useAdminLoad` (son `stopWatchingReturn` disparaît), `useMcpConnections` (le rappel remet `lastOutcome` à `null` avant `load()` — A), `useOAuthConsent`, `useAssistant`, le bandeau (`hasRestored = true`) ; le bandeau garde un `watch(hasSessionEnded)` pour la seule montée (CR6). Docblocks de `useMcpConnections` et `useOAuthConsent` (l. 170 et 199) : rétablir la session est l'affaire de `useAuth().recheckEndedSession()`, branchée par `useSessionRecheckOnFocus` ; le retour arrive par `onSessionRestored`.
- [ ] **Step 4 : règles** — `frontend-session.md` et `frontend-backoffice.md` : le signal de retour est `onSessionRestored`, plus un `watch` par page ; une page qui dépend du retour s'y abonne ; A (un retour efface l'issue d'une révocation refusée pour session finie).
- [ ] **Step 5 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 6 : commit** — `refactor(frontend): un seul signal de retour de session, onSessionRestored (#748)`.

---

### Task R14 : Une génération de session : rien de périmé ne finit ni ne rétablit la session (C, F5, Sp-c1)

**Files :**
- Modify : `frontend/src/application/auth/useAuth.ts`, `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/main.ts`, `frontend/src/application/mcp/{useMcpConnections,useOAuthConsent}.ts`, `frontend/src/application/assistant/useAssistant.ts`
- Test : `useAuth.spec.ts`, `BackofficeHttpClient.spec.ts`, `backofficeSessionExpiry.spec.ts`, `AdminWatchPage.spec.ts`, `useMcpConnections.spec.ts`, `useOAuthConsent.spec.ts`, `useAssistant.spec.ts`, `tests/support/signInAgain.ts` si besoin
- Modify : `.claude/rules/frontend-session.md` (« The interface », « Every refusal re-announces », « The re-check on return »)

**Interfaces :**
- Produces (`useAuth.ts`) :
  - `sessionStamp(): number` (fonction de module) — la génération courante ;
  - `markSessionExpired(sentAt?: number): boolean` — sans `sentAt` ou avec la génération courante : fin acceptée (la première lève `sessionEnded` et anonymise l'en-tête ; une fin pendant une session finie augmente seulement `sessionEndedCount`), la génération avance, rend `true` ; avec une génération périmée : rien ne change, rend `true` si la session est finie (le verdict tient, déjà annoncé), `false` si elle a été rétablie depuis l'envoi ;
  - une descente unique, interne : elle exige un compte, `hasWriteToken()` et — sauf pour `login` — la génération lue à l'envoi de `/api/me` ; elle avance la génération et appelle `onSessionRestored`. `recheckEndedSession()` et `checkAuth()` y passent ; `applySession()` n'abaisse plus `sessionEnded`.
- Produces (`BackofficeHttpClient.ts`) : `interface BackofficeSessionEndedHandler { stampAtSend(): number; ended(sentAt: number): boolean }` ; le client lit `stampAtSend()` avant l'envoi ; sur un verdict « session finie », `ended(sentAt)` vrai → `SessionEndedError` levée, faux → la réponse est rendue au dépôt (qui la traduit en échec ordinaire) ; sans réaction enregistrée, `SessionEndedError` comme avant.
- Produces (`backofficeSessionExpiry.ts`) : `backofficeSessionEndedHandler: BackofficeSessionEndedHandler` = `{ stampAtSend: sessionStamp, ended: markSessionExpired }`, remplace `onBackofficeSessionEnded`.
- Les trois composables lisent `sessionStamp()` avant l'appel au dépôt et passent `markSessionExpired(sentAt)` ; un `false` traite l'échec comme ordinaire : MCP `load` → `status = 'error'` sans `refusal`, `revoke` → `lastOutcome = 'failed'` sans `refusal` ; consentement → l'échec `unknown` de `fail()` ; assistant → `AssistantError('unknown')`.

- [ ] **Step 1 : tests, rouges**
  - `useAuth.spec.ts` : `it("un refus parti avant le retour ne refinit pas la session")` (`const sent = sessionStamp()`, fin, retour, `markSessionExpired(sent)` → `false`, session rétablie, compteur inchangé — Review Focus 1) ; `it("session finie : un refus parti avant la fin ne réannonce pas")` (→ `true`, compteur inchangé) ; `it("session finie : un refus parti après la fin réannonce")` (compteur + 1) ; `it("un /api/me parti avant un nouveau refus ne rétablit pas la session")` (`me` différé, `recheckEndedSession()`, `markSessionExpired(sessionStamp())`, puis `me` rend le compte → toujours finie, aucun rappel) ; `it("checkAuth ne rétablit pas une session finie sans jeton d'écriture")` (F5).
  - `BackofficeHttpClient.spec.ts` : `it("un 401 parti avant le retour : la réponse est rendue, aucune SessionEndedError")` (réaction `{ stampAtSend: () => 3, ended: () => false }`) ; `it("ended vrai : SessionEndedError")` ; `it("stampAtSend est lu avant l'envoi")` (ordre des appels).
  - `AdminWatchPage.spec.ts` (Sp-c1, Review Focus 2) : `it("trois lectures en 401 dans trois ticks au chargement : sessionEndedCount n'avance que d'un")` — vrai `useAuth`, la réaction de `main.ts` enregistrée, trois `fetch` résolus en 401 à trois `flushPromises()` d'écart.
  - `useMcpConnections.spec.ts`, `useOAuthConsent.spec.ts`, `useAssistant.spec.ts` : `it("refus de session parti avant le retour : échec ordinaire, la session reste rétablie")`.
  - `backofficeSessionExpiry.spec.ts` : le cas « idempotent » devient « une fin périmée n'annonce rien de plus » (changement exprès).
  Expected : `sessionStamp` absent ; session refinie ; « rétablie » annoncée à tort ; compteur à 3.
- [ ] **Step 2 : implémenter** — selon les interfaces ; `main.ts` enregistre `backofficeSessionEndedHandler` ; son commentaire et le docblock de `backofficeSessionExpiry.ts` disent le verdict jugé par génération (plus « idempotente… rien à dédoublonner », ni « un 401 » seul : un 401 ou un 403 sans jeton).
- [ ] **Step 3 : règle** — `frontend-session.md` : la génération (pourquoi, les deux sens, choix 1), la descente unique (compte, jeton d'écriture, génération ; `login` sans comparaison), « Every refusal re-announces » réécrit (seul un refus parti sous la génération courante réannonce : un geste fait pendant la session finie, jamais les lectures parallèles du chargement), un échec de session périmé est un échec ordinaire.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): une réponse partie sous une autre session ne finit ni ne rétablit la session (#748)`.

---

### Task R15 : Relire le palier après un refus, une fois, et dire la session finie quand elle l'est (F)

**Files :**
- Modify : `frontend/src/application/auth/useAuth.ts`, `frontend/src/application/mcp/{useMcpConnections,useOAuthConsent}.ts`, `frontend/src/application/assistant/useAssistant.ts`
- Test : `useAuth.spec.ts`, `useMcpConnections.spec.ts`, `useOAuthConsent.spec.ts`, `useAssistant.spec.ts`, `McpConnectionsPage.spec.ts`
- Modify : `.claude/rules/frontend-session.md` (« A real refusal »)

**Interfaces :**
- Produces : `type RefusalReread = 'session-held' | 'session-ended'` ; `UseAuthResult.rereadAfterRefusal(): Promise<RefusalReread>` — une promesse de module en vol (deux appels rapprochés, un seul `/api/me`) ; génération lue à l'envoi ; `/api/me` en 401 → `markSessionExpired(sentAt)` et `'session-ended'` si elle rend `true` ; base ou compte → la session appliquée (en-tête), `'session-held'` ; un rejet est avalé, `'session-held'` ; `isChecking` jamais touché.
- Les trois composables remplacent `void checkAuth().catch(…)` par `rereadAfterRefusal()` ; sur `'session-ended'`, si le refus affiché est encore ce `forbidden`-là : MCP `refusal = 'session-ended'`, consentement `failure = 'session-ended'`, assistant `error = new AssistantError('unauthenticated')`.

- [ ] **Step 1 : tests, rouges**
  - `useAuth.spec.ts` : `it("deux refus rapprochés : un seul /api/me")` ; `it("/api/me en 401 : la session est marquée finie, 'session-ended'")` ; `it("/api/me en 403 : palier de base, 'session-held'")` ; `it("panne de /api/me : avalée, 'session-held'")` ; `it("isChecking n'est pas touché")`.
  - `useMcpConnections.spec.ts` : `it("lecture et révocation refusées ensemble : un seul /api/me")` ; `it("refus réel puis /api/me en 401 : refusal 'session-ended', session finie")`. `McpConnectionsPage.spec.ts` : le même cas montre le lien de reconnexion, plus « Changer de compte ».
  - `useOAuthConsent.spec.ts` et `useAssistant.spec.ts` : `it("refus réel puis /api/me en 401 : la page dit la session finie")`.
  Expected : deux `me` ; `hasSessionEnded` faux, refus resté `forbidden`.
- [ ] **Step 2 : implémenter** — selon les interfaces.
- [ ] **Step 3 : règle** — `frontend-session.md`, « A real refusal » : `rereadAfterRefusal` (une relecture en vol, la session finie dite à la page quand `/api/me` répond 401, F) remplace « `checkAuth()`, not awaited » ; « Limits removed » : l'en-tête anonyme sans `sessionEnded` n'existe plus.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): un refus relit le palier une fois, et une session finie y est dite comme telle (#748)`.

---

### Task R16 : Un constructeur des liens de connexion ; `expired=1` ouvre la page ; même onglet sur le consentement (I, B/F2, F7, F8)

**Files :**
- Create : `frontend/src/presentation/router/loginLocation.ts`
- Modify : `frontend/src/presentation/router/{sessionEnded,reauthentication,index}.ts`, `frontend/src/presentation/ui/SignInAgainLink.vue`, `frontend/src/presentation/layout/AppHeader.vue`, `frontend/src/presentation/pages/OAuthConsentPage.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`
- Test : `signInLocations.spec.ts`, `adminGuard.spec.ts`, `SignInAgainLink.spec.ts`, `AppHeader.spec.ts`, `OAuthConsentPage.spec.ts`
- Modify : `.claude/rules/frontend-session.md` (« The sign-in-again link », « The header follows the banner »), `.claude/rules/mcp-oauth.md` (une phrase, « The consent page »)

**Interfaces :**
- Produces : `type LoginFlag = 'session-ended' | 'reauthentication'` ; `loginLocation(route: Pick<RouteLocationNormalizedLoaded, 'fullPath' | 'params' | 'meta'>, fallback: Locale, flag: LoginFlag): RouteLocationRaw` — `login` dans la langue de la route, le drapeau (`expired=1` ou `reauth=1`), et `redirect` = `fullPath` **seulement si `route.meta.requiresAuth`** (F8 : une route non revue par `protectedRoutesCarryNoSecret` ne met pas son chemin dans l'URL). `signInAgainLocation` et `switchAccountLocation` gardent leur nom et délèguent (aucun appelant ne change).
- `RouteMeta.signInInSameTab?: boolean`, posé sur `oauth-consent` ; `SignInAgainLink` le lit sur la route courante : sans `target`, `rel`, texte caché ni icône.
- `beforeEnter` de `login` : une personne connectée y entre avec `reauth=1` **ou** `expired=1` (le drapeau n'ouvre que la page).

- [ ] **Step 1 : filet** — `make front-test` vert, noté.
- [ ] **Step 2 : tests, rouges**
  - `signInLocations.spec.ts` : `it('loginLocation : les deux drapeaux')` ; `it("hors route protégée : pas de redirect")` (les deux constructeurs) ; la garde « aucun autre fichier » admet `loginLocation.ts`.
  - `adminGuard.spec.ts` : `it("connectée, expired=1 : la page de connexion s'ouvre")` (B/F2 ; `reauth=1` déjà couvert ; sans drapeau, toujours l'accueil).
  - `SignInAgainLink.spec.ts` : `it("sur une route publique : pas de redirect")` (F8) ; `it("route signInInSameTab : même onglet, ni texte caché ni icône")`.
  - `AppHeader.spec.ts` : le cas `oauth-consent` de R8 change exprès : « Connexion » sans `target`, `href` = `login?redirect=…&expired=1` (F7).
  - `OAuthConsentPage.spec.ts` : le lien « Se reconnecter » du corps reste sans `target` (rendu désormais par `SignInAgainLink`).
  Expected : module introuvable, accueil, `redirect` présent, `target="_blank"`.
- [ ] **Step 3 : implémenter** — selon les interfaces ; le consentement rend son lien de session finie par `<SignInAgainLink>` et garde `switchAccountLocation` pour « Changer de compte » ; renommage `oauthConsent.errors.sessionExpired` → `oauthConsent.errors.sessionEnded` (FR/EN, `OAuthConsentPage.vue`) ; docblocks : `sessionEnded.ts` (`SESSION_ENDED_QUERY` : un 401 ou un 403 sans jeton, de toute page protégée), `reauthentication.ts` (`REAUTH_QUERY` : le consentement et « Changer de compte » des refus réels), `SignInAgainLink.vue` (le consentement l'utilise, en même onglet par `meta`) ; `AppHeader.vue:235` : renvoyer à `.claude/rules/frontend-session.md`, plus à « la tâche 11 du plan ».
- [ ] **Step 4 : règles** — `frontend-session.md` : `loginLocation`, `redirect` seulement sur une route protégée, `expired=1` ouvre la connexion à une personne connectée (pourquoi : `BEARER` vivant sans `XSRF-TOKEN`, une impasse jusqu'à une heure), `signInInSameTab` ; le paragraphe « The consent page is the exception » et la puce « The header's Connexion in a new tab holds on every requiresAuth route » : le consentement est en même onglet, en-tête compris. `mcp-oauth.md` : la phrase « while the header's « Connexion » opens a new tab (`SignInAgainLink`, as on every `requiresAuth` page) … » remplacée par « the header's « Connexion » too (`meta.signInInSameTab`) » — taille ≤ avant.
- [ ] **Step 5 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 6 : commit** — `fix(frontend): un constructeur des liens de connexion, expired=1 ouvre la page, le consentement reste dans son onglet (#748)`.

---

### Task R17 : Un vrai 403 du backoffice relit le palier et propose de changer de compte (F4)

**Files :**
- Create : `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`
- Modify : `BackofficeHttpClient.ts`, `backofficeSessionExpiry.ts`, `main.ts`, `useAdminLoad.ts`, `visibleMutationError.ts`, `frontend/src/presentation/layout/AdminLayout.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`admin.notLoaded`)
- Test : `BackofficeHttpClient.spec.ts`, `backofficeSessionExpiry.spec.ts`, `useAdminLoad.spec.ts`, `visibleMutationError.spec.ts`, `AdminLayout.sessionEnded.spec.ts`, `HttpAdminUserRepository.spec.ts` (un 403 n'y arrive plus), `AdminCaseStudiesPage.spec.ts`, `AdminWatchPage.spec.ts` (texte « non chargé »)
- Modify : `.claude/rules/frontend-session.md`, `.claude/rules/frontend-backoffice.md`

**Interfaces :**
- Consumes : `rereadAfterRefusal` (R15), `switchAccountLocation` (R16), `AccessForbiddenError`.
- Produces : `BackofficeSessionEndedHandler.refused(): void` (troisième membre) ; le client l'appelle sur un 403 qui n'est pas une fin de session (lecture, ou écriture avec jeton) puis **lève `AccessForbiddenError`** ; sans réaction, il lève aussi.
- Produces (`backofficeAccessRefusal.ts`) : `backofficeAccessRefused: Readonly<Ref<number>>` (nombre de refus, 0 = aucun), `markBackofficeAccessRefused(): void`, `clearBackofficeAccessRefusal(): void`.
- Produces (`backofficeSessionExpiry.ts`) : `createBackofficeSessionEndedHandler(auth: Pick<UseAuthResult, 'rereadAfterRefusal'>): BackofficeSessionEndedHandler` (`refused` = `markBackofficeAccessRefused()` puis `void auth.rereadAfterRefusal()`), appelée par `main.ts` avec `app.runWithContext(() => useAuth())` ; elle remplace la constante `backofficeSessionEndedHandler` de R14 (ses lecteurs : `main.ts`, `backofficeSessionExpiry.spec.ts`, `AdminWatchPage.spec.ts`).
- `useAdminLoad` : `AccessForbiddenError` ne lève pas `hasError` et n'est pas relue au retour ; `visibleMutationError` : `null` pour `AccessForbiddenError`.
- `AccessRefusedNotice.vue` : rendu par `AdminLayout` juste après `SessionEndedBanner` ; `role="alert"` inséré par `v-if` (refus > 0 **et** session non finie), message `common.accessRefused` dans un `<span>` dont la `key` est le nombre de refus (la règle que R19 donne ensuite au bandeau : l'alerte reste, son message est remplacé), `RouterLink` `switchAccountLocation` libellé `common.switchAccount`, même onglet ; effacé à chaque changement de `route.fullPath`.

- [ ] **Step 1 : tests, rouges**
  - `BackofficeHttpClient.spec.ts` : `it.each(['GET', 'PUT avec jeton'])("403 %s : refused appelé une fois, AccessForbiddenError")` ; `it("403 sans réaction : AccessForbiddenError")` ; le tableau « 403 rendu au dépôt » de R3 change exprès.
  - `backofficeSessionExpiry.spec.ts` : `it("refused : l'alerte est levée et le palier relu une fois pour deux refus")`.
  - `useAdminLoad.spec.ts` : `it("refus réel : ni hasError ni relecture au retour")` ; `visibleMutationError.spec.ts` : `AccessForbiddenError` → `null`.
  - `AdminLayout.sessionEnded.spec.ts` : `it("refus réel à l'enregistrement : une alerte « accès refusé », « Changer de compte » vers login?reauth=1&redirect=<cette page>, la saisie reste")` (FR et EN, texte exact de `common.accessRefused`, axe vert) ; `it("l'alerte s'efface à la navigation")` ; `it("la relecture trouve la session finie : seul le bandeau de session finie reste")`.
  - `AdminCaseStudiesPage.spec.ts`, `AdminWatchPage.spec.ts` : « non chargé » lit le texte neutre (changement exprès).
  Expected : réponse rendue au dépôt, `hasError` levé, aucune alerte.
- [ ] **Step 2 : implémenter** — selon les interfaces. i18n `admin.notLoaded` : FR « Contenu non chargé : voyez le message en haut de la page. », EN « Content not loaded: see the message at the top of the page. ».
- [ ] **Step 3 : règles** — `frontend-session.md` : « A real refusal » couvre le backoffice (la réaction du client, l'alerte unique d'`AdminLayout`, même onglet, choix 5) ; `frontend-backoffice.md`, puce « A 401, or a 403 … » : un autre 403 lève `AccessForbiddenError`, tu de la même façon, et l'alerte d'`AdminLayout` le dit ; `admin.notLoaded` neutre.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): un refus réel du backoffice relit le palier et propose de changer de compte (#748, #732)`.

---

### Task R18 : Le même compte est rétabli en place, un autre rejoue la navigation (F3/D)

**Files :**
- Modify : `frontend/src/application/auth/useAuth.ts`, `frontend/src/presentation/layout/AppLayout.vue`, `frontend/tests/support/signInAgain.ts`
- Test : `useAuth.spec.ts`, `AppLayout.spec.ts`, `SessionEndedBanner.spec.ts`, `AssistantPage.spec.ts`
- Modify : `.claude/rules/frontend-session.md` (« The re-check on return »)

**Interfaces :**
- Produces : `UseAuthResult.onAccountSwitched(callback: () => void): void` (même mécanique qu'`onSessionRestored`). `useAuth` retient, à la première fin acceptée, le `username` du compte affiché (`null` sans compte). À la descente : même `username`, ou aucun retenu → `onSessionRestored` ; autre → la session est appliquée et seul `onAccountSwitched` est appelé. `login()` abaisse sans appeler ni l'un ni l'autre (la page de connexion navigue elle-même).
- `AppLayout` : `onAccountSwitched` → `await router.replace({ path, query, hash, force: true })` sur la route courante, puis la `key` du composant rendu par `<RouterView v-slot="{ Component }">` avance.
- `signInAgain()` (support de test) : en session finie, retour par `recheckEndedSession()` avec un `me` qui rend le compte d'avant et `hasWriteToken` vrai ; sinon `login` comme avant.

- [ ] **Step 1 : tests, rouges**
  - `useAuth.spec.ts` : `it("le même compte revient : rétabli en place")` (`onSessionRestored` une fois, `onAccountSwitched` jamais) ; `it("un autre compte revient : la session est appliquée, onAccountSwitched seul")` ; `it("une connexion dans l'onglet n'appelle aucun des deux")`.
  - `AppLayout.spec.ts` : `it("un autre compte : la garde rejuge la route et la page est remontée")` (une page sonde compte ses montages : 2 ; la garde appelée) ; `it("le nouveau compte n'a pas le rôle : forbidden")`.
  - `SessionEndedBanner.spec.ts` : `it("un autre compte : « Session rétablie » n'est pas annoncée")`.
  - `AssistantPage.spec.ts` (Review Focus 3) : `it("un autre compte revient : la conversation du premier n'est plus affichée")`.
  Expected : `onAccountSwitched` absent ; « Session rétablie » annoncée ; un seul montage.
- [ ] **Step 2 : implémenter** — selon les interfaces ; `signInAgain.ts` adapté (son docblock dit pourquoi : `login` n'annonce plus de retour).
- [ ] **Step 3 : règle** — `frontend-session.md` : le compte retenu, « rétablie » seulement pour le même, le rejeu et le remontage (choix 3, pourquoi `force` et la `key`), `login` silencieux.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): un autre compte au retour rejoue la navigation au lieu de rétablir la page (#748)`.

---

### Task R19 : Le focus reste où il est : réannonce du bandeau, Échap du menu du compte (E, G)

**Files :**
- Modify : `frontend/src/presentation/ui/admin/SessionEndedBanner.vue`, `frontend/src/presentation/layout/AccountMenu.vue`
- Test : `SessionEndedBanner.spec.ts`, `AccountMenu.spec.ts`
- Modify : `.claude/rules/frontend-a11y.md` (puce « A live region is announced… »), `.claude/rules/frontend-session.md` (« Every refusal re-announces »), `.claude/rules/mcp-oauth-revocation-personne.md` (« Desktop account menu », une phrase remplacée)

- [ ] **Step 1 : tests, rouges**
  - `SessionEndedBanner.spec.ts` (E) : `it("un nouveau refus réannonce sans retirer le lien qui a le focus")` — lien focalisé, refus sous la génération courante, `nextTick` → `document.activeElement` est toujours le lien, l'élément `[role="alert"]` est le même, le nœud du message (`[data-testid="admin-session-ended-message"]`) est remplacé. Le test S7 de R9 (« l'élément d'après n'est pas celui d'avant ») change exprès.
  - `AccountMenu.spec.ts` (G) : `it("Échap après que le focus parti ailleurs a été retiré : il n'est pas volé")` — menu ouvert au clic, un bouton hors du menu focalisé puis retiré du DOM (`activeElement` = `body`), Échap → le focus n'est pas sur le bouton du menu. Le test de R10 (`blur()` sans focus ailleurs) reste vert.
  Expected : `activeElement` = `body` après la réannonce ; focus volé.
- [ ] **Step 2 : implémenter** — bandeau : la `key` passe du `<p role="alert">` à un `<span data-testid="admin-session-ended-message">` qui porte le message (choix 6) ; le docblock et le commentaire du gabarit le disent. Menu : un écouteur `focusin` sur le document pendant l'ouverture retient si le dernier focus est hors du conteneur ; Échap rend le focus si l'élément actif est dans le menu, ou si personne ne le tient **et** aucun focus n'est parti ailleurs depuis l'ouverture (choix 7) ; l'écouteur est retiré à la fermeture et au démontage.
- [ ] **Step 3 : règles** — `frontend-a11y.md` : une alerte se réannonce par le remplacement de son contenu, jamais de l'élément qui porte un lien (E) ; et la ligne 78 dit « un 401, ou un 403 à une écriture partie sans jeton ». `frontend-session.md` : idem. `mcp-oauth-revocation-personne.md` : « or held by nobody » devient « or held by nobody since it was opened ».
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): la réannonce du bandeau et Échap ne déplacent plus le focus (#748)`.

---

### Task R20 : Les réglages « À propos » : une clé courante, une écriture qui périme les lectures, une erreur à sa langue (F10, Sp-c3/St, F11)

**Files :**
- Modify : `frontend/src/application/admin/shared/useAdminLoad.ts`, `frontend/src/application/admin/about/useAdminAboutSettings.ts`, `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue`
- Test : `useAdminLoad.spec.ts`, `useAdminAboutSettings.spec.ts`, `AdminAboutPage.spec.ts`
- Modify : `.claude/rules/frontend-backoffice.md` (« The key guards the writes too »)

**Interfaces :**
- Produces (`useAdminLoad`) : `write(key: K, value: T): boolean` — si `key` est la clé demandée en dernier : périme toute lecture en vol (`requestGuard.begin()`), `apply(value)`, clé détenue = `key`, `isLoaded` vrai, rend `true` ; sinon ne pose rien, rend `false`. `isCurrentKey(key: K): boolean`, réactive (lue dans un `computed`).
- `useAdminAboutSettings` : `requestedLocale` retiré ; `save` pose la réponse par `write(locale, saved)` ; `errorMessage` devient un `computed` : l'erreur du dernier enregistrement **si sa locale est la clé courante**, `null` sinon (elle réapparaît au retour sur sa langue, jusqu'au prochain enregistrement de cette langue).
- `AdminAboutSettingsForm` : `proposalSourceLocale` n'est effacée que si `save()` rend `true`.

- [ ] **Step 1 : tests, rouges**
  - `useAdminLoad.spec.ts` : `it("write sur la clé courante pose la valeur et périme la lecture en vol")` ; `it("write sur une autre clé ne pose rien")` ; `it("isCurrentKey suit le dernier load")`.
  - `useAdminAboutSettings.spec.ts` : `it("FR enregistré lentement, EN, puis FR : la lecture FR servie avant la réponse du PUT ne repose pas l'ancien FR")` (F10-1 : `update('fr')` et `get('fr')` différés ; `update` résout d'abord avec les valeurs neuves, puis `get` avec les anciennes → `settings` garde les neuves) ; `it("un échec FR arrivé après le passage en EN n'est pas montré sous l'EN, il l'est au retour en FR")` (F10-2).
  - `AdminAboutPage.spec.ts` (F11) : `it("un enregistrement refusé garde la mention de la proposition de traduction")`.
  Expected : `write` absent ; anciennes valeurs posées ; erreur sous l'EN ; mention effacée.
- [ ] **Step 2 : implémenter** — selon les interfaces ; le test R11 (`useAdminAboutSettings.spec.ts`) reste vert sans changement.
- [ ] **Step 3 : règle** — `frontend-backoffice.md` : la clé courante a une source (`useAdminLoad.isCurrentKey`), une écriture périme les lectures en vol de sa clé (F10-1, pourquoi : un `GET` servi avant le COMMIT du `PUT`), l'erreur reste à sa langue (F10-2), la proposition survit à un refus (F11).
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): les réglages « À propos » ne perdent ni un enregistrement ni son erreur en changeant de langue (#748)`.

---

### Task R21 : `BEARER` et `XSRF-TOKEN` deviennent des cookies de session, et tout cookie refusé est effacé (F1, H ; backend)

**Files :**
- Modify : `backend/config/packages/lexik_jwt_authentication.yaml`, `backend/src/Security/Authentication/Infrastructure/Jwt/{LoginSuccessSubscriber,StaleSessionCookiesClearingListener}.php`, `backend/src/Security/User/Presentation/Controller/BaseAccessController.php`, `backend/src/Security/Authentication/Infrastructure/Http/AuthCookieFactory.php` (docblock)
- Create : `backend/tests/Security/Authentication/Infrastructure/Jwt/RefusedSessionCookiesAreClearedTest.php`
- Test : `backend/tests/Security/Authentication/AuthCookieAttributesTest.php`, `backend/tests/Security/User/Presentation/Controller/BaseAccessControllerTest.php`
- Modify : `.claude/rules/security-authentication-sessions.md`, `frontend/src/infrastructure/http/sessionAwareFetch.ts` (docblock : les faits), `.claude/rules/frontend-session.md` (« The rule, in one function » : les faits)

**Interfaces :**
- `LoginSuccessSubscriber::__construct(CsrfCookieTokenSigner $csrfCookieTokenSigner, AuthCookieFactory $authCookieFactory)` — `$sessionLifetimeSeconds`, l'`Autowire` et `time()` retirés ; `xsrf($token)` sans échéance.
- `BaseAccessController` : `bearer($jwt)` et `xsrf(…)` sans échéance ; le JWT garde `exp` = maintenant + 900 et le corps `expiresAt`.
- `StaleSessionCookiesClearingListener` : `#[AsEventListener(event: Events::JWT_INVALID)]` **et** `Events::JWT_EXPIRED`, pour toute exception ; expire les deux cookies par `AuthCookieFactory::expired()`.

- [ ] **Step 1 : tests, rouges**
  - `AuthCookieAttributesTest` (changement de sens, décision 1) : `testTheLoginCookiesAreSessionCookies` et `testTheBaseAccessCookiesAreSessionCookies` — sur la réponse (jamais le jar), `BEARER` et `XSRF-TOKEN` : `assertSame(0, $cookie->getExpiresTime())` ; ils remplacent les deux tests de R1 ; le premier test (attributs par nom) inchangé.
  - `BaseAccessControllerTest:74` : le cookie n'a plus d'échéance (`0`) ; `expiresAt` du corps égale l'`exp` du JWT (lu par l'encodeur du conteneur).
  - `RefusedSessionCookiesAreClearedTest` (Review Focus 4) : `testAnExpiredJwtCookieIsClearedByItsFirst401` — un JWT signé par l'encodeur du conteneur (`JWTEncoderInterface`, `exp` passé), posé comme `BEARER`, `GET /api/about/fr` (route publique) → 401 qui expire `BEARER` et `XSRF-TOKEN` (attributs de la fabrique, `isCleared()`), puis la même requête rejouée avec le jar (cookies effacés) n'est plus un 401 (la réponse anonyme) ; `testAnInvalidJwtCookieIsClearedToo` (une valeur qui n'est pas un JWT valide) → même effacement ; `testThePasswordChangeRefusalStillClearsBoth` (témoin vert : renvoyer à `SessionBeforePasswordChangeTest`, ou le rejouer).
  Run : `docker compose exec -u dev backend php bin/phpunit tests/Security/Authentication tests/Security/User/Presentation/Controller/BaseAccessControllerTest.php`. Expected : échéances non nulles ; 401 sans `Set-Cookie`.
- [ ] **Step 2 : implémenter** — `set_cookies.BEARER.lifetime: 0` avec son commentaire (cookie de session : fermer le navigateur sans restauration finit la session, lecture et écriture ; le JWT garde `token_ttl`) ; le reste selon les interfaces. Docblock du listener : avec des cookies de session, un cookie refusé (expiré, invalide, antérieur au changement de mot de passe) resterait jusqu'à la fermeture du navigateur et ferait de tout `/api`, public compris, un 401 ; effacé, la requête suivante est anonyme. Docblock d'`AuthCookieFactory` : plus aucun site ne pose d'échéance.
- [ ] **Step 3 : règles et faits** — `security-authentication-sessions.md` : la puce `LoginSuccessSubscriber` réécrite (cookies de session, pourquoi, décision du 2026-10-29), `lifetime: 0`, les tests ; la puce `AuthCookieFactory` ; la puce `StaleSessionCookiesClearingListener` (tout refus, pourquoi). `sessionAwareFetch.ts` et `frontend-session.md`, les faits : les deux cookies vivent la session du navigateur ; à l'expiration du JWT, la première requête prend un 401 qui les efface (une lecture : 401 ; une écriture : 401 puis, cookies effacés, le 403 sans jeton) ; une session ouverte avant le déploiement garde des cookies persistants, une heure au plus. Constat H : sans objet (plus de durée répétée).
- [ ] **Step 3b : amendement d'ADR 0003 (décision de l'auteur du 2026-10-29 : dans R21, pas à la clôture)** — `docs/adr/0003-paliers-d-acces.md`, avec le contenu décrit à la tâche 12 (ligne de statut, faits, amendement « 2026-10-29 (#748, F1) »), relu contre `security-authentication-sessions.md` ; ajouter que la première requête qui suit l'expiration d'un JWT de **login** prend un 401 unique qui efface les cookies (limite acceptée), et que l'accès de base l'évite par une déconnexion anticipée côté client (tâche R21b).
- [ ] **Step 4 : verts** — même commande PASS ; `make back-test`, `make back-quality` (`-u dev`), `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(security): BEARER et XSRF-TOKEN cookies de session, tout cookie refusé effacé (#748)` (ADR compris).

---

### Task R21b : l'accès de base se déconnecte à son échéance, avant le 401 (décision de l'auteur du 2026-10-29 ; frontend)

Contexte : après R21, la première requête `/api` qui suit l'expiration du JWT prend un 401 qui efface les cookies ; une
page publique peut afficher une erreur une fois. **Décision de l'auteur : pour l'accès de base (15 min), le frontend
appelle `POST /api/logout` à l'échéance qu'il connaît déjà** (`expiresAt` du corps de `POST /api/account/base-access`,
déjà lu par le frontend : voir `.claude/rules/frontend.md`, son expiration côté client). Pour le login (1 h), dont
l'échéance n'est pas exposée au frontend, le 401 unique est une **limite acceptée**, écrite dans `frontend-session.md`
et la note de release.

**Files :** le module qui gère aujourd'hui l'expiration de l'accès de base côté client (à trouver : `frontend.md`,
« its expiry on the frontend ») ; `useAuth.ts` si la déconnexion passe par lui ; leurs specs ; `frontend-session.md`.

- [ ] **Step 1 : test rouge** — faux minuteurs : un accès de base obtenu avec `expiresAt` = maintenant + 15 min ; à
  l'échéance (et pas avant), `POST /api/logout` part une fois, l'état passe en anonyme **sans** `markSessionExpired()`
  (ce n'est pas une session finie à annoncer) ; un onglet revenu après l'échéance (minuterie gelée en arrière-plan)
  déconnecte au retour (`visibilitychange`/`focus`) ; une nouvelle obtention ou un login entre-temps annule la
  minuterie ; un échec réseau du logout laisse l'état anonyme côté client (le 401 suivant effacera les cookies).
- [ ] **Step 2 : implémenter** au plus près de l'expiration existante de l'accès de base (ne pas dupliquer sa
  minuterie : la brancher). `POST /api/logout` est une écriture : il part avec son jeton CSRF par `sessionAwareFetch`.
- [ ] **Step 3 : règle** — `frontend-session.md` : l'accès de base se déconnecte à son échéance ; le login garde le 401
  unique, limite acceptée, et pourquoi (échéance non exposée).
- [ ] **Step 4 : verts** — `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): l'accès de base se déconnecte à son échéance, avant le 401 (#748)`.

---

### Task R22 : Le reste de la documentation (Standards, Spec)

**Files :**
- Modify : `.claude/rules/frontend-session.md`, `frontend/src/presentation/ui/useFocusThenAnnounce.ts` (docblock)

- [ ] **Step 1 : relire** — `grep -rn "R4 to R9\|re-read on \`focus\`\|tâche 11 du plan\|un 401 du backoffice\|onBackofficeSessionEnded\|requestedLocale\|checkAuth().catch\|sessionExpired'" frontend/src .claude/rules` : chaque occurrence restante est soit corrigée par R12-R21, soit traitée ici ; aucune ne reste.
- [ ] **Step 2 : corriger** — `frontend-session.md:38` : la phrase « Later tasks of #748 (R4 to R9) add their sections here » retirée ; « Tests » (l. 288) : `SessionEndedBanner.spec.ts` ne teste plus « the re-read on `focus` » mais « the banner does not listen to `focus` » ; la liste des tests complétée de R12 à R21 (`csrfCookie.spec.ts`, `problemJson.spec.ts`, `AccessRefusedNotice.spec.ts`, `RefusedSessionCookiesAreClearedTest`…). `useFocusThenAnnounce.ts` : la page connaît aussi `isUnmounted`.
- [ ] **Step 3 : verts** — `tools/check-claude-rules.sh` ; `make front-lint`, `make front-build`.
- [ ] **Step 4 : commit** — `docs: la règle de session à jour de la deuxième relecture de #748`.

---

## Corrections de la troisième relecture (2026-10-29)

### Réponses de l'auteur aux questions de ce plan (2026-10-29)

Les quatre recommandations sont retenues : (1) même compte avec un rôle perdu → la navigation est rejouée ; (2) la
réaction du backoffice va dans `domain/admin/shared/` ; (3) rejeu unique d'« Accès instantané » dans #748, le pare-feu
sans authentificateur va dans le ticket `__Host-` ; (4) `clock_skew: 5`.


R12 à R22 et R21b sont faites (455c43ba → b4fda293). Quatre relectures du diff be0b8d8d...b4fda293
(`/code-review medium` CR3-*, audit de sécurité A3-*, axe Standards St3-*, axe Spec Sp3-*) : aucun critique ni haut.
Constats complets : rapport de la troisième relecture (fil principal). **Tous sont traités dans cette branche** par les
tâches R23 à R34, avant les tâches 11 et 12, sauf A3-3, rattaché au futur ticket `__Host-` avec F12 et S2 (tâche 12).

**Décisions de l'auteur (2026-10-29)**, non rediscutées ici :
1. **R21b est remplacée** : la déconnexion de l'accès de base à son échéance est retirée (minuterie qui appelle
   `POST /api/logout`, `knownRepository`, écouteurs `focus`/`visibilitychange`) ; l'expiration côté client redevient ce
   qu'elle était avant R21b (l'en-tête repasse à anonyme, sans appel réseau). À la place : **`credentials: 'omit'` sur
   chaque dépôt public qui n'a besoin d'aucun cookie**, et **un rejeu unique d'« Accès instantané » sur 401**.
2. **Tout est corrigé ici** : moyens, faibles, documentation, découpage de `useAuth.ts` et isolement de son état de
   module dans les tests. F12, S2 et A3-3 partent dans le ticket `__Host-`.

### Vérification de chaque constat dans le code (HEAD b4fda293)

- **CR3-1 = A3-1 = Sp3-1, A3-2, A3-6, St3-13, St3-6 : sans objet après la décision 1.** Tous visent le code de R21b
  (`endBaseAccessAtExpiry`, `expireBaseAccessIfDue`, `baseAccessExpiresAt`, `knownRepository`, `useAuth.ts:98-221`,
  `:443`), que R23 retire. Ce qui reste d'A3-2 : l'expiration côté client lit l'horloge du poste ; elle ne fait plus que
  repasser l'en-tête à anonyme (effet d'affichage, aucun appel réseau) — limite écrite par R23.
- **CR3-2, confirmé.** `StaleSessionCookiesClearingListener.php:56-57` pose deux `Set-Cookie` expirés **par nom** sur
  tout 401 de `JWT_INVALID`/`JWT_EXPIRED`. Le serveur ne connaît que le cookie que la requête portait ; un `Set-Cookie`
  ne peut pas être conditionnel à ce que le navigateur détient au moment où la réponse arrive. Choix : voir « Choix de
  conception », n° 5.
- **CR3-3 = A3-4, confirmé.** `HttpAboutContentRepository.ts:49`, `HttpQualityContentRepository.ts:19`,
  `HttpIncidentRepository.ts:19`, `HttpContributionRepository.ts:20`, `HttpWatchRepository.ts:21`,
  `HttpExperienceTechnologyRepository.ts:29`, `HttpContactRepository.ts:41`, `HttpAccountRepository.ts:43` appellent
  `fetch` sans `credentials` : la valeur par défaut, `same-origin`, envoie les cookies en préprod et en prod (une seule
  origine), pas en dev (Vite 5173, nginx 8080 : deux origines) — d'où un défaut invisible en dev. Leurs docblocks disent
  « pas de `credentials: 'include'` nécessaire », vrai en dev seulement. `SetPasswordPage` : route publique, le garde
  n'attend pas `checkAuth()` ; après R23 `validate` et la complétion partent sans cookie, et le 401 du premier
  `/api/me` (cookie mort) n'a aucun effet sur la page (vérifié : `useAccountPasswordSetup` ne lit pas la session ; la
  complétion répond 204 sans cookie, `.claude/rules/security-user.md`, donc `omit` ne perd rien).
- **CR3-4 = A3-8, confirmé.** `useAuth.ts:445-468` : `login()` hors session finie et `logout()` passent par
  `applySession` sans avancer `generation` ; `markBaseAccessExpired()` (`:291`) et `markBaseAccessGranted()` (`:276`)
  non plus. Un `/api/me` parti avant (premier `checkAuth`, `rereadAfterRefusal`) passe `applyReadSession` (`:477`,
  `sentAt === generation`) et réapplique l'ancien compte (ou l'anonyme par-dessus un accès de base tout juste obtenu).
- **CR3-5 = A3-7 = Sp3-2, confirmé.** `useAuth.ts:147` : `null === endedFor` vaut « le même compte ». `endedAccount` est
  lu à la **première** fin (`:326-328`) : après `rereadAfterRefusal` qui a appliqué le palier de base (`user` nul), il
  vaut `null`, et tout compte qui revient est rétabli en place. Les rôles ne sont pas comparés.
- **CR3-6, confirmé.** `rereadAfterRefusal` (`:560`) → `applyReadSession` → `applySession` d'un autre compte, sans la
  détection de R18 : en-tête de B au-dessus de la page de A.
- **CR3-8, confirmé.** `BackofficeHttpClient.ts:98` appelle `refused()` à la réception ; `AccessRefusedNotice.vue:49`
  efface le compteur au changement de `route.fullPath` : un 403 de la page quittée, arrivé après, le remonte sur la
  suivante.
- **CR3-9, confirmé.** `useAuth.ts:450` : en session finie sans `XSRF-TOKEN` lisible, la connexion passe par
  `applySession` ; `sessionEnded` reste vrai, `endedAccount` garde l'ancien compte.
- **CR3-10 = Sp3-9, confirmé.** `sessionAwareFetch.ts:71-72` lit le cookie deux fois (`hasCsrfToken()` puis
  `readCsrfToken()`) ; s'il disparaît entre les deux, le verdict dit « avec jeton » et aucun en-tête ne part : le 403 du
  CSRF serait lu comme un refus réel. Le commentaire « ne peuvent pas diverger » est faux.
- **A3-5, confirmé**, et vérifié côté serveur : `/api/account/base-access` est sous le pare-feu `api` (`jwt`) ; un
  `BEARER` mort y prend le 401 de Lexik (qui efface les deux cookies). `BaseAccessRateLimitRequestListener` (priorité
  15) passe **avant** le pare-feu (8) : la tentative refusée consomme un jeton du quota (20 par heure et par IP), le
  rejeu un second. Accepté et écrit (R24).
- **A3-9, confirmé.** Lexik 3.2.0, `LoadedJWS::checkIssuedAt()` : `iat - clock_skew > time()` → `INVALID` ;
  `clock_skew` vaut 0 par défaut (`Configuration.php:50`). Un `iat` en avance d'une seconde (deux pods aux horloges
  divergentes) est un 401 qui, depuis R21, efface la session. Effets vérifiés : `clock_skew` relâche aussi `exp`
  (`checkExpiration()` : un jeton expiré servi `clock_skew` secondes de plus) ; `JwtAuthenticationInstant` lit `iat`
  pour les dix minutes du consentement : un `iat` jusqu'à 5 s dans le futur donne un âge négatif, donc « récent » —
  sans conséquence (5 s) ; `CredentialsVersionClaim` ne lit aucune date.
- **A3-10, confirmé.** `LoginPage.vue:31` : `hasSessionEnded = isSessionEnded(route)` sans regarder la session ; une
  personne connectée qui ouvre `login?expired=1` (B/F2) lit « votre session a pris fin ». **Précision** : le lien
  `expired=1` s'ouvre dans un **nouvel onglet**, où `authState.sessionEnded` vaut toujours faux (application neuve) ; le
  critère juste est donc « un compte est affiché » (`user` non nul), pas `sessionEnded` seul.
- **A3-11, confirmé.** `CsrfCookieRequestSubscriber.php:47-48` : « jamais atteint avec une session ou un cookie
  BEARER » est faux en prod avant R23 ; vrai pour le SPA après (le navigateur d'un autre client peut toujours l'envoyer).
- **A3-13, confirmé.** `AppLayout.vue:43-55` : `restoreSession()` a appliqué le nouveau compte (l'en-tête change) avant
  que `router.replace` ne se résolve ; l'ancienne page reste rendue jusque-là.
- **Sp3-3, confirmé.** `useAuth.ts:480-484` : en session finie, une relecture qui trouve le palier de base passe par la
  branche `else` et pose « base » avec `sessionEnded` vrai ; `frontend-session.md:263-264` dit « changes nothing ».
- **Sp3-4, confirmé.** `useAdminAboutSettings.ts:61` : `lastFailure.value = null` à chaque `save()`, toutes langues ; un
  échec EN remplace l'erreur FR.
- **Sp3-5, confirmé.** `frontend-session.md:138` (« On return (`authState.sessionEnded` true to false) ») ; `:162` et
  `SessionEndedBanner.vue:12` nomment `backofficeSessionEndedHandler`, remplacé par
  `createBackofficeSessionEndedHandler` (R17).
- **Sp3-6, confirmé.** `AppHeader.vue:233-236` renvoie à « un vrai navigateur à 320 px » ; aucune règle ne parle des
  320 px (`grep 320 .claude/rules` : rien).
- **St3-1, confirmé (violation dure).** `content/fr.ts:145-146`, `content/en.ts:145-146` : `BEARER` « expire […] à
  l'expiration du jeton (1 h / 15 min) », `XSRF-TOKEN` « expire avec lui » — faux depuis F1 (cookies de session).
  Aucune autre mention publique fausse : `content/*.ts:114` parle du **jeton** (15 min, vrai), `cookieNotice.message`
  (`locales/*.json`) ne dit aucune durée.
- **St3-2, confirmé.** `useAuth.spec.ts:865-869` (« Le cookie D6 […] s'éteint seul après 15 min » : faux, c'est le JWT)
  et le titre `:883` « sans aucun appel réseau », que R21b contredisait (le test ne vérifie que `me`).
- **St3-3, confirmé.** `AuthCookieFactory.php:21-25,70,78` : `?int $expiresAt` « pour un appelant futur » ; aucun
  appelant ne le passe (`grep -rn "->bearer(\|->xsrf(" src`), seul `AuthCookieFactoryTest.php:86` l'exerce.
- **St3-4, confirmé.** `backofficeSessionExpiry.ts:3` (présentation) importe un type de `infrastructure/`. **Lieu
  retenu : `domain/`, pas `application/`** comme le suggérait le constat : aucun fichier d'`infrastructure/` n'importe
  `application/` aujourd'hui (vérifié), alors qu'infrastructure, application et présentation importent toutes `domain/`
  (les interfaces de dépôts y vivent). Voir « Choix », n° 7.
- **St3-5, confirmé.** `useAuth.ts` : 598 lignes, cinq responsabilités.
- **St3-7, confirmé.** Condition de descente écrite trois fois (`:450`, `:477-480`, `:528`) ;
  `onSessionRestored`/`onAccountSwitched` identiques à leur `Set` près (`:343-357`) ; la séquence « refus réel → relecture
  → `forbidden` devient `session-ended` si c'est encore lui » recopiée dans `useMcpConnections.ts:113-121`,
  `useOAuthConsent.ts:122-133`, `useAssistant.ts:141-149`.
- **St3-8, confirmé.** `BackofficeSessionEndedHandler` porte `stampAtSend` et `refused`.
- **St3-9, confirmé.** `loginLocation.ts:3,5` importe `reauthentication.ts` et `sessionEnded.ts`, qui l'importent.
- **St3-10, confirmé.** Aucun `setupFiles` (`vite.config.ts`) ; l'état de module de `useAuth` (`generation`,
  `endedAccount`, `refusalReread`, `endedSessionRecheck`, `hasCheckedOnce`, les deux `Set`) n'est remis à zéro nulle
  part ; `useAuth.spec.ts:57-61` ne remet que le palier ; `enableAutoUnmount` est appelé dans dix specs seulement.
- **St3-11, confirmé.** `frontend-session.md` : 44 564 octets, paragraphe « Tests » d'un seul bloc.
- **St3-12, confirmé.** `RefusedSessionCookiesAreClearedTest.php:110-111` : tout code sauf 401 sous 500 passe (un 403
  compris). `/api/contributions/fr` répond 200 sur une table vide (`ContributionResourceTest.php:86-88`).
- **Aucun constat écarté** : chacun est confirmé, ou sans objet après la décision 1 (ci-dessus).

### Choix de conception tranchés

1. **`credentials` toujours explicite** (CR3-3) : `'omit'` sur les huit dépôts publics (`about`, `quality`,
   `incidents`, `contributions`, `watch`, `experience`, `contact`, `account`), `'include'` sur ceux qui portent une
   session (`auth`, `baseAccess`, `caseStudies`, `anonymousCv`, `cv`), `sessionAwareFetch` ailleurs. Une garde de
   source refuse un `fetch(` d'`infrastructure/` sans `credentials` écrit (le défaut `same-origin` envoie les cookies en
   prod et pas en dev : c'est ce qui a caché le défaut). *Avantages* : un cookie mort n'atteint plus aucune page
   publique ; aucun cookie de session ne part vers une route qui n'en lit pas. *Inconvénient* : un dépôt public qui
   deviendrait authentifié doit changer sa valeur (la garde le rappelle). *Écarté* : `omit` décidé par une liste de
   chemins dans `sessionAwareFetch` (un second endroit qui sait ce qui est public).
2. **Le rejeu d'« Accès instantané » vit dans le dépôt** (`HttpBaseAccessRepository.grant()`, A3-5) : un 401 au premier
   essai = un cookie mort que ce 401 vient d'effacer ; un second essai, un seul. C'est un fait de transport, pas de
   session. Le quota paie deux jetons dans ce cas (vérifié, accepté). *Écarté* : un pare-feu sans authentificateur pour
   `/api/account/base-access` (comme `oauth`), qui supprimerait le 401 et le rejeu — il touche `security.yaml` et quatre
   gardes (`AccessControlAnchoringTest`, `ApiRouteExposureTest`, `RouterScopeTest`, les parités) contre la décision
   prise ; proposé dans le ticket `__Host-` (tâche 12).
3. **`useAuth.ts` découpé par responsabilité, API publique inchangée** (St3-5, St3-7) : cinq modules sous
   `application/auth/` (état et palier ; minuterie de l'accès de base ; génération ; signaux ; fin et retour), le
   composable et les deux relectures mutualisées restant dans `useAuth.ts`, qui **réexporte** tout ce qu'il exporte
   aujourd'hui (aucun site d'import ne change). La minuterie reçoit son rappel en paramètre : aucun cycle d'import.
   *Écarté* : un objet `SessionMachine` (une classe pour un état de module : même couplage, plus de cérémonie).
4. **Remise à zéro de test exportée, appelée par un `setupFiles` global** (St3-10) :
   `resetAuthStateForTests()` remet chaque module à son état de chargement ; `tests/support/setup.ts` l'appelle en
   `beforeEach` et pose `enableAutoUnmount(afterEach)` une fois pour toute la suite. *Écarté* : `vi.resetModules()` et
   des imports dynamiques (chaque spec à réécrire, et les modules qui importent `useAuth` garderaient l'ancienne
   instance).
5. **CR3-2 : réduire côté client, accepter et écrire le reste** — options (a) + (c) du constat. Le serveur ne peut pas
   savoir qu'un cookie plus neuf a été posé après l'envoi : un `Set-Cookie` n'est jamais conditionnel, et la requête
   n'apporte que le cookie mort. L'option (b) (n'effacer que si le JWT a expiré « depuis peu ») ne ferme pas la course,
   qui porte sur l'**ordre d'arrivée** des réponses, pas sur l'âge du jeton, et rouvrirait le 401 permanent d'un vieux
   cookie. Ce qui reste après R23 : seule une requête **authentifiée** encore en vol avec un cookie mort au moment où un
   autre onglet (ou celui-ci) reçoit des cookies neufs peut les effacer — une fenêtre de l'ordre d'un aller-retour, et
   la suite se répare d'elle-même (une lecture : 401, une écriture : 403 sans jeton, donc « session finie », bandeau,
   reconnexion). Écrit dans `security-authentication-sessions.md`, l'ADR 0003 et le docblock du listener (R29).
6. **« Le même compte » est une identité et des droits, retenue tant qu'elle s'affiche** (CR3-5, CR3-6, CR3-9) : un
   `lastAccount` (`username` et rôles), posé par chaque session appliquée **avec** un compte, gardé quand l'en-tête
   devient anonyme ou passe au palier de base (la page ouverte est toujours la sienne), oublié à `logout()` et à
   `markBaseAccessGranted()`. **À la descente** (retour après une session finie) : même `username` **et aucun rôle
   perdu** → rétabli en place ; sinon, ou aucun compte retenu (inconnu) → rejeu de la navigation (la garde rejuge la
   route). **À une relecture hors session finie** (`rereadAfterRefusal`, `checkAuth`) : seul un **autre `username`**
   rejoue ; un rôle perdu y reste traité en place par R7/R17 (alerte, saisie gardée — décision de l'auteur). *Écarté* :
   l'égalité stricte des rôles (un rôle **gagné** démontait la page pour rien).
7. **L'abstraction de la réaction du backoffice va dans `domain/admin/shared/`** (St3-4, St3-8) :
   `BackofficeSessionReaction` (nom qui dit ce que fait le type) et `BackofficeSendStamp`. Raison du lieu : voir St3-4
   ci-dessus. Le compteur de refus et la fabrique restent où ils sont.
8. **Un refus périmé ne compte pas** (CR3-8) : le timbre de l'envoi porte aussi un « périmètre de refus » que
   `clearBackofficeAccessRefusal()` avance ; un refus arrivé sous un autre périmètre n'incrémente rien et ne relit pas
   le palier. Même mécanique que la génération de session, pour la navigation. *Écarté* : estampiller par
   `route.fullPath` (l'infrastructure ne connaît pas la route, et deux visites du même chemin se confondraient).
9. **`clock_skew: 5`** (A3-9) : tolère cinq secondes d'écart entre pods sur `iat` (et `exp`) ; pas plus — c'est aussi
   le prolongement d'un jeton expiré.
10. **`$expiresAt` retiré de `bearer()`/`xsrf()`** (St3-3) : une capacité sans appelant, qui inviterait un cookie
    persistant contre F1 ; un test de réflexion épingle l'unique paramètre.

### Review Focus (troisième relecture)

1. **Une page publique avec un `BEARER` mort, en même origine** : aucune requête publique ne porte de cookie, aucune ne
   prend de 401. Test : R23, la garde `credentialsAreExplicit.spec.ts` et chaque spec de dépôt (`credentials: 'omit'`).
2. **« Accès instantané » avec un cookie mort** : un 401 puis un second essai qui aboutit ; un second 401 est une erreur,
   jamais une boucle. Test : R24.
3. **Une déconnexion pendant qu'un `/api/me` est en vol** : l'en-tête reste anonyme. Test : R26.
4. **Un autre compte revient après une fin de session survenue au palier de base** (aucun compte affiché) : la
   navigation est rejouée, rien n'est « rétabli ». Test : R26.
5. **Le 403 d'une page d'administration quittée arrive sur la suivante** : aucune alerte « accès refusé », aucune
   relecture. Test : R28.

### Contraintes propres aux tâches R23 à R34

- Les **Global Constraints**, les « Contraintes propres aux tâches R » et celles de R12 à R22 valent toutes. Exécution :
  un agent par tâche (sonnet, effort high), successifs dans l'ordre R23 → R34, `superpowers:test-driven-development`,
  un commit par tâche, fichiers nommés (`git add <fichiers>`, `git rm` pour une suppression), message en français
  terminé par les lignes d'attribution que donne le fil principal.
- Frontend : test ciblé `docker compose exec frontend npm test -- <chemins>`, puis `make front-test`, `make front-lint`,
  `make front-build`. Vitest 5 vide les imports CSS (lire par `node:fs`) ; axe-core se bloque sous faux minuteurs
  (`vi.useRealTimers()` avant `expectNoAccessibilityViolation`).
- Backend (R29) : lire avec `Read` un fichier de `backend/src/Security/Authentication/Infrastructure/` avant d'écrire ;
  test ciblé `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis `make back-test` et
  `make back-quality` (toujours `-u dev`) ; un JWT de test se signe par l'encodeur du conteneur
  (`JWTEncoderInterface`), jamais de secret littéral (`TestCredentials`, `TestKeys::material()`). Après un changement de
  `config/packages/*.yaml` : `rm -rf var/cache/test` avant de croire un test.
- **Refactoring pur** (R25, la partie « séquence » de R27) : `make front-test` vert avant la première modification
  (nombre de tests noté) et après la dernière ; seuls changent les tests que la tâche nomme.
- **Taille de `frontend-session.md` jusqu'à R34** : `tools/check-claude-rules.sh` après chaque modification d'une règle.
  Une tâche **remplace** des phrases plutôt que d'en ajouter ; si `wc -c .claude/rules/frontend-session.md` passerait
  49 500 octets, le texte nouveau va dans `tasks/regle-session-en-attente.md` (une section par tâche, la phrase exacte et
  l'endroit visé), que R34 intègre. `mcp-oauth.md` (~41 Ko), `mcp-oauth-revocation-personne.md` (~40 Ko),
  `security-authentication-sessions.md` : une phrase remplacée, jamais un paragraphe ajouté sans mesurer.
- Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets ; ne jamais nommer un compte
  réel. Pas de push, pas de PR.

### Fichiers (tâches R23 à R34)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `frontend/src/application/auth/useAuth.ts` | retrait de R21b ; puis le composable, les deux relectures, les réexports | R23, R25, R26, R27 |
| `frontend/src/application/auth/{sessionState,baseAccessExpiry,sessionGeneration,sessionEvents,sessionLifecycle,resetAuthStateForTests}.ts` (nouveaux) | le découpage | R25, R26 |
| `frontend/src/infrastructure/{about,quality,incidents,contributions,watch,experience,contact,account}/Http*Repository.ts` | `credentials: 'omit'` | R23 |
| `frontend/tests/infrastructure/http/credentialsAreExplicit.spec.ts` (nouveau) | garde de source | R23 |
| `frontend/src/infrastructure/baseAccess/HttpBaseAccessRepository.ts` | rejeu unique sur 401 | R24 |
| `frontend/src/infrastructure/auth/HttpAuthRepository.ts` | commentaire de `logout()` | R23 |
| `frontend/vite.config.ts`, `frontend/tests/support/setup.ts` (nouveau) | `setupFiles`, remise à zéro, `enableAutoUnmount` | R25 |
| `frontend/src/presentation/layout/AppLayout.vue` | page masquée pendant le rejeu | R27 |
| `frontend/src/application/{mcp/useMcpConnections,mcp/useOAuthConsent,assistant/useAssistant}.ts` | séquence du refus réel factorisée | R27 |
| `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts` (nouveau), `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/main.ts` | réaction renommée, refus périmé | R28 |
| `frontend/src/infrastructure/{http/sessionAwareFetch,auth/csrfCookie}.ts` | une seule lecture du cookie | R28 |
| `frontend/src/presentation/pages/LoginPage.vue`, `frontend/src/presentation/router/{loginLocation,sessionEnded,reauthentication}.ts` | A3-10, cycle d'import | R30 |
| `frontend/src/application/admin/about/useAdminAboutSettings.ts` | une erreur par langue | R31 |
| `frontend/src/infrastructure/portfolio/content/{fr,en}.ts` | politique de cookies | R32 |
| `backend/config/packages/lexik_jwt_authentication.yaml`, `backend/src/Security/Authentication/Infrastructure/{Http/AuthCookieFactory,Http/CsrfCookieRequestSubscriber,Jwt/StaleSessionCookiesClearingListener}.php`, `backend/src/Security/User/Presentation/Controller/BaseAccessController.php` | `clock_skew`, `$expiresAt`, docblocks, CR3-2 | R29 |
| `.claude/rules/frontend-session*.md`, `.claude/CLAUDE.md` | découpage par sous-domaine | R34 |

---

### Task R23 : R21b retirée ; les dépôts publics partent sans cookie (décision 1, CR3-3/A3-4, CR3-1/A3-1/Sp3-1, A3-2, A3-6, St3-13, St3-6, St3-2)

**Files :**
- Modify : `frontend/src/application/auth/useAuth.ts`, `frontend/src/infrastructure/auth/HttpAuthRepository.ts` (commentaire), les huit dépôts publics du tableau ci-dessus
- Create : `frontend/tests/infrastructure/http/credentialsAreExplicit.spec.ts`
- Test : `frontend/tests/application/auth/useAuth.spec.ts`, les huit specs `frontend/tests/infrastructure/{about,quality,incidents,contributions,watch,experience,contact,account}/Http*Repository.spec.ts`
- Modify : `.claude/rules/frontend-session.md` (« Accepted limits »), `.claude/rules/frontend.md` (« Base access », et la phrase des dépôts publics de « API-backed content »), `.claude/rules/security-authentication-sessions.md` (puce `StaleSessionCookiesClearingListener`, « Accepted limit »), `docs/adr/0003-paliers-d-acces.md` (amendement du 2026-10-29)

**Interfaces :**
- Retirés de `useAuth.ts` : `baseAccessExpiresAt`, `knownRepository`, `expireBaseAccessIfDue`, `endBaseAccessAtExpiry` et les écouteurs `focus`/`visibilitychange`. `scheduleBaseAccessExpiry(expiresAt)` arme de nouveau `setTimeout(() => { baseAccessExpiryTimer = null; markBaseAccessExpired() }, delay)`, comme avant 607a693d.
- `HttpAuthRepository.logout()` **reste** sur `sessionAwareFetch` (une seule définition de « avec jeton », R12/J) ; seul son commentaire perd la mention de R21b.
- Chaque dépôt public : `fetch(url, { method, …, credentials: 'omit' })`.
- `credentialsAreExplicit.spec.ts` : lit `src/infrastructure/**/*.ts` (`import.meta.glob(…, { query: '?raw', import: 'default', eager: true })`, comme `colorClasses.spec.ts`) ; chaque fichier qui appelle `fetch(` doit être `sessionAwareFetch.ts`, ou écrire `credentials: 'omit'`, ou être dans `SESSION_REPOSITORIES` (`HttpAuthRepository`, `HttpBaseAccessRepository`, `HttpCaseStudyRepository`, `HttpAnonymousCvRepository`, `HttpCvRepository`, chacun avec sa raison) et écrire `credentials: 'include'` ; une entrée de `SESSION_REPOSITORIES` qui n'écrit plus `fetch(` fait échouer la garde.

- [ ] **Step 1 : filet** — `make front-test` vert, nombre noté.
- [ ] **Step 2 : tests, rouges**
  - `credentialsAreExplicit.spec.ts` : `it("tout fetch d'infrastructure écrit ses credentials")` (rouge : huit fichiers nommés) ; `it("chaque dépôt de session est encore un appelant de fetch")`.
  - Chacune des huit specs de dépôt : l'assertion `toHaveBeenCalledWith(url, {…})` existante prend `credentials: 'omit'` (changement exprès) ; `HttpAccountRepository.spec.ts` le vérifie pour `validate` **et** la complétion.
  - `useAuth.spec.ts` : le `describe("déconnexion à l'échéance, avant le 401 (R21b)")` est supprimé (neuf tests) ; « à l'échéance annoncée, le palier de base retombe à anonyme sans aucun appel réseau » ajoute `expect(repository.logout).not.toHaveBeenCalled()` (rouge tant que R21b est là) ; nouveau `it("le retour sur l'onglet après l'échéance ne fait aucun appel réseau")` (faux minuteurs, `window.dispatchEvent(new Event('focus'))` après l'échéance → ni `logout` ni `me`) ; le commentaire `:865-869` dit le vrai (le JWT meurt à 15 min ; le cookie, de session, reste jusqu'à la fermeture ou au premier 401 qui l'efface).
  Run : `docker compose exec frontend npm test -- tests/application/auth/useAuth.spec.ts tests/infrastructure`. Expected : la garde nomme les huit dépôts ; `logout` appelé à l'échéance ; `credentials` absent.
- [ ] **Step 3 : implémenter** — selon les interfaces ; les docblocks des huit dépôts disent pourquoi `omit` (une seule origine en prod : le défaut `same-origin` enverrait un `BEARER` mort, et son 401 tomberait sur une page publique ; aucun de ces endpoints ne lit une identité) au lieu de « pas de `credentials: 'include'` nécessaire ». `HttpAccountRepository` : la complétion ne pose aucun cookie (204), `omit` ne perd rien.
- [ ] **Step 4 : règles et ADR** — `frontend-session.md`, « Accepted limits » : la puce R21b est **remplacée** par : après l'`exp` d'un JWT (login ou accès de base), les lectures publiques, le contact et le choix du mot de passe partent sans cookie (`credentials: 'omit'`, garde `credentialsAreExplicit.spec.ts`) et ne prennent aucun 401 ; le premier 401 ne reste que sur les routes authentifiées, où il est lu comme une session finie, et sur « Accès instantané », qui rejoue (R24) ; l'accès de base expire côté client sur l'horloge du poste, effet d'affichage seulement (A3-2). `frontend.md`, « Base access » : l'en-tête repasse à anonyme à `expiresAt` (ou au premier 401 d'un contenu du palier), sans appel réseau ; « API-backed content » : ces dépôts envoient `credentials: 'omit'`. `security-authentication-sessions.md` : la phrase « The base access avoids it by a client-side sign-out at `expiresAt` (R21b) » devient « the SPA's public reads, contact and password setup send no cookie (`credentials: 'omit'`, #748 R23), so it only reaches an authenticated route, read as an ended session ». ADR 0003, amendement du 2026-10-29 : la phrase « l'accès de base l'évite […] (tâche R21b de #748) » remplacée de même (décision de l'auteur du 2026-10-29, R23, qui remplace R21b).
- [ ] **Step 5 : verts** — specs ciblés PASS ; `make front-test` (filet − 9 + nouveaux), `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 6 : commit** — `fix(frontend): les dépôts publics partent sans cookie ; l'accès de base n'appelle plus le logout à son échéance (#748)`.

---

### Task R24 : « Accès instantané » rejoue une fois sur 401 (A3-5)

**Files :**
- Modify : `frontend/src/infrastructure/baseAccess/HttpBaseAccessRepository.ts`
- Test : `frontend/tests/infrastructure/baseAccess/HttpBaseAccessRepository.spec.ts`
- Modify : `.claude/rules/frontend.md` (« Base access »), `.claude/rules/security-authentication.md` (puce « Base access », une phrase)

**Interfaces :**
- `grant()` : un premier 401 → un second `fetch` identique (`credentials: 'include'`, `LOGIN_CSRF_HEADER`) ; la réponse du second est jugée comme aujourd'hui (429 → `rate-limited`, autre échec → `unknown`, 401 compris). Jamais de troisième.

- [ ] **Step 1 : tests, rouges** — `it("un 401 (cookie mort, effacé par ce 401) : un second essai, qui aboutit")` (deux appels, `expiresAt` du second) ; `it("deux 401 : BaseAccessError('unknown'), deux appels seulement")` ; `it("un 429 au premier essai : aucun rejeu")` ; `it("un échec réseau au premier essai : aucun rejeu, le rejet remonte")`. Expected : un seul appel, `unknown`.
- [ ] **Step 2 : implémenter** — une boucle bornée à deux essais ou un appel répété ; docblock : pourquoi (le pare-feu `api` lit le `BEARER` sur cette route ; son 401 efface les deux cookies, `StaleSessionCookiesClearingListener` ; le second essai part sans cookie), et le prix (le limiteur, priorité 15, passe avant le pare-feu : deux jetons du quota).
- [ ] **Step 3 : règles** — `frontend.md`, « Base access » : le rejeu unique et son prix. `security-authentication.md`, puce « Base access » : une phrase — un `BEARER` refusé y prend le 401 du pare-feu après que le limiteur a consommé un jeton ; le SPA rejoue une fois (#748, R24).
- [ ] **Step 4 : verts** — spec ciblé PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): « Accès instantané » rejoue une fois après le 401 d'un cookie mort (#748)`.

---

### Task R25 : `useAuth.ts` découpé ; l'état de module remis à zéro avant chaque test (St3-5, St3-10, St3-7 pour la descente et les signaux ; refactoring pur)

**Files :**
- Create : `frontend/src/application/auth/{sessionState,baseAccessExpiry,sessionGeneration,sessionEvents,sessionLifecycle,resetAuthStateForTests}.ts`, `frontend/tests/support/setup.ts`
- Modify : `frontend/src/application/auth/useAuth.ts`, `frontend/vite.config.ts` (`test.setupFiles: ['tests/support/setup.ts']`), les dix specs qui appellent `enableAutoUnmount` (retirer l'appel local : VTU refuse un second `enableAutoUnmount`)
- Test : la suite existante (filet) ; `frontend/tests/application/auth/resetAuthStateForTests.spec.ts` (nouveau)
- Modify : `.claude/rules/frontend-session.md` (les `paths:` et « The interface » : où vit quoi), `.claude/rules/frontend-a11y.md` (dernier paragraphe : `enableAutoUnmount` global)

**Interfaces** (tout sous `application/auth/`, chaque fichier avec son docblock en français) :
- `sessionState.ts` : `state` (réactif, écrit par les modules d'`auth/` seulement), `authState = readonly(state)`, `applySession(session: AuthSession): void` (ne touche pas `sessionEnded`, efface la minuterie), `waitForAuthCheck(): Promise<void>`, `markBaseAccessGranted(expiresAt?: Date | null): void`, `markBaseAccessExpired(): void`, `isFirstCheckDone(): boolean`, `markFirstCheckDone(): void`.
- `baseAccessExpiry.ts` : `scheduleBaseAccessExpiry(expiresAt: Date, onExpiry: () => void): void`, `clearBaseAccessExpiry(): void`, `recallBaseAccessExpiry(): Date | null` — n'importe rien d'`auth/` (le rappel est passé par `sessionState`).
- `sessionGeneration.ts` : `sessionStamp(): number`, `advanceGeneration(): void`, `isCurrentGeneration(sentAt: number): boolean`.
- `sessionEvents.ts` : `onSessionRestored(callback): void`, `onAccountSwitched(callback): void` (une fonction `subscribe(set, callback)` commune, St3-7), `notifySessionRestored(): void`, `notifyAccountSwitched(): void` (l'isolement des abonnés qui lèvent, inchangé).
- `sessionLifecycle.ts` : `markSessionExpired(sentAt?: number): boolean`, `restoreSession(session: AuthSession, announce: boolean): void`, `canRestore(session: AuthSession, hasWriteToken: () => boolean): boolean` (la condition de descente écrite **une fois** : session finie, un compte, un jeton d'écriture — St3-7), l'`endedAccount` actuel (R26 le remplace).
- `useAuth.ts` : `AUTH_REPOSITORY`, `RefusalReread`, `UseAuthResult`, `useAuth()` (login, logout, `applyReadSession`, `checkAuth`, `recheckEndedSession`, `rereadAfterRefusal`, `endedSessionRecheck`, `refusalReread`) et **les réexports** de `authState`, `waitForAuthCheck`, `markBaseAccessGranted`, `markBaseAccessExpired`, `sessionStamp`, `markSessionExpired`, `onSessionRestored`, `onAccountSwitched`, `ROLE_SUPER` : aucun import d'un autre fichier ne change.
- `resetAuthStateForTests(): void` : remet l'état à celui du chargement (anonyme, `isChecking` vrai, `sessionEnded` faux, compteur 0), `hasCheckedOnce`, la génération, `endedAccount`, les deux promesses en vol, vide les deux `Set`, efface la minuterie et la clé `localStorage`. Chaque module exporte sa propre remise à zéro (`reset…ForTests`), celle-ci les appelle toutes ; docblock : réservée aux tests, jamais appelée par `src/`.
- `tests/support/setup.ts` : `beforeEach(resetAuthStateForTests)`, `enableAutoUnmount(afterEach)`.

- [ ] **Step 1 : filet** — `make front-test` vert, nombre noté.
- [ ] **Step 2 : test, rouge** — `resetAuthStateForTests.spec.ts` : `it("remet chaque état de module à celui du chargement")` (une session finie, une génération avancée, un abonné, une minuterie armée, une relecture en vol → après la remise à zéro : `authState` initial, `sessionStamp()` 0, l'abonné plus appelé, la minuterie n'agit plus, `rereadAfterRefusal()` relance un `me`). Expected : module introuvable.
- [ ] **Step 3 : découper** — déplacer le code tel quel (comportement inchangé), docblocks déplacés avec lui ; `useAuth.ts` ne garde que ce que dit l'interface. Le `beforeEach` de `useAuth.spec.ts` (« on le remet à "non connecté" ») devient inutile pour la remise à zéro : le garder s'il sert à poser `isChecking` faux, et le dire.
- [ ] **Step 4 : brancher la remise à zéro** — `setupFiles` ; retirer les dix `enableAutoUnmount` locaux (VTU lève « enableAutoUnmount cannot be called more than once » ; un `wrapper.unmount()` manuel suivi du démontage automatique ne donne qu'un avertissement de Vue, sans échec). Une spec qui devient rouge **dépendait de l'état laissé par un test précédent** : poser cet état explicitement dans le test (jamais retirer la remise à zéro) et le noter dans le message de commit.
- [ ] **Step 5 : règles** — `frontend-session.md` : `paths:` gagne `frontend/tests/support/setup.ts` ; « The interface » : où vit chaque responsabilité (une phrase par module) et la remise à zéro de test. `frontend-a11y.md` : « `enableAutoUnmount` est posé une fois, par `tests/support/setup.ts` ».
- [ ] **Step 6 : verts** — `make front-test` (le filet + 1, aucun test retiré), `make front-lint`, `make front-build`, `tools/check-claude-rules.sh` ; `wc -l src/application/auth/*.ts` noté dans le message.
- [ ] **Step 7 : commit** — `refactor(frontend): useAuth découpé par responsabilité, son état remis à zéro avant chaque test (#748)`.

---

### Task R26 : La machine d'état de la session (CR3-4/A3-8, CR3-5/A3-7/Sp3-2, CR3-6, CR3-9, Sp3-3)

**Files :**
- Modify : `frontend/src/application/auth/{sessionState,sessionLifecycle,useAuth}.ts`
- Test : `frontend/tests/application/auth/useAuth.spec.ts`, `frontend/tests/presentation/layout/AppLayout.spec.ts`
- Modify : `.claude/rules/frontend-session.md` (« The session generation », « Only the same account… », « A real refusal »)

**Interfaces :**
- **Toute session décidée dans l'onglet avance la génération** (CR3-4) : `login()` (les deux branches), `logout()` (avant l'envoi **et** à l'application de l'anonyme), `markBaseAccessGranted()`, `markBaseAccessExpired()` quand il change le palier. Une session **lue** (`applyReadSession`) n'avance rien : elle est la réponse.
- `interface LastAccount { readonly username: string; readonly roles: readonly string[] }` (dans `sessionLifecycle.ts`) ; `lastAccount: LastAccount | null` remplace `endedAccount` (choix 6) : posé par `applySession` quand `session.user` n'est pas nul, gardé quand l'en-tête devient anonyme ou passe au palier de base, remis à `null` par `logout()` et `markBaseAccessGranted()`.
- `isSameAccount(previous: LastAccount | null, user: AuthenticatedUser): boolean` (dans `sessionLifecycle.ts`) : `previous` non nul, même `username`, et chaque rôle de `previous` présent dans `user.roles`. À la descente annoncée : vrai → `notifySessionRestored()` ; faux → `notifyAccountSwitched()` (aucun compte retenu compris, CR3-5).
- `applyReadSession` hors session finie (CR3-6) : `lastAccount` non nul et un compte lu d'un autre `username` → la session appliquée, puis `notifyAccountSwitched()`. Un compte au même `username` (rôles changés ou non) ou un palier sans compte : appliqué, aucun signal (R7/R17 restent en place).
- `applyReadSession` en session finie (Sp3-3) : seul un compte qui `canRestore` change quelque chose ; le palier de base ou l'anonyme rendent `false`, rien n'est posé.
- `login()` en session finie (CR3-9) : `restoreSession(session, false)` **sans** condition de jeton d'écriture — une connexion réussie est toujours un retour (le backend pose `XSRF-TOKEN` à la connexion ; s'il manque, la première écriture prendra le 403 et refinira la session, état cohérent).

- [ ] **Step 1 : tests, rouges** (`useAuth.spec.ts`, sauf mention)
  - CR3-4 : `it("logout pendant qu'un /api/me est en vol : l'en-tête reste anonyme")` (`me` différé, `checkAuth()`, `logout()`, puis `me` rend le compte → anonyme) ; `it("login pendant qu'un /api/me est en vol : la réponse ne remplace pas le compte connecté")` ; `it("accès de base obtenu pendant qu'un /api/me est en vol : la réponse anonyme ne l'efface pas")` ; `it("échéance de l'accès de base pendant qu'un /api/me est en vol : le palier de base ne revient pas")`.
  - CR3-5 (Review Focus 4) : `it("fin de session après une relecture au palier de base, puis un autre compte revient : onAccountSwitched, pas onSessionRestored")` ; `it("le même compte revient avec un rôle de moins : onAccountSwitched")` ; `it("le même compte revient avec un rôle de plus : onSessionRestored")` ; `it("aucun compte retenu (fin au palier de base dès le départ) : onAccountSwitched")`.
  - CR3-6 : `it("relecture après refus qui trouve un autre compte : la session appliquée et onAccountSwitched")` ; `it("relecture après refus qui trouve le même compte, rôles réduits : aucun signal")`. `AppLayout.spec.ts` : `it("un refus réel dont la relecture trouve un autre compte rejoue la navigation")`.
  - CR3-9 : `it("connexion en session finie sans XSRF-TOKEN lisible : la session n'est plus finie, aucun signal")`.
  - Sp3-3 : `it("session finie, une relecture trouve le palier de base : rien ne change (ni palier ni sessionEnded)")`.
  Expected : compte réappliqué après logout ; `onSessionRestored` appelé pour B ; `sessionEnded` resté vrai ; palier « base » posé.
- [ ] **Step 2 : implémenter** — selon les interfaces ; les tests de R14 et R18 restent verts (un test qui comptait sur « aucun compte retenu = le même » change exprès : le dire).
- [ ] **Step 3 : règle** — `frontend-session.md` : toute session décidée dans l'onglet avance la génération (pourquoi : CR3-4) ; « le même compte » = `lastAccount` (choix 6 : identité et aucun rôle perdu à la descente ; seul l'identifiant à une relecture hors session finie) ; `login` rétablit toujours ; une relecture en session finie ne pose qu'un compte qui peut rétablir. Remplacer, ne pas ajouter (contrainte de taille).
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): toute session décidée dans l'onglet périme les relectures ; un compte inconnu ou réduit rejoue la navigation (#748)`.

---

### Task R27 : La page masquée pendant le rejeu ; la suite d'un refus réel écrite une fois (A3-13, St3-7 pour les composables)

**Files :**
- Modify : `frontend/src/presentation/layout/AppLayout.vue`, `frontend/src/application/auth/useAuth.ts`, `frontend/src/application/mcp/{useMcpConnections,useOAuthConsent}.ts`, `frontend/src/application/assistant/useAssistant.ts`
- Test : `frontend/tests/presentation/layout/AppLayout.spec.ts`, `frontend/tests/application/auth/useAuth.spec.ts` ; filet : `useMcpConnections.spec.ts`, `useOAuthConsent.spec.ts`, `useAssistant.spec.ts`
- Modify : `.claude/rules/frontend-session.md` (« Only the same account… », « A real refusal »)

**Interfaces :**
- `AppLayout` : `isReplaying = ref(false)`, mis à `true` **de façon synchrone** dans le rappel d'`onAccountSwitched`, remis à `false` dans le `finally` de `replayNavigation`, après l'avance de `pageKey` ; le composant routé est rendu `v-if="!isReplaying"`.
- `UseAuthResult.followRefusal(isStillRefused: () => boolean, showSessionEnded: () => void): void` — `void rereadAfterRefusal().then(…)` : sur `'session-ended'`, `showSessionEnded()` si `isStillRefused()`. Les trois composables l'appellent à la place de leur copie (`refusal`/`failure`/`error` passés par les deux fonctions).

- [ ] **Step 1 : filet** — `make front-test` vert, noté.
- [ ] **Step 2 : tests, rouges**
  - `AppLayout.spec.ts` (A3-13) : `it("pendant le rejeu, l'ancienne page n'est plus rendue")` — une garde dont la promesse est retenue ; après `notifyAccountSwitched` (par `recheckEndedSession` d'un autre compte) et `nextTick`, la sonde de page est absente ; la garde libérée, la page est remontée (2 montages) ; `it("un rejeu qui échoue rend quand même la page")`.
  - `useAuth.spec.ts` : `it("followRefusal : session finie et refus toujours affiché → showSessionEnded")`, `it("… refus remplacé entre-temps → rien")`, `it("… session tenue → rien")`.
  Expected : la sonde présente pendant le rejeu ; `followRefusal` absent.
- [ ] **Step 3 : implémenter** — selon les interfaces. Les specs des trois composables restent vertes **sans changement** (refactoring de la séquence).
- [ ] **Step 4 : règle** — `frontend-session.md` : la page masquée pendant le rejeu (pourquoi : l'en-tête du nouveau compte au-dessus de la page de l'ancien) ; « A real refusal » : `followRefusal`, la suite écrite une fois.
- [ ] **Step 5 : verts** — `make front-test` (filet + nouveaux), `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 6 : commit** — `fix(frontend): la page d'un autre compte n'est plus rendue pendant le rejeu ; la suite d'un refus réel écrite une fois (#748)`.

---

### Task R28 : Le backoffice — un refus périmé ne compte pas, la réaction nommée et rangée ; le cookie CSRF lu une fois (CR3-8, St3-8, St3-4, CR3-10/Sp3-9)

**Files :**
- Create : `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts`
- Modify : `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/main.ts`, `frontend/src/infrastructure/http/sessionAwareFetch.ts`, `frontend/src/infrastructure/auth/csrfCookie.ts`
- Test : `BackofficeHttpClient.spec.ts`, `backofficeSessionExpiry.spec.ts`, `AdminLayout.sessionEnded.spec.ts`, `AdminWatchPage.spec.ts`, `HttpAdminUserRepository.spec.ts` (renommage), `sessionAwareFetch.spec.ts`, `csrfCookie.spec.ts`
- Modify : `.claude/rules/frontend-session.md` (« The interface », « A real refusal », « The rule, in one function »), `.claude/rules/frontend-backoffice.md` (puce « A 401, or a 403… »)

**Interfaces :**
- `domain/admin/shared/BackofficeSessionReaction.ts` : `interface BackofficeSendStamp { readonly session: number; readonly refusalScope: number }` ; `interface BackofficeSessionReaction { stampAtSend(): BackofficeSendStamp; ended(sentAt: BackofficeSendStamp): boolean; refused(sentAt: BackofficeSendStamp): void }`.
- `BackofficeHttpClient` : `setBackofficeSessionReaction(reaction: BackofficeSessionReaction | null): void` remplace `setBackofficeSessionEndedHandler` ; le type n'est plus déclaré ni réexporté ici.
- `backofficeAccessRefusal.ts` : `refusalScope(): number` ; `clearBackofficeAccessRefusal()` avance le périmètre ; `markBackofficeAccessRefused(sentIn: number): boolean` — `false` sans rien changer si `sentIn` n'est plus le périmètre courant.
- `backofficeSessionExpiry.ts` : `createBackofficeSessionReaction(auth: Pick<UseAuthResult, 'rereadAfterRefusal'>): BackofficeSessionReaction` remplace `createBackofficeSessionEndedHandler` ; `stampAtSend` = `{ session: sessionStamp(), refusalScope: refusalScope() }`, `ended(s)` = `markSessionExpired(s.session)`, `refused(s)` : si `markBackofficeAccessRefused(s.refusalScope)`, `void auth.rereadAfterRefusal()`.
- `csrfCookie.ts` : `readWriteToken(): string | null` — la valeur lue, décodée et non vide, sinon `null` ; `hasCsrfToken()` = `null !== readWriteToken()` (une définition). `sessionAwareFetch` : `const csrfToken = isWrite ? readWriteToken() : null`, verdict sur `isWrite && null === csrfToken` — **une lecture**.

- [ ] **Step 1 : tests, rouges**
  - `backofficeSessionExpiry.spec.ts` (Review Focus 5) : `it("un refus parti avant une navigation n'est ni compté ni relu")` (`stampAtSend()`, `clearBackofficeAccessRefusal()`, `refused(stamp)` → compteur 0, `rereadAfterRefusal` jamais appelé) ; `it("un refus du périmètre courant compte et relit")`.
  - `AdminLayout.sessionEnded.spec.ts` : `it("le 403 d'une page quittée, arrivé sur la suivante : aucune alerte")` (requête différée, navigation, puis 403).
  - `BackofficeHttpClient.spec.ts` : `refused` reçoit le timbre lu à l'envoi (ordre des appels) ; les specs qui enregistrent une réaction passent par `setBackofficeSessionReaction` (renommage exprès).
  - `sessionAwareFetch.spec.ts` (CR3-10) : `it("le cookie n'est lu qu'une fois : il disparaît entre deux lectures, l'en-tête part et le verdict suit ce qui est parti")` (`vi.spyOn(document, 'cookie', 'get')` : la valeur, puis `''`) ; `csrfCookie.spec.ts` : `readWriteToken` (absent, vide, indécodable → `null` ; une valeur → elle).
  Expected : compteur à 1 ; alerte affichée ; deux lectures du cookie.
- [ ] **Step 2 : implémenter** — selon les interfaces ; `main.ts` enregistre `createBackofficeSessionReaction(useAuth())` ; le commentaire de `sessionAwareFetch` (« ne peuvent pas diverger ») dit la vraie raison : une seule lecture.
- [ ] **Step 3 : règles** — `frontend-session.md` et `frontend-backoffice.md` : les nouveaux noms, le lieu du type (domaine, choix 7), le périmètre de refus (choix 8), « une lecture du cookie » ; remplacer, ne pas ajouter.
- [ ] **Step 4 : verts** — `grep -rn "SessionEndedHandler" frontend/src frontend/tests .claude/rules` ne rend rien d'autre que ce que R33 corrige (le noter) ; specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): un refus du backoffice parti avant une navigation ne s'affiche plus ; la réaction nommée, rangée dans le domaine ; le cookie CSRF lu une fois (#748)`.

---

### Task R29 : Backend — `$expiresAt` retiré, `clock_skew`, un test plus strict, la limite du 401 tardif écrite (St3-3/CR3-10, A3-9, St3-12, CR3-2, A3-11)

**Files :**
- Modify : `backend/src/Security/Authentication/Infrastructure/Http/AuthCookieFactory.php`, `backend/config/packages/lexik_jwt_authentication.yaml`, `backend/src/Security/Authentication/Infrastructure/Jwt/StaleSessionCookiesClearingListener.php` (docblock), `backend/src/Security/Authentication/Infrastructure/Http/CsrfCookieRequestSubscriber.php` (docblock d'`EXCLUDED_PATHS`), `backend/src/Security/User/Presentation/Controller/BaseAccessController.php` (commentaire)
- Create : `backend/tests/Security/Authentication/Infrastructure/Jwt/JwtClockSkewTest.php`
- Test : `backend/tests/Security/Authentication/Infrastructure/Http/AuthCookieFactoryTest.php`, `backend/tests/Security/Authentication/Infrastructure/Jwt/RefusedSessionCookiesAreClearedTest.php`
- Modify : `.claude/rules/security-authentication-sessions.md` (puces `AuthCookieFactory` et `StaleSessionCookiesClearingListener`, une sur `clock_skew`), `docs/adr/0003-paliers-d-acces.md` (amendement du 2026-10-29 : une phrase sur CR3-2)

**Interfaces :**
- `AuthCookieFactory::bearer(#[SensitiveParameter] string $jwt): Cookie`, `xsrf(#[SensitiveParameter] string $token): Cookie` ; `create()` privée garde `?int $expiresAt` pour `expired()`.
- `lexik_jwt_authentication.clock_skew: 5`, avec son commentaire (deux pods, horloges divergentes ; effet sur `iat` **et** `exp` ; le consentement lit `iat`, un âge négatif de 5 s au plus).

- [ ] **Step 1 : tests, rouges**
  - `AuthCookieFactoryTest` : le test de `:86` (échéance passée) est supprimé ; `testNeitherCookieTakesAnExpiry` — `(new ReflectionMethod(AuthCookieFactory::class, 'bearer'))->getNumberOfParameters()` vaut 1, de même pour `xsrf` (F1 : aucun cookie persistant ne peut être demandé).
  - `JwtClockSkewTest` (fonctionnel, `GET /api/me` avec un `BEARER` signé par l'encodeur du conteneur, la charge d'un accès de base — lire `BaseAccessControllerTest` pour sa forme) : `testAnIssuedAtThreeSecondsAheadIsServed` (403 : authentifié au palier de base, pas 401) ; `testAnIssuedAtThirtySecondsAheadIsRefused` (401) ; `testATokenExpiredThreeSecondsAgoIsServed` ; `testATokenExpiredThirtySecondsAgoIsRefused` ; `testTheConfiguredSkewIsFiveSeconds` (paramètre `lexik_jwt_authentication.clock_skew`).
  - `RefusedSessionCookiesAreClearedTest` (St3-12) : `PUBLIC_ROUTE` devient `/api/contributions/fr` ; `assertThePublicRouteIsServedAfterwards` exige `assertResponseStatusCodeSame(200)`.
  - Avant d'implémenter : `grep -rn "'exp' => time()\|'iat' => time()" backend/tests` — un jeton de test « tout juste expiré » (moins de 5 s) deviendrait servi : l'écarter de plus de 5 s dans le même commit.
  Run : `docker compose exec -u dev backend php bin/phpunit tests/Security/Authentication`. Expected : 2 paramètres ; 401 sur `iat` + 3 s.
- [ ] **Step 2 : implémenter** — selon les interfaces ; `rm -rf var/cache/test` après la configuration. Docblocks : `AuthCookieFactory` (plus de « pour un appelant futur » : aucune échéance ne peut être demandée) ; `StaleSessionCookiesClearingListener` (retirer R21b : le SPA n'envoie plus de cookie à une route publique, R23 ; **la limite CR3-2** : l'effacement vise un nom, le serveur ne sait pas qu'un cookie plus neuf a pu être posé après l'envoi — une réponse tardive l'efface ; la fenêtre, la réparation, pourquoi pas autrement, choix 5) ; `CsrfCookieRequestSubscriber` (A3-11 : le SPA appelle `/api/contact` sans cookie, `credentials: 'omit'`, #748 ; un autre client peut en envoyer un — sans effet, la route ne lit aucune identité, mais un cookie refusé y prend le 401 du pare-feu) ; `BaseAccessController` (retirer R21b : le SPA rejoue une fois sur 401, R24).
- [ ] **Step 3 : règle et ADR** — `security-authentication-sessions.md` : `bearer()`/`xsrf()` sans échéance possible ; `clock_skew` (pourquoi, ce qu'il relâche) ; « Accepted limit » de CR3-2 (une phrase). ADR 0003, amendement du 2026-10-29 : la même limite, une phrase.
- [ ] **Step 4 : verts** — commande ciblée PASS ; `make back-test`, `make back-quality` (`-u dev`), `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(security): les cookies d'authentification sans échéance possible, cinq secondes de tolérance d'horloge, la limite du 401 tardif écrite (#748)`.

---

### Task R30 : La connexion `expired=1` d'une personne connectée ; plus de cycle d'import (A3-10, St3-9)

**Files :**
- Modify : `frontend/src/presentation/pages/LoginPage.vue`, `frontend/src/presentation/router/{loginLocation,sessionEnded,reauthentication}.ts`
- Test : `frontend/tests/presentation/pages/LoginPage.spec.ts`, `frontend/tests/presentation/router/signInLocations.spec.ts`
- Modify : `.claude/rules/frontend-session.md` (« Both flags open the login… »)

**Interfaces :**
- `LoginPage` : l'annonce « votre session a pris fin » (`login-session-ended-notice`) ne s'affiche que si `expired=1` **et** aucun compte n'est affiché (`null === user.value`) ; avec `expired=1` et un compte affiché, l'annonce neutre `auth.reauthNotice` (`login-reauth-notice`), comme `reauth=1` — aucune chaîne nouvelle.
- `SESSION_ENDED_QUERY`, `SESSION_ENDED_VALUE`, `REAUTH_QUERY`, `REAUTH_VALUE` déclarées dans `loginLocation.ts` ; `sessionEnded.ts` et `reauthentication.ts` les importent de là (et les réexportent si un appelant les lit) ; `loginLocation.ts` n'importe plus ni l'un ni l'autre ; son paragraphe sur le cycle disparaît.

- [ ] **Step 1 : tests, rouges** — `LoginPage.spec.ts` : `it("connectée, expired=1 : l'annonce neutre, pas « session finie »")` (FR et EN, texte exact) ; `it("anonyme, expired=1 : « votre session a pris fin »")` (inchangé, vert) ; `signInLocations.spec.ts` : `it("loginLocation n'importe ni sessionEnded ni reauthentication")` (lecture de la source par `import.meta.glob` `?raw`) ; la garde « aucun autre fichier ne nomme expired/reauth » reste verte. Expected : « session finie » affichée ; import présent.
- [ ] **Step 2 : implémenter** — selon les interfaces.
- [ ] **Step 3 : règle** — `frontend-session.md`, « Both flags open the login… » : ce que dit la page selon qu'un compte est affiché (et pourquoi pas `sessionEnded` : le lien ouvre un nouvel onglet, dont l'état est neuf).
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): expired=1 ne dit plus « session finie » à une personne connectée ; les drapeaux de connexion sans cycle d'import (#748)`.

---

### Task R31 : Réglages « À propos » : une erreur par langue (Sp3-4)

**Files :**
- Modify : `frontend/src/application/admin/about/useAdminAboutSettings.ts`
- Test : `frontend/tests/application/admin/about/useAdminAboutSettings.spec.ts`
- Modify : `.claude/rules/frontend-backoffice.md` (« The error stays with its language »)

**Interfaces :**
- `failures = ref<Partial<Record<Locale, AdminAboutError>>>({})` remplace `lastFailure` ; `save(locale)` n'efface que `failures[locale]` et, en échec visible, pose `failures[locale]` ; `errorMessage` = l'échec de la locale courante (`SUPPORTED_LOCALES.find(isCurrentKey)`), `null` sinon.

- [ ] **Step 1 : tests, rouges** — `it("un échec FR, puis un enregistrement EN réussi : l'erreur FR revient en FR")` ; `it("un échec FR puis un échec EN : chacun sous sa langue")`. Le test F10-2 de R20 reste vert. Expected : `errorMessage` nul au retour en FR ; erreur EN sous le FR.
- [ ] **Step 2 : implémenter** — selon l'interface.
- [ ] **Step 3 : règle** — `frontend-backoffice.md` : « jusqu'au prochain enregistrement **de cette langue** », une erreur par langue.
- [ ] **Step 4 : verts** — spec ciblé PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): les réglages « À propos » gardent une erreur par langue (#748)`.

---

### Task R32 : La politique de cookies publiée dit des cookies de session (St3-1)

**Files :**
- Modify : `frontend/src/infrastructure/portfolio/content/{fr,en}.ts` (section « Cookies »)
- Create : `frontend/tests/infrastructure/portfolio/cookiePolicy.spec.ts`

**Texte exact** (FR puis EN, la liste de la section « Cookies ») :
- FR `BEARER` : « BEARER — jeton d’authentification (JWT), httpOnly, non lisible en JavaScript. Cookie de session : supprimé à la fermeture du navigateur (sauf si celui-ci restaure la session), à la déconnexion, ou dès qu’il est présenté après l’expiration du jeton qu’il porte (1 heure pour un compte, 15 minutes pour l’accès instantané). »
- FR `XSRF-TOKEN` : « XSRF-TOKEN — jeton de protection contre les attaques CSRF, lisible en JavaScript, posé en même temps que le précédent ; cookie de session lui aussi, supprimé en même temps que lui. »
- EN `BEARER` : « BEARER — authentication token (JWT), httpOnly, not readable from JavaScript. Session cookie: deleted when the browser is closed (unless it restores the session), on logout, or as soon as it is presented after the token it carries has expired (1 hour for an account, 15 minutes for instant access). »
- EN `XSRF-TOKEN` : « XSRF-TOKEN — CSRF protection token, readable from JavaScript, set together with the previous one; also a session cookie, deleted together with it. »

- [ ] **Step 1 : test, rouge** — `cookiePolicy.spec.ts` : pour chaque locale, la section « Cookies » nomme `BEARER` et `XSRF-TOKEN`, dit « de session » / « session cookie » pour les deux, et ne contient plus « expire avec lui » / « expires with it » ni « expire à la déconnexion ou à l’expiration du jeton » / « expires on logout or token expiry ». Expected : rouge sur les quatre lignes actuelles.
- [ ] **Step 2 : implémenter** — le texte exact ci-dessus (apostrophes typographiques comme le fichier).
- [ ] **Step 3 : vérifier** qu'aucune autre mention publique ne contredit (`grep -rn "XSRF\|BEARER\|cookie" frontend/src/infrastructure/i18n/locales frontend/src/infrastructure/portfolio/content`) : la ligne 114 (le **jeton** de 15 minutes) et `cookieNotice.message` restent justes.
- [ ] **Step 4 : verts** — spec PASS ; `make front-test`, `make front-lint`, `make front-build`.
- [ ] **Step 5 : commit** — `fix(frontend): la politique de cookies dit des cookies de session (#748)`.

---

### Task R33 : Le reste de la documentation (Sp3-5, Sp3-6, balayage)

**Files :**
- Modify : `.claude/rules/frontend-session.md`, `.claude/rules/frontend-a11y.md`, `frontend/src/presentation/ui/admin/SessionEndedBanner.vue` (docblock), `frontend/src/presentation/layout/AppHeader.vue` (commentaire), et ce que le balayage trouve

- [ ] **Step 1 : relire** — `grep -rn "R21b\|knownRepository\|endBaseAccessAtExpiry\|endedAccount\|SessionEndedHandler\|true to false\|à mesurer dans un vrai navigateur" frontend/src .claude/rules docs/adr backend/src` : chaque occurrence restante est corrigée ici (aucune ne reste, hors historique daté d'un amendement).
- [ ] **Step 2 : corriger** — `frontend-session.md:138` : « On return (`onSessionRestored`, the same account) » ; `:162` et `SessionEndedBanner.vue:12` : `createBackofficeSessionReaction` (R28). `frontend-a11y.md`, puce sur l'en-tête : à 320 px la marque se tronque (`text-truncate`), les actions ne cèdent jamais (`flex-shrink-0`), la largeur du groupe de droite se mesure au navigateur (tâche 11) ; `AppHeader.vue:233-236` renvoie à cette puce. La liste « Tests » de `frontend-session.md` gagne les specs de R23 à R31 (`credentialsAreExplicit.spec.ts`, `resetAuthStateForTests.spec.ts`, `cookiePolicy.spec.ts`, `JwtClockSkewTest`…) — dans `tasks/regle-session-en-attente.md` si la taille l'impose.
- [ ] **Step 3 : verts** — `tools/check-claude-rules.sh` ; `make front-lint`, `make front-build`.
- [ ] **Step 4 : commit** — `docs: la règle de session à jour de la troisième relecture de #748`.

---

### Task R34 : `frontend-session.md` découpée par sous-domaine (St3-11) — en dernier

**Files :**
- Create : `.claude/rules/frontend-session-bandeau.md`, `.claude/rules/frontend-session-retour.md`, `.claude/rules/frontend-session-refus.md`
- Modify : `.claude/rules/frontend-session.md`, `.claude/CLAUDE.md` (table des règles) ; supprimer `tasks/regle-session-en-attente.md` s'il existe (`git rm`), son contenu intégré

**Découpage** (procédure de `.claude/CLAUDE.md`, « Where the rest lives » : la règle d'origine garde un index ; chaque partie a ses `paths:`, chacun correspondant à un fichier suivi) :
- `frontend-session.md` — la règle (`sessionAwareFetch`, « avec jeton »), les deux erreurs, une section par client, les limites acceptées, `credentials` explicite (R23) ; **une table d'index** (comme `security-authentication.md`) : chaque section partie, son fichier ; ses `paths:` : l'infrastructure HTTP, les dépôts, `csrfCookie`.
- `frontend-session-bandeau.md` — « The interface » : le bandeau, sa réannonce, le retour annoncé, le lien de reconnexion (`loginLocation`, `SignInAgainLink`, les drapeaux, `LoginPage`), l'en-tête ; `paths:` : `SessionEndedBanner.vue`, `AccessRefusedNotice.vue`, `SignInAgainLink.vue`, `router/{loginLocation,sessionEnded,reauthentication,routeLocale}.ts`, `AppHeader.vue`, `usePublishedHeight.ts`, `AdminLayout.vue` et leurs specs.
- `frontend-session-retour.md` — la revérification au retour, la génération, le compte retenu, le rejeu et le masquage, le découpage de `application/auth/` et la remise à zéro de test ; `paths:` : `application/auth/**`, `useSessionRecheckOnFocus.ts`, `AppLayout.vue`, `tests/support/{setup,signInAgain}.ts`, `tests/application/auth/**`.
- `frontend-session-refus.md` — « A real refusal », le backoffice compris (`BackofficeSessionReaction`, le périmètre de refus) ; `paths:` : `application/{mcp,assistant}/**`, `backofficeAccessRefusal.ts`, `backofficeSessionExpiry.ts`, `BackofficeHttpClient.ts`, `domain/admin/shared/BackofficeSessionReaction.ts`.
- Les « Tests » sont répartis : chaque partie liste les specs de ce qu'elle porte.

- [ ] **Step 1 : déplacer** le texte **tel quel** (aucune phrase réécrite, sauf l'intégration de `tasks/regle-session-en-attente.md`) ; chaque fichier commence par l'en-tête des parties découpées (« Split from `.claude/rules/frontend-session.md` (#748, St3-11; procedure of #605), which keeps the index of the parts. »).
- [ ] **Step 2 : index** — la table de `frontend-session.md` ; trois lignes nouvelles dans la table de `.claude/CLAUDE.md` (fichier, domaine, « Read it before… ») et la ligne de `frontend-session.md` réduite à ce qu'elle garde. Les renvois « `.claude/rules/frontend-session.md`, « <section> » » des autres règles restent valides par l'index (ne pas les réécrire).
- [ ] **Step 3 : verts** — `tools/check-claude-rules.sh` (aucun `paths:` vide ni sans fichier suivi, chaque fichier sous 50 000 octets) ; `wc -c .claude/rules/frontend-session*.md` noté ; somme des quatre ≥ taille d'avant (rien perdu) ; `make front-lint`.
- [ ] **Step 4 : commit** — `docs: la règle de session découpée par sous-domaine (#748)`.

---

### Task 11 : Contrôle au navigateur — **fil principal, pas un agent de tâche**

Pile de dev locale, FR et EN, axe-core injecté dans la page, noté dans la PR (référentiel WCAG 2.2 AA) :

- [ ] axe sur « Mes connexions MCP » (liste, état vide, erreur, session finie) ; menu du compte **ouvert** ; une page d'administration **avec le bandeau** (provoqué pour de vrai : déconnexion dans un autre onglet, puis « Enregistrer » → 403 sans jeton → bandeau seul, saisie intacte ; puis reconnexion dans l'autre onglet, retour → « Session rétablie », « Enregistrer » aboutit).
- [ ] Contrastes mesurés : « Révoquer » (repos, survol de ligne), « Supprimer » du backoffice, messages `text-danger-emphasis`, bandeau, « EN » inactif, liens du pied de page (version comprise).
- [ ] 320 px : page MCP sans défilement horizontal (palier de confiance) ; en-tête aux trois paliers, FR et EN, bouton du menu entier.
- [ ] Bandeau collant après « Enregistrer » en bas d'un long formulaire ; menu mobile ouvert : le bandeau reste sous l'en-tête ; un champ focalisé au clavier n'est pas masqué (2.4.11) ; zoom 200 %.
- [ ] Page MCP : plus de barre verticale en desktop ; contour de focus du dernier « Révoquer » entier ; « Réessayer » rend le focus ; révocation : focus au titre puis annonce (inspecteur d'accessibilité, sans lecteur d'écran réel — le noter non vérifié).
- [ ] Consentement OAuth et assistant : déconnexion dans un autre onglet puis décision / question → session finie (lien de reconnexion qui s'ouvre) ; le refus `forbidden` (compte de confiance rétrogradé pendant que la page est ouverte) → message de refus, en-tête inchangé, « Changer de compte » ouvre la connexion (consentement).
- [ ] Menu du compte : Échap depuis la page ne déplace pas le focus ; Tab après la dernière entrée le ferme (Chrome, Firefox) ; entrée courante visible ; ouvert à la souris puis Échap : le focus revient au bouton (R10 ; Safari s'il est disponible, sinon le noter non vérifié).

Ce que les corrections R1 à R10 ont changé, à mesurer aussi :

- [ ] **Bandeau collant, mesuré** (R9) : page d'administration longue, bandeau affiché, défilement jusqu'en bas : `getBoundingClientRect().top` du bandeau égale la hauteur publiée `--app-header-height` (± 1 px) ; aucun espace vide supplémentaire entre la navigation d'administration et la page, session active (comparer à une capture de `develop`).
- [ ] **Réannonce** (R9, revue par R14 et R19) : bandeau affiché, lien « Se reconnecter » focalisé au clavier, « Enregistrer » une seconde fois → le message de l'alerte est remplacé (inspecteur : le `<span>` du message change, l'élément `role="alert"` reste), le focus reste sur le lien ; au chargement d'une page d'administration dont plusieurs lectures prennent 401, une seule annonce ; « Session rétablie » s'efface si la session reprend fin.
- [ ] **Non chargé, puis relu** (R4) : session finie avant d'ouvrir une page d'administration (déconnexion dans un autre onglet, puis navigation par l'application) → mention « Contenu non chargé », aucun « aucune … » ; reconnexion dans l'autre onglet, retour → la liste se lit. Réglages « À propos » : FR lu, session finie, passage en EN → ni champs FR ni « Enregistrer » ; retour → l'EN s'affiche ; « Enregistrer » ne touche que l'EN.
- [ ] **Cookies** (R1, remplacé par R21) : voir « Cookies de session » ci-dessous.
- [ ] **Revérification partout** (R8) : sur « Mes connexions MCP », l'assistant et le consentement, déconnexion dans un autre onglet puis geste → session finie ; reconnexion dans l'autre onglet, retour → l'en-tête retrouve le compte, la page se relit (MCP, consentement) ou efface son erreur (assistant, brouillon gardé) ; une seule requête `/api/me` par retour (onglet Réseau), bandeau affiché ou non. Session ouverte avant R1, `XSRF-TOKEN` supprimé à la main (outils du navigateur) : le retour ne rétablit pas la session.
- [ ] **Refus réel** (R7) : compte de confiance rétrogradé (par un autre compte `ROLE_SUPER`) pendant que « Mes connexions MCP », l'assistant ou le consentement est ouvert, puis geste → message neutre, « Changer de compte » ouvre la connexion (annonce neutre), en-tête au palier de base après la relecture de `/api/me`, aucun « Réessayer » sur la page MCP ; « Révoquer » désactivé.
- [ ] **Liens** (R6, R16) : les liens de reconnexion du bandeau, de l'en-tête, de la page MCP et de l'assistant s'ouvrent dans un nouvel onglet et ramènent à la page (`expired=1`) ; sur le consentement, ceux du corps **et** « Connexion » de l'en-tête restent dans l'onglet (F7).
- [ ] **« Réessayer »** (R5) : après une révocation annoncée puis une relecture en échec, « Réessayer » ne laisse pas « révoquée » dans la région d'état.
- [ ] **En-tête à 320 px, avec la marque tronquée (C2)** : mesurer, aux trois paliers, FR et EN, la largeur du groupe de droite (`div.flex-shrink-0` de l'en-tête) et `document.documentElement.scrollWidth` (doit valoir 320). Si la ligne déborde encore (le ticket mesurait le groupe **seul** à 331 px au palier de confiance et 389 px en anonyme et en EN pour 301 utiles, ce que la troncature de la marque ne peut pas absorber), appliquer le repli suivant dans un commit `fix(frontend): l'en-tête tient à 320 px, actions resserrées (#748)` puis refaire la mesure : sous 360 px (`@media (max-width: 359.98px)`, `<style scoped>` d'`AppHeader`), « Connexion » (lien ordinaire et `SignInAgainLink`) devient une icône `~icons/lucide/log-in` avec `aria-label` = `common.login` (comme « Accès instantané » sous 576 px) et le groupe passe de `gap-2` à `gap-1` ; test `AppHeader.spec.ts` « sous 360 px, « Connexion » garde son nom accessible » (le `aria-label`, l'icône `aria-hidden`), et garde de source sur la règle `@media` dans le bloc scopé. **Si cela ne suffit pas** (mesure à l'appui), marche suivante **décidée par l'auteur le 2026-10-29** : sous 360 px, le sélecteur de langue quitte la ligne et passe dans le menu mobile (deux rangées restent écartées pour 2.4.11) ; test de non-régression (le sélecteur reste atteignable et nommé dans le menu mobile sous 360 px) ; consigner les mesures dans la PR.
Ce que les corrections R12 à R22 ont changé, à mesurer aussi :

- [ ] **Cookies de session** (R21, décision 1) : après une connexion **et** après « Accès instantané », dans les outils du navigateur (Application → Cookies), `BEARER` et `XSRF-TOKEN` ont « Session » pour échéance (aucun `Expires`/`Max-Age` dans le `Set-Cookie` de l'onglet Réseau) ; navigateur fermé puis rouvert **sans** restauration de session : visiteur anonyme, aucune boucle « session finie » / « Session rétablie » ; avec la restauration de session du navigateur (Chrome « Continuer là où vous en étiez », Firefox « Restaurer la session ») : noter si les cookies reviennent (c'est la limite écrite dans l'ADR). JWT expiré : **remplacé par le contrôle « Cookie mort, en même origine » ci-dessous** (R23 : une page publique ne reçoit plus ce 401).
- [ ] **Rejeu sur changement de compte** (R18, décision 3) : onglet A sur une page d'administration avec une saisie, session finie (déconnexion dans l'onglet B) ; dans B, connexion avec **un autre** compte. Retour sur A : aucune « Session rétablie », la route est rejugée (compte sans `ROLE_SUPER` → page « interdit » ; compte `ROLE_SUPER` → la page est remontée, champs vides) ; une seule requête `/api/me`. Même scénario avec **le même** compte : « Session rétablie », saisie intacte, « Enregistrer » aboutit. Assistant avec une conversation : un autre compte au retour → conversation vide.
- [ ] **Refus réel du backoffice** (R17) : un compte `ROLE_SUPER` rétrogradé par un autre pendant qu'il édite, puis « Enregistrer » → une alerte « accès refusé » sous le bandeau d'administration, « Changer de compte » ouvre la connexion (`reauth=1`, même onglet), l'en-tête retombe au palier relu ; la mention « Contenu non chargé » neutre si une liste n'a pas pu se lire.
- [ ] **`expired=1` ouvre la connexion** (R16, B/F2) : connecté, `XSRF-TOKEN` supprimé à la main, « Enregistrer » → bandeau ; son lien ouvre la page de connexion (pas l'accueil).
- [ ] **Relecture après refus** (R15) : refus réel puis `/api/me` en 401 (`BEARER` supprimé à la main entre les deux) → la page propose de se reconnecter, l'en-tête montre « Connexion » en nouvel onglet ; une seule requête `/api/me` pour la lecture et la révocation refusées ensemble.
- [ ] **Échap du menu du compte** (R19, G) : menu ouvert à la souris, focus placé au clavier sur un autre élément de l'en-tête qui disparaît ensuite (menu « Contenu » refermé), Échap → le focus ne saute pas au bouton du menu du compte.
- [ ] **`XSRF-TOKEN` indécodable** (R12, F13) : `document.cookie = 'XSRF-TOKEN=%E0; path=/'` dans la console, puis « Enregistrer » → aucune exception dans la console, bandeau de session finie.
- [ ] **Réglages « À propos »** (R20) : enregistrement FR ralenti (outils réseau, « Slow 3G »), passage en EN puis retour en FR avant la réponse → les valeurs enregistrées restent ; un enregistrement FR refusé (hors ligne) puis passage en EN → aucune erreur sous l'EN, elle revient en FR.

Ce que les corrections R23 à R34 ont changé, à mesurer aussi.

**Une pile en même origine, comme en prod** (préalable des deux premiers points). La pile de dev sert le frontend
(Vite, 5173) et l'API (nginx, 8080) sur **deux origines** : le défaut `same-origin` de `fetch` n'y envoyait déjà aucun
cookie aux dépôts publics, et CR3-3 ne s'y voit pas. Deux façons de reproduire la prod :
(a) **la préprod** (même hôte, `/api` routé par l'ingress), quand la branche de release la déploie ;
(b) **en local**, sans rien commiter : dans `frontend/vite.config.ts`, `server: { proxy: { '/api': 'http://web' } }`
(le service `web` du réseau `app`), et `VITE_API_URL=http://localhost:5173` pour le conteneur `frontend`
(`docker-compose.override.yml` ou variable d'environnement), puis `docker compose restart frontend` ; tout passe alors
par `http://localhost:5173`. Vérifier d'abord, connecté, que `GET /api/me` part vers `localhost:5173` et **porte**
`Cookie: BEARER=…` (sinon la pile ne reproduit pas la prod) ; retirer les deux changements locaux à la fin (`git diff`
vide sur ces fichiers).

- [ ] **Cookie mort, en même origine** (R23, CR3-3) : connecté (puis, à part, avec « Accès instantané »), remplacer la
  valeur de `BEARER` par `x` (Application → Cookies ; même effet qu'un JWT expiré : `JWT_INVALID`, effacé par son
  premier 401). Visiter « À propos », la page d'accueil (qualité, contributions, incidents, technologies), `/stack`,
  envoyer le formulaire de contact, ouvrir un lien `set-password#…` : dans l'onglet Réseau, **aucune** de ces requêtes ne
  porte d'en-tête `Cookie`, aucune ne répond 401, les pages s'affichent. Puis une page authentifiée (backoffice, MCP,
  assistant, études de cas) : un 401, lu comme une session finie (bandeau, lien de reconnexion) ou, au palier de base,
  « accès de base expiré » ; le `Set-Cookie` du 401 expire les deux cookies.
- [ ] **Deux onglets, en même origine** (R23, R26) : onglet A sur « À propos », onglet B connecté ; `BEARER` rendu mort
  dans B ; A se recharge et navigue sur les pages publiques sans 401 ni changement d'en-tête ; B se reconnecte, A revient
  au premier plan : une seule requête `/api/me` si une session finie était affichée, aucune sinon.
- [ ] **« Accès instantané » avec un cookie mort** (R24) : `BEARER` = `x`, en-tête anonyme (rechargement), « Accès
  instantané » → onglet Réseau : un `POST /api/account/base-access` en 401 puis un second en 200, l'en-tête au palier de
  base, les deux cookies neufs ; sans cookie mort, un seul `POST`.
- [ ] **Échéance de l'accès de base** (R23) : « Accès instantané », attendre 15 minutes sur une page publique (ou avancer
  l'horloge du poste, en le notant) : l'en-tête repasse à anonyme **sans aucune requête** (ni `POST /api/logout` ni
  `/api/me`) ; les pages publiques restent servies sans 401 ; une page du palier de base prend le 401 et propose
  l'accès ; un onglet resté en arrière-plan pendant l'échéance revient sans requête.
- [ ] **Changement de compte révélé par un refus** (R26, CR3-6) : onglet A sur une page d'administration avec un compte
  `ROLE_SUPER` et une saisie ; onglet B : déconnexion, puis connexion avec **un compte de confiance sans `ROLE_SUPER`**.
  Retour sur A (aucune session finie dans A : pas de relecture au focus), « Enregistrer » → la requête porte le cookie de
  B, 403 avec jeton (refus réel) → `/api/me` rend B → l'en-tête passe à B **et** la navigation est rejouée : page
  « interdit », plus rien du compte de A à l'écran. Le cas CR3-5 (une fin de session survenue au palier de base, puis un
  autre compte) demande une rétrogradation pendant la page puis une fin de session : le noter couvert par
  `useAuth.spec.ts` (R26) s'il n'est pas reproduit.
- [ ] **Rejeu sans l'ancienne page** (R27, A3-13) : scénario R18 ci-dessus (un autre compte au retour) : pas d'image de
  l'ancienne page sous l'en-tête du nouveau compte (enregistrement de l'écran au ralenti si besoin ; sinon le noter
  couvert par `AppLayout.spec.ts`).
- [ ] **Refus du backoffice périmé** (R28, CR3-8) : un compte `ROLE_SUPER` rétrogradé ; sur une page d'administration
  dont la lecture est ralentie (« Slow 3G »), naviguer vers une autre page avant la réponse 403 → aucune alerte « accès
  refusé » sur la seconde ; un refus sur la page courante l'affiche.
- [ ] **`expired=1` à une personne connectée** (R30, A3-10) : connecté, ouvrir `/<locale>/login?expired=1` → l'annonce
  neutre (« Confirmez votre identité… »), pas « votre session a pris fin » ; anonyme, la même URL dit « votre session a
  pris fin ». FR et EN.
- [ ] **Une erreur par langue** (R31) : hors ligne, enregistrer les réglages FR (échec), passer en EN, enregistrer (échec),
  revenir en FR → l'erreur FR ; en EN → l'erreur EN ; en ligne, enregistrer l'EN → l'erreur FR reste en FR.
- [ ] **Politique de cookies** (R32) : la section « Cookies » des mentions, FR et EN, dit « cookie de session » pour
  `BEARER` et `XSRF-TOKEN`, et la fermeture du navigateur.
- [ ] Non contrôlables au navigateur, à noter comme tels : la génération sur `logout`/`login` pendant un `/api/me` en vol
  (R26, CR3-4), `clock_skew` (R29, `JwtClockSkewTest`), le 401 tardif qui efface des cookies neufs (CR3-2, limite écrite).

- [ ] Tout écart → retour à la tâche concernée (nouveau commit), puis ce contrôle refait.

### Task 12 : Clôture — **fil principal**

- [ ] `make front-test`, `make front-lint`, `make front-build`, **`make back-test` et `make back-quality`** (R1, R21) verts sur la tête ; `tools/check-claude-rules.sh` vert (les `paths:` ajoutés correspondent à des fichiers suivis, aucune règle au-delà de 50 000 octets, `frontend-session.md` et ses trois parties de R34 comprises, chacune avec sa ligne dans l'index de `.claude/CLAUDE.md`) ; `tasks/regle-session-en-attente.md` n'existe plus (intégré par R34).
- [ ] Corps de la PR, compléments de la troisième relecture (R23 à R32) : **les lectures publiques, le formulaire de contact et le choix du mot de passe partent sans cookie** (`credentials: 'omit'`) : un cookie mort (JWT expiré, invalide, antérieur à un changement de mot de passe) n'atteint plus une page publique ; son premier 401 ne tombe plus que sur une page authentifiée, lue comme une session finie. **R21b est retirée** (décision de l'auteur du 2026-10-29) : l'accès de base n'appelle plus `POST /api/logout` à son échéance, l'en-tête repasse simplement à anonyme. **« Accès instantané » rejoue une fois** après le 401 d'un cookie mort (deux jetons du quota de 20 par heure dans ce cas). **`clock_skew: 5`** : cinq secondes d'écart d'horloge entre pods tolérées (sur `iat` comme sur `exp`). Limite écrite : la réponse tardive (401) d'une requête authentifiée partie avec un cookie mort peut effacer des cookies posés entre-temps par une connexion dans un autre onglet ; la requête suivante le dit (session finie), une reconnexion répare. La politique de cookies publiée dit désormais « cookies de session ». Aucune action au déploiement.
- [ ] Corps de la PR : la note de release de F1 (R21, remplace celle de R1) — `BEARER` et `XSRF-TOKEN` sont désormais des **cookies de session**, au login comme à l'accès de base (le JWT garde son heure, l'accès de base ses quinze minutes) : fermer le navigateur sans restauration de session finit la session ; à l'expiration du JWT, la première requête prend un 401 qui efface les deux cookies, la suivante est anonyme ; **une session ouverte avant le déploiement garde ses cookies persistants, au plus une heure** (rien à faire) ; le frontend juge désormais la session par génération (une réponse partie sous une autre session ne la finit ni ne la rétablit) et un autre compte au retour rejoue la navigation.
- [ ] **Amendement d'ADR 0003** — **déplacé dans R21 (Step 3b) par décision de l'auteur du 2026-10-29** ; à la clôture, seulement vérifier qu'il est fait et cohérent. Contenu attendu (décision 1 du 2026-10-29) : ligne de statut (« Amendée le 2026-10-29 ») ; dans « Risque accepté — le logout ne révoque pas le jeton », le paragraphe des faits (`set_cookies.BEARER.lifetime` vaut `0` : cookie de session ; `token_ttl` 3600 et les 900 s de l'accès de base inchangés) ; un amendement « 2026-10-29 (#748, F1) » : `BEARER` et `XSRF-TOKEN` sont des cookies de session ; le risque « navigateur fermé » (un cookie qui survit à la fermeture, en lecture comme en écriture) **disparaît, sauf navigateur qui restaure la session** ; tout cookie refusé (expiré, invalide, antérieur au changement de mot de passe) est effacé par son premier 401 ; ce que cela ne change pas (un jeton copié reste valable jusqu'à son `exp`) ; les conditions qui rouvrent la décision, inchangées. `docs/adr/0003-paliers-d-acces.md` seul, relu contre `security-authentication-sessions.md` (R21).
- [ ] **Ticket à proposer à l'auteur** (texte dans le rapport de clôture, pas de création sans son accord) : le préfixe `__Host-` des deux cookies (S2 de la première relecture : préprod d'abord, il renomme ce que Lexik, `CsrfCookieRequestSubscriber` et le frontend lisent par nom) **et F12** (le jeton CSRF n'est lié ni à la session ni au temps : le lier au JWT — par exemple un HMAC dérivé de son `iat` et du compte — ou lui donner une échéance signée), avec la note que R21 réduit déjà la durée de vie des deux cookies à la session du navigateur, **et A3-3** de la troisième relecture (depuis R21, tout cookie `BEARER` refusé efface les deux cookies : un sous-domaine qui pose un faux `BEARER` sur `Path=/api` — plus spécifique que le `Path=/` du vrai, donc envoyé en premier — fait répondre 401 et efface la session valide ; `__Host-` interdit qu'un sous-domaine pose ce nom). À y joindre, pour décision : un pare-feu sans authentificateur pour `/api/account/base-access` (comme `oauth`), qui rendrait le rejeu de R24 inutile (choix 2 de la troisième relecture).
- [ ] #732 : le point 4 est **entièrement traité par #748** (règle `endsTheSession` dans le backoffice, « Mes connexions MCP », le consentement OAuth et l'assistant, portée par `sessionAwareFetch` ; un refus réel relit `/api/me` et propose « Changer de compte » ; tâches 3, 4, 4b, 4c, R2, R7) — le marquer comme tel dans le corps (barré, renvoi à #748 et à son commit de fusion), rien n'y reste. Par `gh api -X PATCH repos/{owner}/{repo}/issues/732` (pas `gh issue edit`, cassé en gh 2.46 sur ce dépôt).
- [ ] `tasks/todo.md` : cocher « Tâche 11 » (critère de #748) dans le commit de clôture.
- [ ] Après la fusion dans `feature/379-mcp-paliers` : fermer #748 à la main (`Closes #748` ne ferme rien vers une base qui n'est pas `main`).
