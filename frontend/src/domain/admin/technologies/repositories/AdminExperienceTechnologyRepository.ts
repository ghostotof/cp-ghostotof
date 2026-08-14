import type { AdminExperienceTechnology } from '../entities/AdminExperienceTechnology'

export interface AdminExperienceTechnologyInput {
  name: string
  years: number
  iconKey: string | null
  relatedTechnologyName: string | null
}

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAdminExperienceTechnologyRepository) est injectée au niveau du
 * composition root (main.ts). Distincte de ExperienceTechnologyRepository
 * (lecture publique seule) : celle-ci couvre le CRUD réservé au backoffice
 * (ROLE_SUPER, cf. /api/backoffice/experience/technologies).
 */
export interface AdminExperienceTechnologyRepository {
  list(): Promise<readonly AdminExperienceTechnology[]>

  create(input: AdminExperienceTechnologyInput): Promise<AdminExperienceTechnology>

  update(id: number, input: AdminExperienceTechnologyInput): Promise<AdminExperienceTechnology>

  remove(id: number): Promise<void>
}
