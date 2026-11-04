# Troisième relecture de #748 — diff be0b8d8d...b4fda293 (R12 à R22, R21b), 2026-10-29

Quatre relectures : `/code-review medium` (CR3-*), audit sécurité (A3-*), axe Standards (St3-*), axe Spec (Sp3-*).
Aucun critique ni haut. Sévérité / confiance après chaque constat.

## Décisions de l'utilisateur (2026-10-29)
1. **R21b est remplacée** : retirer la minuterie de déconnexion de l'accès de base (`endBaseAccessAtExpiry`,
   `knownRepository`, écouteurs `focus`/`visibilitychange` ajoutés par R21b, `logout()` passé par `sessionAwareFetch`
   seulement si c'est encore utile ailleurs) ; à la place, **`credentials: 'omit'` sur tous les dépôts publics qui
   n'ont besoin d'aucun cookie** (lectures publiques : about, contributions, incidents, quality, watch, experience… ;
   contact ; `password-setup` / `HttpAccountRepository` — vérifier chacun : un dépôt qui sert aussi une route
   authentifiée ne passe pas en `omit`) ; et **un rejeu unique d'« Accès instantané » sur 401** (`HttpBaseAccessRepository`
   garde `credentials: 'include'`, il doit recevoir `Set-Cookie` ; le 401 a effacé le cookie mort, le second essai
   passe ; vérifier si le limiteur de `base-access` consomme un jeton avant le pare-feu). Mettre à jour l'ADR 0003, la
   règle `security-authentication-sessions.md`, `frontend-session.md` et `frontend.md` (le premier 401 après
   l'expiration n'atteint plus une page publique ; il ne reste que sur les routes authentifiées, où il est lu comme une
   session finie). Garder l'expiration côté client existante de l'accès de base (repasser l'en-tête à anonyme) telle
   qu'elle était avant R21b, sans appel réseau.
2. **Périmètre : tout ici** — moyens, faibles, documentation, et découpage de `useAuth.ts` (+ isolement de l'état de
   module dans les tests). F12/S2 (`__Host-`) reste hors périmètre (ticket à venir, y rattacher A3-3).

## Défauts de comportement — moyens
- CR3-1 = A3-1 = Sp3-1 (moyen-haut) : logout d'échéance de R21b qui coupe la session d'un autre onglet → **disparaît
  avec la décision 1**.
- A3-2 (moyen) : horloge cliente en avance → logout immédiat → **disparaît avec la décision 1** (l'expiration côté
  client qui ne fait que repasser l'en-tête à anonyme reste soumise à l'horloge : effet d'affichage seulement, à écrire
  comme limite si elle subsiste).
- CR3-2 (moyen/moyenne) `StaleSessionCookiesClearingListener.php:56` — tout 401 de cookie refusé envoie des
  `Set-Cookie` expirés par nom ; une réponse tardive (lecture parallèle, autre onglet) efface les cookies **neufs**
  posés après l'envoi (connexion, nouvel accès de base). Piste à étudier et trancher : ne pas effacer quand la requête
  portait un cookie différent de celui… (le serveur ne voit que le cookie envoyé) — options : (a) côté client, réduire
  les requêtes qui portent un cookie mort (décision 1 en retire la plupart) ; (b) côté serveur, n'effacer que si le
  JWT refusé est expiré depuis peu / invalide, jamais… ; (c) accepter et documenter. Le plan doit choisir et justifier.
- CR3-3 = A3-4 (moyen/haute) : en prod (même origine), lectures publiques, contact, `password-setup` envoient le
  `BEARER` mort et prennent le 401 → **résolu par la décision 1** ; `SetPasswordPage` (route publique, le garde n'attend
  pas `checkAuth()`) : vérifier le cas du lien d'invitation ouvert avec un cookie mort.
- CR3-4 = A3-8 (moyen/moyenne) `useAuth.ts:445` — `login()` hors session finie, `logout()` et
  `markBaseAccessExpired()` n'avancent pas `generation` : un `/api/me` parti avant (relecture après refus,
  `checkAuth`) réapplique l'ancien compte après une connexion à un autre ou après une déconnexion (en-tête « connecté »
  sans cookie). Piste : `generation += 1` dans `logout()` avant l'envoi, dans `markBaseAccessExpired()`, et dans toute
  branche de `login()`. Test : `me` différé, `logout()`, puis `me` résout A → l'en-tête reste anonyme.
- CR3-5 = A3-7 = Sp3-2 (moyen/moyenne) `useAuth.ts:147` — `isSameAccount` traite `endedFor === null` comme le même
  compte (cas : rétrogradé → `rereadAfterRefusal` applique le palier de base, `user` nul → session finie → un autre
  compte revient : rétabli en place, données et conversation du premier compte). Un même `username` revenu avec moins
  de rôles garde aussi la page montée. Piste : retenir le dernier compte appliqué (pas seulement à la première fin) ;
  `null` = inconnu → rejouer ; comparer aussi l'ensemble des rôles.
- St3-1 (moyen/haute, **violation dure**) `frontend/src/infrastructure/portfolio/content/{fr,en}.ts:145-146` — la
  politique de cookies publiée dit que `BEARER` « expire à l'expiration du jeton (1 h / 15 min) » et que
  `XSRF-TOKEN` « expire avec lui » : faux depuis F1 (cookies de session). Texte d'information CNIL : corriger FR et EN,
  et chercher toute autre mention publique (mentions légales, page de connexion, accès de base).
- St3-5 (moyen/haute) `useAuth.ts` : 598 lignes, cinq responsabilités (état du palier ; minuterie de l'accès de
  base ; génération ; diffusion des deux événements ; deux relectures mutualisées). **Décision 2 : découper** (ex.
  `baseAccessExpiry.ts`, `sessionEvents.ts`, `sessionGeneration`…), sans changer l'API publique de `useAuth()` si
  possible. St3-6 : `knownRepository` (localisateur de service, couplage temporel) → disparaît avec la décision 1 ;
  s'il en reste un besoin, injection explicite au démarrage (`main.ts`). St3-10 (moyen/moyenne) : tests dépendants de
  l'état de module (`generation`, `endedAccount`, `refusalReread`, `knownRepository`, `Set` d'abonnés jamais vidés,
  sondes non démontées) → une remise à zéro de test exportée (`resetAuthStateForTests()` ou équivalent) appelée dans
  un `beforeEach` partagé (`tests/setup` si le projet en a un), `enableAutoUnmount`.

## Défauts de comportement — faibles
- CR3-6 (faible-moyen/moyenne) `useAuth.ts:560` — `rereadAfterRefusal` applique le compte que `/api/me` renvoie sans
  détecter un autre compte (R18 contourné) : onglet A en backoffice, compte B connecté dans l'onglet B, 403 dans A →
  `/api/me` rend B → en-tête B, page avec les données de A. Piste : passer par la même détection que R18 (rejouer la
  navigation si le compte change).
- CR3-8 (faible/moyenne) `BackofficeHttpClient.ts:98` — un 403 d'une requête de la page qu'on vient de quitter
  s'affiche sur la suivante (le compteur est remis à zéro au changement de route puis remonté par la réponse tardive).
  Piste : estampiller le refus par la route (ou une génération de navigation) et ignorer un refus périmé.
- CR3-9 (faible/moyenne) `useAuth.ts:452` — connexion en session finie sans `XSRF-TOKEN` lisible : `sessionEnded`
  reste vrai et `endedAccount` garde l'ancien compte après une connexion réussie. Piste : une connexion réussie est
  toujours un retour (le backend pose le cookie à la connexion) ; si le cookie manque, ne pas laisser l'état
  incohérent.
- A3-5 (faible/haute) — premier « Accès instantané » qui échoue s'il reste un cookie mort → **rejeu unique sur 401
  (décision 1)**.
- A3-6 (faible/moyenne) — course du logout de R21b → **disparaît avec la décision 1**.
- A3-9 (faible-info/basse-moyenne) — deux pods aux horloges divergentes pendant un déploiement progressif : un `iat`
  dans le futur donne `JWT_INVALID`, et depuis R21 le 401 efface la session. Piste : `clock_skew: 5` dans
  `lexik_jwt_authentication.yaml` ; vérifier l'effet sur `JwtAuthenticationInstant` (fraîcheur de 10 min du
  consentement, lit `iat`) et `CredentialsVersionClaim`.
- A3-10 (info) — `expired=1` ouvre la page à une personne connectée avec « votre session a pris fin » : n'afficher
  cette annonce que si `authState.sessionEnded` est vrai dans l'onglet, sinon un texte neutre.
- A3-11 (info) — docblock de `EXCLUDED_PATHS` (`CsrfCookieRequestSubscriber.php`) : « jamais atteint avec un cookie
  BEARER » est faux en prod ; la décision 1 le rend vrai côté frontend (dire pourquoi).
- A3-13 (info) `AppLayout.vue:40-54` — entre `restoreSession()` et la fin du rejeu, l'ancienne page reste montée une
  ou deux micro-tâches sous l'en-tête du nouveau compte : masquer la page (`v-if`) pendant le rejeu.
- Sp3-3 (faible/moyenne) `applyReadSession` — une relecture qui tombe sur le palier de base pendant une session finie
  pose quand même ce palier (en-tête « base » avec `sessionEnded` vrai) ; la règle dit qu'elle « changes nothing ».
- Sp3-4 (faible/haute) R20 — `save()` efface `lastFailure` quelle que soit la langue, et un échec EN remplace
  l'erreur FR : garder une erreur par langue (la spec disait « jusqu'au prochain enregistrement de cette langue »).
- St3-12 (faible/moyenne) `RefusedSessionCookiesAreClearedTest::assertThePublicRouteIsServedAfterwards` : tout code
  sauf 401 sous 500 passe, un 403 compris → assertion plus stricte (ou une route publique qui répond 200 en test).
- St3-13 (très faible) `endBaseAccessAtExpiry` : effacement redondant → disparaît avec la décision 1.

## Documentation et conception
- Sp3-5 : `frontend-session.md` l.138 (« On return (`authState.sessionEnded` true to false) » → `onSessionRestored`) ;
  l.162 de la règle et `SessionEndedBanner.vue` l.12 nomment `backofficeSessionEndedHandler`, remplacé par
  `createBackofficeSessionEndedHandler` (R17).
- Sp3-6 : commentaire d'`AppHeader.vue:235` : renvoyer à une règle (la règle a11y ou frontend qui parle du 320 px ;
  s'il n'y en a pas, y écrire le fait).
- St3-2 : `useAuth.spec.ts` l.866 (« le cookie D6 … s'éteint seul après 15 min ») et le titre « …sans aucun appel
  réseau » contredit par R21b → à réécrire selon la décision 1.
- St3-3 + CR3-10 : `AuthCookieFactory.php:21-25,70` — paramètre `$expiresAt` de `bearer()`/`xsrf()` gardé « pour un
  appelant futur » (conception par anticipation, contraire aux préférences de l'utilisateur, et il inviterait un cookie
  persistant contre l'invariant F1) → le supprimer. `sessionAwareFetch.ts:71-72` lit le cookie deux fois
  (`hasCsrfToken()` puis `readCsrfToken()`) alors que son commentaire dit que verdict et en-tête « ne peuvent pas
  diverger » → une seule lecture.
- St3-4 (faible/moyenne) : `presentation/router/backofficeSessionExpiry.ts` importe le type
  `BackofficeSessionEndedHandler` de `infrastructure/admin/shared/BackofficeHttpClient` : déplacer l'abstraction côté
  application (couches de `frontend.md`).
- St3-7 : condition de descente (`sessionEnded && user && hasWriteToken()`) écrite trois fois (`login`,
  `applyReadSession`, `recheckEndedSession`) ; `onSessionRestored`/`onAccountSwitched` presque identiques ; la séquence
  `sessionStamp` → `markSessionExpired(sentAt)` → `rereadAfterRefusal().then(…)` recopiée dans `useAssistant`,
  `useMcpConnections`, `useOAuthConsent` → factoriser (dans le découpage de `useAuth`).
- St3-8 : `BackofficeSessionEndedHandler`/`setBackofficeSessionEndedHandler` portent aussi `stampAtSend` et `refused`
  → nom qui dit ce que fait le type (ex. `BackofficeSessionReaction`).
- St3-9 : cycle d'import `loginLocation` ↔ `sessionEnded`/`reauthentication` → déplacer les constantes dans
  `loginLocation.ts`.
- St3-11 : `frontend-session.md` à 44 564 octets (plafond 50 000), paragraphe « Pinned by » monolithique → la découper
  par sous-domaine (procédure de `.claude/CLAUDE.md` : la règle d'origine garde un index, chaque partie ses `paths:`,
  nouvelles lignes dans la table de `.claude/CLAUDE.md`), à faire en dernier, après les tâches qui l'éditent.
- Sp3-9 (info) : la lecture double de `sessionAwareFetch` (voir CR3-10).

## Hors périmètre
F12 et S2 (`__Host-`, jeton CSRF lié ni à la session ni au temps) + A3-3 (un sous-domaine peut maintenant effacer une
session valide par un faux `BEARER` sur `Path=/api`) → ticket `__Host-`.
