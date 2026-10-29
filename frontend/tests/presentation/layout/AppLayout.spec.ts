import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { defineComponent, h } from 'vue'
import AppLayout from '../../../src/presentation/layout/AppLayout.vue'
import LandingPage from '../../../src/presentation/pages/LandingPage.vue'
import AboutPage from '../../../src/presentation/pages/AboutPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { AUTH_REPOSITORY, authState, markSessionExpired, useAuth } from '../../../src/application/auth/useAuth'
import { hasRole } from '../../../src/domain/auth/services/hasRole'
import { ROLE_SUPER } from '../../../src/domain/auth/entities/Role'
import SessionEndedBanner from '../../../src/presentation/ui/admin/SessionEndedBanner.vue'
import { signInAgain } from '../../support/signInAgain'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import { CV_REPOSITORY } from '../../../src/application/cv/useCvDownload'
import type { CvRepository } from '../../../src/domain/cv/repositories/CvRepository'
import { ABOUT_CONTENT_REPOSITORY } from '../../../src/application/about/useAboutContent'
import type { AboutContentRepository } from '../../../src/domain/about/repositories/AboutContentRepository'
import { QUALITY_CONTENT_REPOSITORY } from '../../../src/application/quality/useQualityContent'
import type { QualityContentRepository } from '../../../src/domain/quality/repositories/QualityContentRepository'
import { createAppI18n } from '../../../src/presentation/i18n'
import { BASE_ACCESS_REPOSITORY } from '../../../src/application/baseAccess/useBaseAccess'
import { sessionFor } from '../../support/authSession'
import { notifyAccountSwitched } from '../../../src/application/auth/sessionEvents'

const STUB_ABOUT_CONTENT = {
  site: { eyebrow: 'À propos de ce site (stub)', cards: [] },
  me: {
    eyebrow: 'À propos de moi',
    technicalSubtitle: '',
    technicalCards: [],
    personalSubtitle: '',
    personalCards: [],
    hobbiesSubtitle: '',
    hobbiesCards: [],
  },
}

function createStubAuthRepository(): AuthRepository {
  return {
    login: async () => ({ username: 'jane', roles: ['ROLE_USER'] }),
    logout: async () => undefined,
    hasWriteToken: () => true,
    me: async () => sessionFor(null),
  }
}

function createStubCvRepository(): CvRepository {
  return {
    download: async () => ({ blob: new Blob(['%PDF-1.4'], { type: 'application/pdf' }), filename: 'cv.pdf' }),
  }
}

function createStubAboutContentRepository(): AboutContentRepository {
  return { get: vi.fn(async () => STUB_ABOUT_CONTENT) }
}

function createStubQualityContentRepository(): QualityContentRepository {
  return { get: vi.fn(async () => ({ principles: [], traits: [] })) }
}

// Une page d'administration réduite à ce qui compte ici : le vrai bandeau, que `AdminLayout` rend une fois.
const AdminStub = defineComponent({ setup: () => () => h('div', [h(SessionEndedBanner)]) })
const PlainStub = defineComponent({ setup: () => () => h('div') })

async function mountLayout(initialPath = '/fr', authRepository: AuthRepository = createStubAuthRepository()) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: LandingPage },
      { path: '/:locale(fr|en)/about', name: 'about', component: AboutPage },
      { path: '/:locale(fr|en)/admin/case-studies', name: 'admin-case-studies', component: AdminStub },
      { path: '/:locale(fr|en)/mcp-connections', name: 'mcp-connections', component: PlainStub },
      { path: '/:locale(fr|en)/login', name: 'login', component: PlainStub },
    ],
  })
  await router.push(initialPath)
  await router.isReady()

  return mountLayoutOn(router, authRepository)
}

function mountLayoutOn(router: Router, authRepository: AuthRepository) {
  return mount(AppLayout, {
    global: {
      plugins: [router, createAppI18n()],
      provide: {
        [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository(),
        [AUTH_REPOSITORY as symbol]: authRepository,
        [CV_REPOSITORY as symbol]: createStubCvRepository(),
        [BASE_ACCESS_REPOSITORY as symbol]: { grant: async () => undefined },
        [ABOUT_CONTENT_REPOSITORY as symbol]: createStubAboutContentRepository(),
        [QUALITY_CONTENT_REPOSITORY as symbol]: createStubQualityContentRepository(),
      },
    },
  })
}

describe('AppLayout', () => {
  it("affiche l'en-tête (marque, navigation) autour de la page routée", async () => {
    const repository = new StaticPortfolioContentRepository()
    const wrapper = await mountLayout('/fr')

    expect(wrapper.text()).toContain(repository.getSiteIdentity().brandName)
    expect(wrapper.find('main').exists()).toBe(true)
  })

  it('expose un lien d\'évitement ciblant le contenu principal focusable', async () => {
    const wrapper = await mountLayout('/fr')

    const skipLink = wrapper.get('a.skip-link')
    expect(skipLink.attributes('href')).toBe('#main-content')

    const main = wrapper.get('main')
    expect(main.attributes('id')).toBe('main-content')
    expect(main.attributes('tabindex')).toBe('-1')
  })

  it("rend la landing page sur '/fr' et la page À propos sur '/fr/about'", async () => {
    const homeWrapper = await mountLayout('/fr')
    expect(homeWrapper.find('#hero').exists()).toBe(true)

    const aboutWrapper = await mountLayout('/fr/about')
    await flushPromises()
    expect(aboutWrapper.find('#hero').exists()).toBe(false)
    expect(aboutWrapper.text()).toContain(STUB_ABOUT_CONTENT.site.eyebrow)
  })

  it("rend le bandeau d'information cookies après le pied de page, sans passer devant le lien d'évitement", async () => {
    localStorage.clear()
    const wrapper = await mountLayout()

    const notice = wrapper.get('section.cookie-notice')
    const footer = wrapper.get('footer')
    expect(footer.element.compareDocumentPosition(notice.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(wrapper.get('a, button').classes()).toContain('skip-link')
  })
})

/**
 * La revérification de la session finie au retour sur l'onglet (#748, R8, CR7) : un seul mécanisme, installé par
 * `AppLayout`, donc valable sur toutes les pages — avant, le bandeau seul l'écoutait, et il n'existe que dans le
 * backoffice.
 */
describe('AppLayout — revérification de la session finie au retour', () => {
  const account = { username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] }

  function repositoryReturning(user: typeof account | null): AuthRepository {
    return { ...createStubAuthRepository(), me: vi.fn(async () => sessionFor(user)) }
  }

  // `sessionEnded` est un état partagé du module : un compte appliqué l'abaisse, pour ne pas fuir d'un test à l'autre.
  afterEach(async () => {
    await signInAgain()
  })

  it("un retour sur l'onglet, bandeau affiché : une seule relecture de /api/me", async () => {
    const repository = repositoryReturning(account)
    const wrapper = await mountLayout('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()
    expect(wrapper.find('[data-testid="admin-session-ended"]').exists()).toBe(true)

    window.dispatchEvent(new Event('focus'))
    await flushPromises()

    // Le bandeau n'a plus son écouteur : le mécanisme partagé est le seul, sans doublon.
    expect(repository.me).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="admin-session-ended"]').exists()).toBe(false)
    expect(authState.sessionEnded).toBe(false)
  })

  it("la revérification vaut hors du backoffice (« Mes connexions MCP »)", async () => {
    const repository = repositoryReturning(account)
    await mountLayout('/fr/mcp-connections', repository)
    markSessionExpired()
    await flushPromises()

    window.dispatchEvent(new Event('focus'))
    await flushPromises()

    expect(repository.me).toHaveBeenCalledTimes(1)
    expect(authState.sessionEnded).toBe(false)
  })

  it("tant que le compte n'est pas revenu, la session reste finie", async () => {
    const repository = repositoryReturning(null)
    await mountLayout('/fr/mcp-connections', repository)
    markSessionExpired()
    await flushPromises()

    window.dispatchEvent(new Event('focus'))
    await flushPromises()

    expect(repository.me).toHaveBeenCalledTimes(1)
    expect(authState.sessionEnded).toBe(true)
  })
})

/**
 * Un autre compte revient pendant qu'une page protégée est ouverte (#748, R18, F3/D) : la session est appliquée, mais
 * la page n'est plus celle de ce compte. `AppLayout` rejoue la navigation (la garde juge le nouveau compte) puis
 * remonte la page (une `key` sur le composant routé : même chemin, sinon même instance, avec les données du premier).
 */
describe('AppLayout — un autre compte au retour', () => {
  const FIRST = { username: 'premier', roles: ['ROLE_SUPER', 'ROLE_USER'] }
  const OTHER_SUPER = { username: 'autre-super', roles: ['ROLE_SUPER', 'ROLE_USER'] }
  const OTHER_PLAIN = { username: 'autre-simple', roles: ['ROLE_TRUSTED', 'ROLE_USER'] }

  /** Le compte affiché quand la session finit : c'est lui que le retour compare. */
  async function signInAs(user: typeof FIRST): Promise<void> {
    const repository: AuthRepository = { ...createStubAuthRepository(), login: async () => user }
    const Probe = defineComponent({ setup: () => ({ auth: useAuth() }), template: '<div />' })
    const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
    await probe.vm.auth.login(user.username, 'x')
    probe.unmount()
  }

  /** Une page protégée dont chaque montage est compté, derrière une garde qui reprend celle de `router/index.ts`. */
  async function mountProtected(returning: typeof FIRST) {
    const mounts = { count: 0 }
    const Counted = defineComponent({
      setup() {
        mounts.count += 1
        return () => h('div', { 'data-testid': 'counted-page' })
      },
    })
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/:locale(fr|en)/admin/case-studies', name: 'admin-case-studies', component: Counted, meta: { requiresAuth: true, roles: [ROLE_SUPER] } },
        { path: '/:locale(fr|en)/forbidden', name: 'forbidden', component: PlainStub },
        { path: '/:locale(fr|en)/login', name: 'login', component: PlainStub },
      ],
    })
    const judged = vi.fn()
    router.beforeEach((to) => {
      judged(to.fullPath)
      if (!to.meta.requiresAuth) {
        return
      }
      if (!authState.user) {
        return { name: 'login', params: { locale: 'fr' } }
      }
      if (to.meta.roles && !to.meta.roles.some((role) => hasRole(authState.user, role))) {
        return { name: 'forbidden', params: { locale: 'fr' } }
      }
    })
    await signInAs(FIRST)
    await router.push('/fr/admin/case-studies?tri=nom#fin')
    await router.isReady()
    const repository: AuthRepository = { ...createStubAuthRepository(), me: vi.fn(async () => sessionFor(returning)) }
    const wrapper = mountLayoutOn(router, repository)
    await flushPromises()
    judged.mockClear()

    return { wrapper, router, repository, mounts, judged }
  }

  async function returnToTab(): Promise<void> {
    markSessionExpired()
    await flushPromises()
    window.dispatchEvent(new Event('focus'))
    await flushPromises()
  }

  afterEach(async () => {
    await signInAgain()
  })

  it('le même compte : rétabli en place, la navigation n\'est pas rejouée et la page garde son instance', async () => {
    const { mounts, judged, repository } = await mountProtected(FIRST)
    expect(mounts.count).toBe(1)

    await returnToTab()

    expect(repository.me).toHaveBeenCalledTimes(1)
    expect(judged).not.toHaveBeenCalled()
    expect(mounts.count).toBe(1)
  })

  it('un autre compte : la garde rejuge la route (même adresse) et la page est remontée', async () => {
    const { wrapper, router, mounts, judged, repository } = await mountProtected(OTHER_SUPER)
    expect(mounts.count).toBe(1)

    await returnToTab()

    expect(repository.me).toHaveBeenCalledTimes(1)
    // La navigation rejouée garde le chemin, la requête et l'ancre : la personne reste où elle était.
    expect(judged).toHaveBeenCalledTimes(1)
    expect(judged).toHaveBeenCalledWith('/fr/admin/case-studies?tri=nom#fin')
    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/case-studies?tri=nom#fin')
    expect(mounts.count).toBe(2)
    expect(wrapper.find('[data-testid="counted-page"]').exists()).toBe(true)
  })

  it("pendant le rejeu, l'ancienne page n'est plus rendue ; la garde libérée, la page est remontée (A3-13)", async () => {
    const { wrapper, router, mounts } = await mountProtected(OTHER_SUPER)
    // Ajoutée après la garde de la page : elle ne retient que le rejeu, la garde du routeur n'ayant pas encore rejugé.
    let release: () => void = () => undefined
    const held = new Promise<void>((resolve) => {
      release = resolve
    })
    router.beforeEach(() => held)
    expect(wrapper.find('[data-testid="counted-page"]').exists()).toBe(true)

    await returnToTab()

    // Le nouveau compte est déjà appliqué (l'en-tête a changé) mais la garde n'a rien rendu : la page du premier
    // compte ne reste pas affichée sous l'en-tête du second.
    expect(authState.user?.username).toBe(OTHER_SUPER.username)
    expect(wrapper.find('[data-testid="counted-page"]').exists()).toBe(false)

    release()
    await flushPromises()

    expect(wrapper.find('[data-testid="counted-page"]').exists()).toBe(true)
    expect(mounts.count).toBe(2)
    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/case-studies?tri=nom#fin')
  })

  it("deux rejeux qui se chevauchent : la page reste masquée jusqu'au dernier (CR4-2)", async () => {
    const { wrapper, router, mounts } = await mountProtected(OTHER_SUPER)
    // Une garde retenue : le second rejeu l'atteint ; le premier, annulé par le second avant d'y arriver, règle sa
    // promesse tout de suite — c'est elle qui, avec un booléen partagé, démasquait la page pendant que le second court.
    let release: () => void = () => undefined
    const held = new Promise<void>((resolve) => {
      release = resolve
    })
    router.beforeEach(() => held)

    notifyAccountSwitched()
    notifyAccountSwitched()
    await flushPromises()

    expect(wrapper.find('[data-testid="counted-page"]').exists()).toBe(false)

    release()
    await flushPromises()

    expect(wrapper.find('[data-testid="counted-page"]').exists()).toBe(true)
    expect(mounts.count).toBe(2)
  })

  it('un rejeu qui échoue rend quand même la page', async () => {
    const { wrapper, router, mounts } = await mountProtected(OTHER_SUPER)
    const failure = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    router.beforeEach(() => {
      throw new Error('garde en échec')
    })

    await returnToTab()

    expect(failure).toHaveBeenCalled()
    failure.mockRestore()
    expect(wrapper.find('[data-testid="counted-page"]').exists()).toBe(true)
    expect(mounts.count).toBe(2)
  })

  it('le même compte : la page n\'est jamais masquée (aucun rejeu, aucun clignotement)', async () => {
    const { wrapper, router, mounts } = await mountProtected(FIRST)
    let judgedDuringReturn = 0
    router.beforeEach(() => {
      judgedDuringReturn += 1
    })

    await returnToTab()

    expect(judgedDuringReturn).toBe(0)
    expect(wrapper.find('[data-testid="counted-page"]').exists()).toBe(true)
    expect(mounts.count).toBe(1)
  })

  it('un refus réel dont la relecture trouve un autre compte rejoue la navigation (R26, CR3-6)', async () => {
    const { wrapper, router, mounts, judged, repository } = await mountProtected(OTHER_SUPER)
    expect(mounts.count).toBe(1)
    const Probe = defineComponent({ setup: () => ({ auth: useAuth() }), template: '<div />' })
    const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })

    // Un 403 réel vient d'être reçu (aucune session finie) : la relecture trouve un autre compte que celui de la page.
    await probe.vm.auth.rereadAfterRefusal()
    await flushPromises()
    probe.unmount()

    expect(repository.me).toHaveBeenCalledTimes(1)
    expect(judged).toHaveBeenCalledTimes(1)
    expect(judged).toHaveBeenCalledWith('/fr/admin/case-studies?tri=nom#fin')
    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/case-studies?tri=nom#fin')
    expect(mounts.count).toBe(2)
    expect(wrapper.find('[data-testid="counted-page"]').exists()).toBe(true)
  })

  it("le nouveau compte n'a pas le rôle : la garde redirige, la page protégée n'est pas remontée pour lui", async () => {
    const { wrapper, router, mounts } = await mountProtected(OTHER_PLAIN)

    await returnToTab()

    expect(router.currentRoute.value.name).toBe('forbidden')
    expect(mounts.count).toBe(1)
    expect(wrapper.find('[data-testid="counted-page"]').exists()).toBe(false)
  })
})
