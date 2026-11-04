# Dixième relecture de #748 — diff 778d114f...80b5832d (R102 à R111), 2026-10-31

Quatre relectures : `/code-review medium` (CR10-*), audit sécurité (C10-*), axe Standards (St10-*), axe Spec (Sp10-*).
**Aucun critique ni haut**, trois moyens (C10-1 antérieur à la série, St10-1, St10-2). Sévérité / confiance après chaque
constat ; doublons regroupés sous le premier identifiant. Avant les relectures, le fil principal a rejoué sur conteneurs
redémarrés les cinq cibles `make`, les 26 tests hors ligne et `check-claude-rules` : tous à 0 (front 2284, back 5588).
L'audit a rejoué hors ligne les cinq tests de fumée et d'audit, shellcheck (image épinglée) sur les 7 scripts modifiés,
R106 sous `bash -x` avec faux `curl`/`dig`/`openssl` (valeur absente de la trace et des arguments, 31 appels `-K`, fichier
600, dossier 700, sondes OAuth sans `-K`, `SMOKE_AUTH_*` hérités ignorés) et sous TERM/HUP/INT (`set -m`), les trois gardes
backend ciblées, `make front-test`, et comparé les `sha256` hôte/conteneur : identiques.

## Décisions de l'utilisateur (2026-10-31)
1. **C10-1 : un correctif `fix/*` vers `develop`, tout de suite**, indépendant de #748 (sondes de redirection en
   `CURL_OPTS_ANONYMOUS` + cas de test). La onzième série applique la même correction à la version de la branche
   (`audit-prod.sh` réécrit par R90/R106), pour que le report de `develop` ne fasse qu'aligner deux textes.
2. **C10-1 : pas de rotation** de la Basic Auth de préprod. Risque accepté : seul un acteur placé sur le chemin runner
   GitHub → répartiteur Scaleway a pu lire l'identifiant, lors des audits de release depuis le 2026-09-12.
3. **St10-2 / CR10-3 : époque de montage.** Chaque lecture est estampillée à l'envoi (`BackofficeSendStamp`) ; une lecture
   envoyée avant le montage de l'alerte (`settle…OnMount`) est du chargement de la page d'arrivée et garde l'avis, toute
   autre rend `common.accessRefused`. Le compteur `loadsInFlight`, `beginBackofficeLoad` et l'import `useAdminLoad` →
   module disparaissent (règle CR10-1, CR10-2) ; amende la lettre de la décision 1 de la neuvième (« retombe à la fin du
   chargement »), pas son intention. Avec le test d'intégration layout + vraie page de Sp10-1.
4. **CR10-6 / C10-2 : même règle pour les trois smokes** : secret défini mais vide → sortie 2 « secret vide », par une
   fonction commune de la bibliothèque, un cas de test par script.
5. **Sans suite** : C10-5 (course `-K`), Sp10-8 (`translateEntryButton` sur les réglages).
6. Toujours valables : C5-1, C5-2, C7, CR6-6, CR7-2, la limite de R94, les Sans suite de la neuvième, critère d'arrêt
   **tout jusqu'à zéro** : tous les autres constats se corrigent sans décision.

## Moyens
- **C10-1** `tools/audit-prod.sh` envoie la Basic Auth de préprod **en clair** : la boucle « Redirections » (l. 107-111)
  appelle `http://${DOMAIN}` et `http://www.${DOMAIN}` avec `CURL_OPTS`, qui porte `-K` ; curl envoie `Authorization:
  Basic` dès la première requête, quel que soit le schéma. Vérifié par le fil principal : `develop` a la même forme
  (l. 42, 78-79) depuis **15b56512 (2026-09-12)** — chaque run `audit-preprod` d'une branche `release/*` a donc envoyé
  l'identifiant courant de la préprod deux fois en HTTP, runner GitHub → répartiteur Scaleway (`www.preprod` ne résout
  pas aujourd'hui ; un joker DNS l'y enverrait aussi). Preuve de l'audit : un faux serveur 301 reçoit `Authorization:
  Basic dTpw` en HTTP avec `-K`. Correction : `CURL_OPTS_ANONYMOUS` pour les quatre sondes de redirection (la
  redirection HTTP→HTTPS d'ingress-nginx part avant l'authentification, à vérifier une fois sur la préprod) + cas
  `audit-prod.test.sh` « aucun appel `http://` ne porte `-K` ». Moyenne / haute (antérieur à la série ; R106 le grave
  sans condition).
- **St10-1** le docblock du module `backofficeAccessRefusal.ts` (l. 34-35) dit encore qu'il ne tient que « combien de
  refus depuis le dernier effacement » : R102 y a ajouté `loadsInFlight` et `beginBackofficeLoad` (consigne : la
  docstring se corrige dans le commit du comportement). Moyenne / haute.
- **St10-2 ≈ CR10-3** (+ CR10-1 = C10-8, CR10-2 ; à arbitrer) la fenêtre du chargement de la page d'arrivée est un
  compteur de module `let loadsInFlight`, remis à zéro à la main à trois endroits (la forme qu'avait éliminée R84 /
  St7-6) et alimenté par un import de `useAdminLoad` vers le module de l'alerte (Divergent Change). Il fuit : (a) CR10-1 =
  C10-8 — aucune lecture n'a de délai maximal (`BackofficeHttpClient`, `fetch`) : une lecture qui ne répond pas laisse
  `loadsInFlight > 0` jusqu'au changement de chemin, et toute lecture refusée ensuite garde « enregistrement précédent » ;
  (b) CR10-2 — `beginBackofficeLoad()` est appelé avant le `try` et `options.clear?.()` entre les deux : une exception
  synchrone de `clear` fuit le compteur. CR10-3 propose une **époque de montage** portée par l'estampille d'envoi
  (`BackofficeSendStamp`, qui porte déjà le périmètre) : une lecture envoyée avant `settle…OnMount` est du chargement de
  la page d'arrivée, jugée refus par refus dans `markBackofficeAccessRefused`, sans compteur ni fuite. Moyenne / moyenne.

## Faibles
- **Sp10-1 = C10-9** R102 repose sur l'ordre « le `setup` de la page précède l'`onMounted` de l'alerte » (et sur
  `AccessRefusedNotice` avant `<RouterView />` dans `AdminLayout`) ; seul `useAdminLoad.spec.ts` couvre le câblage, et le
  Step 3 du plan (« le cas Sp9-9 d'`AccessRefusedNotice.spec.ts` rougit ») est faux, ce spec appelant
  `beginBackofficeLoad()` lui-même. Une page qui lancerait sa lecture dans `onMounted` sortirait de la fenêtre sans que
  rien ne rougisse → un cas dans `AdminLayout.sessionEnded.spec.ts` avec une vraie page à deux listes refusées.
  Faible-moyenne / haute.
- **CR10-4 = C10-10** (+ St10-6) la garde de `handleTranslate` (R104) ne lit que `isProposalLocked`, avant l'`await` ;
  rien ne refuse une seconde traduction **pendant qu'une première est en vol** (`isTranslating`) : une double activation
  ou un `translate` émis par programme envoie un second appel facturé au modèle (borné par
  `TranslationRateLimiterInterface`), et une réponse arrivée après « Annuler » réimpose une proposition sur un formulaire
  vidé. Antérieur à la série (même schéma « seul le bouton désactivé protège » que C9-2). St10-6 : la garde et son
  commentaire sont copiés huit fois dans six pages, le couple `let lockTranslation` / `translationRequests` huit fois
  dans les specs → l'écrire une fois (dans `useAdminTranslation` ou un garde du verrou). Faible / moyenne.
- **CR10-6 = C10-2** les trois scripts de fumée (`smoke-dead-session-cookie.sh:78`, `smoke-login-throttling.sh:63`,
  `smoke-mcp.sh:91`) courent encore sans Basic Auth quand `PREPROD_BASIC_AUTH` est vide : les 401 de l'ingress y sont lus
  comme des écarts (sortie 1, diagnostics trompeurs : « pas de throttling vu », « mauvais hôte ou zone ») alors que
  l'audit dit « secret vide » (sortie 2). Pas de faux vert. La décision 3 ne visait que l'audit → même règle
  (`smoke_require_auth_config` commune ?). Faible / haute.
- **C10-11** la garde `'omit'` ne lit pas les **attributs statiques** (`<Fetcher mode="omit" />`, sondé : `[]`) ni les
  gabarits **sans arbre** (`<template lang="pug">`, `<template src>` rendent `[]` en silence) ; hors des limites écrites,
  antérieur → `prop.value.content` pour un attribut sans `exp`, échec sur un `.vue` dont le `<template>` n'a pas d'arbre.
  Faible / moyenne.
- **CR10-5 = Sp10-12 = C10-11 bis** tout `v-on` est rendu en corps de fonction, y compris la forme objet sans argument
  (`v-on="{ click: a, keydown: b }"`, compilée par Vue comme une expression) : bloc à étiquettes, erreur de syntaxe —
  la garde rougit sur du code valide (échoue fermée), contre « comme Vue l'entend » ; `function () {}` devient une
  déclaration anonyme → corps de fonction seulement si `directive.arg`. Aucun cas dans `src/` aujourd'hui. Faible / moyenne.
- **CR10-8 = C10-7 = St10-3** (+ C10-6, Sp10-5) l'ancrage « relève de `/api` » n'est pas écrit une fois : `isApiPath()`
  (R107) refait celui de `CanonicalPath::isUnderApi()` (#322, « écrit une fois ») et `RouterScopeTest.php:80` porte la même
  expression. **C10-6 (audit)** : `CsrfCookieRequestSubscriber::requiresCsrfCheck()` (l. 168) garde **exprès** le préfixe
  large `str_starts_with($path, '/api')` — le pare-feu `api` est `^/api` non ancré (`security.yaml:176`, A23), une route
  `/apix` y lirait `BEARER` : l'aligner sur l'ancrage sortirait une écriture authentifiée du contrôle CSRF ; idem
  `PublicRouteIgnoresRefusedSessionTest.php:187` (compare plus de routes, sans effet). → un seul ancrage partagé pour les
  tests (`tests/Support` ou une variante chaîne de `CanonicalPath`), et un commentaire + un cas `POST /apix` → 403 qui
  disent pourquoi le subscriber suit le pare-feu. Faible / haute.
- **CR10-9 = Sp10-11 = St10-10** docblocks contradictoires d'`AuthorizationLivesInAccessControlTest` : « par nature et par
  fichier, pas site par site (C7-13) » (`EXEMPTIONS`) contre « liste, un par un, les sites » (classe) ; le code apparie par
  nature (`[Refusal, Refusal]` est un décompte en unaire, un site déplacé dans le fichier reste couvert) — c'est le
  docblock de classe qui est faux ; le commentaire de boucle « celui-ci n'y figure pas » est trompeur. Faible / haute.
- **CR10-7** `appClassesIn()` : un `CollectionType` enveloppe un `GenericType` dont les variables portent déjà clé et
  valeur ; les lignes explicites `getCollectionKeyType()`/`getCollectionValueType()` refont le parcours, et retirer la
  ligne de la clé laisse vert le cas « la clé d'une collection » : le test présenté comme sa preuve ne prouve rien →
  retirer les lignes, ou un cas qui rougit sans elles. Faible / moyenne.
- **St10-4 = Sp10-4** lignes > 140 : `AuthenticationFlowTest.php:106-107` (156, 162), `AccessRefusedNotice.spec.ts:23` (214,
  `accessRefusedFr`), `audit-prod.test.sh:168` (149), `smoke-http.test.sh:427` (146). Faible / haute.
- **St10-5 + Sp10-5** récits au passé restés (contre St9-15) : `audit-prod.test.sh` (« Un audit qui courait sans Basic
  Auth lisait le 401… »), `ApiRouteExposureTest.php:345` (« n'a plus le 401 de Lexik »), `AdminAboutPage.spec.ts:736`
  (« Le sélecteur restait ») ; le balayage R111 n'a pas noté le préfixe large de C10-6. Faible / haute.
- **St10-8 = Sp10-3** anciens noms : `LOCALE_LOCKED_EDITING_TEXT` / `LOCKED_BY_PROPOSAL_TEXT` (`entryFormLock.ts`), « Les cas
  du verrou **de la langue** » (`AdminAboutPage.spec.ts`, `AdminQualityPage.spec.ts`), `sources` qui contient des
  littéraux (`noOmittedCredentials.spec.ts`). Faible / haute.
- **Sp10-2** le cas partagé de R104 émet `'fr'` et le commentaire d'`entryFormLock.ts` dit « la langue source » : pour la
  carte « moi », l'entrée éditée est en EN, `'fr'` est la cible (le test discrimine quand même). Faible / haute.
- **St10-7** `expressionsOfTemplate` et `templateExpressionsOf` (`sourceText.ts`) : quasi homonymes, contrats différents.
  Faible / haute.
- **St10-9** `ApiPlatformOperationsCarryNoSecurityTest` : les deux étapes rendent des formes de tableau
  (`array{classes, dtoClasses, operations, links}`) et écrivent par référence (`array &$found`) → petit objet `readonly`
  (« préférer les Value Objects »). Faible / moyenne.
- **St10-12** `readDirective` : ternaire imbriqué ; `read(expression: object | null | undefined)` plus lâche que le type
  `TemplateExpression` dérivé juste au-dessus. Faible / moyenne.
- **St10-13** la plomberie de `smoke-http.sh` (`-K` relu à chaque appel, fichier disparu → 2, retour à la ligne → 2) est
  décrite dans `deploiement-nginx.md` **et** `security-authentication-sessions.md` (détails de `tools/`, que couvre
  `release.md`) ; St9-18 corrigé en partie : lignes de 300-400 caractères dans un paragraphe coupé vers 115. Faible /
  moyenne.
- **CR10-10** une proposition gardée à l'arrivée des réglages de l'autre langue (R103) n'est retirée que par la relecture
  de sa cible : si celle-ci est refusée (403) ou interrompue (fin de session), `settings` reste nul, le `watch` ne passe
  pas et `isProposalShown` est vrai pour un formulaire non chargé (non rendu : risque faible) ; `onSessionRestored` la
  retire ensuite. Faible / faible.

## Info
- **C10-3 = Sp10-7** le cas `videtraced` d'`audit-prod.test.sh` ne voit pas la mutation qu'il décrit (`[ -z
  "$SMOKE_AUTH_CONFIG" ]` → `[ -z "$AUDIT_BASIC_AUTH" ]` : la trace porte `'[' -z '' ']'`, le grep ne trouve rien) ; le cas
  C8-1 à valeur pleine l'attrape → chercher `-z ''`, ou n'admettre le nom que dans `[[ -v` et l'appel. St10-15 : le bloc
  réécrit `run` en ligne.
- **C10-4** une valeur faite de blancs, ou sans `:`, passe pour pleine (`smoke-http.sh:119-131`) : la cascade de 401 que
  la décision 3 voulait éviter, ou un mot de passe demandé par curl → refuser une valeur sans `:` (retour 2, sous `set +x`).
- **C10-5** course entre `[ ! -f ]` et curl dans `smoke_fetch` (code 26 → « réseau, DNS ou délai », sortie 1). Négligeable.
- **C10-12** le témoin de R107 couvre « cookie présent, en-tête absent » ; le cas intersite réel (aucun cookie, `SameSite=Lax`)
  répond 403 mais l'absence de `Set-Cookie` n'y est épinglée par aucun test → une ligne de plus dans le témoin.
- **Sp10-6** `ApiRouteExposureTest.php:66` : « Les témoins de cette prémisse » suit une phrase qui ne nomme aucune prémisse.
- **St10-11** `useEntryFormLock` : `proposalSource: MaybeRefOrGetter<Locale | null>` n'est lu que pour sa nullité (couple le
  composable à `Locale`) ; nom différent de `proposalSourceLocale`.
- **St10-14** commentaires obscurs ou mal placés : `useAuth.ts` (« qui n'ont donc qu'un site d'import »),
  `AdminAboutSettingsForm.vue` (« le test lit l'état », un commentaire de prod qui parle du test), `smoke-http.sh` (une ligne
  de ≈ 118 dans un bloc coupé vers 85).
- **St10-15** repères de processus dans les noms de tests (« (Review Focus 1 et 2) », « (Sp9-9) », « (CR9-1) »).
- **Sp10-8** R105 a retiré le `translateButton` local des réglages « À propos » que le plan gardait : `translateEntryButton`
  vise un formulaire qui n'est pas « par entrée ». Sans effet.
- **Sp10-9** écarts au plan sans effet : dépôt de traduction par montage (R104), `transition()` inexistant (R102), `classes`
  en `array<string, true>` (R108) ; repli `source === undefined ? []` de `sessionStateEncapsulation.spec.ts:254` hors de C9-9.
- **Sp10-10** cases R102–R111 du plan non cochées (convention des séries : la section suivante dit qu'elles sont faites).

## Vérifié sain (audit)
`lock.isProposalLocked` est un booléen déballé (`readonly(reactive(…))`) ; `endLoad` idempotente et sans effet hors
périmètre (pas de compteur négatif) ; aucun `v-html`/`innerHTML`/`eval`/`credentials`/`fetch` ajouté ; `/api/logout` en
GET/HEAD/OPTIONS/QUERY → 405 sans cookie, POST sans en-tête → 403 sans cookie ; R108/R109 sémantique inchangée
(`array_search` strict sur des cas d'enum, sites consommés un par un, aucune exemption élargie, parcours sans cycle) ;
R110 détecte `v-for` (alias déstructuré compris), `v-slot`, argument dynamique de `v-model`, directive maison, `v-else-if`.
