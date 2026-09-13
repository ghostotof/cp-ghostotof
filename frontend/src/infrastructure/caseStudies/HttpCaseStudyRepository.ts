import type { CaseStudy } from '../../domain/caseStudies/entities/CaseStudy'
import type { CaseStudyRepository } from '../../domain/caseStudies/repositories/CaseStudyRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { CaseStudiesAccessNotGrantedError } from '../../domain/caseStudies/errors/CaseStudiesAccessNotGrantedError'
import { CaseStudiesUnavailableError } from '../../domain/caseStudies/errors/CaseStudiesUnavailableError'

/**
 * Implémentation HTTP de CaseStudyRepository. Comme HttpCvRepository, le
 * cookie httpOnly BEARER voyage automatiquement (`credentials: 'include'`) ;
 * GET n'étant pas une méthode "unsafe" pour CsrfCookieRequestSubscriber
 * (backend), aucun header X-XSRF-TOKEN n'est requis.
 */
export class HttpCaseStudyRepository implements CaseStudyRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly CaseStudy[]> {
    const response = await fetch(`${this.apiBaseUrl}/api/case-studies/${locale}`, {
      method: 'GET',
      credentials: 'include',
    })

    if (!response.ok) {
      // 401 : aucun jeton (visiteur anonyme, le cas courant). 403 : jeton
      // présent mais rôle insuffisant — ne devrait pas arriver en pratique
      // sur cette route (quiconque authentifié, même via le jeton du palier
      // de base, a déjà ROLE_USER), mais traité pareil par prudence plutôt
      // que de retomber sur l'erreur générique.
      if (401 === response.status || 403 === response.status) {
        throw new CaseStudiesAccessNotGrantedError()
      }
      throw new CaseStudiesUnavailableError()
    }

    return (await response.json()) as readonly CaseStudy[]
  }
}
