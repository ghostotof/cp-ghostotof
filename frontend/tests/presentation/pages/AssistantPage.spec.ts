import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent } from 'vue'
import AssistantPage from '../../../src/presentation/pages/AssistantPage.vue'
import { ASSISTANT_REPOSITORY } from '../../../src/application/assistant/useAssistant'
import { CV_REPOSITORY } from '../../../src/application/cv/useCvDownload'
import type { AssistantRepository } from '../../../src/domain/assistant/repositories/AssistantRepository'
import { AssistantError, type AssistantErrorReason } from '../../../src/domain/assistant/errors/AssistantError'
import { AUTH_REPOSITORY, markSessionExpired, useAuth } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import { BASE_ACCESS_SESSION } from '../../../src/domain/auth/entities/AuthSession'
import { createAppI18n } from '../../../src/presentation/i18n'
import AppLayout from '../../../src/presentation/layout/AppLayout.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { BASE_ACCESS_REPOSITORY } from '../../../src/application/baseAccess/useBaseAccess'
import { sessionFor } from '../../support/authSession'
import { signInAgain } from '../../support/signInAgain'
import { ACCESS_REFUSED_EN, ACCESS_REFUSED_FR, SWITCH_ACCOUNT_EN, SWITCH_ACCOUNT_FR } from '../../support/accessRefusalTexts'
import fr from '../../../src/infrastructure/i18n/locales/fr.json'
import en from '../../../src/infrastructure/i18n/locales/en.json'
import { expectNoAccessibilityViolation } from '../../support/axe'

/** Un appel au repository dont le test pilote les fragments et l'issue. */
interface PendingCall {
  onDelta: (text: string) => void
  resolve: () => void
  reject: (error: unknown) => void
}

function createControlledRepository(): { repository: AssistantRepository; calls: PendingCall[]; answer: ReturnType<typeof vi.fn> } {
  const calls: PendingCall[] = []
  const answer = vi.fn(
    (_locale: string, _messages: unknown, onDelta: (text: string) => void) =>
      new Promise<void>((resolve, reject) => {
        calls.push({ onDelta, resolve, reject })
      }),
  )
  return { repository: { answer } as unknown as AssistantRepository, calls, answer }
}

/** `/api/me`, que la page relit sur un refus réel (#748, R7) : il répond le palier de base. */
let me = vi.fn(async () => BASE_ACCESS_SESSION)

function mountPage(
  repository: AssistantRepository,
  downloadCv = vi.fn(async () => ({ blob: new Blob(), filename: 'cv.pdf' })),
  locale: 'fr' | 'en' = 'fr',
): VueWrapper {
  me = vi.fn(async () => BASE_ACCESS_SESSION)
  const authRepository: AuthRepository = { login: vi.fn(), logout: vi.fn(), hasWriteToken: () => true, me }
  const i18n = createAppI18n()
  i18n.global.locale.value = locale
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/:locale/login', name: 'login', component: { template: '<div />' } },
      // `requiresAuth`, comme la vraie route : sans lui le lien de connexion n'a pas de `redirect` (#748, R16, F8).
      { path: '/:locale/assistant', component: { template: '<div />' }, meta: { requiresAuth: true } },
    ],
  })

  return mount(AssistantPage, {
    global: {
      plugins: [router, i18n],
      provide: {
        [ASSISTANT_REPOSITORY as symbol]: repository,
        [AUTH_REPOSITORY as symbol]: authRepository,
        [CV_REPOSITORY as symbol]: { download: downloadCv },
      },
    },
  })
}

async function type(wrapper: VueWrapper, text: string): Promise<void> {
  await wrapper.find('textarea').setValue(text)
}

async function submitWithButton(wrapper: VueWrapper): Promise<void> {
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

function sendButton(wrapper: VueWrapper) {
  return wrapper.find('button[type="submit"]')
}

describe('AssistantPage', () => {
  it('a un h1, un bandeau permanent sans rôle live et trois liens de vérification', () => {
    const wrapper = mountPage(createControlledRepository().repository)

    expect(wrapper.find('h1').exists()).toBe(true)
    const notice = wrapper.find('[data-testid="assistant-disclaimer"]')
    expect(notice.text()).toContain('peut se tromper')
    expect(notice.attributes('role')).toBeUndefined()
    const hrefs = notice.findAll('a').map((a) => a.attributes('href'))
    expect(hrefs).toEqual(['/fr/case-studies', '/fr/anonymous-cv'])
    expect(notice.findAll('button')).toHaveLength(1)
  })

  it('le bouton du bandeau télécharge le CV', async () => {
    const download = vi.fn(async () => ({ blob: new Blob(), filename: 'cv.pdf' }))
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const wrapper = mountPage(createControlledRepository().repository, download)

    await wrapper.find('[data-testid="assistant-disclaimer"] button').trigger('click')
    await flushPromises()

    expect(download).toHaveBeenCalledOnce()
  })

  it('expose un journal role="log" et un seul role="status", vide au repos', () => {
    const wrapper = mountPage(createControlledRepository().repository)

    expect(wrapper.find('[role="log"]').attributes('aria-live')).toBe('polite')
    expect(wrapper.find('[role="log"]').attributes('aria-label')).toBeTruthy()
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(wrapper.find('[role="status"]').text()).toBe('')
  })

  it('« Envoyer » est désactivé tant que la saisie est vide', async () => {
    const wrapper = mountPage(createControlledRepository().repository)

    expect(sendButton(wrapper).attributes('disabled')).toBeDefined()
    await type(wrapper, 'Bonjour')
    expect(sendButton(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('envoie par le bouton et rend la réponse fragment par fragment', async () => {
    const { repository, calls, answer } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Quelle expérience ?')
    await submitWithButton(wrapper)

    expect(answer).toHaveBeenCalledOnce()
    expect(wrapper.find('[role="log"]').text()).toContain('Quelle expérience ?')

    calls[0].onDelta('Douze ')
    await flushPromises()
    expect(wrapper.find('[role="log"]').text()).toContain('Douze')
    calls[0].onDelta('ans.')
    await flushPromises()
    expect(wrapper.find('[role="log"]').text()).toContain('Douze ans.')

    calls[0].resolve()
    await flushPromises()
    expect(wrapper.find('[role="status"]').text()).toBe('')
  })

  it('pendant le flux : annonce la réponse dans l\'unique role="status", « Envoyer » désactivé et aria-busy', async () => {
    const { repository } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Question')
    await submitWithButton(wrapper)
    await type(wrapper, 'Suivante')

    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(wrapper.find('[role="status"]').text()).not.toBe('')
    expect(sendButton(wrapper).attributes('disabled')).toBeDefined()
    expect(sendButton(wrapper).attributes('aria-busy')).toBe('true')
  })

  it('Entrée envoie', async () => {
    const { repository, answer } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Question')
    await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(answer).toHaveBeenCalledOnce()
  })

  it('Maj+Entrée n\'envoie pas (saut de ligne)', async () => {
    const { repository, answer } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Question')
    await wrapper.find('textarea').trigger('keydown', { key: 'Enter', shiftKey: true })
    await flushPromises()

    expect(answer).not.toHaveBeenCalled()
  })

  it('Entrée avec keyCode 229 (validation de candidat IME sous Safari) n\'envoie pas', async () => {
    const { repository, answer } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Question')
    await wrapper.find('textarea').trigger('keydown', { key: 'Enter', isComposing: false, keyCode: 229 })
    await flushPromises()

    expect(answer).not.toHaveBeenCalled()
  })

  it('aria-busy du journal : true pendant le flux, false sinon', async () => {
    const { repository } = createControlledRepository()
    const wrapper = mountPage(repository)

    expect(wrapper.find('[role="log"]').attributes('aria-busy')).toBe('false')
    await type(wrapper, 'Question')
    await submitWithButton(wrapper)
    expect(wrapper.find('[role="log"]').attributes('aria-busy')).toBe('true')
  })

  it('un échec du téléchargement du CV est annoncé dans un role="alert"', async () => {
    const download = vi.fn(async () => {
      throw new Error('boom')
    })
    const wrapper = mountPage(createControlledRepository().repository, download)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    await wrapper.find('[data-testid="assistant-disclaimer"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain(createAppI18n().global.t('common.downloadCvError'))
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
  })

  it('Entrée pendant une composition IME n\'envoie pas', async () => {
    const { repository, answer } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Question')
    await wrapper.find('textarea').trigger('keydown', { key: 'Enter', isComposing: true })
    await flushPromises()

    expect(answer).not.toHaveBeenCalled()
  })

  it('compte la saisie en points de code, en rouge au-delà de 1000, sans attribut maxlength', async () => {
    const wrapper = mountPage(createControlledRepository().repository)

    await type(wrapper, '😀'.repeat(3))
    const counter = wrapper.find('#assistant-counter')
    expect(counter.text()).toContain('3 / 1000')
    expect(counter.classes()).not.toContain('text-danger-emphasis')
    expect(wrapper.find('textarea').attributes('aria-describedby')).toBe('assistant-counter')
    expect(wrapper.find('textarea').attributes('maxlength')).toBeUndefined()

    await type(wrapper, 'a'.repeat(1001))
    expect(counter.classes()).toContain('text-danger-emphasis')
    expect(sendButton(wrapper).attributes('disabled')).toBeDefined()
  })

  it('rend une réponse contenant du HTML en texte, jamais en éléments', async () => {
    const { repository, calls } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Question')
    await submitWithButton(wrapper)
    calls[0].onDelta('<img src=x onerror=alert(1)>')
    calls[0].resolve()
    await flushPromises()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('[role="log"]').text()).toContain('<img src=x onerror=alert(1)>')
  })

  it('« Nouvelle conversation » vide le transcript', async () => {
    const { repository, calls } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Question')
    await submitWithButton(wrapper)
    calls[0].onDelta('Réponse')
    calls[0].resolve()
    await flushPromises()
    expect(wrapper.find('[role="log"]').text()).toContain('Réponse')

    await wrapper.find('button[type="button"].assistant-reset').trigger('click')

    expect(wrapper.find('[role="log"]').text()).not.toContain('Réponse')
    expect(wrapper.find('[role="log"]').text()).not.toContain('Question')
  })

  it.each<[AssistantErrorReason, string]>([
    ['too-large', 'trop longue'],
    ['unavailable', 'indisponible'],
    ['network', 'interrompue'],
    ['unknown', 'inattendue'],
    ['validation', "n'a pas pu être envoyée"],
    ['rate-limited', 'Trop de questions'],
  ])('erreur « %s » : message dans role="alert", la conversation reste affichée', async (reason, expected) => {
    const { repository, calls } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Première')
    await submitWithButton(wrapper)
    calls[0].onDelta('Début')
    calls[0].reject(new AssistantError(reason))
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain(expected)
    expect(wrapper.find('[role="log"]').text()).toContain('Première')
    expect(wrapper.find('[role="log"]').text()).toContain('Début')
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
  })

  // Session finie (401, ou 403 sans jeton CSRF) : le lien s'ouvre, la route `login` accueille un anonyme.
  // Un nouvel onglet (#748, R6) : dans le même, la question tapée serait perdue ; `redirect` ramène l'autre onglet ici.
  it.each<['fr' | 'en', string, string]>([
    ['fr', fr.assistant.errors.unauthenticated, `${fr.assistant.errors.relogin} ${fr.common.opensInNewTab}`],
    ['en', en.assistant.errors.unauthenticated, `${en.assistant.errors.relogin} ${en.common.opensInNewTab}`],
  ])("session finie (%s) : « Se reconnecter » : nouvel onglet, expired=1, retour à l'assistant", async (locale, message, linkText) => {
    const { repository, calls } = createControlledRepository()
    const wrapper = mountPage(repository, undefined, locale)
    // La page est montée sur sa route : `redirect` est le chemin courant.
    await wrapper.vm.$router.push(`/${locale}/assistant`)

    await type(wrapper, 'Question')
    await submitWithButton(wrapper)
    calls[0].reject(new AssistantError('unauthenticated'))
    await flushPromises()

    const alert = wrapper.find('[role="alert"]')
    expect(alert.text()).toContain(message)
    const link = alert.find('a')
    expect(link.attributes('href')).toBe(`/${locale}/login?redirect=/${locale}/assistant&expired=1`)
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toBe('noopener')
    expect(link.text()).toBe(linkText)
  })

  // Refus réel (403 avec jeton) : la session vaut pour le reste du site ; le message est neutre (un compte rétrogradé,
  // ou une signature CSRF périmée) et « Changer de compte » ouvre la connexion dans le même onglet (#748, R7) : la
  // conversation perdue est acceptée.
  it.each<['fr' | 'en', string, string]>([
    [
      'fr',
      ACCESS_REFUSED_FR,
      SWITCH_ACCOUNT_FR,
    ],
    [
      'en',
      ACCESS_REFUSED_EN,
      SWITCH_ACCOUNT_EN,
    ],
  ])('refus (%s) : message neutre et « Changer de compte » (reauth=1, retour à l\'assistant)', async (locale, message, linkText) => {
    const { repository, calls } = createControlledRepository()
    const wrapper = mountPage(repository, undefined, locale)
    await wrapper.vm.$router.push(`/${locale}/assistant`)

    await type(wrapper, 'Question')
    await submitWithButton(wrapper)
    calls[0].reject(new AssistantError('forbidden'))
    await flushPromises()

    const alert = wrapper.find('[role="alert"]')
    expect(alert.text().replace(/\s+/g, ' ')).toBe(`${message} ${linkText}`)
    const link = alert.get('a')
    expect(link.text()).toBe(linkText)
    expect(link.attributes('target')).toBeUndefined()
    expect(link.attributes('href')).toBe(`/${locale}/login?redirect=/${locale}/assistant&reauth=1`)
  })

  it.each<AssistantErrorReason>(['unauthenticated', 'forbidden'])(
    'erreur « %s » : aucune violation d\'accessibilité détectable',
    async (reason) => {
      const { repository, calls } = createControlledRepository()
      const wrapper = mountPage(repository)

      await type(wrapper, 'Question')
      await submitWithButton(wrapper)
      calls[0].reject(new AssistantError(reason))
      await flushPromises()

      await expectNoAccessibilityViolation(wrapper)
    },
  )

  it('429 avec 90 s : « 2 minutes » ; avec 30 s : « 1 minute »', async () => {
    const first = createControlledRepository()
    const wrapper = mountPage(first.repository)
    await type(wrapper, 'Question')
    await submitWithButton(wrapper)
    first.calls[0].reject(new AssistantError('rate-limited', 90))
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain('2 minutes')

    await submitWithButton(wrapper)
    first.calls[1].reject(new AssistantError('rate-limited', 30))
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain('1 minute')
    expect(wrapper.find('[role="alert"]').text()).not.toContain('1 minutes')
  })

  it('429 sans durée : message sans aucun chiffre', async () => {
    const { repository, calls } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Question')
    await submitWithButton(wrapper)
    calls[0].reject(new AssistantError('rate-limited'))
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).not.toMatch(/\d/)
  })

  it('marque une réponse interrompue comme incomplète', async () => {
    const { repository, calls } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Question')
    await submitWithButton(wrapper)
    calls[0].onDelta('Partiel')
    calls[0].reject(new AssistantError('network'))
    await flushPromises()

    expect(wrapper.find('[role="log"]').text()).toContain('incomplète')
  })

  it('ne présente aucune violation d\'accessibilité, conversation affichée', async () => {
    const { repository, calls } = createControlledRepository()
    const wrapper = mountPage(repository)

    await type(wrapper, 'Question')
    await submitWithButton(wrapper)
    calls[0].onDelta('Réponse avec `code`.\n\nSecond paragraphe.')
    calls[0].resolve()
    await flushPromises()

    await expectNoAccessibilityViolation(wrapper)
  })
})

/**
 * Un autre compte revient pendant que l'assistant montre une conversation (#748, R18, Review Focus 3) : la page est
 * remontée par `AppLayout`, rien du premier compte n'est affiché. Le même chemin garderait sinon la même instance.
 */
describe('AssistantPage — le compte change au retour', () => {
  const FIRST = { username: 'premier', roles: ['ROLE_TRUSTED', 'ROLE_USER'] }
  const OTHER = { username: 'autre', roles: ['ROLE_TRUSTED', 'ROLE_USER'] }

  afterEach(async () => {
    await signInAgain()
  })

  async function mountInLayout(returning: typeof FIRST, repository: AssistantRepository) {
    // Le compte affiché quand la session finit : c'est lui que le retour compare.
    const signedIn: AuthRepository = {
      login: vi.fn(async () => FIRST),
      logout: vi.fn(),
      hasWriteToken: () => true,
      me: vi.fn(async () => sessionFor(returning)),
    }
    const Probe = defineComponent({ setup: () => ({ auth: useAuth() }), template: '<div />' })
    const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: signedIn } } })
    await probe.vm.auth.login(FIRST.username, 'x')
    probe.unmount()

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/:locale/login', name: 'login', component: { template: '<div />' } },
        { path: '/:locale/assistant', name: 'assistant', component: AssistantPage, meta: { requiresAuth: true } },
      ],
    })
    await router.push('/fr/assistant')
    await router.isReady()

    const wrapper = mount(AppLayout, {
      global: {
        plugins: [router, createAppI18n()],
        provide: {
          [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository(),
          [AUTH_REPOSITORY as symbol]: signedIn,
          [ASSISTANT_REPOSITORY as symbol]: repository,
          [CV_REPOSITORY as symbol]: { download: vi.fn() },
          [BASE_ACCESS_REPOSITORY as symbol]: { grant: async () => undefined },
        },
      },
    })
    await flushPromises()

    return { wrapper, signedIn }
  }

  async function converse(wrapper: VueWrapper, calls: PendingCall[]): Promise<void> {
    await wrapper.find('textarea').setValue('Quelle expérience ?')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    calls[0].onDelta('Douze ans.')
    calls[0].resolve()
    await flushPromises()
  }

  async function returnToTab(): Promise<void> {
    markSessionExpired()
    await flushPromises()
    window.dispatchEvent(new Event('focus'))
    await flushPromises()
  }

  it("un autre compte revient : la conversation du premier n'est plus affichée", async () => {
    const { repository, calls } = createControlledRepository()
    const { wrapper } = await mountInLayout(OTHER, repository)
    await converse(wrapper, calls)
    expect(wrapper.find('[role="log"]').text()).toContain('Douze ans.')

    await returnToTab()

    expect(wrapper.find('[role="log"]').text()).not.toContain('Douze ans.')
    expect(wrapper.find('[role="log"]').text()).not.toContain('Quelle expérience ?')
  })

  it('le même compte revient : la conversation reste affichée', async () => {
    const { repository, calls } = createControlledRepository()
    const { wrapper } = await mountInLayout(FIRST, repository)
    await converse(wrapper, calls)

    await returnToTab()

    expect(wrapper.find('[role="log"]').text()).toContain('Douze ans.')
  })
})
