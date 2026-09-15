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

  it('ArrowDown émet un déplacement vers le bas', async () => {
    const wrapper = mountHandle({ index: 0, count: 3, label: 'Panne réseau' })

    await wrapper.get('button').trigger('keydown', { key: 'ArrowDown' })

    expect(wrapper.emitted('move')).toEqual([[0, 1]])
  })

  /**
   * Issue #170 F2 : rien n'indiquait qu'↑/↓ déplacent, et Entrée/Espace ne
   * font rien sur ce bouton. L'aide est décrite (`aria-describedby`), pas
   * ajoutée au nom : le nom reste « Déplacer : … », court, la description
   * vient après pour qui la demande.
   */
  it("décrit l'usage des flèches par aria-describedby vers une aide rendue", () => {
    const wrapper = mountHandle({ index: 0, count: 3, label: 'Panne réseau' })

    const hintId = wrapper.get('button').attributes('aria-describedby')
    expect(hintId).toBeTruthy()
    expect(wrapper.get(`#${hintId}`).text()).toBe('Flèches haut et bas pour déplacer la ligne.')
  })

  /**
   * Issue #170 F3 : l'annonce ne vit plus dans la ligne déplacée — une région
   * live re-parentée au même cycle de rendu peut être avalée par le lecteur
   * d'écran. Elle est portée par la barre d'ordre, une par tableau.
   */
  it("ne porte aucune région live : l'annonce appartient à la barre du tableau", () => {
    const wrapper = mountHandle({ index: 0, count: 3, label: 'Panne réseau' })

    expect(wrapper.find('[role="status"]').exists()).toBe(false)
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

    const last = mountHandle({ index: 2, count: 3, label: 'Panne réseau' })
    await last.get('button').trigger('keydown', { key: 'ArrowDown' })
    expect(last.emitted('move')).toBeUndefined()
  })

  it("ne présente aucune violation d'accessibilité", async () => {
    await expectNoAccessibilityViolation(mountHandle({ index: 0, count: 3, label: 'Panne réseau' }))
  })
})
