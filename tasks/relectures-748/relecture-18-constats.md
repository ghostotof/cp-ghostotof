# Dix-huitième relecture de #748 — diff 16aa56f5...d0b896c1 (R209 à R221, R216b, R219b, R219c, R220b), 2026-11-03

Quatre relectures : `/code-review medium` (CR18-*), audit sécurité (C18-*), axe Standards (St18-*), axe Spec (Sp18-*).
**Aucun critique ni haut.** Moyens : **CR18-1** (le sélecteur de langue perd `route.query` : sur `/fr/oauth/consent?…` le
consentement perd sa demande OAuth ; préexistant, la série réécrit `switchTo` sans le corriger), **St18-1** (un libellé du
catalogue resté en dur, `AdminLayout.spec.ts:226`, confirmé par le fil principal), **St18-2** (faible à moyen : les extraits du
catalogue en `includes`/`toContain` échappent au relevé d'égalité), **St18-8 = CR18-5 = St18-10 = Sp18-21** (faible à moyen : la
fabrique `unsupportedLocale()` et son docblock recopiés dans neuf dépôts, `value` jamais lue), **St18-17** (faible à moyen : la
garde `as Locale` teste une copie de sa regex), **St18-20 ≈ Sp18-17** (faible à moyen : `_smoke_writes_in_auth_dir` et le texte
`writes` ne disent plus ce qui est jugé). L'audit : 0 moyen, 4 faibles dont deux contournements mesurés de la garde `ps` (C18-1,
C18-2) et un défaut antérieur révélé par R220b (C18-3).
Avant les relectures, le fil principal a rejoué sur conteneurs redémarrés les cinq cibles `make`, les 26 tests hors ligne et
`check-claude-rules` : tous à 0 (front 2 663 tests / 185 fichiers, back 5 798, `smoke-http` 535 `ok`). L'audit ferme C17-1 (voisins
rouverts : C18-1, C18-2), C17-2, C17-3, C17-4, C17-5, C17-6, C17-8 (reste C18-4), C17-9 ; C17-7 reste à la charge de l'auteur.

## Décisions de l'utilisateur
Prises le 2026-11-03 : **les quatre recommandations**.
- Q1 (C18-3) → corrigé dans la dix-neuvième série : `ANTHROPIC_API_KEY`, `SCALEWAY_AI_*`, `CONTACT_*` forcés aussi par
  `<env force>` (le `<server>` gardé), un test qui lance un fils avec une valeur factice exportée et vérifie par empreinte que le
  parent et le fils lisent la fausse clé, la phrase de `phpunit.dist.xml` corrigée.
- Q2 (C18-4) → les formes qui lisent d'autres processus (`ps -auxe`, `ps -eaux`…) sont jugées par la garde **sans être
  exécutées** ; seules les formes bornées au processus mesuré (`ps e -p $$`…) sont lancées ; `write_value_patterns` refuse une
  valeur faite de blancs seuls ; la phrase de `outillage-smoke.md:182` dit vrai.
- Q3 (St18-2) → le critère de R219 s'applique aux extraits (`includes`/`toContain`/`startsWith`) : un extrait dont la mutation de
  la clé fait rougir le spec se lit du catalogue (clé entière ou constante partagée, `ORDER_TEXTS` pour les erreurs) ; les autres
  restent avec leur raison ; le relevé est rejoué avec déséchappement (St18-1).
- Q4 (CR18-2 ≈ Sp18-12) → sur un chemin sans préfixe de langue, le sélecteur change la langue d'interface sans naviguer ; ailleurs
  il remplace le premier segment **en gardant query et hash** (CR18-1) ; les deux cas testés.
- Les sept questions de R221 suivent leur recommandation : (a) chaque numéro de tâche seul gagne sa série et sa règle (forme de Q9
  (A) de la seizième) ; (b) récits au passé mis au présent, un nom de test renommé si son sens est un récit ; (c) les docblocks qui
  redisent le membre retirés ; (d) les dix lignes de code > 140 coupées ; (e) les deux lignes du brief recollées ; (f) la limite de
  curl de la règle dit 8.5.0 pour les trois mesures faites, la quatrième dite ; (g) la ligne de 151 de ddf40ed8 déclarée (non
  réécrivable).
- C17-7 : rotation du jeton à la charge de l'auteur, hors session.
- Tout le reste se corrige (critère « tout jusqu'à zéro »).

Questions posées :
1. **C18-3** : clés de test forcées en `<server>` seul. Reco : `<env force>` en plus, test par empreinte. Option : ticket à part.
2. **C18-4** : `ps -auxe` exécuté. Reco : juger sans exécuter. Options : `unshare -rpf --mount-proc` (restreint sur Ubuntu 24.04) ;
   garder et refuser les blancs.
3. **St18-2** : extraits du catalogue. Reco : même critère. Options : erreurs partagées seules ; écrire l'exclusion.
4. **CR18-2 / Sp18-12** : sélecteur sur la 404 sans préfixe. Reco : changer la langue sans naviguer. Options : masquer ; limite.

## Moyens
- **CR18-1** (moyen, haute) — `frontend/src/presentation/ui/LocaleSwitcher.vue:29`. `switchTo` pousse `{ path, hash }` sans
  `route.query` (déjà le cas à 16aa56f5, `git show 16aa56f5:…`). `/fr/oauth/consent?client_id=…&state=…&code_challenge=…` →
  « English » → `/en/oauth/consent` sans query : la demande OAuth (PKCE, `state`) est perdue, le flux casse ;
  `/fr/login?redirect=…&reauth=1` perd ses drapeaux.
- **St18-1** (moyen, haute, confirmé) — `frontend/tests/presentation/layout/AdminLayout.spec.ts:226`
  `toBe('Navigation d\'administration')` = `fr.admin.navigation` (`fr.json:54`), la l. 227 lit déjà le catalogue. Cause probable :
  le relevé de R219/R219c ne déséchappe pas `\'` — à rejouer avec déséchappement sur toutes les specs.
- **St18-2** (faible à moyen, moyenne) — les extraits passés à `includes`/`toContain`/`startsWith` : « La liste a changé
  entre-temps » (`admin.order.errors.stale-order`) dans 10 sites de 7 specs d'admin ; 165 extraits en tout (beaucoup de prose,
  OAuthConsent, Login) ; `McpConnectionsPage.spec.ts:395` `startsWith('Révoquer')` (gardé par R219b, préfixe de `revokeAria`).
- **St18-8 = CR18-5 = St18-10 = Sp18-21** (faible à moyen, haute) — `unsupportedLocale()` + docblock (« que la page montre comme son
  erreur habituelle ») ×9 dépôts, message `'Unsupported locale in response'` ×9 ; `supportedLocale.ts:19`
  `unsupported: (value: string) => Error`, `value` lue par aucun appelant. Corr. : `UNSUPPORTED_LOCALE_MESSAGE` exporté,
  l'explication dans `supportedLocale.ts` seul, `() => Error` ; **St18-9** : le nom `unsupportedLocaleError()`.
- **St18-17** (faible à moyen, haute) — `noLocaleCast.spec.ts:64` réécrit `/\bas\s+Locale\b/` (l. 37) dans `detect` : un détecteur
  modifié laisserait le cas vert. Corr. : une constante lue par les deux.
- **St18-20 ≈ Sp18-17** (faible à moyen, haute/moyenne) — `tools/lib/smoke-http.sh:405-428, 464, 526` : `_smoke_writes_in_auth_dir`
  juge aussi les corps sous `TMPDIR` et toute valeur à composant `..` ; un `-D ../x` reçoit « une valeur qui écrit sur un fichier
  de la Basic Auth ou de son corps est refusée », pas forcément vrai. Corr. : un nom juste (`_smoke_writes_on_call_file`), un texte
  vrai pour les trois cas (ou un « par précaution » pour `..`), règle et docblock de `smoke_fetch` suivis.

## Faibles
### Sécurité
- **C18-1** (haute, mesuré) — `smoke-http.test.sh` `ps_lists_environment` ~l. 1494 : l'option BSD `e` collée à des chiffres passe
  (`ps e$$`, `ps -p $$ e1`, `ps -p $$ e9999999` ; `env -i … | grep -c factice` = 1). Corr. : retirer chiffres et `$` du jeton avant
  `^[[:alpha:]]*e[[:alpha:]]*$` ; cas dans `dump_printing`/`dump_forbidden` ; `ps -p 1` reste admis.
- **C18-2** (haute, mesuré) — `ps_word` : `"ps" e`, `'ps' e` passent (le guillemet colle ; `"ps" e -p $$` mesuré = 1). Corr. :
  guillemet/accent grave optionnels autour de `ps` ; `"$(command -v ps)" e`, `p=ps; $p e` restent dans la limite écrite.
- **C18-3** (haute sur le mécanisme, antérieur au diff) — `backend/phpunit.dist.xml:48-52`, `backend-outillage.md:164`,
  `.claude/CLAUDE.md` (« dummy forced value in test ») : `ANTHROPIC_API_KEY`, `SCALEWAY_AI_*`, `CONTACT_*` ne sont forcés qu'en
  `<server>` ; Symfony lit `$_ENV` d'abord (`EnvVarProcessor.php:173`, `variables_order=EGPCS`) : une vraie clé exportée dans le
  conteneur gagne chez le parent (`KernelTestCase`, `WebTestCase`), le filet « une sortie réelle échouerait en 401 » ne tient plus.
  Mesuré (valeur factice) : `ENV=shell SERVER=factice` → `EnvVarProcessor lit: shell`. Latent (aucun compose ne pose ces noms ;
  la CI les écrit dans `.env.test.local`). Corr. : `<env … force>` en plus du `<server>`, un test par empreinte, la phrase de
  `phpunit.dist.xml` corrigée.
- **C18-4** (haute) — `measure_dump_output` ~l. 1597-1607 : `ps -auxe`/`ps -eaux`, même sous `env -i`, font passer l'environnement
  de **tous** les processus de l'utilisateur (jetons de la session d'agent compris) dans le tube ; rien sur disque tant que le
  fichier de motifs est sain, mais `write_value_patterns` n'écarte pas une valeur faite de blancs seuls (`' '`, tabulation), qui
  ferait tout écrire dans `$TMP/measured-output.txt` (600). Écart : `outillage-smoke.md:182` « never an argument of a process »
  alors que la valeur factice est un argument d'`env`.
### Frontend
- **CR18-2 ≈ Sp18-12** (haute) — `LocaleSwitcher.vue:27-28` : R213 remplace le seul premier segment (écart déclaré au commit, non
  validé au plan) ; sur `/frogs` (404 sans préfixe) le bouton EN pousse le même chemin, vue-i18n reste `fr` : sans effet.
- **CR18-3 = C18-9** (moyenne) — `supportedLocaleOf` sur la réponse d'écriture lève après l'écriture serveur ; un nouvel essai crée
  un doublon (Q1 (A) accepté ; le doublon n'est écrit nulle part).
- **CR18-4 = St18-11** (moyenne) — `HttpAdminAboutMeCardRepository.ts:101` `category as AdminAboutMeCardCategory` sous la langue
  validée : forme voisine de R214 (hors périmètre explicite de D2).
- **CR18-6 = St18-14** (haute) — `AdminQualityPage.vue:78, 91` : l'union des champs réécrite à la main alors que le `satisfies` la
  déclare → `type QualityPrincipleField = keyof typeof QUALITY_PRINCIPLE_FIELD_OPTIONS` ; les membres recopiés des deux sources
  sont justifiés par leur docblock (contrôle des membres en trop).
- **CR18-7** (moyenne) — R216/R216b retirent des commentaires explicatifs (pourquoi la prose n'entre pas dans l'identité) : la
  raison n'est plus écrite aux sites qui la changeraient (`~/.claude/CLAUDE.md` « commentaires explicatifs et utiles ») ; elle
  reste dans les docblocks sources (`useAdminTranslation.ts:67`, `useTranslatedForm.ts:33`), choix de R216b.
- **CR18-8** (moyenne) — `noLocaleCast.spec.ts:37` ne voit que `as Locale` : `<Locale>x`, `as 'fr' | 'en'`, `as typeof
  DEFAULT_LOCALE`, `as (typeof SUPPORTED_LOCALES)[number]` passent.
- **CR18-10 ≈ St18-25** (moyenne) — `renderedMessage.ts` : une instance vue-i18n complète par appel ; `key: string` non typé (une
  clé renommée ne casse rien) ; `plural === undefined` non Yoda.
- **St18-12** (haute) — `currentLocale` désigne la langue d'interface dans dix fichiers et la langue de route ailleurs
  (`AppFooter`, `ContactPage`, `LocaleSwitcher`, `LoginPage`, `SetPasswordPage`, où `interfaceLocale` est une `function`).
- **St18-15** (moyenne) — `qualityEntrySection.ts` : `entryField`/`EntryFieldOptions` à côté d'`entryFieldsOf`/`QualityFieldOptions`.
- **St18-18** (moyenne) — `noLocaleCast.spec.ts` porte deux gardes sous un nom qui n'en dit qu'une ; `REMAINING_LOCALE_CASTS` vide
  pour toujours (`toEqual([])`) ; blocs `surplus`/`missing` ×2.
- **St18-24** (haute) — cinq façons de lire le catalogue dans les specs (`fr.x.y`, `renderedMessage`, `createAppI18n().global.t`
  en ligne — `OrderHandle.spec.ts:18`, `AssistantPage.spec.ts:221` —, les fonctions d'`adminButtonTexts.ts`, constantes `_FR/_EN`
  contre fonction par langue) ; `AppHeader.spec` mêle `MENU` et `fr.common` ; `OAuthConsentPage.spec` lit `SWITCH_ACCOUNT_FR`.
- **St18-26** (moyenne) — `accessRefusalTexts.ts` : paires FR/EN à docblocks qui redisent le nom → `accessRefusalTexts(locale)` ;
  `ORDER_TEXTS` redit ses clés ; `entryFormTexts`/`entryTitleTexts` Middle Man ; `AdminEntrySection` inclut `watch`.
- **St18-27** (haute) — `OAuthConsentPage.spec.ts:91` `withoutRouteLocale` change la route **et** pose `de`.
- **St18-30** (haute) — `useCurrentLocale.spec.ts:13` `currentLocaleOf` sans type de retour.
- **Sp18-2** (haute) — Review Focus 3 partiel : aucun cas `setUnsupportedLocale` pour `AppHeader`, `AppFooter`, `LocaleSwitcher`,
  `ContactPage`, `LoginPage`, `SetPasswordPage`, ni les 8 composables de contenu (6 specs en posent une).
- **Sp18-3** (moyenne) — Review Focus 2 : aucune page de contenu d'admin montée avec une liste `locale: 'de'` (seuls les dépôts).
- **Sp18-16** (moyenne) — R221 brouille le sens : « Un seul composable pour chaque page ordonnée » (`useUnsavedOrderGuard.ts:11`),
  « écrits une fois pour chaque formulaire par entrée » (six specs) lisent « un par page ».
### Outillage et backend
- **St18-5** (moyenne) — docblocks à décompte : `useCurrentLocale.ts:9`, `accessRefusalTexts.ts:6`, `accountMenuTexts.ts:7, 11`,
  `adminButtonTexts.ts:17, 46`, `smoke-cases.sh:335`, `smoke-http.sh:378` (« onze endroits », redit la règle) ; appelants nommés :
  `useCurrentLocale.ts:12, 14`, `accessRefusalTexts.ts:24`, `qualityEntrySection.ts:134`, `AdminQualityEntrySection.vue:52`,
  `buttonLabelled.ts:8`, `ChildPhpProcess.php:88`, les neuf `unsupportedLocale()`.
- **St18-6** (moyenne) — `backend/tests/Support/PhpSources.php:124` : « '((' passerait pour '(' », faux depuis R220b.
- **St18-7** (haute) — `frontend-session-bandeau.md:68-69` énumère les lecteurs de `routeLocale`, incomplet (`ContactPage`,
  `LoginPage`, `SetPasswordPage`) → retirer l'énumération.
- **St18-21** (haute) — le texte du refus `writes` recopié à six endroits (code, trois docblocks, règle, `WRITE_REFUSAL`) ; **St18-22**
  (moyenne) `WRITE_REFUSAL` global sans préfixe dans `smoke-cases.sh:336`.
- **St18-28** (moyenne) — `PhpSourcesTest.php:47` commentaire périmé (`array_any`), deux cas qui prouvent la même chose, indice
  magique `$tokens[\count($tokens) - 3]`.
- **Sp18-13** (haute) — R220b superpose tous les `<env>`, pas seulement ceux à `force` (le plan : « pour chaque nom que phpunit
  force ») ; non validé par bddf40b9 (qui ne valide que `Registry`) ; **C18-7** (info) : un `phpunit.xml` local avec `<env>` sur
  une clé ferait gagner le shell chez le fils.
- **Sp18-4** (moyenne) — R212 / St17-23 : la préparation des blocs vide / témoin / `CDPATH` / racine reste recopiée dans les smokes
  (`smoke-login-throttling.test.sh:218-244`, `smoke-mcp.test.sh:355-382`, `smoke-dead-session-cookie`) ; ni le commit ni le bilan
  ne le disent.
### Documentation et traçabilité
- **Sp18-1** (haute) — 14 numéros de tâche seuls écrits par R213–R215 (`useCurrentLocale.ts:6, 12`, `supportedLocale.ts:4`, neuf
  dépôts « #748, R214, Q1 (A) », `qualityEntrySection.ts:15, 20`) : R216 ne les a pas traités (forme voisine de St17-14) = question
  (a) de R221 ; **St18-32** : « Q1 (A) » sans série est ambigu.
- **St18-3 = Sp18-9** (haute) — dix lignes de code > 140 écrites par la série : `ChildProcessHarnessTest.php:274, 279, 283` ;
  `OAuthConsentPage.spec.ts:271, 297, 702` ; `AdminAboutPage.spec.ts:1148, 1155` ; `OrderHandle.spec.ts:18` ;
  `adminButtonTexts.ts:14` = question (d) de R221.
- **St18-4 = Sp18-10** (haute) — le corps de ddf40ed8 porte une ligne de 151 caractères qui n'est pas une ligne rouge (non
  réécrivable) = question (g).
- **St18-19** (info/faible) — récits au passé : `noLocaleCast.spec.ts:16-17` et le nom du test l. 72 (« vide depuis R214 »),
  `useCurrentLocale.ts:9-12`, `ChildPhpProcess.php` (« répondait 400 ») = question (b).
- **Sp18-20** (haute) — bilan de R221 : (i) « 79 lignes gardées » vrai à f47804d8, 68 à HEAD ; (ii) le trailer Opus de R220b n'est
  pas une anomalie (9 Opus, 16 Sonnet : modèle de l'agent, et le fil principal) ; (iii) 12 lignes de table + 1 de traçabilité, non
  13 de table ; (iv) les lignes courtes d'`outillage-smoke.md` : 11 après le recollage, non 12 ; (v) l'absence de shellcheck /
  `check-claude-rules` / lint / build au corps relevée pour R210, R220, R220b mais pas pour R211, R212, R213–R218 ; (vi) l'écart
  « sortie 2 au lieu d'un `fail` nommé » de R212 non cité.
- **Sp18-5** (haute) — R211 Step 3 : la longueur des fonctions (`awk`) absente du corps de a4b4192a (vrai à HEAD : 23 au plus).
- **Sp18-8** (moyenne) — R220 : le filet du corps vient d'un `phpunit` direct sans code de sortie et tait les cinq échecs ; le bilan
  dit « conforme, avec une réserve ».

## Info
- **CR18-9** (basse, altitude) — la langue de vue-i18n n'est écrite que par `createAppI18n` et `syncLocale` : l'invariant tiendrait
  à l'écriture, plutôt qu'un accesseur à 27 sites et une garde par regex.
- **C18-5** (moyenne) — `-D` vers le chemin d'un corps supprimé passe (`-ef` exige l'existence) et ce chemin reste admis pour
  `--data-binary @` (égalité de chaîne) ; aucun smoke ne retire son corps avant `smoke_cleanup`.
- **C18-6** (haute) — `-D "<fichier>/"`, `-D "<dossier>/neuf/"` passent le jugement ; curl sort en 23, fichier intact.
- **C18-8** (haute) — `check_hollow_basic_auth_values` imprime `$(cat "$CASE_CALLS")` sans filtre (repère généré) ;
  `values_found_in` : une valeur à plusieurs lignes est « trouvée » par un seul fragment (aucune recherche positive ne le fait).
- **St18-13** — `routeLocale(route, interfaceLocale.value)` ×6, avalisé par le choix 1 ; piste `useRouteLocale()`.
- **St18-16** — `qualityEntrySection.ts:173` redit la prose ; `:49` « et de la section » porte sur un autre fichier.
- **St18-23** — `check_hollow_basic_auth_values` dépend d'un global `rc` posé par `CASE_RUNNER`.
- **St18-29** — `isAnyChar` valide deux fois ; `$serverBefore` se sert de `null` comme sentinelle.
- **St18-31** — `vueCompilerAlignment.spec.ts` : `toContain('devDependencies')` retiré (redondant ; demandé par Sp17-20).
- **St18-33** — `smoke-http.test.sh` : libellés `$'\nabc'`/`$'abc\n'` identiques ; `check_option_lists` compte deux fois.
- **St18-34** — `backend-outillage.md` à 46 050 octets (92 % du plafond).
- **Sp18-6, Sp18-7** — R211 Step 6 : la sortie du premier `grep` non rendue (vide à HEAD) ; R220 : « cache vidé » absent du corps.
- **Sp18-11** — `brief-commun-agents.md:58-59` « `security-user.md` en fait 47 914 » : 47 922.
- **Sp18-14** — R217 inverse aussi trois comparaisons à `null` de `StackPage.vue` (déclaré, hors liste du plan).
- **Sp18-15** — R221 : « neuf copies » d'`orderProblems.ts` est un nombre du domaine plutôt qu'un décompte d'appelants.
- **Sp18-18** — corps faux : b2fae12b « la source n'a plus aucun `as Locale` » (un docblock de `useCurrentLocale.ts:9` en garde
  un) ; f149381e « les sept composables de contenu » (huit).
- **Sp18-19** — corps de 2f9b6391 : le détail EN (« 51 FR + 20 EN déjà gardées », « +1 EN pour les deux premières ») est faux
  (18 EN, trois fichiers à une ligne EN) ; le total, 23 EN, est juste.
- Les deux lignes courtes du brief (l. 16, 23, recollage de R209) = question (e) ; la limite de curl de la règle mesurée par R221 sur
  l'image 8.5.0 (trois mesures sur quatre) = question (f).

## Questions de R221 (bilan, l. ~15330)
(a) Sp18-1 ; (b) St18-19 ; (c) St18-26 ; (d) St18-3 ; (e) brief l. 16/23 ; (f) curl 8.5.0 ; (g) St18-4.

## Vérifié sain
- Mutations : chaque mutation annoncée est au corps de son commit (méthode, commande, rouge, `restauré :`), aucune non attestée ;
  la restauration de R209 (`git diff --exit-code` / `cmp`) respectée.
- Nombres du bilan justes à HEAD : front 2 663 / 185, back 5 798, 26 tests hors ligne à 0 (1 115 `ok`), shellcheck à 0,
  `check-claude-rules` à 0 ; tailles des règles exactes à chaque commit ; les 73 constats de la 17e dans la traçabilité.
- `smoke-http.sh` : refusés à bon droit `..`, le dossier (avec ou sans `/`), `dir/./…`, `dir//x`, un lien symbolique ou physique
  vers le `-K` ou le corps ; admis `a..b`, `...`, `-` ; une option sans valeur en fin de `SMOKE_CURL_OPTS` refusée ; messages au
  rang ; aucune option n'ajoute de lecture. La garde des vidages refuse ~35 formes rejouées (`compgen -e`, `typeset -x`,
  `/proc/self/env*`, `ENVIRON`, `os.environ`, `ps -eo …`) ; `perl %ENV` et `envi""ron` restent des limites écrites.
- Frontend : plus aucun `as Locale` dans `src` (hors un commentaire) ; chaque adresse construite reçoit une `Locale` jugée ;
  `:locale(fr|en)` borne le routeur ; aucun appel d'API ni `credentials` nouveau ; l'invariant n° 9 tient ; records `satisfies`,
  une seule assertion (`fieldRecord`), `NoInfer` testé ; `EntryFormPlacement` partagé ; plus de `'de' as never`.
- PHP : `strict_types`, tout typé, exception nommée (`NotASingleCharacterException::forArgument`), imports triés, natives
  qualifiées ; `Registry` (`@internal`) accepté par l'auteur.
- Commits : `Claude-Session` partout, aucun marqueur `[skip ci]` ; aucun secret littéral ajouté ; aucune case cochée.

## Portfolio (à proposer, rien rédigé)
- « Incidents » : une variable posée par le conteneur de dev (`APP_PUBLIC_API_BASE_URL`, #647) a rendu rouges cinq tests à
  processus fils pendant dix jours, masquée par un conteneur créé avant elle ; la CI, sans Compose, ne pouvait pas la voir (R220b).
- « Études de cas » : une garde par motifs contre `ps`, contournée par une option collée à des chiffres (C18-1) — la limite d'une
  liste face à un analyseur d'options BSD.
