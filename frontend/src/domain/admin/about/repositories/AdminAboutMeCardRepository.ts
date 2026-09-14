import type { AdminAboutMeCard, AdminAboutMeCardCategory } from '../entities/AdminAboutMeCard'
import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Corps d'écriture d'une carte « moi ». Sans `position` (spec 0004, D3) et avec
 * `translationGroup`, dont la sémantique est celle décrite sur
 * `AdminAboutSiteCardInput` — à une réserve près : un groupe appartient à une
 * seule catégorie, donc le rattacher depuis une autre catégorie est refusé
 * (422 `unknown-translation-group`).
 */
export interface AdminAboutMeCardInput {
  locale: Locale
  translationGroup: string | null
  category: AdminAboutMeCardCategory
  title: string
  description: string
  iconKey: string | null
}

/**
 * Abstraction (DIP) dont dépend l'application. CRUD réservé au backoffice
 * (ROLE_SUPER, cf. /api/backoffice/about/me-cards).
 */
export interface AdminAboutMeCardRepository {
  /**
   * **Sans locale ni catégorie** depuis la spec 0004 (D8). La locale disparaît
   * pour la même raison que partout ailleurs : le tableau montre toutes les
   * langues. La catégorie disparaît parce que la page affiche les **trois**
   * tableaux à la fois : la garder comme filtre aurait imposé trois requêtes
   * pour un seul rendu, là où la répartition est un simple groupement local
   * d'une collection déjà entière. Les filtres `?locale=`/`?category=`
   * existent toujours côté serveur, simplement plus appelés d'ici.
   */
  list(): Promise<readonly AdminAboutMeCard[]>

  create(input: AdminAboutMeCardInput): Promise<AdminAboutMeCard>

  update(id: string, input: AdminAboutMeCardInput): Promise<AdminAboutMeCard>

  remove(id: string): Promise<void>

  /**
   * `PUT …/order` avec `{ category, groups }` : la seule des neuf ressources
   * d'ordre à porter un second champ. Le périmètre est la **catégorie**,
   * toutes langues confondues — `keys` doit donc être exactement l'ensemble
   * des groupes de `category` (D4), et les deux autres catégories ne sont ni
   * lues ni écrites. Rejette avec `AdminOrderError`.
   */
  reorder(keys: readonly string[], category: AdminAboutMeCardCategory): Promise<void>
}
