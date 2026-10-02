import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AssistantPage from '../../../src/presentation/pages/AssistantPage.vue'
import { ASSISTANT_REPOSITORY } from '../../../src/application/assistant/useAssistant'
import { CV_REPOSITORY } from '../../../src/application/cv/useCvDownload'
import type { AssistantRepository } from '../../../src/domain/assistant/repositories/AssistantRepository'
import { AssistantError, type AssistantErrorReason } from '../../../src/domain/assistant/errors/AssistantError'
import { createAppI18n } from '../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../support/axe'

// Aucun flux ne doit survivre à un test : chaque wrapper est démonté à la fin.
enableAutoUnmount(afterEach)

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

function mountPage(repository: AssistantRepository, downloadCv = vi.fn(async () => ({ blob: new Blob(), filename: 'cv.pdf' }))): VueWrapper {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/:locale/login', name: 'login', component: { template: '<div />' } },
    ],
  })

  return mount(AssistantPage, {
    global: {
      plugins: [router, createAppI18n()],
      provide: {
        [ASSISTANT_REPOSITORY as symbol]: repository,
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
    expect(counter.classes()).not.toContain('text-danger')
    expect(wrapper.find('textarea').attributes('aria-describedby')).toBe('assistant-counter')
    expect(wrapper.find('textarea').attributes('maxlength')).toBeUndefined()

    await type(wrapper, 'a'.repeat(1001))
    expect(counter.classes()).toContain('text-danger')
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

  it.each<AssistantErrorReason>(['unauthenticated', 'forbidden'])(
    'erreur « %s » : message et lien de reconnexion avec redirect',
    async (reason) => {
      const { repository, calls } = createControlledRepository()
      const wrapper = mountPage(repository)

      await type(wrapper, 'Question')
      await submitWithButton(wrapper)
      calls[0].reject(new AssistantError(reason))
      await flushPromises()

      const link = wrapper.find('[role="alert"] a')
      expect(link.attributes('href')).toBe('/fr/login?redirect=/fr/assistant')
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
