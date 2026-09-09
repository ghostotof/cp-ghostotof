import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ContributionsPage from '../../../src/presentation/pages/ContributionsPage.vue'
import { CONTRIBUTION_REPOSITORY } from '../../../src/application/contributions/useContributions'
import type { ContributionRepository } from '../../../src/domain/contributions/repositories/ContributionRepository'
import type { Contribution } from '../../../src/domain/contributions/entities/Contribution'
import { createAppI18n } from '../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../support/axe'

const CONTRIBUTION: Contribution = {
  title: 'Retry de transport, re-prompt de validation : deux mécanismes, un seul mot',
  project: 'symfony/ai',
  reference: 'Issue #1688',
  url: 'https://github.com/symfony/ai/issues/1688',
  summary: 'Deux opérations différentes sous un seul mot.',
  body: 'Premier paragraphe, citant `maxRetries`.\n\nSecond paragraphe.\n\nTroisième paragraphe.',
}

function createStubRepository(overrides: Partial<ContributionRepository> = {}): ContributionRepository {
  return {
    list: vi.fn(async () => [CONTRIBUTION]),
    ...overrides,
  }
}

function mountPage(repository: ContributionRepository = createStubRepository()) {
  // BaseButton rend un RouterLink pour les cibles internes : la page a besoin
  // d'un routeur même si son seul lien est externe.
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })

  return mount(ContributionsPage, {
    global: {
      plugins: [router, createAppI18n()],
      provide: { [CONTRIBUTION_REPOSITORY as symbol]: repository },
    },
  })
}

describe('ContributionsPage', () => {
  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', () => {
    expect(mountPage().find('h1').exists()).toBe(true)
  })

  it('affiche un message de chargement pendant la récupération', () => {
    expect(mountPage().text()).toContain('Chargement des contributions')
  })

  it('affiche le projet, la référence, le titre et le chapeau une fois chargée', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('symfony/ai')
    expect(wrapper.text()).toContain('Issue #1688')
    expect(wrapper.text()).toContain(CONTRIBUTION.title)
    expect(wrapper.text()).toContain(CONTRIBUTION.summary)
  })

  it('découpe le corps en un paragraphe par bloc séparé d\'une ligne vide', async () => {
    const wrapper = mountPage()
    await flushPromises()

    // Ciblé par classe plutôt que par un décompte de <p> : l'article porte
    // aussi la ligne projet·référence et le chapeau, qui n'ont rien à voir
    // avec le découpage du corps.
    const paragraphs = wrapper.findAll('.contribution__paragraph')

    expect(paragraphs).toHaveLength(3)
    expect(paragraphs[2]?.text()).toBe('Troisième paragraphe.')
  })

  it('lie vers la discussion d\'origine, dans un nouvel onglet protégé', async () => {
    const wrapper = mountPage()
    await flushPromises()

    const link = wrapper.get(`a[href="${CONTRIBUTION.url}"]`)
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toBe('noopener noreferrer')
  })

  describe('rendu du corps', () => {
    it('rend les passages entre accents graves en <code>', async () => {
      const wrapper = mountPage()
      await flushPromises()

      const code = wrapper.get('code')
      expect(code.text()).toBe('maxRetries')
      // Les accents graves eux-mêmes ne doivent pas rester dans le texte rendu.
      expect(wrapper.text()).not.toContain('`')
    })

    it('n\'interprète jamais le corps comme du HTML', async () => {
      const repository = createStubRepository({
        list: vi.fn(async () => [
          { ...CONTRIBUTION, body: 'Avant <img src=x onerror="alert(1)"> après.' },
        ]),
      })
      const wrapper = mountPage(repository)
      await flushPromises()

      // Le corps vient d'un champ de saisie du backoffice : l'injecter en
      // v-html échangerait une mise en forme contre une faille XSS sur une
      // page publique. Le balisage doit rester du texte, visible tel quel.
      expect(wrapper.find('img').exists()).toBe(false)
      expect(wrapper.text()).toContain('<img src=x onerror="alert(1)">')
    })
  })

  it('affiche un message d\'erreur générique si la récupération échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = mountPage(repository)
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('article').exists()).toBe(false)
  })

  it('affiche un état vide explicite quand aucune contribution n\'est publiée', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => []) })
    const wrapper = mountPage(repository)
    await flushPromises()

    expect(wrapper.text()).toContain('Aucune contribution publiée')
    expect(wrapper.find('article').exists()).toBe(false)
  })

  /**
   * Audit du DOM rendu, complémentaire du lint d'accessibilité : celui-ci ne
   * voit que le template, axe inspecte ce qui existe une fois rendu.
   *
   * Attention à ce qu'il ne dit PAS : jsdom ne calcule ni mise en page ni
   * couleur, donc le contraste n'est jamais vérifié ici (cf. tests/support/axe).
   */
  it("ne présente aucune violation d'accessibilité détectable", async () => {
    const wrapper = mountPage()
    await flushPromises()

    await expectNoAccessibilityViolation(wrapper)
  })
})
