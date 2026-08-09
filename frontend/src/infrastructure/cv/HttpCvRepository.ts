import type { CvFile, CvRepository } from '../../domain/cv/repositories/CvRepository'
import { CvUnavailableError } from '../../domain/cv/errors/CvUnavailableError'

const FALLBACK_FILENAME = 'cv.pdf'

/**
 * Implémentation HTTP de CvRepository. Comme HttpAuthRepository, le cookie
 * httpOnly BEARER voyage automatiquement avec la requête : `credentials:
 * 'include'` suffit, pas de header Authorization. GET n'étant pas une méthode
 * "unsafe" pour App\Security\Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber
 * (backend), aucun header X-XSRF-TOKEN n'est requis ici.
 */
export class HttpCvRepository implements CvRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async download(): Promise<CvFile> {
    const response = await fetch(`${this.apiBaseUrl}/api/cv`, {
      method: 'GET',
      credentials: 'include',
    })

    if (!response.ok) {
      throw new CvUnavailableError()
    }

    const blob = await response.blob()
    const filename = parseFilename(response.headers.get('Content-Disposition')) ?? FALLBACK_FILENAME

    return { blob, filename }
  }
}

function parseFilename(contentDisposition: string | null): string | null {
  return contentDisposition ? /filename="?([^";]+)"?/.exec(contentDisposition)?.[1] ?? null : null
}
