import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { BASE_ACCESS_REPOSITORY, useBaseAccess } from '../../../src/application/baseAccess/useBaseAccess'
import type { BaseAccessRepository } from '../../../src/domain/baseAccess/repositories/BaseAccessRepository'
import { BaseAccessError } from '../../../src/domain/baseAccess/errors/BaseAccessError'

function createStubRepository(overrides: Partial<BaseAccessRepository> = {}): BaseAccessRepository {
  return {
    grant: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: BaseAccessRepository) {
  let captured: ReturnType<typeof useBaseAccess> | undefined

  const Host = defineComponent({
    setup() {
      captured = useBaseAccess()
      return () => h('div')
    },
  })

  mount(Host, {
    global: {
      provide: { [BASE_ACCESS_REPOSITORY as symbol]: repository },
    },
  })

  if (!captured) {
    throw new Error('Le composable n\'a pas été capturé.')
  }

  return captured
}

describe('useBaseAccess', () => {
  it('grant() réussi : isGranting repasse à false, aucune erreur, retourne true', async () => {
    const repository = createStubRepository()
    const { grant, isGranting, errorReason } = mountWithComposable(repository)

    const pending = grant()
    expect(isGranting.value).toBe(true)

    await expect(pending).resolves.toBe(true)
    expect(isGranting.value).toBe(false)
    expect(errorReason.value).toBeNull()
  })

  it("grant() en échec expose la raison de l'erreur et retourne false", async () => {
    const repository = createStubRepository({
      grant: vi.fn(async () => Promise.reject(new BaseAccessError('rate-limited', 'Too many attempts'))),
    })
    const { grant, errorReason, isGranting } = mountWithComposable(repository)

    await expect(grant()).resolves.toBe(false)

    expect(errorReason.value).toBe('rate-limited')
    expect(isGranting.value).toBe(false)
  })

  it('échoue explicitement si le repository n\'a pas été fourni', () => {
    const Host = defineComponent({
      setup() {
        useBaseAccess()
        return () => h('div')
      },
    })

    expect(() => mount(Host)).toThrow(/BaseAccessRepository/)
  })
})
