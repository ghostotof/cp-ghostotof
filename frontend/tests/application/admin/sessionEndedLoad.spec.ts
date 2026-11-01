import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, type InjectionKey, type Ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { ADMIN_TRANSLATION_REPOSITORY, useAdminTranslation } from '../../../src/application/admin/translation/useAdminTranslation'
import { useOrderDraft } from '../../../src/application/admin/shared/useOrderDraft'
import { SessionEndedError } from '../../../src/domain/auth/errors/SessionEndedError'
import { ADMIN_ABOUT_ME_CARD_REPOSITORY, useAdminAboutMeCards } from '../../../src/application/admin/about/useAdminAboutMeCards'
import { ADMIN_ABOUT_SETTINGS_REPOSITORY, useAdminAboutSettings } from '../../../src/application/admin/about/useAdminAboutSettings'
import { ADMIN_ABOUT_SITE_CARD_REPOSITORY, useAdminAboutSiteCards } from '../../../src/application/admin/about/useAdminAboutSiteCards'
import {
  ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY,
  useAdminAnonymousCvSections,
} from '../../../src/application/admin/anonymousCv/useAdminAnonymousCvSections'
import { ADMIN_CASE_STUDY_REPOSITORY, useAdminCaseStudies } from '../../../src/application/admin/caseStudies/useAdminCaseStudies'
import { ADMIN_CONTRIBUTION_REPOSITORY, useAdminContributions } from '../../../src/application/admin/contributions/useAdminContributions'
import { ADMIN_INCIDENT_REPOSITORY, useAdminIncidents } from '../../../src/application/admin/incidents/useAdminIncidents'
import {
  ADMIN_QUALITY_PRINCIPLE_REPOSITORY,
  useAdminQualityPrinciples,
} from '../../../src/application/admin/quality/useAdminQualityPrinciples'
import { ADMIN_QUALITY_TRAIT_REPOSITORY, useAdminQualityTraits } from '../../../src/application/admin/quality/useAdminQualityTraits'
import {
  ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY,
  useAdminExperienceTechnologies,
} from '../../../src/application/admin/technologies/useAdminExperienceTechnologies'
import { ADMIN_USER_REPOSITORY, useAdminUsers } from '../../../src/application/admin/users/useAdminUsers'
import { ADMIN_VULNERABILITY_REPOSITORY, useAdminVulnerabilities } from '../../../src/application/admin/watch/useAdminVulnerabilities'
import { ADMIN_WATCHED_PRODUCT_REPOSITORY, useAdminWatchedProducts } from '../../../src/application/admin/watch/useAdminWatchedProducts'

/**
 * #748, constat 7 : un chargement refusé parce que la session est finie ne doit pas lever `hasError`
 * (l'alerte de chargement doublerait le bandeau de session finie), ni vider une liste déjà lue ; et,
 * R3, une mutation refusée pour la même raison ne pose aucune erreur (la page n'ajoute pas son
 * alerte au bandeau). Le client lève `SessionEndedError` : c'est elle que les dépôts simulés rejettent.
 *
 * Le dépôt est un Proxy dont toute méthode rejette l'erreur donnée : seule la lecture est exercée
 * pour les chargements, et la première mutation pour les mutations, de la même façon pour les treize
 * composables.
 */

/** Ce qu'un composable de liste d'administration expose de commun, et la valeur lue qu'il porte. */
interface Handle {
  hasError: Ref<boolean>
  isLoaded: Ref<boolean>
  load: (...args: never[]) => Promise<void>
  value: () => unknown
  /** La première mutation du composable, ou `null` pour une lecture seule. */
  mutate: (() => Promise<unknown>) | null
  /** L'erreur de mutation que la page afficherait. */
  shown: () => unknown
}

interface Case {
  name: string
  key: InjectionKey<unknown>
  /** Nom de la méthode de lecture du dépôt. */
  read: 'list' | 'get'
  compose: () => Handle
  /** Ce que le dépôt rend à une lecture réussie, et que le composable expose. */
  loaded: unknown
  /** Arguments de `load()` (la locale, pour le singleton des réglages). */
  args: unknown[]
}

const ONE = { id: 'x' }

/** Ce que les composables de liste exposent de commun : la lecture, la suppression (leur première mutation), l'erreur de mutation. */
interface ListComposable {
  hasError: Ref<boolean>
  isLoaded: Ref<boolean>
  load: Handle['load']
  remove: (id: string) => Promise<unknown>
  errorMessage: { readonly value: unknown }
}

/** Le `compose` d'un composable de liste : la valeur lue est celle de sa propriété `listProperty` (`cards`, `sections`…). */
function composeList<K extends string>(
  use: () => ListComposable & Record<K, { readonly value: unknown }>,
  listProperty: K,
): () => Handle {
  return () => {
    const c = use()
    return {
      hasError: c.hasError,
      isLoaded: c.isLoaded,
      load: c.load,
      value: () => c[listProperty].value,
      mutate: () => c.remove('x'),
      shown: () => c.errorMessage.value,
    }
  }
}

/**
 * Le cas d'un composable dont le dépôt lit une liste : une lecture `list` qui rend `[ONE]`, un `load()` sans argument. Seuls
 * `key` et `compose` changent d'un composable à l'autre ; les réglages, lus par `get`, s'écrivent en entier.
 */
function listCase(name: string, parts: Pick<Case, 'key' | 'compose'>): Case {
  return { name, read: 'list', loaded: [ONE], args: [], ...parts }
}

const CASES: Case[] = [
  listCase('useAdminAboutMeCards', { key: ADMIN_ABOUT_ME_CARD_REPOSITORY, compose: composeList(useAdminAboutMeCards, 'cards') }),
  // Le singleton des réglages : lu par `get`, une locale en argument de `load()`, `save` pour première mutation.
  {
    name: 'useAdminAboutSettings',
    key: ADMIN_ABOUT_SETTINGS_REPOSITORY,
    read: 'get',
    loaded: ONE,
    args: ['fr'],
    compose: () => {
      const c = useAdminAboutSettings()
      return {
        hasError: c.hasError,
        isLoaded: c.isLoaded,
        load: c.load,
        value: () => c.settings.value,
        mutate: () => c.save('fr', {} as never),
        shown: () => c.errorMessage.value,
      }
    },
  },
  listCase('useAdminAboutSiteCards', { key: ADMIN_ABOUT_SITE_CARD_REPOSITORY, compose: composeList(useAdminAboutSiteCards, 'cards') }),
  listCase('useAdminAnonymousCvSections', {
    key: ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY,
    compose: composeList(useAdminAnonymousCvSections, 'sections'),
  }),
  listCase('useAdminCaseStudies', { key: ADMIN_CASE_STUDY_REPOSITORY, compose: composeList(useAdminCaseStudies, 'caseStudies') }),
  listCase('useAdminContributions', { key: ADMIN_CONTRIBUTION_REPOSITORY, compose: composeList(useAdminContributions, 'contributions') }),
  listCase('useAdminIncidents', { key: ADMIN_INCIDENT_REPOSITORY, compose: composeList(useAdminIncidents, 'incidents') }),
  listCase('useAdminQualityPrinciples', {
    key: ADMIN_QUALITY_PRINCIPLE_REPOSITORY,
    compose: composeList(useAdminQualityPrinciples, 'principles'),
  }),
  listCase('useAdminQualityTraits', { key: ADMIN_QUALITY_TRAIT_REPOSITORY, compose: composeList(useAdminQualityTraits, 'traits') }),
  listCase('useAdminExperienceTechnologies', {
    key: ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY,
    compose: composeList(useAdminExperienceTechnologies, 'technologies'),
  }),
  listCase('useAdminUsers', { key: ADMIN_USER_REPOSITORY, compose: composeList(useAdminUsers, 'users') }),
  listCase('useAdminVulnerabilities', {
    key: ADMIN_VULNERABILITY_REPOSITORY,
    compose: () => {
      const c = useAdminVulnerabilities()
      // Pas de `load` exposé : le chargement part au montage, un second ne se déclenche pas à la demande.
      return {
        hasError: c.hasError,
        isLoaded: c.isLoaded,
        load: async () => undefined,
        value: () => c.vulnerabilities.value,
        mutate: null,
        shown: () => null,
      }
    },
  }),
  listCase('useAdminWatchedProducts', { key: ADMIN_WATCHED_PRODUCT_REPOSITORY, compose: composeList(useAdminWatchedProducts, 'products') }),
]

/**
 * Un dépôt dont la première lecture rend `loaded` (si `firstSucceeds`), les suivantes rejettent
 * `failure`, et dont toute autre méthode rejette `failure`.
 */
function failingRepository(testCase: Case, failure: Error, firstSucceeds: boolean): unknown {
  let reads = 0
  return new Proxy({}, {
    get: (_target, property) => async () => {
      if (property === testCase.read && firstSucceeds && 0 === reads++) return testCase.loaded
      throw failure
    },
  })
}

function mountComposable(testCase: Case, repository: unknown): Handle {
  let captured: Handle | undefined
  const Host = defineComponent({
    setup() {
      captured = testCase.compose()
      return () => h('div')
    },
  })
  mount(Host, { global: { provide: { [testCase.key as symbol]: repository } } })
  if (!captured) throw new Error("Le composable n'a pas été capturé.")
  return captured
}

/** Relance un chargement avec les arguments du cas (la locale, pour les réglages). */
async function reload(testCase: Case, handle: Handle): Promise<void> {
  await (handle.load as (...args: unknown[]) => Promise<void>)(...testCase.args)
  await flushPromises()
}

describe('chargement refusé : session finie', () => {
  it.each(CASES)('$name : un chargement refusé pour session finie ne lève pas hasError', async (testCase) => {
    const handle = mountComposable(testCase, failingRepository(testCase, new SessionEndedError(), false))
    await flushPromises()
    await reload(testCase, handle)

    expect(handle.hasError.value).toBe(false)
  })

  it.each(CASES)('$name : une première lecture en session finie laisse isLoaded faux', async (testCase) => {
    const handle = mountComposable(testCase, failingRepository(testCase, new SessionEndedError(), false))
    await flushPromises()
    await reload(testCase, handle)

    // Non chargé n'est pas vide (R4) : la page ne dit « aucune … » que si isLoaded est vrai.
    expect(handle.isLoaded.value).toBe(false)
  })

  it.each(CASES)('$name : une lecture réussie lève isLoaded', async (testCase) => {
    const handle = mountComposable(testCase, failingRepository(testCase, new SessionEndedError(), true))
    await flushPromises()
    if ('get' === testCase.read) await reload(testCase, handle)

    expect(handle.isLoaded.value).toBe(true)
  })

  it.each(CASES)('$name : tout autre échec lève hasError', async (testCase) => {
    const handle = mountComposable(testCase, failingRepository(testCase, new Error('boom'), false))
    await flushPromises()
    await reload(testCase, handle)

    expect(handle.hasError.value).toBe(true)
  })

  it.each(CASES)('$name : garde la liste déjà lue', async (testCase) => {
    // Premier chargement réussi, second refusé pour session finie (Review Focus 4). Les réglages ne se
    // chargent pas d'eux-mêmes : la page pilote `load(locale)`, on le fait ici.
    const handle = mountComposable(testCase, failingRepository(testCase, new SessionEndedError(), true))
    await flushPromises()
    if ('get' === testCase.read) await reload(testCase, handle)
    expect(handle.value()).toEqual(testCase.loaded)

    await reload(testCase, handle)

    expect(handle.value()).toEqual(testCase.loaded)
    expect(handle.hasError.value).toBe(false)
  })
})

/** Tous les composables ont une mutation, sauf la lecture seule des vulnérabilités. */
const MUTATING_CASES = CASES.filter((testCase) => 'useAdminVulnerabilities' !== testCase.name)

describe('mutation refusée : session finie', () => {
  it.each(MUTATING_CASES)('$name : une mutation refusée pour session finie ne pose aucune erreur', async (testCase) => {
    const handle = mountComposable(testCase, failingRepository(testCase, new SessionEndedError(), false))
    await flushPromises()

    const succeeded = await handle.mutate?.()
    await flushPromises()

    expect(handle.shown()).toBeNull()
    // Pas d'erreur à afficher ne veut pas dire « réussi » : la page garde alors sa saisie (régression vue
    // en R3, où `!errorMessage` valait succès et vidait le formulaire sous le bandeau).
    expect(succeeded).toBe(false)
  })

  it.each(MUTATING_CASES)('$name : toute autre mutation refusée pose l\'erreur « unknown » du contexte', async (testCase) => {
    const handle = mountComposable(testCase, failingRepository(testCase, new Error('boom'), false))
    await flushPromises()
    // Les réglages ne montrent l'erreur d'un enregistrement que sous la clé courante (R20) : la page a lu avant d'enregistrer.
    if ('get' === testCase.read) await reload(testCase, handle)

    const succeeded = await handle.mutate?.()
    await flushPromises()

    expect(handle.shown()).toMatchObject({ reason: 'unknown' })
    expect(succeeded).toBe(false)
  })

  it("useAdminTranslation : une traduction refusée pour session finie ne pose aucune raison", async () => {
    let captured: ReturnType<typeof useAdminTranslation> | undefined
    mount(
      defineComponent({
        setup() {
          captured = useAdminTranslation()
          return () => h('div')
        },
      }),
      {
        global: {
          provide: { [ADMIN_TRANSLATION_REPOSITORY as symbol]: { translate: async () => Promise.reject(new SessionEndedError()) } },
        },
      },
    )

    const accept = vi.fn()
    const accepted = await captured?.translate('fr', 'en', {}, accept)

    expect(accepted).toBe(false)
    expect(accept).not.toHaveBeenCalled()
    expect(captured?.errorReason.value).toBeNull()
  })

  it("useAdminTranslation : tout autre échec pose la raison « unknown »", async () => {
    let captured: ReturnType<typeof useAdminTranslation> | undefined
    mount(
      defineComponent({
        setup() {
          captured = useAdminTranslation()
          return () => h('div')
        },
      }),
      { global: { provide: { [ADMIN_TRANSLATION_REPOSITORY as symbol]: { translate: async () => Promise.reject(new Error('boom')) } } } },
    )

    await captured?.translate('fr', 'en', {}, vi.fn())

    expect(captured?.errorReason.value).toBe('unknown')
  })

  it("useOrderDraft : un enregistrement refusé pour session finie ne pose aucune raison, et garde le brouillon", async () => {
    const draft = useOrderDraft({
      serverKeys: () => ['a', 'b'],
      reorder: async () => Promise.reject(new SessionEndedError()),
      reload: async () => undefined,
    })
    draft.move(0, 1)

    await draft.save()

    expect(draft.errorReason.value).toBeNull()
    expect(draft.draft.value).toEqual(['b', 'a'])
    expect(draft.isDirty.value).toBe(true)
  })

  it("useOrderDraft : tout autre échec pose la raison « unknown »", async () => {
    const draft = useOrderDraft({
      serverKeys: () => ['a', 'b'],
      reorder: async () => Promise.reject(new Error('boom')),
      reload: async () => undefined,
    })
    draft.move(0, 1)

    await draft.save()

    expect(draft.errorReason.value).toBe('unknown')
  })
})
