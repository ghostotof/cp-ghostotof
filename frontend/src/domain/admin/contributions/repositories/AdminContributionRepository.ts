import type { AdminContribution } from '../entities/AdminContribution'

/**
 * Corps d'écriture d'une contribution. Sans `position` (spec 0004, D3 : elle
 * n'est plus jamais saisie) et avec `translationGroup` :
 *
 * - `null` à la création : contenu neuf, le serveur lui alloue un groupe frais
 *   et la position `max + 1` du périmètre ;
 * - le groupe d'une entrée d'une autre locale à la création : l'entrée est
 *   rattachée et **hérite de la position du groupe** ;
 * - sur une mise à jour, `null` **détache** l'entrée de ses traductions. C'est
 *   pourquoi le formulaire renvoie toujours le groupe qu'il a lu : omettre de
 *   le faire romprait le lien FR/EN à la première édition.
 */
export interface AdminContributionInput {
  locale: string
  translationGroup: string | null
  title: string
  project: string
  reference: string
  url: string
  summary: string
  body: string
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

  update(id: string, input: AdminContributionInput): Promise<AdminContribution>

  remove(id: string): Promise<void>

  /**
   * `PUT …/order` : la liste **complète** des groupes du périmètre, dans
   * l'ordre voulu (spec 0004, D4 — un sous-ensemble est refusé). Rejette avec
   * `AdminOrderError`, jamais avec `AdminContributionError` : c'est
   * `useOrderDraft` qui traite l'échec, et il ne connaît que ce type-là.
   */
  reorder(keys: readonly string[]): Promise<void>
}
