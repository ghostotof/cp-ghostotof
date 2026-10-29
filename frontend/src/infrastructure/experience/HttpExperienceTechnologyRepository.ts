import type { ExperienceTechnology, ExperienceRelatedTechnology } from '../../domain/experience/entities/ExperienceTechnology'
import type { ExperienceTechnologyRepository } from '../../domain/experience/repositories/ExperienceTechnologyRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { ExperienceTechnologiesUnavailableError } from '../../domain/experience/errors/ExperienceTechnologiesUnavailableError'
import { formatExperienceDuration } from '../../domain/experience/services/formatExperienceDuration'

interface ExperienceTechnologyApiResponse {
  name: string
  years: number
  iconKey: string | null
  relatedTechnology: ExperienceRelatedTechnology | null
  secondary: boolean
}

/**
 * Implémentation HTTP de ExperienceTechnologyRepository. Contrairement à
 * HttpCvRepository, l'endpoint (GET /api/experience/technologies) est public
 * (cf. App\Portfolio\Experience, aucune restriction dans access_control côté
 * backend).
 *
 * `credentials: 'omit'` (#748, R23), écrit : aucune identité n'est lue, et en production (une seule origine) le défaut
 * `same-origin` enverrait un `BEARER` mort, dont le 401 tomberait sur une page publique (en dev, deux origines : il
 * n'en enverrait pas, ce qui cachait le défaut). `credentialsAreExplicit.spec.ts` le garde.
 */
export class HttpExperienceTechnologyRepository implements ExperienceTechnologyRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly ExperienceTechnology[]> {
    const response = await fetch(`${this.apiBaseUrl}/api/experience/technologies`, { method: 'GET', credentials: 'omit' })

    if (!response.ok) {
      throw new ExperienceTechnologiesUnavailableError()
    }

    const technologies = (await response.json()) as ExperienceTechnologyApiResponse[]

    return [...technologies]
      .sort((a, b) => b.years - a.years)
      .map((technology) => ({
        name: technology.name,
        years: technology.years,
        duration: formatExperienceDuration(technology.years, locale),
        iconKey: technology.iconKey ?? undefined,
        relatedTechnology: technology.relatedTechnology ?? undefined,
        // `?? false` : une API plus ancienne (ou un cache de réponse antérieur
        // au déploiement) n'expose pas encore le champ — mieux vaut afficher
        // une technologie dans le classement que la faire disparaître.
        isSecondary: technology.secondary ?? false,
      }))
  }
}
