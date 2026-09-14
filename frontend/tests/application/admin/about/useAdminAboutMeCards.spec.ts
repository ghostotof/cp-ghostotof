import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import {
  ADMIN_ABOUT_ME_CARD_REPOSITORY,
  useAdminAboutMeCards,
} from '../../../../src/application/admin/about/useAdminAboutMeCards'
import type { AdminAboutMeCardRepository } from '../../../../src/domain/admin/about/repositories/AdminAboutMeCardRepository'
import type { AdminAboutMeCard } from '../../../../src/domain/admin/about/entities/AdminAboutMeCard'
import { AdminAboutError } from '../../../../src/domain/admin/about/errors/AdminAboutError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const ME_CARD_ID = '019968a0-0000-7000-8000-000000000001'
const GROUP_ID = '019968b0-0000-7000-8000-000000000001'
const CARD: AdminAboutMeCard = {
  id: ME_CARD_ID,
  locale: 'fr',
  translationGroup: GROUP_ID,
  category: 'technical',
  title: 'Dev senior',
  description: 'D',
  iconKey: 'code',
  position: 0,
}
const INPUT = {
  locale: 'fr' as const,
  translationGroup: null,
  category: 'hobby' as const,
  title: 'Musique',
  description: 'D',
  iconKey: null,
}

function createStubRepository(overrides: Partial<AdminAboutMeCardRepository> = {}): AdminAboutMeCardRepository {
  return {
    list: vi.fn(async () => [CARD]),
    create: vi.fn(async () => CARD),
    update: vi.fn(async () => CARD),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminAboutMeCardRepository) {
  let captured: ReturnType<typeof useAdminAboutMeCards> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAdminAboutMeCards()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ADMIN_ABOUT_ME_CARD_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAdminAboutMeCards() did not run during mount')
  }

  return captured
}

describe('useAdminAboutMeCards', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAdminAboutMeCards()
        return () => h('div')
      },
    })
    expect(() => mount(Probe)).toThrow(/AdminAboutMeCardRepository/)
  })

  it('charge la liste au montage sans filtre, ni de locale ni de catégorie', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()

    // Spec 0004, D8 : la page rend les trois tableaux, donc il lui faut la
    // collection entière — trois requêtes filtrées diraient la même chose en
    // trois allers-retours.
    expect(repository.list).toHaveBeenCalledWith()
    expect(composable.cards.value).toEqual([CARD])
    expect(composable.isLoading.value).toBe(false)
  })

  it('hasError passe à true si le chargement échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(composable.hasError.value).toBe(true)
    expect(composable.cards.value).toEqual([])
  })

  it('create() appelle le repository puis recharge la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.create(INPUT)

    expect(repository.create).toHaveBeenCalledWith(INPUT)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it("update() propage errorMessage sans vider la liste chargée", async () => {
    const repository = createStubRepository({
      update: vi.fn(async () => Promise.reject(new AdminAboutError('not-found', 'Introuvable'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    await composable.update(ME_CARD_ID, INPUT)

    expect(composable.errorMessage.value?.reason).toBe('not-found')
    expect(composable.hasError.value).toBe(false)
    expect(composable.cards.value).toEqual([CARD])
  })

  it('remove() appelle le repository puis recharge la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.remove(ME_CARD_ID)

    expect(repository.remove).toHaveBeenCalledWith(ME_CARD_ID)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it('délègue reorder() au repository avec sa catégorie, sans recharger la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.reorder([GROUP_ID], 'technical')

    expect(repository.reorder).toHaveBeenCalledWith([GROUP_ID], 'technical')
    // useOrderDraft recharge lui-même après un enregistrement réussi.
    expect(repository.list).not.toHaveBeenCalled()
  })

  it("laisse remonter l'AdminOrderError telle quelle, sans la convertir", async () => {
    const repository = createStubRepository({
      reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    const error = await composable.reorder([GROUP_ID], 'technical').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('stale-order')
    expect(composable.errorMessage.value).toBeNull()
  })
})
