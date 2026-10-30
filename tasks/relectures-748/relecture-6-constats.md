# Sixième relecture de #748 — diff 63dc48f7...69d45ae3 (R46 à R58), 2026-10-30

Quatre relectures : `/code-review medium` (CR6-*), audit sécurité (C6-*), axe Standards (St6-*), axe Spec (Sp6-*).
**Aucun critique ni haut** ; l'audit sécurité : aucun moyen non plus (3 faibles, 9 info), C5-1, C5-2 et C7 non aggravées.
Sévérité / confiance après chaque constat ; doublons regroupés sous le premier identifiant.

## Décisions de l'utilisateur (2026-10-30)
1. **CR6-3 : signaler au retour.** Au montage d'`AccessRefusedNotice`, un refus de **lecture** compté hors du backoffice
   est oublié, un refus d'**écriture** s'affiche avec un texte propre, FR/EN (« Un enregistrement précédent a été refusé
   par le serveur… »). Amende la réponse Q1 de la cinquième série (effacé au montage) pour les écritures : R38 (une
   écriture refusée est toujours dite) l'emporte.
2. **CR6-5 : juger sur le compte.** Le timbre d'envoi retient le compte (identifiant) ; un refus n'est écarté que si le
   compte a changé — un 403 du même compte reste dit, même après une fin puis un retour de session ou une bascule de
   palier. Remplace le contrôle de génération ajouté en R48 dans `refused()` (à trancher au plan : génération gardée en
   plus pour les lectures, ou compte seul).
3. **CR6-2 : bloquer la langue cible.** « Proposer la traduction » désactivé tant qu'un enregistrement de la langue
   **cible** est en vol (un enregistrement EN n'est toujours pas tenu par un FR, R50).
4. **Sp6-5 / CR6-1 : corriger dans la série.** L'avis de proposition ne s'affiche que sur sa langue cible et disparaît
   quand le formulaire de cette langue est relu du serveur (la proposition perdue).
5. Toujours valables : **tout jusqu'à zéro** (décision 2 de la quatrième série) ; C5-1, C5-2, C7 limites acceptées.

## Moyens
- **CR6-3** `AccessRefusedNotice.vue:75` — `onMounted(discardBackofficeAccessRefusals)` jette les refus d'écriture comptés
  hors du backoffice : un enregistrement refusé pendant que la personne était sur une page publique n'est jamais dit.
  → décision 1.
- **CR6-5** `backofficeSessionExpiry.ts:51` — `isCurrentGeneration` écarte aussi un refus du même compte (la génération
  avance aussi à `markSessionExpired`, `restoreSession` même compte, `markBaseAccess*`). → décision 2.
- **CR6-2** `AdminAboutSettingsForm.vue:206` — « Proposer la traduction » actif pendant l'enregistrement d'une autre
  langue ; sa réponse (`write` sans condition depuis R50) écrase la proposition appliquée et efface l'avis. → décision 3.
- **CR6-1 = Sp6-5** `AdminAboutSettingsForm.vue:213` — l'avis (`v-if="proposalSourceLocale"`) n'est pas lié à la langue
  affichée ; il reste sous le formulaire de l'autre langue et survit à la relecture qui perd la proposition. → décision 4.
- **St6-1 = Sp6-2** CR5-8 : le titre et le commentaire de `AccessRefusedNotice.spec.ts:143` (« le routeur n'y voit pas de
  navigation », « doublon ») contredisent le composant et la règle. **Fait établi** : le test était rouge avant le tri de
  la clé (R54), donc vue-router exécute la navigation permutée ; c'est le test et `backofficeAccessRefusal.ts` qu'il faut
  corriger, pas le tri. Moyenne / haute.
- **St6-2 = Sp6-1** docs qui disent que le montage de l'alerte avance le périmètre (faux : `discard…` ne l'avance pas) :
  `backofficeAccessRefusal.ts` (docblock de `scope`), `backofficeSessionExpiry.ts`, `frontend-session-refus.md` (se
  contredit, trois descriptions des déclencheurs, « plus, for R48 » ajouté au lieu de remplacé). Moyenne / haute.
- **St6-3** `AuthorizationLivesInAccessControlTest` : détecteur prouvé sur aucun extrait (`#[IsGranted]` court/complet,
  `denyAccessUnlessGranted`, `security:`), contraire à la pratique de `backend-conventions.md`. Moyenne / haute.

## Faibles
- **CR6-4 = St6-11** `useAuth.ts:233` — `catch {}` de `checkAuth` avale tout (erreur de programmation d'`applyLiveSession`,
  d'un abonné, de `scheduleBaseAccessExpiry`, un 500) : ne capturer que le rejet de `await repository.me()`. Faible / haute.
- **CR6-8** `useAuth.ts:209` — docblock de `checkAuth` : « à chaque relecture hors session finie » faux (seul appelant :
  `main.ts`). Faible / haute.
- **CR6-6** `ApiRouteExposureTest.php:267` — l'assertion d'effacement suppose que tout 401 anonyme du pare-feu `api` vient
  du pare-feu : une route publique qui répond 401 par logique métier la ferait rougir à tort → ne la vérifier que si
  `requiresAccess()` est vrai. Faible / moyenne.
- **C6-1** invariant n° 8 : sur les écritures protégées, le CSRF répond 403 avant le pare-feu dans les trois variantes →
  égalité triviale, R46 jamais appliqué aux écritures ; poser une paire `XSRF-TOKEN` valide (signée par
  `CsrfCookieTokenSigner`) pour les méthodes non sûres. Faible / haute.
- **CR6-7 = C6-3** `AuthorizationLivesInAccessControlTest` ne voit pas : `Foo::isGranted(`, `security:` en YAML/XML
  (`config/api_platform/`), `AccessDeniedException` / `AccessDeniedHttpException` / `UnauthorizedHttpException` levées,
  `->decide(`, un `is_granted()` hors attribut → les ajouter avec exemptions justifiées (les gardes CSRF lèvent
  `AccessDeniedHttpException` avant le pare-feu), ou écrire les limites. Faible / moyenne.
- **Sp6-4 = C6-2** docblock d'`AuthorizationLivesInAccessControlTest` : « l'invariant n° 8 ne compare que les statuts »,
  faux depuis R46 (il voit un `#[IsGranted]` sur une lecture, pas sur une écriture ni derrière un 404). Faible / moyenne.
- **Sp6-11 = C6-6** `offendsWithOmit` : `'/*'` ou `'//'` dans une chaîne masque la suite → limite écrite, ou lecture par
  littéraux. Faible / moyenne.
- **St6-4** trait `RefusedBearerCookie` : `self::getContainer()` sans `@phpstan-require-extends KernelTestCase`. Faible / moyenne.
- **St6-5** l'invariant « toute autorisation de `/api` est une règle d'`access_control` » n'est écrit que dans
  `security-authentication-sessions.md` ; un `security:` posé dans `Presentation/` charge `backoffice-api.md`
  (« Authorization ») → l'y écrire. Faible / moyenne.
- **St6-7** `answerWithBearer` réécrit `Cookie::isCleared()`. Faible / haute.
- **St6-9** Duplicated Code : retrait des commentaires par regex ×3 (`namesImportedFromSessionState`,
  `namesInExportBlocks`, `offendsWithOmit`) ; faux `fetch` qui obéit à `abort` ×2 (`HttpAuthRepository.spec.ts`,
  `adminGuard.spec.ts`) ; ces fonctions `export`ées de `.spec.ts` sans importeur. Faible / haute.
- **St6-10** Data Clumps : `proposalSourceLocale` + `proposalTargetLocale` → un `ref<{source, target} | null>`. Faible / moyenne.
- **St6-12** outillage : bloc Basic Auth recopié ×3, `fetch()`/`request()` Middle Man, `smoke_auth_config_file` nomme
  `AUDIT_BASIC_AUTH`, deux noms du binaire curl (`SMOKE_CURL`, `SMOKE_CURL_BIN`) et leur correspondance ×3. Faible / haute.

## Info
- **C6-4** exemption `NominativeCorpusReader` valable pour tout le fichier et un nombre illimité d'appels → épingler 1 site.
- **C6-5** garde R52 contournable : `./sessionState.js`, `import()` en gabarit, `import.meta.glob`, réexport par un
  importeur autorisé (`export const x = writer`) ; correction : suffixe `(?:\.[cm]?[jt]s)?` et contrôle **par identité**
  (tous les modules de `src/` en eager, aucun hors `sessionState.ts` n'exporte une valeur `===` à un writer).
- **C6-7** `audit-prod.sh` (et les smokes) : `source … 2> /dev/null` masque une erreur de syntaxe de la lib (« introuvable »)
  → retirer `2>/dev/null`, dire « introuvable ou invalide » ; `audit-prod.sh` ne source qu'avec `AUDIT_BASIC_AUTH`.
- **C6-8** `audit-prod.sh` : `trap` posé après la création du fichier `-K` → le poser avant, comme les smokes.
- **C6-9** le refus de `smoke_auth_config_file` nomme `AUDIT_BASIC_AUTH` en dur (= St6-12) ; dit qu'un CR/LF existe (négligeable).
- **C6-10** invariant n° 8 : échéance des cookies effacés vérifiée, pas leurs attributs → comparer à `AuthCookieFactory::expired()`.
- **C6-11** balayage sans variante « session close » (`cv` périmé) ; facultatif, coût en base.
- **C6-12** (hors diff) `smoke-login-throttling.sh:113` imprime un `Retry-After` brut → `smoke_excerpt`.
- **St6-6** `JwtClockSkewTest` n'utilise que `putTheBearer` du trait (Refused Bequest) → petit trait à part.
- **St6-8** `SessionBeforePasswordChangeTest` garde un `putTheBearer` homonyme ; le docblock du trait dit « quatre tests ».
- **St6-13** une ligne ~250 caractères dans `ApiRouteExposureTest`, docblocks au-delà de la largeur habituelle.
- **St6-14** « sans effet (St5-12) » dans le docblock de `requiresAccess()` incompréhensible.
- **St6-15** `let endedSessionRecheck` / `let refusalReread` réaffectables pour les tests (suite de St5-12).
- **St6-16** nombre d'assertions PHPUnit variable : pas un contrat ; cause probable une boucle d'attente temporelle.
- **St6-17** `frontend-backoffice.md` raconte l'historique (« `lastSaveOf` … is gone »).
- **Sp6-3** archive du plan à corriger : CR5-8 dit « faux » (l. ~2510, ~3039, ~3214) ; commit 79d0b073 « comportement identique ».
- **Sp6-6** archive : « sortie 7 » pour `smoke-mcp.sh` (l. ~3147) et « même motif dans smoke-mcp.sh » (vérification CR5-10).
- **Sp6-7** « `-K` passe en 600 » (R57) sans objet : `mktemp` crée déjà en 0600.
- **Sp6-8** ajouts hors spec cohérents (test de la lib et job, `deploiement-nginx.md`, helper du trait, tests R52).
- **Sp6-9** R46 : l'effacement n'a été vu rouge que pour `JWT_INVALID`, pas `JWT_EXPIRED`.
- **Sp6-10** archive : `private const` devenu `protected` (justifié dans le trait).
