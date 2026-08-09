import type { ExperienceTechnology } from './ExperienceTechnology'

export interface ExperienceContent {
  readonly eyebrow: string
  readonly description: string
  readonly technologies: readonly ExperienceTechnology[]
}
