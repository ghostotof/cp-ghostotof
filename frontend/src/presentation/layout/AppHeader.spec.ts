import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent } from 'vue'
import AppHeader from './AppHeader.vue'
import { createAppI18n } from '../i18n'
import { AUTH_REPOSITORY, useAuth } from '../../application/auth/useAuth'
import type { AuthRepository } from '../../domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../domain/auth/entities/AuthenticatedUser'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import type { NavigationLink } from '../../domain/portfolio/entities/NavigationLink'

const siteIdentity: SiteIdentity = { brandName: 'CP-Ghostotof' }

const navigationLinks: readonly NavigationLink[] = [
  { label: 'Accueil', to: '/fr', isEnabled: true },
  { label: 'À propos', to: '/fr/about', isEnabled: true },
  { label: 'Expériences', to: '/fr#experiences', isEnabled: false },
]

const StubPage = { template: '<div />' }

function createStubAuthRepository(user: AuthenticatedUser | null): AuthRepository {
  return {
    login: vi.fn(async () => user ?? { username: 'jane' }),
    logout: vi.fn(async () => undefined),
    me: vi.fn(async () => user),
  }
}

/**
 * L'état d'authentification est un singleton au niveau du module (partagé
 * entre AppHeader et LoginPage) : on le fixe explicitement avant chaque test
 * pour isoler les cas "connecté"/"non connecté" les uns des autres.
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

/**
 * AppHeader dépend de Vue Router (RouterLink + route courante pour l'état actif) et
 * de vue-i18n (locale courante, aria-labels traduits) : on lui fournit de vraies
 * instances plutôt que des stubs, pour vérifier le comportement réel.
 */
async function mountHeader(initialPath = '/fr', authRepository?: AuthRepository) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: StubPage },
      { path: '/:locale(fr|en)/about', name: 'about', component: StubPage },
      { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
    ],
  })
  await router.push(initialPath)
  await router.isReady()

  const wrapper = mount(AppHeader, {
    props: { siteIdentity, navigationLinks },
    global: {
      plugins: [router, createAppI18n()],
      provide: { [AUTH_REPOSITORY as symbol]: authRepository ?? createStubAuthRepository(null) },
    },
  })
  await wrapper.vm.$nextTick()

  return wrapper
}

describe('AppHeader', () => {
  it('affiche le nom de marque', async () => {
    await primeAuthState(null)
    const wrapper = await mountHeader()

    expect(wrapper.text()).toContain('CP-Ghostotof')
  })

  it('non authentifié : affiche un lien de connexion à la place du CV', async () => {
    await primeAuthState(null)
    const wrapper = await mountHeader()

    expect(wrapper.get('a[href="/fr/login"]').text()).toBe('Connexion')
    expect(wrapper.text()).not.toContain('Déconnexion')
  })

  it("authentifié : affiche le bouton CV (lien vide pour le moment) et un bouton de déconnexion, plus de lien de connexion", async () => {
    await primeAuthState({ username: 'jane' })
    const wrapper = await mountHeader()

    expect(wrapper.find('a[href="/fr/login"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Télécharger mon CV')

    const logoutButton = wrapper.findAll('button').find((button) => 'Déconnexion' === button.text())
    expect(logoutButton).toBeDefined()
  })

  it('rend un lien navigable (RouterLink) pour chaque lien de navigation actif', async () => {
    await primeAuthState(null)
    const wrapper = await mountHeader()

    const activeLink = wrapper.get('a[href="/fr/about"]')
    expect(activeLink.text()).toBe('À propos')
    expect(activeLink.attributes('aria-disabled')).toBe('false')
  })

  it('désactive les liens de navigation non activés (pas de href, aria-disabled)', async () => {
    await primeAuthState(null)
    const wrapper = await mountHeader()

    const disabledLinks = wrapper.findAll('.nav-link-portfolio--disabled')
    const experiencesLink = disabledLinks.find((link) => link.text() === 'Expériences')

    expect(experiencesLink).toBeDefined()
    expect(experiencesLink?.attributes('href')).toBeUndefined()
    expect(experiencesLink?.attributes('aria-disabled')).toBe('true')
  })

  it('marque le lien Accueil comme actif sur la page d\'accueil', async () => {
    await primeAuthState(null)
    const wrapper = await mountHeader('/fr')

    const homeLink = wrapper.get('nav a[href="/fr"]')
    expect(homeLink.classes()).toContain('nav-link-portfolio--active')
  })

  it('marque le lien À propos comme actif sur la page /fr/about', async () => {
    await primeAuthState(null)
    const wrapper = await mountHeader('/fr/about')

    const aboutLink = wrapper.get('nav a[href="/fr/about"]')
    expect(aboutLink.classes()).toContain('nav-link-portfolio--active')

    const homeLink = wrapper.get('nav a[href="/fr"]')
    expect(homeLink.classes()).not.toContain('nav-link-portfolio--active')
  })

  it("le menu mobile est fermé par défaut puis s'ouvre au clic sur le bouton menu", async () => {
    await primeAuthState(null)
    const wrapper = await mountHeader()

    expect(wrapper.find('#mobile-nav').exists()).toBe(false)

    await wrapper.get('button[aria-controls="mobile-nav"]').trigger('click')

    expect(wrapper.find('#mobile-nav').exists()).toBe(true)
  })

  it('le menu mobile se referme après un clic sur un lien', async () => {
    await primeAuthState(null)
    const wrapper = await mountHeader()

    await wrapper.get('button[aria-controls="mobile-nav"]').trigger('click')
    expect(wrapper.find('#mobile-nav').exists()).toBe(true)

    await wrapper.get('#mobile-nav a[href="/fr/about"]').trigger('click')

    expect(wrapper.find('#mobile-nav').exists()).toBe(false)
  })

  it('rend un sélecteur de langue avec FR et EN', async () => {
    await primeAuthState(null)
    const wrapper = await mountHeader()

    expect(wrapper.text()).toContain('FR')
    expect(wrapper.text()).toContain('EN')
  })
})
