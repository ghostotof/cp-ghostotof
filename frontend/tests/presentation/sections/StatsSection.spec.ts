import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StatsSection from '../../../src/presentation/sections/StatsSection.vue'
import type { Stat } from '../../../src/domain/portfolio/entities/Stat'

const stats: readonly Stat[] = [
  { value: '+50K', label: 'Lignes de code', iconKey: 'code' },
  { value: '10+', label: 'Technologies maîtrisées', iconKey: 'box' },
]

describe('StatsSection', () => {
  it('rend la valeur et le libellé de chaque statistique', () => {
    const wrapper = mount(StatsSection, { props: { stats } })

    for (const stat of stats) {
      expect(wrapper.text()).toContain(stat.value)
      expect(wrapper.text()).toContain(stat.label)
    }
  })

  it('rend une icône par statistique', () => {
    const wrapper = mount(StatsSection, { props: { stats } })

    expect(wrapper.findAll('.col svg')).toHaveLength(stats.length)
  })
})
