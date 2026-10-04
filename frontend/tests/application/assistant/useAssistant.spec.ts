import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ASSISTANT_REPOSITORY, useAssistant } from '../../../src/application/assistant/useAssistant'
import { AUTH_REPOSITORY, useAuth } from '../../../src/application/auth/useAuth'
import { AssistantError } from '../../../src/domain/assistant/errors/AssistantError'
import type { AssistantRepository } from '../../../src/domain/assistant/repositories/AssistantRepository'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import { sessionFor } from '../../support/authSession'

type AnswerFn = AssistantRepository['answer']

/** Promesse pilotable à la main, pour tenir un flux « ouvert » le temps d'une assertion. */
function deferred() {
  let resolve!: () => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<void>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

function createProbe() {
  let captured: ReturnType<typeof useAssistant> | undefined
  let auth: ReturnType<typeof useAuth> | undefined
  const Probe = defineComponent({
    setup() {
      captured = useAssistant()
      auth = useAuth()
      return () => h('div')
    },
  })
  return { Probe, get: () => ({ assistant: captured!, auth: auth! }) }
}

function setup(answer: AnswerFn, authRepo?: AuthRepository) {
  const { Probe, get } = createProbe()
  const repository: AssistantRepository = { answer: vi.fn(answer) }
  const authRepository: AuthRepository = authRepo ?? {
    login: vi.fn(async () => ({ username: 'jane', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })),
    logout: vi.fn(async () => undefined),
    me: vi.fn(async () => sessionFor(null)),
  }
  const wrapper = mount(Probe, {
    global: { provide: { [ASSISTANT_REPOSITORY as symbol]: repository, [AUTH_REPOSITORY as symbol]: authRepository } },
  })
  return { ...get(), repository, wrapper }
}

describe('useAssistant', () => {
  beforeEach(async () => {
    // État d'auth partagé (singleton de module) : retour à anonyme avant chaque test.
    await setup(async () => undefined).auth.checkAuth()
  })

  it("lève une erreur explicite si le repository n'a pas été fourni", () => {
    const { Probe } = createProbe()
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    expect(() => mount(Probe)).toThrow(/AssistantRepository/)
    warn.mockRestore()
  })

  it('flux nominal : question, réponse qui grandit, streaming puis idle', async () => {
    const gate = deferred()
    let push!: (text: string) => void
    const { assistant } = setup(async (_l, _m, onDelta) => {
      push = onDelta
      await gate.promise
    })
    assistant.draft.value = '  Bonjour ?  '

    const sending = assistant.send('fr')
    expect(assistant.state.value).toBe('streaming')
    expect(assistant.draft.value).toBe('')
    expect(assistant.messages.value).toEqual([
      { role: 'user', content: 'Bonjour ?', status: 'complete' },
      { role: 'assistant', content: '', status: 'streaming' },
    ])

    push('Sa')
    push('lut')
    expect(assistant.messages.value[1].content).toBe('Salut')

    gate.resolve()
    await sending
    expect(assistant.state.value).toBe('idle')
    expect(assistant.messages.value[1]).toMatchObject({ content: 'Salut', status: 'complete' })
    expect(assistant.error.value).toBeNull()
  })

  it("envoie la fenêtre du domaine : la question orpheline de l'historique est écartée", async () => {
    let call = 0
    const { assistant, repository } = setup(async (_l, _m, onDelta) => {
      call += 1
      if (1 === call) {
        throw new AssistantError('unavailable') // erreur avant tout fragment : question orpheline
      }
      onDelta('ok')
    })
    assistant.draft.value = 'première'
    await assistant.send('fr')
    assistant.draft.value = 'seconde'
    await assistant.send('en')

    const [locale, turns] = vi.mocked(repository.answer).mock.calls[1]
    expect(locale).toBe('en')
    expect(turns).toEqual([{ role: 'user', content: 'seconde' }])
  })

  it('canSend : faux si vide, blanc, > 1000 points de code ou en streaming ; vrai à exactement 1000 émojis', async () => {
    const gate = deferred()
    const { assistant } = setup(async () => gate.promise)

    expect(assistant.canSend.value).toBe(false)
    assistant.draft.value = '   '
    expect(assistant.canSend.value).toBe(false)
    assistant.draft.value = '😀'.repeat(1001)
    expect(assistant.draftLength.value).toBe(1001)
    expect(assistant.canSend.value).toBe(false)
    assistant.draft.value = '😀'.repeat(1000)
    expect(assistant.draftLength.value).toBe(1000)
    expect(assistant.canSend.value).toBe(true)

    const sending = assistant.send('fr')
    assistant.draft.value = 'autre'
    expect(assistant.canSend.value).toBe(false)
    gate.resolve()
    await sending
  })

  it('erreur après deux fragments : réponse incomplète gardée, raison exposée', async () => {
    const { assistant } = setup(async (_l, _m, onDelta) => {
      onDelta('a')
      onDelta('b')
      throw new AssistantError('unavailable')
    })
    assistant.draft.value = 'q'
    await assistant.send('fr')

    expect(assistant.messages.value[1]).toMatchObject({ content: 'ab', status: 'incomplete' })
    expect(assistant.error.value?.reason).toBe('unavailable')
    expect(assistant.state.value).toBe('error')
  })

  it('erreur avant tout fragment : réponse retirée, question gardée, saisie restaurée', async () => {
    const { assistant } = setup(async () => {
      throw new AssistantError('rate-limited', 30)
    })
    assistant.draft.value = 'ma question'
    await assistant.send('fr')

    expect(assistant.messages.value).toEqual([{ role: 'user', content: 'ma question', status: 'complete' }])
    expect(assistant.draft.value).toBe('ma question')
    expect(assistant.error.value?.retryAfterSeconds).toBe(30)
  })

  it('ne restaure pas la saisie si la personne a déjà retapé quelque chose', async () => {
    const gate = deferred()
    const { assistant } = setup(async () => gate.promise)
    assistant.draft.value = 'ma question'
    const sending = assistant.send('fr')
    assistant.draft.value = 'autre chose'
    gate.reject(new AssistantError('network'))
    await sending

    expect(assistant.draft.value).toBe('autre chose')
  })

  it('une erreur inattendue est traitée comme unknown', async () => {
    const { assistant } = setup(async () => {
      throw new TypeError('boom')
    })
    assistant.draft.value = 'q'
    await assistant.send('fr')

    expect(assistant.error.value?.reason).toBe('unknown')
  })

  it.each(['unauthenticated', 'forbidden'] as const)('%s : la session nominative est révoquée', async (reason) => {
    const { assistant, auth } = setup(async () => {
      throw new AssistantError(reason)
    })
    await auth.login('jane', 'password')
    expect(auth.tier.value).toBe('trusted')

    assistant.draft.value = 'q'
    await assistant.send('fr')

    expect(auth.tier.value).toBe('anonymous')
  })

  it("une autre erreur ne touche pas à la session", async () => {
    const { assistant, auth } = setup(async () => {
      throw new AssistantError('unavailable')
    })
    await auth.login('jane', 'password')
    assistant.draft.value = 'q'
    await assistant.send('fr')

    expect(auth.tier.value).toBe('trusted')
  })

  it("reset pendant un flux : signal abandonné, messages vides, aucune erreur, résultat tardif ignoré, saisie intacte", async () => {
    let signal!: AbortSignal
    let push!: (text: string) => void
    const gate = deferred()
    const { assistant } = setup(async (_l, _m, onDelta, s) => {
      signal = s!
      push = onDelta
      await gate.promise
    })
    assistant.draft.value = 'q'
    const sending = assistant.send('fr')
    assistant.draft.value = 'brouillon'

    assistant.reset()
    expect(signal.aborted).toBe(true)
    expect((signal.reason as DOMException).name).toBe('AbortError')
    expect(assistant.messages.value).toEqual([])
    expect(assistant.state.value).toBe('idle')

    push('tardif')
    gate.reject(new DOMException('aborted', 'AbortError'))
    await sending

    expect(assistant.messages.value).toEqual([])
    expect(assistant.error.value).toBeNull()
    expect(assistant.state.value).toBe('idle')
    expect(assistant.draft.value).toBe('brouillon')
  })

  it('démontage pendant un flux : signal abandonné', async () => {
    let signal!: AbortSignal
    const gate = deferred()
    const { assistant, wrapper } = setup(async (_l, _m, _d, s) => {
      signal = s!
      await gate.promise
    })
    assistant.draft.value = 'q'
    const sending = assistant.send('fr')

    wrapper.unmount()
    expect(signal.aborted).toBe(true)
    gate.reject(new DOMException('aborted', 'AbortError'))
    await sending
  })

  it('deux send de suite pendant un flux : un seul appel au repository', async () => {
    const gate = deferred()
    const { assistant, repository } = setup(async () => gate.promise)
    assistant.draft.value = 'un'
    const first = assistant.send('fr')
    assistant.draft.value = 'deux'
    await assistant.send('fr')

    expect(repository.answer).toHaveBeenCalledTimes(1)
    gate.resolve()
    await first
  })
})
