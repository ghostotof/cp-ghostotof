# Cinquième relecture de #748 — diff fc99f3b4...cce0310a (R35 à R45), 2026-10-30

Quatre relectures : `/code-review medium` (CR5-*), audit sécurité (C5-*), axe Standards (St5-*), axe Spec (Sp5-*).
**Aucun critique ni haut ; aucun contournement** (l'audit a vérifié qu'une route qui exige un rôle n'est jamais servie en
anonyme et qu'une session refusée ne laisse aucune identité). Sévérité / confiance après chaque constat. Les doublons
entre relectures sont regroupés sous le premier identifiant, les autres entre parenthèses.

## Décisions de l'utilisateur (2026-10-30)
1. **C5-1 (= CR5-5) : limite acceptée, écrite.** Q1 inchangé (cookie gardé, audit à chaque requête) ; la rafale de lignes
   `session-refused-after-credentials-change` d'un onglet légitime est documentée dans
   `security-authentication-sessions.md` (bornée par la zone nginx `publicapi` et la vie de la page : `/api/me` à chaque
   chargement l'efface ; un attaquant n'y gagne rien).
2. **C5-2 (= CR5-6) : accepté et documenté.** Une lecture publique d'une personne connectée authentifie (JWT + un SELECT du
   compte) ; conséquence de la décision 1 de la 4e série, écrite dans la règle et la PR.
3. **CR5-3 / CR5-4 (= Sp5-11) : un seul enregistrement par langue.** Le bouton d'une langue est désactivé tant que sa
   sauvegarde est en vol ; « en cours » par langue. Le chevauchement devient impossible ; `lastSaveOf` (R43) devient
   probablement superflu — à trancher au plan (le retirer ou le garder en défense).
4. Toujours valables : décision 2 de la 4e série (**tout jusqu'à zéro** : moyens, faibles, doc, conception, puis les quatre
   relectures, on recommence) ; C7 limite acceptée ; à dire dans la PR : C7, Sp4-b, C5-1, C5-2.

## Moyens
- **CR5-1 (= C5-3a)** `backofficeSessionExpiry.ts:40` — une écriture timbrée `refusalScope = null` compte toujours, même
  après le démontage d'`AdminLayout` (et d'`AccessRefusedNotice`) ; rien ne remet le compteur à zéro au montage suivant →
  alerte « accès refusé » plus tard sur une page sans rapport, plus une relecture `/api/me` inutile. Moyenne / moyenne
  (audit : faible / haute). Pistes : compter seulement avec une alerte montée, ou `clearBackofficeAccessRefusal()` au montage.
- **CR5-2 (= C5-3b)** `backofficeSessionExpiry.ts:44` — `refused()` ignore `sentAt.session` : le 403 tardif d'une écriture
  du compte A s'affiche pour B après un changement de compte (« Changer de compte » pour un geste de B jamais fait).
  Moyenne / moyenne. Piste : ne compter une écriture que si `isCurrentGeneration(sentAt.session)` ; effacer aussi sur
  `onAccountSwitched`.
- **St5-1** docblocks périmés depuis R41 : `sessionLifecycle.ts` (`restoreSession` : « **La** descente »), `sessionState.ts`
  (`applySession` : « qui seuls »). Moyenne / haute.

## Faibles
- **CR5-7 (= C5-5)** `router/index.ts:218` — `/login` attend `waitForAuthCheck()` sans borne ; un `/api/me` qui pend
  (aucun délai ni `AbortSignal`) empêche toute connexion. Faible / moyenne. Piste : `Promise.race` avec un délai, ou
  `AbortSignal.timeout()` sur `me()`.
- **CR5-8 (= St5-14)** `AccessRefusedNotice.vue:53` — clé `path + JSON.stringify(query)` sensible à l'ordre des
  paramètres ; une revisite du même `fullPath` n'avance pas le périmètre (le docblock dit « deux visites, deux
  périmètres »). Faible / faible.
- **CR5-9 (= St5-3)** `sessionState.ts:251` — `raiseSessionEnded` / `lowerSessionEnded` exportés à tout `src/` ;
  l'encapsulation R42 n'est tenue que par un commentaire. Piste : garde d'imports (comme `signInLocations.spec.ts`).
  Le commentaire du spec d'encapsulation (« les trois écritures ») est faux. Faible / moyenne.
- **CR5-10 (= C5-4)** `tools/smoke-dead-session-cookie.sh:100` — curl en échec (6, 7, 28) sous `set -e` sort sans message,
  hors du contrat 0/1/2. Faible / haute. Piste : `|| { rc=$?; echo …; exit 1; }` + un cas hors ligne.
- **Sp5-2** R37 a perdu le test « rejet réseau » d'« Accès instantané » (`rejects.toBe(failure)`) et les 403/500 un par un ;
  le nouveau test 429 double l'existant. Faible / haute.
- **Sp5-1 (= C5-9)** invariant n° 8 : ne compare que les statuts (effacement sur une route protégée vérifié sur `/api/me`
  seulement) ; aucun cas « JWT signé d'un compte supprimé », ni `cv` périmé, ni `%XX`. Faible-info / haute.
- **St5-2** `backofficeAccessRefusal.ts`, docblock de `scope` : « avance … à chaque navigation » (faux depuis R38 : le
  fragment n'efface plus). Faible / haute.
- **St5-4** `frontend-session.md`, liste des tests : « cleared by its first 401 » → « of a protected route ». Faible / moyenne.
- **St5-5** `k8s/README.md` §2bis : les scripts qui lisent `PREPROD_BASIC_AUTH` ne citent pas `smoke-dead-session-cookie.sh`.
  Faible / moyenne.

## Conception, duplication (jugements)
- **St5-6** tests backend : charge du JWT expiré écrite trois fois, `new Cookie(BEARER, …)` trois fois, `PUBLIC_ROUTE` /
  `PROTECTED_ROUTE` copiées → un trait dans `tests/Support`. Faible / haute.
- **St5-7** `testThePredicateAgreesWithTheAccessListenerOnEveryRoute` réécrit `concreteUrl()` d'`ApiRouteExposureTest`.
  Faible / moyenne.
- **St5-8** le smoke recopie `auth_config`, `excerpt`, `fetch` de `smoke-login-throttling.sh` / `smoke-mcp.sh` ; `tools/lib/`
  existe. Faible / haute.
- **St5-9** même paragraphe de trois lignes (« Aucun `credentials`… Jamais `'omit'`… ») dans les huit `Http*Repository` →
  une phrase qui renvoie à `frontend.md`. Faible / haute.
- **St5-10** `refusalScope: number | null` (null = écriture) → union discriminée `{kind:'read', scope} | {kind:'write'}` ;
  **rouvert** : le jugement St4 « `domain/` porte une notion de navigation » (sa condition, CR4-1, est remplie). Faible / moyenne.
- **St5-11** « seul le dernier gagne » écrit une fois de plus (`lastSaveOf`, `replayId`, à côté de `sessionGeneration`,
  `scope`, `useAdminLoad.isCurrentKey`). Info / faible.
- **St5-12** API dictée par les tests : `requiresAccess()` public, `SingleFlight.clear()` dans l'interface de production,
  `resetBackofficeAccessRefusalForTests` dans `src/`. Info / moyenne.
- **St5-13** `LoginPage.vue` : `'anonymous' === tier.value` reproduit `isAuthenticated`. Faible / moyenne.
- **St5-15** `singleFlight.ts` (utilitaire générique) rangé sous `application/auth/`. Info / faible.
- **St5-16** `security-authentication-sessions.md` : la puce de `StaleSessionCookiesClearingListener` documente aussi l'écouteur
  R35 → sa propre puce ; docblock de 75 lignes de `PublicRouteIgnoresRefusedSessionListener` qui raconte l'historique.
  Info / moyenne.

## Info (audit, spec)
- **C5-6** le smoke ne vérifie que `BEARER`, jamais `XSRF-TOKEN` (étape 2 : aucun des deux posé ; étape 3 : les deux expirés).
- **C5-7** un `\n` dans `AUDIT_BASIC_AUTH` injecte une directive dans le fichier `-K` (secret tenu par le propriétaire) →
  refuser `\r`/`\n` (sortie 2). Même motif dans `smoke-login-throttling.sh:59`.
- **C5-8** le test hors ligne ne vérifie pas le contenu du fichier `-K` (échappement de `"` et `\`).
- **C5-10** `noOmittedCredentials.spec.ts` ne voit que le littéral (`const mode: RequestCredentials = 'omit'` passe) →
  élargir ou écrire la limite.
- **C5-11** en dev (deux origines), les lectures publiques partent sans cookie : R35 n'est exercé que par PHPUnit et le smoke
  → une ligne dans `frontend-session.md`.
- **C5-12** la correction suppose « toute autorisation de `/api` passe par `access_control` » sans garde ; un futur
  `#[IsGranted]` sur une route sans règle n'ouvrirait rien (401 de l'anonyme) mais n'effacerait pas le cookie → recensement
  facultatif (`IsGranted`, `security:`, `denyAccessUnlessGranted`).
- **C5-13** `/api/logout` avec `BEARER` mort et sans `XSRF-TOKEN` valide : 403 CSRF, pas d'effacement ; préexistant, aucune
  correction requise.
- **Sp5-3** le test R39 dépend du moment où vue-router annule la première navigation. Info / moyenne.
- **Sp5-4** limiteur lu par `getContainer()` et non `TestServiceLocator` (interdit par son docblock). Info.
- **Sp5-5 / Sp5-6 / Sp5-7 / Sp5-8** fichiers ou tests hors table, cohérents. Info.
- **Sp5-9** R36 ne reproduit pas C1 (curl envoie la Basic Auth) : C1 se voit au navigateur, tâche 11. Info.
- **Sp5-10** ADR 0003 l. 272 gagnerait « qui exige un rôle ». Info.
