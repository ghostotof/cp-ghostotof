import { flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import TranslateEntryButton from '../../src/presentation/ui/admin/TranslateEntryButton.vue'
import { expectNoAccessibilityViolation } from './axe'
import { SUPPORTED_LOCALES } from '../../src/domain/portfolio/entities/Locale'
import type { AdminTranslationRepository } from '../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import { proseFieldId } from '../../src/presentation/ui/admin/useTranslatedForm'
import {
  DISCARD_PENDING_FR,
  ENTRY_LOCK_EDITING_FR,
  ENTRY_LOCK_PROPOSAL_FR,
  PROSE_READONLY_FR,
  TRANSLATION_UNAVAILABLE_FR,
} from './adminTranslationTexts'
import { AdminTranslationError } from '../../src/domain/admin/translation/errors/AdminTranslationError'

/**
 * Les textes que désigne l'`aria-describedby` du champ `selector`, dans l'ordre des identifiants ;
 * `[]` sans attribut. **Un identifiant qui ne désigne aucun élément fait échouer le test** : une
 * IDREF pendante est elle-même une faute d'accessibilité (`.claude/rules/frontend-a11y.md`).
 */
export function describedTexts(wrapper: VueWrapper, selector: string): string[] {
  return textsDescribing(wrapper, wrapper.get(selector), selector)
}

/** Comme `describedTexts`, pour un élément déjà trouvé (un bouton sans identifiant, par exemple). */
export function textsDescribing(wrapper: VueWrapper, element: Pick<DOMWrapper<Element>, 'attributes'>, label = 'élément'): string[] {
  const reference = element.attributes('aria-describedby')
  if (undefined === reference) {
    return []
  }

  return reference
    .split(/\s+/)
    .filter((id) => '' !== id)
    .map((id) => {
      const target = wrapper.find(`#${id}`)
      if (!target.exists()) {
        throw new Error(`${label} : aria-describedby désigne #${id}, qui n'existe pas.`)
      }

      return target.text()
    })
}

/**
 * Le « Annuler » du formulaire d'un contenu (le `form`-ième de la page) : la barre d'ordre du tableau porte le
 * sien, qu'on ne vise pas ici. Sans `form`, le premier « Annuler » de n'importe quel formulaire de la page.
 */
export function formCancelButton(wrapper: VueWrapper, form?: number): DOMWrapper<Element> | undefined {
  const buttons = undefined === form ? wrapper.findAll('form button') : wrapper.findAll('form')[form].findAll('button')

  return buttons.find((candidate) => candidate.text() === 'Annuler')
}

/**
 * Le bouton « Proposer la version XX » du `form`-ième formulaire de la page (le libellé dépend de la langue du
 * formulaire, d'où la recherche par préfixe).
 */
export function translateEntryButton(wrapper: VueWrapper, form: number): DOMWrapper<Element> {
  const button = wrapper
    .findAll('form')
    [form].findAll('button')
    .find((candidate) => candidate.text().startsWith('Proposer la version'))
  if (undefined === button) {
    throw new Error('Bouton de traduction introuvable.')
  }

  return button
}

/** Un formulaire par entrée, tel que les cas partagés du verrou le montent et le conduisent (#748, St8-5). */
export interface EntryFormLockCase {
  /** Préfixe des noms de test (« principe », « carte « moi » »…) ; vide pour une page à un seul formulaire. */
  readonly name: string
  /** Le dépôt de traduction de chaque cas : le support le crée, le passe à `mount` et compte ses appels. */
  readonly createTranslation: () => AdminTranslationRepository
  readonly mount: (translation: AdminTranslationRepository) => Promise<VueWrapper>
  /** Rang du formulaire dans la page (`findAll('form')`) ; 0 pour une page à un seul formulaire. */
  readonly form: number
  /** Identifiants (sans `#`) du sélecteur de langue, du groupe de traduction, du champ de titre et du texte du verrou. */
  readonly ids: {
    readonly locale: string
    readonly group: string
    readonly title: string
    readonly hint: string
  }
  /**
   * Les champs de prose du formulaire — la liste que la page lit (`proseFields.ts`), jamais une copie — et le préfixe de
   * leurs identifiants : le champ `rootCause` du préfixe `admin-incident` est `#admin-incident-root-cause` (`proseFieldId`).
   */
  readonly proseFields: readonly string[]
  readonly fieldIdPrefix: string
  /** Le titre `h2` du formulaire quand il est vierge (« Ajouter … »). */
  readonly heading: string
  /** L'aide permanente de la langue (#582), lue avant le texte du verrou, quand le formulaire en a une. */
  readonly permanentHelp?: string
  /** Ouvre une entrée existante en édition. */
  readonly startEdit: (wrapper: VueWrapper) => Promise<void>
  /** Ouvre en édition une autre entrée que celle de `startEdit` : ce qui remplace le formulaire par celui d'une autre. */
  readonly startEditOther: (wrapper: VueWrapper) => Promise<void>
  /** « Créer la version XX » d'une ligne du tableau : ouvre une création rattachée, prose vide. */
  readonly startCreateVersion: (wrapper: VueWrapper) => Promise<void>
  /** Depuis l'entrée en édition, demande la proposition de traduction et attend qu'elle s'affiche. */
  readonly propose: (wrapper: VueWrapper) => Promise<void>
  /**
   * Supprime l'entrée en édition depuis le tableau (son bouton « Supprimer »). Le `window.confirm` de la page
   * est simulé à `true` par le support, qui le rétablit ensuite.
   */
  readonly deleteEditedEntry: (wrapper: VueWrapper) => Promise<void>
  /**
   * Supprime, depuis le tableau, l'entrée source de la ligne que `startCreateVersion` vient d'ouvrir en création rattachée
   * (son bouton « Supprimer », l'entrée de l'autre langue de la ligne). Même `window.confirm` simulé que `deleteEditedEntry`.
   */
  readonly deleteSourceEntry: (wrapper: VueWrapper) => Promise<void>
  /** Modifie l'ordre d'un tableau de la page (glisser une ligne) sans l'enregistrer : le brouillon d'ordre est sale. */
  readonly dirtyOrder: (wrapper: VueWrapper) => Promise<void>
  /**
   * Arme le dépôt du formulaire pour que son prochain enregistrement ne reçoive jamais de réponse : l'enregistrement lancé
   * ensuite par le test reste « en vol » (`isSubmitting`). Appelé après `mount`, sur le dépôt que ce `mount` a créé.
   */
  readonly holdNextSave: () => void
}

/**
 * Déclare, dans le `describe` appelant, les cas du verrou d'un formulaire par entrée :
 * - la proposition désactive la langue et montre « Annuler » ; « Annuler » rend un formulaire vide ;
 * - le verrou est expliqué en édition et pas dans un formulaire libre ; il l'est pendant une proposition, avec « Version de »
 *   et « Proposer la version XX », et « Annuler » retire l'explication ;
 * - un « translate » émis pendant une proposition, un ordre modifié, un enregistrement en vol, sans prose, ou avant la première
 *   réponse, ne lance rien ;
 * - « Annuler », « Modifier », « Créer la version XX », la suppression de l'entrée éditée ou de la source d'une création
 *   rattachée, et le changement de langue d'une création pendant une traduction en périment la réponse ;
 * - un formulaire vierge enregistré pendant une traduction n'en reçoit pas la réponse (la génération du formulaire) ;
 * - la prose est en lecture seule pendant une traduction ; l'erreur d'un échec survit à la frappe, et ne reparaît pas au retour
 *   à l'entrée où elle est née.
 * Le support crée le dépôt de traduction de chaque cas, le passe à `mount` et compte ses appels. Écrits une fois pour les huit
 * formulaires : un cas de plus s'y ajoute une fois.
 */
export function describeEntryFormLock(lockCase: EntryFormLockCase): void {
  const { ids, form } = lockCase
  // Les identifiants des champs de prose, dérivés de la liste de la page : ceux que la traduction envoie et remplace.
  const proseIds = lockCase.proseFields.map((field) => proseFieldId(lockCase.fieldIdPrefix, field))
  const locale = `#${ids.locale}`
  const prefix = '' === lockCase.name ? '' : `${lockCase.name} : `
  const permanent = undefined === lockCase.permanentHelp ? [] : [lockCase.permanentHelp]
  const headingOf = (wrapper: VueWrapper): string => wrapper.findAll('h2')[form].text()
  const mountWithNewTranslation = async (): Promise<VueWrapper> => lockCase.mount(lockCase.createTranslation())
  const titleValue = (wrapper: VueWrapper): string => (wrapper.get(`#${ids.title}`).element as HTMLInputElement).value
  // Le formulaire du cas dans la page, l'avis de proposition qu'il porte et le composant de son bouton de traduction
  // (`translateEntryButton`, exporté plus haut, est le bouton DOM) : un seul chemin vers chacun.
  const formOf = (wrapper: VueWrapper) => wrapper.findAll('form')[form]
  const proposalNotice = (wrapper: VueWrapper) => formOf(wrapper).find('[role="status"]')
  const translateButtonComponent = (wrapper: VueWrapper) => formOf(wrapper).findComponent(TranslateEntryButton)

  it(`${prefix}après une proposition, le sélecteur de langue est désactivé et « Annuler » est affiché`, async () => {
    const wrapper = await mountWithNewTranslation()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)

    // Création dans la langue cible (le formulaire n'est plus en édition) : la proposition est la version
    // de l'entrée source dans l'autre langue, dans son groupe gardé. Changer la langue en ferait une
    // seconde version de la langue source dans ce groupe (Sp7-1) : le sélecteur reste verrouillé.
    expect(wrapper.get(locale).attributes('disabled')).toBeDefined()
    expect(headingOf(wrapper)).toBe(lockCase.heading)
    expect(formCancelButton(wrapper, form)).toBeDefined()
  })

  it(`${prefix}« Annuler » après une proposition rend un formulaire vide, le sélecteur actif, sans avis`, async () => {
    const wrapper = await mountWithNewTranslation()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)
    expect(proposalNotice(wrapper).exists()).toBe(true)
    expect(formCancelButton(wrapper, form)).toBeDefined()

    await formCancelButton(wrapper, form)?.trigger('click')
    await flushPromises()

    expect(proposalNotice(wrapper).exists()).toBe(false)
    expect(wrapper.get(locale).attributes('disabled')).toBeUndefined()
    expect((wrapper.get(locale).element as HTMLSelectElement).value).toBe('fr')
    expect((wrapper.get(`#${ids.group}`).element as HTMLSelectElement).value).toBe('')
    expect(titleValue(wrapper)).toBe('')
    expect(headingOf(wrapper)).toBe(lockCase.heading)
    expect(formCancelButton(wrapper, form)).toBeUndefined()
  })

  // D-a de l'auteur (#748, 2026-10-30) : un sélecteur désactivé dit pourquoi, par un texte rendu
  // seulement pendant le verrou et désigné par `aria-describedby` (jamais une référence pendante).
  it(`${prefix}le verrou de la langue est expliqué en édition, et pas dans un formulaire libre`, async () => {
    const wrapper = await mountWithNewTranslation()

    expect(describedTexts(wrapper, locale)).toEqual(permanent)
    expect(wrapper.find(`#${ids.hint}`).exists()).toBe(false)

    await lockCase.startEdit(wrapper)

    expect(describedTexts(wrapper, locale)).toEqual([...permanent, ENTRY_LOCK_EDITING_FR])
  })

  // Décision 1 de la huitième relecture (R93, CR8-1) : une seconde traduction depuis la proposition remettait
  // le formulaire dans la langue source, groupe gardé (Sp7-1) ; le serveur la refuse (409). Le bouton est
  // désactivé et décrit par le texte du verrou, qui dit que « Annuler » est la sortie.
  it(`${prefix}après une proposition, « Proposer la version … » est désactivé et décrit par le texte du verrou`, async () => {
    const wrapper = await mountWithNewTranslation()

    await lockCase.startEdit(wrapper)
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeUndefined()
    await lockCase.propose(wrapper)

    const button = translateEntryButton(wrapper, form)
    expect(button.attributes('disabled')).toBeDefined()
    expect(textsDescribing(wrapper, button)).toEqual([ENTRY_LOCK_PROPOSAL_FR])
  })

  it(`${prefix}« Annuler » après une proposition rend le bouton de traduction libre de tout texte de verrou`, async () => {
    const wrapper = await mountWithNewTranslation()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)
    await formCancelButton(wrapper, form)?.trigger('click')
    await flushPromises()

    // Le formulaire est vide : le bouton reste désactivé faute de prose, mais ne désigne plus aucun verrou
    // (une IDREF vers un texte retiré serait pendante, et `textsDescribing` échoue sur elle).
    expect(textsDescribing(wrapper, translateEntryButton(wrapper, form))).toEqual([])
  })

  // Décision 3 de la huitième relecture (R94, Sp8-5) : pendant une proposition, « Version de » est verrouillé comme
  // la langue. « aucune » détacherait la proposition de sa source (D-b) ; « Annuler » est la sortie. En édition,
  // il reste libre (détacher une entrée de son groupe est un geste permis) et ne désigne aucun verrou.
  it(`${prefix}après une proposition, « Version de » est désactivé et décrit par le texte du verrou ; « Annuler » le libère`, async () => {
    const wrapper = await mountWithNewTranslation()
    const group = `#${ids.group}`

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)

    expect(wrapper.get(group).attributes('disabled')).toBeDefined()
    expect(describedTexts(wrapper, group)).toEqual([ENTRY_LOCK_PROPOSAL_FR])

    await formCancelButton(wrapper, form)?.trigger('click')
    await flushPromises()

    expect(wrapper.get(group).attributes('disabled')).toBeUndefined()
    expect(describedTexts(wrapper, group)).toEqual([])
  })

  it(`${prefix}en édition, « Version de » reste libre et ne désigne aucun texte de verrou`, async () => {
    const wrapper = await mountWithNewTranslation()

    await lockCase.startEdit(wrapper)

    expect(wrapper.get(`#${ids.group}`).attributes('disabled')).toBeUndefined()
    expect(describedTexts(wrapper, `#${ids.group}`)).toEqual([])
  })

  it(`${prefix}après une proposition, le verrou de la langue est expliqué et « Annuler » retire l'explication`, async () => {
    const wrapper = await mountWithNewTranslation()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)

    expect(describedTexts(wrapper, locale)).toEqual([...permanent, ENTRY_LOCK_PROPOSAL_FR])

    await formCancelButton(wrapper, form)?.trigger('click')
    await flushPromises()

    expect(describedTexts(wrapper, locale)).toEqual(permanent)
    expect(wrapper.find(`#${ids.hint}`).exists()).toBe(false)
  })

  // Le bouton désactivé n'est pas la seule garde : un « translate » émis par programme, dans l'une ou l'autre langue
  // — la cible de la proposition comme sa source —, ne lance aucune traduction et laisse la proposition en place.
  // Le refus vient de `useAdminTranslation` (`isRefused`), pas seulement du `:disabled`.
  it(`${prefix}pendant une proposition, un « translate » émis ne lance aucune traduction`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)
    const requests = vi.mocked(translation.translate).mock.calls.length
    const localeBefore = (wrapper.get(locale).element as HTMLSelectElement).value

    for (const target of SUPPORTED_LOCALES) {
      translateButtonComponent(wrapper).vm.$emit('translate', target)
      await flushPromises()
    }

    expect(vi.mocked(translation.translate)).toHaveBeenCalledTimes(requests)
    expect((wrapper.get(locale).element as HTMLSelectElement).value).toBe(localeBefore)
    expect(headingOf(wrapper)).toBe(lockCase.heading)
    expect(proposalNotice(wrapper).exists()).toBe(true)
  })

  // Décision 5 de la onzième relecture (R127) : le bouton est désactivé pendant un brouillon d'ordre (une proposition que le
  // formulaire verrouillé ne pourrait pas enregistrer brûlerait du quota) ; un « translate » émis par programme l'est aussi.
  it(`${prefix}un ordre modifié : un « translate » émis ne lance aucune traduction`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    await lockCase.dirtyOrder(wrapper)
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()

    expect(vi.mocked(translation.translate)).not.toHaveBeenCalled()
    expect(proposalNotice(wrapper).exists()).toBe(false)
  })

  // Le bouton et `translate` lisent le même refus (R137) : un brouillon d'ordre modifié désactive le bouton, et c'est la source
  // que lit aussi le composable. Retirer cette condition du refus de la page rouvrirait le bouton et l'appel à la fois.
  it(`${prefix}un ordre modifié : le bouton de traduction est désactivé`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeUndefined()

    await lockCase.dirtyOrder(wrapper)

    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()
  })

  // R127b (décision de l'auteur du 2026-10-31) : le bouton est aussi désactivé pendant l'enregistrement du formulaire et sans
  // aucune prose à traduire ; `isRefused` reprend ces deux conditions, faute de quoi un « translate » émis les passerait.
  it(`${prefix}un enregistrement en vol : un « translate » émis ne lance aucune traduction`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    lockCase.holdNextSave()
    await formOf(wrapper).trigger('submit.prevent')
    await flushPromises()
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()

    expect(vi.mocked(translation.translate)).not.toHaveBeenCalled()
    expect(proposalNotice(wrapper).exists()).toBe(false)
  })

  it(`${prefix}sans aucune prose à traduire : un « translate » émis ne lance aucune traduction`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    for (const id of proseIds) {
      await wrapper.get(`#${id}`).setValue('')
    }
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()

    expect(vi.mocked(translation.translate)).not.toHaveBeenCalled()
    expect(proposalNotice(wrapper).exists()).toBe(false)
    expect(formOf(wrapper).find('[role="alert"]').exists()).toBe(false)
  })

  it(`${prefix}deux « translate » avant la première réponse : une seule traduction`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    const reply = deferNextTranslation(translation)

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    translateButtonComponent(wrapper).vm.$emit('translate', 'fr')
    await flushPromises()
    reply.release()
    await flushPromises()

    expect(vi.mocked(translation.translate)).toHaveBeenCalledTimes(1)
    expect(proposalNotice(wrapper).exists()).toBe(true)
  })

  it(`${prefix}« Annuler » pendant une traduction : la réponse qui arrive ne remplit rien`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    const reply = deferNextTranslation(translation)

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()
    await formCancelButton(wrapper, form)?.trigger('click')
    await flushPromises()
    reply.release()
    await flushPromises()

    expect(titleValue(wrapper)).toBe('')
    expect(headingOf(wrapper)).toBe(lockCase.heading)
    expect(proposalNotice(wrapper).exists()).toBe(false)
  })

  // Décision 2 de la onzième relecture (R125) : jamais plus d'un appel au modèle en vol par formulaire. « Annuler » vide le
  // formulaire mais l'appel déjà parti continue : une nouvelle traduction émise aussitôt est refusée, et le bouton dit qu'il attend.
  it(`${prefix}« Annuler » puis « translate » avant la réponse périmée : une seule traduction, l'attente dite`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    const reply = deferNextTranslation(translation)

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()
    await formCancelButton(wrapper, form)?.trigger('click')
    await flushPromises()
    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()

    expect(vi.mocked(translation.translate)).toHaveBeenCalledTimes(1)
    expect(textsDescribing(wrapper, translateEntryButton(wrapper, form))).toEqual([DISCARD_PENDING_FR])
    await expectNoAccessibilityViolation(wrapper)

    reply.release()
    await flushPromises()

    expect(textsDescribing(wrapper, translateEntryButton(wrapper, form))).toEqual([])
    expect(titleValue(wrapper)).toBe('')
    expect(proposalNotice(wrapper).exists()).toBe(false)
  })

  // Chaque geste qui remplace le contenu du formulaire périme la traduction en vol : sa réponse n'y pose ni prose ni avis.
  it(`${prefix}« Modifier » une autre entrée pendant une traduction : la réponse qui arrive ne remplit rien`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    const reply = deferNextTranslation(translation)

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()
    await lockCase.startEditOther(wrapper)
    const titleOfOther = titleValue(wrapper)
    reply.release()
    await flushPromises()

    expect(titleValue(wrapper)).toBe(titleOfOther)
    expect(headingOf(wrapper)).not.toBe(lockCase.heading)
    expect(proposalNotice(wrapper).exists()).toBe(false)
  })

  it(`${prefix}« Créer la version » pendant une traduction : la réponse qui arrive ne remplit rien`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    const reply = deferNextTranslation(translation)

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()
    await lockCase.startCreateVersion(wrapper)
    reply.release()
    await flushPromises()

    expect(titleValue(wrapper)).toBe('')
    expect(proposalNotice(wrapper).exists()).toBe(false)
  })

  // Ce que l'identité du formulaire ne voit pas : supprimer l'entrée éditée ne change rien au formulaire, la page périme
  // la traduction elle-même. Changer la langue d'une création change l'identité : le composable la périme seul.
  it(`${prefix}supprimer l'entrée en édition pendant une traduction : la réponse ne pose rien`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    const titleOfEntry = titleValue(wrapper)
    const reply = deferNextTranslation(translation)

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    try {
      await lockCase.deleteEditedEntry(wrapper)
    } finally {
      confirm.mockRestore()
    }
    await flushPromises()
    reply.release()
    await flushPromises()

    expect(titleValue(wrapper)).toBe(titleOfEntry)
    expect(proposalNotice(wrapper).exists()).toBe(false)
  })

  // Supprimer l'entrée source d'une « Créer la version XX » ne change rien au formulaire (ni entrée, ni langue, ni groupe) : la
  // proposition serait posée sur un groupe sans membre, alors que son entrée source n'existe plus (R140).
  it(`${prefix}supprimer l'entrée source d'une création rattachée pendant une traduction : la réponse ne pose rien`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startCreateVersion(wrapper)
    await wrapper.get(`#${ids.title}`).setValue('Titre saisi')
    const formLocale = (wrapper.get(locale).element as HTMLSelectElement).value
    const reply = deferNextTranslation(translation)

    translateButtonComponent(wrapper).vm.$emit('translate', SUPPORTED_LOCALES.find((candidate) => candidate !== formLocale))
    await flushPromises()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    try {
      await lockCase.deleteSourceEntry(wrapper)
    } finally {
      confirm.mockRestore()
    }
    await flushPromises()
    reply.release()
    await flushPromises()

    expect(vi.mocked(translation.translate)).toHaveBeenCalledTimes(1)
    expect(titleValue(wrapper)).toBe('Titre saisi')
    expect((wrapper.get(locale).element as HTMLSelectElement).value).toBe(formLocale)
    expect(proposalNotice(wrapper).exists()).toBe(false)
  })

  // Un formulaire vierge enregistré pendant une traduction : l'enregistrement le remet à zéro sans changer l'entrée, la langue ni
  // le groupe, et la réponse du modèle, arrivée après, ne doit rien poser sur ce formulaire vidé (la génération du formulaire).
  it(`${prefix}un formulaire vierge enregistré pendant une traduction : la réponse ne pose rien`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await wrapper.get(`#${ids.title}`).setValue('Titre saisi')
    const reply = deferNextTranslation(translation)

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()
    await formOf(wrapper).trigger('submit.prevent')
    await flushPromises()
    reply.release()
    await flushPromises()

    expect(titleValue(wrapper)).toBe('')
    for (const id of proseIds) {
      expect((wrapper.get(`#${id}`).element as HTMLInputElement | HTMLTextAreaElement).value, `#${id}`).toBe('')
    }
    expect(proposalNotice(wrapper).exists()).toBe(false)
    expect((wrapper.get(locale).element as HTMLSelectElement).value).toBe('fr')
    expect(headingOf(wrapper)).toBe(lockCase.heading)
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()
  })

  it(`${prefix}changer la langue d'une création pendant une traduction : la réponse ne pose rien`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await wrapper.get(`#${ids.title}`).setValue('Titre saisi')
    const reply = deferNextTranslation(translation)

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()
    await wrapper.get(locale).setValue('en')
    reply.release()
    await flushPromises()

    expect(titleValue(wrapper)).toBe('Titre saisi')
    expect(headingOf(wrapper)).toBe(lockCase.heading)
    expect(proposalNotice(wrapper).exists()).toBe(false)
  })

  // Décision 3 de la onzième relecture (R126) : pendant une traduction, la prose est en lecture seule (focusable et
  // lisible, pas `disabled`) et le dit ; la réponse remplace une prose que personne n'a pu changer entre-temps.
  it(`${prefix}pendant une traduction, chaque champ de prose est en lecture seule et décrit par le texte d'aide`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    const reply = deferNextTranslation(translation)

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()

    for (const id of proseIds) {
      const field = wrapper.get(`#${id}`)
      expect(field.attributes('readonly'), `#${id} : readonly`).toBeDefined()
      expect(field.attributes('disabled'), `#${id} : disabled`).toBeUndefined()
      expect(describedTexts(wrapper, `#${id}`), `#${id} : aria-describedby`).toContain(PROSE_READONLY_FR)
    }
    expect(describedTexts(wrapper, locale)).not.toContain(PROSE_READONLY_FR)
    // Dans l'autre sens : aucun autre champ du formulaire n'est en lecture seule, ni ne manque à la liste de la page.
    const readonlyIds = formOf(wrapper).findAll('[readonly]').map((field) => field.attributes('id'))
    expect([...readonlyIds].sort()).toEqual([...proseIds].sort())
    await expectNoAccessibilityViolation(wrapper)

    reply.release()
    await flushPromises()

    for (const id of proseIds) {
      expect(wrapper.get(`#${id}`).attributes('readonly'), `#${id} : readonly après la réponse`).toBeUndefined()
    }
    expect(wrapper.text()).not.toContain(PROSE_READONLY_FR)
  })

  it(`${prefix}une traduction en échec : la prose redevient modifiable, intacte, le texte d'aide disparaît`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    const titleOfEntry = titleValue(wrapper)
    vi.mocked(translation.translate).mockRejectedValueOnce(new AdminTranslationError('unavailable', 'Indisponible.'))

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()

    for (const id of proseIds) {
      expect(wrapper.get(`#${id}`).attributes('readonly'), `#${id} : readonly après l'échec`).toBeUndefined()
      expect(describedTexts(wrapper, `#${id}`), `#${id} : aria-describedby`).not.toContain(PROSE_READONLY_FR)
    }
    expect(wrapper.text()).not.toContain(PROSE_READONLY_FR)
    expect(titleValue(wrapper)).toBe(titleOfEntry)
    expect(formOf(wrapper).find('[role="alert"]').text()).toBe(TRANSLATION_UNAVAILABLE_FR)
    await expectNoAccessibilityViolation(wrapper)
  })

  // Décision de l'auteur du 2026-10-31 (après R124) : la prose n'est pas dans l'identité du formulaire ; la saisie est
  // empêchée pendant la traduction, et le message d'un échec (429, 503) n'est effacé que par un vrai changement de
  // formulaire ou la traduction suivante, pas par la frappe qui corrige la prose.
  it(`${prefix}l'erreur d'une traduction survit à la frappe`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    vi.mocked(translation.translate).mockRejectedValueOnce(new AdminTranslationError('unavailable', 'Indisponible.'))

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()
    expect(formOf(wrapper).find('[role="alert"]').text()).toBe(TRANSLATION_UNAVAILABLE_FR)

    await wrapper.get(`#${ids.title}`).setValue('Titre corrigé après l\'échec')
    await flushPromises()

    expect(titleValue(wrapper)).toBe('Titre corrigé après l\'échec')
    expect(formOf(wrapper).find('[role="alert"]').text()).toBe(TRANSLATION_UNAVAILABLE_FR)
  })

  // L'échec d'une traduction est effacé à tout changement d'identité du formulaire, et un retour à l'identité de l'échec ne le
  // rend pas : « Modifier » A (échec), « Modifier » B, puis A ne montre aucune erreur. Une frappe, elle, ne l'efface pas.
  it(`${prefix}une traduction en échec, une autre entrée ouverte, puis la première : aucune erreur`, async () => {
    const translation = lockCase.createTranslation()
    const wrapper = await lockCase.mount(translation)
    await lockCase.startEdit(wrapper)
    vi.mocked(translation.translate).mockRejectedValueOnce(new AdminTranslationError('unavailable', 'Indisponible.'))

    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()
    expect(formOf(wrapper).find('[role="alert"]').text()).toBe(TRANSLATION_UNAVAILABLE_FR)

    await lockCase.startEditOther(wrapper)
    expect(formOf(wrapper).find('[role="alert"]').exists()).toBe(false)
    await lockCase.startEdit(wrapper)

    expect(formOf(wrapper).find('[role="alert"]').exists()).toBe(false)

    // Un nouvel échec sur la première entrée se montre, et la frappe qui suit ne l'efface pas.
    vi.mocked(translation.translate).mockRejectedValueOnce(new AdminTranslationError('unavailable', 'Indisponible.'))
    translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await flushPromises()
    await wrapper.get(`#${ids.title}`).setValue('Titre corrigé après le second échec')
    await flushPromises()

    expect(formOf(wrapper).find('[role="alert"]').text()).toBe(TRANSLATION_UNAVAILABLE_FR)
  })
}

/**
 * Rend la prochaine réponse du dépôt différée jusqu'à `release()`, qui rend alors celle que le dépôt aurait
 * rendue d'emblée : la traduction est « en vol » le temps du test.
 */
function deferNextTranslation(translation: AdminTranslationRepository): { readonly release: () => void } {
  const mocked = vi.mocked(translation.translate)
  const immediate = mocked.getMockImplementation()
  if (undefined === immediate) {
    throw new Error('Le dépôt de traduction du cas doit être un `vi.fn(implémentation)`.')
  }

  let release = (): void => {
    throw new Error("La traduction n'a pas été demandée.")
  }
  mocked.mockImplementationOnce(
    (...args) =>
      new Promise((resolve) => {
        release = () => resolve(immediate(...args))
      }),
  )

  return { release: () => release() }
}
