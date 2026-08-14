import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import {
  ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY,
  useAdminExperienceTechnologies,
} from '../../../../src/application/admin/technologies/useAdminExperienceTechnologies'
import type { AdminExperienceTechnologyRepository } from '../../../../src/domain/admin/technologies/repositories/AdminExperienceTechnologyRepository'
import type { AdminExperienceTechnology } from '../../../../src/domain/admin/technologies/entities/AdminExperienceTechnology'
import { AdminExperienceTechnologyError } from '../../../../src/domain/admin/technologies/errors/AdminExperienceTechnologyError'

const TECHNOLOGY: AdminExperienceTechnology = { id: 1, name: 'PHP', years: 13.5, iconKey: null, relatedTechnologyName: null }

function createStubRepository(overrides: Partial<AdminExperienceTechnologyRepository> = {}): AdminExperienceTechnologyRepository {
  return {
    list: vi.fn(async () => [TECHNOLOGY]),
    create: vi.fn(async () => TECHNOLOGY),
    update: vi.fn(async () => TECHNOLOGY),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminExperienceTechnologyRepository) {
  let captured: ReturnType<typeof useAdminExperienceTechnologies> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAdminExperienceTechnologies()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAdminExperienceTechnologies() did not run during mount')
  }

  return captured
}

describe('useAdminExperienceTechnologies', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAdminExperienceTechnologies()
        return () => h('div')
      },
    })
    expect(() => mount(Probe)).toThrow(/AdminExperienceTechnologyRepository/)
  })

  it('charge la liste au montage', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)

    expect(composable.isLoading.value).toBe(true)
    await composable.load()

    expect(repository.list).toHaveBeenCalled()
    expect(composable.technologies.value).toEqual([TECHNOLOGY])
    expect(composable.isLoading.value).toBe(false)
  })

  it('hasError passe à true si le chargement échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const composable = mountWithComposable(repository)

    await composable.load()

    expect(composable.hasError.value).toBe(true)
  })

  it('create() appelle le repository puis recharge la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load()
    vi.mocked(repository.list).mockClear()

    await composable.create({ name: 'Vue', years: 3, iconKey: null, relatedTechnologyName: null })

    expect(repository.create).toHaveBeenCalledWith({ name: 'Vue', years: 3, iconKey: null, relatedTechnologyName: null })
    expect(repository.list).toHaveBeenCalledTimes(1)
    expect(composable.errorMessage.value).toBeNull()
  })

  it('update() propage errorMessage sans planter en cas d\'échec', async () => {
    const repository = createStubRepository({
      update: vi.fn(async () => Promise.reject(new AdminExperienceTechnologyError('duplicate', 'Nom déjà pris'))),
    })
    const composable = mountWithComposable(repository)
    await composable.load()

    await composable.update(1, { name: 'PHP', years: 1, iconKey: null, relatedTechnologyName: null })

    expect(composable.errorMessage.value?.reason).toBe('duplicate')
  })

  it('remove() appelle le repository puis recharge la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load()
    vi.mocked(repository.list).mockClear()

    await composable.remove(1)

    expect(repository.remove).toHaveBeenCalledWith(1)
    expect(repository.list).toHaveBeenCalledTimes(1)
  })
})
