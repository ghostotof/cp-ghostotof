import { describe, expect, it } from 'vitest'
import { defineComponent, h, type InjectionKey, type Ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
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
import { AdminAboutError } from '../../../src/domain/admin/about/errors/AdminAboutError'
import { AdminAnonymousCvSectionError } from '../../../src/domain/admin/anonymousCv/errors/AdminAnonymousCvSectionError'
import { AdminCaseStudyError } from '../../../src/domain/admin/caseStudies/errors/AdminCaseStudyError'
import { AdminContributionError } from '../../../src/domain/admin/contributions/errors/AdminContributionError'
import { AdminIncidentError } from '../../../src/domain/admin/incidents/errors/AdminIncidentError'
import { AdminQualityError } from '../../../src/domain/admin/quality/errors/AdminQualityError'
import { AdminExperienceTechnologyError } from '../../../src/domain/admin/technologies/errors/AdminExperienceTechnologyError'
import { AdminUserError } from '../../../src/domain/admin/users/errors/AdminUserError'
import { AdminWatchedProductError } from '../../../src/domain/admin/watch/errors/AdminWatchedProductError'

/**
 * #748, constat 7 : un chargement refusé parce que la session est finie ne doit pas lever `hasError`
 * (l'alerte de chargement doublerait le bandeau de session finie), ni vider une liste déjà lue.
 *
 * Le dépôt est un Proxy dont toute méthode rejette l'erreur donnée : seul `list()`/`get()` est exercé
 * ici, et il l'est de la même façon pour les treize composables.
 */
type Reason = 'session-ended' | 'unknown'

/** Ce qu'un composable de liste d'administration expose de commun, et la valeur lue qu'il porte. */
interface Handle {
  hasError: Ref<boolean>
  load: (...args: never[]) => Promise<void>
  value: () => unknown
}

interface Case {
  name: string
  key: InjectionKey<never>
  /** Fabrique l'erreur de la classe du dépôt avec la raison donnée. */
  error: (reason: Reason) => Error
  /** Nom de la méthode de lecture du dépôt. */
  read: 'list' | 'get'
  compose: () => Handle
  /** Ce que le dépôt rend à une lecture réussie, et que le composable expose. */
  loaded: unknown
  /** Arguments de `load()` (la locale, pour le singleton des réglages). */
  args: unknown[]
}

// La raison « session-ended » est ajoutée aux unions de raisons par cette même tâche : le transtypage
// vers `never` tient ce test indépendant de l'état des unions, il ne vérifie que le comportement.
const asReason = <T>(reason: Reason): T => reason as never

const ONE = { id: 'x' }

const CASES: Case[] = [
  {
    name: 'useAdminAboutMeCards', key: ADMIN_ABOUT_ME_CARD_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminAboutError(asReason(reason), 'x'),
    compose: () => { const c = useAdminAboutMeCards(); return { hasError: c.hasError, load: c.load, value: () => c.cards.value } },
  },
  {
    name: 'useAdminAboutSettings', key: ADMIN_ABOUT_SETTINGS_REPOSITORY as never, read: 'get', loaded: ONE, args: ['fr'],
    error: (reason) => new AdminAboutError(asReason(reason), 'x'),
    compose: () => { const c = useAdminAboutSettings(); return { hasError: c.hasError, load: c.load, value: () => c.settings.value } },
  },
  {
    name: 'useAdminAboutSiteCards', key: ADMIN_ABOUT_SITE_CARD_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminAboutError(asReason(reason), 'x'),
    compose: () => { const c = useAdminAboutSiteCards(); return { hasError: c.hasError, load: c.load, value: () => c.cards.value } },
  },
  {
    name: 'useAdminAnonymousCvSections', key: ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminAnonymousCvSectionError(asReason(reason), 'x'),
    compose: () => { const c = useAdminAnonymousCvSections(); return { hasError: c.hasError, load: c.load, value: () => c.sections.value } },
  },
  {
    name: 'useAdminCaseStudies', key: ADMIN_CASE_STUDY_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminCaseStudyError(asReason(reason), 'x'),
    compose: () => { const c = useAdminCaseStudies(); return { hasError: c.hasError, load: c.load, value: () => c.caseStudies.value } },
  },
  {
    name: 'useAdminContributions', key: ADMIN_CONTRIBUTION_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminContributionError(asReason(reason), 'x'),
    compose: () => { const c = useAdminContributions(); return { hasError: c.hasError, load: c.load, value: () => c.contributions.value } },
  },
  {
    name: 'useAdminIncidents', key: ADMIN_INCIDENT_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminIncidentError(asReason(reason), 'x'),
    compose: () => { const c = useAdminIncidents(); return { hasError: c.hasError, load: c.load, value: () => c.incidents.value } },
  },
  {
    name: 'useAdminQualityPrinciples', key: ADMIN_QUALITY_PRINCIPLE_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminQualityError(asReason(reason), 'x'),
    compose: () => { const c = useAdminQualityPrinciples(); return { hasError: c.hasError, load: c.load, value: () => c.principles.value } },
  },
  {
    name: 'useAdminQualityTraits', key: ADMIN_QUALITY_TRAIT_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminQualityError(asReason(reason), 'x'),
    compose: () => { const c = useAdminQualityTraits(); return { hasError: c.hasError, load: c.load, value: () => c.traits.value } },
  },
  {
    name: 'useAdminExperienceTechnologies', key: ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminExperienceTechnologyError(asReason(reason), 'x'),
    compose: () => { const c = useAdminExperienceTechnologies(); return { hasError: c.hasError, load: c.load, value: () => c.technologies.value } },
  },
  {
    name: 'useAdminUsers', key: ADMIN_USER_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminUserError(asReason(reason), 'x'),
    compose: () => { const c = useAdminUsers(); return { hasError: c.hasError, load: c.load, value: () => c.users.value } },
  },
  {
    name: 'useAdminVulnerabilities', key: ADMIN_VULNERABILITY_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    // Lecture seule : ce dépôt n'a pas de classe d'erreur propre, la raison portée par l'objet suffit.
    error: (reason) => Object.assign(new Error('x'), { reason }),
    compose: () => {
      const c = useAdminVulnerabilities()
      // Pas de `load` exposé : le chargement part au montage, un second ne se déclenche pas à la demande.
      return { hasError: c.hasError, load: async () => undefined, value: () => c.vulnerabilities.value }
    },
  },
  {
    name: 'useAdminWatchedProducts', key: ADMIN_WATCHED_PRODUCT_REPOSITORY as never, read: 'list', loaded: [ONE], args: [],
    error: (reason) => new AdminWatchedProductError(asReason(reason), 'x'),
    compose: () => { const c = useAdminWatchedProducts(); return { hasError: c.hasError, load: c.load, value: () => c.products.value } },
  },
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
    const handle = mountComposable(testCase, failingRepository(testCase, testCase.error('session-ended'), false))
    await flushPromises()
    await reload(testCase, handle)

    expect(handle.hasError.value).toBe(false)
  })

  it.each(CASES)('$name : tout autre échec lève hasError', async (testCase) => {
    const handle = mountComposable(testCase, failingRepository(testCase, testCase.error('unknown'), false))
    await flushPromises()
    await reload(testCase, handle)

    expect(handle.hasError.value).toBe(true)
  })

  it.each(CASES)('$name : garde la liste déjà lue', async (testCase) => {
    // Premier chargement réussi, second refusé pour session finie (Review Focus 4). Les réglages ne se
    // chargent pas d'eux-mêmes : la page pilote `load(locale)`, on le fait ici.
    const handle = mountComposable(testCase, failingRepository(testCase, testCase.error('session-ended'), true))
    await flushPromises()
    if ('get' === testCase.read) await reload(testCase, handle)
    expect(handle.value()).toEqual(testCase.loaded)

    await reload(testCase, handle)

    expect(handle.value()).toEqual(testCase.loaded)
    expect(handle.hasError.value).toBe(false)
  })
})
