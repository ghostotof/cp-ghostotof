import { describe, expect, it } from 'vitest'
import { defineComponent, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { useCurrentLocale } from '../../../src/application/shared/useCurrentLocale'
import { createAppI18n } from '../../../src/presentation/i18n'
import { setUnsupportedLocale } from '../../support/unsupportedLocale'

/**
 * La langue courante de l'interface, typée `Locale` (#748, R213, D2) : `useI18n().locale` est une `string`, et ses
 * lecteurs la convertissaient chacun par `locale.value as Locale`. Le composable est monté dans un composant, car
 * `useI18n()` exige une instance.
 */
function currentLocaleOf(i18n: ReturnType<typeof createAppI18n>) {
  let current: ReturnType<typeof useCurrentLocale> | undefined
  mount(
    defineComponent({
      setup() {
        current = useCurrentLocale()
        return () => null
      },
    }),
    { global: { plugins: [i18n] } },
  )
  if (undefined === current) {
    throw new Error("useCurrentLocale n'a pas été appelé")
  }

  return current
}

describe('useCurrentLocale', () => {
  it("la langue de l'interface, si le site la porte (#748, R213)", async () => {
    const i18n = createAppI18n()
    i18n.global.locale.value = 'en'
    const current = currentLocaleOf(i18n)

    expect(current.value).toBe('en')

    i18n.global.locale.value = 'fr'
    await nextTick()

    expect(current.value).toBe('fr')
  })

  it('une langue hors du site rend la langue par défaut (#748, R213)', () => {
    const i18n = createAppI18n()
    setUnsupportedLocale(i18n, 'de')

    expect(currentLocaleOf(i18n).value).toBe('fr')
  })
})
