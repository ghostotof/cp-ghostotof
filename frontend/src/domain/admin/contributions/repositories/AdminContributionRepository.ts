import type { AdminContribution } from '../entities/AdminContribution'

export interface AdminContributionInput {
  locale: string
  title: string
  project: string
  reference: string
  url: string
  summary: string
  body: string
  position: number
}

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAdminContributionRepository) est injectée au composition root
 * (main.ts). Distincte de ContributionRepository (lecture publique seule) :
 * celle-ci couvre le CRUD réservé au backoffice (ROLE_SUPER, cf.
 * /api/backoffice/contributions).
 */
export interface AdminContributionRepository {
  list(): Promise<readonly AdminContribution[]>

  create(input: AdminContributionInput): Promise<AdminContribution>

  update(id: number, input: AdminContributionInput): Promise<AdminContribution>

  remove(id: number): Promise<void>
}
