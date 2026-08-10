import { defineComponent } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AboutPage from '../../../src/presentation/pages/AboutPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { createAppI18n } from '../../../src/presentation/i18n'
import { AUTH_REPOSITORY, useAuth } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../src/domain/auth/entities/AuthenticatedUser'

function createStubAuthRepository(user: AuthenticatedUser | null): AuthRepository {
  return {
    login: vi.fn(async () => user ?? { username: 'jane' }),
    logout: vi.fn(async () => undefined),
    me: vi.fn(async () => user),
  }
}

/**
 * L'état d'authentification est un singleton au niveau du module (cf.
 * application/auth/useAuth.ts) : on le fixe explicitement avant chaque test
 * pour isoler les cas "connecté"/"non connecté" les uns des autres, comme le
 * fait déjà tests/presentation/layout/AppHeader.spec.ts.
 */
async function primeAuthState(user: AuthenticatedUser | null): Promise<void> {
  const repository = createStubAuthRepository(user)
  const Probe = defineComponent({
    setup() {
      return { auth: useAuth() }
    },
    template: '<div />',
  })
  const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
  await wrapper.vm.auth.checkAuth()
  wrapper.unmount()
}

function mountAboutPage() {
  return mount(AboutPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository(),
        [AUTH_REPOSITORY as symbol]: createStubAuthRepository(null),
      },
    },
  })
}

describe('AboutPage', () => {
  it("rend la section « À propos de ce site » avec son titre et ses cartes (locale par défaut)", async () => {
    await primeAuthState(null)
    const repository = new StaticPortfolioContentRepository()
    const about = repository.getAboutContent('fr')
    const wrapper = mountAboutPage()

    expect(wrapper.text()).toContain(about.site.eyebrow)
    for (const card of about.site.cards) {
      expect(wrapper.text()).toContain(card.title)
      expect(wrapper.text()).toContain(card.description)
    }
  })

  it("rend la section « À propos de moi », avec un volet technique et un volet humain", async () => {
    await primeAuthState(null)
    const repository = new StaticPortfolioContentRepository()
    const about = repository.getAboutContent('fr')
    const wrapper = mountAboutPage()

    expect(wrapper.text()).toContain(about.me.eyebrow)
    expect(wrapper.text()).toContain(about.me.technicalSubtitle)
    expect(wrapper.text()).toContain(about.me.personalSubtitle)
    for (const card of [...about.me.technicalCards, ...about.me.personalCards]) {
      expect(wrapper.text()).toContain(card.title)
      expect(wrapper.text()).toContain(card.description)
    }
  })

  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', async () => {
    await primeAuthState(null)
    const wrapper = mountAboutPage()

    expect(wrapper.find('h1').exists()).toBe(true)
  })

  it("les cartes « À propos de ce site » sont des h2, sans saut de niveau après le h1", async () => {
    await primeAuthState(null)
    const repository = new StaticPortfolioContentRepository()
    const about = repository.getAboutContent('fr')
    const wrapper = mountAboutPage()

    const h2Titles = wrapper.findAll('h2').map((h2) => h2.text())
    for (const card of about.site.cards) {
      expect(h2Titles).toContain(card.title)
    }
  })

  it("masque le volet « hobbies » de la section « À propos de moi » pour un visiteur non authentifié", async () => {
    await primeAuthState(null)
    const repository = new StaticPortfolioContentRepository()
    const about = repository.getAboutContent('fr')
    const wrapper = mountAboutPage()

    expect(wrapper.text()).not.toContain(about.me.hobbiesSubtitle)
    for (const card of about.me.hobbiesCards) {
      expect(wrapper.text()).not.toContain(card.title)
    }
  })

  it("affiche le volet « hobbies » de la section « À propos de moi » pour un utilisateur authentifié", async () => {
    await primeAuthState({ username: 'jane' })
    const repository = new StaticPortfolioContentRepository()
    const about = repository.getAboutContent('fr')
    const wrapper = mountAboutPage()

    expect(wrapper.text()).toContain(about.me.hobbiesSubtitle)
    for (const card of about.me.hobbiesCards) {
      expect(wrapper.text()).toContain(card.title)
      expect(wrapper.text()).toContain(card.description)
    }
  })
})
