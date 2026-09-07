import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import {
  ADMIN_INCIDENT_REPOSITORY,
  useAdminIncidents,
} from '../../../../src/application/admin/incidents/useAdminIncidents'
import type { AdminIncident } from '../../../../src/domain/admin/incidents/entities/AdminIncident'
import type { AdminIncidentRepository } from '../../../../src/domain/admin/incidents/repositories/AdminIncidentRepository'
import { AdminIncidentError } from '../../../../src/domain/admin/incidents/errors/AdminIncidentError'

const INCIDENT: AdminIncident = {
  id: 1, locale: 'fr', title: 'RabbitMQ', version: 'v0.5.0', occurredAt: '2026-09-03',
  impact: 'Impact.', rootCause: 'Cause.', resolution: 'Résolution.', invariant: 'Règle.', position: 0,
}
const INPUT = {
  locale: 'fr', title: 'RabbitMQ', version: 'v0.5.0', occurredAt: '2026-09-03',
  impact: 'Impact.', rootCause: 'Cause.', resolution: 'Résolution.', invariant: 'Règle.', position: 0,
}

function createStubRepository(overrides: Partial<AdminIncidentRepository> = {}): AdminIncidentRepository {
  return {
    list: vi.fn(async () => [INCIDENT]),
    create: vi.fn(async () => INCIDENT),
    update: vi.fn(async () => INCIDENT),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminIncidentRepository) {
  let captured: ReturnType<typeof useAdminIncidents> | undefined
  const Host = defineComponent({
    setup() {
      captured = useAdminIncidents()
      return () => h('div')
    },
  })
  mount(Host, { global: { provide: { [ADMIN_INCIDENT_REPOSITORY as symbol]: repository } } })
  if (!captured) throw new Error('Le composable n\'a pas été capturé.')
  return captured
}

describe('useAdminIncidents', () => {
  it('charge les incidents au montage', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(repository.list).toHaveBeenCalledOnce()
    expect(composable.incidents.value).toEqual([INCIDENT])
    expect(composable.isLoading.value).toBe(false)
  })

  it('recharge la liste après une création réussie', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.create(INPUT)

    expect(repository.create).toHaveBeenCalledWith(INPUT)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it('recharge la liste après une suppression réussie', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.remove(1)

    expect(repository.remove).toHaveBeenCalledWith(1)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it('expose la raison de l\'échec d\'une mutation sans vider la liste chargée', async () => {
    const repository = createStubRepository({
      update: vi.fn(async () => Promise.reject(new AdminIncidentError('validation', 'invalid'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    await composable.update(1, INPUT)

    expect(composable.errorMessage.value?.reason).toBe('validation')
    expect(composable.hasError.value).toBe(false)
    expect(composable.incidents.value).toEqual([INCIDENT])
  })

  it('bascule hasError quand le chargement initial échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('down'))) })
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(composable.hasError.value).toBe(true)
    expect(composable.incidents.value).toEqual([])
  })

  it('échoue explicitement si le repository n\'a pas été fourni', () => {
    const Host = defineComponent({
      setup() {
        useAdminIncidents()
        return () => h('div')
      },
    })

    expect(() => mount(Host)).toThrow(/AdminIncidentRepository/)
  })
})
