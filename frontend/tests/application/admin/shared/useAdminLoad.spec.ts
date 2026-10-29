import { describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { useAdminLoad } from '../../../../src/application/admin/shared/useAdminLoad'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'

/**
 * `useAdminLoad` est l'unique endroit où un chargement d'administration taît la session finie
 * (#748, R3) : le bandeau de session finie est alors la seule annonce, et la valeur déjà lue reste.
 * L'hôte est monté comme le fait `sessionEndedLoad.spec.ts` : le composable vit dans un `setup()`.
 */
function mountLoad<T, K = void>(options: Parameters<typeof useAdminLoad<T, K>>[0]): ReturnType<typeof useAdminLoad<T, K>> {
  let captured: ReturnType<typeof useAdminLoad<T, K>> | undefined
  mount(
    defineComponent({
      setup() {
        captured = useAdminLoad<T, K>(options)
        return () => h('div')
      },
    }),
  )
  if (!captured) throw new Error("Le composable n'a pas été capturé.")

  return captured
}

/** Une promesse que le test résout ou rejette à la main : l'ordre des réponses est ce qu'on éprouve. */
function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void; reject: (reason: unknown) => void } {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })

  return { promise, resolve, reject }
}

describe('useAdminLoad', () => {
  it('pose la valeur lue et baisse isLoading', async () => {
    const value = ref<readonly string[]>([])
    const state = mountLoad<readonly string[]>({
      read: async () => ['a', 'b'],
      apply: (read) => {
        value.value = read
      },
    })
    expect(state.isLoading.value).toBe(true)

    await state.load()
    await flushPromises()

    expect(value.value).toEqual(['a', 'b'])
    expect(state.isLoading.value).toBe(false)
    expect(state.hasError.value).toBe(false)
  })

  it('un échec lève hasError', async () => {
    const state = mountLoad<string>({
      read: async () => {
        throw new Error('boom')
      },
      apply: () => undefined,
    })

    await state.load()

    expect(state.hasError.value).toBe(true)
    expect(state.isLoading.value).toBe(false)
  })

  it('une session finie ne lève pas hasError et garde la valeur déjà posée', async () => {
    const value = ref<string | null>(null)
    let sessionEnded = false
    const state = mountLoad<string>({
      read: async () => {
        if (sessionEnded) throw new SessionEndedError()
        return 'lue'
      },
      apply: (read) => {
        value.value = read
      },
    })
    await state.load()
    expect(value.value).toBe('lue')

    sessionEnded = true
    await state.load()

    expect(state.hasError.value).toBe(false)
    expect(state.isLoading.value).toBe(false)
    expect(value.value).toBe('lue')
  })

  it('une réponse plus ancienne que le dernier load est jetée', async () => {
    const first = deferred<string>()
    const second = deferred<string>()
    const pending = [first, second]
    const value = ref<string | null>(null)
    const state = mountLoad<string>({
      read: () => (pending.shift() as ReturnType<typeof deferred<string>>).promise,
      apply: (read) => {
        value.value = read
      },
    })

    const olderLoad = state.load()
    const newerLoad = state.load()
    second.resolve('récente')
    await newerLoad
    first.resolve('ancienne')
    await olderLoad

    expect(value.value).toBe('récente')
  })

  it("l'échec d'une lecture plus ancienne ne lève pas hasError sur la plus récente", async () => {
    const first = deferred<string>()
    const second = deferred<string>()
    const pending = [first, second]
    const state = mountLoad<string>({
      read: () => (pending.shift() as ReturnType<typeof deferred<string>>).promise,
      apply: () => undefined,
    })

    const olderLoad = state.load()
    const newerLoad = state.load()
    second.resolve('récente')
    await newerLoad
    first.reject(new Error('en retard'))
    await olderLoad

    expect(state.hasError.value).toBe(false)
  })

  it('load(clé) passe la clé au lecteur', async () => {
    const keys: string[] = []
    const state = mountLoad<string, string>({
      read: async (key) => {
        keys.push(key)
        return `valeur ${key}`
      },
      apply: () => undefined,
    })

    await state.load('fr')
    await state.load('en')

    expect(keys).toEqual(['fr', 'en'])
  })
})
