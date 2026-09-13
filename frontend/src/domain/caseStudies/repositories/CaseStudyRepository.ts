import type { CaseStudy } from '../entities/CaseStudy'
import type { Locale } from '../../portfolio/entities/Locale'

/**
 * Abstraction (DIP). Contrairement à ContributionRepository, cette source
 * exige le palier de base (ROLE_USER, ADR 0003 D6) — voir
 * CaseStudiesAccessNotGrantedError pour le cas où il n'est pas encore obtenu.
 */
export interface CaseStudyRepository {
  list(locale: Locale): Promise<readonly CaseStudy[]>
}
