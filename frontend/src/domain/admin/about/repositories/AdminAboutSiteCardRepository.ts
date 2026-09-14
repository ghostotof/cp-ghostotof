import type { AdminAboutSiteCard } from '../entities/AdminAboutSiteCard'
import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Corps d'écriture d'une carte « site ». Sans `position` (spec 0004, D3 : elle
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
export interface AdminAboutSiteCardInput {
  locale: Locale
  translationGroup: string | null
  title: string
  description: string
  iconKey: string | null
}

/**
 * Abstraction (DIP) dont dépend l'application. CRUD réservé au backoffice
 * (ROLE_SUPER, cf. /api/backoffice/about/site-cards).
 */
export interface AdminAboutSiteCardRepository {
  /**
   * **Sans locale** depuis la spec 0004 (D8) : le tableau du backoffice
   * affiche désormais toutes les langues d'un même groupe sur une ligne, donc
   * il lui faut l'intégralité de la collection. Le filtre `?locale=` existe
   * toujours côté serveur, simplement plus appelé d'ici.
   */
  list(): Promise<readonly AdminAboutSiteCard[]>

  create(input: AdminAboutSiteCardInput): Promise<AdminAboutSiteCard>

  update(id: string, input: AdminAboutSiteCardInput): Promise<AdminAboutSiteCard>

  remove(id: string): Promise<void>

  /**
   * `PUT …/order` : la liste **complète** des groupes du périmètre, dans
   * l'ordre voulu (spec 0004, D4 — un sous-ensemble est refusé). Rejette avec
   * `AdminOrderError`, jamais avec `AdminAboutError` : c'est `useOrderDraft`
   * qui traite l'échec, et il ne connaît que ce type-là.
   */
  reorder(keys: readonly string[]): Promise<void>
}
