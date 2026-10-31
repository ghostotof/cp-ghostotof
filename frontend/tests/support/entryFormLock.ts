import { flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { expect, it } from 'vitest'
import TranslateEntryButton from '../../src/presentation/ui/admin/TranslateEntryButton.vue'

/**
 * Les textes qui expliquent un sélecteur verrouillé d'un formulaire par entrée (#748, D-a et D-b
 * de l'auteur, 2026-10-30), écrits ici une fois pour les huit formulaires et la catégorie des
 * cartes « moi ». Lus par `describedTexts()` : un test dit « ce texte décrit ce sélecteur »,
 * jamais « cet id existe ».
 */
export const LOCALE_LOCKED_EDITING_TEXT = "La langue d'une entrée existante ne peut pas être modifiée."
/** Le texte unique du verrou d'une proposition : langue, bouton de traduction et catégorie « moi » le désignent (R93). */
export const LOCKED_BY_PROPOSAL_TEXT =
  'Verrouillé par la proposition de traduction en cours ; « Annuler » l\'abandonne et vide le formulaire.'

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
  /** Le nombre d'appels, depuis le dernier `mount`, au dépôt de traduction du formulaire monté (R104, C9-2). */
  readonly translationRequests: () => number
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

  // Décision 1 de la huitième relecture (R93, CR8-1) : une seconde traduction depuis la proposition remettait
  // le formulaire dans la langue source, groupe gardé (Sp7-1) ; le serveur la refuse (409). Le bouton est
  // désactivé et décrit par le texte du verrou, qui dit que « Annuler » est la sortie.
  it(`${prefix}après une proposition, « Proposer la version … » est désactivé et décrit par le texte du verrou`, async () => {
    const wrapper = await lockCase.mount()

    await lockCase.startEdit(wrapper)
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeUndefined()
    await lockCase.propose(wrapper)

    const button = translateEntryButton(wrapper, form)
    expect(button.attributes('disabled')).toBeDefined()
    expect(textsDescribing(wrapper, button)).toEqual([LOCKED_BY_PROPOSAL_TEXT])
  })

  it(`${prefix}« Annuler » après une proposition rend le bouton de traduction libre de tout texte de verrou`, async () => {
    const wrapper = await lockCase.mount()

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
    const wrapper = await lockCase.mount()
    const group = `#${ids.group}`

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)

    expect(wrapper.get(group).attributes('disabled')).toBeDefined()
    expect(describedTexts(wrapper, group)).toEqual([LOCKED_BY_PROPOSAL_TEXT])

    await formCancelButton(wrapper, form)?.trigger('click')
    await flushPromises()

    expect(wrapper.get(group).attributes('disabled')).toBeUndefined()
    expect(describedTexts(wrapper, group)).toEqual([])
  })

  it(`${prefix}en édition, « Version de » reste libre et ne désigne aucun texte de verrou`, async () => {
    const wrapper = await lockCase.mount()

    await lockCase.startEdit(wrapper)

    expect(wrapper.get(`#${ids.group}`).attributes('disabled')).toBeUndefined()
    expect(describedTexts(wrapper, `#${ids.group}`)).toEqual([])
  })

  it(`${prefix}après une proposition, le verrou de la langue est expliqué et « Annuler » retire l'explication`, async () => {
    const wrapper = await lockCase.mount()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)

    expect(describedTexts(wrapper, locale)).toEqual([...permanent, LOCKED_BY_PROPOSAL_TEXT])

    await formCancelButton(wrapper, form)?.trigger('click')
    await flushPromises()

    expect(describedTexts(wrapper, locale)).toEqual(permanent)
    expect(wrapper.find(`#${ids.hint}`).exists()).toBe(false)
  })

  // C9-2 (R104) : le bouton désactivé n'est pas la seule garde. Un « translate » émis pendant une proposition
  // (la langue source, celle qui ramènerait le formulaire en arrière) ne lance aucune traduction et laisse la
  // proposition en place : le refus vient du gestionnaire, pas seulement du `:disabled`.
  it(`${prefix}pendant une proposition, un « translate » émis ne lance aucune traduction`, async () => {
    const wrapper = await lockCase.mount()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)
    const requests = lockCase.translationRequests()
    const localeBefore = (wrapper.get(locale).element as HTMLSelectElement).value

    wrapper.findAll('form')[form].findComponent(TranslateEntryButton).vm.$emit('translate', 'fr')
    await flushPromises()

    expect(lockCase.translationRequests()).toBe(requests)
    expect((wrapper.get(locale).element as HTMLSelectElement).value).toBe(localeBefore)
    expect(headingOf(wrapper)).toBe(lockCase.heading)
    expect(wrapper.findAll('form')[form].find('[role="status"]').exists()).toBe(true)
  })
}
