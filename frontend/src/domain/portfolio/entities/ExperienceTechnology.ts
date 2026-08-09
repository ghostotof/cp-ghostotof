/**
 * A technology ranked by cumulative time spent on it across a career, most experienced first.
 * `years` drives sorting and the proportional bar; `duration` is the pre-formatted,
 * locale-specific label shown to the user (e.g. "~9,5 ans" / "~9.5 years").
 */
export interface ExperienceTechnology {
  readonly name: string
  readonly years: number
  readonly duration: string
  readonly iconKey?: string
}
