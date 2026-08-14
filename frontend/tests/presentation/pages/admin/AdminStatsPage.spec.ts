import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AdminStatsPage from '../../../../src/presentation/pages/admin/AdminStatsPage.vue'
import { ADMIN_STATS_REPOSITORY } from '../../../../src/application/admin/stats/useAdminStats'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminStatsRepository } from '../../../../src/domain/admin/stats/repositories/AdminStatsRepository'
import type { AdminStat } from '../../../../src/domain/admin/stats/entities/AdminStat'
import { AdminStatsError } from '../../../../src/domain/admin/stats/errors/AdminStatsError'

const STAT: AdminStat = { id: 1, locale: 'fr', value: '+50K', label: 'Lignes de code', iconKey: 'code', position: 0 }

function createStubRepository(overrides: Partial<AdminStatsRepository> = {}): AdminStatsRepository {
  return {
    list: vi.fn(async () => [STAT]),
    create: vi.fn(async () => STAT),
    update: vi.fn(async () => STAT),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

async function mountPage(repository: AdminStatsRepository = createStubRepository()) {
  const wrapper = mount(AdminStatsPage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [ADMIN_STATS_REPOSITORY as symbol]: repository },
    },
  })
  await flushPromises()

  return wrapper
}

describe('AdminStatsPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('charge et affiche la liste des statistiques pour la locale par défaut (fr)', async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(wrapper.text()).toContain('+50K')
    expect(wrapper.text()).toContain('Lignes de code')
  })

  it('affiche un message si le chargement échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = await mountPage(repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('affiche un message si la liste est vide', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => []) })
    const wrapper = await mountPage(repository)

    expect(wrapper.text()).toContain('Aucune statistique enregistrée pour cette langue.')
  })

  it('recharge la liste filtrée quand la locale sélectionnée change', async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)
    vi.mocked(repository.list).mockClear()

    await wrapper.get('#admin-stats-locale').setValue('en')
    await flushPromises()

    expect(repository.list).toHaveBeenCalledWith('en')
  })

  it('crée une statistique via le formulaire puis réinitialise les champs', async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await wrapper.get('#admin-stat-value').setValue('10+')
    await wrapper.get('#admin-stat-label').setValue('Technologies')
    await wrapper.get('#admin-stat-icon-key').setValue('box')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.create).toHaveBeenCalledWith({ locale: 'fr', value: '10+', label: 'Technologies', iconKey: 'box', position: 0 })
    expect((wrapper.get('#admin-stat-value').element as HTMLInputElement).value).toBe('')
  })

  it("préremplit le formulaire à l'édition puis appelle update()", async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await wrapper.get('button.btn-outline-light').trigger('click')

    expect((wrapper.get('#admin-stat-value').element as HTMLInputElement).value).toBe('+50K')

    await wrapper.get('#admin-stat-value').setValue('+60K')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.update).toHaveBeenCalledWith(1, { locale: 'fr', value: '+60K', label: 'Lignes de code', iconKey: 'code', position: 0 })
  })

  it('supprime après confirmation', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    const deleteButton = wrapper.findAll('button').find((button) => 'Supprimer' === button.text())
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(repository.remove).toHaveBeenCalledWith(1)
  })

  it("affiche un message traduit si une mutation échoue", async () => {
    const repository = createStubRepository({
      create: vi.fn(async () => Promise.reject(new AdminStatsError('validation', 'Invalide'))),
    })
    const wrapper = await mountPage(repository)

    await wrapper.get('#admin-stat-value').setValue('')
    await wrapper.get('#admin-stat-label').setValue('x')
    await wrapper.get('#admin-stat-icon-key').setValue('x')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Le formulaire contient des erreurs. Vérifiez les champs.')
  })
})
