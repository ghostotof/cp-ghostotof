import type { AdminQualityTrait } from '../entities/AdminQualityTrait'
import type { Locale } from '../../../portfolio/entities/Locale'

export interface AdminQualityTraitInput {
  locale: Locale
  label: string
  position: number
}

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAdminQualityTraitRepository) est injectée au niveau du composition
 * root (main.ts). Distincte de QualityContentRepository (lecture publique
 * seule) : celle-ci couvre le CRUD réservé au backoffice (ROLE_SUPER, cf.
 * /api/backoffice/quality/traits).
 */
export interface AdminQualityTraitRepository {
  list(locale: Locale): Promise<readonly AdminQualityTrait[]>

  create(input: AdminQualityTraitInput): Promise<AdminQualityTrait>

  update(id: number, input: AdminQualityTraitInput): Promise<AdminQualityTrait>

  remove(id: number): Promise<void>
}
