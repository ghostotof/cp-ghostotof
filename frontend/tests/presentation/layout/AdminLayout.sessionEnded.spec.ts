import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import AdminLayout from '../../../src/presentation/layout/AdminLayout.vue'
import AdminCaseStudiesPage from '../../../src/presentation/pages/admin/AdminCaseStudiesPage.vue'
import AdminQualityPage from '../../../src/presentation/pages/admin/AdminQualityPage.vue'
import { createAppI18n } from '../../../src/presentation/i18n'
import { ADMIN_CASE_STUDY_REPOSITORY } from '../../../src/application/admin/caseStudies/useAdminCaseStudies'
import { ADMIN_QUALITY_PRINCIPLE_REPOSITORY } from '../../../src/application/admin/quality/useAdminQualityPrinciples'
import { ADMIN_QUALITY_TRAIT_REPOSITORY } from '../../../src/application/admin/quality/useAdminQualityTraits'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../src/application/admin/translation/useAdminTranslation'
import { CV_REPOSITORY } from '../../../src/application/cv/useCvDownload'
import { AUTH_REPOSITORY, authState, lastAccountName, markSessionExpired, useAuth } from '../../../src/application/auth/useAuth'
import { HttpAdminCaseStudyRepository } from '../../../src/infrastructure/admin/caseStudies/HttpAdminCaseStudyRepository'
import { HttpAdminQualityPrincipleRepository } from '../../../src/infrastructure/admin/quality/HttpAdminQualityPrincipleRepository'
import { HttpAdminQualityTraitRepository } from '../../../src/infrastructure/admin/quality/HttpAdminQualityTraitRepository'
import { setBackofficeSessionReaction } from '../../../src/infrastructure/admin/shared/BackofficeHttpClient'
import {
  createBackofficeSessionReaction,
} from '../../../src/presentation/router/backofficeSessionExpiry'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
  markBackofficeAccessRefused,
} from '../../../src/application/admin/shared/backofficeAccessRefusal'
import { expectNoAccessibilityViolation } from '../../support/axe'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AdminTranslationRepository } from '../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { CvRepository } from '../../../src/domain/cv/repositories/CvRepository'
import { sessionFor } from '../../support/authSession'
import { signInAgain } from '../../support/signInAgain'
import { ACCESS_REFUSED_FR, EARLIER_SAVE_REFUSED_FR } from '../../support/accessRefusalTexts'
import { defineComponent, h, type Component } from 'vue'

const SUPER = { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }

const authRepository: AuthRepository = {
  login: async () => SUPER,
  logout: async () => undefined,
  hasWriteToken: () => true,
  me: async () => sessionFor(null),
}

/**
 * La réaction de production, construite comme `main.ts` le fait (#748, R17) : sur le `useAuth()` d'un composant
 * monté avec `repository`, dont `me` est ce que la relecture du palier lira.
 */
function createProductionReaction(repository: AuthRepository): ReturnType<typeof createBackofficeSessionReaction> {
  const Probe = defineComponent({ setup: () => ({ handler: createBackofficeSessionReaction(useAuth()) }), template: '<div />' })
  const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
  const { handler } = probe.vm
  probe.unmount()

  return handler
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

/** Les rubriques de la navigation d'`AdminLayout` : chacune est une route, le lien d'un nom inconnu lèverait. */
const ADMIN_SECTIONS = [
  'technologies',
  'about',
  'quality',
  'contributions',
  'incidents',
  'anonymous-cv',
  'case-studies',
  'watch',
  'users',
]

/**
 * Le montage de production : `AdminLayout` autour d'une vraie page d'édition (`route`, la rubrique qu'elle tient), ses
 * vrais dépôts HTTP (donc `BackofficeHttpClient`, `providers`) sur un `fetch` simulé, la vraie réaction. Les autres
 * rubriques sont des routes vides, et `elsewhere` une page où naviguer. Les lectures du chargement partent dans le `setup`
 * de la page, avant le montage de l'alerte d'`AdminLayout`.
 */
async function mountAdminPage(
  page: Component,
  route: string,
  providers: Record<symbol, unknown>,
  locale: 'fr' | 'en' = 'fr',
): Promise<{ wrapper: VueWrapper; router: Router }> {
  const emptyPage = defineComponent({ setup: () => () => h('p') })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/:locale(fr|en)/admin',
        component: AdminLayout,
        // Comme la vraie route : le lien de reconnexion ne ramène qu'à une route `requiresAuth` (#748, R16, F8).
        meta: { requiresAuth: true },
        children: [
          ...ADMIN_SECTIONS.map((name) => ({
            path: name,
            name: `admin-${name}`,
            component: name === route ? page : emptyPage,
          })),
          { path: 'elsewhere', name: 'admin-elsewhere', component: defineComponent({ setup: () => () => h('p', 'ailleurs') }) },
        ],
      },
      { path: '/:locale(fr|en)/login', name: 'login', component: emptyPage },
    ],
  })
  await router.push(`/${locale}/admin/${route}`)
  await router.isReady()

  const i18n = createAppI18n()
  // La langue de l'interface suit celle de la route (le routeur de production la pose).
  i18n.global.locale.value = locale
  const wrapper = mount(defineComponent({ template: '<RouterView />' }), {
    attachTo: document.body,
    global: {
      plugins: [i18n, router],
      provide: {
        [ADMIN_TRANSLATION_REPOSITORY as symbol]: translationRepository,
        [CV_REPOSITORY as symbol]: cvRepository,
        ...providers,
      },
    },
  })
  await flushPromises()

  return { wrapper, router }
}

/** Les études de cas : une page d'édition à une liste. */
const mountAdminCaseStudies = (auth: AuthRepository = authRepository, locale: 'fr' | 'en' = 'fr') =>
  mountAdminPage(
    AdminCaseStudiesPage,
    'case-studies',
    {
      [ADMIN_CASE_STUDY_REPOSITORY as symbol]: new HttpAdminCaseStudyRepository('https://api.example.test'),
      [AUTH_REPOSITORY as symbol]: auth,
    },
    locale,
  )

/** La qualité : une page à **deux** listes (principes, traits). */
const mountAdminQuality = async (auth: AuthRepository): Promise<VueWrapper> =>
  (
    await mountAdminPage(AdminQualityPage, 'quality', {
      [ADMIN_QUALITY_PRINCIPLE_REPOSITORY as symbol]: new HttpAdminQualityPrincipleRepository('https://api.example.test'),
      [ADMIN_QUALITY_TRAIT_REPOSITORY as symbol]: new HttpAdminQualityTraitRepository('https://api.example.test'),
      [AUTH_REPOSITORY as symbol]: auth,
    })
  ).wrapper

/**
 * Régression de R1c (code-review CR1) : un 401 mène à la connexion, la page est
 * démontée et une étude de cas rédigée pendant plus d'une heure est perdue au
 * moment d'« Enregistrer ». La session finit, la saisie reste.
 */
describe('AdminLayout — fin de session pendant une saisie (#650, S2)', () => {
  beforeEach(async () => {
    await signIn()
    setBackofficeSessionReaction(createProductionReaction(authRepository))
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init?: RequestInit) =>
        'GET' === (init?.method ?? 'GET') ? jsonResponse(200, []) : jsonResponse(401, { code: 401, message: 'Expired JWT Token' }),
      ),
    )
  })

  afterEach(() => {
    setBackofficeSessionReaction(null)
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
    setBackofficeSessionReaction(createProductionReaction(authRepository))
  })

  afterEach(() => {
    setBackofficeSessionReaction(null)
    clearBackofficeAccessRefusal()
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

  it("un 403 avec jeton : pas de bandeau de session finie, l'alerte « accès refusé » (#748, R17)", async () => {
    document.cookie = 'XSRF-TOKEN=jeton; path=/'
    stubWrites(403)
    // Le palier relu après le refus reste celui d'un compte : la session tient.
    const signedIn: AuthRepository = { ...authRepository, me: async () => sessionFor(SUPER) }
    setBackofficeSessionReaction(createProductionReaction(signedIn))
    const { wrapper } = await mountAdminCaseStudies(signedIn)

    await fillAndSubmit(wrapper)

    const alerts = wrapper.findAll('[role="alert"]')
    expect(alerts.some((alert) => alert.text().includes('Votre session a pris fin'))).toBe(false)
    expect(alerts.some((alert) => alert.text().includes('Une erreur est survenue'))).toBe(false)
    expect(alerts).toHaveLength(1)
    expect(alerts[0]?.text()).toContain("Le serveur refuse cette demande à votre session")
    wrapper.unmount()
  })
})

/**
 * Un refus réel du backoffice (#748, R17, F4) : un 403 qui n'est pas une fin de session. Le compte garde son jeton
 * (`XSRF-TOKEN`), mais n'a plus, ou pas, `ROLE_SUPER`. Une seule alerte, celle d'`AdminLayout`, avec « Changer de
 * compte » dans le même onglet ; la saisie reste ; le palier est relu une fois.
 */
describe('AdminLayout — refus réel du backoffice (#748, R17)', () => {
  const me = vi.fn(async () => sessionFor(SUPER))
  const repository: AuthRepository = { ...authRepository, me }

  function stubFetch(readStatus: number, writeStatus: number): void {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init?: RequestInit) =>
        'GET' === (init?.method ?? 'GET')
          ? jsonResponse(readStatus, 200 === readStatus ? [] : { code: readStatus, message: 'x' })
          : jsonResponse(writeStatus, { code: writeStatus, message: 'x' }),
      ),
    )
  }

  beforeEach(async () => {
    me.mockClear()
    await signIn()
    document.cookie = 'XSRF-TOKEN=jeton; path=/'
    setBackofficeSessionReaction(createProductionReaction(repository))
  })

  afterEach(() => {
    setBackofficeSessionReaction(null)
    clearBackofficeAccessRefusal()
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    document.body.innerHTML = ''
  })

  it("aucun refus : aucune alerte « accès refusé »", async () => {
    stubFetch(200, 200)
    const { wrapper } = await mountAdminCaseStudies(repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it.each([
    ['fr', "Le serveur refuse cette demande à votre session : votre compte n'a peut-être pas, ou plus, l'accès de confiance, ou la session doit être rouverte. Changez de compte, ou reconnectez-vous au même, pour continuer.", 'Changer de compte'],
    ['en', 'The server refuses this request to your session: your account may not have, or may no longer have, trusted access, or the session needs to be reopened. Switch account, or sign in again to the same one, to continue.', 'Switch account'],
  ] as const)(
    "refus réel à l'enregistrement (%s) : une alerte, « Changer de compte » vers la connexion dans le même onglet, la saisie reste, le palier est relu une fois",
    async (locale, message, linkLabel) => {
      stubFetch(200, 403)
      const { wrapper, router } = await mountAdminCaseStudies(repository, locale)

      await fillAndSubmit(wrapper)

      const alerts = wrapper.findAll('[role="alert"]')
      expect(alerts).toHaveLength(1)
      expect(alerts[0]?.text()).toContain(message)
      const link = alerts[0]?.get('a')
      expect(link?.text()).toBe(linkLabel)
      expect(link?.attributes('href')).toBe(`/${locale}/login?redirect=/${locale}/admin/case-studies&reauth=1`)
      expect(link?.attributes('target')).toBeUndefined()
      // La saisie reste : la mutation rend `false`, la page n'est pas démontée.
      expect((wrapper.get('#admin-case-study-title').element as HTMLInputElement).value).toBe('Un moteur de tarification asynchrone')
      expect(router.currentRoute.value.fullPath).toBe(`/${locale}/admin/case-studies`)
      expect(me).toHaveBeenCalledTimes(1)
      expect(authState.user).not.toBeNull()
      await expectNoAccessibilityViolation(wrapper)
      wrapper.unmount()
    },
  )

  it("refus réel d'une lecture au chargement : l'alerte, une seule, et pas l'échec de chargement de la page", async () => {
    stubFetch(403, 200)
    const { wrapper } = await mountAdminCaseStudies(repository)

    const alerts = wrapper.findAll('[role="alert"]')
    expect(alerts).toHaveLength(1)
    expect(alerts[0]?.text()).toContain('Le serveur refuse cette demande')
    expect(me).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('un second refus remplace le message sans dupliquer l\'alerte', async () => {
    stubFetch(403, 403)
    const { wrapper } = await mountAdminCaseStudies(repository)

    await fillAndSubmit(wrapper)

    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect((wrapper.get('#admin-case-study-title').element as HTMLInputElement).value).toBe('Un moteur de tarification asynchrone')
    wrapper.unmount()
  })

  it("l'alerte s'efface à la navigation", async () => {
    stubFetch(200, 403)
    const { wrapper, router } = await mountAdminCaseStudies(repository)
    await fillAndSubmit(wrapper)
    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)

    await router.push('/fr/admin/elsewhere')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it("le 403 d'un enregistrement revenu après une navigation s'affiche sur la page suivante (R38)", async () => {
    let release: (status: number) => void = () => undefined
    const pendingWrite = new Promise<Response>((resolve) => {
      release = (status) => resolve(jsonResponse(status, { code: status, message: 'x' }))
    })
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init?: RequestInit) => ('GET' === (init?.method ?? 'GET') ? jsonResponse(200, []) : pendingWrite)),
    )
    const { wrapper, router } = await mountAdminCaseStudies(repository)

    // « Enregistrer » part, la personne navigue ailleurs avant la réponse, puis le serveur refuse l'écriture.
    await wrapper.get('#admin-case-study-title').setValue('Un moteur de tarification asynchrone')
    await wrapper.get('#admin-case-study-problem').setValue('Des calculs bloquants.')
    await wrapper.get('#admin-case-study-solution').setValue('Messenger.')
    await wrapper.get('#admin-case-study-tradeoffs').setValue('Cohérence différée.')
    await wrapper.get('#admin-case-study-measured-result').setValue('Temps divisé par dix.')
    await wrapper.get('form').trigger('submit')
    await router.push('/fr/admin/elsewhere')
    await flushPromises()
    release(403)
    await flushPromises()

    // La personne a fait le geste : elle apprend que l'enregistrement a échoué, sur la page où elle est allée.
    expect(wrapper.text()).toContain('ailleurs')
    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect(wrapper.get('[role="alert"]').text()).toContain('Le serveur refuse cette demande')
    expect(me).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it("après un changement de compte, le 403 tardif d'un enregistrement du premier compte n'affiche rien (R48)", async () => {
    let release: (status: number) => void = () => undefined
    const pendingWrite = new Promise<Response>((resolve) => {
      release = (status) => resolve(jsonResponse(status, { code: status, message: 'x' }))
    })
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init?: RequestInit) => ('GET' === (init?.method ?? 'GET') ? jsonResponse(200, []) : pendingWrite)),
    )
    const { wrapper } = await mountAdminCaseStudies(repository)

    // « Enregistrer » part sous le compte « super » ; la page est restée ouverte.
    await wrapper.get('#admin-case-study-title').setValue('Un moteur de tarification asynchrone')
    await wrapper.get('#admin-case-study-problem').setValue('Des calculs bloquants.')
    await wrapper.get('#admin-case-study-solution').setValue('Messenger.')
    await wrapper.get('#admin-case-study-tradeoffs').setValue('Cohérence différée.')
    await wrapper.get('#admin-case-study-measured-result').setValue('Temps divisé par dix.')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    // Hors session finie, une lecture du palier trouve un autre compte (par exemple connecté dans un autre onglet).
    const Probe = defineComponent({ setup: () => ({ auth: useAuth() }), template: '<div />' })
    const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
    me.mockImplementationOnce(async () => sessionFor({ username: 'autre', roles: ['ROLE_SUPER', 'ROLE_USER'] }))
    await probe.vm.auth.checkAuth()
    probe.unmount()
    expect(me).toHaveBeenCalledTimes(1)

    // Le serveur refuse l'enregistrement du premier compte : la réponse est celle d'une session qui n'est plus la courante.
    release(403)
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(me).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it("le même compte rétabli entre l'envoi et le 403 d'un enregistrement : l'alerte s'affiche (R63, décision 2)", async () => {
    let release: (status: number) => void = () => undefined
    const pendingWrite = new Promise<Response>((resolve) => {
      release = (status) => resolve(jsonResponse(status, { code: status, message: 'x' }))
    })
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init?: RequestInit) => ('GET' === (init?.method ?? 'GET') ? jsonResponse(200, []) : pendingWrite)),
    )
    const { wrapper } = await mountAdminCaseStudies(repository)

    // « Enregistrer » part sous le compte « super » ; la page est restée ouverte.
    await wrapper.get('#admin-case-study-title').setValue('Un moteur de tarification asynchrone')
    await wrapper.get('#admin-case-study-problem').setValue('Des calculs bloquants.')
    await wrapper.get('#admin-case-study-solution').setValue('Messenger.')
    await wrapper.get('#admin-case-study-tradeoffs').setValue('Cohérence différée.')
    await wrapper.get('#admin-case-study-measured-result').setValue('Temps divisé par dix.')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    // Déconnecté dans un autre onglet, puis reconnecté au même compte : la génération a avancé, le compte n'a pas changé.
    markSessionExpired()
    await signInAgain()
    expect(authState.sessionEnded).toBe(false)
    me.mockClear()

    // Le serveur refuse l'enregistrement : c'est bien celui du compte qui est à l'écran, la personne doit l'apprendre.
    release(403)
    await flushPromises()

    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect(wrapper.get('[role="alert"]').text()).toContain('Le serveur refuse cette demande')
    expect(me).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  describe('un refus parti avant une navigation (#748, R28)', () => {
    /** Une lecture lente : la réponse n'arrive que lorsque le test la libère. */
    function stubSlowRead(): { release: (status: number) => void } {
      let release: (status: number) => void = () => undefined
      const pending = new Promise<Response>((resolve) => {
        release = (status) => resolve(jsonResponse(status, { code: status, message: 'x' }))
      })
      vi.stubGlobal('fetch', vi.fn(async () => pending))

      return { release }
    }

    it("le 403 d'une page quittée, arrivé sur la suivante : aucune alerte, aucune relecture", async () => {
      const slow = stubSlowRead()
      const { wrapper, router } = await mountAdminCaseStudies(repository)

      // La personne quitte la page X avant que sa lecture ne réponde.
      await router.push('/fr/admin/elsewhere')
      await flushPromises()
      slow.release(403)
      await flushPromises()

      expect(wrapper.text()).toContain('ailleurs')
      expect(wrapper.find('[role="alert"]').exists()).toBe(false)
      expect(me).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it("un refus de la page suivante s'affiche toujours", async () => {
      const slow = stubSlowRead()
      const { wrapper, router } = await mountAdminCaseStudies(repository)
      await router.push('/fr/admin/elsewhere')
      await flushPromises()
      slow.release(403)
      await flushPromises()

      // La personne revient sur une page d'administration : sa lecture, partie après la navigation, est refusée.
      vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(403, { code: 403, message: 'x' })))
      await router.push('/fr/admin/case-studies')
      await flushPromises()

      expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
      expect(wrapper.get('[role="alert"]').text()).toContain('Le serveur refuse cette demande')
      expect(me).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })
  })

  it("la relecture trouve la session finie : seul le bandeau de session finie reste", async () => {
    stubFetch(200, 403)
    me.mockImplementationOnce(async () => sessionFor(null))
    const { wrapper } = await mountAdminCaseStudies(repository)

    await fillAndSubmit(wrapper)

    const alerts = wrapper.findAll('[role="alert"]')
    expect(alerts).toHaveLength(1)
    expect(alerts[0]?.text()).toContain('Votre session a pris fin')
    expect(authState.sessionEnded).toBe(true)
    wrapper.unmount()
  })
})

/** La réponse que le dépôt HTTP lit d'un principe de qualité créé (`POST`), servie par les écritures de ces cas. */
function createdPrincipleResponse(): Response {
  return jsonResponse(201, {
    id: '019968a0-0000-7000-8000-0000000000a1',
    locale: 'fr',
    translationGroup: '019968b0-0000-7000-8000-0000000000e1',
    title: 'DDD',
    description: 'Description DDD',
    iconKey: 'boxes',
    position: 0,
  })
}

/** Une lecture retenue : son refus part quand le test le décide (`refuse`), après d'autres événements. */
function heldRead(): { response: Promise<Response>; refuse: () => void } {
  let refuse: () => void = () => undefined
  const response = new Promise<Response>((resolve) => {
    refuse = () => resolve(jsonResponse(403, { code: 403, message: 'x' }))
  })

  return { response, refuse }
}

/** Un principe de qualité créé par le formulaire : l'écriture est servie, puis la page relit sa liste. */
async function createQualityPrinciple(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('#admin-quality-principle-title').setValue('DDD')
  await wrapper.get('#admin-quality-principle-description').setValue('Description DDD')
  await wrapper.get('#admin-quality-principle-icon-key').setValue('boxes')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

/** Le `<span>` du message de l'alerte : sa référence change à chaque annonce (c'est ce qui réannonce l'alerte). */
function noticeMessage(wrapper: VueWrapper): { text: string; element: Element } {
  const span = wrapper.get('[data-testid="admin-access-refused"] span')

  return { text: span.text(), element: span.element }
}

/**
 * L'avis de l'enregistrement précédent sur une vraie page à deux listes, et la fin du chargement de la page d'arrivée
 * (#748, R112, R122) : la personne est connectée, un enregistrement a été refusé pendant qu'elle avait quitté le backoffice,
 * et les deux lectures du chargement partent dans le `setup` de la page, avant le montage de l'alerte.
 */
describe("AdminLayout — l'avis de l'enregistrement précédent sur une page à deux listes (#748, R112, R122)", () => {
  const repository: AuthRepository = { ...authRepository, me: async () => sessionFor(SUPER) }

  beforeEach(async () => {
    await signIn()
    document.cookie = 'XSRF-TOKEN=jeton; path=/'
    setBackofficeSessionReaction(createProductionReaction(repository))
  })

  afterEach(() => {
    setBackofficeSessionReaction(null)
    clearBackofficeAccessRefusal()
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    document.body.innerHTML = ''
  })

  it(
    "l'avis à chaque annonce du chargement, le texte courant à la relecture qui suit un enregistrement (#748, R112, décision 3)",
    async () => {
      // Les deux listes du chargement sont retenues : chaque refus arrive à son heure, et chaque annonce se lit.
      const principles = heldRead()
      const traits = heldRead()
      let principleReads = 0
      vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
          if ('POST' === init?.method) {
            return createdPrincipleResponse()
          }
          if (url.endsWith('/traits')) {
            return traits.response
          }
          principleReads += 1

          // Le chargement des principes est retenu ; la relecture qui suit l'enregistrement est refusée.
          return 1 === principleReads ? principles.response : jsonResponse(403, { code: 403, message: 'x' })
        }),
      )
      // Un enregistrement refusé pendant que la personne avait quitté le backoffice, sous le compte connecté.
      markBackofficeAccessRefused({ kind: 'write' }, lastAccountName())

      const wrapper = await mountAdminQuality(repository)

      // L'alerte porte l'avis dès le montage : l'écriture seule est comptée.
      const atMount = noticeMessage(wrapper)
      expect(atMount.text).toBe(EARLIER_SAVE_REFUSED_FR)
      expect(backofficeAccessRefused.value).toBe(1)

      // Les deux lectures du chargement, parties avant le montage de l'alerte, sont refusées l'une après l'autre.
      principles.refuse()
      await flushPromises()
      const afterPrinciples = noticeMessage(wrapper)
      expect(afterPrinciples.text).toBe(EARLIER_SAVE_REFUSED_FR)
      expect(afterPrinciples.element).not.toBe(atMount.element)
      expect(backofficeAccessRefused.value).toBe(2)

      traits.refuse()
      await flushPromises()
      const afterTraits = noticeMessage(wrapper)
      expect(afterTraits.text).toBe(EARLIER_SAVE_REFUSED_FR)
      expect(afterTraits.element).not.toBe(afterPrinciples.element)
      expect(backofficeAccessRefused.value).toBe(3)

      // Un principe créé : l'écriture est servie, sa relecture, partie après le montage, est refusée.
      await createQualityPrinciple(wrapper)

      const afterRead = noticeMessage(wrapper)
      expect(afterRead.text).toBe(ACCESS_REFUSED_FR)
      expect(afterRead.element).not.toBe(afterTraits.element)
      expect(backofficeAccessRefused.value).toBe(4)
      wrapper.unmount()
    },
  )

  it("une relecture servie, puis le refus tardif d'une lecture de la page d'arrivée : le texte courant (#748, R122)", async () => {
    // La liste des traits est retenue : sa réponse arrive après la relecture des principes.
    const traits = heldRead()
    let principleReads = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string, init?: RequestInit) => {
        if ('POST' === init?.method) {
          return createdPrincipleResponse()
        }
        if (url.endsWith('/traits')) {
          return traits.response
        }
        principleReads += 1

        // Le chargement des principes est refusé ; la relecture qui suit l'enregistrement est servie.
        return 1 === principleReads ? jsonResponse(403, { code: 403, message: 'x' }) : jsonResponse(200, [])
      }),
    )
    markBackofficeAccessRefused({ kind: 'write' }, lastAccountName())

    const wrapper = await mountAdminQuality(repository)

    // Les deux lectures du chargement sont parties avant le montage : l'une refusée, l'autre en attente. L'avis tient.
    expect(noticeMessage(wrapper).text).toBe(EARLIER_SAVE_REFUSED_FR)
    expect(backofficeAccessRefused.value).toBe(2)

    // Un principe créé : l'écriture, puis la relecture des principes, partie après le montage, sont servies.
    await createQualityPrinciple(wrapper)
    expect(principleReads).toBe(2)
    expect(noticeMessage(wrapper).text).toBe(EARLIER_SAVE_REFUSED_FR)

    // La lecture retenue du chargement répond enfin, refusée : le chargement est clos, c'est le texte courant.
    traits.refuse()
    await flushPromises()

    expect(backofficeAccessRefused.value).toBe(3)
    expect(noticeMessage(wrapper).text).toBe(ACCESS_REFUSED_FR)
    wrapper.unmount()
  })
})
