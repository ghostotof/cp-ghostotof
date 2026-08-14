import type { HeroContent } from '../../../domain/portfolio/entities/HeroContent'
import type { ExperienceContent } from '../../../domain/portfolio/entities/ExperienceContent'
import type { Technology } from '../../../domain/portfolio/entities/Technology'
import type { LegalPageContent } from '../../../domain/portfolio/entities/LegalPageContent'

/**
 * Contenu "riche" (structuré, pas de simples chaînes courtes) d'une locale donnée.
 * Volontairement séparé des messages d'UI de `infrastructure/i18n/locales/*.json` :
 * ce contenu n'est jamais consommé via `t()`/`$t()`, donc il ne doit pas vivre dans
 * les fichiers de messages vue-i18n (sinon le lint `@intlify/vue-i18n/no-unused-keys`
 * le signalerait à tort comme non utilisé).
 */
export interface PortfolioLocaleContent {
  readonly hero: HeroContent
  readonly experience: ExperienceContent
  readonly technologies: {
    readonly featured: readonly Technology[]
    readonly additional: readonly Technology[]
  }
  readonly legalNotice: LegalPageContent
  readonly privacyPolicy: LegalPageContent
}
