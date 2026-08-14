import type { AboutContent, AboutCard } from '../../domain/portfolio/entities/AboutContent'
import type { AboutContentRepository } from '../../domain/about/repositories/AboutContentRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { AboutContentUnavailableError } from '../../domain/about/errors/AboutContentUnavailableError'

interface AboutCardApiResponse {
  title: string
  description: string
  iconKey: string | null
}

interface AboutContentApiResponse {
  site: {
    eyebrow: string
    cards: AboutCardApiResponse[]
  }
  me: {
    eyebrow: string
    technicalSubtitle: string
    technicalCards: AboutCardApiResponse[]
    personalSubtitle: string
    personalCards: AboutCardApiResponse[]
    hobbiesSubtitle: string
    hobbiesCards: AboutCardApiResponse[]
  }
}

function toAboutCard(card: AboutCardApiResponse): AboutCard {
  return {
    title: card.title,
    description: card.description,
    iconKey: card.iconKey ?? undefined,
  }
}

/**
 * Implémentation HTTP de AboutContentRepository. Endpoint public (GET
 * /api/about/{locale}, cf. App\Portfolio\About, aucune restriction dans
 * access_control côté backend) : pas de `credentials: 'include'` nécessaire.
 */
export class HttpAboutContentRepository implements AboutContentRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async get(locale: Locale): Promise<AboutContent> {
    const response = await fetch(`${this.apiBaseUrl}/api/about/${locale}`, { method: 'GET' })

    if (!response.ok) {
      throw new AboutContentUnavailableError()
    }

    const body = (await response.json()) as AboutContentApiResponse

    return {
      site: {
        eyebrow: body.site.eyebrow,
        cards: body.site.cards.map(toAboutCard),
      },
      me: {
        eyebrow: body.me.eyebrow,
        technicalSubtitle: body.me.technicalSubtitle,
        technicalCards: body.me.technicalCards.map(toAboutCard),
        personalSubtitle: body.me.personalSubtitle,
        personalCards: body.me.personalCards.map(toAboutCard),
        hobbiesSubtitle: body.me.hobbiesSubtitle,
        hobbiesCards: body.me.hobbiesCards.map(toAboutCard),
      },
    }
  }
}
