import type { HeroContent } from '../../../domain/portfolio/entities/HeroContent'
import type { AboutContent } from '../../../domain/portfolio/entities/AboutContent'
import type { ExperienceContent } from '../../../domain/portfolio/entities/ExperienceContent'
import type { Technology } from '../../../domain/portfolio/entities/Technology'
import type { QualityPrinciple } from '../../../domain/portfolio/entities/QualityPrinciple'
import type { QualityTrait } from '../../../domain/portfolio/entities/QualityTrait'
import type { Stat } from '../../../domain/portfolio/entities/Stat'

/**
 * Contenu "riche" (structuré, pas de simples chaînes courtes) d'une locale donnée.
 * Volontairement séparé des messages d'UI de `infrastructure/i18n/locales/*.json` :
 * ce contenu n'est jamais consommé via `t()`/`$t()`, donc il ne doit pas vivre dans
 * les fichiers de messages vue-i18n (sinon le lint `@intlify/vue-i18n/no-unused-keys`
 * le signalerait à tort comme non utilisé).
 */
export interface PortfolioLocaleContent {
  readonly hero: HeroContent
  readonly about: AboutContent
  readonly experience: ExperienceContent
  readonly technologies: {
    readonly featured: readonly Technology[]
    readonly additional: readonly Technology[]
  }
  readonly quality: {
    readonly principles: readonly QualityPrinciple[]
    readonly traits: readonly QualityTrait[]
  }
  readonly stats: readonly Stat[]
}
