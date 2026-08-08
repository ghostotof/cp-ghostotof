import type { CallToAction } from './CallToAction'
import type { HeroHighlight } from './HeroHighlight'

export interface HeroContent {
  readonly eyebrow: string
  readonly titleLead: string
  readonly titleAccent: string
  readonly description: string
  readonly callsToAction: readonly CallToAction[]
  readonly highlights: readonly HeroHighlight[]
}
