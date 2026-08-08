/**
 * A featured technology carries an icon and a short description (displayed as a card).
 * An additional technology (the "et aussi" list) only carries a name.
 */
export interface Technology {
  readonly name: string
  readonly description?: string
  readonly iconKey?: string
}
