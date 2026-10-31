# Onzième relecture de #748 — diff 13d4de17...55199dca (R112 à R120, R114b), 2026-10-31

Quatre relectures : `/code-review medium` (CR11-*), audit sécurité (C11-*), axe Standards (St11-*), axe Spec (Sp11-*).
**Aucun critique ni haut**, trois moyens (CR11-1 de conception, St11-3, St11-4). Sévérité / confiance après chaque constat ;
doublons regroupés sous le premier identifiant. Avant les relectures, le fil principal a rejoué sur conteneurs redémarrés
les cinq cibles `make`, les 26 tests hors ligne et `check-claude-rules` : tous à 0 (front 2321, back 5594). L'audit a
rejoué `audit-prod.sh` et les trois smokes sous `bash -x` avec faux `curl` pour six valeurs (absente, vide, sans « : »,
blancs, retour à la ligne, pleine avec `"` `\` `#`) et des `SMOKE_AUTH_*` hérités hostiles : sorties 1/2 attendues, valeur
jamais dans la trace ni les arguments, aucun `http://` avec `-K`, fichier 600, dossier 700, contenu échappé, `/etc` intact ;
TERM/HUP/INT au PID puis au groupe (`set -m`) sur les quatre scripts : 143/129/130, dossier retiré dans les 24 cas ; les
tests backend ciblés (CanonicalPath, CsrfCookieRequestSubscriber, AuthenticationFlow, gardes) et `make front-test`.

## Décisions de l'utilisateur (2026-10-31)
1. **CR11-1 / C11-9 : règle + garde.** `frontend-session-refus.md` dit qu'une page du backoffice lance son chargement dans
   `setup` (ou un watcher `immediate`), et une garde de source refuse un chargement (`load(`, appel de dépôt) dans un
   `onMounted` des pages et sections admin ; la limite d'un sous-composant rendu plus tard par un `v-if` reste écrite.
2. **CR11-3 / C11-6 : un appel en vol.** Une nouvelle traduction reste refusée tant que la requête périmée par `discard()`
   n'est pas terminée : jamais plus d'un appel au modèle en vol par formulaire ; le bouton occupé après « Annuler » le
   dit (texte d'aide FR/EN lié).
3. **CR11-5 : prose en lecture seule.** Pendant une traduction, les champs de prose du formulaire passent en `readonly`
   (pas `disabled` : lisibles et focusables), avec un texte d'aide lié par `aria-describedby` ; rien de tapé n'est perdu.
   (C11-7 b, la suppression de l'entrée en édition, se corrige sans décision : `discard()` dans `handleDelete`.)
4. **Identifiants de constat : gardés et permis dans les commentaires** (ils renvoient aux constats archivés), interdits
   seulement dans les noms de tests ; le brief commun est corrigé en ce sens. St11-18 et Sp11-5 n'appellent pas d'autre
   correction ; St11-1 et Sp11-1 (noms de tests) se corrigent.
5. **Sans suite** : Sp11-4 (lignes de 121 à 129 de `deploiement-nginx.md`).
6. Toujours valables : C5-1, C5-2, C7, CR6-6, CR7-2, la limite de R94, les Sans suite précédents, pas de rotation (C10-1),
   critère d'arrêt **tout jusqu'à zéro** : tous les autres constats se corrigent sans décision.

## Moyens
- **CR11-1 ≈ C11-9** (à arbitrer) R112 range une lecture dans le chargement de la page d'arrivée si elle est estampillée
  **avant l'`onMounted` de l'alerte** : une lecture qu'une page enverrait depuis son propre `onMounted` (les hooks
  `mounted` des frères suivants passent après `AccessRefusedNotice`, placé avant `<RouterView />`), depuis un
  sous-composant rendu plus tard par un `v-if`, ou après un `await` avant `stampAtSend`, porte la nouvelle époque : son 403
  remplace « enregistrement précédent » par `common.accessRefused` et la personne n'apprend pas que son enregistrement a
  échoué. Jamais un texte faux sur l'identité du compte (audit). **Aujourd'hui aucune page ne le fait** (audit : chargements
  dans `setup` ou des watchers `immediate`, sections d'« À propos » inconditionnelles, seul `onMounted` = écouteurs
  d'`AdminUsersPage`), et le test d'intégration l'épingle sur une vraie page ; rien n'empêche une page future. Moyenne /
  faible-moyenne.
- **St11-3** `discardTranslation()` recopié dans chaque `resetForm`, `startEdit`, `startCreateVersion` (≈ 24 sites, six
  pages, huit formulaires) avec le même `useAdminTranslation({ isRefused: () => lock.isProposalLocked })` (Shotgun
  Surgery) : un nouveau geste qui remplace le formulaire doit penser à périmer la traduction. Moyenne / moyenne.
- **St11-4** couplage temporel : `smoke_write_auth_config X || exit 2` est toujours suivi de `smoke_require_auth_config X
  || exit 2` (quatre appelants) ; la seconde dépend de `SMOKE_AUTH_CONFIG` laissé par la première (« à appeler juste
  après ») → une seule fonction qui écrit et exige, ou un drapeau. Moyenne / moyenne.

## Faibles
- **CR11-3 = C11-6** (à arbitrer) `discard()` ne fait que périmer la réponse : l'appel au modèle continue (pas
  d'`AbortSignal`) et, `isTranslating` remis à faux, une nouvelle traduction facturée peut partir aussitôt :
  « Proposer » → « Annuler » répété lance N appels concurrents (ADR 0004 : coût borné ; borné par
  `TranslationRateLimiterInterface`). Faible / moyenne.
- **CR11-5 + C11-7** (à arbitrer pour la saisie) deux gestes qui touchent le formulaire ne périment pas la traduction en
  vol : (a) la **saisie** pendant la traduction — la réponse remplace chaque champ de prose par la traduction de l'ancien
  texte, ce qui a été tapé entre-temps est perdu en silence (antérieur ; le diff dit « chaque geste qui remplace le
  contenu ») ; (b) **supprimer l'entrée en édition** (`handleDelete`, huit formulaires) : la réponse pose une proposition
  en création avec le groupe d'une entrée supprimée (`sourceGroup`), l'enregistrement finira sur un 4xx (non vérifié) ;
  et le sélecteur de langue d'un formulaire en création (C11-7). Faible / moyenne.
- **CR11-6 = C11-5** (+ St11-17) `isRefused` ne couvre pas tout : `AdminAboutSettingsForm` n'en passe aucun (pendant une
  proposition affichée, un `translate` émis lance une traduction qui perd la proposition non enregistrée, cas Sp7-1) ;
  le brouillon d'ordre (`isOrderDirty`) de chaque page et l'enregistrement des réglages (`isAnySaving`, R67) ne sont
  protégés que par le bouton → `isRefused: () => lock.isProposalLocked || isOrderDirty.value`, et pour les réglages
  `isAnySaving.value || isLocked.value` (+ proposition affichée), avec leurs cas. Faible / moyenne.
- **C11-1** (antérieur, famille de C10-1) les trois smokes envoient la Basic Auth **en clair** si on leur passe une URL
  `http://` (`smoke_fetch` pose `-K` quel que soit le schéma, `base` jamais vérifié) ; la CI passe `https://` en dur, pas
  touchée → `smoke_fetch` sort 2 sur une URL non `https://` quand `SMOKE_AUTH_CONFIG` est posé (ou `--proto =https`), un
  cas par smoke. Faible / haute.
- **CR11-2** rien ne borne la durée pendant laquelle une lecture de la page d'arrivée garde l'avis : un 403 de chargement
  arrivé des minutes plus tard (après un « Réessayer » servi) réannonce `common.earlierSaveRefused` pour un refus courant.
  Compromis voulu de R112 (l'audit : `earlierSave` est monotone, une lecture d'avant le montage ne peut que garder un avis
  encore vrai). Faible / moyenne.
- **CR11-7** (+ St11-11) `isLoaded.value &&` dans `isProposalShown` (R113) ne change rien de visible : son seul lecteur, le
  `v-if` de l'avis, est déjà sous `<form v-else>` rendu une fois chargé ; le test de R113 lit l'état interne
  (`as unknown as`), il n'a pas pu échouer dans le DOM, et c'est une seconde source de vérité. Faible / moyenne.
- **CR11-8 = St11-12** `ApiPlatformOperationsCarryNoSecurityTest` : `$found = [...$found, ...collectNested()]` recopie le
  tableau à chaque ressource et opération (quadratique) ; `collectNested()` et `countLinks()` sont toujours appelés
  ensemble, parcourent chacun `uriVariablesOf()` et décident chacun ce qu'est un `Link` compilé (`instanceof Link` deux
  fois) → une seule passe qui rend `{found, links}`. Faible / haute.
- **St11-1** identifiants dans les noms de tests nouveaux : « (#748, R119) », « (#748, R112) », « (#748, R114b) »… alors
  que la consigne les retire (un identifiant de tâche toléré = info) ; **Sp11-1** le balayage R120 est encore incomplet :
  la recherche 5 ne voyait que `Sp|CR|St|C` suivis d'un tiret — restent A3-10 (`LoginPage.spec.ts:144`), A3-13
  (`AppLayout.spec.ts:298`), CR2 et F11 (`AdminAboutPage.spec.ts:1203`, `:1340`, `:1527`), F8 (`signInLocations.spec.ts:57`,
  `:84`, `SignInAgainLink.spec.ts:76`), B/F2 (`adminGuard.spec.ts:262`), C1 (`noOmittedCredentials.spec.ts:88`). Faible /
  moyenne.
- **St11-2 = Sp11-3** lignes > 140 dans les règles touchées : `frontend-session-refus.md:79` (143), `:26` (216),
  `frontend-session.md:151` (142), `security-authentication-sessions.md:177` (176) ; la recherche 6 n'a couvert que
  frontend, backend, tools. Faible / moyenne.
- **St11-5 = Sp11-8** `readRefusalScope()` : « read » est verbe et adjectif ; le docblock d'`AccessRefusedNotice.vue:35` dit
  « Effacée (le périmètre de refus avance, `readRefusalScope()`) » alors que la fonction lit — c'est l'effacement qui
  avance ; `RefusalScope` porte maintenant `scope` et `epoch`. Faible / haute.
- **St11-6** `backofficeAccessRefusal.spec.ts` : `readSentNow()` est un Middle Man de `readRefusalScope()` ; le cas « une
  lecture … qui ne répond pas » appelle `readSentNow()` sans rien en faire et ne se distingue plus de « partie après le
  montage ». Faible / moyenne.
- **St11-7** `tests/support/entryFormLock.ts` : `titleValue` créé puis la l. 231 refait la lecture ;
  `findAll('form')[form].find('[role="status"]')` cinq fois ; `translateButton` double `translateEntryButton` ;
  `mountLocked` monte avec un dépôt neuf et n'a rien d'un verrou (Mysterious Name). Faible / haute.
- **St11-8** `sourceText.ts` : `5 === node.type` nombre magique alors que `NodeTypes.INTERPOLATION` existe ;
  `templateExpressionsOf` rend aussi des valeurs d'attributs statiques, qui ne sont pas des expressions (St10-7 à moitié).
  Faible / moyenne.
- **St11-10** `earlierSaveFr` / `accessRefusedFr` recopiés entre `AdminLayout.sessionEnded.spec.ts` et
  `AccessRefusedNotice.spec.ts` ; `mountAdminQuality` refait en grande partie `mountAdminCaseStudies`. Faible / haute.
- **St11-14** `audit-prod.test.sh` : `run() { run_as "$1"; }` Middle Man, `run_as` flou ; le cas « vide » copié tel quel dans
  les trois tests de smoke. Faible / haute.
- **St11-15** reliquats de R120 : `AdminCaseStudiesPage.spec.ts` « // (#748, R93) : le bouton … et les » suivi d'une ligne
  courte ; docblock d'`entryFormLock.ts` coupé (« Le support\n * crée ») ; variable `proposalSource` de
  `useEntryFormLock.spec.ts` non renommée. Faible / haute.
- **Sp11-2** `AppLayout.spec.ts:168` « Le bandeau n'a plus son écouteur » (récit au passé, fichier touché par R120). Faible /
  moyenne.
- **Sp11-7** `entryFormLock.ts:239-241` : « celle qui ramènerait le formulaire en arrière comme celle de la source » nomme
  deux fois la source, jamais la cible — le point même de Sp10-2. Faible / moyenne.
- **St11-9** un nouveau test de `sourceText.spec.ts` (« suite d'instructions … corps de fonction ») refait les assertions
  d'un test existant (`a(); b('omit')`). Faible / haute.
- **St11-16** la plomberie de `tools/` est décrite une fois, mais dans `deploiement-nginx.md` (section MCP) quand St10-13 la
  rattachait à `release.md` ; l'explication de #750 est écrite trois fois (deux dans `audit-prod.sh`, une dans la règle).
  Faible / moyenne.

## Info
- **C11-2** l'audit suit les redirections (`-L`) avec `-K` (l. 99, 150, 169, 253, 393, 412, 422) ; seul curl ≥ 7.83 (CVE-2022-
  27774/27776) empêche de renvoyer l'identifiant sur changement de schéma/hôte, et le curl du runner n'est pas épinglé →
  `CURL_OPTS+=(--proto-redir =https)` à côté du `-K`.
- **C11-3** la garde « aucune ligne ne développe `AUDIT_BASIC_AUTH` » ne lit que `${…}`/`$…`/`${!…}` : `${#AUDIT_BASIC_AUTH}`,
  `printenv`, `declare -p`, `env`, `eval`, l'indirection `n=…; ${!n}` lui échappent → liste d'autorisation (toute ligne non
  commentée qui contient le mot est refusée, sauf `[[ -v … ]]` et les deux appels de la bibliothèque).
- **C11-4** `CanonicalPath::isPathUnder` (R117, publique en production, « pour les tests », cf. St11-13) accepte une chaîne
  non décodée : un futur listener `isPathUnder($request->getPathInfo(), …)` réintroduirait le `%XX` de #77 ; aucune garde de
  `src/` ne refuse `getPathInfo()` hors de `CanonicalPath` → garde par jetons (`getPathInfo(`, `isPathUnder(` hors
  `CanonicalPath.php`), ou au moins `@internal` avec renvoi à `of()`.
- **C11-8** une valeur `:`, `u:` ou `:p` passe pour une Basic Auth (cascade de 401, pas de faux vert) → exiger un
  utilisateur non vide (`?*:*`) sous le même `set +x`.
- **CR11-9** la sonde `https://www.${DOMAIN}` part aussi sans Basic Auth (comme dans #750) : si un hôte `www` de préprod
  passait un jour derrière la Basic Auth, la ligne dirait 401 au lieu de la redirection (aucun `www` en préprod aujourd'hui).
- **CR11-10** `wrapperOf` choisit expression ou corps de fonction en essayant de lire `(texte)` comme expression, là où Vue
  juge par son test d'expression membre / de fonction : `@click="{ credentials: 'omit' }"` (bloc à étiquette pour Vue, objet
  pour le lecteur) — le littéral est trouvé, la promesse « comme Vue le compile » ne tient pas à la lettre.
- **CR11-4** = C11-7 (b), regroupé plus haut.
- **St11-13** `CanonicalPath::isPathUnder()` publique dans `src/`, documentée « pour les tests » (une des deux options de
  CR10-8) ; voir C11-4.
- **St11-18** identifiants de constat ou de décision dans les commentaires de `src/` (« A23 », « R112, décision 3 »,
  « (#748, R118) ») — la question globale de R120, une seule fois ; **Sp11-5** traitement incohérent : R120 a retiré « CR3-8 »
  d'un commentaire, R112 en écrit (« CR7-4 » dans `AccessRefusedNotice.vue`).
- **Sp11-4** St10-13 demandait des lignes « coupées vers 115 » : huit lignes du paragraphe de `deploiement-nginx.md` font 121
  à 129.
- **Sp11-6** le grep de R119 rend encore `const sources` dans `colorClasses.spec.ts` et `signInLocations.spec.ts` (des
  sources de fichiers : nom juste, hors du champ de St10-8).
- **Sp11-9** le test d'intégration de R112 ne vérifie le texte qu'une fois les deux refus passés, ni le texte ni le
  remplacement du `<span>` entre la première et la seconde annonce (il discrimine quand même).
- **Sp11-10** le cas « AUDIT_BASIC_AUTH vide » d'`audit-prod.test.sh` n'affiche plus le contenu de `TMPDIR` dans son message
  d'échec (diagnostic appauvri).

## Vérifié sain (audit)
R115/R116 (voir l'en-tête) ; la valeur sans « : » est jugée sous `set +x`, `smoke_require_auth_config` juge sur `[[ -v ]]` et
`SMOKE_AUTH_CONFIG` sans développer la valeur ; `origin/fix/750-…` (345178b0) porte la même correction. R117 : `isUnder`
délègue à `isPathUnder(self::of($request), …)` (même règle, même exception) ; `/apix` protégé ; `/api/logout` hors
`EXCLUDED_PATHS`, témoin intersite sans cookie → 403 sans `Set-Cookie` ; parcours du routeur à l'ancrage d'avant. R112 :
`epoch` n'avance qu'au montage, `scope` qu'aux effacements, `earlierSave` monotone pour les lectures ; identité du compte
tenue par trois verrous (compte à l'envoi ≠ courant écarté, montage remis à zéro si un autre compte, `pageKey` qui remonte
`AdminLayout`) : aucun chemin ne montre à B l'avis de A. R114/R114b : `isTranslating` posé de façon synchrone avant l'`await`
(double activation refusée), `isProposalLocked` déballé, `discard()` câblé par formulaire, une réponse périmée ne touche ni
l'état ni la raison, aucune proposition dans le formulaire d'une autre entrée. Lecteur de gabarit sain.
