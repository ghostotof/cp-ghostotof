import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AdminQualityPage from '../../../../src/presentation/pages/admin/AdminQualityPage.vue'
import { ADMIN_QUALITY_PRINCIPLE_REPOSITORY } from '../../../../src/application/admin/quality/useAdminQualityPrinciples'
import { ADMIN_QUALITY_TRAIT_REPOSITORY } from '../../../../src/application/admin/quality/useAdminQualityTraits'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminQualityPrincipleRepository } from '../../../../src/domain/admin/quality/repositories/AdminQualityPrincipleRepository'
import type { AdminQualityTraitRepository } from '../../../../src/domain/admin/quality/repositories/AdminQualityTraitRepository'
import type { AdminQualityPrinciple } from '../../../../src/domain/admin/quality/entities/AdminQualityPrinciple'
import type { AdminQualityTrait } from '../../../../src/domain/admin/quality/entities/AdminQualityTrait'
import { AdminQualityError } from '../../../../src/domain/admin/quality/errors/AdminQualityError'

const PRINCIPLE: AdminQualityPrinciple = { id: 1, locale: 'fr', title: 'DDD', description: 'Description DDD', iconKey: 'boxes', position: 0 }
const TRAIT: AdminQualityTrait = { id: 1, locale: 'fr', label: 'Testé', position: 0 }

function createStubPrincipleRepository(overrides: Partial<AdminQualityPrincipleRepository> = {}): AdminQualityPrincipleRepository {
  return {
    list: vi.fn(async () => [PRINCIPLE]),
    create: vi.fn(async () => PRINCIPLE),
    update: vi.fn(async () => PRINCIPLE),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

function createStubTraitRepository(overrides: Partial<AdminQualityTraitRepository> = {}): AdminQualityTraitRepository {
  return {
    list: vi.fn(async () => [TRAIT]),
    create: vi.fn(async () => TRAIT),
    update: vi.fn(async () => TRAIT),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

async function mountPage(
  principleRepository: AdminQualityPrincipleRepository = createStubPrincipleRepository(),
  traitRepository: AdminQualityTraitRepository = createStubTraitRepository(),
) {
  const wrapper = mount(AdminQualityPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [ADMIN_QUALITY_PRINCIPLE_REPOSITORY as symbol]: principleRepository,
        [ADMIN_QUALITY_TRAIT_REPOSITORY as symbol]: traitRepository,
      },
    },
  })
  await flushPromises()

  return wrapper
}

describe('AdminQualityPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('charge et affiche les principes et les traits pour la locale par défaut (fr)', async () => {
    const principleRepository = createStubPrincipleRepository()
    const traitRepository = createStubTraitRepository()
    const wrapper = await mountPage(principleRepository, traitRepository)

    expect(principleRepository.list).toHaveBeenCalledWith('fr')
    expect(traitRepository.list).toHaveBeenCalledWith('fr')
    expect(wrapper.text()).toContain('DDD')
    expect(wrapper.text()).toContain('Testé')
  })

  it('affiche un message si le chargement des principes échoue', async () => {
    const principleRepository = createStubPrincipleRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = await mountPage(principleRepository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('affiche un message si les listes sont vides', async () => {
    const principleRepository = createStubPrincipleRepository({ list: vi.fn(async () => []) })
    const traitRepository = createStubTraitRepository({ list: vi.fn(async () => []) })
    const wrapper = await mountPage(principleRepository, traitRepository)

    expect(wrapper.text()).toContain('Aucun principe enregistré pour cette langue.')
    expect(wrapper.text()).toContain('Aucun trait enregistré pour cette langue.')
  })

  it('recharge les deux listes filtrées quand la locale sélectionnée change', async () => {
    const principleRepository = createStubPrincipleRepository()
    const traitRepository = createStubTraitRepository()
    const wrapper = await mountPage(principleRepository, traitRepository)
    vi.mocked(principleRepository.list).mockClear()
    vi.mocked(traitRepository.list).mockClear()

    await wrapper.get('#admin-quality-locale').setValue('en')
    await flushPromises()

    expect(principleRepository.list).toHaveBeenCalledWith('en')
    expect(traitRepository.list).toHaveBeenCalledWith('en')
  })

  it('crée un principe via son formulaire puis réinitialise les champs', async () => {
    const principleRepository = createStubPrincipleRepository()
    const wrapper = await mountPage(principleRepository)

    await wrapper.get('#admin-quality-principle-title').setValue('SOLID')
    await wrapper.get('#admin-quality-principle-description').setValue('Description SOLID')
    await wrapper.get('#admin-quality-principle-icon-key').setValue('columns-3')
    await wrapper.findAll('form')[0]?.trigger('submit.prevent')
    await flushPromises()

    expect(principleRepository.create).toHaveBeenCalledWith({
      locale: 'fr',
      title: 'SOLID',
      description: 'Description SOLID',
      iconKey: 'columns-3',
      position: 0,
    })
    expect((wrapper.get('#admin-quality-principle-title').element as HTMLInputElement).value).toBe('')
  })

  it("préremplit le formulaire de principe à l'édition puis appelle update()", async () => {
    const principleRepository = createStubPrincipleRepository()
    const wrapper = await mountPage(principleRepository)

    await wrapper.get('button.btn-outline-light').trigger('click')

    expect((wrapper.get('#admin-quality-principle-title').element as HTMLInputElement).value).toBe('DDD')

    await wrapper.get('#admin-quality-principle-title').setValue('DDD (mis à jour)')
    await wrapper.findAll('form')[0]?.trigger('submit.prevent')
    await flushPromises()

    expect(principleRepository.update).toHaveBeenCalledWith(1, {
      locale: 'fr',
      title: 'DDD (mis à jour)',
      description: 'Description DDD',
      iconKey: 'boxes',
      position: 0,
    })
  })

  it('crée un trait via son formulaire puis réinitialise les champs', async () => {
    const traitRepository = createStubTraitRepository()
    const wrapper = await mountPage(undefined, traitRepository)

    await wrapper.get('#admin-quality-trait-label').setValue('Documenté')
    await wrapper.findAll('form')[1]?.trigger('submit.prevent')
    await flushPromises()

    expect(traitRepository.create).toHaveBeenCalledWith({ locale: 'fr', label: 'Documenté', position: 0 })
    expect((wrapper.get('#admin-quality-trait-label').element as HTMLInputElement).value).toBe('')
  })

  it('supprime un principe après confirmation', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const principleRepository = createStubPrincipleRepository()
    const wrapper = await mountPage(principleRepository)

    const deleteButton = wrapper.findAll('button').find((button) => 'Supprimer' === button.text())
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(principleRepository.remove).toHaveBeenCalledWith(1)
  })

  it("affiche un message traduit si une mutation de principe échoue", async () => {
    const principleRepository = createStubPrincipleRepository({
      create: vi.fn(async () => Promise.reject(new AdminQualityError('validation', 'Invalide'))),
    })
    const wrapper = await mountPage(principleRepository)

    await wrapper.get('#admin-quality-principle-title').setValue('')
    await wrapper.get('#admin-quality-principle-description').setValue('x')
    await wrapper.get('#admin-quality-principle-icon-key').setValue('x')
    await wrapper.findAll('form')[0]?.trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Le formulaire contient des erreurs. Vérifiez les champs.')
  })
})
