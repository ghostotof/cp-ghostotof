# Quatrième relecture de #748 — diff b4fda293...45ec285d (R23 à R34), 2026-10-29

Quatre relectures : `/code-review medium` (CR4-*), audit sécurité (C*), axe Standards (St4-*), axe Spec (Sp4-*).
Aucun critique ni haut. Sévérité / confiance après chaque constat.

## Décisions de l'utilisateur (2026-10-29)
1. **C1 : corriger la cause côté serveur.** Le pare-feu `api` authentifie toute requête qui porte un `BEARER`, même
   sur une route `PUBLIC_ACCESS`, et répond 401 si le jeton est mort. Corriger cela (pistes : `lazy: true` sur le
   pare-feu `api` — l'authentificateur ne joue que si un contrôle d'accès en a besoin ; ou un authentificateur qui
   ignore un jeton invalide sur les routes publiques ; ou un pare-feu sans authentificateur pour les routes publiques
   — à étudier dans `security.yaml`, Lexik, et contre `ApiRouteExposureTest`, `RouterScopeTest`,
   `SecurityConfigEnvironmentParityTest`, `RouteEnvironmentParityTest`, `SecurityServicesEnvironmentParityTest`,
   `RefusedSessionCookiesAreClearedTest`, l'audit des refus (`SecurityAuditLogger`), le refus d'une session antérieure
   au changement de mot de passe, le limiteur de `base-access`, le consentement OAuth et le pare-feu `mcp`). **Puis
   retirer `credentials: 'omit'` (R23) et le rejeu d'« Accès instantané » (R24)**, et les tests/gardes/règles/ADR qui
   les décrivent ; documenter ce que devient un cookie mort (effacé par la première route protégée qu'il touche ?).
2. **Tout jusqu'à zéro** : corriger moyens, faibles, documentation et jugements de conception dans cette branche ;
   après la série, les quatre relectures ; on recommence tant qu'elles trouvent quelque chose.
3. **C7 : limite acceptée et documentée** (un changement de compte dans un autre onglet, sans session finie, n'est
   détecté par rien ; antérieure à #748 ; suppose un accès physique au même navigateur) — dans la règle et la PR.

## Moyens
- **C1** (moyenne / ~85 %) — `credentials: 'omit'` exclut aussi l'authentification HTTP (spécification Fetch) : en
  préprod (Basic Auth sur l'Ingress principal, `k8s/overlays/preprod/kustomization.yaml:48,104-112`), les 8 dépôts
  publics prennent le 401 de l'ingress. Les curl du pipeline (`smoke-test-preprod`, `audit-preprod`, `pipeline.yml`
  ~890-937) envoient la Basic Auth et ne le voient pas → décision 1. Ajouter un contrôle préprod qui le verrait
  (curl avec Basic Auth **sans** cookie sur une lecture publique, ou contrôle navigateur à la tâche 11).
- **CR4-1 / C3** (moyenne / moyenne) — `backofficeSessionExpiry.ts:42`, `AccessRefusedNotice.vue:48-51`,
  `backofficeAccessRefusal.ts:54-57` : le périmètre de refus avance à tout changement de `route.fullPath`, y compris
  le fragment (`#main-content` du lien d'évitement, `AppLayout.vue:72-77`) ; un 403 réel sur un **enregistrement**
  revenu après une navigation est ignoré (aucune alerte, aucune relecture du palier) et `visibleMutationError` le
  tait : l'enregistrement refusé passe pour réussi. Pistes : ne réinitialiser que sur `route.name`/`params`/`query`
  (pas le hash) ; un refus d'**écriture** n'est jamais périmé pour la personne qui l'a déclenché (au minimum un
  message sur la page/au formulaire qui a envoyé, et la relecture du palier).
- **CR4-2 / C5** (moyenne-faible / faible-moyenne) — `AppLayout.vue:49-67` : `isReplaying` booléen partagé ; deux
  rejeux qui se chevauchent démasquent la page trop tôt → un jeton par rejeu (`const mine = ++replayId`).

## Faibles
- **Sp4-1 / C2** — `LoginPage.vue:24-42`, `main.ts:121-125` (`app.mount` avant `checkAuth()`), route `login` sans
  `waitForAuthCheck` : dans un onglet neuf, « Votre session a pris fin » s'affiche puis bascule sur l'annonce neutre.
  Ne rien annoncer tant que `isChecking`, ou faire attendre `waitForAuthCheck()` au `beforeEnter` de `login` ; test
  avec `/api/me` résolu **après** le montage.
- **CR4-7** — `LoginPage.vue:24` : `isSignedIn` ne regarde que `user` ; un visiteur au palier de base avec
  `expired=1` lit « session finie » alors qu'il a encore l'accès de base (tenir compte du palier).
- **CR4-3** — `sessionState.ts:137` : `markBaseAccessGranted()` laisse `sessionEnded` vrai ; après une session finie,
  « Accès instantané » laisse le bandeau à côté d'un en-tête au palier de base, et `forgetLastAccount()` fait d'un
  retour ultérieur un changement de compte. Une obtention de l'accès de base est une session décidée dans l'onglet :
  elle clôt l'état « session finie ».
- **CR4-4 / C4 / Sp4-2** — `credentialsAreExplicit.spec.ts` juge un fichier, pas chaque `fetch` → **devient sans objet
  si `omit` est retiré** (décision 1) ; sinon, à réécrire par appel (compter `fetch(` et `credentials:` ou AST).
- **C6** — R24 sous A3-3 (cookie planté) : deux 401, deux jetons → sans objet si le rejeu est retiré (décision 1).
- **CR4-6** — `useAdminAboutSettings.ts:79` : erreurs par langue effacées seulement par le prochain enregistrement de
  la langue ; deux enregistrements FR qui se chevauchent : le plus ancien, en échec, pose une erreur après le succès
  du plus récent ; une erreur FR reste sous un formulaire FR rechargé depuis le serveur. Piste : estampiller chaque
  enregistrement (seul le dernier d'une langue pose son verdict) et effacer l'erreur d'une langue quand sa lecture est
  rechargée.
- **CR4-8 / Sp4-3** — `backofficeAccessRefusal.ts:73` : `scope` et `refusals` (et `sessionReaction` de
  `BackofficeHttpClient`) ne sont pas remis à zéro par `resetAuthStateForTests()` / `tests/support/setup.ts`.
- **St4-4** (faible-moyenne / haute) — `useAuth.ts` : `recheckEndedSession` garde un `finally` interne qui, si `me()`
  lève de façon synchrone, laisse la relecture bloquée pour toujours (le défaut que `rereadAfterRefusal` a corrigé) →
  un utilitaire `singleFlight` partagé par les deux.
- **CR4-10 / C10** — `JwtClockSkewTest.php:63` : « expiré depuis 3 s servi » n'a que ~2 s de marge (résolution à la
  seconde) → instable en CI ; figer l'horloge ou `exp = now - 1` ; ajouter une borne à 7 s (refusé) pour épingler la
  tolérance.

## Documentation
- **St4-1** (moyenne / haute) — `sessionGeneration.ts` (en-tête, `advanceGeneration`) : dit « à chaque fin acceptée et
  à chaque retour » alors que `login`, `logout`, `markBaseAccessGranted`, `markBaseAccessExpired` l'avancent aussi.
- **St4-2** — `.claude/rules/frontend-session-retour.md` l.117-119 : `canRestore` exigerait « an account, a write
  token and the current generation » alors que la génération est jugée par l'appelant (l.26 de la même règle) ;
  l.119-120 oublient `rereadAfterRefusal`/`applyReadSession`.
- **St4-3** — `sessionEvents.ts` `notify` : le message « Un abonné au retour de la session… » sert aussi pour
  `onAccountSwitched`.
- **Sp4-c** — docblock de `RefusedSessionCookiesAreClearedTest::PUBLIC_ROUTE` : « St3-3 » → « St3-12 ».
- **C11** — politique de cookies (`content/fr.ts:145`, `en.ts:145`) et registre RGPD : « supprimé dès qu'il est
  présenté après l'expiration du jeton » → « dès qu'il est présenté et refusé (jeton expiré ou invalidé) » — à revoir
  de toute façon selon la décision 1 (un cookie mort n'est plus refusé sur une route publique).
- **C7** : limite acceptée à écrire (décision 3).
- Sp4-b : R32 a changé `lastUpdated` de la politique de confidentialité et le registre RGPD sans que le plan le
  demande — garder (le texte publié a changé), le dire dans la PR.

## Jugements de conception
- **CR4-5 / St** — altitude : la cause côté serveur → décision 1.
- **CR4-9** — 7 dépôts publics répètent `credentials: 'omit'` et un docblock → sans objet avec la décision 1 ; si un
  envoi public partagé reste utile (un seul endroit qui dit « route publique »), le justifier.
- **St4-5** — `sessionState.ts` exporte `state` mutable ; `useAuth.checkAuth` écrit `state.isChecking = true` alors que
  `markFirstCheckDone` gère ce drapeau → une fonction (`markChecking()`), `state` non exporté en écriture.
- **St4-6** — `isSameAccount` exporté sans consommateur externe ; les `reset…ForTests` dans `src/` (C12 : vérifier
  qu'ils ne sont pas dans le bundle de production, `grep` sur `dist/`).
- **St4 (jugement)** — `BackofficeSessionReaction` dans `domain/` porte une notion de navigation (`refusalScope`) :
  revoir le nom/la place si le correctif CR4-1 change ce périmètre.

## Hors périmètre (ticket `__Host-`)
F12, S2, A3-3 (cookie planté par un sous-domaine), C8 (« même compte » = identifiant, pas l'UUID), et le pare-feu
sans authentificateur pour `/api/account/base-access` s'il n'est pas couvert par la décision 1.
