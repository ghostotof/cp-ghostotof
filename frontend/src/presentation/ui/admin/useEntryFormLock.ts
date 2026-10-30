import { computed, reactive, readonly } from 'vue'

/**
 * Ce qui verrouille un formulaire par entrée : rien, l'édition d'une entrée existante, ou une
 * proposition de traduction affichée. L'édition l'emporte (les deux ne se produisent pas ensemble :
 * une proposition remet le formulaire en création).
 */
export type EntryFormLockState = 'none' | 'editing' | 'proposal'

export interface EntryFormLock {
  readonly state: EntryFormLockState
  /** Vrai en édition comme pendant une proposition : la langue (et la catégorie d'une carte « moi ») ne change pas. */
  readonly isEntryLocked: boolean
  /** Identifiant du texte qui dit le verrou (`EntryFormLockHint`), rendu seulement pendant le verrou. */
  readonly hintId: string
  /** `aria-describedby` du sélecteur de langue : son aide permanente s'il en a une, puis le texte du verrou pendant le verrou. */
  readonly localeDescribedBy: string | undefined
}

/**
 * Les identifiants non vides, joints par une espace (la forme d'un `aria-describedby`) ; `undefined`
 * s'il n'y en a aucun, pour que l'attribut ne soit pas rendu.
 */
export function describedBy(...ids: ReadonlyArray<string | undefined>): string | undefined {
  const present = ids.filter((id): id is string => undefined !== id && '' !== id)

  return 0 === present.length ? undefined : present.join(' ')
}

/**
 * Le verrou d'un formulaire par entrée (#748, D-a et D-b de l'auteur ; R92) : le prédicat, l'état et
 * les identifiants ARIA sont écrits ici une fois pour les huit formulaires (incidents, contributions,
 * CV sans identité, études de cas, principes, traits, cartes du site, cartes « moi »). Le texte
 * (`EntryFormLockHint`) et les `aria-describedby` lisent le même état : une référence vers un texte
 * absent est impossible par construction.
 *
 * Deux verrous. En édition, la langue d'une entrée existante ne change pas (un `PUT` ne la change pas,
 * 409 `locale-immutable`). Pendant une proposition, le formulaire est une création dans la langue
 * cible : changer la langue en ferait une seconde version de la langue source dans le groupe gardé
 * (Sp7-1). « Annuler » retire la proposition et rend le sélecteur.
 *
 * Une proposition naît de trois gestes : l'édition d'une entrée, « Créer la version XX » (qui pose le
 * groupe de la ligne et la langue manquante) ou un formulaire vierge (aucun groupe). Le verrou de la
 * langue est juste dans les trois cas, la prose proposée étant dans la langue cible ; le groupe gardé
 * est celui de la source quand elle en a un, sinon aucun.
 *
 * L'objet rendu est un `reactive` en lecture seule : le gabarit lit `lock.isEntryLocked` sans `.value`
 * (un `computed` rangé dans un objet ordinaire n'est pas déballé par le gabarit, la condition serait
 * toujours vraie).
 */
export function useEntryFormLock(options: {
  readonly hintId: string
  readonly isEditing: () => boolean
  readonly hasProposal: () => boolean
  /** Identifiant de l'aide permanente du sélecteur de langue (#582), quand il en a une. */
  readonly localeHelpId?: string
}): EntryFormLock {
  const state = computed<EntryFormLockState>(() => {
    if (options.isEditing()) {
      return 'editing'
    }

    return options.hasProposal() ? 'proposal' : 'none'
  })
  const isEntryLocked = computed(() => 'none' !== state.value)
  const localeDescribedBy = computed(() =>
    describedBy(options.localeHelpId, isEntryLocked.value ? options.hintId : undefined),
  )

  return readonly(
    reactive({
      state,
      isEntryLocked,
      hintId: options.hintId,
      localeDescribedBy,
    }),
  )
}
