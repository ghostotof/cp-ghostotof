# Septième relecture de #748 — diff 3b1aaeb3...c737f648 (R59 à R71), 2026-10-30

Quatre relectures : `/code-review medium` (CR7-*), audit sécurité (C7-*), axe Standards (St7-*), axe Spec (Sp7-*).
**Aucun critique ni haut** ; trois moyens. Sévérité / confiance après chaque constat ; doublons regroupés sous le premier
identifiant.

## Décisions de l'utilisateur (2026-10-30)
1. **C7-1 : contrôle à l'exécution.** Une double implication sur le pare-feu `api` : toute route hors `PUBLIC_PATHS` a une
   règle d'`access_control` (`requiresAccess()` vrai), toute route de `PUBLIC_PATHS` n'en a aucune, exception justifiée
   `/api/logout` (à vérifier). Le détecteur par jetons (`AuthorizationSites`) reste tel quel, **sans nouvel
   élargissement** ; ses limites (`#[CurrentUser]`, `createAccessDeniedException()`, `HttpException(403)`,
   `isGrantedForUser`, alias, callable…) sont écrites dans son docblock, avec le renvoi au contrôle à l'exécution.
   Inclut C7-10 (`requiresAccess() ⇒ 401 anonyme` sur le pare-feu `api`).
2. **C7-4 : retenir le compte.** Le refus d'écriture compté retient le compte qui l'a envoyé ; au montage, l'avis
   « enregistrement précédent » n'est dit que si c'est le compte courant, sinon tout est remis à zéro.
3. **Critère d'arrêt : tout jusqu'à zéro** (décision 2 de la quatrième série maintenue) : moyens, faibles, info, doc et
   conception, puis les quatre relectures, et on recommence tant qu'elles trouvent quelque chose.
4. Toujours valables : C5-1, C5-2, C7 (limite de la session) acceptés.

## Moyens
- **C7-1 (= CR7-6)** les deux gardes ne voient pas une autorisation par `#[CurrentUser]` non nullable (déjà dans `src/` :
  `OwnMcpConnectionsController`, `ConsentController`, routes à règle aujourd'hui), `->isGrantedForUser(`,
  `UserAuthorizationCheckerInterface`, `createAccessDeniedException()`, `new HttpException(403)`,
  `InsufficientAuthenticationException`, une réponse 403 écrite à la main, une exception métier mappée 401/403 → la
  prémisse de R35 rompue sans test rouge. → décision 1. Moyenne (garde) / haute.
- **C7-6 (= CR7-1)** `smoke-dead-session-cookie.sh:75`, `smoke-login-throttling.sh:60`, `smoke-mcp.sh:89` : le `trap`
  supprime le fichier de `SMOKE_AUTH_CONFIG` hérité de l'environnement (non initialisé depuis R71) — reproduit
  (`victim.txt` supprimé) → `SMOKE_AUTH_CONFIG=""` à la source de la lib ou avant le `trap`, cas hors ligne. Moyenne / haute.
- **CR7-4** `backofficeAccessRefusal.ts:102` : tout refus compté après le montage remet `earlierWrite` à faux ; la lecture
  de chargement de la page d'arrivée (403, même périmètre) remplace aussitôt l'avis « enregistrement précédent » par le
  texte générique → la personne ne l'apprend jamais (but de R64). Faible-moyenne / moyenne.

## Faibles
- **C7-4** (décision 2) l'avis « enregistrement précédent » peut s'afficher sous un autre compte (A refusé hors backoffice,
  déconnexion, B se connecte dans l'onglet) : `writeCountedSinceClear` n'est effacé que par l'alerte. Faible / haute.
- **CR7-2 = C7-5** identité du compte = son **nom** : un compte supprimé puis recréé sous le même nom est « le même » ;
  `null === null` compte un refus envoyé et reçu sans compte retenu, alors que le docblock du domaine dit « sous aucun » est
  écarté ; une déconnexion puis reconnexion du même nom, ou un rôle perdu (que R26 tient pour un changement), ne l'écartent
  pas → trancher au plan : un identifiant stable (UUID de `/api/me`) ou la limite écrite et la phrase « ou sous aucun »
  alignée. Faible / moyenne.
- **CR7-3** `backofficeSessionExpiry.ts:490` : `account` lu à l'envoi vaut `null` avant le premier `checkAuth` ; un refus
  réel arrivé ensuite est écarté (`null !== 'nom'`). Le garde du backoffice attend la vérification : quasi inatteignable.
  Faible / faible.
- **C7-2** `AuthorizationSites.php:73` : un refus importé sous alias (`use …AccessDeniedHttpException as Denied`) ou un
  callable `[$security, 'isGranted']` échappe au détecteur ; limite non écrite → l'écrire (décision 1 : pas d'élargissement).
- **C7-3** `ApiPlatformOperationsCarryNoSecurityTest.php:62` ne lit pas `Parameter::getSecurity()`, `Link::getSecurity()`
  (`uriVariables`), `ApiProperty::getSecurity()` → les parcourir (métadonnées compilées, même garde), un cas de chaque.
  Faible / moyenne.
- **St7-1** `tools/lib/smoke-http.sh` : `local name="${1:?…}"` fait sortir **tout le script** (127) sans argument,
  contraire à l'en-tête de la lib (« aucune fonction ne fait sortir l'appelant ») → `[ -n "${1:-}" ] || return 2`. Faible / haute.
- **St7-2** CR6-8 partiel : `frontend-session-retour.md` l. 47-48 (« every later `checkAuth()` … the re-read after a real
  refusal »), `useAuth.ts:142` (« une relecture (`checkAuth()`, `rereadAfterRefusal()`) »), `useAuth.ts:230`
  (« Silencieuse après la première vérification ») ; `hasCheckedOnce` avec un seul appelant (Speculative Generality).
  Faible / moyenne.
- **CR7-7 (= C7-11)** `sourceText.ts:95` : aucune notion de regex littérale (`/^\/*$/`, `/["']/`) ni de `/*` dans le texte
  d'un `<template>` `.vue` → la garde manque du code réel ; ne lire que les `<script>` d'un `.vue`, gérer ou écrire les
  regex. Faible-moyenne / moyenne.
- **CR7-8** `AdminAboutSettingsForm.vue:382` : un enregistrement réussi de la langue cible pendant qu'une autre est montrée
  ne retire pas l'avis (`write(en)` ne touche pas `settings`) ; retiré seulement au retour, et laissé si ce retour finit sur
  une session finie. Faible / faible.
- **Sp7-1** autres pages d'admin (`AdminCaseStudiesPage`, `AdminIncidentsPage`, `AdminContributionsPage`,
  `AdminAnonymousCvPage`, `AdminAboutMeCardsSection`, `AdminAboutSiteCardsSection`) : après une traduction, le formulaire
  repasse en création et son sélecteur de langue redevient actif ; remettre la langue source laisse l'avis
  (`v-if="proposalSourceLocale"`) sur un formulaire de la langue source. Faible / moyenne.
- **Sp7-7** lignes > 140 caractères dans `AuthorizationLivesInAccessControlTest` (jusqu'à 200, `EXEMPTIONS` et
  fournisseurs) et `ApiPlatformOperationsCarryNoSecurityTest` (157). Faible / haute.
- **St7-3 = CR7-9** `smoke-dead-session-cookie.sh` `set_cookies()` / `set_cookies_named` refont la lecture d'en-tête de
  `smoke_header` (multi-valeurs) → `smoke_headers <fichier> <nom>` dans la lib. Faible / haute.
- **St7-4** bloc `source … || { echo "introuvable ou invalide"; exit 2; }` recopié 7 fois, son commentaire 4. Faible /
  moyenne (l'amorçage limite le remède).
- **St7-5** la phrase « tenue par `AuthorizationLivesInAccessControlTest` et `ApiPlatformOperationsCarryNoSecurityTest`,
  seuls témoins » écrite ≥ 7 fois (Shotgun Surgery de prose) → une seule source, des renvois. Faible / moyenne.
- **St7-6** Data Clumps : `refusals`, `earlierWrite`, `writeCountedSinceClear` remis ensemble à la main dans trois
  fonctions → un seul état. Faible / moyenne.
- **St7-9** méthodes longues : `AuthorizationSites::in()` (~50 lignes, 7 branches), `scan()` de `sourceText.ts` (~125
  lignes, trois fermetures sur `i`/`text` mutables). Faible / moyenne.
- **St7-10** `ApiRouteExposureTest` écrit sa liste de méthodes sûres au lieu du complément de
  `CsrfCookieRequestSubscriber::UNSAFE_METHODS`. Faible / moyenne.
- **Sp7-5 = St7-12** `security-authentication-sessions.md` : compte daté « 39 classes, 112 operations on 2026-10-30 » et
  récit de mesure, hors de la prescription de R61 ; vieillira. Faible / haute.

## Info
- **CR7-5 = C7-12** `checkAuth` laisse remonter une erreur après la réponse (rejet non géré, état appliqué à moitié) :
  voulu (R65) ; rien d'exploitable.
- **CR7-10** `isSaving || isSavingLocale(otherLocale(locale))` → `isSavingLocale(locale) || isSavingLocale(otherLocale(locale))`.
- **C7-7** `${!name}` évalue un indice de tableau (injection si le nom est forgé) → valider `^[A-Za-z_][A-Za-z0-9_]*$`.
- **C7-8** fenêtre résiduelle : le fichier `-K` est créé dans un sous-shell avant l'affectation que lit le `trap` →
  répertoire privé `mktemp -d` puis écriture dans le shell parent ; nuancer le commentaire d'`audit-prod.sh`.
- **C7-9** le secret circule en argument de fonction et paraît sous `xtrace` → `smoke_auth_config_file` lit `${!name}`
  elle-même ; `set +x` autour de l'écriture.
- **C7-10** → décision 1.
- **C7-13** exemptions comptées par nature, pas par site.
- **St7-7** noms : `earlierWriteRefused`/`earlierWrite` contre la clé `common.earlierSaveRefused` ;
  `$isRefusedByTheApiFirewall` ; `tree()` dans `audit-prod.test.sh` masque la commande `tree`.
- **St7-8** natures de site en chaînes libres (`'refusal'`, `'isGranted'`).
- **St7-10 (suite)** `isSaving` répète `true === saving.value[locale]` ; `proposal?.target === locale` ×2 ; timbre
  `{ session, account: null, refusal }` recopié ×3 dans `HttpAdminUserRepository.spec.ts`.
- **St7-11** `useAuth.ts` réexporte encore `isCurrentGeneration` (seul un spec l'importe).
- **St7-13** `audit-prod.test.sh` : `AUDIT_BASIC_AUTH='secret-user:secret-pass'` (contient « secret », précédent : `'u:p'`).
- **St7-14** `{@see}` par nom court d'une classe du même namespace non importée (lecture stricte incertaine).
- **St7-15** `backoffice-api.md` : « **Every `path` is » seul sur sa ligne après l'insertion.
- **Sp7-2** archive : R62 Step 1 et C6-12 parlent d'un `\r` dans le `Retry-After` que `header()` retirait déjà.
- **Sp7-3** archive : R62 Step 2 « pas de test hors ligne » faux (`audit-prod.test.sh`) ; table des fichiers et listes de
  R62/R71 incomplètes (`audit-prod.test.sh`, `pipeline.yml`, `deploiement-nginx.md`, `deploiement.md`,
  `check-tls-expiry.sh`, `tls-certificate.test.sh`).
- **Sp7-4** archive : R61 « filet + 1 » (7) ; R69 mutation sur `lowerSessionEnded`, pas `raiseSessionEnded`.
- **Sp7-6** = CR7-2 (`null === null`).
- **Sp7-8** R68 : une chaîne non fermée s'arrête à la fin de la ligne (limite écrite, acceptée).
