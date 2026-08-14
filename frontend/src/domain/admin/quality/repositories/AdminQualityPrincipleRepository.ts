import type { AdminQualityPrinciple } from '../entities/AdminQualityPrinciple'
import type { Locale } from '../../../portfolio/entities/Locale'

export interface AdminQualityPrincipleInput {
  locale: Locale
  title: string
  description: string
  iconKey: string
  position: number
}

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAdminQualityPrincipleRepository) est injectée au niveau du composition
 * root (main.ts). Distincte de QualityContentRepository (lecture publique
 * seule) : celle-ci couvre le CRUD réservé au backoffice (ROLE_SUPER, cf.
 * /api/backoffice/quality/principles).
 */
export interface AdminQualityPrincipleRepository {
  list(locale: Locale): Promise<readonly AdminQualityPrinciple[]>

  create(input: AdminQualityPrincipleInput): Promise<AdminQualityPrinciple>

  update(id: number, input: AdminQualityPrincipleInput): Promise<AdminQualityPrinciple>

  remove(id: number): Promise<void>
}
