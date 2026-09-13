import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AdminAnonymousCvPage from '../../../../src/presentation/pages/admin/AdminAnonymousCvPage.vue'
import { ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY } from '../../../../src/application/admin/anonymousCv/useAdminAnonymousCvSections'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminAnonymousCvSectionRepository } from '../../../../src/domain/admin/anonymousCv/repositories/AdminAnonymousCvSectionRepository'
import type { AdminAnonymousCvSection } from '../../../../src/domain/admin/anonymousCv/entities/AdminAnonymousCvSection'
import { AdminAnonymousCvSectionError } from '../../../../src/domain/admin/anonymousCv/errors/AdminAnonymousCvSectionError'
import { expectNoAccessibilityViolation } from '../../../support/axe'

const SECTION: AdminAnonymousCvSection = {
  id: 1, locale: 'fr', title: 'Backend PHP / Symfony', skills: 'Symfony 7', yearsOfExperience: 12, achievements: 'API multi-tenant.', position: 0,
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

async function mountPage(repository: AdminAnonymousCvSectionRepository = createStubRepository()) {
  const wrapper = mount(AdminAnonymousCvPage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY as symbol]: repository },
    },
  })
  await flushPromises()

  return wrapper
}

async function fillForm(wrapper: Awaited<ReturnType<typeof mountPage>>): Promise<void> {
  await wrapper.get('#admin-anonymous-cv-title').setValue('Frontend')
  await wrapper.get('#admin-anonymous-cv-skills').setValue('Vue 3, TypeScript')
  await wrapper.get('#admin-anonymous-cv-years').setValue('4')
  await wrapper.get('#admin-anonymous-cv-achievements').setValue('SPA découplée.')
  await wrapper.get('#admin-anonymous-cv-position').setValue('1')
}

describe('AdminAnonymousCvPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('affiche la liste des sections chargées', async () => {
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Backend PHP / Symfony')
    expect(wrapper.text()).toContain('12')
  })

  it('rappelle la règle éditoriale (ni nom, ni employeur) au-dessus du formulaire', async () => {
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Ni nom, ni employeur, ni client')
  })

  it('affiche un message si le chargement échoue', async () => {
    const wrapper = await mountPage(createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) }))

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('affiche un message si la liste est vide', async () => {
    const wrapper = await mountPage(createStubRepository({ list: vi.fn(async () => []) }))

    expect(wrapper.text()).toContain('Aucune section enregistrée.')
  })

  it('crée une section via le formulaire (années et position en nombres) puis réinitialise les champs', async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await fillForm(wrapper)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.create).toHaveBeenCalledWith({
      locale: 'fr',
      title: 'Frontend',
      skills: 'Vue 3, TypeScript',
      yearsOfExperience: 4,
      achievements: 'SPA découplée.',
      position: 1,
    })
    expect((wrapper.get('#admin-anonymous-cv-title').element as HTMLInputElement).value).toBe('')
  })

  it("préremplit le formulaire à l'édition puis appelle update()", async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    const editButton = wrapper.findAll('button').find((button) => 'Modifier' === button.text())
    await editButton?.trigger('click')

    expect((wrapper.get('#admin-anonymous-cv-title').element as HTMLInputElement).value).toBe('Backend PHP / Symfony')
    expect((wrapper.get('#admin-anonymous-cv-years').element as HTMLInputElement).value).toBe('12')

    await wrapper.get('#admin-anonymous-cv-title').setValue('Backend')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.update).toHaveBeenCalledWith(1, {
      locale: 'fr',
      title: 'Backend',
      skills: 'Symfony 7',
      yearsOfExperience: 12,
      achievements: 'API multi-tenant.',
      position: 0,
    })
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

  it("n'appelle pas remove() si la confirmation est refusée", async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    const deleteButton = wrapper.findAll('button').find((button) => 'Supprimer' === button.text())
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(repository.remove).not.toHaveBeenCalled()
  })

  it('affiche un message traduit si la validation échoue (réalisations vides) et garde le formulaire rempli', async () => {
    const repository = createStubRepository({
      create: vi.fn(async () => Promise.reject(new AdminAnonymousCvSectionError('validation', 'invalid'))),
    })
    const wrapper = await mountPage(repository)

    await fillForm(wrapper)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Certains champs sont invalides.')
    expect((wrapper.get('#admin-anonymous-cv-title').element as HTMLInputElement).value).toBe('Frontend')
  })

  it("ne présente aucune violation d'accessibilité détectable", async () => {
    await expectNoAccessibilityViolation(await mountPage())
  })
})
