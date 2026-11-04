# Quatorzième relecture de #748 — diff 7b6de067...68c88b96 (R152 à R167, R157b), 2026-11-01

Quatre relectures : `/code-review medium` (CR14-*), audit sécurité (C14-*), axe Standards (St14-*), axe Spec (Sp14-*).
**Aucun critique ni haut.** Moyens : **C14-1 = St14-20 = Sp14-13** (options de trace de curl), **St14-9** (ordre de
`removeEntry` non vérifié), **St14-21 = Sp14-18** (« aucun processus avant » faux). Avant les relectures, le fil principal a
rejoué sur conteneurs redémarrés les cinq cibles `make`, les 26 tests hors ligne et `check-claude-rules` : tous à 0 (front
2561, back 5655). L'audit a rejoué la PoC de C13-1 : **fermée** (curl 8.5.0 et 8.14.1, l'écouteur sur 127.0.0.1 ne reçoit
rien) ; C13-2 à C13-5 fermés, dans la limite « bonne foi » écrite. Rejeux dans le scratchpad de la session (`poc.sh`,
`poc2.sh`, `fakecurl.sh`, `guard.sh`, `cases.txt`). Trois relectures convergent sur le même fond : la protection de la Basic
Auth est une **liste d'interdits** qui grandit à chaque relecture (CR14-2).

## Décisions de l'utilisateur
Prises le 2026-11-01 : **les quatre recommandations**.
- Q1 → **liste d'autorisation** des options que les smokes passent réellement (relevées par `grep` des appels), tout autre
  argument refusé avec la Basic Auth, une seule URL positionnelle (celle qui est jugée), `-q` en premier argument (dans
  `smoke_fetch` et chaque `curl` authentifié de l'audit).
- Q2 → **réordonner** les trois smokes (`trap`, `smoke_use_auth_config` juste après le `source`, puis `mktemp`/`openssl`/
  `date`) : la limite générale des fils (R167) disparaît, seule reste celle du `rm -rf` de tête.
- Q3 → **`unset` du nom juste après la lecture** (un drapeau privé garde « défini »), plus les deux corrections ciblées de
  la garde (`set` après n'importe quel mot, `p`/`n` dans toutes les grappes d'options).
- Q4 → **renommer le membre** de `useTranslatedForm` en `translationErrorText` (l'alias disparaît des neuf sites).
- Tout le reste se corrige (critère « tout jusqu'à zéro ») ; CR14-6 écarté (raison dans « Info »).

Questions posées :
1. **Liste d'interdits ou liste d'autorisation** (CR14-1, CR14-2, C14-1, C14-2, C14-3, C14-4, C14-10, St14-20, St14-24,
   Sp14-13, Sp14-14, Sp14-15) : reco **une liste d'autorisation** des options que les smokes passent réellement (à mesurer
   par `grep` des appels), tout autre argument refusé avec la Basic Auth, une seule URL positionnelle (celle qui est jugée),
   et `-q` en premier argument (le `~/.curlrc` n'est plus lu) — ferme la classe (trace, proxy, DoH, `--cacert`, seconde URL,
   `--proto-default`, options futures). Option : étendre la liste d'interdits (`verbose`, `trace*`, `libcurl`, `v` des
   grappes, `proto-default`, routes et CA, `-q`).
2. **Processus fils lancés avant `smoke_use_auth_config`** (St14-21 = Sp14-18, C14-9, St14-28) : les commentaires des trois
   smokes et une ligne de la règle disent « aucun processus avant », alors que la limite générale (R167) les admet. Reco :
   **réordonner** (le `trap`, puis `smoke_use_auth_config` juste après le `source`, puis `mktemp`/`openssl`/`date`) : la
   promesse devient vraie et la limite générale disparaît (il ne reste que le `rm -rf` de tête, limite gardée). Option :
   réécrire les quatre textes et garder la limite.
3. **Garde des vidages** (C14-5, Sp14-20, St14-29) : `if set`, `then set`, `! set`, `time set`, `declare -g -p`,
   `local -r -n`, le `e` BSD de `ps` passent encore. Reco : **`unset` du nom juste après la lecture** (la définition gardée
   dans un drapeau privé lu par `_smoke_require_auth_config`) — plus aucun `set` ou `declare -p` ne peut imprimer la valeur,
   la garde reste comme filet de bonne foi ; plus les deux corrections ciblées (`set` après n'importe quel mot, `p`/`n` dans
   toutes les grappes). Option : seulement les corrections ciblées.
4. **L'alias `errorText: translationErrorText`** des neuf sites (St14-5 ; l'axe Spec le juge conforme) : reco **renommer le
   membre du composable en `translationErrorText`** (les pages le lisent déjà sous ce nom : l'alias disparaît, aucun gabarit
   ne change). Option : garder l'alias.

## Moyens
- **C14-1 = St14-20 = Sp14-13** `tools/lib/smoke-http.sh:250,286-288` (et `SMOKE_CURL_OPTS`, `:340-345`) : `-v`/`--verbose`,
  `--trace`, `--trace-ascii`, `--libcurl` ne sont pas refusés avec la Basic Auth. Mesuré (curl 8.5.0, `--network none`,
  identifiant factice) : `-v` imprime `> Authorization: Basic …` ; `--libcurl -` imprime `CURLOPT_USERPWD` en clair (dans
  `SMOKE_BODY`, dont les extraits sont imprimés). Premier réflexe de débogage d'un 401 de préprod : l'identifiant finit dans un
  terminal ou un transcript. Le docblock promet qu'aucun argument ne fait partir l'identifiant en clair. Moyenne / haute.
- **St14-9** `useTranslatedForm.spec.ts`, cas `removeEntry` : l'ordre « péremption avant `remove` » (D7) n'est pas vérifié ;
  `try { await remove() } finally { expireIfDeleted() }` passerait (lire `isTranslating` dans le faux `remove`). Moyenne /
  haute.
- **St14-21 = Sp14-18** `tools/smoke-mcp.sh:61-62`, `smoke-login-throttling.sh:47-48`, `smoke-dead-session-cookie.sh:56-57`,
  `outillage-smoke.md:64-66` : « aucun processus ne doit être lancé avant » le retrait de l'export est faux (`mktemp`,
  `date +%s`, `openssl rand`) ; la règle se contredit (limite générale l. 28-31 / interdiction l. 65) ; le cas `dirname` ne
  protège qu'un fils sur quatre ; la limite générale n'est pas attribuée dans le plan (voir Q2). Moyenne / haute.

## Faibles
- **CR14-1 = C14-4 = St14-24 = Sp14-15** options de route et de confiance non jugées (`-x`, `--proxy`, `--preproxy`,
  `--socks5*`, `--unix-socket`, `--abstract-unix-socket`, `--doh-url`, `--cacert`/`--capath`, `--proxy-insecure`,
  `--doh-insecure`, `--netrc-file`) ; combinées (route + CA de l'attaquant) = MITM, `--proto =https` n'y peut rien ;
  `HTTPS_PROXY`, `SSLKEYLOGFILE` non cités. Faible / moyenne (voir Q1).
- **CR14-2** altitude : la liste d'interdits grandit à chaque relecture et à chaque version de curl (`--follow` en 8.16) ;
  une liste d'autorisation des options réellement passées fermerait la classe (voir Q1). Moyenne-faible / moyenne.
- **C14-2 = Sp14-14** une seconde URL `https` positionnelle, ou `--proto-default https` + un argument sans schéma, envoie la
  Basic Auth à un autre hôte en TLS (le `user` du `-K` vaut pour toutes les URL) ; le test `smoke-http.test.sh:597-617` les
  tient pour voisins permis. Faible / haute.
- **C14-3** pas de `-q` : `~/.curlrc` (ou `$CURL_HOME`, `$XDG_CONFIG_HOME`) est lu avant la ligne de commande ; un
  `location-trusted` y rouvre les redirections authentifiées (mesuré : `CURLOPT_UNRESTRICTED_AUTH 1`, 0 avec `-q`). Faible /
  haute (mécanisme), faible (exposition).
- **C14-5 = Sp14-20** garde des vidages : `if set; then`, `if set | grep`, `then set`, `time set`, `! set > f`,
  `declare -g -p NOM`, `local -r -n ref=NOM` (seule la 1re grappe d'options lue), `ps -p $$ e`, `ps -o args= -p $$ ewww`
  passent ; `ps -ef` refusé à tort (sans danger) ; `export -n` garde la variable dans le shell, donc un `set` qui passe
  imprime la valeur (voir Q3). Faible / haute.
- **CR14-3 = C14-6 = St14-23** `audit-prod.sh:129-131` : l'indice du 401 lit `${SMOKE_AUTH_CONFIG:-}` ; en prod la
  bibliothèque n'est pas chargée, une valeur héritée de l'environnement n'est pas remise à zéro → indice faux (contraire à
  C7-6) → drapeau local ou `SMOKE_AUTH_CONFIG=""` en tête. Faible / haute.
- **CR14-4** grappes courtes refusées si elles portent `L`/`K`/`k`/`:` : une valeur collée (`-HAccept-Language: fr-CA`,
  `-o/tmp/klog`) → sortie 2 sans nommer l'argument, diagnostic difficile (limite Q11) — couvert par Q1. Faible.
- **CR14-5** `useTranslatedForm.ts:461` prend `form` à l'appel : l'ordre de déclaration (`form` avant l'appel) n'est imposé
  que par un commentaire (TDZ) ; un getter ou une lecture paresseuse lèverait la contrainte. Faible / moyenne.
- **St14-1** `AdminQualityPage.vue:30` dit que les deux panneaux « partagent déjà leur sélecteur de langue » (faux, chaque
  section a le sien). **St14-2** `AdminQualityPrinciplesSection.vue:242` cite « le sélecteur de la page » (n'existe plus).
  **St14-3** le découpage de Qualité a perdu deux paragraphes du docblock (D8 : « Les tableaux affichent toutes les langues…
  `list()` a perdu son paramètre de locale » ; « Rien n'est rechargé derrière… » en partie). Faible / haute.
- **St14-4** les sections de Qualité lisent `props.isLocked`, à jour seulement après l'`emit` et le rendu du parent (avant :
  `isAnyOrderDirty` synchrone) : un `translate` programmatique entre un `move` et le flush n'est plus refusé (une
  micro-tâche ; « À propos » fonctionne déjà ainsi). Faible / moyenne.
- **St14-6** commentaire « Avant toute écriture… (R152) » ×16, `handleTranslate`, le lambda `switchForm` et son docstring ×8.
  **St14-7 = CR14-7** `describedBy.ts:5-9` énumère 13 appelants (périme au premier oubli) ; idem `MemberCalls.php:11-12`,
  `MemberCallsTest.php:16`, `PathIsDecidedCanonicallyTest.php:28-29` (St14-31). Faible / haute.
- **St14-8** `expireIfDeleted` n'a plus de lecteur de page (seul le spec) ; `translate` ne sert qu'aux réglages, qui
  recopient `collectProseFields(form, ABOUT_SETTINGS_PROSE_FIELDS)` et `props.locale` que le composable connaît.
  Faible / moyenne.
- **St14-10 = Sp14-6** la garde exempte tout `watch` `immediate`, dont les exécutions suivantes ont lieu après le montage :
  le vrai critère est « la première exécution » (docblock, règle). **St14-11** le cas « source d'une création rattachée »
  repose sur un contrat implicite (`startEdit` ouvre la source de `startCreateVersion`), ni vérifié ni signalé. Faible.
- **St14-22** moignons du déplacement vers `outillage-smoke.md` : l. 16 « …and this one » (renvoyait à `smoke-mcp.sh`, omet
  l'audit), l. 90 « (`CURL_OPTS` here) » (renvoie à un texte absent). **Sp14-9** `frontend-backoffice.md:254-255` (« « » en fin
  de ligne, « Annuler ») » en tête de la suivante). **Sp14-12** `frontend-session-refus.md:28-29` coupe un code en ligne.
  Faible-info / haute.
- **St14-25 = Sp14-19** les tests `dirname` des trois smokes ne vérifient que `! grep`, sans le témoin `-s` d'`audit-prod.test.sh:157` ni le `rc` : ils passent à vide ; le bloc est recopié quatre fois. **St14-26** `smoke-cases.sh:46` `exec $real_dirname`
  sans guillemets (SC2016 sur la ligne). Faible / haute.
- **St14-30** `MemberCallsTest.php` : « le spec rend » (vocabulaire Vitest), `testEachPredicateKeepsTheNamesItsFormCalls`
  peu lisible, `testAnIndexOutsideTheTokensIsNoCall` teste l'index 0 (dans le tableau). **St14-32** le comptage canonique
  dépend de `ClassNameResolver::imports`, qui ne lit pas un `use` groupé (l'ancien compteur le voyait ; aucun cas ;
  `ImportsStaySortedTest` refuse ces imports dans `src/`). **CR14-8** `ClassNameResolver::scopes` compte un `use` de trait et
  ne remet pas les imports à zéro à la fin d'un bloc `namespace X { }`. Faible / moyenne-faible.
- **Sp14-1** `sessionEndedLoad.spec.ts:104-160` : 6 entrées sur 13 tiennent sur une ligne (St13-18), le bilan de R167 dit
  « treize » (faux). **Sp14-2** des sondes de `backofficeAccessRefusal.spec.ts` / `AccessRefusedNotice.spec.ts` qui n'envoient
  rien passent par `stampBackofficeRead()` et ferment au passage le chargement de la page d'arrivée (un test peut passer pour
  une autre raison). **Sp14-4** `AdminAboutPage.spec.ts:~1830` dit encore que la proposition « ne survit pas à une lecture de
  la cible qui échoue » (faux depuis R157b). Faible / moyenne-haute.
- **Sp14-7** la garde ne voit pas `onBeforeUnmount`, `onUnmounted`, `onDeactivated`, `setInterval`, `onBeforeRouteUpdate`,
  `.then(load)` ; « Non vu » ne cite que `await`. **Sp14-8** `vueCompilerAlignment.spec.ts:35-36,51` lit `dependencies` avant
  `devDependencies` mais la commande écrit toujours `devDependencies`. Faible / moyenne.
- **Sp14-16** `AuthorizationSites.php:111,143` : seule la signature est unifiée, `$previous` relu par `ofName` puis `ofCall`
  (St13-57), écart non consigné. **Sp14-17** `CompiledNestedReading.php:11-12` : rétabli par d8f481a2, réécrit une troisième
  fois par 68c88b96 (R167) — St13-59 défait, non dit. **Sp14-21** `PathIsDecidedCanonicallyTest.php:140-189` : trois paires
  de cas au code identique. Faible / haute.
- **CR14-6** (écarté par le fil principal, voir « Info »).

## Info
- **CR14-6** (écarté) : une lecture de la cible des réglages en échec sans changement de langue laisse la proposition en
  attente jusqu'au prochain chargement réussi de la même clé — c'est exactement le cas « fin de session, posée au retour »
  voulu (Q10) ; R157b a établi qu'aucun autre chemin ne relit la cible.
- **C14-7** `smoke-cases.sh` « jamais exportées » faux si l'appelant exporte déjà ces noms (une locale hérite de l'attribut
  d'export) → `export -n` des sept noms ou nuancer. **C14-10** un argument sans schéma refusé par curl n'échoue pas l'appel
  (code de la dernière URL, corps préfixé de `\n000`) — couvert par Q1. **C14-11** calcul du dossier sans `dirname` :
  `CDPATH` positionné → `cd` imprime, le `source` échoue (fermé) ; script à la racine → `cd ""` ; `CDPATH= cd -- "$dir"`.
- **C14-8** `useTranslatedForm.ts:139-148` : une micro-tâche sépare le jugement `isCurrent` (`translate`) de la pose
  (`proposeFrom` après `await`) ; un `resetForm()` résolu dans le même point de contrôle passerait entre les deux → un rappel
  `apply` exécuté sous `isCurrent`. Confiance faible.
- **St14-12** `standardCase` sert aussi aux cas non standard ; « onze composables » figé. **St14-13** `form`/`fields`
  inversés dans `MountedTranslatedForm`. **St14-14** `it.each` avec boucle et attente conditionnelle, nom « le prédicat de la
  page ». **St14-15** littéraux `'Enregistrer'`, `'Supprimer'`, `'Annuler'`, `'Proposer la version'` antérieurs.
  **St14-16** commentaires au passé (`AdminQualityPage.vue` « a été supprimé… il permettait », `AdminAboutPage.spec.ts`
  « l'ancien… », `AdminAboutSettingsForm.vue` « il n'aurait rien retiré »). **St14-17** docstring de production qui justifie
  l'export de `currentRefusalScope` par les specs ; `resetTo` à un appelant. **St14-18** `en.json` `admin.entryLock.proposal`
  « “Cancel” discards it » (la règle réserve « discard » aux réglages ; texte affiché). **St14-19** fonctions longues
  (`useTranslatedForm` ≈ 75 lignes de closure, `lockToolkit` > 20 membres non typés, `describeExpiry` ≈ 195 lignes).
- **St14-27** « seule `smoke_use_auth_config` l'appelle » / le test l'appelle aussi → « le seul appelant de production ».
  **St14-29** faux positifs probables de `pat_environ`/`pat_ps` en prose. **St14-33** `ofStringLiteral(array, int)` ne lit
  qu'un jeton. **St14-34** quadruplet `CASE_*` répété. **St14-35** `_SMOKE_REFUSED_LONG` modifiable par l'appelant (limite
  St8-12). **St14-36** `deploiement-nginx.md:134` « Its » ambigu.
- **Sp14-3** docblock de `currentRefusalScope` au pluriel alors qu'un seul lecteur reste. **Sp14-5** le texte Q6 « Si elle
  aboutit, la proposition s'ouvre dans la langue cible » inexact pour une réponse périmée et pour les réglages dont la
  lecture de la cible échoue (texte validé par l'auteur). **Sp14-10** `button[aria-busy]` prend le premier bouton qui porte
  l'attribut (fragile). **Sp14-11** D1 « huit formulaires (neuf sites) » / docblock « neuf formulaires ». **Sp14-22**
  `MemberCalls` ne voit pas un nom semi-réservé après `::` (`X::list(`, pas une régression). **Sp14-23** R163 « refus plus
  larges que la liste du plan » (le choix 12 les listait) ; `outillage-smoke.md` « Each offline test … records the
  environment of a fake `dirname` » faux pour `smoke-http.test.sh`. **Sp14-24** commandes et nombres du balayage R167 au seul
  rendu de l'agent ; R166 Step 3 sans ligne au bilan.

## Écarts déclarés, jugés (Spec)
Acceptables : R152, R153 (`form` sans `Readonly`, alias), R154, R155, R157b, R160, `echo` recollés (13 lignes laissées,
exactement), index après `release.md`, `ClassNameResolver`, `MemberCallsTest` sous `tests/Shared/`, casse ignorée, limite du
nettoyage. Inexact : le bilan R158 (Sp14-1). À confirmer : la limite générale des fils (Q2). Les 98 identifiants de la 13e
relecture sont tous dans la traçabilité ; restent ouverts ou partiels St13-18 (Sp14-1), St13-57 (Sp14-16), St13-59 défait
(Sp14-17).

## Vérifié sain
Audit : PoC C13-1 fermée (8.5.0 `Protocol "http" not supported or disabled` ; 8.14.1 via la bibliothèque, l'écouteur ne
reçoit rien) ; l'audit pose `-K … --proto =https --proto-redir =https`, ses sondes anonymes n'ont ni `-K` ni `--proto` ;
C13-2 (grappes, `--insec`), C13-3 (préfixe sans casse), C13-4 (formes testées vues, exemption d'une ligne), C13-5
(`export -n` dès la lecture, aucun `dirname`) ; R152 (`renewForm()` en tête des 16 gestes), R153 (`proposeFrom` ne pose rien
sur `null`, `removeEntry` périme avant), R157/R157b, R158, R161, R160 (pas de script de cycle de vie). Standards/Spec :
régressions rouges avant / vertes après pour R152, R156, R157, R159, R160 ; anciens noms introuvables ; `FormIdentity` en
application ; règles sous 50 000 octets ; outillage 286/30/38/28/39 ok ; shellcheck à 0 ; PHPUnit ciblé 134 OK ;
`entryFormLock.ts` 26 → 29 cas, aucun perdu.

## Portfolio (à proposer, rien rédigé)
Rubrique « Études de cas » : une garde de « bonne foi » contre la fuite d'un secret dans des scripts shell et ses limites —
une liste d'interdits d'options `curl` grandit à chaque relecture (`-v`, `--libcurl`, `--doh-url` + `--cacert`), ce qu'une
liste d'autorisation et `-q` ferment d'un coup.
