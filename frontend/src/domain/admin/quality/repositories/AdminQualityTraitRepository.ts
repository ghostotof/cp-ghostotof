import type { AdminQualityTrait } from '../entities/AdminQualityTrait'
import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Corps d'écriture d'un trait de qualité. Mêmes règles que
 * `AdminQualityPrincipleInput`, qui les documente : pas de `position`
 * (spec 0004, D3) et un `translationGroup` nullable (`null` = contenu neuf à
 * la création, détachement sur une mise à jour).
 */
export interface AdminQualityTraitInput {
  locale: Locale
  translationGroup: string | null
  label: string
}

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAdminQualityTraitRepository) est injectée au niveau du composition
 * root (main.ts). Distincte de QualityContentRepository (lecture publique
 * seule) : celle-ci couvre le CRUD réservé au backoffice (ROLE_SUPER, cf.
 * /api/backoffice/quality/traits).
 */
export interface AdminQualityTraitRepository {
  /** Sans locale (spec 0004, D8) — cf. `AdminQualityPrincipleRepository.list`. */
  list(): Promise<readonly AdminQualityTrait[]>

  create(input: AdminQualityTraitInput): Promise<AdminQualityTrait>

  update(id: string, input: AdminQualityTraitInput): Promise<AdminQualityTrait>

  remove(id: string): Promise<void>

  /** `PUT …/order`, ensemble exact des groupes — cf. `AdminQualityPrincipleRepository.reorder`. */
  reorder(keys: readonly string[]): Promise<void>
}
