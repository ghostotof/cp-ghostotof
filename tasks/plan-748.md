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

## Corrections de la quatrième relecture (2026-10-29)

R23 à R34 sont faites (b4fda293 → 45ec285d). Quatre relectures du diff b4fda293...45ec285d (`/code-review medium`
CR4-*, audit de sécurité C*, axe Standards St4-*, axe Spec Sp4-*) : aucun critique ni haut. Constats complets : rapport de
la quatrième relecture (fil principal). Tous sont traités ici par R35 à R45, avant les tâches 11 et 12, sauf ceux que la
vérification écarte (ci-dessous) et F12, S2, A3-3, C8, rattachés au futur ticket `__Host-` (tâche 12).

### Décisions de l'auteur (2026-10-29)

1. **C1 : la cause est corrigée côté serveur.** Le pare-feu `api` ne répond plus 401 à une route publique parce qu'elle
   porte un `BEARER` refusé (R35). **Puis `credentials: 'omit'` (R23) et le rejeu d'« Accès instantané » (R24) sont
   retirés** (R37), avec les tests, gardes, règles et textes qui les décrivent.
2. **Tout est corrigé jusqu'à zéro** : moyens, faibles, documentation, jugements de conception. Après cette série, les
   quatre relectures recommencent, tant qu'elles trouvent quelque chose.
3. **C7 : limite acceptée et documentée** (R45 et la PR) : un changement de compte dans un autre onglet, sans session finie
   dans celui-ci, n'est détecté par rien ; antérieure à #748, elle suppose un accès au même navigateur.

### Vérification de chaque constat dans le code (HEAD 45ec285d)

- **C1, confirmé.** La spécification Fetch range sous « credentials » les cookies **et** l'authentification HTTP :
  `credentials: 'omit'` n'envoie pas l'en-tête `Authorization: Basic` que le navigateur rejoue sur l'origine. La préprod a
  la Basic Auth sur l'Ingress principal (`k8s/overlays/preprod/kustomization.yaml:97-112`, annotations `auth-type`,
  `auth-secret`, `auth-realm`) : les huit dépôts de R23 y prennent le 401 de l'ingress. Les `curl` de `smoke-test-preprod`
  et d'`audit-preprod` passent la Basic Auth par `-K` : ils ne le voient pas. La cause côté serveur, vérifiée dans le
  vendor : `JWTAuthenticator::supports()` (Lexik 3.2) répond `true` dès qu'un cookie `BEARER` est présent, quelle que soit
  la route ; un échec passe par `onAuthenticationFailure()`, qui rend la réponse 401 (`JWTAuthenticationFailureResponse`),
  et `AuthenticatorManager::handleAuthenticationFailure()` (Symfony 8.1) la rend telle quelle — sauf si un écouteur de
  `LoginFailureEvent` la remplace par `null` (« returning null is ok, it means they want the request to continue »).
- **CR4-1 / C3, confirmé.** `AccessRefusedNotice.vue:48-51` efface (et avance le périmètre, `backofficeAccessRefusal.ts:54-57`)
  à tout changement de `route.fullPath`, fragment compris ; le lien d'évitement (`AppLayout.vue:72-77`, `href="#main-content"`)
  change le fragment (une navigation par fragment émet `popstate`, que vue-router suit — à mesurer à la tâche 11 ; le test
  passe par `router.push({ hash })`). `backofficeSessionExpiry.ts:40-44` : un refus sous un autre périmètre n'est ni compté
  ni relu ; `visibleMutationError` tait `AccessForbiddenError` et la mutation rend `false` : un enregistrement refusé
  n'affiche **rien**.
- **CR4-2 / C5, confirmé.** `AppLayout.vue:39,49-67` : `isReplaying` est un booléen ; le `finally` du premier rejeu le
  rabat pendant que le second est en vol.
- **Sp4-1 / C2, confirmé.** `main.ts` : `app.mount('#app')` puis `checkAuth()` ; la route `login` n'attend pas
  `waitForAuthCheck()` (`router/index.ts:213-224`, qui lit `authState.user` à nu). Dans un onglet neuf, `user` est nul
  pendant la vérification : `LoginPage` affiche « Votre session a pris fin » puis l'annonce neutre. **Effet de bord vu en
  vérifiant** : le même `beforeEnter` laisse ouvrir `/login` sans drapeau à une personne connectée, dans un onglet neuf (il
  devrait la renvoyer à l'accueil) — corrigé par la même attente (R40).
- **CR4-7, confirmé.** `LoginPage.vue:24,42` : `isSignedIn` ne lit que `user` ; au palier de base (`user` nul, `tier` base),
  `expired=1` dit « session finie ». Cas réel : session finie dans l'onglet A, « Accès instantané » dans B, lien du bandeau
  de A ouvert ensuite.
- **CR4-3, confirmé.** `sessionState.ts:137-145` : `markBaseAccessGranted()` ne touche pas `sessionEnded`.
- **CR4-4 / C4 / Sp4-2, C6, CR4-9 : sans objet après la décision 1** (la garde `credentialsAreExplicit.spec.ts`, le double
  401 du rejeu, la répétition des sept `omit` disparaissent avec R37).
- **CR4-6, confirmé en partie.** `useAdminAboutSettings.ts:61-79` : aucun timbre ; deux enregistrements FR qui se
  chevauchent laissent le plus ancien poser son verdict (une erreur après le succès du plus récent) **et sa valeur**
  (`write(locale, saved)` d'une réponse ancienne arrivée après celle du plus récent). Corrigé en R43. **Écarté après
  vérification** : « effacer l'erreur d'une langue quand sa lecture est rechargée » — le formulaire recopie les réglages
  relus (`AdminAboutSettingsForm.vue:66`), la saisie refusée est donc perdue au changement de langue, et l'erreur qui revient
  est la seule trace que l'enregistrement de cette langue a échoué ; l'effacer ferait croire qu'il a abouti. C'est la
  décision R31/F10-2. Question à l'auteur ci-dessous.
- **CR4-8 / Sp4-3, confirmé.** `backofficeAccessRefusal.ts:17,25` (`refusals`, `scope`) et `BackofficeHttpClient.ts:7`
  (`sessionReaction`) : aucun n'est remis à zéro par `tests/support/setup.ts`.
- **St4-4, confirmé.** `useAuth.ts:235-249` : un `me()` qui lève de façon synchrone termine l'IIFE (catch, `finally` qui remet
  `null`) **avant** que `endedSessionRecheck` reçoive la promesse ; la promesse résolue reste posée, et toute relecture
  suivante la rend sans appeler `me()`.
- **CR4-10 / C10, confirmé.** `JwtClockSkewTest.php` : « expiré depuis 3 s servi » n'est vrai que si la requête part moins
  de 2 s après la signature (`LoadedJWS:77` : `clock_skew <= time() - exp` ; `LooseValidAt` de lcobucci, à la
  microseconde, même borne) ; `iat` + 30 et `exp` − 30 ont de la marge, mais rien n'épingle la tolérance entre 5 et 30.
- **St4-1, confirmé.** `sessionGeneration.ts:2-3,22` : « à chaque fin acceptée et à chaque retour » ; `login`, `logout`,
  `markBaseAccessGranted`, `markBaseAccessExpired` l'avancent aussi (R26).
- **St4-2, confirmé.** `.claude/rules/frontend-session-retour.md`, « The session generation », deuxième puce : `canRestore`
  « asks for an account, a write token and the current generation » — la génération est jugée par l'appelant
  (`sessionLifecycle.ts:33-39`) ; la liste « `login()`, `checkAuth()` and `recheckEndedSession()` » oublie
  `rereadAfterRefusal` (par `applyReadSession`).
- **St4-3, confirmé.** `sessionEvents.ts:44` : « Un abonné au retour de la session… » pour les deux `Set`.
- **St4-5, confirmé.** `sessionState.ts:21` exporte `state` en écriture ; `useAuth.ts:205-207` écrit `isChecking` ;
  `sessionLifecycle.ts` écrit `sessionEnded` et `sessionEndedCount`.
- **St4-6, confirmé.** `isSameAccount` (`sessionLifecycle.ts:19`) n'a aucun appelant hors du module (`grep` sur `src` et
  `tests`). C12 : les `reset…ForTests` sont des exports de modules importés par `src/` ; Rollup doit les retirer du bundle
  (exports inutilisés) — à vérifier sur un build non minifié (R42).
- **St4 (jugement), `refusalScope` dans le domaine** : tranché en R38 (choix n° 6).
- **Sp4-c, confirmé.** `RefusedSessionCookiesAreClearedTest.php`, docblock de `PUBLIC_ROUTE` : « St3-3 » → « St3-12 »
  (R35 réécrit ce test).
- **C11, confirmé.** `content/{fr,en}.ts:145` et `docs/rgpd/registre-traitements.md` §3.1 et §3.3 disent l'effacement « dès
  qu'il est présenté après l'expiration » ; faux pour un jeton invalidé, et faux après R35 sur une route publique. Le
  registre dit aussi la session antérieure au changement de mot de passe « refusée par toute l'API » (R35 le change).
- **C7** : limite (décision 3). **Sp4-b** : gardé, dit dans la PR (tâche 12).

### Le correctif serveur : options comparées (décision 1)

Le besoin : une route que l'`access_control` laisse à l'anonyme est servie comme à l'anonyme quel que soit le `BEARER`
porté, **sans** rien changer pour une route qui exige un rôle. Faits vérifiés :

- **Toute autorisation de `/api` passe par `access_control`** : aucun `security:` d'API Platform, aucun `#[IsGranted]`,
  aucun `denyAccessUnlessGranted` dans `src/` ; les `#[CurrentUser]` et `Security::getUser()` sont sous une règle
  (`/api/me`, `/api/backoffice`, `/api/oauth/consent`, `/api/assistant`). Une route publique est donc une route **sans
  règle** (aucune n'est en `PUBLIC_ACCESS` sous `/api`) ; `AccessMapStaysPathOnlyTest` garantit que la règle ne dépend que
  du chemin.
- `LazyFirewallContext` (SecurityBundle 8.1) n'est paresseux que si **tous** les écouteurs répondent `null` à `supports()` ;
  `AuthenticatorManagerListener` répond `true` dès que `JWTAuthenticator::supports()` le dit (un cookie présent).
- Les écouteurs de `LoginFailureEvent` sur `api` : aucun des nôtres n'y agit (`SecurityEventsSubscriber` et
  `FailedLoginTimingEqualizer` ne jugent que `login`).

| Option | Avantages | Inconvénients | Verdict |
|---|---|---|---|
| (a) `lazy: true` sur `api` | une ligne de configuration | **ne change rien** : l'authentificateur JWT répond `true` à `supports()`, le contexte reste immédiat ; le rendre paresseux demande de toute façon un authentificateur modifié, et toute lecture du jeton (journal d'audit, `Security::getUser()`) relancerait alors le 401 sur une route publique, par `LazyResponseException` — un comportement qui dépend de qui lit le jeton | écartée |
| (b) un authentificateur dérivé de `JWTAuthenticator` (clé `jwt.authenticator`, point d'extension de Lexik) dont `onAuthenticationFailure()` rend `null` sur une route publique | décide avant les événements `JWT_INVALID`/`JWT_EXPIRED` de Lexik | hérite d'une classe du vendor (couplage à son constructeur) ; l'ADR 0003 a déjà écarté « un authentificateur Lexik décoré (recopier une classe du vendor) » pour le refus du `cv` | écartée |
| (c) un pare-feu sans authentificateur pour les routes publiques (comme `oauth`) | aucun cookie lu | une **seconde liste de ce qui est public** (le motif du pare-feu), à tenir à la main à côté de `access_control` et de `PUBLIC_PATHS` : une route publique ajoutée sans elle retrouve le 401 en silence ; `/api/logout` ne peut pas y entrer (son écouteur de déconnexion est celui d'`api`) ; `AccessControlAnchoringTest` et les parités à revoir pour chaque motif | écartée |
| (d) **un écouteur de `LoginFailureEvent` sur le seul dispatcher d'`api`, qui rend la réponse `null` quand l'`AccessMap` ne demande rien pour la requête** | la source de vérité est celle que l'`AccessListener` lit (aucune liste de plus) ; aucune classe du vendor touchée ; même forme que `SessionBeforePasswordChangeRefusalListener` (écouteur d'`api` seul) ; une route protégée garde exactement son 401 | Lexik construit sa réponse 401 et émet `JWT_INVALID`/`JWT_EXPIRED` pour rien sur une route publique (coût négligeable ; l'effacement que `StaleSessionCookiesClearingListener` y ajoute part avec la réponse écartée) | **retenue** |

**Ce que (d) change, vérifié pour chaque garde et mécanisme** :

- **Routes protégées** (une règle non `PUBLIC_ACCESS`) : rien. Le 401 et l'effacement des deux cookies restent
  (`RefusedSessionCookiesAreClearedTest`, sur `/api/me`). Un `BEARER` refusé ne donne jamais plus que l'anonyme : c'est
  l'invariant n° 8 ajouté à `ApiRouteExposureTest` (R35), sur **chaque** route et chaque méthode.
- **`ApiRouteExposureTest`** : `PUBLIC_PATHS` et `BASE_TIER_PATHS` inchangés (aucun appel anonyme ne porte de cookie) ; un
  invariant de plus. **`RouterScopeTest`**, **`AccessControlAnchoringTest`** : aucune route ni aucun motif ne change.
- **Les trois parités** : rien n'est déclaré par environnement ; le service nouveau est une classe `App\` compilée à
  l'identique partout (`SecurityServicesEnvironmentParityTest` la compare, comme ses écouteurs sur `security.event_dispatcher.api`).
- **Un cookie refusé n'est plus effacé par une route publique** : il l'est par la première route protégée qu'il atteint.
  Le SPA en appelle une à chaque chargement (`GET /api/me`, `checkAuth()` de `main.ts`), puis à chaque relecture : le cookie
  mort tombe donc au premier chargement. **Avantage retenu** : la course CR3-2 (un 401 tardif qui efface des cookies neufs)
  reste limitée aux requêtes authentifiées ; effacer aussi sur une route publique l'étendrait à chaque lecture publique,
  les plus nombreuses. Question à l'auteur ci-dessous.
- **`SessionBeforePasswordChangeRefusalListener`** : inchangé ; il audite avant de lever. Une session antérieure au
  changement de mot de passe, présentée à une route publique, est **servie comme anonyme** (plus un 401) et **auditée à
  chaque requête** (`session-refused-after-credentials-change`, le chemin public dans la ligne). Rien de plus que l'anonyme
  n'est servi : ce n'est pas un affaiblissement, et la ligne garde le signal « quelqu'un rejoue une session close ».
- **Audit des refus** : le canal `security` écrit toujours « Authenticator failed. » ; aucun événement d'audit nouveau.
- **Limiteur de `base-access`** (priorité 15, avant le pare-feu) : un jeton par tentative ; plus de second essai (R37),
  donc un seul jeton consommé avec un cookie mort.
- **Double soumission CSRF** (priorité 20, avant le pare-feu) : inchangée ; les routes publiques en écriture sont
  exclues (`EXCLUDED_PATHS`) et ne lisent aucune identité ; `/api/logout` reste gardé.
- **`/api/logout`** (sans règle, donc « publique » pour l'`AccessMap`) avec un cookie mort et son `XSRF-TOKEN` : servi
  anonymement, les deux cookies expirés par `CookieLogoutListener` (2xx au lieu du 401 de Lexik, qui les effaçait aussi) ;
  l'audit `logged-out` sans compte, comme pour un anonyme. Avec une session valide : inchangé.
- **Pare-feu `mcp`** et **`oauth`** : autres dispatchers, rien ne change. **Consentement OAuth** (`ROLE_TRUSTED`) :
  protégé, 401 inchangé.
- **`access_control`** : inchangé ; il reste l'unique source de « public ».

### Choix de conception tranchés

1. **(d) ci-dessus**, nommé `PublicRouteIgnoresRefusedSessionListener` (`Security/Authentication/Infrastructure/Security/`).
   Le prédicat « la route n'exige rien » est **celui d'`AccessListener::supports()`**, recopié en une méthode et épinglé par
   un test qui compare les deux verdicts sur chaque route (R35) : `$attributes` vide ou `[PUBLIC_ACCESS]`.
2. **Une route publique n'efface pas le cookie refusé** (voir plus haut) ; la première route protégée l'efface.
3. **Retrait de `omit` : retour au défaut de `fetch`** (`same-origin`, aucune clé `credentials` écrite) sur les huit
   dépôts, comme avant R23. La garde `credentialsAreExplicit.spec.ts` est supprimée : sa raison (un cookie mort faisait
   répondre 401 une page publique en production, pas en dev) est corrigée côté serveur et épinglée par l'invariant n° 8,
   qui voit toutes les routes, pas seulement les dépôts du SPA. *Écarté* : `'include'` partout (le dev enverrait les cookies
   comme la prod) — huit dépôts réécrits une seconde fois pour une propriété que le serveur rend indifférente.
4. **Une garde de source contre `credentials: 'omit'`** (`noOmittedCredentials.spec.ts`, R37) : C1 n'est visible qu'en
   préprod, derrière la Basic Auth, ni en dev ni en CI ; un `omit` réintroduit y casserait chaque appel concerné sans
   qu'aucun test le voie. Une ligne de test pour une classe de défaut invisible ailleurs. Ce n'est pas la garde retirée :
   celle-ci n'impose rien, elle interdit une valeur.
5. **Contrôle préprod** : un script de fumée (`tools/smoke-dead-session-cookie.sh`, R36) dans `smoke-test-preprod`, sur la
   vraie pile (ingress, nginx, image prod) : un `BEARER` aléatoire sur une lecture publique → 200 sans `Set-Cookie`, sur
   `/api/me` → 401 qui efface. C1 lui-même (ce que le navigateur envoie) se voit au navigateur, en préprod, à la tâche 11.
6. **Le périmètre de refus du backoffice reste dans le domaine** (St4) : `BackofficeSendStamp.refusalScope` est un nombre
   opaque, pas une route ; R38 le rend nullable pour dire « une écriture, jamais périmée ». Le type ne nomme aucune notion
   de navigation : son docblock dit ce qu'est le périmètre (« ce qu'un refus doit encore concerner »), la présentation
   décide quand il avance.
7. **Le périmètre n'avance plus sur le seul fragment** (CR4-1) : la clé surveillée est le chemin et la requête
   (`route.path` + `JSON.stringify(route.query)`). **Un refus d'écriture n'est jamais périmé** : la personne a fait le
   geste, elle doit apprendre son échec — l'alerte s'affiche, au besoin sur la page où elle est allée, et le palier est
   relu. Une **lecture** refusée après une navigation reste ignorée (R28). *Écarté* : un message propre à chaque
   formulaire (`visibleMutationError` rendrait l'erreur au lieu de `null`) — douze pages à toucher pour dire ce que
   l'alerte commune dit déjà.
8. **Le rejeu porte un jeton** (CR4-2) : `const mine = ++replayId` ; seul le dernier rejeu démasque la page.
9. **La route `login` attend la première vérification** (C2) : `beforeEnter` asynchrone, `await waitForAuthCheck()`, comme
   le garde global. Au palier de base, `expired=1` n'annonce rien (CR4-7) : ni « session finie » (l'accès de base tient), ni
   « confirmez votre identité » (aucun compte à confirmer) ; la page est la connexion ordinaire.
10. **Obtenir l'accès de base clôt une session finie** (CR4-3), sans signal, comme `login()` : c'est une session décidée
    dans l'onglet.
11. **`singleFlight`** (St4-4) : `application/auth/singleFlight.ts`, un emplacement de module par relecture, libéré par un
    `.finally` posé **hors** de la tâche ; `recheckEndedSession` et `rereadAfterRefusal` le partagent.
12. **`state` n'est plus exporté** (St4-5) : `sessionState.ts` expose des fonctions nommées (`beginCheck`,
    `raiseSessionEnded`, `lowerSessionEnded`) ; tout le reste lit `authState`. `isSameAccount` devient privé (St4-6).
13. **Un enregistrement « À propos » ne pose son verdict et sa valeur que s'il est le dernier de sa langue** (CR4-6) : un
    numéro par langue.

**Questions à l'auteur** (le plan suit la recommandation ; une autre réponse ne change que la tâche citée) :

- **Q1 (R35)** : une route publique qui reçoit un cookie refusé le **laisse** (recommandé : la course CR3-2 reste aux
  requêtes authentifiées, et `/api/me` l'efface au chargement) ou l'**efface** en servant (un `Set-Cookie` expiré sur la
  réponse 200, posé par l'écouteur) ? Et une session antérieure au changement de mot de passe présentée à une route
  publique reste-t-elle **auditée à chaque requête** (recommandé) ?
- **Q2 (R43)** : la seconde moitié de CR4-6 (effacer l'erreur d'une langue quand sa lecture est rechargée) contredit R31
  (F10-2 : l'erreur revient avec sa langue). Recommandé : garder R31, la seconde moitié écartée.

### Review Focus (quatrième relecture)

1. **Un cookie refusé sur une route protégée** : toujours 401, toujours effacé ; jamais plus que l'anonyme. Test : R35,
   invariant n° 8 et `RefusedSessionCookiesAreClearedTest`.
2. **`/api/logout` avec un cookie mort et son `XSRF-TOKEN`** : servi, les deux cookies expirés, audit anonyme. Test : R35.
3. **Une session close par un changement de mot de passe sur une lecture publique** : 200, une ligne d'audit, cookie
   gardé ; `/api/me` ensuite l'efface. Test : R35, `SessionBeforePasswordChangeTest`.
4. **Le lien d'évitement pendant qu'un refus est affiché**, puis un « Enregistrer » refusé arrivé après une vraie
   navigation : l'alerte reste au premier, s'affiche au second. Test : R38.
5. **Onglet neuf sur `login?expired=1` pendant que `/api/me` est en vol** : aucune annonce avant la réponse, puis la bonne.
   Test : R40.

### Contraintes propres aux tâches R35 à R45

- Les **Global Constraints** et toutes les contraintes des séries précédentes valent. Exécution : un agent par tâche
  (sonnet, effort high), successifs dans l'ordre R35 → R45, `superpowers:test-driven-development` (test écrit d'abord, **vu
  rouge pour la bonne raison** ; refactoring pur : la suite existante comme filet, nombre de tests noté avant et après), un
  commit par tâche, fichiers nommés (`git add <fichiers>`, `git rm` pour une suppression), message en français terminé par
  les lignes d'attribution que donne le fil principal.
- Backend (R35, R44) : lire avec `Read` un fichier de `backend/src/Security/Authentication/Infrastructure/` avant d'écrire ;
  test ciblé `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis `make back-test` et `make back-quality`
  (toujours `-u dev`) ; `rm -rf var/cache/test` après un changement de configuration ou de service ; un JWT de test se signe
  par l'encodeur du conteneur (`JWTEncoderInterface` ou `JWTTokenManagerInterface`), une valeur invalide se tire au hasard
  (`bin2hex(random_bytes(16))`) — jamais de secret littéral (`TestCredentials`, `TestKeys::material()`).
- Outillage (R36) : lire avec `Read` `tools/smoke-login-throttling.sh` et `.github/workflows/pipeline.yml` avant d'écrire ;
  `bash tools/tests/smoke-dead-session-cookie.test.sh` hors ligne, `shellcheck -S warning`, `tools/check-workflow-timeouts.sh`,
  `tools/check-release-gates.sh`.
- Frontend : test ciblé `docker compose exec frontend npm test -- <chemins>`, puis `make front-test`, `make front-lint`,
  `make front-build`. Vitest 5 vide les imports CSS (lire par `node:fs`) ; axe-core se bloque sous faux minuteurs
  (`vi.useRealTimers()` avant `expectNoAccessibilityViolation`).
- Règles : `tools/check-claude-rules.sh` après chaque modification (50 000 octets par règle) ; **remplacer** des phrases
  plutôt qu'en ajouter dans `security-authentication-sessions.md`, `frontend-session*.md`, `frontend.md`. Un chemin ajouté
  aux `paths:` d'une règle l'est dans le commit qui crée le fichier.
- Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets ; ne jamais nommer un compte réel.
  Pas de push, pas de PR.

### Fichiers (tâches R35 à R45)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php` (nouveau) | une route publique servie malgré un cookie refusé | R35 |
| `backend/tests/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSession{Test,RegistrationTest}.php` (nouveaux) | comportement, inscription et prédicat | R35 |
| `backend/tests/Security/ApiRouteExposureTest.php` | invariant n° 8 | R35 |
| `backend/tests/Security/Authentication/Infrastructure/Jwt/RefusedSessionCookiesAreClearedTest.php`, `backend/tests/Security/Authentication/SessionBeforePasswordChangeTest.php`, `backend/tests/Security/User/Presentation/Controller/BaseAccessControllerTest.php` | ce qui change sur une route publique | R35 |
| `backend/src/Security/Authentication/Infrastructure/{Jwt/StaleSessionCookiesClearingListener,Http/CsrfCookieRequestSubscriber}.php`, `backend/src/Security/User/Presentation/Controller/BaseAccessController.php` | docblocks | R35 |
| `tools/smoke-dead-session-cookie.sh`, `tools/tests/smoke-dead-session-cookie.test.sh` (nouveaux), `.github/workflows/pipeline.yml` | contrôle préprod | R36 |
| les huit dépôts publics `frontend/src/infrastructure/{about,quality,incidents,contributions,watch,experience,contact,account}/Http*Repository.ts` et leurs specs | `omit` retiré | R37 |
| `frontend/src/infrastructure/baseAccess/HttpBaseAccessRepository.ts` et son spec | rejeu retiré | R37 |
| `frontend/tests/infrastructure/http/credentialsAreExplicit.spec.ts` (supprimé), `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts` (nouveau) | gardes | R37 |
| `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue`, `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts`, `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/tests/support/setup.ts` | périmètre de refus, remise à zéro | R38 |
| `frontend/src/presentation/layout/AppLayout.vue` | jeton de rejeu | R39 |
| `frontend/src/presentation/router/index.ts`, `frontend/src/presentation/pages/LoginPage.vue` | la connexion attend la vérification ; le palier de base | R40 |
| `frontend/src/application/auth/{singleFlight,sessionState,useAuth,sessionLifecycle}.ts` | accès de base et session finie ; `singleFlight` ; encapsulation | R41, R42 |
| `frontend/src/application/admin/about/useAdminAboutSettings.ts` | dernier enregistrement d'une langue | R43 |
| `backend/tests/Security/Authentication/Infrastructure/Jwt/JwtClockSkewTest.php` | marges | R44 |
| `.claude/rules/*`, `docs/adr/0003-paliers-d-acces.md`, `docs/rgpd/registre-traitements.md`, `frontend/src/infrastructure/portfolio/content/{fr,en}.ts`, `.claude/CLAUDE.md` | documentation | R35, R37, R45 |

---

### Task R35 : Backend — une route publique sert l'anonyme malgré un `BEARER` refusé (C1 côté serveur, CR4-5, Sp4-c)

**Files :**
- Create : `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php`
- Create : `backend/tests/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionTest.php`, `…/PublicRouteIgnoresRefusedSessionRegistrationTest.php`
- Modify (tests) : `backend/tests/Security/ApiRouteExposureTest.php`, `backend/tests/Security/Authentication/Infrastructure/Jwt/RefusedSessionCookiesAreClearedTest.php`, `backend/tests/Security/Authentication/SessionBeforePasswordChangeTest.php`, `backend/tests/Security/User/Presentation/Controller/BaseAccessControllerTest.php`
- Modify (docblocks) : `StaleSessionCookiesClearingListener.php`, `CsrfCookieRequestSubscriber.php` (paragraphe A3-11 d'`EXCLUDED_PATHS`), `BaseAccessController.php` (commentaire R24)
- Modify (docs) : `.claude/rules/security-authentication-sessions.md` (`paths:` + puces `StaleSessionCookiesClearingListener`, « A session opened before… refused by the whole `api` firewall »), `.claude/rules/security-authentication.md` (puce « Base access », dernière phrase), `docs/adr/0003-paliers-d-acces.md` (amendement du 2026-10-29, paragraphe « La contrepartie… »), `.claude/CLAUDE.md` (ligne de l'ADR 0003 : « refused by the whole `api` firewall »)

**Interfaces :**
- `final readonly class PublicRouteIgnoresRefusedSessionListener`, `#[AsEventListener(event: LoginFailureEvent::class, dispatcher: 'security.event_dispatcher.api', priority: -1024)]`, constructeur `(#[Autowire(service: 'security.access_map')] AccessMapInterface $accessMap)` ; `__invoke(LoginFailureEvent $event): void` : si `!$this->requiresAccess($event->getRequest())`, `$event->setResponse(null)`.
- `public function requiresAccess(Request $request): bool` — `[$attributes] = $this->accessMap->getPatterns($request)` ; vrai si `$attributes` non vide **et** différent de `[AuthenticatedVoter::PUBLIC_ACCESS]` (la condition d'`AccessListener::supports()`, citée dans le docblock).
- Docblock (français) : pourquoi (C1 ; un cookie de session refusé faisait répondre 401 à tout `/api`), pourquoi l'`AccessMap` (aucune autre liste de ce qui est public ; toute autorisation de `/api` est dans `access_control`, vérifié), pourquoi le dispatcher d'`api` seul (`login` n'a pas de JWT ; `mcp`, `oauth` d'autres pare-feux), pourquoi la dernière priorité (le dernier mot sur la réponse), ce que Lexik fait quand même (réponse construite puis écartée, `JWT_INVALID`/`JWT_EXPIRED` émis, l'effacement part avec elle — choix n° 2), et les options écartées (a, b, c, une phrase chacune).

- [ ] **Step 1 : filet** — `make back-test` vert, nombre noté.
- [ ] **Step 2 : tests, rouges**
  - `ApiRouteExposureTest`, **invariant n° 8** : `testARefusedSessionCookieNeverChangesTheAnonymousAnswer` — pour chaque chemin de `allApiRoutePaths()` et chaque méthode de `requestableMethods()`, avec le corps et les en-têtes de l'invariant n° 7, trois appels sur un pot de cookies vidé (`getCookieJar()->clear()`) et les quotas remis à zéro (`cache.rate_limiter`) avant chacun : anonyme ; `BEARER` aléatoire ; `BEARER` expiré signé par l'encodeur (`exp` une heure passée). Le statut des deux derniers **égale** celui de l'anonyme (message : la méthode, le chemin, les trois statuts) ; pour une route de `PUBLIC_PATHS`, la réponse avec cookie ne pose aucun cookie que l'anonyme ne pose pas (noms comparés). Rouge : chaque `GET` public répond 401.
  - `PublicRouteIgnoresRefusedSessionTest` (fonctionnel) : `testAnExpiredCookieOnAPublicReadIsServedAndKept` (`/api/contributions/fr` : 200, aucun `Set-Cookie`, le pot garde `BEARER`) ; `testAnInvalidCookieOnAPublicReadIsServedAndKept` ; `testTheFirstProtectedRouteStillRefusesAndClears` (le même cookie, puis `/api/me` : 401, les deux cookies expirés aux attributs de la fabrique — `ReadsResponseCookies`) ; `testLogoutWithADeadCookieExpiresBothCookies` (connexion d'un compte de test, `BEARER` remplacé par une valeur aléatoire, `POST /api/logout` avec l'en-tête `X-XSRF-TOKEN` du cookie : 2xx, les deux cookies expirés) ; `testAValidSessionOnAPublicRouteIsStillAuthenticated` (témoin : une session valide sur `/api/logout` donne une ligne `logged-out` **avec** l'UUID du compte) ; `testThePredicateAgreesWithTheAccessListenerOnEveryRoute` (pour chaque route `/api` du routeur, `requiresAccess()` égale `AccessListener::supports() === true`, l'`AccessListener` lu dans le conteneur de test).
  - `PublicRouteIgnoresRefusedSessionRegistrationTest` : présent parmi les écouteurs de `LoginFailureEvent` de `security.event_dispatcher.api`, **le dernier** ; absent de ceux de `security.event_dispatcher.login` et du dispatcher principal (modèle : `SessionBeforePasswordChangeRefusalListenerRegistrationTest`, `OverlongPasswordRefusalListenerPriorityTest`).
  - `RefusedSessionCookiesAreClearedTest` : la route qui refuse devient `PROTECTED_ROUTE = '/api/me'` (les deux tests y prennent le 401 et l'effacement, puis `PUBLIC_ROUTE` répond 200) ; docblock de classe réécrit (la route publique ne refuse plus, R35) et celui de `PUBLIC_ROUTE` cite **St3-12** (Sp4-c).
  - `SessionBeforePasswordChangeTest` : `testTheRefusalClearsTheDeadCookiesSoThePublicRoutesStayServed` devient `testAClosedSessionIsServedAsAnonymousOnAPublicRouteAndClearedByTheFirstProtectedOne` (cookie de version 0 sur un compte en version 1 : `GET /api/contributions/fr` → 200, **une** ligne `session-refused-after-credentials-change` au chemin public, aucun `Set-Cookie` ; puis `GET /api/me` → 401 et l'effacement exact ; puis `POST /api/account/base-access` aboutit).
  - `BaseAccessControllerTest` : `testADeadCookieDoesNotStopTheBaseAccess` (`BEARER` aléatoire : 200, cookies neufs, **un seul** jeton consommé du limiteur de `BaseAccessRateLimitRequestListener` — lu par `TestServiceLocator` (`limiter.base_access`, la clé de l'IP de test, `consume(0)->getRemainingTokens()`) avant et après).
  Run : `docker compose exec -u dev backend php bin/phpunit tests/Security`. Expected : 401 là où 200 est attendu ; classe introuvable.
- [ ] **Step 3 : implémenter** — selon les interfaces ; `rm -rf var/cache/test`. **Mutation à mesurer et noter dans le message** : l'attribut `#[AsEventListener]` retiré → l'invariant n° 8 et `PublicRouteIgnoresRefusedSessionTest` rougissent ; `requiresAccess()` qui rend toujours `false` → `testTheFirstProtectedRouteStillRefusesAndClears` et l'invariant n° 8 (sur `/api/me`) rougissent.
- [ ] **Step 4 : docblocks** — `StaleSessionCookiesClearingListener` : le paragraphe « Le firewall `api` authentifie dès qu'un cookie `BEARER` est présent, sur une route publique comme sur les autres » et « Le prix, accepté » remplacés : une route publique sert l'anonyme sans rien effacer (R35) ; le refus et l'effacement ne restent que sur une route qui exige un rôle, et le SPA en lit une à chaque chargement (`/api/me`) ; la limite CR3-2 inchangée (plus étroite : requêtes authentifiées seules). `CsrfCookieRequestSubscriber` : la mention `credentials: 'omit'` remplacée (le SPA envoie ses cookies à `/api/contact` ; la route ne lit aucune identité, et un cookie refusé n'y fait plus répondre 401, R35). `BaseAccessController` : le rejeu (R24) remplacé — un cookie refusé est ignoré sur cette route publique (R35), un seul jeton du limiteur.
- [ ] **Step 5 : règles, ADR, CLAUDE.md** — `security-authentication-sessions.md` : `paths:` gagne le listener et ses deux tests ; la puce `StaleSessionCookiesClearingListener` : « The firewall authenticates as soon as a cookie is present, public routes included » et l'« Accepted limit » `credentials: 'omit'` **remplacés** par une phrase sur `PublicRouteIgnoresRefusedSessionListener` (une route sans règle d'`access_control` sert l'anonyme, rien n'est effacé ; la première route protégée efface ; invariant n° 8) ; « refused by the whole `api` firewall » → « refused by every route of the `api` firewall that requires a role; a public route serves the request as anonymous, the refusal still audited ». `security-authentication.md`, puce « Base access », dernière phrase : « A refused `BEARER` is ignored here (a public route, #748 R35): one limiter token. » ADR 0003, amendement du 2026-10-29 : les phrases « le firewall `api` authentifie dès qu'un `BEARER` est présent, route publique comprise… » et « le SPA ne l'envoie plus à une page publique… (`credentials: 'omit'`, R23…) » remplacées par le correctif serveur (décision de l'auteur du 2026-10-29, R35, qui remplace R23 et R24) ; la limite CR3-2 dite pour les requêtes authentifiées seules. `.claude/CLAUDE.md`, ligne de l'ADR 0003 : « is refused by every route of the `api` firewall that requires a role ».
- [ ] **Step 6 : verts** — `make back-test` (filet + nouveaux), `make back-quality` (`-u dev`), `tools/check-claude-rules.sh`.
- [ ] **Step 7 : commit** — `fix(security): une route publique sert l'anonyme malgré un cookie de session refusé (#748)`.

---

### Task R36 : Le contrôle préprod d'un cookie mort (C1, contrôle demandé)

**Files :**
- Create : `tools/smoke-dead-session-cookie.sh`, `tools/tests/smoke-dead-session-cookie.test.sh`
- Modify : `.github/workflows/pipeline.yml` (une étape dans `smoke-test-preprod`, après « Throttling du login » ; le test dans la liste de `tools-tests`), `.claude/rules/security-authentication-sessions.md` (`paths:` : les deux fichiers ; une phrase dans la puce de R35)

**Interfaces :**
- `tools/smoke-dead-session-cookie.sh <url-de-base>` ; env `AUDIT_BASIC_AUTH` (fichier `-K` en 600, jamais `-u`, comme `smoke-login-throttling.sh`), `SMOKE_CURL` (substitut hors ligne). Le cookie est une valeur aléatoire (`openssl rand -hex 16`), **pas un secret** (le dire dans l'en-tête du script : il passe en argument `-b`). Trois vérifications, chacune nommée à l'échec : (1) `GET /api/contributions/fr` **sans** cookie → 200 (témoin) ; (2) le même avec `BEARER=<aléatoire>` → 200 et **aucun** `Set-Cookie: BEARER` ; (3) `GET /api/me` avec le même cookie → 401 et un `Set-Cookie: BEARER=` expiré (`Max-Age=0` ou `expires=` passé, insensible à la casse). Code de sortie 1 sur un écart, 2 sur un usage faux.

- [ ] **Step 1 : test hors ligne, rouge** — `smoke-dead-session-cookie.test.sh` (modèle : `smoke-login-throttling.test.sh`, un faux `curl` qui rend des en-têtes et un statut par URL et par présence du cookie) : la pile corrigée → sortie 0 ; la route publique en 401 avec cookie → 1, message « lecture publique refusée pour un cookie mort » ; `/api/me` sans `Set-Cookie` expirant → 1 ; un 401 de l'ingress sur le témoin → 1, message « Basic Auth » ; sans argument → 2 ; la Basic Auth jamais dans les arguments du faux `curl`. Expected : script introuvable.
- [ ] **Step 2 : implémenter** le script (`set -euo pipefail`, en-têtes lus dans un fichier `mktemp`, nettoyé par `trap`).
- [ ] **Step 3 : brancher** — étape « Un cookie mort ne ferme pas les lectures publiques » (`timeout-minutes: 2`, `AUDIT_BASIC_AUTH: ${{ secrets.PREPROD_BASIC_AUTH }}`) ; le test dans `tools-tests`.
- [ ] **Step 4 : verts** — `bash tools/tests/smoke-dead-session-cookie.test.sh`, `shellcheck -S warning tools/smoke-dead-session-cookie.sh tools/tests/smoke-dead-session-cookie.test.sh`, `tools/check-workflow-timeouts.sh`, `tools/check-release-gates.sh`, `tools/check-claude-rules.sh` ; actionlint si disponible dans le conteneur d'outils (sinon le noter : le job `tools-tests` le passe).
- [ ] **Step 5 : commit** — `ci: la préprod vérifie qu'un cookie mort ne ferme pas les lectures publiques (#748)`.

---

### Task R37 : Retrait de `credentials: 'omit'` et du rejeu d'« Accès instantané » (décision 1 ; C1 côté frontend, CR4-4/C4/Sp4-2, C6, CR4-9)

**Files :**
- Modify : les huit dépôts publics et leurs huit specs ; `frontend/src/infrastructure/baseAccess/HttpBaseAccessRepository.ts` et `frontend/tests/infrastructure/baseAccess/HttpBaseAccessRepository.spec.ts`
- Delete : `frontend/tests/infrastructure/http/credentialsAreExplicit.spec.ts` (`git rm`)
- Create : `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`
- Modify : `.claude/rules/frontend.md` (« API-backed content », « Base access »), `.claude/rules/frontend-session.md` (« What stays here », « Accepted limits » dernière puce, « Tests »)

**Interfaces :**
- Chaque dépôt public : l'appel `fetch` d'avant R23 (aucune clé `credentials`) ; docblock : « Aucun `credentials` : le défaut `same-origin` envoie les cookies sur une seule origine (préprod, prod), et le serveur sert une route publique comme à l'anonyme quel que soit le cookie (#748, R35). Jamais `'omit'` : il retire aussi la Basic Auth de la préprod (C1). »
- `HttpBaseAccessRepository.grant()` : un seul `fetch` (`credentials: 'include'`, `LOGIN_CSRF_HEADER`) ; 429 → `rate-limited`, tout autre échec → `unknown`. Docblock sans rejeu (le cookie refusé est ignoré par le serveur sur cette route, R35).
- `noOmittedCredentials.spec.ts` : lit `src/**/*.{ts,vue}` (`import.meta.glob` `?raw`, `eager`) ; aucun fichier ne contient `credentials: 'omit'` ni `credentials: "omit"` (message : la Basic Auth de la préprod, C1).

- [ ] **Step 1 : filet** — `make front-test` vert, nombre noté.
- [ ] **Step 2 : tests, rouges** — `noOmittedCredentials.spec.ts` (rouge : huit fichiers) ; les huit specs : l'assertion `toHaveBeenCalledWith(url, {…})` perd `credentials: 'omit'` (changement exprès ; `HttpAccountRepository.spec.ts` pour `validate` et la complétion) ; `HttpBaseAccessRepository.spec.ts` : les quatre tests du rejeu (R24) remplacés par `it("un 401 est un échec unknown, un seul appel")` et `it("un 429 est rate-limited, un seul appel")`. Expected : deux appels sur 401 ; `credentials` présent.
- [ ] **Step 3 : implémenter** — selon les interfaces ; `git rm frontend/tests/infrastructure/http/credentialsAreExplicit.spec.ts`.
- [ ] **Step 4 : règles** — `frontend.md`, « API-backed content » : la phrase `credentials: 'omit'` / `credentialsAreExplicit.spec.ts` **remplacée** par : ces dépôts n'écrivent pas `credentials` (défaut `same-origin`) ; le serveur sert une route publique comme à l'anonyme quel que soit le cookie (R35) ; `'omit'` est interdit (`noOmittedCredentials.spec.ts`) — il retire aussi la Basic Auth de la préprod. « Base access » : la phrase du rejeu (R24) remplacée par « a refused `BEARER` is ignored by the server on this public route (R35): one call, one limiter token ». `frontend-session.md` : « What stays here » sans « the explicit `credentials` » ; dernière puce des « Accepted limits » : « After the `exp` of a JWT the first 401 only reaches a route that requires a role: the server serves a public route as anonymous whatever the cookie (#748, R35), and the SPA reads `/api/me` at every load, which clears a dead cookie… » (la suite sur l'horloge du poste gardée) ; « Tests » : la ligne `credentialsAreExplicit.spec.ts` devient `noOmittedCredentials.spec.ts`.
- [ ] **Step 5 : verts** — `grep -rn "credentials: 'omit'\|R24\|credentialsAreExplicit" frontend/src frontend/tests .claude/rules` ne rend que l'historique daté (le noter) ; `make front-test` (filet − 3 : − 2 de la garde, − 4 du rejeu, + 2, + 1), `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 6 : commit** — `fix(frontend): les dépôts publics envoient de nouveau leurs cookies et la Basic Auth ; « Accès instantané » sans rejeu (#748)`.

---

### Task R38 : Le périmètre de refus du backoffice — le fragment ne compte pas, une écriture n'est jamais périmée ; l'état remis à zéro (CR4-1/C3, CR4-8/Sp4-3, St4 jugement)

**Files :**
- Modify : `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue`, `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts`, `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/tests/support/setup.ts`
- Test : `AccessRefusedNotice.spec.ts`, `backofficeAccessRefusal.spec.ts`, `backofficeSessionExpiry.spec.ts`, `BackofficeHttpClient.spec.ts`, `AdminLayout.sessionEnded.spec.ts`
- Modify : `.claude/rules/frontend-session-refus.md` (« The backoffice reaction », la puce du backoffice de « A real refusal », « Tests »), `.claude/rules/frontend-session-retour.md` (la phrase sur `tests/support/setup.ts`)

**Interfaces :**
- `domain/admin/shared/BackofficeSessionReaction.ts` : `type BackofficeSendKind = 'read' | 'write'` ; `BackofficeSendStamp { readonly session: number; readonly refusalScope: number | null }` (`null` : une écriture, jamais périmée — docblock, choix n° 6) ; `stampAtSend(kind: BackofficeSendKind): BackofficeSendStamp`.
- `BackofficeHttpClient` : `get` appelle `stampAtSend('read')`, `mutate` `stampAtSend('write')`.
- `backofficeSessionExpiry.ts` : `stampAtSend(kind)` = `{ session: sessionStamp(), refusalScope: 'write' === kind ? null : refusalScope() }`.
- `backofficeAccessRefusal.ts` : `markBackofficeAccessRefused(sentIn: number | null): boolean` — `null` compte toujours ; `resetBackofficeAccessRefusalForTests(): void` (compteur et périmètre à 0 ; réservée aux tests).
- `AccessRefusedNotice.vue` : le `watch` porte sur `() => route.path + JSON.stringify(route.query)` (plus `route.fullPath`) ; docblock : pourquoi pas le fragment (le lien d'évitement).
- `tests/support/setup.ts` : `beforeEach` appelle aussi `resetBackofficeAccessRefusalForTests()` et `setBackofficeSessionReaction(null)`.

- [ ] **Step 1 : tests, rouges**
  - `AccessRefusedNotice.spec.ts` (Review Focus 4) : `it("un changement du seul fragment garde l'alerte et le périmètre")` (refus compté, `router.push({ hash: '#main-content' })` → alerte présente, `refusalScope()` inchangé) ; `it("un changement de requête efface")` (vert).
  - `backofficeSessionExpiry.spec.ts` : `it("un refus d'écriture parti avant une navigation est compté et relu")` (`stampAtSend('write')`, `clearBackofficeAccessRefusal()`, `refused(stamp)` → compteur 1, `rereadAfterRefusal` appelé une fois) ; le test R28 de la lecture reste vert avec `stampAtSend('read')`.
  - `BackofficeHttpClient.spec.ts` : `get` timbre `'read'`, `mutate` `'write'` (l'argument de `stampAtSend`).
  - `AdminLayout.sessionEnded.spec.ts` : `it("le 403 d'un enregistrement revenu après une navigation s'affiche sur la page suivante")`.
  - `backofficeAccessRefusal.spec.ts` : deux tests successifs — le premier compte un refus et avance le périmètre, le second lit `backofficeAccessRefused.value === 0` et `refusalScope() === 0` ; `BackofficeHttpClient.spec.ts` : de même, une réaction enregistrée dans un test n'est plus appelée au suivant.
  Expected : alerte effacée par le fragment ; compteur 0 pour l'écriture ; état hérité.
- [ ] **Step 2 : implémenter** — selon les interfaces.
- [ ] **Step 3 : règles** — `frontend-session-refus.md` : le timbre `{ session, refusalScope }` avec `refusalScope` nul pour une écriture ; « cleared on every `route.fullPath` change » → « on every change of path or query (not of the fragment: the skip link) » ; « A refusal sent before a navigation does not count » → « A **read** refused after a navigation does not count; a **write** always does — the person made the gesture » ; « Tests » : les nouveaux. `frontend-session-retour.md` : `setup.ts` remet aussi à zéro le refus du backoffice et sa réaction.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): un refus d'enregistrement n'est jamais périmé, le fragment n'efface pas l'alerte ; l'état du refus remis à zéro avant chaque test (#748)`.

---

### Task R39 : Deux rejeux qui se chevauchent ne démasquent pas la page trop tôt (CR4-2/C5)

**Files :**
- Modify : `frontend/src/presentation/layout/AppLayout.vue`
- Test : `frontend/tests/presentation/layout/AppLayout.spec.ts`
- Modify : `.claude/rules/frontend-session-retour.md` (la phrase « The routed page is hidden during the replay »)

**Interfaces :**
- `let replayId = 0` (module du composant) ; l'abonné : `const mine = ++replayId`, `isReplaying.value = true`, `void replayNavigation(mine)` ; dans le `finally`, `pageKey` avance toujours, `isReplaying` ne retombe que si `mine === replayId`.

- [ ] **Step 1 : test, rouge** — `it("deux rejeux qui se chevauchent : la page reste masquée jusqu'au dernier")` : une garde dont la promesse est retenue par appel ; deux `notifyAccountSwitched` ; la première garde libérée → sonde absente ; la seconde libérée → sonde présente. Expected : sonde présente après la première.
- [ ] **Step 2 : implémenter** ; docblock d'`isReplaying` : le jeton et pourquoi.
- [ ] **Step 3 : règle** — une demi-phrase : « only the last replay unhides it ».
- [ ] **Step 4 : verts** — spec PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): seul le dernier rejeu de la navigation démasque la page (#748)`.

---

### Task R40 : La connexion attend la première vérification ; le palier de base n'est pas une session finie (Sp4-1/C2, CR4-7)

**Files :**
- Modify : `frontend/src/presentation/router/index.ts` (route `login`), `frontend/src/presentation/pages/LoginPage.vue`
- Test : `frontend/tests/presentation/pages/LoginPage.spec.ts`, `frontend/tests/presentation/router/adminGuard.spec.ts`
- Modify : `.claude/rules/frontend-session-bandeau.md` (« Both flags open the login… »)

**Interfaces :**
- `beforeEnter: async (to) => { await waitForAuthCheck(); … }` — le reste inchangé.
- `LoginPage` : `const { login, user, tier } = useAuth()` ; `hasSessionEnded = 'anonymous' === tier.value && isSessionEnded(route)` ; `isReauthenticatingASession` inchangé (un compte affiché). Au palier de base avec `expired=1` : aucune des deux annonces (choix n° 9).

- [ ] **Step 1 : tests, rouges**
  - `adminGuard.spec.ts` (le routeur de l'application, qui porte le `beforeEnter` ; Review Focus 5) : `it("onglet neuf, expired=1, /api/me résolu après le montage avec un compte : « session finie » n'apparaît jamais")` — `isChecking` vrai (état de chargement, remis par `setup.ts`), `router.push('/fr/login?expired=1')` non attendu, `flushPromises` : ni `login-session-ended-notice` ni la page ; `checkAuth()` résolu avec un compte → `login-reauth-notice` ; `it("… avec l'anonyme : « session finie » après la vérification")` ; `it("une personne connectée qui ouvre /login sans drapeau dans un onglet neuf va à l'accueil")` (vérification en vol, puis un compte).
  - `LoginPage.spec.ts` : `it("palier de base, expired=1 : aucune annonce")` (FR et EN).
  Expected : l'annonce rendue avant la vérification ; la page de connexion ouverte à un compte ; « session finie » au palier de base.
- [ ] **Step 2 : implémenter** — selon les interfaces.
- [ ] **Step 3 : règle** — « What the page says » : anonyme → « votre session a pris fin » ; un compte → l'annonce neutre ; **palier de base → rien** ; la route attend la première vérification (`waitForAuthCheck`), comme le garde des routes protégées. Remplacer, ne pas ajouter.
- [ ] **Step 4 : verts** — specs PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): la connexion attend la vérification de la session ; expired=1 ne dit rien au palier de base (#748)`.

---

### Task R41 : L'accès de base clôt une session finie ; une relecture ne reste jamais bloquée (CR4-3, St4-4)

**Files :**
- Create : `frontend/src/application/auth/singleFlight.ts`, `frontend/tests/application/auth/singleFlight.spec.ts`
- Modify : `frontend/src/application/auth/{sessionState,useAuth,sessionLifecycle}.ts` (`sessionLifecycle` : son docblock de tête seulement)
- Test : `frontend/tests/application/auth/useAuth.spec.ts`
- Modify : `.claude/rules/frontend-session-retour.md` (« The state », « The re-check on return » : une relecture en vol)

**Interfaces :**
- `singleFlight.ts` : `interface SingleFlight<T> { run(task: () => Promise<T>): Promise<T>; clear(): void }` ; `createSingleFlight<T>(): SingleFlight<T>` — en vol : rend la promesse en cours sans appeler `task` ; sinon appelle `task` (un `throw` synchrone devient une promesse rejetée), pose la promesse, puis la libère par un `.finally` posé **après** la pose (si c'est toujours elle). Docblock : pourquoi (St4-4 ; le défaut corrigé par R15 sur `rereadAfterRefusal`, écrit une fois).
- `useAuth.ts` : `const endedSessionRecheck = createSingleFlight<void>()`, `const refusalReread = createSingleFlight<RefusalReread>()` ; les deux IIFE deviennent des tâches passées à `run()`, sans `finally` interne ; `resetSessionRereadsForTests()` appelle `clear()` sur les deux.
- `sessionState.markBaseAccessGranted()` : met aussi `sessionEnded` à `false`, sans signal ni annonce (la génération avance déjà) ; docblock : une session décidée dans l'onglet clôt la fin, comme `login()` (CR4-3). Le docblock de tête de `sessionLifecycle.ts` (« les seuls à lever et à baisser ») cite cette seconde descente.

- [ ] **Step 1 : tests, rouges**
  - `singleFlight.spec.ts` : `it("partage la promesse en vol")` ; `it("libère après une résolution")` ; `it("libère après un rejet")` ; `it("une tâche qui lève de façon synchrone : rejet, puis libérée")`.
  - `useAuth.spec.ts` (St4-4) : `it("session finie, me() lève de façon synchrone : la relecture suivante rappelle me()")` (`me = vi.fn(() => { throw new Error('réseau') })`, deux `recheckEndedSession()` → deux appels). Expected : un seul appel.
  - `useAuth.spec.ts` (CR4-3) : `it("session finie, puis accès de base obtenu : la session n'est plus finie, aucun signal")` (`markSessionExpired()`, `markBaseAccessGranted()` → `hasSessionEnded` faux, palier base, ni `onSessionRestored` ni `onAccountSwitched`) ; `it("puis une connexion du même compte : aucun rejeu")` (`login()` ne signale rien, R18). Expected : `hasSessionEnded` vrai.
- [ ] **Step 2 : implémenter** — selon les interfaces ; les tests existants des deux relectures restent verts sans changement.
- [ ] **Step 3 : règle** — « The state » : l'accès de base obtenu baisse aussi `sessionEnded` (sans signal) ; « one re-read in flight » : `createSingleFlight`, un seul endroit.
- [ ] **Step 4 : verts** — specs PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): l'accès de base clôt une session finie ; une relecture de session ne reste jamais bloquée (#748)`.

---

### Task R42 : L'état de session encapsulé (St4-5, St4-6, C12 ; refactoring pur)

**Files :**
- Modify : `frontend/src/application/auth/{sessionState,sessionLifecycle,useAuth}.ts`
- Create : `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts`
- Modify : `.claude/rules/frontend-session-retour.md` (« Where it lives »)

**Interfaces :**
- `sessionState.ts` : `state` **non exporté** ; exports nouveaux `beginCheck(): void` (`isChecking` monte seulement avant la première vérification — la condition sort de `checkAuth`), `raiseSessionEnded(): void` (`sessionEnded` vrai, compteur + 1), `lowerSessionEnded(): void`. `isFirstCheckDone` retiré s'il n'a plus d'appelant.
- `sessionLifecycle.ts` : écrit par ces fonctions, lit `authState` ; `isSameAccount` **non exporté**. `useAuth.ts` : `beginCheck()` ; lit `authState.sessionEnded`.

- [ ] **Step 1 : filet** — `make front-test` vert, nombre noté.
- [ ] **Step 2 : test, rouge** — `sessionStateEncapsulation.spec.ts` : `it("sessionState n'exporte pas l'état en écriture")` (`Object.keys(await import(…/sessionState))` ne contient pas `state`) ; `it("sessionLifecycle n'exporte pas isSameAccount")`. Expected : `state` et `isSameAccount` présents.
- [ ] **Step 3 : implémenter** — comportement inchangé ; aucun autre test ne change.
- [ ] **Step 4 : C12** — `docker compose exec frontend npx vite build --minify false --outDir /tmp/dist-nominify` puis `docker compose exec frontend grep -c "ForTests" -r /tmp/dist-nominify` : 0 attendu (Rollup retire les exports inutilisés) ; sinon, les fonctions `reset…ForTests` passent dans `resetAuthStateForTests.ts` (jamais importé par `src/`) et la mesure est refaite. Le résultat dans le message de commit ; `/tmp/dist-nominify` supprimé.
- [ ] **Step 5 : règle** — « Where it lives » : `sessionState.ts` n'exporte que des fonctions nommées et `authState` (lecture seule).
- [ ] **Step 6 : verts** — `make front-test` (filet + 2), `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 7 : commit** — `refactor(frontend): l'état de session n'est plus écrit hors de son module (#748)`.

---

### Task R43 : Réglages « À propos » — seul le dernier enregistrement d'une langue décide (CR4-6, première moitié)

**Files :**
- Modify : `frontend/src/application/admin/about/useAdminAboutSettings.ts`
- Test : `frontend/tests/application/admin/about/useAdminAboutSettings.spec.ts`
- Modify : `.claude/rules/frontend-backoffice.md` (« The error stays with its language »)

**Interfaces :**
- `const lastSaveOf: Partial<Record<Locale, number>> = {}` ; `save(locale)` : `const mine = (lastSaveOf[locale] ?? 0) + 1`, posé ; efface `failures[locale]` au départ (inchangé) ; à l'arrivée, **seulement si** `lastSaveOf[locale] === mine` : succès → `write(locale, saved)`, échec visible → `failures[locale]`. Une réponse plus ancienne : `save()` rend quand même `true`/`false` (son vrai sort), sans rien poser.

- [ ] **Step 1 : tests, rouges** — `it("deux enregistrements FR : le plus ancien échoue après le succès du plus récent — aucune erreur FR")` ; `it("deux enregistrements FR : la réponse du plus ancien, arrivée après le plus récent, ne remplace pas ses valeurs")` ; témoin vert : `it("le plus récent échoue après le succès de l'ancien : l'erreur FR")`. Les tests R20/R31 restent verts. Expected : erreur FR posée ; anciennes valeurs posées.
- [ ] **Step 2 : implémenter**.
- [ ] **Step 3 : règle** — « The error stays with its language » : seul le dernier enregistrement d'une langue pose son verdict et sa valeur.
- [ ] **Step 4 : verts** — spec PASS ; `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 5 : commit** — `fix(frontend): réglages « À propos », seul le dernier enregistrement d'une langue décide (#748)`.

---

### Task R44 : `JwtClockSkewTest` stable et borné (CR4-10/C10)

**Files :**
- Modify : `backend/tests/Security/Authentication/Infrastructure/Jwt/JwtClockSkewTest.php`

**Interfaces :** aucune ; marges calculées sur les deux vérifications (`LoadedJWS::checkExpiration()` / `checkIssuedAt()` à la seconde, `LooseValidAt` de lcobucci à la microseconde) : un cas « servi » doit le rester **4 s** après la signature, un cas « refusé » le reste pour toujours ou au moins 4 s.

- [ ] **Step 1 : tests** — `testATokenExpiredThreeSecondsAgoIsServed` devient `testATokenExpiredOneSecondAgoIsServed` (`exp = time() - 1`) ; `testAnIssuedAtThreeSecondsAheadIsServed` → `iat = time() + 1` ; nouveaux bornes : `testATokenExpiredSevenSecondsAgoIsRefused` (`exp = time() - 7` : refusé pour toujours, donc la tolérance est sous 7 s) et `testAnIssuedAtNineSecondsAheadIsRefused` (`iat = time() + 9` : refusé tant que moins de 4 s ont passé) ; les deux cas à 30 s retirés (couverts par les bornes). Docblock : les marges et pourquoi, et que `testTheConfiguredSkewIsFiveSeconds` épingle la valeur exacte. **Vu rouge** : avec `clock_skew: 10` (local, non commité), `testATokenExpiredSevenSecondsAgoIsRefused` et `testAnIssuedAtNineSecondsAheadIsRefused` rougissent ; `rm -rf var/cache/test` avant et après.
- [ ] **Step 2 : verts** — `docker compose exec -u dev backend php bin/phpunit tests/Security/Authentication/Infrastructure/Jwt/JwtClockSkewTest.php` dix fois de suite ; `make back-test`, `make back-quality` (`-u dev`).
- [ ] **Step 3 : commit** — `test(security): la tolérance d'horloge épinglée avec quatre secondes de marge (#748)`.

---

### Task R45 : Documentation (St4-1, St4-2, St4-3, C11, C7)

**Files :**
- Modify : `frontend/src/application/auth/sessionGeneration.ts` (en-tête, `advanceGeneration`), `frontend/src/application/auth/sessionEvents.ts` (`notify`), `.claude/rules/frontend-session-retour.md`, `frontend/src/infrastructure/portfolio/content/{fr,en}.ts`, `frontend/tests/infrastructure/portfolio/cookiePolicy.spec.ts`, `docs/rgpd/registre-traitements.md`

**Texte exact** de la politique de cookies (C11, la phrase `BEARER`, FR puis EN) :
- FR : « BEARER — jeton d’authentification (JWT), httpOnly, non lisible en JavaScript. Cookie de session : supprimé à la fermeture du navigateur (sauf si celui-ci restaure la session), à la déconnexion, ou dès qu’une page qui demande une connexion refuse le jeton qu’il porte (expiré — 1 heure pour un compte, 15 minutes pour l’accès instantané — ou invalidé). »
- EN : « BEARER — authentication token (JWT), httpOnly, not readable from JavaScript. Session cookie: deleted when the browser is closed (unless it restores the session), on logout, or as soon as a page that requires signing in refuses the token it carries (expired — 1 hour for an account, 15 minutes for instant access — or invalidated). »

- [ ] **Step 1 : test, rouge** — `cookiePolicy.spec.ts` : `it("dit l'effacement au refus par une page qui demande une connexion, expiré ou invalidé")` (FR « refuse le jeton », « invalidé » ; EN « refuses the token », « invalidated ») et n'a plus « présenté après l’expiration » / « presented after ». Expected : rouge sur les deux locales.
- [ ] **Step 2 : écrire** — le texte exact ci-dessus. `docs/rgpd/registre-traitements.md` : §3.1 et §3.3, « au premier refus du jeton une fois ses 15 minutes écoulées » / « au premier refus du jeton, une fois expiré » → « au premier refus du jeton (expiré ou invalidé) par une route qui demande une session — une route publique sert la requête comme anonyme (#748, R35) » ; §3.3 « est refusé par toute l'API » → « est refusé par toute route de l'API qui demande une session » ; la ligne « Dernière mise à jour » (2026-10-29) dit aussi ces deux précisions.
- [ ] **Step 3 : docblocks** — `sessionGeneration.ts` : en-tête et `advanceGeneration` disent les trois sources (fin acceptée, retour, session décidée dans l'onglet : `login`, `logout`, `markBaseAccessGranted`, `markBaseAccessExpired` quand il change le palier). `sessionEvents.ts` : `notify` reçoit le nom du signal (`notify(callbacks, label)`) et le message dit « Un abonné à <label> a levé une erreur. » (« au retour de la session », « au changement de compte »).
- [ ] **Step 4 : règle** — `frontend-session-retour.md`, « The session generation », deuxième puce : `canRestore` demande **un compte et un jeton d'écriture** ; la génération est jugée par l'appelant (`isCurrentGeneration`), sauf `login()` ; la liste des passages gagne `rereadAfterRefusal()` (par `applyReadSession`). **C7**, une puce « Accepted limit » à la fin de « The state, the re-check on return and the replay » : un changement de compte dans un autre onglet **sans** session finie dans celui-ci n'est vu par rien — la revérification au retour ne joue qu'en session finie ; l'en-tête et la page restent ceux du premier compte jusqu'à un refus (relecture, R26), une navigation protégée ou un rechargement, et les écritures partent avec le cookie du second ; antérieure à #748, elle suppose un accès au même navigateur (décision de l'auteur du 2026-10-29).
- [ ] **Step 5 : verts** — `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 6 : commit** — `docs: la génération, les signaux, la politique de cookies et la limite C7 à jour de la quatrième relecture de #748`.

---

## Corrections de la cinquième relecture (2026-10-30)

R35 à R45 sont faites (6e4e1de4 → cce0310a). Quatre relectures du diff fc99f3b4...cce0310a (`/code-review medium`
CR5-*, audit de sécurité C5-*, axe Standards St5-*, axe Spec Sp5-*) : aucun critique ni haut, aucun contournement.
Constats complets et décisions : `tasks/relectures-748/relecture-5-constats.md` (la source de vérité). Tous sont traités
ici par R46 à R58, avant les tâches 11 et 12 : corrigés, écrits comme limite, ou écartés avec leur raison (ci-dessous).

### Décisions de l'auteur (2026-10-30)

1. **C5-1 (= CR5-5) : limite acceptée, écrite** (R58). Q1 de la quatrième série inchangé (cookie gardé sur une route
   publique, audit à chaque requête) ; la rafale de lignes `session-refused-after-credentials-change` d'un onglet légitime
   est écrite dans `security-authentication-sessions.md` (bornée par la zone nginx `publicapi` et la vie de la page :
   `/api/me` à chaque chargement efface le cookie ; un attaquant n'y gagne rien).
2. **C5-2 (= CR5-6) : accepté et documenté** (R58 et la PR). Une lecture publique d'une personne connectée authentifie
   (JWT vérifié et un `SELECT` du compte) : conséquence de la décision 1 de la quatrième série.
3. **CR5-3 / CR5-4 (= Sp5-11) : un seul enregistrement par langue** (R50). Le bouton d'une langue est désactivé tant que
   sa sauvegarde est en vol ; l'état « en cours » est tenu par langue. `lastSaveOf` (R43) est **retiré** (choix n° 5).
4. Toujours valables : **tout jusqu'à zéro** (décision 2 de la quatrième série) ; C7 limite acceptée ; à dire dans la PR :
   C7, Sp4-b, C5-1, C5-2 (tâche 12).

### Vérification de chaque constat dans le code (HEAD 95d84fee)

- **CR5-1 (= C5-3a), confirmé.** `backofficeSessionExpiry.ts:45-53` : `refused()` compte toute écriture (`refusalScope`
  nul, `markBackofficeAccessRefused`, `backofficeAccessRefusal.ts:41-48`) ; `AccessRefusedNotice.vue:65` efface au
  démontage, **rien au montage**. Une écriture refusée arrivée après la sortie du backoffice laisse le compteur à 1, et
  l'alerte apparaît au prochain montage d'`AdminLayout`, sur une page sans rapport. La relecture de `/api/me` qui
  l'accompagne n'est **pas** inutile : elle remet l'en-tête au palier que le serveur reconnaît (un compte rétrogradé), où
  que soit la personne — elle est gardée (choix n° 1).
- **CR5-2 (= C5-3b), confirmé, et plus large que décrit.** `refused()` ignore `sentAt.session`. Deux chemins de
  changement de compte : le retour après une session finie (`restoreSession`, qui avance la génération) et un autre
  compte lu **hors** session finie (`applyLiveSession`, `sessionLifecycle.ts:77-83`, qui **n'avance pas** la génération) ;
  sur le second, un contrôle de génération dans `refused()` ne suffirait pas. Corrigé par les deux (R48, choix n° 2).
- **St5-1, confirmé.** `sessionLifecycle.ts:42-43` (« **La** descente de `sessionEnded` ») alors que `markBaseAccessGranted`
  la baisse aussi (R41) ; `sessionState.ts:111-114` (`applySession` : « `markSessionExpired`, … `restoreSession`, qui
  seuls jugent… ») ignore la seconde descente.
- **CR5-7 (= C5-5), confirmé, et plus large.** `router/index.ts:213-218` : `await waitForAuthCheck()` sans borne ;
  `HttpAuthRepository.me()` (l. 73-76) n'a ni délai ni `AbortSignal`. Vu en vérifiant : le garde des routes protégées
  attend de même, et un `me()` qui pend tient aussi **pour toujours** l'emplacement `singleFlight` de
  `recheckEndedSession` et de `rereadAfterRefusal` (chaque relecture suivante rend la même promesse en attente). La borne
  se pose donc sur `me()`, pas sur la seule route `login` (R49, choix n° 4).
- **CR5-8 (= St5-14), confirmé pour le docblock, faux en pratique pour l'ordre.** vue-router tient pour **doublon** une
  navigation dont la requête ne diffère que par l'ordre des paramètres (comparaison clé par clé) et ne l'exécute pas :
  le `watch` d'`AccessRefusedNotice.vue:51-54` ne voit jamais `?a=1&b=2` devenir `?b=2&a=1`. Une « revisite du même
  `fullPath` » n'est pas une navigation non plus. Reste faux : le docblock de `scope` (`backofficeAccessRefusal.ts:24`,
  « deux visites successives de la même route sont deux périmètres »). Corrigé en R54, avec un test de caractérisation
  (et la clé triée si ce test se révélait rouge). *(rectifié le 2026-10-30, sixième relecture, Sp6-3 : « faux en pratique » était faux — la caractérisation de
  R54 était rouge avant le tri de la clé : vue-router exécute une navigation aux paramètres permutés ; le tri est resté,
  R64 corrige le test et les docblocks.)*
- **CR5-9 (= St5-3), confirmé.** `raiseSessionEnded`/`lowerSessionEnded` (`sessionState.ts:98,107`) sont exportés à tout
  `src/` ; seul `sessionLifecycle.ts` les importe aujourd'hui. `sessionStateEncapsulation.spec.ts:14` dit « les trois
  écritures » et en liste quatre noms (dont `authState`, une lecture) alors que le module en exporte six.
- **CR5-10 (= C5-4), confirmé en partie.** `smoke-dead-session-cookie.sh:101` : sous `set -e`, un `curl` en échec dans
  l'affectation `response="$(…)"` sort avec **le code de curl** (6, 7, 28), hors du contrat 0/1/2. « Sans message » est
  inexact : `-sS` imprime `curl: (7) …` sur stderr ; le script n'ajoute rien qui dise ce qui n'a pas été vérifié. Même
  motif dans `smoke-login-throttling.sh:88` et `smoke-mcp.sh` (traité par R57). *(rectifié le 2026-10-30, sixième relecture, Sp6-6 : pas `smoke-mcp.sh`, qui attrapait déjà l'échec de
  `curl` et sortait en 1 en nommant l'étape — vu dans 647725d4 ; R57 n'y a changé que la phrase.)*
- **Sp5-2, confirmé.** R37 a retiré `it('un échec réseau au premier essai : aucun rejeu, le rejet remonte')` et
  `it.each([[429], [403], [500]])` ; `HttpBaseAccessRepository.spec.ts:46` et `:71` testent tous deux le 429.
- **Sp5-1 (= C5-9), confirmé.** `ApiRouteExposureTest::testARefusedSessionCookieNeverChangesTheAnonymousAnswer` ne compare
  que les statuts et, pour `PUBLIC_PATHS`, les noms des cookies posés ; l'effacement n'est vérifié que sur `/api/me`
  (`RefusedSessionCookiesAreClearedTest`, `PublicRouteIgnoresRefusedSessionTest`). **Déjà couvert** : le `cv` périmé sur
  une route publique (`SessionBeforePasswordChangeTest`, R35). Manquent : un JWT signé d'un **compte supprimé**, une
  forme `%XX` du chemin. Ces causes ne sont pas répétées route par route : le prédicat ne lit que le chemin
  (`AccessMapStaysPathOnlyTest`) et toutes passent par `LoginFailureEvent` ; une route publique et une protégée suffisent
  (R46, choix n° 7).
- **St5-2, confirmé.** `backofficeAccessRefusal.ts:20` (« avance à chaque effacement, donc à chaque navigation ») et `:15`
  (« depuis la dernière navigation ») : depuis R38, une navigation par le seul fragment n'efface plus.
- **St5-4, confirmé.** `frontend-session.md`, « Tests », dernière puce : « a refused cookie cleared by its first 401 ».
- **St5-5, confirmé, et plus large.** `k8s/README.md` §2bis (l. 637 et l. 659-660) ne cite ni
  `smoke-dead-session-cookie.sh` ni `smoke-mcp.sh`, qui lisent aussi `PREPROD_BASIC_AUTH` (`pipeline.yml:891-949`).
- **St5-6, confirmé.** La charge du JWT expiré : `ApiRouteExposureTest:236-241`, `RefusedSessionCookiesAreClearedTest:56-61`,
  `PublicRouteIgnoresRefusedSessionTest:190-196` ; `new Cookie(AuthCookieFactory::BEARER, …)` quatre fois (avec
  `JwtClockSkewTest:99`) ; `PUBLIC_ROUTE`/`PROTECTED_ROUTE` copiées dans deux tests.
- **St5-7, confirmé.** `PublicRouteIgnoresRefusedSessionTest:166` refait en une ligne `ApiRouteExposureTest::concreteUrl()`
  (sans l'UUID valide, ici sans effet : l'`AccessMap` ne passe pas par le routeur).
- **St5-8, confirmé, et plus large.** Le fichier `-K` est construit à l'identique dans **quatre** scripts
  (`audit-prod.sh:43-47`, `smoke-login-throttling.sh:57-61`, `smoke-mcp.sh:85-88`, `smoke-dead-session-cookie.sh:68-72`) ;
  l'extrait de corps dans trois ; `tools/lib/` sert déjà `security-headers.sh` et `tls-certificate.sh`.
- **St5-9, confirmé.** Le même paragraphe de trois lignes dans les huit dépôts publics (`about`, `quality`, `incidents`,
  `contributions`, `watch`, `experience`, `contact`, `account`).
- **St5-10, confirmé (jugement rouvert).** `BackofficeSendStamp.refusalScope: number | null`, `null` = écriture. La
  condition du jugement St4 (CR4-1) est remplie ; tranché au choix n° 6.
- **St5-11, confirmé, et une réutilisation manquée trouvée.** `application/shared/staleRequestGuard.ts`
  (`createStaleRequestGuard`, « seule la réponse du dernier appel démarré ») existe déjà et sert sept composables ;
  `replayId` (`AppLayout.vue:47`) et `lastSaveOf` le récrivent. Tranché au choix n° 8.
- **St5-12, confirmé.** `requiresAccess()` public, `SingleFlight.clear()` dans l'interface de production,
  `resetBackofficeAccessRefusalForTests` dans `src/`. Tranché au choix n° 9.
- **St5-13, confirmé.** `LoginPage.vue` : `'anonymous' === tier.value` est la négation d'`isAuthenticated`.
- **St5-15, confirmé.** `singleFlight.ts` ne connaît rien de la session ; `application/shared/` existe.
- **St5-16, confirmé.** `security-authentication-sessions.md:142-164` : la puce de `StaleSessionCookiesClearingListener`
  porte aussi tout R35 ; le docblock du listener (l. 14-77) raconte l'historique (`credentials: 'omit'`, préprod).
- **C5-6, confirmé.** Le smoke ne lit que `bearer_set_cookies` ; `XSRF-TOKEN` n'est vérifié ni à l'étape 2 (aucun posé)
  ni à l'étape 3 (expiré aussi, `StaleSessionCookiesClearingListener` expire les deux).
- **C5-7, confirmé, et plus large.** `printf 'user = "%s"\n'` n'échappe que `"` et `\` : un `\n` dans `AUDIT_BASIC_AUTH`
  ouvre une seconde directive dans le fichier `-K`. Quatre copies (St5-8).
- **C5-8, confirmé.** `smoke-dead-session-cookie.test.sh` note les arguments du faux `curl`, jamais le contenu du `-K`.
- **C5-10, confirmé.** `noOmittedCredentials.spec.ts:28` : `/credentials:\s*['"]omit['"]/`.
- **C5-11, confirmé.** `frontend.md` le dit (« not in dev (two) ») ; `frontend-session.md` ne dit pas que R35 n'est donc
  exercé que par PHPUnit et le smoke de préprod.
- **C5-12, confirmé.** Aucun `#[IsGranted]`, `denyAccessUnlessGranted`, `security:` d'API Platform dans `src/` ; un seul
  `isGranted()`, `NominativeCorpusReader.php:66`, sur le pare-feu `mcp` (pas `api`). Rien ne garde la prémisse.
- **C5-13, confirmé, écarté** (voir « Constats écartés »).
- **Sp5-3, confirmé.** `AppLayout.spec.ts:323-344` : le test ne rougit sous l'ancien booléen que si vue-router annule la
  première navigation avant la garde retenue.
- **Sp5-4, faux pour le code, vrai pour le plan.** `BaseAccessControllerTest:225` lit `limiter.base_access` par
  `self::getContainer()` : c'est ce que dit le docblock de `TestServiceLocator` (« Ne pas l'appeler depuis un test »).
  Le plan de R35 avait tort ; le code est juste. Écarté.
- **Sp5-5 à Sp5-8** : fichiers et tests hors des tables de R35 à R45, cohérents. Écartés.
- **Sp5-9, déjà couvert** : la tâche 11 a le point « La préprod réelle, derrière sa Basic Auth » (C1 au navigateur).
- **Sp5-10, confirmé.** `docs/adr/0003-paliers-d-acces.md:272` : « sur toute l'API authentifiée par le cookie ».

### Choix de conception tranchés

1. **CR5-1 : l'alerte efface au montage comme au démontage** (`onMounted(clearBackofficeAccessRefusal)`) *(rectifié le 2026-10-30, sixième relecture, Sp6-1 : R48 a posé
   `onMounted(discardBackofficeAccessRefusals)`, qui remet le compteur à zéro sans avancer le périmètre ; la décision 1
   de la sixième relecture le remplace pour les écritures, R64)*. Le compteur
   peut monter pendant que rien ne l'affiche ; il repart de zéro quand une alerte paraît. La relecture de `/api/me`
   reste. *Écarté* : ne compter qu'avec une alerte montée (un drapeau de module posé par l'alerte) — un état de plus,
   partagé entre trois modules, pour le même résultat visible, et il ferait perdre la relecture qui corrige l'en-tête.
2. **CR5-2 : un refus parti sous une autre génération n'est ni compté ni relu** (`isCurrentGeneration(sentAt.session)` en
   tête de `refused()`), **et un autre compte lu hors session finie avance la génération** (`applyLiveSession`, avant
   `notifyAccountSwitched()`). Ce second point amende R26 (« une session *lue* n'avance rien ») pour un seul cas : la page
   ouverte n'est plus celle du compte qui l'a envoyée, tout ce qui est parti avant est périmé — comme au retour d'une
   session finie, qui l'avance déjà. *Écarté* : effacer sur `onAccountSwitched` — le rejeu remonte déjà `AdminLayout`
   (clé du `RouterView`) et le choix n° 1 efface au montage ; un abonnement de plus ne couvrirait pas la réponse tardive.
3. **Le contrôle de génération ne touche que `refused()`** : `ended()` le fait déjà (`markSessionExpired(sentAt.session)`).
4. **CR5-7 : `me()` abandonne après `ME_TIMEOUT_MS = 15_000`** (`AbortController` et `setTimeout`, minuterie levée dans
   un `finally`). Un abandon rejette comme un échec réseau : la première vérification finit (`markFirstCheckDone` dans le
   `finally` de `checkAuth`), la route `login` et le garde s'ouvrent, l'emplacement `singleFlight` se libère.
   15 s plutôt que 10 : au-delà, une personne connectée sur un réseau très lent passe pour anonyme et une route
   protégée la mène à la connexion — le délai doit dépasser tout `/api/me` qui finirait par répondre. *Écartés* :
   `Promise.race` dans le seul `beforeEnter` de `login` (le garde et les relectures resteraient bloqués) ;
   `AbortSignal.timeout()` (une minuterie interne que les faux minuteurs de Vitest ne pilotent pas).
5. **Décision 3 : `save(locale)` refuse un second enregistrement de la même langue tant que le premier est en vol** (aucune
   requête, rend `false`, aucune erreur posée) ; `isSaving` (calculé, la langue montrée) désactive « Enregistrer » et
   « Proposer la traduction » de cette langue seulement. **`lastSaveOf` est retiré** : avec au plus un enregistrement en
   vol par langue, « le dernier de sa langue » est toujours le seul, la branche `lastSaveOf[locale] !== mine` ne peut
   plus être vraie. Le garder « en défense » défendrait contre un cas que le composable interdit lui-même, et laisserait
   deux mécanismes pour une règle (St5-11). La défense est le refus dans `save()`, pas le seul bouton (une soumission
   implicite au clavier, un futur second appelant) ; un test l'épingle. L'`isSubmitting` global du formulaire, qui
   bloquait l'EN pendant un FR en vol, disparaît.
6. **St5-10 : une union discriminée, et elle reste dans `domain/`** : `type RefusalScope = { readonly kind: 'read';
   readonly scope: number } | { readonly kind: 'write' }`, `BackofficeSendStamp.refusal: RefusalScope`. Le sens de
   « écriture » ne tient plus à un `null`. La place : `infrastructure/` (qui le porte) ne peut importer `application/`
   (R28), et `application/` ne peut importer `infrastructure/` ; seul `domain/` est commun. Le jugement St4 (« une notion
   de navigation dans le domaine ») est levé par le nom : le type dit ce qu'est la requête (lecture, écriture) et porte
   un nombre opaque ; aucune route, aucun chemin.
7. **Sp5-1 : l'invariant n° 8 vérifie aussi l'effacement**, sans requête de plus : quand la réponse au cookie refusé est
   un 401 **du pare-feu `api`**, elle expire `BEARER` et `XSRF-TOKEN`. Les causes nouvelles (compte supprimé, `%XX`) vont
   dans `PublicRouteIgnoresRefusedSessionTest`, une route publique et une protégée chacune : les répéter sur chaque route
   doublerait la durée de l'invariant (déjà trois requêtes par route et méthode) sans rien couvrir de plus.
8. **St5-11 : `replayId` passe par `createStaleRequestGuard`** (R53), `lastSaveOf` disparaît (choix n° 5). *Gardés à
   part, avec leur raison* : la génération (globale, avancée par cinq sources hors de tout `begin`) ; le périmètre de refus
   (lu sans avancer, une écriture n'en relève aucun) ; `isCurrentKey` (une clé, pas un numéro). Un utilitaire commun à ces
   formes aurait autant de modes que d'appelants.
9. **St5-12, tranché point par point.** `requiresAccess()` **reste public** : c'est le point de comparaison avec
   `AccessListener::supports()`, sans effet, et le rendre privé obligerait le test à passer par la réflexion ou par un
   service de plus pour une seule méthode ; son docblock le dit (R56). `SingleFlight.clear()` **sort de l'interface** :
   la remise à zéro recrée les deux emplacements (R55). `resetBackofficeAccessRefusalForTests` **reste dans `src/`** :
   c'est la convention du module (`reset…ForTests`, jamais appelée par `src/`, `frontend-session-retour.md`) et C12 a
   mesuré que Rollup la retire du bundle (R42).
10. **St5-8 : `tools/lib/smoke-http.sh`** (R57), sourcé par les trois scripts de fumée et `audit-prod.sh` : le fichier
    `-K` (refus des retours à la ligne, échappement), l'extrait de corps, l'appel `curl` qui rend statut et corps et dit
    un échec réseau. L'extension du contrat d'échec de `curl` (CR5-10) aux deux autres scripts de fumée est un
    changement de comportement, annoncé et testé dans R57.
11. **C5-10 : la garde retire les commentaires, puis refuse toute chaîne `'omit'`** (guillemets simples, doubles ou
    gabarit) dans `src/`. Limite écrite dans son docblock : une valeur composée (`'om' + 'it'`) ou venue d'ailleurs.
12. **C5-12 : une garde de source**, `AuthorizationLivesInAccessControlTest`, plutôt qu'une limite écrite : la prémisse
    de R35 (une route publique est une route sans règle) se casserait en silence au premier `#[IsGranted]`.

**Questions à l'auteur** (le plan suit la recommandation ; une autre réponse ne change que la tâche citée) :

- **Q1 (R48)** : un refus d'écriture arrivé hors du backoffice : **effacé au prochain montage de l'alerte, la relecture de
  `/api/me` gardée** (recommandé) ou **ni compté ni relu** tant qu'aucune alerte n'est montée ?
- **Q2 (R48)** : un autre compte lu **hors** session finie (`applyLiveSession`) **avance la génération** (recommandé : tout
  ce qui est parti sous l'autre compte devient périmé, comme au retour) ou non (R26 inchangé, CR5-2 alors corrigé sur le
  seul chemin du retour) ?
- **Q3 (R49)** : la borne de `/api/me` : **15 s sur `me()`** (recommandé : la route `login`, le garde et les relectures se
  libèrent ensemble) ou une borne sur le seul `beforeEnter` de `login` ? Une autre valeur que 15 s ne change que la
  constante.

**Réponses de l'auteur (2026-10-30)** : Q1, Q2, Q3 **selon la recommandation** (effacé au montage, relecture gardée *(rectifié le 2026-10-30, sixième relecture : amendé pour les écritures par sa décision 1 — un refus
d'écriture compté sans alerte s'affiche au montage avec son propre texte, R64)* ;
`applyLiveSession` avance la génération, R26 amendé pour ce cas ; 15 s sur `me()`). Et les deux défauts antérieurs relevés
pendant la vérification **rejoignent la série** : (a) `checkAuth()` n'attrape pas un rejet de `me()` → R49, étape 3b ;
(b) l'avis « proposition de traduction » des réglages « À propos » effacé par le succès d'un enregistrement d'une autre
langue → R50, étape 2b.

### Review Focus (cinquième relecture)

1. **Une écriture refusée revenue après la sortie du backoffice, puis un retour plus tard** : aucune alerte « accès
   refusé » au retour ; l'en-tête a suivi le palier relu. Test : R48.
2. **Le 403 tardif d'une écriture du compte A après un changement de compte**, par les deux chemins (retour après une
   session finie, autre compte révélé par un refus) : rien d'affiché, rien de relu. Test : R48.
3. **Un `/api/me` qui ne répond pas, onglet neuf sur `login?expired=1`** : la page s'ouvre après `ME_TIMEOUT_MS`, sans
   rien annoncer avant. Test : R49.
4. **Réglages « À propos » : FR en vol, passage en EN, puis retour en FR** : l'EN s'enregistre ; le FR reste désactivé
   jusqu'à sa réponse ; un second « Enregistrer » FR n'envoie rien. Test : R50.
5. **`AUDIT_BASIC_AUTH` avec un retour à la ligne** : sortie 2 avant tout appel `curl`, valeur jamais citée. Test : R47.

### Contraintes propres aux tâches R46 à R58

- Les **Global Constraints** et toutes les contraintes des séries précédentes valent. Exécution : **un agent par tâche
  (sonnet, effort high), successifs dans l'ordre R46 → R58**, qui lit d'abord `tasks/relectures-748/brief-commun-agents.md`.
  `superpowers:test-driven-development` : test écrit d'abord, **vu rouge pour la bonne raison** ; un test de couverture
  vert d'emblée est vu rouge par la mutation que sa tâche nomme ; refactoring pur : la suite existante comme filet,
  nombre de tests noté avant et après. **`superpowers:verification-before-completion` invoquée réellement** avant le
  rendu. Un commit par tâche, fichiers nommés (`git add <fichiers>`, `git mv`/`git rm` pour un déplacement ou une
  suppression), message en français terminé par les lignes d'attribution que donne le fil principal.
- **Chaque code de sortie se lit sans pipe** : `make front-test; echo "exit=$?"` (de même `front-lint`, `front-build`,
  `back-test`, `back-quality`, chaque test hors ligne et `tools/check-claude-rules.sh`). Un `| tail` masque l'échec.
- Backend (R46, R56) : lire avec `Read` un fichier de `backend/tests/Security/` avant d'écrire ; test ciblé
  `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis `make back-test` et `make back-quality` (toujours
  `-u dev`) ; `rm -rf var/cache/test` après un changement de service ; JWT de test signé par l'encodeur du conteneur,
  valeur invalide tirée au hasard (`bin2hex(random_bytes(16))`), jamais de secret littéral.
- Outillage (R47, R57) : lire avec `Read` les scripts touchés avant d'écrire ; `bash tools/tests/<script>.test.sh` hors
  ligne pour chaque script touché ; shellcheck dans l'image épinglée du job `tools-tests` (celle d'actionlint, qui
  l'embarque) : `docker run --rm --entrypoint shellcheck -v "$PWD":/repo:ro -w /repo
  rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -x --severity=warning
  <fichiers>` (si l'image n'expose pas `shellcheck`, le noter et lancer `shellcheck` de l'hôte, en le disant) ;
  `tools/check-workflow-timeouts.sh` si `pipeline.yml` change.
- Frontend : test ciblé `docker compose exec -T frontend npm test -- <chemins>`, puis `make front-test`, `make front-lint`,
  `make front-build` ; `docker compose restart frontend` après un fichier créé, déplacé ou un remplacement de masse.
- Règles : `tools/check-claude-rules.sh` après chaque modification (50 000 octets par règle ; `security-user.md` n'est
  pas touchée) ; **remplacer** des phrases plutôt qu'en ajouter. Un fichier créé ou déplacé qui entre dans les `paths:`
  d'une règle y est ajouté dans le même commit, et **indexé (`git add`) avant** `check-claude-rules` (qui ne voit que les
  fichiers suivis).
- Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets (les exclure de tout
  `grep -r`) ; ne jamais nommer un compte réel. Aucun `git checkout`, `switch`, `stash`, `reset`. Pas de push, pas de PR.

### Fichiers (tâches R46 à R58)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `backend/tests/Security/ApiRouteExposureTest.php` | invariant n° 8 : l'effacement d'un 401 ; `ConcreteApiUrl` | R46, R56 |
| `backend/tests/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionTest.php` | compte supprimé, `%XX` ; aides partagées | R46, R56 |
| `backend/tests/Security/AuthorizationLivesInAccessControlTest.php` (nouveau) | garde : toute autorisation de `/api` dans `access_control` | R46 |
| `backend/tests/Support/{RefusedBearerCookie,ConcreteApiUrl}.php` (nouveaux) | le cookie refusé des tests, l'URL concrète d'une route | R56 |
| `backend/tests/Security/Authentication/Infrastructure/Jwt/{RefusedSessionCookiesAreClearedTest,JwtClockSkewTest}.php` | aides partagées | R56 |
| `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php` | docblock réduit | R56 |
| `tools/smoke-dead-session-cookie.sh`, `tools/tests/smoke-dead-session-cookie.test.sh` | échec de `curl`, `XSRF-TOKEN`, contenu du `-K` | R47, R57 |
| `tools/{smoke-login-throttling,smoke-mcp,audit-prod}.sh`, `tools/tests/{smoke-login-throttling,smoke-mcp}.test.sh` | retours à la ligne refusés ; bibliothèque | R47, R57 |
| `tools/lib/smoke-http.sh` (nouveau) | `-K`, extrait, appel `curl` | R57 |
| `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue`, `frontend/src/application/auth/{sessionLifecycle,useAuth}.ts` | refus et génération | R48 |
| `frontend/src/infrastructure/auth/HttpAuthRepository.ts`, `frontend/src/presentation/pages/LoginPage.vue` | borne de `/api/me` ; `isAuthenticated` | R49 |
| `frontend/src/application/admin/about/useAdminAboutSettings.ts`, `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue` | un enregistrement par langue | R50 |
| les huit dépôts publics, `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/infrastructure/baseAccess/HttpBaseAccessRepository.spec.ts` | docblock, garde, cas d'échec | R51 |
| `frontend/src/application/auth/{sessionState,sessionLifecycle}.ts`, `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts` | docblocks, garde d'imports | R52 |
| `frontend/src/presentation/layout/AppLayout.vue`, `frontend/tests/presentation/layout/AppLayout.spec.ts` | rejeu | R53 |
| `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts`, `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts`, `backofficeSessionExpiry.ts` | union `RefusalScope`, docblocks | R54 |
| `frontend/src/application/shared/singleFlight.ts` (déplacé), `frontend/tests/application/shared/singleFlight.spec.ts` (déplacé) | utilitaire générique | R55 |
| `.claude/rules/*`, `k8s/README.md`, `docs/adr/0003-paliers-d-acces.md` | documentation | R46 à R58 |

---

### Task R46 : Backend — l'invariant n° 8 vérifie l'effacement ; un compte supprimé et `%XX` ; toute autorisation dans `access_control` (Sp5-1/C5-9, C5-12)

**Files :**
- Modify : `backend/tests/Security/ApiRouteExposureTest.php`, `backend/tests/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionTest.php`
- Create : `backend/tests/Security/AuthorizationLivesInAccessControlTest.php`
- Modify (règle) : `.claude/rules/security-authentication-sessions.md` (la phrase « Invariant n° 8… » de la puce de `StaleSessionCookiesClearingListener`)

**Interfaces :**
- `answerWithBearer()` rend aussi `'expired' => list<string>` : les noms des cookies posés dont `getExpiresTime()` est
  strictement positif et passé.
- Le pare-feu d'une requête : `self::getContainer()->get('security.firewall.map')->getFirewallConfig(Request::create(<url>, <méthode>))?->getName()`.
- `AuthorizationLivesInAccessControlTest` : parcourt `src/` (`PhpSources::files()`, `significantTokens()`) ; refuse
  l'attribut `IsGranted`, l'appel `denyAccessUnlessGranted`, les arguments nommés `security:`, `securityPostDenormalize:`,
  `securityPostValidation:` ; `->isGranted(` seulement dans `ISGRANTED_OUTSIDE_API = [NominativeCorpusReader::class =>
  '<raison : pare-feu mcp, pas api ; le connecteur MCP juge le palier de la connexion>']`, et l'entrée doit rester
  nécessaire. Docblock : pourquoi (C5-12 : `PublicRouteIgnoresRefusedSessionListener` tient « route publique = route sans
  règle d'`access_control` » ; un `#[IsGranted]` sur une route sans règle refuserait l'anonyme mais n'effacerait pas un
  cookie refusé), ce qu'il ne voit pas (un appel dynamique, le vendor).

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`, nombre de tests noté.
- [ ] **Step 2 : tests**
  - `ApiRouteExposureTest`, invariant n° 8 : pour les libellés `invalide` et `expiré`, quand le statut est 401 **et** que le
    pare-feu de la requête est `api`, `expired` contient `BEARER` et `XSRF-TOKEN` (message : la méthode, le chemin, les
    noms expirés). Docblock de l'invariant : une phrase de plus (l'effacement, et pourquoi les autres pare-feux sont
    exclus : `mcp` et `oauth` ne lisent pas le cookie).
  - `PublicRouteIgnoresRefusedSessionTest` : `testATokenOfADeletedAccountIsServedOnAPublicReadAndClearedByTheFirstProtectedRoute`
    (compte de test connecté, ligne supprimée en SQL — `DELETE FROM cpg_user WHERE id = :id` —, puis `PUBLIC_ROUTE` : servi,
    cookie gardé ; `PROTECTED_ROUTE` : 401, les deux cookies expirés aux attributs de la fabrique) ;
    `testAPercentEncodedPathIsJudgedLikeItsCanonicalForm` (`BEARER` aléatoire ; `GET /%61pi/contributions/fr` : 200, aucun
    `Set-Cookie` ; `GET /%61pi/me` : 401, les deux cookies expirés).
  - `AuthorizationLivesInAccessControlTest` : `testNoAuthorizationIsDeclaredOutsideAccessControl` ;
    `testEveryAllowListEntryIsStillNeeded`.
  Run : `docker compose exec -u dev backend php bin/phpunit tests/Security/ApiRouteExposureTest.php tests/Security/AuthorizationLivesInAccessControlTest.php tests/Security/Authentication/Infrastructure/Security`.
  Expected : vert d'emblée (couverture d'un comportement juste). **Rouge vu par mutation, à noter dans le message** :
  l'écoute de `JWT_INVALID` retirée de `StaleSessionCookiesClearingListener` → l'invariant n° 8 et le test du compte
  supprimé rougissent ; un `security: "is_granted('ROLE_USER')"` ajouté à une ressource API Platform → la garde rougit ;
  `rm -rf var/cache/test` après chaque mutation, mutations retirées (`git diff` vide sur `src/`).
- [ ] **Step 3 : règle** — `security-authentication-sessions.md` : la phrase « **Invariant n° 8 of `ApiRouteExposureTest`**: … »
  remplacée par : « **Invariant n° 8 of `ApiRouteExposureTest`**: on every route and method, a refused `BEARER` (invalid,
  expired) gets the anonymous status, a 401 of the `api` firewall expires both cookies, and a `PUBLIC_PATHS` route sets no
  cookie the anonymous call does not; the premise (every authorization of `/api` is an `access_control` rule) is held by
  `AuthorizationLivesInAccessControlTest` (C5-12). »
- [ ] **Step 4 : verts** — `make back-test; echo "exit=$?"` (filet + 4), `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `test(security): l'invariant n° 8 vérifie l'effacement, un compte supprimé et %XX ; toute autorisation reste dans access_control (#748)`.

---

### Task R47 : Le smoke du cookie mort — échec de `curl`, `XSRF-TOKEN`, contenu du `-K` ; les retours à la ligne refusés partout (CR5-10, C5-6, C5-7, C5-8)

**Files :**
- Modify : `tools/smoke-dead-session-cookie.sh`, `tools/tests/smoke-dead-session-cookie.test.sh`
- Modify : `tools/smoke-login-throttling.sh`, `tools/tests/smoke-login-throttling.test.sh`, `tools/smoke-mcp.sh`, `tools/tests/smoke-mcp.test.sh`, `tools/audit-prod.sh`

**Interfaces :**
- Dans les quatre scripts, avant d'écrire le fichier `-K` : `AUDIT_BASIC_AUTH` qui contient `\n` ou `\r` → message
  « AUDIT_BASIC_AUTH contient un retour à la ligne : refusé, il ouvrirait une directive dans le fichier de configuration
  curl » sur stderr (la valeur n'est jamais citée), **sortie 2**, aucun fichier écrit, aucun appel `curl`. (`audit-prod.sh`
  sort déjà en 2 sur une bibliothèque introuvable : même classe d'erreur, d'environnement.)
- `smoke-dead-session-cookie.sh`, `fetch()` : `response="$(…)" || { rc=$?; echo "<méthode> <chemin> : curl a échoué (code $rc : réseau, DNS ou délai) — rien de vérifié" >&2; exit 1; }`.
- `smoke-dead-session-cookie.sh` : `set_cookies_named <nom>` (remplace `bearer_set_cookies`, nom insensible à la casse) ;
  étape 2 : aucun `Set-Cookie` de `BEARER` **ni** de `XSRF-TOKEN` ; étape 3 : un `Set-Cookie` expirant pour **chacun** des
  deux (le message nomme celui qui manque). En-tête du script : les trois vérifications réécrites en conséquence.
- Faux `curl` du test : une ligne de réponse `curl-exit:<n>|…` le fait écrire `curl: (<n>) faux échec` sur stderr et sortir
  en `<n>` ; quand `-K <fichier>` est passé, il copie le fichier dans `$FAKE_KCONFIG` et son mode (`stat -c %a`) dans
  `$FAKE_KMODE`.

- [ ] **Step 1 : tests hors ligne, rouges**
  - `smoke-dead-session-cookie.test.sh` : « curl en échec (code 7) à l'étape 1 → sortie 1, message `curl a échoué (code 7)` » ;
    « étape 2 avec un `Set-Cookie: XSRF-TOKEN=…` → 1, message `XSRF-TOKEN` » ; « étape 3 avec seul `BEARER` expiré → 1,
    message `XSRF-TOKEN` » ; la réponse nominale de l'étape 3 porte désormais les deux cookies expirés ;
    « `AUDIT_BASIC_AUTH='u:p"a\b'` : le fichier `-K` vaut exactement `user = "u:p\"a\\b"` et son mode est `600` » ;
    « `AUDIT_BASIC_AUTH=$'u:p\nurl = "x"'` → sortie 2, aucun appel du faux `curl`, la valeur absente de la sortie ».
  - `smoke-login-throttling.test.sh` et `smoke-mcp.test.sh` : le même cas du retour à la ligne (sortie 2, aucun appel).
  Run : `bash tools/tests/smoke-dead-session-cookie.test.sh; echo "exit=$?"` (et les deux autres). Expected : code 7 au lieu
  de 1 ; sortie 0 malgré `XSRF-TOKEN` ; sortie 0 ou un appel `curl` malgré le retour à la ligne.
- [ ] **Step 2 : implémenter** — selon les interfaces. `audit-prod.sh` n'a pas de test hors ligne : mesure à la main,
  `AUDIT_BASIC_AUTH=$'u:p\nx' tools/audit-prod.sh https://example.invalid; echo "exit=$?"` → 2 sans requête (noter la sortie).
- [ ] **Step 3 : verts** — les trois tests hors ligne à 0, shellcheck (image épinglée) sur les sept fichiers,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 4 : commit** — `fix(tools): le smoke du cookie mort dit un échec réseau et vérifie XSRF-TOKEN ; un retour à la ligne dans la Basic Auth est refusé (#748)`.

---

### Task R48 : Un refus du backoffice ne survit ni au démontage du layout ni à un changement de compte (CR5-1/C5-3a, CR5-2/C5-3b)

**Files :**
- Modify : `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue`, `frontend/src/application/auth/sessionLifecycle.ts` (`applyLiveSession`), `frontend/src/application/auth/useAuth.ts` (réexport)
- Test : `frontend/tests/presentation/router/backofficeSessionExpiry.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`, `frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts`, `frontend/tests/application/auth/useAuth.spec.ts`
- Modify (règles) : `.claude/rules/frontend-session-refus.md` (« A real refusal », puce du backoffice ; « Tests »), `.claude/rules/frontend-session-retour.md` (« The session generation », premier paragraphe ; « Only the same account… », la phrase « Outside an ended session… »)

**Interfaces :**
- `useAuth.ts` réexporte `isCurrentGeneration` (la façade, comme `sessionStamp`).
- `createBackofficeSessionReaction().refused(sentAt)` : `if (!isCurrentGeneration(sentAt.session)) return` **avant**
  `markBackofficeAccessRefused` (ni compté ni relu) ; commentaire : la réponse est celle d'une session qui n'est plus la
  courante (CR5-2).
- `AccessRefusedNotice.vue` : `onMounted(clearBackofficeAccessRefusal)` *(rectifié le 2026-10-30, sixième relecture, Sp6-1 : posé en
  `onMounted(discardBackofficeAccessRefusals)`, sans avancer le périmètre ; voir R64)* ; docblock, la puce « Effacée… » : « et au montage :
  un refus d'écriture revenu pendant qu'aucune alerte n'était montée (la personne avait quitté le backoffice) ne s'affiche
  pas plus tard sur une page sans rapport (CR5-1) ; la relecture du palier a eu lieu ».
- `applyLiveSession(session)` : dans la branche « autre identifiant », `advanceGeneration()` puis `notifyAccountSwitched()` ;
  docblock : un changement de compte périme tout ce qui est parti avant, comme le retour (CR5-2, amende R26 pour ce seul cas).

- [ ] **Step 1 : tests, rouges**
  - `AccessRefusedNotice.spec.ts` (Review Focus 1) : `it("un refus d'écriture arrivé après le démontage ne s'affiche pas au montage suivant")`
    (monter, démonter, `markBackofficeAccessRefused(null)`, remonter → aucune `[data-testid="admin-access-refused"]`).
  - `backofficeSessionExpiry.spec.ts` (Review Focus 2) : `it("le refus d'une écriture partie sous une autre génération n'est ni compté ni relu")`
    (`stampAtSend('write')`, `advanceGeneration()` importé de `sessionGeneration`, `refused(stamp)` → `backofficeAccessRefused.value === 0`,
    `rereadAfterRefusal` jamais appelé) ; `it("le refus d'une lecture partie sous une autre génération n'est ni compté ni relu")`.
  - `useAuth.spec.ts` : `it("un autre compte lu hors session finie avance la génération avant le signal")` (compte A appliqué
    par `checkAuth()`, `sentAt = sessionStamp()`, `rereadAfterRefusal()` rend B → `isCurrentGeneration(sentAt)` faux, et
    l'abonné d'`onAccountSwitched` lit déjà la génération nouvelle) ; `it("le même identifiant lu hors session finie n'avance rien")` (vert).
  - `AdminLayout.sessionEnded.spec.ts` : `it("après un changement de compte, le 403 tardif d'un enregistrement du premier compte n'affiche rien")`.
  Expected : alerte affichée au remontage ; compteur à 1 et relecture appelée ; génération inchangée.
- [ ] **Step 2 : implémenter** — selon les interfaces.
- [ ] **Step 3 : règles** — `frontend-session-refus.md`, puce du backoffice : après « a write always does », une phrase
  **remplacée** : « … for a read that left under another scope; a refusal sent under another session generation counts
  and re-reads nothing (R48, CR5-2); the alert also clears on mount, so a write refused while no alert was mounted never
  shows later on an unrelated page (CR5-1) » ; « Tests » : les quatre nouveaux. `frontend-session-retour.md` : la liste
  « advanced at each accepted end, at each return and by every session the tab itself decides » gagne « and when a read
  outside an ended session finds another account (`applyLiveSession`, R48) » ; la phrase « Outside an ended session … only
  a different `username` … does the same » précise « (the generation advances first) ».
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `fix(frontend): un refus du backoffice ne survit ni au démontage du layout ni à un changement de compte (#748)`.

---

### Task R49 : `/api/me` abandonne après quinze secondes ; `LoginPage` lit `isAuthenticated` (CR5-7/C5-5, St5-13)

**Files :**
- Modify : `frontend/src/infrastructure/auth/HttpAuthRepository.ts`, `frontend/src/presentation/pages/LoginPage.vue`
- Test : `frontend/tests/infrastructure/auth/HttpAuthRepository.spec.ts`, `frontend/tests/presentation/router/adminGuard.spec.ts`, `frontend/tests/application/auth/useAuth.spec.ts`
- Modify (règles) : `.claude/rules/frontend-session-retour.md` (« The re-check on return », première sous-puce), `.claude/rules/frontend-session-bandeau.md` (« Both flags open the login… »)

**Interfaces :**
- `export const ME_TIMEOUT_MS = 15_000` (`HttpAuthRepository.ts`) ; `me()` : `const controller = new AbortController()`,
  `const timer = setTimeout(() => controller.abort(), ME_TIMEOUT_MS)`, `fetch(url, { method: 'GET', credentials: 'include',
  signal: controller.signal })` et la lecture du corps dans un `try`, `clearTimeout(timer)` dans le `finally`. Docblock :
  pourquoi (CR5-7 : la route `login`, le garde et les deux relectures attendent `/api/me` ; un `me()` qui pend tenait aussi
  l'emplacement `singleFlight` pour toujours), pourquoi 15 s (choix n° 4), pourquoi pas `AbortSignal.timeout()`.
- `LoginPage.vue` : `const { login, user, isAuthenticated } = useAuth()` ; `hasSessionEnded = !isAuthenticated.value && isSessionEnded(route)`.

- [ ] **Step 1 : tests, rouges**
  - `HttpAuthRepository.spec.ts` : `it("me() abandonne après ME_TIMEOUT_MS : rejet AbortError")` (faux minuteurs ; `fetch`
    simulé qui ne résout jamais et rejette une `DOMException('…', 'AbortError')` quand son `signal` est abandonné ;
    `vi.advanceTimersByTime(ME_TIMEOUT_MS)`) ; `it("une réponse avant le délai lève la minuterie")` (`vi.getTimerCount() === 0`) ;
    l'assertion `toHaveBeenCalledWith` de `me()` gagne `signal: expect.any(AbortSignal)` (changement exprès).
  - `adminGuard.spec.ts` (Review Focus 3) : `it("onglet neuf, /api/me qui ne répond pas : la connexion s'ouvre après le délai, sans annonce avant")`.
  - `useAuth.spec.ts` : `it("un me() abandonné libère la relecture : la suivante rappelle me()")`.
  Expected : la promesse reste en attente ; `signal` absent ; la page jamais rendue.
- [ ] **Step 2 : implémenter** — selon les interfaces ; `vi.useRealTimers()` en `afterEach`.
- [ ] **Step 3 : St5-13** — `LoginPage` selon les interfaces ; `LoginPage.spec.ts` vert sans changement (refactoring).
- [ ] **Step 3b : `checkAuth()` attrape un rejet de `me()`** (défaut antérieur, décision de l'auteur du 2026-10-30) — un rejet
  (réseau, ou abandon après `ME_TIMEOUT_MS`) termine la première vérification comme un anonyme : `isChecking` retombe, l'état
  reste anonyme, `waitForAuthCheck()` se résout, **aucun rejet non géré** (ni dans `checkAuth`, ni dans `main.ts`). Test
  rouge d'abord dans `useAuth.spec.ts` : `it("checkAuth : un me() rejeté termine la vérification en anonyme, sans rejet non géré")`
  (`me` simulé rejeté ; la promesse de `checkAuth()` se résout ; `waitForAuthCheck()` se résout ; état anonyme). Vérifier
  l'appelant dans `main.ts` et le dire dans le rendu. Docblock de `checkAuth` : pourquoi.
- [ ] **Step 4 : règles** — `frontend-session-retour.md`, première sous-puce de « The re-check on return » : « a rejection of
  `/api/me` (network) is swallowed » devient « a rejection of `/api/me` (network, or `me()` giving up after
  `ME_TIMEOUT_MS`, 15 s, R49) is swallowed », et une demi-phrase : la borne vaut pour la première vérification (le garde et
  la route `login` s'ouvrent). `frontend-session-bandeau.md` : « first awaits `waitForAuthCheck()` » gagne « (bounded:
  `me()` gives up after 15 s, R49) ».
- [ ] **Step 5 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(frontend): /api/me abandonne après quinze secondes, la connexion et le garde n'attendent plus sans fin (#748)`.

---

### Task R50 : Réglages « À propos » — un seul enregistrement en vol par langue (décision 3 : CR5-3, CR5-4, Sp5-11)

**Files :**
- Modify : `frontend/src/application/admin/about/useAdminAboutSettings.ts`, `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue`
- Test : `frontend/tests/application/admin/about/useAdminAboutSettings.spec.ts`, `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (la phrase « **Only the last save of a locale sets its verdict and its value** … »)

**Interfaces :**
- `UseAdminAboutSettingsResult` gagne `isSaving: ComputedRef<boolean>` (un enregistrement de la langue montrée est en vol,
  lue par `isCurrentKey`).
- `const saving = ref<Partial<Record<Locale, true>>>({})` ; `save(locale, input)` : si `saving.value[locale]`, rend `false`
  sans requête ni effacement d'erreur ; sinon pose, puis retire dans un `finally`. `lastSaveOf` et ses deux conditions
  **retirés** : `write(locale, saved)` et `failures.value[locale] = visible` sans condition (le reste inchangé : effacement
  de l'erreur de la langue au départ, `write` sous la clé courante). Commentaire : choix n° 5.
- `AdminAboutSettingsForm.vue` : `isSubmitting` retiré ; « Enregistrer » `:disabled="isSaving || isLocked"`,
  `TranslateEntryButton` `:disabled="!hasProseToTranslate || isSaving || isLocked"` ; `handleSubmit` n'a plus de drapeau.

- [ ] **Step 1 : tests, rouges**
  - `useAdminAboutSettings.spec.ts` : le `describe` R43 (quatre tests) remplacé par `describe('un seul enregistrement en vol par langue (#748, R50, décision 3)')` :
    `it("un second enregistrement FR pendant le premier : aucune requête, rend false, l'erreur FR intacte")` ;
    `it("un enregistrement EN part pendant un FR en vol")` ; `it("isSaving suit la langue montrée : vrai en FR pendant le FR, faux en EN")` ;
    `it("après la réponse du FR, réussie ou en échec, un nouvel enregistrement FR part")`. (Retrait exprès : le chevauchement
    que R43 arbitrait n'existe plus.)
  - `AdminAboutPage.spec.ts` (Review Focus 4) : `it("FR en vol, passage en EN : « Enregistrer » actif ; retour en FR : désactivé jusqu'à la réponse")`.
  Expected : deux appels à `update` ; « Enregistrer » EN désactivé (l'`isSubmitting` global).
- [ ] **Step 2 : implémenter** — selon les interfaces ; les tests R11, R20, R31 restent verts sans changement.
- [ ] **Step 2b : l'avis « proposition de traduction » ne s'efface que pour sa langue** (défaut antérieur, décision de
  l'auteur du 2026-10-30) — aujourd'hui le succès d'un enregistrement de **n'importe quelle** langue remet
  `proposalSourceLocale` à `null` (`AdminAboutSettingsForm.vue`, `handleSubmit`). Établir dans le code à quelle langue
  l'avis se rapporte (la langue montrée qui a reçu la proposition) et ne l'effacer qu'au succès de l'enregistrement de
  **cette** langue ; un échec le garde (F11). Test rouge d'abord dans `AdminAboutPage.spec.ts` :
  `it("une proposition en EN, enregistrement réussi du FR : l'avis EN reste")`, puis le témoin
  `it("enregistrement réussi de l'EN : l'avis disparaît")`. Si la règle `frontend-backoffice.md` décrit l'avis, la phrase
  est remplacée dans le même commit.
- [ ] **Step 3 : règle** — `frontend-backoffice.md` : la phrase R43 **remplacée** par : « **One save per locale in flight**
  (R50, the author's decision of 2026-10-30, CR5-3/CR5-4): `save(locale)` refuses a second save of a locale while its
  first is in flight (no request, `false`), and the form disables « Enregistrer » for that locale only (`isSaving`): an
  English save is not held by a French one. With one response per locale in flight, R43's `lastSaveOf` had nothing left
  to arbitrate and is gone. »
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"` (− 4 + 5), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `fix(frontend): réglages « À propos », un seul enregistrement en vol par langue (#748)`.

---

### Task R51 : Les dépôts publics renvoient à la règle ; la garde voit tout `'omit'` ; « Accès instantané » retrouve ses cas d'échec (St5-9, C5-10, Sp5-2)

**Files :**
- Modify : `frontend/src/infrastructure/{about/HttpAboutContentRepository,quality/HttpQualityContentRepository,incidents/HttpIncidentRepository,contributions/HttpContributionRepository,watch/HttpWatchRepository,experience/HttpExperienceTechnologyRepository,contact/HttpContactRepository,account/HttpAccountRepository}.ts` (docblock seul)
- Modify : `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/infrastructure/baseAccess/HttpBaseAccessRepository.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session.md` (« Tests », la puce `noOmittedCredentials.spec.ts`)

**Interfaces :**
- Les huit docblocks : le paragraphe de trois lignes (« Aucun `credentials` … Jamais `'omit'` … ») **remplacé** par une
  phrase : « Aucun `credentials` : voir `.claude/rules/frontend.md`, « API-backed content » (#748, R37). »
- `noOmittedCredentials.spec.ts` : `function offendsWithOmit(source: string): boolean` (exportée dans le spec) — retire
  `/\*…*/` et `//…` (pas `://`), puis cherche `/(['"`])omit\1/`. Le test parcourt `src/**/*.{ts,vue}` avec elle. Docblock :
  la limite (une valeur composée, venue d'une variable d'ailleurs, un fichier hors de `src/`).
- `HttpBaseAccessRepository.spec.ts` : `it.each([[401], [403], [500]])("un %i est un échec unknown, un seul appel")`
  remplace les deux tests `unknown` ; un seul test 429 (avec le nombre d'appels) ;
  `it("un rejet réseau remonte tel quel, un seul appel")` (`rejects.toBe(failure)`).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : tests, rouges** — `noOmittedCredentials.spec.ts` : `it("le détecteur voit une valeur typée hors de l'objet d'options")`
  (`offendsWithOmit("const mode: RequestCredentials = 'omit'")` vrai ; `` "credentials: `omit`" `` vrai ;
  `"// Jamais 'omit'"` faux ; `"/* 'omit' */"` faux ; `"fetch('https://x/omit')"` faux). Expected : `offendsWithOmit` introuvable.
  `HttpBaseAccessRepository.spec.ts` : verts d'emblée (couverture rendue) ; **rouge vu par mutation** : `grant()` qui
  transforme un rejet réseau en `BaseAccessError('unknown')` → le test du rejet rougit (noter, mutation retirée).
- [ ] **Step 3 : implémenter** — les huit docblocks, la garde.
- [ ] **Step 4 : règle** — `frontend-session.md`, « Tests » : « `noOmittedCredentials.spec.ts` (no file of `src/` writes a
  string `'omit'` outside a comment — typed or in the options object —, which drops preprod's Basic Auth, R37, R51) ».
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"` (filet + 2 : + 1 détecteur ; `HttpBaseAccessRepository.spec.ts` passe de six à sept tests), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `test(frontend): les dépôts publics renvoient à leur règle, la garde voit tout 'omit', « Accès instantané » retrouve ses cas d'échec (#748)`.

---

### Task R52 : L'état de session — des docblocks justes, une garde des importeurs (St5-1, CR5-9/St5-3)

**Files :**
- Modify : `frontend/src/application/auth/sessionState.ts` (docblock d'`applySession`), `frontend/src/application/auth/sessionLifecycle.ts` (docblock de `restoreSession`)
- Modify : `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-retour.md` (« Where it lives », la parenthèse de `sessionState.ts`)

**Interfaces :**
- `restoreSession` : « **La** descente » → « La descente **par le retour d'un compte** (l'autre, sans signal, est
  `markBaseAccessGranted`) ». `applySession` : « … la fin est l'affaire de `markSessionExpired`, ses deux descentes celles de
  `restoreSession` et de `markBaseAccessGranted` ; seules les deux premières jugent la génération et le jeton d'écriture ».
- `sessionStateEncapsulation.spec.ts` : `const WRITERS: Record<string, readonly string[]>` = `raiseSessionEnded` et
  `lowerSessionEnded` → `['application/auth/sessionLifecycle.ts']` ; `beginCheck`, `markFirstCheckDone`, `forgetLastAccount`
  → `['application/auth/useAuth.ts']` ; `applySession` → `['application/auth/sessionLifecycle.ts', 'application/auth/useAuth.ts']`.
  Lit `src/**/*.{ts,vue}` (`import.meta.glob` `?raw`), relève les noms importés depuis un chemin qui finit par `sessionState'` ;
  chaque nom de `WRITERS` n'est importé que par sa liste ; `useAuth.ts` ne réexporte aucun d'eux. `markBaseAccessGranted` et
  `markBaseAccessExpired` restent hors de la table : API publique de la façade (`useBaseAccess`, les composables du palier
  de base). Le commentaire « les trois
  écritures » remplacé par la liste exacte des exports attendus.

- [ ] **Step 1 : test** — `it("les fonctions qui écrivent l'état ne sont importées que par leurs modules")` ;
  `it("useAuth ne réexporte aucune écriture")`. Vert d'emblée ; **rouge vu par mutation** : `raiseSessionEnded` importé
  dans `useAuth.ts` → le premier rougit (noter, mutation retirée).
- [ ] **Step 2 : docblocks** — selon les interfaces.
- [ ] **Step 3 : règle** — « Where it lives » : après « only named functions write it », « (each imported by its own
  modules only: `sessionStateEncapsulation.spec.ts`, R52) ».
- [ ] **Step 4 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `test(frontend): les écritures de l'état de session gardées par importeur ; ses deux descentes dites (#748)`.

---

### Task R53 : Le rejeu — un test qui ne dépend plus du routeur, le jeton par `createStaleRequestGuard` (Sp5-3, St5-11 ; refactoring)

**Files :**
- Modify : `frontend/src/presentation/layout/AppLayout.vue`
- Test : `frontend/tests/presentation/layout/AppLayout.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-retour.md` (la parenthèse « each takes a token, `const mine = ++replayId` »)

**Interfaces :**
- `AppLayout.vue` : `const replays = createStaleRequestGuard()` (`application/shared/staleRequestGuard.ts`) à la place de
  `let replayId` ; l'abonné : `const mine = replays.begin()` ; le `finally` : `if (replays.isCurrent(mine))`. Docblock
  d'`isReplaying` : le garde commun, et pourquoi (St5-11).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : test** — `it("deux rejeux : le premier réglé pendant que le second court ne démasque pas la page (sans dépendre du routeur)")` :
  `vi.spyOn(router, 'replace')` rend, par appel, une promesse retenue ; deux `notifyAccountSwitched()` ; la première promesse
  résolue → sonde absente ; la seconde → sonde présente. Vert d'emblée ; **rouge vu par mutation** (`isReplaying.value = false`
  sans condition dans le `finally`). Le test R39 existant reste, son commentaire précise qu'il passe par l'annulation réelle
  de vue-router (le déclencheur réel), dont il dépend.
- [ ] **Step 3 : refactorer** — selon les interfaces ; aucun autre test ne change.
- [ ] **Step 4 : règle** — « each takes a token, `const mine = ++replayId` » → « each takes a token from
  `createStaleRequestGuard` (`application/shared/`, the guard the composables use) ».
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"` (filet + 1), `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `refactor(frontend): le rejeu prend son jeton au garde commun, et son test ne dépend plus du routeur (#748)`.

---

### Task R54 : Le périmètre de refus — une union lecture/écriture, des docblocks qui disent quand il avance (St5-10, St5-2, CR5-8 ; refactoring)

**Files :**
- Modify : `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts`, `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts` (si un type y est nommé)
- Test : `frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts`, `frontend/tests/presentation/router/backofficeSessionExpiry.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`
- Modify (règles) : `.claude/rules/frontend-session-refus.md` (« The backoffice reaction », la parenthèse du timbre ; puce du backoffice de « A real refusal »)

**Interfaces :**
- `domain/admin/shared/BackofficeSessionReaction.ts` : `export type RefusalScope = { readonly kind: 'read'; readonly scope: number } | { readonly kind: 'write' }` ;
  `BackofficeSendStamp { readonly session: number; readonly refusal: RefusalScope }` ; `BackofficeSendKind = RefusalScope['kind']` ;
  `stampAtSend(kind: BackofficeSendKind)`. Docblock : choix n° 6 (pourquoi le domaine, pourquoi aucune notion de route).
- `backofficeSessionExpiry.ts` : `refusal: 'write' === kind ? { kind: 'write' } : { kind: 'read', scope: refusalScope() }`.
- `markBackofficeAccessRefused(sentIn: RefusalScope): boolean` — compte si `'write' === sentIn.kind` ou `sentIn.scope === scope`.
- Docblocks de `backofficeAccessRefusal.ts` (St5-2, CR5-8) : en tête, « depuis la dernière navigation » → « depuis le dernier
  effacement » ; `scope` : « avance à chaque effacement — un changement de chemin ou de requête (pas le seul fragment),
  une fin de session, le montage et le démontage de l'alerte (R48) » ; « deux visites successives de la même route sont deux
  périmètres » → « A, B puis A : trois périmètres ; une navigation vers l'adresse courante n'en est pas une (vue-router la
  tient pour un doublon, paramètres de requête permutés compris, et ne l'exécute pas) ». *(rectifié le 2026-10-30, sixième relecture, Sp6-3 : faux pour la
  permutation, que vue-router exécute — la clé triée de l'alerte l'ignore ; R64 récrit ce docblock.)*

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : caractérisation (CR5-8)** — `AccessRefusedNotice.spec.ts` : `it("une requête aux paramètres permutés garde l'alerte : le routeur n'y voit pas de navigation")`
  (refus compté sur `?a=1&b=2`, `router.push({ query: { b: '2', a: '1' } })` → alerte présente, `refusalScope()` inchangé).
  Attendu vert. **S'il est rouge** (le routeur navigue), la clé surveillée trie les paramètres —
  `route.path + JSON.stringify(Object.entries(route.query).sort(([a], [b]) => a.localeCompare(b)))` —, le test passe au
  vert avec ce tri, et le message de commit dit CR5-8 confirmé.
- [ ] **Step 3 : refactorer** — selon les interfaces ; les specs changent seulement là où ils construisent un timbre ou
  appellent `markBackofficeAccessRefused` (`null` → `{ kind: 'write' }`, un nombre → `{ kind: 'read', scope: n }`) : changement
  de signature, comportement identique (le dire dans le message). *(rectifié le 2026-10-30, sixième relecture, Sp6-3 : le commit 79d0b073 a aussi
  trié la clé surveillée — la caractérisation était rouge —, un changement de comportement que la première phrase de son
  message tait ; son troisième paragraphe le dit.)*
- [ ] **Step 4 : règles** — `frontend-session-refus.md` : « the send stamp `{ session, refusalScope }`, `refusalScope` null for
  a write » → « the send stamp `{ session, refusal }`, `refusal` a `RefusalScope`: `{ kind: 'read', scope }` or
  `{ kind: 'write' }` (R54) » ; « (`refusalScope()`, `null` for a write: …) » → « (`{ kind: 'write' }` for a write: …) ».
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"` (filet + 1), `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `refactor(frontend): le périmètre de refus est une union lecture/écriture ; ses docblocks disent quand il avance (#748)`.

---

### Task R55 : `singleFlight` rejoint `application/shared`, sans `clear()` de test dans son interface (St5-15, St5-12 en partie ; refactoring)

**Files :**
- Move : `frontend/src/application/auth/singleFlight.ts` → `frontend/src/application/shared/singleFlight.ts` ; `frontend/tests/application/auth/singleFlight.spec.ts` → `frontend/tests/application/shared/singleFlight.spec.ts` (`git mv`)
- Modify : `frontend/src/application/auth/useAuth.ts`, `frontend/tests/application/auth/resetAuthStateForTests.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-retour.md` (`paths:` : les deux nouveaux chemins ; « Where it lives » ; « Tests »)

**Interfaces :**
- `SingleFlight<T> { run(task: () => Promise<T>): Promise<T> }` (plus de `clear`) ; `createSingleFlight<T>()` inchangé sinon.
- `useAuth.ts` : `let endedSessionRecheck = createSingleFlight<void>()`, `let refusalReread = createSingleFlight<RefusalReread>()` ;
  `resetSessionRereadsForTests()` les recrée.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : test** — `resetAuthStateForTests.spec.ts` : `it("une relecture en vol avant la remise à zéro n'est plus partagée après")`
  (`recheckEndedSession()` en session finie avec un `me()` retenu, `resetAuthStateForTests()`, session finie de nouveau,
  `recheckEndedSession()` → `me()` appelé une seconde fois). Vert d'emblée (`clear()` le fait aujourd'hui) : il remplace,
  au niveau de la façade, le test `clear()` de `singleFlight.spec.ts`, retiré avec la méthode.
- [ ] **Step 3 : refactorer** — `git mv` des deux fichiers, imports mis à jour, `clear()` retiré ; `docker compose restart frontend`.
- [ ] **Step 4 : règle** — `paths:` gagne `frontend/src/application/shared/singleFlight.ts` et
  `frontend/tests/application/shared/singleFlight.spec.ts` (**`git add` avant `check-claude-rules`**) ; « Where it lives » :
  `singleFlight.ts` sort de la liste d'`application/auth/` et devient « `application/shared/singleFlight.ts` (generic, next to
  `staleRequestGuard.ts`; the reset for tests recreates the two slots, no `clear()` in its interface, R55) ».
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"` (filet − 1 + 1), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `refactor(frontend): singleFlight rejoint application/shared, sans clear() de test dans son interface (#748)`.

---

### Task R56 : Backend — le cookie refusé des tests et l'URL concrète écrits une fois ; le docblock du listener R35 réduit à ses raisons (St5-6, St5-7, St5-16 pour le docblock, St5-12 pour `requiresAccess`)

**Files :**
- Create : `backend/tests/Support/RefusedBearerCookie.php`, `backend/tests/Support/ConcreteApiUrl.php`
- Modify : `backend/tests/Security/ApiRouteExposureTest.php`, `backend/tests/Security/Authentication/Infrastructure/Jwt/RefusedSessionCookiesAreClearedTest.php`, `backend/tests/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionTest.php`, `backend/tests/Security/Authentication/Infrastructure/Jwt/JwtClockSkewTest.php`
- Modify : `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php` (docblocks seuls)
- Modify (règle) : `.claude/rules/security-authentication-sessions.md` (`paths:` : `backend/tests/Support/RefusedBearerCookie.php`)

**Interfaces :**
- `trait RefusedBearerCookie` : `private const string PUBLIC_ROUTE = '/api/contributions/fr'`, `private const string
  PROTECTED_ROUTE = '/api/me'` (docblocks repris de `RefusedSessionCookiesAreClearedTest`, St3-12 cité),
  `private static function expiredJwt(): string` (l'encodeur du conteneur, `exp` une heure passée),
  `private static function putTheBearer(KernelBrowser $client, string $jwt): void` (le `Cookie` exact d'aujourd'hui).
  *(rectifié le 2026-10-30, sixième relecture, Sp6-10 : les deux constantes sont `protected` — le docblock du trait dit pourquoi : PHPStan
  reprocherait une constante privée inutilisée à une classe qui n'en lit qu'une.)*
- `final class ConcreteApiUrl { public static function of(string $path): string }` — le corps et le docblock de
  `ApiRouteExposureTest::concreteUrl()` (UUID v7 valide compris), retirée de ce test.
- Docblock du listener, **au plus 35 lignes** : ce qu'il fait (une route sans règle d'`access_control` sert l'anonyme
  quel que soit le cookie), pourquoi l'`AccessMap` (aucune seconde liste ; la prémisse gardée par
  `AuthorizationLivesInAccessControlTest`, R46), pourquoi le dispatcher d'`api` et la priorité -1024, ce qui reste à une
  route protégée (le 401 et l'effacement), qu'une route publique ne touche pas au cookie et pourquoi (CR3-2), la session
  close auditée, les trois options écartées en une ligne chacune. L'historique (`credentials: 'omit'`, la Basic Auth de
  la préprod) sort : il est dans l'ADR 0003 et la règle. `requiresAccess()` : « publique parce que c'est le point de
  comparaison avec `AccessListener::supports()`, lu par le test de prédicat ; sans effet » (St5-12).

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : refactorer** — les deux aides ; les quatre tests les utilisent (`use RefusedBearerCookie;`, plus de
  constante ni de fabrique locale ; `testThePredicateAgreesWithTheAccessListenerOnEveryRoute` passe par `ConcreteApiUrl::of()`) ;
  le docblock. `make back-style-fix` si un import est ajouté.
- [ ] **Step 3 : règle** — `paths:` de `security-authentication-sessions.md` gagne `backend/tests/Support/RefusedBearerCookie.php`
  (**`git add` avant `check-claude-rules`**).
- [ ] **Step 4 : verts** — `make back-test; echo "exit=$?"` (même nombre que le filet), `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `refactor(security): le cookie refusé des tests et l'URL concrète d'une route écrits une fois ; le docblock du listener R35 réduit à ses raisons (#748)`.

---

### Task R57 : `tools/lib/smoke-http.sh` — la Basic Auth, l'extrait et l'appel `curl` des scripts de fumée écrits une fois (St5-8 ; CR5-10 étendu)

**Files :**
- Create : `tools/lib/smoke-http.sh`
- Modify : `tools/smoke-dead-session-cookie.sh`, `tools/smoke-login-throttling.sh`, `tools/smoke-mcp.sh`, `tools/audit-prod.sh`
- Test : `tools/tests/smoke-dead-session-cookie.test.sh`, `tools/tests/smoke-login-throttling.test.sh`, `tools/tests/smoke-mcp.test.sh`

**Interfaces :**
- Bibliothèque sourçable, sans `set -e` ni appel réseau à la source (modèle : l'en-tête de `tools/lib/security-headers.sh`) :
  - `smoke_auth_config_file <valeur>` : refuse `\r`/`\n` (message de R47 sur stderr, retour 2) ; sinon écrit le fichier `-K`
    en 600 (`umask 077`, `mktemp`), échappé comme aujourd'hui, et imprime son chemin. *(rectifié le 2026-10-30, sixième relecture, Sp6-7 : `mktemp`
    crée déjà le fichier en 0600 ; le message du commit c49a1fb8, qui le présente comme un changement pour deux scripts,
    se trompe : rien n'a changé là.)* L'appelant :
    `auth_config="$(smoke_auth_config_file "$AUDIT_BASIC_AUTH")" || exit 2` et garde son `trap`.
  - `smoke_excerpt <texte>` : caractères de contrôle en espaces, 160 caractères.
  - `smoke_fetch <libellé> <url> [arguments curl…]` : lance `"$SMOKE_CURL_BIN" "${SMOKE_CURL_OPTS[@]}" "$@" -w '\n%{http_code}' "$url"`,
    pose `SMOKE_STATUS` et `SMOKE_BODY` ; un échec de `curl` imprime « <libellé> : curl a échoué (code <n> : réseau, DNS ou
    délai) — rien de vérifié » et rend 1. L'appelant : `smoke_fetch … || exit 1`.
- Sourcée comme `audit-prod.sh` le fait déjà (`# shellcheck source=tools/lib/smoke-http.sh`, `source "$(cd "$(dirname
  "${BASH_SOURCE[0]}")" && pwd)/lib/smoke-http.sh"`, sortie 2 si introuvable). `audit-prod.sh` n'en prend que
  `smoke_auth_config_file`.

- [ ] **Step 1 : filet** — les trois tests hors ligne à 0, sorties notées.
- [ ] **Step 2 : tests, rouges (le comportement étendu)** — `smoke-login-throttling.test.sh` et `smoke-mcp.test.sh` : « curl en
  échec (code 7) à la première requête → sortie 1, message `curl a échoué (code 7)` » (le faux `curl` gagne la ligne
  `curl-exit:<n>` de R47). Expected : sortie 7. *(rectifié le 2026-10-30, sixième relecture, Sp6-6 : vrai pour `smoke-login-throttling.sh` seul ;
  `smoke-mcp.sh` sortait déjà en 1 avec un message, son rouge était l'absence de « rien de vérifié ».)*
- [ ] **Step 3 : extraire** — la bibliothèque ; les quatre scripts la sourcent et perdent leurs copies (comportement des
  cas existants inchangé : les tests du filet restent verts sans changement).
- [ ] **Step 4 : verts** — les trois tests hors ligne à 0 ; shellcheck (image épinglée) sur `tools/lib/smoke-http.sh`, les
  quatre scripts et les trois tests ; `AUDIT_BASIC_AUTH=$'u:p\nx' tools/audit-prod.sh https://example.invalid; echo "exit=$?"` → 2 ;
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `refactor(tools): la Basic Auth, l'extrait et l'appel curl des scripts de fumée dans tools/lib/smoke-http.sh ; un échec réseau dit partout (#748)`.

---

### Task R58 : Documentation (C5-1, C5-2, C5-11, St5-4, St5-5, St5-16 pour la règle, Sp5-10)

**Files :**
- Modify : `.claude/rules/security-authentication-sessions.md`, `.claude/rules/security-authentication-audit.md`, `.claude/rules/frontend-session.md`, `k8s/README.md`, `docs/adr/0003-paliers-d-acces.md`

- [ ] **Step 1 : `security-authentication-sessions.md` (St5-16, C5-1, C5-2)** — dans la puce de
  `StaleSessionCookiesClearingListener`, tout ce qui suit « `JWT_NOT_FOUND` (no cookie) clears nothing. » jusqu'à « Rejected:
  `lazy: true` … firewall without authenticator for public routes. » **sort** pour former sa propre puce,
  « **`Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener`** (#748 R35, …) », texte déplacé, pas récrit ; elle
  reçoit à sa fin les deux limites, texte exact :
  « **Accepted limit (C5-1, the author's decision of 2026-10-30)**: a closed session (the password changed since) kept by a
  legitimate tab writes one `session-refused-after-credentials-change` line per public request until that tab's next
  `GET /api/me` — every load — clears the cookie; bounded by the nginx `publicapi` zone and the page's life, and an
  attacker gains nothing (served as anonymous). Not de-duplicated, for the reason in `security-authentication-audit.md`.
  **Accepted (C5-2, the author's decision of 2026-10-30)**: since the SPA sends its cookies to public reads (R37), a public
  read by a signed-in person is authenticated — the JWT verified and one `SELECT` of the account (the `cpg_users` provider
  reloads it) —, the price of serving a public route whatever the cookie (decision 1 of the fourth review); the response
  stays the anonymous one. »
  L'« Accepted limit » restant dans la puce de `StaleSessionCookiesClearingListener` (l'échéance du login) y reste.
  La phrase « Pinned by … » se partage : `RefusedSessionCookiesAreClearedTest` reste dans la puce d'origine ;
  `PublicRouteIgnoresRefusedSession{,Registration}Test`, `AuthorizationLivesInAccessControlTest` et le smoke de préprod
  passent dans la nouvelle.
- [ ] **Step 2 : `security-authentication-audit.md` (C5-1)** — dans « No de-duplication, on purpose », « the browser clears the
  cookies after the first 401 (`StaleSessionCookiesClearingListener`), so only a loop replaying a kept token writes several
  lines » **remplacé** par « the browser clears the cookies at the first 401 of a route that requires a role
  (`StaleSessionCookiesClearingListener`); a public route serves the closed session as anonymous and audits each request
  (#748 R35), so a legitimate tab writes one line per public read until its next `/api/me` (C5-1, accepted), and otherwise
  only a loop replaying a kept token writes several lines ».
- [ ] **Step 3 : `frontend-session.md` (St5-4, C5-11)** — « Tests », dernière puce : « (a refused cookie cleared by its first
  401) » → « (a refused cookie cleared by the first 401 of a protected route) ». « Accepted limits », dernière puce : « the
  server serves a public route as anonymous whatever the cookie (R35) » → « the server serves a public route as anonymous
  whatever the cookie (R35; in dev, two origins, public reads leave without cookies, so R35 is exercised there by PHPUnit
  and the preprod smoke only) ».
- [ ] **Step 4 : `k8s/README.md` (St5-5)** — l. 637 : « que construisent `tools/audit-prod.sh` et
  `tools/smoke-login-throttling.sh` » → « que construit `tools/lib/smoke-http.sh` pour `tools/audit-prod.sh` et les trois
  scripts de fumée (`smoke-login-throttling.sh`, `smoke-dead-session-cookie.sh`, `smoke-mcp.sh`) » ; l. 659-660 :
  « `smoke-test-preprod` (`tools/smoke-login-throttling.sh`) » → « `smoke-test-preprod` (`tools/smoke-login-throttling.sh`,
  `tools/smoke-dead-session-cookie.sh`, `tools/smoke-mcp.sh`) ».
- [ ] **Step 5 : ADR 0003 (Sp5-10)** — l. 272 : « sur toute l'API authentifiée par le cookie » → « sur toute route de l'API
  authentifiée par le cookie qui exige un rôle ».
- [ ] **Step 6 : balayage** — `grep -rn "first 401\b\|premier 401\|lastSaveOf\|replayId\|refusalScope: number\|application/auth/singleFlight" .claude docs frontend/src backend/src k8s/README.md`
  ne rend que l'historique daté ou le sens juste (le noter).
- [ ] **Step 7 : verts** — `tools/check-claude-rules.sh; echo "exit=$?"` ; `make front-test; echo "exit=$?"` (aucun code
  touché : témoin) ; `make back-test; echo "exit=$?"` non requis (aucun fichier backend).
- [ ] **Step 8 : commit** — `docs: les limites C5-1 et C5-2, la puce du listener R35, les tests et le README à jour de la cinquième relecture de #748`.

### Constats écartés, avec leur raison

- **C5-13** (`/api/logout` avec un `BEARER` mort et sans `XSRF-TOKEN` valide : 403 CSRF, rien d'effacé). Préexistant ; le
  SPA envoie toujours l'en-tête depuis le cookie (`HttpAuthRepository.logout()`), et sans `XSRF-TOKEN` le cookie mort tombe au
  prochain `/api/me` (chaque chargement). Exclure `/api/logout` du CSRF rouvrirait la déconnexion forcée d'un tiers ; effacer
  sur le 403 CSRF ferait d'un refus de sécurité un effet de bord. Avantage nul, risque réel.
- **Sp5-4** : le code suit le docblock de `TestServiceLocator` ; c'est le plan de R35 qui avait tort (vérification).
- **Sp5-5 à Sp5-8** : écarts de table, cohérents ; rien à changer.
- **Sp5-9** : déjà couvert par la tâche 11 (« La préprod réelle, derrière sa Basic Auth »).
- **St5-11, en partie** : la génération, le périmètre de refus et `isCurrentKey` restent distincts (choix n° 8).
- **St5-12, en partie** : `requiresAccess()` public et `resetBackofficeAccessRefusalForTests` dans `src/` gardés (choix n° 9).
- **CR5-8, l'ordre des paramètres** : faux en pratique (vérification) ; épinglé par la caractérisation de R54.
  *(rectifié le 2026-10-30, sixième relecture, Sp6-3 : ce n'était pas un écart — la caractérisation a rougi, CR5-8 était juste et la clé est triée
  depuis 79d0b073.)*

---

## Corrections de la sixième relecture (2026-10-30)

R46 à R58 sont faites (1a09d55e → 69d45ae3). Quatre relectures du diff 63dc48f7...69d45ae3 (`/code-review medium`
CR6-*, audit de sécurité C6-*, axe Standards St6-*, axe Spec Sp6-*) : aucun critique ni haut ; l'audit de sécurité
n'a aucun moyen non plus (3 faibles, 9 info) et dit C5-1, C5-2 et C7 non aggravées. Constats complets et décisions :
`tasks/relectures-748/relecture-6-constats.md` (la source de vérité). Tous sont traités ici par R59 à R72, avant les
tâches 11 et 12 : corrigés, écrits comme limite, ou écartés avec leur raison (ci-dessous). Les sections passées de ce
plan restent l'archive de leur moment : là où les séries les ont rendues fausses, une note datée « *(rectifié le
2026-10-30, sixième relecture : …)* » est posée à leur endroit, sans les réécrire (Sp6-3, Sp6-6, Sp6-7, Sp6-10, Sp6-1).

### Décisions de l'auteur (2026-10-30)

1. **CR6-3 : signaler au retour.** Au montage d'`AccessRefusedNotice`, un refus de **lecture** compté hors du
   backoffice est oublié ; un refus d'**écriture** s'affiche avec un texte propre, FR et EN (« Un enregistrement
   précédent a été refusé par le serveur… »). **Amende la réponse Q1 de la cinquième série** (« effacé au montage »)
   pour les écritures : R38 (une écriture refusée est toujours dite) l'emporte. R64.
2. **CR6-5 : juger sur le compte.** Le timbre d'envoi retient le compte (identifiant) ; un refus n'est écarté que si le
   compte a changé — un 403 du même compte reste dit, même après une fin puis un retour de session ou une bascule de
   palier. Remplace le contrôle de génération qu'R48 avait mis dans `refused()`. Tranché au choix n° 2 : **le compte
   seul**, lecture comme écriture. R63.
3. **CR6-2 : bloquer la langue cible.** « Proposer la traduction » est désactivé tant qu'un enregistrement de la langue
   **cible** est en vol (un enregistrement EN n'est toujours pas tenu par un FR, R50). R67.
4. **Sp6-5 / CR6-1 : corriger dans la série.** L'avis de proposition ne s'affiche que sous sa langue cible et disparaît
   quand le formulaire de cette langue est relu du serveur (la proposition est alors perdue). R66.
5. Toujours valables : **tout jusqu'à zéro** (décision 2 de la quatrième série) ; C5-1, C5-2, C7 limites acceptées ;
   R31 (une erreur par langue), Q1 de la quatrième série (cookie gardé et audité sur une route publique), R50 (« un EN
   n'est pas tenu par un FR »), Q2 et Q3 de la cinquième série restent tels quels.
6. **CR5-8 / St6-1, fait établi** (consigne du fil principal) : le test de caractérisation de R54 était rouge avant le
   tri de la clé, donc vue-router **exécute** une navigation aux paramètres permutés. Le tri reste ; ce sont le test
   (titre, commentaire) et les docblocks de `backofficeAccessRefusal.ts` qui se corrigent. R64.

### Vérification de chaque constat dans le code (HEAD d925c7dc)

- **CR6-3, confirmé.** `AccessRefusedNotice.vue:75` : `onMounted(discardBackofficeAccessRefusals)` remet le compteur à
  zéro sans distinguer lecture et écriture (`backofficeAccessRefusal.ts:70-72`). Une lecture ne peut d'ailleurs pas être
  comptée hors du backoffice : le démontage de l'alerte avance le périmètre, et la lecture partie avant porte l'ancien
  (`markBackofficeAccessRefused`, l. 45-48). Ce qui est compté pendant qu'aucune alerte n'est montée est donc, en
  pratique, une écriture — exactement ce que R38 veut dire.
- **CR6-5, confirmé.** `backofficeSessionExpiry.ts:51` : `isCurrentGeneration(sentAt.session)` écarte aussi un refus du
  même compte : la génération avance à `markSessionExpired`, à `restoreSession` (même compte compris), à
  `markBaseAccessGranted`/`markBaseAccessExpired`, à `login()`/`logout()` (`sessionLifecycle.ts:54-91`,
  `useAuth.ts:149-182`). Le compte de la page est déjà tenu : `getLastAccount()` (`sessionState.ts:85`), gardé quand
  l'en-tête passe à anonyme ou au palier de base, oublié par `logout()` et `markBaseAccessGranted()`.
- **CR6-2, confirmé.** `AdminAboutSettingsForm.vue:206` : `TranslateEntryButton` n'est désactivé que par `isSaving`, la
  langue **montrée** (`useAdminAboutSettings.ts`, `isSaving` lit `isCurrentKey`). La réponse d'un enregistrement de la
  langue cible passe par `write(locale, saved)` sans condition depuis R50 : elle repose les réglages, que le `watch` de
  `settings` recopie par-dessus la proposition.
- **CR6-1 = Sp6-5, confirmé.** `AdminAboutSettingsForm.vue:213` : `v-if="proposalSourceLocale"`, sans lien avec
  `props.locale` ; rien ne retire l'avis quand le `watch` de `settings` recopie les réglages du serveur dans le
  formulaire (un retour sur la langue cible relit ses réglages et perd la proposition).
- **St6-1 = Sp6-2 (CR5-8), confirmé.** `AccessRefusedNotice.spec.ts:143-158` : titre « le routeur n'y voit pas de
  navigation » et commentaire « cette « navigation » est un doublon, il ne l'exécute pas », contraires au fait établi
  (décision 6), au commentaire du composant (`AccessRefusedNotice.vue:54-56`, juste) et à la règle (juste).
  `backofficeAccessRefusal.ts:26-28` dit « une navigation vers l'adresse courante n'en est pas une (vue-router
  n'exécute pas celle qui ne change rien, et l'alerte ne tient pas compte de l'ordre des paramètres) » : vrai pour
  l'adresse identique, ambigu pour la permutation (exécutée par vue-router, ignorée par la clé triée). Le message du
  commit 79d0b073 dit à la fois « comportement identique » et « la clé trie » : note d'archive (Sp6-3).
- **St6-2 = Sp6-1, confirmé.** Le montage n'avance pas le périmètre (`discard…`), mais : `backofficeAccessRefusal.ts:21-23`
  (« le montage et le démontage de l'alerte (R48) » parmi ce qui l'avance) ; `backofficeSessionExpiry.ts:29`
  (« chemin ou requête changés, fin de session, montage, démontage ») ; `frontend-session-refus.md`, puce du
  backoffice : « every clearing advances it (…; the alert's mount and unmount) » puis, plus loin, « the alert also
  clears on mount, without advancing the scope » — la puce se contredit, décrit trois fois les déclencheurs, et la
  phrase de R48 a été **ajoutée** au lieu de remplacer. Le docblock d'`AccessRefusedNotice.vue:34-41` est juste.
- **St6-3, confirmé.** `AuthorizationLivesInAccessControlTest` n'a aucun test du détecteur sur un extrait : seuls
  `src/` réel (aucun site aujourd'hui) et l'entrée d'exemption le parcourent. La pratique de `backend-conventions.md`
  (« each case is proven on a string ») n'est pas suivie.
- **CR6-4 = St6-11, confirmé.** `useAuth.ts:233` : le `catch {}` de `checkAuth` couvre `applyReadSession`
  (`hasWriteToken()`, `applySession`, `restoreSession`, les abonnés isolés), `scheduleBaseAccessExpiry` et le reste, pas
  seulement le rejet de `repository.me()`.
- **CR6-8, confirmé.** `useAuth.ts:209` (« et à chaque relecture hors session finie ») : le seul appelant est `main.ts:125`.
- **CR6-6, confirmé.** `ApiRouteExposureTest.php:267` vérifie l'effacement sur tout 401 anonyme du pare-feu `api` ; une
  route publique qui répondrait 401 par logique métier (aucune aujourd'hui) rougirait à tort.
- **C6-1, confirmé.** `answerWithBearer()` (l. 291-316) n'envoie ni cookie ni en-tête `XSRF-TOKEN` : sur toute écriture
  protégée, `CsrfCookieRequestSubscriber` (priorité 20) répond 403 avant le pare-feu dans les trois variantes ; l'égalité
  des statuts est triviale et la clause d'effacement (401) ne s'applique jamais aux écritures. `CsrfCookieTokenSigner::issue()`
  existe (service).
- **CR6-7 = C6-3, confirmé.** Non vus : `Foo::isGranted(` (exclu par `T_DOUBLE_COLON` au premier `if`, et pas un appel
  `->`), un `security:` déclaré en YAML ou XML, `new AccessDeniedException` / `AccessDeniedHttpException` /
  `UnauthorizedHttpException`, `->decide(`, une chaîne `is_granted(` hors attribut. État de `src/` : `new
  AccessDeniedHttpException` dans les deux gardes CSRF (`CsrfCookieRequestSubscriber.php:137,147`,
  `LoginCsrfRequestListener.php:75`, avant le pare-feu, par construction) ; un seul `->decide(`, homonyme
  (`ConsentController.php:106`, `$this->consent->decide()`, la décision de consentement OAuth) ; aucun
  `AccessDecisionManagerInterface`, aucune chaîne `is_granted`.
- **Sp6-4 = C6-2, confirmé.** Docblock d'`AuthorizationLivesInAccessControlTest:21-22` : « l'invariant n° 8 … ne compare
  que les statuts », faux depuis R46. Et R59 (choix n° 5) le rend plus faux encore : l'effacement ne sera vérifié que
  sur une route qui exige un rôle, donc **jamais** sur une route sans règle — cette garde devient le seul témoin de la
  prémisse.
- **Sp6-11 = C6-6, confirmé.** `noOmittedCredentials.spec.ts:32` : la regex `\/\*[\s\S]*?\*\/` part d'un `'/*'` écrit
  dans une chaîne et avale tout jusqu'au prochain `*/`, une chaîne `'omit'` comprise ; même défaut dans les deux relevés
  de `sessionStateEncapsulation.spec.ts:53,87`.
- **St6-4, confirmé.** `RefusedBearerCookie` appelle `self::getContainer()` (l. 44, 65) sans `@phpstan-require-extends`.
- **St6-5, confirmé.** L'invariant « toute autorisation de `/api` est une règle d'`access_control` » n'est écrit que dans
  `security-authentication-sessions.md` (puce du listener R35) ; `backoffice-api.md`, « Authorization », que charge un
  fichier de `Presentation/ApiResource/`, n'en dit rien.
- **St6-7, confirmé.** `ApiRouteExposureTest.php:311` : `getExpiresTime() > 0 && getExpiresTime() < time()` est
  `Cookie::isCleared()`.
- **St6-9, confirmé.** Retrait des commentaires par la même regex trois fois (ci-dessus) ; faux `fetch` qui n'obéit qu'à
  `abort` deux fois (`HttpAuthRepository.spec.ts:73`, `adminGuard.spec.ts:532`) ; `namesImportedFromSessionState`,
  `namesInExportBlocks`, `offendsWithOmit` exportées de fichiers `.spec.ts`, sans importeur.
- **St6-10, confirmé.** `proposalSourceLocale` et `proposalTargetLocale` (`AdminAboutSettingsForm.vue:53-56`) sont
  posés et retirés ensemble.
- **St6-12, confirmé, et une copie de plus trouvée.** Le bloc « Basic Auth » (`SMOKE_CURL_OPTS+=(-K …)` sous
  `if [ -n "${AUDIT_BASIC_AUTH:-}" ]`) est recopié dans les trois scripts de fumée ; `smoke_auth_config_file` nomme
  `AUDIT_BASIC_AUTH` en dur (`smoke-http.sh:44`) ; deux noms du binaire (`SMOKE_CURL` dans les scripts et leurs tests,
  `SMOKE_CURL_BIN` dans la bibliothèque), recopiés trois fois (`SMOKE_CURL_BIN="${SMOKE_CURL:-curl}"`). Vu en vérifiant :
  `header()` est écrit deux fois à l'identique (`smoke-login-throttling.sh:81`, `smoke-mcp.sh:101`). `fetch()`
  (`smoke-dead-session-cookie.sh:104`) et `request()` (`smoke-mcp.sh:109`) ne sont pas de purs intermédiaires : ils
  ajoutent le libellé, le `|| exit 1`, les copies locales et (MCP) l'extrait (choix n° 12).
- **C6-4, confirmé.** `ISGRANTED_OUTSIDE_API` exempte tout le fichier de `NominativeCorpusReader`, quel que soit le
  nombre d'appels ; il en a un (l. 66).
- **C6-5, confirmé.** `sessionStateEncapsulation.spec.ts:56` : le chemin doit finir par `sessionState` ou
  `sessionState.ts` (pas `.js`) ; un `import()` à gabarit et `import.meta.glob` échappent ; un importeur autorisé peut
  réexporter une écriture sous une autre forme (`export const x = raiseSessionEnded`). Aucun `import.meta.glob` dans
  `src/` aujourd'hui.
- **C6-7, confirmé.** Les `source … 2> /dev/null || { … introuvable … }` des quatre scripts (`audit-prod.sh:49,109,117`,
  `smoke-*.sh`) masquent une erreur de syntaxe de la bibliothèque sous « introuvable » ; `audit-prod.sh` source
  `smoke-http.sh` sans condition, alors qu'il n'en sert qu'avec `AUDIT_BASIC_AUTH`.
- **C6-8, confirmé.** `audit-prod.sh:54-55` : le fichier `-K` est créé avant le `trap` (les scripts de fumée posent le
  `trap` avant).
- **C6-9, confirmé** (= St6-12 pour le nom en dur).
- **C6-10, confirmé.** L'invariant n° 8 vérifie l'échéance des cookies effacés, pas leurs attributs ; un effacement aux
  attributs différents de ceux posés ne supprime rien dans le navigateur (la raison d'être d'`AuthCookieFactory::expired()`).
- **C6-11, confirmé** (aucune variante « session close » dans le balayage) ; écarté (ci-dessous).
- **C6-12, confirmé.** `smoke-login-throttling.sh:113` imprime `« $retry_after »` brut dans le journal de la CI. *(rectifié le 2026-10-30, septième relecture : `header()` retirait déjà le `\r` (`tr -d '\r'`, vu dans 8cbfea45^) : le défaut réel était les autres caractères de contrôle, que `smoke_excerpt` remplace aussi — Sp7-2)*
- **St6-6, confirmé.** `JwtClockSkewTest` n'utilise du trait que `putTheBearer` (l. 100).
- **St6-8, confirmé en partie.** `SessionBeforePasswordChangeTest:455` garde un `putTheBearer(string)` homonyme, d'un
  autre sens (il recopie les attributs du cookie posé par la vraie connexion) ; le docblock du trait dit « Quatre tests le
  refaisaient chacun » : le compte est juste (quatre utilisateurs), mais la phrase raconte l'historique et « écrit une
  fois » est démenti par l'homonyme.
- **St6-13, confirmé.** `ApiRouteExposureTest.php:253` (201 car.) et `:272` (254 car.), écrites par R46 ; les lignes
  86-97 (justifications de `PUBLIC_PATHS`) sont antérieures à la série.
- **St6-14, confirmé.** Docblock de `requiresAccess()` : « sans effet (St5-12) » ne dit pas ce qui est sans effet.
- **St6-15, confirmé** (`let endedSessionRecheck`, `let refusalReread`, réaffectées par `resetSessionRereadsForTests`) ;
  écarté.
- **St6-16, constaté, cause non épinglée** ; écarté (ci-dessous).
- **St6-17, confirmé.** `frontend-backoffice.md` : « R43's `lastSaveOf` had nothing left to arbitrate and is gone ».
- **Sp6-3, Sp6-6, Sp6-7, Sp6-10, confirmés pour l'archive.** CR5-8 dit « faux en pratique » (l. 2510, 3039-3041, 3214) ;
  R54 dit « comportement identique » (l. 3051) ; « même motif dans `smoke-mcp.sh` » (l. 2522) est faux — avant R57,
  `smoke-mcp.sh` attrapait déjà l'échec de `curl` (`if response="$(…)"; then … else … exit 1`, vu dans 647725d4) — et
  « sortie 7 » attendue pour lui (l. 3147) aussi : son test rouge était le message, pas le code ; le message de c49a1fb8
  dit le fichier `-K` « créé en 600 » comme un changement, alors que `mktemp` le crée déjà en 0600 ; R56 écrit
  `private const` (l. 3098), devenu `protected` (le docblock du trait dit pourquoi). Et `onMounted(clearBackofficeAccessRefusal)`
  (l. 2580, 2815) : R48 a posé `onMounted(discardBackofficeAccessRefusals)` (Sp6-1).
- **Sp6-8, confirmé** (ajouts hors table cohérents) ; rien à changer.
- **Sp6-9, confirmé.** R46 n'a vu l'effacement rouge que par la mutation de `JWT_INVALID` ; la mutation de `JWT_EXPIRED`
  est faite en R59.

### Options comparées

**Décision 2 — sur quoi juger un refus parti avant un changement** (le contrôle de `refused()`) :

| Option | Pour | Contre |
|---|---|---|
| (A) **le compte seul** (`account` du timbre ≠ compte courant → écarté), lecture comme écriture — **retenue** | une règle, celle que l'auteur a dite ; le 403 du même compte reste dit après une fin, un retour, une bascule de palier ; les lectures restent jugées par le périmètre, que chaque effacement avance (fin de session, démontage, chemin) | une lecture du même compte partie avant une bascule de palier sans effacement compterait — cas sans chemin dans le backoffice (un compte `ROLE_SUPER` n'a ni « Accès instantané » ni échéance d'accès de base) |
| (B) le compte, **et** la génération pour les lectures | défense « en profondeur » des lectures | deux mécanismes pour une règle (la leçon de St5-11) ; la génération avance aussi à `markBaseAccess*`, `login`, `logout`, et rendrait muet un refus de lecture du même compte, ce que la décision écarte ; aucun cas qu'(A) laisse passer et que (B) arrête n'a été trouvé (le périmètre avance déjà à la fin et au démontage) |
| (C) la génération seule (R48, statu quo) | aucun changement | contraire à la décision 2 (CR6-5) |

**Décision 1 — que faire, au montage, de ce qui a été compté sans alerte :**

| Option | Pour | Contre |
|---|---|---|
| (A) **un drapeau « une écriture a été comptée depuis le dernier effacement »**, lu au montage : écriture → l'alerte s'affiche avec `common.earlierSaveRefused`, sinon le compteur repart à zéro ; l'alerte s'insère **après** le montage (annoncée) — **retenue** | un booléen de module de plus, remis à zéro par l'effacement ; aucune liste ; l'insertion après le premier rendu suit la règle a11y (« une région présente au premier rendu peut ne pas être annoncée ») | deux textes pour une alerte (un `computed`) |
| (B) un compteur des écritures à part | dit combien | rien ne l'affiche ; un nombre de plus à remettre à zéro partout |
| (C) une file des refus (nature, instant) | le détail complet | un état que rien ne lit, à vider à chaque effacement ; démesuré pour un message |

**CR6-6 — l'effacement vérifié par l'invariant n° 8 :** (A) seulement si la route exige un rôle
(`PublicRouteIgnoresRefusedSessionListener::requiresAccess()`), **retenue** : pas de faux rouge sur un 401 métier, la
même source de vérité que le listener ; prix : un `#[IsGranted]` posé sur une route sans règle n'est plus vu par
l'invariant, `AuthorizationLivesInAccessControlTest` (R60) et la garde des métadonnées compilées (R61) en deviennent
les seuls témoins, et leurs docblocks le disent. (B) garder tout 401 du pare-feu : faux rouge possible, et confond un
refus métier avec une session refusée.

**CR6-7, `security:` hors des attributs :** (A) lire les **métadonnées compilées** d'API Platform (chaque opération :
`getSecurity()`, `getSecurityPostDenormalize()`, `getSecurityPostValidation()` nuls), **retenue** : voit l'attribut, le
YAML, le XML et tout ce qu'API Platform fusionne, comme `ApiPlatformStartupRaisesNoDeprecationTest` les parcourt
déjà ; (B) lire `config/**` par motifs : ne voit que la forme écrite, et `security:` est aussi la clé racine de
`security.yaml`.

**CR6-7, `->decide(` :** (A) refuser le **nom** `AccessDecisionManagerInterface` dans `src/` (sans lui, aucun
`decide()` d'autorisation n'est injectable), **retenue** ; (B) refuser `->decide(` : l'homonyme
`ConsentInterface::decide()` exigerait une exemption qui couvrirait aussi un vrai appel.

**Sp6-11 et St6-9 :** (A) un **lecteur de source** partagé (`tests/support/sourceText.ts`) qui parcourt le texte en
connaissant les chaînes (`'`, `"`, gabarits) et les commentaires, **retenu** : `'/*'` et `'http://'` dans une chaîne
restent des chaînes, et la garde `'omit'` lit les littéraux au lieu d'une regex ; (B) une limite écrite : la regex reste
fausse sur une chaîne banale (une URL dans un commentaire de bloc).

**C6-5 :** (A) la regex élargie (suffixe `.js`, `.mjs`…, `import()` non littéral et `import.meta.glob` comme jokers)
**et** un contrôle **par identité** (tous les modules `.ts` de `src/` chargés : aucun autre que `sessionState.ts`
n'exporte une valeur `===` à une écriture), **retenue** : la réexportation par une valeur échappe à toute lecture de
texte ; (B) la regex seule : laisse `export const x = writer`.

### Choix de conception tranchés

1. **Décision 1 (R64).** `backofficeAccessRefusal.ts` gagne `earlierWriteRefused` (lecture seule) et un drapeau de
   module « écriture comptée depuis le dernier effacement » ; `settleBackofficeAccessRefusalsOnMount()` remplace
   `discardBackofficeAccessRefusals()` : écriture comptée → `earlierWriteRefused` vrai, le compteur garde sa valeur ;
   sinon le compteur repart à zéro. Le périmètre n'avance pas au montage (le chargement de la page d'arrivée est parti
   avant). Un nouveau refus compté remet `earlierWriteRefused` à faux (le message redevient `common.accessRefused`, la
   `key` du `<span>` le réannonce). `AccessRefusedNotice` ne s'affiche qu'**après** son montage (`isMounted`) : inséré
   après le premier rendu, il est annoncé. L'effacement (`clearBackofficeAccessRefusal`) remet les deux à faux.
2. **Décision 2 (R63) : le compte seul** (options ci-dessus). `BackofficeSendStamp` gagne `account: string | null`, le
   dernier compte affiché à l'envoi (`lastAccountName()`, la façade `useAuth.ts`, sur `getLastAccount()`) ; `refused()`
   écarte le refus si `sentAt.account !== lastAccountName()`, et ne lit plus la génération. **Reste** : la génération de
   `ended()` (inchangée) et l'avance de génération d'`applyLiveSession` (Q2 de la cinquième série) — elle sert `ended()` :
   un 401 parti sous le premier compte ne finit pas la session du second. `null` (aucun compte retenu, par exemple après
   « Accès instantané », qui oublie le dernier compte) diffère de tout identifiant : écarté.
3. **Décision 3 (R67).** `useAdminAboutSettings` expose `isSavingLocale(locale: Locale): boolean` ; le formulaire
   désactive « Proposer la traduction » si la langue montrée **ou** la langue cible enregistre (`isSaving ||
   isSavingLocale(otherLocale(locale))`). La langue cible est calculée une fois, `otherLocale(locale)` dans
   `domain/portfolio/entities/Locale.ts`, que `TranslateEntryButton` utilise aussi (aujourd'hui son propre `find`).
   Conséquence acceptée et écrite : un enregistrement de la cible lancé **pendant** que la traduction court (la personne
   change de langue, enregistre, revient) recouvre encore la proposition à sa réponse ; l'avis part alors avec elle
   (décision 4), rien n'est dit de faux.
4. **Décision 4 et St6-10 (R66).** `const proposal = ref<{ readonly source: Locale; readonly target: Locale } | null>(null)` ;
   l'avis : `v-if="proposal && proposal.target === locale"` ; le `watch` de `settings` (synchrone), après la copie,
   retire `proposal` si sa cible est `props.locale` : toute relecture serveur de cette langue (la réponse d'un
   enregistrement réussi, un rechargement, un retour) le retire. L'effacement explicite de `handleSubmit` disparaît
   (la réponse écrite par `write` passe par ce `watch`) ; un enregistrement refusé ne relit rien et garde l'avis (F11).
   La proposition en attente se pose après (le `watch` d'`isLoaded`), donc n'est pas retirée par la copie qui la précède.
5. **CR6-6, C6-1, C6-10, St6-7 (R59).** `answerWithBearer()` pose, pour une méthode non sûre, une paire `XSRF-TOKEN`
   valide (cookie et en-tête `X-XSRF-TOKEN`, valeur de `CsrfCookieTokenSigner::issue()` du conteneur) dans les **trois**
   variantes ; il rend `cleared` (nom → attributs, `isCleared()`) ; l'effacement est vérifié quand le statut anonyme est
   401, le pare-feu `api` **et** `requiresAccess()` vrai, aux attributs exacts de `AuthCookieFactory::expired()`.
6. **St6-3, CR6-7, C6-4 (R60).** Le détecteur sort dans `tests/Support/AuthorizationSites` (la pratique de
   `DollarAnchoredPatterns`), prouvé sur des extraits ; formes ajoutées : `::isGranted(`, `new AccessDeniedException` /
   `AccessDeniedHttpException` / `UnauthorizedHttpException`, le nom `AccessDecisionManagerInterface`, une chaîne qui
   contient `is_granted(`. Exemptions comptées : `[raison, nombre exact de sites]` ; les deux gardes CSRF exemptées pour
   leurs `new AccessDeniedHttpException` (avant le pare-feu, par construction : leur 403 n'est pas l'autorisation d'une
   route).
7. **CR6-7, YAML et XML (R61) :** garde des métadonnées compilées, `ApiPlatformOperationsCarryNoSecurityTest`.
8. **CR6-4 (R65).** Seul le rejet de `await repository.me()` est avalé ; une erreur levée après (programmation, abonné,
   minuterie) remonte de `checkAuth()`, le `finally` lève toujours la première vérification. `main.ts` ne l'attrape pas :
   une erreur de programmation doit se voir (rejet non géré dans la console), un échec réseau non.
9. **Sp6-11, St6-9 (R68).** `tests/support/sourceText.ts` : `withoutComments(source)` et `stringLiterals(source)` ;
   `tests/support/abortableFetch.ts` : `fetchSettledOnlyByAbort()` ; les trois relevés des specs ne sont plus exportés.
10. **C6-5 (R69)** : options ci-dessus. `src/main.ts` est hors du chargement (il monte l'application) ; les `.vue` aussi
    (limite écrite : un `<script>` non `setup` qui réexporterait une écriture).
11. **C6-7, C6-8, C6-12 (R62)** : comportement d'outillage, avant le refactoring R71.
12. **St6-12 (R71)** : `smoke_add_basic_auth <nom de variable>` (le bloc, le `-K`, `SMOKE_AUTH_CONFIG` pour le `trap`),
    `smoke_auth_config_file <nom> <valeur>` (le message nomme la variable reçue, C6-9), `smoke_header <fichier> <nom>`,
    un seul nom du binaire, `SMOKE_CURL`, lu par la bibliothèque. `fetch()` et `request()` **restent** (raison au choix
    ci-dessus : ils ajoutent la sortie, le libellé et les copies ; les fondre dans chaque appel recopierait `|| exit 1`
    et les affectations à chaque site).
13. **St6-4, St6-6, St6-8, St6-14 (R70)** : un trait `PutsTheBearerCookie` (le seul `putTheBearer`), que
    `RefusedBearerCookie` utilise et que `JwtClockSkewTest` prend seul ; `@phpstan-require-extends KernelTestCase` sur
    `RefusedBearerCookie` ; l'homonyme de `SessionBeforePasswordChangeTest` renommé `replaceTheSignedInBearer()` ; le
    docblock de `requiresAccess()` récrit.

**Questions à l'auteur** (le plan suit la recommandation ; une autre réponse ne change que la tâche citée) :

- **Q1 (R64)** : le texte du refus d'un enregistrement précédent. **Recommandé**, calqué sur `common.accessRefused` (même
  neutralité, S8) : FR « Un enregistrement précédent a été refusé par le serveur : votre compte n'a peut-être pas, ou
  plus, l'accès de confiance, ou la session doit être rouverte. Changez de compte, ou reconnectez-vous au même, pour
  continuer. » ; EN « An earlier save was refused by the server: your account may not have, or may no longer have,
  trusted access, or the session needs to be reopened. Switch account, or sign in again to the same one, to
  continue. » Autres options : la première phrase seule (plus court, mais la personne ne sait pas quoi faire) ; nommer
  la page de l'enregistrement (impossible : le refus ne porte pas sa route, et l'y mettre ferait entrer une notion de
  navigation dans le timbre d'une écriture).

**Réponse de l'auteur (2026-10-30)** : Q1 selon la recommandation, le texte complet (FR et EN ci-dessus, la version EN du
plan fait foi).

### Review Focus (sixième relecture)

1. **Une écriture refusée pendant que la personne était sur une page publique, puis un retour au backoffice** : l'alerte
   s'affiche avec `common.earlierSaveRefused`, insérée après le montage ; un nouveau refus sur la page la remplace par
   `common.accessRefused` ; une navigation l'efface. Test : R64.
2. **Le même compte, une session finie puis rétablie entre l'envoi et le 403** (déconnexion et reconnexion dans un autre
   onglet) : le refus est compté et relu, l'alerte s'affiche. Test : R63.
3. **Réglages « À propos » : proposition EN, passage en FR, retour en EN** : sous le FR, aucun avis ; au retour, le
   formulaire EN est celui du serveur et l'avis a disparu. Test : R66.
4. **Réglages « À propos » : un enregistrement EN en vol, la page en FR** : « Proposer la version EN » désactivé jusqu'à
   la réponse EN ; « Enregistrer » FR actif. Test : R67.
5. **Une écriture protégée avec un `BEARER` refusé et une paire CSRF valide** : 401 du pare-feu, les deux cookies
   expirés aux attributs de la fabrique. Test : R59 (invariant n° 8, toutes les méthodes non sûres).

### Contraintes propres aux tâches R59 à R72

- Les **Global Constraints** et toutes les contraintes des séries précédentes valent. Exécution : **un agent par tâche
  (sonnet, effort high), successifs dans l'ordre R59 → R72**, qui lit d'abord `tasks/relectures-748/brief-commun-agents.md`.
  `superpowers:test-driven-development` : test écrit d'abord, **vu rouge pour la bonne raison** ; un test de couverture
  vert d'emblée est vu rouge par la mutation que sa tâche nomme, **restaurée depuis une copie du fichier faite avant la
  mutation et vérifiée par `cmp`** (`cp f "$scratch/f.orig"`, muter, mesurer, `cp "$scratch/f.orig" f`,
  `cmp f "$scratch/f.orig"`) — **aucune commande git ne réécrit l'arbre** (ni `checkout`, ni `checkout-index`, ni
  `restore`, ni `stash`, ni `reset`) ; refactoring pur : la suite existante comme filet, nombre de tests noté avant et
  après. **`superpowers:verification-before-completion` invoquée réellement** avant le rendu. **Un commit par tâche**,
  fichiers nommés (`git add <fichiers>`, `git mv`/`git rm` pour un déplacement ou une suppression), message en français
  terminé par les lignes d'attribution que donne le fil principal.
- **Une docstring ou une règle qui décrit un comportement modifié se corrige dans le même commit que le comportement**
  (St6-2 et Sp6-1 sont nés de docs laissées en arrière) ; une phrase de règle se **remplace**, elle ne s'ajoute pas à
  côté de l'ancienne.
- **Aucun travail en arrière-plan au rendu** : ni conteneur lancé à part, ni `vitest --watch`, ni processus détaché.
- **Chaque code de sortie se lit sans pipe** : `make front-test; echo "exit=$?"` (de même `front-lint`, `front-build`,
  `back-test`, `back-quality`, chaque test hors ligne et `tools/check-claude-rules.sh`).
- Backend (R59, R60, R61, R70) : lire avec `Read` un fichier de `backend/tests/Security/` avant d'écrire ; test ciblé
  `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis `make back-test` et `make back-quality` (toujours
  `-u dev`) ; `rm -rf var/cache/test` après un changement de service ou de configuration et après chaque mutation ;
  `make back-style-fix` après un import ajouté ; jamais de secret littéral (`TestCredentials`, `TestKeys::material()`,
  une valeur aléatoire).
- Outillage (R62, R71) : lire avec `Read` les scripts touchés avant d'écrire ; `bash tools/tests/<script>.test.sh` hors
  ligne pour chaque script touché ; shellcheck dans l'image épinglée du job `tools-tests` : `docker run --rm
  --entrypoint shellcheck -v "$PWD":/repo:ro -w /repo
  rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -x --severity=warning
  <fichiers>` (si l'image n'expose pas `shellcheck`, le noter et lancer celui de l'hôte, en le disant).
- Frontend (R63 à R69) : test ciblé `docker compose exec -T frontend npm test -- <chemins>`, puis `make front-test`,
  `make front-lint`, `make front-build` ; `docker compose restart frontend` après un fichier créé, déplacé ou un
  remplacement de masse.
- Règles : `tools/check-claude-rules.sh` après chaque modification (50 000 octets par règle ; `security-user.md` n'est
  pas touchée ; `backoffice-api.md` fait 39 457 octets, `frontend-session-refus.md` 11 759, `frontend-backoffice.md`
  26 149, `security-authentication-sessions.md` 20 052) ; un fichier créé ou déplacé qui entre dans les `paths:` d'une
  règle y est ajouté dans le même commit et **indexé (`git add`) avant** `check-claude-rules`.
- Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets (les exclure de tout
  `grep -r`) ; ne jamais nommer un compte réel. Pas de push, pas de PR.

### Fichiers (tâches R59 à R72)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `backend/tests/Security/ApiRouteExposureTest.php` | invariant n° 8 : écritures, `requiresAccess()`, attributs | R59 |
| `backend/tests/Support/AuthorizationSites.php` (nouveau), `backend/tests/Security/AuthorizationLivesInAccessControlTest.php` | détecteur et garde de source | R60 |
| `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php` (nouveau) | garde des métadonnées compilées | R61 |
| `backend/tests/Support/{RefusedBearerCookie,PutsTheBearerCookie}.php`, `backend/tests/Security/Authentication/Infrastructure/Jwt/JwtClockSkewTest.php`, `backend/tests/Security/Authentication/SessionBeforePasswordChangeTest.php` | aides de test | R70 |
| `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php` | docblock de `requiresAccess()` | R70 |
| `tools/{audit-prod,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp}.sh`, `tools/lib/smoke-http.sh`, `tools/tests/{smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp,smoke-http}.test.sh` | sources, `trap`, extrait ; bibliothèque | R62, R71 |
| `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/application/auth/useAuth.ts` | le compte du timbre | R63, R65 |
| `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json` | le refus au montage | R64 |
| `frontend/src/application/admin/about/useAdminAboutSettings.ts`, `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue`, `frontend/src/presentation/ui/admin/TranslateEntryButton.vue`, `frontend/src/domain/portfolio/entities/Locale.ts` | avis et bouton de traduction | R66, R67 |
| `frontend/tests/support/{sourceText,abortableFetch}.ts` (nouveaux), `frontend/tests/support/sourceText.spec.ts` (nouveau) | lecteur de source, faux `fetch` | R68 |
| `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts`, `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts` | gardes de source | R68, R69 |
| `.claude/rules/*` | dans le commit du comportement | R59 à R71 ; balayage R72 |

*(rectifié le 2026-10-30, septième relecture : la table omettait des fichiers que R62 et R71 ont touchés : `tools/tests/audit-prod.test.sh` (créé par R62, modifié par R71), `.github/workflows/pipeline.yml` (R62), `.claude/rules/deploiement-nginx.md` (R62, R71), `.claude/rules/deploiement.md`, `tools/check-tls-expiry.sh` et `tools/tests/tls-certificate.test.sh` (R71) — Sp7-3)*

---

### Task R59 : Backend — l'invariant n° 8 voit les écritures, ne juge l'effacement que sur une route qui exige un rôle, et en vérifie les attributs (C6-1, CR6-6, C6-10, St6-7, St6-13, Sp6-9)

**Files :**
- Modify : `backend/tests/Security/ApiRouteExposureTest.php`
- Modify (règle) : `.claude/rules/security-authentication-sessions.md` (la phrase « **Invariant n° 8 of `ApiRouteExposureTest`**: … » de la puce du listener R35)

**Interfaces :**
- `answerWithBearer($client, $method, $path, ?string $bearer)` : pour une méthode non sûre (hors `GET`, `HEAD`,
  `OPTIONS`), pose dans le pot `XSRF-TOKEN` = `self::getContainer()->get(CsrfCookieTokenSigner::class)->issue()` (aux
  attributs de la fabrique : `Path=/`, `localhost`, non `HttpOnly`, `lax`) et l'en-tête `HTTP_X_XSRF_TOKEN` de même
  valeur, dans les trois variantes (anonyme, invalide, expiré) ; rend `array{status: int, cookies: list<string>,
  cleared: array<string, array<string, mixed>>}` — `cleared` : nom → `$this->attributes($cookie)` des cookies dont
  `isCleared()` est vrai (St6-7 ; `expired` disparaît).
- La clause d'effacement : `401 === $answers['anonyme']['status'] && 'api' === $this->firewallOf($path, $method) &&
  $this->requiresAccess($path, $method)`, où `requiresAccess()` appelle
  `self::getContainer()->get(PublicRouteIgnoresRefusedSessionListener::class)->requiresAccess(Request::create(ConcreteApiUrl::of($path), $method))` ;
  pour `BEARER` et `XSRF-TOKEN` : `assertSame($this->attributes($factory->expired($name)), $answers[$label]['cleared'][$name] ?? null, <méthode, chemin, libellé, noms effacés>)`.
- Docblock de l'invariant 8 (en tête de classe et de la méthode) : les écritures portent une paire CSRF valide (sinon
  le double-submit répond 403 avant le pare-feu) ; l'effacement n'est jugé que sur une route qui exige un rôle (un 401
  métier d'une route publique n'est pas une session refusée, CR6-6) ; donc un `#[IsGranted]` posé sur une route sans
  règle ne se voit pas ici, ce qu'`AuthorizationLivesInAccessControlTest` garde. Lignes touchées ≤ 140 caractères
  (St6-13 : les lignes 253 et 272 de R46 coupées ; les justifications de `PUBLIC_PATHS`, antérieures, restent).

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`, nombre de tests et d'assertions noté.
- [ ] **Step 2 : implémenter** selon les interfaces. Run : `docker compose exec -u dev backend php bin/phpunit tests/Security/ApiRouteExposureTest.php`.
  Expected : vert (couverture d'un comportement juste ; si une écriture rougit, c'est un vrai défaut : s'arrêter et
  rendre la question).
- [ ] **Step 3 : rouges vus par mutation** (copie vérifiée par `cmp`, `rm -rf var/cache/test` après chaque) :
  (a) l'attribut `#[AsEventListener(event: Events::JWT_EXPIRED)]` retiré de `StaleSessionCookiesClearingListener` →
  l'invariant rougit pour le libellé `expiré` (Sp6-9) ; (b) le même listener n'efface plus pour une méthode non sûre
  (une condition sur la méthode de la requête en tête de son effacement) → rouge sur une écriture (C6-1 : avant R59
  les écritures prenaient le 403 du CSRF, aucune n'atteignait cet effacement) ; (c) l'effacement construit par
  `Cookie::create($name)->withExpires(1)` au lieu de la fabrique → rouge sur les attributs (C6-10). CR6-6 n'a pas de
  mutation raisonnable (aucune route publique ne répond 401) : le dire.
- [ ] **Step 4 : règle** — la phrase « **Invariant n° 8 of `ApiRouteExposureTest`**: on every route and method, a refused
  `BEARER` (invalid, expired) gets the anonymous status, a 401 of the `api` firewall expires both cookies, and a
  `PUBLIC_PATHS` route sets no cookie the anonymous call does not; » remplacée par « **Invariant n° 8 of
  `ApiRouteExposureTest`**: on every route and method — writes carrying a valid CSRF pair, or the double-submit's 403
  would answer first —, a refused `BEARER` (invalid, expired) gets the anonymous status, a 401 of a route that requires
  a role (`requiresAccess()`) expires both cookies with the factory's exact attributes, and a `PUBLIC_PATHS` route sets
  no cookie the anonymous call does not; » (la suite de la phrase inchangée).
- [ ] **Step 5 : verts** — `make back-test; echo "exit=$?"` (même nombre de tests que le filet), `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `test(security): l'invariant n° 8 voit les écritures et vérifie les attributs de l'effacement, sur les seules routes qui exigent un rôle (#748)`.

---

### Task R60 : Backend — la garde d'autorisation prouvée sur des extraits, élargie, ses exemptions comptées (St6-3, CR6-7/C6-3 pour le code, C6-4, Sp6-4/C6-2)

**Files :**
- Create : `backend/tests/Support/AuthorizationSites.php`
- Modify : `backend/tests/Security/AuthorizationLivesInAccessControlTest.php`

**Interfaces :**
- `final class AuthorizationSites { /** @return list<array{int, string}> */ public static function in(string $code): array }` —
  ligne et nature de chaque site, par jetons (`PhpSources::significantTokens()`). Natures : `IsGranted` (attribut, son
  import ou son nom complet), `denyAccessUnlessGranted`, `security:` (les trois arguments nommés d'API Platform),
  `isGranted` (`->isGranted(`, `?->isGranted(`, **`::isGranted(`**), `refusal` (**`new AccessDeniedException`,
  `new AccessDeniedHttpException`, `new UnauthorizedHttpException`**, nom court ou complet), `decisionManager` (**le
  nom `AccessDecisionManagerInterface`**), `is_granted` (**une chaîne littérale qui contient `is_granted(`**).
- `AuthorizationLivesInAccessControlTest` : `private const array EXEMPTIONS` = `array<class-string, array{string,
  array<string, int>}>` (classe => [raison, nature => nombre exact de sites]) : `NominativeCorpusReader` =>
  [raison actuelle, `['isGranted' => 1]`] (C6-4) ; `CsrfCookieRequestSubscriber` => [« garde du double-submit,
  priorité 20, avant le pare-feu : son 403 refuse une écriture sans jeton, il n'autorise aucune route », `['refusal'
  => 2]`] ; `LoginCsrfRequestListener` => [même raison, login-CSRF, `['refusal' => 1]`].
  `testNoAuthorizationIsDeclaredOutsideAccessControl` : tout site hors exemption échoue ; un fichier exempté dont le
  nombre de sites d'une nature diffère échoue (« attendu n, trouvé m »). `testEveryExemptionIsStillNeeded` remplace
  `testEveryAllowListEntryIsStillNeeded` (le nombre exact, pas seulement « au moins un »).
- Docblock (Sp6-4) : la phrase « l'invariant n° 8 … ne compare que les statuts » remplacée par : « L'invariant n° 8
  d'`ApiRouteExposureTest` ne le verrait pas : il ne juge l'effacement que sur une route qui exige un rôle, et une route
  sans règle n'en exige pas (R59). Cette garde et `ApiPlatformOperationsCarryNoSecurityTest` (les métadonnées
  compilées, R61) sont les seuls témoins de la prémisse. » ; la liste des formes refusées et des limites mise à jour
  (non vus : un appel dynamique, une sous-classe des trois exceptions, le vendor).

- [ ] **Step 1 : extraire** `AuthorizationSites::in()` à comportement constant (les quatre natures d'aujourd'hui) ; le
  test l'appelle. `testTheDetectorSeesEachForm` (fournisseur de données, une ligne par extrait) : `#[IsGranted('X')]`,
  `#[\Symfony\Component\Security\Http\Attribute\IsGranted('X')]`, `use …\IsGranted;`, `$this->denyAccessUnlessGranted('X')`,
  `new Get(security: "x")`, `new Put(securityPostDenormalize: "x")`, `$security->isGranted('X')`,
  `$security?->isGranted('X')` → leur nature ; `testTheDetectorIgnoresWhatIsNotAnAuthorization` : `// #[IsGranted('X')]`,
  `public function isGranted(): bool`, `$consent->decide($d)`, `$a->securityMessage` → aucun site. Run :
  `docker compose exec -u dev backend php bin/phpunit tests/Security/AuthorizationLivesInAccessControlTest.php`. Expected : vert (filet de l'extraction).
- [ ] **Step 2 : rouges** — extraits ajoutés : `Security::isGranted('X')` → `isGranted` ; `throw new AccessDeniedHttpException()`,
  `new \Symfony\Component\Security\Core\Exception\AccessDeniedException()`, `new UnauthorizedHttpException('x')` →
  `refusal` ; `private AccessDecisionManagerInterface $m` → `decisionManager` ; `'is_granted("ROLE_X")'` → `is_granted`.
  Expected : FAIL, aucun site trouvé pour ces extraits.
- [ ] **Step 3 : implémenter** les formes et les exemptions comptées. `testNoAuthorizationIsDeclaredOutsideAccessControl`
  vert sur `src/` (les trois exemptions, aux nombres exacts).
- [ ] **Step 4 : rouge vu par mutation** — un second `->isGranted(` ajouté dans `NominativeCorpusReader` → rouge (« attendu
  1, trouvé 2 ») ; restauré par copie vérifiée par `cmp`.
- [ ] **Step 5 : verts** — `make back-test; echo "exit=$?"`, `make back-quality; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `test(security): la garde d'autorisation prouvée sur des extraits, élargie aux refus levés, aux appels statiques et au gestionnaire de décision ; ses exemptions comptées (#748)`.

---

### Task R61 : Backend — aucune opération d'API Platform ne porte d'expression de sécurité, quelle que soit sa source (CR6-7 pour la configuration, St6-5)

**Files :**
- Create : `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`
- Modify (règles) : `.claude/rules/backoffice-api.md` (« Authorization »), `.claude/rules/security-authentication-sessions.md` (la phrase « the premise … is held by `AuthorizationLivesInAccessControlTest` (C5-12) »)

**Interfaces :**
- `final class ApiPlatformOperationsCarryNoSecurityTest extends KernelTestCase` :
  `testNoOperationCarriesASecurityExpression` — chaque classe de ressource (`ResourceNameCollectionFactoryInterface`),
  chaque opération de sa collection de métadonnées (`ResourceMetadataCollectionFactoryInterface::create()`), comme
  `ApiPlatformStartupRaisesNoDeprecationTest` les parcourt : `getSecurity()`, `getSecurityPostDenormalize()`,
  `getSecurityPostValidation()` valent `null` ; le message nomme la classe, l'opération et l'expression. Un garde-fou :
  au moins une opération parcourue (`assertGreaterThan(0, …)`). Docblock : pourquoi (la prémisse de R35, comme
  `AuthorizationLivesInAccessControlTest`), ce que celle-ci voit de plus (YAML, XML, toute fusion d'API Platform), ce
  qu'elle ne voit pas (une autorisation hors API Platform : l'autre garde).

- [ ] **Step 1 : test** — écrit, vert d'emblée (aucune expression aujourd'hui). **Rouge vu par mutation** : `security:
  "is_granted('ROLE_USER')"` ajouté à une opération d'une ressource publique (attribut ; la garde de jetons de R60
  rougit aussi, le noter) → rouge, la classe et l'expression nommées ; restauré par copie vérifiée par `cmp`,
  `rm -rf var/cache/test`.
- [ ] **Step 2 : règles** — `backoffice-api.md`, « Authorization » : après la première phrase (« a single
  `access_control` entry … »), **une** phrase : « **Every authorization of `/api` is an `access_control` rule** — never
  a `#[IsGranted]`, a `denyAccessUnlessGranted`, an `isGranted()` nor an API Platform `security:` (attribute, YAML or
  XML): `PublicRouteIgnoresRefusedSessionListener` reads « public route » as « route without a rule » (#748 R35); held
  by `AuthorizationLivesInAccessControlTest` (tokens) and `ApiPlatformOperationsCarryNoSecurityTest` (compiled
  metadata), details in `security-authentication-sessions.md`. » `security-authentication-sessions.md` : « is held by
  `AuthorizationLivesInAccessControlTest` (C5-12) » → « is held by `AuthorizationLivesInAccessControlTest` and
  `ApiPlatformOperationsCarryNoSecurityTest` (C5-12, R60, R61) » ; la phrase « Pinned by … » de la même puce gagne le
  second nom.
- [ ] **Step 3 : verts** — `make back-test; echo "exit=$?"` (filet + 1), `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"` ; taille des deux règles notée. *(rectifié le 2026-10-30, septième relecture : la classe compte sept tests, un parcours et six cas de fournisseur : « filet + 7 » — Sp7-4)*
- [ ] **Step 4 : commit** — `test(security): aucune opération d'API Platform ne porte d'expression de sécurité, attribut, YAML ou XML ; l'invariant écrit dans backoffice-api.md (#748)`.

---

### Task R62 : Outillage — une bibliothèque invalide se dit, le `trap` d'`audit-prod.sh` avant son fichier, le `Retry-After` en extrait (C6-7, C6-8, C6-12)

**Files :**
- Modify : `tools/audit-prod.sh`, `tools/smoke-dead-session-cookie.sh`, `tools/smoke-login-throttling.sh`, `tools/smoke-mcp.sh`
- Test : `tools/tests/smoke-dead-session-cookie.test.sh`, `tools/tests/smoke-login-throttling.test.sh`
- *(rectifié le 2026-10-30, septième relecture : R62 a aussi créé `tools/tests/audit-prod.test.sh` et touché `.github/workflows/pipeline.yml` et `.claude/rules/deploiement-nginx.md` — Sp7-3)*

**Interfaces :**
- Chaque `source "…/lib/<x>.sh" 2> /dev/null || { echo "bibliothèque introuvable : tools/lib/<x>.sh" >&2; exit 2; }`
  (les quatre scripts, et les deux autres bibliothèques d'`audit-prod.sh`) devient
  `source "…/lib/<x>.sh" || { echo "bibliothèque introuvable ou invalide : tools/lib/<x>.sh" >&2; exit 2; }` : l'erreur
  de bash (syntaxe, ligne) reste sur stderr. Les commentaires voisins disent pourquoi (C6-7).
- `audit-prod.sh` : `trap 'rm -f "${CURL_AUTH_CONFIG:-}"' EXIT` posé **avant** la création du fichier ; `smoke-http.sh`
  sourcé **dans** le bloc `if [ -n "${AUDIT_BASIC_AUTH:-}" ]`, seul endroit qui s'en sert.
- `smoke-login-throttling.sh:113` : `« $(smoke_excerpt "$retry_after") »` à la place de `« $retry_after »`.

- [ ] **Step 1 : tests hors ligne, rouges**
  - `smoke-dead-session-cookie.test.sh` : « bibliothèque invalide » — le script copié dans `$TMP/tools/` avec un
    `$TMP/tools/lib/smoke-http.sh` à erreur de syntaxe (`if then`) → sortie 2, stderr contient `introuvable ou invalide`
    **et** l'erreur de bash (`syntax error`). Expected : stderr dit seulement « introuvable », sans l'erreur.
  - `smoke-login-throttling.test.sh` : « 429 avec un `Retry-After` qui porte un `\r` » → sortie 1, aucun `\r` dans
    stderr (`grep -c $'\r'` vaut 0). Expected : le `\r` présent. *(rectifié le 2026-10-30, septième relecture : ce rouge ne pouvait venir du `\r`, que `header()` retirait déjà ; le défaut que `smoke_excerpt` corrige est celui des autres caractères de contrôle — Sp7-2)*
  Run : `bash tools/tests/smoke-dead-session-cookie.test.sh; echo "exit=$?"` et `bash tools/tests/smoke-login-throttling.test.sh; echo "exit=$?"`.
- [ ] **Step 2 : implémenter** selon les interfaces. *(rectifié le 2026-10-30, septième relecture : la phrase qui suit est fausse, R62 a créé `tools/tests/audit-prod.test.sh` (faux `dig`, `curl`, `openssl`), branché dans le job `tools-tests` — Sp7-3)* `audit-prod.sh` n'a pas de test hors ligne : mesure à la main,
  `AUDIT_BASIC_AUTH=$'u:p\nx' tools/audit-prod.sh https://example.invalid; echo "exit=$?"` → 2 sans requête, et
  `tools/audit-prod.sh https://example.invalid; echo "exit=$?"` sans `AUDIT_BASIC_AUTH` → la bibliothèque n'est pas
  sourcée (`bash -x` : aucune ligne `source …smoke-http.sh`) ; noter les deux sorties.
- [ ] **Step 3 : verts** — les quatre tests hors ligne (`smoke-dead-session-cookie`, `smoke-login-throttling`,
  `smoke-mcp`, `smoke-http`) à 0, shellcheck (image épinglée) sur les quatre scripts et les tests touchés,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 4 : commit** — `fix(tools): une bibliothèque invalide se dit, le trap d'audit-prod précède son fichier, le Retry-After passe en extrait (#748)`.

---

### Task R63 : Un refus du backoffice est jugé sur le compte, pas sur la génération (décision 2 : CR6-5)

**Files :**
- Modify : `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/application/auth/useAuth.ts`
- Test : `frontend/tests/presentation/router/backofficeSessionExpiry.spec.ts`, `frontend/tests/application/auth/useAuth.spec.ts`, `frontend/tests/infrastructure/admin/shared/BackofficeHttpClient.spec.ts`, `frontend/tests/infrastructure/admin/users/HttpAdminUserRepository.spec.ts` (timbres construits), `frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts`
- Modify (règles) : `.claude/rules/frontend-session-refus.md` (« The backoffice reaction », la parenthèse du timbre ; la puce du backoffice, la phrase « a refusal sent under another session generation counts and re-reads nothing (R48, CR5-2), read or write » ; « Tests », la mention R48 de `backofficeSessionExpiry.spec.ts`), `.claude/rules/frontend-session-retour.md` (« The session generation », la parenthèse d'`applyLiveSession`)

**Interfaces :**
- `BackofficeSendStamp` gagne `readonly account: string | null` — docblock : le dernier compte affiché à l'envoi
  (identifiant), `null` sans compte retenu ; un refus n'est écarté que si ce compte a changé (R63, décision 2 de la
  sixième relecture). Le docblock de `refused()` du même fichier le dit.
- `useAuth.ts` : `export function lastAccountName(): string | null` (`getLastAccount()?.username ?? null`, une
  lecture : hors de la table `WRITERS`).
- `backofficeSessionExpiry.ts` : `stampAtSend` pose `account: lastAccountName()` ; `refused()` : `if (sentAt.account !==
  lastAccountName()) return` **à la place** du contrôle de génération (commentaire : décision 2, et pourquoi la
  génération ne juge plus que `ended()`) ; l'import d'`isCurrentGeneration` retiré s'il n'a plus d'usage ; le docblock
  de tête : la phrase « sauf si la session a changé depuis l'envoi (R48, CR5-2) … lecture ou écriture » remplacée par
  « sauf si le compte a changé depuis l'envoi (R63, décision 2 de la sixième relecture) : un autre compte, ou aucun ;
  le même compte reste dit après une fin puis un retour, ou une bascule de palier ».

- [ ] **Step 1 : tests, rouges**
  - `backofficeSessionExpiry.spec.ts` : le `it.each` R48 (« partie sous une autre génération n'est ni compté ni relu »)
    **remplacé** (changement exprès) par : `it.each(['read', 'write'])("le même compte, génération avancée depuis l'envoi (fin puis retour) : le refus (%s) est compté et relu")`
    (compte A appliqué, `stampAtSend(kind)`, `markSessionExpired()` puis retour du même compte par `signInAgain()`,
    `refused(stamp)` → compteur à 1, `rereadAfterRefusal` appelé ; aucune alerte n'est montée dans ce spec, le
    périmètre ne bouge donc pas à la fin : seul le compte juge, ce que le test veut isoler) ; `it.each(['read', 'write'])("un autre compte depuis l'envoi : le refus (%s) n'est ni compté ni relu")` ;
    `it("aucun compte retenu depuis l'envoi (accès de base obtenu) : le refus d'une écriture n'est ni compté ni relu")`.
  - `useAuth.spec.ts` : `it("lastAccountName suit le dernier compte affiché : gardé à l'anonyme, oublié par logout")`.
  - `AdminLayout.sessionEnded.spec.ts` (Review Focus 2) : `it("le même compte rétabli entre l'envoi et le 403 d'un enregistrement : l'alerte s'affiche")`.
  Expected : compteur à 0 et relecture non appelée sous le même compte ; `lastAccountName` introuvable.
- [ ] **Step 2 : implémenter** — selon les interfaces ; les specs qui construisent un timbre gagnent `account`
  (changement de signature). Le test R48 « après un changement de compte, le 403 tardif … n'affiche rien » reste vert
  sans changement.
- [ ] **Step 3 : règles** — `frontend-session-refus.md` : « the send stamp `{ session, refusal }` » → « the send stamp
  `{ session, account, refusal }` » ; « a refusal sent under another session generation counts and re-reads nothing (R48,
  CR5-2), read or write » → « a refusal whose stamp names another account than the page's now (`account`, the last
  account displayed, R63, decision 2 of the sixth review) counts and re-reads nothing, read or write; the same account's
  403 stays said across an end and a return, or a tier change » ; « Tests » : « a refusal sent under another generation
  counts nothing (`backofficeSessionExpiry.spec.ts`) » → « a refusal is dropped only for another account, the same
  account's counts across an end and a return (`backofficeSessionExpiry.spec.ts`, R63) ». `frontend-session-retour.md` :
  « (`applyLiveSession`, R48, CR5-2: before `onAccountSwitched`, so what left under the first account is stale) » →
  « (`applyLiveSession`, R48, CR5-2: before `onAccountSwitched`, so a 401 that left under the first account does not end
  the second's session; a refusal is judged on the account, R63) ».
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `fix(frontend): un refus du backoffice n'est écarté que si le compte a changé, plus pour une génération (#748)`.

---

### Task R64 : Le montage de l'alerte dit l'enregistrement refusé pendant l'absence ; le périmètre et la navigation permutée décrits juste (décision 1 : CR6-3 ; St6-2/Sp6-1 ; St6-1/Sp6-2)

**Files :**
- Modify : `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue`, `frontend/src/presentation/router/backofficeSessionExpiry.ts` (docblock), `frontend/src/infrastructure/i18n/locales/{fr,en}.json`
- Test : `frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-refus.md` (la puce « **The backoffice does the same, once for all its pages** », **récrite d'un bloc** ; « Tests », première puce)

**Interfaces :**
- `backofficeAccessRefusal.ts` : `export const earlierWriteRefused = readonly(earlierWrite)` (`Ref<boolean>`) ;
  `let writeCountedSinceClear = false` ; `markBackofficeAccessRefused(sentIn)` : quand il compte, `earlierWrite.value =
  false` et, pour une écriture, `writeCountedSinceClear = true` ; `export function settleBackofficeAccessRefusalsOnMount(): void`
  **remplace** `discardBackofficeAccessRefusals` : `writeCountedSinceClear` → `earlierWrite.value = true` (le compteur
  garde sa valeur) ; sinon `refusals.value = 0` ; le périmètre n'avance pas. `clearBackofficeAccessRefusal()` et
  `resetBackofficeAccessRefusalForTests()` remettent aussi les deux à faux.
- Docblocks (St6-2, St6-1) : `scope` — « avance à chaque effacement (`clearBackofficeAccessRefusal`), et là seulement :
  un changement de chemin ou de requête (pas le seul fragment, R38), une fin de session, le démontage de l'alerte. Le
  montage n'avance rien (R64). Une navigation vers l'adresse courante n'est pas exécutée par vue-router ; une
  permutation des paramètres de requête l'est (R54, CR5-8), mais la clé triée de l'alerte n'y voit pas de changement :
  ni l'une ni l'autre n'avance le périmètre. » ; l'en-tête du module et celui de `settle…` disent la décision 1 ;
  `backofficeSessionExpiry.ts` : « (chemin ou requête changés, fin de session, montage, démontage) » → « (chemin ou
  requête changés, fin de session, démontage) ».
- `AccessRefusedNotice.vue` : `const isMounted = ref(false)` ; `isShown = isMounted.value && backofficeAccessRefused.value
  > 0 && !authState.sessionEnded` ; `onMounted(() => { settleBackofficeAccessRefusalsOnMount(); isMounted.value = true })` ;
  le message `{{ earlierWriteRefused ? t('common.earlierSaveRefused') : t('common.accessRefused') }}` dans le `<span>` à
  `key`. Docblock, la puce « Effacée… » récrite : les effacements (chemin, requête aux paramètres triés, fin de session,
  démontage) ; le montage règle ce qui a été compté sans alerte (décision 1, amende Q1 de la cinquième série) ;
  l'insertion après le montage, et pourquoi (`frontend-a11y.md` : une région présente au premier rendu peut ne pas être
  annoncée).
- i18n : `common.earlierSaveRefused`, FR et EN, le texte de Q1 (recommandation).

- [ ] **Step 1 : tests, rouges**
  - `AccessRefusedNotice.spec.ts` : le test R48 « un refus d'écriture arrivé après le démontage ne s'affiche pas au
    montage suivant » **remplacé** (changement exprès, décision 1) par `it("un refus d'écriture arrivé après le démontage s'affiche au montage suivant, avec son propre texte")`
    (texte exact FR ; après `await nextTick()`, pas au premier rendu) ; `it("un refus de lecture compté sans alerte est oublié au montage")` ;
    `it("un nouveau refus sur la page remplace le texte de l'enregistrement précédent par celui du refus courant")` ;
    `it("une navigation efface l'alerte de l'enregistrement précédent")` ; `it("le texte de l'enregistrement précédent en anglais")` ;
    l'axe-core de l'alerte affichée.
  - CR5-8 (St6-1) : le test « une requête aux paramètres permutés garde l'alerte : le routeur n'y voit pas de navigation »
    renommé `"une requête aux paramètres permutés garde l'alerte : vue-router exécute la navigation, la clé triée l'ignore (R54, CR5-8)"`,
    son commentaire récrit (vue-router compare la requête dans l'ordre : le test était rouge avant le tri) et une
    assertion de plus : `router.currentRoute.value.fullPath` vaut `'/fr/admin/case-studies?b=2&a=1'`.
  - `backofficeAccessRefusal.spec.ts` : le test de `discard…` remplacé par ceux de `settle…` (écriture comptée → compteur
    gardé et `earlierWriteRefused` vrai ; lecture seule → compteur à 0 ; le périmètre inchangé dans les deux cas).
  Expected : alerte absente au remontage ; `settleBackofficeAccessRefusalsOnMount` introuvable ; le test CR5-8 renommé
  vert d'emblée (son assertion nouvelle épingle le fait établi).
- [ ] **Step 2 : implémenter** — selon les interfaces.
- [ ] **Step 3 : règle** — `frontend-session-refus.md`, la puce du backoffice **remplacée d'un bloc** par (St6-2 : une
  description des déclencheurs, une seule) :

  « - **The backoffice does the same, once for all its pages** (#748, R17, F4). `BackofficeHttpClient` calls the
    reaction's `refused()` on a 403 that is not an ended session (a read, or a write that carried its token) and
    **raises `AccessForbiddenError`**; `createBackofficeSessionReaction(useAuth())` (built by `main.ts` with
    `app.runWithContext`, the one place that has the `AuthRepository`: the thirteen composables never call `useAuth()`)
    counts the refusal (`backofficeAccessRefused`) and calls `rereadAfterRefusal()`, so a read and a write refused
    together send one `/api/me`. **What a refusal must still concern to count** is read on the send stamp
    `{ session, account, refusal }`:
    - **the account** (R63, decision 2 of the sixth review): `account` is the last account displayed at send time; a
      refusal is dropped — neither counted nor re-read, read or write — only when that account has changed (another
      account, or none after the base access). The same account's 403 stays said across an end and a return, or a tier
      change; the generation judges `ended()` only.
    - **the scope, for a read** (R28, CR3-8, R38): `get` stamps `{ kind: 'read', scope: refusalScope() }`, `mutate`
      `{ kind: 'write' }`. The scope advances at every clearing of the alert, and only there: a path or query change
      (the query's parameters sorted: vue-router runs a navigation that only permutes them, the alert ignores it, R54;
      not the fragment alone: the skip link), an ended session, the alert's unmount. A read that left under another
      scope came from a page left: `markBackofficeAccessRefused(sentIn)` answers `false`. A write carries no scope: the
      person made the gesture and must learn it failed.
    - **the alert's mount advances nothing and settles what was counted without an alert** (R64, decision 1 of the sixth
      review, amending Q1 of the fifth): a read's refusal is forgotten (the tier was re-read), a **write**'s is shown with
      its own text, `common.earlierSaveRefused` (« Un enregistrement précédent a été refusé par le serveur… »), the alert
      inserted after the mount so that it is announced; the next refusal on the page brings back `common.accessRefused`.
    `useAdminLoad` (no `hasError`, nothing re-read on return) and `visibleMutationError` (`null`, the mutation resolves
    `false`, the input stays) keep it quiet like `SessionEndedError`; **one alert**, `AccessRefusedNotice` in
    `AdminLayout` next to the banner, says it with « Changer de compte » in **the same tab** (the author's decision of
    2026-10-29: as R7, accepting that following it loses what is typed). It is shown while the count is above 0 **and**
    the session has not ended (a re-read that finds the session ended leaves the banner alone); a further refusal
    replaces the message (the `key` is on the `<span>`, the link is never destroyed). `admin.notLoaded` is neutral
    (« see the message at the top of the page »): a page whose read was refused is not « session ended ». »

  « Tests », première puce : « a write refused after the layout unmounted does not show at the next mount
  (`AccessRefusedNotice.spec.ts`) » → « a write refused after the layout unmounted shows at the next mount with
  `common.earlierSaveRefused`, a read's is forgotten, R64 (`AccessRefusedNotice.spec.ts`, `backofficeAccessRefusal.spec.ts`) » ;
  « the same parameters in another order keep it, R54 » → « the same parameters in another order keep it — vue-router
  runs that navigation, the sorted key ignores it, R54 ».
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ; taille de la règle notée.
- [ ] **Step 5 : commit** — `fix(frontend): l'enregistrement refusé pendant l'absence est dit au retour dans le backoffice ; le périmètre et la navigation permutée décrits juste (#748)`.

---

### Task R65 : `checkAuth()` n'avale que le rejet de `/api/me` (CR6-4/St6-11, CR6-8)

**Files :**
- Modify : `frontend/src/application/auth/useAuth.ts`
- Test : `frontend/tests/application/auth/useAuth.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-retour.md` (« The re-check on return », la phrase « The bound holds for the first check too: `checkAuth()` swallows the rejection like an anonymous answer, … »)

**Interfaces :**
- `checkAuth` : un `try { session = await repository.me() } catch { return }` autour du seul appel réseau (le `return`
  passe par le `finally` extérieur, `markFirstCheckDone()`) ; le reste hors de tout `catch`. Docblock (CR6-8) : « La
  vérification de la session au chargement (`main.ts`, son seul appelant). » ; ce qui est avalé (le rejet de `me()` :
  réseau, abandon après `ME_TIMEOUT_MS`) et ce qui ne l'est pas (une erreur levée après la réponse : elle remonte, la
  première vérification finit quand même) ; pourquoi `main.ts` ne l'attrape pas (une erreur de programmation doit se voir).

- [ ] **Step 1 : test, rouge** — `it("checkAuth : une erreur levée après la réponse de /api/me remonte, et la première vérification finit quand même")`
  (`hasWriteToken` simulé qui lève `new TypeError('bug')`, session finie posée par `markSessionExpired()`, `me()` rend un
  compte → `await expect(checkAuth()).rejects.toThrow('bug')`, puis `waitForAuthCheck()` se résout et `isChecking` est
  faux). Le test R49 « un me() rejeté termine la vérification en anonyme, sans rejet non géré » reste vert sans
  changement. Expected : la promesse se résout (l'erreur avalée).
- [ ] **Step 2 : implémenter** — selon les interfaces.
- [ ] **Step 3 : règle** — « `checkAuth()` swallows the rejection like an anonymous answer » → « `checkAuth()` swallows a
  rejection of `/api/me` only, like an anonymous answer (an error raised after the response surfaces, the first check
  still ends, R65) ».
- [ ] **Step 4 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `fix(frontend): checkAuth n'avale que le rejet de /api/me, une erreur de programmation remonte (#748)`.

---

### Task R66 : Réglages « À propos » — l'avis de proposition n'est que celui de sa langue cible, et part à sa relecture (décision 4 : CR6-1/Sp6-5 ; St6-10)

**Files :**
- Modify : `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue`
- Test : `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (la phrase « **A proposal notice survives a refusal, and the save of another locale** (F11, R50): … »)

**Interfaces :**
- `const proposal = ref<{ readonly source: Locale; readonly target: Locale } | null>(null)` remplace
  `proposalSourceLocale` et `proposalTargetLocale` (St6-10) ; `applyPendingProposal` pose `{ source, target }`.
- Avis : `v-if="proposal && proposal.target === locale"`, texte `{ locale: proposal.source.toUpperCase() }`.
- `watch(settings, …, { flush: 'sync' })` : après la copie, `if (proposal.value?.target === props.locale) proposal.value =
  null` (commentaire : décision 4, toute relecture serveur de la langue cible perd la proposition ; la proposition en
  attente se pose après, au `watch` d'`isLoaded`). `handleSubmit` perd son effacement (la réponse écrite par `write`
  passe par ce `watch` ; un refus ne relit rien, F11). Le docblock de l'assistant de traduction du fichier dit la règle.

- [ ] **Step 1 : tests, rouges** (Review Focus 3) — `it("une proposition EN, passage en FR : aucun avis sous le FR")` ;
  `it("une proposition EN, passage en FR puis retour en EN : les réglages EN relus, l'avis a disparu")` ;
  `it("une proposition EN, enregistrement EN refusé : l'avis reste")` (F11, vert attendu : témoin). Les tests R50 2b
  restent verts ; si l'un affirme l'avis visible sous une autre langue que sa cible, il change exprès (le dire).
  Expected : l'avis présent sous le FR ; l'avis présent au retour en EN.
- [ ] **Step 2 : implémenter** — selon les interfaces.
- [ ] **Step 3 : règle** — la phrase « **A proposal notice survives a refusal, and the save of another locale** (F11, R50):
  it belongs to the locale the proposal landed on (`proposalTargetLocale`), and only a `true` save of **that** locale
  clears it, the form still carrying the proposal otherwise. » remplacée par « **A proposal notice is its target
  locale's** (F11, R50, R66, decision 4 of the sixth review): `proposal` (`{ source, target }`) shows under the form of
  `target` only, survives a refused save, and goes whenever that locale's form is re-read from the server — the response
  of its successful save, a reload, a return to it —, which loses the proposal. »
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `fix(frontend): réglages « À propos », l'avis de proposition n'est que celui de sa langue cible et part à sa relecture (#748)`.

---

### Task R67 : Réglages « À propos » — « Proposer la traduction » attend l'enregistrement de la langue cible (décision 3 : CR6-2 ; St6-17)

**Files :**
- Modify : `frontend/src/application/admin/about/useAdminAboutSettings.ts`, `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue`, `frontend/src/presentation/ui/admin/TranslateEntryButton.vue`, `frontend/src/domain/portfolio/entities/Locale.ts`
- Test : `frontend/tests/application/admin/about/useAdminAboutSettings.spec.ts`, `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts`, le spec de `Locale.ts` s'il existe (sinon `frontend/tests/domain/portfolio/entities/Locale.spec.ts`)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (la phrase « **One save per locale in flight** (R50, …) … is gone. »)

**Interfaces :**
- `export function otherLocale(locale: Locale): Locale` (`Locale.ts` ; l'autre des `SUPPORTED_LOCALES`, la première par
  défaut, comme le `find` d'aujourd'hui) ; `TranslateEntryButton` l'utilise (`targetLocale = computed(() =>
  otherLocale(props.formLocale))`, refactoring : ses tests inchangés).
- `UseAdminAboutSettingsResult` gagne `isSavingLocale: (locale: Locale) => boolean` (lit `saving`).
- `AdminAboutSettingsForm.vue` : `TranslateEntryButton` `:disabled="!hasProseToTranslate || isSaving ||
  isSavingLocale(otherLocale(locale)) || isLocked"` ; commentaire : décision 3 (la réponse de la cible recouvrirait la
  proposition), et la conséquence acceptée du choix n° 3.

- [ ] **Step 1 : tests, rouges**
  - `useAdminAboutSettings.spec.ts` : `it("isSavingLocale dit l'enregistrement en vol de chaque langue, quelle que soit la langue montrée")`.
  - `AdminAboutPage.spec.ts` (Review Focus 4) : `it("un enregistrement EN en vol, la page en FR : « Proposer la version EN » désactivé jusqu'à la réponse, « Enregistrer » FR actif")`.
  - `otherLocale('fr') === 'en'`, `otherLocale('en') === 'fr'`.
  Expected : `isSavingLocale` et `otherLocale` introuvables ; le bouton actif.
- [ ] **Step 2 : implémenter** — selon les interfaces.
- [ ] **Step 3 : règle** — la phrase R50 (« **One save per locale in flight** … R43's `lastSaveOf` had nothing left to
  arbitrate and is gone. ») **remplacée** par (St6-17 : sans l'historique) : « **One save per locale in flight** (R50,
  the author's decision of 2026-10-30, CR5-3/CR5-4): `save(locale)` refuses a second save of a locale while its first is
  in flight (no request, `false`), so the response that arrives is always the only one of its locale. The form disables
  « Enregistrer » for the locale shown only (`isSaving`: an English save is not held by a French one), and « Proposer la
  traduction » while the locale shown **or the target locale** has a save in flight (`isSavingLocale`, R67, decision 3 of
  the sixth review: the target's response would overwrite the proposal it lands on; a save of the target started while
  the translation runs still does, and the notice goes with it, R66). »
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ; taille de la règle notée.
- [ ] **Step 5 : commit** — `fix(frontend): réglages « À propos », « Proposer la traduction » attend l'enregistrement de la langue cible (#748)`.

---

### Task R68 : Un lecteur de source qui connaît les chaînes ; un faux `fetch` écrit une fois ; rien d'exporté des specs (Sp6-11/C6-6, St6-9)

**Files :**
- Create : `frontend/tests/support/sourceText.ts`, `frontend/tests/support/sourceText.spec.ts`, `frontend/tests/support/abortableFetch.ts`
- Modify : `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts`, `frontend/tests/infrastructure/auth/HttpAuthRepository.spec.ts`, `frontend/tests/presentation/router/adminGuard.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session.md` (« Tests », la puce `noOmittedCredentials.spec.ts`)

**Interfaces :**
- `sourceText.ts` : `export function withoutComments(source: string): string` — parcourt le texte caractère par
  caractère, garde tels quels les littéraux `'…'`, `"…"` et les gabarits `` `…` `` (échappements compris, `${…}`
  imbriqués suivis par profondeur), remplace un commentaire `//…` ou `/*…*/` par un espace (les fins de ligne gardées) ;
  `export function stringLiterals(source: string): string[]` — le contenu de chaque littéral (gabarits : les seuls sans
  `${`). Docblock : les limites (une regex littérale qui contiendrait `//` ou une quote non échappée dans une classe de
  caractères, un `.vue` lu comme du texte : son `<template>` n'a pas de commentaire JS).
- `abortableFetch.ts` : `export function fetchSettledOnlyByAbort(): typeof fetch` — ne résout jamais, rejette
  `new DOMException('The operation was aborted.', 'AbortError')` quand le `signal` passé est abandonné ; les deux specs
  l'utilisent.
- `offendsWithOmit(source)` : `stringLiterals(source).includes('omit')` ; `namesImportedFromSessionState` et
  `namesInExportBlocks` lisent `withoutComments(source)` ; les trois **ne sont plus exportées**.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : tests, rouges** — `noOmittedCredentials.spec.ts` : `expect(offendsWithOmit("const a = '/*'\nconst mode = 'omit' // */")).toBe(true)` ;
  `expect(offendsWithOmit("const u = 'http://x/omit'")).toBe(false)`. Expected : FAIL, faux (la regex avale `'omit'`
  entre `/*` et `*/`). `sourceText.spec.ts` : `withoutComments` garde `'http://x'`, `"/*"`, `` `a ${'//'} b` `` ; retire
  `// c` et `/* c */` ; `stringLiterals("f('a', \"b\", `c`, `d${x}`)")` vaut `['a', 'b', 'c']`.
- [ ] **Step 3 : implémenter** — selon les interfaces ; les deux faux `fetch` remplacés par `fetchSettledOnlyByAbort()`
  (aucun autre test ne change).
- [ ] **Step 4 : règle** — puce `noOmittedCredentials.spec.ts` : « (no file of `src/` writes a string `'omit'` outside a
  comment — typed or in the options object —, …) » → « (no file of `src/` holds a string literal `'omit'` — typed or in the
  options object; read by `tests/support/sourceText.ts`, which knows strings from comments, R68 —, …) ».
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"` (filet + les nouveaux), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `test(frontend): un lecteur de source qui connaît les chaînes pour les gardes, un faux fetch écrit une fois, rien d'exporté des specs (#748)`.

---

### Task R69 : La garde des écritures de l'état de session ferme ses contournements (C6-5)

**Files :**
- Modify : `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-retour.md` (« Where it lives », la parenthèse « (… `sessionStateEncapsulation.spec.ts`, R52) »)

**Interfaces :**
- Le relevé : le chemin finit par `sessionState` suivi de `(?:\.[cm]?[jt]s)?` ; `import(` suivi d'autre chose qu'un
  littéral `'…'`/`"…"` (un gabarit, une variable) et tout `import.meta.glob` comptent comme le joker `ALL`.
- `it("aucun module de src/ autre que sessionState n'exporte une écriture, sous aucun nom")` : `import.meta.glob(['../../../src/**/*.ts',
  '!../../../src/main.ts'], { eager: true })` ; pour chaque module autre que `application/auth/sessionState.ts`, aucune
  valeur exportée n'est `===` à une fonction de `WRITERS` (lues sur le module `sessionState`) ; le message nomme le
  module et l'export. Un module qu'on ne peut charger sous jsdom est exclu par son nom, avec sa raison, et le rendu le dit.
- Docblock du spec : les limites mises à jour (les `.vue` hors du contrôle par identité ; `main.ts` exclu, il monte
  l'application).

- [ ] **Step 1 : tests, rouges** — `namesImportedFromSessionState("import { raiseSessionEnded } from './sessionState.js'")`
  vaut `['raiseSessionEnded']` ; `` namesImportedFromSessionState("const m = await import(`./session${'State'}`)") `` et
  `namesImportedFromSessionState("const m = import.meta.glob('./*.ts')")` valent `[ALL]`. Expected : `[]`.
- [ ] **Step 2 : implémenter** le relevé et le contrôle par identité. **Rouge vu par mutation** du second :
  `export const raise = raiseSessionEnded` ajouté dans `sessionLifecycle.ts` (importeur autorisé : la table reste verte)
  → rouge, le module et l'export nommés ; restauré par copie vérifiée par `cmp`. *(rectifié le 2026-10-30, septième relecture : la mutation faite a porté sur `lowerSessionEnded`, pas `raiseSessionEnded` ; même preuve, l'autre écriture du même importeur — Sp7-4)*
- [ ] **Step 3 : règle** — « (… each write is imported by its own modules only (…) and `useAuth.ts` re-exports none of
  them: `sessionStateEncapsulation.spec.ts`, R52) » → « (… and no other module of `src/` exports one, under any name —
  checked by identity on the loaded modules: `sessionStateEncapsulation.spec.ts`, R52, R69) ».
- [ ] **Step 4 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `test(frontend): la garde des écritures de l'état de session voit les suffixes, les imports dynamiques et toute réexportation (#748)`.

---

### Task R70 : Backend — un `putTheBearer` écrit une fois, le trait déclare son parent, le docblock de `requiresAccess()` lisible (St6-4, St6-6, St6-8, St6-14 ; refactoring)

**Files :**
- Create : `backend/tests/Support/PutsTheBearerCookie.php`
- Modify : `backend/tests/Support/RefusedBearerCookie.php`, `backend/tests/Security/Authentication/Infrastructure/Jwt/JwtClockSkewTest.php`, `backend/tests/Security/Authentication/SessionBeforePasswordChangeTest.php`
- Modify : `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php` (docblock seul)
- Modify (règle) : `.claude/rules/security-authentication-sessions.md` (`paths:` : `backend/tests/Support/PutsTheBearerCookie.php`)

**Interfaces :**
- `trait PutsTheBearerCookie { private static function putTheBearer(KernelBrowser $client, string $jwt): void }` (le
  corps d'aujourd'hui) ; `RefusedBearerCookie` l'utilise et perd sa copie ; `JwtClockSkewTest` utilise
  `PutsTheBearerCookie` seul (St6-6).
- `RefusedBearerCookie` : `@phpstan-require-extends KernelTestCase` (St6-4) ; docblock au présent (« le JWT expiré, les
  deux routes témoins, la vérification de l'effacement ; la pose du cookie vient de `PutsTheBearerCookie` »), sans
  « Quatre tests le refaisaient chacun ».
- `SessionBeforePasswordChangeTest` : `putTheBearer(string $jwt)` renommé `replaceTheSignedInBearer(string $jwt)`,
  docblock : il recopie les attributs du cookie posé par la vraie connexion — ce qui le distingue de l'aide commune,
  aux attributs fixes (St6-8).
- Docblock de `requiresAccess()` (St6-14) : « Publique pour ses deux lecteurs de test : `PublicRouteIgnoresRefusedSessionTest`
  la compare à `AccessListener::supports()` sur chaque route, et l'invariant n° 8 d'`ApiRouteExposureTest` n'y juge
  l'effacement que là où elle répond vrai (R59). Une lecture de l'`AccessMap` : aucun état modifié. »

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`, nombre de tests noté.
- [ ] **Step 2 : refactorer** — selon les interfaces ; `make back-style-fix` après l'import ajouté.
- [ ] **Step 3 : règle** — `paths:` gagne `backend/tests/Support/PutsTheBearerCookie.php` (**`git add` avant `check-claude-rules`**).
- [ ] **Step 4 : verts** — `make back-test; echo "exit=$?"` (même nombre que le filet), `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `refactor(security): un putTheBearer écrit une fois, le trait des cookies refusés déclare son parent, le docblock de requiresAccess lisible (#748)`.

---

### Task R71 : Outillage — la Basic Auth, l'en-tête et le binaire `curl` des scripts de fumée écrits une fois (St6-12, C6-9 pour le nom ; refactoring)

**Files :**
- Modify : `tools/lib/smoke-http.sh`, `tools/smoke-dead-session-cookie.sh`, `tools/smoke-login-throttling.sh`, `tools/smoke-mcp.sh`, `tools/audit-prod.sh`
- Test : `tools/tests/smoke-http.test.sh` (et les trois tests de scripts si leur faux `curl` change de variable : non, ils posent déjà `SMOKE_CURL`)
- *(rectifié le 2026-10-30, septième relecture : R71 a aussi touché `tools/check-tls-expiry.sh`, `tools/tests/tls-certificate.test.sh`, `tools/tests/audit-prod.test.sh`, `.claude/rules/deploiement-nginx.md` et `.claude/rules/deploiement.md` — Sp7-3)*

**Interfaces :**
- `smoke_auth_config_file <nom de variable> <valeur>` : le message de refus nomme `<nom>` (C6-9) ; le reste inchangé.
- `smoke_add_basic_auth <nom de variable>` : si `${!nom}` est vide, rien ; sinon `SMOKE_AUTH_CONFIG="$(smoke_auth_config_file
  "$nom" "${!nom}")" || return 2`, puis `SMOKE_CURL_OPTS+=(-K "$SMOKE_AUTH_CONFIG")`. L'appelant pose `trap 'rm -f
  "$headers_file" ${SMOKE_AUTH_CONFIG:+"$SMOKE_AUTH_CONFIG"}' EXIT` **avant**, puis `smoke_add_basic_auth AUDIT_BASIC_AUTH || exit 2`.
- `smoke_header <fichier> <nom>` : le `header()` des deux scripts ; ceux-ci l'appellent avec `"$headers_file"`.
- `smoke_fetch` lit `${SMOKE_CURL:-curl}` ; `SMOKE_CURL_BIN` et ses trois affectations disparaissent ; `smoke-http.test.sh`
  passe à `SMOKE_CURL`.
- `audit-prod.sh` : `smoke_auth_config_file AUDIT_BASIC_AUTH "$AUDIT_BASIC_AUTH"` (il garde son `CURL_AUTH_CONFIG`, hors
  de `SMOKE_CURL_OPTS`).
- `fetch()` et `request()` restent (choix n° 12).

- [ ] **Step 1 : filet** — les quatre tests hors ligne à 0, sorties notées.
- [ ] **Step 2 : test, rouge** — `smoke-http.test.sh` : « `smoke_auth_config_file AUTRE_VAR $'a\nb'` → retour 2, message
  qui nomme `AUTRE_VAR` » ; « `smoke_add_basic_auth` d'une variable vide n'ajoute rien à `SMOKE_CURL_OPTS` » ; « d'une
  variable posée ajoute `-K <fichier>` et pose `SMOKE_AUTH_CONFIG` » ; « `smoke_header` lit un en-tête sans casse ».
  Expected : message à `AUDIT_BASIC_AUTH` ; fonctions introuvables.
- [ ] **Step 3 : extraire** — selon les interfaces (les tests du filet verts sans changement, sauf le nom du binaire dans
  `smoke-http.test.sh`).
- [ ] **Step 4 : verts** — les quatre tests hors ligne à 0 ; shellcheck (image épinglée) sur la bibliothèque, les quatre
  scripts et les tests touchés ; `AUDIT_BASIC_AUTH=$'u:p\nx' tools/audit-prod.sh https://example.invalid; echo "exit=$?"` → 2 ;
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `refactor(tools): la Basic Auth, l'en-tête et le binaire curl des scripts de fumée écrits une fois dans tools/lib/smoke-http.sh (#748)`.

---

### Task R72 : Balayage documentaire de la sixième série

Les docstrings et les règles ont suivi chaque comportement dans son commit (contrainte de la série) ; cette tâche
vérifie qu'il ne reste rien.

**Files :** ceux que le balayage désigne (`.claude/rules/*`, `docs/adr/0003-paliers-d-acces.md`, `k8s/README.md`, docblocks de `frontend/src` et `backend/tests`).

- [ ] **Step 1 : balayage** — `grep -rn "discardBackofficeAccessRefusals\|isCurrentGeneration(sentAt.session)\|another session generation\|mount and unmount\|montage et le démontage\|lastSaveOf\|proposalSourceLocale\|proposalTargetLocale\|SMOKE_CURL_BIN\|ne compare que les statuts\|n'y voit pas de navigation" .claude docs frontend/src frontend/tests backend/src backend/tests tools k8s/README.md`
  (les fichiers de secrets exclus) : ne rend que l'historique daté ou le sens juste ; le noter ligne par ligne. Toute
  phrase fausse trouvée est **remplacée**.
- [ ] **Step 2 : verts** — `tools/check-claude-rules.sh; echo "exit=$?"` ; taille de chaque règle touchée par la série
  (`frontend-session-refus.md`, `frontend-session-retour.md`, `frontend-session.md`, `frontend-backoffice.md`,
  `backoffice-api.md`, `security-authentication-sessions.md`) notée, toutes < 50 000 octets ; `make front-test; echo "exit=$?"`
  (témoin) si un fichier de `frontend/` a changé, `make back-test; echo "exit=$?"` si un de `backend/`.
- [ ] **Step 3 : commit** (seulement si le balayage a changé un fichier) — `docs: le balayage de la sixième relecture de #748`.
  Sinon, le rendu dit « rien à changer » et les sorties du grep.

### Constats écartés, avec leur raison

- **St6-15** (`let endedSessionRecheck` / `let refusalReread` réaffectables). Pour : un `const` dirait qu'ils ne
  changent pas. Contre : ils changent, par la remise à zéro de test (`resetSessionRereadsForTests`, la convention
  `reset…ForTests` du module, R55) ; un objet `const` aux propriétés réaffectées serait la même mutation sous un autre
  nom. Ni exportés ni réaffectés par `src/`. Écarté.
- **C6-11** (pas de variante « session close » dans le balayage de l'invariant n° 8). Pour : une cause de plus vue sur
  chaque route. Contre : une requête de plus par route et méthode (un quart de durée en plus sur un test déjà long), un
  compte et un changement de version en base ; le prédicat ne lit que le chemin (`AccessMapStaysPathOnlyTest`) et toutes
  les causes passent par `LoginFailureEvent`, déjà couvertes sur une route publique et une protégée
  (`SessionBeforePasswordChangeTest`). La raison du choix n° 7 de la cinquième série. Écarté.
- **St6-16** (nombre d'assertions PHPUnit variable d'un passage à l'autre). Pour une tâche : épingler un compteur rendrait
  la variation visible. Contre : le nombre d'assertions n'est pas un résultat de test ; il varie parce que des tests
  attendent un état en boucle et assertent à chaque tour (attente de processus enfants, d'une session bloquée, d'une
  horloge : `ChildProcessHarnessTest`, `LoginFailureTimingTest`, `CacheWorkerRestartSignalTest` en sont les candidats),
  par construction ; un compteur épinglé serait un test fragile qui ne dit rien du comportement. Les gardes « au moins
  un cas vu » existent déjà là où le nombre compte (`assertGreaterThan(0, $checked)`). Écarté ; à rouvrir si un test
  passe ou échoue selon ce nombre.
- **Sp6-7, pour le code** (« `-K` passe en 600 », R57). Le fichier est en 0600 depuis toujours (`mktemp`) ; les
  commentaires des scripts disent « en 600 », ce qui est vrai ; seul le message du commit c49a1fb8 le présente comme un
  changement : un message de commit ne se réécrit pas (aucune commande git ne réécrit l'historique), une note est posée
  sous R57. `umask 077` reste, redondant mais sans coût, et protège le jour où le fichier ne viendrait plus de `mktemp`.
- **Sp6-8** : ajouts hors table de la cinquième série, cohérents ; rien à changer.
- **C6-9, la seconde moitié** (le refus dit qu'un CR/LF existe). Pour : ne rien dire du secret. Contre : sans le dire,
  l'opérateur ne sait pas pourquoi sa valeur est refusée ; un bit d'information sur une valeur qu'il possède, sur stderr
  d'un job de CI. Négligeable, écarté.
- **St6-12, `fetch()` / `request()` « Middle Man »** : gardés (choix n° 12).
- **St6-13, en partie** : les justifications de `PUBLIC_PATHS` (l. 86-97), antérieures à la série, sont des chaînes que
  le message d'échec cite ; les couper n'apporte rien à la lecture et touche une liste que l'invariant n° 1 lit. Les
  lignes écrites par R46 sont coupées en R59.

---

## Corrections de la septième relecture (2026-10-30)

R59 à R72 sont faites (773472ec → c737f648 ; R72, le balayage, n'a rien changé). Quatre relectures du diff
3b1aaeb3...c737f648 (`/code-review medium` CR7-*, audit de sécurité C7-*, axe Standards St7-*, axe Spec Sp7-*) :
aucun critique ni haut, trois moyens. Constats complets et décisions : `tasks/relectures-748/relecture-7-constats.md`
(la source de vérité). Tous sont traités ici par R73 à R87, avant les tâches 11 et 12 : corrigés, écrits comme
limite, ou écartés avec leur raison (ci-dessous). Les sections passées restent l'archive de leur moment : là où les
séries les ont rendues fausses, une note datée « *(rectifié le 2026-10-30, septième relecture : …)* » est posée à
leur endroit (Sp7-2, Sp7-3, Sp7-4, et le point R64 de la tâche 11), dans le commit de ce plan.

### Décisions de l'auteur (2026-10-30)

1. **C7-1 : contrôle à l'exécution.** Une double implication sur le pare-feu `api` : toute route hors de
   `PUBLIC_PATHS` a une règle d'`access_control` (`requiresAccess()` vrai), toute route de `PUBLIC_PATHS` n'en a
   aucune, exception justifiée `/api/logout` (vérifiée ci-dessous : c'est la seule). Le détecteur par jetons
   (`AuthorizationSites`) reste tel quel, **sans élargissement** ; ses limites sont écrites dans son docblock, avec le
   renvoi au contrôle à l'exécution. Inclut C7-10 (`requiresAccess()` ⇒ 401 anonyme sur le pare-feu `api`). R73, R81.
2. **C7-4 : retenir le compte.** Le refus d'écriture compté retient le compte qui l'a envoyé ; au montage, l'avis
   « enregistrement précédent » n'est dit que si c'est le compte courant, sinon tout est remis à zéro. R76.
3. **Tout jusqu'à zéro** (décision 2 de la quatrième série, maintenue) : moyens, faibles, info, documentation et
   conception, puis les quatre relectures, et l'on recommence tant qu'elles trouvent quelque chose.
4. Toujours valables : C5-1, C5-2, C7 (limite de la session) acceptés ; les décisions des séries 4 à 6 (R31, Q1 de la
   quatrième série, R50, Q2 et Q3 de la cinquième, décisions 1 à 4 et Q1 de la sixième) restent telles quelles.
5. **Q1 et Q2 de la septième relecture : recommandations validées** (2026-10-30, avant R73). Q1 : la limite écrite,
   la tolérance de CR6-6 gardée. Q2 : option A de Sp7-1, le sélecteur de langue verrouillé et « Annuler » affiché.
6. **Décisions prises pendant la série** (2026-10-30, par l'auteur). R75 : `smoke_write_auth_config` appelle
   `smoke_cleanup` en tête (fait en R83). R79b : D-a (le verrou du sélecteur de langue est expliqué sur les huit
   formulaires par entrée) et D-b (la catégorie des cartes « moi » est verrouillée par une proposition).

### Vérification de chaque constat dans le code (HEAD 30afa8e7)

- **C7-1 (= CR7-6), confirmé.** `OwnMcpConnectionsController::list()`/`revoke()` et `ConsentController::show()`/
  `decide()` autorisent par `#[CurrentUser] CpgUser $account` non nullable ; aucune des formes citées
  (`isGrantedForUser(`, `UserAuthorizationCheckerInterface`, `createAccessDeniedException()`, `new HttpException(403`,
  `InsufficientAuthenticationException`) n'est vue par `AuthorizationSites::in()`. Les quatre routes ont aujourd'hui
  une règle (`^/api/me(/|$)`, `^/api/oauth/consent(/|$)`) : rien n'est cassé, mais une route sans règle ainsi
  gardée passerait les trois gardes (l'invariant n° 1 se contente d'un 401). **L'exception `/api/logout`, vérifiée**
  (`debug:router --env=test` croisé avec les sept règles de `security.yaml`, le 2026-10-30) : sur le pare-feu `api`,
  les routes sans règle sont exactement celles de `PUBLIC_PATHS` qui y relèvent, plus `/api/logout` (qui figure dans
  `BASE_TIER_PATHS`, pas dans `PUBLIC_PATHS`) ; `/api/login_check`, `/api/mcp` et `/api/oauth/{authorize,token,revoke}`
  relèvent des pare-feux `login`, `mcp`, `oauth`, hors du contrôle.
- **C7-10, confirmé.** Invariant n° 8 (`ApiRouteExposureTest.php:291-292`) : l'effacement n'est jugé que si l'anonyme
  a reçu 401 ; une route à règle qui répondrait autre chose à l'anonyme (403, 200) n'est jugée par rien.
- **C7-6 (= CR7-1), confirmé et reproduit.** `smoke-dead-session-cookie.sh:75`, `smoke-login-throttling.sh:60`,
  `smoke-mcp.sh:89` : `trap 'rm -f … ${SMOKE_AUTH_CONFIG:+"$SMOKE_AUTH_CONFIG"}' EXIT` ; sans `AUDIT_BASIC_AUTH`,
  `smoke_add_basic_auth` ne touche pas `SMOKE_AUTH_CONFIG`, qui garde la valeur héritée de l'environnement, et le
  `trap` supprime ce fichier. `audit-prod.sh` n'est pas touché (son `CURL_AUTH_CONFIG` n'est lu que dans le bloc qui
  l'affecte).
- **CR7-4, confirmé, chemin étroit.** `backofficeAccessRefusal.ts:71` : tout refus compté remet `earlierWrite` à faux,
  lecture comprise ; le montage n'avance pas le périmètre, donc la lecture de chargement de la page d'arrivée, si
  elle est refusée, remplace aussitôt l'avis. Le chemin est étroit : après un refus, `rereadAfterRefusal()` remet
  l'en-tête au palier relu, et un compte rétrogradé n'entre plus dans le backoffice (le garde de route) ; reste un
  compte que le frontend croit encore `ROLE_SUPER` (relecture en échec, rétrogradation entre la relecture et le
  retour).
- **C7-4, confirmé.** `writeCountedSinceClear` n'est remis à faux que par `clearBackofficeAccessRefusal()` et le
  montage ne lit aucun compte : A refusé hors du backoffice, déconnexion, B connecté dans l'onglet, B ouvre le
  backoffice → l'avis de A.
- **CR7-2 (= C7-5 = Sp7-6), confirmé.** `useAuth.ts:42` : l'identité est `getLastAccount()?.username` ;
  `backofficeSessionExpiry.ts:54` : `sentAt.account !== lastAccountName()`, donc `null === null` compte, alors que
  l'en-tête du même fichier (l. 32) dit « un refus parti sous un autre compte, **ou sous aucun**, n'est ni compté ni
  relu ». Une déconnexion puis une reconnexion au même nom, ou un rôle perdu, ne l'écartent pas. `/api/me`
  (`CpgUserPresenter`) n'expose que `username` et `roles`, sous `ROLE_TRUSTED` (objectif n° 9) : un identifiant
  stable demanderait de changer son contrat.
- **CR7-3, confirmé ; le numéro de ligne est faux.** `backofficeSessionExpiry.ts` a 68 lignes (le constat cite
  `:490`) ; la logique est l. 44 et 54 : `account` relevé à l'envoi vaut `null` avant le premier `checkAuth`, et un
  refus arrivé ensuite est écarté (`null !== 'nom'`). Le garde du backoffice attend `waitForAuthCheck()` : aucune
  requête du backoffice ne part avant.
- **C7-2, confirmé.** `AuthorizationSites.php:73` compare le dernier segment du nom écrit : un import sous alias
  (`use …\AccessDeniedHttpException as Denied;` puis `new Denied()`) et un callable `[$security, 'isGranted']`
  échappent. Limite non écrite.
- **C7-3, confirmé.** `ApiPlatformOperationsCarryNoSecurityTest::expressionsOf()` ne lit que `Metadata` ; API Platform
  4.4.6 porte aussi `Parameter::getSecurity()` (`string|\Stringable|null`, dont `Link`, qui l'étend : `uriVariables`,
  `getLinks()`), `ApiProperty::getSecurity()` et `ApiProperty::getSecurityPostDenormalize()`.
- **St7-1, confirmé.** `smoke-http.sh:52` et `:78` : `local name="${1:?…}"` fait sortir le shell non interactif tout
  entier, contre l'en-tête de la bibliothèque (l. 28-29).
- **St7-2, confirmé.** `frontend-session-retour.md:47-48` (« keeps every later `checkAuth()` — this re-check's
  neighbours, the re-read after a real refusal — »), `useAuth.ts:142` (« une relecture (`checkAuth()`,
  `rereadAfterRefusal()`) trouve un autre identifiant » : au chargement aucun compte n'est retenu, `checkAuth()` n'en
  trouve jamais un « autre »), `useAuth.ts:230` (« Silencieuse après la première vérification ») ; et, vu en
  vérifiant, `frontend-session-refus.md:104-105` (« every `checkAuth()` after the first is silent »). `checkAuth()`
  n'a qu'un appelant (`main.ts`) ; `hasCheckedOnce` (`sessionState.ts:48`) ne sert qu'à `beginCheck()`, que
  `checkAuth()` appelle une fois, alors que `state.isChecking` vaut déjà `true` au chargement (l. 25) : `beginCheck()`
  et `hasCheckedOnce` ne changent rien (Speculative Generality).
- **CR7-7 (= C7-11), confirmé, et la limite écrite est fausse.** Le docblock de `sourceText.ts` dit qu'aucune regex
  littérale gênante n'existe dans `src/` : `RichText.vue:36` (`` /`([^`]+)`/ ``) ouvre un gabarit que rien ne ferme
  avant le prochain accent grave du fichier, `HttpCvRepository.ts:38` (`/filename="?([^";]+)"?/`) ouvre une chaîne
  jusqu'à la fin de sa ligne. Les deux exemples du constat (`/^\/*$/`, `/["']/`) ne sont pas dans `src/`, mais le
  premier avalerait tout jusqu'au prochain `*/`. Un `.vue` est lu en entier, `<template>` compris.
- **CR7-8, constaté, sans effet visible.** `AdminAboutSettingsForm.vue` : la réponse réussie d'un enregistrement de la
  langue cible pendant qu'une autre est montrée passe par `write(en)`, qui ne touche pas `settings` ; `proposal` reste
  en mémoire. Mais l'avis est dans le `<form v-else>` de `!isLoaded` (l. 169-175) et tout chemin qui ramène le
  formulaire de la cible relit `settings` (retour, rechargement, session rétablie), ce qui retire `proposal` avant
  tout rendu : aucun état visible n'est faux. Le commentaire de `handleSubmit` (l. 141-144), lui, est faux pour ce cas.
- **Sp7-1, confirmé, et un formulaire de plus trouvé.** `AdminCaseStudiesPage.vue:257-261` (et les cinq autres pages
  citées) : la traduction met le formulaire en création (`editingId = null`), le sélecteur de langue
  (`:disabled="isEditing"`) redevient actif, et l'avis (`v-if="proposalSourceLocale"`) reste sur une langue qui n'est
  plus la cible ; le groupe de traduction gardé (`sourceGroup`) en ferait une seconde version de la langue source dans
  le même groupe. **Vu en vérifiant** : `AdminQualityPage.vue` a le même défaut, deux fois (principes, traits :
  `principleProposalSourceLocale`, `traitProposalSourceLocale`). Huit formulaires dans sept fichiers.
- **Sp7-7, confirmé, et un fichier de plus.** Lignes de plus de 140 caractères : `AuthorizationLivesInAccessControlTest`
  (onze, jusqu'à 232), `ApiPlatformOperationsCarryNoSecurityTest` (trois), et `AuthorizationSites.php` (59, 68, 96).
- **St7-3 (= CR7-9), confirmé.** `smoke-dead-session-cookie.sh:84-92` refait la lecture d'en-tête de `smoke_header`
  pour des valeurs multiples.
- **St7-4, confirmé.** Le bloc `source … || { echo "bibliothèque introuvable ou invalide : …" >&2; exit 2; }` est écrit
  sept fois (trois scripts de fumée, trois dans `audit-prod.sh`, `check-tls-expiry.sh`) ; son commentaire de deux à
  quatre lignes, quatre fois.
- **St7-5, confirmé.** La liste des témoins de la prémisse de R35 est écrite dans `ApiRouteExposureTest` (docblock de
  classe l. 61-64, de méthode l. 248-250), `AuthorizationLivesInAccessControlTest` (l. 25-28),
  `ApiPlatformOperationsCarryNoSecurityTest` (l. 21-26), `PublicRouteIgnoresRefusedSessionListener` (l. 27-30),
  `backoffice-api.md` (l. 20-23), `security-authentication-sessions.md` (l. 166-169, 179). **Et R73 la rend fausse**
  (« seuls témoins ») : l'invariant n° 9 devient un troisième témoin. La correction va donc dans R73.
- **St7-6, confirmé.** `refusals`, `earlierWrite`, `writeCountedSinceClear` remis ensemble à la main dans
  `markBackofficeAccessRefused` (deux sur trois), `clearBackofficeAccessRefusal`, `resetBackofficeAccessRefusalForTests`.
- **St7-9, confirmé.** `AuthorizationSites::in()` (54 lignes, sept branches) ; `scan()` de `sourceText.ts` (l. 34-164,
  trois fermetures sur `i` et `text` mutables).
- **St7-10, confirmé.** `ApiRouteExposureTest.php:343` écrit `['GET', 'HEAD', 'OPTIONS']` ;
  `CsrfCookieRequestSubscriber::UNSAFE_METHODS` (`private`, l. 42) est la source de vérité du double-submit.
- **Sp7-5 (= St7-12), confirmé.** `security-authentication-sessions.md:174` : « (39 classes, 112 operations on
  2026-10-30) » et « (measured: a `defaults.security` in a temporary config file turns it red and leaves the first
  green) ».
- **Info, chacun vu dans le code** : CR7-5 (= C7-12, `useAuth.ts:243-249`, voulu par R65) ; CR7-10
  (`AdminAboutSettingsForm.vue:220` ; `isSaving` vaut `isSavingLocale(props.locale)` dès que `load(props.locale)` a
  été appelé, le `watch` immédiat le fait) ; C7-7 (`smoke-http.sh:79-80`, `${!name}` évalue un indice) ; C7-8 (le
  fichier `-K` naît dans le sous-shell de `$(…)` avant l'affectation que lit le `trap`) ; C7-9 (la valeur passe en
  argument de `smoke_auth_config_file` et dans `printf '%s' "$value" | sed`, tous deux tracés sous `set -x`) ; C7-13
  (`EXEMPTIONS` : nature ⇒ nombre, par fichier) ; St7-7 (`earlierWrite`/`earlierWriteRefused` contre la clé
  `common.earlierSaveRefused` ; `$isRefusedByTheApiFirewall`, qui dit « 401 anonyme sur `api` », pas « refus du
  pare-feu » ; `tree()` dans `audit-prod.test.sh:40`) ; St7-8 (`'refusal'`, `'isGranted'`… chaînes libres) ;
  St7-10 suite (`useAdminAboutSettings.ts:75` répète la lecture de `saving` ; `proposal?.target === …` l. 87 et 227 ;
  le timbre recopié trois fois dans `HttpAdminUserRepository.spec.ts`) ; St7-11 (`useAuth.ts:29`, seul
  `backofficeSessionExpiry.spec.ts:11` l'importe) ; St7-13 (`audit-prod.test.sh:118`) ; St7-14
  (`ApiPlatformOperationsCarryNoSecurityTest.php:25`, `{@see AuthorizationLivesInAccessControlTest}` : même namespace,
  non importée, donc nom complet selon `backend-conventions.md`) ; St7-15 (`backoffice-api.md:23`) ; Sp7-8 (limite
  écrite par R68, acceptée).
- **Sp7-2, Sp7-3, Sp7-4, confirmés pour l'archive.** `header()` de `smoke-login-throttling.sh` retirait déjà le `\r`
  (`tr -d '\r'`, vu dans 8cbfea45^) ; R62 a créé `tools/tests/audit-prod.test.sh` et touché `pipeline.yml` et
  `deploiement-nginx.md`, R71 aussi `deploiement-nginx.md`, `deploiement.md`, `check-tls-expiry.sh`,
  `tls-certificate.test.sh` et `audit-prod.test.sh` (stat des commits 8cbfea45 et c737f648) ; la classe de R61 compte
  sept tests (un parcours, six cas de fournisseur) ; la mutation de R69 a porté sur `lowerSessionEnded`.
- **Vu en vérifiant, hors constats : le point R64 de la tâche 11 est inatteignable tel qu'écrit.** Un compte
  rétrogradé dont l'écriture est refusée fait relire `/api/me` ; l'en-tête passe au palier relu, et le garde de route
  envoie toute page d'administration vers la page « interdit » : `AdminLayout` ne se monte pas, l'alerte non plus.
  L'avis « enregistrement précédent » ne se voit qu'avec un compte resté `ROLE_SUPER` dont l'écriture est refusée
  (une signature `XSRF-TOKEN` périmée). Note posée sur ce point de la tâche 11 ; R76 ajoute le procédé.

### Options comparées

**Décision 1 — où vit la double implication (C7-1, C7-10) :**

| Option | Pour | Contre |
|---|---|---|
| (A) **un invariant n° 9 d'`ApiRouteExposureTest`**, et C7-10 dans la boucle de l'invariant n° 8 — **retenue** | `PUBLIC_PATHS` (privée), `firewallOf()`, `requiresAccess()` et le parcours du routeur y sont déjà ; aucune liste à exposer ni à recopier ; l'invariant n° 9 ne lit que le routeur et l'`AccessMap` (aucune requête HTTP) ; C7-10 profite des trois appels que l'invariant n° 8 fait déjà, paire CSRF comprise | la classe grossit (672 lignes) |
| (B) une classe à part | une responsabilité par fichier | `PUBLIC_PATHS` deviendrait publique ou recopiée (deux listes, ce que le projet refuse) ; C7-10 referait les requêtes de l'invariant n° 8 |

**C7-10 sans changer CR6-6 :** l'assertion est `requiresAccess()` ⇒ anonyme en 401, jamais la réciproque : un 401
métier d'une route publique reste toléré (choix de CR6-6, sixième série).

**CR7-4 — que fait un refus compté après le montage, quand l'avis « enregistrement précédent » est affiché :**

| Option | Pour | Contre |
|---|---|---|
| (A) l'avis reste jusqu'au prochain effacement | une ligne de moins | un nouvel enregistrement refusé sur la page serait dit « précédent » : faux |
| (B) un seuil : seuls les refus de requêtes parties après le montage remettent le texte courant | exact | un compteur d'envois de plus dans le timbre et le module, pour un chemin étroit |
| (C) **par nature : une lecture refusée garde l'avis (le compteur avance, l'alerte se réannonce), une écriture refusée ramène `common.accessRefused`** — **retenue** | une condition ; le texte de l'avis (« votre compte n'a peut-être pas, ou plus, l'accès… Changez de compte ») dit déjà ce qu'une lecture refusée dirait ; un nouvel enregistrement refusé est le geste courant, il a le texte courant | une lecture refusée bien après le montage garde l'avis « précédent » : son texte reste vrai |

**CR7-2 — l'identité du compte :**

| Option | Pour | Contre |
|---|---|---|
| (A) l'UUID du compte, exposé par `/api/me` et retenu dans `LastAccount` | un compte recréé sous le même nom n'est plus « le même » | change le contrat de `/api/me` (backend, présentateur, frontend, tests) pour un cas qui suppose un compte supprimé puis recréé sous le même nom entre l'envoi et la réponse ; c'est C8 de la quatrième série, déjà rattaché au ticket `__Host-` proposé à la clôture (tâche 12) |
| (B) **la limite écrite, les textes alignés sur le code** — **retenue** | aucun contrat touché ; le comportement d'aujourd'hui est celui que la décision 2 de la sixième série veut (le même compte reste dit après une fin puis un retour ; un rôle perdu est précisément ce qu'un 403 dit) | le nom reste l'identité (limite écrite, renvoi à C8) |

Le choix ne change pas le contrat de `/api/me` : pas de question à l'auteur.

**CR7-7 — le lecteur de source des gardes :**

| Option | Pour | Contre |
|---|---|---|
| (A) reconnaître les regex littérales dans `scan()` (l'heuristique « `/` après un opérateur ») et ne lire que les `<script>` d'un `.vue` | aucune dépendance | l'heuristique division/regex est connue pour ses faux pas ; `scan()` grossit (St7-9 empire) |
| (B) **l'arbre syntaxique de TypeScript** (`typescript`, déjà en `devDependencies`, `~6.0.2`) et `vue/compiler-sfc` (exporté par `vue`) pour les blocs `<script>` — **retenue** | exact (chaînes, gabarits, regex, commentaires comme le compilateur les lit) ; `scan()` et ses trois fermetures disparaissent (St7-9) ; un échappement est lu (`'om\x69t'` est `omit`) | les gardes dépendent de l'API du compilateur : un passage à TypeScript 7 (portage natif) la changera ; la garde rougira alors bruyamment, jamais en silence |
| (C) la limite écrite | rien à coder | la garde manque aujourd'hui du code réel (`RichText.vue`) |

**Sp7-1 — l'avis d'une proposition dans un formulaire par entrée :**

| Option | Pour | Contre |
|---|---|---|
| (A) **le sélecteur de langue verrouillé tant que le formulaire porte une proposition, comme en édition, et « Annuler » affiché** — **retenue** (question Q2) | la proposition est la version d'une entrée dans l'autre langue : en changer la langue n'a pas de sens, et son groupe gardé ferait une seconde version de la langue source ; même sémantique que l'édition | un bouton de plus après une traduction |
| (B) l'avis sous la seule langue cible (comme R66) | calqué sur « À propos » | changer de langue garde la prose générée sans l'avis qui la signale (ADR 0004 D4), et l'enregistrement viserait le groupe de la source dans sa propre langue |
| (C) changer de langue abandonne la proposition (avis retiré) | simple | la prose générée reste dans les champs sans avis |

**St7-6 — un seul état du refus :** (A) un `ref` d'un objet immuable remplacé en bloc (`NO_REFUSAL` à l'effacement),
les deux exports en `computed`, **retenue** : une seule affectation par transition, aucun champ oublié ; (B) une
fonction privée qui remet les trois variables : retire la répétition, garde trois états à tenir d'accord.

### Choix de conception tranchés

1. **Décision 1 (R73).** Invariant n° 9, `testOnTheApiFirewallAPublicRouteIsExactlyARouteWithoutRule()` : sur chaque
   chemin et méthode du routeur dont le pare-feu est `api`, `requiresAccess()` vaut vrai si et seulement si le chemin
   n'est ni dans `PUBLIC_PATHS` ni dans `RULELESS_OUTSIDE_PUBLIC_PATHS` (`/api/logout`, avec sa raison) ;
   `testEveryRulelessExceptionIsStillNeeded()` exige de chaque entrée qu'elle existe, relève d'`api`, soit hors de
   `PUBLIC_PATHS` et sans règle. C7-10 : dans l'invariant n° 8, `if ('api' === firewallOf && requiresAccess)` ⇒
   anonyme en 401, puis l'effacement (le bloc de `$isRefusedByTheApiFirewall` disparaît, St7-7).
2. **St7-5 (R73).** **Une source** : la puce de `PublicRouteIgnoresRefusedSessionListener` dans
   `security-authentication-sessions.md` liste les trois témoins de la prémisse et ce qu'aucun ne voit. Les six autres
   endroits disent leur propre portée et renvoient à cette puce par une phrase.
3. **Q1 (R73), recommandation suivie** : une autorisation autre qu'une expression d'API Platform posée sur une route de
   `PUBLIC_PATHS` (un `#[CurrentUser]` non nullable, par exemple) n'est vue par aucun témoin : limite écrite dans la
   source unique. La justification écrite de l'entrée de `PUBLIC_PATHS` (« servie sans authentification ») est ce que
   la relecture lit.
4. **C7-3 (R74).** La garde des métadonnées compilées parcourt aussi les paramètres (`getParameters()`), les liens
   (`getUriVariables()`, `getLinks()`, de la ressource et de l'opération) et les propriétés
   (`PropertyNameCollectionFactoryInterface`, `PropertyMetadataFactoryInterface`) ; un garde-fou par famille parcourue
   là où `src/` en a (liens, propriétés). *(rectifié le 2026-10-30, huitième relecture : `getLinks()` rend des WebLink
   de Symfony — les relations de l'en-tête HTTP `Link` —, pas des `Link` d'API Platform, et ne porte pas de `security` ;
   R74 ne l'a pas parcouru et son docblock le dit. Vaut aussi pour `getLinks()` au constat C7-3 et dans les Interfaces de
   R74.)*
5. **C7-6, St7-1, C7-7, C7-8, C7-9 (R75).** La bibliothèque remet `SMOKE_AUTH_DIR` et `SMOKE_AUTH_CONFIG` à vide à la
   source ; `smoke_write_auth_config <nom>` remplace `smoke_auth_config_file <nom> <valeur>` : il valide le nom, lit
   `${!nom}` lui-même, écrit dans un répertoire privé (`mktemp -d`, 0700) **dans le shell courant**, sous `set +x`,
   échappe par expansion de paramètres (aucun sous-shell, aucun `sed`) ; `smoke_cleanup` retire le répertoire ; les
   `trap` des quatre scripts l'appellent. Aucune fonction ne fait plus sortir l'appelant.
6. **Décision 2 et CR7-4 (R76).** `markBackofficeAccessRefused(sentIn, account)` retient, pour une écriture comptée,
   le compte qui l'a envoyée ; une lecture comptée laisse l'avis (option C) ; `settleBackofficeAccessRefusalsOnMount(currentAccount)`
   ne dit l'avis que si l'écriture comptée est de `currentAccount`, sinon remet tout à zéro. Paramètres plutôt
   qu'import de `useAuth` : le module reste « exposé à part de `useAuth` » (son docblock).
7. **CR7-2, CR7-3 (R77).** Textes alignés sur le code : un refus est écarté quand le compte de l'envoi et celui de la
   réception diffèrent, `null` d'un seul côté compris ; `null` des deux côtés est le même « aucun » et compte ; le
   même nom après une déconnexion et une reconnexion, ou un rôle perdu, est le même compte (voulu) ; un compte
   supprimé puis recréé sous le même nom l'est aussi (limite, C8, tâche 12) ; `null` à l'envoi puis un compte est
   écarté, cas sans chemin (le garde attend la première vérification). Des tests de caractérisation l'épinglent.
8. **Sp7-1 (R78, R79), recommandation de Q2 suivie.** `:disabled="isEditing || null !== proposalSourceLocale"` sur le
   sélecteur de langue et « Annuler » `v-if="isEditing || null !== proposalSourceLocale"` (le `resetForm` existant
   retire la proposition et l'avis) ; mêmes deux lignes dans chaque formulaire, sans composable commun (aucun constat
   ne le demande ; une extraction toucherait huit formulaires pour deux lignes chacun).
9. **CR7-7, St7-9, Sp7-8 (R80).** `sourceText.ts` sur l'arbre de TypeScript (option B) ; `scriptOf(path, source)`
   ne garde d'un `.vue` que ses blocs `<script>`, à leur place (le reste en espaces, les fins de ligne gardées, pour
   des numéros de ligne justes). Sp7-8 disparaît : une chaîne non terminée est du code que `vue-tsc` refuse
   (`make front-build`).
10. **St7-8, St7-9, Sp7-7, C7-2, C7-13 (R81).** `enum AuthorizationSiteKind: string` (ses valeurs sont les chaînes
    d'aujourd'hui) ; `in()` découpé en un parcours et trois lectures privées ; le docblock du détecteur liste ce qu'il
    ne voit pas (décision 1) et renvoie à l'invariant n° 9 ; celui de la garde renvoie au détecteur pour ses limites.
11. **St7-10 (R82).** `CsrfCookieRequestSubscriber::UNSAFE_METHODS` devient `public` ; l'invariant n° 8 lit ce
    complément.
12. **St7-3, St7-4, St7-13, St7-7 (R83).** `smoke_headers <fichier> <nom>` (toutes les valeurs, une par ligne) ;
    `smoke_header` = sa dernière ligne ; les sept blocs `source` restent (l'amorçage : une fonction qui sourcerait une
    bibliothèque devrait d'abord être sourcée), leurs commentaires réduits à une ligne identique ; `'u:p'` ;
    `tree()` → `copy_tools()`.
13. **St7-6, St7-7, St7-10 suite (R84).** Option A ci-dessus ; `earlierWriteRefused` → `earlierSaveRefused`, comme la
    clé ; une aide `readStamp()` dans `HttpAdminUserRepository.spec.ts`.
14. **St7-2, St7-11 (R85).** `beginCheck()` et `hasCheckedOnce` retirés (`isChecking` naît vrai et
    `markFirstCheckDone()` l'abaisse) ; les textes disent « les relectures ne touchent jamais `isChecking` » ;
    `isCurrentGeneration` n'est plus réexporté par `useAuth.ts`.
15. **CR7-10, St7-10 suite, CR7-8 (R86).** `isSaving` lit `isSavingLocale` ; le bouton de traduction lit
    `isSavingLocale(locale) || isSavingLocale(otherLocale(locale))` (même valeur qu'aujourd'hui : `load(props.locale)`
    est appelé par le `watch` immédiat) ; `isProposalShown` (`computed`) remplace les deux comparaisons ; le
    commentaire de `handleSubmit` dit le cas CR7-8.

**Questions à l'auteur** (le plan suit la recommandation ; une autre réponse ne change que la tâche citée) :

- **Q1 (R73)** : une route de `PUBLIC_PATHS` gardée autrement que par une expression d'API Platform (un
  `#[CurrentUser]` non nullable) n'est vue par aucun témoin. **Recommandé : la limite écrite** (la justification de
  l'entrée de `PUBLIC_PATHS` est ce que la relecture lit, et CR6-6 tolère exprès un 401 métier d'une route publique).
  Autre option : une clause de plus à l'invariant n° 8, « sur le pare-feu `api`, une route de `PUBLIC_PATHS` ne
  répond jamais 401 ni 403 à l'anonyme (paire CSRF et `X-Requested-With` fournis) » — elle verrait le cas, au prix
  de revenir sur la tolérance de CR6-6 ; R73 gagnerait cette assertion, sa mutation (`#[CurrentUser] CpgUser` ajouté à
  un contrôleur public) et une phrase de règle.
- **Q2 (R78, R79)** : après une traduction dans un formulaire par entrée. **Recommandé : le sélecteur de langue
  verrouillé et « Annuler » affiché** tant que le formulaire porte la proposition (option A de Sp7-1). Autres :
  l'avis sous la seule langue cible (B), ou l'abandon de la proposition au changement de langue (C) ; chacune
  changerait les deux lignes de R78 et R79 et leurs tests.

### Review Focus (septième relecture)

1. **L'avis « enregistrement précédent » puis la lecture de chargement de la page d'arrivée refusée** : l'avis reste,
   le compteur avance (réannonce) ; un enregistrement refusé sur la page ramène le texte courant. Test : R76.
2. **Une écriture refusée sous le compte A, A se déconnecte, B se connecte dans l'onglet et ouvre le backoffice** :
   aucune alerte. Test : R76.
3. **Une traduction proposée, puis la personne veut changer de langue** : le sélecteur est verrouillé ; « Annuler »
   rend un formulaire vide, sélecteur actif, avis parti. Test : R78 et R79.
4. **Un script de fumée lancé avec un `SMOKE_AUTH_CONFIG` hérité qui désigne un vrai fichier, sans
   `AUDIT_BASIC_AUTH`** : le fichier survit. Test : R75.
5. **Une route ajoutée hors de `PUBLIC_PATHS`, sans règle, gardée par un `#[CurrentUser]`** : la suite rougit
   (invariant n° 9). Test : R73 (mutation : la règle `^/api/oauth/consent(/|$)` retirée).

### Contraintes propres aux tâches R73 à R87

- Les **Global Constraints** et toutes les contraintes des séries précédentes valent. Exécution : **un agent par tâche
  (sonnet, effort high), successifs dans l'ordre R73 → R87**, qui lit d'abord `tasks/relectures-748/brief-commun-agents.md`.
  `superpowers:test-driven-development` : test écrit d'abord, **vu rouge pour la bonne raison** ; un test de couverture
  vert d'emblée est vu rouge par la mutation que sa tâche nomme, **restaurée depuis une copie du fichier faite avant la
  mutation et vérifiée par `cmp`** (`cp f "$scratch/f.orig"`, muter, mesurer, `cp "$scratch/f.orig" f`,
  `cmp f "$scratch/f.orig"`) puis `git diff` vide sur le fichier muté ; refactoring pur : la suite existante comme
  filet, nombre de tests noté avant et après. **`superpowers:verification-before-completion` invoquée réellement**
  avant le rendu.
- **Git : seuls `git add <fichiers>` et `git commit`** (et `git mv` / `git rm` pour un déplacement ou une
  suppression). Aucune autre commande git qui modifie l'index ou l'arbre : ni `checkout`, ni `checkout-index`, ni
  `restore`, ni `stash`, ni `reset` — **pas même `git reset` sans argument**. **Un commit par tâche**, fichiers nommés,
  message en français terminé par les lignes d'attribution que donne le fil principal.
- **Une docstring ou une règle qui décrit un comportement modifié se corrige dans le même commit que le comportement** ;
  une phrase de règle se **remplace**, elle ne s'ajoute pas à côté de l'ancienne.
- **Aucun travail en arrière-plan au rendu** : ni conteneur lancé à part, ni `vitest --watch`, ni processus détaché.
- **Chaque code de sortie se lit sans pipe** : `make front-test; echo "exit=$?"` (de même `front-lint`, `front-build`,
  `back-test`, `back-quality`, chaque test hors ligne et `tools/check-claude-rules.sh`).
- Backend (R73, R74, R81, R82) : lire avec `Read` un fichier de `backend/tests/Security/` avant d'écrire ; test ciblé
  `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis `make back-test` et `make back-quality` (toujours
  `-u dev`) ; `rm -rf var/cache/test` après un changement de configuration, de service ou d'attribut d'API Platform, et
  après chaque mutation ; `make back-style-fix` après un import ajouté ; lignes touchées ≤ 140 caractères ; jamais de
  secret littéral.
- Outillage (R75, R83) : lire avec `Read` les scripts touchés avant d'écrire ; `bash tools/tests/<script>.test.sh` hors
  ligne pour chaque script touché ; shellcheck dans l'image épinglée du job `tools-tests` : `docker run --rm
  --entrypoint shellcheck -v "$PWD":/repo:ro -w /repo
  rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -x --severity=warning
  <fichiers>` (si l'image n'expose pas `shellcheck`, le noter et lancer celui de l'hôte, en le disant). Une valeur de
  test ne contient jamais le mot « secret ».
- Frontend (R76 à R80, R84 à R86) : test ciblé `docker compose exec -T frontend npm test -- <chemins>`, puis
  `make front-test`, `make front-lint`, `make front-build` ; `docker compose restart frontend` après un fichier créé,
  déplacé ou un remplacement de masse ; toute chaîne nouvelle en FR et EN.
- Règles : `tools/check-claude-rules.sh` après chaque modification ; 50 000 octets par règle, taille de chaque règle
  touchée notée au rendu. Tailles au départ : `backoffice-api.md` **39 946** (n'y rien ajouter sans retirer d'abord :
  R73 la réduit), `security-user.md` 47 914 (non touchée), `security-authentication-sessions.md` 21 333,
  `frontend-session-refus.md` 12 534, `frontend-session-retour.md` 17 758, `frontend-session.md` 15 460,
  `frontend-backoffice.md` 26 539, `deploiement-nginx.md` 28 196, `deploiement.md` 24 665. Un fichier créé qui entre
  dans les `paths:` d'une règle y est ajouté dans le même commit et **indexé (`git add`) avant** `check-claude-rules`.
- Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets (les exclure de tout
  `grep -r`) ; ne jamais nommer un compte réel. Pas de push, pas de PR.

### Fichiers (tâches R73 à R87)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `backend/tests/Security/ApiRouteExposureTest.php` | invariant n° 9, C7-10, méthodes sûres | R73, R82 |
| `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php` | paramètres, liens, propriétés | R73 (renvoi), R74 |
| `backend/tests/Support/AuthorizationSites.php`, `backend/tests/Support/AuthorizationSiteKind.php` (nouveau), `backend/tests/Security/AuthorizationLivesInAccessControlTest.php` | détecteur, natures, limites | R73 (renvoi), R81 |
| `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php` | docblock (renvoi) | R73 |
| `backend/src/Security/Authentication/Infrastructure/Http/CsrfCookieRequestSubscriber.php` | `UNSAFE_METHODS` public | R82 |
| `tools/lib/smoke-http.sh`, `tools/{audit-prod,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp,check-tls-expiry}.sh`, `tools/tests/{smoke-http,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp,audit-prod}.test.sh` | Basic Auth, `trap`, en-têtes, amorçage | R75, R83 |
| `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue`, `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts` | le refus, son compte, son état | R76, R77, R84 |
| `frontend/src/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,AdminAnonymousCvPage,AdminQualityPage,AdminAboutMeCardsSection,AdminAboutSiteCardsSection}.vue` | verrou de langue d'une proposition | R78, R79 |
| `frontend/tests/support/sourceText.ts`, `frontend/tests/support/sourceText.spec.ts`, `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts` | lecteur de source | R80, R85 |
| `frontend/src/application/auth/{useAuth,sessionState}.ts` | `beginCheck`, réexport | R85 |
| `frontend/src/application/admin/about/useAdminAboutSettings.ts`, `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue` | `isSaving`, avis | R86 |
| `.claude/rules/*` | dans le commit du comportement | R73 à R86 ; balayage R87 |

---

### Task R73 : Backend — sur le pare-feu `api`, une route publique est exactement une route sans règle ; une route à règle répond 401 à l'anonyme ; les témoins écrits une fois (décision 1 : C7-1, C7-10 ; St7-5, Sp7-5/St7-12, St7-15, St7-7 pour `$isRefusedByTheApiFirewall`)

**Files :**
- Modify : `backend/tests/Security/ApiRouteExposureTest.php`
- Modify (docblocks) : `backend/tests/Security/AuthorizationLivesInAccessControlTest.php` (l. 25-28), `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php` (l. 21-26), `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php` (l. 23-30)
- Modify (règles) : `.claude/rules/security-authentication-sessions.md` (puce de `PublicRouteIgnoresRefusedSessionListener`), `.claude/rules/backoffice-api.md` (« Authorization », l. 18-24)

**Interfaces :**
- `private const array RULELESS_OUTSIDE_PUBLIC_PATHS = ['/api/logout' => '« Terminer cet accès » (#65, R35) : sans
  règle par construction, il expire les deux cookies de quiconque, y compris d'un porteur dont le cookie est refusé
  (2xx depuis R35) ; une règle lui rendrait un 401. Pas public pour autant : le double-submit refuse une écriture sans
  jeton (invariant n° 1), et il ne sert aucune donnée.']` (`array<string, string>`, chemin => raison).
- `public function testOnTheApiFirewallAPublicRouteIsExactlyARouteWithoutRule(): void` (invariant n° 9) : pour chaque
  chemin de `allApiRoutePaths()` et chaque méthode de `requestableMethods(routeMethods($path))` dont `firewallOf()`
  vaut `'api'` : `$expectedRule = !isset(self::PUBLIC_PATHS[$path]) && !isset(self::RULELESS_OUTSIDE_PUBLIC_PATHS[$path])` ;
  un écart va dans l'une de deux listes, « hors de PUBLIC_PATHS, sans règle d'access_control » ou « dans
  PUBLIC_PATHS, avec une règle » ; `assertGreaterThan(0, $checked)` ; `assertSame([], …)` avec un message qui nomme les
  deux listes et dit quoi faire (une règle dans `security.yaml`, ou une entrée justifiée de `PUBLIC_PATHS`).
- `public function testEveryRulelessExceptionIsStillNeeded(): void` : chaque entrée existe au routeur, relève d'`api`,
  n'est pas dans `PUBLIC_PATHS`, et `requiresAccess()` y est faux pour chaque méthode.
- Invariant n° 8 : le bloc `$isRefusedByTheApiFirewall` devient `if ('api' === $this->firewallOf($path, $method) &&
  $this->requiresAccess($path, $method)) { self::assertSame(401, $answers['anonyme']['status'], <méthode, chemin :
  une route qui exige un rôle répond 401 à l'anonyme, C7-10>); <l'effacement, inchangé> }`.
- Docblocks : en tête de classe « Neuf invariants », le n° 9 décrit en deux lignes ; le n° 8 et sa méthode perdent
  leur liste de témoins au profit de la phrase de renvoi ; dans les trois autres fichiers aussi. **Phrase de renvoi**,
  identique partout : « Les témoins de cette prémisse sont listés une fois, dans
  `.claude/rules/security-authentication-sessions.md` (puce de `PublicRouteIgnoresRefusedSessionListener`). »
  `AuthorizationLivesInAccessControlTest` : la phrase « L'invariant n° 8 … sont les seuls témoins de la prémisse. »
  devient « Hors de `PUBLIC_PATHS`, l'invariant n° 9 d'`ApiRouteExposureTest` voit à l'exécution une route sans règle ;
  sur une route de `PUBLIC_PATHS`, seule une garde de source voit un `#[IsGranted]`. » suivie de la phrase de renvoi.

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`, nombre de tests noté.
- [ ] **Step 2 : test** — l'invariant n° 9, `testEveryRulelessExceptionIsStillNeeded` et l'assertion C7-10, écrits.
  Run : `docker compose exec -u dev backend php bin/phpunit tests/Security/ApiRouteExposureTest.php`. Expected : vert
  (couverture d'un état juste ; une route qui rougirait est un vrai défaut : s'arrêter et rendre la question).
- [ ] **Step 3 : rouges vus par mutation** (copie vérifiée par `cmp`, `rm -rf var/cache/test` après chaque) :
  (a) l'entrée `/api/logout` retirée de `RULELESS_OUTSIDE_PUBLIC_PATHS` → l'invariant n° 9 nomme `/api/logout` ;
  (b) `{ path: ^/api/oauth/consent(/|$), roles: ROLE_TRUSTED }` retirée de `security.yaml` → l'invariant n° 9 rougit
  sur `/api/oauth/consent` (Review Focus 5 : `ConsentController` n'est plus gardé que par `#[CurrentUser]`), noter
  que l'invariant n° 1 reste vert ; (c) `{ path: ^/api/watch(/|$), roles: ROLE_USER }` ajoutée → l'invariant n° 9
  rougit (route de `PUBLIC_PATHS` à règle) ; (d) `requiresAccess()` du listener qui rend toujours `true` → l'assertion
  C7-10 rougit sur une route publique d'`api` (par exemple `POST /api/contact`).
- [ ] **Step 4 : règles** — `security-authentication-sessions.md`, puce du listener R35 : de « ; the premise (every
  authorization of `/api` is an `access_control` rule) is held by … » jusqu'à « … and leaves the first green). »
  (l. 166-176), **remplacé** par : « ; on the `api` firewall a route that requires a role answers the anonymous call
  401 (C7-10). **The premise — a public route is a route without a rule, every authorization of `/api` an
  `access_control` rule — has three witnesses, listed here only** (the docblocks point here): **invariant n° 9 of
  `ApiRouteExposureTest`** (R73, decision 1 of the seventh review), at run time on the router and the `AccessMap`: on
  the `api` firewall every route outside `PUBLIC_PATHS` has a rule and every `PUBLIC_PATHS` route has none, `/api/logout`
  the one justified exception (`RULELESS_OUTSIDE_PUBLIC_PATHS`), so a route outside `PUBLIC_PATHS` guarded otherwise
  (`#[CurrentUser]`, a hand-thrown refusal) turns it red; `AuthorizationLivesInAccessControlTest`, whose token detector
  (`tests/Support/AuthorizationSites`, proven on code extracts, not widened: its limits are in its docblock) refuses in
  `src/` an `#[IsGranted]`, a `denyAccessUnlessGranted`, an `isGranted()`, an API Platform `security:` argument, a
  hand-thrown `AccessDenied(Http)Exception` / `UnauthorizedHttpException`, `AccessDecisionManagerInterface` and an
  `is_granted(` string, each exemption (the MCP reader, the two CSRF guards) carrying its reason and the exact number of
  sites it covers; `ApiPlatformOperationsCarryNoSecurityTest`, which reads the **compiled** API Platform metadata, so it
  also sees a `security:` declared in YAML or XML or merged by `api_platform.defaults`. **Seen by none (accepted, Q1 of
  the seventh review)**: an authorization other than an API Platform expression on a `PUBLIC_PATHS` route; the
  written justification of the `PUBLIC_PATHS` entry is what a review reads. » ; la phrase « Pinned by
  `PublicRouteIgnoresRefusedSession{,Registration}Test`, `AuthorizationLivesInAccessControlTest`,
  `ApiPlatformOperationsCarryNoSecurityTest` and, on the real stack, » → « Pinned by
  `PublicRouteIgnoresRefusedSession{,Registration}Test`, the three witnesses above and, on the real stack, ».
  `backoffice-api.md` : « held by `AuthorizationLivesInAccessControlTest` (tokens) and
  `ApiPlatformOperationsCarryNoSecurityTest` (compiled metadata), details in `security-authentication-sessions.md`.
  **Every `path` is » + saut de ligne + « anchored » → « its witnesses are listed in
  `security-authentication-sessions.md`. **Every `path` is anchored », le paragraphe rejoint (St7-15) ; taille
  mesurée avant et après, **plus petite** après.
- [ ] **Step 5 : verts** — `make back-test; echo "exit=$?"` (filet + 2), `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"` ; `grep -rn "seuls témoins\|only witnesses" backend .claude` (secrets
  exclus) ne rend plus rien ; tailles des deux règles notées.
- [ ] **Step 6 : commit** — `test(security): sur le pare-feu api, une route publique est exactement une route sans règle et une route à règle répond 401 à l'anonyme ; les témoins de la prémisse écrits une fois (#748)`.

---

### Task R74 : Backend — la garde des métadonnées compilées lit aussi les paramètres, les liens et les propriétés (C7-3 ; Sp7-7 et St7-14 pour ce fichier)

**Files :**
- Modify : `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`
- Modify (règle) : `.claude/rules/security-authentication-sessions.md` (le témoin `ApiPlatformOperationsCarryNoSecurityTest` de la puce écrite par R73)

**Interfaces :**
- `private function parameterExpressions(Parameter $parameter): array` → `['security' => (string) …]` quand
  `getSecurity()` n'est pas `null` (`Link` étend `Parameter`) ; `private function propertyExpressions(ApiProperty
  $property): array` → `security`, `securityPostDenormalize`.
- `testNoOperationCarriesASecurityExpression` parcourt en plus, pour la ressource et chaque opération :
  `getParameters()` (nom => `Parameter`), `getUriVariables()` et, pour une `HttpOperation`, `getLinks()` (les `Link`) ;
  puis, par classe de ressource, chaque propriété (`PropertyNameCollectionFactoryInterface::create()`,
  `PropertyMetadataFactoryInterface::create()`). Messages : « <classe>, opération <nom>, paramètre <nom> : security =
  <expr> », « <classe>, lien <nom> : … », « <classe>, propriété <nom> : … ». Garde-fous : `assertGreaterThan(0, …)` sur
  les liens et sur les propriétés parcourus (src en a ; pas sur les paramètres, qu'aucune ressource ne déclare
  peut-être : le compter et le dire au rendu).
- Fournisseurs : `testTheCheckSeesEachParameterExpression` (`new QueryParameter(security: 'a')`, `new Link(security:
  'b')`, `new QueryParameter()` → `[]`) et `testTheCheckSeesEachPropertyExpression` (`new ApiProperty(security:
  'a')`, `new ApiProperty(securityPostDenormalize: 'b')`, `new ApiProperty()` → `[]`).
- Docblock : la liste « Les trois expressions » complétée (paramètres, liens, propriétés) ; St7-14 : `{@see
  \App\Tests\Security\AuthorizationLivesInAccessControlTest}` ; lignes ≤ 140 caractères dans tout le fichier (Sp7-7 :
  l. 97-99 coupées).

- [ ] **Step 1 : tests, rouges** — les deux fournisseurs écrits. Run : `docker compose exec -u dev backend php bin/phpunit tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`.
  Expected : FAIL, `parameterExpressions` et `propertyExpressions` introuvables.
- [ ] **Step 2 : implémenter** selon les interfaces.
- [ ] **Step 3 : rouges vus par mutation** (copie vérifiée par `cmp`, `rm -rf var/cache/test`) : (a) `#[ApiProperty(security:
  "is_granted('ROLE_USER')")]` posé sur une propriété d'une ressource publique (par exemple un champ de
  `AboutContentResource`) → rouge, la classe, la propriété et l'expression nommées (noter si la garde de jetons de R60
  rougit aussi : elle voit la chaîne `is_granted(`) ; (b) un `uriVariables: ['locale' => new Link(security: 'true')]`
  sur une opération publique → rouge, le lien nommé.
- [ ] **Step 4 : règle** — dans la puce écrite par R73 : « which reads the **compiled** API Platform metadata, so » →
  « which reads the **compiled** API Platform metadata — every resource, operation, parameter, link and property (R74)
  —, so ».
- [ ] **Step 5 : verts** — `make back-test; echo "exit=$?"`, `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `test(security): la garde des métadonnées compilées lit aussi les paramètres, les liens et les propriétés d'API Platform (#748)`.

---

### Task R75 : Outillage — la Basic Auth écrite dans le shell courant, d'un nom validé, hors de toute trace ; un fichier hérité jamais supprimé (C7-6, St7-1, C7-7, C7-8, C7-9)

**Files :**
- Modify : `tools/lib/smoke-http.sh`, `tools/smoke-dead-session-cookie.sh`, `tools/smoke-login-throttling.sh`, `tools/smoke-mcp.sh`, `tools/audit-prod.sh`
- Test : `tools/tests/smoke-http.test.sh`, `tools/tests/smoke-dead-session-cookie.test.sh`, `tools/tests/audit-prod.test.sh` (sa ligne 135 cherche `smoke_auth_config_file AUDIT_BASIC_AUTH ` : changement exprès)
- Modify (règles) : `.claude/rules/deploiement-nginx.md` (l. 118, « `audit-prod.sh` shares the file only (`smoke_auth_config_file`, … ) »), `.claude/rules/security-authentication-sessions.md` (la phrase « a Basic Auth holding a line break is refused (exit 2), and that plumbing is shared in `tools/lib/smoke-http.sh` »)

**Interfaces :**
- À la source : `SMOKE_AUTH_DIR=""` et `SMOKE_AUTH_CONFIG=""` (commentaire : une valeur héritée de l'environnement
  n'est jamais crue, C7-6).
- `smoke_write_auth_config <nom de variable>` (remplace `smoke_auth_config_file`) : `[[ "${1:-}" =~
  ^[A-Za-z_][A-Za-z0-9_]*$ ]]` sinon « smoke_write_auth_config : nom de variable invalide » et retour 2 (C7-7, St7-1) ;
  lit `${!1}` lui-même (C7-9) ; refuse un CR/LF en nommant la variable (inchangé) ; `SMOKE_AUTH_DIR="$(umask 077 &&
  mktemp -d)" || return 2` ; `SMOKE_AUTH_CONFIG="$SMOKE_AUTH_DIR/curl-auth.conf"` ; écrit `user = "<valeur échappée>"`
  **dans le shell courant**, l'échappement par `${v//\\/\\\\}` puis `${v//\"/\\\"}`, sous `set +x` (l'état de `xtrace`
  relevé avant, rétabli après) et `umask 077` ; retour 2 si l'écriture échoue (après `smoke_cleanup`). N'imprime rien.
- `smoke_add_basic_auth <nom>` : nom validé (retour 2) ; vide → retour 0 ; sinon `smoke_write_auth_config "$1" ||
  return 2` puis `SMOKE_CURL_OPTS+=(-K "$SMOKE_AUTH_CONFIG")`.
- `smoke_cleanup` : `[ -n "$SMOKE_AUTH_DIR" ] && rm -rf -- "$SMOKE_AUTH_DIR"`, puis les deux variables à vide ; retour 0.
- Les trois scripts de fumée : `trap 'rm -f "$headers_file"[ "$body_file"]; smoke_cleanup' EXIT`.
  `audit-prod.sh` : `trap 'smoke_cleanup' EXIT`, `smoke_write_auth_config AUDIT_BASIC_AUTH || exit 2`,
  `CURL_OPTS+=(-K "$SMOKE_AUTH_CONFIG")` ; `CURL_AUTH_CONFIG` disparaît ; son commentaire nuancé (C7-8) : un arrêt
  entre `mktemp -d` et l'affectation peut laisser un répertoire privé **vide**, jamais un secret (la valeur n'est
  écrite qu'une fois le chemin connu du `trap`).
- L'en-tête de la bibliothèque : cinq fonctions décrites avec leurs nouveaux noms ; « aucune fonction ne fait sortir
  l'appelant » devient vrai.

- [ ] **Step 1 : filet** — les cinq tests hors ligne (`smoke-http`, `smoke-dead-session-cookie`,
  `smoke-login-throttling`, `smoke-mcp`, `audit-prod`) à 0, sorties notées.
- [ ] **Step 2 : tests, rouges**
  - `smoke-dead-session-cookie.test.sh` (Review Focus 4) : « `SMOKE_AUTH_CONFIG` hérité qui désigne un fichier
    témoin, sans `AUDIT_BASIC_AUTH` : le témoin existe après la sortie ». Expected : le témoin supprimé.
  - `smoke-http.test.sh` : « source avec `SMOKE_AUTH_DIR` et `SMOKE_AUTH_CONFIG` hérités : les deux vides » ;
    « `smoke_add_basic_auth` sans argument : retour 2, et la ligne suivante de l'appelant s'exécute » (expected : le
    sous-shell sort avant) ; « `smoke_write_auth_config 'x[$(touch "$TMP/pwned")]'` : retour 2, aucun `$TMP/pwned` »
    (expected : `pwned` créé — l'ancienne fonction, appelée par `smoke_add_basic_auth`) ; « sous `set -x`, une valeur
    posée n'apparaît pas sur stderr » (expected : la valeur tracée en argument) ; « le fichier est dans un répertoire
    0700, le fichier en 600, `smoke_cleanup` retire le répertoire » ; les tests de `smoke_auth_config_file` réécrits
    pour `smoke_write_auth_config` (changement exprès : la valeur n'est plus un argument).
  - `audit-prod.test.sh` : « avec `AUDIT_BASIC_AUTH`, aucun répertoire de la bibliothèque laissé dans `TMPDIR` après la
    sortie » (`TMPDIR` pointé sur un répertoire du test).
  Run : chaque test hors ligne, `; echo "exit=$?"`.
- [ ] **Step 3 : implémenter** selon les interfaces.
- [ ] **Step 4 : règles** — `deploiement-nginx.md` : « `audit-prod.sh` shares the file only (`smoke_auth_config_file`,
  which names the variable it refuses) » → « `audit-prod.sh` shares the file only (`smoke_write_auth_config`, which
  validates the variable's name, reads it itself and writes in a private directory under `set +x`; `smoke_cleanup` in
  every `trap`) » ; `security-authentication-sessions.md` : « and that plumbing is shared in `tools/lib/smoke-http.sh` »
  → « and that plumbing — a private directory removed by `smoke_cleanup`, never a path inherited from the environment —
  is shared in `tools/lib/smoke-http.sh` ».
- [ ] **Step 5 : verts** — les cinq tests hors ligne à 0 ; shellcheck (image épinglée) sur la bibliothèque, les quatre
  scripts et les tests touchés ; `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(tools): la Basic Auth écrite dans le shell courant, d'un nom validé, hors de toute trace, et un fichier hérité de l'environnement jamais supprimé (#748)`.

---

### Task R76 : Le refus d'écriture retient son compte ; une lecture refusée après le montage garde l'avis de l'enregistrement précédent (décision 2 : C7-4 ; CR7-4)

**Files :**
- Modify : `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue`
- Test : `frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`, `frontend/tests/presentation/router/backofficeSessionExpiry.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-refus.md` (la sous-puce « **the alert's mount advances nothing and settles what was counted without an alert** » ; « Tests », première puce)

**Interfaces :**
- `markBackofficeAccessRefused(sentIn: RefusalScope, account: string | null): boolean` : quand il compte une
  écriture, il retient `account` (le compte de l'écriture comptée depuis le dernier effacement) et remet l'avis à faux ;
  une lecture comptée avance le compteur **et laisse l'avis** (option C de CR7-4).
- `settleBackofficeAccessRefusalsOnMount(currentAccount: string | null): void` : une écriture comptée depuis le dernier
  effacement **et** de `currentAccount` → l'avis à vrai, le compteur gardé ; sinon compteur, avis et écriture comptée
  remis à zéro (décision 2).
- `backofficeSessionExpiry.ts`, `refused()` : `markBackofficeAccessRefused(sentAt.refusal, sentAt.account)`.
  `AccessRefusedNotice.vue` : `settleBackofficeAccessRefusalsOnMount(lastAccountName())` (`lastAccountName` de la
  façade `useAuth.ts`).
- Docblocks du module, de `markBackofficeAccessRefused` et de `settle…`, et la puce « Effacée… » d'`AccessRefusedNotice`,
  disent les deux règles (le compte, la nature) ; St7-6 et St7-7 restent pour R84 (les noms d'aujourd'hui gardés ici).

- [ ] **Step 1 : tests, rouges**
  - `backofficeAccessRefusal.spec.ts` : `it("au montage, l'écriture comptée du compte courant est dite")` ;
    `it("au montage, l'écriture comptée d'un autre compte remet tout à zéro")` (expected : l'avis vrai) ;
    `it("après le montage, une lecture refusée garde l'avis et fait avancer le compteur")` (expected : l'avis faux) ;
    `it("après le montage, une écriture refusée ramène le texte courant")` (témoin).
  - `AccessRefusedNotice.spec.ts` (Review Focus 1) : `it("l'avis de l'enregistrement précédent reste quand la lecture de la page d'arrivée est refusée")`
    (écriture comptée sous A, montage sous A, puis un refus de lecture au périmètre courant : texte FR exact de
    `common.earlierSaveRefused`, le `<span>` réannoncé) ; (Review Focus 2) `it("une écriture refusée sous A, B connecté dans l'onglet : aucune alerte au montage")`.
  - `backofficeSessionExpiry.spec.ts` : `it("refused transmet le compte du timbre au comptage")`.
  Expected : les rouges nommés ci-dessus ; les specs qui appellent `markBackofficeAccessRefused` ou `settle…` sans le
  nouveau paramètre gagnent leur compte (changement de signature).
- [ ] **Step 2 : implémenter** selon les interfaces.
- [ ] **Step 3 : règle** — la sous-puce de R64 : « a **write**'s is shown with its own text, `common.earlierSaveRefused`
  (…), the alert inserted after the mount so that it is announced (`frontend-a11y.md`); the next refusal on the page
  brings back `common.accessRefused`. » → « a **write**'s is shown with its own text, `common.earlierSaveRefused` (…),
  **only to the account that sent it** (R76, decision 2 of the seventh review: another account at the mount resets
  everything), the alert inserted after the mount so that it is announced (`frontend-a11y.md`); a later refused **read**
  keeps that text and re-announces it (the landing page's own load, CR7-4), a later refused **write** brings back
  `common.accessRefused`. » ; « Tests » : « a write refused after the layout unmounted shows at the next mount with
  `common.earlierSaveRefused`, a read's is forgotten, R64 » → « … a read's is forgotten, R64; another account at the
  mount sees nothing, a read refused after the mount keeps the text, R76 ».
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `fix(frontend): l'enregistrement refusé pendant l'absence n'est dit qu'au compte qui l'a envoyé, et la lecture refusée de la page d'arrivée ne le fait plus taire (#748)`.

---

### Task R77 : L'identité du compte d'un refus écrite comme le code la juge (CR7-2/C7-5/Sp7-6, CR7-3)

**Files :**
- Modify (docblocks et commentaire) : `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts` (docblock d'`account`, l. 9-14 ; de `refused`, l. 54-61), `frontend/src/presentation/router/backofficeSessionExpiry.ts` (en-tête l. 31-33, commentaire l. 49-53), `frontend/src/application/auth/useAuth.ts` (docblock de `lastAccountName`)
- Test : `frontend/tests/presentation/router/backofficeSessionExpiry.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-refus.md` (sous-puce « **the account** »)

**Interfaces :**
- Aucun changement de comportement. Le texte, partout le même en substance : l'identité est le **nom** du dernier
  compte affiché ; un refus est écarté quand le compte relevé à l'envoi et celui de la réception diffèrent — `null`
  d'un seul côté compris (un compte puis aucun, ou l'inverse) ; `null` des deux côtés est le même « aucun » et compte ;
  le même nom après une déconnexion puis une reconnexion, ou après un rôle perdu, est le même compte (voulu : le 403
  d'un compte rétrogradé est ce qu'il faut dire) ; **limites** : un compte supprimé puis recréé sous le même nom passe
  pour le même (C8 de la quatrième série, ticket `__Host-` de la tâche 12) ; `null` à l'envoi puis un compte écarte le
  refus, cas sans chemin (le garde du backoffice attend la première vérification, CR7-3).

- [ ] **Step 1 : tests de caractérisation** — `it("aucun compte à l'envoi ni à la réception : le refus est compté")`,
  `it("aucun compte à l'envoi, un compte à la réception : le refus est écarté (limite écrite)")`,
  `it("déconnexion puis reconnexion au même nom entre l'envoi et le refus : le refus est compté")`. Expected : verts
  (ils épinglent le comportement d'aujourd'hui).
- [ ] **Step 2 : rouge vu par mutation** — la condition de `refused()` changée en `null === sentAt.account ||
  sentAt.account !== lastAccountName()` → le premier test rougit ; restauré par copie vérifiée par `cmp`.
- [ ] **Step 3 : textes** — selon les interfaces. Règle : « a refusal is dropped — neither counted nor re-read, read or
  write — only when that account has changed (another account, or none after the base access). » → « a refusal is
  dropped — neither counted nor re-read, read or write — only when the account at send time and the account now
  differ, `null` on one side included (none on both sides is the same « none » and counts); the identity is the
  **name** (R77): the same name after a sign-out and a sign-in, or after a lost role, is the same account, on purpose;
  a deleted account recreated under the same name passes for the same (accepted, C8, the `__Host-` ticket); `null` at
  send time then an account drops the refusal, with no path to it (the backoffice guard waits for the first check). »
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `docs(frontend): l'identité du compte d'un refus du backoffice écrite comme le code la juge, ses limites dites (#748)`.

---

### Task R78 : Formulaires par entrée — une proposition verrouille la langue du formulaire, « Annuler » la retire (Sp7-1 : études de cas, incidents, contributions, CV sans identité)

**Files :**
- Modify : `frontend/src/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,AdminAnonymousCvPage}.vue`
- Test : `frontend/tests/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,AdminAnonymousCvPage}.spec.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (l. 176-181, la phrase « Since spec 0004 every per-entry form … names the proposal's source locale. »)

**Interfaces :**
- Dans chaque page : le `BaseSelect` de la langue `:disabled="isEditing || null !== proposalSourceLocale"` ; le bouton
  « Annuler » existant `v-if="isEditing || null !== proposalSourceLocale"` (il appelle `resetForm`, qui retire la
  proposition et l'avis). Un commentaire au-dessus du sélecteur : la proposition est la version de l'entrée
  source dans l'autre langue, son groupe gardé ; en changer la langue créerait une seconde version de la langue source (Sp7-1).

- [ ] **Step 1 : tests, rouges** (Review Focus 3), dans chacun des quatre specs :
  `it("après une proposition, le sélecteur de langue est désactivé et « Annuler » est affiché")` ;
  `it("« Annuler » après une proposition rend un formulaire vide, le sélecteur actif, sans avis")`. Expected : le
  sélecteur actif, « Annuler » absent.
- [ ] **Step 2 : implémenter** selon les interfaces.
- [ ] **Step 3 : règle** — après « a `role="status"` banner names the proposal's source locale » : « ; while the form
  holds the proposal its locale selector is locked, as in editing, and « Annuler » drops it (R78, R79, Sp7-1: the
  kept group would make a second version of the source locale) ».
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `fix(frontend): une proposition de traduction verrouille la langue du formulaire et « Annuler » la retire, études de cas, incidents, contributions, CV sans identité (#748)`.

---

### Task R79 : Formulaires par entrée — le même verrou pour les principes et traits qualité et les cartes « À propos » (Sp7-1, AdminQualityPage vu en vérifiant)

**Files :**
- Modify : `frontend/src/presentation/pages/admin/AdminQualityPage.vue` (deux formulaires), `frontend/src/presentation/pages/admin/AdminAboutMeCardsSection.vue`, `frontend/src/presentation/pages/admin/AdminAboutSiteCardsSection.vue`
- Test : `frontend/tests/presentation/pages/admin/AdminQualityPage.spec.ts`, `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts`

**Interfaces :**
- Comme R78, sur chaque formulaire, avec sa variable : `principleProposalSourceLocale`, `traitProposalSourceLocale`,
  `proposalSourceLocale` ; dans `AdminAboutMeCardsSection.vue`, seul le sélecteur de **langue** est verrouillé (l'autre
  `:disabled="isEditing"`, l. 384, est à lire : s'il n'est pas celui de la langue, il reste tel quel ; le dire).

- [ ] **Step 1 : tests, rouges** — pour chacun des quatre formulaires (principe, trait, carte « moi », carte « site ») :
  `it("<formulaire> : après une proposition, la langue est verrouillée et « Annuler » la retire")`. Expected : le
  sélecteur actif.
- [ ] **Step 2 : implémenter** selon les interfaces.
- [ ] **Step 3 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`.
- [ ] **Step 4 : commit** — `fix(frontend): le même verrou de langue après une proposition, principes et traits qualité, cartes « À propos » (#748)`.

---

### Task R79b : Formulaires par entrée — le verrou de la langue est expliqué, la catégorie des cartes « moi » est verrouillée par une proposition (D-a et D-b, décidées par l'auteur pendant la série, 2026-10-30)

Tâche **ajoutée pendant la série**, après R79 (qui verrouille la langue sans l'expliquer et laisse la catégorie libre).

- **D-a** : sur les huit formulaires de R78 et R79, **en édition comme après une proposition**, le sélecteur de langue
  désactivé reçoit un `aria-describedby` vers un texte rendu **seulement pendant le verrou** (convention de
  `lockedHintId`, `AdminCaseStudiesPage.vue` : jamais un `title`, jamais une référence vers un élément absent). Deux
  textes partagés, FR et EN : `admin.localeLockedEditing` (« La langue d'une entrée existante ne peut pas être
  modifiée. ») et `admin.localeLockedProposal` (« La langue est celle de la proposition de traduction ; « Annuler »
  permet d'en changer. »). Composant `presentation/ui/admin/LocaleLockedHint.vue` (`id`, `isEditing`, `hasProposal`).
- **Aides permanentes existantes** (`admin.quality.localeHelp`, `admin.about.localeHelp`, restées vraies) : **le texte
  du verrou s'y ajoute**, un `aria-describedby` à deux ids (`<aide> <verrou>`) pendant le verrou, un seul (l'aide)
  hors verrou ; le texte du verrou est rendu après l'aide. Sans aide permanente (études de cas, incidents,
  contributions, CV sans identité) : un seul id pendant le verrou, aucun attribut hors verrou.
- **D-b** : dans `AdminAboutMeCardsSection.vue`, le sélecteur de **catégorie** est aussi désactivé tant que le formulaire
  porte une proposition (`isEditing || null !== proposalSourceLocale`), avec `admin.about.meCard.categoryProposalLockedHelp`
  (même id d'aide que l'édition, texte selon le cas). Raison : une version dans l'autre langue ne change pas de
  catégorie (#501, #561, comme en édition #578) ; la changer déclenchait le watcher `form.category` (`flush: 'sync'`),
  qui vidait « Version de », et l'enregistrement créait une carte détachée de sa source avec la prose générée.
  « Créer la version XX » (`startCreateVersion`) reste tel quel (catégorie libre) : limite écrite en une phrase.

**Files :**
- Create : `frontend/src/presentation/ui/admin/LocaleLockedHint.vue`, `frontend/tests/support/localeLockHint.ts`
- Modify : `frontend/src/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,AdminAnonymousCvPage,AdminQualityPage,AdminAboutMeCardsSection,AdminAboutSiteCardsSection}.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`
- Test : `frontend/tests/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,AdminAnonymousCvPage,AdminQualityPage,AdminAboutPage}.spec.ts` (par formulaire : verrou expliqué en édition et pas dans un formulaire libre ; après une proposition, expliqué, et « Annuler » retire l'explication ; carte « moi » : catégorie désactivée après une proposition, libre après « Annuler » et dans « Créer la version XX » ; l'assertion de R79 « la catégorie reste active après une proposition » change exprès)
- Modify (règle) : `.claude/rules/frontend-backoffice.md`

- [ ] Tests rouges vus (18, pour la bonne raison : texte du verrou absent, catégorie active), implémentation, verts ; mutation du test « Créer la version » (catégorie verrouillée par `sourceGroup`) vue rouge puis restaurée (`cmp`).
- [ ] Commit — `fix(frontend): le verrou de la langue est expliqué sur les huit formulaires par entrée, la catégorie des cartes « moi » est verrouillée par une proposition (#748)`.

---

### Task R80 : Les gardes de source lisent l'arbre de TypeScript et les seuls `<script>` d'un `.vue` (CR7-7/C7-11 ; St7-9 pour `scan()` ; Sp7-8)

**Files :**
- Modify : `frontend/tests/support/sourceText.ts`, `frontend/tests/support/sourceText.spec.ts`, `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session.md` (l. 158, puce `noOmittedCredentials.spec.ts`)

**Interfaces :**
- `export function scriptOf(path: string, source: string): string` — pour un chemin en `.vue`, `parse(source, {
  filename: path })` de `vue/compiler-sfc` : les contenus de `descriptor.script` et `descriptor.scriptSetup` gardés à
  leur décalage (`loc.start.offset`), tout le reste remplacé par des espaces, les `\n` gardés ; tout autre chemin :
  la source telle quelle.
- `export function withoutComments(source: string): string` — `ts.createSourceFile('source.ts', source,
  ts.ScriptTarget.Latest, true, ts.ScriptKind.TS)` ; chaque jeton atteint par `getChildren(file)` récursivement (le
  jeton de fin de fichier compris) ; les plages de `ts.getLeadingCommentRanges(source, token.getFullStart())`
  remplacées par des espaces, les `\n` gardés.
- `export function stringLiterals(source: string): string[]` — `node.text` de chaque `StringLiteral` et
  `NoSubstitutionTemplateLiteral` (échappements lus : `'om\x69t'` donne `omit`).
- `scan()`, ses trois fermetures et l'interface `Scan` disparaissent. Docblock : pourquoi l'arbre (CR7-7 : deux regex
  réelles de `src/` trompaient le parcours), ce qu'il coûte (l'API du compilateur ; un passage à TypeScript 7 la
  changera, la garde rougira) ; les limites de R68 retirées (Sp7-8 : une chaîne non terminée est refusée par
  `make front-build`).
- Les deux gardes appellent `scriptOf(path, source)` avant `stringLiterals` / `withoutComments`.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté, et la durée de `noOmittedCredentials.spec.ts`
  et `sessionStateEncapsulation.spec.ts` (sortie de vitest).
- [ ] **Step 2 : tests, rouges** — `sourceText.spec.ts` : `` stringLiterals("const r = /`([^`]+)`/g\nconst m = 'omit'") ``
  vaut `['omit']` (la forme de `RichText.vue:36` ; expected : `[]` ou autre, le gabarit ouvert avale `'omit'`) ;
  `withoutComments("const r = /^\\/*$/\nimport { x } from './sessionState'")` garde l'import (expected : avalé par le
  faux `/*`) ; `stringLiterals(scriptOf('X.vue', "<template><p>l'accès // ici</p></template>\n<script setup lang=\"ts\">\nconst m = 'omit'\n</script>\n"))`
  vaut `['omit']`, et `scriptOf` rend le même nombre de lignes que la source ; `stringLiterals("'om\\x69t'")` vaut
  `['omit']`. Les tests « une chaîne non terminée s'arrête à la fin de sa ligne » et « garde les échappements tels
  quels » sont **remplacés** (changement exprès : le premier par celui de `scriptOf`, le second par celui de
  l'échappement lu).
- [ ] **Step 3 : implémenter** selon les interfaces.
- [ ] **Step 4 : rouge vu par mutation** — `const mode = 'omit'` ajouté dans le `<script>` de `RichText.vue`, après sa
  regex → `noOmittedCredentials.spec.ts` rouge (avant R80, la regex le masquait : le vérifier en lançant la mutation
  sur la copie d'avant si besoin) ; restauré par copie vérifiée par `cmp`.
- [ ] **Step 5 : règle** — « read by `tests/support/sourceText.ts`, which knows strings from comments, R68 » → « read by
  `tests/support/sourceText.ts` on TypeScript's syntax tree, a `.vue` by its `<script>` blocks only, R68, R80 ».
- [ ] **Step 6 : verts** — `make front-test; echo "exit=$?"` (filet + les nouveaux − les deux remplacés ; durées des
  deux gardes notées), `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 7 : commit** — `test(frontend): les gardes de source lisent l'arbre de TypeScript et les seuls blocs <script> d'un .vue (#748)`.

---

### Task R81 : Backend — le détecteur d'autorisations découpé, ses natures typées, ses limites écrites (St7-8, St7-9 pour `in()`, Sp7-7, décision 1 : C7-1 et C7-2 pour les limites, C7-13 ; refactoring)

**Files :**
- Create : `backend/tests/Support/AuthorizationSiteKind.php`
- Modify : `backend/tests/Support/AuthorizationSites.php`, `backend/tests/Security/AuthorizationLivesInAccessControlTest.php`
- Aucune règle : `AuthorizationSites.php` n'est dans les `paths:` d'aucune (vérifié le 2026-10-30), le fichier créé non plus.

**Interfaces :**
- `enum AuthorizationSiteKind: string { case IsGrantedAttribute = 'IsGranted'; case DenyAccessUnlessGranted =
  'denyAccessUnlessGranted'; case SecurityArgument = 'security:'; case IsGrantedCall = 'isGranted'; case Refusal =
  'refusal'; case DecisionManager = 'decisionManager'; case IsGrantedExpression = 'is_granted'; }`.
- `AuthorizationSites::in(string $code): list<array{int, AuthorizationSiteKind}>` ; le corps : un parcours qui demande
  à `private static function kindAt(array $tokens, int $index): ?AuthorizationSiteKind`, qui délègue à trois lectures
  privées (`ofStringLiteral`, `ofName`, `ofCall`), chacune de quinze lignes au plus. Comportement identique.
- `EXEMPTIONS` : `[AuthorizationSiteKind::IsGrantedCall->value => 1]`, etc. ; les fournisseurs rendent les cas de
  l'enum (changement de type, pas de comportement).
- Docblock d'`AuthorizationSites` (décision 1) : « **Ne voit pas** (le détecteur n'est pas élargi, décision 1 de la
  septième relecture) : un `#[CurrentUser]` non nullable, `createAccessDeniedException()`, `new HttpException(403)`,
  `InsufficientAuthenticationException`, `isGrantedForUser()` et `UserAuthorizationCheckerInterface`, une exception de
  refus importée sous un alias (`use …\AccessDeniedHttpException as Denied`, C7-2), un callable `[$security,
  'isGranted']` (C7-2), un appel dynamique, une sous-classe des trois exceptions, une réponse 403 écrite à la main,
  une exception métier rendue en 401 ou 403 (`exception_to_status`), le vendor et la configuration. Sur une route hors
  de `PUBLIC_PATHS`, l'invariant n° 9 d'`ApiRouteExposureTest` voit toutes ces formes à l'exécution (une route sans
  règle y rougit) ; sur une route de `PUBLIC_PATHS`, aucune : limite écrite dans la source des témoins. » ; « Une
  exemption compte les sites **par nature et par fichier**, pas site par site (C7-13) : un site déplacé dans le
  fichier exempté, ou remplacé par un autre de même nature, reste couvert. » `AuthorizationLivesInAccessControlTest` :
  son paragraphe « Non vus » remplacé par un renvoi au docblock d'`AuthorizationSites`.
- Lignes ≤ 140 caractères dans les trois fichiers (Sp7-7 : `EXEMPTIONS` sur plusieurs lignes, fournisseurs coupés,
  le `sprintf` de la l. 169 sur plusieurs lignes).

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`, nombre de tests noté ; la classe ciblée verte.
- [ ] **Step 2 : refactorer** selon les interfaces ; `make back-style-fix`.
- [ ] **Step 3 : verts** — la classe ciblée verte avec le même nombre de cas ; `make back-test; echo "exit=$?"` (même
  nombre que le filet), `make back-quality; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ; les
  longueurs mesurées (`python3 -I`, en caractères) : aucune ligne au-delà de 140 dans les trois fichiers.
- [ ] **Step 4 : commit** — `refactor(security): le détecteur d'autorisations découpé, ses natures en enum, ce qu'il ne voit pas écrit avec le renvoi au contrôle à l'exécution (#748)`.

---

### Task R82 : Backend — l'invariant n° 8 lit les méthodes non sûres du double-submit (St7-10 ; refactoring)

**Files :**
- Modify : `backend/src/Security/Authentication/Infrastructure/Http/CsrfCookieRequestSubscriber.php` (`UNSAFE_METHODS` : `public`, docblock : lue par `ApiRouteExposureTest`), `backend/tests/Security/ApiRouteExposureTest.php` (l. 343)

**Interfaces :**
- `answerWithBearer()` : `if (\in_array($method, CsrfCookieRequestSubscriber::UNSAFE_METHODS, true))` remplace
  `if (!\in_array($method, ['GET', 'HEAD', 'OPTIONS'], true))`, le commentaire dit pourquoi (la même liste que la
  garde, une seule source). Un autre test de `backend/tests` qui recopie la liste (`grep -rn "'GET', 'HEAD'"`) est
  noté au rendu, pas changé.

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`.
- [ ] **Step 2 : refactorer** ; `make back-style-fix` après l'import.
- [ ] **Step 3 : verts** — `make back-test; echo "exit=$?"` (même nombre), `make back-quality; echo "exit=$?"`.
- [ ] **Step 4 : commit** — `refactor(security): l'invariant n° 8 lit les méthodes non sûres du double-submit au lieu de les recopier (#748)`.

---

### Task R83 : Outillage — les en-têtes multiples lus par la bibliothèque, l'amorçage commenté une fois, des valeurs et des noms de test sans piège (St7-3, St7-4, St7-13, St7-7 pour `tree()` ; refactoring)

**Files :**
- Modify : `tools/lib/smoke-http.sh`, `tools/smoke-dead-session-cookie.sh`, `tools/smoke-login-throttling.sh`, `tools/smoke-mcp.sh`, `tools/audit-prod.sh`, `tools/check-tls-expiry.sh`
- Test : `tools/tests/smoke-http.test.sh`, `tools/tests/audit-prod.test.sh`

**Interfaces :**
- `smoke_headers <fichier> <nom>` : toutes les valeurs de l'en-tête, une par ligne, nom sans casse, CR retiré ;
  `smoke_header` = `smoke_headers "$1" "$2" | tail -n 1` (vide si absent) ; `set_cookies()` de
  `smoke-dead-session-cookie.sh` devient `smoke_headers "$headers_file" Set-Cookie`.
- Les sept blocs `source … || { …; exit 2; }` restent (l'amorçage) ; chacun précédé d'**une** ligne identique :
  `# Sortie 2 si la bibliothèque manque ou ne se lit pas (sans -e, un source en échec continuerait) ; stderr de bash visible.`
  Les commentaires de deux à quatre lignes (quatre) disparaissent ; une phrase propre au script (pourquoi la
  bibliothèque n'est sourcée que dans un bloc, `audit-prod.sh`) reste.
- `audit-prod.test.sh` : `AUDIT_BASIC_AUTH='u:p'` (St7-13) ; `tree()` → `copy_tools()` et ses six appels (St7-7).

- [ ] **Step 1 : filet** — les cinq tests hors ligne à 0, sorties notées.
- [ ] **Step 2 : test, rouge** — `smoke-http.test.sh` : « `smoke_headers` rend les deux `Set-Cookie` d'un fichier, sans
  casse, CR retiré ». Expected : fonction introuvable.
- [ ] **Step 3 : refactorer** selon les interfaces.
- [ ] **Step 4 : verts** — les cinq tests hors ligne à 0 (mêmes sorties que le filet, plus le nouveau) ; shellcheck
  (image épinglée) sur les fichiers touchés ; `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `refactor(tools): les en-têtes multiples lus par la bibliothèque, l'amorçage des bibliothèques commenté d'une ligne, des valeurs et des noms de test sans piège (#748)`.
- [x] **Décision 6 (R75), fait en R83** : `smoke_write_auth_config` appelle `smoke_cleanup` en tête (avant la validation du nom et avant son dossier) ; un second appel remplace le premier ; test deux appels successifs et dossier hérité jamais supprimé.

---

### Task R84 : Le refus du backoffice tient un seul état, nommé comme sa clé (St7-6, St7-7 pour `earlierWrite`, St7-10 suite pour le timbre ; refactoring)

**Files :**
- Modify : `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue`
- Test : `frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`, `frontend/tests/infrastructure/admin/users/HttpAdminUserRepository.spec.ts`
- Aucune règle : aucune ne nomme `earlierWrite` ni `earlierWriteRefused` (vérifié le 2026-10-30).

**Interfaces :**
- `interface RefusalState { readonly count: number; readonly earlierSave: boolean; readonly countedWriteBy: { readonly
  account: string | null } | null }` ; `const NO_REFUSAL: RefusalState` ; `const state = ref<RefusalState>(NO_REFUSAL)`,
  remplacé en bloc à chaque transition ; `export const backofficeAccessRefused = computed(() => state.value.count)` ;
  `export const earlierSaveRefused = computed(() => state.value.earlierSave)` (St7-7 : remplace `earlierWriteRefused`).
  `clearBackofficeAccessRefusal()` : `state.value = NO_REFUSAL` puis `scope += 1` ; la remise des tests : `NO_REFUSAL`
  et `scope = 0`. Le périmètre reste à part (autre préoccupation, autre remise).
- `HttpAdminUserRepository.spec.ts` : `const readStamp = (): BackofficeSendStamp => ({ session: 0, account: null,
  refusal: { kind: 'read', scope: 0 } })` (les valeurs d'aujourd'hui) remplace les trois littéraux des
  `stampAtSend` (l. 33, 43, 54).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : refactorer** selon les interfaces (les specs qui importent `earlierWriteRefused` suivent le nom).
- [ ] **Step 3 : verts** — `make front-test; echo "exit=$?"` (même nombre), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 4 : commit** — `refactor(frontend): le refus du backoffice tient un seul état, l'avis nommé comme sa clé, un timbre de test écrit une fois (#748)`.

---

### Task R85 : La vérification de session sans drapeau inutile ; la façade sans réexport de test (St7-2, St7-11 ; refactoring)

**Files :**
- Modify : `frontend/src/application/auth/sessionState.ts` (`hasCheckedOnce`, `beginCheck`, docblocks l. 19 et 42-56), `frontend/src/application/auth/useAuth.ts` (l. 8, 29, 142, 230-231)
- Test : `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts` (table `WRITERS` et liste l. 40-49, 166 : changement exprès), `frontend/tests/presentation/router/backofficeSessionExpiry.spec.ts` (l. 11 : `isCurrentGeneration` importé de `sessionGeneration`)
- Modify (règles) : `.claude/rules/frontend-session-retour.md` (l. 27, la liste des écritures sans `beginCheck` ; l. 46-50), `.claude/rules/frontend-session-refus.md` (l. 104-105)

**Interfaces :**
- `beginCheck()` et `hasCheckedOnce` retirés ; `markFirstCheckDone()` abaisse `isChecking` (qui naît vrai) ; `checkAuth()`
  n'appelle plus rien avant sa lecture. `isCurrentGeneration` retiré du bloc `export { … }` de `useAuth.ts`.
- Textes : `onAccountSwitched` (l. 142) — « ou, hors session finie, la relecture qui suit un refus réel
  (`rereadAfterRefusal()`) trouve **un autre identifiant** » ; `sessionState.ts` — « `isChecking` naît vrai et retombe
  à la fin de la première vérification (`markFirstCheckDone`) ; aucune relecture ne le touche » ; règle retour, l. 46-50
  → « **It is silent: `isChecking` is never touched.** `isChecking` is born `true` and lowered once, when the load's
  `checkAuth()` ends (`markFirstCheckDone()`, the one `waitForAuthCheck` waits for); no re-read raises it (this one, the
  re-read after a real refusal). Raised on each return, it would hide … » (la suite inchangée) ; règle refus,
  l. 104-105 : « (R8: every `checkAuth()` after the first is silent, … ) » → « (R8: the re-reads never touch
  `isChecking`, … ) ».

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : refactorer** selon les interfaces ; la garde `sessionStateEncapsulation.spec.ts` perd la ligne de
  `beginCheck` (le dire au rendu).
- [ ] **Step 3 : verts** — `make front-test; echo "exit=$?"` (même nombre), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 4 : commit** — `refactor(frontend): la vérification de session sans drapeau inutile, la façade sans réexport que seul un test lisait (#748)`.

---

### Task R86 : Réglages « À propos » — l'enregistrement en vol lu à une source, l'avis comparé une fois, le cas CR7-8 écrit (CR7-10, St7-10 suite, CR7-8 pour le commentaire ; refactoring)

**Files :**
- Modify : `frontend/src/application/admin/about/useAdminAboutSettings.ts` (l. 75), `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue` (l. 87, 141-144, 220, 227)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (l. 253-256)

**Interfaces :**
- `isSaving = computed(() => SUPPORTED_LOCALES.some((locale) => isCurrentKey(locale) && isSavingLocale(locale)))`.
- `const isProposalShown = computed(() => proposal.value?.target === props.locale)` : lu par le `watch` de `settings`
  et par le `v-if` de l'avis.
- `TranslateEntryButton` : `:disabled="!hasProseToTranslate || isSavingLocale(locale) || isSavingLocale(otherLocale(locale)) || isLocked"`
  (CR7-10 : même valeur, le `watch` immédiat appelle `load(props.locale)`, qui fait de `props.locale` la clé courante).
- Commentaire de `handleSubmit` (CR7-8) : la réponse réussie d'un enregistrement de la langue montrée passe par le
  `watch`, qui retire l'avis ; montrée plus tard, la langue est relue au retour avant tout rendu (le formulaire attend
  `isLoaded`), ce qui le retire aussi ; un refus ne relit rien et le garde (F11).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : refactorer** selon les interfaces.
- [ ] **Step 3 : règle** — « The form disables « Enregistrer » for the locale shown only (`isSaving`: an English save
  is not held by a French one), and « Proposer la traduction » while the locale shown **or the target locale** has a
  save in flight (`isSavingLocale`, R67, … ) » → « The form disables « Enregistrer » for the locale shown only
  (`isSaving`, read on `isSavingLocale`: an English save is not held by a French one), and « Proposer la traduction »
  while the locale shown **or the target locale** has a save in flight (`isSavingLocale` of both, R67, R86, … ) ».
- [ ] **Step 4 : verts** — `make front-test; echo "exit=$?"` (même nombre), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `refactor(frontend): réglages « À propos », l'enregistrement en vol lu à une source et l'avis comparé une fois (#748)`.

---

### Task R87 : Balayage documentaire de la septième série

Les docstrings et les règles ont suivi chaque comportement dans son commit (contrainte de la série) ; cette tâche
vérifie qu'il ne reste rien.

**Files :** ceux que le balayage désigne (`.claude/rules/*`, `docs/adr/0003-paliers-d-acces.md`, docblocks de `frontend/src`, `frontend/tests`, `backend/src`, `backend/tests`, `tools/`).

- [ ] **Step 1 : balayage** — `grep -rn "seuls témoins\|only witnesses\|39 classes\|smoke_auth_config_file\|CURL_AUTH_CONFIG\|earlierWrite\|writeCountedSinceClear\|hasCheckedOnce\|beginCheck\|isRefusedByTheApiFirewall\|Huit invariants\|secret-user\|every later .checkAuth\|after the first is silent\|ou sous aucun" .claude docs frontend/src frontend/tests backend/src backend/tests tools`
  (les fichiers de secrets exclus) : ne rend que l'historique daté ou le sens juste ; le noter ligne par ligne. Toute
  phrase fausse trouvée est **remplacée**.
- [ ] **Step 2 : verts** — `tools/check-claude-rules.sh; echo "exit=$?"` ; taille de chaque règle touchée par la série
  notée, toutes < 50 000 octets, `backoffice-api.md` sous ses 39 946 octets de départ ; `make front-test; echo "exit=$?"`
  si un fichier de `frontend/` a changé, `make back-test; echo "exit=$?"` si un de `backend/`, les tests hors ligne si
  un de `tools/`.
- [ ] **Step 3 : commit** (seulement si le balayage a changé un fichier) — `docs: le balayage de la septième relecture de #748`.
  Sinon, le rendu dit « rien à changer » et les sorties du grep.

### Traçabilité des constats

| Constat | Traitement |
|---|---|
| C7-1 (= CR7-6) | R73 (invariant n° 9) ; limites du détecteur écrites R81 |
| C7-6 (= CR7-1) | R75 |
| CR7-4 | R76 |
| C7-4 | R76 |
| CR7-2 (= C7-5 = Sp7-6) | R77 (limite écrite, textes alignés) ; l'UUID reste au ticket C8 (tâche 12) |
| CR7-3 | R77 (limite écrite, test) |
| C7-2 | R81 (limite écrite, sans élargissement) |
| C7-3 | R74 |
| St7-1 | R75 |
| St7-2 | R85 |
| CR7-7 (= C7-11) | R80 |
| CR7-8 | R86 (commentaire) ; code écarté (ci-dessous) |
| Sp7-1 | R78, R79 |
| Sp7-7 | R74, R81 |
| St7-3 (= CR7-9) | R83 |
| St7-4 | R83 (commentaires) ; blocs gardés (ci-dessous) |
| St7-5 | R73 |
| St7-6 | R84 |
| St7-9 | R81 (`in()`), R80 (`scan()`) |
| St7-10 | R82 |
| Sp7-5 (= St7-12) | R73 |
| CR7-5 (= C7-12) | écarté (ci-dessous) |
| CR7-10 | R86 |
| C7-7, C7-8, C7-9 | R75 |
| C7-10 | R73 |
| C7-13 | R81 (limite écrite) ; comptage par site écarté (ci-dessous) |
| St7-7 | R73 (`$isRefusedByTheApiFirewall`), R84 (`earlierWrite`), R83 (`tree()`) |
| St7-8 | R81 |
| St7-10 suite | R86 (`isSaving`, `proposal`), R84 (timbre) |
| St7-11 | R85 |
| St7-13 | R83 |
| St7-14 | R74 |
| St7-15 | R73 |
| Sp7-2, Sp7-3, Sp7-4 | notes d'archive, commit de ce plan |
| Sp7-8 | R80 (la limite disparaît) |
| Sp7-1 suite, décisions D-a, D-b de l'auteur (pendant la série) | R79b |
| Décision 6 (`smoke_write_auth_config` appelle `smoke_cleanup` en tête) | R75 → R83 |

### Constats écartés, avec leur raison

- **CR7-5 (= C7-12)** (`checkAuth` laisse remonter une erreur levée après la réponse, état appliqué à moitié). Pour un
  changement : aucun rejet non géré, aucun état partiel. Contre : c'est le choix n° 8 de la sixième série (R65) — une
  erreur de programmation doit se voir, et l'avaler la confondait avec un réseau muet ; l'état à moitié appliqué
  suppose un défaut de code, que le rejet visible signale. Rien d'exploitable. Écarté.
- **C7-13, comme changement de code** (exemptions comptées par site). Pour : un site déplacé ou remplacé dans un fichier
  exempté se verrait. Contre : une clé par ligne casserait à chaque modification voisine ; une clé par méthode
  englobante demanderait au détecteur de suivre les méthodes, un élargissement que la décision 1 exclut ; le nombre
  exact par nature borne déjà ce qu'une exemption couvre. Limite écrite (R81). Écarté.
- **St7-4, pour les blocs `source`** (sept copies). Pour : une seule écriture. Contre : une fonction qui sourcerait une
  bibliothèque devrait elle-même être sourcée, par le même bloc ; chaque bloc fait quatre lignes et chacun a son test
  hors ligne (« introuvable ou invalide »). Les commentaires, eux, sont réduits (R83). Écarté pour le code.
- **CR7-8, pour le code** (retirer `proposal` à la réponse réussie de sa cible quand une autre langue est montrée). Pour :
  l'état en mémoire serait juste. Contre : aucun état visible n'est faux (l'avis vit dans le formulaire, rendu
  seulement une fois `isLoaded`, et chaque retour relit `settings`, ce qui le retire) ; aucun test ne pourrait rougir
  pour la bonne raison. Le commentaire faux est corrigé (R86). Écarté pour le code.
- **CR7-2, l'option UUID**. Pour : un compte recréé sous le même nom ne passerait plus pour le même. Contre : change le
  contrat de `/api/me` (backend, présentateur, frontend) pour un cas étroit ; c'est C8 de la quatrième série, rattaché
  au ticket `__Host-` que la tâche 12 propose à l'auteur. Limite écrite (R77).
- **Q1, l'option « une route de `PUBLIC_PATHS` ne répond jamais 401/403 »** : écartée sur recommandation (CR6-6) ; elle
  revient si l'auteur la choisit.

---


## Corrections de la huitième relecture (2026-10-30)

R73 à R87 et R79b sont faites (commits 1513e4fa → af12b068).

Quatre relectures du diff 181d78d6...af12b068 (`/code-review medium` CR8-*, audit de sécurité C8-*, axe Standards
St8-*, axe Spec Sp8-*) : aucun critique ni haut, un moyen (conception). Constats complets et décisions :
`tasks/relectures-748/relecture-8-constats.md` (la source de vérité). Tous sont traités ici par R88 à R101, avant les
tâches 11 et 12 : corrigés, écrits comme limite, ou écartés avec leur raison (ci-dessous). Les sections passées restent
l'archive de leur moment : la note datée de Sp8-8 est posée au choix tranché 4 de la septième série, dans le commit de ce
plan, et les points de Sp8-1 sont ajoutés aux tâches 11 et 12 dans ce même commit.

### Décisions de l'auteur (2026-10-30)

1. **CR8-1 : désactiver le bouton.** « Proposer la version XX » est désactivé tant qu'une proposition est affichée, sur
   les huit formulaires par entrée, avec le texte d'aide du verrou ; pour retraduire, « Annuler ». R93.
2. **CR8-4 : corriger le texte.** Les textes d'aide FR/EN disent qu'« Annuler » abandonne la proposition et vide le
   formulaire ; `resetForm` ne change pas. R93.
3. **Sp8-5 : verrouiller « Version de ».** Le groupe de traduction est verrouillé pendant une proposition sur les huit
   formulaires, avec un texte d'aide lié comme pour la langue (cohérent avec D-b). R94.
4. **CR8-3 : borner au chargement.** Seule la première lecture refusée après le montage garde l'avis « enregistrement
   précédent » ; les suivantes reprennent `common.accessRefused`. Le code suit le docblock. R95.
5. **Toujours valables** : C5-1, C5-2, C7 (limite de la session), CR6-6, l'identité du compte par son nom (CR7-2), les
   décisions des séries 4 à 7 (dont D-a, D-b et la décision 6 de la septième) ; critère d'arrêt **tout jusqu'à zéro** :
   chaque constat, info compris, est corrigé, écrit comme limite, ou écarté avec sa raison. La section « Sans suite » du
   fichier de constats est tranchée (reprise ci-dessous, « Constats écartés »).

### Vérification de chaque constat dans le code (HEAD 10804c00)

- **CR8-7 (= St8-3 = St8-4), confirmé.** Dans chacun des sept fichiers (huit formulaires), le prédicat est écrit trois
  fois : `isLocaleLocked` (par exemple `AdminCaseStudiesPage.vue:98`), `:has-proposal="null !== proposalSourceLocale"`
  (l. 310) et le `v-if="isEditing || hasProposal"` de `LocaleLockedHint.vue:28` ; l'identifiant du texte est écrit en
  littéral deux fois (l. 303 et 308) ; le commentaire de cinq lignes est recopié huit fois, abîmé dans
  `AdminAboutMeCardsSection.vue:366` (« la version de la carte de l'entrée source ») ; le ternaire du texte est refait
  pour la catégorie (`AdminAboutMeCardsSection.vue:413`). Lignes de plus de 140 caractères :
  `AdminAboutSiteCardsSection.vue:291`, `AdminAboutMeCardsSection.vue:374` et `:413`, `AdminQualityPage.vue:518` et `:796`.
- **CR8-1 (= Sp8-4), confirmé.** `TranslateEntryButton` : `:disabled="!hasProseToTranslate || isSubmitting || isOrderDirty"`
  (`AdminCaseStudiesPage.vue:369`, de même dans les sept autres formulaires) ; `handleTranslate` (l. 251-264) remet
  `form.locale` à la cible et `form.translationGroup` au `sourceGroup` : une seconde traduction depuis la proposition
  ramène le formulaire à la langue source, groupe gardé — le cas de Sp7-1. Le serveur refuse (409).
- **CR8-2 (= Sp8-6), confirmé ; le cas « Créer la version XX » suivi d'une proposition, vérifié.** `LocaleLockedHint.vue:10-11`
  (« une proposition de traduction ne se produit que depuis une entrée en édition ») et le commentaire des huit
  sélecteurs (« dans son groupe gardé ») sont faux : `handleTranslate` s'exécute aussi depuis un formulaire vierge
  (`sourceGroup` nul, la proposition part sans groupe) et depuis « Créer la version XX » (`startCreateVersion` pose
  `sourceGroup = row.key` et la langue manquante). Avec deux langues, la cible de ce dernier cas est la langue que le
  groupe a déjà : l'enregistrement prend le 409 `translation-already-exists` (« Ce contenu a déjà une version dans cette
  langue. ») ; même chose depuis l'édition d'une entrée dont le groupe a déjà la langue cible (antérieur, spec 0004). Le
  verrou de la langue y reste juste (la prose proposée est dans la langue cible). **La décision 1 ne change pas ce cas**
  (la première proposition reste permise, seule la seconde est empêchée). **La décision 3 le change** : avant elle,
  « Version de » → « aucune » permettait d'enregistrer la proposition détachée (une seconde entrée de la langue cible
  hors du groupe — ce que D-b et Sp8-5 jugent faux) ; après elle, le 409 est la seule issue de l'enregistrement, et
  « Annuler » la sortie. Écrit comme limite (R94), question Q1 ci-dessous. Depuis un formulaire vierge, « Version de »
  est verrouillé sur « aucune » et la prose source, jamais enregistrée, est remplacée par la proposition (antérieur,
  spec 0004) : limite écrite avec la précédente.
- **CR8-4 (= Sp8-9), confirmé.** `fr.json:65` et `:184`, `en.json:65` et `:184` : « « Annuler » permet d'en changer » ;
  `resetForm` (`AdminCaseStudiesPage.vue:170-181`) vide le formulaire entier, prose générée et groupe compris.
- **Sp8-5, confirmé.** Le `BaseSelect` « Version de » des huit formulaires n'a ni `:disabled` ni `aria-describedby`
  (`AdminCaseStudiesPage.vue:312-317`, `AdminQualityPage.vue:533-536` et `:811-814`, etc.).
- **CR8-3, confirmé.** `backofficeAccessRefusal.ts:97-102` : une lecture comptée garde `earlierSave` à chaque fois, sans
  borne, contre le docblock de `settleBackofficeAccessRefusalsOnMount` (l. 124-125 : « le chargement de la page
  d'arrivée est parti avant ce montage »).
- **CR8-5, confirmé.** `smoke-http.sh:96` (`local name … value saved_umask traced=0 rc=0`) puis `value="${!name:-}"`
  (l. 104) ; `:141` (`local name … traced=0 empty=0`) puis `${!name:-}` (l. 148) : la regex admet `value`, `name`, `rc`,
  `traced`, `saved_umask`, `empty`, que `${!…}` lit d'abord comme locales. `smoke_write_auth_config name` écrit
  l'utilisateur `name`. Aucun appelant ne passe un tel nom aujourd'hui.
- **C8-1, confirmé.** `audit-prod.sh:53` : `if [ -n "${AUDIT_BASIC_AUTH:-}" ]` développe la valeur hors de tout `set +x` ;
  `bash -x tools/audit-prod.sh` l'imprime. L'audit de prod (`pipeline.yml`, job `audit-prod`) ne pose pas la variable ;
  les quatre étapes de préprod la posent (`secrets.PREPROD_BASIC_AUTH`).
- **CR8-9 (= C8-3), confirmé.** `ApiPlatformOperationsCarryNoSecurityTest.php:95` ne lit les propriétés que des classes
  de ressource ; `src/` déclare un DTO d'entrée (`BackofficeUserInviteInput`, `BackofficeUserResource.php:51`) dont les
  propriétés ne sont pas lues, ni celles d'un objet imbriqué. `:232` : une `uriVariable` qui n'est pas un `Link` est
  sautée sans bruit. Les paramètres (aucun dans `src/`) n'ont pas de garde contre la vacuité (raison au choix 6).
- **CR8-10 (= C8-7), confirmé.** `sourceText.ts:81` : `ts.isStringLiteralLike` ne retient que `StringLiteral` et
  `NoSubstitutionTemplateLiteral` ; dans `` `${''}omit` ``, `omit` est un `TemplateTail`. `scriptOf` (l. 108) blanchit le
  `<template>` : un `@click="fetch(u, { credentials: 'omit' })"` échappe. Coût mesuré de la lecture du gabarit : `parse()`
  de `vue/compiler-sfc` construit déjà l'arbre du gabarit (`descriptor.template.ast`) pour `scriptOf`, sur les 60 `.vue`
  de `src/` ; lire ses expressions ajoute un parcours de cet arbre et un `createSourceFile` par expression (quelques
  dizaines de caractères chacune) — négligeable devant le parse existant ; la durée des deux gardes est notée avant et
  après (R96).
- **St8-1 (= Sp8-7), confirmé.** `frontend-backoffice.md`, puce #582 : « a static `aria-describedby="<select id>-help"` »,
  faux depuis R79b (attribut lié, deux identifiants pendant le verrou) ; la puce D-a suit au lieu de la remplacer.
- **St8-2, confirmé.** `sourceText.spec.ts` n'a aucun test de la branche d'erreur de `scriptOf` (l. 104-106).
- **St8-5, confirmé.** `formCancelButton` est écrit dans six specs (`AdminCaseStudiesPage.spec.ts:153`,
  `AdminIncidentsPage.spec.ts:150`, `AdminContributionsPage.spec.ts:147`, `AdminAnonymousCvPage.spec.ts:148`,
  `AdminQualityPage.spec.ts:203`, `AdminAboutPage.spec.ts:307`) ; les tests du verrou sont recopiés par formulaire
  (quatre par formulaire dans les quatre premiers specs ; quatre par panneau dans `AdminQualityPage.spec.ts:687-790` et
  `AdminAboutPage.spec.ts:1026-1130`).
- **St8-6, confirmé.** `noOmittedCredentials.spec.ts:22-29` et `sessionStateEncapsulation.spec.ts:29-36` : même
  `import.meta.glob`, même `Object.fromEntries(… scriptOf …)`, même docblock.
- **St8-7, confirmé.** Le docblock de `sourceText.ts` (l. 7-14) raconte R68 ; `withoutComments` remplace un commentaire
  par `.replace(/[^\n]/g, '')` (l. 65), qui retire le `\r` d'un `\r\n`, alors que `scriptOf` le garde (`/[^\r\n]/g`, l. 108).
- **St8-8, confirmé.** `AuthorizationSites::in()` rend `list<array{int, AuthorizationSiteKind}>` (l. 66) ; `->value` est
  écrit six fois dans `AuthorizationLivesInAccessControlTest` (l. 65, 70, 75, 86, 88-89, 229) ; le docblock de classe
  d'`AuthorizationSites` (l. 16-28) recopie la description de chaque `case` d'`AuthorizationSiteKind`.
- **St8-9, confirmé.** La règle d'identité est écrite dans `BackofficeSessionReaction.ts` (docblock d'`account`,
  l. 10-17, et celui de `refused`), `backofficeSessionExpiry.ts` (en-tête l. 33-38, commentaire l. 59), `useAuth.ts`
  (docblock de `lastAccountName`, l. 35-39), et dans `frontend-session-refus.md` (sous-puce « the account », l. 65-71).
- **St8-11 (= CR8-8), confirmé.** Validation du nom et sauvegarde de `xtrace` écrites deux fois (`smoke-http.sh:98-103`
  et `:142-153`) ; `"$(umask 077 && mktemp -d)"` (l. 111) sous un `umask 077` déjà posé (l. 110) ; `audit-prod.sh:49-51`,
  ligne de commentaire coupée (« Le `trap` précède la / création du dossier : un / arrêt »).
- **St8-12 (= C8-2), confirmé.** `smoke_add_basic_auth` ajoute `-K <fichier>` à chaque appel (l. 158) : au second, le
  `smoke_cleanup` de tête de `smoke_write_auth_config` retire le premier fichier, dont le `-K` reste dans
  `SMOKE_CURL_OPTS`. Sourcer la bibliothèque deux fois remet `SMOKE_AUTH_DIR` à vide (l. 42) : le dossier du premier
  appel n'est plus retiré par le `trap`. Aucun appelant ne fait l'un ou l'autre.
- **St8-14, confirmé, mutant équivalent.** `AdminAboutSettingsForm.vue:90` : `settings` n'est jamais posé que pour la
  clé courante (`clear` au changement de clé, `write` sous la clé courante) ; une proposition dont la cible n'est pas la
  langue montrée n'est pas affichée, et le retour sur sa cible relit `settings`, ce qui la retire de toute façon. Retirer
  la condition ne change rien de visible.
- **Sp8-1, confirmé.** Le point R78/R79 de la tâche 11 ne contrôle ni le texte du verrou (D-a), ni la catégorie (D-b),
  ni la limite « Créer la version » ; le corps de PR de la tâche 12 (puce de la septième série) non plus.
- **Info, chacun vu dans le code** : CR8-6 (`AdminAboutSettingsForm.vue:226`, `isSavingLocale(locale) ||
  isSavingLocale(otherLocale(locale))`, à côté d'`isSaving`, `useAdminAboutSettings.ts:78`) ; St8-10
  (`backofficeAccessRefusal.ts:47` et `:51`, `countedWriteBy: { account } | null`, seul `NO_REFUSAL` figé) ; St8-13
  (`ApiRouteExposureTest.php:309-322`, aucune mutation ne prouve « 401 et pas 403 » ; `/api/logout` justifié dans
  `BASE_TIER_PATHS`, l. 135, et dans `RULELESS_OUTSIDE_PUBLIC_PATHS`, l. 146-149) ; C8-5 (l. 148 : « le double-submit
  refuse une écriture sans jeton », alors qu'une paire s'obtient en un clic par l'accès de base) ; C8-6 (aucun test sur
  les méthodes des routes ; aujourd'hui, `debug:router --env=test` le 2026-10-30 : sous `/api`, GET, GET|HEAD, POST, PUT,
  DELETE et GET|POST|DELETE|OPTIONS (`/api/mcp`), aucune route sans méthode déclarée) ; C8-4 (`requestableMethods`,
  l. 731-736, sonde en GET une route sans méthode ; un paramètre de chemin prend une valeur ; aucune route n'a de
  paramètre juste après `/api`, et aucune règle d'`access_control` ne nomme un segment qu'une route paramètre, vérifié le
  2026-10-30) ; Sp8-8 (plan, l. 4223-4224, 4362, 4579) ; Sp8-10 (la phrase de renvoi varie : « Les témoins de la prémisse
  … » dans `ApiRouteExposureTest.php:65` et `:268`, « Les témoins de cette prémisse … » ailleurs, « témoins listés une
  fois » dans le listener) ; Sp8-11 (`frontend-backoffice.md`, « eight forms, one text each »).

### Options comparées

**CR8-7 — où vit le verrou des formulaires par entrée (avant les décisions 1 et 3, qui en ajoutent deux) :**

| Option | Pour | Contre |
|---|---|---|
| (A) **un composable `useEntryFormLock`** (`presentation/ui/admin/`) qui rend l'état `'none' \| 'editing' \| 'proposal'`, les verrous dérivés et les `aria-describedby`, et un texte `EntryFormLockHint.vue` qui lit l'état — **retenue** | le prédicat et les identifiants écrits une fois ; les décisions 1 et 3 ajoutent un dérivé au composable et une ligne par formulaire au lieu de seize copies ; un IDREF pendant devient impossible par construction (le texte et la référence lisent le même état) ; testable seul | une indirection de plus ; un objet `reactive` à rendre (un `computed` imbriqué dans un objet n'est pas déballé par le gabarit : piège nommé au choix 1) |
| (B) un composant `LockedLocaleSelect.vue` (sélecteur, texte et identifiants ensemble) | le gabarit aussi est factorisé | ne sert que la langue : le bouton de traduction, « Version de » et la catégorie ont besoin du même identifiant de texte ; les aides permanentes (#582) passeraient par des props ou des slots |
| (C) garder la recopie, n'extraire que le prédicat | le moins de changement | les décisions 1 et 3 ajouteraient seize copies de plus ; le ternaire de la catégorie et les identifiants littéraux restent |

Le nom : `useEntryFormLock` plutôt que `useLocaleLock`, qui ne dit plus ce qu'il porte (la langue, la catégorie, le
groupe, le bouton de traduction). L'emplacement : `presentation/ui/admin/`, à côté du texte, comme
`useFocusThenAnnounce` dans `presentation/ui` — il construit des identifiants ARIA, une affaire de présentation.

**St8-5 — l'ordre des tâches du frontend.** Les tests du verrou sont paramétrés **avant** l'extraction du composable
(R91 avant R92) : le filet de R92 est alors le même jeu de cas sur les huit formulaires, et R93 et R94 ajoutent leurs cas
une fois, dans `describeEntryFormLock`, au lieu de huit fois. Écart assumé à l'ordre « fonctionnalités, puis
refactorings » de la série.

**Décisions 1 et 2 — le texte d'une proposition :**

| Option | Pour | Contre |
|---|---|---|
| (A) **un seul texte de verrou par proposition, `admin.lockedByProposal`, désigné par chaque contrôle verrouillé** (langue, « Version de », bouton de traduction, catégorie « moi ») — **retenue** | un élément, un identifiant ; le ternaire de la catégorie disparaît (St8-3) ; « Annuler » dit une fois ce qu'il fait (décision 2) | le texte est générique (« Verrouillé par la proposition… ») plutôt que propre au contrôle |
| (B) un texte par contrôle (langue, groupe, bouton, catégorie) | chaque description nomme son contrôle | quatre clés FR/EN, quatre éléments, le Repeated Switch de St8-3 multiplié |

Texte retenu — FR : « Verrouillé par la proposition de traduction en cours ; « Annuler » l'abandonne et vide le
formulaire. » ; EN : « Locked by the current translation proposal; “Cancel” discards it and clears the form. » Il
remplace `admin.localeLockedProposal` et `admin.about.meCard.categoryProposalLockedHelp` (retirées). En édition, rien ne
change : la langue garde `admin.localeLockedEditing`, la catégorie `admin.about.meCard.categoryLockedHelp`.

**St8-12 et St8-11 — la Basic Auth d'un second appel :**

| Option | Pour | Contre |
|---|---|---|
| (A) `smoke_add_basic_auth` retire de `SMOKE_CURL_OPTS` le `-K` du fichier précédent | garde l'interface | une manipulation de tableau de plus ; la validation et le `xtrace` restent écrits deux fois (St8-11) |
| (B) un second appel refusé (retour 2) | simple | un état de plus à décrire, et le cas qu'il refuse est légitime (`smoke_write_auth_config` le permet déjà, décision 6) |
| (C) **`smoke_fetch` ajoute lui-même `-K "$SMOKE_AUTH_CONFIG"` quand il est posé ; `smoke_add_basic_auth` disparaît, les scripts appellent `smoke_write_auth_config`, qui juge aussi le vide** — **retenue** | aucun `-K` périmé possible (il est lu à l'appel) ; la validation du nom et le `xtrace` écrits une fois (St8-11) ; `audit-prod.sh` et les scripts de fumée appellent la même fonction (C8-1 : la bibliothèque juge le vide) | les tests de `smoke_add_basic_auth` et les trois scripts changent exprès |

Sourcer la bibliothèque deux fois : **limite écrite**, pas de code. Pour un marqueur (un tableau, qu'un environnement ne
peut pas transmettre, posé à la première source pour appeler `smoke_cleanup` à la seconde) : le dossier du premier
appel serait retiré. Contre : une astuce de shell pour un cas qu'aucun script n'a (chaque script source une fois, en
tête), que la relecture voit, et dont le reste est un dossier 0700 de l'utilisateur sous `TMPDIR`.

**CR8-10 — ce que la garde `'omit'` lit de plus :**

| Option | Pour | Contre |
|---|---|---|
| (A) **les parties des gabarits (`TemplateHead/Middle/Tail`) comme des littéraux, et les expressions du `<template>` d'un `.vue`** (directives, gestionnaires, interpolations, lues dans l'arbre que `parse()` construit déjà) — **retenue** | ferme les deux trous nommés ; coût négligeable (mesuré au rendu) | une partie de gabarit `omit` à côté d'une expression variable (`` `${x}omit` ``) est signalée sans être `'omit'` : faux positif bruyant, aucun dans `src/` |
| (B) plier les gabarits dont toutes les expressions sont des littéraux | exact sur `` `${''}omit` `` | plus de code ; ne voit toujours ni `'om' + 'it'` ni une variable |
| (C) la limite écrite | rien à coder | la garde manque un `credentials: 'omit'` écrit dans un gestionnaire en ligne |

La concaténation (`'om' + 'it'`) et une valeur venue d'une variable restent hors de portée : limite déjà écrite (R51).

**CR8-3 — l'état de l'avis :** `earlierSave: 'none' | 'atMount' | 'keptByARead'` (une lecture refusée après le montage
passe `'atMount'` → `'keptByARead'`, la suivante `'keptByARead'` → `'none'`, une écriture remet `'none'`) ; l'export
`earlierSaveRefused` reste un booléen. Contre un compteur de lectures : trois états nommés disent la règle de la
décision 4, un nombre la laisserait à lire dans une comparaison.

### Choix de conception tranchés

1. **CR8-7 (R92).** `presentation/ui/admin/useEntryFormLock.ts` :
   `export type EntryFormLockState = 'none' | 'editing' | 'proposal'` ;
   `export function useEntryFormLock(options: { readonly hintId: string; readonly isEditing: () => boolean; readonly hasProposal: () => boolean; readonly localeHelpId?: string }): EntryFormLock`,
   où `EntryFormLock` est un objet **`readonly(reactive({ … }))`** de `computed` — `{ state, isEntryLocked, hintId,
   localeDescribedBy }` en R92, `isProposalLocked` et `proposalDescribedBy` ajoutés en R93 — pour que le gabarit lise
   `principleLock.isEntryLocked` sans `.value` (un `computed` rangé dans un objet ordinaire n'est pas déballé par le
   gabarit : la condition serait toujours vraie). `state` : `'editing'` si `isEditing()`, sinon `'proposal'` si
   `hasProposal()`, sinon `'none'` ; `isEntryLocked` : `'none' !== state` (la langue, la catégorie « moi ») ;
   `localeDescribedBy` : `describedBy(localeHelpId, isEntryLocked ? hintId : undefined)`. Aide exportée :
   `export function describedBy(...ids: ReadonlyArray<string | undefined>): string | undefined` (les identifiants non
   vides joints par une espace, `undefined` s'il n'y en a aucun). `LocaleLockedHint.vue` devient
   `EntryFormLockHint.vue` (`git mv`), props `{ id: string; state: EntryFormLockState }`, rendu si `'none' !== state`.
   Les identifiants d'aujourd'hui (`admin-case-study-locale-locked-help`…) sont gardés. Le docblock du composable dit
   d'où naît une proposition (édition, « Créer la version XX », formulaire vierge : CR8-2) et pourquoi le verrou tient
   dans les trois cas (la prose proposée est dans la langue cible) ; le commentaire de cinq lignes des huit sélecteurs
   devient une ligne qui y renvoie.
2. **St8-5 (R91).** `tests/support/localeLockHint.ts` devient `tests/support/entryFormLock.ts` (`git mv`) et gagne
   `formCancelButton(wrapper: VueWrapper, form?: number)` et
   `describeEntryFormLock(lockCase: EntryFormLockCase): void`, qui déclare dans le `describe` appelant les quatre cas
   d'aujourd'hui (proposition : langue désactivée et « Annuler » présent ; « Annuler » : formulaire vide, sélecteur
   actif, sans avis ; verrou expliqué en édition et pas dans un formulaire libre ; proposition expliquée, « Annuler »
   retire l'explication). `EntryFormLockCase` :
   `{ readonly name: string; readonly mount: () => Promise<VueWrapper>; readonly form: number; readonly ids: { readonly locale: string; readonly group: string; readonly title: string; readonly hint: string }; readonly permanentHelp?: string; readonly startEdit: (wrapper: VueWrapper) => Promise<void>; readonly propose: (wrapper: VueWrapper) => Promise<void> }`.
3. **Décisions 1 et 2 (R93).** `isProposalLocked` (`'proposal' === state`) et `proposalDescribedBy`
   (`isProposalLocked ? hintId : undefined`) ; le bouton de traduction : `:disabled="… || lock.isProposalLocked"`,
   `:aria-describedby="describedBy(lockedHintId, lock.proposalDescribedBy)"` ; `EntryFormLockHint` rend
   `admin.localeLockedEditing` en édition, `admin.lockedByProposal` pendant une proposition ; la catégorie « moi » :
   désactivée par `isEntryLocked`, décrite par `CATEGORY_HELP_ID` (rendu **en édition seulement**,
   `categoryLockedHelp`) ou par `proposalDescribedBy`.
4. **Décision 3 (R94).** « Version de » : `:disabled="lock.isProposalLocked"`, `:aria-describedby="lock.proposalDescribedBy"` ;
   la limite de CR8-2 écrite dans le docblock du composable et dans la règle.
5. **Décision 4 et St8-10 (R95).** `RefusalState` : `{ readonly count: number; readonly earlierSave: EarlierSave; readonly countedWrite: CountedWrite }`,
   `type EarlierSave = 'none' | 'atMount' | 'keptByARead'`,
   `type CountedWrite = { readonly kind: 'none' } | { readonly kind: 'write'; readonly account: string | null }` ; chaque
   transition passe par une fonction privée `transition(next: RefusalState): void` qui fige l'objet
   (`Object.freeze`) et l'affecte — une seule affectation de `state.value` dans le module.
6. **CR8-9, C8-3 (R88).** La garde des métadonnées compilées lit, en plus, les propriétés des classes `input` et
   `output` de chaque ressource et opération, et des classes `App\` que le type natif d'une propriété atteint
   (`ApiProperty::getNativeType()`, objets, collections, nullables, unions), chaque classe une fois ; une `uriVariable`
   compilée qui n'est pas un `Link` est un constat (« n'est pas un Link compilé : sa sécurité ne serait pas lue »).
   **Pas de garde contre la vacuité des paramètres**, raison écrite dans le docblock : le parcours de `getParameters()`
   est la même boucle que celle des liens, exécutée sur chaque ressource et chaque opération (comptées) ;
   `testTheCheckSeesEachParameterExpression` prouve la lecture ; une garde exigerait qu'un paramètre existe, et aucun
   n'existe ; une ressource de test ajoutée pour cela ajouterait une route `/api` au conteneur `test` que lisent toutes
   les gardes d'exposition (`ApiRouteExposureTest`, `RouterScopeTest`). Un garde-fou de vacuité sur les classes DTO
   (`src/` en a une) ; sur les classes imbriquées seulement si `src/` en a une au moment de la tâche (le compter ; sinon
   le dire dans le docblock et au rendu).
7. **C8-6, C8-4 (R89).** Invariant n° 10 d'`ApiRouteExposureTest` : chaque route de `/api` déclare ses méthodes, et
   chacune est `GET`, `HEAD`, `OPTIONS` (`SAFE_METHODS_OUTSIDE_THE_DOUBLE_SUBMIT`, avec sa raison : ce que le
   double-submit laisse passer et qu'une route peut servir sans écrire) ou un élément de
   `CsrfCookieRequestSubscriber::UNSAFE_METHODS`. Une route sans méthode déclarée (sondée en GET seul, C8-4) et une
   route `QUERY` ou d'une méthode propre (hors du double-submit, C8-6) y rougissent. La seconde moitié de C8-4 (un
   paramètre de chemin ne prend qu'une valeur) est une **limite écrite** dans le docblock de l'invariant n° 1 : exacte
   tant qu'aucune règle d'`access_control` ne nomme un segment qu'une route paramètre (vérifié le 2026-10-30).
8. **C8-1, CR8-5, St8-11, St8-12 (R90).** Option C ci-dessus ; les locales de `smoke_write_auth_config` préfixées
   `_smoke_` et tout nom commençant par `_smoke_` refusé ; une valeur vide ou absente : retour 0, rien créé,
   `SMOKE_AUTH_CONFIG` vide ; `audit-prod.sh` : `if [[ -v AUDIT_BASIC_AUTH ]]`.
9. **CR8-10, St8-2, St8-7 pour le `\r` (R96) ; St8-6, St8-7 pour le docblock (R97).** Option A ci-dessus ;
   `export function templateExpressionsOf(path: string, source: string): string[]` (le texte de chaque expression du
   `<template>` d'un `.vue` : `exp` des directives, argument dynamique, interpolations ; `[]` pour un autre chemin) ;
   `stringLiterals` retient aussi `TemplateHead`, `TemplateMiddle`, `TemplateTail` ; `withoutComments` garde le `\r`
   (`/[^\r\n]/g`). R97 : `tests/support/srcSources.ts` (nouveau, pour que `sourceText.spec.ts` ne charge pas tout `src/`)
   exporte `SRC_SCRIPTS: Readonly<Record<string, string>>` (chemin → `scriptOf`) et
   `SRC_TEMPLATE_EXPRESSIONS: Readonly<Record<string, readonly string[]>>`, la glob écrite une fois.
10. **CR8-6, St8-14 (R98).** `useAdminAboutSettings` expose `isAnySaving: ComputedRef<boolean>`
    (`SUPPORTED_LOCALES.some(isSavingLocale)`) ; le bouton de traduction le lit ; `isSavingLocale` ne reste dans
    l'interface que si un autre lecteur de `src/` le garde (le dire au rendu). La condition `if (isProposalShown.value)`
    est **retirée** (pour : une branche que rien ne peut observer en moins ; contre : la proposition d'une autre langue
    tombe aussi à l'arrivée des réglages de la langue montrée — invisible, puisqu'elle n'est pas affichée et que le retour
    sur sa cible la retirait déjà) ; le commentaire du `watch` dit pourquoi.
11. **St8-8 (R99).** `tests/Support/AuthorizationSite.php` (nouveau) :
    `final readonly class AuthorizationSite { public function __construct(public int $line, public AuthorizationSiteKind $kind) {} }` ;
    `AuthorizationSites::in(string $code): list<AuthorizationSite>` ; `EXEMPTIONS` :
    `array<class-string, array{string, list<array{AuthorizationSiteKind, int}>}>` (les cas d'enum sont permis dans une
    constante de classe ; aucun `->value`) ; un seul `->value`, dans une méthode privée du test
    `countByKind(list<AuthorizationSite>): array<string, int>` ; le docblock d'`AuthorizationSites` renvoie aux `case`
    d'`AuthorizationSiteKind` au lieu de les recopier.
12. **St8-9 (R100).** **Une source**, comme St7-5 : la sous-puce « the account » de `frontend-session-refus.md` ; les
    cinq docblocks et commentaires de code disent en une phrase ce que porte leur champ ou leur fonction et renvoient à
    la règle par une phrase identique partout : « La règle de l'identité du compte et ses limites sont écrites une fois,
    dans `.claude/rules/frontend-session-refus.md` (sous-puce « the account »). »
13. **Sp8-10 (R89).** La phrase de renvoi aux témoins, identique dans les cinq fichiers qui la portent : « Les témoins de
    cette prémisse sont listés une fois, dans `.claude/rules/security-authentication-sessions.md` (puce de
    `PublicRouteIgnoresRefusedSessionListener`). »

**Questions à l'auteur** (le plan suit la recommandation ; une autre réponse ne change que la tâche citée) :

- **Q1 (R94)** : une proposition dont la langue cible existe déjà dans le groupe (depuis « Créer la version XX », ou
  depuis l'édition d'une entrée dont le groupe a les deux langues) se termine, à l'enregistrement, sur le 409
  « Ce contenu a déjà une version dans cette langue. » ; depuis la décision 3, « Version de » ne permet plus de la
  détacher, et « Annuler » est la seule sortie. **Recommandé : la limite écrite** (antérieure, spec 0004 ; le 409 dit
  vrai et rien de faux n'est écrit ; la détection recopierait au frontend la règle d'unicité du serveur, ce que #573 a
  refusé pour la même raison). Autre option : « Proposer la version XX » désactivé quand la langue cible existe déjà
  dans `sourceGroup`, avec son texte d'aide — une tâche R94b sur les huit formulaires (un cas de plus dans
  `describeEntryFormLock`, deux clés FR/EN, une phrase de règle).
  **Décidé par l'auteur le 2026-10-30 : limite écrite** (recommandation suivie ; R94).

### Review Focus (huitième relecture)

1. **Une proposition affichée et l'ordre modifié en même temps** : le bouton de traduction est décrit par les deux textes
   (le verrou d'ordre, puis celui de la proposition), tous deux rendus. Test : R93.
2. **« Annuler » après une proposition** : le bouton de traduction, « Version de », la langue et la catégorie « moi »
   redeviennent actifs et ne désignent plus aucun texte de verrou (aucun IDREF pendant). Test : R93, R94
   (`describeEntryFormLock`).
3. **Carte « moi » en édition puis en proposition** : en édition, la catégorie est décrite par `categoryLockedHelp`
   seul et « Version de » reste libre ; en proposition, langue, catégorie, « Version de » et bouton désignent le même
   texte. Test : R93, R94.
4. **Après le montage avec l'avis « enregistrement précédent »** : une lecture refusée le garde, une seconde ramène
   `common.accessRefused`, une écriture refusée aussi. Test : R95.
5. **`bash -x tools/audit-prod.sh` avec une Basic Auth posée, puis avec `AUDIT_BASIC_AUTH=""`** : la valeur n'apparaît
   pas dans la trace ; vide, aucun `-K`, aucun dossier. Test : R90.

### Contraintes propres aux tâches R88 à R101

- Les **Global Constraints** et toutes les contraintes des séries précédentes valent. Exécution : **un agent par tâche
  (sonnet, effort high), successifs dans l'ordre R88 → R101**, qui lit d'abord `tasks/relectures-748/brief-commun-agents.md`.
  `superpowers:test-driven-development` : test écrit d'abord, **vu rouge pour la bonne raison** ; un test de couverture
  vert d'emblée est vu rouge par la mutation que sa tâche nomme, **restaurée depuis une copie du fichier faite avant la
  mutation et vérifiée par `cmp`** (`cp f "$scratch/f.orig"`, muter, mesurer, `cp "$scratch/f.orig" f`,
  `cmp f "$scratch/f.orig"`) puis `git diff` vide sur le fichier muté. **Une mutation s'écrit en place, avec l'outil
  Edit, jamais par `sed -i`** (ni `perl -pi`) : ces outils remplacent le fichier par un nouvel inode, que le montage du
  conteneur ne voit pas — la mutation ne serait pas mesurée. Refactoring pur : la suite existante comme filet, nombre de
  tests noté avant et après. **`superpowers:verification-before-completion` invoquée réellement** avant le rendu.
- **Git : seuls `git add <fichiers>` et `git commit`** (et `git mv` / `git rm` pour un déplacement ou une
  suppression). Aucune autre commande git qui modifie l'index ou l'arbre : ni `reset` (pas même sans argument), ni
  `add -N`, ni `checkout`, ni `checkout-index`, ni `switch`, ni `restore`, ni `stash`. **Un commit par tâche**, fichiers
  nommés, message en français terminé par les lignes d'attribution que donne le fil principal.
- **Une docstring ou une règle qui décrit un comportement modifié se corrige dans le même commit que le comportement** ;
  une phrase de règle se **remplace**, elle ne s'ajoute pas à côté de l'ancienne (St8-1).
- **Aucun travail en arrière-plan au rendu** : ni conteneur lancé à part, ni `vitest --watch`, ni processus détaché.
- **Chaque code de sortie se lit sans pipe** : `make front-test; echo "exit=$?"` (de même `front-lint`, `front-build`,
  `back-test`, `back-quality`, chaque test hors ligne et `tools/check-claude-rules.sh`).
- Backend (R88, R89, R99) : lire avec `Read` un fichier de `backend/tests/Security/` avant d'écrire ; test ciblé
  `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis `make back-test` et `make back-quality` (toujours
  `-u dev`) ; `rm -rf var/cache/test` après un changement de configuration, de service ou d'attribut d'API Platform, et
  après chaque mutation ; `make back-style-fix` après un import ajouté ; lignes touchées ≤ 140 caractères ; jamais de
  secret littéral.
- Outillage (R90) : lire avec `Read` les scripts touchés avant d'écrire ; `bash tools/tests/<script>.test.sh` hors ligne
  pour chaque script touché ; shellcheck dans l'image épinglée du job `tools-tests` : `docker run --rm --entrypoint
  shellcheck -v "$PWD":/repo:ro -w /repo
  rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -x --severity=warning
  <fichiers>` (si l'image n'expose pas `shellcheck`, le noter et lancer celui de l'hôte, en le disant). Une valeur de
  test ne contient jamais le mot « secret ».
- Frontend (R91 à R98, R100) : test ciblé `docker compose exec -T frontend npm test -- <chemins>`, puis
  `make front-test`, `make front-lint`, `make front-build` ; `docker compose restart frontend` après un fichier créé,
  déplacé (`git mv`) ou un remplacement de masse ; toute chaîne nouvelle en FR et EN ; une clé i18n retirée l'est des
  deux fichiers (le lint signale une clé inutilisée).
- Règles : `tools/check-claude-rules.sh` après chaque modification ; 50 000 octets par règle, taille de chaque règle
  touchée notée au rendu. Tailles au départ (HEAD 10804c00) : `backoffice-api.md` **39 834** (n'y rien ajouter sans
  retirer d'abord ; aucune tâche de la série n'y touche), `security-user.md` 47 914 (non touchée),
  `frontend-backoffice.md` 27 616, `security-authentication-sessions.md` 22 284, `security-authentication.md` 9 109,
  `deploiement-nginx.md` 28 431, `frontend-session.md` 15 498, `frontend-session-refus.md` 13 456. Un fichier créé ou
  déplacé qui entre dans les `paths:` d'une règle y est ajouté dans le même commit et **indexé (`git add`) avant**
  `check-claude-rules`.
- Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets (les exclure de tout
  `grep -r`) ; ne jamais nommer un compte réel. Pas de push, pas de PR.

### Fichiers (tâches R88 à R101)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php` | DTO, objets imbriqués, `uriVariables` non `Link` | R88, R89 (phrase) |
| `backend/tests/Security/ApiRouteExposureTest.php` | invariant n° 10, raison de `/api/logout`, limite C8-4 | R89 |
| `backend/tests/Security/AuthorizationLivesInAccessControlTest.php`, `backend/tests/Support/AuthorizationSites.php`, `backend/tests/Support/AuthorizationSite.php` (nouveau) | phrase de renvoi ; sites typés, exemptions par cas d'enum | R89, R99 |
| `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php` | phrase de renvoi | R89 |
| `tools/lib/smoke-http.sh`, `tools/{audit-prod,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp}.sh`, `tools/tests/{smoke-http,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp,audit-prod}.test.sh` | la Basic Auth jugée et lue par la bibliothèque | R90 |
| `frontend/tests/support/entryFormLock.ts` (ex-`localeLockHint.ts`), les specs des sept fichiers de formulaires | cas du verrou écrits une fois | R91, R93, R94 |
| `frontend/src/presentation/ui/admin/useEntryFormLock.ts` (nouveau), `frontend/src/presentation/ui/admin/EntryFormLockHint.vue` (ex-`LocaleLockedHint.vue`), `frontend/tests/presentation/ui/admin/useEntryFormLock.spec.ts` (nouveau) | le verrou d'un formulaire par entrée | R92, R93, R94 |
| `frontend/src/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,AdminAnonymousCvPage,AdminQualityPage,AdminAboutMeCardsSection,AdminAboutSiteCardsSection}.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json` | câblage du verrou, bouton, « Version de », textes | R92, R93, R94 |
| `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts` | l'avis borné au chargement | R95 |
| `frontend/tests/support/sourceText.ts`, `frontend/tests/support/sourceText.spec.ts`, `frontend/tests/support/srcSources.ts` (nouveau), `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts` | lecteur de source | R96, R97 |
| `frontend/src/application/admin/about/useAdminAboutSettings.ts`, `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue` | `isAnySaving`, `watch` | R98 |
| `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/application/auth/useAuth.ts` | renvoi à la règle d'identité | R100 |
| `.claude/rules/*` | dans le commit du comportement | R88 à R100 ; balayage R101 |

---

### Task R88 : Backend — la garde des métadonnées compilées lit les DTO d'entrée et de sortie, les objets imbriqués, et dit une `uriVariable` qui n'est pas un `Link` (CR8-9 = C8-3)

**Files :**
- Modify : `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`
- Modify (règle) : `.claude/rules/security-authentication-sessions.md` (le témoin `ApiPlatformOperationsCarryNoSecurityTest` de la puce du listener R35)

**Interfaces :**
- `private function dtoClassesOf(Metadata $element): list<class-string>` : la `class` de `getInput()` et de `getOutput()`
  quand c'est un tableau qui en porte une (API Platform normalise `input: Foo::class` en `['class' => …]`).
- `private function nestedClassesOf(ApiProperty $property): list<class-string>` : les classes que
  `getNativeType()` (Symfony TypeInfo) atteint — objet, valeur de collection, type nullable, union, intersection —,
  gardées si elles sont sous `App\` et ne sont pas des énumérations.
- `testNoOperationCarriesASecurityExpression` : les propriétés se lisent sur une file de classes (les classes de
  ressource, puis les classes DTO de chaque ressource et opération, puis les classes imbriquées atteintes), chacune une
  fois ; message inchangé (« <classe>, propriété <nom> : … ») ; dans `collectNested`, une `uriVariable` qui n'est pas un
  `Link` ajoute « <contexte>, lien <nom> : n'est pas un Link compilé, sa sécurité ne serait pas lue » au lieu de
  `continue`. Garde-fous : `assertGreaterThan(0, $dtoClasses)` ; sur les classes imbriquées, seulement si `src/` en a
  (choix 6).
- Docblock de classe : « chaque propriété » dit ce qui est parcouru (ressources, DTO, objets imbriqués) ; la raison de
  l'absence de garde de vacuité sur les paramètres (choix 6) ; la phrase de renvoi aux témoins reste pour R89.

- [ ] **Step 1 : tests, rouges** — `testTheCheckReachesNestedClasses` (fournisseur : `new ApiProperty(nativeType:
  Type::object(<classe App\ de tests/>))` → `[<classe>]` ; `Type::list(Type::object(…))` → `[<classe>]` ;
  `Type::nullable(Type::object(…))` → `[<classe>]` ; `Type::string()` → `[]` ; un objet hors `App\` (`DateTimeImmutable`)
  → `[]`) ; `testANonLinkUriVariableIsReported` (`new Get(uriVariables: ['id' => 'App\Foo'])` passé à `collectNested` :
  un constat qui nomme `id`). Run : `docker compose exec -u dev backend php bin/phpunit tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`.
  Expected : FAIL, `nestedClassesOf` introuvable ; aucun constat pour l'`uriVariable`.
- [ ] **Step 2 : implémenter** selon les interfaces.
- [ ] **Step 3 : rouges vus par mutation** (copie vérifiée par `cmp`, `rm -rf var/cache/test` après chacune) :
  (a) `#[ApiProperty(security: 'true')]` sur une propriété de `BackofficeUserInviteInput` → rouge, la classe et la
  propriété nommées (noter que la garde de jetons de R60 rougit aussi : elle voit `security:`) ; (b) le même attribut sur
  une propriété d'une classe imbriquée de `src/` s'il en existe une (sinon le dire).
- [ ] **Step 4 : règle** — dans la puce du listener R35 : « every resource, operation, parameter, link and property
  (R74) » → « every resource, operation, parameter, link and property — of the resources, of their input and output
  DTOs and of the `App\` objects they nest (R74, R88) —, a `uriVariable` that is not a compiled `Link` reported ».
- [ ] **Step 5 : verts** — `make back-test; echo "exit=$?"`, `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"` ; taille de la règle notée.
- [ ] **Step 6 : commit** — `test(security): la garde des métadonnées compilées lit les DTO et les objets imbriqués, et dit une uriVariable qui n'est pas un Link (#748)`.

---

### Task R89 : Backend — chaque route de `/api` déclare des méthodes que le double-submit juge ; `/api/logout` justifié juste ; « 401 et pas 403 » prouvé ; la phrase de renvoi identique (C8-6, C8-4, C8-5, St8-13, Sp8-10)

**Files :**
- Modify : `backend/tests/Security/ApiRouteExposureTest.php`
- Modify (phrase de renvoi, Sp8-10) : `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`, `backend/tests/Security/AuthorizationLivesInAccessControlTest.php`, `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php`
- Modify (règle) : `.claude/rules/security-authentication.md` (puce `CsrfCookieRequestSubscriber`, la phrase « Its `EXCLUDED_PATHS` are **exact** paths, each justified in the docblock. »)

**Interfaces :**
- `private const array SAFE_METHODS_OUTSIDE_THE_DOUBLE_SUBMIT = ['GET', 'HEAD', 'OPTIONS']` (docblock : les méthodes
  qu'une route de `/api` peut déclarer hors de `CsrfCookieRequestSubscriber::UNSAFE_METHODS` ; une autre — `QUERY`, une
  méthode propre — sortirait du double-submit sans qu'aucune garde le voie, C8-6).
- `public function testEveryApiRouteDeclaresMethodsTheDoubleSubmitJudges(): void` (invariant n° 10) : pour chaque route
  du routeur dont le chemin relève de `allApiRoutePaths()`, `getMethods()` non vide (sinon « <chemin> ne déclare aucune
  méthode : elle les accepte toutes, et l'invariant n° 1 ne la sonde qu'en GET ») et chaque méthode dans
  `SAFE_METHODS_OUTSIDE_THE_DOUBLE_SUBMIT` ∪ `UNSAFE_METHODS` ; `assertGreaterThan(0, $checked)`.
- `RULELESS_OUTSIDE_PUBLIC_PATHS['/api/logout']` (C8-5) : « « Terminer cet accès » (#65, R35) : sans règle par
  construction, il expire les deux cookies de l'appelant, y compris d'un porteur dont le cookie est refusé (2xx depuis
  R35) ; une règle lui rendrait un 401. Pas public pour autant : il ne sert aucune donnée et n'agit que sur les cookies
  de l'appelant — le double-submit n'y protège rien d'autre, une paire s'obtenant en un clic (accès de base). » ; le
  docblock des deux listes dit pourquoi `/api/logout` est dans les deux (St8-13 : chacune sa question — ce que le
  palier de base ouvre, ce qui n'a pas de règle — et la même substance : aucune donnée, les seuls cookies de l'appelant).
- Docblock de classe : « Dix invariants », le n° 10 en deux lignes ; docblock de l'invariant n° 1 : la limite de C8-4
  (« un paramètre de chemin y prend une seule valeur : exact tant qu'aucune règle d'`access_control` ne nomme un
  segment qu'une route paramètre — vérifié le 2026-10-30 ; une route sans méthode déclarée est refusée par
  l'invariant n° 10 »).
- Sp8-10 : la phrase de renvoi du choix 13, **identique**, dans `ApiRouteExposureTest` (l. 65 et 268),
  `ApiPlatformOperationsCarryNoSecurityTest`, `AuthorizationLivesInAccessControlTest` et le listener.

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`, nombre de tests noté.
- [ ] **Step 2 : test** — l'invariant n° 10 écrit. Run : `docker compose exec -u dev backend php bin/phpunit tests/Security/ApiRouteExposureTest.php`.
  Expected : vert (couverture d'un état juste ; une route qui rougirait est un vrai défaut : s'arrêter et rendre la
  question).
- [ ] **Step 3 : rouges vus par mutation** (copie vérifiée par `cmp`, `rm -rf var/cache/test` après chacune) :
  (a) `methods: ['GET', 'QUERY']` sur la route de `CurrentUserController` → l'invariant n° 10 nomme `QUERY` ;
  (b) `methods:` retiré de cette route → l'invariant n° 10 la dit sans méthode ; (c) **St8-13** : l'appel anonyme de
  l'invariant n° 8 envoyé sans sa paire CSRF sur une écriture → l'assertion C7-10 rougit sur une écriture à règle
  (« … doit y recevoir 401, pas 403 »), noter la route et le message.
- [ ] **Step 4 : textes** — C8-5, St8-13, C8-4, Sp8-10 selon les interfaces ; `grep -rn "témoins" backend/src backend/tests`
  (secrets exclus) : la phrase de renvoi identique partout où elle est ; une phrase différente n'est gardée que si elle
  dit autre chose (`AuthorizationSites.php:47`, la limite), noté au rendu.
- [ ] **Step 5 : règle** — `security-authentication.md` : « Its `EXCLUDED_PATHS` are **exact** paths, each justified in
  the docblock. » → « Its `EXCLUDED_PATHS` are **exact** paths, each justified in the docblock; every route of `/api`
  declares its methods, each `GET`, `HEAD`, `OPTIONS` or one of its public `UNSAFE_METHODS` (invariant n° 10 of
  `ApiRouteExposureTest`, C8-6: a `QUERY` route or a custom method would leave the double-submit). » ; taille notée.
- [ ] **Step 6 : verts** — `make back-test; echo "exit=$?"` (filet + 1), `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 7 : commit** — `test(security): chaque route de /api déclare des méthodes que le double-submit juge, /api/logout justifié juste, la phrase de renvoi aux témoins identique (#748)`.

---

### Task R90 : Outillage — la Basic Auth jugée par la bibliothèque, lue par `smoke_fetch`, d'un nom qui ne désigne jamais une locale, et jamais développée hors de `set +x` (C8-1, CR8-5, St8-11 = CR8-8, St8-12 = C8-2)

**Files :**
- Modify : `tools/lib/smoke-http.sh`, `tools/audit-prod.sh`, `tools/smoke-dead-session-cookie.sh`, `tools/smoke-login-throttling.sh`, `tools/smoke-mcp.sh`
- Test : `tools/tests/smoke-http.test.sh`, `tools/tests/audit-prod.test.sh`, et les tests des trois scripts de fumée là où ils lisent `smoke_add_basic_auth` ou un `-K` dans `SMOKE_CURL_OPTS` (changement exprès)
- Modify (règles) : `.claude/rules/deploiement-nginx.md` (l. 118 : « adds the Basic Auth (`smoke_add_basic_auth`) », et « sources the library only when `AUDIT_BASIC_AUTH` is set »), `.claude/rules/security-authentication-sessions.md` (la phrase « that plumbing — a private directory removed by `smoke_cleanup` … »), si elles nomment ce qui change

**Interfaces :**
- `smoke_write_auth_config <nom>` : locales `_smoke_name`, `_smoke_value`, `_smoke_umask`, `_smoke_traced`, `_smoke_rc` ;
  validation `[[ "$_smoke_name" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "$_smoke_name" != _smoke_* ]]` sinon « smoke_write_auth_config :
  nom de variable invalide » et retour 2 (CR8-5) ; valeur vide ou variable absente : retour 0, aucun dossier,
  `SMOKE_AUTH_CONFIG` vide (C8-1 : la bibliothèque juge le vide, sous `set +x`) ; `"$(mktemp -d)"` sous le `umask 077`
  déjà posé (St8-11) ; le reste inchangé (`smoke_cleanup` en tête, décision 6).
- `smoke_add_basic_auth` **retiré** ; les trois scripts de fumée : `smoke_write_auth_config AUDIT_BASIC_AUTH || exit 2`.
- `smoke_fetch` : une locale `_smoke_auth=()`, `(-K "$SMOKE_AUTH_CONFIG")` quand il est posé, passée à curl après
  `SMOKE_CURL_OPTS` — un `-K` n'est jamais rangé dans `SMOKE_CURL_OPTS`, donc jamais périmé (St8-12).
- `audit-prod.sh` : `if [[ -v AUDIT_BASIC_AUTH ]]; then` (C8-1 : le nom testé, la valeur jamais développée) ; dans le
  bloc, après `smoke_write_auth_config AUDIT_BASIC_AUTH || exit 2`, `CURL_OPTS+=(-K "$SMOKE_AUTH_CONFIG")` seulement si
  `SMOKE_AUTH_CONFIG` n'est pas vide ; le commentaire des l. 35-52 réécrit d'un tenant (St8-11 : la ligne coupée), il dit
  que l'audit de prod ne pose pas la variable, donc ne source pas la bibliothèque.
- En-tête de la bibliothèque : cinq fonctions publiques ; `smoke_fetch` lit la Basic Auth ; **limite écrite** : sourcer
  la bibliothèque deux fois remet ses variables à vide, le dossier d'un premier `smoke_write_auth_config` n'est alors
  plus retiré (aucun script ne le fait ; raison du choix au paragraphe « St8-12 et St8-11 » des options comparées).

- [ ] **Step 1 : filet** — les cinq tests hors ligne (`smoke-http`, `smoke-dead-session-cookie`,
  `smoke-login-throttling`, `smoke-mcp`, `audit-prod`) à 0, sorties notées.
- [ ] **Step 2 : tests, rouges**
  - `smoke-http.test.sh` : « une variable nommée `name` qui vaut `u:p` : le fichier porte `user = "u:p"` » (expected :
    `user = "name"`) ; « `smoke_write_auth_config _smoke_x` : retour 2, rien créé » (expected : retour 0) ;
    « variable vide, puis absente : retour 0, aucun dossier, `SMOKE_AUTH_CONFIG` vide » (expected : un dossier et
    `user = ""`) ; « `smoke_fetch` passe `-K <fichier>` au faux curl quand la Basic Auth est posée, aucun `-K` sinon » ;
    « deux `smoke_write_auth_config` successifs : le faux curl reçoit un seul `-K`, celui du second fichier, qui
    existe » (St8-12 ; expected : fonction `smoke_add_basic_auth` encore là / deux `-K`, selon la forme écrite) ; les
    tests de `smoke_add_basic_auth` retirés (changement exprès).
  - `audit-prod.test.sh` (Review Focus 5) : « `bash -x tools/audit-prod.sh` avec `AUDIT_BASIC_AUTH='u:p'` : `u:p`
    n'apparaît pas sur stderr » (expected : `[ -n u:p ]` dans la trace) ; « `AUDIT_BASIC_AUTH=''` posé : aucun `-K`
    donné au faux curl, aucun dossier dans `TMPDIR` » (`TMPDIR` pointé sur un répertoire du test).
  Run : chaque test hors ligne, `; echo "exit=$?"`.
- [ ] **Step 3 : implémenter** selon les interfaces.
- [ ] **Step 4 : règles** — `deploiement-nginx.md` : « adds the Basic Auth (`smoke_add_basic_auth`) » → « writes the
  Basic Auth (`smoke_write_auth_config`, which `smoke_fetch` reads: a `-K` is never stored in `SMOKE_CURL_OPTS`) » ;
  « and sources the library only when `AUDIT_BASIC_AUTH` is set » → « and sources the library only when
  `AUDIT_BASIC_AUTH` is defined (`[[ -v ]]`: its value is never expanded outside the library's `set +x`; an empty one
  writes nothing) » ; un nom de variable refusé s'il commence par `_smoke_` (les locales de la bibliothèque), dans la
  parenthèse qui décrit `smoke_write_auth_config`. Puis `grep -rn "smoke_add_basic_auth" .claude tools docs .github`
  (secrets exclus) : ne rend plus rien. Tailles notées.
- [ ] **Step 5 : verts** — les cinq tests hors ligne à 0 ; shellcheck (image épinglée) sur la bibliothèque, les quatre
  scripts et les tests touchés ; `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(tools): la Basic Auth jugée par la bibliothèque et lue par smoke_fetch, d'un nom qui ne désigne jamais une de ses locales, jamais développée hors de set +x (#748)`.

---

### Task R91 : Formulaires par entrée — les cas du verrou écrits une fois, pour les huit formulaires (St8-5 ; refactoring de tests, préalable de R92)

**Files :**
- Move : `frontend/tests/support/localeLockHint.ts` → `frontend/tests/support/entryFormLock.ts` (`git mv`)
- Modify : `frontend/tests/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,AdminAnonymousCvPage,AdminQualityPage,AdminAboutPage}.spec.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (le nom du fichier de support dans la puce D-a ; la puce est réécrite en R92)

**Interfaces :**
- Choix tranché 2 : `formCancelButton`, `EntryFormLockCase`, `describeEntryFormLock` dans `entryFormLock.ts`, à côté de
  `describedTexts` et des constantes de texte. Chaque spec déclare son ou ses cas (huit en tout) et appelle
  `describeEntryFormLock` ; ses copies de `formCancelButton` et de ses tests de verrou disparaissent. Les tests propres
  à un formulaire restent dans son spec (catégorie « moi », aide permanente #582, « Créer la version »).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre de tests noté, et la liste des tests de verrou
  remplacés (par spec).
- [ ] **Step 2 : refactorer** selon les interfaces ; `docker compose restart frontend` après le `git mv`.
- [ ] **Step 3 : rouge vu par mutation** — `:disabled="isLocaleLocked"` → `:disabled="isEditing"` dans
  `AdminIncidentsPage.vue` → les cas partagés rougissent pour « incidents » seulement ; restauré par copie vérifiée par
  `cmp`.
- [ ] **Step 4 : verts** — `make front-test; echo "exit=$?"` (le nombre de tests peut croître : quatre cas pour chacun
  des huit formulaires ; dire l'écart au filet), `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `refactor(frontend): les cas du verrou des formulaires par entrée écrits une fois pour les huit formulaires (#748)`.

---

### Task R92 : Formulaires par entrée — le verrou tient dans un composable ; son texte lit l'état ; les textes disent d'où naît une proposition (CR8-7 = St8-3 = St8-4, CR8-2 = Sp8-6, St8-1 = Sp8-7, Sp8-11 ; refactoring)

**Files :**
- Create : `frontend/src/presentation/ui/admin/useEntryFormLock.ts`, `frontend/tests/presentation/ui/admin/useEntryFormLock.spec.ts`
- Move : `frontend/src/presentation/ui/admin/LocaleLockedHint.vue` → `frontend/src/presentation/ui/admin/EntryFormLockHint.vue` (`git mv`)
- Modify : les sept fichiers de formulaires (`AdminCaseStudiesPage`, `AdminIncidentsPage`, `AdminContributionsPage`, `AdminAnonymousCvPage`, `AdminQualityPage` ×2, `AdminAboutMeCardsSection`, `AdminAboutSiteCardsSection`)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (puces #582 et D-a)

**Interfaces :**
- Choix tranché 1 (`useEntryFormLock`, `describedBy`, `EntryFormLockState`, `EntryFormLockHint.vue`). Dans chaque
  formulaire : `const lock = useEntryFormLock({ hintId: '<id d'aujourd'hui>', isEditing: () => isEditing.value,
  hasProposal: () => null !== proposalSourceLocale.value, localeHelpId: '<aide #582, s'il y en a une>' })` (deux dans
  `AdminQualityPage.vue` : `principleLock`, `traitLock`) ; le sélecteur de langue `:disabled="lock.isEntryLocked"`,
  `:aria-describedby="lock.localeDescribedBy"` ; `<EntryFormLockHint :id="lock.hintId" :state="lock.state" />` ;
  « Annuler » `v-if="lock.isEntryLocked"` ; la catégorie « moi » `:disabled="lock.isEntryLocked"` (son texte inchangé
  ici, R93 le change). `isLocaleLocked` et `isPrincipleLocaleLocked`/`isTraitLocaleLocked` disparaissent.
- Commentaire des sélecteurs : une ligne, « Verrou d'édition et de proposition : voir `useEntryFormLock` (#748). » ; le
  docblock du composable porte ce que disaient les huit commentaires, **juste** (CR8-2 : une proposition naît d'une
  édition, d'un « Créer la version XX » ou d'un formulaire vierge ; le verrou tient dans les trois cas, la prose
  proposée étant dans la langue cible ; le groupe gardé est celui de la source quand il y en a un, sinon aucun).
- Le docblock d'`EntryFormLockHint.vue` : le texte rendu seulement pendant le verrou, un texte par état ; plus de
  prémisse « depuis une entrée en édition ».
- Toutes les lignes touchées ≤ 140 caractères (les `aria-describedby` littéraux disparaissent).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : test, rouge** — `useEntryFormLock.spec.ts` : `state` vaut `'none'`, `'editing'`, `'proposal'` selon les
  deux fonctions (l'édition l'emporte) ; `localeDescribedBy` : `undefined` sans verrou ni aide, l'aide seule hors
  verrou, `'<aide> <hint>'` pendant, `'<hint>'` sans aide ; `describedBy('a', undefined, '', 'b')` vaut `'a b'`,
  `describedBy()` vaut `undefined` ; les valeurs se lisent sans `.value` (objet `reactive`). Run :
  `docker compose exec -T frontend npm test -- tests/presentation/ui/admin/useEntryFormLock.spec.ts`. Expected : FAIL,
  module introuvable.
- [ ] **Step 3 : implémenter** le composable, renommer le texte, câbler les huit formulaires ; `docker compose restart frontend`.
- [ ] **Step 4 : règle** — les puces #582 et D-a **remplacées** par une seule : « **A locale selector says why it is
  locked, and the help it always has** (#582; #748, R79b D-a, R92): About's settings, site and me cards and Quality's
  principles and traits always render a help text (`<select id>-help`, a static `aria-describedby`); every per-entry form's lock goes through
  `presentation/ui/admin/useEntryFormLock.ts` (`state`: `'none' | 'editing' | 'proposal'`), whose
  `EntryFormLockHint.vue` text is rendered **only during the lock** and referenced by `aria-describedby` only then,
  after the permanent help when there is one (`localeDescribedBy`: one or two ids); one text per state.
  `tests/support/entryFormLock.ts` (`describedTexts()`, `describeEntryFormLock()`) reads the texts an
  `aria-describedby` designates, fails on a dangling IDREF and runs the lock cases on the eight forms;
  `AdminAboutPage.spec.ts` and `AdminQualityPage.spec.ts` pin the permanent help. » (St8-1, Sp8-11 ; l'attribut
  statique ne vaut plus que pour les sélecteurs sans verrou — les réglages « À propos » —, celui des formulaires par
  entrée est lié : le vérifier dans le code avant d'écrire, et ajuster la parenthèse). Taille notée.
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"` (filet + les tests du composable),
  `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "LocaleLockedHint\|isLocaleLocked\|une entrée en édition" frontend/src .claude` : vide ; longueurs mesurées
  (`python3 -I`) : aucune ligne touchée au-delà de 140.
- [ ] **Step 6 : commit** — `refactor(frontend): le verrou des formulaires par entrée tient dans un composable, son texte lit l'état, d'où naît une proposition dit juste (#748)`.

---

### Task R93 : Formulaires par entrée — une proposition affichée désactive « Proposer la version XX » ; un seul texte dit le verrou et ce que fait « Annuler » (décisions 1 et 2 : CR8-1 = Sp8-4, CR8-4 = Sp8-9)

**Files :**
- Modify : `frontend/src/presentation/ui/admin/useEntryFormLock.ts`, `frontend/src/presentation/ui/admin/EntryFormLockHint.vue`, les sept fichiers de formulaires, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`
- Test : `frontend/tests/presentation/ui/admin/useEntryFormLock.spec.ts`, `frontend/tests/support/entryFormLock.ts`, `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts` (catégorie « moi »)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant », la phrase « while the form holds the proposal its locale selector is locked … by decision) » ; la sous-puce de la catégorie, « the same id, the text `categoryProposalLockedHelp` »)

**Interfaces :**
- Choix tranché 3. Clé `admin.lockedByProposal` (FR, EN : textes du paragraphe « Décisions 1 et 2 » des options
  comparées) ; `admin.localeLockedProposal` et `admin.about.meCard.categoryProposalLockedHelp` retirées des deux
  fichiers. `entryFormLock.ts` : `LOCKED_BY_PROPOSAL_TEXT` remplace `LOCALE_LOCKED_PROPOSAL_TEXT` et
  `CATEGORY_LOCKED_PROPOSAL_TEXT` (changement exprès des tests qui les lisent).

- [ ] **Step 1 : tests, rouges** — `useEntryFormLock.spec.ts` : `isProposalLocked` et `proposalDescribedBy` (vrai et
  `<hint>` pendant une proposition seulement). `describeEntryFormLock`, deux cas de plus pour les huit formulaires :
  `it("après une proposition, « Proposer la version … » est désactivé et décrit par le texte du verrou")` et
  `it("« Annuler » après une proposition rend le bouton de traduction libre de tout texte de verrou")` (Review Focus 2) ;
  le cas « proposition expliquée » attend désormais `LOCKED_BY_PROPOSAL_TEXT`. `AdminAboutPage.spec.ts` : la catégorie
  « moi » en proposition décrite par `LOCKED_BY_PROPOSAL_TEXT`, en édition par `categoryLockedHelp` seul (Review
  Focus 3) ; un ordre modifié **et** une proposition : le bouton décrit par les deux textes, dans cet ordre (Review
  Focus 1, sur un formulaire : études de cas). Expected : le bouton actif ; l'ancien texte « « Annuler » permet d'en
  changer ».
- [ ] **Step 2 : implémenter** selon les interfaces ; `docker compose restart frontend`.
- [ ] **Step 3 : règle** — « while the form holds the proposal its locale selector is locked, as in editing, and
  « Annuler » drops it (R78, R79, Sp7-1: the kept group would make a second version of the source locale); so is the
  me-card **category** selector (R79b, D-b: …) » → « while the form holds the proposal its locale selector is locked, as
  in editing (R78, R79, Sp7-1: the kept group would make a second version of the source locale), and so are « Proposer
  la version XX » (R93, decision 1 of the eighth review: a second translation took the form back to the source locale,
  group kept) and the me-card **category** selector (R79b, D-b: …), all described by one text,
  `admin.lockedByProposal`, which says that « Annuler » drops the proposal and empties the form (R93, CR8-4) » (le
  contenu de la parenthèse D-b, sur « Créer la version XX », inchangé) ; la sous-puce de la catégorie : « (editing, or a translation
  proposal, R79b: the same id, the text `categoryProposalLockedHelp`) » → « (editing; during a translation proposal the
  category is described by the form's proposal text instead, R93) ». Taille notée.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "permet d'en changer\|lets you change it\|categoryProposalLockedHelp\|localeLockedProposal" frontend .claude`
  (`node_modules` exclu) : vide.
- [ ] **Step 5 : commit** — `fix(frontend): une proposition affichée désactive « Proposer la version », et un seul texte dit le verrou et qu'« Annuler » abandonne la proposition (#748)`.

---

### Task R94 : Formulaires par entrée — une proposition verrouille « Version de » ; la limite d'une langue que le groupe a déjà écrite (décision 3 : Sp8-5 ; CR8-2 pour la limite)

**Files :**
- Modify : `frontend/src/presentation/ui/admin/useEntryFormLock.ts` (docblock : la limite), les sept fichiers de formulaires
- Test : `frontend/tests/support/entryFormLock.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant », la phrase réécrite par R93)

**Interfaces :**
- Choix tranché 4 : le `BaseSelect` « Version de » de chaque formulaire `:disabled="lock.isProposalLocked"`,
  `:aria-describedby="lock.proposalDescribedBy"`.
- Limite (docblock du composable et règle, Q1 suivie) : une proposition dont la langue cible existe déjà dans le groupe
  — depuis « Créer la version XX », ou depuis l'édition d'une entrée dont le groupe a les deux langues — prend, à
  l'enregistrement, le 409 `translation-already-exists` ; « Version de » verrouillé, « Annuler » est la sortie ; depuis
  un formulaire vierge, la proposition part sans groupe et remplace la prose source, jamais enregistrée (antérieur,
  spec 0004).

- [ ] **Step 1 : tests, rouges** — `describeEntryFormLock`, un cas de plus pour les huit formulaires :
  `it("après une proposition, « Version de » est désactivé et décrit par le texte du verrou ; « Annuler » le libère")`
  (Review Focus 2 et 3) ; et `it("en édition, « Version de » reste libre et ne désigne aucun texte de verrou")`.
  Expected : « Version de » actif après une proposition.
- [ ] **Step 2 : implémenter** selon les interfaces.
- [ ] **Step 3 : règle** — dans la phrase de R93 : « and so are « Proposer la version XX » (R93, …) and the me-card
  **category** selector » → « and so are « Proposer la version XX » (R93, …), « Version de » (R94, decision 3:
  « aucune » would detach the proposal from its source) and the me-card **category** selector » ;
  puis, après la fin de la phrase : « A proposal whose target locale the group already has (from « Créer la version XX »,
  or from an entry whose group has both) ends on the server's 409 `translation-already-exists` at save, « Annuler »
  being the way out (accepted, Q1 of the eighth review: detecting it would copy the server's uniqueness rule, as #573
  refused). » Taille notée.
- [ ] **Step 4 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `fix(frontend): une proposition de traduction verrouille « Version de » sur les huit formulaires par entrée, la limite d'une langue que le groupe a déjà écrite (#748)`.

---

### Task R95 : Le refus du backoffice — seule la première lecture refusée après le montage garde l'avis de l'enregistrement précédent ; un état discriminé (décision 4 : CR8-3 ; St8-10)

**Files :**
- Modify : `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`
- Test : `frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-refus.md` (l. 83-85, « a later refused **read** keeps that text and re-announces it (the landing page's own load, CR7-4), a later refused **write** brings back `common.accessRefused`. »)

**Interfaces :**
- Choix tranché 5. `markBackofficeAccessRefused` : une lecture comptée fait passer `earlierSave` de `'atMount'` à
  `'keptByARead'`, de `'keptByARead'` à `'none'` ; une écriture comptée `'none'` et `countedWrite = { kind: 'write',
  account }`. `settleBackofficeAccessRefusalsOnMount` : une écriture comptée du compte courant → `'atMount'`, sinon
  `NO_REFUSAL`. `earlierSaveRefused = computed(() => 'none' !== state.value.earlierSave)`. Les docblocks du module, de
  `RefusalState`, de `markBackofficeAccessRefused` et de `settle…` disent la règle de la décision 4.

- [ ] **Step 1 : tests, rouges** — `backofficeAccessRefusal.spec.ts` :
  `it("après le montage, la première lecture refusée garde l'avis, la seconde ramène le texte courant")` (expected :
  l'avis vrai après la seconde) ; `it("une écriture comptée sous aucun compte se distingue de l'absence d'écriture")`
  (St8-10 : au montage sous aucun compte, l'avis n'est dit que si une écriture a été comptée ; caractérisation, vert
  d'emblée, vu rouge par la mutation du Step 3). `AccessRefusedNotice.spec.ts` (Review Focus 4) :
  `it("l'avis de l'enregistrement précédent tient la lecture de chargement, pas la suivante")` (textes FR exacts de
  `common.earlierSaveRefused` puis `common.accessRefused`, le `<span>` réannoncé à chaque refus).
- [ ] **Step 2 : implémenter** selon les interfaces.
- [ ] **Step 3 : rouge vu par mutation** — dans `settle…`, « aucune écriture » lu comme « écriture sous aucun compte »
  (`('write' === countedWrite.kind ? countedWrite.account : null) === currentAccount`) → le test St8-10 rougit (sous
  aucun compte, l'avis dit sans écriture) ; restauré par copie vérifiée par `cmp`.
- [ ] **Step 4 : règle** — « a later refused **read** keeps that text and re-announces it (the landing page's own
  load, CR7-4), a later refused **write** brings back `common.accessRefused`. » → « the **first** read refused after the
  mount keeps that text and re-announces it (the landing page's own load, CR7-4); any later refusal, read or write,
  brings back `common.accessRefused` (R95, decision 4 of the eighth review). » Taille notée.
- [ ] **Step 5 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(frontend): seule la lecture de chargement refusée après le montage garde l'avis de l'enregistrement précédent, un état discriminé (#748)`.

---

### Task R96 : La garde `'omit'` lit les parties de gabarit et les expressions du `<template>` ; le `\r` d'un commentaire gardé ; la branche d'erreur de `scriptOf` testée (CR8-10 = C8-7, St8-2, St8-7 pour le `\r`)

**Files :**
- Modify : `frontend/tests/support/sourceText.ts`, `frontend/tests/support/sourceText.spec.ts`, `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session.md` (l. 158, puce `noOmittedCredentials.spec.ts`)

**Interfaces :**
- Choix tranché 9 : `stringLiterals` retient aussi `TemplateHead`, `TemplateMiddle`, `TemplateTail` (`node.text`) ;
  `export function templateExpressionsOf(path: string, source: string): string[]` (le parse de `vue/compiler-sfc`,
  `descriptor.template.ast` parcouru : `exp.content` des directives — `v-on` en ligne compris —, `arg.content` d'un
  argument dynamique, `content.content` des interpolations ; `[]` sans gabarit ou pour un autre chemin ; un `.vue`
  illisible refusé comme par `scriptOf`) ; `withoutComments` : `.replace(/[^\r\n]/g, '')`.
- `noOmittedCredentials.spec.ts` : la garde lit `stringLiterals(scriptOf(…))` **et** `stringLiterals` de chaque
  expression du gabarit ; son docblock dit les deux lectures et garde la limite de R51 (concaténation, variable).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté, et la durée de `noOmittedCredentials.spec.ts`
  et `sessionStateEncapsulation.spec.ts` (sortie de vitest).
- [ ] **Step 2 : tests, rouges** — `sourceText.spec.ts` : ``stringLiterals("const m = `${''}omit`")`` contient `'omit'`
  (expected : `['']`) ; `templateExpressionsOf('X.vue', '<template><button @click="go({ credentials: \'omit\' })">x</button></template>\n<script setup lang="ts">\n</script>\n')`
  rend une expression dont `stringLiterals` vaut `['omit']` (expected : fonction introuvable) ;
  `withoutComments("x /* a\r\nb */ y")` garde le `\r\n` du commentaire de bloc (`"x  \r\n y"` à l'espace près ;
  expected : `"x  \n y"`, le `\r` retiré) ;
  `scriptOf('X.vue', '<template><p></template>')` lève une erreur qui nomme `X.vue` (St8-2 ; vert d'emblée : vu rouge
  par la mutation du Step 4).
- [ ] **Step 3 : implémenter** selon les interfaces.
- [ ] **Step 4 : rouges vus par mutation** (copie vérifiée par `cmp`) : (a) `@click="void fetch('/x', { credentials: 'omit' })"`
  posé sur un bouton d'un `.vue` de `src/` → `noOmittedCredentials.spec.ts` rouge, le fichier nommé ; (b) le `throw` de
  `scriptOf` remplacé par `return source` → le test St8-2 rouge.
- [ ] **Step 5 : règle** — « read by `tests/support/sourceText.ts` on TypeScript's syntax tree, a `.vue` by its
  `<script>` blocks only, R68, R80 » → « read by `tests/support/sourceText.ts` on TypeScript's syntax tree, template
  parts included, a `.vue` by its `<script>` blocks and the expressions of its `<template>`, R68, R80, R96 ». Taille notée.
- [ ] **Step 6 : verts** — `make front-test; echo "exit=$?"` (durées des deux gardes notées et comparées au filet),
  `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 7 : commit** — `test(frontend): la garde 'omit' lit les parties de gabarit et les expressions du template d'un .vue, le lecteur de source garde le CR (#748)`.

---

### Task R97 : Les sources de `src/` lues une fois pour les gardes ; le docblock du lecteur sans historique (St8-6, St8-7 pour le docblock ; refactoring)

**Files :**
- Create : `frontend/tests/support/srcSources.ts`
- Modify : `frontend/tests/support/sourceText.ts` (docblock), `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts`

**Interfaces :**
- Choix tranché 9 (R97) : `SRC_SCRIPTS` et `SRC_TEMPLATE_EXPRESSIONS`, la glob `['../../src/**/*.ts', '../../src/**/*.vue']`
  (`?raw`, `import: 'default'`, `eager: true`) et son docblock écrits une fois ; les deux gardes les importent.
- Docblock de `sourceText.ts` : ce que le module fait et ce qu'il coûte, au présent ; l'historique (R68, « la limite
  était fausse ») retiré — il vit dans le plan et dans `git log`.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : refactorer** selon les interfaces ; `docker compose restart frontend` après le fichier créé.
- [ ] **Step 3 : verts** — `make front-test; echo "exit=$?"` (même nombre), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"` ; `grep -rn "import.meta.glob" frontend/tests` : la glob des sources de `src/`
  n'apparaît plus que dans `srcSources.ts` (les autres globs du dossier notées, inchangées).
- [ ] **Step 4 : commit** — `refactor(frontend): les sources de src/ lues une fois pour les gardes, le lecteur de source décrit au présent (#748)`.

---

### Task R98 : Réglages « À propos » — un enregistrement en vol dit une fois ; une condition que rien n'observe retirée (CR8-6, St8-14 ; refactoring)

**Files :**
- Modify : `frontend/src/application/admin/about/useAdminAboutSettings.ts`, `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue`
- Test : `frontend/tests/application/admin/about/useAdminAboutSettings.spec.ts` (si `isSavingLocale` quitte l'interface : changement exprès)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« (`isSavingLocale` of both, R67, R86, … ) »)

**Interfaces :**
- Choix tranché 10. Le bouton de traduction : `:disabled="!hasProseToTranslate || isAnySaving || isLocked"`. Le `watch`
  de `settings` : `proposal.value = null` sans condition, commentaire « des réglages qui arrivent sont ceux de la
  langue montrée (la clé courante) : la proposition, si elle est affichée, est la leur ; sinon elle n'est pas affichée
  et le retour sur sa cible la retirerait de toute façon (St8-14) ». `isProposalShown` reste pour le `v-if` de l'avis.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : refactorer** selon les interfaces.
- [ ] **Step 3 : règle** — « while the locale shown **or the target locale** has a save in flight (`isSavingLocale` of
  both, R67, R86, » → « while any locale — with two, the one shown or the target — has a save in flight (`isAnySaving`,
  R67, R86, R98, ». Taille notée.
- [ ] **Step 4 : verts** — `make front-test; echo "exit=$?"` (même nombre, ou l'écart dit si un test lisait
  `isSavingLocale`), `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `refactor(frontend): réglages « À propos », un enregistrement en vol dit une fois et une condition que rien n'observe retirée (#748)`.

---

### Task R99 : Backend — les sites d'autorisation typés, les exemptions par cas d'enum, le docblock sans recopie (St8-8 ; refactoring)

**Files :**
- Create : `backend/tests/Support/AuthorizationSite.php`
- Modify : `backend/tests/Support/AuthorizationSites.php`, `backend/tests/Security/AuthorizationLivesInAccessControlTest.php`
- Aucune règle : ni ces fichiers ni le nouveau ne sont dans les `paths:` d'une règle (vérifié pour R81 ; à revérifier au rendu).

**Interfaces :**
- Choix tranché 11. Les fournisseurs de cas rendent des `AuthorizationSite` (`new AuthorizationSite(3,
  AuthorizationSiteKind::Refusal)`) ; comportement identique.

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`, nombre noté ; la classe ciblée verte.
- [ ] **Step 2 : refactorer** selon les interfaces ; `make back-style-fix`.
- [ ] **Step 3 : verts** — la classe ciblée verte avec le même nombre de cas ; `make back-test; echo "exit=$?"` (même
  nombre), `make back-quality; echo "exit=$?"` ; `grep -n "\->value" backend/tests/Security/AuthorizationLivesInAccessControlTest.php` :
  une occurrence ; lignes ≤ 140 caractères dans les trois fichiers.
- [ ] **Step 4 : commit** — `refactor(security): les sites d'autorisation typés, les exemptions écrites par cas d'enum, le docblock du détecteur sans recopie (#748)`.

---

### Task R100 : La règle de l'identité du compte d'un refus écrite une fois (St8-9 ; refactoring documentaire)

**Files :**
- Modify (docblocks et commentaire) : `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts` (docblocks d'`account` et de `refused`), `frontend/src/presentation/router/backofficeSessionExpiry.ts` (en-tête l. 33-38, commentaire l. 59), `frontend/src/application/auth/useAuth.ts` (docblock de `lastAccountName`)
- La règle `frontend-session-refus.md` (sous-puce « the account ») est la source : relue, non modifiée sauf si elle a perdu une limite que les docblocks disaient (la reprendre alors).

**Interfaces :**
- Choix tranché 12 : chaque endroit dit en une phrase ce que porte son champ ou sa fonction, puis la phrase de renvoi
  identique. Aucun changement de comportement.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : textes** — selon les interfaces ; avant, chaque limite écrite dans les docblocks (C8, `null` d'un
  côté, `null` des deux, même nom après reconnexion ou rôle perdu, `null` à l'envoi puis un compte) est cherchée dans la
  sous-puce de la règle ; une absente y est ajoutée en remplaçant la phrase voisine.
- [ ] **Step 3 : verts** — `make front-test; echo "exit=$?"` (même nombre), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "recréé sous le même nom" frontend/src` : vide.
- [ ] **Step 4 : commit** — `docs(frontend): la règle de l'identité du compte d'un refus écrite une fois, les docblocks y renvoient (#748)`.

---

### Task R101 : Balayage documentaire de la huitième série

Les docstrings et les règles ont suivi chaque comportement dans son commit ; cette tâche vérifie qu'il ne reste rien.

**Files :** ceux que le balayage désigne (`.claude/rules/*`, docblocks de `frontend/src`, `frontend/tests`, `backend/src`, `backend/tests`, `tools/`).

- [ ] **Step 1 : balayage** — `grep -rn "LocaleLockedHint\|localeLockHint\|isLocaleLocked\|localeLockedProposal\|categoryProposalLockedHelp\|permet d'en changer\|lets you change it\|smoke_add_basic_auth\|Neuf invariants\|eight forms, one text each\|static .aria-describedby\|une entrée en édition\|keeps that text and re-announces\|isSavingLocale(otherLocale\|Les témoins de la prémisse" .claude docs frontend/src frontend/tests backend/src backend/tests tools`
  (les fichiers de secrets et `node_modules` exclus) : ne rend que l'historique daté ou le sens juste ; le noter ligne
  par ligne. Toute phrase fausse trouvée est **remplacée**.
- [ ] **Step 2 : verts** — `tools/check-claude-rules.sh; echo "exit=$?"` ; taille de chaque règle touchée par la série
  notée, toutes < 50 000 octets, `backoffice-api.md` à ses 39 834 octets de départ ; `make front-test; echo "exit=$?"`
  si un fichier de `frontend/` a changé, `make back-test; echo "exit=$?"` si un de `backend/`, les tests hors ligne si
  un de `tools/`.
- [ ] **Step 3 : commit** (seulement si le balayage a changé un fichier) — `docs: le balayage de la huitième relecture de #748`.
  Sinon, le rendu dit « rien à changer » et les sorties du grep.

### Traçabilité des constats

| Constat | Traitement |
|---|---|
| CR8-7 (= St8-3 = St8-4) | R92 (composable) ; R91 en préalable |
| CR8-1 (= Sp8-4) | R93 (décision 1) |
| CR8-2 (= Sp8-6) | R92 (textes justes) ; R94 (limite « Créer la version », Q1) |
| CR8-4 (= Sp8-9) | R93 (décision 2) |
| Sp8-5 | R94 (décision 3) |
| CR8-3 | R95 (décision 4) |
| CR8-5 | R90 |
| C8-1 | R90 |
| CR8-9 (= C8-3) | R88 (DTO, objets imbriqués, `uriVariable` non `Link`) ; vacuité des paramètres écartée avec sa raison (choix 6) |
| CR8-10 (= C8-7) | R96 |
| St8-1 (= Sp8-7) | R92 |
| St8-2 | R96 |
| St8-5 | R91 |
| St8-6 | R97 |
| St8-7 | R96 (`\r`), R97 (docblock) |
| St8-8 | R99 |
| St8-9 | R100 |
| St8-11 (= CR8-8) | R90 |
| St8-12 (= C8-2) | R90 (second appel : option C) ; double source : limite écrite (R90) |
| St8-14 | R98 (condition retirée) |
| Sp8-1 | tâches 11 et 12, commit de ce plan |
| CR8-6 | R98 |
| St8-10 | R95 |
| St8-13 | R89 (mutation « 401 et pas 403 » ; les deux raisons de `/api/logout` alignées) |
| C8-5 | R89 |
| C8-6 | R89 (invariant n° 10) |
| C8-4 | R89 (route sans méthode refusée par l'invariant n° 10 ; valeur unique d'un paramètre : limite écrite) |
| Sp8-8 | note datée au choix tranché 4 de la septième série, commit de ce plan |
| Sp8-10 | R89 |
| Sp8-11 | R92 |
| Sp8-2, Sp8-3, C8-8, C8-9, écarts déclarés de la septième série | écartés (ci-dessous, « Sans suite » du fichier de constats) |

### Constats écartés, avec leur raison

- **CR8-9, la garde contre la vacuité des paramètres.** Pour : une garde qui ne parcourt aucun paramètre ne rougirait
  jamais. Contre : aucune ressource n'en déclare, une garde rougirait aujourd'hui ; la boucle est celle des liens,
  exécutée et comptée ; la lecture est prouvée par le fournisseur `parametersThatCarrySecurity` ; une ressource de test
  ajouterait une route `/api` au conteneur que lisent toutes les gardes d'exposition. Raison écrite dans le docblock
  (R88). Écarté pour le code.
- **St8-12, la double source de la bibliothèque, pour le code.** Pour : aucun dossier orphelin. Contre : un marqueur de
  shell pour un cas qu'aucun script n'a, que la relecture voit, et dont le reste est un dossier 0700 de l'utilisateur.
  Limite écrite (R90).
- **Sp8-2** (pas d'audit axe en état verrouillé) : non exigé par R79b ; la tâche 11 audite le formulaire avec
  proposition, et R93-R94 y ajoutent les contrôles verrouillés. Sans suite.
- **Sp8-3** (R82 n'est pas un écart) : le plan prescrivait `in_array($method, UNSAFE_METHODS)`. Sans suite.
- **C8-8** (le verrou de langue est une présentation) : le serveur est la garde (index unique, 409,
  `locale-immutable` sur `PUT`) ; le verrou de catégorie protège la cohérence, pas la sécurité (`ROLE_SUPER` seul).
  Sans suite.
- **C8-9** (fuite d'un compte à l'autre) : au montage, le compte de l'écriture est comparé au compte courant ; un
  changement de compte remonte la page (`pageKey`). CR7-2 non aggravée. Sans suite.
- **Écarts déclarés de la septième série, jugés acceptables** : R73 mutation (d), R74 `getLinks()` (la note de Sp8-8
  rectifie le plan), R79b `categoryProposalLockedHelp` (R93 le remplace), R83 nettoyage avant la validation, R86 mutant
  équivalent (St8-14, R98), cases du plan non cochées (convention). Sans suite.

---


## Corrections de la neuvième relecture (2026-10-31)

R88 à R101 sont faites (après c585b960, jusqu'à 2c0a6d57 ; constats de la neuvième relecture : 7e3e39b3).

Quatre relectures du diff c585b960...2c0a6d57 (`/code-review medium` CR9-*, audit de sécurité C9-*, axe Standards
St9-*, axe Spec Sp9-*) : aucun critique ni haut, un moyen (St9-6, lisibilité). Constats complets et décisions :
`tasks/relectures-748/relecture-9-constats.md` (la source de vérité). Tous sont traités ici par R102 à R111, avant les
tâches 11 et 12 : corrigés, ou écartés par l'auteur (« Sans suite », repris ci-dessous). Les points de contrôle au
navigateur et le corps de PR de la série sont ajoutés aux tâches 11 et 12 dans le commit de ce plan. Les sections
passées restent l'archive de leur moment ; la contrainte de mutation de la huitième série est rectifiée ici (Sp9-7,
« Contraintes propres aux tâches R102 à R111 »).

### Décisions de l'auteur (2026-10-31)

1. **CR9-1 / Sp9-9 : lier l'avis au chargement.** L'avis « enregistrement précédent » vaut pour **toutes** les lectures
   du chargement de la page d'arrivée et retombe à `'none'` dès que ce chargement se termine, réussi ou refusé ; toute
   lecture refusée ensuite rend `common.accessRefused`. Amende la décision 4 de la huitième série. R102 (la façon de
   signaler la fin du chargement est la question Q1 ci-dessous ; le plan suit sa recommandation).
2. **CR9-9 : remettre la condition.** `if (isProposalShown)` rétabli dans le `watch` de `settings`
   d'`AdminAboutSettingsForm`, avec un test qui l'observe (enregistrer la source pendant une proposition vers l'autre
   langue garde l'avis de la cible). R103 (Q2 ci-dessous : ce que le test peut observer).
3. **CR9-8 / C9-6 : échouer tôt.** `AUDIT_BASIC_AUTH` défini mais vide → sortie 2 « secret de Basic Auth vide » avant
   tout appel, cas ajouté à `audit-prod.test.sh` ; variable non définie (prod) inchangée. R106.
4. **Sans suite** : St9-17 (emplacement de `useEntryFormLock.ts`), Sp9-8 (champ `heading`), C9-8 (angle mort de
   `getNativeType()`, pas même écrit), St9-11 (`describedBy` et voisins restent où ils sont).
5. **Toujours valables** : C5-1, C5-2, C7, CR6-6, CR7-2, la limite de R94 ; critère d'arrêt **tout jusqu'à zéro** : tous
   les autres constats se corrigent sans décision.

### Vérification de chaque constat dans le code (HEAD 7e3e39b3)

- **CR9-1 ≈ Sp9-9, confirmé.** `backofficeAccessRefusal.ts:113` : une lecture comptée fait `'atMount'` → `'keptByARead'`
  → `'none'`, rien d'autre ne fait avancer l'état ; aucune notion de fin de chargement n'existe dans le module. Les
  chargements partent tous dans le `setup` de la page : chaque composable appelle `void load()` en fin de `setup`
  (`useAdminCaseStudies.ts:86`, `useAdminQualityPrinciples.ts:82`…, treize composables, tous par `useAdminLoad`), et
  `AdminAboutSettingsForm.vue:100-106` par un `watch(…, { immediate: true })`. `AccessRefusedNotice` est rendue par
  `AdminLayout.vue:164`, avant `<RouterView />` (l. 166) : son `onMounted`, qui appelle
  `settleBackofficeAccessRefusalsOnMount` (l. 90-93), part après le `setup` de toute la page — **les lectures du
  chargement sont en vol au moment du règlement**. `BackofficeHttpClient` appelle `refused()` **avant** de lever
  `AccessForbiddenError` (docblock de `refused`, `BackofficeSessionReaction.ts:54-60`) : le refus d'une lecture est
  compté avant que le `finally` de son `load()` ne s'exécute.
- **CR9-9, confirmé ; l'effet n'est pas visible dans le DOM.** `AdminAboutSettingsForm.vue:89-92` vide `proposal` à
  toute arrivée de réglages. Avec la condition, une proposition vers l'EN survit à l'arrivée et à l'enregistrement des
  réglages FR, mais le retour sur l'EN relit l'EN (`watch` de `props.locale`, l. 100) et la retire de toute façon ;
  l'avis n'est rendu que sous sa cible (`v-if="proposal && isProposalShown"`, l. 233). C'est le mutant équivalent de
  St8-14 : le test de la décision 2 lit l'état du composant (R103, Q2).
- **CR9-8 = C9-6, confirmé.** `audit-prod.sh:55-64` : `[[ -v AUDIT_BASIC_AUTH ]]` vrai pour une valeur vide, la
  bibliothèque ne crée rien, `CURL_OPTS` reste sans `-K` et l'audit court ; `audit-prod.test.sh:155-164` pince
  aujourd'hui ce comportement (« l'audit court », sortie 1) — changement exprès.
- **St9-16, confirmé.** `audit-prod.sh:62` range `-K` dans `CURL_OPTS` ; l'audit n'appelle jamais `smoke_fetch`, écrit
  le fichier une fois, avant tout appel, et ne le réécrit pas : aucun `-K` périmé n'y est possible (la forme écartée
  par St8-12 visait un second `smoke_write_auth_config`). Le commentaire Basic Auth est recopié dans
  `smoke-dead-session-cookie.sh:76-79`, `smoke-login-throttling.sh:61-64`, `smoke-mcp.sh:90-93`.
- **C9-4, C9-5, confirmés.** `smoke_fetch` (`smoke-http.sh:200-205`) passe `-K` sans vérifier le fichier ; les trois
  appelants font `|| exit 1` (`smoke-login-throttling.sh:84`, `smoke-dead-session-cookie.sh:104`, `smoke-mcp.sh:102`).
  `smoke_write_auth_config` (l. 113) accepte `SMOKE_AUTH_DIR` et `SMOKE_AUTH_CONFIG`, que son `smoke_cleanup` de tête
  vide avant la lecture : retour 0, aucune Basic Auth.
- **St9-18, confirmé.** `deploiement-nginx.md:117-119`, une phrase de plus de 900 caractères, trois niveaux de
  parenthèses et de tirets.
- **C9-1, confirmé.** `ApiRouteExposureTest.php:152-155` ; `AuthenticationFlowTest.php:83-85` vérifie le 403 d'un
  logout sans en-tête, pas l'absence de `Set-Cookie` ; aucun texte ne dit que ce 403 est la barrière contre la
  déconnexion forcée.
- **CR9-5 = Sp9-11, confirmé.** `ApiRouteExposureTest.php:191` (invariant n° 10) **et** `allApiRoutePaths()` (l. 773) :
  `str_starts_with($path, '/api')`.
- **C9-7, Sp9-10, St9-5, confirmés.** Docblock de l'invariant n° 10 (l. 177-183) ; `security-authentication.md:32-34`
  (la phrase de R89 entre « Its `EXCLUDED_PATHS` … » et « Two since #647 ») ; `ApiRouteExposureTest.php:325-326`
  (« cette prémisse » sans antécédent) ; `PublicRouteIgnoresRefusedSessionListener.php:27-28` (incise ouverte par un
  tiret, jamais fermée, sans verbe).
- **St9-6, CR9-4 = St9-7, Sp9-6, confirmés.** `ApiPlatformOperationsCarryNoSecurityTest.php:79-166` (file, DTO, objets
  imbriqués, six assertions) ; `appClassesIn()` (l. 319-341) ne suit ni `GenericType::getVariableTypes()` ni
  `CollectionType::getCollectionKeyType()` ; l. 158 : 143 caractères.
- **CR9-2 = St9-2 = C9-3, CR9-3 = St9-4, St9-3, confirmés.** `AuthorizationLivesInAccessControlTest.php:92`
  (`->name`) contre `:291` (`->value`) ; `AuthorizationSiteKind.php:13-14` (« Les valeurs sont les libellés lus dans les
  messages d'échec et dans les exemptions ») ; les cas `Refusal` et `DecisionManager` (l. 30-34) disent moins que ce
  que le docblock d'`AuthorizationSites` leur délègue ; `EXEMPTIONS` (l. 62-80) en tuples `[nature, nombre]` et
  `[raison, …]`, `countByKind($this->expand($expected))` (l. 232).
- **CR9-7 = Sp9-1, CR9-6, St9-14 (+ Sp9-12), confirmés.** `sourceText.ts:155-158` : une expression n'est retenue que si
  `undefined !== node.ast` ; l'`exp` d'un `v-for` (lue en mode `Skip`, `ast` absent) et son `forParseResult` ne sont
  jamais lus ; `'object' === typeof node.content` est vrai pour `null` ; `TemplateNode` (l. 128-136, « Vrai pour une
  expression simple ») est écrit à la main et l'arbre lui est converti par `as unknown as` (l. 168) ;
  `stringLiterals` (l. 70-73) dit « gabarits sans `${` » puis le contraire ; « Reste vrai : … n'a plus de limite écrite »
  (l. 21-22) est un reste d'historique. `vue/compiler-sfc` ne réexporte pas les types de nœuds de `@vue/compiler-core`
  (seulement `BindingMetadata`, `CompilerError`… : `compiler-sfc.d.ts:4`) ; `SFCTemplateBlock['ast']` est typé `RootNode`.
- **St9-12, CR9-10 = St9-13, C9-9, St9-15, confirmés.** `noOmittedCredentials.spec.ts:30-49` (`sources` et `literalsOf`
  recomposent la même lecture ; le vrai test lit `literals.includes('omit')`, pas `offendsWithOmit`) ;
  `sessionStateEncapsulation.spec.ts:227-228` (`const sources = SRC_SCRIPTS`, `const file = path`) ; **C9-9 : la
  ligne `expect(source).not.toBe('')` existe déjà** (`sessionStateEncapsulation.spec.ts:295`) : ce qui reste est le
  repli `?? ''` (l. 291), qui transforme un fichier absent en assertion au lieu d'une erreur qui le nomme — R110 le
  retire. « Depuis R51 », « Depuis R96 » (`noOmittedCredentials.spec.ts:16`, `:21`), « celle qui était là »
  (`AdminAboutSettingsForm.vue:91`).
- **C9-2, St9-8 = Sp9-2 = Sp9-3, St9-9, St9-10, confirmés.** `handleTranslate` (`AdminCaseStudiesPage.vue:256` et les
  six autres fichiers) ne lit pas le verrou ; `entryFormLock.ts:11` (`LOCKED_BY_PROPOSAL_TEXT` : « langue, bouton de
  traduction et catégorie », sans « Version de ») et `:94` (« les quatre cas ») ; « quatre cas » dans les six specs de
  formulaires ; `EntryFormLockHint.vue:6-13` (le sélecteur de langue seul ; « « Annuler » la retire ») ; identifiants
  `admin-…-locale-locked-help` (sept fichiers) et clés `admin.localeLockedEditing` / `admin.lockedByProposal`, alors
  que le texte dit l'état du verrou du formulaire ; `hasProposal: () => null !== proposalSourceLocale.value` répété
  (`AdminCaseStudiesPage.vue:102`…) ; un `translateButton` local dans six specs (`AdminCaseStudiesPage.spec.ts:152`…),
  à côté de `translateEntryButton` (`tests/support/entryFormLock.ts`).
- **Sp9-4, Sp9-5, Sp9-6 (front), confirmés.** `BackofficeSessionReaction.ts:53-60` (docblock de `refused`, sans la
  phrase de renvoi du choix 12 de R100) ; `AccessRefusedNotice.spec.ts` (second texte lu par `toContain`) ;
  `backofficeAccessRefusal.ts:140` et `:143` au-delà de 140 caractères.
- **Sp9-7, confirmé.** Le plan, contraintes de la huitième série (« Une mutation s'écrit en place, avec l'outil Edit »),
  contredit `brief-commun-agents.md:54-57` (Python seul). Rectifié dans les contraintes ci-dessous ; le brief, lui,
  est juste.

### Options comparées

**Décision 1 — qui dit la fin du chargement de la page d'arrivée** (Q1, rendue à l'auteur ; le plan suit (A)) :

| Option | Pour | Contre |
|---|---|---|
| (A) **`useAdminLoad` déclare chacun de ses `load()` au module** (`beginBackofficeLoad()` au départ, la fonction rendue dans le `finally`) ; le chargement de la page d'arrivée est l'ensemble des `load()` en vol au règlement, et il se termine quand ce nombre retombe à zéro — **recommandée** | un seul endroit, les treize composables couverts sans toucher une page ; l'ordre est sûr (le refus est compté avant le `finally`, le règlement suit le `setup` de la page) ; une page à plusieurs listes (À propos, Qualité) est un seul chargement ; le module reste au même étage (`application/admin/shared`) | un état de plus dans le module (un compteur, remis à zéro à chaque effacement) ; un `load()` lancé pendant le chargement (« Réessayer » sur une autre liste, la relecture qui suit un enregistrement) le prolonge — accepté, écrit dans le docblock |
| (B) `BackofficeHttpClient` compte les **lectures** en vol par la réaction (`stampAtSend('read')`, plus une méthode `settled(sentAt)` à ajouter à `BackofficeSessionReaction`) | au plus près du réseau ; voit une lecture qui ne passerait pas par `useAdminLoad` | l'interface du domaine change (trois couches) ; une lecture n'est pas un chargement (un chargement à deux lectures successives retomberait à zéro entre elles) ; aucune lecture de `src/` ne contourne `useAdminLoad` aujourd'hui |
| (C) chaque page dit la fin de son chargement (`await` de ses `load()`, puis un `endLandingLoad()`) | la sémantique exacte « le chargement de la page » | douze pages à câbler ; une page qui l'oublie garde l'avis ouvert jusqu'à la navigation suivante — il faudrait une garde de source de plus |
| (D) une fenêtre de temps ou un tick après le montage | rien à câbler | non déterministe, faux sur un réseau lent |

**Décision 1 — le texte affiché à la fin du chargement.** Lue au pied de la lettre (`earlierSave` à `'none'` à la fin
du chargement), la décision ferait basculer le texte de l'alerte déjà affichée vers `common.accessRefused` **sans
nouveau refus** (le texte est un `computed` de l'état) : l'alerte dirait « accès refusé » d'un enregistrement qu'elle
annonçait l'instant d'avant, sans réannonce (la `key` est le compteur). Le plan distingue donc la **fenêtre** (fermée
à la fin du chargement) du **texte** (gardé jusqu'au refus suivant, qui ramène `common.accessRefused`). Q1 (b)
demande à l'auteur de le confirmer.

**CR9-7 — comment lire une expression de gabarit.** (A) **chaque expression rendue comme un fragment JavaScript que
TypeScript lit comme Vue l'entend** (un gestionnaire `v-on` en corps de fonction, un paramètre de `v-slot` ou un alias
de `v-for` en paramètre de fonction, le reste entre parenthèses), une garde vérifiant qu'aucun fragment de `src/` n'a
d'erreur de syntaxe — **retenue** : un littéral objet n'est plus un bloc étiqueté, un `v-slot` déstructuré n'est plus
lu par chance, et la garde rougit le jour où une forme nouvelle n'est pas un fragment valide. (B) Garder le texte brut :
rien à écrire, mais « par chance » reste vrai.

**St9-14 — le type des nœuds du gabarit.** (A) Déclarer `@vue/compiler-core` en `devDependencies` et importer ses types
et `NodeTypes` : typé de bout en bout, mais un second paquet à garder au pas de `vue` (une version divergente
dupliquerait le compilateur dans l'arbre). (B) **Dériver la racine de `vue/compiler-sfc`
(`NonNullable<SFCTemplateBlock['ast']>`) et ses enfants de ce type, rétrécis par propriété (`'props' in node`,
`'exp' in prop`)** — **retenue** : aucun paquet de plus, aucun `as unknown as`, aucun nombre magique.

**St9-3 — la forme d'une exemption.** (A) **`array{reason: string, sites: list<AuthorizationSiteKind>}`, chaque site
couvert écrit une fois** (`[Refusal, Refusal]`) — **retenue** : ni tuple lu par position, ni `expand()` ; un site de
plus ou de moins se lit dans la liste. (B) un objet `AuthorizationExemption` : impossible dans une constante de classe
(pas de `new` dans une constante), il faudrait une méthode statique.

### Choix de conception tranchés

1. **Décision 1, option (A) (R102).** `backofficeAccessRefusal.ts` :
   `type EarlierSave = 'none' | 'landing' | 'landed'` (remplace `'atMount' | 'keptByARead'`) — `'landing'` : le montage a
   réglé une écriture refusée du compte courant **et** le chargement de la page d'arrivée est en vol ; `'landed'` : le
   chargement est terminé (réussi ou refusé), le texte reste celui de l'avis jusqu'au refus suivant ; `'none'` : le
   texte courant. Un compteur de module `let loadsInFlight = 0` (pas de `ref` : rien ne l'affiche).
   `export function beginBackofficeLoad(): () => void` — incrémente `loadsInFlight`, relève le périmètre courant et rend
   la fin, **idempotente** et **sans effet si le périmètre a changé depuis** ; la fin qui ramène `loadsInFlight` à 0
   passe `'landing'` à `'landed'` (par `transition`, compteur de refus inchangé : aucune réannonce).
   Transitions : `settle…(compte)` — une écriture comptée de ce compte → `earlierSave` = `loadsInFlight > 0 ? 'landing'
   : 'landed'`, sinon `NO_REFUSAL` ; une lecture comptée — `'landing'` reste `'landing'`, `'landed'` → `'none'`,
   `'none'` reste `'none'` ; une écriture comptée → `'none'` (inchangé) ; `clearBackofficeAccessRefusal()` et
   `resetBackofficeAccessRefusalForTests()` remettent aussi `loadsInFlight` à 0. `earlierSaveRefused` reste
   `'none' !== earlierSave`. `useAdminLoad.load()` : `const endLoad = beginBackofficeLoad()` en première ligne,
   `endLoad()` en tête du `finally` (après le `catch` : le refus est déjà compté), pour toute réponse, périmée comprise.
2. **Décision 2 (R103).** `if (isProposalShown.value) { proposal.value = null }` dans le `watch` de `settings` ; le
   commentaire dit, au présent, que des réglages arrivés pour une autre langue que la cible laissent la proposition
   (retirée au retour sur la cible, qui relit) ; le test lit `proposal` par `wrapper.findComponent(AdminAboutSettingsForm).vm`
   (Q2).
3. **C9-2 (R104).** `if (lock.isProposalLocked) { return }` en tête de chaque `handleTranslate` des huit formulaires
   (`principleLock` / `traitLock` dans `AdminQualityPage.vue`), avec le commentaire du motif de
   `useAdminAboutSettings.save` (« le refus vient d'ici, pas seulement du bouton désactivé »).
4. **St9-9, St9-10 (R105).** Identifiants de texte `admin-<contenu>-entry-lock-help` (remplacent
   `admin-<contenu>-locale-locked-help`) ; clés `admin.entryLock.editing` et `admin.entryLock.proposal` (remplacent
   `admin.localeLockedEditing` et `admin.lockedByProposal`, textes FR et EN inchangés) : nommées par l'état du verrou,
   comme `EntryFormLockState`. `useEntryFormLock(options: { readonly hintId: string; readonly isEditing:
   MaybeRefOrGetter<boolean>; readonly proposalSource: MaybeRefOrGetter<Locale | null>; readonly localeHelpId?: string })`
   (`toValue`, `vue`) : chaque formulaire passe ses refs (`isEditing`, `proposalSourceLocale`).
5. **CR9-8, C9-6, St9-16, C9-4, C9-5 (R106).** `audit-prod.sh` : après `smoke_write_auth_config AUDIT_BASIC_AUTH || exit 2`,
   un `SMOKE_AUTH_CONFIG` vide → « AUDIT_BASIC_AUTH : secret de Basic Auth vide, rien de vérifié » sur stderr, sortie 2 —
   jugé sur le chemin, jamais sur la valeur (`set -x` n'imprime rien d'elle) ; puis `CURL_OPTS+=(-K "$SMOKE_AUTH_CONFIG")`
   sans condition, la raison de St9-16 écrite à côté (fichier écrit une fois avant tout appel, jamais réécrit,
   `smoke_fetch` non utilisé par l'audit). `smoke_write_auth_config` refuse aussi tout nom commençant par
   `SMOKE_AUTH_` (les variables que son `smoke_cleanup` de tête remet à vide), retour 2, « nom de variable invalide ».
   `smoke_fetch` : `SMOKE_AUTH_CONFIG` posé mais fichier absent → « <libellé> : fichier de la Basic Auth absent — rien
   de vérifié », retour 2, curl non appelé ; les trois appelants `|| exit "$?"`.
6. **C9-1 (R107).** La raison de `/api/logout` dans `RULELESS_OUTSIDE_PUBLIC_PATHS` dit que le double-submit l'y garde
   de la déconnexion forcée, et le nomme jamais exclu ; témoin `AuthenticationFlowTest::testAForcedLogoutWithoutTheCsrfHeaderExpiresNoCookie`.
   `private static function isApiPath(string $path): bool` (`'/api' === $path || str_starts_with($path, '/api/')`),
   seule lecture de « relève de `/api` » dans `ApiRouteExposureTest`.
7. **St9-6 (R108).** `testNoOperationCarriesASecurityExpression` appelle
   `collectClassesToRead(ResourceNameCollectionFactoryInterface $names, ResourceMetadataCollectionFactoryInterface $metadata, array &$found): array{classes: array<class-string, true>, dtoClasses: array<class-string, true>, operations: int, links: int}`
   puis
   `readProperties(array $classes, PropertyNameCollectionFactoryInterface $propertyNames, PropertyMetadataFactoryInterface $propertyMetadata, array &$found): array{properties: int, nestedClasses: array<class-string, true>}`,
   et garde les six assertions ; formes à clés nommées, jamais un tuple.
8. **CR9-2, CR9-3, St9-3 (R109).** `AuthorizationSiteKind` devient une énumération **pure** (sans `: string` ni valeurs) :
   les messages d'échec et le décompte des exemptions nomment le cas (`->name`), un seul vocabulaire. Ses `case`
   portent ce que le docblock d'`AuthorizationSites` leur délègue (texte au Step 2 de R109). `EXEMPTIONS` : option (A)
   ci-dessus ; `countByKind(list<AuthorizationSiteKind>): array<string, int>` (par `->name`, trié) ; `expand()` retiré.
9. **CR9-7, CR9-6, St9-14 (R110).** Option (A) de CR9-7, option (B) de St9-14 ; `export function
   expressionsOfTemplate(root: TemplateRoot): string[]` (`type TemplateRoot = NonNullable<SFCTemplateBlock['ast']>`,
   exporté), appelée par `templateExpressionsOf` ; fragments : directive `on` avec expression → `` `() => {\n${exp}\n}` `` ;
   directive `slot` avec expression → `` `(${exp}) => 0` `` ; directive `for` → `` `(${source})` `` puis, pour chacun de
   `value`, `key`, `index` présents, `` `(${alias}) => 0` `` (lus dans `forParseResult`) ; toute autre expression de
   directive, argument dynamique non statique, interpolation → `` `(${exp})` `` ; un `content` `null` ou absent n'est
   jamais visité.

**Questions à l'auteur** (le plan suit la recommandation ; une autre réponse ne change que la tâche citée). **Réponses de
l'auteur (2026-10-31) : Q1 (a) (A), Q1 (b) le texte reste, Q2 l'accepter — les trois recommandations, le plan ne change pas.**

- **Q1 (R102)** : (a) **qui dit la fin du chargement** — options (A) à (D) ci-dessus ; **recommandé : (A)**,
  `useAdminLoad` déclare ses chargements (un endroit, aucune page à câbler, ordre sûr). (B) changerait l'interface
  `BackofficeSessionReaction` et `BackofficeHttpClient` ; (C) les douze pages et une garde de source de plus.
  (b) **le texte à la fin du chargement** : **recommandé : il reste celui de l'avis jusqu'au refus suivant**, qui ramène
  `common.accessRefused` (sinon le texte d'une alerte déjà affichée change sans refus ni réannonce). La lecture
  littérale (texte rendu à `common.accessRefused` dès la fin du chargement) ne change que le Step 1 et la transition
  de fin de R102.
- **Q2 (R103)** : l'effet de la condition rétablie **ne se voit pas dans le DOM** (le retour sur la langue cible la relit
  et retire la proposition, l'avis n'est rendu que sous elle : St8-14, mutant équivalent). Le test de la décision 2 lit
  donc l'état du composant (`proposal`, par `wrapper.vm`, que Vue Test Utils rend pour un `<script setup>`).
  **Recommandé : l'accepter** (la décision le demande, et c'est la seule observation possible). Autre option : garder
  la condition sans test, en écrivant pourquoi dans le commentaire du `watch`.

### Review Focus (neuvième relecture)

1. **Une page à deux listes (Qualité, À propos) dont les deux lectures du chargement sont refusées, après un
   enregistrement refusé pendant l'absence** : l'avis « enregistrement précédent » est dit aux deux annonces. Test : R102.
2. **Le chargement réussi, puis « Réessayer » ou une relecture refusée** (rôle perdu) : `common.accessRefused`. Test : R102.
3. **`AUDIT_BASIC_AUTH=''` (secret de préprod manquant)** : sortie 2, « secret de Basic Auth vide », aucun appel,
   aucun dossier ; sous `bash -x`, rien de la valeur. Non définie : l'audit de prod inchangé. Test : R106.
4. **Une proposition vers l'EN, passage au FR, enregistrement du FR** : la proposition de l'EN reste. Test : R103.
5. **Un `translate` émis pendant une proposition** (le bouton désactivé n'est pas la seule garde) : aucune requête de
   traduction, le formulaire garde la proposition. Test : R104.

### Contraintes propres aux tâches R102 à R111

- Les **Global Constraints** et toutes les contraintes des séries précédentes valent. Exécution : **un agent par tâche,
  successifs dans l'ordre R102 → R111**, qui lit d'abord `tasks/relectures-748/brief-commun-agents.md`. **Pour R102 et
  suivantes, la section à lire en entier jusqu'à la première tâche est celle-ci** (« Corrections de la neuvième
  relecture ») et le fichier de constats `tasks/relectures-748/relecture-9-constats.md` : le brief nomme encore la
  huitième série, le fil principal le met à jour avant de lancer R102.
  `superpowers:test-driven-development` : test écrit d'abord, **vu rouge pour la bonne raison** ; un test de couverture
  vert d'emblée est vu rouge par la mutation que sa tâche nomme, **restaurée depuis une copie du fichier faite avant la
  mutation et vérifiée par `cmp`** (`cp f "$scratch/f.orig"`, muter, mesurer, `cp "$scratch/f.orig" f`,
  `cmp f "$scratch/f.orig"`) puis `git diff` vide sur le fichier muté. **Une mutation s'écrit en place par Python
  seulement** (`python3 -I` ; `open(p, 'r+')` ou `open(p, 'w')` sur le même chemin, qui garde l'inode), **jamais par
  l'outil Edit, `sed -i` ni `perl -pi`** : tous trois remplacent le fichier par un nouvel inode, que le montage du
  conteneur peut ne pas voir — la mutation ne serait pas mesurée (R99). *Ceci rectifie la contrainte de la huitième
  série, qui prescrivait l'outil Edit (Sp9-7) ; le brief commun dit déjà juste.* Après une écriture du code par Edit ou
  Write (pas une mutation), `docker compose restart frontend` (ou `backend`) avant de croire un test, en cas de doute.
  Refactoring pur : la suite existante comme filet, nombre de tests noté avant et après.
  **`superpowers:verification-before-completion` invoquée réellement** avant le rendu.
- **Git : seuls `git add <fichiers>` et `git commit`** (et `git mv` / `git rm` pour un déplacement ou une
  suppression). Ni `reset` (pas même sans argument), ni `add -N`, ni `checkout`, ni `checkout-index`, ni `switch`, ni
  `restore`, ni `stash`. **Un commit par tâche**, fichiers nommés, message en français terminé par les lignes
  d'attribution que donne le brief.
- **Une docstring ou une règle qui décrit un comportement modifié se corrige dans le même commit** ; une phrase de règle
  se **remplace**, elle ne s'ajoute pas à côté ; un commentaire s'écrit **au présent** (St9-15 : ni « depuis R… », ni
  « celle qui était là » — l'historique vit dans le plan et dans `git log`).
- **Aucun travail en arrière-plan au rendu** ; **chaque code de sortie se lit sans pipe** (`make front-test; echo
  "exit=$?"`, de même `front-lint`, `front-build`, `back-test`, `back-quality`, chaque test hors ligne et
  `tools/check-claude-rules.sh`).
- Frontend (R102 à R105, R110) : lire avec `Read` `.claude/rules/frontend.md`, `.claude/rules/frontend-a11y.md` et la
  règle de la zone (`frontend-session-refus.md`, `frontend-backoffice.md`, `frontend-session.md`) ; test ciblé
  `docker compose exec -T frontend npm test -- <chemins>`, puis `make front-test`, `make front-lint`, `make front-build` ;
  toute chaîne nouvelle ou clé renommée en FR et EN ; lignes touchées ≤ 140 caractères (mesurées par `python3 -I`).
- Backend (R107 à R109) : lire avec `Read` un fichier de `backend/tests/Security/` et `.claude/rules/backoffice-api.md`
  avant d'écrire ; test ciblé `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis `make back-test` et
  `make back-quality` ; `rm -rf var/cache/test` après chaque mutation de configuration ou de service ;
  `make back-style-fix` après un import ajouté ; lignes touchées ≤ 140 caractères ; jamais de secret littéral.
- Outillage (R106) : lire avec `Read` les scripts touchés et `.claude/rules/deploiement-nginx.md` ;
  `bash tools/tests/<script>.test.sh; echo "exit=$?"` pour chaque script touché ; shellcheck dans l'image épinglée du job
  `tools-tests` : `docker run --rm --entrypoint shellcheck -v "$PWD":/repo:ro -w /repo
  rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -x --severity=warning
  <fichiers>` (si l'image n'expose pas `shellcheck`, le noter et lancer celui de l'hôte, en le disant). Une valeur de
  test ne contient jamais le mot « secret » (un message attendu, si).
- Règles : `tools/check-claude-rules.sh` après chaque modification ; 50 000 octets par règle, taille de chaque règle
  touchée notée au rendu. Tailles au départ (HEAD 7e3e39b3) : `backoffice-api.md` **39 834** (aucune tâche n'y touche),
  `security-user.md` 47 914 (non touchée), `frontend-backoffice.md` 28 662, `frontend-session-refus.md` 13 575,
  `frontend-session.md` 15 682, `security-authentication.md` 9 347, `security-authentication-sessions.md` 22 487,
  `deploiement-nginx.md` 28 687.
- Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets (les exclure de tout
  `grep -r`) ; ne jamais nommer un compte réel. Pas de push, pas de PR.

### Fichiers (tâches R102 à R111)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/application/admin/shared/useAdminLoad.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue` (docblock), `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts` (docblock de `refused`) ; specs `backofficeAccessRefusal.spec.ts`, `useAdminLoad.spec.ts`, `AccessRefusedNotice.spec.ts` | l'avis lié au chargement | R102 |
| `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue`, `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts` | la condition du `watch` | R103 |
| les sept fichiers de formulaires par entrée, `frontend/tests/support/entryFormLock.ts`, les six specs de formulaires | `handleTranslate` se refuse lui-même | R104 |
| `frontend/src/presentation/ui/admin/useEntryFormLock.ts`, `EntryFormLockHint.vue`, les sept fichiers de formulaires, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`, `frontend/tests/support/entryFormLock.ts`, `frontend/tests/presentation/ui/admin/useEntryFormLock.spec.ts`, les six specs | noms, interface, textes du verrou | R105 |
| `tools/lib/smoke-http.sh`, `tools/{audit-prod,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp}.sh`, `tools/tests/{smoke-http,audit-prod}.test.sh` | Basic Auth vide, fichier absent, noms refusés | R106 |
| `backend/tests/Security/ApiRouteExposureTest.php`, `backend/tests/Security/Authentication/AuthenticationFlowTest.php`, `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php` | `/api/logout`, « relève de `/api` », prémisse | R107 |
| `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php` | extraction, génériques | R108 |
| `backend/tests/Support/AuthorizationSiteKind.php`, `backend/tests/Security/AuthorizationLivesInAccessControlTest.php` | un vocabulaire, exemptions | R109 |
| `frontend/tests/support/sourceText.ts`, `sourceText.spec.ts`, `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts` | lecteur de gabarit, gardes | R110 |
| `.claude/rules/*` | dans le commit du comportement | R102 à R110 ; balayage R111 |

---

### Task R102 : Le refus du backoffice — l'avis de l'enregistrement précédent vaut pour tout le chargement de la page d'arrivée (décision 1 : CR9-1 ≈ Sp9-9 ; Sp9-4, Sp9-5, Sp9-6 pour le frontend)

**Files :**
- Modify : `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/application/admin/shared/useAdminLoad.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue` (docblock et commentaire de l'`onMounted`), `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts` (docblock de `refused`, Sp9-4)
- Test : `frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts`, `frontend/tests/application/admin/shared/useAdminLoad.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-refus.md` (l. 83-85 et la puce « Tests », l. 116)

**Interfaces :**
- Produces : `export function beginBackofficeLoad(): () => void` (choix tranché 1) ; `EarlierSave` `'none' | 'landing' |
  'landed'` ; `earlierSaveRefused` inchangé (booléen). Consumes : rien d'une autre tâche.
- Docblocks du module, de `RefusalState`, de `markBackofficeAccessRefused`, de `settle…`, d'`useAdminLoad` (une ligne :
  chaque `load()` se déclare au refus du backoffice, pour borner l'avis au chargement de la page d'arrivée) et
  d'`AccessRefusedNotice` (puce du montage) : la règle de la décision 1, au présent, dont la limite écrite « un `load()`
  lancé pendant le chargement le prolonge ».
- Sp9-4 : le docblock de `refused` se termine par la phrase de renvoi identique du choix 12 de R100 : « La règle de
  l'identité du compte et ses limites sont écrites une fois, dans `.claude/rules/frontend-session-refus.md`
  (sous-puce « the account »). »

- [ ] **Step 1 : tests, rouges** — `backofficeAccessRefusal.spec.ts` (une écriture refusée du compte `alice` comptée
  hors alerte, puis les chargements, puis `settle…('alice')`) :
  - `it("pendant le chargement de la page d'arrivée, chaque lecture refusée garde l'avis (Sp9-9)")` : deux
    `beginBackofficeLoad()`, `settle`, deux lectures refusées → `earlierSaveRefused` vrai après chacune (expected
    aujourd'hui : faux après la seconde) ;
  - `it("le chargement terminé, la lecture refusée suivante ramène le texte courant (CR9-1)")` : un chargement, `settle`,
    sa fin → `earlierSaveRefused` **encore vrai** (le texte ne change pas sans refus, Q1 b) ; une lecture refusée →
    faux (expected aujourd'hui : vrai) ;
  - `it("un chargement refusé avant le montage est terminé au règlement : la lecture refusée suivante ramène le texte courant")` :
    un chargement, sa lecture refusée et sa fin **avant** `settle` → avis dit ; une lecture refusée → faux ;
  - `it("la fin d'un chargement d'un périmètre effacé ne ferme pas le chargement suivant")` ;
  - `it("un chargement lancé après la fin du chargement de la page d'arrivée ne rouvre pas l'avis")` ;
  - `it("la fin d'un chargement est idempotente")` (deux chargements, la fin du premier appelée deux fois : l'avis tient
    une lecture refusée de plus) ;
  - les tests qui nomment `'keptByARead'` ou « la première lecture » changent exprès.
  `useAdminLoad.spec.ts` : `it("chaque load() se déclare au refus du backoffice et dit sa fin, réussie, refusée ou périmée")`
  (une lecture différée : avant sa réponse l'avis tient une lecture refusée, après il ne la tient plus).
  `AccessRefusedNotice.spec.ts` (Review Focus 1 et 2) : `it("l'avis tient toutes les lectures du chargement, et part au refus qui le suit")`
  — deux lectures refusées du chargement : le texte FR exact de `common.earlierSaveRefused` aux deux annonces (le
  `<span>` réannoncé) ; puis le chargement fini, une lecture refusée : le texte FR exact de `common.accessRefused`.
  **Sp9-5** : chaque texte attendu de ce spec est comparé par `toBe` au texte FR exact, jamais par `toContain`.
  Run : `docker compose exec -T frontend npm test -- tests/application/admin/shared/backofficeAccessRefusal.spec.ts tests/application/admin/shared/useAdminLoad.spec.ts tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`.
  Expected : FAIL, `beginBackofficeLoad` introuvable ; puis, une fois exportée vide, les cas Sp9-9 et CR9-1 sur leurs
  valeurs attendues.
- [ ] **Step 2 : implémenter** selon le choix tranché 1 ; chaque transition par `transition()` ; lignes du module
  ≤ 140 caractères (Sp9-6 : l. 140 et 143 d'aujourd'hui).
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : dans `useAdminLoad.ts`, `endLoad()` déplacé
  du `finally` au seul chemin de réussite → le cas « refusé » de `useAdminLoad.spec.ts` et le cas Sp9-9
  d'`AccessRefusedNotice.spec.ts` rougissent ; restauré.
- [ ] **Step 4 : règle** — `frontend-session-refus.md` : « the **first** read refused after the mount keeps that text and
  re-announces it (the landing page's own load, CR7-4); any later refusal, read or write, brings back
  `common.accessRefused` (R95, decision 4 of the eighth review). » → « every read refused **during the landing page's
  load** keeps that text and re-announces it — the load is the `useAdminLoad` reads in flight at the mount, each
  `load()` declaring itself (`beginBackofficeLoad`), and it ends when they have all answered, served or refused (a
  `load()` started meanwhile extends it); once it has ended the text stays until the next refusal, which, read or
  write, brings back `common.accessRefused` (R102, decision 1 of the ninth review, amending decision 4 of the
  eighth). » ; puce « Tests » : « the first read refused after the mount keeps the text and the next brings back the
  current one, R76, R95 » → « every read refused during the landing load keeps the text, the first refusal after it
  brings back the current one, R76, R102 ». Taille notée.
- [ ] **Step 5 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "atMount\|keptByARead\|première lecture" frontend/src frontend/tests .claude` : vide ou historique daté.
- [ ] **Step 6 : commit** — `fix(frontend): l'avis de l'enregistrement précédent vaut pour tout le chargement de la page d'arrivée et retombe à sa fin (#748)`.

---

### Task R103 : Réglages « À propos » — des réglages d'une autre langue que la cible laissent la proposition (décision 2 : CR9-9 ; St9-15 pour ce commentaire)

**Files :**
- Modify : `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue` (le `watch` de `settings` et son commentaire)
- Test : `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« **A proposal notice is its target locale's** … goes whenever that locale's form is re-read from the server »)

**Interfaces :**
- Choix tranché 2. Le commentaire du `watch`, au présent : des réglages qui arrivent sont ceux de la langue montrée ;
  si c'est la cible de la proposition, ils la remplacent et la retirent ; sinon la proposition reste, et le retour sur
  sa cible la relit (décision 4 de la sixième série). Plus de « celle qui était là » (St9-15).

- [ ] **Step 1 : test, rouge** — `it("une proposition en EN, passage au FR puis enregistrement du FR : la proposition de l'EN reste (#748, R103, décision 2)")` :
  les étapes du test « une proposition en EN, enregistrement réussi du FR » (même dépôt de réglages et de traduction),
  puis `expect((wrapper.findComponent(AdminAboutSettingsForm).vm as unknown as { proposal: unknown }).proposal).toEqual({ source: 'fr', target: 'en' })`
  (Review Focus 4 ; l'avis reste absent sous le FR, assertion gardée). Run :
  `docker compose exec -T frontend npm test -- tests/presentation/pages/admin/AdminAboutPage.spec.ts`. Expected : FAIL,
  `proposal` vaut `null`. Si Vue Test Utils ne rend pas l'état du `<script setup>` par `vm` : s'arrêter et rendre Q2.
- [ ] **Step 2 : implémenter** selon le choix tranché 2.
- [ ] **Step 3 : règle** — « and goes whenever that locale's form is re-read from the server — the response of its
  successful save, a reload, a return to it —, which loses the proposal » → « and goes whenever that locale's form is
  re-read from the server — the response of its successful save, a reload, a return to it —, which loses the
  proposal; settings of the other locale arriving leave it (R103, decision 2 of the ninth review) ». Taille notée.
- [ ] **Step 4 : verts** — spec ciblé PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `fix(frontend): réglages « À propos », des réglages d'une autre langue que la cible laissent la proposition (#748)`.

---

### Task R104 : Formulaires par entrée — `handleTranslate` se refuse lui-même pendant une proposition (C9-2)

**Files :**
- Modify : `frontend/src/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,AdminAnonymousCvPage,AdminQualityPage,AdminAboutMeCardsSection,AdminAboutSiteCardsSection}.vue`
- Test : `frontend/tests/support/entryFormLock.ts`, les six specs qui déclarent un `EntryFormLockCase`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant », la phrase « and so are « Proposer la version XX » (R93, … ) »)

**Interfaces :**
- Choix tranché 3. `EntryFormLockCase` gagne `readonly translationRequests: () => number` (le nombre d'appels au dépôt
  de traduction du spec, remis à zéro par son `beforeEach`).

- [ ] **Step 1 : test, rouge** — dans `describeEntryFormLock`, pour les huit formulaires :
  `it("pendant une proposition, un « translate » émis ne lance aucune traduction")` (Review Focus 5) : `startEdit`,
  `propose`, `n = translationRequests()`, puis `wrapper.findAll('form')[form].findComponent(TranslateEntryButton).vm.$emit('translate', 'fr')`
  (la langue source, celle qui ramènerait le formulaire en arrière), `flushPromises()` → `translationRequests()` vaut
  `n`, le titre du formulaire et l'avis `role="status"` restent ceux de la proposition. Run :
  `docker compose exec -T frontend npm test -- tests/presentation/pages/admin`. Expected : FAIL sur les huit cas, un
  appel de plus au dépôt.
- [ ] **Step 2 : implémenter** selon le choix tranché 3 ; `docker compose restart frontend` en cas de doute.
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : la garde retirée de
  `AdminAboutSiteCardsSection.vue` → seul le cas « carte du site » rougit ; restauré.
- [ ] **Step 4 : règle** — dans la phrase « and so are « Proposer la version XX » (R93, decision 1 of the eighth review: a
  second translation took the form back to the source locale, group kept) » → « … group kept; `handleTranslate`
  refuses itself as well, not only through the disabled button, R104) ». Taille notée.
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"` (filet + 8), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(frontend): pendant une proposition, la traduction d'un formulaire par entrée se refuse elle-même, pas seulement par son bouton (#748)`.

---

### Task R105 : Formulaires par entrée — le verrou nommé par son état, une interface qui prend les refs, des textes sans compteur périmé (St9-8 = Sp9-2 = Sp9-3, St9-9, St9-10 ; refactoring)

**Files :**
- Modify : `frontend/src/presentation/ui/admin/useEntryFormLock.ts`, `frontend/src/presentation/ui/admin/EntryFormLockHint.vue`, les sept fichiers de formulaires, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`
- Test : `frontend/tests/support/entryFormLock.ts`, `frontend/tests/presentation/ui/admin/useEntryFormLock.spec.ts` (interface : changement exprès), les six specs de formulaires (identifiants `ids.hint`, `translateButton` local)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (la phrase « all described by one text, `admin.lockedByProposal`, … » et toute mention des clés ou identifiants renommés)

**Interfaces :**
- Consumes : la garde de R104 (`lock.isProposalLocked`). Produces : choix tranché 4 — `useEntryFormLock({ hintId,
  isEditing, proposalSource, localeHelpId? })`, identifiants `admin-<contenu>-entry-lock-help`, clés
  `admin.entryLock.editing` / `admin.entryLock.proposal`.
- St9-8 : le docblock de `describeEntryFormLock` et les commentaires des six specs nomment « les cas du verrou »,
  **sans nombre** ; `LOCKED_BY_PROPOSAL_TEXT` : « langue, « Version de », bouton de traduction et, sur une carte
  « moi », catégorie » ; le docblock d'`EntryFormLockHint.vue` dit le texte du verrou **du formulaire** : en édition, la
  langue ; pendant une proposition, chaque contrôle qu'elle fige (langue, « Version de », « Proposer la version XX »,
  catégorie d'une carte « moi »), et qu'« Annuler » abandonne la proposition et vide le formulaire.
- St9-10 : les specs lisent `translateEntryButton` du support là où leur `translateButton` local fait la même chose ;
  un local qui vise autre chose (le bouton des réglages « À propos ») reste, sa raison en une ligne.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre de tests noté.
- [ ] **Step 2 : refactorer** selon les interfaces (renommages : `python3 -I` sur la liste des fichiers que `grep -rln`
  donne, puis `docker compose restart frontend`) ; `useEntryFormLock.spec.ts` passe des refs et des getters (les deux
  formes de `MaybeRefOrGetter`).
- [ ] **Step 3 : verts** — `make front-test; echo "exit=$?"` (même nombre), `make front-lint; echo "exit=$?"` (aucune clé
  inutilisée ni manquante), `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "locale-locked-help\|localeLockedEditing\|lockedByProposal\|hasProposal\|quatre cas" frontend/src frontend/tests .claude`
  (`node_modules` exclu) : vide ; taille de la règle notée.
- [ ] **Step 4 : commit** — `refactor(frontend): le verrou des formulaires par entrée nommé par son état, une interface qui prend les refs, des textes sans compteur (#748)`.

---

### Task R106 : Outillage — une Basic Auth vide arrête l'audit, un fichier `-K` disparu se dit, les noms de la bibliothèque refusés (décision 3 : CR9-8 = C9-6 ; St9-16, C9-4, C9-5, St9-18)

**Files :**
- Modify : `tools/audit-prod.sh`, `tools/lib/smoke-http.sh`, `tools/smoke-dead-session-cookie.sh`, `tools/smoke-login-throttling.sh`, `tools/smoke-mcp.sh`
- Test : `tools/tests/audit-prod.test.sh` (le cas « AUDIT_BASIC_AUTH vide » change exprès), `tools/tests/smoke-http.test.sh`
- Modify (règles) : `.claude/rules/deploiement-nginx.md` (l. 117-119, St9-18), `.claude/rules/security-authentication-sessions.md` (« A `curl` that gets no answer exits 1 with a message, a Basic Auth holding a line break is refused (exit 2), and that plumbing — … »)

**Interfaces :**
- Choix tranché 5. Commentaires : le bloc Basic Auth d'`audit-prod.sh` réécrit d'un tenant, au présent (vide → sortie 2 ;
  non définie → l'audit de prod, bibliothèque non sourcée ; `-K` dans `CURL_OPTS` et pourquoi c'est juste ici) ; dans
  les trois scripts de fumée, le commentaire de quatre lignes devient « # Basic Auth : `smoke_write_auth_config` et
  `smoke_fetch` (en-tête de tools/lib/smoke-http.sh). » ; le contrat de `smoke_fetch` dit « rend 1 (aucune réponse) ou
  2 (fichier de la Basic Auth absent) », et ceux des appelants « sortie 1 ou 2 ».

- [ ] **Step 1 : filet** — les cinq tests hors ligne (`smoke-http`, `smoke-dead-session-cookie`,
  `smoke-login-throttling`, `smoke-mcp`, `audit-prod`) à 0, sorties notées.
- [ ] **Step 2 : tests, rouges**
  - `audit-prod.test.sh` (Review Focus 3) : le cas « `AUDIT_BASIC_AUTH=''` » attend désormais sortie 2, « secret de
    Basic Auth vide » sur stderr, **aucun appel** au faux `dig`/`curl`, aucun dossier dans `TMPDIR` (expected
    aujourd'hui : sortie 1, des appels) ; le même sous `bash -x` : rien d'autre de la variable que son nom dans la trace ;
    le cas sans la variable (`sansauth`) inchangé.
  - `smoke-http.test.sh` : « `smoke_write_auth_config SMOKE_AUTH_CONFIG`, puis `SMOKE_AUTH_DIR` : retour 2, « nom de
    variable invalide », rien créé » (expected : retour 0) ; « Basic Auth écrite, fichier retiré, `smoke_fetch` : retour
    2, « fichier de la Basic Auth absent », le faux curl jamais appelé » (expected : curl appelé) ; garde de source :
    `grep -n 'smoke_fetch' tools/*.sh` — aucun appel suivi de `|| exit 1`.
  Run : chaque test hors ligne, `; echo "exit=$?"`.
- [ ] **Step 3 : implémenter** selon les interfaces.
- [ ] **Step 4 : règles** — `deploiement-nginx.md`, l. 117-119 : la parenthèse « (its own `timeout-minutes: 3`; like …
  « introuvable ou invalide » …) » **remplacée** par des phrases courtes, un fait chacune, sans parenthèse imbriquée : la
  bibliothèque partagée et ses fonctions ; `smoke_write_auth_config` (nom validé, refusé s'il commence par `_smoke_` ou
  `SMOKE_AUTH_`, valeur lue sous `set +x`, dossier privé, un second appel remplace le premier) ; `smoke_fetch` lit le
  `-K` à chaque appel et dit un fichier absent (sortie 2) ; `audit-prod.sh` ne source la bibliothèque que si
  `AUDIT_BASIC_AUTH` est définie (`[[ -v ]]`), une valeur vide l'arrête (sortie 2, R106, décision 3 de la neuvième
  série) ; ses sorties (retour à la ligne, bibliothèque invalide : 2 ; curl sans réponse : 1) ; puis la suite de la
  puce (« proves it on the real pod: … ») inchangée. `security-authentication-sessions.md` : « A `curl` that gets no
  answer exits 1 with a message, a Basic Auth holding a line break is refused (exit 2) » → « … refused (exit 2), so is a
  `-K` file gone (exit 2) ». Tailles notées.
- [ ] **Step 5 : verts** — les cinq tests hors ligne à 0 ; shellcheck (image épinglée) sur la bibliothèque, les quatre
  scripts et les deux tests ; `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(tools): une Basic Auth vide arrête l'audit avant tout appel, un fichier -K disparu se dit, les noms des variables de la bibliothèque refusés (#748)`.

---

### Task R107 : Backend — `/api/logout` gardé par le double-submit, dit et prouvé ; « relève de `/api` » écrit une fois ; la prémisse dite juste (C9-1, CR9-5 = Sp9-11, C9-7, Sp9-10, St9-5)

**Files :**
- Modify : `backend/tests/Security/ApiRouteExposureTest.php`, `backend/src/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionListener.php` (docblock, St9-5)
- Test : `backend/tests/Security/Authentication/AuthenticationFlowTest.php`
- Modify (règle) : `.claude/rules/security-authentication.md` (l. 31-45, puce `CsrfCookieRequestSubscriber`)

**Interfaces :**
- Choix tranché 6. Raison de `/api/logout` dans `RULELESS_OUTSIDE_PUBLIC_PATHS` : « « Terminer cet accès » (#65, R35) :
  sans règle par construction, il expire les deux cookies de l'appelant, y compris d'un porteur dont le cookie est
  refusé (2xx depuis R35) ; une règle lui rendrait un 401. Pas public pour autant : il ne sert aucune donnée, et le
  double-submit le garde de la déconnexion forcée — un formulaire tiers part sans `X-XSRF-TOKEN`, et son 403 (priorité
  20, avant le `LogoutListener`) est la seule barrière, `SameSite=Lax` n'empêchant pas le navigateur d'accepter les
  `Set-Cookie` expirés de la réponse. Jamais dans `EXCLUDED_PATHS` (témoin :
  `AuthenticationFlowTest::testAForcedLogoutWithoutTheCsrfHeaderExpiresNoCookie`). » (lignes ≤ 140).
- `isApiPath()` : lu par l'invariant n° 10 et par `allApiRoutePaths()` ; toute autre lecture « commence par `/api` » du
  fichier y passe (`grep -n "'/api'" `).
- C9-7, docblock de l'invariant n° 10 : « « Sûre » est une convention : l'invariant juge les méthodes déclarées, pas
  qu'une route `GET` n'écrit rien ; une écriture servie en `GET` échapperait au double-submit, la relecture tient ce
  point. »
- Sp9-10 : à la l. 325-326, « … l'invariant n° 9 voit qu'une route hors de PUBLIC_PATHS en a une : c'est la prémisse
  « route publique = route sans règle ». Les témoins de cette prémisse … » ; chaque autre occurrence de la phrase de
  renvoi du fichier a son antécédent dans la phrase qui la précède (le vérifier, l. 64-65 comprises).
- St9-5 : « La prémisse — toute autorisation de `/api` est une règle d'`access_control`, et elle ne dépend que du
  chemin. » → « Cette lecture repose sur une prémisse : toute autorisation de `/api` est une règle d'`access_control`,
  et cette règle ne dépend que du chemin. »

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`, nombre de tests noté.
- [ ] **Step 2 : tests** — `ApiRouteExposureTest::testOnlyApiPathsAreJudged` (fournisseur : `/api` vrai, `/api/me` vrai,
  `/apis` faux, `/api-x` faux, `/apix/y` faux ; `isApiPath` lu par réflexion ou rendu `public static` dans le test).
  Expected : FAIL, `isApiPath` introuvable. `AuthenticationFlowTest::testAForcedLogoutWithoutTheCsrfHeaderExpiresNoCookie` :
  connexion comme dans le test du cycle complet, `POST /api/logout` sans `X-XSRF-TOKEN` → 403 et
  `$client->getResponse()->headers->getCookies()` vide, puis `GET /api/me` → 200. Expected : vert (comportement juste) ;
  vu rouge au Step 4.
- [ ] **Step 3 : implémenter** selon les interfaces.
- [ ] **Step 4 : rouge vu par mutation** (Python, copie vérifiée par `cmp`, `rm -rf var/cache/test` après) :
  `'/api/logout'` ajouté à `CsrfCookieRequestSubscriber::EXCLUDED_PATHS` → le témoin rougit (204 et deux cookies
  expirés) ; restauré.
- [ ] **Step 5 : règle** — `security-authentication.md` : la phrase de R89 (« every route of `/api` declares its methods,
  … would leave the double-submit) ») **quitte** la place où elle coupe `EXCLUDED_PATHS` de « Two since #647 » : « Its
  `EXCLUDED_PATHS` are **exact** paths, each justified in the docblock. Two since #647, … » reste d'un tenant jusqu'à
  « See `.claude/rules/mcp-oauth.md`. », puis : « **Every route of `/api` declares its methods**, each `GET`, `HEAD`,
  `OPTIONS` or one of its public `UNSAFE_METHODS` (invariant n° 10 of `ApiRouteExposureTest`, C8-6: a `QUERY` route or a
  custom method would leave the double-submit; « safe » is a convention the review keeps, C9-7). **`/api/logout` stays
  under the guard**: its 403 is the only barrier against a forced logout (C9-1, pinned by `AuthenticationFlowTest`). »
  Taille notée.
- [ ] **Step 6 : verts** — `make back-test; echo "exit=$?"` (filet + 6), `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"` ; lignes touchées ≤ 140.
- [ ] **Step 7 : commit** — `test(security): /api/logout gardé par le double-submit contre la déconnexion forcée, dit et prouvé ; « relève de /api » écrit une fois ; la prémisse dite juste (#748)`.

---

### Task R108 : Backend — la garde des métadonnées compilées en deux étapes nommées, les génériques et les clés de collection suivis (St9-6, CR9-4 = St9-7, Sp9-6)

**Files :**
- Modify : `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`

**Interfaces :**
- Choix tranché 7 (`collectClassesToRead`, `readProperties`). `appClassesIn()` suit aussi
  `GenericType::getVariableTypes()` et `CollectionType::getCollectionKeyType()` ; son docblock et celui de
  `nestedClassesOf()` disent « à toute profondeur » juste (objet, collection — clé et valeur —, générique, nullable,
  union, intersection). Lignes du fichier ≤ 140 caractères (Sp9-6 : l. 158).

- [ ] **Step 1 : filet** — la classe ciblée verte, nombre de cas noté ; `make back-test; echo "exit=$?"`.
- [ ] **Step 2 : tests, rouges** — `propertiesThatNestObjects` gagne `'un générique non collection'`
  (`Type::generic(Type::object(DateTimeImmutable::class), Type::object(self::class))` → `[self::class]`) et `'la clé
  d'une collection'` (`Type::collection(Type::object(SplObjectStorage::class), Type::string(), Type::object(self::class))`
  → `[self::class]` ; si TypeInfo refuse une clé objet, ce cas est retiré, la raison écrite dans le docblock
  d'`appClassesIn()` et au rendu). Run :
  `docker compose exec -u dev backend php bin/phpunit tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`.
  Expected : FAIL, `[]` au lieu de `[self::class]`.
- [ ] **Step 3 : implémenter** `appClassesIn()`, puis extraire `collectClassesToRead()` et `readProperties()` ;
  `make back-style-fix` si un import est ajouté.
- [ ] **Step 4 : verts** — la classe ciblée verte (filet + 2) ; `make back-test; echo "exit=$?"`,
  `make back-quality; echo "exit=$?"` ; longueurs mesurées (`python3 -I`).
- [ ] **Step 5 : commit** — `test(security): la garde des métadonnées compilées en deux étapes nommées, les génériques et les clés de collection suivis (#748)`.

---

### Task R109 : Backend — les sites d'autorisation dans un seul vocabulaire, les exemptions écrites site par site (CR9-2 = St9-2 = C9-3 et St9-1, CR9-3 = St9-4, St9-3 ; refactoring)

**Files :**
- Modify : `backend/tests/Support/AuthorizationSiteKind.php`, `backend/tests/Security/AuthorizationLivesInAccessControlTest.php` ; `backend/tests/Support/AuthorizationSites.php` seulement si son renvoi aux `case` change

**Interfaces :**
- Choix tranché 8. Docblock de l'énumération : « … Les messages d'échec et le décompte des exemptions nomment le cas
  (`->name`) : un seul vocabulaire. » (remplace « Les valeurs sont les libellés … »). `case Refusal` : « Un refus levé
  à la main — `new AccessDeniedException`, `new AccessDeniedHttpException` ou `new UnauthorizedHttpException`, de nom
  court ou complet : il décide à la place d'`access_control`. Attraper, tester ou importer l'exception n'en est pas
  un. » `case DecisionManager` : « Le nom `AccessDecisionManagerInterface`, partout où il est écrit (import, type,
  propriété) : le nom et non l'appel `->decide(`, que le consentement OAuth porte en homonyme. »
- `EXEMPTIONS` : `array<class-string, array{reason: string, sites: list<AuthorizationSiteKind>}>` —
  `NominativeCorpusReader` : `[IsGrantedCall]`, `CsrfCookieRequestSubscriber` : `[Refusal, Refusal]`,
  `LoginCsrfRequestListener` : `[Refusal]` ; son docblock (« Classe => [raison, [[nature, nombre exact de sites]]] »)
  réécrit ; `testEveryExemptionIsStillNeeded` compare `countByKind($sites)` à `countByKind(<sites trouvés>)`.

- [ ] **Step 1 : filet** — la classe ciblée verte, nombre de cas noté ; `make back-test; echo "exit=$?"`.
- [ ] **Step 2 : refactorer** selon les interfaces.
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : un troisième `Refusal` ajouté à l'exemption
  de `CsrfCookieRequestSubscriber` → `testEveryExemptionIsStillNeeded` rougit, attendu et trouvé écrits
  `{"Refusal":3}` / `{"Refusal":2}` (le même nom que les messages de `testNoAuthorizationIsDeclaredOutsideAccessControl`) ;
  restauré.
- [ ] **Step 4 : verts** — la classe ciblée verte, même nombre de cas ; `make back-test; echo "exit=$?"` (même nombre),
  `make back-quality; echo "exit=$?"` ; `grep -n -- "->value\|expand(" backend/tests/Security/AuthorizationLivesInAccessControlTest.php` :
  vide ; lignes ≤ 140.
- [ ] **Step 5 : commit** — `refactor(security): les sites d'autorisation dans un seul vocabulaire, les exemptions écrites site par site, chaque cas dit ce qu'il recense (#748)`.

---

### Task R110 : Le lecteur de gabarit lit chaque expression comme Vue l'entend ; ses types sont ceux du compilateur ; les gardes de source sans recomposition ni repli (CR9-7 = Sp9-1, CR9-6, St9-14 + Sp9-12, St9-12, CR9-10 = St9-13, C9-9, St9-15)

**Files :**
- Modify : `frontend/tests/support/sourceText.ts`, `frontend/tests/support/sourceText.spec.ts`, `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session.md` (l. 158, puce `noOmittedCredentials.spec.ts`)

**Interfaces :**
- Choix tranché 9. `TemplateNode` retiré ; aucun `as unknown as` dans `sourceText.ts`.
- St9-14 : docblock de `stringLiterals` : « chaînes `'…'` et `"…"`, gabarits sans expression, et chaque partie fixe d'un
  gabarit à expression (`a${x}b` donne `a` et `b`), puis les chaînes de ses expressions » ; « Reste vrai : … n'a plus
  de limite écrite » → « Une chaîne non terminée est lue comme TypeScript la lit ; `vue-tsc` refuse ce code (`make
  front-build`), il n'est jamais livré. »
- St9-12 : `noOmittedCredentials.spec.ts` — `function literalsOf(script: string, templateExpressions: readonly string[]): string[]`
  et `function writesOmit(literals: readonly string[]): boolean`, lues par `sources`, par les tests du détecteur
  (`offendsWithOmit(source, path) = writesOmit(literalsOf(scriptOf(path, source), templateExpressionsOf(path, source)))`)
  **et** par le vrai test ; son docblock au présent (St9-15 : « Depuis R51… », « Depuis R96… » réécrits) ; nouveau
  test : chaque fragment de `SRC_TEMPLATE_EXPRESSIONS` se lit sans erreur de syntaxe
  (`ts.transpileModule(fragment, { reportDiagnostics: true }).diagnostics` vide, le fichier et le fragment nommés).
- CR9-10 : les alias `sources` et `file` retirés ; C9-9 : `function sourceOf(path: string): string` lève une erreur qui
  nomme le chemin absent de `SRC_SCRIPTS` ; il remplace le repli `?? ''` (l. 291), et l'assertion `not.toBe('')`
  devenue inutile part avec lui.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté, durées de `noOmittedCredentials.spec.ts` et
  `sessionStateEncapsulation.spec.ts`.
- [ ] **Step 2 : tests, rouges** — `sourceText.spec.ts` :
  `templateExpressionsOf('X.vue', '<template><li v-for="m in [\'omit\']">{{ m }}</li></template>')` : ses `stringLiterals`
  contiennent `'omit'` (expected : non, l'`exp` du `v-for` sautée) ; `<template><C v-slot="{ mode = 'omit' }">x</C></template>` :
  un fragment `({ mode = 'omit' }) => 0` ; `:opts="{ credentials: 'omit' }"` : un fragment `({ credentials: 'omit' })` ;
  `@click="a(); b('omit')"` : un fragment en corps de fonction ; chaque fragment de ces cas sans diagnostic de
  `ts.transpileModule` ; `expressionsOfTemplate({ type: 0, children: [{ type: 5, content: null }] } as unknown as TemplateRoot)`
  rend `[]` sans lever (CR9-6, le transtypage restant dans le test). Run :
  `docker compose exec -T frontend npm test -- tests/support/sourceText.spec.ts tests/infrastructure/http/noOmittedCredentials.spec.ts tests/application/auth/sessionStateEncapsulation.spec.ts`.
  Expected : FAIL, `expressionsOfTemplate` introuvable et le `v-for` non lu.
- [ ] **Step 3 : implémenter** selon les interfaces.
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) la branche `for` retirée → le cas
  `v-for` rouge ; (b) le test `null` du `content` retiré → le cas CR9-6 lève ; restaurés.
- [ ] **Step 5 : règle** — « a `.vue` by its `<script>` blocks and the expressions of its `<template>`, R68, R80, R96 » →
  « a `.vue` by its `<script>` blocks and the expressions of its `<template>` — `v-for` and `v-slot` included, each read
  as the JavaScript fragment Vue compiles, every fragment of `src/` required to parse, R68, R80, R96, R110 ». Taille notée.
- [ ] **Step 6 : verts** — `make front-test; echo "exit=$?"` (durées des deux gardes comparées au filet),
  `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 7 : commit** — `test(frontend): le lecteur de gabarit lit chaque expression comme Vue l'entend, typé par le compilateur ; les gardes de source sans recomposition ni repli (#748)`.

---

### Task R111 : Balayage documentaire de la neuvième série

Les docstrings et les règles ont suivi chaque comportement dans son commit ; cette tâche vérifie qu'il ne reste rien.

**Files :** ceux que le balayage désigne (`.claude/rules/*`, docblocks de `frontend/src`, `frontend/tests`, `backend/src`, `backend/tests`, `tools/`).

- [ ] **Step 1 : balayage** — `grep -rn "atMount\|keptByARead\|première lecture refusée\|locale-locked-help\|localeLockedEditing\|lockedByProposal\|hasProposal\|quatre cas\|libellés lus dans les messages\|n'a plus de limite\|qui était là\|le double-submit n'y protège rien\|str_starts_with(\$path, '/api')\|smoke_fetch .*|| exit 1" .claude docs frontend/src frontend/tests backend/src backend/tests tools`
  (les fichiers de secrets et `node_modules` exclus) : ne rend que l'historique daté ou le sens juste, noté ligne par
  ligne. Puis St9-15 : dans les fichiers que `git diff --name-only c585b960...HEAD -- frontend backend tools` liste, un
  commentaire qui raconte une série (« depuis R… », « n'est plus », « celle qui était ») est réécrit au présent — la
  référence `R…` qui **justifie** une règle reste. Toute phrase fausse trouvée est **remplacée**.
- [ ] **Step 2 : verts** — `tools/check-claude-rules.sh; echo "exit=$?"` ; taille de chaque règle touchée par la série
  notée, toutes < 50 000 octets, `backoffice-api.md` à ses 39 834 octets ; `make front-test; echo "exit=$?"` si un
  fichier de `frontend/` a changé, `make back-test; echo "exit=$?"` si un de `backend/`, les tests hors ligne si un de
  `tools/`.
- [ ] **Step 3 : commit** (seulement si le balayage a changé un fichier) — `docs: le balayage de la neuvième relecture de #748`.
  Sinon, le rendu dit « rien à changer » et les sorties du grep.

### Traçabilité des constats

| Constat | Traitement |
|---|---|
| St9-6 | R108 |
| CR9-1 ≈ Sp9-9 | R102 (décision 1, Q1) |
| C9-1 | R107 |
| CR9-2 = St9-2 = C9-3, St9-1 | R109 |
| CR9-3 = St9-4 | R109 |
| CR9-4 = St9-7 | R108 |
| C9-8 | sans suite (décision 4) |
| CR9-5 = Sp9-11 | R107 |
| CR9-7 = Sp9-1 | R110 |
| CR9-6 | R110 |
| CR9-8 = C9-6 | R106 (décision 3) |
| St9-16 | R106 |
| CR9-9 | R103 (décision 2, Q2) |
| C9-2 | R104 |
| St9-8 = Sp9-2 = Sp9-3 | R105 |
| St9-9 | R105 |
| St9-10 | R105 |
| St9-11 | sans suite (décision 4) |
| St9-12 | R110 |
| St9-14 (+ Sp9-12) | R110 |
| St9-3 | R109 |
| St9-5 | R107 |
| Sp9-4 | R102 |
| Sp9-5 | R102 |
| Sp9-6 | R102 (frontend), R108 (backend) |
| Sp9-7 | contrainte rectifiée dans cette section (commit de ce plan) ; l'édition du brief par R101 est celle du fil principal, gardée |
| CR9-10 = St9-13 | R110 |
| C9-9 | R110 (l'assertion existe déjà ; le repli `?? ''` retiré) |
| C9-4 | R106 |
| C9-5 | R106 |
| C9-7 | R107 |
| Sp9-10 | R107 |
| St9-15 | R103 (« celle qui était là »), R110 (« Depuis R51/R96 »), R111 (le reste) |
| St9-17 | sans suite (décision 4) |
| St9-18 | R106 |
| Sp9-8 | sans suite (décision 4) |

### Constats écartés, avec leur raison

- **St9-17, Sp9-8, C9-8, St9-11** : écartés par l'auteur le 2026-10-31 (décision 4) ; C9-8 n'est pas même écrit comme
  limite.

---


## Corrections de la dixième relecture (2026-10-31)

R102 à R111 sont faites (après 778d114f, jusqu'à 80b5832d ; constats de la dixième relecture : 39498f33). Leurs cases
restent non cochées, par convention des séries : cette phrase dit qu'elles sont faites (Sp10-10).

Quatre relectures du diff 778d114f...80b5832d (`/code-review medium` CR10-*, audit de sécurité C10-*, axe Standards
St10-*, axe Spec Sp10-*) : aucun critique ni haut, trois moyens (C10-1, antérieur à la série ; St10-1 ; St10-2).
Constats complets et décisions : `tasks/relectures-748/relecture-10-constats.md` (la source de vérité). Tous sont
traités ici par R112 à R120, avant les tâches 11 et 12 : corrigés, ou écartés par l'auteur (« Sans suite », repris
ci-dessous). Les points de contrôle au navigateur et le corps de PR de la série sont ajoutés aux tâches 11 et 12 dans
le commit de ce plan. Les sections passées restent l'archive de leur moment.

### Décisions de l'auteur (2026-10-31)

1. **C10-1 : un correctif séparé vers `develop`**, indépendant de #748 : issue #750, branche
   `fix/750-audit-basic-auth-http` (commit 345178b0 : les sondes de redirection en `CURL_OPTS_ANONYMOUS`, sans `-K`, et
   un cas de test « aucun appel `http://` ne porte `-K` »). **Cette série applique la même correction à la version de
   la branche** (`tools/audit-prod.sh` réécrit par R90/R106, où `CURL_OPTS_ANONYMOUS` existe déjà), avec les mêmes cas
   de test et le même commentaire, en citant #750 : le report de `develop` n'aura qu'à aligner deux textes. R115.
2. **C10-1 : pas de rotation** de la Basic Auth de préprod. Risque accepté : seul un acteur placé sur le chemin runner
   GitHub → répartiteur Scaleway a pu lire l'identifiant, lors des audits de release depuis le 2026-09-12. **Aucune
   règle ne l'écrit** : une règle porte ce qui tient pour le code à venir, et après R115 il ne reste rien à garder que
   le cas de test (« aucun appel `http://` ne porte `-K` ») et la phrase de règle de R115 ; le risque passé est dit
   dans le corps de la PR (tâche 12) et dans ce plan.
3. **St10-2 ≈ CR10-3 : époque de montage.** Chaque lecture est estampillée à l'envoi (`BackofficeSendStamp`, qui porte
   déjà le périmètre) ; une lecture envoyée avant le montage de l'alerte (`settleBackofficeAccessRefusalsOnMount`) est
   du chargement de la page d'arrivée et garde l'avis ; toute autre rend `common.accessRefused`. Le compteur
   `loadsInFlight`, `beginBackofficeLoad` et l'import `useAdminLoad` → module disparaissent (règle CR10-1, CR10-2).
   Amende la lettre de la décision 1 de la neuvième (« retombe à la fin du chargement »), pas son intention. Avec le
   test d'intégration layout + vraie page de Sp10-1. R112 (la forme de l'estampille est la question Q1 ci-dessous ; le
   plan suit sa recommandation).
4. **CR10-6 = C10-2 : même règle pour les trois smokes** : variable de Basic Auth définie mais vide → sortie 2
   « secret vide », par une fonction commune de `tools/lib/smoke-http.sh`, un cas de test par script. R116.
5. **Sans suite** : C10-5 (course entre `[ ! -f ]` et curl), Sp10-8 (`translateEntryButton` sur les réglages).
6. **Toujours valables** : C5-1, C5-2, C7, CR6-6, CR7-2, la limite de R94, les « Sans suite » de la neuvième (St9-17,
   Sp9-8, C9-8, St9-11) ; critère d'arrêt **tout jusqu'à zéro** : tous les autres constats se corrigent sans décision.
7. **Arbitrage du fil principal (C10-6 prime sur St10-3)** : `CsrfCookieRequestSubscriber::requiresCsrfCheck()` garde
   **exprès** le préfixe large `str_starts_with($path, '/api')` (le pare-feu `api` est `^/api` non ancré, A23 : une
   route `/apix` y lirait `BEARER`), avec un commentaire et un cas `POST /apix` → 403 ; seuls les **tests** partagent
   un ancrage unique (`isApiPath` d'`ApiRouteExposureTest`, `RouterScopeTest`, `CanonicalPath`). R117.

### Vérification de chaque constat dans le code (HEAD 39498f33)

- **St10-1, confirmé.** `backofficeAccessRefusal.ts:33-34` : « ce module ne tient que « combien de refus depuis le
  dernier effacement » », alors qu'il tient aussi `scope`, `loadsInFlight` (l. 91) et `beginBackofficeLoad` (l. 112).
- **St10-2 ≈ CR10-3, CR10-1 = C10-8, CR10-2, confirmés.** `let loadsInFlight` est remis à zéro à la main à trois
  endroits (l. 164, 197, et décrémenté l. 122) ; `useAdminLoad.ts:6` importe le module de l'alerte. (a) Aucune lecture
  n'a de délai maximal (`BackofficeHttpClient.get`, l. 33-38, un `fetch` nu) : une lecture qui ne répond pas laisse
  `loadsInFlight > 0` jusqu'au prochain effacement, et toute lecture refusée ensuite garde l'avis. (b)
  `useAdminLoad.ts:80` appelle `beginBackofficeLoad()` hors du `try` (l. 94), et `options.clear?.()` (l. 89) entre les
  deux : une exception synchrone de `clear` fuit le compteur. L'estampille existe déjà : `stampAtSend` de
  `createBackofficeSessionReaction` (`backofficeSessionExpiry.ts:44-48`) relève `refusalScope()` pour une lecture,
  synchrone avant l'envoi (`BackofficeHttpClient.get`, l. 35, première ligne). Chaque `load()` atteint `get` sans
  `await` intermédiaire (dépôts `async list() { const response = await this.client.get(…) }`), donc **le `setup` de la
  page estampille ses lectures avant l'`onMounted` de l'alerte** (`AccessRefusedNotice` est rendue avant
  `<RouterView />`, `AdminLayout.vue:164-166` ; tous les `onMounted` partent après tous les `setup` du rendu).
- **Sp10-1 = C10-9, confirmé.** `AccessRefusedNotice.spec.ts:321`, `:345`, `:385` appellent `beginBackofficeLoad()`
  eux-mêmes : aucun spec ne monte le layout avec une vraie page dont les lectures sont refusées ;
  `AdminLayout.sessionEnded.spec.ts` ne connaît que `AdminCaseStudiesPage` (une liste).
- **CR10-4 = C10-10, St10-6, confirmés.** La garde `if (lock.isProposalLocked) { return }` et son commentaire sont
  écrits huit fois dans sept fichiers (`AdminQualityPage.vue` deux fois) ; `useAdminTranslation.translate`
  (`useAdminTranslation.ts:39-57`) ne refuse rien pendant `isTranslating`, et rien n'invalide sa réponse à
  « Annuler ». Le couple `let lockTranslation` / `translationRequests` est recopié dans six specs (huit cas).
- **Sp10-2, confirmé.** `tests/support/entryFormLock.ts:240` émet `'fr'` ; pour la carte « moi », l'entrée éditée est
  en EN et `'fr'` est la cible.
- **St10-8 = Sp10-3, St10-11, confirmés.** `entryFormLock.ts:11`, `:13` (`LOCALE_LOCKED_EDITING_TEXT`,
  `LOCKED_BY_PROPOSAL_TEXT`), « Les cas du verrou de la langue » (`AdminAboutPage.spec.ts:1013`,
  `AdminQualityPage.spec.ts:664`), `const sources` (`noOmittedCredentials.spec.ts:42`) qui contient des littéraux ;
  `useEntryFormLock.ts:76` : `proposalSource: MaybeRefOrGetter<Locale | null>`, lu pour sa nullité (l. 85).
- **CR10-10, confirmé.** `AdminAboutSettingsForm.vue:62` : `isProposalShown` ne lit pas `isLoaded` ; une relecture
  refusée (403) ou interrompue de la cible laisse `settings` nul, le `watch` de la l. 79 ne passe pas, et
  `isProposalShown` est vrai sous un formulaire non rendu.
- **C10-1, confirmé.** `audit-prod.sh:107-111` : les six appels de la boucle « Redirections » prennent `CURL_OPTS`,
  qui porte `-K` (l. 73) ; `CURL_OPTS_ANONYMOUS` (l. 27) n'est lu qu'en section 6ter (l. 320). Le faux `curl`
  d'`audit-prod.test.sh` note « curl <arguments> », l'URL en dernier (l. 30-34) : le cas de #750 s'y lit sans nouveau
  faux binaire.
- **CR10-6 = C10-2, C10-4, confirmés.** `smoke-dead-session-cookie.sh:78`, `smoke-login-throttling.sh:63`,
  `smoke-mcp.sh:92` : `smoke_write_auth_config AUDIT_BASIC_AUTH || exit 2`, rien après (le pipeline passe
  `secrets.PREPROD_BASIC_AUTH` en `AUDIT_BASIC_AUTH`, `pipeline.yml:893-915`). `smoke-http.sh:118-131` : seule une valeur
  à retour à la ligne est refusée ; des blancs, ou une valeur sans `:`, écrivent un fichier.
- **C10-3, St10-15 (outillage), confirmés.** `audit-prod.test.sh:177-187` : le cas `videtraced` cherche
  `AUDIT_BASIC_AUTH=|AUDIT_BASIC_AUTH:-` ; la mutation `[ -z "$SMOKE_AUTH_CONFIG" ]` → `[ -z "$AUDIT_BASIC_AUTH" ]`
  trace `'[' -z '' ']'`, comme le code juste : la trace ne distingue pas les deux. Le bloc réécrit `run` en ligne
  (l. 174-180, et l. 143-150 pour `traced`).
- **St10-13, St10-14 (outillage), confirmés.** La plomberie de `smoke-http.sh` est décrite dans
  `deploiement-nginx.md:117-121` (lignes de 300 à 400 caractères) **et** `security-authentication-sessions.md:188` ;
  `smoke-http.sh` porte une ligne de commentaire d'environ 118 caractères dans un bloc coupé vers 85.
- **CR10-8 = C10-7 = St10-3, C10-6, Sp10-5 (préfixe), confirmés.** `ApiRouteExposureTest.php:815-818` (`isApiPath`),
  `RouterScopeTest.php:80` (même expression), `CanonicalPath::isUnder()` (l. 63-71) ne prend qu'une `Request`.
  `CsrfCookieRequestSubscriber.php:168` : `str_starts_with($path, '/api')`, sans commentaire qui dise pourquoi ;
  `PublicRouteIgnoresRefusedSessionTest.php:187` : la même forme, qui suit le pare-feu `api` (le listener y vit) —
  traitée comme le subscriber, avec un commentaire. `CsrfCookieRequestSubscriberTest` n'a aucun cas `/apix`.
- **CR10-9 = Sp10-11 = St10-10, confirmé.** `AuthorizationLivesInAccessControlTest.php:41` (« liste, un par un, les
  sites ») contre `:57` (« par nature et par fichier, pas site par site ») ; le code apparie par nature
  (`countByKind`) ; `:87` et `:92` (« un par un », « celui-ci n'y figure pas ») disent le contraire du code.
- **CR10-7, confirmé.** `ApiPlatformOperationsCarryNoSecurityTest.php:400-407` : la branche `GenericType` lit les
  variables, puis la branche `CollectionType` relit clé et valeur ; sans elle, le cas « la clé d'une collection » reste
  vert (la `CollectionType` enveloppe un `GenericType`).
- **St10-9, confirmé.** Même fichier, l. 132 et 190 : `@return array{…}` et `array &$found` par référence.
- **St10-4 = Sp10-4, confirmé** (mesuré par `awk 'length>140'`) : `AuthenticationFlowTest.php:106-107` (156, 162),
  `AccessRefusedNotice.spec.ts:23` (214), `audit-prod.test.sh:168` (149), `smoke-http.test.sh:428` (146). D'autres
  lignes longues de ces fichiers sont antérieures à la série : une tâche qui touche le fichier coupe celles qu'elle
  touche, le balayage R120 mesure les fichiers de la série.
- **St10-5 + Sp10-5 (passé), Sp10-6, confirmés.** `audit-prod.test.sh:161` (« Un audit qui courait sans Basic Auth
  lisait le 401… »), `ApiRouteExposureTest.php:345` (« n'a plus le 401 de Lexik »), `AdminAboutPage.spec.ts:736`
  (« Le sélecteur restait ») ; `ApiRouteExposureTest.php:66` : « Les témoins de cette prémisse » suit une phrase qui ne
  nomme aucune prémisse.
- **C10-11, CR10-5 = Sp10-12, St10-7, St10-12, Sp10-9 (repli), confirmés.** `sourceText.ts:144` rend `[]` quand le
  `<template>` n'a pas d'arbre (`lang="pug"`, `src`) ; les attributs statiques (nœud d'attribut, sans `exp`) ne sont
  jamais lus ; l. 181 : tout `v-on` en corps de fonction, sans regarder `directive.arg`, par un ternaire imbriqué ;
  l. 163 : `read(expression: object | null | undefined, …)` ; `expressionsOfTemplate` (l. 160) et
  `templateExpressionsOf` (l. 134) ; `sessionStateEncapsulation.spec.ts:253` : `source === undefined ? [] : …`.
- **C10-12, confirmé.** `AuthenticationFlowTest::testAForcedLogoutWithoutTheCsrfHeaderExpiresNoCookie` couvre « cookie
  présent, en-tête absent » ; « aucun cookie » (le cas intersite réel, `SameSite=Lax`) n'est épinglé nulle part.
- **St10-14 (frontend), St10-15 (noms), confirmés.** `useAuth.ts:17` (« qui n'ont donc qu'un site d'import ») ;
  `AdminAboutSettingsForm.vue:91` (« le test lit l'état ») ; noms de tests portant « (Review Focus 1 et 2) »,
  « (Sp9-9) », « (CR9-1) » (`AccessRefusedNotice.spec.ts:339`, `backofficeAccessRefusal.spec.ts:123`, `:138`).
- **Sp10-9 (le reste), confirmé, sans effet.** Dépôt de traduction par montage (R104), `transition()` inexistant
  (R102), `classes` en `array<string, true>` (R108) : écarts au plan, pas au comportement ; actés ici, rien à corriger.

### Options comparées

**Décision 3 — où l'époque est lue à l'envoi** (Q1, rendue à l'auteur ; le plan suit (A)). Les deux options lisent
l'époque au même endroit, `stampAtSend` de `createBackofficeSessionReaction`, synchrone avant chaque `fetch` ;
`BackofficeHttpClient` ne change pas. Elles diffèrent par ce que porte l'estampille :

| Option | Pour | Contre |
|---|---|---|
| (A) **un second nombre opaque dans l'estampille d'une lecture** : `RefusalScope` devient `{ kind: 'read'; scope; epoch }`, `epoch` avançant à chaque `settle…OnMount` ; une lecture refusée dont `epoch` n'est pas l'époque courante est partie avant le montage — **recommandée** | chaque nombre garde un seul sens : le périmètre dit « quelle page », l'époque « avant ou après le montage de l'alerte » ; la règle « le périmètre n'avance qu'à un effacement » reste vraie ; le jugement tient en une comparaison ; le domaine reste aveugle (deux nombres dont il ignore le sens) | le type du domaine gagne un champ (trois couches le lisent) ; les specs qui écrivent `{ kind: 'read', scope }` à la main changent (six fichiers, par une fonction `readRefusalScope()`) |
| (B) le montage **avance le périmètre** et retient l'ancien comme « périmètre de la page d'arrivée » (`landingScope`) : une lecture compte si son périmètre est le courant **ou** celui de la page d'arrivée, et garde l'avis dans le second cas | l'estampille ne change pas (un seul nombre) ; aucune spec d'estampille ne change | le périmètre prend deux sens (page, et avant/après le montage) ; « le montage n'avance rien » (R64) et « le périmètre n'avance qu'à un effacement » deviennent faux dans le code et dans deux règles ; deux périmètres acceptés à la fois, une acceptation plus subtile à relire |

Écartées : garder une fenêtre de chargement (le compteur de R102, ses deux fuites CR10-1/CR10-2) ; un délai maximal sur
`fetch` (traite CR10-1, pas CR10-2 ni la forme St10-2).

**CR10-4 / St10-6 — où la traduction se refuse.** (A) **`useAdminTranslation` se refuse lui-même** pendant une
traduction en vol et quand la page le lui dit (`isRefused`), et rend `discard()` pour « Annuler » — **retenue** : la
garde et son commentaire écrits une fois, les huit formulaires et les réglages couverts, la page ne garde aucun état de
plus. (B) La garde dans un `guardTranslate(lock, fn)` de `useEntryFormLock` : écrit une fois pour le verrou, mais
l'appel en vol reste à garder ailleurs, et les réglages « À propos », qui n'ont pas de verrou d'entrée, restent sans
garde.

**CR10-8 / St10-3 — où l'ancrage « relève de `/api` » est écrit pour les tests.** (A) **une variante chaîne dans
`CanonicalPath`** (`isPathUnder(string $path, string $prefix)`, que `isUnder()` appelle) — **retenue** : la règle
d'ancrage reste écrite une fois, dans `src/` (#322), et les tests lisent la même ; aucun doublon `tests/Support`. (B) un
`tests/Support/ApiPath::contains()` : rien dans `src/` ne change, mais l'ancrage est alors écrit deux fois (`src/` et
`tests/`), ce que #322 interdit.

**CR10-5 — un `v-on` en corps de fonction ou en expression.** (A) **comme Vue** : sans argument (`v-on="{ … }"`),
une expression ; avec argument, une expression si `(${exp})` se lit sans erreur de syntaxe (gestionnaire, fonction,
chemin de membre, appel unique), sinon un corps de fonction (instructions) — **retenue** : `function () {}` et
l'objet sans argument se lisent, une suite d'instructions aussi, les littéraux lus sont les mêmes. (B) Recopier
l'expression régulière interne de Vue (`fnExpRE`, `isMemberExpression`) : plus fidèle à la lettre, mais une copie à
garder au pas de Vue, que `vue/compiler-sfc` n'exporte pas.

### Choix de conception tranchés

1. **Décision 3, option (A) (R112).** Domaine (`BackofficeSessionReaction.ts`) :
   `export type ReadRefusalScope = { readonly kind: 'read'; readonly scope: number; readonly epoch: number }` et
   `RefusalScope = ReadRefusalScope | { readonly kind: 'write' }` ; docblock : deux nombres opaques que la
   présentation fait avancer, le domaine en ignore le sens. Module `backofficeAccessRefusal.ts` : `let epoch = 0` (pas
   de `ref`) ; `export function readRefusalScope(): ReadRefusalScope` (remplace `refusalScope()`, les deux nombres
   relevés ensemble) ; `RefusalState.earlierSave: boolean` (remplace `'none' | 'landing' | 'landed'`) ;
   `settleBackofficeAccessRefusalsOnMount(compte)` avance d'abord `epoch`, puis règle comme aujourd'hui (une écriture
   comptée de ce compte → `earlierSave: true`, compteur gardé ; sinon `NO_REFUSAL`) ;
   `markBackofficeAccessRefused(sentIn, compte)` : une lecture d'un autre périmètre → `false` (inchangé) ; une lecture
   comptée garde `earlierSave` si `sentIn.epoch !== epoch` (partie avant le montage : la page d'arrivée), le met à
   `false` sinon ; une écriture → `false` (inchangé). `clearBackofficeAccessRefusal()` ne touche pas `epoch` (le
   périmètre isole déjà une page) ; `resetBackofficeAccessRefusalForTests()` le remet à 0. `beginBackofficeLoad`,
   `loadsInFlight`, l'import et l'appel dans `useAdminLoad.ts` et son paragraphe de docblock **disparaissent**.
   `stampAtSend` : `refusal: 'write' === kind ? { kind: 'write' } : readRefusalScope()`. Le texte d'une alerte affichée
   ne change que sur un refus (Q1 b de la neuvième, gardée sans transition de fin).
2. **CR10-10 (R113).** `isProposalShown = computed(() => isLoaded.value && proposal.value?.target === props.locale)`.
3. **CR10-4, St10-6 (R114).** `useAdminTranslation(options?: { readonly isRefused?: () => boolean })` ;
   `translate()` rend `null` **sans requête** si une traduction est en vol (`isTranslating`) ou si `isRefused?.()` est
   vrai — le commentaire « le refus vient d'ici, pas seulement du bouton désactivé » écrit une fois, ici ;
   `discard(): void` périme la traduction en vol (`createStaleRequestGuard`, `application/shared/staleRequestGuard`) :
   sa réponse rend `null`, `isTranslating` retombe à `false`, `errorReason` reste `null`. Les huit formulaires passent
   `{ isRefused: () => lock.isProposalLocked }` (`principleLock`, `traitLock`), retirent leur garde, et leur
   fonction de remise à zéro (`resetForm()`, et `resetPrincipleForm()` / `resetTraitForm()` dans `AdminQualityPage.vue`)
   appelle `discard()`. Support : `EntryFormLockCase` perd `translationRequests` et gagne
   `readonly createTranslation: () => AdminTranslationRepository` ; `mount` devient
   `(translation: AdminTranslationRepository) => Promise<VueWrapper>` ; le support crée le dépôt, le passe et compte
   ses appels.
4. **St10-11 (R114).** L'option `proposalSource` devient `proposalSourceLocale` (le nom des refs de chaque
   formulaire, passées en raccourci), type inchangé ; docblock : seule sa présence est lue. Un booléen ramènerait les
   huit getters `() => null !== …` que R105 a retirés.
5. **C10-1 (R115).** La boucle « Redirections » d'`audit-prod.sh` prend `CURL_OPTS_ANONYMOUS` pour ses six appels,
   précédée du commentaire de 345178b0 mot pour mot ; le commentaire de `CURL_OPTS_ANONYMOUS` nomme ses deux lecteurs
   (section 3, #750 ; section 6ter).
6. **Décision 4, C10-4, C10-3 (R116).** `smoke-http.sh` : `smoke_require_auth_config <NOM>` — nom validé comme par
   `smoke_write_auth_config` (même fonction de validation, extraite) ; si `[[ -v $NOM ]]` et `SMOKE_AUTH_CONFIG` vide :
   « <NOM> : secret de Basic Auth vide, rien de vérifié » sur stderr, retour 2 ; sinon 0. Appelée juste après
   `smoke_write_auth_config <NOM> || exit 2` par les trois smokes **et** par `audit-prod.sh` (qui perd son test en
   ligne, message identique). `smoke_write_auth_config` refuse une valeur non vide sans `:` (blancs compris) :
   « <NOM> : Basic Auth sans « : » (utilisateur:mot_de_passe attendu), refusée », retour 2, aucun dossier, jugé sous
   `set +x`. Garde de source : aucune des quatre lignes de commande (`audit-prod.sh`, trois smokes) ne développe
   `AUDIT_BASIC_AUTH` (`\$\{?AUDIT_BASIC_AUTH`, `!AUDIT_BASIC_AUTH`).
7. **Arbitrage, CR10-8 (R117).** `public static function isPathUnder(string $path, string $prefix): bool` dans
   `CanonicalPath` (même `MalformedPathPrefixException`), `isUnder()` = `self::isPathUnder(self::of($request), $prefix)`.
   `ApiRouteExposureTest::isApiPath()` est retiré, ses lecteurs et `RouterScopeTest.php:80` appellent
   `CanonicalPath::isPathUnder($path, '/api')` ; le fournisseur de `testOnlyApiPathsAreJudged` passe à
   `CanonicalPathTest` (cas chaîne). `CsrfCookieRequestSubscriber::requiresCsrfCheck()` et
   `PublicRouteIgnoresRefusedSessionTest.php:187` gardent le préfixe large, chacun avec un commentaire : il suit le
   pare-feu `api`, `^/api` non ancré (A23), où une route `/apix` lirait `BEARER`.
8. **St10-9, CR10-7 (R118).** `final readonly class CompiledResourcesReading` (`classes`, `dtoClasses`, `operations`,
   `links`, `found: list<string>`) et `final readonly class CompiledPropertiesReading` (`properties`, `nestedClasses`,
   `found: list<string>`), dans `backend/tests/Support/` ; `collectClassesToRead()` et `readProperties()` les rendent,
   sans paramètre par référence ; `appClassesIn()` suit une `CollectionType` par `getWrappedType()`, les lignes
   explicites de clé et de valeur retirées.
9. **C10-11, CR10-5, St10-7, St10-12 (R119).** `expressionsOfTemplate` devient `fragmentsOfTemplateAst(root:
   TemplateRoot): string[]` ; `templateExpressionsOf(path, source)` garde son nom et **lève**
   `Error(`${path} : <template> sans arbre (lang ou src), non lu`)` pour un `<template>` sans `ast` ; un attribut
   statique à valeur donne le fragment `JSON.stringify(valeur)` ; `function wrapperOf(directive: TemplateDirective):
   (text: string) => string` (un `switch` sur `directive.name`, option (A) de CR10-5) remplace le ternaire ;
   `read(expression: TemplateExpression | undefined, wrap)`.

**Question à l'auteur** (le plan suit la recommandation ; une autre réponse ne change que R112). **Réponse de l'auteur
(2026-10-31) : (A), le champ `epoch` — la recommandation, le plan ne change pas.**

- **Q1 (R112, décision 3) : la forme de l'estampille.** (A) un second nombre opaque `epoch` dans l'estampille d'une
  lecture, avancé au montage de l'alerte ; (B) le montage avance le périmètre et retient l'ancien comme périmètre de la
  page d'arrivée. **Recommandé : (A)** — chaque nombre garde un sens, et les deux phrases de règle « le montage n'avance
  rien », « le périmètre n'avance qu'à un effacement » restent vraies ; son coût est un champ de plus dans un type du
  domaine et six specs d'estampille à passer par `readRefusalScope()`. (B) ne changerait que le module, ses deux
  règles et les specs qui vérifient que le montage n'avance pas le périmètre (`AccessRefusedNotice.spec.ts:164-171`,
  `:193-202`).

### Review Focus (dixième relecture)

1. **Une page à deux listes (Qualité) dont les deux lectures du chargement sont refusées, après un enregistrement
   refusé pendant l'absence, puis un principe créé dont la relecture est refusée** : l'avis aux deux premières
   annonces, `common.accessRefused` à la troisième. Test : R112 (intégration).
2. **Une lecture du chargement qui ne répond jamais** (aucun délai sur `fetch`), puis une lecture partie après le
   montage refusée : `common.accessRefused`. Test : R112.
3. **Deux « Proposer la version XX » avant la première réponse** (double activation, `translate` émis par programme) :
   une seule requête de traduction ; « Annuler » pendant la traduction : la réponse qui arrive ne remplit rien. Test :
   R114.
4. **L'audit de préprod (`AUDIT_BASIC_AUTH` défini)** : aucun appel `http://` ne porte `-K`, la sonde `https://www.`
   non plus, chaque appel `https://` de l'apex le porte. Test : R115.
5. **Un smoke de préprod au secret vide, ou à valeur sans `:`** : sortie 2, le message dit lequel, curl jamais appelé.
   Variable non définie (dev local) : le smoke court comme aujourd'hui. Test : R116.

### Contraintes propres aux tâches R112 à R120

- Les **Global Constraints** et toutes les contraintes des séries précédentes valent. Exécution : **un agent par tâche,
  successifs dans l'ordre R112 → R120**, qui lit d'abord `tasks/relectures-748/brief-commun-agents.md`. **Pour R112 et
  suivantes, la section à lire en entier jusqu'à la première tâche est celle-ci** (« Corrections de la dixième
  relecture ») et le fichier de constats `tasks/relectures-748/relecture-10-constats.md` (le brief le dit, mis à jour
  dans le commit de ce plan). `superpowers:test-driven-development` : test écrit d'abord, **vu rouge pour la bonne
  raison** ; un test de couverture vert d'emblée est vu rouge par la mutation que sa tâche nomme, **restaurée depuis
  une copie du fichier faite avant la mutation et vérifiée par `cmp`** puis `git diff` vide sur le fichier muté.
  **Une mutation s'écrit en place par Python seulement** (`python3 -I` ; `open(p, 'r+')` ou `open(p, 'w')` sur le même
  chemin, qui garde l'inode), **jamais par l'outil Edit, `sed -i` ni `perl -pi`**. Après une écriture du code par Edit
  ou Write (pas une mutation), `docker compose restart frontend` (ou `backend`) avant de croire un test, en cas de
  doute. Refactoring pur : la suite existante comme filet, nombre de tests noté avant et après.
  **`superpowers:verification-before-completion` invoquée réellement** avant le rendu.
- **Cache de test backend vidé dans le conteneur, jamais sur l'hôte** : `docker compose exec -u dev backend rm -rf
  var/cache/test` après chaque mutation de configuration ou de service et après sa restauration. Un `rm -rf` de
  `backend/var/cache/test` sur l'hôte désynchronise le montage (vu en R108 : tests menteurs jusqu'au `restart`).
- **Git : seuls `git add <fichiers>` et `git commit`** (et `git mv` / `git rm` pour un déplacement ou une
  suppression). Ni `reset` (pas même sans argument), ni `add -N`, ni `checkout`, ni `checkout-index`, ni `switch`, ni
  `restore`, ni `stash`. **Un commit par tâche**, fichiers nommés, message en français terminé par les lignes
  d'attribution que donne le brief. R115 lit la branche `fix/750-audit-basic-auth-http` par `git show
  345178b0:<chemin>` seulement, sans jamais la sortir.
- **Une docstring ou une règle qui décrit un comportement modifié se corrige dans le même commit** ; une phrase de règle
  se **remplace**, elle ne s'ajoute pas à côté ; un commentaire s'écrit **au présent** (ni « depuis R… », ni « n'est
  plus », ni « restait » : l'historique vit dans le plan et dans `git log`) ; un nom de test peut citer le ticket, une
  tâche ou une décision (`#748, R112, décision 3`), jamais un identifiant de constat ni un repère de section du plan
  (« Review Focus », `Sp9-9`, `CR9-1`), qui n'ont pas de sens hors du plan (St10-15).
- **Lignes touchées ≤ 140 caractères**, mesurées par `python3 -I` (ou `awk 'length>140'`) sur chaque fichier touché.
- **Aucun travail en arrière-plan au rendu** ; **chaque code de sortie se lit sans pipe** (`make front-test; echo
  "exit=$?"`, de même `front-lint`, `front-build`, `back-test`, `back-quality`, chaque test hors ligne et
  `tools/check-claude-rules.sh`).
- Frontend (R112 à R114, R119) : lire avec `Read` `.claude/rules/frontend.md`, `.claude/rules/frontend-a11y.md` et la
  règle de la zone (`frontend-session-refus.md`, `frontend-session.md`, `frontend-backoffice.md`) ; test ciblé
  `docker compose exec -T frontend npm test -- <chemins>`, puis `make front-test`, `make front-lint`, `make front-build` ;
  toute chaîne nouvelle en FR et EN.
- Backend (R117, R118) : lire avec `Read` un fichier de `backend/tests/Security/`, `.claude/rules/backoffice-api.md` et
  `.claude/rules/security-authentication.md` avant d'écrire ; test ciblé `docker compose exec -u dev backend php
  bin/phpunit <chemin>`, puis `make back-test` et `make back-quality` ; `make back-style-fix` après un import ajouté ;
  jamais de secret littéral.
- Outillage (R115, R116) : lire avec `Read` les scripts touchés et `.claude/rules/deploiement-nginx.md` ;
  `bash tools/tests/<script>.test.sh; echo "exit=$?"` pour chaque script touché ; shellcheck dans l'image épinglée du job
  `tools-tests` : `docker run --rm --entrypoint shellcheck -v "$PWD":/repo:ro -w /repo
  rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -x --severity=warning
  <fichiers>` (si l'image n'expose pas `shellcheck`, le noter et lancer celui de l'hôte, en le disant). Une valeur de
  test ne contient jamais le mot « secret » (un message attendu, si).
- Règles : `tools/check-claude-rules.sh` après chaque modification ; 50 000 octets par règle, taille de chaque règle
  touchée notée au rendu. Tailles au départ (HEAD 39498f33) : `backoffice-api.md` **39 834** (aucune tâche n'y touche),
  `frontend-session-refus.md` 13 907, `frontend-session.md` 15 990, `frontend-backoffice.md` 28 837,
  `security-authentication.md` 9 601, `security-authentication-sessions.md` 22 529, `deploiement-nginx.md` 28 981,
  `release.md` 7 045 (non touchée : ses `paths:` ne couvrent pas `tools/lib/smoke-http.sh`).
- Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets (les exclure de tout
  `grep -r`) ; ne jamais nommer un compte réel. Pas de push, pas de PR.

### Fichiers (tâches R112 à R120)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts`, `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/application/admin/shared/useAdminLoad.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue` (docblock) ; specs `backofficeAccessRefusal.spec.ts`, `AccessRefusedNotice.spec.ts`, `useAdminLoad.spec.ts`, `BackofficeHttpClient.spec.ts`, `HttpAdminUserRepository.spec.ts` (estampilles), `frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts` | l'époque de montage | R112 |
| `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue`, `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts` | l'avis des réglages lié au formulaire chargé | R113 |
| `frontend/src/application/admin/translation/useAdminTranslation.ts`, les sept fichiers de formulaires par entrée, `frontend/src/presentation/ui/admin/useEntryFormLock.ts`, `frontend/tests/support/entryFormLock.ts`, `frontend/tests/presentation/ui/admin/useEntryFormLock.spec.ts`, les six specs de formulaires, `frontend/tests/application/admin/translation/useAdminTranslation.spec.ts` | la traduction se refuse elle-même, écrite une fois | R114 |
| `tools/audit-prod.sh`, `tools/tests/audit-prod.test.sh` | les sondes de redirection sans Basic Auth (#750) | R115 |
| `tools/lib/smoke-http.sh`, `tools/{audit-prod,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp}.sh`, `tools/tests/{smoke-http,audit-prod,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp}.test.sh` | secret vide ou sans `:`, garde de source, textes | R116 |
| `backend/src/Shared/Infrastructure/Http/CanonicalPath.php`, `backend/src/Security/Authentication/Infrastructure/Http/CsrfCookieRequestSubscriber.php` (commentaire), `backend/tests/Shared/Infrastructure/Http/CanonicalPathTest.php`, `backend/tests/Security/{ApiRouteExposureTest,RouterScopeTest}.php`, `backend/tests/Security/Authentication/Infrastructure/Http/CsrfCookieRequestSubscriberTest.php`, `backend/tests/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionTest.php` (commentaire), `backend/tests/Security/Authentication/AuthenticationFlowTest.php` | l'ancrage écrit une fois, le préfixe large justifié, le témoin intersite | R117 |
| `backend/tests/Security/AuthorizationLivesInAccessControlTest.php`, `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`, `backend/tests/Support/{CompiledResourcesReading,CompiledPropertiesReading}.php` (créés) | docblocks justes, parcours des types, valeurs | R118 |
| `frontend/tests/support/sourceText.ts`, `sourceText.spec.ts`, `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts` | lecteur de gabarit | R119 |
| `.claude/rules/*`, `frontend/src/application/auth/useAuth.ts` (commentaire) | dans le commit du comportement ; balayage | R112 à R119 ; R120 |

---

### Task R112 : Le refus du backoffice — l'époque de montage remplace la fenêtre de chargement (décision 3 : St10-2 ≈ CR10-3, CR10-1 = C10-8, CR10-2 ; St10-1 ; Sp10-1 = C10-9 ; St10-4 et St10-15 pour ces specs)

**Files :**
- Modify : `frontend/src/domain/admin/shared/BackofficeSessionReaction.ts`, `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/application/admin/shared/useAdminLoad.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue` (docblock, commentaire de l'`onMounted`)
- Test : `frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`, `frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts`, `frontend/tests/application/admin/shared/useAdminLoad.spec.ts` (le cas R102 retiré, l'estampille), `frontend/tests/infrastructure/admin/shared/BackofficeHttpClient.spec.ts` et `frontend/tests/infrastructure/admin/users/HttpAdminUserRepository.spec.ts` (estampilles : `epoch` ajouté)
- Modify (règles) : `.claude/rules/frontend-session-refus.md` (l. 26, l. 72-88, puce « Tests »), `.claude/rules/frontend-session.md` (l. 119 et la puce `useAdminLoad.spec.ts`, l. 152)

**Interfaces :**
- Produces : choix tranché 1 — `ReadRefusalScope`, `RefusalScope`, `readRefusalScope(): ReadRefusalScope`,
  `RefusalState.earlierSave: boolean`. Supprime : `refusalScope()`, `beginBackofficeLoad()`, `EarlierSave`. Consumes :
  rien d'une autre tâche.
- St10-1 : le docblock du module dit ce qu'il tient — le compteur de refus, l'avis de l'enregistrement précédent,
  l'écriture comptée, le périmètre et l'époque — et que la relecture du palier est l'affaire de `useAuth`. Les
  docblocks de `markBackofficeAccessRefused`, `settle…`, `clearBackofficeAccessRefusal`, `RefusalState`, de
  `ReadRefusalScope` et d'`AccessRefusedNotice` (puce du montage) disent, au présent : une lecture partie avant le
  montage de l'alerte est du chargement de la page d'arrivée et garde l'avis, quel que soit le moment de sa réponse ;
  toute autre lecture refusée, et toute écriture refusée, rend `common.accessRefused`. Plus de « fenêtre », de
  « chargement en vol », de « prolonge ».

- [ ] **Step 1 : tests, rouges.**
  `backofficeAccessRefusal.spec.ts` — les cas de `beginBackofficeLoad` (l. 112-220) sont **remplacés** (changement
  exprès), chacun partant d'une écriture refusée du compte `alice` comptée hors alerte, les lectures de la page
  d'arrivée estampillées (`readRefusalScope()`) **avant** `settle…('alice')` :
  - `it("chaque lecture partie avant le montage garde l'avis, quel que soit le moment de son refus (#748, R112)")` :
    deux estampilles avant `settle`, deux refus après → `earlierSaveRefused` vrai après chacun, compteur 3 ;
  - `it("une lecture partie après le montage rend le texte courant (#748, R112)")` : une estampille après `settle`,
    refusée → `earlierSaveRefused` faux ;
  - `it("une lecture de la page d'arrivée qui ne répond pas ne garde pas l'avis pour les suivantes (#748, R112)")`
    (Review Focus 2) : une estampille avant `settle` jamais refusée, une après, refusée → faux ;
  - `it("le montage avance l'époque, pas le périmètre (#748, R112)")` : `readRefusalScope()` avant et après `settle`
    → même `scope`, `epoch` différent ;
  - `it("après un effacement, une lecture d'avant le montage est périmée (#748, R112)")` : estampille avant `settle`,
    `clearBackofficeAccessRefusal()`, refus → `false` rendu, compteur 0.
  `AccessRefusedNotice.spec.ts` — `refuse(sentIn: ReadRefusalScope = readRefusalScope())` ; les cas « l'avis tient »
  (l. 315-400) estampillent leurs lectures avant `mountNotice()` et ne nomment plus `beginBackofficeLoad` ;
  `it("l'avis tient toutes les lectures parties avant le montage, et part au refus d'une lecture partie après (#748, R112)")`
  — textes FR exacts par `toBe`, `<span>` remplacé à chaque annonce. Constante `accessRefusedFr` (l. 23) coupée
  ≤ 140 caractères (St10-4).
  `AdminLayout.sessionEnded.spec.ts` (Sp10-1, Review Focus 1) — un `describe` qui monte le layout sur une **vraie**
  page à deux listes, `AdminQualityPage` (route `admin-quality`, `ADMIN_QUALITY_PRINCIPLE_REPOSITORY` et
  `ADMIN_QUALITY_TRAIT_REPOSITORY` fournis par `HttpAdminQualityPrincipleRepository` / `HttpAdminQualityTraitRepository`
  sur le vrai `BackofficeHttpClient`, la réaction de `createBackofficeSessionReaction`, `fetch` simulé) ;
  `it("une page à deux listes refusées après un enregistrement refusé pendant l'absence : l'avis aux deux annonces, le texte courant à la relecture qui suit un enregistrement (#748, R112, décision 3)")` :
  le compte connecté, `markBackofficeAccessRefused({ kind: 'write' }, <ce compte>)` avant tout montage, puis navigation
  vers la page : les deux `GET` en 403 → texte FR exact de `common.earlierSaveRefused`, `backofficeAccessRefused` 3 ;
  puis un principe créé (`POST` 201) dont la relecture (`GET`) répond 403 → texte FR exact de `common.accessRefused`.
  Run : `docker compose exec -T frontend npm test -- tests/application/admin/shared/backofficeAccessRefusal.spec.ts tests/presentation/ui/admin/AccessRefusedNotice.spec.ts tests/presentation/layout/AdminLayout.sessionEnded.spec.ts`.
  Expected : FAIL, `readRefusalScope` introuvable ; puis, exportée vide de sens (`epoch` toujours 0), le cas « partie
  après le montage » sur `true` au lieu de `false`, et le cas d'intégration sur le texte de la troisième annonce.
- [ ] **Step 2 : implémenter** selon le choix tranché 1 ; chaque transition reste une seule affectation de
  `state.value` (R84) ; `useAdminLoad.load()` sans `endLoad` (son `finally` ne garde que `isLoading`) ; les estampilles
  écrites à la main dans `useAdminLoad.spec.ts`, `BackofficeHttpClient.spec.ts` (`stampOf`) et
  `HttpAdminUserRepository.spec.ts` portent `epoch` ou passent par `readRefusalScope()` ; le cas R102 de
  `useAdminLoad.spec.ts` (« chaque load() se déclare… ») est retiré (il n'y a plus rien à déclarer).
- [ ] **Step 3 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) dans `AccessRefusedNotice.vue`,
  `settleBackofficeAccessRefusalsOnMount(lastAccountName())` déplacé de l'`onMounted` au `setup` → le cas d'intégration
  rougit (l'époque avance avant les lectures de la page : la première annonce dit `common.accessRefused`) ; (b) dans
  `markBackofficeAccessRefused`, la comparaison d'époque retirée (une lecture garde toujours l'avis) → le cas « partie
  après le montage » et la troisième annonce de l'intégration rougissent ; restaurés.
- [ ] **Step 4 : noms** — St10-15 : dans les trois specs de la tâche, aucun nom de test ne porte « Review Focus »,
  `Sp9-…`, `CR9-…` ni `CR10-…` (`grep -nE "it\(.*(Review Focus|Sp[0-9]+-[0-9]+|CR[0-9]+-[0-9]+|St[0-9]+-[0-9]+|C[0-9]+-[0-9]+)" <specs>` vide).
- [ ] **Step 5 : règles** — `frontend-session-refus.md` : l. 26, « `{ kind: 'read', scope }` » → « `{ kind: 'read',
  scope, epoch }` » ; l. 72-77, la puce du périmètre gagne, à la fin : « A read also carries the **epoch**, which the
  alert's mount advances (and nothing else): a read sent before the mount belongs to the landing page's load. » ;
  l. 78, « **the alert's mount advances nothing and settles what was counted without an alert** » → « **the alert's
  mount advances the epoch only, never the scope, and settles what was counted without an alert** » ;
  l. 83-88, « every read refused **during the landing page's load** keeps that text … amending decision 4 of the
  eighth). » → « every read **sent before the mount** — the landing page's load, stamped by its `setup` — keeps that
  text and re-announces it, whenever it answers; any other refusal, read or write, brings back `common.accessRefused`
  (R112, decision 3 of the tenth review, amending the letter of decision 1 of the ninth: no load window, no counter). » ;
  puce « Tests » : « every read refused during the landing load keeps the text, the first refusal after it brings back
  the current one, R76, R102 (`AccessRefusedNotice.spec.ts`, `backofficeAccessRefusal.spec.ts`, `useAdminLoad.spec.ts`,
  `backofficeSessionExpiry.spec.ts`) » → « every read sent before the mount keeps the text, a read sent after it brings
  back the current one, R76, R112 (`AccessRefusedNotice.spec.ts`, `backofficeAccessRefusal.spec.ts`,
  `backofficeSessionExpiry.spec.ts`; on a real two-list page, `AdminLayout.sessionEnded.spec.ts`) ».
  `frontend-session.md` : l. 119, la phrase « Each `load()` also declares itself to the backoffice's refusal
  (`beginBackofficeLoad`, `frontend-session-refus.md`). » **retirée** ; l. 152, « , the load declared and ended on every
  outcome, R102 » retiré. Tailles notées.
- [ ] **Step 6 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn -e beginBackofficeLoad -e loadsInFlight -e refusalScope -e "'landing'" -e "'landed'" frontend/src frontend/tests .claude/rules`
  : vide (`readRefusalScope` seul) ; `grep -n backofficeAccessRefusal frontend/src/application/admin/shared/useAdminLoad.ts` : vide.
- [ ] **Step 7 : commit** — `fix(frontend): une lecture partie avant le montage de l'alerte garde l'avis de l'enregistrement précédent, l'époque portée par l'estampille remplace la fenêtre de chargement (#748)`.

---

### Task R113 : Réglages « À propos » — l'avis de proposition n'est montré que sous un formulaire chargé (CR10-10 ; St10-14 pour ce commentaire)

**Files :**
- Modify : `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue` (`isProposalShown`, le commentaire du `watch` de `settings`)
- Test : `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« **A proposal notice is its target locale's** … »)

**Interfaces :**
- Choix tranché 2. Le commentaire du `watch` (l. 89-92) ne parle plus du test (St10-14) : il dit ce que fait le code.

- [ ] **Step 1 : test, rouge** — `it("une proposition vers l'EN, passage au FR puis retour à l'EN dont la relecture est refusée : la proposition n'est pas montrée sous un formulaire non chargé (#748, R113)")` :
  les étapes du cas de R103 jusqu'au passage au FR, puis retour à l'EN, le `GET` des réglages EN en 403 →
  `(wrapper.findComponent(AdminAboutSettingsForm).vm as unknown as { isProposalShown: boolean }).isProposalShown` vaut
  `false` (expected aujourd'hui : `true`), et aucun avis `role="status"` de proposition dans le DOM (assertion gardée).
  Run : `docker compose exec -T frontend npm test -- tests/presentation/pages/admin/AdminAboutPage.spec.ts`.
  Expected : FAIL sur `isProposalShown`. Si `vm` ne rend pas ce `computed` du `<script setup>` : s'arrêter et le dire.
- [ ] **Step 2 : implémenter** selon le choix tranché 2 ; le commentaire du `watch`, au présent : des réglages qui
  arrivent sont ceux de la langue montrée ; ceux de la cible remplacent la proposition et la retirent ; ceux d'une
  autre langue la laissent, et la relecture de la cible la retire.
- [ ] **Step 3 : règle** — après « settings of the other locale arriving leave it (R103, decision 2 of the ninth
  review) » : « ; it shows only under a loaded form (`isLoaded`), so a refused or interrupted re-read of its target
  shows nothing (R113) ». Taille notée.
- [ ] **Step 4 : verts** — spec ciblé PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `fix(frontend): réglages « À propos », l'avis de proposition n'est montré que sous un formulaire chargé (#748)`.

---

### Task R114 : Traduction assistée — elle se refuse elle-même pendant une traduction en vol et pendant une proposition, écrite une fois ; « Annuler » périme sa réponse (CR10-4 = C10-10, St10-6 ; Sp10-2, St10-8 = Sp10-3 pour le verrou, St10-11, St10-5 pour `AdminAboutPage.spec.ts`)

**Files :**
- Modify : `frontend/src/application/admin/translation/useAdminTranslation.ts`, `frontend/src/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,AdminAnonymousCvPage,AdminQualityPage,AdminAboutMeCardsSection,AdminAboutSiteCardsSection}.vue`, `frontend/src/presentation/ui/admin/useEntryFormLock.ts`
- Test : `frontend/tests/application/admin/translation/useAdminTranslation.spec.ts` (créé s'il n'existe pas), `frontend/tests/support/entryFormLock.ts`, `frontend/tests/presentation/ui/admin/useEntryFormLock.spec.ts`, les six specs qui déclarent un `EntryFormLockCase`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant » : la phrase « `handleTranslate` refuses itself as well, not only through the disabled button, R104 » ; la description de `tests/support/entryFormLock.ts`)

**Interfaces :**
- Produces : choix tranchés 3 et 4 — `useAdminTranslation(options?: { readonly isRefused?: () => boolean })`,
  `UseAdminTranslationResult.discard: () => void` ; `useEntryFormLock({ hintId, isEditing, proposalSourceLocale,
  localeHelpId? })` ; `EntryFormLockCase.createTranslation`, `mount(translation)`. Consumes : rien d'une autre tâche.
- St10-8 : `LOCALE_LOCKED_EDITING_TEXT` → `ENTRY_LOCK_EDITING_TEXT`, `LOCKED_BY_PROPOSAL_TEXT` →
  `ENTRY_LOCK_PROPOSAL_TEXT` (les clés `admin.entryLock.editing` / `.proposal`) ; « Les cas du verrou de la langue »
  → « Les cas du verrou du formulaire » (`AdminAboutPage.spec.ts:1013`, `AdminQualityPage.spec.ts:664`).
- Sp10-2 : le cas « pendant une proposition, un « translate » émis ne lance aucune traduction » émet **chaque langue**
  de `SUPPORTED_LOCALES` tour à tour ; son commentaire et celui d'`entryFormLock.ts` disent « chaque langue », plus
  « la langue source ».
- St10-5 : `AdminAboutPage.spec.ts:736` (« Le sélecteur restait ») réécrit au présent (ce que le cas pince).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre de tests noté.
- [ ] **Step 2 : tests, rouges** (Review Focus 3).
  `useAdminTranslation.spec.ts` : `it("une traduction en vol refuse la suivante sans requête (#748, R114)")` (dépôt
  différé : deux `translate`, un appel au dépôt, la seconde rend `null`) ;
  `it("isRefused vrai : aucune requête, null (#748, R114)")` ;
  `it("discard() périme la traduction en vol : sa réponse rend null (#748, R114)")` (`isTranslating` faux après,
  `errorReason` nul).
  `entryFormLock.ts` (`describeEntryFormLock`, huit formulaires) :
  `it(\`${prefix}deux « translate » avant la première réponse : une seule traduction\`)` (le support rend la réponse
  du dépôt différée par `mockImplementationOnce`, émet deux fois `translate` depuis le formulaire en édition → un appel) ;
  `it(\`${prefix}« Annuler » pendant une traduction : la réponse qui arrive ne remplit rien\`)` (différée, « Annuler »,
  réponse rendue, `flushPromises()` → titre du formulaire vide, aucun avis `role="status"` de proposition).
  Run : `docker compose exec -T frontend npm test -- tests/application/admin/translation tests/presentation/pages/admin`.
  Expected : FAIL — `discard` introuvable ; deux appels au dépôt ; le formulaire rempli après « Annuler ».
- [ ] **Step 3 : implémenter** selon les choix tranchés 3 et 4 : les huit gardes `if (lock.isProposalLocked)` et leurs
  commentaires retirés des pages ; le support et les six specs (plus de `let lockTranslation` ni de
  `translationRequests`) ; renommages St10-8 par `python3 -I` sur la liste que `grep -rln` donne, puis
  `docker compose restart frontend`.
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) le test de `isTranslating` retiré
  de `translate` → les huit cas « deux « translate » » rougissent ; (b) l'appel de `discard()` retiré du `resetForm()`
  d'`AdminAboutSiteCardsSection.vue` → seul son cas « Annuler pendant une traduction » rougit ; (c) `isRefused` non
  passé par `AdminIncidentsPage.vue` → seul son cas « pendant une proposition » rougit ; restaurés.
- [ ] **Step 5 : règle** — « `handleTranslate` refuses itself as well, not only through the disabled button, R104) »
  → « `useAdminTranslation` refuses the call itself, not only the disabled button: during a proposal (`isRefused`, the
  form's lock) and while a translation is in flight, and « Annuler » discards the response in flight (`discard()`,
  R114)) » ; la description de `tests/support/entryFormLock.ts` dit qu'il crée le dépôt de traduction de chaque cas et
  compte ses appels. Taille notée.
- [ ] **Step 6 : verts** — `make front-test; echo "exit=$?"` (filet + 3 + 16), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn -e "isProposalLocked) {" -e lockTranslation -e translationRequests -e LOCALE_LOCKED_EDITING_TEXT -e LOCKED_BY_PROPOSAL_TEXT -e "verrou de la langue, avec" -e "proposalSource:" frontend/src frontend/tests`
  : vide.
- [ ] **Step 7 : commit** — `fix(frontend): la traduction assistée se refuse elle-même pendant une traduction en vol et pendant une proposition, « Annuler » périme sa réponse (#748)`.

---

### Task R114b : Traduction assistée — « Modifier », « Créer la version » et la langue des réglages périment la traduction en vol

(Origine : question de R114, tranchée par l'auteur le 2026-10-31 : à corriger maintenant.)

R114 n'a donné `discard()` qu'aux remises à zéro (« Annuler », enregistrement réussi) : `startEdit` et `startCreateVersion` remplacent
aussi le contenu du formulaire, et une traduction en vol y posait alors la prose d'une autre entrée ; de même, le changement de
langue de la page relit le formulaire des réglages « À propos » d'une autre langue.

**Files :**
- Modify : `frontend/src/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,
  AdminAnonymousCvPage,AdminQualityPage,AdminAboutMeCardsSection,AdminAboutSiteCardsSection,AdminAboutSettingsForm}.vue`
  (`discard…()` en tête de chaque `startEdit…` et `startCreate…Version`, et du `watch` de `props.locale` des réglages),
  `frontend/src/application/admin/translation/useAdminTranslation.ts` (docblock)
- Test : `frontend/tests/support/entryFormLock.ts` (deux cas partagés, `startCreateVersion` dans `EntryFormLockCase`),
  les six specs qui déclarent un `EntryFormLockCase` (une ligne `startCreateVersion`), `AdminAboutPage.spec.ts` (réglages)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant » : la phrase de `discard()`)

- [x] Rouges vus pour la bonne raison : les huit formulaires (16 cas : « Modifier » une entrée pendant une traduction → la prose traduite
  apparaît ; « Créer la version » → idem, avis de proposition) et les réglages (la langue de la page bascule en EN à l'arrivée).
- [x] Autres chemins examinés : suppression de l'entrée ouverte (`handleDelete` ne touche pas au formulaire : rien à périmer) ;
  changement de langue d'un formulaire par entrée (sélecteur désactivé en édition, et ne remplace aucune prose) ; catégorie d'une
  carte « moi » (ne change que « Version de ») ; enregistrement réussi (passe déjà par `resetForm()`).
- [x] Mutation de contrôle : l'appel retiré du `startEdit` d'`AdminIncidentsPage.vue`, du `startCreateVersion` du même fichier, du `watch`
  des réglages → seul le cas visé rougit à chaque fois ; restaurés depuis une copie vérifiée par `cmp`.
- [x] Vérification : `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh` à 0.
- Commit : `fix(frontend): « Modifier » une autre entrée et le changement de langue des réglages périment une traduction en vol (#748)`.

---

### Task R115 : Outillage — les sondes de redirection de l'audit partent sans Basic Auth, jamais en clair sur `http://` (décision 1 : C10-1, même correction que #750)

**Files :**
- Modify : `tools/audit-prod.sh` (boucle « Redirections », commentaire de `CURL_OPTS_ANONYMOUS`)
- Test : `tools/tests/audit-prod.test.sh`
- Modify (règle) : `.claude/rules/deploiement-nginx.md` (paragraphe d'`audit-prod.sh`, l. 120)

**Interfaces :**
- Choix tranché 5. Texte de référence : `git show 345178b0:tools/audit-prod.sh` (commentaire de la boucle, lignes
  `Ces sondes partent sans Basic Auth (CURL_OPTS_ANONYMOUS) : …` à `… (#750).`) et
  `git show 345178b0:tools/tests/audit-prod.test.sh` (les trois noms de cas ci-dessous), lus sans sortir la branche.
- Le report de `develop` dans la branche rencontrera `tools/tests/audit-prod.test.sh` créé des deux côtés : garder
  celui de la branche, qui porte les trois cas de #750 sous les mêmes noms (dit dans le corps de la PR, tâche 12).

- [ ] **Step 1 : tests, rouges** (Review Focus 4) — dans `audit-prod.test.sh`, sur les appels du run `nominal`
  (`AUDIT_BASIC_AUTH='u:p'`, `$TMP/nominal.calls`, l'URL en dernier argument), trois cas aux noms de #750 :
  `pass "aucun appel http:// ne porte -K"` (et un échec si aucun appel `http://` n'est noté : le cas ne prouverait
  rien) ; `pass "la sonde de redirection https://www. part sans -K"` ; `pass "chaque appel https:// vers l'apex porte -K (la préprod reste authentifiée)"`.
  Run : `bash tools/tests/audit-prod.test.sh; echo "exit=$?"`. Expected : exit 1, les deux premiers en FAIL (les
  sondes portent `-K`), le troisième PASS.
- [ ] **Step 2 : implémenter** selon le choix tranché 5 ; le commentaire de la boucle copié mot pour mot de 345178b0.
- [ ] **Step 3 : règle** — `deploiement-nginx.md`, dans le paragraphe d'`audit-prod.sh` : « Its redirection probes
  (section 3) leave without the Basic Auth (`CURL_OPTS_ANONYMOUS`): curl sends `Authorization: Basic` on the first
  request whatever the scheme, so a `-K` there would put the preprod credential in clear on `http://` (#750, R115). »
  Taille notée.
- [ ] **Step 4 : verts** — `bash tools/tests/audit-prod.test.sh; echo "exit=$?"` à 0 ; shellcheck (image épinglée) sur
  `tools/audit-prod.sh` et `tools/tests/audit-prod.test.sh` ; `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `diff <(git show 345178b0:tools/audit-prod.sh | sed -n '/^section "Redirections"/,/^done/p') <(sed -n '/^section "Redirections"/,/^done/p' tools/audit-prod.sh)` : vide.
- [ ] **Step 5 : commit** — `fix(tools): les sondes de redirection de l'audit partent sans Basic Auth, jamais en clair sur http:// (#748, #750)`.

---

### Task R116 : Outillage — un secret vide ou sans « : » arrête aussi les trois smokes, par une fonction commune ; la garde de source du nom ; les textes (décision 4 : CR10-6 = C10-2 ; C10-4, C10-3 = Sp10-7, St10-15 pour `run`, St10-4, St10-5, St10-13, St10-14 pour `smoke-http.sh`)

**Files :**
- Modify : `tools/lib/smoke-http.sh`, `tools/audit-prod.sh`, `tools/smoke-dead-session-cookie.sh`, `tools/smoke-login-throttling.sh`, `tools/smoke-mcp.sh`
- Test : `tools/tests/smoke-http.test.sh`, `tools/tests/audit-prod.test.sh`, `tools/tests/smoke-dead-session-cookie.test.sh`, `tools/tests/smoke-login-throttling.test.sh`, `tools/tests/smoke-mcp.test.sh`
- Modify (règles) : `.claude/rules/deploiement-nginx.md` (l. 117-121), `.claude/rules/security-authentication-sessions.md` (l. 187-190)

**Interfaces :**
- Choix tranché 6. Consumes : R115 (le test d'audit déjà étendu). Les trois smokes et l'audit écrivent :
  `smoke_write_auth_config AUDIT_BASIC_AUTH || exit 2` puis `smoke_require_auth_config AUDIT_BASIC_AUTH || exit 2`.
- St10-15 : `audit-prod.test.sh` gagne `run_traced <nom>` (même environnement que `run`, sous `bash -x`), lu par les
  cas `traced` et `videtraced` ; aucun des deux ne réécrit l'appel en ligne.
- St10-14 : le commentaire d'environ 118 caractères de `smoke-http.sh` recoupé à la largeur de son bloc.

- [ ] **Step 1 : filet** — les cinq tests hors ligne à 0, sorties notées.
- [ ] **Step 2 : tests, rouges** (Review Focus 5).
  - `smoke-http.test.sh` : `smoke_require_auth_config` — définie vide → retour 2, « secret de Basic Auth vide » ;
    non définie → 0 ; définie pleine après `smoke_write_auth_config` → 0 ; nom invalide (`SMOKE_AUTH_DIR`) → 2.
    `smoke_write_auth_config` d'une valeur `u` (sans `:`) → 2, « sans « : » », aucun dossier ; de trois blancs → 2.
    Garde de source : dans `tools/{audit-prod,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp}.sh`, aucune
    ligne hors commentaire ne développe `AUDIT_BASIC_AUTH` (`grep -nE '\$\{?AUDIT_BASIC_AUTH|!AUDIT_BASIC_AUTH'`) —
    C10-3 : c'est elle qui voit la mutation que la trace ne voit pas.
  - Un cas par smoke (`smoke-dead-session-cookie`, `smoke-login-throttling`, `smoke-mcp`) : `AUDIT_BASIC_AUTH=''` →
    sortie 2, « AUDIT_BASIC_AUTH : secret de Basic Auth vide » sur stderr, le faux curl jamais appelé.
  Run : chaque test hors ligne, `; echo "exit=$?"`. Expected : `smoke_require_auth_config` introuvable ; les smokes
  sortent 1 après des appels ; la valeur sans `:` rend 0.
- [ ] **Step 3 : implémenter** selon le choix tranché 6 ; `audit-prod.sh` remplace son `if [ -z "$SMOKE_AUTH_CONFIG" ]`
  par l'appel commun (ses tests restent verts, même message) ; son commentaire des trois cas le dit ; les trois
  commentaires « # Basic Auth : … » des smokes nomment les deux fonctions.
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) dans `audit-prod.sh`, la ligne
  `[ -z "$AUDIT_BASIC_AUTH" ] && exit 2` ajoutée avant l'appel commun (la forme de C10-3) → la garde de source rougit,
  en nommant le fichier et la ligne ; (b) l'appel de `smoke_require_auth_config` retiré de `smoke-mcp.sh` → seul le cas
  « secret vide » de `smoke-mcp.test.sh` rougit ; restaurés.
- [ ] **Step 5 : textes** — St10-5 : `audit-prod.test.sh:161` au présent (« un audit sans Basic Auth lirait le 401 de
  la préprod comme des dizaines d'échecs ») ; St10-4 : `audit-prod.test.sh:168` et `smoke-http.test.sh:428` ≤ 140, et
  toute ligne touchée.
- [ ] **Step 6 : règles** — `deploiement-nginx.md`, l. 117-121 **remplacées** par des lignes coupées vers 115
  caractères, une phrase par fait (St10-13 : la plomberie est écrite ici seulement) : la bibliothèque et ses lecteurs ;
  `smoke_write_auth_config` (nom validé, valeur lue sous `set +x`, une valeur sans `:` refusée en sortie 2, dossier
  privé, un second appel remplace le premier) ; `smoke_require_auth_config` (définie mais vide : sortie 2, « secret de
  Basic Auth vide », pour l'audit et les trois smokes, R116, décision 4 de la dixième série ; non définie : sans Basic
  Auth) ; `smoke_fetch` (relit le `-K` à chaque appel, fichier absent : 2, curl sans réponse : 1) ; `audit-prod.sh`
  (ne source la bibliothèque que si la variable est définie, `[[ -v ]]` ; retour à la ligne, bibliothèque absente ou
  invalide : 2) ; le test hors ligne. `security-authentication-sessions.md`, l. 187-190 : « A `curl` that gets no
  answer exits 1 … `tools/tests/smoke-http.test.sh`. » → « Its plumbing (the Basic Auth file, exits 1 and 2) is
  `tools/lib/smoke-http.sh`'s, described once in `.claude/rules/deploiement-nginx.md`; offline tests
  `tools/tests/smoke-dead-session-cookie.test.sh` and `tools/tests/smoke-http.test.sh`. » Tailles notées.
- [ ] **Step 7 : verts** — les cinq tests hors ligne à 0 (filet + nouveaux cas) ; shellcheck (image épinglée) sur la
  bibliothèque, les quatre scripts et les cinq tests ; `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `awk 'length>140' .claude/rules/deploiement-nginx.md | wc -l` : pas plus qu'au départ, et aucune ligne du paragraphe
  réécrit.
- [ ] **Step 8 : commit** — `fix(tools): un secret de Basic Auth vide ou sans « : » arrête aussi les smokes, par une fonction commune ; aucune ligne ne développe la variable (#748)`.

---

### Task R117 : Backend — « relève de `/api` » écrit une fois pour les tests, le préfixe large du double-submit justifié et prouvé, le témoin intersite (arbitrage : CR10-8 = C10-7 = St10-3, C10-6 ; Sp10-5 pour le préfixe, Sp10-6, St10-5 et St10-4 pour ces fichiers, C10-12)

**Files :**
- Modify : `backend/src/Shared/Infrastructure/Http/CanonicalPath.php`, `backend/src/Security/Authentication/Infrastructure/Http/CsrfCookieRequestSubscriber.php` (commentaire de `requiresCsrfCheck()`), `backend/tests/Security/ApiRouteExposureTest.php`, `backend/tests/Security/RouterScopeTest.php`, `backend/tests/Security/Authentication/Infrastructure/Security/PublicRouteIgnoresRefusedSessionTest.php` (commentaire)
- Test : `backend/tests/Shared/Infrastructure/Http/CanonicalPathTest.php`, `backend/tests/Security/Authentication/Infrastructure/Http/CsrfCookieRequestSubscriberTest.php`, `backend/tests/Security/Authentication/AuthenticationFlowTest.php`
- Modify (règle) : `.claude/rules/security-authentication.md` (puce `CanonicalPath`, l. 70-81 ; puce `CsrfCookieRequestSubscriber`, l. 30-47)

**Interfaces :**
- Choix tranché 7. Commentaire de `requiresCsrfCheck()` : « Le préfixe large est voulu : le pare-feu `api` est `^/api`
  non ancré (A23), une route `/apix` y lirait `BEARER` ; ancrer ce test sortirait une écriture authentifiée du
  double-submit. » ; celui de `PublicRouteIgnoresRefusedSessionTest.php:187` dit qu'il suit le même pare-feu.
- Sp10-6 : `ApiRouteExposureTest.php:64-66` : « … sur le routeur et l'`AccessMap` : c'est la prémisse « route publique
  = route sans règle ». Les témoins de cette prémisse … ». St10-5 : l. 345, « une route publique n'a plus le 401 de
  Lexik pour un cookie mort » → « une route publique répond à un cookie mort comme à l'anonyme ».

- [ ] **Step 1 : filet** — `make back-test; echo "exit=$?"`, nombre de tests noté.
- [ ] **Step 2 : tests** —
  `CanonicalPathTest::testIsPathUnder` (fournisseur : `['/api', '/api', true]`, `['/api/me', '/api', true]`,
  `['/apis', '/api', false]`, `['/api-x', '/api', false]`, `['/apix/y', '/api', false]`) et
  `testIsPathUnderRefusesAMalformedPrefix` (`'api'`, `'/api/'`, `''` → `MalformedPathPrefixException`). Expected : FAIL,
  `isPathUnder` introuvable.
  `CsrfCookieRequestSubscriberTest::testANeighbourOfApiUnderTheApiFirewallIsStillChecked` : `POST /apix` avec un
  cookie `BEARER`, sans `X-XSRF-TOKEN` → `AccessDeniedHttpException` (403). Expected : vert (comportement juste) ; vu
  rouge au Step 4.
  `AuthenticationFlowTest` (C10-12) : dans `testAForcedLogoutWithoutTheCsrfHeaderExpiresNoCookie`, après le cas avec
  cookie, un second client **sans aucun cookie** : `POST /api/logout` sans en-tête → 403 et
  `getResponse()->headers->getCookies()` vide. Expected : vert ; vu rouge au Step 4. Lignes 106-107 coupées ≤ 140
  (St10-4).
- [ ] **Step 3 : implémenter** selon le choix tranché 7 : `isApiPath()` retiré d'`ApiRouteExposureTest`, son
  fournisseur `testOnlyApiPathsAreJudged` retiré (couvert par `CanonicalPathTest`) ; `RouterScopeTest.php:80` ;
  commentaires ; `make back-style-fix`.
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`, puis `docker compose exec -u dev backend
  rm -rf var/cache/test` après la mutation et après la restauration) : (a) dans `requiresCsrfCheck()`,
  `str_starts_with($path, '/api')` → `CanonicalPath::isPathUnder($path, '/api')` → le cas `/apix` rougit (aucune
  exception) ; (b) `'/api/logout'` ajouté à `EXCLUDED_PATHS` → les deux moitiés du témoin rougissent ; restaurés.
- [ ] **Step 5 : règle** — `security-authentication.md` : dans la puce `CanonicalPath`, « A subtree check goes through
  `CanonicalPath::isUnder($request, '/api/<x>')` (`isUnderApi()` is that, for `/api`) » → « … (`isUnderApi()` is that,
  for `/api`; `isPathUnder($path, $prefix)` is the same rule on a string, for the tests that walk the router, R117) » ;
  dans la puce `CsrfCookieRequestSubscriber`, après « **`/api/logout` stays under the guard** … » : « Its own test of
  « under `/api` » is the **bare** prefix on purpose: it follows the `api` firewall, `^/api` unanchored (A23), where a
  `/apix` route would read `BEARER` (`CsrfCookieRequestSubscriberTest`, `/apix`, C10-6). » Taille notée.
- [ ] **Step 6 : verts** — `make back-test; echo "exit=$?"` (filet + les nouveaux cas − les cinq cas retirés de `testOnlyApiPathsAreJudged`, nombres notés), `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"` ; `grep -rn "str_starts_with(\$path, '/api')\|'/api' === \$path" backend/src backend/tests`
  : les deux préfixes larges commentés et `CanonicalPath` seuls ; lignes touchées ≤ 140.
- [ ] **Step 7 : commit** — `test(security): « relève de /api » écrit une fois pour les tests, le préfixe large du double-submit justifié et prouvé, le logout intersite témoin (#748)`.

---

### Task R118 : Backend — les docblocks de la garde d'autorisation disent ce que fait le code ; la garde des métadonnées en valeurs, ses génériques par une seule voie (CR10-9 = Sp10-11 = St10-10, CR10-7, St10-9)

**Files :**
- Modify : `backend/tests/Security/AuthorizationLivesInAccessControlTest.php`, `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`
- Create : `backend/tests/Support/CompiledResourcesReading.php`, `backend/tests/Support/CompiledPropertiesReading.php`
- Modify (règle) : `.claude/rules/security-authentication-sessions.md` (l. 175-176 : « each exemption … carrying its reason and the exact sites it covers, each written once »)

**Interfaces :**
- Choix tranché 8. CR10-9 : le docblock de classe d'`AuthorizationLivesInAccessControlTest` dit « Chaque exemption dit
  sa raison et le nombre de sites qu'elle couvre dans son fichier, par nature (C7-13) : un site déplacé dans le
  fichier reste couvert. » ; les commentaires de boucle (l. 87, 92) : « chaque site trouvé consomme un site de sa
  nature dans l'exemption de son fichier » et « plus aucun site de cette nature dans l'exemption : un site de trop ».
- La règle : « each exemption (the MCP reader, the two CSRF guards) carrying its reason and the number of sites of
  each kind it covers in its file ».

- [ ] **Step 1 : filet** — les deux classes vertes, nombres de cas notés ; `make back-test; echo "exit=$?"`.
- [ ] **Step 2 : rouge vu par mutation, avant le changement** (Python, copie vérifiée par `cmp`) : la ligne
  `$inner[] = $type->getCollectionKeyType();` retirée → le cas « la clé d'une collection » reste **vert** (le constat
  CR10-7, noté) ; restauré.
- [ ] **Step 3 : refactorer** selon le choix tranché 8 ; `make back-style-fix`.
- [ ] **Step 4 : rouge vu par mutation, après** (Python, copie vérifiée par `cmp`) : la branche `GenericType`
  d'`appClassesIn()` retirée → « un générique non collection » **et** « la clé d'une collection » rougissent ; restauré.
- [ ] **Step 5 : règle** selon les interfaces ; taille notée.
- [ ] **Step 6 : verts** — les deux classes vertes, mêmes nombres ; `make back-test; echo "exit=$?"` (même nombre),
  `make back-quality; echo "exit=$?"` ; `grep -n "array &\|@return array{" backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`
  : vide ; `grep -n "un par un\|n'y figure pas" backend/tests/Security/AuthorizationLivesInAccessControlTest.php` : vide ;
  lignes ≤ 140.
- [ ] **Step 7 : commit** — `refactor(security): les docblocks de la garde d'autorisation disent l'appariement par nature ; la garde des métadonnées rend des valeurs et suit les génériques par une seule voie (#748)`.

---

### Task R119 : Le lecteur de gabarit — attributs statiques lus, gabarit sans arbre refusé, `v-on` lu comme Vue, noms et types justes (C10-11, CR10-5 = Sp10-12, St10-7, St10-12, Sp10-9 pour le repli, St10-8 pour `sources`)

**Files :**
- Modify : `frontend/tests/support/sourceText.ts`, `frontend/tests/infrastructure/http/noOmittedCredentials.spec.ts`, `frontend/tests/application/auth/sessionStateEncapsulation.spec.ts`
- Test : `frontend/tests/support/sourceText.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session.md` (l. 159, puce `noOmittedCredentials.spec.ts`)

**Interfaces :**
- Choix tranché 9. Docblocks : `templateExpressionsOf(path, source)` lit un SFC et rend les fragments de son gabarit,
  ou lève pour un gabarit sans arbre ; `fragmentsOfTemplateAst(root)` parcourt un arbre déjà lu.
- St10-8 : `const sources` → `const literalsByFile` (`noOmittedCredentials.spec.ts`).
- Sp10-9 : `sessionStateEncapsulation.spec.ts:253` lit `sourceOf(file)` (R110), le repli `source === undefined ? []`
  retiré.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : tests, rouges** — `sourceText.spec.ts` :
  `templateExpressionsOf('X.vue', '<template><Fetcher mode="omit" /></template>')` : ses `stringLiterals` contiennent
  `'omit'` (expected : `[]`) ;
  `expect(() => templateExpressionsOf('X.vue', '<template lang="pug">p x</template>')).toThrow('X.vue')` (expected : `[]`) ;
  `<template><C v-on="{ click: a, keydown: b }" /></template>` : un fragment sans erreur de syntaxe
  (`syntaxErrorsOf`) (expected : erreur, bloc à étiquettes) ;
  `@click="function () { b('omit') }"` : un fragment sans erreur, `'omit'` lu ;
  `@click="a(); b('omit')"` : toujours un corps de fonction, `'omit'` lu (non-régression).
  Run : `docker compose exec -T frontend npm test -- tests/support/sourceText.spec.ts tests/infrastructure/http/noOmittedCredentials.spec.ts tests/application/auth/sessionStateEncapsulation.spec.ts`.
  Expected : FAIL sur les quatre premiers cas.
- [ ] **Step 3 : implémenter** selon le choix tranché 9 ; le garde réel (`noOmittedCredentials.spec.ts`) et le test
  « chaque fragment de `src/` se lit » restent verts sur `src/` (aucun gabarit sans arbre, aucun attribut `"omit"`).
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) la lecture des attributs statiques
  retirée → le cas `mode="omit"` rouge ; (b) `wrapperOf` rend toujours le corps de fonction pour `on` → le cas objet
  et le cas `function () {}` rougissent ; restaurés.
- [ ] **Step 5 : règle** — « … `v-for` and `v-slot` included, each read as the JavaScript fragment Vue compiles, every
  fragment of `src/` required to parse, R68, R80, R96, R110 » → « … `v-for`, `v-slot` and static attribute values
  included, each read as the JavaScript fragment Vue compiles (a `v-on` handler as an expression, a statement list as
  a function body), every fragment of `src/` required to parse, a `<template>` without a tree (`lang`, `src`) refused,
  R68, R80, R96, R110, R119 ». Taille notée.
- [ ] **Step 6 : verts** — `make front-test; echo "exit=$?"` (filet + 5), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "expressionsOfTemplate\|source === undefined ? \[\]\|const sources" frontend/tests` : vide.
- [ ] **Step 7 : commit** — `test(frontend): le lecteur de gabarit lit les attributs statiques et chaque v-on comme Vue, refuse un gabarit sans arbre ; noms et types justes (#748)`.

---

### Task R120 : Balayage documentaire de la dixième série — avec la liste des recherches et de leurs résultats

Les docstrings et les règles ont suivi chaque comportement dans son commit ; cette tâche vérifie qu'il ne reste rien.
**Les deux derniers balayages ont été incomplets** (R111 n'a pas noté le préfixe large de C10-6) : le rendu donne,
pour **chaque** recherche ci-dessous, la commande exacte, le nombre de lignes rendues et, pour chaque ligne gardée, la
raison en quelques mots (historique daté, sens juste, préfixe voulu). Une recherche sans ligne se dit « 0 ».

**Files :** ceux que le balayage désigne ; St10-14 désigne déjà `frontend/src/application/auth/useAuth.ts:17`
(« qui n'ont donc qu'un site d'import ») : la phrase dit ce que sont les réexports et pourquoi, sans « donc ».

- [ ] **Step 1 : recherches** (fichiers de secrets et `node_modules` exclus ; périmètre `.claude docs frontend/src
  frontend/tests backend/src backend/tests tools`) :
  1. Noms disparus : `grep -rn -e beginBackofficeLoad -e loadsInFlight -e refusalScope -e "'landing'" -e "'landed'" -e lockTranslation -e translationRequests -e LOCALE_LOCKED_EDITING_TEXT -e LOCKED_BY_PROPOSAL_TEXT -e expressionsOfTemplate -e isApiPath`.
  2. Vocabulaire de la fenêtre : `grep -rn -e "chargement en vol" -e "prolonge" -e "fin du chargement" -e "landing load" -e "load window" -e "during the landing"`.
  3. Préfixes larges (C10-6, Sp10-5) : `grep -rnE "str_starts_with\(\\\$[a-zA-Z]+, '/api'\)|'/api' === "` — chaque ligne
     gardée commentée ou dans `CanonicalPath`.
  4. Récits au passé (St10-5) dans les fichiers que `git diff --name-only 80b5832d...HEAD -- frontend backend tools`
     liste : `grep -nE "(depuis R[0-9]+|n'est plus|n'a plus|restait|courait|lisait|était là)"` ; la référence `R…` qui
     **justifie** une règle reste.
  5. Repères de processus dans les noms de tests (St10-15) : `grep -rnE "(it|describe|test)\(['\"\`].*(Review Focus|Sp[0-9]+-[0-9]+|CR[0-9]+-[0-9]+|St[0-9]+-[0-9]+|\bC[0-9]+-[0-9]+)" frontend/tests`
     et `grep -rnE "function test.*(ReviewFocus|Sp[0-9]|CR[0-9])" backend/tests` ; chaque nom ajouté ou touché par une
     série de #748 est réécrit sans identifiant de constat (le ticket, la tâche ou la décision restent).
  6. Longueurs (St10-4) : `awk 'length>140 {print FILENAME": "FNR}'` sur chaque fichier que
     `git diff --name-only 778d114f...HEAD -- frontend backend tools` liste ; chaque ligne rendue que la série a
     ajoutée ou touchée est coupée, les autres sont listées comme antérieures.
  7. Règles touchées par la série : relire en entier, avec `Read`, `frontend-session-refus.md`, `frontend-session.md`,
     `frontend-backoffice.md` (« Translation assistant » et les réglages), `security-authentication.md`,
     `security-authentication-sessions.md` (puce de la garde d'autorisation et du smoke), `deploiement-nginx.md`
     (paragraphe des smokes) : toute phrase fausse sur le code de HEAD est **remplacée**.
  8. Le docblock d'en-tête de chaque fichier de `src/` modifié par R112 à R119 (`git diff --name-only 39498f33...HEAD -- frontend/src backend/src tools`)
     relu contre son code.
- [ ] **Step 2 : verts** — `tools/check-claude-rules.sh; echo "exit=$?"` ; taille de chaque règle touchée par la série
  notée, toutes < 50 000 octets, `backoffice-api.md` à ses 39 834 octets ; `make front-test; echo "exit=$?"` si un
  fichier de `frontend/` a changé, `make back-test; echo "exit=$?"` si un de `backend/`, les tests hors ligne si un de
  `tools/`.
- [ ] **Step 3 : commit** (seulement si le balayage a changé un fichier) — `docs: le balayage de la dixième relecture de #748`.
  Sinon, le rendu dit « rien à changer » et donne la liste des recherches et de leurs résultats.

### Traçabilité des constats

| Constat | Traitement |
|---|---|
| C10-1 | R115 (décisions 1 et 2 ; pas de rotation, tâche 12) |
| St10-1 | R112 |
| St10-2 ≈ CR10-3 | R112 (décision 3, Q1) |
| CR10-1 = C10-8 | R112 (le compteur disparaît ; cas « une lecture qui ne répond pas ») |
| CR10-2 | R112 (`beginBackofficeLoad` et son appel hors `try` disparaissent ; vérifié par le grep du Step 6) |
| Sp10-1 = C10-9 | R112 (intégration `AdminLayout.sessionEnded.spec.ts`, vraie page à deux listes) |
| CR10-4 = C10-10 | R114 |
| St10-6 | R114 |
| CR10-6 = C10-2 | R116 (décision 4) |
| C10-11 | R119 |
| CR10-5 = Sp10-12 = C10-11 bis | R119 |
| CR10-8 = C10-7 = St10-3 | R117 (arbitrage) |
| C10-6 | R117 (commentaire, cas `/apix`, règle) ; R120 recherche 3 |
| CR10-9 = Sp10-11 = St10-10 | R118 |
| CR10-7 | R118 |
| St10-4 = Sp10-4 | R112 (`AccessRefusedNotice.spec.ts`), R116 (`audit-prod.test.sh`, `smoke-http.test.sh`), R117 (`AuthenticationFlowTest.php`), R120 recherche 6 |
| St10-5 + Sp10-5 | R116 (`audit-prod.test.sh`), R117 (`ApiRouteExposureTest.php`), R114 (`AdminAboutPage.spec.ts`), R120 recherches 3 et 4 |
| St10-8 = Sp10-3 | R114 (constantes, « verrou de la langue »), R119 (`sources`) |
| Sp10-2 | R114 |
| Question de R114 (décision de l'auteur, 2026-10-31) | R114b |
| St10-7 | R119 |
| St10-9 | R118 |
| St10-12 | R119 |
| St10-13 | R116 |
| CR10-10 | R113 |
| C10-3 = Sp10-7 | R116 (garde de source ; `run_traced`) |
| C10-4 | R116 |
| C10-5 | sans suite (décision 5) |
| C10-12 | R117 |
| Sp10-6 | R117 |
| St10-11 | R114 |
| St10-14 | R113 (`AdminAboutSettingsForm.vue`), R116 (`smoke-http.sh`), R120 (`useAuth.ts`) |
| St10-15 | R112 (noms de ses specs), R116 (`run` en ligne), R120 recherche 5 |
| Sp10-8 | sans suite (décision 5) |
| Sp10-9 | R119 (le repli de `sessionStateEncapsulation.spec.ts`) ; le reste acté dans la vérification ci-dessus, sans effet |
| Sp10-10 | la phrase d'ouverture de cette section (convention des séries) |

### Constats écartés, avec leur raison

- **C10-5, Sp10-8** : écartés par l'auteur le 2026-10-31 (décision 5).
- **Sp10-9, sauf le repli** : des écarts au plan sans effet sur le comportement (dépôt de traduction par montage,
  `transition()` inexistant, `classes` en `array<string, true>`) ; actés ici, rien à corriger.

---


## Corrections de la onzième relecture (2026-10-31)

R112 à R120 et R114b sont faites (après 39498f33, jusqu'à 55199dca ; constats de la onzième relecture : 5fd65d6e). Leurs
cases restent non cochées, par convention des séries : cette phrase dit qu'elles sont faites.

Quatre relectures du diff 13d4de17...55199dca (`/code-review medium` CR11-*, audit de sécurité C11-*, axe Standards
St11-*, axe Spec Sp11-*) : aucun critique ni haut, trois moyens (CR11-1, St11-3, St11-4). Constats complets et
décisions : `tasks/relectures-748/relecture-11-constats.md` (la source de vérité). Tous sont traités ici par R121 à
R136, avant les tâches 11 et 12 : corrigés, ou écartés par l'auteur (« Sans suite », repris ci-dessous). Les points de
contrôle au navigateur et le corps de PR de la série sont ajoutés aux tâches 11 et 12 dans le commit de ce plan. Les
sections passées restent l'archive de leur moment.

### Décisions de l'auteur (2026-10-31)

1. **CR11-1 ≈ C11-9 : règle + garde.** `frontend-session-refus.md` dit qu'une page du backoffice lance son chargement
   dans `setup` (ou un watcher `immediate`) ; une garde de source refuse un chargement (`load(`, appel de dépôt) dans un
   `onMounted` des pages et sections admin ; la limite d'un sous-composant rendu plus tard par un `v-if` reste écrite.
   R121.
2. **CR11-3 = C11-6 : un appel en vol.** Une nouvelle traduction reste refusée tant que la requête périmée par
   `discard()` (ou par un formulaire remplacé) n'est pas terminée : jamais plus d'un appel au modèle en vol par
   formulaire ; le bouton occupé après « Annuler » le dit par un texte d'aide FR/EN lié par `aria-describedby`. R125.
3. **CR11-5 : prose en lecture seule.** Pendant une traduction, les champs de prose du formulaire sont en `readonly`
   (pas `disabled` : lisibles et focusables), avec un texte d'aide lié par `aria-describedby` ; huit formulaires par
   entrée + réglages « À propos ». C11-7 (b), sans décision : la suppression de l'entrée en édition et le changement de
   langue d'un formulaire en création périment la traduction en vol. R124 (C11-7), R126 (lecture seule).
4. **Identifiants de constat : permis dans les commentaires** (ils renvoient aux constats archivés), **interdits dans
   les noms de tests** ; `brief-commun-agents.md` est corrigé en ce sens dans le commit de ce plan. St11-18 et Sp11-5
   n'appellent pas d'autre correction ; St11-1 et Sp11-1 (noms de tests) se corrigent. R135.
5. **Sans suite** : Sp11-4 (lignes de 121 à 129 de `deploiement-nginx.md`).
6. **Toujours valables** : C5-1, C5-2, C7, CR6-6, CR7-2, la limite de R94, les « Sans suite » précédents (C10-5,
   Sp10-8, St9-17, Sp9-8, C9-8, St9-11), pas de rotation de la Basic Auth de préprod (C10-1) ; critère d'arrêt **tout
   jusqu'à zéro** : tous les autres constats se corrigent sans décision.

### Vérification de chaque constat dans le code (HEAD 5fd65d6e)

- **CR11-1 ≈ C11-9, confirmé, sans effet aujourd'hui.** Les seuls `onMounted` de `presentation/pages/admin` sont ceux
  d'`AdminUsersPage.vue:83` (deux `addEventListener`) ; chaque page charge dans son `setup` (`useAdminLoad`) ou un
  watcher `immediate` (`AdminAboutSettingsForm.vue:103-111`). `AccessRefusedNotice.vue:92` avance l'époque dans son
  `onMounted`, rendu avant `<RouterView />` : un `onMounted` de page passe après lui. Aucune garde ne l'empêche.
- **CR11-2, confirmé.** `markBackofficeAccessRefused` (`backofficeAccessRefusal.ts:110-131`) garde l'avis pour toute
  lecture dont `sentIn.epoch !== epoch`, sans borne : aucun délai sur `fetch`, et rien ne clôt le chargement de la page
  d'arrivée quand la personne relance une lecture.
- **CR11-3 = C11-6, confirmé.** `useAdminTranslation.ts:89-93` : `discard()` appelle `requests.begin()` et remet
  `isTranslating` à faux ; l'appel au dépôt (l. 69) continue sans `AbortSignal`, et la garde de la l. 60 laisse partir
  la traduction suivante.
- **St11-3, confirmé.** `discardTranslation()` en tête de `resetForm`, `startEdit` et `startCreateVersion` dans les
  sept fichiers (huit formulaires : `AdminIncidentsPage.vue:182`, `:198`, `:225`, et de même ailleurs), et dans le watcher
  de langue des réglages (`AdminAboutSettingsForm.vue:107`).
- **CR11-5, C11-7 (= CR11-4), confirmés.** Les champs de prose restent modifiables pendant la traduction
  (`BaseTextInput`/`BaseTextarea` sans `readonly`) ; `handleTranslate` (`AdminIncidentsPage.vue:276-289`) remplace chaque
  champ de `PROSE_FIELDS` à la réponse. `handleDelete` (l. 291-297) ne touche ni le formulaire ni la traduction ; le
  sélecteur de langue d'un formulaire en création (`:disabled="lock.isEntryLocked"`, libre hors verrou) ne périme rien.
- **CR11-6 = C11-5 (+ St11-17), confirmé.** `AdminAboutSettingsForm.vue:53` : `useAdminTranslation()` sans
  `isRefused` ; son bouton (`:disabled="!hasProseToTranslate || isAnySaving || isLocked"`) ne regarde pas
  `isProposalShown`. Les huit formulaires passent `isRefused: () => lock.isProposalLocked` seulement, le brouillon
  d'ordre (`isOrderDirty`) n'étant refusé que par le `:disabled` du bouton.
- **C11-1, confirmé.** `smoke-http.sh:231-250` : `smoke_fetch` pose `-K` quel que soit le schéma de `url`.
- **St11-4, confirmé.** Quatre appelants écrivent `smoke_write_auth_config AUDIT_BASIC_AUTH || exit 2` puis
  `smoke_require_auth_config AUDIT_BASIC_AUTH || exit 2` (`audit-prod.sh:72-73`, `smoke-dead-session-cookie.sh:80-81`,
  `smoke-login-throttling.sh:65-66`, `smoke-mcp.sh:94-95`) ; la seconde lit `SMOKE_AUTH_CONFIG` laissé par la première.
- **C11-8, confirmé.** `smoke-http.sh:139` n'exige qu'un `:` : `:`, `u:` et `:p` écrivent un fichier.
- **C11-2, confirmé.** `audit-prod.sh:74` ajoute `-K` à `CURL_OPTS`, lu avec `-L` l. 99, 150, 169, 253, 393, 412, 422,
  sans `--proto-redir`.
- **C11-3, confirmé.** `smoke-http.test.sh:518-530` cherche `\$\{?AUDIT_BASIC_AUTH|!AUDIT_BASIC_AUTH` : une liste
  d'interdits. Lignes non commentées qui nomment la variable aujourd'hui : `audit-prod.sh:64` (`[[ -v ]]`), `:72-73`,
  les deux appels de chaque smoke, et le message de `smoke-dead-session-cookie.sh:128` (le nom dans une chaîne).
- **CR11-9, confirmé.** `audit-prod.sh:111-116` imprime le code de chaque sonde sans le juger : un 401 sur
  `https://www.` se lirait sans explication.
- **St11-16, confirmé.** La plomberie de `tools/lib/smoke-http.sh` est décrite dans `deploiement-nginx.md:117-135` —
  c'est la bonne règle (ses `paths:` couvrent la bibliothèque et ses tests, ceux de `release.md` non) — et
  l'explication de #750 est écrite dans `audit-prod.sh:24-28`, `:107-110` et `deploiement-nginx.md:133-134`.
- **CR11-7 (+ St11-11), confirmé.** `AdminAboutSettingsForm.vue:63` (`isLoaded.value &&`) ; l'avis
  (`v-if="proposal && isProposalShown"`) est sous le `<form v-else>` rendu une fois `isLoaded` ; le test de R113 lit
  `vm.isProposalShown` par `as unknown as`.
- **CR11-8 = St11-12, confirmé.** `ApiPlatformOperationsCarryNoSecurityTest.php:146-147`, `:158-161` (copie du tableau
  par décomposition), `collectNested()` (l. 417) et `countLinks()` (l. 445) parcourent chacun `uriVariablesOf()` et
  testent chacun `instanceof Link`.
- **C11-4, St11-13, confirmés.** `CanonicalPath::isPathUnder()` (l. 78) est publique ; aucun fichier de `src/` hors
  de `CanonicalPath.php` n'appelle `getPathInfo()` ni `isPathUnder()` aujourd'hui (le seul autre `getPathInfo()` est un
  commentaire de `CsrfCookieRequestSubscriber.php:31`), et rien ne l'interdit.
- **CR11-10, confirmé.** `sourceText.ts:163-174` : `wrapperOf` essaie de lire `(texte)` ; Vue (`vOn`) juge par
  `isMemberExpression` / `isFnExpression` puis par la présence d'un `;`. `@vue/compiler-core` exporte
  `isMemberExpressionBrowser(exp)` et `isFnExpressionBrowser(exp)` (`compiler-core.d.ts:1042`, `:1045`), sur le nœud
  même que lit le lecteur ; il n'est pas une dépendance déclarée du frontend (`vue` en dépend, 3.5.44).
- **St11-8, confirmé.** `sourceText.ts:224` (`5 === node.type`) ; `NodeTypes` vient de `@vue/compiler-core`
  (`compiler-core.d.ts:153`), que `vue/compiler-sfc` ne réexporte pas ; `templateExpressionsOf` (l. 135) rend aussi la
  valeur d'un attribut statique (l. 218).
- **St11-5 = Sp11-8, confirmé.** `AccessRefusedNotice.vue:35` : « **Effacée** (le périmètre de refus avance,
  `readRefusalScope()`) » nomme le lecteur pour l'effaceur (`clearBackofficeAccessRefusal`).
- **St11-6, confirmé.** `backofficeAccessRefusal.spec.ts:17` (`readSentNow()` ne fait qu'appeler
  `readRefusalScope()`) ; le cas « une lecture … qui ne répond pas » relève une estampille qu'il n'utilise pas.
- **St11-7, St11-15, Sp11-7, confirmés.** `tests/support/entryFormLock.ts:116-118`, `:139`, `:145`, `:259`, `:275`,
  `:293`, `:312`, `:328` (cinq `findAll('form')[form].find('[role="status"]')` et plus), `translateButton` et
  `translateEntryButton` (l. 65, 118), `mountLocked` (l. 116) ; docblock coupé (l. 106-107) ; commentaire des l. 239-241 ;
  `AdminCaseStudiesPage.spec.ts:259-260` ; `useEntryFormLock.spec.ts:11` (`proposalSource`).
- **St11-9, confirmé.** `sourceText.spec.ts:168` et `:197-201` posent la même assertion sur `a(); b('omit')`.
- **St11-10, confirmé.** `earlierSaveFr` / `accessRefusedFr` dans `AccessRefusedNotice.spec.ts:22`, `:237` et
  `AdminLayout.sessionEnded.spec.ts:616-619` ; `mountAdminQuality` (l. 567) refait `mountAdminCaseStudies` (l. 75).
- **St11-14, Sp11-10, confirmés.** `audit-prod.test.sh:56-68` (`run() { run_as "$1"; }`) ; le cas « vide » recopié dans
  `smoke-dead-session-cookie.test.sh`, `smoke-login-throttling.test.sh:212-220`, `smoke-mcp.test.sh:302` ;
  `audit-prod.test.sh:216` ne montre plus `TMPDIR` à l'échec.
- **Sp11-2, confirmé.** `AppLayout.spec.ts:168` (« Le bandeau n'a plus son écouteur »).
- **Sp11-9, confirmé.** Le cas d'intégration de R112 (`AdminLayout.sessionEnded.spec.ts`, vers la l. 665) ne lit le
  texte qu'après les deux refus.
- **St11-1, Sp11-1, confirmés.** Les noms listés par Sp11-1 portent un identifiant de constat (`LoginPage.spec.ts:144`
  A3-10, `AppLayout.spec.ts:298` A3-13, `AdminAboutPage.spec.ts:1203` CR2, `:1340` et `:1527` F11,
  `signInLocations.spec.ts:57`, `:84` et `SignInAgainLink.spec.ts:76` F8, `adminGuard.spec.ts:262` B/F2,
  `noOmittedCredentials.spec.ts:88` C1) ; la recherche large en rend d'autres, dont une partie antérieure à #748
  (`#650, S2`, `#650, F-I1`, `audit A7`, `#647, F1`) : seuls les noms ajoutés ou touchés par une série de #748 (lignes
  dont `git blame` donne un commit de `0e581b42..HEAD`) changent. St11-1 relève aussi des identifiants de **tâche**
  (« (#748, R119) ») qu'il dit tolérés : le brief les permet, Q3 le rend à l'auteur.
- **St11-2 = Sp11-3, confirmé** (`awk 'length>140'`) : `frontend-session-refus.md:26` (216) et `:79` (143),
  `frontend-session.md:151` (142), `security-authentication-sessions.md:177` (176). Les règles portent d'autres lignes
  longues, antérieures (tables, puces) : le balayage R136 ne coupe que celles qu'une série de #748 a écrites.
- **St11-18, Sp11-5** : décision 4, rien à changer dans le code. **Sp11-6** : `const sources` de `colorClasses.spec.ts`
  et `signInLocations.spec.ts` nomme bien des sources de fichiers ; acté, rien à corriger. **Sp11-4** : sans suite.

### Options comparées

**St11-3 — comment une traduction en vol apprend que son formulaire a été remplacé** (Q1, rendue à l'auteur ; le plan
suit (A)).

| Option | Pour | Contre |
|---|---|---|
| (A) **le composable juge l'identité du formulaire** : la page lui donne `formIdentity: () => unknown` (l'entrée éditée, la langue, le groupe source, la prose envoyée) ; une traduction dont le formulaire n'a plus l'identité relevée à l'envoi est périmée (réactif : `isTranslating` retombe dès le remplacement, la réponse rend `null`) — **recommandée** | les 24 appels disparaissent ; un geste futur qui remplace le formulaire (ou change sa langue, C11-7) périme la traduction sans y penser ; avec la prose en lecture seule (R126), seule une remise du formulaire change l'identité | un getter par formulaire (neuf) ; une comparaison sérialisée (`JSON.stringify`) à chaque lecture d'`isTranslating` ; une suppression de l'entrée éditée ne change pas l'identité : `discard()` reste pour elle |
| (B) **garder les appels explicites** et ajouter une garde de source : toute fonction d'une page qui affecte `editingId.value`, `form.locale` ou un champ de `PROSE_FIELDS` appelle `discardTranslation()` avant | aucun mécanisme nouveau ; le code reste celui de R114b | la recopie reste (Shotgun Surgery tenue par une garde au lieu d'être retirée) ; la garde lit des noms (`editingId`, `form`) que chaque page choisit, fragile au renommage |

**CR11-2 — combien de temps une lecture de la page d'arrivée garde l'avis** (Q2, rendue à l'auteur ; le plan suit (B)).

| Option | Pour | Contre |
|---|---|---|
| (A) **écrire la limite** : le texte reste vrai (`earlierSave` est monotone, une lecture d'avant le montage ne garde qu'un avis encore vrai) ; règle et docblock disent qu'aucune durée ne borne l'avis | rien ne change dans le code ; R112 reste tel quel | la personne qui a relancé une lecture (« Réessayer ») et dont une lecture lente de l'arrivée est refusée ensuite relit l'ancien avis au lieu du refus courant |
| (B) **la première lecture envoyée après le montage clôt le chargement de la page d'arrivée** : une lecture d'avant le montage refusée ensuite rend `common.accessRefused` — **recommandée** | un geste de la personne (une lecture qu'elle relance) l'emporte, comme partout ailleurs ; la borne est un fait (un envoi), pas un délai | un état de plus dans le module (`isLandingOpen`), avancé par l'estampille d'une lecture : la fonction qui estampille n'est plus un simple lecteur (son nom le dit, St11-5) |

**St11-8, CR11-10 — d'où le lecteur de gabarit tient les règles de Vue** (Q4, rendue à l'auteur ; le plan suit (A)).

| Option | Pour | Contre |
|---|---|---|
| (A) **déclarer `@vue/compiler-dom` en `devDependencies`, au même intervalle que `vue` (`^3.5.44`)** et importer `NodeTypes`, `isMemberExpressionBrowser`, `isFnExpressionBrowser` (réexportés de `compiler-core`) — **recommandée** | les deux constats sont tenus à la lettre : le type de nœud et le jugement d'un `v-on` sont ceux de Vue ; `vue` dépend déjà de ce paquet, `npm` le déduplique (aucun octet de plus au `node_modules`) | `package.json` et `package-lock.json` changent ; une montée de `vue` qui sortirait de l'intervalle désalignerait les deux (même intervalle : `npm` le refuse bruyamment) ; le manifeste de la veille (`app:watch:build-manifest`) liste une dépendance de développement de plus |
| (B) **une constante nommée et un essai de syntaxe gardé** : `INTERPOLATION = 5`, épinglée par un test qui lit le type d'un `{{ a }}` analysé ; le `v-on` reste jugé par l'essai `(texte)`, sa différence avec Vue écrite et épinglée | aucune dépendance déclarée | CR11-10 reste vrai à la lettre (la promesse « comme Vue » ne tient pas pour un objet en gestionnaire avec argument) ; un nombre recopié de Vue |

**CR11-6 — les réglages « À propos » pendant une proposition affichée** (Q5, rendue à l'auteur ; le plan suit (A)).
(A) **refuser et désactiver** « Proposer la version XX » pendant une proposition affichée, avec un texte d'aide lié
(`admin.about.settingsProposalLockedHelp`, rendu seulement pendant elle), comme les formulaires par entrée (R93) —
**recommandée** : une seconde traduction bascule la page et la proposition non enregistrée est perdue sans geste
d'abandon ; coût : une clé FR/EN et un texte de plus. (B) refuser seulement ce que le bouton refuse déjà
(`isAnySaving || isLocked`), le bouton restant libre pendant une proposition : rien de nouveau à lire, mais la perte
silencieuse de la proposition reste (le cas Sp7-1 sur les réglages).

Écartées (décision 2) : un `AbortSignal` passé au dépôt (un `fetch` interrompu côté client ne retire pas l'appel au
modèle déjà parti côté serveur : il n'économise rien du coût, que la décision borne) ; garder `isTranslating` vrai
jusqu'à la réponse périmée (le formulaire vidé par « Annuler » resterait en lecture seule, R126).

### Choix de conception tranchés

1. **Décision 1 (R121).** `tests/support/sourceText.ts` exporte `syntaxTreeOf(source: string): ts.SourceFile` (l'actuel
   `treeOf`, renommé) et `callsInsideHook(source: string, hook: string): string[]` : le texte du *callee* de chaque appel
   écrit dans une fonction passée à `hook(…)` (fonctions imbriquées comprises), dans l'ordre du source
   (`['document.addEventListener', 'load', 'repository.list']`). Garde
   `frontend/tests/presentation/pages/admin/noLoadInOnMounted.spec.ts`, sur les clés de `SRC_SCRIPTS` qui commencent
   par `presentation/pages/admin/` : refusé, un *callee* dont le dernier segment est `load`, `reload`, commence par
   `load` ou finit par `Load`, est `list`, `get`, `fetch`, `translate`, ou porte un nom local que le fichier lie à la
   clé `load` d'une déstructuration (`const { load: refresh } = useX()`). Garde-fous : au moins douze fichiers lus, au
   moins un `onMounted` trouvé (celui d'`AdminUsersPage.vue`).
2. **Q1 (A) (R124).** `UseAdminTranslationOptions.formIdentity?: () => unknown` : relevée (`JSON.stringify`) à
   l'envoi ; `isTranslating: Readonly<Ref<boolean>>` est vrai tant que la traduction courante est en vol, n'a pas été
   périmée par `discard()` et que `JSON.stringify(formIdentity())` vaut l'identité relevée ; sa réponse rend `null`
   sinon. `discard()` reste (la suppression de l'entrée éditée). Chaque formulaire passe
   `formIdentity: () => ({ entry: editingId.value, locale: form.locale, group: sourceGroup.value, prose: collectProseFields(form, PROSE_FIELDS) })`
   (Qualité : `editingPrincipleId` / `editingTraitId` et leurs champs ; réglages : `{ locale: props.locale, prose: … }`).
   Les vingt-quatre `discardTranslation()` des remises, `startEdit…` et `startCreate…Version`, et celui du watcher de
   langue des réglages, sont retirés ; `handleDelete…` appelle `discard()` quand l'entrée supprimée est celle en
   édition (`entry.id === editingId.value`).
3. **Décision 2 (R125).** `UseAdminTranslationResult.isDiscardPending: Readonly<Ref<boolean>>` : vrai tant qu'un appel
   périmé (par `discard()` ou par l'identité) n'a pas répondu. `translate()` rend `null` sans requête si
   `isTranslating`, `isDiscardPending` ou `isRefused?.()` est vrai : jamais plus d'un appel au dépôt en vol par
   instance. `TranslateEntryButton` gagne les props `isDiscardPending: boolean` (défaut `false`) et `hintId: string`
   (requise), passe à `inheritAttrs: false` et deux racines : le bouton (désactivé si `disabled || isTranslating ||
   isDiscardPending`, `aria-describedby` = celui reçu suivi de `hintId` pendant l'attente, par `describedBy`) et
   `<p v-if="isDiscardPending" :id="hintId" class="form-text mb-0">` portant `admin.translation.discardPending`.
   FR : « La traduction annulée se termine côté serveur : ce bouton revient dès sa réponse, qui ne change rien au
   formulaire. » EN : “The cancelled translation is still finishing on the server: this button comes back as soon as it
   answers, and the answer changes nothing in the form.”
4. **Décision 3 (R126).** Composant `presentation/ui/admin/TranslationReadonlyHint.vue` (`id: string`, `shown:
   boolean`) : `<p v-if="shown" :id class="form-text mb-3">{{ t('admin.translation.proseReadonly') }}</p>`, rendu sous
   le bouton de traduction. FR : « Traduction en cours : les champs de texte sont en lecture seule jusqu'à la réponse.
   Rien de ce qui est écrit n'est perdu. » EN : “Translation in progress: the text fields are read-only until the answer.
   Nothing written is lost.” Chaque champ de `PROSE_FIELDS` (et de `PRINCIPLE_PROSE_FIELDS`, `TRAIT_PROSE_FIELDS`, des
   réglages) reçoit `:readonly="isTranslating"` (passé par `$attrs`, `inheritAttrs: false` des `Base*`, rien à y
   changer) et `:aria-describedby="describedBy(<aide existante>, proseReadonlyHintId)"`, où `proseReadonlyHintId` vaut
   l'identifiant du texte pendant la traduction et `undefined` sinon (aucune référence pendante).
5. **CR11-6, Q5 (A) (R127).** Les huit formulaires : `isRefused: () => lock.isProposalLocked || isOrderDirty.value`
   (Qualité : `isAnyOrderDirty` de la page, ou ses deux brouillons). Réglages : `isRefused: () => isAnySaving.value ||
   props.isLocked || isProposalShown.value` ; le `:disabled` du bouton gagne `|| isProposalShown` et son
   `aria-describedby` le texte `admin.about.settingsProposalLockedHelp` (FR : « Verrouillé par la proposition de
   traduction affichée : enregistrez-la, ou changez de langue pour l'abandonner. » EN : “Locked by the translation
   proposal shown: save it, or switch language to discard it.”), rendu seulement pendant elle.
6. **Q2 (B) (R122).** `backofficeAccessRefusal.ts` : `readRefusalScope()` devient `stampBackofficeRead():
   ReadRefusalScope` (St11-5 : un verbe, puisqu'elle a un effet) ; `let isLandingOpen = false`, mis à vrai par
   `settleBackofficeAccessRefusalsOnMount` après l'avance de l'époque, mis à faux par la première
   `stampBackofficeRead()` appelée sous l'époque courante (après le montage) ; une lecture refusée garde
   `earlierSave` si `sentIn.epoch !== epoch && isLandingOpen`. `clearBackofficeAccessRefusal()` le remet à faux,
   `resetBackofficeAccessRefusalForTests()` aussi. `stampAtSend` appelle `stampBackofficeRead()`.
7. **St11-4, C11-8, C11-1 (R133).** `smoke-http.sh` : `smoke_use_auth_config <NOM>` écrit et exige en une fonction
   (validation du nom, `smoke_write_auth_config`, puis le jugement du vide) ; `smoke_require_auth_config` devient
   `_smoke_require_auth_config` (privée, appelée par elle seule) ; les quatre appelants écrivent
   `smoke_use_auth_config AUDIT_BASIC_AUTH || exit 2`. `smoke_write_auth_config` refuse une valeur dont l'utilisateur
   est vide (motif `?*:*` non satisfait : `:`, `:p`) ou le mot de passe vide (`u:`) : « <NOM> : Basic Auth sans
   utilisateur ou sans mot de passe (utilisateur:mot_de_passe attendu), refusée », retour 2, jugé sous `set +x`.
   `smoke_fetch` sort 2, curl non appelé, quand `SMOKE_AUTH_CONFIG` est posé et `url` ne commence pas par `https://` :
   « <libellé> : Basic Auth refusée sur une URL non https:// — rien de vérifié ».
8. **C11-3 (R133).** La garde de source de `smoke-http.test.sh` devient une liste d'autorisation : toute ligne non
   commentée des quatre scripts qui contient le mot `AUDIT_BASIC_AUTH` est refusée, sauf `if [[ -v AUDIT_BASIC_AUTH ]];
   then` et `smoke_use_auth_config AUDIT_BASIC_AUTH || exit 2` (formes exactes, blancs de tête admis). Le message de
   `smoke-dead-session-cookie.sh:128` dit « la Basic Auth de l'ingress (secret de préprod absent ou faux ?) ».
9. **C11-2, CR11-9, St11-16 (R134).** `audit-prod.sh` : `CURL_OPTS+=(-K "$SMOKE_AUTH_CONFIG" --proto-redir =https)` ;
   dans la boucle « Redirections », un code `401` est suivi de « (l'hôte demande la Basic Auth : la sonde part sans
   elle, #750) ». Le commentaire de `CURL_OPTS_ANONYMOUS` ne réexplique plus #750 : il nomme ses deux lecteurs et renvoie
   au commentaire de la boucle, seul à l'expliquer dans le script ; la règle garde sa phrase.
10. **CR11-8 (R131).** `final readonly class CompiledNestedReading` (`found: list<string>`, `links: int`), dans
    `backend/tests/Support/` ; `readNested(Metadata $element, string $context): CompiledNestedReading` remplace
    `collectNested()` et `countLinks()` (un parcours de `uriVariablesOf()`, un seul `instanceof Link`) ; l'appelant
    ajoute par `array_push($found, ...$nested->found)`.
11. **C11-4, St11-13 (R132).** Garde `backend/tests/Shared/Infrastructure/Http/PathIsDecidedCanonicallyTest.php` : par
    jetons (`PhpToken::tokenize`, commentaires écartés), aucun fichier de `backend/src` hors de `CanonicalPath.php`
    n'appelle `getPathInfo(` ni `isPathUnder(` ; garde-fou : au moins un appel de `CanonicalPath::of(` trouvé.
    Docblock d'`isPathUnder()` : « hors des tests, une requête passe par `isUnder()` (le chemin décodé par `of()`) ;
    `PathIsDecidedCanonicallyTest` le tient ».
12. **St11-8, CR11-10, Q4 (A) (R130).** `templateExpressionsOf` → `templateFragmentsOf`, `SRC_TEMPLATE_EXPRESSIONS` →
    `SRC_TEMPLATE_FRAGMENTS` ; `NodeTypes.INTERPOLATION` remplace `5` ; `wrapperOf` pour `on` avec argument :
    expression si `isMemberExpressionBrowser(exp) || isFnExpressionBrowser(exp)`, sinon corps de fonction si
    `exp.content` contient `;`, sinon l'expression `($event) => (texte)` — la règle de `vOn` de Vue 3.5, nommée dans le
    docblock.

**Questions à l'auteur** (le plan suit chaque recommandation ; une autre réponse ne change que la tâche nommée). **Réponses de l'auteur
(2026-10-31) : Q1 (A) `formIdentity`, Q2 (B) borner, Q3 garder les identifiants de tâche, Q4 (A) déclarer `@vue/compiler-dom`,
Q5 (A) verrouiller — les cinq recommandations, le plan ne change pas.**

- **Q1 (R124, St11-3) : l'identité du formulaire ou les appels gardés.** Recommandé : **(A)**, `formIdentity` — les
  24 appels disparaissent et un geste futur est couvert sans y penser ; son coût : un getter par formulaire et
  `discard()` gardé pour la suppression. (B) changerait R124 en : appels gardés, `handleDelete` et le sélecteur de
  langue en création gagnent les leurs, plus une garde de source sur les affectations.
- **Q2 (R122, CR11-2) : borner l'avis par la première lecture relancée, ou écrire la limite.** Recommandé : **(B)** —
  une lecture relancée par la personne l'emporte ; son coût : un état de plus dans le module. (A) changerait R122 en :
  `readRefusalScope()` renommée `currentReadScope()` (St11-5), une phrase de règle et de docblock, aucun état.
- **Q3 (R135, St11-1) : les identifiants de tâche dans les noms de tests.** Le brief les permet (« le ticket, une
  tâche ou une décision ») et St11-1 les dit tolérés. Recommandé : **les garder** (ils renvoient au plan archivé, comme
  un identifiant de constat dans un commentaire) ; les retirer ferait de R135 un renommage de plusieurs centaines de
  noms (`grep -rcP "\(#748, R[0-9]+" frontend/tests` le chiffre avant d'y toucher).
- **Q4 (R130, St11-8 + CR11-10) : `@vue/compiler-dom` déclaré, ou une constante nommée.** Recommandé : **(A)** — les
  deux constats tenus à la lettre, aucun octet de plus installé ; son coût : `package.json` et `package-lock.json`.
  (B) changerait R130 en : constante épinglée par un test, l'écart du `v-on` avec Vue écrit dans le docblock et
  épinglé par un cas.
- **Q5 (R127, CR11-6) : verrouiller la traduction des réglages pendant une proposition affichée.** Recommandé :
  **(A)** — la perte silencieuse d'une proposition non enregistrée est le cas que R93 a fermé pour les entrées ; son
  coût : une clé FR/EN. (B) changerait R127 en : `isRefused` des réglages = `isAnySaving || isLocked` seulement.

### Review Focus (onzième relecture)

1. **« Annuler » pendant une traduction, puis « Proposer la version XX » aussitôt** (clic, Entrée, `translate` émis) :
   aucune seconde requête tant que la première n'a pas répondu ; le bouton est désactivé et décrit par le texte de
   l'attente ; à la réponse périmée, rien ne change au formulaire et le bouton revient. Test : R125.
2. **Une saisie pendant la traduction** : chaque champ de prose est `readonly` et décrit par le texte d'aide, le focus
   y entre et la touche Tab en sort ; à la réponse, la proposition remplace la prose ; à un échec (429, 503), la prose
   d'origine est intacte et de nouveau modifiable, le texte d'aide disparu. Test : R126.
3. **Supprimer l'entrée en édition, ou changer la langue d'un formulaire en création, pendant une traduction** : la
   réponse ne pose aucune proposition, aucun avis `role="status"`. Test : R124.
4. **Une page future qui charge dans son `onMounted`** (directement, ou par un nom local lié à `load`) : la garde
   rougit en nommant le fichier et l'appel. Test : R121.
5. **Un smoke ou l'audit avec une Basic Auth et une base `http://`, ou une valeur `:`/`u:`/`:p`** : sortie 2 avant
   tout appel de curl, le message dit pourquoi sans citer la valeur. Test : R133.

### Contraintes propres aux tâches R121 à R136

- Les **Global Constraints** et toutes les contraintes des séries précédentes valent. Exécution : **un agent par tâche,
  successifs dans l'ordre R121 → R136**, qui lit d'abord `tasks/relectures-748/brief-commun-agents.md`. **Pour R121 et
  suivantes, la section à lire en entier jusqu'à la première tâche est celle-ci** (« Corrections de la onzième
  relecture ») et le fichier de constats `tasks/relectures-748/relecture-11-constats.md` (le brief le dit, mis à jour
  dans le commit de ce plan). `superpowers:test-driven-development` : test écrit d'abord, **vu rouge pour la bonne
  raison** ; un test de couverture vert d'emblée est vu rouge par la mutation que sa tâche nomme, **restaurée depuis
  une copie du fichier faite avant la mutation et vérifiée par `cmp`** puis `git diff` vide sur le fichier muté.
  **Une mutation s'écrit en place par Python seulement** (`python3 -I` ; `open(p, 'r+')` ou `open(p, 'w')` sur le même
  chemin, qui garde l'inode), **jamais par l'outil Edit, `sed -i` ni `perl -pi`**. Après une écriture du code par Edit
  ou Write (pas une mutation), `docker compose restart frontend` (ou `backend`) avant de croire un test, en cas de
  doute. Refactoring pur : la suite existante comme filet, nombre de tests noté avant et après.
  **`superpowers:verification-before-completion` invoquée réellement** avant le rendu.
- **Cache de test backend vidé dans le conteneur, jamais sur l'hôte** : `docker compose exec -u dev backend rm -rf
  var/cache/test` après chaque mutation de configuration ou de service et après sa restauration.
- **Git : seuls `git add <fichiers>` et `git commit`** (et `git mv` / `git rm` pour un déplacement ou une
  suppression). Ni `reset` (pas même sans argument), ni `add -N`, ni `checkout`, ni `checkout-index`, ni `switch`, ni
  `restore`, ni `stash`, **ni `git commit --amend`** : une erreur de commit se corrige par un commit suivant. **Un
  commit par tâche**, fichiers nommés, message en français terminé par les lignes d'attribution que donne le brief.
- **Une docstring ou une règle qui décrit un comportement modifié se corrige dans le même commit** ; une phrase de règle
  se **remplace**, elle ne s'ajoute pas à côté ; un commentaire s'écrit **au présent** (ni « depuis R… », ni « n'est
  plus », ni « restait ») ; **un identifiant de constat est permis dans un commentaire** (décision 4), **jamais dans un
  nom de test** ; un nom de test peut citer le ticket, une tâche ou une décision (`#748, R125, décision 2`).
- **Lignes touchées ≤ 140 caractères**, mesurées par `python3 -I` (ou `awk 'length>140'`) sur chaque fichier touché,
  **règles de `.claude/rules/` comprises** (une ligne de table Markdown ne se coupe pas : le dire au rendu).
- **Aucun travail en arrière-plan au rendu** ; **chaque code de sortie se lit sans pipe** (`make front-test; echo
  "exit=$?"`, de même `front-lint`, `front-build`, `back-test`, `back-quality`, chaque test hors ligne et
  `tools/check-claude-rules.sh`).
- Frontend (R121 à R130, R135) : lire avec `Read` `.claude/rules/frontend.md`, `.claude/rules/frontend-a11y.md` et la
  règle de la zone (`frontend-session-refus.md`, `frontend-session.md`, `frontend-backoffice.md` ; `ai.md` avant de
  toucher `useAdminTranslation.ts`) ; test ciblé `docker compose exec -T frontend npm test -- <chemins>`, puis
  `make front-test`, `make front-lint`, `make front-build` ; toute chaîne nouvelle en FR et EN
  (`frontend/src/infrastructure/i18n/locales/{fr,en}.json`).
- Backend (R131, R132) : lire avec `Read` un fichier de `backend/tests/Security/`, `.claude/rules/backoffice-api.md` et
  `.claude/rules/security-authentication.md` avant d'écrire ; test ciblé `docker compose exec -u dev backend php
  bin/phpunit <chemin>`, puis `make back-test` et `make back-quality` ; `make back-style-fix` après un import ajouté.
- Outillage (R133, R134) : lire avec `Read` les scripts touchés et `.claude/rules/deploiement-nginx.md` ;
  `bash tools/tests/<script>.test.sh; echo "exit=$?"` pour chaque script touché ; shellcheck dans l'image épinglée du job
  `tools-tests` : `docker run --rm --entrypoint shellcheck -v "$PWD":/repo:ro -w /repo
  rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -x --severity=warning
  <fichiers>` (si l'image n'expose pas `shellcheck`, le noter et lancer celui de l'hôte, en le disant). Une valeur de
  test ne contient jamais le mot « secret » (un message attendu, si).
- Règles : `tools/check-claude-rules.sh` après chaque modification ; 50 000 octets par règle, taille de chaque règle
  touchée notée au rendu. Tailles au départ (HEAD 5fd65d6e) : `frontend-session-refus.md` 13 957,
  `frontend-session.md` 15 976, `frontend-backoffice.md` 29 329, `frontend-a11y.md` 13 050,
  `security-authentication.md` 10 047, `security-authentication-sessions.md` 22 279, `deploiement-nginx.md` 29 512 ;
  non touchées : `backoffice-api.md` **39 834**, `release.md` 7 045, `ai.md` 18 919.
- Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets (les exclure de tout
  `grep -r`) ; ne jamais nommer un compte réel. Pas de push, pas de PR.

### Fichiers (tâches R121 à R136)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `frontend/tests/support/sourceText.ts`, `sourceText.spec.ts`, `frontend/tests/presentation/pages/admin/noLoadInOnMounted.spec.ts` (créé), `.claude/rules/frontend-session-refus.md` | aucun chargement dans un `onMounted` admin | R121 |
| `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue` (docblock), leurs specs et celles qui estampillent à la main | la page d'arrivée close par la première lecture relancée ; le nom de l'estampille | R122 |
| `backofficeAccessRefusal.spec.ts`, `AccessRefusedNotice.spec.ts`, `AdminLayout.sessionEnded.spec.ts`, `frontend/tests/support/accessRefusalTexts.ts` (créé) | specs du refus : sans Middle Man, textes et montage écrits une fois, intégration lue à chaque annonce | R123 |
| `frontend/src/application/admin/translation/useAdminTranslation.ts`, les sept fichiers de formulaires par entrée, `AdminAboutSettingsForm.vue`, `useAdminTranslation.spec.ts`, `frontend/tests/support/entryFormLock.ts`, les six specs de formulaires, `AdminAboutPage.spec.ts` | identité du formulaire ; suppression et langue en création | R124 |
| `useAdminTranslation.ts`, `frontend/src/presentation/ui/admin/TranslateEntryButton.vue`, les neuf sites du bouton, `fr.json`/`en.json`, `TranslateEntryButton.spec.ts`, `entryFormLock.ts`, `AdminAboutPage.spec.ts` | un appel en vol, l'attente dite | R125 |
| `frontend/src/presentation/ui/admin/TranslationReadonlyHint.vue` (créé), les neuf formulaires, `fr.json`/`en.json`, `entryFormLock.ts`, `AdminAboutPage.spec.ts` | prose en lecture seule pendant la traduction | R126 |
| les neuf formulaires (`isRefused`), `AdminAboutSettingsForm.vue` (bouton), `fr.json`/`en.json`, `entryFormLock.ts`, `AdminAboutPage.spec.ts` | refus par programme = refus du bouton | R127 |
| neuf `isRefused`, `fr/en.json`, `entryFormLock.ts`, six specs, règle | `isRefused` complet ; texte exact | R127b |
| `AdminAboutSettingsForm.vue`, `fr.json`/`en.json`, `AdminAboutPage.spec.ts`, règle | bouton « Abandonner » des réglages | R127c |
| `AdminAboutSettingsForm.vue`, `AdminAboutPage.spec.ts` | une seule source de vérité pour l'avis des réglages | R128 |
| `frontend/tests/support/entryFormLock.ts`, `useEntryFormLock.spec.ts`, `AdminCaseStudiesPage.spec.ts`, `AppLayout.spec.ts`, `sourceText.spec.ts` | hygiène des specs | R129 |
| `frontend/package.json`, `frontend/package-lock.json`, `sourceText.ts`, `srcSources.ts`, `sourceText.spec.ts`, lecteurs de `SRC_TEMPLATE_EXPRESSIONS` | le lecteur de gabarit tient ses règles de Vue | R130 |
| `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`, `backend/tests/Support/CompiledNestedReading.php` (créé) | une passe sur les liens | R131 |
| `backend/src/Shared/Infrastructure/Http/CanonicalPath.php` (docblock), `backend/tests/Shared/Infrastructure/Http/PathIsDecidedCanonicallyTest.php` (créé), `.claude/rules/security-authentication.md` | aucun chemin brut hors de `CanonicalPath` | R132 |
| `tools/lib/smoke-http.sh`, `tools/{audit-prod,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp}.sh`, `tools/tests/{smoke-http,audit-prod,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp}.test.sh`, `tools/tests/lib/smoke-cases.sh` (créé), `.claude/rules/deploiement-nginx.md`, `.claude/rules/security-authentication-sessions.md` | une fonction de Basic Auth, refus `http://` et valeur creuse, liste d'autorisation, tests sans recopie | R133 |
| `tools/audit-prod.sh`, `tools/tests/audit-prod.test.sh`, `.claude/rules/deploiement-nginx.md` | redirections `https` seulement, 401 de la sonde dit, #750 expliqué une fois | R134 |
| specs et tests hors ligne désignés par la recherche | noms de tests sans identifiant de constat | R135 |
| `.claude/rules/*`, fichiers désignés | balayage | R136 |

---

### Task R121 : Aucune page du backoffice ne charge dans un `onMounted` : la règle et sa garde (décision 1 : CR11-1 ≈ C11-9)

**Files :**
- Modify : `frontend/tests/support/sourceText.ts` (`syntaxTreeOf`, `callsInsideHook`)
- Test : `frontend/tests/support/sourceText.spec.ts`, `frontend/tests/presentation/pages/admin/noLoadInOnMounted.spec.ts` (créé)
- Modify (règle) : `.claude/rules/frontend-session-refus.md` (puce « the alert's mount advances the epoch only… », `paths:`)

**Interfaces :**
- Produces : choix tranché 1 — `syntaxTreeOf(source: string): ts.SourceFile`, `callsInsideHook(source: string, hook:
  string): string[]`. Consumes : `SRC_SCRIPTS` (`srcSources.ts`).

- [ ] **Step 1 : tests, rouges.** `sourceText.spec.ts` :
  `it("callsInsideHook rend le callee de chaque appel des fonctions passées au hook, imbriquées comprises (#748, R121)")`
  — source `onMounted(() => { a(); x.y(); const f = () => load() }) ; b()` → `['a', 'x.y', 'load']` ; `onMounted(go)`
  (référence, pas de fonction écrite) → `[]` ; `other(() => a())` → `[]`.
  `noLoadInOnMounted.spec.ts` (docblock : la règle, ce qu'elle ne voit pas — un `await` avant l'envoi, un
  sous-composant rendu plus tard) :
  `it("aucun fichier de presentation/pages/admin n'appelle un chargement dans un onMounted (#748, R121, décision 1)")`
  (message : `fichier : callee`), et ses garde-fous (≥ 12 fichiers, ≥ 1 `onMounted` trouvé).
  Run : `docker compose exec -T frontend npm test -- tests/support/sourceText.spec.ts tests/presentation/pages/admin/noLoadInOnMounted.spec.ts`.
  Expected : FAIL, `callsInsideHook` introuvable.
- [ ] **Step 2 : implémenter** selon le choix tranché 1 (`treeOf` renommé partout où il est lu).
- [ ] **Step 3 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) dans `AdminIncidentsPage.vue`,
  `onMounted(() => { void load() })` ajouté (et `onMounted` importé) → la garde rougit en nommant
  `presentation/pages/admin/AdminIncidentsPage.vue : load` ; (b) dans `AdminQualityPage.vue`, une déstructuration
  `load: refresh` et `onMounted(() => refresh())` → rouge sur `refresh` ; restaurés.
- [ ] **Step 4 : règle** — dans la puce « **the alert's mount advances the epoch only, never the scope…** », après
  « …whenever it answers; » : « **a backoffice page starts its load in `setup`** (or an `immediate` watcher), both
  stamped before the alert's `onMounted` — a read sent from the page's own `onMounted` would carry the new epoch and say
  `common.accessRefused` for the earlier save; `noLoadInOnMounted.spec.ts` refuses a load or a repository call in an
  `onMounted` of `presentation/pages/admin` (R121, decision 1 of the eleventh review). Accepted, held by the review: a
  read sent after an `await` before `get`, or by a sub-component rendered later by a `v-if`; » — lignes ≤ 140 ;
  `paths:` gagne `frontend/tests/presentation/pages/admin/noLoadInOnMounted.spec.ts` (fichier indexé par `git add`
  avant `tools/check-claude-rules.sh`). Taille notée.
- [ ] **Step 5 : verts** — specs ciblés PASS ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ; `grep -rn "treeOf(" frontend/tests`
  : `syntaxTreeOf` seul.
- [ ] **Step 6 : commit** — `test(frontend): aucune page du backoffice ne charge dans un onMounted, la règle et sa garde de source (#748)`.

---

### Task R122 : Le refus du backoffice — la première lecture relancée clôt le chargement de la page d'arrivée ; l'estampille nommée par son effet (CR11-2, Q2 (B) ; St11-5 = Sp11-8)

**Files :**
- Modify : `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `frontend/src/presentation/router/backofficeSessionExpiry.ts`, `frontend/src/presentation/ui/admin/AccessRefusedNotice.vue` (docblock)
- Test : `frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`, `frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts` ; les specs qui appellent `readRefusalScope()` (liste par `grep -rln readRefusalScope frontend/tests`)
- Modify (règle) : `.claude/rules/frontend-session-refus.md` (l. 26, l. 72-87)

**Interfaces :**
- Produces : choix tranché 6 — `stampBackofficeRead(): ReadRefusalScope`. Supprime : `readRefusalScope()`.
- St11-5 : la puce « **Effacée** » d'`AccessRefusedNotice.vue` nomme l'effaceur, `clearBackofficeAccessRefusal()`, qui
  fait avancer le périmètre ; le docblock du module dit que `stampBackofficeRead()` estampille une lecture et, après le
  montage, clôt le chargement de la page d'arrivée.

- [ ] **Step 1 : tests, rouges.** `backofficeAccessRefusal.spec.ts` :
  `it("après la première lecture envoyée après le montage, une lecture de la page d'arrivée refusée rend le texte courant (#748, R122)")`
  (écriture refusée comptée, une estampille avant `settle`, `settle`, une estampille après, puis le refus de la
  première → `earlierSaveRefused` faux) ; `it("avant toute lecture relancée, chaque lecture de la page d'arrivée garde l'avis (#748, R122)")`
  (le cas de R112, sous le nouveau nom). `AdminLayout.sessionEnded.spec.ts` :
  `it("« Réessayer » servi, puis le refus tardif d'une lecture de la page d'arrivée : le texte courant (#748, R122)")`
  sur `AdminQualityPage` (une liste en 403, l'autre retenue par une promesse manuelle ; « Réessayer » servi ; la
  retenue répond 403 → texte FR exact de `common.accessRefused`).
  Run : `docker compose exec -T frontend npm test -- tests/application/admin/shared tests/presentation/ui/admin/AccessRefusedNotice.spec.ts tests/presentation/layout/AdminLayout.sessionEnded.spec.ts`.
  Expected : FAIL, `stampBackofficeRead` introuvable ; puis, exportée sans l'état, le premier cas sur `true`.
- [ ] **Step 2 : implémenter** selon le choix tranché 6 ; chaque transition reste une seule affectation de
  `state.value` (R84) ; renommage de `readRefusalScope` par `python3 -I` sur la liste de `grep -rln`, puis
  `docker compose restart frontend`.
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : la mise à faux d'`isLandingOpen` retirée
  de `stampBackofficeRead()` → le cas de l'intégration et le premier cas du module rougissent ; restauré.
- [ ] **Step 4 : règle** — l. 26 et l. 72 : `readRefusalScope()` → `stampBackofficeRead()` ; l. 84-87, « every read
  **sent before the mount** … whenever it answers; » → « every read **sent before the mount** — the landing page's
  load, stamped by its `setup` — keeps that text and re-announces it until the first read sent after the mount (a
  « Réessayer », a re-read after a save) closes that load (R122); ». Lignes touchées ≤ 140 (la l. 26, 216 caractères, et
  la l. 79, 143, coupées : St11-2). Taille notée.
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn readRefusalScope frontend/src frontend/tests .claude/rules` : vide.
- [ ] **Step 6 : commit** — `fix(frontend): la première lecture relancée après le montage clôt le chargement de la page d'arrivée ; l'estampille d'une lecture nommée par son effet (#748)`.

---

### Task R123 : Les specs du refus — sans intermédiaire, textes et montage écrits une fois, l'intégration lue à chaque annonce (St11-6, St11-10, Sp11-9 ; refactoring de tests)

**Files :**
- Create : `frontend/tests/support/accessRefusalTexts.ts` (`EARLIER_SAVE_REFUSED_FR`, `ACCESS_REFUSED_FR`, lus de
  `fr.json` par la clé, jamais recopiés)
- Modify : `frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts`, `frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts`, `frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts`

**Interfaces :**
- Consumes : R122 (`stampBackofficeRead`). St11-6 : `readSentNow()` retiré, `stampBackofficeRead()` appelée
  directement ; le cas « une lecture … qui ne répond pas » garde l'estampille jamais refusée **et** le dit par une
  assertion (`backofficeAccessRefused` inchangé après les deux estampilles) — distinct de « partie après le montage ».
  St11-10 : `mountAdminPage(component, route, providers)` remplace `mountAdminCaseStudies` et `mountAdminQuality`
  (les deux deviennent des appels d'une ligne).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre de tests noté.
- [ ] **Step 2 : test renforcé, rouge par mutation** (Sp11-9) — le cas d'intégration de R112 lit le texte FR exact
  **après chaque** annonce et vérifie que le `<span>` est remplacé (référence d'élément différente) entre la première
  et la seconde. Mutation (Python, copie vérifiée par `cmp`) : la `key` du `<span>` d'`AccessRefusedNotice.vue`
  retirée → le cas rougit sur le remplacement ; restauré.
- [ ] **Step 3 : refactorer** selon les interfaces.
- [ ] **Step 4 : verts** — même nombre de tests (± les cas renommés, dits) ; `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"` ; `grep -rn "readSentNow\|const earlierSaveFr\|const accessRefusedFr\|function mountAdminQuality" frontend/tests` : vide.
- [ ] **Step 5 : commit** — `test(frontend): les specs du refus sans intermédiaire, textes et montage écrits une fois, chaque annonce lue (#748)`.

---

### Task R124 : Traduction assistée — l'identité du formulaire périme la traduction en vol ; la suppression de l'entrée éditée et la langue d'une création aussi (St11-3, Q1 (A) ; C11-7 = CR11-4, décision 3)

**Files :**
- Modify : `frontend/src/application/admin/translation/useAdminTranslation.ts`, `frontend/src/presentation/pages/admin/{AdminCaseStudiesPage,AdminIncidentsPage,AdminContributionsPage,AdminAnonymousCvPage,AdminQualityPage,AdminAboutMeCardsSection,AdminAboutSiteCardsSection,AdminAboutSettingsForm}.vue`
- Test : `frontend/tests/application/admin/translation/useAdminTranslation.spec.ts`, `frontend/tests/support/entryFormLock.ts` (deux cas partagés, `deleteEditedEntry` dans `EntryFormLockCase`), les six specs qui déclarent un `EntryFormLockCase`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant », la phrase « every gesture that replaces the form's content … (`discard()`, R114, R114b) »)

**Interfaces :**
- Produces : choix tranché 2 — `UseAdminTranslationOptions.formIdentity?: () => unknown`, `isTranslating:
  Readonly<Ref<boolean>>`. `EntryFormLockCase.deleteEditedEntry: (wrapper: VueWrapper) => Promise<void>` (le
  `window.confirm` simulé à `true` par le support).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté ; les cas de R114/R114b (« Annuler »,
  « Modifier », « Créer la version XX » pendant une traduction, langue des réglages) en sont le filet : ils restent
  verts une fois les appels retirés.
- [ ] **Step 2 : tests, rouges** (Review Focus 3). `useAdminTranslation.spec.ts` :
  `it("une identité de formulaire changée pendant la traduction : isTranslating retombe, la réponse rend null (#748, R124)")`
  (dépôt différé, `formIdentity` sur une `ref`, changée avant la réponse) ;
  `it("une identité inchangée : la proposition est rendue (#748, R124)")`.
  `entryFormLock.ts` (huit formulaires) :
  `it(\`${prefix}supprimer l'entrée en édition pendant une traduction : la réponse ne pose rien\`)` (différée,
  `deleteEditedEntry`, réponse, `flushPromises()` → aucun avis `role="status"` de proposition, aucun champ de prose
  rempli) ; `it(\`${prefix}changer la langue d'une création pendant une traduction : la réponse ne pose rien\`)`
  (formulaire vierge rempli, traduction différée, `#${ids.locale}` changé, réponse → rien).
  Run : `docker compose exec -T frontend npm test -- tests/application/admin/translation tests/presentation/pages/admin`.
  Expected : FAIL — `formIdentity` sans effet ; les seize cas posent la proposition.
- [ ] **Step 3 : implémenter** selon le choix tranché 2 ; docblock du composable : l'identité, `discard()` pour ce
  qu'elle ne voit pas (une suppression).
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) la comparaison d'identité retirée
  du composable → les cas « Modifier », « Créer la version XX », « langue d'une création » et le cas des réglages
  rougissent ; (b) l'appel de `discard()` retiré de `handleDelete` d'`AdminContributionsPage.vue` → seul son cas
  « supprimer » rougit ; restaurés.
- [ ] **Step 5 : règle** — « and every gesture that replaces the form's content — « Annuler », « Modifier », « Créer la
  version XX », and the About settings' change of language — discards the response in flight (`discard()`, R114,
  R114b) » → « and a response whose form no longer has the identity read at send time (the entry, the locale, the source
  group, the prose: `formIdentity`) is discarded, whatever gesture replaced it, a deleted entry being discarded by
  `discard()` (R124) ». Taille notée.
- [ ] **Step 6 : verts** — `make front-test; echo "exit=$?"` (filet + 2 + 16), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -c discardTranslation frontend/src/presentation/pages/admin/*.vue` : le nom déstructuré et un appel par
  `handleDelete…` seulement (Qualité : deux de chaque ; réglages : 0).
- [ ] **Step 7 : commit** — `refactor(frontend): une traduction en vol se périme quand son formulaire change d'identité, la suppression de l'entrée éditée aussi (#748)`.

---

### Task R125 : Traduction assistée — un seul appel au modèle en vol par formulaire ; l'attente après « Annuler » est dite (décision 2 : CR11-3 = C11-6)

**Files :**
- Modify : `frontend/src/application/admin/translation/useAdminTranslation.ts`, `frontend/src/presentation/ui/admin/TranslateEntryButton.vue`, les neuf sites de `<TranslateEntryButton>` (`hintId` et `isDiscardPending` passés), `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`admin.translation.discardPending`)
- Test : `useAdminTranslation.spec.ts`, `frontend/tests/presentation/ui/admin/TranslateEntryButton.spec.ts`, `frontend/tests/support/entryFormLock.ts`, `AdminAboutPage.spec.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant ») ; `.claude/rules/frontend-a11y.md` non touchée (la convention du texte rendu pendant l'état y est déjà)

**Interfaces :**
- Consumes : R124 (`isTranslating`, l'identité). Produces : choix tranché 3 — `isDiscardPending`,
  `TranslateEntryButton` props `isDiscardPending`, `hintId`.

- [ ] **Step 1 : tests, rouges** (Review Focus 1). `useAdminTranslation.spec.ts` :
  `it("discard() puis translate() avant la réponse périmée : aucune requête, null (#748, R125, décision 2)")` ;
  `it("la réponse périmée arrivée, isDiscardPending retombe et une traduction repart (#748, R125)")` ;
  `it("une réponse périmée en échec : isDiscardPending retombe, aucune raison posée (#748, R125)")`.
  `TranslateEntryButton.spec.ts` : `isDiscardPending` vrai → bouton `disabled`, `aria-describedby` se termine par
  `hintId`, le texte FR exact rendu sous cet `id` ; faux → aucun élément de cet `id`, aucun `aria-describedby` vers lui.
  `entryFormLock.ts` (huit formulaires) : `it(\`${prefix}« Annuler » puis « translate » avant la réponse périmée : une seule traduction, l'attente dite\`)`
  (dépôt différé, édition, `translate`, « Annuler », `translate` émis → un appel ; `describedTexts` du bouton contient
  le texte FR exact ; réponse → le texte disparaît, formulaire vide). `AdminAboutPage.spec.ts` : le même pour les
  réglages (langue changée pendant la traduction). `expectNoAccessibilityViolation` sur un formulaire pendant
  l'attente.
  Run : `docker compose exec -T frontend npm test -- tests/application/admin/translation tests/presentation/ui/admin/TranslateEntryButton.spec.ts tests/presentation/pages/admin`.
  Expected : FAIL — deux appels au dépôt ; `isDiscardPending` introuvable.
- [ ] **Step 2 : implémenter** selon le choix tranché 3 ; FR et EN exacts du choix 3 ; identifiants
  `admin-<formulaire>-translation-hint` (un par formulaire, uniques dans la page à plusieurs formulaires).
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : `isDiscardPending` retiré de la garde de
  `translate()` → les cas « une seule traduction » rougissent (le bouton reste désactivé : seul le `translate` émis par
  programme le voit) ; restauré.
- [ ] **Step 4 : règle** — dans « Translation assistant », après la phrase de R124 : « **one model call in flight per
  form**: a discarded call still running refuses the next one (`isDiscardPending`, R125, decision 2 of the eleventh
  review: no `AbortSignal`, the call already left costs what it costs), and the button says why it waits through a text
  referenced by `aria-describedby` only while it waits (`admin.translation.discardPending`) ». Taille notée.
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(frontend): un seul appel au modèle en vol par formulaire, l'attente après « Annuler » dite (#748)`.

---

### Task R126 : Traduction assistée — la prose est en lecture seule pendant une traduction, et le dit (décision 3 : CR11-5)

**Files :**
- Create : `frontend/src/presentation/ui/admin/TranslationReadonlyHint.vue`
- Modify : les neuf formulaires (sept fichiers par entrée, `AdminAboutSettingsForm.vue`), `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`admin.translation.proseReadonly`)
- Test : `frontend/tests/support/entryFormLock.ts` (`EntryFormLockCase.ids.prose: readonly string[]`, renseigné dans les six specs), `AdminAboutPage.spec.ts` (réglages)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant »)

**Interfaces :**
- Consumes : R124 (`isTranslating` réactif à l'identité). Produces : choix tranché 4 — `TranslationReadonlyHint`
  (`id`, `shown`), identifiants `admin-<formulaire>-prose-readonly-hint`.

**Décision de l'auteur du 2026-10-31, après R124 :** la prose sort de `formIdentity` (R124 l'y avait mise :
`prose: collectProseFields(…)`) ; l'identité devient l'entrée, la langue, le groupe. La saisie pendant une traduction est
empêchée par le `readonly`, et le message d'une traduction en échec (429, 503 : `errorReason`) survit à la frappe, effacé
par un vrai changement de formulaire ou la traduction suivante. Cas partagé « l'erreur d'une traduction survit à la
frappe », vu rouge avant le retrait. Écart : le plan nomme `describedBy(<aide existante>, proseReadonlyHintId)` ; le code
lit un calculé par formulaire (`proseDescribedBy`) qui vaut l'identifiant pendant la traduction et `undefined` sinon.

- [ ] **Step 1 : tests, rouges** (Review Focus 2). `entryFormLock.ts` (huit formulaires) :
  `it(\`${prefix}pendant une traduction, chaque champ de prose est en lecture seule et décrit par le texte d'aide\`)`
  (différée : pour chaque `ids.prose`, `readonly` présent, `disabled` absent, `describedTexts` contient le texte FR
  exact ; le sélecteur de langue n'est pas décrit par lui) ;
  `it(\`${prefix}la traduction répondue ou en échec : la prose redevient modifiable, le texte disparaît\`)` (succès,
  puis un second montage avec un dépôt qui rejette `AdminTranslationError('unavailable')` : `readonly` absent, aucun
  élément de l'`id`, la prose d'origine intacte dans le cas d'échec) ; `expectNoAccessibilityViolation` pendant la
  traduction. `AdminAboutPage.spec.ts` : les deux mêmes pour les cinq champs des réglages.
  Run : `docker compose exec -T frontend npm test -- tests/presentation/pages/admin`.
  Expected : FAIL sur `readonly` absent.
- [ ] **Step 2 : implémenter** selon le choix tranché 4 (FR et EN exacts) ; les champs non-prose (`version`,
  `occurredAt`, `iconKey`, catégorie…) ne changent pas.
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : `:readonly` retiré d'un seul champ de
  prose d'`AdminAnonymousCvPage.vue` → seul son cas rougit, en nommant l'`id` ; restauré.
- [ ] **Step 4 : règle** — dans « Translation assistant », après la phrase de R125 : « while a translation runs, the
  form's prose fields are `readonly` (not `disabled`: readable and focusable), each described by
  `admin.translation.proseReadonly`, rendered only then: the answer replaces prose nobody could change meanwhile (R126,
  decision 3 of the eleventh review) ». Taille notée.
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(frontend): pendant une traduction, la prose du formulaire est en lecture seule et le dit (#748)`.

---

### Task R127 : Traduction assistée — ce que le bouton refuse, `translate` le refuse aussi : brouillon d'ordre, enregistrement, proposition des réglages (CR11-6 = C11-5, St11-17 ; Q5 (A))

**Files :**
- Modify : les sept fichiers de formulaires par entrée, `AdminAboutSettingsForm.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`admin.about.settingsProposalLockedHelp`)
- Test : `frontend/tests/support/entryFormLock.ts` (un cas, `dirtyOrder` dans `EntryFormLockCase`), `AdminAboutPage.spec.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant » : `isRefused` ; réglages : « The button is disabled while an order draft is dirty… »)

**Interfaces :**
- Produces : choix tranché 5. `EntryFormLockCase.dirtyOrder: (wrapper: VueWrapper) => Promise<void>` (un ↓ sur la
  première poignée de la table du formulaire).

- [ ] **Step 1 : tests, rouges.** `entryFormLock.ts` : `it(\`${prefix}un ordre modifié : un « translate » émis ne lance aucune traduction\`)`.
  `AdminAboutPage.spec.ts` : `it("réglages, un enregistrement en vol : un « translate » émis ne lance rien (#748, R127)")` ;
  `it("réglages, un ordre modifié ailleurs : un « translate » émis ne lance rien (#748, R127)")` ;
  `it("réglages, une proposition affichée : le bouton est désactivé, décrit par son texte, et un « translate » émis ne lance rien (#748, R127)")`
  (texte FR exact ; l'avis gardé ; hors proposition, aucun élément de l'`id`).
  Run : `docker compose exec -T frontend npm test -- tests/presentation/pages/admin`.
  Expected : FAIL, un appel au dépôt dans chaque cas ; bouton actif pendant la proposition.
- [ ] **Step 2 : implémenter** selon le choix tranché 5 (FR et EN exacts).
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : `|| isOrderDirty.value` retiré de
  l'`isRefused` d'`AdminCaseStudiesPage.vue` → seul son cas rougit ; restauré.
- [ ] **Step 4 : règle** — « during a proposal (`isRefused`, the form's lock) and while a translation is in flight »
  → « whenever its button is disabled (`isRefused`: the form's lock and a dirty order draft; for the About settings a
  save in flight, a dirty order and a proposal shown, R127) and while a translation is in flight » ; la phrase des
  réglages gagne : « and while a proposal is shown, said by `admin.about.settingsProposalLockedHelp` (R127) ». Taille notée.
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(frontend): la traduction assistée refuse par programme tout ce que son bouton refuse (#748)`.

---

### Task R127b : Traduction assistée — `isRefused` complet (enregistrement, prose) ; le texte des réglages dit le geste d'abandon

**Origine.** Deux questions rendues par R127, tranchées par l'auteur le 2026-10-31. (1) La promesse de R127 (« ce que le bouton
refuse, `translate` le refuse ») restait inexacte : le `:disabled` de chaque bouton compte aussi `isSubmitting`
(ou `isAnySaving`) et `!hasProseToTranslate`, que l'`isRefused` des formulaires par entrée ne reprenait pas.
(2) `admin.about.settingsProposalLockedHelp`
disait « changez de langue pour l'abandonner » : faux, changer de langue garde la proposition (R103) ; elle ne disparaît qu'au retour
sur sa langue cible, dont le formulaire est relu.

*(rectifié le 2026-10-31, douzième relecture, St12-17 : les cases cochées par l'agent de R127b sont décochées,
la convention des séries ; le rendu ne disait pas ses chaînes JSON de plus de 140 caractères, hors critère.)*

**Files :**
- Modify : `AdminCaseStudiesPage.vue`, `AdminContributionsPage.vue`, `AdminIncidentsPage.vue`, `AdminAnonymousCvPage.vue`,
  `AdminQualityPage.vue` (principe et trait), `AdminAboutSiteCardsSection.vue`, `AdminAboutMeCardsSection.vue`,
  `AdminAboutSettingsForm.vue` (`!hasProseToTranslate`), `useAdminTranslation.ts` (docblock d'`isRefused`),
  `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`admin.about.settingsProposalLockedHelp`)
- Test : `frontend/tests/support/entryFormLock.ts` (deux cas partagés, `holdNextSave` dans `EntryFormLockCase`), les six specs de
  page qui l'appellent (`holdNextSave`), `AdminAboutPage.spec.ts` (sans prose, geste, texte)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant » : `isRefused`, texte des réglages)

**Interfaces :**
- Produces : `EntryFormLockCase.holdNextSave: () => void` (arme le dépôt du formulaire : le prochain enregistrement ne répond jamais).
- Vérifié : aucune autre condition du `:disabled` de `TranslateEntryButton` n'échappe à `isRefused` ; ses propres `isTranslating` et
  `isDiscardPending` sont tenus par le composable (`callRunning`).
- Geste retenu : « passer à l'autre langue puis revenir à celle-ci » par le sélecteur « Langue des textes de la page À propos » ;
  prouvé par un test (la proposition survit au passage, disparaît au retour, avis et verrou avec elle, bouton libre).

- [ ] **Step 1 : tests rouges** — cas partagés « un enregistrement en vol » et « sans aucune prose à traduire » (les huit
  formulaires), `AdminAboutPage.spec.ts` : réglages sans prose, texte exact du verrou, geste.
- [ ] **Step 2 : implémenter** `isRefused` complet des neuf sites, textes FR et EN.
- [ ] **Step 3 : mutation** (Python, copie vérifiée par `cmp`) : `isSubmitting.value ||` retiré de `AdminCaseStudiesPage.vue` →
  seul son cas « enregistrement en vol » rougit ; `!hasProseToTranslate.value ||` retiré → seul son cas « sans prose » ; restauré.
- [ ] **Step 4 : règle** — phrase d'`isRefused` et phrase des réglages remplacées.
- [ ] **Step 5 : verts** — `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 6 : commit** — `fix(frontend): la traduction refuse aussi pendant un enregistrement et sans prose ;
  le texte des réglages verrouillés dit le geste qui abandonne la proposition (#748)`.

---

### Task R127c : Réglages « À propos » — un bouton « Abandonner » retire la proposition de traduction affichée

**Origine.** Question rendue par R127b (le seul geste d'abandon était un aller-retour de langue), tranchée par l'auteur le
2026-10-31 : un bouton « Abandonner » (EN « Discard »), visible pendant la proposition seulement, qui la retire et relit le
formulaire de la langue montrée. Le texte du verrou le nomme. Pas de confirmation ; focus au premier champ une fois relu ;
pas d'annonce, comme l'« Annuler » des formulaires par entrée. Une traduction en vol est périmée par le même geste.

**Files :**
- Modify : `AdminAboutSettingsForm.vue` (`handleAbandonProposal`, `copySettingsToForm`),
  `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`admin.about.settingsProposalLockedHelp`, `settingsProposalDiscard`)
- Test : `AdminAboutPage.spec.ts` (bouton et texte, abandon, audit axe pendant la proposition)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (phrase des réglages : « Abandonner » remplace l'aller-retour)

- [ ] **Step 1 : tests rouges** — bouton absent hors proposition, simple et nommé par le texte exact du verrou ; abandon :
  proposition, avis et verrou partis, valeurs enregistrées relues (lecture servie par le même objet : la recopie ne dépend pas
  du `watch`), « Proposer » libre, focus au premier champ ; axe pendant la proposition.
- [ ] **Step 2 : implémenter** le bouton et le texte, FR et EN.
- [ ] **Step 3 : mutation** (Python, copie vérifiée par `cmp`) : la recopie retirée → « valeurs relues » rougit ; `.focus()` retiré →
  le focus ; `await load()` retiré → le nombre de lectures ; restauré.
- [ ] **Step 4 : règle** — phrase des réglages remplacée.
- [ ] **Step 5 : verts** — `make front-test`, `make front-lint`, `make front-build`, `tools/check-claude-rules.sh`.
- [ ] **Step 6 : commit** — `feat(frontend): réglages « À propos », un bouton « Abandonner » retire la proposition de
  traduction affichée (#748)`.

---

### Task R128 : Réglages « À propos » — l'avis de proposition a une seule source de vérité (CR11-7, St11-11)

**Files :**
- Modify : `frontend/src/presentation/pages/admin/AdminAboutSettingsForm.vue` (`isProposalShown`, son commentaire)
- Test : `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts` (le cas de R113)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« it shows only under a loaded form (`isLoaded`), … (R113) »)

**Interfaces :**
- `isProposalShown = computed(() => proposal.value?.target === props.locale)` ; le commentaire dit que l'avis est rendu
  sous `<form v-else>`, qui n'existe qu'une fois `isLoaded`. Le cas de R113 ne lit plus `vm` : il vérifie dans le DOM,
  après la relecture refusée, qu'aucun formulaire ni avis `role="status"` de proposition n'est rendu.

- [ ] **Step 1 : filet** — le spec à 0, nombre noté.
- [ ] **Step 2 : réécrire le cas** selon les interfaces ; il passe sur le code actuel.
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : l'avis sorti du `<form v-else>` (placé
  avant lui, même `v-if`) **et** `isLoaded.value &&` retiré → le cas rougit dans le DOM ; restauré.
- [ ] **Step 4 : implémenter** : `isLoaded.value &&` retiré ; le cas reste vert.
- [ ] **Step 5 : règle** — « it shows only under a loaded form (`isLoaded`), so a refused or interrupted re-read of its
  target shows nothing (R113) » → « it is rendered inside the form, which exists only once loaded, so a refused or
  interrupted re-read of its target shows nothing (R113, R128) ». Taille notée.
- [ ] **Step 6 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -n "as unknown as { isProposalShown" frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts` : vide.
- [ ] **Step 7 : commit** — `refactor(frontend): réglages « À propos », l'avis de proposition n'a qu'une source de vérité, son test lit le DOM (#748)`.

---

### Task R129 : Hygiène des specs — le support du verrou, les commentaires restés, un test en double (St11-7, St11-15, Sp11-7, Sp11-2, St11-9 ; refactoring de tests)

**Files :**
- Modify : `frontend/tests/support/entryFormLock.ts`, `frontend/tests/presentation/ui/admin/useEntryFormLock.spec.ts`, `frontend/tests/presentation/pages/admin/AdminCaseStudiesPage.spec.ts`, `frontend/tests/presentation/layout/AppLayout.spec.ts`, `frontend/tests/support/sourceText.spec.ts`

**Interfaces :**
- St11-7 : toute lecture du titre passe par `titleValue` ; `proposalNotice(wrapper)` (le `role="status"` du
  formulaire) remplace les `findAll('form')[form].find('[role="status"]')` ; `translateButton` retiré au profit de
  `findComponent(TranslateEntryButton)` sur le résultat de `formOf(wrapper)` (un seul nom par objet : le bouton DOM
  reste `translateEntryButton`, le composant devient `translateButtonComponent`) ; `mountLocked` → `mountWithNewTranslation`.
- St11-15 : `AdminCaseStudiesPage.spec.ts:259-260` en un commentaire d'une phrase ; le docblock de
  `describeEntryFormLock` (l. 106-107) recoupé ; `useEntryFormLock.spec.ts` : `proposalSource` → `proposalSourceLocale`.
- Sp11-7 : le commentaire des l. 239-241 dit « dans l'une ou l'autre langue — la cible de la proposition comme sa
  source ».
- Sp11-2 : `AppLayout.spec.ts:168` au présent (« Le mécanisme partagé relit seul la session : un seul `me()` »).
- St11-9 : le cas de `sourceText.spec.ts:197-201` retiré (celui de la l. 168 tient la même assertion).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : refactorer** selon les interfaces (renommages par `python3 -I` puis `docker compose restart frontend`).
- [ ] **Step 3 : verts** — nombre de tests : filet − 1 (St11-9, dit) ; `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"` ; `grep -rn "mountLocked\|const translateButton =\|\[form\]\.find('\[role=\"status\"\]')\|proposalSource\b" frontend/tests` : vide.
- [ ] **Step 4 : commit** — `test(frontend): le support du verrou sans doublons ni noms trompeurs, commentaires au présent, un test en double retiré (#748)`.

---

### Task R130 : Le lecteur de gabarit tient de Vue le type d'un nœud et le jugement d'un `v-on` (St11-8, CR11-10 ; Q4 (A))

**Files :**
- Modify : `frontend/package.json`, `frontend/package-lock.json`, `frontend/tests/support/sourceText.ts`, `frontend/tests/support/srcSources.ts`, les lecteurs de `SRC_TEMPLATE_EXPRESSIONS` (`grep -rln SRC_TEMPLATE_EXPRESSIONS frontend/tests`)
- Test : `frontend/tests/support/sourceText.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session.md` (puce `noOmittedCredentials.spec.ts`, « (a `v-on` handler as an expression, a statement list as a function body) »)

**Interfaces :**
- Choix tranché 12. `"@vue/compiler-dom": "^3.5.44"` en `devDependencies` (le même intervalle que `vue`), installé
  dans le conteneur (`docker compose exec -T frontend npm install --save-dev @vue/compiler-dom@^3.5.44`), puis
  `npm ls @vue/compiler-dom` : **une seule** version, celle de `vue`.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : tests, rouges** — `sourceText.spec.ts` : `@click="{ credentials: 'omit' }"` → le fragment
  `($event) => ({ credentials: 'omit' })`, `'omit'` lu ; `@click="a.b"` et `@click="() => b('omit')"` → l'expression
  telle quelle ; `@click="b('omit')"` → `($event) => (b('omit'))` ; `@click="a(); b('omit')"` → corps de fonction.
  Chaque forme attendue est confirmée par la sortie de `compileTemplate` de `vue/compiler-sfc` sur le même gabarit
  (lue une fois à l'écriture du test, notée au rendu). Un cas : `NodeTypes.INTERPOLATION` est le type d'un `{{ a }}`
  analysé. Run : `docker compose exec -T frontend npm test -- tests/support/sourceText.spec.ts`. Expected : FAIL sur
  l'objet et l'appel unique (l'essai `(texte)` les lit sans `$event`).
- [ ] **Step 3 : implémenter** selon le choix tranché 12 ; renommages par `python3 -I` ; docblocks de
  `templateFragmentsOf` et de l'en-tête du module (« les fragments », plus « les expressions » pour l'ensemble).
- [ ] **Step 4 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : `isFnExpressionBrowser(exp)` retiré de
  `wrapperOf` → le cas `() => b('omit')` rougit ; restauré.
- [ ] **Step 5 : règle** — « (a `v-on` handler as an expression, a statement list as a function body) » → « (a `v-on`
  judged by Vue's own `isMemberExpressionBrowser` / `isFnExpressionBrowser`, an inline statement as Vue wraps it,
  R130) ». Taille notée.
- [ ] **Step 6 : verts** — `make front-test; echo "exit=$?"` (filet + nouveaux cas), `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "templateExpressionsOf\|SRC_TEMPLATE_EXPRESSIONS\|5 === node.type" frontend/tests` : vide.
- [ ] **Step 7 : commit** — `test(frontend): le lecteur de gabarit tient de Vue le type d'une interpolation et le jugement d'un v-on (#748)`.

---

### Task R131 : Backend — la garde des métadonnées lit les paramètres et les liens d'un élément en une passe (CR11-8 = St11-12 ; refactoring)

**Files :**
- Modify : `backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php`
- Create : `backend/tests/Support/CompiledNestedReading.php`

**Interfaces :**
- Choix tranché 10. `testANonLinkUriVariableIsReported` lit `readNested()` (`links` 0, `found` d'une ligne).

- [ ] **Step 1 : filet** — la classe verte, nombre de cas noté ; `make back-test; echo "exit=$?"`.
- [ ] **Step 2 : refactorer** selon le choix tranché 10 ; `make back-style-fix`.
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : `++$links` retiré de `readNested()` → le
  garde-fou « `src/` a des liens » rougit ; restauré.
- [ ] **Step 4 : verts** — mêmes nombres ; `make back-test; echo "exit=$?"`, `make back-quality; echo "exit=$?"` ;
  `grep -n "collectNested\|countLinks\|\.\.\.\$found" backend/tests/Security/ApiPlatformOperationsCarryNoSecurityTest.php` : vide ;
  `grep -c "instanceof Link" …` : 1.
- [ ] **Step 5 : commit** — `refactor(security): la garde des métadonnées lit paramètres et liens d'un élément en une passe (#748)`.

---

### Task R132 : Backend — aucun chemin de requête brut hors de `CanonicalPath` (C11-4, St11-13)

**Files :**
- Modify : `backend/src/Shared/Infrastructure/Http/CanonicalPath.php` (docblock d'`isPathUnder()`)
- Create : `backend/tests/Shared/Infrastructure/Http/PathIsDecidedCanonicallyTest.php`
- Modify (règle) : `.claude/rules/security-authentication.md` (puce `CanonicalPath`)

**Interfaces :**
- Choix tranché 11. Le test lit `backend/src/**/*.php` (`RecursiveDirectoryIterator`), par jetons : un `T_STRING`
  `getPathInfo` ou `isPathUnder` suivi de `(`, précédé de `->` ou `::`, hors de `CanonicalPath.php` → constat
  `<fichier>:<ligne> : <nom>`.

- [ ] **Step 1 : test** `testNoFileOfSrcReadsTheRawPathOrJudgesAStringPath` — vert d'emblée (aucun site). Garde-fou
  `testTheGuardSeesTheCanonicalReads` (au moins un `CanonicalPath::of(` hors de `CanonicalPath.php`).
- [ ] **Step 2 : rouges vus par mutation** (Python, copie vérifiée par `cmp`, cache vidé dans le conteneur après la
  mutation et la restauration) : (a) dans `LoginCsrfRequestListener.php`, `CanonicalPath::of($request)` →
  `$request->getPathInfo()` → rouge, fichier et ligne nommés ; (b) un `CanonicalPath::isPathUnder($request->getPathInfo(), '/api')`
  dans `CsrfCookieRequestSubscriber.php` → deux constats ; restaurés.
- [ ] **Step 3 : docblock et règle** — docblock selon le choix tranché 11 ; règle : « `isPathUnder($path, $prefix)` is
  the same rule on a string, for the tests that walk the router » → « … for the tests that walk the router; no file of
  `src/` but `CanonicalPath` calls it or `getPathInfo()` (`PathIsDecidedCanonicallyTest`, R132) ». Taille notée.
- [ ] **Step 4 : verts** — `make back-test; echo "exit=$?"`, `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `test(security): aucun fichier de src ne lit le chemin brut ni ne juge un chemin en chaîne hors de CanonicalPath (#748)`.

---

### Task R133 : Outillage — la Basic Auth posée et exigée par une fonction, jamais sur `http://`, jamais creuse ; la garde en liste d'autorisation ; les tests sans recopie (St11-4, C11-1, C11-8, C11-3, St11-14, Sp11-10)

**Files :**
- Modify : `tools/lib/smoke-http.sh`, `tools/audit-prod.sh`, `tools/smoke-dead-session-cookie.sh`, `tools/smoke-login-throttling.sh`, `tools/smoke-mcp.sh`
- Create : `tools/tests/lib/smoke-cases.sh` (`check_empty_basic_auth <libellé> <rc> <fichier err> <fichier appels> <fichier out>`, la forme des trois cas « vide »)
- Test : `tools/tests/{smoke-http,audit-prod,smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp}.test.sh`
- Modify (règles) : `.claude/rules/deploiement-nginx.md` (l. 119-130), `.claude/rules/security-authentication-sessions.md` (si elle nomme `smoke_require_auth_config` : `grep` d'abord)

**Interfaces :**
- Choix tranchés 7 et 8. St11-14 : dans `audit-prod.test.sh`, `run_as` → `run_audit <nom> [préfixe de commande…]`,
  `run` retiré, `run_traced <nom>` = `run_audit "$1" bash -x` ; les trois cas « vide » des smokes appellent
  `check_empty_basic_auth`. Sp11-10 : l'échec du cas « vide » de l'audit liste `TMPDIR` (`ls -A`).

- [ ] **Step 1 : filet** — les cinq tests hors ligne à 0, sorties notées.
- [ ] **Step 2 : tests, rouges** (Review Focus 5). `smoke-http.test.sh` : `smoke_use_auth_config` — définie vide → 2,
  « secret de Basic Auth vide » ; non définie → 0, aucun dossier ; pleine → 0, fichier écrit ; `:`, `u:`, `:p` → 2,
  « sans utilisateur ou sans mot de passe », aucun dossier, la valeur absente de stderr ; `smoke_fetch` avec
  `SMOKE_AUTH_CONFIG` posé sur `http://x` → 2, « non https:// », le faux curl jamais appelé ; sur `https://x` → curl
  appelé avec `-K`. Garde de source réécrite (choix 8) ; elle rougit aujourd'hui sur
  `smoke-dead-session-cookie.sh:128` et sur les appels `smoke_write_auth_config`/`smoke_require_auth_config`. Un cas
  par smoke : base `http://…` avec `AUDIT_BASIC_AUTH='u:p'` → sortie 2, aucun appel.
  Run : chaque test hors ligne, `; echo "exit=$?"`. Expected : `smoke_use_auth_config` introuvable ; `:p` rend 0 ;
  `http://` appelle curl.
- [ ] **Step 3 : implémenter** selon les choix 7 et 8 ; les commentaires d'en-tête des quatre scripts et de la
  bibliothèque nomment `smoke_use_auth_config` ; la phrase « À appeler juste après smoke_write_auth_config » disparaît.
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) `printenv AUDIT_BASIC_AUTH >/dev/null`
  ajouté à `smoke-mcp.sh` (la forme de C11-3, invisible à l'ancienne garde) → la garde rougit en nommant la ligne ;
  (b) le test du schéma retiré de `smoke_fetch` → le cas `http://` de la bibliothèque et des trois smokes rougit ;
  restaurés.
- [ ] **Step 5 : règles** — `deploiement-nginx.md`, l. 119-130 **remplacées** (une phrase par fait, ≤ 140) :
  `smoke_use_auth_config` (nom validé, valeur lue sous `set +x`, retour à la ligne, valeur sans `:`, sans utilisateur
  ou sans mot de passe : sortie 2 ; définie vide : « secret de Basic Auth vide », sortie 2 ; non définie : sans Basic
  Auth) ; `smoke_fetch` (refuse le `-K` sur une URL non `https://`, R133) ; la garde de source, liste
  d'autorisation (`[[ -v ]]` et l'appel de la bibliothèque, R133). Tailles notées.
- [ ] **Step 6 : verts** — les cinq tests hors ligne à 0 (filet + nouveaux cas) ; shellcheck (image épinglée) sur la
  bibliothèque, les quatre scripts, les cinq tests et `tools/tests/lib/smoke-cases.sh` ;
  `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "smoke_require_auth_config\|run() {" tools` : `_smoke_require_auth_config` seul, dans la bibliothèque.
- [ ] **Step 7 : commit** — `fix(tools): la Basic Auth posée et exigée par une fonction, refusée sur http:// et sans utilisateur ou mot de passe ; la garde en liste d'autorisation (#748)`.

---

### Task R134 : Outillage — l'audit ne suit une redirection authentifiée qu'en `https`, une sonde en 401 le dit, #750 expliqué une fois (C11-2, CR11-9, St11-16)

**Files :**
- Modify : `tools/audit-prod.sh`
- Test : `tools/tests/audit-prod.test.sh` (le faux curl rend `FAKE_CURL_HTTP_CODE_FOR_WWW` sur stdout pour une URL
  `https://www.` avec `-w '%{http_code}'`, si posée)
- Modify (règle) : `.claude/rules/deploiement-nginx.md` (phrase de #750, l. 133-134)

**Interfaces :**
- Consumes : R133 (`smoke_use_auth_config` dans l'audit). Choix tranché 9.

- [ ] **Step 1 : tests, rouges.** `audit-prod.test.sh`, run nominal (`AUDIT_BASIC_AUTH='u:p'`) :
  `pass "chaque appel qui porte -K porte --proto-redir =https"` ; un run `FAKE_CURL_HTTP_CODE_FOR_WWW=401` :
  `pass "une sonde https://www. en 401 dit que l'hôte demande la Basic Auth"`. Run :
  `bash tools/tests/audit-prod.test.sh; echo "exit=$?"`. Expected : exit 1, les deux en FAIL.
- [ ] **Step 2 : implémenter** selon le choix tranché 9.
- [ ] **Step 3 : règle** — St11-16 : la plomberie reste dans `deploiement-nginx.md` (ses `paths:` couvrent
  `tools/lib/smoke-http.sh` et ses tests, ceux de `release.md` non) ; la phrase de #750 gagne « ; every authenticated
  call follows redirects on `https` only (`--proto-redir =https`, R134), and a probe answered 401 says the host asks
  for the Basic Auth ». Taille notée.
- [ ] **Step 4 : verts** — `bash tools/tests/audit-prod.test.sh; echo "exit=$?"` à 0 ; shellcheck (image épinglée)
  sur les deux fichiers ; `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -c "#750" tools/audit-prod.sh` : 2 (le commentaire de la boucle et le renvoi de `CURL_OPTS_ANONYMOUS`).
- [ ] **Step 5 : commit** — `fix(tools): l'audit ne suit une redirection authentifiée qu'en https, une sonde en 401 le dit ; #750 expliqué une fois (#748)`.

---

### Task R135 : Les noms de tests sans identifiant de constat (décision 4 : St11-1, Sp11-1)

**Files :** les specs et tests hors ligne que la recherche désigne, dont la liste de Sp11-1 (`LoginPage.spec.ts:144`,
`AppLayout.spec.ts:298`, `AdminAboutPage.spec.ts:1203`, `:1340`, `:1527`, `signInLocations.spec.ts:57`, `:84`,
`SignInAgainLink.spec.ts:76`, `adminGuard.spec.ts:262`, `noOmittedCredentials.spec.ts:88`).

**Interfaces :**
- Q3 : les identifiants de **tâche** (`R…`), de décision et le ticket restent. Un identifiant de constat retiré d'un
  nom va, s'il éclaire le cas, dans un commentaire au-dessus (décision 4).
- Regex des identifiants de constat (`grep -P`), à la place de celle de R120 qui ne voyait que `Sp|CR|St|C` suivis
  d'un tiret : `\b(CR|St|Sp|C|A|F|S|B|E|I|M)[0-9]+(-[0-9]+)?[a-z]?\b|\bB/F[0-9]+\b|\bF-I[0-9]+\b` (couvre `A3-10`,
  `F8`, `CR2`, `B/F2`, `C1`, `F11`, `S2`, `F-I1`, `CR10-4`).

- [ ] **Step 1 : recherche** — noms de tests :
  `grep -rnP "\b(it|describe|test)(\.each\([^)]*\))?\(\s*['\"\x60].*(<regex>)" frontend/tests` ;
  `grep -rnP "function test\w*\(|yield ['\"]" backend/tests | grep -P "<regex>"` ;
  `grep -rnP "\b(pass|fail) ['\"].*(<regex>)" tools/tests`. Pour chaque ligne, le commit de `git blame --porcelain -L n,n`
  est-il dans `git rev-list 0e581b42..HEAD` ? Oui : réécrit ; non : listé comme antérieur à #748, gardé. Faux positifs
  (`%E0`, un code d'état) listés avec leur raison.
- [ ] **Step 2 : réécrire** chaque nom désigné (par `python3 -I`), puis `docker compose restart frontend`.
- [ ] **Step 3 : verts** — mêmes nombres de tests ; `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"` ; `make back-test; echo "exit=$?"` et les tests hors ligne si un de leurs fichiers
  a changé ; la recherche du Step 1 rejouée : seules les lignes antérieures et les faux positifs restent.
- [ ] **Step 4 : commit** — `test: les noms de tests de #748 sans identifiant de constat (#748)`.

---

### Task R136 : Balayage documentaire de la onzième série — avec la liste des recherches et de leurs résultats

Les docstrings et les règles ont suivi chaque comportement dans son commit ; cette tâche vérifie qu'il ne reste rien.
**Les trois derniers balayages ont été incomplets** (R120 : identifiants vus sous une seule forme, longueurs mesurées
hors de `.claude/rules/`) : le rendu donne, pour **chaque** recherche ci-dessous, la commande exacte, le nombre de
lignes rendues et, pour chaque ligne gardée, la raison en quelques mots. Une recherche sans ligne se dit « 0 ».

**Files :** ceux que le balayage désigne ; St11-2 désigne déjà `frontend-session.md:151` (142) et
`security-authentication-sessions.md:177` (176) si R122 ne les a pas coupées.

- [ ] **Step 1 : recherches** (fichiers de secrets et `node_modules` exclus ; périmètre `.claude docs frontend/src
  frontend/tests backend/src backend/tests tools`) :
  1. Noms disparus : `grep -rn -e readRefusalScope -e readSentNow -e "smoke_require_auth_config AUDIT" -e templateExpressionsOf -e SRC_TEMPLATE_EXPRESSIONS -e collectNested -e countLinks -e mountLocked -e "treeOf("`
     (`syntaxTreeOf(` et `_smoke_require_auth_config` attendus, à dire).
  2. Vocabulaire de l'ancien mécanisme : `grep -rn -e "discardTranslation()" -e "périme la traduction" -e "every gesture that replaces"`
     — chaque ligne gardée dit l'identité du formulaire ou la suppression.
  3. Identifiants de constat dans les noms de tests : la recherche de R135, rejouée ; 0 hors antérieurs et faux
     positifs.
  4. Récits au passé dans les fichiers que `git diff --name-only 55199dca...HEAD -- frontend backend tools .claude`
     liste : `grep -nE "(depuis R[0-9]+|n'est plus|n'a plus|ne .* plus |restait|courait|lisait|était là|used to|no longer)"` ;
     la référence `R…` qui **justifie** une règle reste.
  5. Longueurs : `awk 'length>140 {print FILENAME": "FNR": "length}'` sur chaque fichier que
     `git diff --name-only 0e581b42...HEAD -- frontend backend tools .claude/rules` liste ; chaque ligne rendue dont
     `git blame` donne un commit de `0e581b42..HEAD` est coupée (une ligne de table Markdown se dit, non coupée) ; les
     autres sont listées comme antérieures.
  6. Règles touchées par la série : relire en entier, avec `Read`, `frontend-session-refus.md`, `frontend-session.md`,
     `frontend-backoffice.md` (« Translation assistant » et les réglages), `security-authentication.md`,
     `security-authentication-sessions.md`, `deploiement-nginx.md` (paragraphe des smokes) : toute phrase fausse sur le
     code de HEAD est **remplacée**.
  7. Le docblock d'en-tête de chaque fichier de `src/` et de `tests/support/` modifié par R121 à R135
     (`git diff --name-only 5fd65d6e...HEAD -- frontend/src frontend/tests/support backend/src tools`) relu contre son
     code.
- [ ] **Step 2 : verts** — `tools/check-claude-rules.sh; echo "exit=$?"` ; taille de chaque règle touchée par la série
  notée, toutes < 50 000 octets, `backoffice-api.md` à ses 39 834 octets ; `make front-test; echo "exit=$?"` si un
  fichier de `frontend/` a changé, `make back-test; echo "exit=$?"` si un de `backend/`, les tests hors ligne si un de
  `tools/`.
- [ ] **Step 3 : commit** (seulement si le balayage a changé un fichier) — `docs: le balayage de la onzième relecture de #748`.
  Sinon, le rendu dit « rien à changer » et donne la liste des recherches et de leurs résultats.

### Traçabilité des constats

| Constat | Traitement |
|---|---|
| CR11-1 ≈ C11-9 | R121 (décision 1) |
| St11-3 | R124 (Q1) |
| St11-4 | R133 |
| CR11-3 = C11-6 | R125 (décision 2) |
| CR11-5 | R126 (décision 3) |
| C11-7 = CR11-4 | R124 (suppression, langue d'une création) |
| CR11-6 = C11-5 (+ St11-17) | R127 (Q5) |
| questions de R127 (décisions de l'auteur du 2026-10-31) | R127b |
| question de R127b (décision de l'auteur du 2026-10-31) | R127c |
| C11-1 | R133 |
| CR11-2 | R122 (Q2) |
| CR11-7 + St11-11 | R128 |
| CR11-8 = St11-12 | R131 |
| St11-1, Sp11-1 | R135 (décision 4, Q3) ; R136 recherche 3 |
| St11-2 = Sp11-3 | R122 (`frontend-session-refus.md:26`, `:79`), R136 recherche 5 (`frontend-session.md:151`, `security-authentication-sessions.md:177`) |
| St11-5 = Sp11-8 | R122 |
| St11-6 | R123 |
| St11-7 | R129 |
| St11-8 | R130 (Q4) |
| St11-10 | R123 |
| St11-14 | R133 |
| St11-15 | R129 |
| Sp11-2 | R129 |
| Sp11-7 | R129 |
| St11-9 | R129 |
| St11-16 | R134 (règle gardée dans `deploiement-nginx.md`, #750 expliqué une fois) |
| C11-2 | R134 |
| C11-3 | R133 |
| C11-4, St11-13 | R132 |
| C11-8 | R133 |
| CR11-9 | R134 |
| CR11-10 | R130 (Q4) |
| St11-18, Sp11-5 | décision 4 : `brief-commun-agents.md` corrigé dans le commit de ce plan, rien d'autre |
| Sp11-6 | acté dans la vérification ci-dessus, rien à corriger |
| Sp11-9 | R123 |
| Sp11-10 | R133 |
| Sp11-4 | sans suite (décision 5) |

### Constats écartés, avec leur raison

- **Sp11-4** : écarté par l'auteur le 2026-10-31 (décision 5).
- **Sp11-6** : le constat dit lui-même que les noms sont justes (des sources de fichiers) ; rien à corriger.

---


## Corrections de la douzième relecture (2026-10-31)

R121 à R136, R127b et R127c sont faites (après 5fd65d6e, jusqu'à 53d83217 ; constats de la douzième relecture :
f6f1e9b3, décisions : 902bd8b8). Leurs cases restent non cochées, par convention des séries : cette phrase dit qu'elles
sont faites (celles de R127b, cochées par son agent, sont décochées dans le commit de ce plan, St12-17).

Quatre relectures du diff 33af7369...53d83217 (`/code-review medium` CR12-*, audit de sécurité C12-*, axe Standards
St12-*, axe Spec Sp12-*) : aucun critique ni haut ; moyens : CR12-1 = C12-1, St12-1, St12-2, St12-3, St12-5, Sp12-1.
Constats complets et décisions : `tasks/relectures-748/relecture-12-constats.md` (la source de vérité). Tous sont
traités ici par R137 à R151, avant les tâches 11 et 12 : corrigés, ou écartés avec leur raison (ci-dessous). Les points
de contrôle au navigateur et le corps de PR de la série sont ajoutés aux tâches 11 et 12 dans le commit de ce plan.

### Décisions de l'auteur (2026-10-31)

1. **Q1 (b) : une génération de formulaire.** L'identité du formulaire porte une génération, incrémentée par la remise
   à zéro du formulaire (`resetForm()`, `resetPrincipleForm()`, `resetTraitForm()`) : un formulaire vierge enregistré
   pendant une traduction la périme, même si l'entrée, la langue et le groupe sont inchangés. `failure` est effacé au
   changement de génération (CR12-2). R139.
2. **Q2 : oui aux deux.** Un seul `computed` du refus de la traduction, lu par `isRefused` et par le `:disabled` de
   `TranslateEntryButton` (R137) ; un composable de la prose : l'identité, l'id de l'aide en lecture seule,
   `proseDescribedBy` (R138).
3. **Q3 : les lignes > 140 antérieures de #748**, dans les règles et le code, pas le JSON ni les tables, dans une tâche
   dédiée, la liste refaite (R150 ; méthode dans la tâche).
4. **Q4 : oui.** Le mot `AUDIT_BASIC_AUTH` est refusé partout dans l'audit et les trois smokes hors des deux formes,
   commentaires compris (les en-têtes `Env :` réécrits), et une garde refuse les vidages d'environnement (R148).
5. **Toujours valables** : les décisions et « Sans suite » des séries précédentes (Sp11-4, C5-1, C5-2, C7, CR6-6, CR7-2,
   la limite de R94, C10-5, Sp10-8, St9-17, Sp9-8, C9-8, St9-11), pas de rotation de la Basic Auth de préprod (C10-1) ;
   critère d'arrêt **tout jusqu'à zéro** : tous les autres constats se corrigent.

### Vérification de chaque constat dans le code (HEAD 902bd8b8)

- **CR12-1 = C12-1, confirmé.** `useAdminTranslation.ts:75` compare `JSON.stringify(formIdentity())` ; l'identité des
  formulaires par entrée est `{ entry, locale, group }` (`AdminIncidentsPage.vue:47-51`) ; `resetForm()` (l. 196-209) remet
  `{ null, 'fr', null }`, l'identité d'un formulaire vierge FR : `handleSubmit` réussi pendant une traduction ne périme
  rien, et `handleTranslate` (l. 296-300) pose la proposition sur le formulaire vidé. « Enregistrer » :
  `:disabled="isSubmitting || isOrderDirty"` (l. 464).
- **St12-1 = CR12-7, confirmé.** `AdminIncidentsPage.vue:44` (`isRefused`) et `:425` (`:disabled`) écrivent le même
  prédicat ; de même sur les huit autres sites (`AdminAboutSettingsForm.vue:67` et son bouton).
- **St12-2 = CR12-8, confirmé.** `PROSE_READONLY_HINT_ID` et `proseDescribedBy` dans les neuf formulaires (huit
  fichiers), `:readonly="isTranslating"` + `:aria-describedby` sur chaque champ de prose (vingt-cinq champs), `formIdentity`
  et son commentaire recopiés ; `EntryFormLockCase.ids.prose` (`tests/support/entryFormLock.ts:108`) recopie chaque
  `PROSE_FIELDS`. Les identifiants suivent une règle : `<préfixe>-<champ en kebab>` (`admin-incident-root-cause`), le
  préfixe des champs des réglages étant `admin-about`, celui de leurs textes `admin-about-settings`.
- **St12-3, confirmé.** `useAdminTranslation.ts:76-79` : `inFlight` (la traduction courante, non périmée) et
  `callRunning` (un appel en vol, périmé ou non).
- **St12-4, confirmé.** `formIdentity?: () => unknown` (l. 48), comparé sérialisé (l. 75).
- **St12-5, confirmé.** `backofficeAccessRefusal.ts:81`, `:90`, `:99` (trois `let`), remis ensemble l. 153-156,
  171-173, 189-193 ; `stampBackofficeRead()` (l. 112) met `isLandingOpen` à faux et sert de lecture dans
  `AccessRefusedNotice.spec.ts:164`, `:171`, `:193`, `:202`.
- **CR12-4, confirmé.** `stampBackofficeRead()` est appelée par `backofficeSessionExpiry.ts:51` pour **toute** lecture
  estampillée : une section rendue par un `v-if`, un watcher ou un rejeu clôt le chargement de la page d'arrivée.
- **Sp12-3, confirmé.** « Réessayer » nommé dans `backofficeAccessRefusal.ts:33`, `AccessRefusedNotice.vue:49` et
  `frontend-session-refus.md:89` ; aucune page de `presentation/pages/admin` n'a ce bouton.
- **Sp12-8, confirmé.** La section « Tests » de `frontend-session-refus.md` (l. 125) ne cite ni R122 ni
  `noLoadInOnMounted.spec.ts`.
- **CR12-2 = Sp12-2, confirmé.** `errorReason` (l. 85) masque l'échec tant que l'identité diffère, sans effacer
  `failure` : « Modifier » A (échec), B, puis A le réaffiche.
- **CR12-3 = Sp12-4 = C12-3, confirmé.** `callsInsideHook` ne rend que les appels écrits dans une fonction passée au
  hook (`onMounted(go)` → `[]`, cas de son propre spec) ; le hook est cherché par son nom ; `nextTick`, `setTimeout`, un
  `watch(…, { flush: 'post' })` ne sont pas lus ; `ADMIN_PAGES` = `presentation/pages/admin/` seul. Le docblock de
  `noLoadInOnMounted.spec.ts:14` ne cite que l'`await` et le `v-if`.
- **St12-11, confirmé sauf un point.** `syntaxTreeOf` exporté (`sourceText.ts:266`) ; `'load' === name` couvert par
  `startsWith('load')` (`noLoadInOnMounted.spec.ts:39-41`) ; `loadAliasesOf` lit par regex (une annotation
  `load: () => void` passe pour un alias). « décision 1 » dans un nom de test : écarté (voir « Constats écartés »).
- **CR12-5 = C12-4, confirmé.** `PathIsDecidedCanonicallyTest.php:159` : `in_array(…, self::FORBIDDEN_CALLS, true)`.
- **CR12-6 = St12-12, confirmé.** `canonicalReadsIn` (l. 179-194) ne voit que `T_STRING` `CanonicalPath` ; un nom complet
  est un seul `T_NAME_FULLY_QUALIFIED` ; `'of'` comparé avec la casse ; pas de test ; `'of' === …` non Yoda ; la forme
  d'appel (`->`, `?->`, `::` puis `(`) est aussi écrite dans `tests/Support/AuthorizationSites.php:137-138`.
- **C12-12, confirmé.** Le docblock (l. 28-30) et `security-authentication.md:85` ne nomment ni `getUri()` ni
  `getBaseUrl()`.
- **St12-13 = Sp12-6, confirmé.** `CompiledNestedReading.php:12` (« testaient »), `smoke-http.test.sh:592`
  (« ne voyait »).
- **C12-2, confirmé.** `smoke-http.test.sh` : `grep -nvE '^[[:space:]]*#'` écarte toute ligne commençant par `#`, y
  compris une ligne d'un heredoc ; aucun cas pour `declare -p`, `printenv`, `env`, `set`, `export -p`, `${!x}`.
- **CR12-10 = C12-13, confirmé.** `smoke-http.sh:263` : `_smoke_auth=(-K "$SMOKE_AUTH_CONFIG")` sans `--proto-redir` ;
  seul `url` est jugé (l. 255), pas `"$@"`.
- **C12-9, confirmé.** `smoke-http.sh:142` refuse un utilisateur ou un mot de passe **vide**, pas fait de blancs.
- **C12-10, confirmé.** Rien ne retire l'export de la variable lue.
- **St12-14, confirmé.** `smoke_write_auth_config` publique (l. 126, listée dans l'en-tête l. 19) ; le décompte
  `grep -cxF -e "$allowed_use" -e "  $allowed_use"` (`smoke-http.test.sh`) code deux espaces ; `audit-prod.sh:114`
  assigne un `curl`, `:118` l'appelle en ligne. Les `check_*` à six paramètres : `tools/tests/lib/smoke-cases.sh`.
- **St12-15, confirmé.** `smoke-*.sh:32/39/46` (`Env :`) ne citent ni « sans « : » » ni le retour à la ligne.
- **CR12-9, confirmé.** `audit-prod.sh:114` et `:118` : deux `curl` par URL ; l'indice « #750 » (l. 116) s'imprime
  sans condition, en prod aussi.
- **St12-6, confirmé.** `describedBy` est exporté par `useEntryFormLock.ts:33`, importé seul par
  `TranslateEntryButton.vue:5` et `AdminAboutSettingsForm.vue:10` ; cast `attrs['aria-describedby'] as string | undefined`.
- **St12-7, confirmé.** `AdminAboutSettingsForm.vue:187` : « Abandonner » n'existe que pendant une proposition
  affichée, et `isRefused` (l. 67) refuse toute traduction pendant elle : aucune traduction n'est en vol, l'appel est mort.
- **St12-8, confirmé.** `admin.about.settingsProposalLockedHelp` et `admin.about.settingsProposalDiscard` hors de
  `admin.about.settings` ; « Abandonner » s'écrit `handleAbandonProposal`, `settingsProposalDiscard`, `discardTranslation`.
- **St12-9 = Sp12-5, confirmé.** `AdminAboutPage.spec.ts:1320` et `:1747` lisent `vm as unknown as { proposal }` ;
  `:1354` : `expect(SETTINGS_PROPOSAL_LOCKED_TEXT).toContain('« Abandonner »')` teste une constante du spec.
- **St12-10, confirmé.** `entryFormLock.ts:15-61` (`ENTRY_LOCK_*`, `DISCARD_PENDING_TEXT`, `PROSE_READONLY_TEXT`,
  `TRANSLATION_UNAVAILABLE_TEXT`, `LOCALE_HELP_TEXT`) et `AdminAboutPage.spec.ts:1225` recopient `fr.json`.
- **St12-16, confirmé.** `frontend-backoffice.md`, phrase de « Proposer la version XX » (R93 à R125), une dizaine de
  lignes de parenthèses ; `frontend-session-refus.md:94` (« Accepted, held by the review: … »).
- **Sp12-7, confirmé.** `AdminLayout.sessionEnded.spec.ts:152-159`.
- **St12-17 = Sp12-11, confirmé.** Plan : cases de R127b cochées ; `dirtyOrder: (wrapper) => dragRow(wrapper, 0, 2)` dans
  les six specs, le plan de R127 disait « un ↓ sur la première poignée » ; le rendu de R127b ne dit pas ses chaînes JSON
  longues (aucun code à corriger : le JSON est hors du critère, décision 3).
- **C12-5, confirmé.** `isDiscardPending` vit dans l'instance du composable : une page quittée puis rouverte, ou un
  second onglet, lance un second appel (borné par le quota 30/h) ; la limite n'est écrite nulle part.
- **C12-6 = Sp12-9, confirmé.** `admin.translation.proseReadonly` dit « Rien de ce qui est écrit n'est perdu » : une saisie
  non enregistrée est remplacée par la réponse ; `admin.translation.discardPending` dit « La traduction annulée » quand la
  péremption vient aussi d'un changement de langue, de « Modifier », de « Créer la version XX », d'un enregistrement (R139).
- **C12-7, confirmé.** `AdminAboutSettingsForm.vue:57` : « d'autres réglages posés » ; l'identité ne porte que la langue.
- **C12-8, confirmé.** `handleDelete` (`AdminIncidentsPage.vue:308-311`) ne périme que l'entrée en édition ; supprimer
  l'entrée source d'un « Créer la version XX » laisse la traduction poser une proposition sur un groupe sans membre.
- **C12-11, confirmé.** `frontend/package.json` déclare `@vue/compiler-dom` et `vue` en `^3.5.44`, indépendamment.
- **Sp12-1, confirmé et recompté** (`awk 'length>140'` hors tables, JSON exclu, commit de `git blame` dans
  `0e581b42..HEAD`) : **190 lignes** — 64 dans `.claude/rules/`, 87 de code dont la longueur tient à un seul littéral
  (noms de tests, messages), 39 autres (commentaires, code). `0e581b42` est la base de la branche
  (`git merge-base HEAD feature/379-mcp-paliers`).
- **Sp12-10** : constat de conformité (les deux « #750 » sont ceux que le choix 9 de la onzième série voulait).

### Choix de conception tranchés

1. **Q2, refus unique (R137).** `UseAdminTranslationResult.isRefused: Readonly<Ref<boolean>>`, le `computed` de
   `options.isRefused?.() ?? false` ; `translate()` le lit, et chaque `<TranslateEntryButton>` reçoit
   `:disabled="isTranslationRefused"` (le nom déstructuré). Aucun site ne réécrit plus le prédicat.
2. **St12-4 (R137).** `domain/admin/translation/entities/FormIdentity.ts` : `interface FormIdentity { readonly entry:
   string | null; readonly locale: Locale; readonly group: string | null; readonly generation: number }` et
   `sameFormIdentity(a: FormIdentity, b: FormIdentity): boolean` (champ par champ). `formIdentity?: () => FormIdentity`.
   Avant R139, `generation` vaut `0` partout.
3. **St12-3 (R137).** `inFlight` → `isAwaitingCurrent` (« la traduction courante attend sa réponse, non périmée ») ;
   `callRunning` → `isAnyCallRunning` (« un appel au dépôt est parti et n'a pas répondu, périmé ou non »).
4. **Q2, le composable de la prose (R138).** `frontend/src/presentation/ui/admin/useTranslatedForm.ts` :
   `useTranslatedForm(options: UseTranslatedFormOptions): UseTranslatedFormResult`, avec
   `UseTranslatedFormOptions { hintIdPrefix: string; fieldIdPrefix?: string (défaut : hintIdPrefix); proseFields: readonly
   string[]; identity: () => Omit<FormIdentity, 'generation'>; isRefused: () => boolean }`. Il appelle
   `useAdminTranslation` et rend tout ce qu'il rend (`isTranslating`, `isDiscardPending`, `errorReason`, `translate`,
   `discard`, `isRefused`) plus `proseReadonlyHintId: string` (`${hintIdPrefix}-prose-readonly-hint`),
   `translationHintId: string` (`${hintIdPrefix}-translation-hint`), `proseDescribedBy: Readonly<Ref<string |
   undefined>>` et `proseFieldAttrs(helpId?: string): { readonly: boolean; 'aria-describedby': string | undefined }`
   (lié par `v-bind` sur chaque champ de prose). `proseFieldId(prefix: string, field: string): string` (kebab) exporté
   par le même module. Les identifiants actuels sont gardés à l'octet près (`grep` avant et après).
   Les listes de champs de prose quittent les `<script setup>` pour `frontend/src/presentation/pages/admin/proseFields.ts`
   (`INCIDENT_PROSE_FIELDS`, `CONTRIBUTION_PROSE_FIELDS`, `CASE_STUDY_PROSE_FIELDS`, `ANONYMOUS_CV_PROSE_FIELDS`,
   `QUALITY_PRINCIPLE_PROSE_FIELDS`, `QUALITY_TRAIT_PROSE_FIELDS`, `SITE_CARD_PROSE_FIELDS`, `ME_CARD_PROSE_FIELDS`,
   `ABOUT_SETTINGS_PROSE_FIELDS`) : chaque page reste seule à décider de ses champs (spec D2), la liste est lue par la
   page et par son spec ; `EntryFormLockCase.ids.prose` disparaît au profit de `proseFields` + `fieldIdPrefix`.
5. **St12-6 (R138).** `describedBy` quitte `useEntryFormLock.ts` pour `frontend/src/presentation/ui/admin/describedBy.ts`
   (même signature) ; `TranslateEntryButton` déclare la prop `ariaDescribedby?: string` (Vue y range
   `aria-describedby`), le cast disparaît, `inheritAttrs: false` reste (deux racines).
6. **Q1 + CR12-2 (R139).** Le composable porte `generation` (une `ref`, 0 au départ) et rend `renewForm(): void` ;
   chaque remise à zéro du formulaire l'appelle en premier. `failure` est effacé **à tout changement d'identité**
   (génération comprise, un guetteur `flush: 'sync'` permanent sur l'identité), donc `errorReason` rend
   `failure.reason` sans comparaison : un échec ne reparaît jamais par un retour à son identité (réponse attendue à Q8).
7. **C12-8 (R140), si Q1 (a).** `useTranslatedForm` rend `discardIfDeleted(entry: { id: string; translationGroup: string |
   null }, form: { editingId: string | null; sourceGroup: string | null }): void` : périme si `entry.id === editingId`,
   ou si le formulaire est une création (`editingId === null`) rattachée à `sourceGroup` et que `entry.translationGroup
   === sourceGroup`. Les neuf `handleDelete…` l'appellent à la place de leur bloc.
8. **Q4 (R148).** Garde de `smoke-http.test.sh` réécrite, **sur toutes les lignes** (commentaires et heredocs compris)
   de `audit-prod.sh` et des trois smokes : le mot `AUDIT_BASIC_AUTH` n'y figure que sous les deux formes exactes
   (blancs de tête quelconques, comparés après retrait de l'indentation, plus de décompte à deux espaces), l'appel une
   fois par script. Seconde garde, mêmes fichiers plus `tools/lib/smoke-http.sh` hors de `smoke_write_auth_config` :
   aucune ligne n'appelle `declare -p`, `printenv`, `env` (commande), `set` seul ou suivi de `|`/`>`, `export -p`, ni une
   indirection `${!` (motifs ancrés sur des limites de mot, chacun prouvé par un cas qui rougit et un voisin qui passe :
   `env` dans `environment`, `set -e`, `set +x`). Les en-têtes `Env :` des trois smokes et de l'audit disent « la Basic
   Auth de préprod : voir `tools/lib/smoke-http.sh` » ; l'en-tête de la bibliothèque, seul à nommer la variable, dit
   toutes ses règles (retour à la ligne, sans « : », utilisateur ou mot de passe vide ou fait de blancs, définie vide).
9. **CR12-10, C12-9, C12-10, St12-14 (R148).** `smoke_fetch` : `_smoke_auth=(-K "$SMOKE_AUTH_CONFIG" --proto-redir
   =https)` ; avec `SMOKE_AUTH_CONFIG` posé, sortie 2 sans appel si un argument de `"$@"` est `-L`, `--location`,
   `--location-trusted`, `--url`, `--url=…` ou contient `://` sans commencer par `https://` (« <libellé> : option de
   redirection ou URL non https:// avec la Basic Auth — rien de vérifié »). `smoke_write_auth_config` refuse un
   utilisateur ou un mot de passe fait seulement de blancs (même message que le vide, si Q6 retenue), puis
   `export -n -- "$_smoke_name"` une fois le fichier écrit ; elle devient privée (`_smoke_write_auth_config`), seule
   `smoke_use_auth_config` l'appelle. `check_*` de `tools/tests/lib/smoke-cases.sh` : paramètres nommés par
   variables d'environnement locales (`CASE_LABEL=… CASE_RC=… check_empty_basic_auth`) ou un tableau associatif, au
   choix de l'agent, dit au rendu.
10. **CR12-9 (R149).** Une sonde de redirection = **un** `curl -w '%{http_code} %{redirect_url}'`, lu par `read -r code
    target` ; l'indice 401 n'est imprimé que si l'audit porte la Basic Auth (un drapeau `audit_with_basic_auth=1` posé
    dans le bloc `if [[ -v AUDIT_BASIC_AUTH ]]`), si Q5 retenue ; chaque `curl` de l'audit est soit assigné, soit en
    ligne, une forme pour toutes les sondes (assigné).

**Pourquoi cet ordre (R137 → R141).** Les huit formulaires (neuf sites) sont touchés par Q1 et Q2. Les deux refactorings
d'abord, sans changer le comportement, gardés par la suite existante et une mutation chacun : R137 dans le composable
existant (noms, type de l'identité, refus exposé), puis R138 qui rassemble la prose de chaque formulaire dans
`useTranslatedForm`. Q1 (R139) vient ensuite : la génération s'écrit **une fois**, dans le composable qui compose
l'identité, et chaque remise à zéro gagne un seul appel (`renewForm()`). L'inverse (génération d'abord) l'aurait écrite
dans neuf `formIdentity` que R138 aurait aussitôt retirés : deux passes sur les neuf sites, et un test rouge de Q1 dont
la preuve aurait porté sur du code jeté. C12-8 (R140) s'appuie sur le composable de R138 ; les réglages (R141) et les
textes (R142) ne touchent qu'à leurs propres fichiers une fois le composable en place.

**Questions à l'auteur** (le plan suit chaque recommandation ; une autre réponse ne change que la tâche nommée) : voir le
rendu du plan au fil principal, repris ici en une ligne chacune.

- **Q1 (R140, C12-8)** : périmer la traduction quand l'entrée source d'une création rattachée est supprimée. Recommandé :
  (a) seulement pour une création rattachée à son groupe ; (b) toute suppression d'un membre du groupe source ; (c)
  écrire la limite.
- **Q2 (R142, C12-6)** : textes. Recommandé : `proseReadonly` FR « Traduction en cours : les champs de texte sont en
  lecture seule jusqu'à la réponse, qui les remplace par la proposition ; l'entrée enregistrée ne change pas. » EN
  “Translation in progress: the text fields are read-only until the answer, which replaces them with the proposal; the
  saved entry does not change.” ; `discardPending` FR « La traduction précédente, abandonnée, se termine côté serveur :
  ce bouton revient dès sa réponse, qui ne change rien au formulaire. » EN “The previous translation, abandoned, is still
  finishing on the server: this button comes back as soon as it answers, and the answer changes nothing in the form.”
- **Q3 (R143, CR12-4)** : recommandé (a) écrire la limite (toute lecture estampillée après le montage clôt le
  chargement) ; (b) estampiller à part les lectures relancées par un geste.
- **Q4 (R150, Sp12-1)** : les 87 lignes dont un seul littéral fait la longueur. Recommandé : l'appel coupé pour que le
  littéral tienne seul sur sa ligne ; un littéral qui dépasse encore seul reste, listé comme une ligne de table.
- **Q5 (R149, CR12-9)** : l'indice 401 imprimé seulement quand l'audit porte la Basic Auth. Recommandé : oui.
- **Q6 (R148, C12-9)** : un utilisateur ou un mot de passe fait de blancs refusé avec le message du vide. Recommandé : oui.
- **Q7 (St12-11)** : « décision 1 » dans un nom de test reste permis (brief, contraintes de la onzième série). Recommandé :
  le garder ; sinon R144 qualifie « décision 1 de la onzième relecture ».
- **Q8 (R139, CR12-2)** : l'erreur d'une traduction effacée à **tout** changement d'identité (« Modifier » une autre
  entrée l'efface aussi), pas seulement au changement de génération. Recommandé : oui (seule forme qui ferme A, B, A).

**Réponses de l'auteur (2026-10-31)** : Q1 à Q8 → **les huit recommandations** (Q1 (a), Q2 ces textes, Q3 (a),
Q4 (a), Q5 oui, Q6 oui, Q7 le garder, Q8 oui). Le plan les suit déjà : aucune tâche ne change.

### Review Focus (douzième relecture)

1. **Un formulaire vierge enregistré pendant une traduction** (clic sur « Enregistrer », réponse du dépôt de création
   avant celle du modèle) : la réponse du modèle ne pose rien, le formulaire reste vide, le bouton dit l'attente puis
   revient. Test : R139.
2. **« Modifier » A, traduction en échec (503), « Modifier » B, puis A** : aucune erreur de traduction affichée ; une
   frappe dans la prose de A, elle, ne l'efface pas. Test : R139.
3. **Un `translate` émis par programme pendant chaque condition du bouton** : refusé, et le bouton désactivé, par la
   même source (une condition retirée du `computed` rougit les deux cas). Test : R137.
4. **Un smoke avec la Basic Auth appelé avec `-L` ou une URL `http://` dans ses arguments** : sortie 2 sans appel, la
   valeur jamais citée ; un `-K` toujours suivi de `--proto-redir =https`. Test : R148.
5. **Une page future qui charge par `onMounted(load)`, `nextTick(load)`, un `onMounted` importé sous un alias ou un
   `watch` en `flush: 'post'`** : la garde rougit en nommant le fichier et l'appel. Test : R144.

### Contraintes propres aux tâches R137 à R151

- Les **Global Constraints** et toutes les contraintes des séries précédentes valent (« Contraintes propres aux tâches
  R121 à R136 », reprises ici sauf ce qui suit). Exécution : **un agent par tâche, successifs dans l'ordre R137 → R151**,
  qui lit d'abord `tasks/relectures-748/brief-commun-agents.md`, puis **cette section** (« Corrections de la douzième
  relecture ») en entier jusqu'à la première tâche, `tasks/relectures-748/relecture-12-constats.md`, puis sa tâche.
- **Rien ne se coche** dans ce plan (R127b l'a fait) ; **jamais `git commit --amend`, `git reset`, `git checkout-index`**
  ni aucune commande git qui réécrit l'index ou l'arbre ; mutations **en place par Python seulement** (`python3 -I`),
  restaurées depuis une copie vérifiée par `cmp` ; cache de test backend vidé **dans le conteneur** — un `rm -rf
  var/cache/test` sur l'hôte désynchronise le montage : `docker compose restart backend`.
- **« ≤ 140 » est la longueur d'une ligne, en caractères** (`awk 'length>140'`), **pas la taille d'un diff** ni un nombre
  de lignes changées (R121 l'a mal lu). Toute ligne écrite ou touchée par la série y est soumise, règles comprises ; une
  ligne de table Markdown ou une ligne de JSON ne se coupe pas (le dire au rendu).
- Identifiants de constat : permis dans un commentaire, **interdits dans un nom de test** ; un nom de test peut citer le
  ticket, une tâche ou une décision.
- Frontend (R137 à R146) : lire avec `Read` `frontend.md`, `frontend-a11y.md`, `frontend-backoffice.md` (et `ai.md`
  avant `useAdminTranslation.ts`, `frontend-session-refus.md` avant R143 et R144) ; ciblé `docker compose exec -T
  frontend npm test -- <chemins>`, puis `make front-test`, `make front-lint`, `make front-build`, codes lus sans pipe ;
  `docker compose restart frontend` après un fichier créé ou un renommage de masse.
- Backend (R147) : lire avec `Read` un fichier de `backend/tests/Security/` et `security-authentication.md` ; ciblé
  `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis `make back-test`, `make back-quality` ;
  `make back-style-fix` après un import.
- Outillage (R148, R149) : lire avec `Read` les scripts touchés et `deploiement-nginx.md` ; chaque test hors ligne
  touché, `; echo "exit=$?"` ; shellcheck dans l'image épinglée du job `tools-tests` (commande de la onzième série).
- Règles : `tools/check-claude-rules.sh` après chaque modification ; tailles au départ (HEAD 902bd8b8) :
  `frontend-backoffice.md` 31 008, `frontend-session-refus.md` 14 819, `frontend-session.md` 16 047,
  `frontend-a11y.md` 13 050, `security-authentication.md` 10 156, `security-authentication-sessions.md` 22 283,
  `deploiement-nginx.md` 30 058, `frontend-session-retour.md` 17 701, `frontend-session-bandeau.md` 12 839,
  `frontend.md` 19 358, `mcp-oauth.md` 40 874, `mcp-oauth-revocation-personne.md` 39 841, **`security-user.md` 47 914**
  (R150 seule y touche, en coupant deux lignes : la taille notée, < 50 000), **`backoffice-api.md` 39 834** (non touchée).

### Fichiers (tâches R137 à R151)

| Fichier | Responsabilité | Tâches |
|---|---|---|
| `useAdminTranslation.ts`, `domain/admin/translation/entities/FormIdentity.ts` (créé), les huit fichiers de formulaires, `useAdminTranslation.spec.ts`, `entryFormLock.ts` | noms, identité typée, un seul `computed` du refus | R137 |
| `presentation/ui/admin/useTranslatedForm.ts` (créé), `presentation/ui/admin/describedBy.ts` (créé), `presentation/pages/admin/proseFields.ts` (créé), `useEntryFormLock.ts`, `TranslateEntryButton.vue`, les huit fichiers, `entryFormLock.ts`, six specs, `AdminAboutPage.spec.ts`, `useTranslatedForm.spec.ts` (créé) | la prose d'un formulaire en un composable | R138 |
| `useTranslatedForm.ts`, `useAdminTranslation.ts`, les huit fichiers (remises), `entryFormLock.ts`, `useAdminTranslation.spec.ts`, règle | génération du formulaire, erreur effacée | R139 |
| `useTranslatedForm.ts`, les sept fichiers par entrée (`handleDelete…`), `entryFormLock.ts` | suppression de l'entrée source | R140 |
| `AdminAboutSettingsForm.vue`, `fr.json`/`en.json`, `AdminAboutPage.spec.ts`, règle | réglages : appel mort, clés, noms, commentaire, spec sans `vm` | R141 |
| `frontend/tests/support/adminTranslationTexts.ts` (créé), `entryFormLock.ts`, `AdminAboutPage.spec.ts`, `fr.json`/`en.json` | textes lus de `fr.json` ; textes justes | R142 |
| `backofficeAccessRefusal.ts`, `backofficeSessionExpiry.ts`, `AccessRefusedNotice.vue`, leurs specs, `frontend-session-refus.md` | état d'un bloc, lecture pure, limite écrite | R143 |
| `sourceText.ts`, `sourceText.spec.ts`, `noLoadInOnMounted.spec.ts`, `frontend-session-refus.md` | la garde des chargements voit plus, ses limites écrites | R144 |
| `AdminLayout.sessionEnded.spec.ts`, six specs (`dirtyOrder`) | hygiène des specs | R145 |
| `frontend/tests/support/vueCompilerAlignment.spec.ts` (créé), `frontend.md` | `@vue/compiler-dom` aligné sur `vue` | R146 |
| `PathIsDecidedCanonicallyTest.php`, `tests/Support/MemberCalls.php` (créé), `AuthorizationSites.php`, `CompiledNestedReading.php`, `CanonicalPath.php` (docblock), `security-authentication.md` | la garde du chemin canonique | R147 |
| `tools/lib/smoke-http.sh`, les quatre scripts, `tools/tests/{smoke-http,audit-prod,smoke-*}.test.sh`, `tools/tests/lib/smoke-cases.sh`, `deploiement-nginx.md` | la Basic Auth : garde complète, redirections, blancs, export | R148 |
| `tools/audit-prod.sh`, `tools/tests/audit-prod.test.sh`, `deploiement-nginx.md` | une sonde, un `curl`, l'indice 401 en préprod seulement | R149 |
| fichiers que la liste de R150 désigne | lignes > 140 de #748 | R150 |
| `.claude/rules/*`, fichiers désignés | balayage | R151 |

---

### Task R137 : Traduction assistée — un seul `computed` du refus, une identité typée, des noms qui disent l'état (Q2 : St12-1 = CR12-7 ; St12-3, St12-4 ; refactoring)

**Files :**
- Create : `frontend/src/domain/admin/translation/entities/FormIdentity.ts`
- Modify : `frontend/src/application/admin/translation/useAdminTranslation.ts`, les huit fichiers de formulaires
  (`AdminCaseStudiesPage`, `AdminIncidentsPage`, `AdminContributionsPage`, `AdminAnonymousCvPage`, `AdminQualityPage`,
  `AdminAboutMeCardsSection`, `AdminAboutSiteCardsSection`, `AdminAboutSettingsForm`)
- Test : `frontend/tests/application/admin/translation/useAdminTranslation.spec.ts`,
  `frontend/tests/domain/admin/translation/FormIdentity.spec.ts` (créé)

**Interfaces :**
- Produces : choix tranchés 1 à 3 — `FormIdentity`, `sameFormIdentity`, `UseAdminTranslationResult.isRefused`,
  `formIdentity?: () => FormIdentity`. Chaque formulaire passe `generation: 0` (R139 le remplace).

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre de tests noté.
- [ ] **Step 2 : tests** — `FormIdentity.spec.ts` : `it("deux identités égales champ par champ sont la même, quel que
  soit l'ordre des clés (#748, R137)")`, et un cas par champ qui diffère (`entry`, `locale`, `group`, `generation`) ;
  `useAdminTranslation.spec.ts` : `it("isRefused suit le prédicat de la page (#748, R137)")` (une `ref` basculée : `isRefused.value`
  suit, `translate()` rend `null` sans requête). Expected : FAIL, `FormIdentity` et `isRefused` introuvables.
- [ ] **Step 3 : implémenter** selon les choix 1 à 3 ; docblocks du composable réécrits avec les nouveaux noms ;
  `:disabled` des neuf boutons = le refus exposé.
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) `|| isOrderDirty.value` retiré du seul
  prédicat d'`AdminCaseStudiesPage.vue` → **deux** cas rougissent, « un ordre modifié : translate émis » et le
  bouton désactivé pendant l'ordre modifié (preuve que le bouton lit la même source ; ce second cas,
  s'il n'existe pas, s'écrit au Step 2) ; (b) `a.group === b.group` retiré
  de `sameFormIdentity` → le cas « Créer la version XX » pendant une traduction rougit ; restaurés.
- [ ] **Step 5 : verts** — nombre filet + nouveaux cas ; `make front-lint; echo "exit=$?"`, `make front-build; echo
  "exit=$?"` ; `grep -rn "JSON.stringify\|inFlight\|callRunning" frontend/src/application/admin/translation` : vide ;
  `grep -c "isProposalLocked" frontend/src/presentation/pages/admin/AdminIncidentsPage.vue` : une de moins qu'avant.
- [ ] **Step 6 : commit** — `refactor(frontend): la traduction assistée lit son refus d'une seule source, son identité typée, ses états nommés (#748)`.

---

### Task R138 : Traduction assistée — la prose d'un formulaire en un composable ; `describedBy` à sa place (Q2 : St12-2 = CR12-8 ; St12-6 ; refactoring)

**Files :**
- Create : `frontend/src/presentation/ui/admin/useTranslatedForm.ts`, `frontend/src/presentation/ui/admin/describedBy.ts`,
  `frontend/src/presentation/pages/admin/proseFields.ts`, `frontend/tests/presentation/ui/admin/useTranslatedForm.spec.ts`
- Modify : `useEntryFormLock.ts` (export de `describedBy` retiré, import du nouveau module), `TranslateEntryButton.vue`,
  les huit fichiers de formulaires, `frontend/tests/support/entryFormLock.ts` (`ids.prose` → `proseFields` +
  `fieldIdPrefix`), les six specs qui déclarent un `EntryFormLockCase`, `AdminAboutPage.spec.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant » : `TranslationReadonlyHint` et la
  prose en lecture seule nomment `useTranslatedForm`)

**Interfaces :**
- Consumes : R137. Produces : choix tranchés 4 et 5 — `useTranslatedForm`, `proseFieldId`, `proseFieldAttrs`,
  `describedBy` (nouveau module), `ariaDescribedby` prop.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté ; liste des identifiants relevée
  (`grep -rhoE "admin-[a-z-]+-(prose-readonly|translation)-hint|id=\"admin-[a-z-]+\"" frontend/src/presentation/pages/admin | sort`)
  dans un fichier du scratchpad.
- [ ] **Step 2 : test du composable** — `useTranslatedForm.spec.ts` : `proseFieldId('admin-incident', 'rootCause')` vaut
  `admin-incident-root-cause` ; `proseFieldAttrs()` hors traduction `{ readonly: false, 'aria-describedby': undefined }`,
  pendant `{ readonly: true, 'aria-describedby': '<prefix>-prose-readonly-hint' }` ; `proseFieldAttrs('aide')` pendant :
  `'aide <prefix>-prose-readonly-hint'`, hors : `'aide'`. Expected : FAIL, module introuvable.
- [ ] **Step 3 : implémenter** selon les choix 4 et 5 ; chaque champ de prose : `v-bind="proseFieldAttrs()"` (ou
  `proseFieldAttrs('<id de son aide>')`) ; `TranslationReadonlyHint :id="proseReadonlyHintId"`,
  `TranslateEntryButton :hint-id="translationHintId"`. Docblock du composable : ce qu'il tient, ce que la page garde
  (le formulaire, la proposition, la suppression).
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) `readonly: isTranslating.value` →
  `readonly: false` dans `proseFieldAttrs` → le cas partagé « chaque champ de prose est en lecture seule » rougit sur les
  huit formulaires et les réglages ; (b) un champ retiré d'`INCIDENT_PROSE_FIELDS` → le cas rougit en nommant
  `#admin-incident-…` (preuve que le spec lit la liste de la page) ; restaurés.
- [ ] **Step 5 : verts** — même nombre de tests (± les cas du composable, dits) ; liste des identifiants relevée de
  nouveau : identique à celle du Step 1 (`diff` vide), en lisant cette fois le DOM rendu si les gabarits ne portent plus
  les littéraux (cas partagé : chaque `proseFieldId` existe dans le formulaire) ; `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "PROSE_READONLY_HINT_ID\|as string | undefined\|ids.prose\|from './useEntryFormLock'" frontend/src frontend/tests` :
  `describedBy` importé de `describedBy.ts` seul, le reste vide.
- [ ] **Step 6 : commit** — `refactor(frontend): la prose d'un formulaire traduit tient en un composable, describedBy dans son module (#748)`.

---

### Task R139 : Traduction assistée — une remise à zéro du formulaire périme la traduction en vol ; l'erreur ne reparaît pas (Q1 (b) : CR12-1 = C12-1 ; CR12-2 = Sp12-2 ; C12-5, St12-16)

**Files :**
- Modify : `useTranslatedForm.ts` (`generation`, `renewForm`), `useAdminTranslation.ts` (`failure` effacé au changement
  d'identité), les huit fichiers (chaque `resetForm`, `resetPrincipleForm`, `resetTraitForm` appelle `renewForm()`)
- Test : `useAdminTranslation.spec.ts`, `frontend/tests/support/entryFormLock.ts` (deux cas partagés)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« Translation assistant »)

**Interfaces :**
- Consumes : R138. Produces : choix tranché 6 — `renewForm(): void`.

- [ ] **Step 1 : tests, rouges** (Review Focus 1 et 2). `entryFormLock.ts` (huit formulaires) :
  `it(\`${prefix}un formulaire vierge enregistré pendant une traduction : la réponse ne pose rien\`)` (le remplissage du
  cas « langue d'une création », traduction différée, « Enregistrer » servi en succès, réponse du modèle,
  `flushPromises()` → champs de prose vides, aucun avis `role="status"` de proposition, sélecteur de langue en FR) ;
  `it(\`${prefix}une traduction en échec, une autre entrée ouverte, puis la première : aucune erreur\`)` (dépôt qui
  rejette `AdminTranslationError('unavailable')` sur l'entrée A, « Modifier » B, « Modifier » A → aucun `role="alert"` de
  traduction ; puis, sur A, une frappe dans la prose après un nouvel échec : l'erreur reste — le cas de R126 gardé).
  `useAdminTranslation.spec.ts` : `it("une identité changée efface l'échec, un retour à elle ne le rend pas (#748, R139)")`.
  Run : `docker compose exec -T frontend npm test -- tests/application/admin/translation tests/presentation/pages/admin`.
  Expected : FAIL — la proposition posée sur le formulaire vidé ; l'erreur réaffichée au retour sur A.
- [ ] **Step 2 : implémenter** selon le choix tranché 6 ; docblock de `formIdentity` : la génération, ce qu'elle couvre
  (toute remise à zéro, présente ou future), ce que `discard()` couvre encore.
- [ ] **Step 3 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) l'appel `renewForm()` retiré du seul
  `resetForm` d'`AdminContributionsPage.vue` → seul son cas « formulaire vierge enregistré » rougit ; (b) le guetteur qui
  efface `failure` retiré → le cas « une autre entrée ouverte, puis la première » rougit sur les huit ; restaurés.
- [ ] **Step 4 : règle** — dans « Translation assistant », la phrase de R124 (« and a response whose form no longer has the
  identity read at send time (the entry, the locale, the source group: `formIdentity`, never the prose) … ») gagne « and
  the form's generation, advanced by every reset of the form (R139, decision 1 of the twelfth review) », et la phrase de
  R126 « cleared only by a real change of form or the next translation » devient « cleared by any change of identity or
  the next translation, never brought back by a return to it (R139) ». **St12-16** : la phrase de « Proposer la version
  XX » (de « so are « Proposer la version XX » (R93 … » jusqu'à « … `admin.translation.discardPending`) ») est **réécrite**
  en phrases courtes, une par fait, sans parenthèse de plus d'une ligne. **C12-5** : « **one model call in flight per
  form** » gagne « per mounted form: a page left and reopened, or a second tab, starts its own (bounded by the 30/h quota,
  accepted) ». Lignes ≤ 140. Taille notée.
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(frontend): une remise à zéro du formulaire périme la traduction en vol, et l'erreur d'une traduction ne reparaît pas (#748)`.

---

### Task R140 : Traduction assistée — supprimer l'entrée source d'une création rattachée périme la traduction (C12-8 ; Q1 (a))

**Files :**
- Modify : `useTranslatedForm.ts` (`discardIfDeleted`), les sept fichiers par entrée (`handleDelete…`, deux dans
  `AdminQualityPage.vue`)
- Test : `frontend/tests/support/entryFormLock.ts` (`deleteSourceEntry` dans `EntryFormLockCase`), les six specs
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (« a deleted entry being discarded by `discard()` (R124) »)

**Interfaces :**
- Consumes : R138. Produces : choix tranché 7. `EntryFormLockCase.deleteSourceEntry: (wrapper: VueWrapper) =>
  Promise<void>` (après « Créer la version XX », supprime l'entrée de l'autre langue de la ligne ; `window.confirm` à
  `true`).

- [ ] **Step 1 : tests, rouges.** `entryFormLock.ts` (huit formulaires) :
  `it(\`${prefix}supprimer l'entrée source d'une création rattachée pendant une traduction : la réponse ne pose rien\`)`
  (« Créer la version XX », prose saisie, traduction différée, `deleteSourceEntry`, réponse → aucun avis, aucun champ
  rempli) ; et le voisin qui passe : en édition de l'entrée EN, supprimer l'entrée FR du même groupe **ne** périme
  **pas** la traduction (la proposition est posée). Expected : FAIL sur le premier.
- [ ] **Step 2 : implémenter** selon le choix tranché 7 ; les blocs `if (x.id === editingId.value) discardTranslation()`
  disparaissent.
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : la branche « création rattachée » retirée
  de `discardIfDeleted` → le premier cas rougit sur les huit, le voisin reste vert ; restauré.
- [ ] **Step 4 : règle** — « a deleted entry being discarded by `discard()` (R124) » → « a deleted entry being discarded
  by `discardIfDeleted()`: the entry being edited, or the source of a creation attached to its group (R124, R140) ».
  Taille notée.
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`,
  `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -c "discardTranslation" frontend/src/presentation/pages/admin/*.vue` : 0 hors `AdminAboutSettingsForm.vue`
  (que R141 traite).
- [ ] **Step 6 : commit** — `fix(frontend): supprimer l'entrée source d'une création rattachée périme la traduction en vol (#748)`.

---

### Task R141 : Réglages « À propos » — l'appel mort retiré, un seul mot pour l'abandon, des clés à leur place, un spec qui lit le DOM (St12-7, St12-8, C12-7, St12-9 = Sp12-5)

**Files :**
- Modify : `AdminAboutSettingsForm.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`
- Test : `frontend/tests/presentation/pages/admin/AdminAboutPage.spec.ts`
- Modify (règle) : `.claude/rules/frontend-backoffice.md` (nom des clés des réglages)

**Interfaces :**
- St12-7 : `discardTranslation()` retiré de l'abandon (et `discard` de la déstructuration) ; la docstring de `discard`
  (composable) nomme son seul appelant, `discardIfDeleted` (R140). St12-8 : `admin.about.settingsProposalLockedHelp` →
  `admin.about.settings.proposalLockedHelp`, `admin.about.settingsProposalDiscard` → `admin.about.settings.proposalDiscard`
  (FR et EN, valeurs inchangées) ; `handleAbandonProposal` → `handleDiscardProposal`. C12-7 : le commentaire de
  l'identité dit « une autre langue ». St12-9 : `:1320` et `:1747` vérifient la proposition par le DOM (l'avis
  `role="status"` et la valeur des champs), `:1354` vérifie que le bouton « Proposer » est décrit (`describedTexts`) par un
  texte qui contient le libellé du bouton « Abandonner » rendu (lu sur le bouton, pas dans une constante).

- [ ] **Step 1 : filet** — `docker compose exec -T frontend npm test -- tests/presentation/pages/admin/AdminAboutPage.spec.ts`,
  nombre noté.
- [ ] **Step 2 : réécrire les trois cas** (St12-9) ; verts sur le code actuel.
- [ ] **Step 3 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) la proposition retirée par
  `handleDiscardProposal` laissée (`proposal.value = null` retiré) → les cas de `:1320`/`:1747` rougissent dans le DOM ;
  (b) le libellé du bouton « Abandonner » changé dans `fr.json` → le cas de `:1354` rougit ; restaurés.
- [ ] **Step 4 : implémenter** St12-7, St12-8, C12-7 ; preuve de St12-7 : la suite reste verte sans l'appel (le dire au
  rendu, avec le nombre de tests).
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"` (la règle i18n voit les
  clés renommées), `make front-build; echo "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "settingsProposal\|handleAbandonProposal\|as unknown as { proposal" frontend/src frontend/tests .claude/rules` : vide.
- [ ] **Step 6 : commit** — `refactor(frontend): réglages « À propos », l'abandon a un nom et ses clés leur place, l'appel mort retiré, son spec lit le DOM (#748)`.

---

### Task R142 : Traduction assistée — les textes des specs lus de `fr.json`, et des textes justes (St12-10 ; C12-6 = Sp12-9, Q2)

**Files :**
- Create : `frontend/tests/support/adminTranslationTexts.ts` (sur le modèle d'`accessRefusalTexts.ts` : chaque texte lu de
  `fr.json` par sa clé, jamais recopié)
- Modify : `frontend/tests/support/entryFormLock.ts` (les sept constantes des l. 15-61), `AdminAboutPage.spec.ts` (`:1225`),
  `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`admin.translation.proseReadonly`, `admin.translation.discardPending`)
- Modify (règle) : `.claude/rules/frontend-backoffice.md` si elle cite ces textes (`grep` d'abord)

**Interfaces :**
- Exporte `ENTRY_LOCK_EDITING_FR`, `ENTRY_LOCK_PROPOSAL_FR`, `DISCARD_PENDING_FR`, `PROSE_READONLY_FR`,
  `TRANSLATION_UNAVAILABLE_FR`, `LOCALE_HELP_FR`, `SETTINGS_PROPOSAL_LOCKED_FR` (noms des constantes actuelles suffixés
  `_FR`, lus de la clé). Q2 : textes FR et EN de la question 2, mot pour mot.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : refactorer** les constantes (St12-10) ; suite verte, même nombre.
- [ ] **Step 3 : test des textes, rouge** — `adminTranslationTexts.spec.ts` (créé) : `PROSE_READONLY_FR` ne contient pas
  « n'est perdu » et contient « remplace » ; `DISCARD_PENDING_FR` commence par « La traduction précédente » ; les clés EN
  existent et diffèrent des FR. Expected : FAIL sur les textes actuels.
- [ ] **Step 4 : textes** FR et EN de Q2 ; vert.
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`, `make front-build; echo
  "exit=$?"` ; `grep -rn "Rien de ce qui est écrit\|Nothing written is lost\|La traduction annulée" frontend .claude/rules` : vide ;
  `grep -n "= \"\|= '" frontend/tests/support/entryFormLock.ts | grep -i "traduction\|langue"` : vide.
- [ ] **Step 6 : commit** — `fix(frontend): les textes de la traduction disent ce qui arrive à la saisie et d'où vient l'attente ; les specs les lisent de fr.json (#748)`.

---

### Task R143 : Le refus du backoffice — un état d'un bloc, une lecture pure pour les specs, la limite de la clôture écrite (St12-5 ; CR12-4, Q3 (a) ; Sp12-3, Sp12-8, St12-16)

**Files :**
- Modify : `frontend/src/application/admin/shared/backofficeAccessRefusal.ts`, `AccessRefusedNotice.vue` (docblock)
- Test : `backofficeAccessRefusal.spec.ts`, `AccessRefusedNotice.spec.ts` (les lectures d'état), `AdminLayout.sessionEnded.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-refus.md` (l. 84-96, section « Tests »)

**Interfaces :**
- St12-5 : `interface RefusalModuleState { scope: number; epoch: number; isLandingOpen: boolean }`, une seule `let state`,
  `INITIAL_STATE` figé, chaque remise par `state = { ...INITIAL_STATE, … }` ; `currentRefusalScope(): ReadRefusalScope`
  (requête pure, sans effet) exportée pour les lecteurs qui n'envoient rien ; `stampBackofficeRead()` reste la commande
  d'envoi. Les specs qui lisent la portée sans envoyer passent à `currentRefusalScope()`.
- CR12-4 / Sp12-3 : docblocks et règle disent « la première lecture **estampillée** après le montage — une lecture que la
  personne relance, mais aussi une section rendue par un `v-if`, un watcher, un rejeu — clôt le chargement de la page
  d'arrivée » ; plus aucun « Réessayer » (aucune page admin ne l'a) ; la limite : « accepté : le texte rendu ensuite est
  `common.accessRefused`, vrai aussi » (décision Q3 (a)).

- [ ] **Step 1 : filet** — specs ciblés, nombre noté.
- [ ] **Step 2 : test de la lecture pure, rouge** — `backofficeAccessRefusal.spec.ts` : `it("currentRefusalScope ne clôt
  pas le chargement de la page d'arrivée (#748, R143)")` (écriture refusée, `settle`, `currentRefusalScope()` deux fois,
  puis une lecture de la page d'arrivée refusée → l'avis « enregistrement précédent » gardé). Expected : FAIL, introuvable.
- [ ] **Step 3 : implémenter** St12-5 ; specs basculés.
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) `currentRefusalScope` qui appelle
  `stampBackofficeRead()` → le cas du Step 2 rougit ; (b) `isLandingOpen` oublié dans `clearBackofficeAccessRefusal`
  (le champ non remis) → le cas existant de l'effacement rougit ; restaurés.
- [ ] **Step 5 : règle** — l. 84-96 **remplacées** (Sp12-3, CR12-4 ; St12-16 : la limite dans une puce « Accepted
  limits » distincte, plus « Accepted, held by the review: » collé à la règle) ; section « Tests » : R122
  (`backofficeAccessRefusal.spec.ts`, `AdminLayout.sessionEnded.spec.ts`) et `noLoadInOnMounted.spec.ts` (R121) cités
  (Sp12-8). Lignes ≤ 140. Taille notée.
- [ ] **Step 6 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`, `make front-build; echo
  "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ; `grep -rn "Réessayer" frontend/src/application/admin/shared
  frontend/src/presentation/ui/admin/AccessRefusedNotice.vue` : vide ; `grep -c "^let " backofficeAccessRefusal.ts` : 1.
- [ ] **Step 7 : commit** — `refactor(frontend): le refus du backoffice tient son état d'un bloc et se lit sans effet ; la clôture du chargement écrite telle qu'elle est (#748)`.

---

### Task R144 : La garde des chargements dans un `onMounted` voit les références, les alias, les différés, les composants (CR12-3 = Sp12-4 = C12-3 ; St12-11)

**Files :**
- Modify : `frontend/tests/support/sourceText.ts`, `frontend/tests/presentation/pages/admin/noLoadInOnMounted.spec.ts`
- Test : `frontend/tests/support/sourceText.spec.ts`
- Modify (règle) : `.claude/rules/frontend-session-refus.md` (phrase de R121 : ce que la garde voit, `paths:` si un fichier change de place)

**Interfaces :**
- `callsInsideHook(source, hooks: readonly string[])` → `deferredCallsOf(source: string): string[]` : le *callee* de
  chaque appel écrit dans une fonction passée à un **hook différé** — `onMounted` sous son nom importé de `vue`, alias
  compris (lu dans l'`ImportDeclaration`, pas par regex), `nextTick`, `setTimeout`, `queueMicrotask`,
  `requestAnimationFrame`, `watchPostEffect`, et le rappel d'un `watch`/`watchEffect` dont l'objet d'options porte
  `flush: 'post'` — **et** le nom passé par référence (`onMounted(load)` → `['load']`). `syntaxTreeOf` cesse d'être
  exporté s'il n'a pas d'autre lecteur (`grep` d'abord). Les alias de `load` / `reload` lus dans l'arbre
  (`ObjectBindingPattern`, `propertyName`), plus par regex ; `'load' === name` retiré. Périmètre :
  `presentation/pages/admin/` **et** `presentation/ui/admin/` ; garde-fous mis à jour (au moins douze fichiers, au
  moins un hook trouvé). Docblock : la liste exacte de ce qui reste invisible (un `await` avant l'envoi, un sous-composant
  hors de ces deux dossiers, une fonction d'un autre module qui charge, un hook passé par une variable).

- [ ] **Step 1 : tests, rouges** (Review Focus 5) — `sourceText.spec.ts` : `onMounted(load)` → `['load']` ;
  `import { onMounted as whenMounted } from 'vue'; whenMounted(() => go())` → `['go']` ; `nextTick(() => a())`,
  `setTimeout(load, 0)` → `['a']`, `['load']` ; `watch(x, () => b(), { flush: 'post' })` → `['b']`, sans `flush` → `[]` ;
  `const f: { load: () => void } = …` ne crée pas d'alias. Expected : FAIL, `deferredCallsOf` introuvable.
- [ ] **Step 2 : implémenter** ; renommages par `python3 -I`, `docker compose restart frontend`.
- [ ] **Step 3 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) `onMounted(load)` ajouté à
  `AdminIncidentsPage.vue` ; (b) `nextTick(load)` dans le `setup` d'`AdminContributionsPage.vue` ; (c) un `onMounted`
  importé sous un alias qui appelle `load()` dans `AdminQualityPage.vue` ; (d) un appel de dépôt dans un `onMounted`
  d'un composant de `presentation/ui/admin/` → chaque fois la garde rougit en nommant fichier et appel ; restaurés.
- [ ] **Step 4 : règle** — la phrase de R121 dit ce que la garde lit (les hooks différés nommés ci-dessus, les deux
  dossiers) et renvoie au docblock pour les limites. Taille notée.
- [ ] **Step 5 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`, `make front-build; echo
  "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"` ; `grep -rn "callsInsideHook\|'load' === name" frontend/tests` : vide.
- [ ] **Step 6 : commit** — `test(frontend): la garde des chargements après le montage voit références, alias, différés et composants du backoffice (#748)`.

---

### Task R145 : Hygiène des specs — le montage de « Qualité » en une ligne, l'ordre modifié par « ↓ » (Sp12-7 ; St12-17 = Sp12-11 ; refactoring de tests)

**Files :**
- Modify : `frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts`, les six specs qui déclarent `dirtyOrder`

**Interfaces :**
- Sp12-7 : `qualityProviders(auth)` (les trois dépôts) ; `mountAdminQuality` tient sur une ligne :
  `(auth) => mountAdminPage(AdminQualityPage, 'quality', qualityProviders(auth)).then(({ wrapper }) => wrapper)`.
- St12-17 : `dirtyOrder` clique le bouton « ↓ » de la première poignée (`OrderHandle`) de la table du formulaire, comme
  le plan de R127 l'avait dit ; `dragRow` reste pour les cas qui testent le glisser-déposer.

- [ ] **Step 1 : filet** — `make front-test; echo "exit=$?"`, nombre noté.
- [ ] **Step 2 : refactorer** selon les interfaces.
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : le bouton « ↓ » d'`OrderHandle.vue` qui
  n'émet plus `move` → les cas « un ordre modifié » des six specs rougissent (ils dépendent bien de « ↓ ») ; restauré.
- [ ] **Step 4 : verts** — même nombre ; `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"` ;
  `grep -rn "dirtyOrder: (wrapper) => dragRow" frontend/tests` : vide.
- [ ] **Step 5 : commit** — `test(frontend): le montage de « Qualité » en une ligne, l'ordre modifié par la flèche du clavier (#748)`.

---

### Task R146 : `@vue/compiler-dom` reste la version de `vue` (C12-11)

**Files :**
- Create : `frontend/tests/support/vueCompilerAlignment.spec.ts`
- Modify (règle) : `.claude/rules/frontend.md` (« Toolchain history » : une phrase, la garde)

**Interfaces :**
- Le spec lit `frontend/package.json` et `frontend/package-lock.json` par `node:fs` : même intervalle déclaré pour
  `vue` et `@vue/compiler-dom` ; dans le lockfile, **une seule** entrée `node_modules/**/@vue/compiler-dom`, de la
  version de `node_modules/@vue/compiler-dom` exigée par `node_modules/vue` (`dependencies`) et égale à celle de `vue`.
  Message : `npm install --save-dev @vue/compiler-dom@<version de vue>`.

- [ ] **Step 1 : test** — vert d'emblée (R130 a aligné les deux).
- [ ] **Step 2 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) l'intervalle de `@vue/compiler-dom`
  changé dans `package.json` → rouge ; (b) une seconde entrée `node_modules/x/node_modules/@vue/compiler-dom` ajoutée au
  lockfile → rouge ; restaurés (`cmp`, puis `git diff` vide sur les deux fichiers).
- [ ] **Step 3 : règle** — taille notée.
- [ ] **Step 4 : verts** — `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`, `make front-build; echo
  "exit=$?"`, `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 5 : commit** — `test(frontend): @vue/compiler-dom reste à la version de vue, une seule installée (#748)`.

---

### Task R147 : Backend — la garde du chemin canonique sans casse ni nom complet manqués, sa forme d'appel écrite une fois (CR12-5 = C12-4 ; CR12-6 = St12-12 ; C12-12 ; St12-13 côté PHP)

**Files :**
- Create : `backend/tests/Support/MemberCalls.php` (`final class MemberCalls` : `isCallAt(array $tokens, int $index):
  bool`, le nom en `$index` précédé de `->`, `?->` ou `::` et suivi de `(`)
- Modify : `backend/tests/Shared/Infrastructure/Http/PathIsDecidedCanonicallyTest.php`, `backend/tests/Support/AuthorizationSites.php`
  (lit `MemberCalls::isCallAt`), `backend/tests/Support/CompiledNestedReading.php` (commentaire au présent),
  `backend/src/Shared/Infrastructure/Http/CanonicalPath.php` (docblock d'`isPathUnder()` si la limite y est dite)
- Modify (règle) : `.claude/rules/security-authentication.md` (l. 85 : les lectures non vues)

**Interfaces :**
- CR12-5 : `\in_array(strtolower($token->text), self::FORBIDDEN_CALLS_LOWER, true)` (liste en minuscules). CR12-6 :
  `canonicalReadsIn` compte `CanonicalPath::of(` en nom court, `T_NAME_QUALIFIED` ou `T_NAME_FULLY_QUALIFIED` dont le
  dernier segment est `CanonicalPath`, `of` comparé sans casse, comparaisons Yoda ; cas `#[DataProvider]` dédiés
  (`testTheCanonicalReadCounterSeesEachForm`). C12-12 : le docblock et la règle nomment `getUri()`, `getBaseUrl()` (et
  `getRequestUri()`, déjà dit) parmi les lectures non vues.

- [ ] **Step 1 : tests, rouges** — `formsTheDetectorSees` : `->getpathinfo()` et `::ISPATHUNDER(` → vus ;
  `testTheCanonicalReadCounterSeesEachForm` : nom court, `Http\CanonicalPath::of(`, `\App\…\CanonicalPath::of(`,
  `CanonicalPath::OF(` → 1 chacun ; `CanonicalPath::often(` et `Other::of(` → 0. Run : `docker compose exec -u dev backend
  php bin/phpunit tests/Shared/Infrastructure/Http/PathIsDecidedCanonicallyTest.php`. Expected : FAIL sur la casse et le
  nom complet.
- [ ] **Step 2 : implémenter** ; `AuthorizationSites` passe par `MemberCalls` (ses tests inchangés, verts) ;
  `make back-style-fix`.
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`, cache vidé dans le conteneur) :
  `MemberCalls::isCallAt` qui ignore `?->` → le cas « lecture nullsafe » **et** un cas nullsafe
  d'`AuthorizationLivesInAccessControlTest` rougissent (preuve que la forme est partagée) ; restauré.
- [ ] **Step 4 : règle** — taille notée.
- [ ] **Step 5 : verts** — `make back-test; echo "exit=$?"`, `make back-quality; echo "exit=$?"`,
  `tools/check-claude-rules.sh; echo "exit=$?"` ; `grep -n "testaient" backend/tests/Support/CompiledNestedReading.php` : vide.
- [ ] **Step 6 : commit** — `test(security): la garde du chemin canonique voit la casse et le nom complet, sa forme d'appel partagée (#748)`.

---

### Task R148 : Outillage — la Basic Auth : le mot refusé partout hors de ses deux formes, aucun vidage d'environnement, aucune redirection ni URL `http://` en argument, des blancs refusés, l'export retiré (Q4 : C12-2 ; CR12-10 = C12-13 ; C12-9, Q6 ; C12-10 ; St12-14, St12-15 ; St12-13 côté shell)

**Files :**
- Modify : `tools/lib/smoke-http.sh`, `tools/audit-prod.sh`, `tools/smoke-dead-session-cookie.sh`, `tools/smoke-login-throttling.sh`,
  `tools/smoke-mcp.sh` (en-têtes `Env :`)
- Test : `tools/tests/smoke-http.test.sh`, `tools/tests/lib/smoke-cases.sh`, les tests des smokes et de l'audit qui
  appellent `smoke_write_auth_config` ou un `check_*` (`grep` d'abord)
- Modify (règle) : `.claude/rules/deploiement-nginx.md` (paragraphe de la bibliothèque, l. 117-140)

**Interfaces :**
- Choix tranchés 8 et 9. Les en-têtes `Env :` ne nomment plus la variable ; celui de la bibliothèque la nomme et dit
  toutes les valeurs refusées (St12-15). Commentaires au présent (`smoke-http.test.sh:592`, St12-13).

- [ ] **Step 1 : filet** — les cinq tests hors ligne à 0, sorties notées.
- [ ] **Step 2 : tests, rouges** (Review Focus 4). `smoke-http.test.sh` : (a) la garde réécrite rougit aujourd'hui sur
  les en-têtes `Env :` et sur les commentaires qui nomment la variable ; (b) seconde garde : un cas par motif interdit
  (copie temporaire d'un script avec `declare -p X`, `printenv`, `env | grep`, `set | grep`, `export -p`, `${!n}`) → rouge,
  et ses voisins (`set -euo pipefail`, `set +x`, `environment`) → verts ; (c) `smoke_fetch` avec `SMOKE_AUTH_CONFIG`
  posé et `-L`, `--location`, `--url https://x`, `http://y` dans les arguments → 2, faux curl jamais appelé ; sans eux,
  l'appel porte `-K … --proto-redir =https` ; (d) `' :p'`, `'u: '`, `$'\t:p'` → 2, aucun dossier, valeur absente de
  stderr ; (e) après `smoke_use_auth_config`, `declare -p NOM` ne montre plus `-x` (l'export retiré), la valeur
  inchangée dans le shell. Expected : FAIL sur chaque.
- [ ] **Step 3 : implémenter** selon les choix 8 et 9 ; les deux mutations de C12-2 rejouées à la main : un heredoc
  dont une ligne commence par `#` et nomme la variable, ajouté à `smoke-mcp.sh` → la garde rougit.
- [ ] **Step 4 : rouges vus par mutation** (Python, copie vérifiée par `cmp`) : (a) `--proto-redir =https` retiré de
  `_smoke_auth` → le cas (c) « l'appel porte » rougit ; (b) l'`export -n` retiré → (e) rougit ; restaurés.
- [ ] **Step 5 : règle** — le paragraphe **remplacé**, une phrase par fait (≤ 140) : la fonction privée, les blancs, les
  options et URL refusées en argument, `--proto-redir`, l'export retiré, les deux gardes (le mot sur toutes les lignes,
  les vidages d'environnement). Taille notée.
- [ ] **Step 6 : verts** — les cinq tests hors ligne à 0 ; shellcheck (image épinglée) sur la bibliothèque, les quatre
  scripts, les tests et `tools/tests/lib/smoke-cases.sh` ; `tools/check-claude-rules.sh; echo "exit=$?"` ;
  `grep -rn "smoke_write_auth_config" tools | grep -v "_smoke_write_auth_config"` : vide ;
  `grep -n "AUDIT_BASIC_AUTH" tools/smoke-*.sh tools/audit-prod.sh` : les deux formes seules.
- [ ] **Step 7 : commit** — `fix(tools): la Basic Auth nommée en deux formes seulement, sans vidage d'environnement, sans redirection ni http:// en argument, sans blancs, sans export (#748)`.

---

### Task R149 : Outillage — l'audit sonde une redirection en un seul `curl`, l'indice 401 seulement avec la Basic Auth (CR12-9 ; Q5 ; St12-14 côté audit)

**Files :**
- Modify : `tools/audit-prod.sh`
- Test : `tools/tests/audit-prod.test.sh` (le faux curl compte ses appels par URL de redirection)
- Modify (règle) : `.claude/rules/deploiement-nginx.md` (phrase du 401 de la sonde)

**Interfaces :**
- Consumes : R148. Choix tranché 10.

- [ ] **Step 1 : tests, rouges.** `audit-prod.test.sh` : `pass "une sonde de redirection = un appel curl"` (run nominal :
  trois URL, trois appels) ; run sans `AUDIT_BASIC_AUTH` avec `FAKE_CURL_HTTP_CODE_FOR_WWW=401` : `pass "sans Basic Auth,
  un 401 de sonde s'imprime sans l'indice"` ; avec : l'indice (cas de R134 gardé). Expected : exit 1, deux FAIL.
- [ ] **Step 2 : implémenter** selon le choix tranché 10.
- [ ] **Step 3 : rouge vu par mutation** (Python, copie vérifiée par `cmp`) : le drapeau toujours à 1 → le cas « sans
  Basic Auth » rougit ; restauré.
- [ ] **Step 4 : règle** — « A probe answered 401 says the host asks for the Basic Auth » gagne « when the audit carries
  it (preprod) » ; taille notée.
- [ ] **Step 5 : verts** — `bash tools/tests/audit-prod.test.sh; echo "exit=$?"` à 0 ; shellcheck (image épinglée) ;
  `tools/check-claude-rules.sh; echo "exit=$?"`.
- [ ] **Step 6 : commit** — `fix(tools): l'audit sonde une redirection en un seul appel, l'indice du 401 en préprod seulement (#748)`.

---

### Task R150 : Les lignes de plus de 140 caractères écrites par #748, dans les règles et le code (Sp12-1 ; décision 3, Q4)

**Ce que « > 140 » mesure** : la longueur d'une ligne en caractères (`awk 'length>140'`), pas la taille d'un diff.

**Méthode** (à rejouer telle quelle, la liste du 2026-10-31 recomptée par ce plan : 190 lignes, 64 de règles, 87 de
littéraux, 39 autres ; une ligne écrite depuis peut s'y ajouter) :

```bash
B=$(git merge-base HEAD feature/379-mcp-paliers)   # 0e581b42, la base de la branche
S=<scratchpad>
git rev-list "$B"..HEAD > "$S/revs.txt"
git diff --name-only "$B"...HEAD -- .claude/rules frontend backend tools \
  | grep -v -e '\.json$' -e 'package-lock' -e '/\.env' -e 'config/jwt' > "$S/files.txt"
while read -r f; do [ -f "$f" ] && awk -v F="$f" 'length>140 && $0 !~ /^[[:space:]]*\|/ {print F":"FNR}' "$f"; done \
  < "$S/files.txt" > "$S/cand.txt"
while IFS=: read -r f n; do
  c=$(git blame --porcelain -L "$n,$n" -- "$f" | head -1 | cut -d' ' -f1)
  grep -qx "$c" "$S/revs.txt" && echo "$f:$n"
done < "$S/cand.txt" > "$S/long.txt"
```

Une ligne de `cand.txt` absente de `long.txt` est antérieure à #748 : gardée, listée par son nombre seulement.

**Files :** ceux que `long.txt` désigne (le JSON et les lignes de table exclus par la méthode).

- [ ] **Step 1 : liste** — la méthode rejouée ; le rendu donne les trois nombres (candidates, écrites par #748, par
  catégorie : règles, littéraux, autres).
- [ ] **Step 2 : règles** — chaque ligne coupée à une limite de mot, sans changer un mot ni l'ordre ; une puce reste une
  puce (indentation de continuation conservée) ; tailles avant et après (`security-user.md` < 50 000).
- [ ] **Step 3 : code** — commentaire coupé ; appel coupé (arguments un par ligne, au format de l'outil : `make
  front-lint`, `make back-style-fix`) ; selon Q4 (a), un littéral qui dépasse encore seul sur sa ligne reste et se liste ;
  **aucun nom de test ne change** (un littéral n'est jamais réécrit, seulement déplacé) ; renommages ou déplacements
  massifs par `python3 -I`, puis `docker compose restart frontend` / `backend`.
- [ ] **Step 4 : verts** — la méthode rejouée : `long.txt` ne contient plus que les littéraux listés ; même nombre de
  tests partout ; `make front-test`, `make front-lint`, `make front-build`, `make back-test`, `make back-quality`, les
  tests hors ligne de chaque script touché, shellcheck, `tools/check-claude-rules.sh`, chaque code lu sans pipe.
- [ ] **Step 5 : commit** — `style: les lignes de plus de 140 caractères écrites par #748 coupées, règles et code (#748)`.

---

### Task R151 : Balayage documentaire de la douzième série — avec la liste des recherches et de leurs résultats

Le rendu donne, pour **chaque** recherche, la commande exacte, le nombre de lignes rendues et, pour chaque ligne gardée,
sa raison ; une recherche sans ligne se dit « 0 ». **« ≤ 140 » est la longueur d'une ligne**, pas la taille du diff.

**Files :** ceux que le balayage désigne.

- [ ] **Step 1 : recherches** (secrets et `node_modules` exclus ; périmètre `.claude docs frontend/src frontend/tests
  backend/src backend/tests tools`) :
  1. Noms disparus : `grep -rn -e "JSON.stringify(options.formIdentity" -e inFlight -e callRunning -e PROSE_READONLY_HINT_ID
     -e "ids.prose" -e callsInsideHook -e settingsProposal -e handleAbandonProposal -e "smoke_write_auth_config"
     -e "as string | undefined"` (`_smoke_write_auth_config` attendu, à dire).
  2. Vocabulaire : `grep -rn -e "Réessayer" -e "La traduction annulée" -e "Rien de ce qui est écrit" -e "held by the review"`
     — chaque ligne gardée dit une page qui a ce bouton ou une citation archivée.
  3. Identifiants de constat dans les noms de tests : la recherche de R135, rejouée ; 0 hors antérieurs et faux positifs.
  4. Récits au passé dans les fichiers que `git diff --name-only 902bd8b8...HEAD -- frontend backend tools .claude` liste :
     `grep -nE "(depuis R[0-9]+|n'est plus|n'a plus|ne .* plus |restait|testaient|voyait|lisait|était là|used to|no longer)"`.
  5. Longueurs : `awk 'length>140'` sur chaque fichier que `git diff --name-only 902bd8b8...HEAD` liste ; **aucune ligne
     > 140 écrite par la série** (commit de `git blame` dans `902bd8b8..HEAD`), hors lignes de table, de JSON et
     littéraux listés par R150 ; puis la méthode de R150 rejouée : son résultat inchangé.
  6. Règles touchées : relire avec `Read` `frontend-backoffice.md` (« Translation assistant » et réglages),
     `frontend-session-refus.md`, `frontend.md` (« Toolchain history »), `security-authentication.md`,
     `deploiement-nginx.md` (paragraphe de la bibliothèque) : toute phrase fausse sur HEAD **remplacée**.
  7. Docblock d'en-tête de chaque fichier de `src/`, `tests/support/`, `backend/tests/Support/` et `tools/` modifié par
     R137 à R150 (`git diff --name-only 902bd8b8...HEAD -- frontend/src frontend/tests/support backend/tests/Support tools`)
     relu contre son code.
- [ ] **Step 2 : verts** — `tools/check-claude-rules.sh; echo "exit=$?"` ; taille de chaque règle touchée par la série,
  toutes < 50 000, `backoffice-api.md` à 39 834 ; `make front-test` / `make back-test` / tests hors ligne selon les
  fichiers changés, codes lus sans pipe.
- [ ] **Step 3 : commit** (seulement si un fichier a changé) — `docs: le balayage de la douzième relecture de #748`.
  Sinon, « rien à changer » et la liste des recherches.

### Traçabilité des constats

| Constat | Traitement |
|---|---|
| CR12-1 = C12-1 | R139 (décision 1) |
| St12-1 = CR12-7 | R137 (décision 2) |
| St12-2 = CR12-8 | R138 (décision 2) |
| St12-3 | R137 |
| St12-4 | R137 |
| St12-5 | R143 |
| Sp12-1 | R150 (décision 3, Q4) ; R151 recherche 5 |
| CR12-2 = Sp12-2 | R139 (Q8) |
| CR12-3 = Sp12-4 = C12-3 | R144 |
| CR12-4 | R143 (Q3) |
| CR12-5 = C12-4 | R147 |
| CR12-6 = St12-12 | R147 |
| C12-2 | R148 (décision 4) |
| CR12-9 | R149 (Q5) |
| CR12-10 = C12-13 | R148 |
| St12-6 | R138 |
| St12-7 | R141 |
| St12-8 | R141 |
| St12-9 = Sp12-5 | R141 |
| St12-10 | R142 |
| St12-11 | R144 (sauf « décision 1 » dans les noms : écarté, Q7) |
| St12-13 = Sp12-6 | R147 (PHP), R148 (shell) |
| St12-14 | R148 (bibliothèque, tests), R149 (audit) |
| St12-15 | R148 |
| St12-16 | R139 (`frontend-backoffice.md`), R143 (`frontend-session-refus.md`) |
| Sp12-3 | R143 |
| Sp12-7 | R145 |
| St12-17 = Sp12-11 | commit de ce plan (cases de R127b décochées, rectification), R145 (`dirtyOrder` par « ↓ ») |
| C12-5 | R139 (règle) |
| C12-6 = Sp12-9 | R142 (Q2) |
| C12-7 | R141 |
| C12-8 | R140 (Q1) |
| C12-9 | R148 (Q6) |
| C12-10 | R148 |
| C12-11 | R146 |
| C12-12 | R147 |
| Sp12-8 | R143 |
| Sp12-10 | écarté (constat de conformité) |

### Constats écartés, avec leur raison

- **Sp12-10** : le constat établit la conformité au choix 9 de la onzième série (les deux « #750 » voulus) ; rien à corriger.
- **St12-11, « décision 1 » dans des noms de tests** : la convention (brief commun ; contraintes R121 à R136 ; décision
  4 de la onzième relecture) permet dans un nom de test le ticket, une tâche ou une décision ; seuls les identifiants de
  constat en sont interdits. Rendu à l'auteur (Q7) ; s'il veut qualifier, R144 écrit « décision 1 de la onzième relecture ».

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

- [ ] **Cookie mort, en même origine** (R35 et R37, qui remplacent R23 ; CR3-3, C1) : connecté (puis, à part, avec
  « Accès instantané »), remplacer la valeur de `BEARER` par `x` (Application → Cookies ; même effet qu'un JWT expiré :
  `JWT_INVALID`). **Sans recharger**, visiter « À propos », la page d'accueil (qualité, contributions, incidents,
  technologies), `/stack`, envoyer le formulaire de contact, ouvrir un lien `set-password#…` : dans l'onglet Réseau, ces
  requêtes **portent** l'en-tête `Cookie` (`BEARER=x`), répondent 200 (ou le statut d'un anonyme), sans `Set-Cookie`, et
  les pages s'affichent. Puis une page authentifiée (backoffice, MCP, assistant, études de cas) : un 401, lu comme une
  session finie (bandeau, lien de reconnexion) ou, au palier de base, « accès de base expiré » ; le `Set-Cookie` du 401
  expire les deux cookies. Un rechargement avec `BEARER=x` : le `GET /api/me` de démarrage prend le 401 et efface.
- [ ] **Deux onglets, en même origine** (R23, R26) : onglet A sur « À propos », onglet B connecté ; `BEARER` rendu mort
  dans B ; A se recharge et navigue sur les pages publiques sans 401 ni changement d'en-tête ; B se reconnecte, A revient
  au premier plan : une seule requête `/api/me` si une session finie était affichée, aucune sinon.
- [ ] **« Accès instantané » avec un cookie mort** (R35 et R37, qui remplacent R24) : `BEARER` = `x` posé **après** le
  chargement (sinon `/api/me` l'efface d'abord), « Accès instantané » → onglet Réseau : **un seul**
  `POST /api/account/base-access`, en 200, l'en-tête au palier de base, les deux cookies neufs.
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

Ce que les corrections R35 à R45 ont changé, à mesurer aussi :

- [ ] **La préprod réelle, derrière sa Basic Auth** (C1, R37 ; à faire quand une branche de release la déploie, ou noté
  « à faire à la release » dans la PR) : après la saisie de la Basic Auth, visiter « À propos », l'accueil, `/stack`,
  envoyer le contact : dans l'onglet Réseau, chaque appel à `/api/*` porte `Authorization: Basic …` et aucun ne prend le
  401 de l'ingress (`WWW-Authenticate: Basic`) ; les pages s'affichent. Puis le scénario « Cookie mort, en même origine »
  ci-dessus, en préprod (même origine par construction : ce que la pile locale de (b) imite). Le job
  `smoke-test-preprod` (R36) l'a déjà vérifié côté serveur : lire sa sortie.
- [ ] **Même origine en local** (le procédé (b) ci-dessus) : ce qui change depuis R37 — les dépôts publics envoient de
  nouveau leurs cookies (`Cookie` présent), et un `BEARER` mort n'y change rien ; vérifier qu'un appel public ne porte
  **pas** `credentials: 'omit'` (l'en-tête `Cookie` présent en est la preuve).
- [ ] **Déconnexion avec un cookie mort** (R35) : connecté, `BEARER` = `x`, « Se déconnecter » → `POST /api/logout` en 2xx
  (plus un 401), les deux cookies expirés, l'en-tête anonyme.
- [ ] **Le lien d'évitement ne fait pas taire un refus** (R38, CR4-1) : un refus réel du backoffice affiché (compte
  rétrogradé), Tab jusqu'au lien d'évitement, Entrée → l'URL prend `#main-content`, l'alerte « accès refusé » reste.
  « Enregistrer » refusé (403 avec jeton), réponse ralentie (« Slow 3G »), navigation vers une autre page d'administration
  avant la réponse → l'alerte s'affiche sur la seconde. Une **lecture** refusée dans le même cas : rien (R28).
- [ ] **Connexion dans un onglet neuf** (R40, C2) : connecté, ouvrir `/<locale>/login?expired=1` dans un nouvel onglet,
  réseau ralenti : aucune annonce « votre session a pris fin » ne s'affiche, même brièvement ; puis l'annonce neutre.
  Connecté, `/<locale>/login` sans drapeau dans un nouvel onglet → l'accueil. Au palier de base, `login?expired=1` →
  aucune annonce.
- [ ] **Accès de base après une session finie** (R41, CR4-3) : session finie sur une page publique (déconnexion dans un autre
  onglet, puis un geste qui la constate), « Accès instantané » → le bandeau de session finie disparaît, l'en-tête est au
  palier de base ; plus tard, une connexion au même compte ne rejoue rien.
- [ ] **Réglages « À propos »** (R43) : deux enregistrements FR rapprochés (réseau ralenti, le second après un passage par
  l'EN), le premier échoue (hors ligne pendant sa réponse) → aucune erreur FR si le second a abouti, et ses valeurs restent.
- [ ] Non contrôlables au navigateur, à noter comme tels : deux rejeux de navigation qui se chevauchent (R39,
  `AppLayout.spec.ts`), la relecture débloquée après un `me()` qui lève (R41, `singleFlight.spec.ts`), la tolérance d'horloge
  (R44), l'invariant n° 8 (R35, `ApiRouteExposureTest`), la limite C7 (écrite, R45).

Ce que les corrections R46 à R58 ont changé, à mesurer aussi (ajouté par le plan de la cinquième série) :

- [ ] **Refus d'écriture hors du backoffice** (R48, CR5-1) *(rectifié le 2026-10-30, sixième relecture : remplacé par le point « Enregistrement
  refusé pendant l'absence » de R64 ci-dessous, décision 1)* : un compte `ROLE_SUPER` rétrogradé, « Enregistrer » ralenti
  (« Slow 3G »), aller sur l'accueil avant la réponse (403 avec jeton), revenir plus tard dans le backoffice → aucune
  alerte « accès refusé » ; l'en-tête a suivi le palier relu (un `/api/me` dans l'onglet Réseau).
- [ ] **403 tardif après un changement de compte** (R48, CR5-2) : le scénario « Changement de compte révélé par un refus »
  ci-dessus, avec un second « Enregistrer » du premier compte ralenti et revenu après le passage au second → rien
  d'affiché, aucun `/api/me` de plus.
- [ ] **`/api/me` qui ne répond pas** (R49, CR5-7) : outils réseau, une limitation personnalisée à très forte latence sur
  `/api/me`, `/<locale>/login?expired=1` dans un nouvel onglet → aucune annonce pendant l'attente, la page s'ouvre au bout
  de quinze secondes.
- [ ] **Réglages « À propos »** (R50, décision 3 ; remplace le point R43 ci-dessus, dont le second enregistrement FR n'est
  plus possible) : FR ralenti, passage en EN → « Enregistrer » actif, l'EN s'enregistre ; retour en FR avant la réponse →
  « Enregistrer » désactivé jusqu'à elle ; un double clic n'envoie qu'un `PUT`.
- [ ] Non contrôlables au navigateur, à noter comme tels : l'invariant n° 8 élargi et la garde d'autorisation (R46,
  PHPUnit), le smoke et sa bibliothèque (R47, R57 : tests hors ligne ; la sortie de `smoke-test-preprod` à la release),
  les refactorings R52 à R56.

Ce que les corrections R59 à R72 ont changé, à mesurer aussi (ajouté par le plan de la sixième série) :

- [ ] **Enregistrement refusé pendant l'absence** (R64, décision 1 ; remplace le point R48 « Refus d'écriture hors du
  backoffice » ci-dessus) *(rectifié le 2026-10-30, septième relecture : inatteignable tel qu'écrit : la relecture de `/api/me` qui suit le refus met l'en-tête au palier du compte rétrogradé, et le garde de route l'envoie vers la page « interdit » avant tout montage de l'alerte ; le procédé est celui du point R76 ci-dessous, un compte resté `ROLE_SUPER` dont la signature `XSRF-TOKEN` est rendue invalide)* : un compte `ROLE_SUPER` rétrogradé, « Enregistrer » ralenti (« Slow 3G »), aller sur l'accueil
  avant la réponse (403 avec jeton), revenir dans le backoffice → l'alerte dit « Un enregistrement précédent a été
  refusé par le serveur… » (FR, puis EN), annoncée à l'arrivée (inspecteur d'accessibilité : l'élément `role="alert"`
  inséré après le rendu de la page ; sans lecteur d'écran réel, le noter non vérifié) ; une navigation l'efface ; un
  nouveau refus sur la page la remplace par le texte ordinaire. Une **lecture** refusée dans le même cas : rien.
- [ ] **Le même compte rétabli entre l'envoi et le 403** (R63, décision 2) : « Enregistrer » ralenti d'un compte
  rétrogradé, déconnexion puis reconnexion **au même compte** dans un autre onglet avant la réponse, retour → l'alerte
  « accès refusé » s'affiche, un `/api/me` de plus. Avec **un autre** compte : le point R48 « 403 tardif après un
  changement de compte » ci-dessus (rien d'affiché) reste valable.
- [ ] **Réglages « À propos », l'avis de proposition** (R66, décision 4) : proposition EN depuis le FR, puis passage en FR
  → aucun avis sous le FR ; retour en EN → les champs sont ceux du serveur, l'avis a disparu. Un enregistrement EN
  hors ligne avec la proposition → l'avis reste.
- [ ] **Réglages « À propos », le bouton de traduction** (R67, décision 3) : enregistrement EN ralenti, passage en FR
  avant la réponse → « Proposer la version EN » désactivé jusqu'à elle, « Enregistrer » FR actif.
- [ ] Non contrôlables au navigateur, à noter comme tels : l'invariant n° 8 sur les écritures et les gardes
  d'autorisation (R59 à R61, PHPUnit), les sorties d'outillage (R62, R71 : tests hors ligne ; `smoke-test-preprod` à la
  release), `checkAuth` (R65), les gardes de source (R68, R69), les refactorings R70 et R71.

Ce que les corrections R73 à R87 ont changé, à mesurer aussi (ajouté par le plan de la septième série) :

- [ ] **L'enregistrement précédent, dit au seul compte qui l'a envoyé** (R76, décision 2) : compte `ROLE_SUPER`, la
  valeur de `XSRF-TOKEN` remplacée par une valeur mal signée (outils du navigateur ; l'en-tête suit le cookie, le
  serveur répond 403 avec jeton), « Enregistrer » ralenti (« Slow 3G »), aller sur l'accueil avant la réponse, revenir
  dans le backoffice → « Un enregistrement précédent a été refusé… » ; même départ, puis déconnexion et connexion d'un
  **autre** compte `ROLE_SUPER` dans le même onglet → aucune alerte. Une lecture refusée sur la page d'arrivée qui
  garde l'avis (CR7-4) demande un compte que le frontend croit encore `ROLE_SUPER` : la noter couverte par
  `AccessRefusedNotice.spec.ts` si elle n'est pas reproduite.
- [ ] **Une proposition verrouille la langue** (R78, R79) : sur chaque formulaire par entrée (études de cas,
  incidents, contributions, CV sans identité, principes et traits qualité, cartes « À propos » moi et site), FR et EN,
  « Proposer la version … » → le sélecteur de langue désactivé, l'avis affiché, « Annuler » présent ; « Annuler » →
  formulaire vide, sélecteur actif, avis parti ; axe sur le formulaire avec la proposition.
- [ ] Non contrôlables au navigateur, à noter comme tels : les invariants n° 8 et n° 9 et la garde des métadonnées
  compilées (R73, R74, PHPUnit), l'outillage (R75, R83 : tests hors ligne ; `smoke-test-preprod` et `audit-preprod` à
  la release), l'identité du compte d'un refus (R77, specs), les gardes de source (R80), les refactorings R81 à R86.

Ce que les corrections R88 à R101 ont changé, à mesurer aussi (ajouté par le plan de la huitième série ; Sp8-1 y
reprend D-a, D-b et la limite « Créer la version » de R79b, que le point R78/R79 ci-dessus ne contrôle pas) :

- [ ] **Le verrou de la langue expliqué** (R79b D-a, R92) : sur chaque formulaire par entrée, FR et EN, en édition,
  l'inspecteur d'accessibilité donne au sélecteur de langue la description « La langue d'une entrée existante ne peut
  pas être modifiée. » (après l'aide permanente sur les principes, les traits et les cartes) ; formulaire libre : aucune
  description de verrou.
- [ ] **Une proposition fige le formulaire** (R93, R94, décisions 1 à 3 de la huitième série ; D-b) : « Proposer la
  version … » → la langue, « Version de », « Proposer la version … » et, sur une carte « moi », la catégorie sont
  désactivés, chacun décrit par « Verrouillé par la proposition de traduction en cours ; « Annuler » l'abandonne et vide
  le formulaire. » (EN : « Locked by the current translation proposal; “Cancel” discards it and clears the form. ») ;
  « Annuler » → formulaire vide, tout actif, aucune description de verrou ; axe sur le formulaire verrouillé.
- [ ] **« Créer la version XX »** (R79b, R94, limite Q1 de la huitième série) : la catégorie d'une carte « moi » y reste
  libre ; une proposition faite depuis ce formulaire puis enregistrée → « Ce contenu a déjà une version dans cette
  langue. », « Version de » verrouillé, « Annuler » rend un formulaire vide.
- [ ] **L'avis de l'enregistrement précédent, borné au chargement** (R95, décision 4) : le procédé du point R76
  ci-dessus ; une lecture refusée sur la page d'arrivée garde l'avis, une seconde ramène le texte « accès refusé » ; si
  la lecture refusée n'est pas reproduite (un compte que le frontend croit encore `ROLE_SUPER`), la noter couverte par
  `AccessRefusedNotice.spec.ts`.
- [ ] Non contrôlables au navigateur, à noter comme tels : la garde des métadonnées compilées et l'invariant n° 10
  (R88, R89, PHPUnit), l'outillage (R90 : tests hors ligne ; `smoke-test-preprod` et `audit-preprod` à la release),
  les gardes de source (R96, R97), les refactorings R91, R92 et R97 à R100.

Ce que les corrections R102 à R111 ont changé, à mesurer aussi (ajouté par le plan de la neuvième série) :

- [ ] **L'avis de l'enregistrement précédent, lié au chargement** (R102, décision 1 de la neuvième série ; remplace le
  point R95 « borné au chargement » ci-dessus) : le procédé du point R76, l'arrivée sur « À propos » ou « Qualité »
  (plusieurs listes) ; chaque lecture refusée du chargement réannonce « Un enregistrement précédent a été refusé… »,
  et une fois les listes chargées, « Réessayer » ou une relecture refusée dit « accès refusé ». Si les lectures refusées
  ne se reproduisent pas (un compte que le frontend croit encore `ROLE_SUPER`), les noter couvertes par
  `AccessRefusedNotice.spec.ts` et `backofficeAccessRefusal.spec.ts`.
- [ ] **« Proposer la version … » pendant une proposition** (R104) : bouton désactivé, Entrée et Espace au clavier ne
  lancent rien (onglet Réseau : aucun `POST /api/backoffice/translations` de plus), FR et EN.
- [ ] Non contrôlables au navigateur, à noter comme tels : la proposition gardée sous une autre langue (R103 : état
  interne, `AdminAboutPage.spec.ts`), l'outillage (R106 : tests hors ligne ; `audit-preprod` à la release, où un secret
  vide sort désormais en 2), les gardes backend (R107 à R109, PHPUnit), le lecteur de gabarit (R110), les refactorings
  R105, R108 et R109.

Ce que les corrections R112 à R120 ont changé, à mesurer aussi (ajouté par le plan de la dixième série) :

- [ ] **L'avis de l'enregistrement précédent, lié au montage** (R112, décision 3 de la dixième série ; remplace le point
  R102 « lié au chargement » ci-dessus) : le procédé du point R76, l'arrivée sur « Qualité » (deux listes) ; chaque
  lecture refusée du chargement réannonce « Un enregistrement précédent a été refusé… » ; puis un principe créé dont
  la relecture est refusée, ou « Réessayer », dit « accès refusé ». Avec une lecture ralentie (outils réseau, « Slow
  3G ») : sa réponse refusée, arrivée tard, garde l'avis. Si les lectures refusées ne se reproduisent pas, les noter
  couvertes par `AdminLayout.sessionEnded.spec.ts` et `backofficeAccessRefusal.spec.ts`.
- [ ] **La traduction assistée, en double et annulée** (R114) : réseau ralenti, deux activations rapides de « Proposer la
  version … » (clic puis Entrée) → un seul `POST /api/backoffice/translations` (onglet Réseau) ; « Annuler » pendant
  la traduction → à l'arrivée de la réponse, le formulaire reste vide, aucun avis de proposition, FR et EN.
- [ ] **La redirection HTTP de la préprod précède la Basic Auth** (R115, C10-1 ; à faire quand une branche de release la
  déploie, ou noté non vérifié) : `curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' http://<domaine de
  préprod>` sans identifiant → 308 (ou 301) vers `https://`, jamais 401 ; la section « Redirections » du run
  `audit-preprod` montre les mêmes codes qu'avant R115.
- [ ] Non contrôlables au navigateur, à noter comme tels : l'avis des réglages sous un formulaire non chargé (R113 :
  état interne, `AdminAboutPage.spec.ts`), l'outillage (R115, R116 : tests hors ligne ; `smoke-test-preprod` à la
  release, où un secret vide sort désormais en 2 dans les trois smokes), l'ancrage et les gardes backend (R117, R118,
  PHPUnit), le lecteur de gabarit (R119).

Ce que les corrections R121 à R136 ont changé, à mesurer aussi (ajouté par le plan de la onzième série) :

- [ ] **La prose en lecture seule pendant une traduction** (R126, décision 3 de la onzième série) : réseau ralenti
  (« Slow 3G »), « Proposer la version … » sur un incident en édition, puis un formulaire « À propos » (réglages) ;
  pendant l'attente, chaque champ de prose prend le focus au clavier, ne change pas à la frappe, et un lecteur d'écran
  (ou l'arbre d'accessibilité des outils du navigateur) le dit en lecture seule avec le texte « Traduction en cours :
  les champs de texte sont en lecture seule… » ; à la réponse, la proposition remplit les champs, redevenus
  modifiables ; une réponse en erreur (quota) laisse la prose d'origine, modifiable. FR et EN.
- [ ] **Le texte d'aide après « Annuler »** (R125, décision 2) : réseau ralenti, « Proposer la version … » puis
  « Annuler » → le bouton est désactivé, décrit par « La traduction annulée se termine côté serveur… » (onglet
  Accessibilité), une seconde activation (clic, Entrée) n'envoie aucun `POST /api/backoffice/translations` (onglet
  Réseau : un seul) ; à la réponse, le texte disparaît, le formulaire reste vide, le bouton revient. FR et EN.
- [ ] **Les réglages pendant une proposition affichée** (R127) : « Proposer la version … » désactivé et décrit par son
  texte de verrou ; il revient après l'enregistrement de la proposition.
- [ ] Non contrôlables au navigateur, à noter comme tels : la garde des chargements dans un `onMounted` (R121, garde de
  source), la clôture du chargement de la page d'arrivée par la première lecture relancée (R122 : couverte par
  `AdminLayout.sessionEnded.spec.ts`, ou au navigateur par le procédé du point R76 avec une lecture ralentie, si elle se
  reproduit), l'identité du formulaire (R124, specs), l'outillage (R133, R134 : tests hors ligne ;
  `smoke-test-preprod` et `audit-preprod` à la release), les gardes backend (R131, R132), le lecteur de gabarit
  (R130), les noms de tests (R135).

Ce que les corrections R137 à R151 ont changé, à mesurer aussi (ajouté par le plan de la douzième série) :

- [ ] **Un formulaire vierge enregistré pendant une traduction** (R139, décision 1 de la douzième série) : réseau ralenti
  (« Slow 3G »), formulaire d'incident vierge rempli en FR, « Proposer la version EN », puis « Enregistrer » aussitôt →
  l'entrée FR est créée, le formulaire se vide, le bouton de traduction attend en le disant, puis la réponse du modèle
  ne pose rien (aucun avis de proposition, formulaire vide, langue FR). FR et EN.
- [ ] **L'erreur d'une traduction ne reparaît pas** (R139) : hors ligne pendant une traduction de l'entrée A (erreur
  affichée), « Modifier » B, puis A → aucune erreur ; une frappe dans la prose après une erreur la laisse affichée.
- [ ] **Les textes de la traduction** (R142, si Q2 retenue) : le texte de la prose en lecture seule et celui de l'attente,
  lus à l'arbre d'accessibilité, sont les nouveaux, FR et EN.
- [ ] **Réglages « À propos », « Abandonner »** (R141) : inchangé à l'usage (le bouton, son texte, le focus au premier
  champ relu).
- [ ] Non contrôlables au navigateur, à noter comme tels : le refus unique et le composable de la prose (R137, R138,
  specs), la suppression de l'entrée source d'une création rattachée (R140, specs ; au navigateur si une réponse ralentie
  le permet), l'état du refus du backoffice (R143), la garde des chargements (R144), l'alignement de `@vue/compiler-dom`
  (R146), la garde du chemin canonique (R147), l'outillage (R148, R149 : tests hors ligne ; `smoke-test-preprod` et
  `audit-preprod` à la release), les lignes coupées (R150).

- [ ] Tout écart → retour à la tâche concernée (nouveau commit), puis ce contrôle refait.

### Task 12 : Clôture — **fil principal**

- [ ] `make front-test`, `make front-lint`, `make front-build`, **`make back-test` et `make back-quality`** (R1, R21) verts sur la tête ; `tools/check-claude-rules.sh` vert (les `paths:` ajoutés correspondent à des fichiers suivis, aucune règle au-delà de 50 000 octets, `frontend-session.md` et ses trois parties de R34 comprises, chacune avec sa ligne dans l'index de `.claude/CLAUDE.md`) ; `tasks/regle-session-en-attente.md` n'existe plus (intégré par R34). `bash tools/tests/smoke-dead-session-cookie.test.sh` vert (R36).
- [ ] Corps de la PR, compléments des troisième et quatrième relectures (R23 à R45) : **une route publique sert l'anonyme quel que soit le cookie `BEARER`** (R35, décision de l'auteur du 2026-10-29, qui remplace `credentials: 'omit'` de R23 et le rejeu d'« Accès instantané » de R24, retirés par R37) : un cookie refusé (JWT expiré, invalide, antérieur à un changement de mot de passe) ne fait plus répondre 401 une route sans règle d'`access_control` ; il est effacé par la première route qui exige un rôle (le SPA lit `/api/me` à chaque chargement) ; une session close par un changement de mot de passe, présentée à une route publique, est servie comme anonyme et auditée ; `POST /api/logout` avec un cookie mort répond 2xx et expire les cookies ; « Accès instantané » ne consomme plus qu'un jeton du quota. `ApiRouteExposureTest` gagne l'invariant n° 8 (un cookie refusé ne change jamais la réponse anonyme, sur chaque route) et `smoke-test-preprod` une étape (R36). **C1** : `omit` retirait aussi la Basic Auth de la préprod ; une garde l'interdit désormais. **R21b est retirée** (décision de l'auteur du 2026-10-29) : l'accès de base n'appelle plus `POST /api/logout` à son échéance, l'en-tête repasse simplement à anonyme. **`clock_skew: 5`** : cinq secondes d'écart d'horloge entre pods tolérées (sur `iat` comme sur `exp`). Limite écrite : la réponse tardive (401) d'une requête authentifiée partie avec un cookie mort peut effacer des cookies posés entre-temps par une connexion dans un autre onglet ; la requête suivante le dit (session finie), une reconnexion répare. La politique de cookies publiée dit désormais « cookies de session ». Aucune action au déploiement.
- [ ] Corps de la PR, quatrième relecture (R38 à R45) : un refus d'enregistrement du backoffice n'est jamais périmé, le lien d'évitement n'efface plus l'alerte ; deux rejeux de navigation qui se chevauchent ; la connexion attend la vérification de la session (plus d'annonce « session finie » fugitive dans un onglet neuf) et ne dit rien au palier de base ; l'accès de base clôt une session finie ; `singleFlight` ; l'état de session encapsulé ; seul le dernier enregistrement « À propos » d'une langue décide ; `JwtClockSkewTest` stable. **Limite acceptée C7** (décision de l'auteur du 2026-10-29) : un changement de compte dans un autre onglet, sans session finie dans celui-ci, n'est détecté par rien (antérieure à #748, suppose un accès au même navigateur). **Sp4-b** : R32 a changé la date de la politique de confidentialité et le registre RGPD sans que le plan le demande — gardé, le texte publié ayant changé ; R45 les complète.
- [ ] Corps de la PR, cinquième relecture (R46 à R58 ; ajouté par le plan de la cinquième série) : l'invariant n° 8 vérifie
  l'effacement d'un cookie refusé et une garde tient toute autorisation de `/api` dans `access_control` ; un refus du
  backoffice ne survit ni au démontage du layout ni à un changement de compte (un autre compte lu hors session finie avance
  la génération) ; `/api/me` abandonne après quinze secondes ; réglages « À propos » : un seul enregistrement en vol par
  langue ; les scripts de fumée disent un échec réseau (sortie 1), refusent une Basic Auth à retour à la ligne (sortie 2)
  et partagent `tools/lib/smoke-http.sh`. **Limite acceptée C5-1** (décision de l'auteur du 2026-10-30) : une session close
  par un changement de mot de passe, gardée par un onglet légitime, écrit une ligne d'audit par lecture publique jusqu'au
  prochain `/api/me` (bornée par la zone `publicapi`). **C5-2, accepté** : une lecture publique d'une personne connectée
  est authentifiée (JWT vérifié et un `SELECT` du compte). `bash tools/tests/smoke-{dead-session-cookie,login-throttling,mcp}.test.sh` verts.
- [ ] Corps de la PR, sixième relecture (R59 à R72 ; ajouté par le plan de la sixième série) : un enregistrement du
  backoffice refusé pendant que la personne était ailleurs est dit à son retour, avec son propre texte (décision 1 du
  2026-10-30, qui amende la réponse Q1 de la cinquième série) ; un refus n'est écarté que si le compte a changé depuis
  l'envoi, plus pour une génération (décision 2) ; réglages « À propos » : « Proposer la traduction » attend
  l'enregistrement de la langue cible (décision 3), l'avis de proposition n'est que celui de sa langue cible et part à sa
  relecture (décision 4) ; `checkAuth` n'avale plus que le rejet de `/api/me` ; l'invariant n° 8 voit les écritures
  (paire CSRF valide) et vérifie les attributs de l'effacement ; deux gardes tiennent toute autorisation de `/api` dans
  `access_control` (jetons, métadonnées compilées d'API Platform) ; une bibliothèque d'outillage invalide se dit.
  `bash tools/tests/smoke-{dead-session-cookie,login-throttling,mcp,http}.test.sh` verts.
- [ ] Corps de la PR, septième relecture (R73 à R87 ; ajouté par le plan de la septième série) : sur le pare-feu `api`,
  une route publique est exactement une route sans règle d'`access_control` et une route à règle répond 401 à
  l'anonyme, vérifié à l'exécution (invariant n° 9 d'`ApiRouteExposureTest`, décision 1 du 2026-10-30 ; `/api/logout`
  la seule exception, justifiée) ; la garde des métadonnées d'API Platform lit aussi paramètres, liens et propriétés ;
  un enregistrement refusé pendant l'absence n'est dit qu'au compte qui l'a envoyé (décision 2), et la lecture refusée
  de la page d'arrivée ne le fait plus taire ; une proposition de traduction verrouille la langue de son formulaire ;
  la Basic Auth des scripts de fumée est écrite dans un répertoire privé, hors de toute trace, et un chemin hérité de
  l'environnement n'est plus supprimé ; les gardes de source lisent l'arbre de TypeScript. **Limite écrite** (Q1 de la
  septième série) : une autorisation autre qu'une expression d'API Platform posée sur une route de `PUBLIC_PATHS`
  n'est vue par aucun témoin. `bash tools/tests/{smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp,smoke-http,audit-prod}.test.sh` verts.
- [ ] Corps de la PR, huitième relecture (R88 à R101 ; ajouté par le plan de la huitième série, Sp8-1) : dans les
  formulaires par entrée, le sélecteur de langue verrouillé dit pourquoi (D-a, R79b) et la catégorie d'une carte « moi »
  est verrouillée par une proposition (D-b) ; une proposition affichée verrouille aussi « Proposer la version … » et
  « Version de » (décisions 1 et 3 du 2026-10-30), décrits par un texte qui dit qu'« Annuler » abandonne la proposition
  et vide le formulaire (décision 2) ; le verrou tient dans un composable ; l'avis « enregistrement précédent » ne
  survit qu'à la lecture de chargement de la page (décision 4) ; la garde des métadonnées d'API Platform lit aussi les
  DTO et les objets imbriqués ; chaque route de `/api` déclare des méthodes que le double-submit juge (invariant n° 10) ;
  la Basic Auth des scripts de fumée est jugée et lue par la bibliothèque, jamais développée hors de `set +x` ; la garde
  `'omit'` lit les gabarits et le `<template>`. **Limite écrite** (Q1 de la huitième série) : une proposition dont la
  langue cible existe déjà dans le groupe (depuis « Créer la version XX ») finit sur le 409 du serveur, « Annuler » en
  sort ; « Créer la version XX » laisse la catégorie libre (D-b).
  `bash tools/tests/{smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp,smoke-http,audit-prod}.test.sh` verts.
- [ ] Corps de la PR, neuvième relecture (R102 à R111 ; ajouté par le plan de la neuvième série) : l'avis
  « enregistrement précédent » vaut pour toutes les lectures du chargement de la page d'arrivée et cède au premier refus
  qui suit ce chargement (décision 1 du 2026-10-31, amendant la décision 4 de la huitième) ; réglages « À propos » : des
  réglages d'une autre langue que la cible laissent la proposition (décision 2) ; pendant une proposition, la traduction
  d'un formulaire par entrée se refuse elle-même ; l'audit de préprod s'arrête (sortie 2) sur une Basic Auth définie mais
  vide, avant tout appel (décision 3), et les scripts de fumée disent un fichier `-K` disparu ; `/api/logout` reste sous
  le double-submit, seule barrière contre la déconnexion forcée (témoin ajouté) ; le lecteur de gabarit des gardes lit
  `v-for` et `v-slot`. `bash tools/tests/{smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp,smoke-http,audit-prod}.test.sh` verts.
- [ ] Corps de la PR, dixième relecture (R112 à R120 ; ajouté par le plan de la dixième série) : une lecture partie
  avant le montage de l'alerte garde l'avis « enregistrement précédent », toute autre rend « accès refusé » — l'époque
  portée par l'estampille d'envoi remplace la fenêtre de chargement et son compteur (décision 3 du 2026-10-31, qui
  amende la lettre de la décision 1 de la neuvième) ; la traduction assistée se refuse elle-même pendant une traduction
  en vol et pendant une proposition, et « Annuler » périme sa réponse ; **C10-1 / #750** : les sondes de redirection
  de l'audit partaient avec la Basic Auth de préprod, en clair sur `http://`, depuis 15b56512 (2026-09-12) ; elles
  partent sans elle (R115, la même correction que `fix/750-audit-basic-auth-http` vers `develop`, mêmes cas de test,
  même commentaire : au report de `develop`, `tools/tests/audit-prod.test.sh`, créé des deux côtés, garde la version
  de la branche) ; **pas de rotation** de la Basic Auth de préprod (décision 2 du 2026-10-31 : risque accepté, seul un
  acteur placé sur le chemin runner GitHub → répartiteur Scaleway a pu lire l'identifiant, lors des audits de release
  depuis le 2026-09-12) ; les trois smokes s'arrêtent en 2 sur un secret vide (décision 4) et toute Basic Auth sans
  « : » est refusée ; « relève de `/api` » écrit une fois pour les tests (`CanonicalPath::isPathUnder`), le préfixe
  large du double-submit gardé exprès et prouvé (`POST /apix` → 403). `bash tools/tests/{smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp,smoke-http,audit-prod}.test.sh` verts.
- [ ] Corps de la PR, onzième relecture (R121 à R136 ; ajouté par le plan de la onzième série) : une page du backoffice
  charge dans son `setup`, une garde de source refuse un chargement dans un `onMounted` (décision 1 du 2026-10-31 ; la
  limite d'un sous-composant rendu plus tard reste écrite) ; la première lecture relancée après le montage clôt le
  chargement de la page d'arrivée (réponse à Q2) ; **un seul appel au modèle en vol par formulaire** : « Annuler » ne
  laisse plus repartir une traduction facturée tant que la précédente n'a pas répondu, et le bouton dit pourquoi il
  attend (décision 2 : pas d'`AbortSignal`, l'appel parti coûte ce qu'il coûte) ; **la prose est en lecture seule
  pendant une traduction** (décision 3 : rien de tapé n'est perdu) ; une traduction en vol se périme dès que son
  formulaire change d'identité, y compris par la suppression de l'entrée éditée ou la langue d'une création ; la
  traduction refuse par programme tout ce que son bouton refuse (brouillon d'ordre, enregistrement, proposition des
  réglages) ; les smokes et l'audit posent la Basic Auth par une seule fonction, la refusent sur `http://` et sans
  utilisateur ou mot de passe, ne suivent une redirection authentifiée qu'en `https` ; aucun chemin de requête brut hors
  de `CanonicalPath` ; les identifiants de constat restent permis dans les commentaires et quittent les noms de tests
  (décision 4). Si Q4 (A) est retenue : `@vue/compiler-dom` en `devDependencies`, même intervalle que `vue`, une seule
  version installée. `bash tools/tests/{smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp,smoke-http,audit-prod}.test.sh` verts.
- [ ] Corps de la PR, douzième relecture (R137 à R151 ; ajouté par le plan de la douzième série) : une remise à zéro du
  formulaire périme la traduction en vol (décision 1 du 2026-10-31 : une génération dans l'identité du formulaire ; un
  formulaire vierge enregistré pendant une traduction ne reçoit plus de proposition orpheline) et l'erreur d'une
  traduction ne reparaît plus au retour sur son formulaire ; le refus de la traduction a une seule source, lue par le
  bouton et par le composable, et la prose de chaque formulaire tient en un composable (décision 2) ; supprimer l'entrée
  source d'une création rattachée périme la traduction ; les textes de la traduction disent ce qui arrive à la saisie ;
  la garde des chargements après le montage voit références, alias et différés ; la garde du chemin canonique voit la
  casse et les noms complets ; la Basic Auth des scripts n'est nommée qu'en deux formes, sans vidage d'environnement,
  jamais avec une redirection ni une URL `http://` en argument, ni faite de blancs, et n'est plus exportée aux processus
  enfants (décision 4) ; l'audit sonde une redirection en un appel ; les lignes de plus de 140 caractères écrites par
  #748 sont coupées (décision 3). **Limite écrite** (C12-5) : « un appel en vol » vaut par formulaire monté, une page
  rouverte ou un second onglet en lance un autre, borné par le quota.
  `bash tools/tests/{smoke-dead-session-cookie,smoke-login-throttling,smoke-mcp,smoke-http,audit-prod}.test.sh` verts.
- [ ] Corps de la PR : la note de release de F1 (R21, remplace celle de R1) — `BEARER` et `XSRF-TOKEN` sont désormais des **cookies de session**, au login comme à l'accès de base (le JWT garde son heure, l'accès de base ses quinze minutes) : fermer le navigateur sans restauration de session finit la session ; à l'expiration du JWT, la première requête prend un 401 qui efface les deux cookies, la suivante est anonyme ; **une session ouverte avant le déploiement garde ses cookies persistants, au plus une heure** (rien à faire) ; le frontend juge désormais la session par génération (une réponse partie sous une autre session ne la finit ni ne la rétablit) et un autre compte au retour rejoue la navigation.
- [ ] **Amendement d'ADR 0003** — **déplacé dans R21 (Step 3b) par décision de l'auteur du 2026-10-29** ; à la clôture, seulement vérifier qu'il est fait et cohérent. Contenu attendu (décision 1 du 2026-10-29) : ligne de statut (« Amendée le 2026-10-29 ») ; dans « Risque accepté — le logout ne révoque pas le jeton », le paragraphe des faits (`set_cookies.BEARER.lifetime` vaut `0` : cookie de session ; `token_ttl` 3600 et les 900 s de l'accès de base inchangés) ; un amendement « 2026-10-29 (#748, F1) » : `BEARER` et `XSRF-TOKEN` sont des cookies de session ; le risque « navigateur fermé » (un cookie qui survit à la fermeture, en lecture comme en écriture) **disparaît, sauf navigateur qui restaure la session** ; tout cookie refusé (expiré, invalide, antérieur au changement de mot de passe) est effacé par son premier 401 ; ce que cela ne change pas (un jeton copié reste valable jusqu'à son `exp`) ; les conditions qui rouvrent la décision, inchangées. `docs/adr/0003-paliers-d-acces.md` seul, relu contre `security-authentication-sessions.md` (R21).
- [ ] **Ticket à proposer à l'auteur** (texte dans le rapport de clôture, pas de création sans son accord) : le préfixe `__Host-` des deux cookies (S2 de la première relecture : préprod d'abord, il renomme ce que Lexik, `CsrfCookieRequestSubscriber` et le frontend lisent par nom) **et F12** (le jeton CSRF n'est lié ni à la session ni au temps : le lier au JWT — par exemple un HMAC dérivé de son `iat` et du compte — ou lui donner une échéance signée), avec la note que R21 réduit déjà la durée de vie des deux cookies à la session du navigateur, **et A3-3** de la troisième relecture (depuis R21, tout cookie `BEARER` refusé efface les deux cookies : un sous-domaine qui pose un faux `BEARER` sur `Path=/api` — plus spécifique que le `Path=/` du vrai, donc envoyé en premier — fait répondre 401 et efface la session valide ; `__Host-` interdit qu'un sous-domaine pose ce nom). Depuis R35, A3-3 ne touche plus que les routes qui exigent un rôle (une route publique ignore un cookie refusé). **Et C8** de la quatrième relecture (« le même compte » est jugé sur l'identifiant, pas sur l'UUID du compte : un compte supprimé puis recréé sous le même identifiant passe pour le même ; l'exposer par `/api/me` et le retenir dans `LastAccount` ; CR7-2 de la septième relecture est le même cas, côté refus du backoffice, R77). Le pare-feu sans authentificateur pour `/api/account/base-access`, un temps proposé ici, **n'est plus à joindre** : R35 couvre le cas.
- [ ] #732 : le point 4 est **entièrement traité par #748** (règle `endsTheSession` dans le backoffice, « Mes connexions MCP », le consentement OAuth et l'assistant, portée par `sessionAwareFetch` ; un refus réel relit `/api/me` et propose « Changer de compte » ; tâches 3, 4, 4b, 4c, R2, R7) — le marquer comme tel dans le corps (barré, renvoi à #748 et à son commit de fusion), rien n'y reste. Par `gh api -X PATCH repos/{owner}/{repo}/issues/732` (pas `gh issue edit`, cassé en gh 2.46 sur ce dépôt).
- [ ] `tasks/todo.md` : cocher « Tâche 11 » (critère de #748) dans le commit de clôture.
- [ ] Après la fusion dans `feature/379-mcp-paliers` : fermer #748 à la main (`Closes #748` ne ferme rien vers une base qui n'est pas `main`).
