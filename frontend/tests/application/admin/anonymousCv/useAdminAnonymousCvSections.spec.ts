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
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const SECTION_ID = '019968a0-0000-7000-8000-000000000003'
const GROUP_ID = '019968b0-0000-7000-8000-000000000003'
const SECTION: AdminAnonymousCvSection = {
  id: SECTION_ID, locale: 'fr', translationGroup: GROUP_ID, title: 'Backend', skills: 'Symfony',
  yearsOfExperience: 12, achievements: 'Réalisations.', position: 0,
}
const INPUT = {
  locale: 'fr', translationGroup: null, title: 'Backend', skills: 'Symfony', yearsOfExperience: 12,
  achievements: 'Réalisations.',
}

function createStubRepository(overrides: Partial<AdminAnonymousCvSectionRepository> = {}): AdminAnonymousCvSectionRepository {
  return {
    list: vi.fn(async () => [SECTION]),
    create: vi.fn(async () => SECTION),
    update: vi.fn(async () => SECTION),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
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

    await composable.remove(SECTION_ID)

    expect(repository.remove).toHaveBeenCalledWith(SECTION_ID)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it("expose la raison de l'échec d'une mutation sans vider la liste chargée", async () => {
    const repository = createStubRepository({
      update: vi.fn(async () => Promise.reject(new AdminAnonymousCvSectionError('validation', 'invalid'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    await composable.update(SECTION_ID, INPUT)

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

  it('délègue reorder() au repository sans recharger la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.reorder([GROUP_ID])

    expect(repository.reorder).toHaveBeenCalledWith([GROUP_ID])
    // useOrderDraft recharge lui-même après un enregistrement réussi ; le faire
    // ici aussi doublerait l'appel.
    expect(repository.list).not.toHaveBeenCalled()
  })

  it("laisse remonter l'AdminOrderError telle quelle, sans la convertir", async () => {
    const repository = createStubRepository({
      reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    const error = await composable.reorder([GROUP_ID]).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('stale-order')
    expect(composable.errorMessage.value).toBeNull()
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
