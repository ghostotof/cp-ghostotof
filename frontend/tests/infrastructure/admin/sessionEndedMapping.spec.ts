import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminAboutMeCardRepository } from '../../../src/infrastructure/admin/about/HttpAdminAboutMeCardRepository'
import { HttpAdminAboutSettingsRepository } from '../../../src/infrastructure/admin/about/HttpAdminAboutSettingsRepository'
import { HttpAdminAboutSiteCardRepository } from '../../../src/infrastructure/admin/about/HttpAdminAboutSiteCardRepository'
import { HttpAdminAnonymousCvSectionRepository } from '../../../src/infrastructure/admin/anonymousCv/HttpAdminAnonymousCvSectionRepository'
import { HttpAdminCaseStudyRepository } from '../../../src/infrastructure/admin/caseStudies/HttpAdminCaseStudyRepository'
import { HttpAdminContributionRepository } from '../../../src/infrastructure/admin/contributions/HttpAdminContributionRepository'
import { HttpAdminIncidentRepository } from '../../../src/infrastructure/admin/incidents/HttpAdminIncidentRepository'
import { HttpAdminQualityPrincipleRepository } from '../../../src/infrastructure/admin/quality/HttpAdminQualityPrincipleRepository'
import { HttpAdminQualityTraitRepository } from '../../../src/infrastructure/admin/quality/HttpAdminQualityTraitRepository'
import { HttpAdminExperienceTechnologyRepository } from '../../../src/infrastructure/admin/technologies/HttpAdminExperienceTechnologyRepository'
import { HttpAdminTranslationRepository } from '../../../src/infrastructure/admin/translation/HttpAdminTranslationRepository'
import { HttpAdminUserRepository } from '../../../src/infrastructure/admin/users/HttpAdminUserRepository'
import { HttpAdminVulnerabilityRepository } from '../../../src/infrastructure/admin/watch/HttpAdminVulnerabilityRepository'
import { HttpAdminWatchedProductRepository } from '../../../src/infrastructure/admin/watch/HttpAdminWatchedProductRepository'
import { AdminOrderError } from '../../../src/domain/admin/shared/errors/AdminOrderError'
import { SESSION_ENDED } from '../../../src/domain/admin/shared/errors/sessionEnded'

const API_BASE_URL = 'https://api.example.test'

/**
 * Un dépôt du backoffice, avec une lecture, une écriture et, s'il est ordonné, un ordre : de quoi
 * vérifier que chacun traduit « session finie » en la raison commune (#748, constats 7 et 15). Les
 * entrées sont des coquilles : la réponse est simulée, le corps envoyé n'est pas ce qu'on regarde.
 */
interface RepositoryCase {
  readonly name: string
  readonly read?: () => Promise<unknown>
  readonly write?: () => Promise<unknown>
  readonly reorder?: () => Promise<unknown>
}

const input = {} as never

const cases: readonly RepositoryCase[] = [
  (() => {
    const repository = new HttpAdminAboutMeCardRepository(API_BASE_URL)
    return {
      name: 'HttpAdminAboutMeCardRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
      reorder: () => repository.reorder(['a'], 'hobby' as never),
    }
  })(),
  (() => {
    const repository = new HttpAdminAboutSettingsRepository(API_BASE_URL)
    return {
      name: 'HttpAdminAboutSettingsRepository',
      read: () => repository.get('fr'),
      write: () => repository.update('fr', input),
    }
  })(),
  (() => {
    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    return {
      name: 'HttpAdminAboutSiteCardRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
      reorder: () => repository.reorder(['a']),
    }
  })(),
  (() => {
    const repository = new HttpAdminAnonymousCvSectionRepository(API_BASE_URL)
    return {
      name: 'HttpAdminAnonymousCvSectionRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
      reorder: () => repository.reorder(['a']),
    }
  })(),
  (() => {
    const repository = new HttpAdminCaseStudyRepository(API_BASE_URL)
    return {
      name: 'HttpAdminCaseStudyRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
      reorder: () => repository.reorder(['a']),
    }
  })(),
  (() => {
    const repository = new HttpAdminContributionRepository(API_BASE_URL)
    return {
      name: 'HttpAdminContributionRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
      reorder: () => repository.reorder(['a']),
    }
  })(),
  (() => {
    const repository = new HttpAdminIncidentRepository(API_BASE_URL)
    return {
      name: 'HttpAdminIncidentRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
      reorder: () => repository.reorder(['a']),
    }
  })(),
  (() => {
    const repository = new HttpAdminQualityPrincipleRepository(API_BASE_URL)
    return {
      name: 'HttpAdminQualityPrincipleRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
      reorder: () => repository.reorder(['a']),
    }
  })(),
  (() => {
    const repository = new HttpAdminQualityTraitRepository(API_BASE_URL)
    return {
      name: 'HttpAdminQualityTraitRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
      reorder: () => repository.reorder(['a']),
    }
  })(),
  (() => {
    const repository = new HttpAdminExperienceTechnologyRepository(API_BASE_URL)
    return {
      name: 'HttpAdminExperienceTechnologyRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
    }
  })(),
  (() => {
    const repository = new HttpAdminTranslationRepository(API_BASE_URL)
    return {
      name: 'HttpAdminTranslationRepository',
      write: () => repository.translate('fr', 'en', {}),
    }
  })(),
  (() => {
    const repository = new HttpAdminUserRepository(API_BASE_URL)
    return {
      name: 'HttpAdminUserRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
    }
  })(),
  (() => {
    const repository = new HttpAdminVulnerabilityRepository(API_BASE_URL)
    return {
      name: 'HttpAdminVulnerabilityRepository',
      read: () => repository.list(),
    }
  })(),
  (() => {
    const repository = new HttpAdminWatchedProductRepository(API_BASE_URL)
    return {
      name: 'HttpAdminWatchedProductRepository',
      read: () => repository.list(),
      write: () => repository.remove('id'),
      reorder: () => repository.reorder(['a'])
    }
  })(),
]

function problem(status: number): Response {
  return new Response(JSON.stringify({ code: status, message: 'x' }), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function stubStatus(status: number): void {
  vi.stubGlobal('fetch', vi.fn(async () => problem(status)))
}

function clearCsrfCookie(): void {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
}

const withRead = cases.filter((repository) => undefined !== repository.read)
const withWrite = cases.filter((repository) => undefined !== repository.write)
const withReorder = cases.filter((repository) => undefined !== repository.reorder)

describe('dépôts du backoffice — une session finie est la raison session-ended', () => {
  beforeEach(() => {
    clearCsrfCookie()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    clearCsrfCookie()
  })

  it.each(withRead.map((repository) => [repository.name, repository] as const))(
    '%s : un 401 en lecture est la raison session-ended',
    async (_name, repository) => {
      stubStatus(401)

      await expect(repository.read?.()).rejects.toMatchObject({ reason: SESSION_ENDED })
    },
  )

  it.each(withWrite.map((repository) => [repository.name, repository] as const))(
    '%s : un 403 à une écriture sans jeton est la raison session-ended',
    async (_name, repository) => {
      stubStatus(403)

      await expect(repository.write?.()).rejects.toMatchObject({ reason: SESSION_ENDED })
    },
  )

  it.each(withWrite.map((repository) => [repository.name, repository] as const))(
    '%s : un 403 à une écriture avec jeton garde son mapping',
    async (_name, repository) => {
      document.cookie = 'XSRF-TOKEN=jeton; path=/'
      stubStatus(403)

      const error: unknown = await repository.write?.().catch((caught: unknown) => caught)

      expect(error).toBeInstanceOf(Error)
      expect((error as { reason?: unknown }).reason).not.toBe(SESSION_ENDED)
    },
  )

  it.each(withReorder.map((repository) => [repository.name, repository] as const))(
    '%s : reorder en 401 est AdminOrderError session-ended',
    async (_name, repository) => {
      stubStatus(401)

      const error: unknown = await repository.reorder?.().catch((caught: unknown) => caught)

      expect(error).toBeInstanceOf(AdminOrderError)
      expect((error as AdminOrderError).reason).toBe(SESSION_ENDED)
    },
  )

  it('la table couvre tous les dépôts du backoffice', () => {
    const files = import.meta.glob('../../../src/infrastructure/admin/**/Http*Repository.ts')
    const onDisk = Object.keys(files)
      .map((path) => path.split('/').pop()?.replace(/\.ts$/, ''))
      .sort()

    expect(cases.map((repository) => repository.name).sort()).toEqual(onDisk)
  })
})
