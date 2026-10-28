import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import AdminLayout from '../../../src/presentation/layout/AdminLayout.vue'
import AdminCaseStudiesPage from '../../../src/presentation/pages/admin/AdminCaseStudiesPage.vue'
import { createAppI18n } from '../../../src/presentation/i18n'
import { ADMIN_CASE_STUDY_REPOSITORY } from '../../../src/application/admin/caseStudies/useAdminCaseStudies'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../src/application/admin/translation/useAdminTranslation'
import { CV_REPOSITORY } from '../../../src/application/cv/useCvDownload'
import { AUTH_REPOSITORY, authState, useAuth } from '../../../src/application/auth/useAuth'
import { HttpAdminCaseStudyRepository } from '../../../src/infrastructure/admin/caseStudies/HttpAdminCaseStudyRepository'
import { setBackofficeUnauthorizedHandler } from '../../../src/infrastructure/admin/shared/BackofficeHttpClient'
import { onBackofficeUnauthorized } from '../../../src/presentation/router/backofficeSessionExpiry'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AdminTranslationRepository } from '../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { CvRepository } from '../../../src/domain/cv/repositories/CvRepository'
import { sessionFor } from '../../support/authSession'
import { defineComponent } from 'vue'

const SUPER = { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }

const authRepository: AuthRepository = {
  login: async () => SUPER,
  logout: async () => undefined,
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
    setBackofficeUnauthorizedHandler(onBackofficeUnauthorized)
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init?: RequestInit) =>
        'GET' === (init?.method ?? 'GET') ? jsonResponse(200, []) : jsonResponse(401, { code: 401, message: 'Expired JWT Token' }),
      ),
    )
  })

  afterEach(() => {
    setBackofficeUnauthorizedHandler(null)
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
