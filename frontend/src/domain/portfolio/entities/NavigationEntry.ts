import type { NavigationGroup } from './NavigationGroup'
import type { NavigationLink } from './NavigationLink'

/** Ce que la navigation principale affiche au premier niveau : un lien, ou un groupe de liens. */
export type NavigationEntry = NavigationLink | NavigationGroup

export function isNavigationGroup(entry: NavigationEntry): entry is NavigationGroup {
  return 'links' in entry
}
