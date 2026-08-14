import type { QualityPrinciple } from '../../portfolio/entities/QualityPrinciple'
import type { QualityTrait } from '../../portfolio/entities/QualityTrait'

export interface QualityContent {
  readonly principles: readonly QualityPrinciple[]
  readonly traits: readonly QualityTrait[]
}
