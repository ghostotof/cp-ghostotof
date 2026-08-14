import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AdminTechnologiesPage from '../../../../src/presentation/pages/admin/AdminTechnologiesPage.vue'
import { ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY } from '../../../../src/application/admin/technologies/useAdminExperienceTechnologies'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminExperienceTechnologyRepository } from '../../../../src/domain/admin/technologies/repositories/AdminExperienceTechnologyRepository'
import type { AdminExperienceTechnology } from '../../../../src/domain/admin/technologies/entities/AdminExperienceTechnology'
import { AdminExperienceTechnologyError } from '../../../../src/domain/admin/technologies/errors/AdminExperienceTechnologyError'

const PHP: AdminExperienceTechnology = { id: 1, name: 'PHP', years: 13.5, iconKey: null, relatedTechnologyName: null }

function createStubRepository(overrides: Partial<AdminExperienceTechnologyRepository> = {}): AdminExperienceTechnologyRepository {
  return {
    list: vi.fn(async () => [PHP]),
    create: vi.fn(async () => PHP),
    update: vi.fn(async () => PHP),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

async function mountPage(repository: AdminExperienceTechnologyRepository = createStubRepository()) {
  const wrapper = mount(AdminTechnologiesPage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY as symbol]: repository },
    },
  })
  await flushPromises()

  return wrapper
}

describe('AdminTechnologiesPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('affiche la liste des technologies chargées', async () => {
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('PHP')
    expect(wrapper.text()).toContain('13.5')
  })

  it("affiche un message si le chargement échoue", async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = await mountPage(repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('affiche un message si la liste est vide', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => []) })
    const wrapper = await mountPage(repository)

    expect(wrapper.text()).toContain('Aucune technologie enregistrée pour le moment.')
  })

  it('crée une technologie via le formulaire puis réinitialise les champs', async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await wrapper.get('#admin-tech-name').setValue('Vue')
    await wrapper.get('#admin-tech-years').setValue('3')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.create).toHaveBeenCalledWith({ name: 'Vue', years: 3, iconKey: null, relatedTechnologyName: null })
    expect((wrapper.get('#admin-tech-name').element as HTMLInputElement).value).toBe('')
  })

  it("préremplit le formulaire à l'édition puis appelle update()", async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    const editButtons = wrapper.findAll('button').filter((button) => 'Modifier' === button.text())
    await editButtons[0]?.trigger('click')

    expect((wrapper.get('#admin-tech-name').element as HTMLInputElement).value).toBe('PHP')

    await wrapper.get('#admin-tech-name').setValue('PHP 8')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.update).toHaveBeenCalledWith(1, { name: 'PHP 8', years: 13.5, iconKey: null, relatedTechnologyName: null })
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

  it('n\'appelle pas remove() si la confirmation est refusée', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    const deleteButton = wrapper.findAll('button').find((button) => 'Supprimer' === button.text())
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(repository.remove).not.toHaveBeenCalled()
  })

  it("affiche un message traduit si la création échoue (nom déjà pris)", async () => {
    const repository = createStubRepository({
      create: vi.fn(async () => Promise.reject(new AdminExperienceTechnologyError('duplicate', 'Une technologie existe déjà avec le nom "PHP".'))),
    })
    const wrapper = await mountPage(repository)

    await wrapper.get('#admin-tech-name').setValue('PHP')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Une technologie porte déjà ce nom.')
  })
})
