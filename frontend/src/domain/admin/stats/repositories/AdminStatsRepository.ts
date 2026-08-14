import type { AdminStat } from '../entities/AdminStat'
import type { Locale } from '../../../portfolio/entities/Locale'

export interface AdminStatInput {
  locale: Locale
  value: string
  label: string
  iconKey: string
  position: number
}

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAdminStatsRepository) est injectée au niveau du composition root
 * (main.ts). Distincte de StatsRepository (lecture publique seule) : celle-ci
 * couvre le CRUD réservé au backoffice (ROLE_SUPER, cf. /api/backoffice/stats).
 */
export interface AdminStatsRepository {
  list(locale: Locale): Promise<readonly AdminStat[]>

  create(input: AdminStatInput): Promise<AdminStat>

  update(id: number, input: AdminStatInput): Promise<AdminStat>

  remove(id: number): Promise<void>
}
