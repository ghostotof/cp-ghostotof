# Treizième relecture de #748 — diff 4fa9d3da...ab8c24fd (R137 à R151, R141b, R148b), 2026-10-31

Quatre relectures : `/code-review medium` (CR13-*), audit sécurité (C13-*), axe Standards (St13-*), axe Spec (Sp13-*).
**Aucun critique ni haut.** Moyens : CR13-1 (régression, confirmée par le fil principal sur le code), St13-15, St13-34 =
Sp13-1 = C13-4, St13-35, St13-39 = C13-3, St13-60. Avant les relectures, le fil principal a rejoué sur conteneurs
redémarrés les cinq cibles `make`, les 26 tests hors ligne et `check-claude-rules` : tous à 0 (front 2510, back 5624).
L'audit a rejoué une preuve de concept locale (écouteur sur 127.0.0.1, identifiant factice) : C13-1 reproduit, contre-preuve
`--proto =https` sans fuite ; fichiers de rejeu dans le scratchpad de la session (`c13/`).

## Décisions de l'utilisateur
Prises le 2026-10-31 : **les huit recommandations**.
- Q1 → `renewForm()` en tête de `startCreateVersion` et `startEdit` (huit formulaires) + un cas partagé de la régression.
- Q2 → `--proto =https` avec chaque `-K` (`smoke_fetch`, `CURL_OPTS` de l'audit) ; refus des options par préfixe dans `"$@"`
  et `SMOKE_CURL_OPTS` ; un argument refusé s'il **commence** par un schéma autre que `https://` (plus de `*://*`).
- Q3 → étendre la garde des vidages, exemption réduite à la seule ligne de lecture, faux positif `${env}` corrigé, **et**
  écrire que la garde vise les fautes de bonne foi.
- Q4 → le bloc de traduction porté dans `useTranslatedForm` (`errorText`, `hasProse`, `proposeFrom(...)`), tâche dédiée.
- Q5 → une règle dédiée à l'outillage des smokes et de l'audit, l'ancienne puce gardant un renvoi.
- Q6 → `pendingProposal` des réglages remis à `null` à tout changement de langue qui ne mène pas à la cible et sur un échec
  de la lecture.
- Q7 → l'ordre `discardIfDeleted` puis `remove()` gardé, limite écrite.
- Q8 → raccourcis de R150 acceptés et consignés au plan ; rétablir ce qui a perdu du sens (« désactivé », « palier de
  confiance », la référence R67 si elle tient sous 140).
- Tout le reste se corrige (critère « tout jusqu'à zéro ») ; CR13-8 écarté (raison dans « Info »).

Questions posées :
1. **CR13-1** : `startCreateVersion` (et `startEdit`) remettent le formulaire sans `renewForm()`. Reco : `renewForm()` en
   tête des deux, dans les huit formulaires, + un cas partagé (second « Créer la version XX » de la même ligne pendant une
   traduction). Option : désactiver « Modifier » / « Créer la version » pendant `isTranslating`.
2. **Basic Auth de l'outillage** (C13-1, C13-2, C13-3, C13-6, C13-7, CR13-2, CR13-4, St13-39–41, Sp13-2) : reco
   `--proto =https` posé avec chaque `-K` (`smoke_fetch` et `CURL_OPTS` d'`audit-prod.sh`) — ferme l'URL sans schéma, `--url`,
   un second `-K`, `--expand-url` ; refus des options par **préfixe** (curl 8.5.0 du runner accepte les abréviations) dans
   `"$@"` **et** `SMOKE_CURL_OPTS` : grappes courtes portant `L` ou `K` (`-[!-]*[LK]*`), `--loc*`, `--ur*`, `--pro*` sauf
   `--progress-bar`, `--conf*`, `--var*`, `--exp*` ; le refus « contient `://` » des valeurs (faux positifs sur `-H Origin`, un
   corps JSON) remplacé par le refus d'un argument qui **commence** par un schéma autre que `https://`.
3. **Garde des vidages d'environnement** (St13-34 = Sp13-1 = C13-4, St13-35, St13-46) : reco l'étendre (`declare`, `typeset`,
   `local`, `export`, `readonly` seuls ou avec `-x`, `compgen -v/-e`, `set` suivi d'une redirection, `eval`, nameref `-n`,
   `ps … e`, `environ` seul), réduire l'exemption du lecteur à sa seule ligne de lecture par nom, corriger le faux positif
   `${env}`, **et** écrire que la garde vise les fautes de bonne foi, pas un contournement délibéré (la liste par motifs ne sera
   jamais complète). Option : écrire seulement la limite.
4. **St13-15** (moyen) : le bloc de traduction reste recopié dans les huit formulaires (`translationErrorText`, `hasProse…`,
   `handleTranslate`, `handleDelete` + `discardIfDeleted`). Reco : les porter dans `useTranslatedForm` (`errorText`,
   `hasProse`, `proposeFrom(...)`) dans une tâche dédiée. Option : sans suite (écrire la raison).
5. **St13-60 + St13-53** : la plomberie shell (≈ 35 phrases) vit dans la puce « Nine nginx rate-limit zones » de
   `deploiement-nginx.md`, dont les `paths:` ne couvrent ni `smoke-dead-session-cookie.sh` ni son test. Reco : une règle
   dédiée (p. ex. `outillage-smoke.md`, `paths:` = `tools/lib/**`, `tools/smoke-*.sh`, `tools/audit-prod.sh`, leurs tests),
   l'ancienne puce gardant un renvoi.
6. **C13-10** (antérieur à la série) : `pendingProposal` des réglages reste posé si la lecture de la cible échoue ou si la
   personne revient à l'autre langue avant `isLoaded` ; il est appliqué au prochain chargement de la cible, bien plus tard.
   Reco : le remettre à `null` à tout changement de `props.locale` qui n'est pas la cible, et sur un échec de la lecture.
7. **CR13-7** : `discardIfDeleted` avant `await remove()` périme la traduction même si la suppression échoue. Reco : garder
   l'ordre et écrire la limite (périmer après le succès laisserait une réponse arrivée pendant le DELETE se poser sur une entrée
   supprimée). Option : périmer après le succès seulement.
8. **Sp13-6** : R150 a raccourci 19 noms de tests, contre la lettre du plan mais sur consigne du brief du fil principal.
   Reco : accepté, consigné au plan.

## Moyens
- **CR13-1** `AdminIncidentsPage.vue:235` (et les sept autres formulaires) : `startCreateVersion` vide la prose sans
  `renewForm()` ; un second « Créer la version XX » de la même ligne et de la même langue pendant une traduction garde
  l'identité `{null, locale, groupe, génération}` : la réponse se pose sur le formulaire vidé (la régression que R139 fermait,
  par un autre geste). `startEdit` de la même entrée : même identité. Confirmé sur le code par le fil principal (l'audit ne
  l'a pas vu : son grep portait sur les remises à zéro). Moyenne / moyenne-haute.
- **St13-15** Duplicated Code : chacun des huit formulaires recopie la déstructuration, le prédicat `isRefused`, `identity`,
  `hasProseToTranslate`, `translationErrorText` (×9), `handleTranslate` (7 étapes ×8), `handleDelete` + `discardIfDeleted`
  (×9), `renewForm()` + commentaire, le bloc `TranslateEntryButton` + `TranslationReadonlyHint`. Moyenne / haute.
- **St13-34 = Sp13-1 = C13-4** la garde des vidages (`smoke-http.test.sh:814-846`) ne voit pas `declare`/`typeset`/`local`/
  `export`/`readonly` seuls, `declare -x`, `compgen -v/-e`, `set 2>/dev/null`, `eval "v=\$$n"`, `declare -n`/`local -n`,
  `ps eww -p $$`, `cd /proc/self && cat environ`, `"/proc"/self/environ`, `$p/environ` ; la règle affirme « no line lists the
  environment ». Moyenne (St) / faible (audit, Spec) ; haute (mesuré par les trois).
- **St13-35** l'exemption `in_reader` couvre tout le corps de `_smoke_write_auth_config` pour tous les motifs : un
  `declare -p` ou un `printenv` ajouté dans le lecteur passerait. Moyenne / haute (mesuré).
- **St13-39 = C13-3** le refus par noms exacts (R148b) suppose curl ≥ 8.6 ; le runner `ubuntu-latest` (24.04) a curl 8.5.0,
  qui acceptait les préfixes non ambigus (`--proto-r`, `--location-t`, `--locat`) : non mesurable hors ligne (conteneurs
  8.21/8.22, hôte 8.14.1). Moyenne / faible-moyenne.
- **St13-60** `deploiement-nginx.md` : ≈ 35 phrases de plomberie shell dans la puce « Nine nginx rate-limit zones »
  (Divergent Change). Moyenne / jugement.

## Faibles
- **C13-1** un argument sans schéma (`hôte/chemin`) dans `"$@"` de `smoke_fetch` : curl devine `http://` et y envoie la Basic
  Auth en clair (PoC rejouée) ; idem un second `-K`/`--config` contenant `url = "http://…"`, `--variable` + `--expand-url` ;
  `--proto-redir` ne borne que les redirections. Faible / haute.
- **C13-2 = CR13-2 = St13-40** `-[[:alpha:]]*L*` laisse passer `-0L`, `-4L`, `-6L`, `-#L` ; `SMOKE_CURL_OPTS` n'est jamais
  filtré (`--location-trusted` emporterait le `-K` vers tout hôte https). Faible / haute.
- **Sp13-2 = St13-41** un `-K`/`--config` de l'appelant, `--next`/`-:`, `-k` avec `--resolve`/`--connect-to` non jugés.
  Faible / moyenne.
- **C13-5** `export -n` arrive après `mktemp` (et `dirname`/`source` dans l'audit), qui reçoivent la variable → le placer juste
  après la lecture de la valeur. Faible-info / haute.
- **C13-10** `pendingProposal` des réglages rejoué sur un chargement ultérieur de la cible (antérieur à la série, voir Q6).
  Faible-info / moyenne.
- **CR13-3 = Sp13-10** `deferredCallsOf` ne compte que les `watch` en `flush: 'post'`, alors qu'un `watch` par défaut sans
  `immediate` s'exécute aussi après le montage (`watch(route, () => load())` invisible) ; commentaire faux ; `onUpdated`,
  `onActivated`, `onBeforeUpdate` non lus ni cités dans les limites ; `flush:'post'` + `immediate:true` refusé (faux positif
  prudent). Moyenne-faible / moyenne-haute.
- **CR13-4 = C13-6** la règle `*://*` juge aussi les valeurs d'options (`-H 'Origin: https://…'`, corps JSON) → refus à tort.
  Faible / haute.
- **CR13-5** `vueCompilerAlignment.spec.ts:47` : la commande proposée écrit `^<version>` alors que le test compare les
  intervalles → boucle quand `vue` n'est pas `^<installée>` (proposer `@<vueRange>` ou comparer les versions résolues).
  Faible / haute.
- **CR13-6 = St13-7** `admin.translation.proseReadonly` (FR/EN) « qui les remplace par la proposition » : faux sur un échec,
  une réponse périmée, et pour les réglages (proposition différée, posée sur l'autre langue) ; mot verrouillé par un
  `toContain('remplace')`. Faible / moyenne.
- **CR13-7** `discardIfDeleted` avant `await remove()` même si la suppression échoue (voir Q7). Faible / moyenne.
- **CR13-9 = C13-8 = St13-55** `canonicalReadsIn` compte toute classe de dernier segment `CanonicalPath`
  (`\Other\CanonicalPath::of(`), rate un alias `use … as CP` ; le test de `::` en `$index + 1` doublonne
  `MemberCalls::isCallAt($tokens, $index + 2)` ; expression `strtolower(substr(strrchr(…)))` sans nom. Faible / moyenne.
- **St13-1 = St13-2 = St13-3 = Sp13-13** textes de refus recopiés en dur (`AdminLayout.sessionEnded.spec.ts:374,379` à
  219/223 caractères écrites par b40ddc21, plus 5 `toContain` antérieurs ; `McpConnectionsPage.spec.ts:128,130` ;
  `AssistantPage.spec.ts:333,338` ; `OAuthConsentPage.spec.ts:179,181` ; `AccessRefusedNotice.spec.ts:235`) ;
  `accessRefusalTexts.ts` n'exporte pas l'EN. Faible / haute.
- **St13-4** `describedBy.ts` sans spec au chemin miroir. Faible / haute.
- **St13-5** `adminTranslationTexts.spec.ts` juge `locales/*.json` hors de `tests/infrastructure/i18n/`. Faible / moyenne.
- **St13-6** `proseFields.ts:5-6` dit la liste lue pour « ce qui la rend traduisible » : faux pour les traits
  (`AdminQualityPage.vue:220` `hasTraitProse` sur `label` seul). Faible / haute.
- **St13-10** `sourceText.ts:402` assertion `as` évitable par un prédicat de type. **St13-11** docblock de `callbackOf` faux.
  **St13-12** `useAdminTranslation.ts:112` renvoie à `translate` au lieu de `watchIdentity()`. Faible / haute.
- **St13-16** Shotgun Surgery résiduelle (ajouter un formulaire traduit). Faible / moyenne.
- **St13-17** `moveFirstRowDown` défini 6 fois à l'identique ; `deleteSourceEntry` = copie de `deleteEditedEntry` ×6. Faible /
  haute.
- **St13-18** `sessionEndedLoad.spec.ts:67-250` : 12 entrées d'une ligne devenues 12 blocs de 11 lignes (b40ddc21) →
  `standardCase(…)`. Faible / haute.
- **St13-19** deux modules `proseFields.ts` importés côte à côte (Mysterious Name). **St13-20** `LOCALE_HELP_FR` (Qualité),
  `adminTranslationTexts.ts` porte des textes du verrou, « discard » nomme deux gestes. **St13-21** deux préfixes pour les
  réglages (`admin-about-*` / `admin-about-settings`). Faible.
- **St13-22** `UseTranslatedFormResult` expose `discard` que « aucune page n'appelle » → `Omit`. **St13-23**
  `proseDescribedBy` lu par un spec seul ; `currentRefusalScope()` export pour les specs (= C13-9) ; `qualityProviders` à un
  appelant. Faible / haute.
- **St13-25** la génération (état d'interface) vit dans `domain/…/FormIdentity.ts`. **St13-26** Long Method
  (`describeEntryFormLock` ≈ 510 lignes, `deferredCallsOf`, `fragmentsOfTemplateAst`, `AdminQualityPage.vue` 1 093 lignes).
  **St13-31** branches `undefined === scope` / `!scope.active` de `watchIdentity` non testées. Faible / moyenne.
- **St13-36** « nothing outside the library calls it » faux (le test appelle `_smoke_write_auth_config`). **St13-37** « seul
  endroit à nommer la variable » faux (la règle la nomme et redonne la liste). **St13-38** doc de `smoke_fetch` sans les refus
  `--proto*`, code 2 mal décrit. Faible / haute.
- **St13-43** bloc de 6 `CASE_*` ×13. **St13-45** `smoke-http.test.sh:917` `cp` aussitôt écrasé (ligne morte). **St13-46**
  faux positif `x="${env}"`. **St13-50** reflux de commentaires irrégulier (`smoke-http.sh:90-91,121-123`). Faible / haute.
- **St13-53** `paths:` de `deploiement-nginx.md` ne couvrent pas `smoke-dead-session-cookie.sh` ni son test (voir Q5).
  **St13-54** `MemberCalls` « écrite une fois » mais `CookieConstructions.php:139`, `PlainPasswordInputs.php:59,215` ont leur
  forme. **St13-57** `AuthorizationSites` : deux styles de signature, `$previous` calculé deux fois. Faible / moyenne-haute.
- **St13-61** moignons restants ou créés par le recollage : `mcp-oauth-revocation-personne.md:312,328-329,331,341`,
  `frontend-session-refus.md:26-27`, `deploiement-nginx.md:117-118`. **St13-62** `frontend-session-bandeau.md:120-128`
  continuations non indentées (passage réécrit par la série). **St13-63** paragraphe « Translation assistant » de
  `frontend-backoffice.md:178-238` qui s'allonge, pseudo-titres en gras. Faible / haute.
- **St13-64 = Sp13-8 = Sp13-14** plan non tenu à jour : R141b/R148b sans `**Files :**` ni étapes, R148b après R149 ; choix 4
  (options du composable), 6 (guetteur permanent au montage), 7 (deux arguments), R143 Step 4 (b) (mutation impossible), R145
  (bouton ↓) décrivent l'ancienne forme ; renommages de R150, écart 190/187 non dits ; deux « Q1 ». Faible / haute.
- **Sp13-3** R140 : le voisin « supprimer la sœur ne périme pas » n'existe qu'au composable, pas dans les cas partagés.
  **Sp13-4** R137 : aucun cas d'intégration ne change l'identité par le seul groupe. **Sp13-5** R139 : le cas « formulaire
  vierge enregistré » ne lit que l'état final (le Review Focus 1 : « le bouton dit l'attente puis revient »). Faible.
- **Sp13-6** R150 : 19 noms de tests raccourcis (plan : « aucun nom ne change »), « (#748, R67) » perdu, sens affaibli
  (« désactivé » → « inactif », « ôte le palier de confiance » → « ôte la confiance ») (voir Q8). Faible / haute.
- **Sp13-7** lignes > 140 laissées qui ne sont pas « un littéral seul » (`smoke-http.test.sh:132,402`,
  `smoke-dead-session-cookie.test.sh:149,252`, `sourceText.spec.ts:111`) ; la liste des 27 restantes n'est consignée nulle
  part. Faible / haute.
- **Sp13-9** récits au passé : `backofficeAccessRefusal.ts:119` (« autrefois »), `useTranslatedForm.ts:86-87` (« recopiait »).
  **Sp13-12** `AdminAboutPage.spec.ts:1852` `.not.toContain('Abandonner')` en dur. Faible / haute.

## Info
- **CR13-8** (écarté par le fil principal) : un enregistrement FR réussi pendant une traduction FR→EN des réglages ne périme
  rien — la prose est en lecture seule pendant la traduction, le texte enregistré est celui qui est traduit : la proposition
  reste juste.
- **C13-7** `audit-prod.sh` : `CURL_OPTS` sans `--proto =https` (la garantie tient à l'écriture des URL) — couvert par Q2.
- **C13-9** `currentRefusalScope()` export de production lu par les seuls specs (même motif que `proseFieldId`).
- **St13-8** le cas R141b ne peut pas rougir (accepté par la règle). **St13-9** types de retour non annoncés
  (`proseFieldAttrs`, `mountTranslatedForm`, `qualityProviders`, `deleteDuringTranslation`). **St13-13** liste des appelants de
  `describedBy` incomplète. **St13-14** doc des couches application/domaine nommant des fonctions de `presentation/`.
- **St13-24** `{ id; translationGroup }` écrit deux fois ; `fieldIdPrefix` redondant avec `ids.*`. **St13-27** `parse` +
  `throw` deux fois. **St13-28** répétitions d'`entryFormLock.ts`. **St13-29** commentaire périmé `entryFormLock.ts:458-459`.
  **St13-30** deux tests `isRefused` presque identiques. **St13-32** `{ ...INITIAL_STATE, scope, epoch }` peu lisible.
  **St13-33** coupes de b40ddc21 hétérogènes (`if` sans accolades, apostrophes typographiques `’`).
- **St13-42** `CASE_VALUE` exportée vers les processus lancés (valeur aléatoire). **St13-44** `${CASE_RC:?}` sort sans
  `fail`. **St13-47** commentaires appauvris pour passer la garde. **St13-48** nommage (`…plain_url…`). **St13-49** « garde du
  mot » = sous-chaîne. **St13-51** `audit_with_basic_auth` redit `SMOKE_AUTH_CONFIG`. **St13-52** `${target:-}` inutile.
  **St13-56** `CLASS_NAMES` contient des jetons. **St13-58** `MemberCalls` sans test propre. **St13-59** docblock retouché hors
  sujet.
- **Sp13-11** `frontend-session-refus.md:100-101` : « a function declared in the file » inexact (vue si son nom commence par
  `load`). **Sp13-15** `audit-prod.sh` sans en-tête `Env :`.
- Débordements (Spec) : aucun fonctionnel ; R150 a reformaté des lignes ≤ 140 (`useAuth.ts`, `sessionEndedLoad.spec.ts`), à
  confirmer par `diff -w`.

## Écarts déclarés, jugés (Spec)
R137 conforme (trou de Sp13-4) ; R138 options non créées acceptables, à consigner ; R139 acceptable (guetteur tardif justifié
par la TDZ, preuve rouge par les cas partagés), à consigner ; R140 signature meilleure que le plan, voisin non conforme
(Sp13-3) ; R143 `moduleState` correct, le plan se trompait sur la mutation (b), à consigner ; R145 conforme au composant
réel ; R150 non conforme sur les noms (Sp13-6), partiel sur les littéraux (Sp13-7) ; R151 conforme à l'ajout 3 sauf
St13-61.

## Vérifié sain
Audit : R148 (blancs, `export -n` y compris sur `readonly`, fonction privée, mot sous deux formes), R148b (`--proto*`
refusés, `--proto-redir =https` après `-K`, garde `/proc` avant l'exemption), R149 (une sonde, indice 401 avec la Basic Auth
seule, sondes sans `-K`), R137–R140 (refus, péremption définitive A→B→A, `renewForm()` en tête des remises,
`discardIfDeleted()` avant chaque suppression), R143, R146 (`integrity` sha512, une installation), R147 ; tests rejoués
verts. `/code-review` : `RefusalModuleState`, prop `ariaDescribedby`, ordre `apply`/`isLoaded`, sonde unique, `MemberCalls`.
Standards/Spec : eslint, shellcheck, PHPStan à 0 sur les fichiers du diff ; vitest et phpunit ciblés verts ; aucun `any`
ni `as` ajouté dans `src/` ; `domain/` sans Vue ; ADR 0004 D4 tenu ; textes i18n FR et EN ; règles sous 50 000 octets.

## Portfolio (à proposer, rien rédigé)
Rubrique « Incidents » ou « Études de cas » : une liste de refus d'arguments `curl` ne ferme pas ce qu'une option `--proto`
ferme ; une garde par motifs contre la fuite d'un secret shell n'est jamais complète (`declare` seul, `/proc/<pid>/environ`).
