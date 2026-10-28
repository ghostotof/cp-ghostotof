import type { Locale } from '../../domain/portfolio/entities/Locale'

/**
 * Le fuseau du site : l'heure que lit un visiteur doit être la même que celle
 * des journaux et des échéances du serveur, quel que soit le fuseau de sa
 * machine. Les instants de l'API portent un décalage (ATOM) : ils désignent un
 * instant précis, ce que ce fuseau fixe en heure murale.
 */
const SITE_TIME_ZONE = 'Europe/Paris'

/**
 * Écrit un instant ISO 8601 (avec décalage) en date et heure courtes, dans la
 * langue du site et à l'heure de Paris : « 28 oct. 2026, 09:05 ».
 *
 * À ne pas confondre avec `parseIsoDate`, qui lit un *jour* de calendrier sans
 * heure : ici l'instant est absolu, c'est le fuseau d'affichage qu'on choisit.
 *
 * Une valeur illisible rend la chaîne reçue telle quelle, jamais « Invalid
 * Date » ni une exception : une date mal formée ne doit pas faire disparaître
 * la ligne qui la porte.
 */
export function formatInstant(iso: string, locale: Locale): string {
  const instant = new Date(iso)

  if (Number.isNaN(instant.getTime())) {
    return iso
  }

  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: SITE_TIME_ZONE,
  }).format(instant)
}
