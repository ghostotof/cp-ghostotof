import type { AdminAnonymousCvSection } from '../entities/AdminAnonymousCvSection'

export interface AdminAnonymousCvSectionInput {
  locale: string
  title: string
  skills: string
  yearsOfExperience: number
  achievements: string
  position: number
}

/**
 * Abstraction (DIP) dont dépend l'application. Distincte d'AnonymousCvRepository
 * (lecture publique seule) : celle-ci couvre le CRUD réservé au backoffice.
 */
export interface AdminAnonymousCvSectionRepository {
  list(): Promise<readonly AdminAnonymousCvSection[]>

  create(input: AdminAnonymousCvSectionInput): Promise<AdminAnonymousCvSection>

  update(id: number, input: AdminAnonymousCvSectionInput): Promise<AdminAnonymousCvSection>

  remove(id: number): Promise<void>
}
