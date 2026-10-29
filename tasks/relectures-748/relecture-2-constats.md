# Deuxième relecture de #748 — diff 0040068c...be0b8d8d (corrections R1 à R11), 2026-10-29

Quatre relectures : `/code-review medium` (A–J), audit sécurité (F1–F13), axe Standards (St*), axe Spec (Sp*).
Chaque constat : sévérité / confiance.

## Décisions de l'utilisateur (2026-10-29)
1. **F1 : deux cookies de session.** `BEARER` (`set_cookies.BEARER.lifetime: 0` dans `lexik_jwt_authentication.yaml`,
   vérifier que Lexik l'accepte : `JWTCookieProvider::createCookie`) et `XSRF-TOKEN` deviennent des cookies de session ;
   le JWT garde `exp` = `token_ttl` (1 h). Vaut pour le login (`LoginSuccessSubscriber` : `xsrf()` sans échéance) **et**
   l'accès de base (`BaseAccessController` : `bearer`/`xsrf` sans échéance). Le test `AuthCookieAttributesTest` de R1
   change de sens (les deux cookies sans `Expires`, sur les deux chemins). Amender l'ADR 0003 (le risque « navigateur
   fermé » disparaît pour lecture et écriture, sauf navigateur qui restaure la session) et
   `security-authentication-sessions.md`. Note de release : toute session ouverte avant le déploiement garde ses
   cookies persistants ≤ 1 h. Le constat H (`lifetime` qui répète `token_ttl`) est absorbé par ce changement.
2. **F4 : vrai 403 dans le backoffice traité comme R7.** `BackofficeHttpClient` lève `AccessForbiddenError` pour un 403
   qui n'est pas une fin de session ; `useAdminLoad` et `visibleMutationError` relisent `/api/me` une fois, affichent
   `common.accessRefused` et « Changer de compte » (`switchAccountLocation`).
3. **F3/D : même compte, sinon rejouer.** Retenir le compte dont la session a fini (au `markSessionExpired()`) ; ne
   rétablir « en place » (annonce « Session rétablie ») que si le compte revenu est le même ; sinon appliquer la session
   et rejouer la navigation (`router.replace(route.fullPath)`) pour que la garde de route juge le nouveau compte et que
   la page se remonte (l'assistant ne garde pas la conversation du premier compte).
4. **Périmètre : tout ici** (comportement, documentation, jugements de conception). F12 seul part dans le futur ticket
   `__Host-` (hors périmètre).

## Défauts de comportement
- A (moyenne/haute) `useMcpConnections.ts:112` — au retour de session, le watch relit la liste ; `load()` vide
  `refusal` mais garde `lastOutcome = 'failed'` → alerte « révocation échouée » (role=alert) que personne n'a
  déclenchée (`'failed' === lastOutcome && null === refusal` dans `McpConnectionsPage`).
- B = F2 (moyenne/moyenne) `presentation/router/sessionEnded.ts:37` + `router/index.ts:212` — `signInAgainLocation`
  ne pose que `expired=1` ; le `beforeEnter` de `login` renvoie à l'accueil une personne connectée sans `reauth=1`.
  Cas : `BEARER` vivant, `XSRF-TOKEN` absent → impasse jusqu'à 1 h. Piste de l'audit : laisser `expired=1` ouvrir la
  page comme `reauth=1` (le drapeau n'ouvre que la page). Avec la décision 1 le cas devient plus rare, pas impossible
  (cookie supprimé seul, navigateur qui restaure).
- C (basse-moyenne/moyenne) `BackofficeHttpClient.ts` `react()` — la réponse 401 / 403-sans-jeton d'une requête partie
  **avant** le rétablissement refinit la session juste après. + F5 (info) : `applySession()` (via `checkAuth()`)
  abaisse `sessionEnded` sans `hasWriteToken()` alors que `recheckEndedSession()` l'exige ; une réponse `/api/me` en
  vol avant un nouveau `markSessionExpired()` peut annoncer à tort « rétablie ». Piste : une seule fonction de
  descente qui exige `hasWriteToken()` ; noter `sessionEndedCount` (ou une génération) au départ et ignorer une
  réponse d'une génération périmée — dans les deux sens (fin et retour).
- E (basse/moyenne) `SessionEndedBanner.vue:87` — `:key="sessionEndedCount"` détruit et recrée l'alerte et le
  `SignInAgainLink` qu'elle contient : un focus clavier sur le lien tombe sur `<body>`.
- Sp-c1 = S7 revu (moyenne a11y / moyenne) — `markSessionExpired()` incrémente à chaque refus ; `AdminWatchPage`,
  `AdminQualityPage`, `AdminAboutPage` lancent 2 à 4 lectures en parallèle dont les 401 arrivent dans des ticks
  distincts → bandeau réannoncé plusieurs fois au chargement. Le test ne couvre que « le même tick ». À reprendre avec
  E : réannoncer un **nouveau refus d'un geste de la personne** (mutation), pas chaque lecture ; sans détruire le lien
  focalisé (changer le contenu d'une région, pas l'élément qui porte le lien).
- F (basse/basse-moyenne) `useMcpConnections.ts:104` (même motif dans `useAssistant`, `useOAuthConsent`) — sur
  `AccessForbiddenError`, `checkAuth()` sans attente ; si `/api/me` répond 401, l'en-tête passe en anonyme **sans**
  `sessionEnded` : la page dit « forbidden / Changer de compte », l'en-tête montre un visiteur, et la session n'est
  plus revérifiée. Appels non dédoublonnés (load et revoke).
- G (basse/basse) `AccountMenu.vue:90` — `activeElement === body` vaut aussi quand l'élément focalisé ailleurs vient
  d'être retiré (rendu) : Échap envoie alors le focus au bouton du menu (vol du focus que le constat 12 voulait
  éviter). Piste : retenir au moment de l'ouverture si le focus était libre / dans le menu.
- F7 (info) `AppHeader.vue` sur `oauth-consent` — « Connexion » de l'en-tête en nouvel onglet ouvre une seconde page
  sur la même demande OAuth ; piste : sur cette route, même onglet, par le même constructeur (et mettre à jour la
  phrase de `mcp-oauth.md` qui documente la différence).
- F8 (info) `SignInAgainLink.vue` — rendu sur une route non `requiresAuth`, il garde un `redirect` de `fullPath` que la
  garde `protectedRoutesCarryNoSecret` n'a pas revu : rendre un lien **sans** `redirect` hors route protégée, et le
  tester.
- F10 (info) + Sp-c3 — `useAdminAboutSettings.ts` / `useAdminLoad.ts` : (1) save FR lent → EN → FR : un `GET FR`
  servi avant le COMMIT du `PUT` repose les anciennes valeurs après la réponse du `PUT` (mise à jour perdue) — piste :
  faire avancer le `requestGuard` à chaque enregistrement réussi pour la même clé ; (2) un **échec** du save FR revenu
  après le passage en EN pose `errorMessage` sous l'en-tête EN — le rattacher à sa locale (ou l'ignorer si la locale
  a changé). Constat St : `requestedLocale` (R11) double `held` de `useAdminLoad` → une seule source de la clé courante
  (exposer la clé détenue/demandée depuis `useAdminLoad`).
- F11 (info) `AdminAboutSettingsForm.vue` — `proposalSourceLocale.value = null` avant l'enregistrement : ne l'effacer
  que si `save()` rend `true`.
- F13 (info) `infrastructure/auth/csrfCookie.ts` — `readCsrfToken()` appelle `decodeURIComponent` : un
  `XSRF-TOKEN=%E0` (posé par un sous-domaine) fait échouer chaque écriture ; attraper l'erreur et traiter comme absent
  (ou comme valeur brute), tester.

## Documentation (Standards et Spec)
- `.claude/rules/frontend-session.md` l.288 : `SessionEndedBanner.spec.ts` décrit comme testant « the re-read on
  `focus` » alors que R8 l'a retiré (et l.205 dit l'inverse).
- `frontend-session.md` `paths:` incomplets : `src/application/mcp/**`, `src/application/assistant/**`,
  `src/infrastructure/auth/**`, `src/domain/auth/repositories/**`, `tests/application/auth/**`,
  `tests/support/signInAgain.ts`, `usePublishedHeight.ts` (son spec est listé). Chaque glob doit correspondre à un
  fichier suivi (`tools/check-claude-rules.sh`).
- `frontend-session.md` l.38 : « Later tasks of #748 (R4 to R9) add their sections here » — périmé.
- Docblocks périmés : `useFocusThenAnnounce.ts` (omet `isUnmounted`) ; `reauthentication.ts` (`REAUTH_QUERY` présenté
  comme propre au consentement) ; `sessionEnded.ts` (`SESSION_ENDED_QUERY`) et `frontend-a11y.md` l.78 (« un 401 »
  seul) ; `useMcpConnections.ts` l.170 et `useOAuthConsent.ts` l.199 (« Rétablir la session est l'affaire de
  `useSessionRecheckOnFocus` » → c'est `useAuth().recheckEndedSession()`) ; `backofficeSessionExpiry.ts`
  (« idempotente… rien à dédoublonner », faux depuis R9) ; commentaire de `main.ts` (« un 401 » seul).
- Clé i18n `oauthConsent.errors.sessionExpired` lue pour la raison `'session-ended'` (renommage R2 incomplet).
- Commentaire d'`AppHeader.vue` qui renvoie à « la tâche 11 du plan de #748 » (fichier qui sera archivé hors de
  `tasks/`) : renvoyer à une règle.
- Sp-b1 : `checkAuth()` silencieux après la 1re vérification (R8) contredit la phrase du plan « `checkAuth()`
  (chargement initial) ne change pas » : écrire dans la règle que `waitForAuthCheck` ne retient que la première
  vérification, et pourquoi.
- Sp-b2 : les mutations d'administration rendent `Promise<boolean>` et les pages se fient à ce booléen (R3) : à
  écrire dans `frontend-backoffice.md` / `frontend-session.md` comme invariant (protège la saisie sous le bandeau).

## Jugements de conception
- Le watcher « `authState.sessionEnded` vrai → faux » écrit quatre fois (`useAdminLoad`, `useMcpConnections`,
  `useOAuthConsent`, `useAssistant`) → un `onSessionRestored(cb)` exposé par `useAuth`, qui arrête son watch au
  `onScopeDispose` (aujourd'hui seul `useAdminLoad` le fait) ; et ces composables lisent `authState` du module au lieu
  de `useAuth()`.
- `void checkAuth().catch(() => undefined)` répété trois fois (et bientôt dans le backoffice, décision 2) → une
  fonction de `useAuth` (« relire le palier après un refus ») qui dédoublonne (une seule relecture en vol) et règle F.
- `Parameters<typeof sessionAwareFetch>[1]` dans deux dépôts → type exporté `SessionAwareInit`.
- I : `switchAccountLocation` copie `signInAgainLocation` sauf le drapeau → un `loginLocation(route, i18nLocale, flag)`.
- J : « a un jeton d'écriture » testé à deux endroits (`HttpAuthRepository.hasWriteToken()` et `sessionAwareFetch`) →
  un seul `hasCsrfToken()` dans `csrfCookie.ts`.
- `HttpMcpConnectionsRepository` importe `ApiProblemBody`/`hasProblemType` de `admin/shared` (couplage inter-contexte,
  antérieur) → déplacer vers un module partagé neutre (`infrastructure/http/`).
- Backend : `LoginSuccessSubscriber` calcule l'échéance avec `time()` — devient sans objet si la décision 1 supprime
  l'échéance ; sinon `ClockInterface`.

## Hors périmètre
F12 (jeton CSRF lié ni à la session ni au temps) → ticket `__Host-` avec S2.
