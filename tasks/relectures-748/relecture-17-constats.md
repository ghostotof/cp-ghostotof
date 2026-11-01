# Dix-septième relecture de #748 — diff a33a5a76...b840ea14 (R196 à R208), 2026-11-01

Quatre relectures : `/code-review medium` (CR17-*), audit sécurité (C17-*), axe Standards (St17-*), axe Spec (Sp17-*).
**Aucun critique ni haut.** Moyens : **St17-7 = Sp17-7 = CR17-1 = C17-6** (`startEdit` d'une entrée en langue non prise en
charge applique la moitié du geste : le formulaire édite l'entrée X sous la langue de la précédente, un « Enregistrer » partirait
vers un 409 `locale-immutable` mal traduit — le serveur refuse, l'audit le mesure dans `BackofficeIncidentResource.php:63-72`,
donc pas de déplacement silencieux contrairement à CR17-1 ; le test de R204 fige cet état), **St17-1** (la garde
`requireEveryField`/`fieldsFrom` est circulaire : bâtie à partir de `fieldNames`, elle vérifie `fieldNames` ;
`MissingEntryFieldError` est du code mort et l'en-tête dit le contraire), **St17-22 = C17-8** (la boucle `dump_printing` de R197
lance chaque forme avec tout l'environnement du test, pas sous `env -i`, et passe la valeur factice en argument de `grep`).
Avant les relectures, le fil principal a rejoué sur conteneurs redémarrés les cinq cibles `make`, les 26 tests hors ligne et
`check-claude-rules` : tous à 0 (front 2642, back 5788, `smoke-http` 493 `ok`). L'audit ferme C16-1, C16-2 (sauf C17-1, C17-2),
C16-3 (limite écrite), C16-4, C16-6, C16-7 ; C16-5 reste à #753 (D4).

## Décisions de l'utilisateur
*(à remplir après les questions)*

## Moyens

- **St17-7 = Sp17-7 = CR17-1 = C17-6** — `AdminIncidentsPage.vue:187-209`, même forme dans `AdminContributionsPage.vue`,
  `AdminCaseStudiesPage.vue`, `AdminAnonymousCvPage.vue`. `editingId`, `sourceGroup`, `translationGroup` et la prose sont posés
  avant la garde `isSupportedLocale` ; la langue garde celle de l'entrée précédente, le sélecteur est désactivé en édition. Le
  commentaire « même forme que `chooseLocale` » est faux (`chooseLocale` sort avant toute affectation). Le test « …le sélecteur
  garde la précédente (R204) » fige l'état sans vérifier `editingId`. Inatteignable par l'interface (`groupByTranslationGroup`
  filtre). Correction : `return` en tête de `startEdit`, avant toute affectation ; un test qui vérifie que **rien** ne change
  (`editingId`, prose, langue) ; une phrase dans `frontend-backoffice.md:212-214`. Confiance haute.
- **St17-1** — `qualityEntrySection.ts:13-18, 69-87`, `AdminQualityEntrySection.vue:119, 130, 180, 246, 298`. Garde circulaire
  (voir ci-dessus) ; un nom de `F` oublié dans `QUALITY_PRINCIPLE_FIELDS` n'est vu ni à la compilation ni à l'exécution.
  Correction : champs déclarés `{…} satisfies Record<F, EntryFieldOptions>`, la liste dérivée (exhaustivité par le compilateur),
  ou retirer la garde et corriger l'en-tête. Confiance haute. Question Q1.
- **St17-22 = C17-8** — `tools/tests/smoke-http.test.sh:1529-1534` (`dump_printing`). Contre le brief (2e745f7c, postérieur à
  R197) : `bash -c "$line"` hérite de tout l'environnement (session d'agent, runner, jetons) ; seule la sortie comptée par
  `grep -c` empêche une fuite aujourd'hui, et la valeur factice passe en argument (`grep -cF -- "$measured_value"`, contre la
  convention `values_found_in`, règle l. 195). Correction : `env -i PATH="$PATH" MEASURED_VARIABLE=… bash -c …`, motif par
  fichier (`grep -cF -f`). Confiance moyenne à haute.

## Faibles

Section Qualité :
- **St17-2 = Sp17-4** — `qualityEntrySection.ts:11-14` : l'en-tête range le formulaire (`EntryForm<F>`) dans « ce que le
  compilateur vérifie » ; seul `EntryForm<string>` existe, `form.nimporteQuoi` compile.
- **St17-3 = Sp17-6** (confiance basse) — `AdminQualityEntrySection.vue:83`, `AdminQualityPage.vue:78` : « le compilateur
  refuse un libellé … en trop » non attesté (un `computed` n'est pas un littéral frais). Un cas `@ts-expect-error`, ou retirer.
- **St17-6** — `qualityEntrySection.ts:164-172` : `proseFields`, `copiedToNewVersion: false`, `column: false` répétés à chaque
  `entryField(...)` (Data Clump).
- **CR17-2** — `AdminQualityEntrySection.vue:289` : `handleSubmit` pose `isSubmitting = true` puis `fieldsFrom()` peut lever ;
  rien ne remet `isSubmitting` : formulaire verrouillé jusqu'au rechargement (un champ non chaîne venu de l'API, ex. `iconKey`
  nul copié tel quel par `startEdit`). Lié à St17-1 (si la garde disparaît, le chemin disparaît).
- **CR17-9** — `AdminQualityPage.vue:833` : `{ ...reading, entries: reading.principles }` passe la même liste sous deux noms,
  et des refs non déclarées par `QualityEntrySource`.
- **Sp17-5** (≈ St17-5) — Q6 (A) « sans le renommer » : la clé `entries` ajoutée n'est pas déclarée au bilan de R202.

Langue :
- **St17-9 ≈ Sp17-18 ≈ CR17-3 ≈ C17-5 (info)** — 30 ` as Locale` restent dans `frontend/src` (14 présentation, 8 application,
  5 infrastructure d'admin, 1 domaine) ; trois sont la forme exacte corrigée dans `AccessRefusedNotice` :
  `OAuthConsentPage.vue:104, 110`, `SignInAgainLink.vue:35`. La même expression revient 22 fois. L'invariant tient (route
  `/:locale(fr|en)`), rien d'exploitable. Correction : un accesseur unique (`useCurrentLocale(): ComputedRef<Locale>`, ou le
  générique de langues de vue-i18n). Question Q2.
- **St17-8 ≈ CR17-4** — la garde de langue copiée quatre fois avec le même commentaire ; quatre entités d'admin gardent
  `locale: string` et cinq dépôts HTTP font `as Locale` (`HttpAdminQualityPrincipleRepository.ts:86`, `…TraitRepository.ts:84`,
  `HttpAdminAboutSettingsRepository.ts:55`, `…SiteCardRepository.ts:86`, `…MeCardRepository.ts:91`). Correction DDD : valider à
  la frontière (dépôt). Contre la décision « sans changer le contrat des entités ». Question Q2.

Formulaires par entrée :
- **St17-10** — le commentaire « L'état commun des formulaires par entrée (`useEntryFormState`) ; `formState` entier va à la
  bascule… » copié sept fois, redit le docblock du module.
- **St17-11 = Sp17-8** — `entryFormState.ts:25-26` : « pour les huit formulaires » (décompte d'appelants, classe St16-4) ; forme
  voisine antérieure `useEntryFormLock.ts:32`.
- **St17-14** (≈ Sp17-3 sur `AdminAboutSettingsForm.vue:140`) — six numéros de tâche seuls écrits par la série dans
  `frontend/src` : `useAdminTranslation.ts:183-184`, `AdminAboutPage.vue:44`, `AdminAboutSettingsForm.vue:140`,
  `useTranslatedForm.ts:82, 85` ; Q9 (A) demande la règle et sa section.
- **Sp17-3** — `AdminAboutSettingsForm.vue:139-142` : le docblock nomme encore son appelant (« la page l'appelle au geste… ») ;
  le bilan dit R201 « conforme », c'est faux.

Specs et support de tests :
- **St17-15 ≈ Sp17-15** — `frontend/tests/support/buttonLabelled.ts:3-8` dit « jamais d'un littéral français » ; ~83
  libellés restent (`'Enregistrer'` 18, « Enregistrer l'ordre » 19, `'Créer la version` 41, `'Proposer la version` 5, des
  titres). Question Q3.
- **St17-16** — `DELETE_FR`, `CANCEL_FR`, `ORDER_CANCEL_FR` redéclarés dans sept specs avec un commentaire de quatre lignes.
- **St17-17** — `startEditThroughSetup.ts:9-26` dépend de l'état `setup` exposé par le `vm` ; un `defineExpose` le casse : à
  écrire. (Lié à St17-7 : si `startEdit` sort en tête, le cas reste le seul chemin.)
- **St17-18** — les égalités de R203 (10, 15/12, 8) : les docblocks recopient nombres et listes, qui dériveront.
- **St17-19** — `sourceText.ts:308-312` : la procédure de `DEFERRED_HOOKS` a perdu l'étape « un cas dans
  `sourceText.spec.ts` » ; noms écrits deux fois ; « sur `window` » alors que `globalThis` et `self` sont acceptés.
- **Sp17-16** (info/faible) — le plancher `reads >= 2` par site (`translatedFormDeclarationOrder.spec.ts:294`) est arbitraire :
  un site qui perd la moitié de ses lectures passe. Les deux autres planchers de R203 sont justifiés. Question Q4.

Outillage smoke :
- **C17-1** (confiance haute, mesuré) — `smoke-http.test.sh:1419-1444` (`ps_lists_environment`, l. 1438 `-*) ;;`) :
  `ps -auxe` et `ps -eaux` passent la garde ; procps les relit en BSD avec `e` ; mesuré sous `env -i` + `unshare` : 4 lignes
  portent la valeur factice, encore 2 après `unset` (copie de `/proc/<pid>/environ`). Correction : liste d'autorisation des
  options de `ps` (esprit de D1), cas `ps -auxe`, `ps -eaux`, `ps -aux` (voisin admis).
- **C17-2** (faible/info) — `smoke-http.test.sh:1473` (`pat_environ`) sensible à la casse : `awk '…ENVIRON…'` passe (mesuré) ;
  perl/php passent aussi (non utilisés). Correction : insensible à la casse, cas awk, perl/php nommés dans la limite.
- **CR17-5** — `tools/tests/lib/smoke-cases.sh:393` (`write_value_patterns`) : une valeur avec un saut de ligne en tête, en fin
  ou doublé écrit une ligne vide, que `grep -F -f` lit comme un motif qui trouve tout.
- **CR17-7** — `smoke-http.sh:275` (`_smoke_writes_in_auth_dir`) : `-D "$SMOKE_AUTH_DATA_FILE"` est admis : curl écrase le
  corps enregistré avec les en-têtes de réponse, qu'un `--data-binary @…` suivant enverrait (Set-Cookie compris).
- **CR17-10** (latent) — `smoke-http.sh:319` : une valeur relative est jugée contre le dossier de la bibliothèque, curl la
  résout contre le sien (identiques aujourd'hui).
- **St17-23** — les blocs vide / témoin / CDPATH / racine / http et la boucle `for hollow` identiques dans les trois
  `smoke-*.test.sh`, modifiés à l'identique : un cas partagé dans `smoke-cases.sh`.
- **St17-24 ≈ Sp17-11** — la procédure « huit endroits » écrite deux fois (`smoke-http.sh:378-390`, `outillage-smoke.md:97-104`)
  et incomplète : le compte « five » (règle l. 70, docblock l. 370), la liste de `_smoke_value_touches_file` (l. 445-450),
  l'énumération de la règle (l. 72-84) ; le test ne vérifie pas « five ».
- **St17-25** — le test de cohérence des options lit deux phrases de prose et un nombre en anglais borné à « twelve ».
- **St17-26** — `smoke-mcp.test.sh:~514` : l'espace final de `'Mcp-Session-Id: 0 '` porte le sens, rien ne le dit.
- **Sp17-9** — `smoke-http.sh:74-79` : une ligne-moignon « # Auth (St16-26). Vides à la », et la phrase sur le préfixe
  `SMOKE_AUTH_` dite deux fois.
- **Sp17-10** — `smoke-http.sh:233-234` : le docblock de `_smoke_write_auth_config` omet `SMOKE_AUTH_DATA_FILE` (sortie 2).
- **C17-7** (faible-moyen, action humaine) — l'incident de R197 (`ps e`, jeton de Claude Code dans le transcript) : rien dans
  le dépôt ne dit la rotation. L'auteur a répondu le 2026-11-01 « Je gère » : action hors session, à confirmer par lui seul.
- **C17-9** — les mutations « en place, copie vérifiée par `cmp` » sur des fichiers sensibles (`smoke-http.sh:284`) : un agent
  interrompu entre mutation et restauration laisserait une bibliothèque affaiblie qu'un commit suivant embarquerait.
  Correction : un `git diff --exit-code` des fichiers mutés avant chaque commit (brief).

Backend :
- **St17-28 = CR17-6** — `ProblemDetailStaysStaticTest.php:~525-530` (et l. 67) : le témoin « sans la virgule » est produit
  dans le `foreach (COMMAS …)` avec une clé sans `$label` : une seconde entrée donnerait une clé en double.
- **CR17-8** — `ProblemDetailStaysStaticTest.php:1648` : `constantsAt` compte désormais `{`, `T_CURLY_OPEN`,
  `T_DOLLAR_OPEN_CURLY_BRACES` ; aucun cas de valeur de constante qui porte des accolades (écart 6 de R206, déclaré).
- **Sp17-12** — R207 : le cas d'`ImportsStaySortedTest` du Step 3 n'est pas écrit (mesure à la main par `inCode()`), contre le
  brief (« un cas vert d'emblée s'écrit quand même ») ; non déclaré comme écart au bilan.
- **Sp17-13** — `ClassNameResolverTest.php:145` : « le recenseur résout `C` vers `app\c` » décrit le défaut comme un
  comportement actuel ; attendu : le présent de ce que le cas garde.

Forme et bilan :
- **St17-32 = Sp17-1** — lignes > 140 dans des corps de commit, hors lignes rouges : b366e1a2 (142, 168, 156, 167, 152, 150),
  d5e29e05 (141), 30334c3f (144) ; le bilan ne déclare que la dernière. Les messages ne se réécrivent pas : à déclarer.
- **Sp17-2** — R197 : le tableau motif × délimiteur demandé au corps du commit n'y est pas (« table au rendu ») : le consigner.
- **Sp17-14** — R208 : les commandes des recherches 3, 6, 7, 8, 9 absentes ; la recherche 9 annonce 346 lignes,
  `grep -rnE 'R[0-9]+' frontend/src backend/src | wc -l` en rend **304** à HEAD.

## Info

- **St17-4** — le docblock de `fieldNames` (`AdminQualityEntrySection.vue:118`) omet l'usage par `requireEveryField` (l. 180) ;
  à réécrire avec St17-1.
- **St17-5** — `AdminQualityEntrySection.vue:51`, `AdminQualityPage.vue:66-75` : « renomme » → « expose la liste sous `entries` ».
- **St17-12** — `proposedCreation.ts:16-20` : `{ locale; translationGroup }` en ligne ; un type nommé `EntryFormPlacement`.
- **St17-13 = Sp17-19** — « ce qu'un getter ne verrait pas » retiré de `useTranslatedForm.ts:24-28`, gardé par
  `frontend-backoffice.md:193` : aligner.
- **St17-20** — `vueCompilerAlignment.spec.ts:63-84` : `outsideSingleQuotes` trompé par une apostrophe française ; limite à écrire.
- **St17-21** — `expect(callCount,` sans espace (`translatedFormDeclarationOrder.spec.ts:~290`) ; `'de' as never`
  (`AccessRefusedNotice.spec.ts:145`).
- **St17-27** — `smoke-cases.sh:163-169` : « /dev/fd propre à bash » ne justifie rien (tous les tests sont en bash).
- **St17-29** — `COMMAS` et `DOLLARS` à une entrée.
- **St17-30** — `ClassNameResolver.php:155-157` : une ligne de ~110 dans un docblock coupé à ~80.
- **St17-31** — `PhpSources.php:118-140` : `isChar`/`isAnyChar` ne lisent que `$char[0]` ; `\assert(1 === \strlen($char))`.
- **C17-3** — `smoke-http.sh:451-452` : « ` @f` … jamais un fichier lu » est faux pour `-b ' @f'` (curl 8.5.0 lit le fichier
  « ␣@f », mesuré) ; le code le refuse (pas de `=`), seule la prose est fausse.
- **C17-4** — `smoke-http.sh:425-438`, `outillage-smoke.md:78` : `-D "$SMOKE_AUTH_DIR"` et `-D "$D/absent/../curl-auth.conf"`
  passent le jugement (`-ef` ne suit `..` qu'à travers des composants existants) ; sans effet mesuré (curl sort en 23, le `-K`
  intact) ; préciser la limite ou refuser `/..`.
- **Sp17-17** — `rate-limited` après la fusion de R205 : pas un constat (le composable ne distingue pas les raisons ; 429 couvert
  par `HttpAdminTranslationRepository.spec.ts:58` et les pages d'admin).
- **Sp17-20** — `vueCompilerAlignment.spec.ts:350` : `toContain('devDependencies')` redondant.
- **Sp17-21** — `AdminAboutPage.vue:66` `form !== null` (constante à gauche dans le dépôt) ; `buttonLabelled.ts` garde un
  `as DOMWrapper<HTMLButtonElement>`.
- **Sp17-22** (confiance basse) — les corps de R199, R201 à R205 et R207 ne donnent que le test lancé comme « commande », pas
  la méthode de mutation ; R197 ne donne pas la taille de la règle modifiée.

## Bilan de R208 relu
Vrai pour R196 à R200 et R202 à R206. R201 « conforme » est faux (Sp17-3). Ne voit pas Sp17-1, Sp17-4/5, Sp17-7/8,
Sp17-9 à 13 ; recherche 9 non reproductible (Sp17-14).

## Vérifié sain
- Tests hors ligne sous `env -i` : `smoke-http` 493, `audit-prod` 40, `smoke-mcp` 44, `login-throttling` 30, `dead-session` 41.
- `smoke-http.sh` : lecture → `unset` → jugement, `set +x` rétabli sur chaque chemin, `umask` restauré, préfixe `SMOKE_AUTH_`
  refusé, `--data-binary @` seulement vers `smoke_data_file`, `-q` en tête, `--proto`/`--proto-redir`/`--noproxy` sur chaque
  appel `-K`, messages au rang ; fonctions ≤ 25 lignes ; exemption d'une ligne intacte.
- Corps d'écriture de Qualité limité à `locale`, `translationGroup` et aux champs ; `switchToProposedCreation` vide `editingId`.
- Aucun puits dangereux ajouté, aucun secret dans le diff ni les commits ; 58 mutations lues dans les corps, aucune non attestée.
- Règles < 50 000 (outillage-smoke 22 478, frontend 20 882, frontend-backoffice 38 926, frontend-session-refus 17 270).

## Portfolio (à proposer, rien rédigé)
- « Incidents » : la fuite d'un jeton par un `ps e` d'agent (C17-7) — cause, correction (le brief), leçon, rotation à confirmer.
- « Études de cas » : liste de refus contre liste d'autorisation appliquée à `ps` (C17-1) ; la lecture des délimiteurs par
  identifiant dans une garde de source PHP (R206 : `&` sans identifiant propre depuis 8.1, nowdocs).
