# Dix-neuvième relecture de #748 — diff cd412c1a...f5645c36 (R222 à R234), 2026-11-04

Quatre relectures : `/code-review medium` (CR19-*), audit sécurité (C19-*), axe Standards (St19-*), axe Spec (Sp19-*).
**Aucun critique ni haut.** 48 constats (9 + 8 + 18 + 13). Moyens : **CR19-1** (la garde des casts ne voit pas `as Ref<Locale>`),
**CR19-3** (`localStorage.setItem` sans `try/catch` à chaque navigation), **St19-1** (docblocks qui comptent ou nomment leurs
appelants), **St19-4** (le message de langue recopié en littéral dans neuf specs), **St19-6** (moyen-faible : `button()` du spec du
sélecteur rend `undefined`, une assertion passe si le bouton manque), **Sp19-1** (`supportedLocale.ts:10` porte encore
« R214, D2 » sans série, que R229 devait corriger). L'audit : 0 moyen, 5 faibles, 3 info.
Avant les relectures, le fil principal a rejoué sur conteneurs redémarrés les cinq cibles `make`, les 26 tests hors ligne et
`check-claude-rules` : tous à 0 (front 2 698 tests / 187 fichiers, back 5 800, `smoke-http` 559 `ok`).

## Boucle de corrections identifiée (arrêt de l'enchaînement automatique)

Consigne de l'auteur du 2026-11-04 : enchaîner les séries à la recommandation jusqu'au propre, ou s'arrêter sur une boucle
clairement identifiée. Constats par relecture depuis la dixième : 49, 47, 51, 98, 79, 88, 89, 73, 74, **48**. Aucun critique ni
haut depuis longtemps ; le nombre ne descend pas vers zéro, il oscille. Deux classes s'alimentent d'elles-mêmes :

1. **La garde lexicale contre les vidages d'environnement** (C15-4, C16-2, C17-1, C17-2, C17-7, C18-1, C18-2, C18-4, puis C19-1
   à C19-4) : chaque série ferme les formes listées et la suivante en mesure de nouvelles (option par expansion `$(echo e)`, mot
   recollé `ps""`, glob `/proc/self/envi*`, `jq "$ENV"`). Une garde par motifs ne peut pas être complète face au shell : la liste
   ne se ferme jamais.
2. **Les conventions d'écriture** (décompte ou appelant dans un docblock, numéro de tâche sans série, récit au passé, ligne
   > 140) : chaque série écrit du code neuf que la relecture suivante relève (St19-1, St19-2, St19-3, Sp19-1 à Sp19-3, Sp19-12).
   Elles ne se tiennent qu'à la relecture, rien ne les vérifie mécaniquement.

**Recommandation pour sortir de la boucle** (à trancher par l'auteur) : une dernière série courte qui corrige les moyens et les
faibles à risque réel, change d'approche sur les deux classes, puis passe aux tâches 11 et 12 sans nouvelle relecture complète :
- garde des vidages : la limite écrite une fois en termes génériques (« garde lexicale : toute forme non écrite en clair —
  expansion, glob, mot recollé, interprète — est hors de portée ; elle protège de l'oubli, pas du contournement délibéré ») ;
  seule la mesure, qui **exécute**, passe à une liste d'autorisation de la ligne entière (C19-2) ; plus d'énumération de formes ;
- conventions : soit une garde mécanique (numéro `R[0-9]+` sans série dans `src/`, lignes > 140 ajoutées), soit leur abandon
  comme critère de relecture au-delà du diff de la série ;
- critère « tout jusqu'à zéro » remplacé par « zéro critique, haut et moyen ; faibles à risque réel corrigés ; le reste écrit ».

## Moyens

- **CR19-1** (moyen, haute) `localeSourceGuards.spec.ts:42` — `LOCALE_CASTS` ne voit pas `as Ref<Locale>`, `as ComputedRef<Locale>`,
  `as Array<Locale>`, `as readonly Locale[]` ; `frontend.md` présente la liste comme complète. Reco : une forme « `as` suivi d'un
  type qui contient `Locale` », cas du détecteur pour chacune.
- **CR19-3** (moyen, moyenne) `interfaceLocale.ts:17` — `localStorage.setItem` sans `try/catch` ; stockage bloqué : chaque
  navigation avorte dans `beforeEach`. Reco : `try/catch` comme `baseAccessExpiry.ts`, test sous un `setItem` qui lève.
- **St19-1** (moyen, haute) décomptes / appelants nommés : `interfaceLocale.ts:10-11`, `seo.ts:36-37`, `supportedLocale.ts:5`,
  `supportedLocale.spec.ts:31`, `smoke-cases.sh:46-48`, le commentaire de `run_nominal` ×4, `ForcedTestValuesProbeTest.php:24/34/81`.
- **St19-4** (moyen, haute) `'Unsupported locale in response'` en littéral dans les neuf `Http*Repository.spec.ts` (écart 4 de
  R229). Reco : les specs lisent `UNSUPPORTED_LOCALE_MESSAGE`.
- **St19-6** (moyen-faible, haute) `LocaleSwitcher.spec.ts:36` — `button()` rend `undefined`, `?.` : une assertion
  `toBeUndefined()` passe si le bouton manque. Reco : `buttonLabelled`.
- **Sp19-1** (moyen, haute) `supportedLocale.ts:10` « (#748, R214, D2) » sans série, site de Sp18-1 attribué à R229 ; le bilan
  dit R229 conforme (faux sur ce point).

## Faibles

### Sécurité
- **C19-1** (haute, mesuré) `smoke-http.test.sh:1493/1513` — `ps -p $$ $(echo e)`, `$'\x65'`, `o=e; … $o`, `ps"" e` passent et
  impriment. Classe 1 de la boucle : reco = limite générique.
- **C19-2** (haute) `ps_bounded_to_self:1618-1647` ne juge que le mot `ps` : `p""s e -A`, `cat /proc/1/environ` seraient
  exécutés s'ils entraient dans `dump_printing` (rien sur disque). Reco : liste d'autorisation de la ligne entière.
- **C19-3** (haute, mesuré, antérieur) `pat_env:1539` — `jq -n "$ENV"`, `process.env`, `ruby -e 'p ENV'` passent ; la limite
  dit faux pour jq (lancé par `audit-prod.sh:383`). Reco : refuser `\$ENV\b` et `process\.env`, la phrase corrigée.
- **C19-4** (haute, mesuré, antérieur) `cat /proc/self/envi*`, `e?viron`, `[e]nviron` passent. Classe 1 : limite générique.
- **C19-5** (moyenne, non mesuré, latent) `MAILER_DSN` ni `<server>` ni `<env force>` : un DSN réel exporté ferait partir les
  mails de test. Reco : forcé à `null://null`, ajouté à la sonde ; revoir les autres DSN sortants.
- **CR19-9** (basse) `smoke-http.sh:464` — `-f` suit les liens, jugé avant l'appel : corps recréé en lien vers le `-K`. Reco :
  limite écrite.
### Frontend
- **CR19-2** (faible à moyen, moyenne) `LocaleSwitcher.vue:37` — la query gardée garde `redirect=/fr/admin` : le choix EN est
  écrasé après la connexion. Reco : réécrire le préfixe d'un `redirect` interne vers la langue choisie.
- **CR19-7 = St19-5** (haute) `'Unknown category in response'` littéral, autre façon que la langue. Reco : constante partagée.
- **CR19-8** (haute) `router/index.ts:374` — `beforeEach` et `guardLocale` recodent `routeLocale()`.
- **St19-15** les cinq formes de cast écrites trois fois (constante, en-tête, règle).
- **St19-11** lanceurs `witness`/`cdpath`/`creuse` (langues mêlées) ; `REFUSAL`/`REFUSAL_EN`, `MENU`/`MENU_EN` recréent des paires.
- **Sp19-5** `entryFormLockTexts.ts:31` lit un message à paramètres par `.split('{locale}')` (choix 11 : `renderedMessage`).
- **Sp19-10** `qualityEntrySection.ts:50` « la seule assertion » alors que deux `as const` suivent.
- **Sp19-11** en-tête de `adminTranslationTexts.ts:5-12` périmé depuis R232.
### Outillage et backend
- **CR19-4 = St19-7** `ForcedTestValuesProbeTest::NAMES` recopie la liste ; un nom sans `<env force>` est admis. Reco : dériver
  des noms à `<server>` + `<env force>` de la dist.
- **CR19-5** `ChildPhpProcess.php:104` — un `<env>` sans force n'est plus superposé : le fils peut différer du parent, contraire
  au docblock. Reco : docblock juste (ou superposer quand `$_ENV` le porte).
- **CR19-6** le `phpunit` imbriqué écrit `.phpunit.cache` pendant le run externe. Reco : `--do-not-cache-result` /
  `--cache-directory` temporaire.
- **St19-8** `fingerprints()` public statique d'un `TestCase` appelé par la fixture ; échec en 255, pas `SETUP_FAILED`.
- **St19-9** `ps_bounded_to_self` refait la découpe de `ps_lists_environment`. **St19-10** `skipped_with_output` jamais non vide.
- **Sp19-4** l'admission d'un jeton fait de chiffres seuls n'a aucun test (`ps -p 1` vert sans rien prouver) = question (a) de
  R234. Reco : `ps 1` dans `dump_allowed`.
### Documentation et traçabilité
- **St19-2 ≈ Sp19-2 ≈ Sp19-3** numéros sans série : D4/D2 (`frontend.md:143`, `LocaleSwitcher.vue:16`, `interfaceLocale.ts:11`,
  `LocaleSwitcher.spec.ts:14/104`, `smoke-http.test.sh:1650/1665/1843`), R227 ×4 et R229 ×2 dans `src/` (= question (f)).
- **St19-3** récits au passé : `LocaleSwitcher.vue:20`, `LocaleSwitcher.spec.ts:104`, `smoke-cases.sh:395`, « plus d'ici » ×3.
- **Sp19-6** R234 sort de son exception (un `paths:` ajouté, deux commentaires PHP) et laisse en question les six numéros de `src/`.

## Info
- C19-6 l'échec de la sonde imprime le sha256 de la valeur lue (adresse devinable confirmée) ; C19-7 `status` de
  `HttpAdminUserRepository.ts:13` non jugé ; C19-8 limites revérifiées (corps retiré écrit autrement : `-f` l'admet si curl l'a
  recréé ; le refus de catégorie étend le doublon C18-9).
- St19-12 à St19-18 : vocabulaire Qualité à moitié unifié, ordre des imports, lignes vides dans `toBe(…)` d'`OAuthConsentPage.spec`,
  `CATALOGUES` recopié ×2, tables `Record<string, …>`, `if` sans accolades (`renderedMessage.ts:10`), `'CP-Ghostotof — '` recopié.
- Sp19-7 écarts déclarés au corps (R223, R225, R226, R229) ; Sp19-8 corps de b6f0adba sans rouge d'avant, de 91ed87c7 sans
  `git status` et avec une ligne de 182 ; Sp19-9 la colonne « fils » de la sonde jamais rougie ; Sp19-12 31 noms de tests
  « (#748, R22x) » sans série ; Sp19-13 R222 « conforme » malgré un moignon resté.
- Écart de l'auditeur : une sonde `ps -o args= e -p $$` a affiché deux lignes d'un `env -i` (PATH, PWD, SHLVL, aucun secret)
  hors lecture par `grep -c`.

## Questions de R234 (bilan), prises à la recommandation
(a) `ps 1` dans `dump_allowed` ; (b) `lines_with_values` laissé (valeur aléatoire) ; (c) `_FR` monolingues laissés ; (d) script
du relevé corrigé hors dépôt ; (e) lignes > 140 antérieures déclarées hors périmètre ; (f) numéros seuls de `src/` complétés ;
(g) « chaque dépôt du backoffice » ; (h) trois docblocks qui redisent le membre retirés.

## Vérifié sain
Le sélecteur ne navigue qu'en interne (`redirect` jugé par `internalRedirectTarget`), le flux OAuth est intact ;
`ps_bounded_to_self` refuse `-p $$,1`, `--pid`, `-N`, un second `-p` ; la couche `force` ferme C18-7 ; les détails d'échec
porteurs d'un repère passent par `lines_without_values` ; 16 commits portent `Claude-Session`, aucun `[skip ci]`, aucun secret.
Fermés : C18-1, C18-2, C18-3, C18-4 (voisins rouverts C19-1, C19-1, C19-5, C19-2), C18-5, C18-7, C18-8 ; C18-6 et C18-9 ouverts
par décision ; C17-7 reste à la charge de l'auteur.

## Portfolio (à proposer, rien rédigé)
Études de cas : la limite d'une garde lexicale face au shell (neuf séries de contournements mesurés de la garde des vidages).
