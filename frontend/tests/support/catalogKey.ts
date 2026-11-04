import type fr from '../../src/infrastructure/i18n/locales/fr.json'

/**
 * Les chemins pointés des messages du catalogue (`admin.order.moved`, `auth.rateLimitedIn`…), calculés de `fr.json` : une clé
 * renommée ou fautive dans un spec casse la compilation (`make front-build`), non le test à l'exécution. `en.json` porte les
 * mêmes clés, ce que `tests/infrastructure/i18n/locales.spec.ts` garde.
 */
type PathsOf<Node> = {
  [Key in keyof Node & string]: Node[Key] extends string ? Key : Node[Key] extends object ? `${Key}.${PathsOf<Node[Key]>}` : never
}[keyof Node & string]

export type CatalogKey = PathsOf<typeof fr>
