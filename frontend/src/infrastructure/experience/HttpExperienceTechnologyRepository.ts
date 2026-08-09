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
}

/**
 * Implémentation HTTP de ExperienceTechnologyRepository. Contrairement à
 * HttpCvRepository, l'endpoint (GET /api/experience/technologies) est public
 * (cf. App\Portfolio\Experience, aucune restriction dans access_control côté
 * backend) : pas de `credentials: 'include'` nécessaire.
 */
export class HttpExperienceTechnologyRepository implements ExperienceTechnologyRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly ExperienceTechnology[]> {
    const response = await fetch(`${this.apiBaseUrl}/api/experience/technologies`, { method: 'GET' })

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
      }))
  }
}
