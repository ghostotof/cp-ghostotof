import type { AnonymousCvSection } from '../entities/AnonymousCvSection'
import type { Locale } from '../../portfolio/entities/Locale'

/**
 * Abstraction (DIP). Comme CaseStudyRepository, cette source exige le palier
 * de base (ROLE_USER, ADR 0003 D6) — voir AnonymousCvAccessNotGrantedError
 * pour le cas où il n'est pas encore obtenu.
 */
export interface AnonymousCvRepository {
  list(locale: Locale): Promise<readonly AnonymousCvSection[]>
}
