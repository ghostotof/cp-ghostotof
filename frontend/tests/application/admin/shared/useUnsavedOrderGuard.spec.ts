import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { useUnsavedOrderGuard } from '../../../../src/application/admin/shared/useUnsavedOrderGuard'
import { createAppI18n } from '../../../../src/presentation/i18n'

const isDirty = ref(false)

const Guarded = defineComponent({
  setup() {
    useUnsavedOrderGuard(isDirty)

    return () => h('p', 'page ordonnée')
  },
})
const Elsewhere = defineComponent({ setup: () => () => h('p', 'ailleurs') })

async function mountGuarded() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/guarded', component: Guarded },
      { path: '/elsewhere', component: Elsewhere },
    ],
  })
  await router.push('/guarded')
  await router.isReady()

  const Host = defineComponent({ setup: () => () => h(RouterView) })
  const wrapper = mount(Host, { global: { plugins: [createAppI18n(), router] } })
  await flushPromises()

  return { wrapper, router }
}

/**
 * Issue #170 F5 : la garde « quitter la page » était recopiée à l'identique
 * dans sept pages. Une seule implémentation, testée ici une fois — les pages
 * n'ont plus qu'à la brancher sur leur `isDirty`.
 */
describe('useUnsavedOrderGuard', () => {
  enableAutoUnmount(afterEach)

  afterEach(() => {
    isDirty.value = false
    vi.restoreAllMocks()
  })

  it("demande confirmation avant de quitter la route avec un ordre modifié, et reste sur place si on refuse", async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { router } = await mountGuarded()
    isDirty.value = true

    await router.push('/elsewhere')
    await flushPromises()

    expect(confirmSpy).toHaveBeenCalledWith("L'ordre modifié n'est pas enregistré. Quitter cette page l'abandonnera. Continuer ?")
    expect(router.currentRoute.value.path).toBe('/guarded')
  })

  it('laisse partir quand la confirmation est acceptée', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { router } = await mountGuarded()
    isDirty.value = true

    await router.push('/elsewhere')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/elsewhere')
  })

  it("quitte la route sans rien demander quand l'ordre est à jour", async () => {
    const confirmSpy = vi.spyOn(window, 'confirm')
    const { router } = await mountGuarded()

    await router.push('/elsewhere')
    await flushPromises()

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/elsewhere')
  })

  it("annule beforeunload seulement tant que l'ordre est modifié, et se détache au démontage", async () => {
    const { wrapper } = await mountGuarded()

    const clean = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(clean)
    expect(clean.defaultPrevented).toBe(false)

    isDirty.value = true
    const dirty = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(dirty)
    expect(dirty.defaultPrevented).toBe(true)

    wrapper.unmount()
    const afterUnmount = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(afterUnmount)
    expect(afterUnmount.defaultPrevented).toBe(false)
  })
})
