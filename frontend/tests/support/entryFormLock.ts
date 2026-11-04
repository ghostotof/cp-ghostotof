import { flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import TranslateEntryButton from '../../src/presentation/ui/admin/TranslateEntryButton.vue'
import { accessibleNameOf, expectNoAccessibilityViolation } from './axe'
import { SUPPORTED_LOCALES } from '../../src/domain/portfolio/entities/Locale'
import type { AdminTranslationRepository } from '../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import { proseFieldId } from './proseFieldId'
import {
  EXPIRED_CALL_PENDING_FR,
  PROSE_READONLY_FR,
  TRANSLATION_IN_PROGRESS_FR,
  TRANSLATION_UNAVAILABLE_FR,
} from './adminTranslationTexts'
import {
  ENTRY_FORM_CANCEL_LABELS_FR,
  ENTRY_LOCK_EDITING_FR,
  ENTRY_LOCK_PROPOSAL_FR,
  PROPOSE_VERSION_PREFIX_FR,
} from './entryFormLockTexts'
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
 * sien, qu'on ne vise pas ici. Sans `form`, le premier « Annuler » de n'importe quel formulaire de la page. Son libellé est lu
 * du catalogue (`ENTRY_FORM_CANCEL_LABELS_FR`), jamais écrit ici.
 */
export function formCancelButton(wrapper: VueWrapper, form?: number): DOMWrapper<Element> | undefined {
  const buttons = undefined === form ? wrapper.findAll('form button') : wrapper.findAll('form')[form].findAll('button')

  return buttons.find((candidate) => ENTRY_FORM_CANCEL_LABELS_FR.includes(candidate.text()))
}

/**
 * Le bouton de traduction du `form`-ième formulaire de la page, trouvé par son nom accessible (`accessibleNameOf`, celui
 * qu'axe-core calcule, non le texte du DOM) : « Proposer la version XX » (le libellé dépend de la langue du formulaire, d'où la
 * recherche par préfixe) ou, tant que l'appel est en vol, « Traduction en cours… ». Les deux textes sont lus du catalogue.
 */
export function translateEntryButton(wrapper: VueWrapper, form: number): DOMWrapper<Element> {
  const button = wrapper
    .findAll('form')
    [form].findAll('button')
    .find((candidate) => {
      const name = accessibleNameOf(candidate.element)

      return name.startsWith(PROPOSE_VERSION_PREFIX_FR) || TRANSLATION_IN_PROGRESS_FR === name
    })
  if (undefined === button) {
    throw new Error('Bouton de traduction introuvable.')
  }

  return button
}

/**
 * Descend la première ligne d'un tableau de la page (le `table`-ième, 0 par défaut) d'un cran par la flèche « ↓ » de sa
 * poignée, l'alternative clavier au glisser-déposer : le brouillon d'ordre devient sale sans geste de souris.
 */
export async function moveFirstRowDown(wrapper: VueWrapper, table = 0): Promise<void> {
  await wrapper.findAll('table')[table].findAll('tbody tr')[0].get('button').trigger('keydown', { key: 'ArrowDown' })
}

/**
 * Déplace la première ligne du tableau `table` puis émet « translate » depuis le bouton du `form`-ième formulaire de la page,
 * **sans rien attendre** entre les deux : le brouillon d'ordre de la section est modifié, mais la page n'a pas encore reçu
 * `orderDirtyChange` ni rendu `isLocked` (#748, R173). Les promesses ne sont réglées qu'à la fin. `moveFirstRowDown` n'est pas
 * attendue (`void`) : son premier geste, le `keydown`, part de façon synchrone.
 */
export async function emitTranslateRightAfterMove(wrapper: VueWrapper, table: number, form: number): Promise<void> {
  void moveFirstRowDown(wrapper, table)
  wrapper.findAllComponents(TranslateEntryButton)[form].vm.$emit('translate', 'en')
  await flushPromises()
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
  /**
   * Identifiants (sans `#`) du sélecteur de langue, du groupe de traduction, du champ de titre et du texte du verrou.
   * Celui de la langue est `<préfixe du formulaire>-locale` : le support en tire le préfixe des champs de prose (un
   * préfixe écrit à part redirait la même chose).
   */
  readonly ids: {
    readonly locale: string
    readonly group: string
    readonly title: string
    readonly hint: string
  }
  /**
   * Les champs de prose du formulaire — la liste que la page lit (`proseFields.ts`), jamais une copie. Leurs identifiants
   * sont dérivés du préfixe de `ids.locale` : le champ `rootCause` du préfixe `admin-incident` est `#admin-incident-root-cause`
   * (`proseFieldId`).
   */
  readonly proseFields: readonly string[]
  /** Le titre `h2` du formulaire quand il est vierge (« Ajouter … »). */
  readonly heading: string
  /** L'aide permanente de la langue (#582), lue avant le texte du verrou, quand le formulaire en a une. */
  readonly permanentHelp?: string
  /** Rang, dans la page (`findAll('table')`), du tableau dont `moveFirstRowDown` modifie l'ordre ; 0 par défaut. */
  readonly orderTable?: number
  /**
   * Ouvre une entrée existante en édition. **Contrat** : c'est l'entrée source de la ligne que `startCreateVersion` ouvre en
   * création, celle que `deleteEditedEntry` supprime ; le cas « source d'une création rattachée » le vérifie avant de supprimer.
   */
  readonly startEdit: (wrapper: VueWrapper) => Promise<void>
  /** Ouvre en édition une autre entrée que celle de `startEdit` : ce qui remplace le formulaire par celui d'une autre. */
  readonly startEditOther: (wrapper: VueWrapper) => Promise<void>
  /**
   * « Créer la version XX » de la ligne dont l'entrée est celle de `startEdit` : ouvre une création rattachée au groupe de cette
   * ligne, prose vide.
   */
  readonly startCreateVersion: (wrapper: VueWrapper) => Promise<void>
  /** Depuis l'entrée en édition, demande la proposition de traduction et attend qu'elle s'affiche. */
  readonly propose: (wrapper: VueWrapper) => Promise<void>
  /**
   * Supprime l'entrée en édition depuis le tableau (son bouton « Supprimer »). Le `window.confirm` de la page est simulé à
   * `true` par le support, qui le rétablit ensuite. C'est aussi l'entrée source de la ligne que `startCreateVersion` ouvre en
   * création rattachée (contrat de `startEdit`) : le support la supprime pour ce cas, sans second geste à écrire.
   */
  readonly deleteEditedEntry: (wrapper: VueWrapper) => Promise<void>
  /**
   * Supprime, depuis le tableau, l'entrée de l'autre langue de celle que `startEditOther` ouvre (elles partagent un groupe) : un
   * membre du groupe de l'entrée éditée, qui n'est ni cette entrée ni la source d'une création. Même `window.confirm` simulé.
   */
  readonly deleteSiblingOfOther: (wrapper: VueWrapper) => Promise<void>
  /**
   * Arme le dépôt du formulaire pour que son prochain enregistrement ne reçoive jamais de réponse : l'enregistrement lancé
   * ensuite par le test reste « en vol » (`isSubmitting`). Appelé après `mount`, sur le dépôt que ce `mount` a créé.
   */
  readonly holdNextSave: () => void
}

/** Le dépôt de traduction d'un cas et la page montée avec lui. */
interface MountedCase {
  readonly translation: AdminTranslationRepository
  readonly wrapper: VueWrapper
}

/** Les outils que partagent les familles de cas d'un même formulaire : un seul chemin vers chaque élément et chaque geste. */
interface LockToolkit {
  /** Les identifiants (sans `#`) du formulaire, ceux de `EntryFormLockCase.ids`. */
  readonly ids: EntryFormLockCase['ids']
  /** Les identifiants (sans `#`) des champs de prose, dérivés de la liste de la page. */
  readonly proseIds: readonly string[]
  /** Le sélecteur CSS du sélecteur de langue. */
  readonly locale: string
  /** Le sélecteur CSS du sélecteur de groupe de traduction (« Version de »). */
  readonly group: string
  /** Le préfixe des noms de test, vide pour une page à un seul formulaire. */
  readonly prefix: string
  /** L'aide permanente de la langue, en liste (vide quand le formulaire n'en a pas). */
  readonly permanent: readonly string[]
  /** Le titre `h2` du formulaire, celui du `form`-ième de la page. */
  headingOf(wrapper: VueWrapper): string
  /** Le formulaire du cas : le `form`-ième de la page. */
  formOf(wrapper: VueWrapper): DOMWrapper<HTMLFormElement>
  /** La valeur du champ de titre. */
  titleValue(wrapper: VueWrapper): string
  /** La valeur du sélecteur de langue. */
  localeValue(wrapper: VueWrapper): string
  /** La valeur d'un champ du formulaire, par son identifiant (sans `#`). */
  fieldValue(wrapper: VueWrapper, id: string): string
  /** Saisit une valeur dans le champ de titre. */
  typeTitle(wrapper: VueWrapper, value: string): Promise<void>
  /** L'avis de proposition que porte le formulaire (`role="status"`). */
  proposalNotice(wrapper: VueWrapper): DOMWrapper<Element>
  /** L'alerte d'erreur du formulaire (`role="alert"`). */
  errorAlert(wrapper: VueWrapper): DOMWrapper<Element>
  /** Le composant du bouton de traduction (`translateEntryButton`, exporté plus haut, est le bouton DOM). */
  translateButtonComponent(wrapper: VueWrapper): VueWrapper
  /** Un « translate » émis par programme, comme le ferait le bouton, puis les promesses réglées. */
  emitTranslate(wrapper: VueWrapper, target: string | undefined): Promise<void>
  /** Un « Annuler » cliqué dans le formulaire, puis les promesses réglées. */
  cancelForm(wrapper: VueWrapper): Promise<void>
  /** Le formulaire du cas et un dépôt de traduction neuf, monté. */
  mountCase(): Promise<MountedCase>
  /** Comme `mountCase`, et ouvre en plus en édition l'entrée de `startEdit`. */
  mountEdited(): Promise<MountedCase>
  /** Le titre de l'entrée que `startEdit` ouvre, lu sur une page montée à part. */
  titleOfEditedEntry(): Promise<string>
  /**
   * Précondition du cas « source d'une création rattachée » : la page est une création (le titre du formulaire vierge) dont le
   * sélecteur « Version de » est sur le groupe de l'entrée de titre `sourceTitle`.
   */
  expectCreationAttachedTo(wrapper: VueWrapper, sourceTitle: string): void
  /** Une langue autre que celle du formulaire : la cible d'une traduction. */
  otherLocale(formLocale: string): string | undefined
  /** Chaque champ de prose du formulaire est vide. */
  expectProseEmpty(wrapper: VueWrapper): void
  /** Rend la prochaine traduction en échec, par une erreur du service. */
  failNextTranslation(translation: AdminTranslationRepository): void
  /** Exécute un geste de suppression avec un `window.confirm` simulé à `true`, rétabli ensuite. */
  confirmingDelete(gesture: () => Promise<void>): Promise<void>
}

function lockToolkit(lockCase: EntryFormLockCase): LockToolkit {
  const { ids, form } = lockCase
  // Le préfixe des identifiants du formulaire, tiré de celui de sa langue ; l'ordre inverse (un préfixe puis `-locale`) serait
  // une seconde source du même fait.
  const formPrefix = ids.locale.replace(/-locale$/, '')
  if (formPrefix === ids.locale) {
    throw new Error(`ids.locale (${ids.locale}) doit finir par « -locale » : le préfixe des champs de prose en est tiré.`)
  }
  // Les identifiants des champs de prose, dérivés de la liste de la page : ceux que la traduction envoie et remplace.
  const proseIds = lockCase.proseFields.map((field) => proseFieldId(formPrefix, field))
  const formOf = (wrapper: VueWrapper) => wrapper.findAll('form')[form]
  const headingOf = (wrapper: VueWrapper): string => wrapper.findAll('h2')[form].text()
  // La valeur d'un champ du formulaire, par son identifiant (sans `#`).
  const fieldValue = (wrapper: VueWrapper, id: string): string =>
    (wrapper.get(`#${id}`).element as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement).value
  const translateButtonComponent = (wrapper: VueWrapper) => formOf(wrapper).findComponent(TranslateEntryButton)

  return {
    ids,
    proseIds,
    locale: `#${ids.locale}`,
    group: `#${ids.group}`,
    prefix: '' === lockCase.name ? '' : `${lockCase.name} : `,
    permanent: undefined === lockCase.permanentHelp ? [] : [lockCase.permanentHelp],
    headingOf,
    formOf,
    titleValue: (wrapper: VueWrapper): string => fieldValue(wrapper, ids.title),
    localeValue: (wrapper: VueWrapper): string => fieldValue(wrapper, ids.locale),
    fieldValue,
    typeTitle: (wrapper: VueWrapper, value: string) => wrapper.get(`#${ids.title}`).setValue(value),
    proposalNotice: (wrapper: VueWrapper) => formOf(wrapper).find('[role="status"]'),
    errorAlert: (wrapper: VueWrapper) => formOf(wrapper).find('[role="alert"]'),
    translateButtonComponent,
    async emitTranslate(wrapper: VueWrapper, target: string | undefined): Promise<void> {
      translateButtonComponent(wrapper).vm.$emit('translate', target)
      await flushPromises()
    },
    async cancelForm(wrapper: VueWrapper): Promise<void> {
      await formCancelButton(wrapper, form)?.trigger('click')
      await flushPromises()
    },
    async mountCase(): Promise<MountedCase> {
      const translation = lockCase.createTranslation()

      return { translation, wrapper: await lockCase.mount(translation) }
    },
    async mountEdited(): Promise<MountedCase> {
      const translation = lockCase.createTranslation()
      const wrapper = await lockCase.mount(translation)
      await lockCase.startEdit(wrapper)

      return { translation, wrapper }
    },
    async titleOfEditedEntry(): Promise<string> {
      const probe = await lockCase.mount(lockCase.createTranslation())
      await lockCase.startEdit(probe)

      return fieldValue(probe, ids.title)
    },
    expectCreationAttachedTo(wrapper: VueWrapper, sourceTitle: string): void {
      expect(sourceTitle, "le titre de l'entrée de `startEdit`").not.toBe('')
      expect(headingOf(wrapper), 'le formulaire est une création').toBe(lockCase.heading)
      const group = wrapper.get(`#${ids.group}`).element as HTMLSelectElement
      expect(group.value, 'la création est rattachée à un groupe').not.toBe('')
      // Les options de « Version de » portent le titre de l'entrée du groupe : la création est celle de la ligne de cette entrée.
      expect(group.selectedOptions[0]?.text, "la ligne de `startCreateVersion` est celle de l'entrée de `startEdit`").toContain(
        sourceTitle,
      )
    },
    otherLocale: (formLocale: string) => SUPPORTED_LOCALES.find((candidate) => candidate !== formLocale),
    expectProseEmpty(wrapper: VueWrapper): void {
      for (const id of proseIds) {
        expect(fieldValue(wrapper, id), `#${id}`).toBe('')
      }
    },
    failNextTranslation(translation: AdminTranslationRepository): void {
      vi.mocked(translation.translate).mockRejectedValueOnce(new AdminTranslationError('unavailable', 'Indisponible.'))
    },
    async confirmingDelete(gesture: () => Promise<void>): Promise<void> {
      const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
      try {
        await gesture()
      } finally {
        confirm.mockRestore()
      }
      await flushPromises()
    },
  }
}

/**
 * Déclare, dans le `describe` appelant, les cas du verrou d'un formulaire par entrée, en cinq familles :
 * - le verrou — la proposition désactive la langue et montre « Annuler » ; « Annuler » rend un formulaire vide ; le verrou est
 *   expliqué en édition et pas dans un formulaire libre ; il l'est pendant une proposition, avec « Version de » et « Proposer la
 *   version XX », et « Annuler » retire l'explication ;
 * - le refus — un « translate » émis pendant une proposition, un ordre modifié, un enregistrement en vol, sans prose, ou avant la
 *   première réponse, ne lance rien ;
 * - la péremption — « Annuler », « Modifier » (une autre entrée, ou celle qu'on édite), « Créer la version XX » (aussi recliqué),
 *   la suppression de l'entrée éditée ou de la source d'une création rattachée, et le changement de langue d'une création pendant
 *   une traduction en périment la réponse ; la suppression de l'autre langue de l'entrée éditée, non : la proposition est posée ;
 *   un formulaire vierge enregistré pendant une traduction n'en reçoit pas la réponse (la génération du formulaire) ;
 * - la prose — en lecture seule pendant une traduction ;
 * - l'échec — son erreur survit à la frappe, et ne reparaît pas au retour à l'entrée où elle est née.
 * Le support crée le dépôt de traduction de chaque cas, le passe à `mount` et compte ses appels. Écrits une fois pour tous les
 * formulaires par entrée : un cas de plus s'y ajoute une fois.
 */
export function describeEntryFormLock(lockCase: EntryFormLockCase): void {
  const kit = lockToolkit(lockCase)

  describeLock(lockCase, kit)
  describeRefusals(lockCase, kit)
  describeExpiry(lockCase, kit)
  describeReadonlyProse(kit)
  describeFailure(lockCase, kit)
}

/** Le verrou : la langue, « Version de » et « Proposer la version XX » pendant une proposition, et le texte qui le dit. */
function describeLock(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  describeLanguageLock(lockCase, kit)
  describeTranslateButtonLock(lockCase, kit)
  describeGroupLock(lockCase, kit)
}

/** Le sélecteur de langue : désactivé pendant une proposition, rendu par « Annuler », et le texte du verrou expliqué en édition. */
function describeLanguageLock(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { form } = lockCase
  const { ids, locale, prefix, permanent } = kit

  it(`${prefix}après une proposition, le sélecteur de langue est désactivé et « Annuler » est affiché`, async () => {
    const { wrapper } = await kit.mountCase()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)

    // Création dans la langue cible (le formulaire n'est plus en édition) : la proposition est la version
    // de l'entrée source dans l'autre langue, dans son groupe gardé. Changer la langue en ferait une
    // seconde version de la langue source dans ce groupe (Sp7-1) : le sélecteur reste verrouillé.
    expect(wrapper.get(locale).attributes('disabled')).toBeDefined()
    expect(kit.headingOf(wrapper)).toBe(lockCase.heading)
    expect(formCancelButton(wrapper, form)).toBeDefined()
  })

  it(`${prefix}« Annuler » après une proposition rend un formulaire vide, le sélecteur actif, sans avis`, async () => {
    const { wrapper } = await kit.mountCase()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)
    expect(kit.proposalNotice(wrapper).exists()).toBe(true)
    expect(formCancelButton(wrapper, form)).toBeDefined()

    await kit.cancelForm(wrapper)

    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
    expect(wrapper.get(locale).attributes('disabled')).toBeUndefined()
    expect(kit.localeValue(wrapper)).toBe('fr')
    expect(kit.fieldValue(wrapper, ids.group)).toBe('')
    expect(kit.titleValue(wrapper)).toBe('')
    expect(kit.headingOf(wrapper)).toBe(lockCase.heading)
    expect(formCancelButton(wrapper, form)).toBeUndefined()
  })

  // D-a de l'auteur (#748, 2026-10-30) : un sélecteur désactivé dit pourquoi, par un texte rendu
  // seulement pendant le verrou et désigné par `aria-describedby` (jamais une référence pendante).
  it(`${prefix}le verrou de la langue est expliqué en édition, et pas dans un formulaire libre`, async () => {
    const { wrapper } = await kit.mountCase()

    expect(describedTexts(wrapper, locale)).toEqual(permanent)
    expect(wrapper.find(`#${ids.hint}`).exists()).toBe(false)

    await lockCase.startEdit(wrapper)

    expect(describedTexts(wrapper, locale)).toEqual([...permanent, ENTRY_LOCK_EDITING_FR])
  })
}

/** Le bouton « Proposer la version XX » : désactivé et décrit par le texte du verrou pendant une proposition, libre après « Annuler ». */
function describeTranslateButtonLock(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { form } = lockCase
  const { prefix } = kit

  // Décision 1 de la huitième relecture (R93, CR8-1) : une seconde traduction depuis la proposition remettait
  // le formulaire dans la langue source, groupe gardé (Sp7-1) ; le serveur la refuse (409). Le bouton est
  // désactivé et décrit par le texte du verrou, qui dit que « Annuler » est la sortie.
  it(`${prefix}après une proposition, « Proposer la version … » est désactivé et décrit par le texte du verrou`, async () => {
    const { wrapper } = await kit.mountCase()

    await lockCase.startEdit(wrapper)
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeUndefined()
    await lockCase.propose(wrapper)

    const button = translateEntryButton(wrapper, form)
    expect(button.attributes('disabled')).toBeDefined()
    expect(textsDescribing(wrapper, button)).toEqual([ENTRY_LOCK_PROPOSAL_FR])
  })

  it(`${prefix}« Annuler » après une proposition rend le bouton de traduction libre de tout texte de verrou`, async () => {
    const { wrapper } = await kit.mountCase()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)
    await kit.cancelForm(wrapper)

    // Le formulaire est vide : le bouton reste désactivé faute de prose, mais ne désigne plus aucun verrou
    // (une IDREF vers un texte retiré serait pendante, et `textsDescribing` échoue sur elle).
    expect(textsDescribing(wrapper, translateEntryButton(wrapper, form))).toEqual([])
  })
}

/** « Version de » : verrouillé pendant une proposition, libre en édition ; l'explication du verrou de la langue, que « Annuler » retire. */
function describeGroupLock(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { ids, locale, group, prefix, permanent } = kit

  // Décision 3 de la huitième relecture (R94, Sp8-5) : pendant une proposition, « Version de » est verrouillé comme
  // la langue. « aucune » détacherait la proposition de sa source (D-b) ; « Annuler » est la sortie. En édition,
  // il reste libre (détacher une entrée de son groupe est un geste permis) et ne désigne aucun verrou.
  it(`${prefix}après une proposition, « Version de » est désactivé et décrit par le texte du verrou ; « Annuler » le libère`, async () => {
    const { wrapper } = await kit.mountCase()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)

    expect(wrapper.get(group).attributes('disabled')).toBeDefined()
    expect(describedTexts(wrapper, group)).toEqual([ENTRY_LOCK_PROPOSAL_FR])

    await kit.cancelForm(wrapper)

    expect(wrapper.get(group).attributes('disabled')).toBeUndefined()
    expect(describedTexts(wrapper, group)).toEqual([])
  })

  it(`${prefix}en édition, « Version de » reste libre et ne désigne aucun texte de verrou`, async () => {
    const { wrapper } = await kit.mountCase()

    await lockCase.startEdit(wrapper)

    expect(wrapper.get(group).attributes('disabled')).toBeUndefined()
    expect(describedTexts(wrapper, group)).toEqual([])
  })

  it(`${prefix}après une proposition, le verrou de la langue est expliqué et « Annuler » retire l'explication`, async () => {
    const { wrapper } = await kit.mountCase()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)

    expect(describedTexts(wrapper, locale)).toEqual([...permanent, ENTRY_LOCK_PROPOSAL_FR])

    await kit.cancelForm(wrapper)

    expect(describedTexts(wrapper, locale)).toEqual(permanent)
    expect(wrapper.find(`#${ids.hint}`).exists()).toBe(false)
  })
}

/** Le refus : un « translate » émis par programme ne lance rien quand le bouton est désactivé, ni en double. */
function describeRefusals(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  describeRefusalsByLockOrOrder(lockCase, kit)
  describeRefusalsBySaveProseOrRepeat(lockCase, kit)
}

/** Le refus pendant une proposition et pendant un brouillon d'ordre : l'appel émis ne lance rien, le bouton est désactivé. */
function describeRefusalsByLockOrOrder(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { form } = lockCase
  const { prefix } = kit

  // Le bouton désactivé n'est pas la seule garde : un « translate » émis par programme, dans l'une ou l'autre langue
  // — la cible de la proposition comme sa source —, ne lance aucune traduction et laisse la proposition en place.
  // Le refus vient de `useAdminTranslation` (`isRefused`), pas seulement du `:disabled`.
  it(`${prefix}pendant une proposition, un « translate » émis ne lance aucune traduction`, async () => {
    const { translation, wrapper } = await kit.mountCase()

    await lockCase.startEdit(wrapper)
    await lockCase.propose(wrapper)
    const requests = vi.mocked(translation.translate).mock.calls.length
    const localeBefore = kit.localeValue(wrapper)

    for (const target of SUPPORTED_LOCALES) {
      await kit.emitTranslate(wrapper, target)
    }

    expect(vi.mocked(translation.translate)).toHaveBeenCalledTimes(requests)
    expect(kit.localeValue(wrapper)).toBe(localeBefore)
    expect(kit.headingOf(wrapper)).toBe(lockCase.heading)
    expect(kit.proposalNotice(wrapper).exists()).toBe(true)
  })

  // Décision 5 de la onzième relecture (R127) : le bouton est désactivé pendant un brouillon d'ordre (une proposition que le
  // formulaire verrouillé ne pourrait pas enregistrer brûlerait du quota) ; un « translate » émis par programme l'est aussi.
  it(`${prefix}un ordre modifié : un « translate » émis ne lance aucune traduction`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    await moveFirstRowDown(wrapper, lockCase.orderTable)
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()

    await kit.emitTranslate(wrapper, 'en')

    expect(vi.mocked(translation.translate)).not.toHaveBeenCalled()
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })

  // Le bouton et `translate` lisent le même refus (R137) : un brouillon d'ordre modifié désactive le bouton, et c'est la source
  // que lit aussi le composable. Retirer cette condition du refus de la page rouvrirait le bouton et l'appel à la fois.
  it(`${prefix}un ordre modifié : le bouton de traduction est désactivé`, async () => {
    const { wrapper } = await kit.mountEdited()
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeUndefined()

    await moveFirstRowDown(wrapper, lockCase.orderTable)

    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()
  })
}

/** Le refus pendant l'enregistrement du formulaire, sans prose à traduire, ou avant la première réponse. */
function describeRefusalsBySaveProseOrRepeat(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { form } = lockCase
  const { prefix } = kit

  // R127b (décision de l'auteur du 2026-10-31) : le bouton est aussi désactivé pendant l'enregistrement du formulaire et sans
  // aucune prose à traduire ; `isRefused` reprend ces deux conditions, faute de quoi un « translate » émis les passerait.
  it(`${prefix}un enregistrement en vol : un « translate » émis ne lance aucune traduction`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    lockCase.holdNextSave()
    await kit.formOf(wrapper).trigger('submit.prevent')
    await flushPromises()
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()

    await kit.emitTranslate(wrapper, 'en')

    expect(vi.mocked(translation.translate)).not.toHaveBeenCalled()
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })

  it(`${prefix}sans aucune prose à traduire : un « translate » émis ne lance aucune traduction`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    for (const id of kit.proseIds) {
      await wrapper.get(`#${id}`).setValue('')
    }
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()

    await kit.emitTranslate(wrapper, 'en')

    expect(vi.mocked(translation.translate)).not.toHaveBeenCalled()
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
    expect(kit.errorAlert(wrapper).exists()).toBe(false)
  })

  it(`${prefix}deux « translate » avant la première réponse : une seule traduction`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    const reply = deferNextTranslation(translation)

    kit.translateButtonComponent(wrapper).vm.$emit('translate', 'en')
    await kit.emitTranslate(wrapper, 'fr')
    await reply.release()

    expect(vi.mocked(translation.translate)).toHaveBeenCalledTimes(1)
    expect(kit.proposalNotice(wrapper).exists()).toBe(true)
  })
}

/**
 * La péremption : chaque geste qui change le formulaire, ou supprime ce que la traduction suppose, en périme la réponse ;
 * ce qui ne le change pas (la suppression de l'autre langue) la laisse se poser. Une famille de gestes par fonction.
 */
function describeExpiry(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  describeExpiryByCancel(lockCase, kit)
  describeExpiryByAnotherForm(lockCase, kit)
  describeExpiryByRepeatedGesture(lockCase, kit)
  describeExpiryByDeletion(lockCase, kit)
  describeExpiryByFormChange(lockCase, kit)
}

/** « Annuler » : la réponse qui arrive ne remplit rien, et l'appel parti refuse le suivant jusqu'à sa réponse. */
function describeExpiryByCancel(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { form } = lockCase
  const { prefix } = kit

  it(`${prefix}« Annuler » pendant une traduction : la réponse qui arrive ne remplit rien`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    await kit.cancelForm(wrapper)
    await reply.release()

    expect(kit.titleValue(wrapper)).toBe('')
    expect(kit.headingOf(wrapper)).toBe(lockCase.heading)
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })

  // Décision 2 de la onzième relecture (R125) : jamais plus d'un appel au modèle en vol par formulaire. « Annuler » vide le
  // formulaire mais l'appel déjà parti continue : une nouvelle traduction émise aussitôt est refusée, et le bouton dit qu'il attend.
  it(`${prefix}« Annuler » puis « translate » avant la réponse périmée : une seule traduction, l'attente dite`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    await kit.cancelForm(wrapper)
    await kit.emitTranslate(wrapper, 'en')

    expect(vi.mocked(translation.translate)).toHaveBeenCalledTimes(1)
    expect(textsDescribing(wrapper, translateEntryButton(wrapper, form))).toEqual([EXPIRED_CALL_PENDING_FR])
    await expectNoAccessibilityViolation(wrapper)

    await reply.release()

    expect(textsDescribing(wrapper, translateEntryButton(wrapper, form))).toEqual([])
    expect(kit.titleValue(wrapper)).toBe('')
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })
}

/** Un geste qui remplace le formulaire par celui d'une autre entrée, ou par une création : la réponse n'y pose rien. */
function describeExpiryByAnotherForm(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { prefix } = kit

  // Chaque geste qui remplace le contenu du formulaire périme la traduction en vol : sa réponse n'y pose ni prose ni avis.
  it(`${prefix}« Modifier » une autre entrée pendant une traduction : la réponse qui arrive ne remplit rien`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    await lockCase.startEditOther(wrapper)
    const titleOfOther = kit.titleValue(wrapper)
    await reply.release()

    expect(kit.titleValue(wrapper)).toBe(titleOfOther)
    expect(kit.headingOf(wrapper)).not.toBe(lockCase.heading)
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })

  it(`${prefix}« Créer la version » pendant une traduction : la réponse qui arrive ne remplit rien`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    await lockCase.startCreateVersion(wrapper)
    await reply.release()

    expect(kit.titleValue(wrapper)).toBe('')
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })
}

/** Le même geste refait sur la même ligne ou la même entrée : l'identité est inchangée, la génération du formulaire avance. */
function describeExpiryByRepeatedGesture(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { prefix } = kit

  // Un geste qui remplit ou vide le formulaire sans changer son identité (même ligne, même langue, même entrée) avance quand même sa
  // génération : recliquer « Créer la version XX » ou « Modifier » vide ou recharge la prose, et la réponse partie avant ne s'y pose pas.
  // Les clics de `startCreateVersion` et de `startEdit` sont ceux de la même ligne : les refaire est le même geste.
  it(`${prefix}« Créer la version XX » recliqué pendant une traduction : la réponse ne pose rien`, async () => {
    const { translation, wrapper } = await kit.mountCase()
    await lockCase.startCreateVersion(wrapper)
    await kit.typeTitle(wrapper, 'Titre saisi')
    const formLocale = kit.localeValue(wrapper)
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, kit.otherLocale(formLocale))
    await lockCase.startCreateVersion(wrapper)
    await reply.release()

    expect(vi.mocked(translation.translate)).toHaveBeenCalledTimes(1)
    expect(kit.titleValue(wrapper)).toBe('')
    kit.expectProseEmpty(wrapper)
    expect(kit.localeValue(wrapper)).toBe(formLocale)
    expect(kit.headingOf(wrapper)).toBe(lockCase.heading)
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })

  it(`${prefix}« Modifier » de l'entrée éditée recliqué pendant une traduction : la réponse ne pose rien`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    const titleOfEntry = kit.titleValue(wrapper)
    const editHeading = kit.headingOf(wrapper)
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    await lockCase.startEdit(wrapper)
    await reply.release()

    expect(vi.mocked(translation.translate)).toHaveBeenCalledTimes(1)
    expect(kit.titleValue(wrapper)).toBe(titleOfEntry)
    expect(kit.headingOf(wrapper)).toBe(editHeading)
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })
}

/** Une suppression : ce que l'identité du formulaire ne voit pas, que `removeEntry` juge ; l'autre langue ne périme rien. */
function describeExpiryByDeletion(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { prefix } = kit

  // Ce que l'identité du formulaire ne voit pas : supprimer l'entrée éditée ne change rien au formulaire, `removeEntry` de
  // `useTranslatedForm` périme la traduction. Changer la langue d'une création change l'identité : le composable la périme seul.
  it(`${prefix}supprimer l'entrée en édition pendant une traduction : la réponse ne pose rien`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    const titleOfEntry = kit.titleValue(wrapper)
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    await kit.confirmingDelete(() => lockCase.deleteEditedEntry(wrapper))
    await reply.release()

    expect(kit.titleValue(wrapper)).toBe(titleOfEntry)
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })

  // Supprimer l'entrée source d'une « Créer la version XX » ne change rien au formulaire (ni entrée, ni langue, ni groupe) : la
  // proposition serait posée sur un groupe sans membre, alors que son entrée source n'existe plus (R140). Cette source est
  // l'entrée que `startEdit` ouvre, celle de `deleteEditedEntry`.
  it(`${prefix}supprimer l'entrée source d'une création rattachée pendant une traduction : la réponse ne pose rien`, async () => {
    const sourceTitle = await kit.titleOfEditedEntry()
    const { translation, wrapper } = await kit.mountCase()
    await lockCase.startCreateVersion(wrapper)
    kit.expectCreationAttachedTo(wrapper, sourceTitle)
    await kit.typeTitle(wrapper, 'Titre saisi')
    const formLocale = kit.localeValue(wrapper)
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, kit.otherLocale(formLocale))
    await kit.confirmingDelete(() => lockCase.deleteEditedEntry(wrapper))
    await reply.release()

    expect(vi.mocked(translation.translate)).toHaveBeenCalledTimes(1)
    expect(kit.titleValue(wrapper)).toBe('Titre saisi')
    expect(kit.localeValue(wrapper)).toBe(formLocale)
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })

  // Voisin du cas précédent : supprimer l'autre langue de l'entrée éditée ne change ni l'entrée, ni le formulaire, ni la source de la
  // traduction (R140) : la proposition arrive et se pose, la traduction n'est pas périmée.
  it(`${prefix}supprimer l'autre langue de l'entrée éditée pendant une traduction : la proposition est posée`, async () => {
    const { translation, wrapper } = await kit.mountCase()
    await lockCase.startEditOther(wrapper)
    // La page est en édition : sa proposition la bascule en création (le titre proposé peut égaler le titre lu : on lit le titre `h2`).
    expect(kit.headingOf(wrapper)).not.toBe(lockCase.heading)
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    await kit.confirmingDelete(() => lockCase.deleteSiblingOfOther(wrapper))
    await reply.release()

    expect(kit.headingOf(wrapper)).toBe(lockCase.heading)
    expect(kit.proposalNotice(wrapper).exists()).toBe(true)
  })
}

/** Un enregistrement ou un changement de langue du formulaire pendant la traduction : sa réponse ne pose rien. */
function describeExpiryByFormChange(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { form } = lockCase
  const { locale, prefix } = kit

  // Un formulaire vierge enregistré pendant une traduction : l'enregistrement le remet à zéro sans changer l'entrée, la langue ni
  // le groupe, et la réponse du modèle, arrivée après, ne doit rien poser sur ce formulaire vidé (la génération du formulaire).
  it(`${prefix}un formulaire vierge enregistré pendant une traduction : la réponse ne pose rien`, async () => {
    const { translation, wrapper } = await kit.mountCase()
    await kit.typeTitle(wrapper, 'Titre saisi')
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    // Pendant l'attente le bouton dit qu'il travaille ; l'enregistrement qui suit ne le libère pas (l'appel est en vol).
    expect(translateEntryButton(wrapper, form).attributes('aria-busy')).toBe('true')
    await kit.formOf(wrapper).trigger('submit.prevent')
    await flushPromises()
    await reply.release()

    expect(kit.titleValue(wrapper)).toBe('')
    kit.expectProseEmpty(wrapper)
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
    expect(kit.localeValue(wrapper)).toBe('fr')
    expect(kit.headingOf(wrapper)).toBe(lockCase.heading)
    // Retour du bouton : plus occupé (`aria-busy="false"`, le composant ne retire pas l'attribut), inactif faute de prose,
    // actif dès qu'une prose est saisie — la réponse périmée ne le laisse pas bloqué.
    expect(translateEntryButton(wrapper, form).attributes('aria-busy')).toBe('false')
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()
    await kit.typeTitle(wrapper, 'Nouveau titre')
    expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeUndefined()
  })

  it(`${prefix}changer la langue d'une création pendant une traduction : la réponse ne pose rien`, async () => {
    const { translation, wrapper } = await kit.mountCase()
    await kit.typeTitle(wrapper, 'Titre saisi')
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    await wrapper.get(locale).setValue('en')
    await reply.release()

    expect(kit.titleValue(wrapper)).toBe('Titre saisi')
    expect(kit.headingOf(wrapper)).toBe(lockCase.heading)
    expect(kit.proposalNotice(wrapper).exists()).toBe(false)
  })
}

/** La prose en lecture seule : pendant la traduction, décrite par le texte d'aide, puis rendue à la réponse ou à l'échec. */
function describeReadonlyProse(kit: LockToolkit): void {
  const { locale, prefix } = kit

  // Décision 3 de la onzième relecture (R126) : pendant une traduction, la prose est en lecture seule (focusable et
  // lisible, pas `disabled`) et le dit ; la réponse remplace une prose que personne n'a pu changer entre-temps.
  it(`${prefix}pendant une traduction, chaque champ de prose est en lecture seule et décrit par le texte d'aide`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    const reply = deferNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')

    for (const id of kit.proseIds) {
      const field = wrapper.get(`#${id}`)
      expect(field.attributes('readonly'), `#${id} : readonly`).toBeDefined()
      expect(field.attributes('disabled'), `#${id} : disabled`).toBeUndefined()
      expect(describedTexts(wrapper, `#${id}`), `#${id} : aria-describedby`).toContain(PROSE_READONLY_FR)
    }
    expect(describedTexts(wrapper, locale)).not.toContain(PROSE_READONLY_FR)
    // Dans l'autre sens : aucun autre champ du formulaire n'est en lecture seule, ni ne manque à la liste de la page.
    const readonlyIds = kit.formOf(wrapper).findAll('[readonly]').map((field) => field.attributes('id'))
    expect([...readonlyIds].sort()).toEqual([...kit.proseIds].sort())
    await expectNoAccessibilityViolation(wrapper)

    await reply.release()

    for (const id of kit.proseIds) {
      expect(wrapper.get(`#${id}`).attributes('readonly'), `#${id} : readonly après la réponse`).toBeUndefined()
    }
    expect(wrapper.text()).not.toContain(PROSE_READONLY_FR)
  })

  it(`${prefix}une traduction en échec : la prose redevient modifiable, intacte, le texte d'aide disparaît`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    const titleOfEntry = kit.titleValue(wrapper)
    kit.failNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')

    for (const id of kit.proseIds) {
      expect(wrapper.get(`#${id}`).attributes('readonly'), `#${id} : readonly après l'échec`).toBeUndefined()
      expect(describedTexts(wrapper, `#${id}`), `#${id} : aria-describedby`).not.toContain(PROSE_READONLY_FR)
    }
    expect(wrapper.text()).not.toContain(PROSE_READONLY_FR)
    expect(kit.titleValue(wrapper)).toBe(titleOfEntry)
    expect(kit.errorAlert(wrapper).text()).toBe(TRANSLATION_UNAVAILABLE_FR)
    await expectNoAccessibilityViolation(wrapper)
  })
}

/** L'échec d'une traduction : son erreur survit à la frappe et s'efface à tout changement de formulaire. */
function describeFailure(lockCase: EntryFormLockCase, kit: LockToolkit): void {
  const { prefix } = kit

  // Décision de l'auteur du 2026-10-31 (après R124) : la prose n'est pas dans l'identité du formulaire ; la saisie est
  // empêchée pendant la traduction, et le message d'un échec (429, 503) n'est effacé que par un vrai changement de
  // formulaire ou la traduction suivante, pas par la frappe qui corrige la prose.
  it(`${prefix}l'erreur d'une traduction survit à la frappe`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    kit.failNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    expect(kit.errorAlert(wrapper).text()).toBe(TRANSLATION_UNAVAILABLE_FR)

    await kit.typeTitle(wrapper, 'Titre corrigé après l\'échec')
    await flushPromises()

    expect(kit.titleValue(wrapper)).toBe('Titre corrigé après l\'échec')
    expect(kit.errorAlert(wrapper).text()).toBe(TRANSLATION_UNAVAILABLE_FR)
  })

  // L'échec d'une traduction est effacé à tout changement d'identité du formulaire, et un retour à l'identité de l'échec ne le
  // rend pas : « Modifier » A (échec), « Modifier » B, puis A ne montre aucune erreur. Une frappe, elle, ne l'efface pas.
  it(`${prefix}une traduction en échec, une autre entrée ouverte, puis la première : aucune erreur`, async () => {
    const { translation, wrapper } = await kit.mountEdited()
    kit.failNextTranslation(translation)

    await kit.emitTranslate(wrapper, 'en')
    expect(kit.errorAlert(wrapper).text()).toBe(TRANSLATION_UNAVAILABLE_FR)

    await lockCase.startEditOther(wrapper)
    expect(kit.errorAlert(wrapper).exists()).toBe(false)
    await lockCase.startEdit(wrapper)

    expect(kit.errorAlert(wrapper).exists()).toBe(false)

    // Un nouvel échec sur la première entrée se montre, et la frappe qui suit ne l'efface pas.
    kit.failNextTranslation(translation)
    await kit.emitTranslate(wrapper, 'en')
    await kit.typeTitle(wrapper, 'Titre corrigé après le second échec')
    await flushPromises()

    expect(kit.errorAlert(wrapper).text()).toBe(TRANSLATION_UNAVAILABLE_FR)
  })
}

/**
 * Rend la prochaine réponse du dépôt différée jusqu'à `release()`, qui rend alors celle que le dépôt aurait
 * rendue d'emblée, puis règle les promesses : la traduction est « en vol » le temps du test.
 */
function deferNextTranslation(translation: AdminTranslationRepository): { readonly release: () => Promise<void> } {
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

  return {
    release: async () => {
      release()
      await flushPromises()
    },
  }
}
