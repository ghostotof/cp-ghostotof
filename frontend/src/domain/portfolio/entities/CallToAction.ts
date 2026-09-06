export type CallToActionVariant = 'primary' | 'secondary'

export interface CallToAction {
  readonly label: string
  readonly href: string
  readonly variant: CallToActionVariant
  readonly iconKey?: string
  /**
   * Cible hors du site (dépôt GitHub, etc.). La présentation ouvre alors le
   * lien dans un nouvel onglet, avec le `rel` qui va avec — et le signale aux
   * lecteurs d'écran, un changement de contexte non annoncé étant désorientant.
   * Reste une propriété du contenu et non une déduction sur l'URL : c'est un
   * choix éditorial (« je ne veux pas perdre le visiteur »), pas une règle
   * mécanique sur la forme du lien.
   */
  readonly isExternal?: boolean
}
