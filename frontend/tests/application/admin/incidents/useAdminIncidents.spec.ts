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
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const INCIDENT_ID = '019968a0-0000-7000-8000-000000000005'
const GROUP_ID = '019968b0-0000-7000-8000-000000000005'
const INCIDENT: AdminIncident = {
  id: INCIDENT_ID, locale: 'fr', translationGroup: GROUP_ID, title: 'RabbitMQ', version: 'v0.5.0', occurredAt: '2026-09-03',
  impact: 'Impact.', rootCause: 'Cause.', resolution: 'Résolution.', invariant: 'Règle.', position: 0,
}
const INPUT = {
  locale: 'fr', translationGroup: null, title: 'RabbitMQ', version: 'v0.5.0', occurredAt: '2026-09-03',
  impact: 'Impact.', rootCause: 'Cause.', resolution: 'Résolution.', invariant: 'Règle.',
}

function createStubRepository(overrides: Partial<AdminIncidentRepository> = {}): AdminIncidentRepository {
  return {
    list: vi.fn(async () => [INCIDENT]),
    create: vi.fn(async () => INCIDENT),
    update: vi.fn(async () => INCIDENT),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
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

    await composable.remove(INCIDENT_ID)

    expect(repository.remove).toHaveBeenCalledWith(INCIDENT_ID)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it('expose la raison de l\'échec d\'une mutation sans vider la liste chargée', async () => {
    const repository = createStubRepository({
      update: vi.fn(async () => Promise.reject(new AdminIncidentError('validation', 'invalid'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    await composable.update(INCIDENT_ID, INPUT)

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

  it('délègue reorder() au repository sans recharger la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.reorder([GROUP_ID])

    expect(repository.reorder).toHaveBeenCalledWith([GROUP_ID])
    // useOrderDraft recharge lui-même après un enregistrement réussi ; le faire
    // ici aussi doublerait l'appel.
    expect(repository.list).not.toHaveBeenCalled()
  })

  it('laisse remonter l\'AdminOrderError telle quelle, sans la convertir', async () => {
    const repository = createStubRepository({
      reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    const error = await composable.reorder([GROUP_ID]).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('stale-order')
    expect(composable.errorMessage.value).toBeNull()
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
