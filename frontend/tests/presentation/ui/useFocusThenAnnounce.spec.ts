import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ANNOUNCE_AFTER_FOCUS_MS, useFocusThenAnnounce } from '../../../src/presentation/ui/useFocusThenAnnounce'

enableAutoUnmount(afterEach)

// Faux minuteurs, rétablis après chaque test : le délai est piloté par le test.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
})

afterEach(() => {
  vi.useRealTimers()
})

type Handle = ReturnType<typeof useFocusThenAnnounce<string>>

/** Monte un composant minimal qui appelle le composable : `onBeforeUnmount` a besoin d'un hôte. */
function host(delayMs?: number): { handle: Handle; unmount: () => void } {
  let handle: Handle | undefined
  const wrapper = mount(
    defineComponent({
      setup() {
        handle = useFocusThenAnnounce<string>(delayMs)

        return () => h('div')
      },
    }),
  )

  if (undefined === handle) {
    throw new Error('Le composable n\'a pas été appelé.')
  }

  return { handle, unmount: () => wrapper.unmount() }
}

function focusable(): HTMLElement {
  const element = document.createElement('button')
  document.body.appendChild(element)

  return element
}

describe('useFocusThenAnnounce', () => {
  it('le délai par défaut est de 250 ms', () => {
    expect(ANNOUNCE_AFTER_FOCUS_MS).toBe(250)
  })

  it('focalise la cible tout de suite et annonce après le délai', () => {
    const { handle } = host()
    const target = focusable()

    handle.focusThenAnnounce(target, 'révoquée')

    // Le focus d'abord, rien d'écrit : une annonce simultanée pourrait être coupée.
    expect(document.activeElement).toBe(target)
    expect(handle.announced.value).toBeNull()

    vi.advanceTimersByTime(ANNOUNCE_AFTER_FOCUS_MS - 1)
    expect(handle.announced.value).toBeNull()

    vi.advanceTimersByTime(1)
    expect(handle.announced.value).toBe('révoquée')
  })

  it("annonce quand même quand il n'y a pas de cible à focaliser", () => {
    const { handle } = host()

    handle.focusThenAnnounce(null, 'déjà terminée')
    vi.advanceTimersByTime(ANNOUNCE_AFTER_FOCUS_MS)

    expect(handle.announced.value).toBe('déjà terminée')
  })

  it('respecte le délai demandé', () => {
    const { handle } = host(1000)

    handle.focusThenAnnounce(focusable(), 'révoquée')
    vi.advanceTimersByTime(999)
    expect(handle.announced.value).toBeNull()

    vi.advanceTimersByTime(1)
    expect(handle.announced.value).toBe('révoquée')
  })

  it("cancel vide l'annonce et annule la minuterie", () => {
    const { handle } = host()

    handle.focusThenAnnounce(focusable(), 'révoquée')
    expect(vi.getTimerCount()).toBe(1)
    handle.cancel()

    expect(vi.getTimerCount()).toBe(0)
    vi.advanceTimersByTime(ANNOUNCE_AFTER_FOCUS_MS * 2)
    expect(handle.announced.value).toBeNull()
  })

  it("cancel vide aussi une annonce déjà écrite", () => {
    const { handle } = host()

    handle.focusThenAnnounce(focusable(), 'révoquée')
    vi.advanceTimersByTime(ANNOUNCE_AFTER_FOCUS_MS)
    expect(handle.announced.value).toBe('révoquée')

    handle.cancel()

    expect(handle.announced.value).toBeNull()
  })

  it('un second appel remplace le premier', () => {
    const { handle } = host()

    handle.focusThenAnnounce(focusable(), 'première')
    vi.advanceTimersByTime(ANNOUNCE_AFTER_FOCUS_MS / 2)
    handle.focusThenAnnounce(focusable(), 'seconde')

    // Une seule minuterie en attente, jamais deux écritures qui se doublent.
    expect(vi.getTimerCount()).toBe(1)
    vi.advanceTimersByTime(ANNOUNCE_AFTER_FOCUS_MS)
    expect(handle.announced.value).toBe('seconde')
  })

  it('un second appel efface aussi la première annonce déjà écrite', () => {
    const { handle } = host()

    handle.focusThenAnnounce(focusable(), 'première')
    vi.advanceTimersByTime(ANNOUNCE_AFTER_FOCUS_MS)
    handle.focusThenAnnounce(focusable(), 'seconde')

    expect(handle.announced.value).toBeNull()
  })

  it('le démontage annule, et isUnmounted devient vrai', () => {
    const { handle, unmount } = host()

    expect(handle.isUnmounted()).toBe(false)
    handle.focusThenAnnounce(focusable(), 'révoquée')
    expect(vi.getTimerCount()).toBe(1)

    unmount()

    expect(handle.isUnmounted()).toBe(true)
    expect(vi.getTimerCount()).toBe(0)
    vi.advanceTimersByTime(ANNOUNCE_AFTER_FOCUS_MS * 2)
    expect(handle.announced.value).toBeNull()
  })
})
