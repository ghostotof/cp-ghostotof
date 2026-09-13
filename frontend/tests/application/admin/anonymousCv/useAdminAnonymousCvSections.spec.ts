import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import {
  ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY,
  useAdminAnonymousCvSections,
} from '../../../../src/application/admin/anonymousCv/useAdminAnonymousCvSections'
import type { AdminAnonymousCvSection } from '../../../../src/domain/admin/anonymousCv/entities/AdminAnonymousCvSection'
import type { AdminAnonymousCvSectionRepository } from '../../../../src/domain/admin/anonymousCv/repositories/AdminAnonymousCvSectionRepository'
import { AdminAnonymousCvSectionError } from '../../../../src/domain/admin/anonymousCv/errors/AdminAnonymousCvSectionError'

const SECTION: AdminAnonymousCvSection = {
  id: 1, locale: 'fr', title: 'Backend', skills: 'Symfony', yearsOfExperience: 12, achievements: 'Réalisations.', position: 0,
}
const INPUT = {
  locale: 'fr', title: 'Backend', skills: 'Symfony', yearsOfExperience: 12, achievements: 'Réalisations.', position: 0,
}

function createStubRepository(overrides: Partial<AdminAnonymousCvSectionRepository> = {}): AdminAnonymousCvSectionRepository {
  return {
    list: vi.fn(async () => [SECTION]),
    create: vi.fn(async () => SECTION),
    update: vi.fn(async () => SECTION),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminAnonymousCvSectionRepository) {
  let captured: ReturnType<typeof useAdminAnonymousCvSections> | undefined
  const Host = defineComponent({
    setup() {
      captured = useAdminAnonymousCvSections()
      return () => h('div')
    },
  })
  mount(Host, { global: { provide: { [ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY as symbol]: repository } } })
  if (!captured) throw new Error("Le composable n'a pas été capturé.")
  return captured
}

describe('useAdminAnonymousCvSections', () => {
  it('charge les sections au montage', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(repository.list).toHaveBeenCalledOnce()
    expect(composable.sections.value).toEqual([SECTION])
    expect(composable.isLoading.value).toBe(false)
  })

  it('recharge la liste après une création réussie', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.create(INPUT)

    expect(repository.create).toHaveBeenCalledWith(INPUT)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it('recharge la liste après une suppression réussie', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.remove(1)

    expect(repository.remove).toHaveBeenCalledWith(1)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it("expose la raison de l'échec d'une mutation sans vider la liste chargée", async () => {
    const repository = createStubRepository({
      update: vi.fn(async () => Promise.reject(new AdminAnonymousCvSectionError('validation', 'invalid'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    await composable.update(1, INPUT)

    expect(composable.errorMessage.value?.reason).toBe('validation')
    expect(composable.hasError.value).toBe(false)
    expect(composable.sections.value).toEqual([SECTION])
  })

  it('bascule hasError quand le chargement initial échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('down'))) })
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(composable.hasError.value).toBe(true)
    expect(composable.sections.value).toEqual([])
  })

  it("échoue explicitement si le repository n'a pas été fourni", () => {
    const Host = defineComponent({
      setup() {
        useAdminAnonymousCvSections()
        return () => h('div')
      },
    })

    expect(() => mount(Host)).toThrow(/AdminAnonymousCvSectionRepository/)
  })
})
