import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AboutPage from '../../../src/presentation/pages/AboutPage.vue'
import { ABOUT_CONTENT_REPOSITORY } from '../../../src/application/about/useAboutContent'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { AboutContentRepository } from '../../../src/domain/about/repositories/AboutContentRepository'
import type { AboutContent } from '../../../src/domain/portfolio/entities/AboutContent'

const STUB_ABOUT_CONTENT: AboutContent = {
  site: {
    eyebrow: 'À propos de ce site',
    cards: [
      { title: 'Architecture', description: 'Description architecture', iconKey: 'layers' },
      { title: 'Stack technique', description: 'Description stack', iconKey: 'server' },
    ],
  },
  me: {
    eyebrow: 'À propos de moi',
    technicalSubtitle: 'Techniquement',
    technicalCards: [{ title: 'Développeur senior', description: 'Description technique', iconKey: 'code' }],
    personalSubtitle: 'Humainement',
    personalCards: [{ title: 'Curieux', description: 'Description personnelle', iconKey: 'lightbulb' }],
    hobbiesSubtitle: 'En dehors du travail',
    hobbiesCards: [{ title: 'Musique', description: 'Description hobby', iconKey: 'guitar' }],
  },
}

function createStubAboutContentRepository(overrides: Partial<AboutContentRepository> = {}): AboutContentRepository {
  return {
    get: vi.fn(async () => STUB_ABOUT_CONTENT),
    ...overrides,
  }
}

/**
 * La page ne dépend plus de l'état d'authentification (audit C3 : filtrage
 * écarté, tout le contenu « À propos » est public) — aucun AuthRepository à
 * fournir, contrairement aux pages qui gatent réellement sur `useAuth()`.
 */
async function mountAboutPage(aboutContentRepository: AboutContentRepository = createStubAboutContentRepository()) {
  const wrapper = mount(AboutPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [ABOUT_CONTENT_REPOSITORY as symbol]: aboutContentRepository,
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('AboutPage', () => {
  it("rend la section « À propos de ce site » avec son titre et ses cartes (locale par défaut)", async () => {
    const wrapper = await mountAboutPage()

    expect(wrapper.text()).toContain(STUB_ABOUT_CONTENT.site.eyebrow)
    for (const card of STUB_ABOUT_CONTENT.site.cards) {
      expect(wrapper.text()).toContain(card.title)
      expect(wrapper.text()).toContain(card.description)
    }
  })

  it("rend la section « À propos de moi », avec un volet technique et un volet humain", async () => {
    const wrapper = await mountAboutPage()

    expect(wrapper.text()).toContain(STUB_ABOUT_CONTENT.me.eyebrow)
    expect(wrapper.text()).toContain(STUB_ABOUT_CONTENT.me.technicalSubtitle)
    expect(wrapper.text()).toContain(STUB_ABOUT_CONTENT.me.personalSubtitle)
    for (const card of [...STUB_ABOUT_CONTENT.me.technicalCards, ...STUB_ABOUT_CONTENT.me.personalCards]) {
      expect(wrapper.text()).toContain(card.title)
      expect(wrapper.text()).toContain(card.description)
    }
  })

  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', async () => {
    const wrapper = await mountAboutPage()

    expect(wrapper.find('h1').exists()).toBe(true)
  })

  it("les cartes « À propos de ce site » sont des h2, sans saut de niveau après le h1", async () => {
    const wrapper = await mountAboutPage()

    const h2Titles = wrapper.findAll('h2').map((h2) => h2.text())
    for (const card of STUB_ABOUT_CONTENT.site.cards) {
      expect(h2Titles).toContain(card.title)
    }
  })

  /**
   * Régression : le volet « En dehors du travail » était auparavant masqué aux
   * visiteurs non authentifiés. Il est désormais public comme le reste de la
   * page — aucun montage authentifié n'est nécessaire pour le voir.
   */
  it("affiche le volet « hobbies » de la section « À propos de moi » sans authentification", async () => {
    const wrapper = await mountAboutPage()

    expect(wrapper.text()).toContain(STUB_ABOUT_CONTENT.me.hobbiesSubtitle)
    for (const card of STUB_ABOUT_CONTENT.me.hobbiesCards) {
      expect(wrapper.text()).toContain(card.title)
      expect(wrapper.text()).toContain(card.description)
    }
  })

  it('affiche un message de chargement pendant la récupération du contenu', () => {
    const wrapper = mount(AboutPage, {
      global: {
        plugins: [createAppI18n()],
        provide: {
          [ABOUT_CONTENT_REPOSITORY as symbol]: createStubAboutContentRepository(),
        },
      },
    })

    expect(wrapper.find('h1').exists()).toBe(false)
    expect(wrapper.text()).toContain('Chargement')
  })

  it('affiche un message d\'erreur générique si la récupération du contenu échoue', async () => {
    const repository = createStubAboutContentRepository({ get: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = await mountAboutPage(repository)

    expect(wrapper.find('h1').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
})
