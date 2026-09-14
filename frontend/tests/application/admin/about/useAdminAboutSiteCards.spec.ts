import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import {
  ADMIN_ABOUT_SITE_CARD_REPOSITORY,
  useAdminAboutSiteCards,
} from '../../../../src/application/admin/about/useAdminAboutSiteCards'
import type { AdminAboutSiteCardRepository } from '../../../../src/domain/admin/about/repositories/AdminAboutSiteCardRepository'
import type { AdminAboutSiteCard } from '../../../../src/domain/admin/about/entities/AdminAboutSiteCard'
import { AdminAboutError } from '../../../../src/domain/admin/about/errors/AdminAboutError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const SITE_CARD_ID = '019968a0-0000-7000-8000-000000000002'
const GROUP_ID = '019968b0-0000-7000-8000-000000000002'
const CARD: AdminAboutSiteCard = {
  id: SITE_CARD_ID,
  locale: 'fr',
  translationGroup: GROUP_ID,
  title: 'Architecture',
  description: 'D',
  iconKey: 'layers',
  position: 0,
}
const INPUT = { locale: 'fr' as const, translationGroup: null, title: 'Stack', description: 'D', iconKey: 'server' }

function createStubRepository(overrides: Partial<AdminAboutSiteCardRepository> = {}): AdminAboutSiteCardRepository {
  return {
    list: vi.fn(async () => [CARD]),
    create: vi.fn(async () => CARD),
    update: vi.fn(async () => CARD),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminAboutSiteCardRepository) {
  let captured: ReturnType<typeof useAdminAboutSiteCards> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAdminAboutSiteCards()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ADMIN_ABOUT_SITE_CARD_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAdminAboutSiteCards() did not run during mount')
  }

  return captured
}

describe('useAdminAboutSiteCards', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAdminAboutSiteCards()
        return () => h('div')
      },
    })
    expect(() => mount(Probe)).toThrow(/AdminAboutSiteCardRepository/)
  })

  it('charge la liste au montage, toutes langues confondues et sans argument de locale', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()

    // Spec 0004, D8 : plus de filtre de locale — le tableau les affiche toutes.
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

    await composable.update(SITE_CARD_ID, INPUT)

    expect(composable.errorMessage.value?.reason).toBe('not-found')
    expect(composable.hasError.value).toBe(false)
    expect(composable.cards.value).toEqual([CARD])
  })

  it('remove() appelle le repository puis recharge la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.remove(SITE_CARD_ID)

    expect(repository.remove).toHaveBeenCalledWith(SITE_CARD_ID)
    expect(repository.list).toHaveBeenCalledOnce()
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
})
