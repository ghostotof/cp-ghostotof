import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StatsSection from '../../../src/presentation/sections/StatsSection.vue'
import type { Stat } from '../../../src/domain/portfolio/entities/Stat'
import { createAppI18n } from '../../../src/presentation/i18n'

const stats: readonly Stat[] = [
  { value: '+50K', label: 'Lignes de code', iconKey: 'code' },
  { value: '10+', label: 'Technologies maîtrisées', iconKey: 'box' },
]

function mountSection() {
  return mount(StatsSection, { props: { stats }, global: { plugins: [createAppI18n()] } })
}

describe('StatsSection', () => {
  it('rend la valeur et le libellé de chaque statistique', () => {
    const wrapper = mountSection()

    for (const stat of stats) {
      expect(wrapper.text()).toContain(stat.value)
      expect(wrapper.text()).toContain(stat.label)
    }
  })

  it('rend une icône par statistique', () => {
    const wrapper = mountSection()

    expect(wrapper.findAll('.col svg')).toHaveLength(stats.length)
  })

  it('expose un titre de section (h2) masqué visuellement pour la navigation par titres', () => {
    const wrapper = mountSection()

    const heading = wrapper.get('h2')
    expect(heading.classes()).toContain('visually-hidden')
    expect(heading.text().length).toBeGreaterThan(0)
  })
})
