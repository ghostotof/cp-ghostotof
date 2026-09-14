import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import OrderHandle from '../../../../src/presentation/ui/admin/OrderHandle.vue'
import { createAppI18n } from '../../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../../support/axe'

type Props = InstanceType<typeof OrderHandle>['$props']

function mountHandle(props: Props) {
  return mount(OrderHandle, { props, global: { plugins: [createAppI18n()] } })
}

describe('OrderHandle', () => {
  it('porte un aria-label nommant la ligne à déplacer', () => {
    const wrapper = mountHandle({ index: 0, count: 3, label: 'Panne réseau' })

    expect(wrapper.get('button').attributes('aria-label')).toBe('Déplacer : Panne réseau')
  })

  it("ArrowDown émet un déplacement vers le bas et l'annonce", async () => {
    const wrapper = mountHandle({ index: 0, count: 3, label: 'Panne réseau' })

    await wrapper.get('button').trigger('keydown', { key: 'ArrowDown' })

    expect(wrapper.emitted('move')).toEqual([[0, 1]])
    expect(wrapper.get('[role="status"]').text()).toBe('Déplacé en position 2 sur 3')
  })

  it('ArrowUp émet un déplacement vers le haut', async () => {
    const wrapper = mountHandle({ index: 1, count: 3, label: 'Panne réseau' })

    await wrapper.get('button').trigger('keydown', { key: 'ArrowUp' })

    expect(wrapper.emitted('move')).toEqual([[1, 0]])
  })

  it("n'émet rien en dehors des bornes", async () => {
    const first = mountHandle({ index: 0, count: 3, label: 'Panne réseau' })
    await first.get('button').trigger('keydown', { key: 'ArrowUp' })
    expect(first.emitted('move')).toBeUndefined()
    expect(first.get('[role="status"]').text()).toBe('')

    const last = mountHandle({ index: 2, count: 3, label: 'Panne réseau' })
    await last.get('button').trigger('keydown', { key: 'ArrowDown' })
    expect(last.emitted('move')).toBeUndefined()
  })

  it("ne présente aucune violation d'accessibilité", async () => {
    await expectNoAccessibilityViolation(mountHandle({ index: 0, count: 3, label: 'Panne réseau' }))
  })
})
