# Huitième relecture de #748 — diff 181d78d6...af12b068 (R73 à R87, R79b), 2026-10-30

Quatre relectures : `/code-review medium` (CR8-*), audit sécurité (C8-*), axe Standards (St8-*), axe Spec (Sp8-*).
**Aucun critique ni haut**, un moyen (conception). Sévérité / confiance après chaque constat ; doublons regroupés sous le
premier identifiant. L'audit a rejoué hors ligne `smoke-http`, `audit-prod`, `smoke-dead-session-cookie` et la
bibliothèque sous `set -x` et sur TERM/INT/HUP : dossier 0700, fichier 600, valeur absente de la trace, dossier retiré à
chaque signal, `SMOKE_AUTH_DIR` hérité ignoré.

## Décisions de l'utilisateur (2026-10-30)
1. **CR8-1 : désactiver le bouton.** « Proposer la version XX » est désactivé tant qu'une proposition est affichée, sur les
   huit formulaires, avec le texte d'aide du verrou ; pour retraduire, « Annuler ».
2. **CR8-4 : corriger le texte.** Les textes d'aide FR/EN disent qu'« Annuler » abandonne la proposition et vide le
   formulaire ; le comportement de `resetForm` ne change pas.
3. **Sp8-5 : verrouiller aussi « Version de ».** Le groupe de traduction est verrouillé pendant une proposition sur les huit
   formulaires, avec un texte d'aide lié comme pour la langue (cohérent avec D-b).
4. **CR8-3 : borner au chargement.** Seule la première lecture refusée après le montage garde l'avis « enregistrement
   précédent » ; les suivantes reprennent `common.accessRefused`. Le code suit le docblock.
5. Toujours valables : C5-1, C5-2, C7, CR6-6, l'identité du compte par son nom (CR7-2), critère d'arrêt **tout jusqu'à
   zéro**. Les autres constats se corrigent sans décision (moyens, faibles, info), sauf « Sans suite ».

## Moyens
- **CR8-7 = St8-3 = St8-4** le verrou de langue est recopié à la main dans les huit formulaires : le prédicat
  (`isEditing`, `null !== proposalSourceLocale`) trois fois par formulaire (`isLocaleLocked`, `:has-proposal`, le `v-if` de
  `LocaleLockedHint`), l'`aria-describedby` à identifiant littéral écrit deux fois, le commentaire de cinq lignes (abîmé
  par la substitution : « la version de la carte de l'entrée source »), le ternaire du texte refait pour la catégorie
  « moi » (Repeated Switches) ; quatre lignes > 140 caractères. Un prédicat de page et un de composant qui divergent
  laissent un IDREF pendant, que seul `describedTexts()` rattrape → un composable (`useLocaleLock`) qui rend l'état
  (`'none' | 'editing' | 'proposal'`) et l'`aria-describedby`. Moyenne (conception) / haute.

## Faibles
- **CR8-1 = Sp8-4** le verrou se contourne par une seconde traduction : `TranslateEntryButton` reste actif pendant une
  proposition ; « Proposer la version FR » sur la proposition EN remet `form.locale` à la langue source avec
  `sourceGroup` gardé — le cas exact de Sp7-1. Antérieur à la série, mais le verrou le promet fermé. Le serveur refuse
  (index unique `(translation_group, locale)`, 409, C8-8) : erreur d'enregistrement, pas de donnée fausse. Faible-moyenne /
  haute (vérifié : `AdminCaseStudiesPage.vue:251-264`, `:366-372`).
- **CR8-2 = Sp8-6** prémisse fausse dans le docblock de `LocaleLockedHint.vue` (« une proposition ne se produit que depuis
  une entrée en édition ») et dans le commentaire des huit sélecteurs (« son groupe gardé ») : `handleTranslate` marche
  aussi depuis un formulaire de création (`sourceGroup` nul) et depuis « Créer la version XX ». Le verrou y reste utile
  (la prose proposée est dans la langue cible), le texte est faux ; depuis « Créer la version », la proposition vise une
  langue que le groupe a déjà (409 serveur). Faible / haute.
- **CR8-4 = Sp8-9** `localeLockedProposal` et `categoryProposalLockedHelp` disent « « Annuler » permet d'en changer » ;
  « Annuler » (`resetForm`) vide tout le formulaire, prose générée et groupe compris → la personne perd la proposition
  (un nouvel appel au modèle, ADR 0004 coût borné). Texte prescrit par R79b mot pour mot. Faible / haute.
- **Sp8-5** « Version de » reste libre pendant une proposition sur les huit formulaires : « aucune » détache la
  proposition de sa source, la raison même de D-b. Écart déclaré en R79b, ni écrit ni décidé. Faible / moyenne.
- **CR8-3** `backofficeAccessRefusal.ts:97-104` : une lecture comptée après le montage garde `earlierSave` **sans borne**,
  pas seulement le chargement de la page d'arrivée que décrit le docblock de `settleBackofficeAccessRefusalsOnMount`
  (« le chargement de la page d'arrivée est parti avant ce montage ») ; une relecture refusée plus tard sur la même page
  (rôle perdu) ré-annonce « Un enregistrement précédent a été refusé » au lieu de `common.accessRefused`. Faible / moyenne.
- **CR8-5** `smoke-http.sh:96-104`, `:141-148` : `${!name}` lit d'abord les locales de la fonction ; la regex accepte
  `value`, `name`, `rc`, `traced`, `saved_umask`, `empty` → Basic Auth fausse et silencieuse (`smoke_add_basic_auth name`
  écrit l'utilisateur `name`). Aucun appelant aujourd'hui → préfixer les locales ou refuser ces noms. Faible / haute.
- **C8-1** `audit-prod.sh:53` : `[ -n "${AUDIT_BASIC_AUTH:-}" ]` développe le secret hors `set +x` → `bash -x
  tools/audit-prod.sh` l'imprime ; la promesse « hors de toute trace » (R75) ne tient que dans la bibliothèque. Antérieur,
  non aggravé → `[[ -v AUDIT_BASIC_AUTH ]]` et laisser la bibliothèque juger le vide. Faible / haute.
- **CR8-9 = C8-3** `ApiPlatformOperationsCarryNoSecurityTest.php:96` : les propriétés ne sont lues que sur les classes de
  ressource, pas sur les DTO `input`/`output` ni les objets imbriqués non ressources ; un
  `#[ApiProperty(security: …)]` y échappe (le docblock dit « chaque propriété »). Une `uriVariable` qui n'est pas un `Link`
  est ignorée sans bruit ; les paramètres (0 aujourd'hui) n'ont pas de garde contre la vacuité. Faible / moyenne.
- **CR8-10 = C8-7** `sourceText.ts:73` : `stringLiterals` ne lit ni `TemplateHead/Middle/Tail` (`` `${''}omit` `` passe
  `noOmittedCredentials`), ni les gestionnaires en ligne du `<template>` d'un `.vue`. Même famille que `'om\x69t'`, que
  R80 dit couvrir. Faible / moyenne.
- **St8-1 = Sp8-7** `frontend-backoffice.md:132-135`, puce #582 : « static `aria-describedby="<select id>-help"` » est faux
  depuis R79b (attribut lié, deux ids pendant le verrou, cartes et qualité) ; la puce D-a s'ajoute au lieu de remplacer.
  Faible / haute.
- **St8-2** `sourceText.ts` : la branche d'erreur de `scriptOf` (`.vue` illisible) n'a pas de test. Faible / haute.
- **St8-5** `formCancelButton` recopié dans six specs ; les quatre tests du verrou (~72 lignes) quasi identiques dans
  quatre specs → paramétrer dans `tests/support/localeLockHint.ts`. Faible / haute.
- **St8-6** le `import.meta.glob` + `Object.fromEntries(… scriptOf …)` et son docblock identiques dans
  `noOmittedCredentials.spec.ts` et `sessionStateEncapsulation.spec.ts` → extraire dans `sourceText.ts`. Faible / haute.
- **St8-8** `AuthorizationSites::in()` rend des tuples `array{int, AuthorizationSiteKind}` ; `EXEMPTIONS` indexées par
  `->value` écrit six fois ; le docblock de classe recopie la description de chaque `case`. Faible / moyenne.
- **St8-9** la règle d'identité du compte (R77 : `null` d'un côté, C8, CR7-3) est écrite cinq fois
  (`BackofficeSessionReaction.ts` ×2, `backofficeSessionExpiry.ts` ×2, `useAuth.ts`), contre le choix « une seule
  source » fait pour les témoins (St7-5). Faible / haute.
- **St8-11 = CR8-8** `smoke-http.sh` : validation du nom et sauvegarde/restauration de xtrace écrites deux fois
  (`smoke_add_basic_auth`, `smoke_write_auth_config`) ; `$(umask 077 && mktemp -d)` redondant ; ligne de commentaire cassée
  dans `audit-prod.sh:49` (« création du dossier : un »). Faible / haute.
- **St8-12 = C8-2** un second `smoke_add_basic_auth` (ou un second appel à nom invalide) laisse dans `SMOKE_CURL_OPTS` un
  `-K` vers un fichier que le nettoyage de tête a retiré (curl échoue, rien ne passe sans authentification) : « un second
  appel remplace le premier » ne vaut que pour `smoke_write_auth_config`. Sourcer la bibliothèque une seconde fois remet
  `SMOKE_AUTH_DIR` à vide et rend orphelin le dossier du premier appel. Aucun appelant aujourd'hui. Faible / moyenne.
- **St8-14** R86 : la condition `if (isProposalShown.value)` du `watch` de `settings` est inobservable (mutant
  équivalent) → la retirer, ou dire en commentaire pourquoi elle est défensive. Faible / moyenne.
- **Sp8-1** tâche 11 (point « Une proposition verrouille la langue (R78, R79) ») ne contrôle ni le texte d'aide (D-a), ni
  la catégorie verrouillée (D-b), ni la limite « Créer la version » ; le corps de PR de la tâche 12 non plus. Faible / haute.

## Info
- **CR8-6** `isSavingLocale(locale) || isSavingLocale(otherLocale(locale))` dit « un enregistrement en vol » des deux
  langues, à côté d'`isSaving` qui lit le même état : trois écritures d'un même état → un `isAnySaving`.
- **St8-7** le docblock de `sourceText.ts` raconte l'historique (R68, « la limite était fausse ») ; `withoutComments`
  retire le `\r` d'un commentaire que `scriptOf` garde.
- **St8-10** `RefusalState.countedWriteBy: { account } | null` : une union discriminée dirait « aucune écriture » contre
  « écriture sous aucun compte » ; seul `NO_REFUSAL` est figé à l'exécution.
- **St8-13** R73 : la part propre de C7-10 (« 401 et pas 403 ») n'est prouvée par aucune mutation de contexte (un 403
  servi à l'anonyme sur une route à règle). `/api/logout` est justifié deux fois avec deux raisons (`BASE_TIER_PATHS`,
  `RULELESS_OUTSIDE_PUBLIC_PATHS`).
- **C8-5** la raison de `RULELESS_OUTSIDE_PUBLIC_PATHS['/api/logout']` (« le double-submit refuse sans jeton ») est plus
  forte que le réel : une paire s'obtient en un clic (`base-access`). L'exception reste juste (n'expire que les cookies de
  l'appelant, ne sert rien) → reformuler.
- **C8-6** rien ne vérifie que chaque méthode de route sous `/api` est sûre ou dans `UNSAFE_METHODS` : une route `QUERY`
  ou une méthode propre sortirait du double-submit sans test rouge.
- **C8-4** invariant n° 9 : une route sans méthode déclarée n'est sondée qu'en GET, un paramètre de chemin ne prend
  qu'une valeur ; fermé aujourd'hui par `AccessMapStaysPathOnlyTest` et l'absence de `methods:` dans `access_control`.
- **Sp8-8** le plan (choix tranché 4, C7-3, R74) dit encore que `getLinks()` rend des `Link` : note « rectifié » à poser.
- **Sp8-10** la phrase de renvoi aux témoins devait être « identique partout » (R73) ; elle varie.
- **Sp8-11** `frontend-backoffice.md` : « eight forms, one text each » alors que deux textes sont possibles par formulaire.

## Sans suite
- **Sp8-2** pas d'audit axe en état verrouillé : non exigé par R79b, la tâche 11 audite le formulaire avec proposition.
- **Sp8-3** R82 n'est pas un écart : le plan prescrit `in_array($method, UNSAFE_METHODS)`.
- **C8-8** le verrou de langue est une présentation, le serveur est la garde (index unique, 409, `locale-immutable` sur
  PUT) ; le verrou de catégorie protège la cohérence, pas la sécurité (`ROLE_SUPER` seul).
- **C8-9** pas de fuite d'un compte à l'autre : au montage, le compte de l'écriture est comparé au compte courant ; un
  changement de compte remonte la page (`pageKey`). CR7-2 non aggravée.
- Écarts déclarés jugés acceptables : R73 mutation (d), R74 `getLinks()`, R79b `categoryProposalLockedHelp`, R83
  nettoyage avant la validation, R86 mutant équivalent (voir St8-14), cases du plan non cochées (convention).

## Tranché
CR8-1, CR8-4, Sp8-5 et CR8-3 : voir les décisions 1 à 4 ci-dessus (2026-10-30).
