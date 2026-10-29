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

## Questions ouvertes (à trancher par l'auteur avant la tâche 10)

1. **Constat 6, l'en-tête à 320 px** (impact visuel). Le bouton « Accès instantané » est **déjà** une icône seule sous
   576 px (`d-sm-none`) : la piste du ticket « icône seule sous 360 px » ne gagne rien. Ce qui déborde : la marque
   (`text-nowrap`), le sélecteur FR/EN, l'icône d'accès, « Connexion » (ou « Mon compte »), le bouton du menu ; 30 px
   de trop au palier de confiance, 88 px en anonyme EN. Trois options :
   - **(a) recommandée** — la marque se tronque (`text-truncate` sur son libellé, `min-width: 0` sur le lien, les
     actions `flex-shrink: 0`) : rien ne change au-dessus de ~400 px, et sous 320 px la marque montre ce qui tient,
     jusqu'au seul logo. Le nom accessible du lien reste entier (la troncature n'est que visuelle). Inconvénient :
     une marque coupée par des points de suspension.
   - (b) le libellé de la marque masqué visuellement sous 360 px (`@media`, le logo reste le lien d'accueil, nom
     accessible inchangé) : net, mais tout ou rien, et une borne de plus à entretenir.
   - (c) `flex-wrap` sur la ligne de l'en-tête : rien n'est caché, mais l'en-tête collant gagne une ligne (~50 px) à
     320 px, ce qui empire le masquage du focus (2.4.11) et le reflow au zoom — le défaut que le bandeau collant
     (tâche 8) aggrave déjà.
   La tâche 10 implémente (a) ; si l'auteur choisit (b) ou (c), son étape 3 donne la variante.
2. **Étendre la règle du 403 au consentement OAuth et à l'assistant ?** `HttpOAuthConsentRepository` (même mapping
   403 → `SessionExpiredError`, même commentaire) et `useAssistant` (401/403 → `markSessionExpired()`) ont le défaut
   du point 4 de #732. L'arbitrage a nommé `BackofficeHttpClient` et `HttpMcpConnectionsRepository` seulement.
   **Recommandation : les laisser hors de #748** et les inscrire dans #732 (point 4 reformulé, tâche 12) — le
   consentement a son propre parcours de reconnexion (`reauth=1`), à relire avec la règle `mcp-oauth.md`. Le plan
   suit cette recommandation ; s'il faut les inclure, une tâche 4b reprendrait la tâche 4 sur ces deux fichiers.

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

### Task 10 : L'en-tête à 320 px (constat 6) — après la réponse à la question ouverte 1

**Files :**
- Modify : `frontend/src/presentation/layout/AppHeader.vue` (lien de marque, groupe de droite, `<style scoped>`)
- Test : `frontend/tests/presentation/layout/AppHeader.spec.ts`

- [ ] **Step 1 : tests, rouges** (option (a))
- `it('la marque cède la place aux actions : son libellé se tronque, les actions ne rétrécissent pas')` — le libellé de marque est un `<span class="text-truncate">`, le lien porte la classe `brand-link` (`min-width: 0`), le groupe de droite `flex-shrink-0`.
- `it('le nom accessible du lien d\'accueil reste la marque entière')` — `text()` du lien = `brandName`.

- [ ] **Step 2 : rouges** — `docker compose exec frontend npm test -- tests/presentation/layout/AppHeader.spec.ts`.

- [ ] **Step 3 : implémenter (a)** — `brand-link` (`min-width: 0`, scopé), libellé en `<span class="text-truncate">`, `flex-shrink-0` sur le groupe de droite ; commentaire : mesures du ticket (331 et 389 px pour 301). Variante (b) si choisie : `<span class="brand-label">` masqué visuellement par `@media (max-width: 359.98px)` en CSS scopé (les règles de `.visually-hidden`), test « sous 360 px, le libellé porte la classe qui le masque ». Variante (c) : `flex-wrap row-gap-2` sur la ligne, test de classe ; noter dans le commentaire la hauteur gagnée par l'en-tête collant.

- [ ] **Step 4 : verts** — puis les trois `make front-*`.

- [ ] **Step 5 : commit** — `fix(frontend): l'en-tête tient à 320 px (#748)`.

---

### Task 11 : Contrôle au navigateur — **fil principal, pas un agent de tâche**

Pile de dev locale, FR et EN, axe-core injecté dans la page, noté dans la PR (référentiel WCAG 2.2 AA) :

- [ ] axe sur « Mes connexions MCP » (liste, état vide, erreur, session finie) ; menu du compte **ouvert** ; une page d'administration **avec le bandeau** (provoqué pour de vrai : déconnexion dans un autre onglet, puis « Enregistrer » → 403 sans jeton → bandeau seul, saisie intacte ; puis reconnexion dans l'autre onglet, retour → « Session rétablie », « Enregistrer » aboutit).
- [ ] Contrastes mesurés : « Révoquer » (repos, survol de ligne), « Supprimer » du backoffice, messages `text-danger-emphasis`, bandeau, « EN » inactif, liens du pied de page (version comprise).
- [ ] 320 px : page MCP sans défilement horizontal (palier de confiance) ; en-tête aux trois paliers, FR et EN, bouton du menu entier.
- [ ] Bandeau collant après « Enregistrer » en bas d'un long formulaire ; menu mobile ouvert : le bandeau reste sous l'en-tête ; un champ focalisé au clavier n'est pas masqué (2.4.11) ; zoom 200 %.
- [ ] Page MCP : plus de barre verticale en desktop ; contour de focus du dernier « Révoquer » entier ; « Réessayer » rend le focus ; révocation : focus au titre puis annonce (inspecteur d'accessibilité, sans lecteur d'écran réel — le noter non vérifié).
- [ ] Menu du compte : Échap depuis la page ne déplace pas le focus ; Tab après la dernière entrée le ferme (Chrome, Firefox) ; entrée courante visible.
- [ ] Tout écart → retour à la tâche concernée (nouveau commit), puis ce contrôle refait.

### Task 12 : Clôture — **fil principal**

- [ ] `make front-test`, `make front-lint`, `make front-build` verts sur la tête ; `tools/check-claude-rules.sh` vert (les `paths:` ajoutés correspondent à des fichiers suivis, aucune règle au-delà de 50 000 octets).
- [ ] #732 : réécrire le point 4 — traité par #748 pour `BackofficeHttpClient` et « Mes connexions MCP » (règle `endsTheSession`) ; restent, selon la réponse à la question ouverte 2, `HttpOAuthConsentRepository` (403 → `SessionExpiredError`) et `useAssistant` (401/403 → `markSessionExpired()`). Par `gh api -X PATCH repos/{owner}/{repo}/issues/732` (pas `gh issue edit`, cassé en gh 2.46 sur ce dépôt).
- [ ] `tasks/todo.md` : cocher « Tâche 11 » (critère de #748) dans le commit de clôture.
- [ ] Après la fusion dans `feature/379-mcp-paliers` : fermer #748 à la main (`Closes #748` ne ferme rien vers une base qui n'est pas `main`).
