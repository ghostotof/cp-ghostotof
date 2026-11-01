# Quinzième relecture de #748 — diff 7eb683ce...7f72ee92 (R168 à R181, R173b), 2026-11-01

Quatre relectures : `/code-review medium` (CR15-*), audit sécurité (C15-*), axe Standards (St15-*), axe Spec (Sp15-*).
**Aucun critique ni haut.** Moyens : **CR15-2 = C15-3 = St15-24** (un `unset` refusé n'est pas vu, classé moyen par
`/code-review`), **CR15-1** (`local +x`, moyen pour `/code-review`, info pour l'audit), **St15-1 = Sp15-5** et **St15-2 =
Sp15-4** (docblocks périmés), **St15-5** (liste des hooks écrite trois fois), **St15-29** (un recenseur PHP écrit à la main
diverge de `ClassNameResolver::scopes()`). L'audit a rejoué sur curl 8.5.0 (`--network none`, identifiant factice) les
fermetures de la quatorzième relecture : **C14-1 à C14-5 fermés** (`.curlrc` : `-q` en tête 0/0 ; liste d'autorisation :
tous les refus attendus, rang seul ; valeurs des options admises consommées ; variables de curl retirées). Avant les
relectures, le fil principal a rejoué sur conteneurs redémarrés les cinq cibles `make`, les 26 tests hors ligne et
`check-claude-rules` : tous à 0 (front 2588, back 5681). **Le bilan de R181 se trompe sur `CDPATH`** (C15-5 = Sp15-14,
mesuré : le cas existe et rougit dans les quatre tests).

## Décisions de l'utilisateur
Prises le 2026-11-01 :
- Q1 → **régression + antérieurs** : `ClassNameResolver::scopes()` compte les accolades par identifiant de jeton (+ cas), et
  `UnsortedImports`, `AnonymousClassesAndFreeFunctions`, `ProblemDetailStaysStaticTest` passent par la même lecture.
- Q2 → **refuser sauf exception** : avec la Basic Auth, une valeur `@…` refusée pour `-H` et `-d` ; pour `--data-binary`,
  admise seulement vers un fichier du dossier temporaire du smoke, jamais le fichier `-K` ; `-b` exige un `=` ; un cas par forme.
- Q3 → FR de `admin.entryLock.proposal` : « « Annuler » la retire et vide le formulaire. »
- Q4 → **factoriser dans #748** (contre la recommandation) : `AdminQualityPrinciplesSection.vue` et
  `AdminQualityTraitsSection.vue` en une section générique paramétrée par ses champs, tâche dédiée, gardée par les 90 tests de
  Qualité.
- Q5 → **écrire un cas** (contre la recommandation) pour les réglages « À propos » de R172 (un `load()` résolu dans le même
  point de contrôle que la réponse) ; s'il reste vert d'emblée, le dire et le prouver par mutation.
- Tout le reste se corrige (critère « tout jusqu'à zéro »).

Questions posées :
1. **C15-2 (régression de R180) + St15-29** : `ClassNameResolver::scopes()` compte les accolades par leur texte ; un fragment
   de chaîne interpolée `}`/`{` décale la profondeur (mesuré : un site de `Cookie` non vu). Le même motif existe, antérieur à
   la série, dans `UnsortedImports.php:74-76`, `AnonymousClassesAndFreeFunctions.php:104-115` et
   `ProblemDetailStaysStaticTest.php:1029-1031,1576-1578,1610-1612` (qui ne passe pas par `scopes()`). Reco : compter par
   identifiant de jeton dans `scopes()` (+ cas), **et** faire passer les recenseurs antérieurs par la même lecture.
   Option : la régression seule, les antérieurs en ticket à part.
2. **CR15-4** : `-H`, `-d`, `--data-binary`, `-b` sont admis sans juger leur valeur ; `@fichier` (et un `-b` sans `=`) fait lire
   et envoyer un fichier local, le fichier `-K` compris. Les smokes passent légitimement `--data-binary @<fichier du dossier
   temporaire>`. Reco : refuser une valeur `@…` sauf pour `--data-binary` vers un fichier du dossier temporaire du smoke, et
   jamais le fichier `-K` ; `-b` exige un `=`. Option : écrire la limite.
3. **St15-15** : le FR de `admin.entryLock.proposal` dit « « Annuler » l'abandonne » alors que « Abandonner » est le
   libellé du bouton des réglages (l'EN a été corrigé en R176 pour la même raison). Reco : FR « « Annuler » la retire et vide
   le formulaire. ». Option : garder.
4. **St15-13** : `AdminQualityPrinciplesSection.vue` et `AdminQualityTraitsSection.vue` ne diffèrent plus que par leurs
   champs (≈ 400 lignes identiques). Reco : **sans suite** dans #748 (chaque page écrit son bloc, règle Q3 de la 14e série ;
   factoriser deux sections en une générique est une décision d'architecture à part). Option : un composant générique.
5. **Sp15-1 = C15-7** : le cas des réglages « À propos » prévu par R172 n'a pas été écrit, l'agent l'a écarté seul. Reco :
   écrire la limite d'une phrase dans `frontend-backoffice.md` (l'identité des réglages est `props.locale`, mise à jour au
   rendu du parent seulement ; aucun geste synchrone ne tombe entre le jugement et la pose). Option : un cas qui monte les
   réglages et résout un `load()` dans le même point de contrôle.

## Moyens
- **CR15-2 = C15-3 = St15-24** `tools/lib/smoke-http.sh:172` : le code de retour de `unset -v -- "$_smoke_name"` n'est pas lu.
  Mesuré (bash 5.2) : `readonly AUDIT_BASIC_AUTH` → `rc=0`, fichier `-K` écrit, un fils voit encore la variable ; une locale
  qui masque une globale exportée → seule la locale est retirée. La règle promet « no child process … sees it, whatever the
  value ». Fix : vérifier `[[ ! -v $_smoke_name ]]` après le retrait, sinon refus (sortie 2) + cas `readonly`. Moyenne
  (CR) / faible (audit, Standards) ; haute.
- **CR15-1 = C15-6** `tools/tests/lib/smoke-cases.sh:81-82`, `outillage-smoke.md:117-120` : `local +x` ne masque pas une
  valeur que l'appelant a exportée (le fils voit la valeur globale) ; « jamais exportées » est trop absolu (R171 l'a mesuré,
  son test ne vise que les valeurs posées par `run_case`). Reformuler : « les valeurs que pose `run_case` n'atteignent
  jamais un fils ; un nom que l'appelant a exporté y garde la valeur de l'appelant ». Moyenne (CR) / info (audit) ; haute.
- **St15-1 = Sp15-5** `useAdminTranslation.ts:48,68` : les docblocks de `expire` et `formIdentity` disent « sa réponse rend
  `null` » alors que `translate` rend `Promise<boolean>` et n'appelle pas `accept` (R172) ; les noms de tests
  `useAdminTranslation.spec.ts:112,210,308` disent aussi « rend null » ; le balayage de R181 les a déclarés conformes.
  Moyenne / haute.
- **St15-2 = Sp15-4** `useTranslatedForm.ts:52` : le docblock de `renewForm` cite `resetPrincipleForm()`/`resetTraitForm()`
  (devenus `resetForm`, R175) et liste ses appelants. Moyenne / haute.
- **St15-5** Shotgun Surgery : la liste des hooks différés est écrite trois fois (docblock de `deferredCallsOf`,
  `DEFERRED_HOOKS`, `noLoadInOnMounted.spec.ts:12-21`). Moyenne / haute.
- **St15-29** `ProblemDetailStaysStaticTest.php:1028-1040` : la boucle namespace/`use` est écrite à la main au lieu de
  `ClassNameResolver::scopes()` ; elle n'a pas la remise à zéro de fin de `namespace X { … }` de R180 (Duplicated Code,
  Divergent Change ; voir Q1). Faible-moyenne / haute.

## Faibles
- **C15-2** régression de R180 (voir Q1) : `ClassNameResolver.php:184-193` + `PhpSources::opensBrace()` comparent le texte du
  jeton ; `"{\"id\":$id}"`, `"$x}"`, `"\{$a}"` produisent un `T_ENCAPSED_AND_WHITESPACE` `}`/`{` ; mesuré :
  `CookieConstructions::inCode` rend `app\c` au lieu de `…\cookie`, et `[]` au lieu de `[5]` ; fix : ouvrir sur l'id 123,
  `T_CURLY_OPEN`, `T_DOLLAR_OPEN_CURLY_BRACES`, fermer sur l'id 125 ; cas dans `ClassNameResolverTest::scoped`. Faible /
  haute.
- **C15-1** garde des vidages : `declare --`, `declare +x`, `export --`, `declare -x --`, `typeset +r`, `bash -c 'set'`,
  `bash -c "set"` passent (chacun imprime la variable en bash 5.2, mesuré) ; la règle dit « options only » → `list_end` qui
  admet `--` et les options en `+`, `'`/`"` avant `set`, ces sept lignes à `dump_forbidden` et dans la règle. Faible / haute.
- **CR15-3** `smoke-http.sh:154` : un nom invalide rend 2 avant toute lecture ou tout retrait ; la variable reste exportée
  (seul chemin où elle survit) ; un `trap` qui lance des fils la voit. Faible / moyenne.
- **CR15-4** (voir Q2) valeurs `@fichier` de `-H`/`-d`/`--data-binary` et `-b` sans `=`. Faible / moyenne.
- **CR15-5** `useAdminTranslation.ts:194` : une exception de `accept` remonte hors de `translate` ; aucun appelant
  (`proposeFrom`, `requestProposal`, `handleTranslate`) ne l'attrape → rejet non géré, formulaire à moitié basculé.
  Faible / moyenne.
- **CR15-6 = St15-19** `AdminAboutMeCardsSection.vue:157` (et Principles 146→168, Traits 112→132, SiteCards 133→154) :
  `isRefused` lit une `const` déclarée plus bas (zone morte latente, le `computed` est paresseux) ; dépendance d'ordre non
  écrite. **CR15-8** `form` en getter recopié neuf fois. Faible / moyenne.
- **CR15-7** `smoke-cases.sh:55` : `write_env_noting` écrit `exec ""` si `command -v` ne trouve pas l'outil → diagnostic
  trompeur. **St15-25** `audit-prod.test.sh:154-165` : le cas cite `openssl` mais les faux `dig`/`openssl`/`curl` en tête du
  PATH ne notent pas leur environnement (curl, celui qui compte, n'est pas vu). **St15-26** `smoke-http.test.sh:1363-1383` :
  `CASE_LABEL` sans repère (9 noms sur 10 couverts). **St15-27** `smoke-http.test.sh:1088` parle encore d'`export -n`.
  Faible / haute-moyenne.
- **C15-4** les variables de curl ne sont retirées qu'une fois (un `export https_proxy=…` ultérieur les rétablit) ;
  `OPENSSL_CONF`, `LD_PRELOAD`, `PATH`, `SMOKE_CURL` non couverts ni écrits comme limite → `--noproxy '*'` dans `_smoke_auth`
  et les `CURL_OPTS` authentifiés + la limite écrite (un environnement contrôlé par l'attaquant est hors du modèle).
  **Sp15-21** liste ENVIRONMENT du plan incomplète (`HOME`, `APPDATA`, `USERPROFILE`, `COLUMNS`), variantes minuscules hors
  proxy non retirées (non vérifié dans les sources de curl). Info-faible / moyenne-faible.
- **St15-23** `outillage-smoke.md:30-32` : « aucun processus ne part avant `smoke_use_auth_config` » alors que
  `source "$(CDPATH='' cd -- … && pwd)/…"` ouvre un sous-shell (pas d'`exec`, aucune exposition nouvelle) ; la règle affirme
  plus que le code. **St15-28** `CookieConstructions.php:41-43` : « refusés dans `src/` par UnsortedImports » appliqué aussi à
  l'échappement `\u{…}`. **St15-30** `_smoke_write_auth_config` 47 lignes, trois rôles. **St15-31** formulations
  historiques (`outillage-smoke.md:65`, `smoke-http.sh:149-150`). **St15-32** docblocks qui listent leurs appelants
  (`smoke-http.sh:7-13,98`). Faible / haute-moyenne.
- **St15-3** lignes vides avant l'accolade fermante des quatre `describeExpiryBy*` (`entryFormLock.ts:618,651,695,755`).
  **St15-4** « ne différent » (`sourceText.ts:403`). **St15-6** `deferredCallsOf` 55 lignes, quatre fermetures. **St15-7**
  `whenOther` de `readCallback` ne reçoit qu'une valeur. **St15-8** `translate` ≈ 50 lignes. **St15-9** `useTranslationErrorText`
  prend un `Ref<string | null>` (l'union `AdminTranslationErrorReason` perdue). **St15-10** `proseFieldAttrsOf` dit « texte de
  l'attente » pour `proseReadonlyHintId`. **St15-11** commentaire « limite : une micro-tâche… » recopié trois fois, renvois
  en chaîne (Traits → Principles → page ; « À propos » → `AdminQualityPage`). **St15-14** docblock et corps de
  `handleTranslate` recopiés dans sept fichiers, le docblock oublie `proposalSourceLocale`. **St15-16 = Sp15-13**
  `emitTranslateRightAfterMove` écrit deux fois (et refait `moveFirstRowDown`), `proposalOf` deux fois, un `Host` remonté.
  **St15-17** un `it` à trois scénarios, deux cas `isRefused` presque identiques. **St15-18** JSDoc de `LockToolkit`
  (`entryFormLock.ts:166-191`) qui couvre deux membres (TS n'en documente qu'un). **St15-34** bloc d'amorçage identique dans
  les trois smokes et l'audit (`_audit_dir` / `script_dir`), cas `CDPATH` copié dans quatre tests au lieu d'un cas partagé.
  **St15-35** la liste des `CASE_*` écrite cinq fois dans `smoke-cases.sh` et deux dans `smoke-http.test.sh`. **St15-42**
  `ClassNameResolver::scopes` ≈ 30 lignes, deux compteurs. Faible.
- **Sp15-2 = Sp15-16** le bilan de R181 range les écritures de R170, R173b et R175 au plan « hors de l'exception du brief »
  sans dire que le fil principal les a données : l'archive présente une autorisation comme une infraction ; R173b sans étapes,
  absente de la table des fichiers, de la traçabilité et de l'ordre « R168 → R181 ». **Sp15-3** la section R173b et l'ajout de
  R175 sont écrits par le commit qui les applique (attestations de l'agent sur lui-même). **Sp15-7** le cas R173b des cartes
  « moi » n'exerce que la catégorie `technical` (un `isAnyOrderDirty` qui n'en lirait qu'une resterait vert). **Sp15-14 =
  C15-5** le bilan de R181 déclare absent un cas `CDPATH` qui existe dans les quatre tests (mutation : 4 FAIL) ; seul le script
  à la racine n'a pas de test. **Sp15-15** R180 : le cas `namespace X {…} namespace Y { new B(` ne pouvait pas être rouge ; le
  bilan ne dit pas que le cas et la mutation du plan étaient sans effet. **Sp15-17** R180 a modifié
  `security-authentication-sessions.md:84` sans le déclarer. **Sp15-18** `PathIsDecidedCanonicallyTest.php:28` « que lisent
  tous les recenseurs par jetons » faux (quatre seulement). Faible / haute.

## Info
- **St15-12** `isRefused` sur deux lignes dans Principles, une dans Traits. **St15-15** (voir Q3). **St15-20**
  `vueCompilerAlignment.spec.ts:37-48` style, second cas sans `(#748, R179)`. **St15-21** fragments littéraux voulus dans
  `adminTranslationTexts.spec.ts`. **St15-22** `'Modifier'` en dur dans les nouveaux cas d'About et de Quality.
- **St15-33** `CompiledNestedReading.php:12` « testaient » (docblock rétabli tel quel sur ordre du plan). **St15-36**
  « table » pour un `case`. **St15-37** `smoke-http.test.sh:578` décalage qui dépend du `-X POST` de `refuse_args`, `rc` global.
  **St15-38** `_smoke_arity`/`_smoke_refused_rank` comme canal de retour. **St15-39** locales sans préfixe. **St15-40**
  `$previous` calculable (`AuthorizationSites.php:80-81`), commentaire « jamais autrement » obscur. **St15-41** `importedAt`
  rend un tableau positionnel. **St15-43** `audit_call_problems` tient sa propre liste (voulu). **St15-44**
  `smoke-http.test.sh:757-758` omet QUIC et le moteur TLS. **St15-45** le cas de l'index 0 a disparu de
  `testAnIndexOutsideTheTokensIsNoCall`. **St15-46** guillemets “…” au milieu de « ». **St15-47** coupe inégale
  `AuthorizationSites.php:28-29`.
- **C15-7** (voir Q5). **C15-8** `vueCompilerAlignment.spec.ts` imprime une commande interpolée sans guillemets simples (une PR
  qui mettrait `$(…)` dans `package.json` ferait exécuter à qui copie le message) → guillemets simples.
- **Sp15-6** `AdminQualityPage.vue:39-43` compare à « À propos » (le plan disait « ne compare plus » ; l'intention tient).
  **Sp15-8** mutations annoncées non lisibles au diff (R172, R173, R174, R175, R177, R178). **Sp15-9** `translateEntryButton`
  lit `.text()`, pas le nom accessible (Sp14-10). **Sp15-10** ajouts A et B de R181 attribués « à la demande du fil
  principal » ; B est un refactoring dans un balayage documentaire. **Sp15-11** R179 : un compilateur déclaré dans les deux
  sections n'est pas traité. **Sp15-12** R178 juge `then`/`catch`/`finally` sur le seul nom (choix déclaré). **Sp15-19** bilan
  de R169 : « refusés exprès (`refuse_args`) » pour `--data` (aucun `refuse_args`, refus générique). **Sp15-20** R171 : `absent`
  attendu, adapté (consigné). **Sp15-22** script à la racine sans test (consigné). **Sp15-23** la phrase du `trap` décrit les
  smokes, pas l'audit. **Sp15-24** `smoke-http.test.sh:758-797` recopie la liste `curl_variables`. **Sp15-25** tailles des
  règles et `check-claude-rules` absents du bilan de R181.

## Écarts déclarés, jugés (Spec)
Acceptables : R168, R171, R174, R176, R177, R178, R179. R172 acceptable sur le fond, écart décidé par l'agent seul (Sp15-1).
R169 un détail inexact au bilan (Sp15-19). R170 tenue, le bilan est faux sur `CDPATH` (Sp15-14). R173b et R175 intention
tenue, forme contestable (Sp15-3, Sp15-16). R180 un cas du plan inopérant (Sp15-15), une règle modifiée sans le dire
(Sp15-17). Constats de la 14e relecture : tous fermés ou consignés, sauf St14-31 (remplacé par une généralisation fausse,
Sp15-18), C14-11 (racine sans test, consigné), St14-8 (docblocks `null`, Sp15-5).

## Vérifié sain
Audit : `.curlrc` (`-q` absent 1/1, en premier 0/0, en second 1/1 ; `XDG_CONFIG_HOME` + `-q` en premier : 0) ; liste
d'autorisation exercée sur la vraie bibliothèque (`-v`, `--trace*`, `--libcurl`, seconde URL, `--`, `--proto-default`, `-x`,
`--proxy`, `--doh-url`, `--cacert`, `--unix-socket`, grappes, `--max-time=5`, `--Max-time`, `--max-tim`, `-L`,
`--location-trusted`, `-u`, `-K`, `--config`, `-:`, `--next`, `''`, option à valeur en dernier : refus, rang seul, curl non
appelé ; `SMOKE_CURL_OPTS` jugé) ; valeurs des options admises consommées sur le vrai curl 8.5.0 ; variables de curl retirées ;
R168 (aucune valeur dans `set` ni chez un fils, hors `readonly`) ; R170 (aucun fils avant `smoke_use_auth_config`, `trap`
avant, drapeau de l'audit) ; C14-5 (formes listées refusées) ; recenseurs PHP (329 tests OK) ; frontend sans `v-html`,
`innerHTML`, `eval`, `credentials` ni `fetch` nouveaux. Standards/Spec : outillage, shellcheck, `check-claude-rules` à 0 ; aucune
ligne ajoutée > 140 hors JSON ; règles sous 50 000 octets ; aucun moignon dans les six règles ; **les nombres du balayage de R181
concordent** (7, 2, 17, 104/60, 1 379, 31 ; `-q` 1/0/1).

## Portfolio (à proposer, rien rédigé)
Le sujet déjà repéré (liste d'interdits d'options `curl` remplacée par une liste d'autorisation et `-q`) se complète : même une
liste d'autorisation laisse passer la **valeur** d'une option admise (`@fichier`), et un `unset` peut échouer sans bruit sur une
variable en lecture seule.
