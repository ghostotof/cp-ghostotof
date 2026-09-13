import type { AnonymousCvSection } from '../../domain/anonymousCv/entities/AnonymousCvSection'
import type { AnonymousCvRepository } from '../../domain/anonymousCv/repositories/AnonymousCvRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { AnonymousCvAccessNotGrantedError } from '../../domain/anonymousCv/errors/AnonymousCvAccessNotGrantedError'
import { AnonymousCvUnavailableError } from '../../domain/anonymousCv/errors/AnonymousCvUnavailableError'

/**
 * Implémentation HTTP d'AnonymousCvRepository. Comme HttpCaseStudyRepository,
 * le cookie httpOnly BEARER voyage automatiquement (`credentials: 'include'`) ;
 * GET n'étant pas une méthode "unsafe" pour CsrfCookieRequestSubscriber
 * (backend), aucun header X-XSRF-TOKEN n'est requis.
 *
 * Le chemin est `/api/anonymous-cv`, distinct de `/api/cv` (le vrai CV, PDF,
 * ROLE_TRUSTED) : côté backend, `^/api/cv` est capturé par une règle
 * ROLE_TRUSTED, d'où un préfixe qui ne la matche pas.
 */
export class HttpAnonymousCvRepository implements AnonymousCvRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly AnonymousCvSection[]> {
    const response = await fetch(`${this.apiBaseUrl}/api/anonymous-cv/${locale}`, {
      method: 'GET',
      credentials: 'include',
    })

    if (!response.ok) {
      // 401 : aucun jeton (visiteur anonyme, le cas courant). 403 : traité
      // pareil par prudence, même raisonnement que HttpCaseStudyRepository.
      if (401 === response.status || 403 === response.status) {
        throw new AnonymousCvAccessNotGrantedError()
      }
      throw new AnonymousCvUnavailableError()
    }

    return (await response.json()) as readonly AnonymousCvSection[]
  }
}
