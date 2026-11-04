# Vingtième relecture de #748 — diff 6a7c5b90...155a4d2e (R235 à R240), 2026-11-04

Quatre relectures du **seul diff de la vingtième série** (E4) : `/code-review medium` (CR20-*), audit sécurité (C20-*), axe
Standards (St20-*), axe Spec (Sp20-*). **Aucun critique ni haut.** 28 constats (10 + 6 + 7 + 5), contre 48 à la dix-neuvième.
Un seul classé moyen par son relecteur (CR20-1). L'audit : 0 moyen, 2 faibles, 4 info. L'axe Spec : aucune exigence manquante,
aucun dépassement, le bilan de R240 vrai sur tous les chiffres revérifiés.
Avant les relectures, le fil principal a rejoué sur conteneurs redémarrés les cinq cibles `make`, les 26 tests hors ligne et
`check-claude-rules` : tous à 0 (front 2 708 tests / 188 fichiers, back 5 802, `smoke-http` 574 `ok`).

## Tri selon E1 (« zéro critique, haut et moyen ; faibles à risque réel corrigés ; le reste écrit »)

Corrigés par la micro-série E4 (R241, R242, plus bas) :
- **CR20-1** (moyen selon son relecteur ; tenu ici pour faible à moyen) `localeSourceGuards.spec.ts:35` — le motif générique de
  R235 rougit à tort sur `as const satisfies Record<Locale, …>` (forme déjà employée sous `tests/support/`), sur un alias
  d'import `import { X as Y, type Locale }`. Un faux rouge, non un défaut caché ; corrigé parce que la forme est idiomatique et la
  correction courte.
- **St20-3 = CR20-3** (tenu pour faible à risque réel : un test qui ne prouve pas ce qu'il dit) — le cas du cache compare
  « absent » à « absent » si le fichier `test-run-history` n'existe pas ou n'est pas celui du run chargé : vert sans preuve.
- **C20-1 = CR20-4** (faible, risque réel : une clé de test future) — la sonde ne lit que les `<server force>` ; un nom posé
  sans `force` ou par `<env force>` seul (`APP_PUBLIC_API_BASE_URL`) n'est ni sondé ni refusé.
- **CR20-6** (faible) la branche « posé par plusieurs `<server>` » d'`envForceDefects()` n'a aucun cas.
- **C20-2** (faible, basse) le commentaire « `DATABASE_URL` est la base de test » tait qu'une valeur exportée gagne.

Écrits, non corrigés (raison) :
- **CR20-2** second phpunit imbriqué : un coût de quelques secondes ; R242 peut le fusionner si la correction de St20-3 le permet.
- **CR20-5, St20-1** la dist relue plusieurs fois : test, coût négligeable, cohérence seulement.
- **CR20-7 ≈ St20-6** `try/catch` de `localStorage` recopié (4 modules) : réutilisation, aucun comportement faux ; un helper
  partagé est un refactoring hors E1.
- **CR20-8** `/FR/about` (casse) : antérieur à la série ; seule une adresse tapée en majuscules y mène, la langue retombe
  sur celle de l'interface sans rien casser.
- **CR20-9 = C20-4** `process["env"]`, `%ENV`, `getenv()` : couverts par la limite E2 ; aucun script gardé n'emploie node, ruby,
  perl ni php (vérifié par l'audit).
- **CR20-10 = St20-5** docblock de `QualityTraitField` retiré par (h) de R234 : E3.
- **St20-2** nom du cas du détecteur ; **St20-4** une forme mesurée touche quatre endroits ; **St20-6** `frontend.md` ne dit pas
  la tolérance au stockage bloqué (R241 l'ajoute si la ligne voisine est réécrite, sinon écrit) ; **St20-7** références de série
  dans les docblocks : E3.
- **C20-3** XPath par concaténation (fichier de confiance) ; **C20-5** lignes admises hors `dump_printing`, toutes bornées
  (sain) ; **C20-6** sortie du phpunit imbriqué recopiée à l'échec, sans environnement.
- **Sp20-1** `supportedLocale.ts:10` « R214 » ; **Sp20-2** `awk` absent du corps de R237, nombre d'avant absent de R239 (mesurés
  sains) ; **Sp20-3** mutation (d) et option de PHPUnit, déclarées ; **Sp20-4** deux écarts de R235 lisibles au diff seul ;
  **Sp20-5** `as Ref<` puis `Locale` à la ligne : hors forme écrite.
- Questions rendues par R240 : `ApiPlatformDeprecationHandoverTest` (phpunit imbriqué sans les deux options), sept « D3 » seuls
  de `smoke-http.test.sh`, « Deux appelants seulement » de `sessionState.ts:96`, deux corps de commit « vingtième » : écrits (E1,
  E3, commits non réécrivables).

## Micro-série E4 (tâches R241, R242)

Après elle : vérification complète sur conteneurs redémarrés, **sans nouvelle relecture** (E4), puis tâches 11 et 12.

### R241 : Frontend — le motif générique des casts sans faux rouge (CR20-1)
- Cas du détecteur, rouges d'abord : faux pour `as const satisfies Record<Locale, string>`, `as const satisfies readonly Locale[]`,
  `import { SUPPORTED_LOCALES as ALL, type Locale } from '…'` ; vrais toujours pour les formes de R235.
- Correction : le premier motif de `LOCALE_CASTS` exclut `as const` et un `as` d'alias d'import (dans des accolades d'`import`
  ou d'`export`) ; rien d'autre. Relevé de `src/` toujours `[]`.
- Mutation : le motif remis à sa forme de R235 → les faux cas rougissent.
- `frontend.md` : une demi-phrase si la liste des exclusions doit s'y lire ; la tolérance au stockage bloqué (St20-6) dans la même
  phrase si elle est voisine. Taille avant/après au corps.
- Sonnet, effort high.

### R242 : Backend, *sensible* — la sonde voit chaque nom de la dist, le cas du cache prouve (C20-1, CR20-4, St20-3, CR20-3, CR20-6, C20-2)
- La sonde relève **tous** les noms `<server>` et `<env>` de la dist. Ceux qui ne sont pas forcés des deux côtés figurent dans
  une liste d'autorisation justifiée (`SHELL_VERBOSITY`, `MCP_D8_AUTHOR_NAME`, `APP_PUBLIC_API_BASE_URL`, chacun avec sa raison) ;
  un nom hors liste et non forcé est un échec qui le nomme, sans valeur. Cas sur une configuration XML écrite dans le test.
- Le cas du cache échoue si le fichier d'historique du run chargé n'existe pas avant la relance (chemin lu de la configuration
  chargée, `Registry`, non de la dist) ; mutation : le nom du fichier changé → rouge sur l'absence, non vert.
- Un cas du détecteur pour « posé par plusieurs `<server>` » ; mutation : la condition retirée → rouge.
- Le commentaire de `DATABASE_URL` dans `phpunit.dist.xml` dit la limite (une valeur exportée gagne ; le garde-fou juge le nom
  de la base, pas l'hôte).
- Si la correction du cas du cache le permet sans perte, l'empreinte se prend autour du premier phpunit imbriqué (CR20-2).
- Opus, effort high.

## Micro-série faite (2026-11-04)
R241 (97754821) et R242 (5eac53f5) faites, leurs écarts déclarés au corps de leur commit : R242 fait rougir le cas du cache
sur l'absence du **répertoire** de cache du run chargé (le fichier d'historique n'existe pas en CI pendant la suite), fige la
forme de chaque nom de la liste d'autorisation et refuse une entrée devenue sans objet, corrige le commentaire de
`DATABASE_URL` (la CI l'exporte), fusionne les deux cas du test du shell (CR20-2). Limite écrite : le retrait de
`--cache-directory` n'est pas mesurable en local (même répertoire). Vérification complète sur conteneurs redémarrés et cache de
résultats PHPUnit supprimé (comme un checkout neuf de CI) : cinq cibles `make`, 26 tests hors ligne, `check-claude-rules`, tous
à 0 (front 2 708 / 188 fichiers, back 5 802). Critère E1 tenu : aucun critique, haut ni moyen ouvert. Suite : tâches 11 et 12.
