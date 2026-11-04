# Neuvième relecture de #748 — diff c585b960...2c0a6d57 (R88 à R101), 2026-10-31

Quatre relectures : `/code-review medium` (CR9-*), audit sécurité (C9-*), axe Standards (St9-*), axe Spec (Sp9-*).
**Aucun critique ni haut**, un moyen (St9-6, lisibilité). Sévérité / confiance après chaque constat ; doublons regroupés sous
le premier identifiant. L'audit a rejoué hors ligne les cinq `tools/tests/smoke*.test.sh` et `audit-prod.test.sh` (tous
verts), `make front-test` (2253), les trois gardes backend modifiées (69 tests, 4071 assertions), et le shell des quatre
scripts sous `bash -x` et sur TERM/HUP/INT (`set -m`) : valeur absente de la trace, dossier 0700, fichier 600, dossier
retiré à chaque signal, `SMOKE_AUTH_DIR`/`SMOKE_AUTH_CONFIG` hérités ignorés. Il a aussi comparé les `sha256` hôte et
conteneur des 40 fichiers modifiés : identiques, les runs portaient sur HEAD (réponse au doute de Sp9-7). Décompte des
méthodes des routes `/api` (conteneur `test`) : GET 47, HEAD 3, POST 24, PUT 22, DELETE 13, OPTIONS 1 (`/api/mcp`).

## Décisions de l'utilisateur (2026-10-31)
1. **CR9-1 / Sp9-9 : lier l'avis au chargement.** L'avis « enregistrement précédent » vaut pour **toutes** les lectures du
   chargement de la page d'arrivée et retombe à `'none'` dès que ce chargement se termine, réussi ou refusé ; toute
   lecture refusée ensuite rend `common.accessRefused`. Corrige les deux scénarios (amende la décision 4 de la huitième).
2. **CR9-9 : remettre la condition.** `if (isProposalShown)` rétabli dans le `watch` de `settings` d'`AdminAboutSettingsForm`,
   avec un test qui l'observe (enregistrer la source pendant une proposition vers l'autre langue garde l'avis de la cible).
3. **CR9-8 / C9-6 : échouer tôt.** `AUDIT_BASIC_AUTH` défini mais vide → sortie 2 « secret de Basic Auth vide » avant tout
   appel, cas ajouté à `audit-prod.test.sh` ; variable non définie (prod) inchangée.
4. **Sans suite** : St9-17 (emplacement de `useEntryFormLock.ts`), Sp9-8 (champ `heading`), C9-8 (angle mort de
   `getNativeType()`, pas même écrit), St9-11 (`describedBy` et voisins restent où ils sont).
5. Toujours valables : C5-1, C5-2, C7, CR6-6, CR7-2, la limite de R94, critère d'arrêt **tout jusqu'à zéro** : tous les
   autres constats se corrigent sans décision.

## Moyens
- **St9-6** le test principal d'`ApiPlatformOperationsCarryNoSecurityTest` porte la file, les DTO, les objets imbriqués et
  six assertions (standard global « éviter les méthodes trop longues ») → extraire `collectClassesToRead()`. Moyenne /
  moyenne.

## Faibles
- **CR9-1 ≈ Sp9-9** (à arbitrer, scénarios opposés) l'avis « enregistrement précédent » n'est lié par rien au chargement
  de la page d'arrivée (`backofficeAccessRefusal.ts:130`) : `'atMount'` ne recule que sur un refus. (a) CR9-1 — le
  chargement réussit, des minutes plus tard « Réessayer » ou une relecture est refusée (rôle perdu) : `'atMount'` →
  `'keptByARead'`, l'alerte dit encore l'enregistrement précédent au lieu de `common.accessRefused` ; idem si le
  chargement a été refusé avant le montage (le refus suivant est celui qui garde le texte). (b) Sp9-9 — sur une page qui
  lit plusieurs listes (À propos, Qualité), la deuxième lecture refusée **du même chargement** rend déjà
  `common.accessRefused`. Faible / moyenne-haute (vérifié : seule une lecture refusée fait avancer l'état).
- **C9-1** la justification de `/api/logout` dans `RULELESS_OUTSIDE_PUBLIC_PATHS` (`ApiRouteExposureTest.php:152-155`)
  dit que « le double-submit n'y protège rien d'autre » : faux en CSRF — un formulaire tiers `POST /api/logout` part sans
  `X-XSRF-TOKEN`, le navigateur accepte les `Set-Cookie` expirés malgré `SameSite=Lax`, le 403 du double-submit
  (priorité 20, avant `LogoutListener`) est la seule barrière contre la déconnexion forcée. Un mainteneur qui le croit
  ajoute la route à `EXCLUDED_PATHS`, aucun test ne rougit → réécrire la phrase + témoin `POST /api/logout` avec
  `XSRF-TOKEN` sans en-tête → 403, aucun cookie expiré. Faible / moyenne.
- **CR9-2 = St9-2 = C9-3** (+ Sp9-8, St9-1) deux vocabulaires : les messages d'échec d'`AuthorizationLivesInAccessControlTest`
  disent `kind->name` (`IsGrantedCall`, l. 92/442), `countByKind`/`testEveryExemptionIsStillNeeded` indexent par `->value`
  (`isGranted`, l. 291) ; le docblock d'`AuthorizationSiteKind` (hors diff) dit encore que les valeurs sont les libellés des
  messages et des exemptions. Faible / haute.
- **CR9-3 = St9-4** le docblock d'`AuthorizationSites.php` renvoie aux cas de l'enum, qui en disent moins : `Refusal` ne
  nomme plus `UnauthorizedHttpException` ni « nom court ou complet », `DecisionManager` a perdu pourquoi le nom et non
  `->decide(` (le consentement OAuth a un `decide()` homonyme), et « un refus levé à la main décide à la place
  d'access_control » a disparu. Faible / moyenne-haute.
- **CR9-4 = St9-7** `appClassesIn()` (`ApiPlatformOperationsCarryNoSecurityTest.php:218`) suit `CompositeType`,
  `WrappingType`, `CollectionType` mais pas les variables d'un `GenericType` non collection ni la clé d'une collection :
  `Paginator<App\Foo>` n'est pas lu, alors que le docblock promet « à toute profondeur ». Faible / moyenne.
- **C9-8** la lecture des objets imbriqués ne suit que `getNativeType()` : une classe atteinte par `array`/`mixed`/`object`
  sans type exploitable, ou rendue par un provider sans `output`, n'est pas lue ; probablement sans effet (API Platform
  n'évalue pas `ApiProperty(security:)` hors ressource, non vérifié) → écrire la limite. Info / faible.
- **CR9-5 = Sp9-11** l'invariant n° 10 (`ApiRouteExposureTest.php:335`) filtre par `str_starts_with($path, '/api')`, qui
  prend aussi `/apis…`, `/api-x` ; le plan (Interfaces de R89) disait `allApiRoutePaths()` → `'/api' === $path ||
  str_starts_with($path, '/api/')` ou la fonction existante. Faible / haute.
- **CR9-7 = Sp9-1** la garde `omit` des templates : `templateExpressionsOf` saute l'expression de `v-for` (le parseur la
  lit en mode `Skip`, `ast` `undefined`, `forParseResult` jamais parcouru — `compiler-core.cjs.js:2546`), alors que le
  choix 9 de R96 dit « `exp` des directives » ; et chaque expression est passée à `stringLiterals` comme un fichier TS
  isolé, si bien que `v-slot` déstructuré ou un littéral objet (`:opts="{ credentials: 'omit' }"`, lu comme bloc
  étiqueté) ne tiennent que par chance. Faible / haute (v-for), moyenne (le reste).
- **CR9-6** `templateExpressionsOf` (`sourceText.ts:155`) visite `node.content` quand `typeof === 'object'`, vrai pour
  `null` : un nœud à `content: null` ferait jeter `srcSources.ts` à l'import, et toutes les gardes qui l'importent
  échoueraient pour une autre raison. Faible / moyenne.
- **CR9-8 = C9-6** (+ St9-16) `[[ -v AUDIT_BASIC_AUTH ]]` (`tools/audit-prod.sh:55`) : un secret de préprod manquant exporte
  une valeur vide, la bibliothèque est sourcée (absente : sortie 2, nouveau), rien n'est écrit, l'audit court sans Basic
  Auth et échoue sur des 401 au lieu de dire le secret manquant (même effet final qu'avant la série). St9-16 : `audit-prod`
  range encore `-K` dans `CURL_OPTS` alors que `smoke_fetch` lit `SMOKE_AUTH_CONFIG` à chaque appel (la forme écartée par
  St8-12), et le commentaire Basic Auth est recopié dans les trois scripts de fumée. Faible / moyenne.
- **CR9-9** `AdminAboutSettingsForm.vue:196` : R98 a retiré `isProposalShown`, tout réglage reçu efface la proposition, y
  compris l'enregistrement de la langue source pendant une proposition vers l'autre. Le texte proposé, lui, est déjà
  perdu au changement de langue (décision 4 de la sixième série) : seul l'avis part plus tôt. Faible / moyenne.
- **C9-2** `handleTranslate` (huit formulaires, deux sur Qualité) ne se refuse pas lui-même pendant une proposition : seul
  le `:disabled` du bouton le fait, contre le motif écrit dans `useAdminAboutSettings.save` (« le refus vient d'ici et pas
  seulement du bouton désactivé ») → `if (lock.isProposalLocked) return` + un test par formulaire. Info / moyenne.
- **St9-8 = Sp9-2 = Sp9-3** compteurs et docblocks périmés : `describeEntryFormLock` (`entryFormLock.ts:94`) et sept specs
  disent « les quatre cas » pour huit ; `LOCKED_BY_PROPOSAL_TEXT` (`entryFormLock.ts:11`) omet « Version de » ; le
  docblock d'`EntryFormLockHint.vue` ne parle que du sélecteur de langue et dit qu'« Annuler la retire ». Contredit le
  « rien à corriger » de R101. Faible / haute.
- **St9-9** `hintId: '…-locale-locked-help'` et les clés `localeLockedEditing`/`lockedByProposal` nomment la langue alors
  qu'ils disent le verrou de tout le formulaire (Mysterious Name). Faible / moyenne.
- **St9-10** `hasProposal: () => null !== x.value` répété dans les huit appels de `useEntryFormLock` (prendre la ref ou un
  `MaybeRefOrGetter`) ; les specs gardent un `translateButton` local alors que le support exporte
  `translateEntryButton`. Faible / moyenne.
- **St9-11** `describedBy()`, `describedTexts()`, `textsDescribing()` sont génériques mais vivent dans des modules propres
  au verrou (Divergent Change). Faible / moyenne.
- **St9-12** `literalsOf()` (`noOmittedCredentials.spec.ts`) recompose ce que fait `sources`, et le vrai test utilise
  `literals.includes('omit')` et non `offendsWithOmit` : les tests du détecteur ne couvrent pas le chemin exécuté.
  Faible / moyenne.
- **St9-14** (+ Sp9-12) `sourceText.ts` : `stringLiterals` dit « gabarits sans `${` » puis « un gabarit à expression donne
  ses parties fixes » ; `TemplateNode.ast` « Vrai pour… » alors que la valeur peut être `null`/`false`/un nœud ; type fait
  main avec `as unknown as` au lieu de `@vue/compiler-core` ; reste d'historique « n'a plus de limite écrite ».
  Faible / moyenne.
- **St9-3** `EXEMPTIONS` en `list<array{AuthorizationSiteKind, int}>`, un tuple lu par position (ce que le docblock
  d'`AuthorizationSite` refuse), et `countByKind($this->expand($expected))` fait un aller-retour. Faible / moyenne.
- **St9-5** `PublicRouteIgnoresRefusedSessionListener.php` : « La prémisse — toute autorisation… du chemin. » — incise
  jamais fermée, phrase sans verbe. Faible / haute.
- **Sp9-4** le docblock de `refused` (`BackofficeSessionReaction.ts:57-60`) n'a pas la phrase de renvoi identique exigée
  par le choix 12 de R100. Faible / haute.
- **Sp9-5** R95 Step 1 exige les « textes FR exacts » : le second n'est vérifié que par `toContain`
  (`AccessRefusedNotice.spec.ts`). Faible / haute.
- **Sp9-6** lignes touchées > 140 : `ApiPlatformOperationsCarryNoSecurityTest.php:158` (143), `backofficeAccessRefusal.ts:146`
  (146, front sans contrainte écrite). Faible / haute.
- **Sp9-7** le commit R101 touche `brief-commun-agents.md` (hors tâche) ; le plan (≈ l. 5430, « une mutation s'écrit… avec
  l'outil Edit ») le contredit désormais. Le doute sur les preuves est levé par l'audit (`sha256` identiques). Faible /
  faible.

## Info
- **CR9-10 = St9-13** `sessionStateEncapsulation.spec.ts:227` : alias morts `const sources = SRC_SCRIPTS`, `const file = path`.
- **C9-9** `sessionStateEncapsulation.spec.ts:294` : un repli `?? ''` rendrait vide (donc verte) la lecture des `export { … }`
  de `useAuth.ts` si la clé changeait → `expect(source).not.toBe('')`.
- **C9-4** `smoke_fetch` (`smoke-http.sh:183-191`) : un fichier `-K` disparu (sortie 26 de curl) est dit « réseau, DNS ou
  délai » → `[ ! -f ]` → sortie 2 « fichier de la Basic Auth absent ».
- **C9-5** `smoke_write_auth_config` (`smoke-http.sh:109`) accepte les noms `SMOKE_AUTH_DIR`/`SMOKE_AUTH_CONFIG`, que le
  `smoke_cleanup` de tête vide avant lecture : rend 0 sans Basic Auth → les refuser + un cas de test.
- **C9-7** l'invariant n° 10 juge les méthodes déclarées, pas qu'une méthode sûre n'écrit pas : écrire dans le docblock que
  « sûre » est une convention tenue par la relecture.
- **Sp9-10** `security-authentication.md:32` : la phrase de R89 s'insère avant « Two since #647 », qui ne désigne plus
  clairement `EXCLUDED_PATHS` ; `ApiRouteExposureTest.php:326` « cette prémisse » sans antécédent.
- **St9-15** commentaires au passé (« celle qui était là… », « Depuis R96… ») contre la consigne « au présent » de 4cd1099a.
- **St9-17** `useEntryFormLock.ts` sous `presentation/ui`, décrit comme « Vue components » par `frontend.md` (précédents :
  `useDeliberateActivation.ts`, `useFocusThenAnnounce.ts`).
- **St9-18** `deploiement-nginx.md` : une puce de plus de 900 caractères, parenthèses sur trois niveaux.
- **Sp9-8** (reste) R91 ajoute un champ `heading` aux cas, hors de l'interface prescrite.

## Vérifié sain (audit)
R89 invariant n° 10 (toutes les routes déclarent leurs méthodes, `allowed_http_method_override: []`), R99 exemptions
(`array_search` strict sur des cas d'enum, unités consommées, nombres exacts par fichier, l'invariant n° 9 juge à
l'exécution), R94 (limite acceptée, serveur garde), R97 (clés relatives à `src/`), automate `earlierSave`, `countedWrite`
discriminé, aucun `v-html`/`innerHTML`/`eval`/`credentials`/`fetch` ajouté, plus aucune référence aux noms retirés,
`-K` jamais dans `SMOKE_CURL_OPTS`, préfixe `_smoke_` refusé.
