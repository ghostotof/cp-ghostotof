import type { AboutValue } from './AboutValue'

export interface AboutContent {
  readonly eyebrow: string
  readonly titleLead: string
  readonly titleAccent: string
  readonly paragraphs: readonly string[]
  readonly values: readonly AboutValue[]
}
