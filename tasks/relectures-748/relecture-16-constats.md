# Seizième relecture de #748 — diff 381a0740...ba56863f (R182 à R195, R187b, R189b), 2026-11-01

Quatre relectures : `/code-review medium` (CR16-*), audit sécurité (C16-*), axe Standards (St16-*), axe Spec (Sp16-*).
**Aucun critique ni haut.** Moyens : **St16-22 = Sp16-15 = CR16-1** (`bodyOf` lit les niveaux par le texte : faux négatif
**mesuré** d'une garde, l'audit le croyait inoffensif — C16-5), **Sp16-1 = Sp16-20** (les mutations ne sont pas portées au plan
malgré la contrainte : Sp15-8 répété), **St16-1** (`as unknown as EntryForm`), **St16-25** (la procédure d'admission d'une
option est incomplète et rouvrirait la fuite), **St16-31** (doublons dans les tests d'outillage). Avant les relectures, le fil
principal a rejoué sur conteneurs redémarrés les cinq cibles `make`, les 26 tests hors ligne et `check-claude-rules` : tous à
0 (front 2618, back 5751). L'audit confirme fermés C15-2, C15-3, C15-4, C15-8 (rejoués sur curl 8.5.0 en `--network none` et
par `php -r`) ; CR15-4 et C15-1 fermés sauf des formes voisines (C16-1, C16-2).

## Décisions de l'utilisateur
_(à prendre — voir les questions ci-dessous)_

Questions à poser :
1. **Sp16-1 = Sp16-20** (Sp15-8 répété) : les mutations des tâches figurent dans les rendus des agents, que le fil principal
   a vérifiés, mais pas au plan. Reco : le fil principal reporte au plan, dans la sous-section de la série, chaque mutation
   rendue (commande, ligne rouge) ; à partir de la série suivante, chaque agent écrit ses mutations dans le **corps du message
   de commit**, où elles sont attestées par git. Option : seulement la règle pour l'avenir.
2. **St16-1 = Sp16-3** : quatre `as` dans `AdminQualityEntrySection.vue` (l. 121, 128 `as unknown as EntryForm`, 189, 309),
   contre la ligne des séries précédentes (« aucun `as unknown as` »). Reco : les retirer — `reactive<{ locale; translationGroup }
   & Record<F, string>>`, `as I` remplacé par le type structurel `QualityEntryInput<F>`, une garde d'exhaustivité pour
   `Object.fromEntries` (Sp16-2). Option : les garder, commentés.
3. **CR16-5 = St16-10 = Sp16-12** : trois relectures regrettent la condition d'`endCall` que vous avez fait retirer après R188
   (défense en profondeur si un jour un second appel était permis). Reco : **garder votre décision** — l'invariant « un seul
   appel en vol » est déjà gardé par le test de R125 (une traduction en vol refuse la suivante), le nommer dans le commentaire.
   Option : remettre la condition.
4. **P15-1** : le ticket que Q13 (A) de la seizième série demandait n'existe pas. Il réunit les recenseurs de `backend/tests`
   qui lisent un jeton d'un caractère par son texte (`MemberCalls.php:77`, `MisqualifiedNativeCalls.php:165-176`,
   `PathsLeavingTests.php:179-201`, `ChildProcessHarnessTest.php`, `ApplicationSecretUsesTest.php`,
   `SignaturesAreNeverComparedByHandTest.php:115-121,159`, `AnonymousClassesAndFreeFunctions.php:101,169`,
   `CookieConstructions.php:133`, `UnsortedImports.php:138`, `ClassNameResolver.php:145`), avec la piste d'une primitive unique
   (CR16-4). `bodyOf`, `arguments()` et `constantsAt` de `ProblemDetailStaysStaticTest` relèvent de #748 (périmètre de D1 /
   Q13 (A)). Reco : **ouvrir le ticket maintenant** (dépôt public `ghostotof/cp-ghostotof`). Option : à la clôture de #748.

## Moyens
- **St16-22 = Sp16-15 = CR16-1** `ProblemDetailStaysStaticTest.php:1605-1610` (`bodyOf`) lit `(`, `)`, `{` par le texte ;
  mesuré : `__construct(public string $a { get => "$a)"; }, string $m) { parent::__construct('x'.$m); }` → 0 violation (1
  pour le témoin) : faux négatif ; le plan le range à tort dans P15-1, avec une raison fausse. Moyenne / haute.
- **Sp16-1 = Sp16-20** mutations non portées au plan (voir Q1) ; la liste du bilan est incomplète (R190 étape 4, R187 étape 1
  omises ; « deux mutations de CR15-5 » pour une prévue) ; rejouées et attestées par la relecture : R183 (a) 10 FAIL, (c) 5,
  R185 1, R186 (`CASE_RANK` 88, racine 1, `EXTRA_NAME` 1), R193 (b) 1, R194 25/52, R192 2. Moyenne / haute.
- **St16-1 = Sp16-3** (voir Q2). Moyenne / haute.
- **St16-25** `outillage-smoke.md:77-78`, `smoke-http.sh:362-363` : « To admit a new option: a line of the `case`… Nothing
  else » — une option dont la valeur lit un fichier exige aussi une ligne dans `_smoke_value_reads_file` et un cas
  `refuse_file_args` ; suivie à la lettre, la procédure rouvre la fuite. Moyenne / moyenne.
- **St16-31** `smoke-http.test.sh` : `refuse_file_args` copie `refuse_args`, `refuse_curl_opts_file` copie `refuse_curl_opts`.
  Moyenne (jugement) / haute.

## Faibles
- **C16-1 = Sp16-16** `smoke-http.sh:403` : `-b '@a=b'` (chemin contenant `=`) passe ; curl 8.5.0 lit un fichier dès le `@` en
  tête (`CURLOPT_COOKIEFILE`, mesuré) ; la règle (« no `@` leads the value, or it is a cookie ») est fausse → `[[ "$2" == @* ||
  "$2" != *=* ]]`, cas `-b '@a=b'` et `-b "@$SMOKE_AUTH_CONFIG"`, règle corrigée. Faible-moyenne / haute.
- **C16-2** garde des vidages : `bash -c 'declare'`, `bash -c "export"`, `bash -c 'declare -x'`, `bash -c 'typeset'`,
  `` x=`declare` ``, `` x=`export` ``, `` x=`set` ``, `\set`, `declare\` passent (mesuré) → `` ` ``, `'`, `"` à la fin de
  `list_end`, `` ` `` au suffixe de `pat_set`, lignes à `dump_forbidden`, voisin `echo 'declare keyword'`. Faible / haute.
- **Sp16-17** `ProblemDetailStaysStaticTest.php:1368` (`arguments()`) : `is(',')` découpe sur une virgule interpolée ; mesuré :
  `new Problem("$a,", 'L', "x$c")` → 0 violation (1 pour le témoin). **Sp16-19** aucun cas heredoc/nowdoc (gérés, mesuré `[7]`) ;
  `constantsAt` l. 1576-1578 (CR16-2, cosmétique) ; `is('$')` l. 1693 (sur-refus sur `"$x$"`) ; le grep de R193 ne voit ni
  `is([…])` ni la virgule. Faible / haute.
- **CR16-6 = St16-18** `translatedFormDeclarationOrder.spec.ts:100` : `readsOf()` retire un nom lié localement de toutes les
  lectures (portée non lexicale) → un paramètre de fermeture homonyme masque une lecture directe (faux négatif) ; `inspect()`
  long. **CR16-8 = St16-17** `emptyStateNeedsLoaded.spec.ts:44` plancher 9 pour 10 fichiers (un fichier peut sortir en
  silence) ; planchers incohérents (`>= 8` pour 8). Faible / haute.
- **CR16-7 = St16-15 = Sp16-13** `ERROR_TEXT_KEYS` typé contre `fr.json` seul (`en.json` non vérifié, le docblock promet plus) ;
  `presentation` importe un type d'`infrastructure` ; commentaire l. 27 qui compare à l'ancienne API. **CR16-9 = St16-13**
  `axe.ts:97-107` : ajout de la copie et `axe.setup` avant le `try` (copie laissée si `setup` lève) ; coût de setup/teardown
  par bouton non mesuré. **CR16-10** `AdminQualityEntrySection.vue:101` : `proseFields`/`idPrefix`/`source` lus une fois au
  setup alors que `fields` est un `computed`. Faible / moyenne-haute.
- **St16-2 = Sp16-4 = C16-7** `AdminAboutPage.vue:48-50` : `locale as Locale` ×2 sans validation (`isSupportedLocale` existe) ;
  non déclaré au bilan. **St16-3** docblock de `EntryForm` faux depuis le cast, historique « le champ `Position` a disparu ».
  **St16-6** quatuor d'états et `proposeFrom(…switchToProposedCreation(…))` répétés sept fois (piste `useEntryFormState()`).
  **St16-7** deux canaux en sens inverse (`defineExpose` + `useTemplateRef` / `@switch-locale`), `?.` silencieux. **St16-8**
  un objet de refs et de fonctions passé en prop (Middle Man), `QualityEntrySource` redéclaré au lieu de `ReturnType<…>`.
  **St16-9** `qualityEntrySection.ts:92-111` nom de champ écrit deux fois (`isProse(…, 'title')` typé `string`). **Sp16-2**
  `qualityEntrySection.ts:12-13` promet que le compilateur vérifie l'exhaustivité (faux). Faible / haute-moyenne.
- **St16-11** `useAdminTranslation.spec.ts:130-141` doublon de l. 90-100, nom ≠ assertions. **St16-12**
  `vueCompilerAlignment.spec.ts:282` `toContain('dependencies')` tautologique. **St16-14 = Sp16-5** `sourceText.ts:309`
  « différent » (St15-4 réintroduit) ; « écrite ici et nulle part ailleurs » contredit par `GLOBAL_DEFERRALS`/`PROMISE_METHODS`.
  **St16-16 = Sp16-6** deux renvois différents pour la même limite (St15-11 fermé en partie). **St16-19** « Supprimer »,
  « Annuler » encore en dur, `editButton` dupliqué. **Sp16-7** la limite « Invisible / Non vu » écrite **trois** fois
  (`sourceText.ts:470-475`, `noLoadInOnMounted.spec.ts:21-30`, `frontend-session-refus.md:104-111`), triplée par R192 (Sp15-12).
  **Sp16-8** bilan de R189b : 13 cas, pas 15. **Sp16-9** bilan de R191 incomplet (deux specs hors plan non dites). Faible / haute.
- **St16-24** docblocks qui listent leurs appelants (`InterpolatedStrings.php:9-12` déjà dérivé, `ClassNameResolver.php:154-156`) ;
  **St16-4** idem côté frontend (`AdminAboutSettingsForm.vue:138-150`, `proposedCreation.ts:16-17,27`, `useTranslatedForm.ts:81`,
  `entryFormLock.ts:95`, `sourceText.ts:309-311`). **St16-23** `InterpolatedStrings::CLOSING_BRACKET` jamais utilisée. **St16-26 =
  Sp16-24** `outillage-smoke.md:20` « what `smoke_cleanup` empties » non exhaustif (`SMOKE_DATA_FILE`), `smoke_use_auth_config
  SMOKE_DATA_FILE` → message trompeur, nommage incohérent avec `SMOKE_AUTH_DATA_FILES`. **St16-27** `smoke-http.sh:69,86`
  attribuent la pose à `_smoke_write_auth_config` (c'est `_smoke_write_config_file`), limite du double sourçage qui perd
  `SMOKE_AUTH_DATA_FILES` non écrite. **St16-28** `_smoke_write_config_file` réécrit `_smoke_value` de l'appelant (la convention
  ne parle que de juger). **St16-34** `smoke-mcp.test.sh:431-432` `sed -n 3p/4p` sur des numéros de ligne. **St16-35** l'`awk`
  prend la première ligne `unset -v`. **Sp16-21 = St16-30** `_smoke_judge_args` 28 et `smoke_fetch` 33 lignes laissées sans
  décision écrite (R185 : ≤ 25). **Sp16-22** `smoke-http.sh:258` docblock cite `trap 'rm -f "$headers_file"; smoke_cleanup'
  EXIT` (forme que D2 interdit). **Sp16-23** R182 : la phrase gardée n'est vraie qu'en partie (`readonly` reste visible par un
  vidage du shell). Faible / haute-moyenne.
- **CR16-3** `ClassNameResolver.php:145` virgule par le texte, `UnsortedImports.php:138` `'{' === …->text` (inatteignable dans un
  `use`, mélange des formes). **CR16-4 = C16-5** `SignaturesAreNeverComparedByHandTest.php:115-121` profondeur du `match` par
  texte (faux positif possible, côté sûr), `MisqualifiedNativeCalls.php:165-176` → une primitive unique (voir Q4). **St16-42**
  `opensALevel`/`closesALevel` sans `$this` (à mettre dans `PhpSources`), `MemberCalls.php:77` faux positif sur
  `"$a->getPathInfo("`. **Sp16-18** les quatre lignes de plus de P15-1 : `AnonymousClassesAndFreeFunctions.php:101,169`
  cosmétiques, `CookieConstructions.php:133` sur-refus réel (`"$r->clearCookie("` → `[1]`), `bodyOf` bug réel dans le
  périmètre ; le grep de R195 rend 24 lignes, pas 25. Faible / haute.

## Info
- **C16-3** R182 sous `shopt -s localvar_unset` : l'`unset` laisse la locale définie-vide, `[[ -v ]]` faux, la globale exportée
  visible des fils (rc=0) ; inatteignable par les quatre scripts → limite écrite. **C16-4** `-D "$SMOKE_AUTH_CONFIG"` passe :
  les en-têtes de réponse écrasent le fichier `-K`, relus comme configuration au prochain appel (pas de fuite) → refuser `-D`
  vers `SMOKE_AUTH_DIR` ou écrire la limite. **C16-6** `vueCompilerAlignment.spec.ts:99` : la **prose** du message interpole
  encore des valeurs du dépôt sans guillemets (copie de la ligne entière → `$(id)` développé) → `JSON.stringify` ou « copier la
  commande après « : » ».
- **St16-5** numéros de tâche cités dans des commentaires de `src` (renvois, jugement). **St16-20** docblocks qui répètent le
  nom du membre. **St16-21** coupes irrégulières (pas de moignon). **St16-29** `_smoke_value_problem` rend 0 sans problème
  (Mysterious Name). **St16-32** doublon `smoke-http.test.sh:199` / l. 92. **St16-33** `rc` local/global incohérent, rc factice,
  arguments inutiles à `_case_incomplete`. **St16-36** commentaires recopiés dans les quatre tests. **St16-37**
  `audit-prod.sh:73-75` « les deux options » pour trois. **St16-38** `smoke-http.sh:274` 144 caractères (R167 (A)). **St16-39**
  titres R187b/R189b > 140 (convention du plan). **St16-40** commentaires au passé (règle « which a getter did not see »,
  `ClassNameResolverTest.php:145`, `CompiledNestedReading.php:12`). **St16-41** `isChar(string)` sans `non-empty-string`,
  `opensBrace`/`closesBrace` qui refont `\ord`. **St16-43** lectures par texte inoffensives déclarées. **St16-44** Basic Auth
  jetables en argument de `grep` dans les tests. **St16-45** phrase ambiguë `outillage-smoke.md:153`.
- **Sp16-10** R189 « +1 » au lieu de +2 (non dit). **Sp16-11** R191 « exactement » interprété (préfixe gardé). **Sp16-14**
  « un cas par hook » imprécis. **Sp16-25** `smoke_data_file` : un lien qui remplace le fichier n'est pas vu (limite non écrite).
  **Sp16-26** `audit-prod.test.sh:187` `AUDIT_BASIC_AUTH='u:p'` en dur. **Sp16-27** nombres de `ok` de R185/R186 absents du
  bilan (mesurés : smoke-http 332 → 388, audit 35 → 40, mcp 39 → 43, login 29 → 30, dead-session 40 → 41).

## Constats de la 15e relecture
Ouverts ou en partie : CR15-4 (Sp16-16 / C16-1), C15-2 dans `ProblemDetailStaysStaticTest` (Sp16-15, Sp16-17), St15-4
réintroduit (Sp16-5), St15-11 (Sp16-6), St15-30 (Sp16-21), St15-32 (Sp16-22), Sp15-8 (Sp16-1, Sp16-20), P15-1 sans ticket
(Sp16-18). Tous les autres fermés (frontend et outillage/backend), St15-34 écarté avec raison.

## Vérifié sain
Audit : R182 (`readonly`, locale masquante, nameref, `declare -x` sans valeur, tableau) ; `--noproxy` mesuré (`HTTPS_PROXY`,
`ALL_PROXY=socks5://…`, `NO_PROXY=` vide) ; ordre des options sur chaque appel authentifié, jamais sur les anonymes ; `-H @f`,
`-H @-`, `-b @f` refusés, appels réels admis ; les sept formes de C15-1 refusées ; C15-2 rejoué (`php -r`, 375 tests OK) ;
`shellQuoted` ; frontend 237 verts, aucun `v-html`/`innerHTML`/`eval`, la section générique n'envoie que `locale`,
`translationGroup` et ses champs. Standards/Spec : `front-test`/`lint`/`build` à 0, 26 hors ligne, shellcheck, `check-claude-rules`
à 0 ; PHPUnit ciblé 457 OK, PHPStan max sans erreur sur les 8 fichiers ; règles sous 50 000 octets ; aucun `any`,
`eslint-disable`, `@ts-*` ajouté ; aucun identifiant de constat dans un nom de test ; aucun moignon.

## Portfolio (à proposer, rien rédigé)
Le sujet « liste d'interdits → liste d'autorisation + `-q` » se complète encore : une valeur d'option admise lue comme fichier
dès un `@` en tête, même quand elle contient le séparateur attendu (`-b '@a=b'`).
