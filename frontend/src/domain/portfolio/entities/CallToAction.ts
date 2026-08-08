export type CallToActionVariant = 'primary' | 'secondary'

export interface CallToAction {
  readonly label: string
  readonly href: string
  readonly variant: CallToActionVariant
  readonly iconKey?: string
}
