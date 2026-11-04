import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick, ref } from 'vue'
import { usePublishedHeight } from '../../../src/presentation/layout/usePublishedHeight'

const PROPERTY = '--test-published-height'

/**
 * jsdom n'a pas de `ResizeObserver` (ni de mise en page) : un faux, qui garde son rappel pour que le test
 * le déclenche avec la hauteur qu'il veut. Chaque instance se déclare dans `observers`.
 */
class FakeResizeObserver {
  static readonly observers: FakeResizeObserver[] = []

  observed: Element | null = null
  disconnected = false

  private readonly callback: ResizeObserverCallback

  constructor(callback: ResizeObserverCallback) {
    this.callback = callback
    FakeResizeObserver.observers.push(this)
  }

  observe(target: Element): void {
    this.observed = target
  }

  unobserve(): void {}

  disconnect(): void {
    this.disconnected = true
  }

  /** Simule un relevé de mise en page : `blockSize` est la hauteur de la boîte de bordure, éventuellement fractionnaire. */
  emit(blockSize: number): void {
    this.callback(
      [{ target: this.observed, borderBoxSize: [{ blockSize, inlineSize: 0 }] } as unknown as ResizeObserverEntry],
      this as unknown as ResizeObserver,
    )
  }
}

/** Compose la composable sur une cible que le test peut faire apparaître et disparaître (`v-if`). */
function mountTarget() {
  const shown = ref(true)
  const target = ref<HTMLElement | null>(null)
  const Host = defineComponent({
    setup() {
      usePublishedHeight(target, PROPERTY)

      return () => (shown.value ? h('div', { ref: target }) : null)
    },
  })
  const wrapper = mount(Host)

  return { wrapper, shown }
}

function published(): string {
  return document.documentElement.style.getPropertyValue(PROPERTY)
}

describe('usePublishedHeight (#748, constat 7)', () => {
  beforeEach(() => {
    FakeResizeObserver.observers.length = 0
    vi.stubGlobal('ResizeObserver', FakeResizeObserver)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.documentElement.style.removeProperty(PROPERTY)
  })

  it('pose la hauteur relevée, arrondie au pixel supérieur', async () => {
    const { wrapper } = mountTarget()
    await nextTick()

    FakeResizeObserver.observers[0].emit(47.2)

    expect(published()).toBe('48px')
    wrapper.unmount()
  })

  it('un second relevé met la propriété à jour (l\'en-tête change de hauteur)', async () => {
    const { wrapper } = mountTarget()
    await nextTick()

    FakeResizeObserver.observers[0].emit(48)
    FakeResizeObserver.observers[0].emit(120)

    expect(published()).toBe('120px')
    wrapper.unmount()
  })

  it('retire la propriété quand la cible disparaît, et au démontage', async () => {
    const { wrapper, shown } = mountTarget()
    await nextTick()
    FakeResizeObserver.observers[0].emit(48)
    expect(published()).toBe('48px')

    shown.value = false
    await nextTick()
    expect(published()).toBe('')
    expect(FakeResizeObserver.observers[0].disconnected).toBe(true)

    shown.value = true
    await nextTick()
    FakeResizeObserver.observers[1].emit(64)
    expect(published()).toBe('64px')

    wrapper.unmount()
    expect(published()).toBe('')
    expect(FakeResizeObserver.observers[1].disconnected).toBe(true)
  })

  it('sans ResizeObserver, ne fait rien', async () => {
    vi.stubGlobal('ResizeObserver', undefined)

    const { wrapper } = mountTarget()
    await nextTick()

    expect(FakeResizeObserver.observers).toHaveLength(0)
    expect(published()).toBe('')
    wrapper.unmount()
  })
})
