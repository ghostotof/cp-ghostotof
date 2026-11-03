import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { useOrderDraft } from '../../../../src/application/admin/shared/useOrderDraft'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'
import OrderToolbar from '../../../../src/presentation/ui/admin/OrderToolbar.vue'
import { createAppI18n } from '../../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../../support/axe'
import { ORDER_TEXTS, orderMovedLabel } from '../../../support/adminButtonTexts'

type Props = InstanceType<typeof OrderToolbar>['$props']

function mountToolbar(props: Props) {
  return mount(OrderToolbar, { props, global: { plugins: [createAppI18n()] } })
}

describe('OrderToolbar', () => {
  it("affiche le statut « modifié » et active les boutons quand le brouillon est modifié", () => {
    const wrapper = mountToolbar({ isDirty: true, isSaving: false, errorReason: null })

    expect(wrapper.text()).toContain(ORDER_TEXTS.statusDirty)
    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(2)
    expect(buttons.every((button) => button.attributes('disabled') === undefined)).toBe(true)
  })

  it("affiche le statut « à jour » et désactive Annuler et Enregistrer quand rien n'est modifié", () => {
    const wrapper = mountToolbar({ isDirty: false, isSaving: false, errorReason: null })

    expect(wrapper.text()).toContain(ORDER_TEXTS.statusClean)
    const buttons = wrapper.findAll('button')
    expect(buttons.every((button) => button.attributes('disabled') !== undefined)).toBe(true)
  })

  it('émet cancel et save', async () => {
    const wrapper = mountToolbar({ isDirty: true, isSaving: false, errorReason: null })
    const buttons = wrapper.findAll('button')

    await buttons[0].trigger('click')
    await buttons[1].trigger('click')

    expect(wrapper.emitted('cancel')).toHaveLength(1)
    expect(wrapper.emitted('save')).toHaveLength(1)
  })

  it("signale aria-busy sur Enregistrer pendant l'enregistrement", () => {
    const wrapper = mountToolbar({ isDirty: true, isSaving: true, errorReason: null })

    const saveButton = wrapper.findAll('button')[1]
    expect(saveButton.attributes('aria-busy')).toBe('true')
  })

  it("désactive Annuler et Enregistrer pendant l'enregistrement, même modifié", () => {
    const wrapper = mountToolbar({ isDirty: true, isSaving: true, errorReason: null })

    const buttons = wrapper.findAll('button')
    expect(buttons.every((button) => button.attributes('disabled') !== undefined)).toBe(true)
  })

  it('affiche une alerte pour une liste obsolète', () => {
    const wrapper = mountToolbar({ isDirty: false, isSaving: false, errorReason: 'stale-order' })

    expect(wrapper.get('[role="alert"]').text()).toContain('a changé entre-temps')
  })

  it('affiche une alerte générique pour un échec non identifié', () => {
    const wrapper = mountToolbar({ isDirty: false, isSaving: false, errorReason: 'unknown' })

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it("un enregistrement refusé pour session finie n'affiche aucune alerte : le bandeau l'annonce seul (#748)", async () => {
    // Le brouillon réel : `useOrderDraft` ne pose aucune raison pour une session finie (R3), la barre n'a
    // donc plus à la taire elle-même ; le geste de l'administrateur reste, il peut le rejouer après reconnexion.
    const draft = useOrderDraft({
      serverKeys: () => ['a', 'b'],
      reorder: async () => Promise.reject(new SessionEndedError()),
      reload: async () => undefined,
    })
    draft.move(0, 1)
    await draft.save()

    const wrapper = mountToolbar({
      isDirty: draft.isDirty.value,
      isSaving: draft.isSaving.value,
      errorReason: draft.errorReason.value,
    })

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('admin.order.errors')
    expect(wrapper.findAll('button').every((button) => button.attributes('disabled') === undefined)).toBe(true)
  })

  it("n'affiche aucune alerte en l'absence d'erreur", () => {
    const wrapper = mountToolbar({ isDirty: false, isSaving: false, errorReason: null })

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it("ne présente aucune violation d'accessibilité, avec ou sans alerte", async () => {
    await expectNoAccessibilityViolation(mountToolbar({ isDirty: true, isSaving: false, errorReason: null }))
    await expectNoAccessibilityViolation(mountToolbar({ isDirty: false, isSaving: false, errorReason: 'unknown' }))
  })

  /**
   * Issue #170 F3 : une seule région live par tableau, stable dans le DOM —
   * la poignée n'annonce plus depuis la ligne déplacée.
   */
  it('annonce le dernier déplacement clavier dans une région status', () => {
    const wrapper = mountToolbar({ isDirty: true, isSaving: false, errorReason: null, lastMove: { position: 2, count: 3 } })

    expect(wrapper.get('[role="status"]').text()).toBe(orderMovedLabel(2, 3))
  })

  it("rend la région status vide, mais présente, tant qu'aucun déplacement clavier n'a eu lieu", () => {
    const wrapper = mountToolbar({ isDirty: false, isSaving: false, errorReason: null })

    expect(wrapper.get('[role="status"]').text()).toBe('')
  })

  /**
   * Issue #170 F4 : après un 422 obsolète, le brouillon est resynchronisé
   * (plus « modifié ») mais l'alerte reste ; sans Annuler actif, elle ne peut
   * plus être effacée.
   */
  it("garde Annuler actif quand une erreur est posée, même l'ordre à jour", () => {
    const wrapper = mountToolbar({ isDirty: false, isSaving: false, errorReason: 'stale-order' })

    const [cancel, save] = wrapper.findAll('button')
    expect(cancel?.attributes('disabled')).toBeUndefined()
    expect(save?.attributes('disabled')).toBeDefined()
  })
})
