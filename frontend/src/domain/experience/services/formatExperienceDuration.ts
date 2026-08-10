import type { Locale } from '../../portfolio/entities/Locale'

/**
 * Formate le temps cumulé passé sur une technologie en un libellé localisé
 * (ex. "~13,5 ans" / "~13.5 years", "~6 mois" / "~6 months"). Reproduit le
 * format des libellés autrefois écrits en dur dans infrastructure/portfolio/
 * content/{fr,en}.ts : en dessous d'un an, exprimé en mois arrondis ; sinon
 * en années avec un chiffre après la virgule/le point, et le singulier
 * correct pour une valeur de 1.
 */
export function formatExperienceDuration(years: number, locale: Locale): string {
  if (years < 1) {
    const months = Math.round(years * 12)
    return locale === 'fr' ? `~${months} mois` : `~${months} month${months === 1 ? '' : 's'}`
  }

  const formattedYears = formatDecimal(years, locale)

  return locale === 'fr' ? `~${formattedYears} an${years === 1 ? '' : 's'}` : `~${formattedYears} year${years === 1 ? '' : 's'}`
}

function formatDecimal(years: number, locale: Locale): string {
  return new Intl.NumberFormat(locale === 'fr' ? 'fr-FR' : 'en-US', { maximumFractionDigits: 1 }).format(years)
}
