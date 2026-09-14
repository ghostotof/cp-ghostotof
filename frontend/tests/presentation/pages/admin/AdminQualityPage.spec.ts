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
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
import { expectNoAccessibilityViolation } from '../../../support/axe'

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

function createTranslationRepository(fields: Record<string, string>, overrides: Partial<AdminTranslationRepository> = {}): AdminTranslationRepository {
  return {
    translate: vi.fn(async () => ({ sourceLocale: 'fr' as const, targetLocale: 'en' as const, fields })),
    ...overrides,
  }
}

async function mountPage(
  principleRepository: AdminQualityPrincipleRepository = createStubPrincipleRepository(),
  traitRepository: AdminQualityTraitRepository = createStubTraitRepository(),
  translationRepository: AdminTranslationRepository = createTranslationRepository({ title: 'DDD', description: 'DDD description' }),
) {
  const wrapper = mount(AdminQualityPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [ADMIN_QUALITY_PRINCIPLE_REPOSITORY as symbol]: principleRepository,
        [ADMIN_QUALITY_TRAIT_REPOSITORY as symbol]: traitRepository,
        [ADMIN_TRANSLATION_REPOSITORY as symbol]: translationRepository,
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

    // Par libellé et non par classe : le bouton de traduction partage
    // `btn-outline-light` et précède désormais « Modifier » dans le DOM.
    const editButton = wrapper.findAll('button').find((button) => 'Modifier' === button.text())
    await editButton?.trigger('click')

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

  describe('assistant de traduction', () => {
    type Wrapper = Awaited<ReturnType<typeof mountPage>>

    function translateButtons(wrapper: Wrapper) {
      return wrapper.findAll('button').filter((candidate) => candidate.text().includes('Proposer la version'))
    }

    async function editFirst(wrapper: Wrapper, formIndex: number): Promise<void> {
      const editButtons = wrapper.findAll('button').filter((button) => 'Modifier' === button.text())
      await editButtons[formIndex]?.trigger('click')
    }

    it('propose un bouton par formulaire, principes et traits', async () => {
      const wrapper = await mountPage()

      expect(translateButtons(wrapper)).toHaveLength(2)
    })

    it('principe : envoie titre et description, jamais la clé d\'icône ni la position, depuis la locale de la page', async () => {
      const translation = createTranslationRepository({ title: 'DDD', description: 'DDD description' })
      const wrapper = await mountPage(createStubPrincipleRepository(), createStubTraitRepository(), translation)
      await editFirst(wrapper, 0)

      await translateButtons(wrapper)[0]?.trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('fr', 'en', { title: 'DDD', description: 'Description DDD' })
    })

    it('principe : bascule la page sur la locale cible (listes rechargées) et le formulaire en création, icône et position conservées', async () => {
      const principleRepository = createStubPrincipleRepository()
      const traitRepository = createStubTraitRepository()
      const wrapper = await mountPage(principleRepository, traitRepository)
      await editFirst(wrapper, 0)
      vi.mocked(principleRepository.list).mockClear()
      vi.mocked(traitRepository.list).mockClear()

      await translateButtons(wrapper)[0]?.trigger('click')
      await flushPromises()

      expect((wrapper.get('#admin-quality-locale').element as HTMLSelectElement).value).toBe('en')
      expect(principleRepository.list).toHaveBeenCalledWith('en')
      expect(traitRepository.list).toHaveBeenCalledWith('en')
      expect(wrapper.findAll('h2')[0]?.text()).toBe('Ajouter un principe')
      expect((wrapper.get('#admin-quality-principle-title').element as HTMLInputElement).value).toBe('DDD')
      expect((wrapper.get('#admin-quality-principle-description').element as HTMLTextAreaElement).value).toBe('DDD description')
      expect((wrapper.get('#admin-quality-principle-icon-key').element as HTMLInputElement).value).toBe('boxes')
      expect((wrapper.get('#admin-quality-principle-position').element as HTMLInputElement).value).toBe('0')
      expect(wrapper.get('[role="status"]').text()).toContain('Brouillon généré par IA')
      expect(principleRepository.create).not.toHaveBeenCalled()
      expect(principleRepository.update).not.toHaveBeenCalled()
    })

    it('principe : Enregistrer crée alors l\'entrée dans la locale cible', async () => {
      const principleRepository = createStubPrincipleRepository()
      const wrapper = await mountPage(principleRepository)
      await editFirst(wrapper, 0)
      await translateButtons(wrapper)[0]?.trigger('click')
      await flushPromises()

      await wrapper.findAll('form')[0]?.trigger('submit.prevent')
      await flushPromises()

      expect(principleRepository.update).not.toHaveBeenCalled()
      expect(principleRepository.create).toHaveBeenCalledWith({ locale: 'en', title: 'DDD', description: 'DDD description', iconKey: 'boxes', position: 0 })
    })

    it('trait : envoie le libellé et bascule de la même façon', async () => {
      const translation = createTranslationRepository({ label: 'Tested' })
      const traitRepository = createStubTraitRepository()
      const wrapper = await mountPage(createStubPrincipleRepository(), traitRepository, translation)
      await editFirst(wrapper, 1)

      await translateButtons(wrapper)[1]?.trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('fr', 'en', { label: 'Testé' })
      expect((wrapper.get('#admin-quality-locale').element as HTMLSelectElement).value).toBe('en')
      expect(wrapper.findAll('h2')[1]?.text()).toBe('Ajouter un trait')
      expect((wrapper.get('#admin-quality-trait-label').element as HTMLInputElement).value).toBe('Tested')
      expect(traitRepository.create).not.toHaveBeenCalled()
    })

    it('en échec, la page reste sur sa locale et le formulaire intact', async () => {
      const translation = createTranslationRepository({}, {
        translate: vi.fn(async () => { throw new AdminTranslationError('unavailable', 'Indisponible.') }),
      })
      const wrapper = await mountPage(createStubPrincipleRepository(), createStubTraitRepository(), translation)
      await editFirst(wrapper, 0)

      await translateButtons(wrapper)[0]?.trigger('click')
      await flushPromises()

      expect(wrapper.get('[role="alert"]').text()).toContain('indisponible')
      expect((wrapper.get('#admin-quality-locale').element as HTMLSelectElement).value).toBe('fr')
      expect(wrapper.findAll('h2')[0]?.text()).toBe('Modifier le principe')
    })

    it('ne présente aucune violation d\'accessibilité avec un brouillon rendu', async () => {
      const wrapper = await mountPage()
      await editFirst(wrapper, 0)
      await translateButtons(wrapper)[0]?.trigger('click')
      await flushPromises()

      await expectNoAccessibilityViolation(wrapper)
    })
  })
})
