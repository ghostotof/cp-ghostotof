import { computed, reactive, readonly, toValue, type MaybeRefOrGetter } from 'vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'

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
  /**
   * Vrai pendant une proposition seulement : « Proposer la version XX » (une seconde traduction depuis la
   * proposition ramènerait le formulaire à la langue source, groupe gardé, Sp7-1) et, avec elle, ce que la
   * proposition fige sans que l'édition le fige (R93).
   */
  readonly isProposalLocked: boolean
  /** Identifiant du texte qui dit le verrou (`EntryFormLockHint`), rendu seulement pendant le verrou. */
  readonly hintId: string
  /** `aria-describedby` du sélecteur de langue : son aide permanente s'il en a une, puis le texte du verrou pendant le verrou. */
  readonly localeDescribedBy: string | undefined
  /** `aria-describedby` d'un contrôle que seule la proposition verrouille : le texte du verrou pendant elle, rien sinon. */
  readonly proposalDescribedBy: string | undefined
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
 * (Sp7-1). « Annuler » abandonne la proposition, vide le formulaire et rend les contrôles.
 *
 * Pendant une proposition, un seul texte (`admin.entryLock.proposal`) est désigné par chaque contrôle verrouillé :
 * la langue, « Version de » (R94 : « aucune » détacherait la proposition de sa source), « Proposer la version XX »
 * (R93) et, dans les cartes « moi », la catégorie. En édition, « Version de » reste libre.
 *
 * Une proposition naît de trois gestes : l'édition d'une entrée, « Créer la version XX » (qui pose le
 * groupe de la ligne et la langue manquante) ou un formulaire vierge (aucun groupe). Le verrou de la
 * langue est juste dans les trois cas, la prose proposée étant dans la langue cible ; le groupe gardé
 * est celui de la source quand elle en a un, sinon aucun.
 *
 * Limite acceptée (Q1 de la huitième relecture, décidée par l'auteur le 2026-10-30) : une proposition dont la
 * langue cible existe déjà dans le groupe (depuis « Créer la version XX », ou depuis l'édition d'une entrée
 * dont le groupe a les deux langues) se termine, à l'enregistrement, sur le 409 `translation-already-exists` ;
 * « Version de » étant verrouillé, « Annuler » est la seule sortie. Le détecter recopierait au frontend la
 * règle d'unicité du serveur, ce que #573 a refusé. Depuis un formulaire vierge, la proposition part sans
 * groupe et remplace la prose source, jamais enregistrée (antérieur, spec 0004).
 *
 * L'objet rendu est un `reactive` en lecture seule : le gabarit lit `lock.isEntryLocked` sans `.value`
 * (un `computed` rangé dans un objet ordinaire n'est pas déballé par le gabarit, la condition serait
 * toujours vraie).
 */
export function useEntryFormLock(options: {
  readonly hintId: string
  /** Vrai quand le formulaire édite une entrée existante : la ref du formulaire, ou un getter. */
  readonly isEditing: MaybeRefOrGetter<boolean>
  /** Locale de l'entrée dont le formulaire est la proposition traduite, `null` hors proposition : la ref du formulaire, ou un getter. */
  readonly proposalSource: MaybeRefOrGetter<Locale | null>
  /** Identifiant de l'aide permanente du sélecteur de langue (#582), quand il en a une. */
  readonly localeHelpId?: string
}): EntryFormLock {
  const state = computed<EntryFormLockState>(() => {
    if (toValue(options.isEditing)) {
      return 'editing'
    }

    return null !== toValue(options.proposalSource) ? 'proposal' : 'none'
  })
  const isEntryLocked = computed(() => 'none' !== state.value)
  const isProposalLocked = computed(() => 'proposal' === state.value)
  const proposalDescribedBy = computed(() => (isProposalLocked.value ? options.hintId : undefined))
  const localeDescribedBy = computed(() =>
    describedBy(options.localeHelpId, isEntryLocked.value ? options.hintId : undefined),
  )

  return readonly(
    reactive({
      state,
      isEntryLocked,
      isProposalLocked,
      proposalDescribedBy,
      hintId: options.hintId,
      localeDescribedBy,
    }),
  )
}
