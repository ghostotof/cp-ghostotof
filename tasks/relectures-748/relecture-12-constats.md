# Douzième relecture de #748 — diff 33af7369...53d83217 (R121 à R136, R127b, R127c), 2026-10-31

Quatre relectures : `/code-review medium` (CR12-*), audit sécurité (C12-*), axe Standards (St12-*), axe Spec (Sp12-*).
**Aucun critique ni haut.** Moyens : CR12-1 = C12-1 (régression de R124, classée faible par l'audit, moyenne par
`/code-review` : à trancher), St12-1, St12-2, St12-3, St12-5, Sp12-1. Avant les relectures, le fil principal a rejoué sur
conteneurs redémarrés les cinq cibles `make`, les 26 tests hors ligne et `check-claude-rules` : tous à 0 (front 2426,
back 5609). L'audit a rejoué `audit-prod.sh` et les trois smokes sous `bash -x` avec faux `curl` (valeurs absente, vide,
`:`, `u:`, `:p`, sans « : », pleine avec ``"\$`;&|*?``, base `http://`) et des `SMOKE_AUTH_*` hérités : sorties attendues,
valeur jamais dans une trace, une sortie ni un argument, 25 appels `-K` tous avec `--proto-redir =https`, aucun `http://`
avec `-K`, fichier 600, dossier 700 retiré ; TERM/HUP/INT (`set -m`) : 143/129/130, dossier retiré dans les 12 cas ; un vrai
`curl` relit exactement une valeur hostile du fichier `-K` ; tests backend ciblés (PathIsDecidedCanonically,
ApiPlatformOperationsCarryNoSecurity) et `make front-test` verts.

## Décisions de l'utilisateur
Prises le 2026-10-31 à la reprise (session close par `/exit` avant les questions) : **les quatre recommandations**.
- Q1 → **(b)** une génération de formulaire dans `formIdentity`, incrémentée par `resetForm()` ; `failure` effacé au
  changement de génération (règle CR12-2 au passage).
- Q2 → **oui aux deux** : un seul `computed` du refus (lu par `isRefused` et le `:disabled`) ; un composable de la prose
  (identité, id de l'aide en lecture seule, `proseDescribedBy`).
- Q3 → couper les lignes > 140 antérieures de #748 dans **les règles et le code**, pas le JSON ni les tables, dans une
  tâche dédiée (liste à refaire, celle de la session précédente est perdue).
- Q4 → **oui** : le mot refusé partout hors des deux formes (commentaires compris, en-têtes `Env :` réécrits) + garde des
  vidages d'environnement.
- Tout le reste (faibles, info) se corrige — critère d'arrêt « tout jusqu'à zéro » ; le plan rend en question ce qui
  demande un arbitrage.

Questions posées :
1. **CR12-1 = C12-1** (régression de R124 + R126) : comment périmer une traduction en vol quand un formulaire vierge est
   enregistré (identité inchangée) ? Options : (a) « Enregistrer » désactivé pendant `isTranslating` (la prose est déjà en
   lecture seule) ; (b) une génération de formulaire dans `formIdentity`, incrémentée par `resetForm()` ; (c) `discard()`
   sur le succès de `handleSubmit`. Reco du fil principal : (b), qui couvre aussi tout geste futur qui remet le formulaire
   à zéro sans changer entrée/langue/groupe (et règle CR12-2 si `failure` est effacé au changement de génération).
2. **St12-1 / CR12-7 et St12-2 / CR12-8** (moyens, conception) : un seul `computed` du refus lu par `isRefused` **et** le
   `:disabled` ; un composable qui rend l'identité, l'id de l'aide en lecture seule et `proseDescribedBy`. Reco : oui aux
   deux.
3. **Sp12-1** : couper aussi les 203 lignes > 140 écrites par les séries antérieures de #748 (67 dans les règles, 10 en
   JSON, 3 de tables) ? Liste : `scratchpad/s5cls.txt` de la session du 2026-10-31 (à refaire si perdue). Reco : couper
   celles des règles et du code (pas JSON ni tables), dans une tâche dédiée.
4. **C12-2** : la liste d'autorisation `AUDIT_BASIC_AUTH` filtre les lignes `^#` (un heredoc la contourne, mutation
   prouvée) et ne voit pas `declare -p`/`printenv`/`env`/`set`/`export -p` → refuser le mot partout hors des deux formes
   (commentaires compris, en-têtes `Env :` réécrits) + garde des vidages d'environnement. Reco : oui.

## Moyens
- **CR12-1 = C12-1** R124 a retiré `discardTranslation()` de `resetForm()` et R126 la prose de `formIdentity` : un
  formulaire vierge FR (identité `{null,'fr',null}`) enregistré pendant une traduction retrouve après `resetForm()` la même
  identité ; la traduction n'est pas périmée et sa réponse pose une version EN dans le formulaire vidé, `translationGroup`
  vide (sans lien avec l'entrée FR créée) → doublon orphelin ou 422. « Enregistrer » reste actif pendant une traduction
  (`:disabled="isSubmitting || isOrderDirty"`). Huit formulaires (Qualité : `resetPrincipleForm`/`resetTraitForm`).
  Moyenne (CR) / faible (audit), confiance moyenne-haute.
- **St12-1 = CR12-7** le prédicat d'`isRefused` est recopié au texte près dans le `:disabled` de `TranslateEntryButton` sur
  les neuf sites : deux sources de vérité (l'écart qu'a corrigé R127b) → un seul `computed`. Moyenne / haute.
- **St12-2 = CR12-8** `formIdentity` (+ commentaire), `PROSE_READONLY_HINT_ID`, `proseDescribedBy`, `hint-id`, `:readonly` +
  `:aria-describedby` recopiés dans huit formulaires (≈ 25 sites) ; `ids.prose` doublonne `PROSE_FIELDS` dans les tests
  (Shotgun Surgery / Data Clumps). Moyenne / haute.
- **St12-3** `useAdminTranslation.ts` : `inFlight` (« courant, non périmé ») et `callRunning` (« en vol ») disent l'inverse
  l'un de l'autre (Mysterious Name). Moyenne / moyenne.
- **St12-5** `backofficeAccessRefusal.ts` : trois `let` (`scope`, `epoch`, `isLandingOpen`) remis à zéro ensemble à trois
  endroits (Data Clumps, forme reprochée par St10-2) ; `stampBackofficeRead()` est une commande que les specs utilisent
  comme requête (`expect(stampBackofficeRead().scope)` clôt au passage le chargement) → une requête pure. Moyenne / moyenne.
- **Sp12-1** balayage R136 incomplet (4e fois) : le Step 5 exigeait de couper toute ligne > 140 écrite par #748 ; 203
  restent (règles `frontend-session*.md`, `security-authentication-sessions.md`, `deploiement-nginx.md:117`…). Moyenne /
  haute (voir la question 3).

## Faibles
- **CR12-2 = Sp12-2** `errorReason` masque l'échec tant que l'identité diffère, sans effacer `failure` : revenir à
  l'identité de l'échec (« Modifier » A, B, puis A ; ou une création vierge après « Annuler ») réaffiche une erreur
  périmée, contre la décision R126 (« effacée par un vrai changement de formulaire ») et le docblock (« périmée pour de
  bon »). Aucun test. Faible-moyenne / haute.
- **CR12-3 = Sp12-4 = C12-3** la garde R121 ne voit pas `onMounted(load)` (référence), un alias d'import d'`onMounted`,
  `nextTick(load)`/`setTimeout(load)` dans le `setup`, un `watch(…, { immediate: true, flush: 'post' })` qui charge, ni les
  composants de `presentation/ui/admin` ; le docblock ne liste pas ces limites. Faible / haute.
- **CR12-4** `stampBackofficeRead()` clôt le chargement sur **toute** lecture estampillée après le montage (section rendue
  par un `v-if`, watcher, rejeu), pas seulement une lecture relancée par la personne. Faible / moyenne.
- **CR12-5 = C12-4** `PathIsDecidedCanonicallyTest` compare les noms avec la casse (`in_array(…, true)`) alors que PHP ne la
  respecte pas pour les méthodes : `->getpathinfo()` passe (atténué : `phpstan-strict-rules` `checkFunctionNameCase`) →
  `strtolower` + un cas. Faible / haute.
- **CR12-6 = St12-12** `canonicalReadsIn` promet « nom court ou complet », mais un `\App\…\CanonicalPath::of(` complet est un
  seul jeton `T_NAME_FULLY_QUALIFIED`, non compté ; `of` comparé avec la casse ; la fonction n'a pas de test ; pas de style
  Yoda ; le détecteur recopie la forme d'appel de `AuthorizationSites`. Faible / haute.
- **C12-2** (voir question 4) la liste d'autorisation R133 contournée par un heredoc dont une ligne commence par `#`
  (mutation : la valeur imprimée deux fois sur stderr, garde verte) ; `declare -p`, `printenv`, `env`, `set`, `export -p`,
  `${!x}` non vus. Faible / haute.
- **CR12-9** `audit-prod.sh` : deux `curl` par URL de redirection (`%{http_code}` puis `%{redirect_url}`) quand un seul
  `-w '%{http_code} %{redirect_url}'` suffit (6 appels au lieu de 3, deux réponses qui peuvent différer) ; l'indice 401
  « #750 » s'imprime aussi en prod, sans Basic Auth. Faible / moyenne.
- **CR12-10 = C12-13** `smoke_fetch` ne pose pas `--proto-redir =https` avec son `-K` et ne juge que son paramètre `url` :
  un `-L`, `--location`, `--url` ou une URL `http://` dans `"$@"` partiraient avec la Basic Auth (aucun appel actuel). Faible
  / moyenne.
- **St12-4** `formIdentity?: () => unknown` comparé par `JSON.stringify` (dépend de l'ordre des clés) → un type
  `FormIdentity`. Faible / moyenne.
- **St12-6** `describedBy` vit dans `useEntryFormLock.ts` mais `TranslateEntryButton` et `AdminAboutSettingsForm`
  l'importent sans le verrou (Feature Envy) ; cast `attrs['aria-describedby'] as string | undefined`. Faible / haute.
- **St12-7** `discardTranslation()` de `handleAbandonProposal` paraît mort (`isRefused` couvre la proposition affichée,
  l'identité a déjà périmé) ; la docstring de `discard` ne nomme pas cet appelant. Faible / moyenne.
- **St12-8** clés `admin.about.settingsProposal*` hors de l'objet `admin.about.settings` ; un même geste porte trois mots
  (`Abandon`, `Discard`, `discardTranslation`). Faible / haute.
- **St12-9 = Sp12-5** `AdminAboutPage.spec.ts:1320` (R127b) lit encore `vm as unknown as { proposal }` (motif retiré par
  R128) ; assertion tautologique `expect(SETTINGS_PROPOSAL_LOCKED_TEXT).toContain(…)`. Faible / haute.
- **St12-10** `DISCARD_PENDING_TEXT`, `PROSE_READONLY_TEXT`, `TRANSLATION_UNAVAILABLE_TEXT`, `SETTINGS_PROPOSAL_LOCKED_TEXT`
  recopiés en dur dans `entryFormLock.ts` et `AdminAboutPage.spec.ts` (l'inverse d'`accessRefusalTexts.ts`). Faible / haute.
- **St12-11** `syntaxTreeOf` exporté sans lecteur hors de son fichier ; `'load' === name` redondant avec
  `startsWith('load')` ; alias lus par regex (attrape les annotations de type) ; « décision 1 » dans des noms de tests.
  Faible / haute.
- **St12-13 = Sp12-6** commentaires au passé : « testaient » (`CompiledNestedReading.php:11-12`), « ne voyait »
  (`smoke-http.test.sh`). Faible / haute.
- **St12-14** `smoke_write_auth_config` reste publique (la règle dit `smoke_use_auth_config` seule appelée) ; `check_*` à six
  paramètres positionnels ; le décompte d'`allowed_use` code une indentation de deux espaces ; un `curl` assigné, l'autre en
  ligne dans `audit-prod.sh`. Faible / moyenne.
- **St12-15** l'en-tête `Env` des trois smokes ne cite plus « sans « : » » ni le retour à la ligne, toujours refusés. Faible /
  haute.
- **St12-16** `frontend-backoffice.md` : la phrase d'`isRefused` s'étend sur une dizaine de lignes de parenthèses ;
  `frontend-session-refus.md` : « Accepted, held by the review: … » colle la limite à la règle. Faible / moyenne.
- **Sp12-3** la règle et des docblocks disent qu'« un « Réessayer » » clôt le chargement : aucune page admin n'a ce bouton.
  Faible / moyenne.
- **Sp12-7** `mountAdminQuality` fait encore 8 lignes (le plan : « un appel d'une ligne ») ; le grep ne passe que parce que
  c'est une `const`. Faible / moyenne.
- **St12-17 = Sp12-11** cases de R127b cochées dans le plan (convention : non) ; `dirtyOrder` par glisser-déposer et non par
  « ↓ » ; quatre chaînes JSON > 140 non dites. Faible / haute.

## Info
- **C12-5** « un seul appel en vol par formulaire » vaut par instance : quitter la page et revenir (ou un second onglet)
  pendant un appel en lance un second (borné par le quota 30/h) → l'écrire dans la limite de R125.
- **C12-6 = Sp12-9** `admin.translation.proseReadonly` promet « Rien de ce qui est écrit n'est perdu » : faux pour une saisie
  non enregistrée (la réponse remplace la prose et bascule en création) ; `discardPending` dit « La traduction annulée »
  alors que la péremption vient aussi d'un changement de langue, de « Modifier », de « Créer la version ».
- **C12-7** commentaire de l'identité des réglages : « d'autres réglages posés » — l'identité ne porte que la langue.
- **C12-8** supprimer l'entrée source d'un « Créer la version XX » ne périme pas la traduction en vol (proposition posée sur
  un groupe sans membre).
- **C12-9** un utilisateur ou un mot de passe fait seulement de blancs (`' :p'`, `'u: '`) passe.
- **C12-10** la valeur reste exportée dans l'environnement des `curl`/`dig`/`openssl` (même utilisateur, pas de nouvelle
  exposition) → `export -n` après l'écriture du fichier.
- **C12-11** `@vue/compiler-dom` en `^3.5.44` indépendamment de `vue` : un `npm update` peut les faire diverger.
- **C12-12** la limite de R132 ne nomme pas `getUri()` ni `getBaseUrl()`.
- **Sp12-8** la section « Tests » de `frontend-session-refus.md` ne cite ni R122 ni `noLoadInOnMounted.spec.ts`.
- **Sp12-10** les deux « #750 » de `audit-prod.sh` sont le commentaire de la boucle et la chaîne du 401 (conforme au choix 9).

## Vérifié sain (audit)
R133/R134 (voir l'en-tête), pas de `--location-trusted`/`--anyauth`/`netrc`. R124–R127c : `callRunning` posé avant tout
`await`, `isRefused` reprend terme à terme le `:disabled`, pas de départ sans prose, réponse périmée → `null`, `:readonly`
transmis par `BaseTextInput`/`BaseTextarea`, « Abandonner » relit la langue de départ sans perdre une saisie enregistrée.
R122 : aucun texte faux à destination d'un autre compte (compte comparé au montage, refus d'un autre compte écarté,
`pageKey`). R130 : une ligne dans `package.json` et le lockfile, même version que le transitif. R131/R132 : comportement
gardé ; aucune lecture brute du chemin dans `src/`.
