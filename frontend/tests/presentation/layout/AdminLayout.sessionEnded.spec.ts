import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import AdminLayout from '../../../src/presentation/layout/AdminLayout.vue'
import AdminCaseStudiesPage from '../../../src/presentation/pages/admin/AdminCaseStudiesPage.vue'
import { createAppI18n } from '../../../src/presentation/i18n'
import { ADMIN_CASE_STUDY_REPOSITORY } from '../../../src/application/admin/caseStudies/useAdminCaseStudies'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../src/application/admin/translation/useAdminTranslation'
import { CV_REPOSITORY } from '../../../src/application/cv/useCvDownload'
import { AUTH_REPOSITORY, authState, markSessionExpired, useAuth } from '../../../src/application/auth/useAuth'
import { HttpAdminCaseStudyRepository } from '../../../src/infrastructure/admin/caseStudies/HttpAdminCaseStudyRepository'
import { setBackofficeSessionEndedHandler } from '../../../src/infrastructure/admin/shared/BackofficeHttpClient'
import { onBackofficeSessionEnded } from '../../../src/presentation/router/backofficeSessionExpiry'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AdminTranslationRepository } from '../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { CvRepository } from '../../../src/domain/cv/repositories/CvRepository'
import { sessionFor } from '../../support/authSession'
import { defineComponent } from 'vue'

const SUPER = { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }

const authRepository: AuthRepository = {
  login: async () => SUPER,
  logout: async () => undefined,
  hasWriteToken: () => true,
  me: async () => sessionFor(null),
}

const translationRepository: AdminTranslationRepository = { translate: vi.fn() }
const cvRepository: CvRepository = { download: vi.fn() }

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

async function signIn(): Promise<void> {
  const Probe = defineComponent({ setup: () => ({ auth: useAuth() }), template: '<div />' })
  const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: authRepository } } })
  await probe.vm.auth.login('super', 'x')
  probe.unmount()
}

/**
 * Le montage de production : AdminLayout autour d'une vraie page d'édition, le
 * vrai dépôt HTTP (donc `BackofficeHttpClient`) sur un `fetch` simulé, et la
 * vraie réaction à un 401.
 */
async function mountAdminCaseStudies(): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/:locale(fr|en)/admin',
        component: AdminLayout,
        children: [{ path: 'case-studies', name: 'admin-case-studies', component: AdminCaseStudiesPage }],
      },
      { path: '/:locale(fr|en)/admin/users', name: 'admin-users', component: AdminCaseStudiesPage },
      { path: '/:locale(fr|en)/login', name: 'login', component: AdminCaseStudiesPage },
    ],
  })
  await router.push('/fr/admin/case-studies')
  await router.isReady()

  const wrapper = mount(
    defineComponent({ template: '<RouterView />' }),
    {
      attachTo: document.body,
      global: {
        plugins: [createAppI18n(), router],
        provide: {
          [ADMIN_CASE_STUDY_REPOSITORY as symbol]: new HttpAdminCaseStudyRepository('https://api.example.test'),
          [ADMIN_TRANSLATION_REPOSITORY as symbol]: translationRepository,
          [CV_REPOSITORY as symbol]: cvRepository,
          [AUTH_REPOSITORY as symbol]: authRepository,
        },
      },
    },
  )
  await flushPromises()

  return { wrapper, router }
}

/**
 * Régression de R1c (code-review CR1) : un 401 mène à la connexion, la page est
 * démontée et une étude de cas rédigée pendant plus d'une heure est perdue au
 * moment d'« Enregistrer ». La session finit, la saisie reste.
 */
describe('AdminLayout — fin de session pendant une saisie (#650, S2)', () => {
  beforeEach(async () => {
    await signIn()
    setBackofficeSessionEndedHandler(onBackofficeSessionEnded)
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init?: RequestInit) =>
        'GET' === (init?.method ?? 'GET') ? jsonResponse(200, []) : jsonResponse(401, { code: 401, message: 'Expired JWT Token' }),
      ),
    )
  })

  afterEach(() => {
    setBackofficeSessionEndedHandler(null)
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it("un 401 à l'enregistrement garde le texte saisi et affiche le bandeau, sans quitter la page", async () => {
    const { wrapper, router } = await mountAdminCaseStudies()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    await wrapper.get('#admin-case-study-title').setValue('Un moteur de tarification asynchrone')
    await wrapper.get('#admin-case-study-problem').setValue('Des calculs bloquants rédigés pendant plus d’une heure.')
    await wrapper.get('#admin-case-study-solution').setValue('Messenger.')
    await wrapper.get('#admin-case-study-tradeoffs').setValue('Cohérence différée.')
    await wrapper.get('#admin-case-study-measured-result').setValue('Temps divisé par dix.')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    // La page est toujours là, avec ce que la personne a écrit.
    expect((wrapper.get('#admin-case-study-title').element as HTMLInputElement).value).toBe('Un moteur de tarification asynchrone')
    expect((wrapper.get('#admin-case-study-problem').element as HTMLTextAreaElement).value).toBe('Des calculs bloquants rédigés pendant plus d’une heure.')
    expect(wrapper.find('form').exists()).toBe(true)
    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/case-studies')

    // La fin de session est annoncée, avec le chemin pour se reconnecter.
    expect(authState.user).toBeNull()
    const banner = wrapper.findAll('[role="alert"]').find((alert) => alert.text().includes('Votre session a pris fin'))
    expect(banner).toBeDefined()
    expect(banner?.get('a').attributes('href')).toBe('/fr/login?redirect=/fr/admin/case-studies&expired=1')
    wrapper.unmount()
  })
})

async function fillAndSubmit(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('#admin-case-study-title').setValue('Un moteur de tarification asynchrone')
  await wrapper.get('#admin-case-study-problem').setValue('Des calculs bloquants.')
  await wrapper.get('#admin-case-study-solution').setValue('Messenger.')
  await wrapper.get('#admin-case-study-tradeoffs').setValue('Cohérence différée.')
  await wrapper.get('#admin-case-study-measured-result').setValue('Temps divisé par dix.')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

/**
 * #748, constats 7 et 15 : une fin de session n'est annoncée qu'une fois (le bandeau, jamais doublé
 * d'une alerte de la page), et un 403 à une écriture partie sans jeton CSRF — la personne s'est
 * déconnectée dans un autre onglet — en est une.
 */
describe('AdminLayout — une seule annonce de fin de session (#748)', () => {
  beforeEach(async () => {
    await signIn()
    setBackofficeSessionEndedHandler(onBackofficeSessionEnded)
  })

  afterEach(() => {
    setBackofficeSessionEndedHandler(null)
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    document.body.innerHTML = ''
  })

  function stubWrites(status: number): void {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init?: RequestInit) =>
        'GET' === (init?.method ?? 'GET') ? jsonResponse(200, []) : jsonResponse(status, { code: status, message: 'x' }),
      ),
    )
  }

  it("un 401 à l'enregistrement : le bandeau est la seule alerte", async () => {
    stubWrites(401)
    const { wrapper } = await mountAdminCaseStudies()

    await fillAndSubmit(wrapper)

    const alerts = wrapper.findAll('[role="alert"]')
    expect(alerts).toHaveLength(1)
    expect(alerts[0]?.text()).toContain('Votre session a pris fin')
    expect(wrapper.text()).not.toContain('Une erreur est survenue')
    wrapper.unmount()
  })

  it('déconnecté dans un autre onglet (403, plus de XSRF-TOKEN) : le bandeau, seul, et la saisie reste', async () => {
    stubWrites(403)
    const { wrapper, router } = await mountAdminCaseStudies()

    await fillAndSubmit(wrapper)

    const alerts = wrapper.findAll('[role="alert"]')
    expect(alerts).toHaveLength(1)
    expect(alerts[0]?.text()).toContain('Votre session a pris fin')
    expect((wrapper.get('#admin-case-study-title').element as HTMLInputElement).value).toBe('Un moteur de tarification asynchrone')
    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/case-studies')
    wrapper.unmount()
  })

  it('le bandeau est un enfant direct de la section qui contient la page : il colle sur toute sa hauteur', async () => {
    stubWrites(401)
    const { wrapper } = await mountAdminCaseStudies()

    markSessionExpired()
    await flushPromises()

    // jsdom ne calcule pas le collage : on épingle la structure qui le permet. Un `position: sticky` ne colle que
    // dans son parent, qui doit donc couvrir toute la page, pas seulement l'alerte (#748, CR1).
    const section = wrapper.get('section').element
    const banner = wrapper.get('[data-testid="admin-session-ended"]').element
    expect(banner.parentElement).toBe(section)
    expect(section.contains(wrapper.get('#admin-case-study-title').element)).toBe(true)
    wrapper.unmount()
  })

  it("session active : le bandeau n'ajoute à la section aucun élément dans le flux", async () => {
    stubWrites(401)
    const { wrapper } = await mountAdminCaseStudies()

    // D1 : la section est un `flex-column gap-4`, un élément de plus (même vide) y ajoute un `gap`.
    const section = wrapper.get('section').element
    const fromBanner = Array.from(section.querySelectorAll(':scope > [data-testid^="admin-session-"]'))
    expect(fromBanner).toHaveLength(1)
    expect(fromBanner[0]?.getAttribute('data-testid')).toBe('admin-session-restored')
    // Hors du flux (`position: absolute`) : une région vide ne réserve ni place ni `gap`.
    expect(fromBanner[0]?.classList.contains('visually-hidden')).toBe(true)
    // Et aucun enfant direct vide ne traîne (un `<div>` enveloppe sans contenu).
    const emptyChildren = Array.from(section.children).filter((child) => 0 === child.children.length && '' === child.textContent)
    expect(emptyChildren).toEqual(fromBanner)
    wrapper.unmount()
  })

  it("un 403 avec jeton : pas de bandeau, l'erreur de la page", async () => {
    document.cookie = 'XSRF-TOKEN=jeton; path=/'
    stubWrites(403)
    const { wrapper } = await mountAdminCaseStudies()

    await fillAndSubmit(wrapper)

    const alerts = wrapper.findAll('[role="alert"]')
    expect(alerts.some((alert) => alert.text().includes('Votre session a pris fin'))).toBe(false)
    expect(alerts.some((alert) => alert.text().includes('Une erreur est survenue'))).toBe(true)
    expect(authState.user).not.toBeNull()
    wrapper.unmount()
  })
})
