import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { ANONYMOUS_CV_REPOSITORY, useAnonymousCv } from '../../../src/application/anonymousCv/useAnonymousCv'
import type { AnonymousCvRepository } from '../../../src/domain/anonymousCv/repositories/AnonymousCvRepository'
import { AnonymousCvAccessNotGrantedError } from '../../../src/domain/anonymousCv/errors/AnonymousCvAccessNotGrantedError'
import { createAppI18n } from '../../../src/presentation/i18n'
import { authState, markBaseAccessGranted } from '../../../src/application/auth/useAuth'

const SECTION = { title: 'Backend', skills: 'Symfony', yearsOfExperience: 12, achievements: 'Réalisations.' }

function createStubRepository(overrides: Partial<AnonymousCvRepository> = {}): AnonymousCvRepository {
  return {
    list: vi.fn(async () => [SECTION]),
    ...overrides,
  }
}

function mountWithComposable(repository: AnonymousCvRepository) {
  let captured: ReturnType<typeof useAnonymousCv> | undefined

  const Host = defineComponent({
    setup() {
      captured = useAnonymousCv()
      return () => h('div')
    },
  })

  const i18n = createAppI18n()
  mount(Host, {
    global: {
      plugins: [i18n],
      provide: { [ANONYMOUS_CV_REPOSITORY as symbol]: repository },
    },
  })

  if (!captured) {
    throw new Error("Le composable n'a pas été capturé.")
  }

  return { ...captured, i18n }
}

describe('useAnonymousCv', () => {
  it('charge les sections au montage, pour la locale courante', async () => {
    const repository = createStubRepository()
    const { sections, isLoading, hasError, needsAccess } = mountWithComposable(repository)

    expect(isLoading.value).toBe(true)
    await flushPromises()

    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(sections.value).toEqual([SECTION])
    expect(isLoading.value).toBe(false)
    expect(hasError.value).toBe(false)
    expect(needsAccess.value).toBe(false)
  })

  it('recharge quand la locale change', async () => {
    const repository = createStubRepository()
    const { i18n } = mountWithComposable(repository)
    await flushPromises()

    i18n.global.locale.value = 'en'
    await flushPromises()

    expect(repository.list).toHaveBeenLastCalledWith('en')
  })

  it('distingue « accès non obtenu » (needsAccess) d\'une erreur générique (hasError)', async () => {
    const denied = mountWithComposable(
      createStubRepository({ list: vi.fn(async () => Promise.reject(new AnonymousCvAccessNotGrantedError())) }),
    )
    await flushPromises()
    expect(denied.needsAccess.value).toBe(true)
    expect(denied.hasError.value).toBe(false)

    const broken = mountWithComposable(createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('boom'))) }))
    await flushPromises()
    expect(broken.hasError.value).toBe(true)
    expect(broken.needsAccess.value).toBe(false)
  })

  it("un refus d'accès alors que l'état croyait au palier de base le fait retomber à anonyme (le jeton a expiré)", async () => {
    markBaseAccessGranted()
    expect(authState.tier).toBe('base')
    mountWithComposable(createStubRepository({ list: vi.fn(async () => Promise.reject(new AnonymousCvAccessNotGrantedError())) }))
    await flushPromises()

    expect(authState.tier).toBe('anonymous')
  })

  it('reload() relance la récupération et efface needsAccess une fois le contenu obtenu', async () => {
    const repository = createStubRepository({
      list: vi.fn().mockRejectedValueOnce(new AnonymousCvAccessNotGrantedError()).mockResolvedValue([SECTION]),
    })
    const { sections, needsAccess, reload } = mountWithComposable(repository)
    await flushPromises()
    expect(needsAccess.value).toBe(true)

    await reload()

    expect(needsAccess.value).toBe(false)
    expect(sections.value).toEqual([SECTION])
  })

  it("échoue explicitement si le repository n'a pas été fourni", () => {
    const Host = defineComponent({
      setup() {
        useAnonymousCv()
        return () => h('div')
      },
    })

    expect(() => mount(Host, { global: { plugins: [createAppI18n()] } })).toThrow(/AnonymousCvRepository/)
  })
})
