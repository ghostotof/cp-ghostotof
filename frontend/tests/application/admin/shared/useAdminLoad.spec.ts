import { afterEach, describe, expect, it, vi } from 'vitest'
import { computed, defineComponent, h, ref, watch } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { useAdminLoad } from '../../../../src/application/admin/shared/useAdminLoad'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'
import { AccessForbiddenError } from '../../../../src/domain/auth/errors/AccessForbiddenError'
import { markSessionExpired } from '../../../../src/application/auth/useAuth'
import { signInAgain } from '../../../support/signInAgain'

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
  afterEach(async () => {
    await signInAgain()
  })

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

  it("un refus réel ne lève pas hasError et n'est pas relu au retour d'une session (#748, R17)", async () => {
    // Le refus d'un compte qui n'a pas, ou plus, l'accès : l'alerte d'AdminLayout le dit, la page ne dit rien de plus,
    // et relire ne le lèverait pas — se reconnecter au même compte non plus.
    const read = vi.fn(async (): Promise<string> => {
      throw new AccessForbiddenError()
    })
    const state = mountLoad<string>({ read, apply: () => undefined })

    await state.load()

    expect(state.hasError.value).toBe(false)
    expect(state.isLoading.value).toBe(false)
    expect(state.isLoaded.value).toBe(false)

    read.mockClear()
    markSessionExpired()
    await signInAgain()

    expect(read).not.toHaveBeenCalled()
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
  describe('« non chargé » n\'est pas « vide » (#748, R4)', () => {
    it('isLoaded : faux avant la première lecture, vrai après, faux encore après une première lecture en session finie', async () => {
      const loaded = mountLoad<string>({ read: async () => 'lue', apply: () => undefined })
      expect(loaded.isLoaded.value).toBe(false)
      await loaded.load()
      expect(loaded.isLoaded.value).toBe(true)

      const ended = mountLoad<string>({
        read: async () => {
          throw new SessionEndedError()
        },
        apply: () => undefined,
      })
      await ended.load()

      expect(ended.isLoading.value).toBe(false)
      expect(ended.hasError.value).toBe(false)
      expect(ended.isLoaded.value).toBe(false)
    })

    it("isLoaded : un échec de la première lecture ne la dit pas chargée", async () => {
      const state = mountLoad<string>({
        read: async () => {
          throw new Error('boom')
        },
        apply: () => undefined,
      })

      await state.load()

      expect(state.isLoaded.value).toBe(false)
    })

    it("isLoaded n'est vrai qu'après apply : un observateur synchrone de la valeur a déjà copié", async () => {
      const seenWhenLoaded: Array<string | null> = []
      const value = ref<string | null>(null)
      const state = mountLoad<string>({
        read: async () => 'lue',
        apply: (read) => {
          value.value = read
        },
      })
      // Comme le formulaire des réglages : un observateur synchrone d'`isLoaded` lit la valeur déjà posée.
      watch(state.isLoaded, (loaded) => {
        if (loaded) seenWhenLoaded.push(value.value)
      }, { flush: 'sync' })

      await state.load()

      expect(seenWhenLoaded).toEqual(['lue'])
    })

    it("une clé nouvelle vide la valeur de l'ancienne avant de lire", async () => {
      const second = deferred<string>()
      const value = ref<string | null>(null)
      const clear = vi.fn(() => {
        value.value = null
      })
      const state = mountLoad<string, string>({
        read: (key) => ('fr' === key ? Promise.resolve('valeur fr') : second.promise),
        apply: (read) => {
          value.value = read
        },
        clear,
      })
      await state.load('fr')
      expect(value.value).toBe('valeur fr')
      expect(clear).not.toHaveBeenCalled()

      const pending = state.load('en')

      // Avant même que la lecture de la clé nouvelle ne réponde : rien de l'ancienne n'est montrable.
      expect(clear).toHaveBeenCalledTimes(1)
      expect(value.value).toBeNull()
      expect(state.isLoaded.value).toBe(false)
      second.resolve('valeur en')
      await pending
      expect(value.value).toBe('valeur en')
      expect(state.isLoaded.value).toBe(true)
    })

    it('la même clé ne vide rien', async () => {
      const clear = vi.fn()
      const state = mountLoad<string, string>({ read: async (key) => key, apply: () => undefined, clear })
      await state.load('fr')

      await state.load('fr')

      expect(clear).not.toHaveBeenCalled()
      expect(state.isLoaded.value).toBe(true)
    })

    it('une clé nouvelle dont la lecture finit en session finie laisse isLoaded faux', async () => {
      const value = ref<string | null>(null)
      const state = mountLoad<string, string>({
        read: async (key) => {
          if ('en' === key) throw new SessionEndedError()
          return 'valeur fr'
        },
        apply: (read) => {
          value.value = read
        },
        clear: () => {
          value.value = null
        },
      })
      await state.load('fr')

      await state.load('en')

      expect(value.value).toBeNull()
      expect(state.isLoaded.value).toBe(false)
      expect(state.hasError.value).toBe(false)
    })
  })

  describe('la clé courante a une source, et une écriture périme les lectures de sa clé (#748, R20)', () => {
    it('isCurrentKey suit le dernier load, avant même que sa lecture réponde', async () => {
      const slow = deferred<string>()
      const state = mountLoad<string, string>({
        read: (key) => ('en' === key ? slow.promise : Promise.resolve(`valeur ${key}`)),
        apply: () => undefined,
      })
      expect(state.isCurrentKey('fr')).toBe(false)

      await state.load('fr')
      expect(state.isCurrentKey('fr')).toBe(true)
      expect(state.isCurrentKey('en')).toBe(false)

      const pending = state.load('en')
      expect(state.isCurrentKey('en')).toBe(true)
      expect(state.isCurrentKey('fr')).toBe(false)
      slow.resolve('valeur en')
      await pending
    })

    it('isCurrentKey est réactive : un computed la relit à chaque nouveau load', async () => {
      const state = mountLoad<string, string>({ read: async (key) => key, apply: () => undefined })
      const isFrench = computed(() => state.isCurrentKey('fr'))

      await state.load('fr')
      expect(isFrench.value).toBe(true)
      await state.load('en')
      expect(isFrench.value).toBe(false)
    })

    it('write sur la clé courante pose la valeur et périme la lecture en vol', async () => {
      const staleRead = deferred<string>()
      const value = ref<string | null>(null)
      const pending = [Promise.resolve('première'), staleRead.promise]
      const state = mountLoad<string, string>({
        read: () => pending.shift() as Promise<string>,
        apply: (read) => {
          value.value = read
        },
      })
      await state.load('fr')
      const reading = state.load('fr')

      // L'écriture arrive pendant la relecture : sa valeur est plus récente que ce que la lecture en vol rendra.
      expect(state.write('fr', 'écrite')).toBe(true)
      expect(value.value).toBe('écrite')
      expect(state.isLoaded.value).toBe(true)
      expect(state.isLoading.value).toBe(false)

      staleRead.resolve('ancienne')
      await reading

      expect(value.value).toBe('écrite')
      expect(state.isLoading.value).toBe(false)
    })

    it("write sur la clé courante pose aussi la valeur d'une clé dont la lecture n'a pas répondu", async () => {
      const never = deferred<string>()
      const value = ref<string | null>(null)
      const state = mountLoad<string, string>({
        read: () => never.promise,
        apply: (read) => {
          value.value = read
        },
      })
      void state.load('fr')
      expect(state.isLoaded.value).toBe(false)

      expect(state.write('fr', 'écrite')).toBe(true)

      expect(value.value).toBe('écrite')
      expect(state.isLoaded.value).toBe(true)
      never.resolve('ancienne')
      await flushPromises()
      expect(value.value).toBe('écrite')
    })

    it('write sur une autre clé ne pose rien', async () => {
      const apply = vi.fn()
      const state = mountLoad<string, string>({ read: async (key) => key, apply })
      await state.load('en')
      apply.mockClear()

      expect(state.write('fr', 'écrite')).toBe(false)

      expect(apply).not.toHaveBeenCalled()
      expect(state.isLoaded.value).toBe(true)
    })

    it("write avant tout load ne pose rien : aucune clé n'est demandée", () => {
      const apply = vi.fn()
      const state = mountLoad<string, string>({ read: async (key) => key, apply })

      expect(state.write('fr', 'écrite')).toBe(false)
      expect(apply).not.toHaveBeenCalled()
    })
  })

  describe('au retour de la session, la lecture interrompue est relue (#748, R4)', () => {
    it('la lecture interrompue est relancée une fois, avec sa clé', async () => {
      const keys: string[] = []
      let ended = true
      const value = ref<string | null>(null)
      const state = mountLoad<string, string>({
        read: async (key) => {
          keys.push(key)
          if (ended) throw new SessionEndedError()
          return `valeur ${key}`
        },
        apply: (read) => {
          value.value = read
        },
      })
      markSessionExpired()
      await state.load('en')
      expect(keys).toEqual(['en'])
      expect(state.isLoaded.value).toBe(false)

      ended = false
      await signInAgain()

      expect(keys).toEqual(['en', 'en'])
      expect(value.value).toBe('valeur en')
      expect(state.isLoaded.value).toBe(true)

      // Une seule fois : la session qui finit puis revient encore ne relit rien, la lecture a abouti.
      markSessionExpired()
      await signInAgain()
      expect(keys).toEqual(['en', 'en'])
    })

    it("au retour, rien n'est relu si la dernière lecture a abouti", async () => {
      const read = vi.fn(async () => 'lue')
      const state = mountLoad<string>({ read, apply: () => undefined })
      await state.load()
      read.mockClear()

      markSessionExpired()
      await signInAgain()

      expect(read).not.toHaveBeenCalled()
    })

    it("au retour, la lecture interrompue d'une clé que la personne a quittée n'est plus relancée", async () => {
      const keys: string[] = []
      const state = mountLoad<string, string>({
        read: async (key) => {
          keys.push(key)
          if ('en' === key) throw new SessionEndedError()
          return `valeur ${key}`
        },
        apply: () => undefined,
      })
      markSessionExpired()
      await state.load('en')
      await state.load('fr')
      keys.length = 0

      await signInAgain()

      expect(keys).toEqual([])
    })

    it("une lecture en cours d'une autre clé n'est pas supplantée par l'interrompue au retour", async () => {
      const keys: string[] = []
      const slow = deferred<string>()
      const state = mountLoad<string, string>({
        read: (key) => {
          keys.push(key)
          if ('en' === key) return Promise.reject(new SessionEndedError())
          return slow.promise
        },
        apply: () => undefined,
      })
      markSessionExpired()
      await state.load('en')
      void state.load('fr')
      keys.length = 0

      await signInAgain()

      expect(keys).toEqual([])
      slow.resolve('valeur fr')
    })

    it("sans « session finie » marquée, un compte appliqué ne relance rien (seule la descente de l'état relit)", async () => {
      const read = vi.fn(async () => {
        throw new SessionEndedError()
      })
      const state = mountLoad<string>({ read, apply: () => undefined })
      // Le dépôt rejette `SessionEndedError` sans que la réaction du client n'ait marqué la session : aucune descente à attendre.
      await state.load()
      read.mockClear()

      await signInAgain()

      expect(read).not.toHaveBeenCalled()
    })
  })
})
