import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent, h } from 'vue'
import AppLayout from '../../../src/presentation/layout/AppLayout.vue'
import LandingPage from '../../../src/presentation/pages/LandingPage.vue'
import AboutPage from '../../../src/presentation/pages/AboutPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { AUTH_REPOSITORY, authState, markSessionExpired } from '../../../src/application/auth/useAuth'
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

// Un `AppLayout` laissé monté garderait son écouteur de `focus` : il relirait la session à la place du test suivant.
enableAutoUnmount(afterEach)

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
