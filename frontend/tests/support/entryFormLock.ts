import { flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { expect, it } from 'vitest'

/**
 * Les textes qui expliquent un sélecteur verrouillé d'un formulaire par entrée (#748, D-a et D-b
 * de l'auteur, 2026-10-30), écrits ici une fois pour les huit formulaires et la catégorie des
 * cartes « moi ». Lus par `describedTexts()` : un test dit « ce texte décrit ce sélecteur »,
 * jamais « cet id existe ».
 */
export const LOCALE_LOCKED_EDITING_TEXT = "La langue d'une entrée existante ne peut pas être modifiée."
export const LOCALE_LOCKED_PROPOSAL_TEXT =
  'La langue est celle de la proposition de traduction ; « Annuler » permet d\'en changer.'
export const CATEGORY_LOCKED_PROPOSAL_TEXT =
  'La catégorie est celle de la proposition de traduction ; « Annuler » permet d\'en changer.'

/**
 * Les textes que désigne l'`aria-describedby` du champ `selector`, dans l'ordre des identifiants ;
 * `[]` sans attribut. **Un identifiant qui ne désigne aucun élément fait échouer le test** : une
 * IDREF pendante est elle-même une faute d'accessibilité (`.claude/rules/frontend-a11y.md`).
 */
export function describedTexts(wrapper: VueWrapper, selector: string): string[] {
  const reference = wrapper.get(selector).attributes('aria-describedby')
  if (undefined === reference) {
    return []
  }

  return reference
    .split(/\s+/)
    .filter((id) => '' !== id)
    .map((id) => {
      const target = wrapper.find(`#${id}`)
      if (!target.exists()) {
        throw new Error(`${selector} : aria-describedby désigne #${id}, qui n'existe pas.`)
      }

      return target.text()
    })
}

/** L'aide permanente des sélecteurs de langue des principes, traits et cartes (#582), vraie en toute circonstance. */
export const LOCALE_HELP_TEXT =
  "Langue de cette entrée : c'est elle qui sera enregistrée, et celle sur laquelle porte l'assistant de traduction. Le tableau ci-dessous, lui, affiche toutes les langues."

/**
 * Le « Annuler » du formulaire d'un contenu (le `form`-ième de la page) : la barre d'ordre du tableau porte le
 * sien, qu'on ne vise pas ici. Sans `form`, le premier « Annuler » de n'importe quel formulaire de la page.
 */
export function formCancelButton(wrapper: VueWrapper, form?: number): DOMWrapper<Element> | undefined {
  const buttons = undefined === form ? wrapper.findAll('form button') : wrapper.findAll('form')[form].findAll('button')

  return buttons.find((candidate) => candidate.text() === 'Annuler')
}

/** Un formulaire par entrée, tel que les cas partagés du verrou le montent et le conduisent (#748, St8-5). */
export interface EntryFormLockCase {
  /** Préfixe des noms de test (« principe », « carte « moi » »…) ; vide pour une page à un seul formulaire. */
  readonly name: string
  readonly mount: () => Promise<VueWrapper>
  /** Rang du formulaire dans la page (`findAll('form')`) ; 0 pour une page à un seul formulaire. */
  readonly form: number
  /** Identifiants (sans `#`) du sélecteur de langue, du groupe de traduction, du champ de titre et du texte du verrou. */
  readonly ids: { readonly locale: string; readonly group: string; readonly title: string; readonly hint: string }
  /** Le titre `h2` du formulaire quand il est vierge (« Ajouter … »). */
  readonly heading: string
  /** L'aide permanente de la langue (#582), lue avant le texte du verrou, quand le formulaire en a une. */
  readonly permanentHelp?: string
  /** Ouvre une entrée existante en édition. */
  readonly startEdit: (wrapper: VueWrapper) => Promise<void>
  /** Depuis l'entrée en édition, demande la proposition de traduction et attend qu'elle s'affiche. */
  readonly propose: (wrapper: VueWrapper) => Promise<void>
}

/**
 * Déclare, dans le `describe` appelant, les quatre cas du verrou d'un formulaire par entrée : la proposition
 * désactive la langue et montre « Annuler » ; « Annuler » rend un formulaire vide ; le verrou est expliqué en
 * édition et pas dans un formulaire libre ; il l'est pendant une proposition et « Annuler » retire l'explication.
 * Écrits une fois pour les huit formulaires (R91) : R93 et R94 y ajoutent leurs cas une fois.
 */
export function describeEntryFormLock(lockCase: EntryFormLockCase): void {
  const { ids, form } = lockCase
  const locale = `#${ids.locale}`
  const prefix = '' === lockCase.name ? '' : `${lockCase.name} : `
  const permanent = undefined === lockCase.permanentHelp ? [] : [lockCase.permanentHelp]
  const headingOf = (wrapper: VueWrapper): string => wrapper.findAll('h2')[form].text()

  it(`${prefix}après une proposition, le sélecteur de langue est désactivé et « Annuler » est affiché`, async () => {
    const wrapper = await lockCase.mount()

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
    const wrapper = await lockCase.mount()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)
    expect(wrapper.findAll('form')[form].find('[role="status"]').exists()).toBe(true)
    expect(formCancelButton(wrapper, form)).toBeDefined()

    await formCancelButton(wrapper, form)?.trigger('click')
    await flushPromises()

    expect(wrapper.findAll('form')[form].find('[role="status"]').exists()).toBe(false)
    expect(wrapper.get(locale).attributes('disabled')).toBeUndefined()
    expect((wrapper.get(locale).element as HTMLSelectElement).value).toBe('fr')
    expect((wrapper.get(`#${ids.group}`).element as HTMLSelectElement).value).toBe('')
    expect((wrapper.get(`#${ids.title}`).element as HTMLInputElement).value).toBe('')
    expect(headingOf(wrapper)).toBe(lockCase.heading)
    expect(formCancelButton(wrapper, form)).toBeUndefined()
  })

  // D-a de l'auteur (#748, 2026-10-30) : un sélecteur désactivé dit pourquoi, par un texte rendu
  // seulement pendant le verrou et désigné par `aria-describedby` (jamais une référence pendante).
  it(`${prefix}le verrou de la langue est expliqué en édition, et pas dans un formulaire libre`, async () => {
    const wrapper = await lockCase.mount()

    expect(describedTexts(wrapper, locale)).toEqual(permanent)
    expect(wrapper.find(`#${ids.hint}`).exists()).toBe(false)

    await lockCase.startEdit(wrapper)

    expect(describedTexts(wrapper, locale)).toEqual([...permanent, LOCALE_LOCKED_EDITING_TEXT])
  })

  it(`${prefix}après une proposition, le verrou de la langue est expliqué et « Annuler » retire l'explication`, async () => {
    const wrapper = await lockCase.mount()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)

    expect(describedTexts(wrapper, locale)).toEqual([...permanent, LOCALE_LOCKED_PROPOSAL_TEXT])

    await formCancelButton(wrapper, form)?.trigger('click')
    await flushPromises()

    expect(describedTexts(wrapper, locale)).toEqual(permanent)
    expect(wrapper.find(`#${ids.hint}`).exists()).toBe(false)
  })
}
