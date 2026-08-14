import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ADMIN_ABOUT_ME_CARD_REPOSITORY, useAdminAboutMeCards } from '../../../../src/application/admin/about/useAdminAboutMeCards'
import type { AdminAboutMeCardRepository } from '../../../../src/domain/admin/about/repositories/AdminAboutMeCardRepository'
import type { AdminAboutMeCard } from '../../../../src/domain/admin/about/entities/AdminAboutMeCard'
import { AdminAboutError } from '../../../../src/domain/admin/about/errors/AdminAboutError'

const CARD: AdminAboutMeCard = { id: 1, locale: 'fr', category: 'technical', title: 'Dev senior', description: 'D', iconKey: 'code', position: 0 }

function createStubRepository(overrides: Partial<AdminAboutMeCardRepository> = {}): AdminAboutMeCardRepository {
  return {
    list: vi.fn(async () => [CARD]),
    create: vi.fn(async () => CARD),
    update: vi.fn(async () => CARD),
    remove: vi.fn(async () => undefined),
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

  it('load(locale) charge la liste (toutes catégories) filtrée par locale', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)

    await composable.load('fr')

    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(composable.cards.value).toEqual([CARD])
  })

  it('hasError passe à true si le chargement échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const composable = mountWithComposable(repository)

    await composable.load('fr')

    expect(composable.hasError.value).toBe(true)
  })

  it('create() appelle le repository puis recharge la locale courante', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load('fr')
    vi.mocked(repository.list).mockClear()

    await composable.create({ locale: 'fr', category: 'hobby', title: 'Musique', description: 'D', iconKey: null, position: 0 })

    expect(repository.create).toHaveBeenCalledWith({ locale: 'fr', category: 'hobby', title: 'Musique', description: 'D', iconKey: null, position: 0 })
    expect(repository.list).toHaveBeenCalledWith('fr')
  })

  it('update() propage errorMessage sans planter en cas d\'échec', async () => {
    const repository = createStubRepository({ update: vi.fn(async () => Promise.reject(new AdminAboutError('not-found', 'Introuvable'))) })
    const composable = mountWithComposable(repository)
    await composable.load('fr')

    await composable.update(1, { locale: 'fr', category: 'technical', title: 'x', description: 'x', iconKey: null, position: 0 })

    expect(composable.errorMessage.value?.reason).toBe('not-found')
  })

  it('remove() appelle le repository puis recharge la locale courante', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load('fr')
    vi.mocked(repository.list).mockClear()

    await composable.remove(1)

    expect(repository.remove).toHaveBeenCalledWith(1)
    expect(repository.list).toHaveBeenCalledWith('fr')
  })
})
