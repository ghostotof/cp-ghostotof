/**
 * Les champs que l'assistant de traduction envoie et remplace, un formulaire après l'autre : la prose. Chaque page
 * reste seule à décider des siens (spec 0004, D2) ; la liste est lue par `useTranslatedForm` (ce qu'il envoie, remplace, et
 * ce qui rend le formulaire traduisible : `hasProse`) et par le spec de la page (les champs qui doivent être en lecture
 * seule pendant une traduction).
 * Le complément de chaque liste est ce que « Créer la version XX » recopie de l'entrée existante : une clé d'icône, une
 * catégorie, une version, une date, une URL ou un nombre d'années n'ont pas de traduction (spec 0002, D2 ; spec 0004 §7).
 *
 * Contenu du palier de base (ADR 0003 D5) pour les études de cas et le CV sans identité : la règle éditoriale — ni nom,
 * ni employeur, ni client — vaut pour la proposition autant que pour l'original, et c'est la relecture humaine qui la
 * garantit.
 *
 * L'identifiant du champ dans le formulaire suit `<préfixe du formulaire>-<champ en kebab>` : les gabarits l'écrivent en littéral
 * (la section de Qualité le construit de son genre et du nom du champ, `qualityEntrySection.ts`, qui lit aussi les deux listes de
 * Qualité), les specs le dérivent de ces listes (`tests/support/proseFieldId.ts`).
 */

/** Incidents. Le complément : `version`, `occurredAt`. */
export const INCIDENT_PROSE_FIELDS = ['title', 'impact', 'rootCause', 'resolution', 'invariant'] as const

/** Contributions. Le complément : `project`, `reference`, `url`. */
export const CONTRIBUTION_PROSE_FIELDS = ['title', 'summary', 'body'] as const

/** Études de cas : tout est prose, « Créer la version XX » n'a rien à recopier. */
export const CASE_STUDY_PROSE_FIELDS = ['title', 'problem', 'solution', 'tradeoffs', 'measuredResult'] as const

/** CV sans identité. Le complément : `yearsOfExperience`. */
export const ANONYMOUS_CV_PROSE_FIELDS = ['title', 'skills', 'achievements'] as const

/** Principes de qualité. Le complément : `iconKey`. */
export const QUALITY_PRINCIPLE_PROSE_FIELDS = ['title', 'description'] as const

/** Traits de qualité : un libellé court, mais un libellé se traduit, et c'est son seul champ. */
export const QUALITY_TRAIT_PROSE_FIELDS = ['label'] as const

/** Cartes du site. Le complément : `iconKey`. */
export const SITE_CARD_PROSE_FIELDS = ['title', 'description'] as const

/** Cartes « moi ». Le complément : `iconKey` et `category`. */
export const ME_CARD_PROSE_FIELDS = ['title', 'description'] as const

/** Réglages « À propos » : les cinq textes. */
export const ABOUT_SETTINGS_PROSE_FIELDS = [
  'siteEyebrow',
  'meEyebrow',
  'technicalSubtitle',
  'personalSubtitle',
  'hobbiesSubtitle',
] as const
