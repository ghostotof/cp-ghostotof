import { computed, inject, type InjectionKey } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PortfolioContentRepository } from '../../domain/portfolio/repositories/PortfolioContentRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'

/**
 * Clé d'injection : le composition root (main.ts) fournit l'implémentation
 * concrète via app.provide(PORTFOLIO_CONTENT_REPOSITORY, ...). Ce composable
 * ne connaît que l'abstraction du domaine (DIP).
 */
export const PORTFOLIO_CONTENT_REPOSITORY: InjectionKey<PortfolioContentRepository> = Symbol(
  'PortfolioContentRepository',
)

/**
 * Use-case applicatif : expose le contenu de la landing page aux composants
 * de présentation, sans qu'aucun d'eux n'ait à connaître la source des données.
 *
 * Chaque propriété est un `computed` dépendant de la locale active (`useI18n`) :
 * le contenu se recalcule automatiquement quand l'utilisateur change de langue,
 * sans que les composants appelants n'aient rien à faire de spécial (le
 * déstructurage préserve la réactivité puisque chaque valeur est déjà une ref).
 */
export function usePortfolioContent() {
  const repository = inject(PORTFOLIO_CONTENT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "PortfolioContentRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(PORTFOLIO_CONTENT_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const { locale } = useI18n()
  const currentLocale = computed(() => locale.value as Locale)

  return {
    siteIdentity: computed(() => repository.getSiteIdentity()),
    navigationLinks: computed(() => repository.getNavigationLinks(currentLocale.value)),
    heroContent: computed(() => repository.getHeroContent(currentLocale.value)),
    aboutContent: computed(() => repository.getAboutContent(currentLocale.value)),
    experienceContent: computed(() => repository.getExperienceContent(currentLocale.value)),
    featuredTechnologies: computed(() => repository.getFeaturedTechnologies(currentLocale.value)),
    additionalTechnologies: computed(() => repository.getAdditionalTechnologies(currentLocale.value)),
    qualityPrinciples: computed(() => repository.getQualityPrinciples(currentLocale.value)),
    qualityTraits: computed(() => repository.getQualityTraits(currentLocale.value)),
    stats: computed(() => repository.getStats(currentLocale.value)),
  }
}
